<?php

namespace WPForms\Emails\Summaries;

/**
 * Data-driven headlines for the top of the weekly summary.
 *
 * Seven tiers, highest qualifying wins, unless it was shown in the previous two summaries.
 * Tiers that need the entries table or blocked-spam counts are Pro-edition only; the rest run
 * everywhere. One edition guard per tier, so flipping a tier is a one-line change.
 *
 * @since 2.0.3
 */
class Headlines {

	/**
	 * Weeks of history loaded for the best-week tiers, including the reported week.
	 *
	 * @since 2.0.3
	 */
	public const HISTORY_WEEKS = 12;

	/**
	 * Prior weeks with activity required before a week can count as the best one.
	 *
	 * @since 2.0.3
	 */
	public const MIN_HISTORY_WEEKS = 4;

	/**
	 * Summaries after which the same headline may show again.
	 *
	 * @since 2.0.3
	 */
	public const REPEAT_WEEKS = 2;

	/**
	 * Entries a form needs this week for its best week, or last week for its jump, to make the headline.
	 *
	 * @since 2.0.3
	 */
	public const MIN_FORM_ENTRIES = 5;

	/**
	 * Entries the site needs this week for its best week to make the headline.
	 *
	 * @since 2.0.3
	 */
	public const MIN_SITE_ENTRIES = 10;

	/**
	 * Views a form needs this week for its conversion goal to make the headline.
	 *
	 * @since 2.0.3
	 */
	public const MIN_GOAL_VIEWS = 50;

	/**
	 * Week-over-week entry growth, in percent, for a single form to make the headline.
	 *
	 * @since 2.0.3
	 */
	public const FORM_GROWTH_PCT = 40;

	/**
	 * Leads saved in the week to make the headline.
	 *
	 * @since 2.0.3
	 */
	public const MIN_LEADS = 5;

	/**
	 * Spam entries blocked in the week to make the headline.
	 *
	 * @since 2.0.3
	 */
	public const MIN_SPAM = 10;

	/**
	 * Week-over-week view growth, in percent, to make the headline.
	 *
	 * @since 2.0.3
	 */
	public const VIEWS_GROWTH_PCT = 25;

	/**
	 * Highest tier.
	 *
	 * @since 2.0.3
	 */
	private const TIERS = 7;

	/**
	 * Tiers needing per-form weekly history, week-over-week form jumps, blocked spam counts or conversion rates, all Pro-only.
	 *
	 * @since 2.0.3
	 */
	private const PRO_TIERS = [ 1, 3, 5, 7 ];

	/**
	 * Shown-blocks history.
	 *
	 * @since 2.0.3
	 *
	 * @var ShownBlocks
	 */
	private $shown;

	/**
	 * Constructor.
	 *
	 * @since 2.0.3
	 *
	 * @param ShownBlocks|null $shown Shown-blocks history. Defaults to a new instance.
	 */
	public function __construct( ?ShownBlocks $shown = null ) {

		$this->shown = $shown ?? new ShownBlocks();
	}

	/**
	 * The highest qualifying headline not shown in the last two summaries.
	 *
	 * @since 2.0.3
	 *
	 * @param array    $data    Metrics from Data::for_week().
	 * @param callable $history Returns Data::get_weekly_by_form() for the reported week; called only when a tier needs it.
	 * @param bool     $is_pro  Whether the Pro edition is installed.
	 * @param string   $slot    Slot the headline renders in, for the shown-blocks history.
	 *
	 * @return array|null Keys `key`, `tier`, `title`; null when no tier qualifies.
	 */
	public function resolve( array $data, callable $history, bool $is_pro, string $slot ): ?array {

		$weekly = null;
		$loader = static function () use ( &$weekly, $history ): array {

			if ( $weekly === null ) {
				$weekly = (array) $history();
			}

			return $weekly;
		};

		$recent = $this->shown->last( $slot, self::REPEAT_WEEKS );

		for ( $tier = 1; $tier <= self::TIERS; $tier++ ) {
			if ( in_array( self::key( $tier ), $recent, true ) ) {
				continue;
			}

			$vars = $this->evaluate( $tier, $data, $loader, $is_pro );

			if ( $vars !== null ) {
				return $this->build( $tier, $vars );
			}
		}

		return null;
	}

