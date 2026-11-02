<?php

namespace WPForms\Emails\Summaries;

use WPForms\Admin\Dashboard\Cache;
use WPForms\Admin\Dashboard\PaymentStats;
use WPForms\Helpers\PaymentGatewayDetector;

/**
 * Metrics the weekly summary reports on, for one week.
 *
 * Lite-safe base. The Lite and Pro subclasses supply the entries domain, the same way
 * the Dashboard cache is split.
 *
 * @since 2.0.3
 */
class Data {

	/**
	 * Resolve the registered metrics source, Lite or Pro depending on the edition.
	 *
	 * @since 2.0.3
	 *
	 * @return Data
	 */
	public static function resolve(): Data {

		$data = wpforms()->obj( 'emails_summaries_data' );

		return $data instanceof self ? $data : new self();
	}

	/**
	 * Collect every metric for a week in a flat array the templates and blocks consume.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to report on.
	 *
	 * @return array
	 */
	public function for_week( Week $week ): array {

		$current  = $this->get_aggregates( $week );
		$previous = $this->get_aggregates( $week->previous() );
		$tiles    = $this->get_payment_tiles( $week );
		$forms    = $this->get_forms( $week );

		$entries          = (int) array_sum( array_column( $forms, 'entries' ) );
		$entries_previous = (int) array_sum( array_column( $forms, 'entries_previous' ) );
		$views            = (int) ( $current['stats']['total_views'] ?? 0 );
		$views_previous   = (int) ( $previous['stats']['total_views'] ?? 0 );

		$conversion_allowed = $this->is_conversion_allowed();

		return $this->lifetime() + [
			'entries'            => $entries,
			'entries_delta'      => self::percent_delta( $entries, $entries_previous ),
			'backups'            => $this->get_backups( $week ),
			'views'              => $views,
			'views_previous'     => $views_previous,
			'views_delta'        => self::percent_delta( $views, $views_previous ),
			'spam'               => $this->get_spam( $current ),
			'leads_saved'        => $this->get_leads_saved( $week ),
			'has_gateway'        => $this->has_gateway(),
			'payments'           => PaymentStats::parse_tile_count( $tiles['total_payments'] ?? 0 ),
			'payments_delta'     => self::to_delta( $tiles['total_payments_delta'] ?? null ),
			'sales'              => (string) ( $tiles['total_sales'] ?? '0' ),
			'sales_delta'        => self::to_delta( $tiles['total_sales_delta'] ?? null ),
			'refunds'            => (int) PaymentStats::parse_amount_tile( $tiles['total_refunded'] ?? '' )['count'],
			'conversion_allowed' => $conversion_allowed,
			// The tier includes conversion, but a lapsed license shows it locked until renewal.
			'conversion_locked'  => $conversion_allowed && ! wpforms_is_license_valid(),
			'forms'              => $this->enrich_forms( $forms, $week ),
		];
	}

	/**
	 * Lifetime totals the milestones are judged against.
	 *
	 * @since 2.0.3
	 *
	 * @return array Keys: `lifetime_entries`, `lifetime_leads` (null unless Form Abandonment is in use), `lifetime_spam`, `lifetime_payments`, `has_payments`.
	 */
	public function lifetime(): array {

		$payments = $this->get_lifetime_payments();

		return [
			'lifetime_entries'  => $this->get_lifetime_entries(),
			'lifetime_leads'    => $this->get_lifetime_leads(),
			'lifetime_spam'     => $this->get_lifetime_spam(),
			'lifetime_payments' => $payments,
			'has_payments'      => $payments > 0,
		];
	}

	/**
	 * Forms that met their conversion goal in at least one week on record. The base has no analytics.
	 *
	 * @since 2.0.3
	 *
	 * @param string|null $to Last day to scan, Y-m-d; today when null.
	 *
	 * @return int[]
	 */
	public function get_lifetime_goal_forms( ?string $to = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return [];
	}

	/**
	 * Entries ever collected. The base has no entries source; each edition supplies its own.
	 *
	 * @since 2.0.3
	 *
	 * @return int
	 */
	protected function get_lifetime_entries(): int {

		return 0;
	}

	/**
	 * Abandoned entries ever captured. Null when Form Abandonment is not in use.
	 *
	 * @since 2.0.3
	 *
	 * @return int|null
	 */
	protected function get_lifetime_leads(): ?int {

		return null;
	}

	/**
	 * Spam entries ever blocked. Null when the edition does not record spam.
	 *
	 * @since 2.0.3
	 *
	 * @return int|null
	 */
	protected function get_lifetime_spam(): ?int {

		return null;
	}

	/**
	 * Weekly entry counts per form for a week and the weeks before it.
	 *
	 * Index 0 is the given week, 1 the week before, and so on. Each item maps form_id => count;
	 * an edition with site-wide history only uses form_id 0. The base has no history.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week  Most recent week.
	 * @param int  $weeks Number of weeks including the given one.
	 *
	 * @return array
	 */
	public function get_weekly_by_form( Week $week, int $weeks ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return [];
	}

