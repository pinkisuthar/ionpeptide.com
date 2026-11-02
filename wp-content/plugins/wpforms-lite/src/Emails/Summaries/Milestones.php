<?php

namespace WPForms\Emails\Summaries;

/**
 * Milestones the weekly summary celebrates once each.
 *
 * Spent milestones live in a non-autoloaded option. The upgrade migration creates it with every
 * milestone the site had reached at that moment marked spent, so nothing fires retroactively. The
 * send path repeats the attempt for installs that never ran the migration, discounting the reported
 * week so a first or a threshold reached during it still gets its celebration. A fresh install passes
 * nothing and starts empty.
 *
 * @since 2.0.3
 */
class Milestones {

	/**
	 * Option holding the spent milestones: key => timestamp.
	 *
	 * @since 2.0.3
	 */
	public const OPTION = 'wpforms_email_summaries_milestones';

	/**
	 * Lifetime entry thresholds, highest first.
	 *
	 * @since 2.0.3
	 */
	public const ENTRY_THRESHOLDS = [ 1000, 500, 100 ];

	/**
	 * First entry ever.
	 *
	 * @since 2.0.3
	 */
	public const FIRST_ENTRY = 'first_entry';

	/**
	 * First payment ever.
	 *
	 * @since 2.0.3
	 */
	public const FIRST_PAYMENT = 'first_payment';

	/**
	 * First abandoned entry captured.
	 *
	 * @since 2.0.3
	 */
	public const FIRST_LEAD = 'first_lead';

	/**
	 * Prefix for entries threshold milestone keys.
	 *
	 * @since 2.0.3
	 */
	public const PREFIX_ENTRIES = 'entries_';

	/**
	 * Prefix for conversion goal milestone keys.
	 *
	 * @since 2.0.3
	 */
	public const PREFIX_GOAL = 'goal_';

	/**
	 * Store key marking a baseline taken on Pro, over the entries table rather than Lite's counters.
	 *
	 * @since 2.0.3
	 */
	public const PRO_BASELINE = 'pro_baseline';

	/**
	 * Nothing to do on load. The baseline is taken by the upgrade migration and, as a fallback, on
	 * the summary send path, so the non-autoloaded option is never queried on unrelated requests.
	 *
	 * @since 2.0.3
	 */
	public function init(): void {
	}

	/**
	 * Mark every milestone the site has reached as spent, once per edition: when the store does not
	 * exist yet, and again the first time Pro runs on a store Lite created.
	 *
	 * With no arguments this is the launch snapshot the upgrade migration takes. The send path passes
	 * the reported week and its metrics instead: what that week added is discounted, so a first or a
	 * threshold reached during it still fires. A repeat pass only adds keys, it never un-spends one.
	 *
	 * @since 2.0.3
	 *
	 * @param array     $data Metrics from Data::for_week(), empty for the launch snapshot.
	 * @param Week|null $week Reported week, bounding the goal history; null scans up to today.
	 */
	public function maybe_baseline( array $data = [], ?Week $week = null ): void {

		$spent  = get_option( self::OPTION );
		$is_pro = wpforms()->is_pro();

		if ( self::is_baselined( $spent, $is_pro ) ) {
			return;
		}

		$source   = Data::resolve();
		$to       = $week ? $week->previous()->get_end_date() : null;
		$baseline = $this->qualifying( self::get_lifetime_before( $source->lifetime(), $data ) );

		foreach ( $source->get_lifetime_goal_forms( $to ) as $form_id ) {
			$baseline[] = self::goal_key( (int) $form_id );
		}

		if ( $is_pro ) {
			$baseline[] = self::PRO_BASELINE;
		}

		// Keys already spent keep their original timestamps.
		update_option( self::OPTION, ( $spent === false ? [] : (array) $spent ) + array_fill_keys( $baseline, time() ), false );
	}

	/**
	 * Whether the store already holds a baseline this edition can trust.
	 *
	 * Lite counts entries from its own counters, which Pro never updates, so Pro baselines once more.
	 *
	 * @since 2.0.3
	 *
	 * @param mixed $spent  The stored option, false when it does not exist.
	 * @param bool  $is_pro Whether Pro is running.
	 *
	 * @return bool
	 */
	private static function is_baselined( $spent, bool $is_pro ): bool {

		return $spent !== false && ( ! $is_pro || isset( $spent[ self::PRO_BASELINE ] ) );
	}