	/**
	 * Tracking and anti-repeat key of a tier.
	 *
	 * @since 2.0.3
	 *
	 * @param int $tier Tier number.
	 *
	 * @return string
	 */
	public static function key( int $tier ): string {

		return "headline_tier_{$tier}";
	}

	/**
	 * Tier number of a key, or 0 when the key is not a headline.
	 *
	 * @since 2.0.3
	 *
	 * @param string $key Block key.
	 *
	 * @return int
	 */
	public static function tier( string $key ): int {

		$tier = (int) substr( $key, strlen( 'headline_tier_' ) );

		return $tier >= 1 && $tier <= self::TIERS ? $tier : 0;
	}

	/**
	 * Copy for a tier. Placeholder wording pending the content review the spec mandates.
	 *
	 * @since 2.0.3
	 *
	 * @param int   $tier Tier number.
	 * @param array $vars Interpolation values: `form`, `n`, `pct` as the tier needs.
	 *
	 * @return array Keys `key`, `tier`, `title`.
	 */
	public function build( int $tier, array $vars ): array {

		// Growth tiers quote whole percents, as the design does; the conversion tier keeps one decimal.
		$pct_decimals = in_array( $tier, [ 3, 6 ], true ) ? 0 : 1;

		// Interpolated values are highlighted in the title, as the design does.
		$n    = number_format_i18n( (int) ( $vars['n'] ?? 0 ) );
		$copy = $this->get_copy(
			self::highlight( ! empty( $vars['form'] ) ? (string) $vars['form'] : __( 'Your form', 'wpforms-lite' ) ),
			self::highlight( $n ),
			self::highlight( number_format_i18n( (float) ( $vars['pct'] ?? 0 ), $pct_decimals ) ),
			$n
		);

		return [
			'key'  => self::key( $tier ),
			'tier' => $tier,
		] + ( $copy[ $tier ] ?? $copy[ self::TIERS ] );
	}

	/**
	 * Wrap a value for the accent color in titles. Templates allow only this tag and the line break.
	 *
	 * The value stays raw: both templates escape the finished title on output, the HTML one
	 * through wp_kses() and the plain-text one through esc_html(), so escaping here would
	 * reach the reader double-encoded.
	 *
	 * @since 2.0.3
	 *
	 * @param string $value Plain value.
	 *
	 * @return string
	 */
	public static function highlight( string $value ): string {

		return '<strong>' . $value . '</strong>';
	}

	/**
	 * Join a two-sentence title so the second sentence starts its own line, as the design sets it.
	 *
	 * @since 2.0.3
	 *
	 * @param string $first  First sentence.
	 * @param string $second Second sentence.
	 *
	 * @return string
	 */
	private static function lines( string $first, string $second ): string {

		return $first . '<br>' . $second;
	}