	/**
	 * Conversion-rate goal set for a form on the Analytics page, in percent. Null when none.
	 *
	 * @since 2.0.3
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return float|null
	 */
	protected function get_form_goal( int $form_id ): ?float { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return null;
	}

	/**
	 * Per-form rows for the breakdown table, keyed by form ID and ordered by entries descending.
	 *
	 * Each row carries `form_id`, `title`, `edit_url`, `entries`, `entries_previous`, `trends`.
	 * The base has no entries source; each edition supplies its own.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to report on.
	 *
	 * @return array
	 */
	protected function get_forms( Week $week ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return [];
	}

	/**
	 * Entries backed up by Lite Connect during the week. Null when not applicable.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to report on.
	 *
	 * @return int|null
	 */
	protected function get_backups( Week $week ): ?int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return null;
	}

	/**
	 * Spam entries blocked during the week. Null when the edition does not record spam.
	 *
	 * @since 2.0.3
	 *
	 * @param array $aggregates Dashboard aggregates for the week.
	 *
	 * @return int|null
	 */
	protected function get_spam( array $aggregates ): ?int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return null;
	}

	/**
	 * Abandoned entries captured during the week. Null when Form Abandonment is not in use.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to report on.
	 *
	 * @return int|null
	 */
	protected function get_leads_saved( Week $week ): ?int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return null;
	}

	/**
	 * Whether the license tier may see conversion rates.
	 *
	 * @since 2.0.3
	 *
	 * @return bool
	 */
	protected function is_conversion_allowed(): bool {

		return false;
	}

	/**
	 * Dashboard aggregates for a week, from the shared cache.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to aggregate.
	 *
	 * @return array
	 */
	protected function get_aggregates( Week $week ): array {

		$cache = $this->get_cache();

		return $cache ? $cache->get_or_compute( $week->range_key(), $week->get_start(), $week->get_end() ) : [];
	}

	/**
	 * Get the Dashboard cache service.
	 *
	 * @since 2.0.3
	 *
	 * @return Cache|null
	 */
	protected function get_cache(): ?Cache {

		$cache = wpforms()->obj( 'dashboard_cache' );

		return $cache instanceof Cache ? $cache : null;
	}

	/**
	 * Live payment tiles with week-over-week deltas.
	 *
	 * Live rather than the rollup: only the live aggregator reports the refund count.
	 *
	 * The tile deltas come from StatsAggregator, which computes the change relative to the
	 * CURRENT value — ( current - previous ) / current — unlike `percent_delta()`, which
	 * divides by the previous value. Kept as-is for parity with the Payments Overview page.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to report on.
	 *
	 * @return array
	 */
	protected function get_payment_tiles( Week $week ): array {

		return ( new PaymentStats() )->get_tiles( $week->get_start(), $week->get_end() );
	}

	/**
	 * Whether a payment gateway is connected: a global one, or PayPal Standard enabled on a form.
	 *
	 * PayPal Standard has no global settings, so the shared detector cannot see it.
	 *
	 * @since 2.0.3
	 *
	 * @return bool
	 */
	protected function has_gateway(): bool {

		if ( PaymentGatewayDetector::is_any_payment_gateway_configured() ) {
			return true;
		}

		return function_exists( 'wpforms_paypal_standard' ) && $this->has_form_matching(
			static function ( array $form_data ): bool {

				return ! empty( $form_data['payments']['paypal_standard']['enable'] );
			}
		);
	}

	/**
	 * Whether any published form passes the given test on its decoded data.
	 *
	 * @since 2.0.3
	 *
	 * @param callable $test Receives the decoded form data, returns whether it matches.
	 *
	 * @return bool
	 */
	protected function has_form_matching( callable $test ): bool {

		$form_handler = wpforms()->obj( 'form' );

		if ( ! $form_handler ) {
			return false;
		}

		foreach ( (array) $form_handler->get( '' ) as $form ) {
			if ( $test( (array) wpforms_decode( $form->post_content ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map raw entries-report rows to the per-form breakdown row shape.
	 *
	 * @since 2.0.3
	 *
	 * @param array $rows Raw rows keyed by form ID, as the edition's entries report returns them.
	 *
	 * @return array
	 */
	protected function map_form_rows( array $rows ): array {

		$mapped = [];

		foreach ( $rows as $form_id => $row ) {
			$mapped[ (int) $form_id ] = [
				'form_id'          => (int) $form_id,
				'title'            => (string) ( $row['title'] ?? '' ),
				'edit_url'         => (string) ( $row['edit_url'] ?? '' ),
				'entries'          => (int) ( $row['count'] ?? 0 ),
				'entries_previous' => (int) ( $row['count_previous_week'] ?? 0 ),
				'trends'           => isset( $row['trends'] ) ? (int) $row['trends'] : null,
			];
		}

		return $mapped;
	}

	/**
	 * Add the per-form analytics, conversion and payments columns to the form rows.
	 *
	 * @since 2.0.3
	 *
	 * @param array $forms Form rows keyed by form ID.
	 * @param Week  $week  Week to report on.
	 *
	 * @return array
	 */
	protected function enrich_forms( array $forms, Week $week ): array {

		if ( ! $forms ) {
			return [];
		}

		$cache = $this->get_cache();

		if ( $cache ) {
			$forms = $cache->enrich_forms_with_analytics( $forms, $week->get_start(), $week->get_end() );
		}

		$payments = $this->count_payments_by_form( array_keys( $forms ), $week );

		foreach ( $forms as $form_id => $form ) {
			$views       = (int) ( $form['views'] ?? 0 );
			$submissions = (int) ( $form['submissions'] ?? 0 );

			$forms[ $form_id ]['views']      = $views;
			$forms[ $form_id ]['conversion'] = $views > 0 ? round( $submissions / $views * 100, 1 ) : 0.0;
			$forms[ $form_id ]['payments']   = $payments[ $form_id ] ?? 0;
			$forms[ $form_id ]['goal']       = $this->get_form_goal( (int) $form_id );
		}

		return $forms;
	}

	/**
	 * Count live, published payments per form for a week.
	 *
	 * @since 2.0.3
	 *
	 * @param array $form_ids Form IDs to count for.
	 * @param Week  $week     Week to report on.
	 *
	 * @return array Map of form_id => count.
	 */
	protected function count_payments_by_form( array $form_ids, Week $week ): array {

		$form_ids = array_values( array_filter( array_map( 'absint', $form_ids ) ) );

		if ( ! $form_ids ) {
			return [];
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $form_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$where = $wpdb->prepare( " AND form_id IN ( $placeholders )", $form_ids );
		$rows  = $this->query_payments( 'form_id, COUNT(*) AS count', $where . $this->get_week_payments_where( $week ), ' GROUP BY form_id' );

		return array_map( 'intval', array_column( $rows, 'count', 'form_id' ) );
	}

	/**
	 * Live, published payments ever recorded.
	 *
	 * @since 2.0.3
	 *
	 * @return int
	 */
	protected function get_lifetime_payments(): int {

		$rows = $this->query_payments( 'COUNT(*) AS count', '' );

		return (int) ( $rows[0]['count'] ?? 0 );
	}

	/**
	 * Date condition selecting the payments created during a week.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to select.
	 *
	 * @return string
	 */
	private function get_week_payments_where( Week $week ): string {

		global $wpdb;

		[ $start, $end ] = $week->get_utc_bounds();

		return $wpdb->prepare( ' AND date_created_gmt BETWEEN %s AND %s', $start, $end );
	}

	/**
	 * Run an aggregate query over the payments table with the shared live-mode conditions.
	 *
	 * Uses the same secondary conditions and failed-status exclusion as the Payments
	 * Overview "Total Payments" card, so the two never disagree.
	 *
	 * @since 2.0.3
	 *
	 * @param string $select Aggregate list. Trusted literal.
	 * @param string $where  Extra prepared conditions, each starting with ` AND`.
	 * @param string $suffix Trailing clause, e.g. ` GROUP BY form_id`.
	 *
	 * @return array
	 */
	private function query_payments( string $select, string $where, string $suffix = '' ): array {

		$payment = wpforms()->obj( 'payment' );

		if ( ! $payment ) {
			return [];
		}

		$stats = new PaymentStats();

		return (array) $stats->with_live_mode(
			static function () use ( $payment, $select, $where, $suffix ) {

				global $wpdb;

				$table     = $payment->table_name;
				$secondary = $payment->add_secondary_where_conditions();

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return (array) $wpdb->get_results(
					"SELECT $select FROM $table
					WHERE status <> 'failed' AND subscription_status <> 'failed' $secondary $where $suffix",
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
	}

	/**
	 * Week-over-week change in percent, the way the current summary computes trends.
	 *
	 * @since 2.0.3
	 *
	 * @param int $current  This week's value.
	 * @param int $previous Last week's value.
	 *
	 * @return int
	 */
	public static function percent_delta( int $current, int $previous ): int {

		if ( $previous === 0 ) {
			return $current === 0 ? 0 : 100;
		}

		return (int) round( ( $current - $previous ) / $previous * 100 );
	}

	/**
	 * Normalize a tile delta to an int, keeping null for "not reported".
	 *
	 * @since 2.0.3
	 *
	 * @param mixed $delta Raw delta.
	 *
	 * @return int|null
	 */
	private static function to_delta( $delta ): ?int {

		return $delta === null ? null : (int) $delta;
	}
}