	/**
	 * Lifetime totals as they stood before the reported week, which the week's metrics are subtracted from.
	 *
	 * @since 2.0.3
	 *
	 * @param array $lifetime Totals from Data::lifetime().
	 * @param array $data     Metrics from Data::for_week(), empty for the launch snapshot.
	 *
	 * @return array
	 */
	private static function get_lifetime_before( array $lifetime, array $data ): array {

		$lifetime['lifetime_entries'] = max( 0, (int) ( $lifetime['lifetime_entries'] ?? 0 ) - (int) ( $data['entries'] ?? 0 ) );
		$lifetime['lifetime_leads']   = max( 0, (int) ( $lifetime['lifetime_leads'] ?? 0 ) - (int) ( $data['leads_saved'] ?? 0 ) );
		$lifetime['has_payments']     = (int) ( $lifetime['lifetime_payments'] ?? 0 ) - (int) ( $data['payments'] ?? 0 ) > 0;

		return $lifetime;
	}

	/**
	 * The highest milestone the site qualifies for and has not celebrated yet.
	 *
	 * @since 2.0.3
	 *
	 * @param array $data Metrics from Data::for_week().
	 *
	 * @return array|null Keys `key`, `title`; null when nothing new was reached.
	 */
	public function resolve( array $data ): ?array {

		$spent = $this->get_spent();

		foreach ( $this->qualifying( $data ) as $key ) {
			// First-lead celebrations share the Pro-and-above license gate with conversion rates.
			if ( $key === self::FIRST_LEAD && empty( $data['conversion_allowed'] ) ) {
				continue;
			}

			if ( ! isset( $spent[ $key ] ) ) {
				return $this->build( $key, $data );
			}
		}

		return null;
	}

	/**
	 * Record a milestone as celebrated.
	 *
	 * @since 2.0.3
	 *
	 * @param string $key Milestone key.
	 */
	public function mark_spent( string $key ): void {

		$spent = $this->get_spent();
		$now   = time();

		$spent[ $key ] = $now;

		// A threshold retires the lower ones with it, or they would each follow a week later.
		if ( strpos( $key, self::PREFIX_ENTRIES ) === 0 ) {
			$reached = (int) substr( $key, strlen( self::PREFIX_ENTRIES ) );
			$lower   = array_filter(
				self::ENTRY_THRESHOLDS,
				static function ( int $threshold ) use ( $reached ): bool {

					return $threshold < $reached;
				}
			);

			$spent += array_fill_keys( array_map( [ self::class, 'entries_key' ], $lower ), $now );
		}

		update_option( self::OPTION, $spent, false );
	}

	/**
	 * Every milestone the data qualifies for, in the spec's priority order.
	 *
	 * First entry, first payment, first lead, conversion goal, then the entry thresholds highest first.
	 *
	 * @since 2.0.3
	 *
	 * @param array $data Metrics from Data::for_week(), or Data::lifetime() alone.
	 *
	 * @return string[]
	 */
	public function qualifying( array $data ): array {

		$keys    = [];
		$entries = (int) ( $data['lifetime_entries'] ?? 0 );

		if ( $entries >= 1 ) {
			$keys[] = self::FIRST_ENTRY;
		}

		if ( ! empty( $data['has_payments'] ) ) {
			$keys[] = self::FIRST_PAYMENT;
		}

		if ( (int) ( $data['lifetime_leads'] ?? 0 ) >= 1 ) {
			$keys[] = self::FIRST_LEAD;
		}

		$keys = array_merge( $keys, $this->goal_keys( $data ) );

		foreach ( self::ENTRY_THRESHOLDS as $threshold ) {
			if ( $entries >= $threshold ) {
				$keys[] = self::entries_key( $threshold );
			}
		}

		return $keys;
	}

	/**
	 * Milestone key for an entries threshold.
	 *
	 * @since 2.0.3
	 *
	 * @param int $threshold Threshold.
	 *
	 * @return string
	 */
	public static function entries_key( int $threshold ): string {

		return self::PREFIX_ENTRIES . $threshold;
	}