	/**
	 * Copy per tier with the numbers interpolated.
	 *
	 * @since 2.0.3
	 *
	 * @param string $form  Highlighted form name.
	 * @param string $n     Highlighted formatted count.
	 * @param string $pct   Highlighted formatted percentage.
	 * @param string $n_raw Formatted count without the highlight markup.
	 *
	 * @return array Tier => [ 'title' ]. The spec's content table gives each tier a title only.
	 */
	private function get_copy( string $form, string $n, string $pct, string $n_raw ): array {

		$spam_phrase = self::highlight(
			sprintf(
				/* translators: %s - number of spam entries. */
				__( '%s spam entries', 'wpforms-lite' ),
				$n_raw
			)
		);

		return [
			1 => [
				'title' => self::lines(
					/* translators: %s - form name. */
					sprintf( __( '%s just had its best week yet.', 'wpforms-lite' ), $form ),
					/* translators: %s - number of entries. */
					sprintf( __( '%s entries and counting!', 'wpforms-lite' ), $n )
				),
			],
			2 => [
				/* translators: %s - number of entries. */
				'title' => sprintf( __( 'This was your biggest week yet, with %s entries!', 'wpforms-lite' ), $n ),
			],
			3 => [
				'title' => self::lines(
					/* translators: %s - form name. */
					sprintf( __( '%s is on a roll.', 'wpforms-lite' ), $form ),
					/* translators: %s - growth percentage. */
					sprintf( __( 'Entries are up %s%% this week!', 'wpforms-lite' ), $pct )
				),
			],
			4 => [
				/* translators: %s - number of leads. */
				'title' => sprintf( __( 'You saved %s leads this week with Form Abandonment!', 'wpforms-lite' ), $n ),
			],
			5 => [
				/* translators: %s - highlighted "N spam entries" phrase. */
				'title' => sprintf( __( 'Your spam protection is working hard. It blocked %s this week.', 'wpforms-lite' ), $spam_phrase ),
			],
			6 => [
				'title' => self::lines(
					__( 'Your forms are getting noticed.', 'wpforms-lite' ),
					/* translators: %s - growth percentage. */
					sprintf( __( 'Views are up %s%% this week!', 'wpforms-lite' ), $pct )
				),
			],
			7 => [
				'title' => self::lines(
					/* translators: %s - form name. */
					sprintf( __( 'Your %s is converting well.', 'wpforms-lite' ), $form ),
					/* translators: %s - conversion rate. */
					sprintf( __( '%s%% of visitors completed it!', 'wpforms-lite' ), $pct )
				),
			],
		];
	}

	/**
	 * Interpolation values for a tier when the week qualifies for it, null otherwise.
	 *
	 * @since 2.0.3
	 *
	 * @param int      $tier   Tier number.
	 * @param array    $data   Metrics from Data::for_week().
	 * @param callable $weekly Memoized Data::get_weekly_by_form() loader.
	 * @param bool     $is_pro Whether the Pro edition is installed.
	 *
	 * @return array|null
	 */
	private function evaluate( int $tier, array $data, callable $weekly, bool $is_pro ): ?array {

		// Per-form history, blocked spam and conversion exist only in the Pro edition.
		if ( ! $is_pro && in_array( $tier, self::PRO_TIERS, true ) ) {
			return null;
		}

		$forms = (array) ( $data['forms'] ?? [] );

		switch ( $tier ) {
			case 1:
				// A single form beats every prior week.
				return $this->best_form_week( $weekly(), $forms );

			case 2:
				// The whole site beats every prior week.
				return $this->best_site_week( $weekly() );

			case 3:
				// A single form jumped week over week.
				return $this->biggest_form_jump( $forms );

			case 4:
				// Enough leads saved.
				return $this->count_at_least( $data, 'leads_saved', self::MIN_LEADS );

			case 5:
				// Enough spam blocked.
				return $this->count_at_least( $data, 'spam', self::MIN_SPAM );

			case 6:
				// Views grew week over week.
				return $this->views_growth( $data );

			default:
				// A form reached its conversion goal.
				return $this->goal_reached( $data );
		}
	}

	/**
	 * A count metric when it reaches a minimum.
	 *
	 * @since 2.0.3
	 *
	 * @param array  $data Metrics.
	 * @param string $key  Metric key.
	 * @param int    $min  Minimum value.
	 *
	 * @return array|null
	 */
	private function count_at_least( array $data, string $key, int $min ): ?array {

		$count = (int) ( $data[ $key ] ?? 0 );

		return $count >= $min ? [ 'n' => $count ] : null;
	}

