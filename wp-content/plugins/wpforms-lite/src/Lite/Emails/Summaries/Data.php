<?php

namespace WPForms\Lite\Emails\Summaries;

use DateTimeZone;
use WPForms\Emails\Summaries\Data as BaseData;
use WPForms\Emails\Summaries\Week;
use WPForms\Integrations\LiteConnect\Integration;
use WPForms\Integrations\LiteConnect\LiteConnect;
use WPForms\Lite\Reports\EntriesCount;

/**
 * Weekly summary metrics (Lite).
 *
 * @since 2.0.3
 */
class Data extends BaseData {

	/**
	 * Per-form rows from the weekly post-meta counters the Lite cron maintains.
	 *
	 * Lite keeps no dated entries, so the week itself cannot narrow the counters.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to report on. Unused on Lite.
	 *
	 * @return array
	 */
	protected function get_forms( Week $week ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return $this->map_form_rows( ( new EntriesCount() )->get_form_trends() );
	}

	/**
	 * Entries ever collected: the sum of the per-form lifetime counters.
	 *
	 * @since 2.0.3
	 *
	 * @return int
	 */
	protected function get_lifetime_entries(): int {

		return (int) array_sum( array_column( ( new EntriesCount() )->get_by_form(), 'count' ) );
	}

	/**
	 * Site-wide weekly entry counts from the Lite Connect day series, under form_id 0.
	 *
	 * The endpoint serves a trailing window from today, so the window is stretched back to
	 * cover the reported weeks and the days after the week end are dropped. No per-form history.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week  Most recent week.
	 * @param int  $weeks Number of weeks including the given one.
	 *
	 * @return array
	 */
	public function get_weekly_by_form( Week $week, int $weeks ): array {

		$stats = $this->get_weeks_stats( $week, $weeks );

		return empty( $stats['days'] ) ? [] : $this->bucket_days( (array) $stats['days'], $week, $weeks );
	}

	/**
	 * Entries backed up by Lite Connect during the week. Null when Lite Connect is off.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to report on.
	 *
	 * @return int|null
	 */
	protected function get_backups( Week $week ): ?int {

		$stats = $this->get_weeks_stats( $week, 1 );

		if ( ! isset( $stats['total'] ) ) {
			return null;
		}

		return $this->bucket_days( (array) ( $stats['days'] ?? [] ), $week, 1 )[0][0];
	}

	/**
	 * Lite Connect stats covering a week and the weeks before it. Null when Lite Connect is off.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week  Most recent week.
	 * @param int  $weeks Number of weeks including the given one.
	 *
	 * @return array|null
	 */
	private function get_weeks_stats( Week $week, int $weeks ): ?array {

		$lag = max( 0, self::days_between( $week->get_end_date(), current_time( 'Y-m-d' ) ) );

		return $this->get_lite_connect_stats( min( 366, Week::DAYS * $weeks + $lag ) );
	}

	/**
	 * Sum a day series into weekly buckets ending on the week's last day. Index 0 is the given week.
	 *
	 * @since 2.0.3
	 *
	 * @param array $days  Items with `date` (Y-m-d) and `count`.
	 * @param Week  $week  Most recent week.
	 * @param int   $weeks Number of weeks including the given one.
	 *
	 * @return array
	 */
	private function bucket_days( array $days, Week $week, int $weeks ): array {

		$week_end = $week->get_end_date();
		$history  = array_fill( 0, max( 1, $weeks ), [ 0 => 0 ] );

		foreach ( $days as $day ) {
			$date = (string) ( $day['date'] ?? '' );

			if ( $date === '' || $date > $week_end ) {
				continue;
			}

			$index = intdiv( self::days_between( $date, $week_end ), Week::DAYS );

			if ( isset( $history[ $index ] ) ) {
				$history[ $index ][0] += (int) ( $day['count'] ?? 0 );
			}
		}

		return $history;
	}

	/**
	 * Whole days from one Y-m-d date to another, negative when `to` is earlier.
	 *
	 * Computed in UTC so a DST switch between the two dates cannot shorten a day.
	 *
	 * @since 2.0.3
	 *
	 * @param string $from Start date, Y-m-d.
	 * @param string $to   End date, Y-m-d.
	 *
	 * @return int
	 */
	private static function days_between( string $from, string $to ): int {

		$utc   = new DateTimeZone( 'UTC' );
		$start = date_create_immutable( $from, $utc );
		$end   = date_create_immutable( $to, $utc );

		return $start && $end ? (int) $start->diff( $end )->format( '%r%a' ) : 0;
	}

	/**
	 * Lite Connect stats for a trailing window. Null when Lite Connect is off.
	 *
	 * @since 2.0.3
	 *
	 * @param int $days Trailing window length in days.
	 *
	 * @return array|null
	 */
	private function get_lite_connect_stats( int $days ): ?array {

		if ( ! LiteConnect::is_allowed() || ! LiteConnect::is_enabled() ) {
			return null;
		}

		return (array) Integration::get_stats( $days, wp_timezone_string() );
	}
}