	/**
	 * Milestone key for a form's conversion goal.
	 *
	 * @since 2.0.3
	 *
	 * @param int $form_id Form id.
	 *
	 * @return string
	 */
	public static function goal_key( int $form_id ): string {

		return self::PREFIX_GOAL . $form_id;
	}

	/**
	 * Goal milestones for every form that met its goal, when the tier may see conversion.
	 *
	 * @since 2.0.3
	 *
	 * @param array $data Metrics from Data::for_week().
	 *
	 * @return string[]
	 */
	private function goal_keys( array $data ): array {

		if ( ! self::shows_conversion( $data ) ) {
			return [];
		}

		$keys = [];

		foreach ( $data['forms'] ?? [] as $form_id => $form ) {
			if ( self::meets_goal( $form ) ) {
				$keys[] = self::goal_key( (int) $form_id );
			}
		}

		return $keys;
	}

	/**
	 * Whether the week may reveal conversion rates: the tier includes them and the license is not lapsed.
	 *
	 * @since 2.0.3
	 *
	 * @param array $data Metrics from Data::for_week().
	 *
	 * @return bool
	 */
	public static function shows_conversion( array $data ): bool {

		return ! empty( $data['conversion_allowed'] ) && empty( $data['conversion_locked'] );
	}

	/**
	 * Whether a form row converted at or above the goal set for it.
	 *
	 * @since 2.0.3
	 *
	 * @param array $form Form row from Data::for_week().
	 *
	 * @return bool
	 */
	public static function meets_goal( array $form ): bool {

		return isset( $form['goal'] ) && (int) ( $form['views'] ?? 0 ) > 0 && (float) ( $form['conversion'] ?? 0 ) >= (float) $form['goal'];
	}

	/**
	 * Copy for a milestone. Placeholder wording pending the content review the spec mandates.
	 *
	 * @since 2.0.3
	 *
	 * @param string $key  Milestone key.
	 * @param array  $data Metrics from Data::for_week().
	 *
	 * @return array Keys `key`, `title`. The spec's content table gives each row a title only.
	 */
	public function build( string $key, array $data ): array {

		if ( strpos( $key, self::PREFIX_ENTRIES ) === 0 ) {
			$threshold = number_format_i18n( (int) substr( $key, strlen( self::PREFIX_ENTRIES ) ) );
			$copy      = [
				/* translators: %s - number of entries. */
				'title' => sprintf( __( 'You just hit %s entries! 🎉', 'wpforms-lite' ), Headlines::highlight( $threshold ) ),
			];
		} elseif ( strpos( $key, self::PREFIX_GOAL ) === 0 ) {
			$form = $data['forms'][ (int) substr( $key, strlen( self::PREFIX_GOAL ) ) ] ?? [];
			$copy = [
				/* translators: %s - form name. */
				'title' => sprintf( __( '%s just hit its conversion goal! 🎉', 'wpforms-lite' ), Headlines::highlight( self::form_title( $form ) ) ),
			];
		} elseif ( $key === self::FIRST_LEAD ) {
			$copy = [
				'title' => __( 'You recovered your first lead! 🎉', 'wpforms-lite' ),
			];
		} elseif ( $key === self::FIRST_PAYMENT ) {
			$copy = [
				'title' => __( 'You just collected your first payment! 🎉', 'wpforms-lite' ),
			];
		} else {
			$copy = [
				'title' => __( 'You just collected your first entry! 🎉', 'wpforms-lite' ),
			];
		}

		return [ 'key' => $key ] + $copy;
	}

	/**
	 * Spent milestones: key => timestamp.
	 *
	 * @since 2.0.3
	 *
	 * @return array
	 */
	private function get_spent(): array {

		return (array) get_option( self::OPTION, [] );
	}

	/**
	 * Form title with a fallback for preview data.
	 *
	 * @since 2.0.3
	 *
	 * @param array $form Form row.
	 *
	 * @return string
	 */
	private static function form_title( array $form ): string {

		return ! empty( $form['title'] ) ? (string) $form['title'] : __( 'Your form', 'wpforms-lite' );
	}
}
