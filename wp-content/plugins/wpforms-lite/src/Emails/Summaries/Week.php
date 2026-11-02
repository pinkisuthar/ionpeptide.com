<?php

namespace WPForms\Emails\Summaries;

use DateTimeImmutable;
use DateTimeZone;
use WPForms\Admin\Dashboard\Cache;

/**
 * The week a summary email reports on: Monday through the previous Sunday, site timezone.
 *
 * Every date-bound query in the summary takes its bounds from here, so the window is
 * defined in exactly one place.
 *
 * @since 2.0.3
 */
class Week {

	/**
	 * Days in a week.
	 *
	 * @since 2.0.3
	 */
	public const DAYS = 7;

	/**
	 * Week start, Monday 00:00:00.
	 *
	 * @since 2.0.3
	 *
	 * @var DateTimeImmutable
	 */
	private $start;

	/**
	 * Week end, Sunday 23:59:59.
	 *
	 * @since 2.0.3
	 *
	 * @var DateTimeImmutable
	 */
	private $end;

	/**
	 * Constructor.
	 *
	 * @since 2.0.3
	 *
	 * @param DateTimeImmutable $end Any moment of the week's last day.
	 */
	public function __construct( DateTimeImmutable $end ) {

		$this->end   = $end->setTime( 23, 59, 59 );
		$this->start = $this->end->modify( '-' . ( self::DAYS - 1 ) . ' days' )->setTime( 0, 0 );
	}

	/**
	 * The week the next summary reports on: the seven days ending the previous Sunday.
	 *
	 * Matches the window the Pro entries query and the re-engagement alert already use.
	 *
	 * @since 2.0.3
	 *
	 * @return Week
	 */
	public static function current(): Week {

		return new self( date_create_immutable( 'previous sunday', wp_timezone() ) );
	}

	/**
	 * Step back a whole number of weeks.
	 *
	 * @since 2.0.3
	 *
	 * @param int $weeks_back Number of weeks to step back. Default 1.
	 *
	 * @return Week
	 */
	public function previous( int $weeks_back = 1 ): Week {

		$days = self::DAYS * max( 1, $weeks_back );

		return new self( $this->end->modify( "-{$days} days" ) );
	}

	/**
	 * Get the week start.
	 *
	 * @since 2.0.3
	 *
	 * @return DateTimeImmutable
	 */
	public function get_start(): DateTimeImmutable {

		return $this->start;
	}

	/**
	 * Get the week end.
	 *
	 * @since 2.0.3
	 *
	 * @return DateTimeImmutable
	 */
	public function get_end(): DateTimeImmutable {

		return $this->end;
	}

	/**
	 * Week bounds in UTC, for queries against `date_created_gmt` columns.
	 *
	 * @since 2.0.3
	 *
	 * @return array Start and end as `Y-m-d H:i:s`.
	 */
	public function get_utc_bounds(): array {

		$utc = new DateTimeZone( 'UTC' );

		return [
			$this->start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$this->end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Formatted end date (Y-m-d), used as date key in histories.
	 *
	 * @since 2.0.3
	 *
	 * @return string
	 */
	public function get_end_date(): string {

		return $this->end->format( 'Y-m-d' );
	}

	/**
	 * Stable Dashboard cache key for this week.
	 *
	 * @since 2.0.3
	 *
	 * @return string
	 */
	public function range_key(): string {

		return Cache::make_key( 'custom', $this->start, $this->end );
	}
}