	/**
	 * The week-over-week view growth when it reaches the threshold, against a non-zero previous week.
	 *
	 * @since 2.0.3
	 *
	 * @param array $data Metrics.
	 *
	 * @return array|null
	 */
	private function views_growth( array $data ): ?array {

		$delta = (int) ( $data['views_delta'] ?? 0 );

		return (int) ( $data['views_previous'] ?? 0 ) > 0 && $delta >= self::VIEWS_GROWTH_PCT ? [ 'pct' => $delta ] : null;
	}

	/**
	 * The form whose reported week beats all of its prior weeks, preferring the most entries.
	 *
	 * @since 2.0.3
	 *
	 * @param array $weekly Data::get_weekly_by_form() output.
	 * @param array $forms  Form rows, for titles.
	 *
	 * @return array|null
	 */
	private function best_form_week( array $weekly, array $forms ): ?array {

		$best = null;

		foreach ( $weekly[0] ?? [] as $form_id => $current ) {
			$prior = array_column( array_slice( $weekly, 1 ), $form_id );

			if ( (int) $current >= self::MIN_FORM_ENTRIES && $this->is_best_week( (int) $current, $prior ) && ( $best === null || $current > $best['n'] ) ) {
				$best = [
					'form' => $forms[ $form_id ]['title'] ?? '',
					'n'    => (int) $current,
				];
			}
		}

		return $best;
	}

	/**
	 * The site-wide reported week when it beats every prior week.
	 *
	 * @since 2.0.3
	 *
	 * @param array $weekly Data::get_weekly_by_form() output.
	 *
	 * @return array|null
	 */
	private function best_site_week( array $weekly ): ?array {

		if ( ! isset( $weekly[0] ) ) {
			return null;
		}

		$totals  = array_map( 'array_sum', $weekly );
		$current = (int) array_shift( $totals );

		return $current >= self::MIN_SITE_ENTRIES && $this->is_best_week( $current, $totals ) ? [ 'n' => $current ] : null;
	}

	/**
	 * Whether a week beats every prior week, given enough prior weeks with activity to compare against.
	 *
	 * @since 2.0.3
	 *
	 * @param int   $current Reported week count.
	 * @param array $prior   Prior weekly counts, most recent first.
	 *
	 * @return bool
	 */
	private function is_best_week( int $current, array $prior ): bool {

		$active = array_filter( array_map( 'intval', $prior ) );

		return count( $active ) >= self::MIN_HISTORY_WEEKS && $current > max( $active );
	}

	/**
	 * The form with the largest week-over-week jump above the growth threshold, from at least five entries last week.
	 *
	 * @since 2.0.3
	 *
	 * @param array $forms Form rows.
	 *
	 * @return array|null
	 */
	private function biggest_form_jump( array $forms ): ?array {

		$best = null;

		foreach ( $forms as $form ) {
			$pct     = (int) ( $form['trends'] ?? 0 );
			$is_jump = (int) ( $form['entries_previous'] ?? 0 ) >= self::MIN_FORM_ENTRIES && $pct >= self::FORM_GROWTH_PCT;

			if ( $is_jump && ( $best === null || $pct > $best['pct'] ) ) {
				$best = [
					'form' => (string) ( $form['title'] ?? '' ),
					'n'    => (int) ( $form['entries'] ?? 0 ),
					'pct'  => $pct,
				];
			}
		}

		return $best;
	}

	/**
	 * The first form that converted at or above the goal set for it, when the tier may see conversion.
	 *
	 * @since 2.0.3
	 *
	 * @param array $data Metrics.
	 *
	 * @return array|null
	 */
	private function goal_reached( array $data ): ?array {

		if ( ! Milestones::shows_conversion( $data ) ) {
			return null;
		}

		foreach ( (array) ( $data['forms'] ?? [] ) as $form ) {
			if ( (int) ( $form['views'] ?? 0 ) >= self::MIN_GOAL_VIEWS && Milestones::meets_goal( $form ) ) {
				return [
					'form' => (string) ( $form['title'] ?? '' ),
					'pct'  => (float) $form['conversion'],
				];
			}
		}

		return null;
	}
}
