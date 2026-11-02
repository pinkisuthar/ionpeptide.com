<?php
/**
 * Dashboards Controller Class
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BWFAN_Dashboards
 *
 */
#[\AllowDynamicProperties]
class BWFAN_Dashboards {

	public function __construct() {
	}

	/**
	 * @var string
	 */
	public static $sql_datetime_format = 'Y-m-d H:i:s';

	/**
	 * @param $start_date
	 * @param $end_date
	 * @param $is_interval
	 * @param $interval
	 *
	 * @return string|null
	 */
	public static function get_total_contacts( $start_date = '', $end_date = '', $is_interval = '', $interval = '' ) {
		$start_date = preg_replace( '/[^0-9 :-]/', '', (string) $start_date );
		$end_date   = preg_replace( '/[^0-9 :-]/', '', (string) $end_date );
		global $wpdb;
		$table          = $wpdb->prefix . 'bwf_contact';
		$date_col       = "creation_date";
		$interval_query = '';
		$group_by       = '';
		$order_by       = ' id ';
		$where          = '';

		if ( 'interval' === $is_interval ) {
			$get_interval   = self::get_interval_format_query( $interval, $date_col );
			$interval_query = $get_interval['interval_query'];
			$interval_group = $get_interval['interval_group'];
			$group_by       = "GROUP BY " . $interval_group;
			$order_by       = ' time_interval ';
		}

		if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
			$where = $wpdb->prepare( " AND " . $date_col . " >= %s AND `" . $date_col . "` <= %s ", $start_date . ' 00:00:00', $end_date . ' 23:59:59' ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$where .= " AND  email != '' AND email IS NOT NULL ";

		$base_query = "SELECT  count(id) as contact_counts" . $interval_query . "  FROM `" . $table . "` WHERE 1=1 " . $where . "" . $group_by . " ORDER BY " . $order_by . " ASC";

		return $wpdb->get_var( $base_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- interval format stripped to date-format chars in date_format(); tables from $wpdb->prefix
	}

	/**
	 * @param $diff_time
	 *
	 * @return DateTime
	 * @throws Exception
	 */
	public static function default_date( $diff_time = 0 ) {
		$now      = time();
		$datetime = new DateTime();
		if ( $diff_time > 0 ) {
			$week_back = $now - $diff_time;
			$datetime->setTimestamp( $week_back );
		}
		$datetime->setTimezone( new DateTimeZone( wp_timezone_string() ) );

		return $datetime;
	}

	/**
	 * @param $interval
	 * @param $table_col
	 *
	 * @return array
	 */
	public static function get_interval_format_query( $interval, $table_col ) {
		$interval_type = self::date_format( $interval );
		$avg           = ( $interval === 'day' ) ? 1 : 0;
		if ( 'YEAR' === $interval_type ) {
			$interval = ", YEAR( " . $table_col . " ) ";
			$avg      = 365;
		} elseif ( 'QUARTER' === $interval_type ) {
			$interval = ", CONCAT( YEAR( " . $table_col . " ), '-', QUARTER( " . $table_col . " ) ) ";
			$avg      = 90;
		} elseif ( '%x-%v' === $interval_type ) {
			$first_day_of_week = absint( get_option( 'start_of_week' ) );

			if ( 1 === $first_day_of_week ) {
				$interval = ", DATE_FORMAT( " . $table_col . ", '" . $interval_type . "' )";
			} else {
				$interval = ", CONCAT( YEAR( " . $table_col . " ), '-', LPAD( FLOOR( ( DAYOFYEAR( " . $table_col . " ) + ( ( DATE_FORMAT( MAKEDATE( YEAR( " . $table_col . " ), 1 ), '%w' ) - $first_day_of_week + 7 ) % 7 ) - 1 ) / 7 ) + 1, 2, '0' ) )";
			}
			$avg = 7;
		} else {
			$interval = ", DATE_FORMAT( " . $table_col . ", '" . $interval_type . "' )";
		}

		$interval       .= " as time_interval ";
		$interval_group = " `time_interval` ";

		return array(
			'interval_query' => $interval,
			'interval_group' => $interval_group,
			'interval_avg'   => $avg,
		);
	}

	/**
	 * @param $start
	 * @param $end
	 * @param $interval
	 *
	 * @return array
	 * @throws Exception
	 */
	public static function intervals_between( $start, $end, $interval ) {
		switch ( $interval ) {
			case 'hour':
				$interval_type = 'PT60M';
				$format        = 'Y-m-d H';
				break;
			case 'day':
				$interval_type = "P1D";
				$format        = 'Y-m-d';
				break;
			case 'month':
				$interval_type = "P1M";
				$format        = 'Y-m';
				break;
			case 'quarter':
				$interval_type = "P3M";
				$format        = 'Y-m';
				break;
			case 'year':
				$interval_type = "P1Y";
				$format        = 'Y';
				break;
			default:
				$interval_type = "P1W";
				$format        = 'W';
				break;
		}

		$result = array();

		// Variable that store the date interval
		// of period 1 day
		$period = new DateInterval( $interval_type );

		$realEnd = new DateTime( $end );

		$realEnd->add( $period );

		$period   = new DatePeriod( new DateTime( $start ), $period, $realEnd );
		$date_end = date_create( $end );
		$count    = iterator_count( $period );

		if ( 'week' !== $interval && 'day' !== $interval ) {
			$count = $count - 1;
		}

		foreach ( $period as $date ) {
			if ( $count >= 1 ) {
				$new_interval = array();

				if ( 'day' === $interval && $date_end->format( 'Y-m-d' ) < $date->format( 'Y-m-d' ) ) {
					$count --;
					continue;
				}

				if ( 'day' === $interval || 'hour' === $interval ) {
					$new_interval['start_date'] = $date->format( self::$sql_datetime_format );
					$new_interval['end_date']   = $date->format( 'Y-m-d 23:59:59' );
				} else {
					$new_interval['start_date'] = self::maybe_first_date( $date, $format );
					$new_interval['end_date']   = ( $count > 1 ) ? self::maybe_last_date( $date, $format ) : $date_end->format( self::$sql_datetime_format );
				}
				if ( 'week' === $interval ) {
					$year                          = $date->format( 'Y' );
					$new_interval['time_interval'] = $year . '-' . $date->format( $format );
				} else if ( 'quarter' === $interval ) {
					$year                          = $date->format( 'Y' );
					$month                         = $date->format( 'm' );
					$yearQuarter                   = ceil( $month / 3 );
					$new_interval['time_interval'] = $year . '-' . $yearQuarter;
				} else {
					$new_interval['time_interval'] = $date->format( $format );
				}

				$result[] = $new_interval;
			}
			$count --;
		}

		return $result;
	}

	/**
	 * @param $interval
	 *
	 * @return mixed|void
	 */
	public static function date_format( $interval ) {
		switch ( $interval ) {
			case 'hour':
				$format = '%Y-%m-%d %H';
				break;
			case 'day':
				$format = '%Y-%m-%d';
				break;
			case 'month':
				$format = '%Y-%m';
				break;
			case 'quarter':
				$format = 'QUARTER';
				break;
			case 'year':
				$format = 'YEAR';
				break;
			default:
				$format = '%x-%v';
				break;
		}

		$format = apply_filters( 'bwfan_api_date_format_' . $interval, $format, $interval );

		/** Placed inside DATE_FORMAT( col, '...' ) in SQL, so keep only MySQL date-format characters */
		return is_string( $format ) ? preg_replace( '/[^%A-Za-z0-9 :\/._,-]/', '', $format ) : $format;
	}

	/**
	 * @param $start_date
	 * @param $end_date
	 * @param $is_interval
	 * @param $interval
	 *
	 * @return array|object|null
	 */
	public static function get_total_engagement_sents( $start_date, $end_date, $is_interval, $interval ) {
		$start_date = preg_replace( '/[^0-9 :-]/', '', (string) $start_date );
		$end_date   = preg_replace( '/[^0-9 :-]/', '', (string) $end_date );
		global $wpdb;
		$table          = $wpdb->prefix . 'bwfan_engagement_tracking';
		$date_col       = "created_at";
		$interval_query = '';
		$group_by       = '';
		$order_by       = ' ID ';
		$where          = '';
		if ( 'interval' === $is_interval ) {
			$get_interval   = self::get_interval_format_query( $interval, $date_col );
			$interval_query = $get_interval['interval_query'];
			$interval_group = $get_interval['interval_group'];
			$group_by       = "GROUP BY " . $interval_group;
			$order_by       = ' time_interval ';
		}

		if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
			$where = $wpdb->prepare( " AND " . $date_col . " >= %s AND `" . $date_col . "` <= %s ", $start_date . ' 00:00:00', $end_date . ' 23:59:59' ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$arr = [];

		$query  = "SELECT count(`ID`) FROM `" . $table . "` WHERE 1 = 1 " . $where . " AND `mode` = 1 AND `c_status` = 2 " . $interval_query;
		$query  .= ( ! empty( $group_by ) ) ? " " . $group_by . " ORDER BY " . $order_by . " ASC" : '';
		$result = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- interval format stripped to date-format chars in date_format(); tables from $wpdb->prefix

		$arr[0]['email_sents'] = $result;

		$query  = "SELECT count(`ID`) FROM `" . $table . "` WHERE 1 = 1 " . $where . " AND `mode` = 2 AND `c_status` = 2 " . $interval_query;
		$query  .= ( ! empty( $group_by ) ) ? " " . $group_by . " ORDER BY " . $order_by . " ASC" : '';
		$result = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- interval format stripped to date-format chars in date_format(); tables from $wpdb->prefix

		$arr[0]['sms_sent'] = $result;

		return $arr;
	}

	/**
	 * @param $start_date
	 * @param $end_date
	 * @param $is_interval
	 * @param $interval
	 *
	 * @return array|object|null
	 */
	public static function get_total_email_open( $start_date, $end_date, $is_interval, $interval ) {
		$start_date = preg_replace( '/[^0-9 :-]/', '', (string) $start_date );
		$end_date   = preg_replace( '/[^0-9 :-]/', '', (string) $end_date );
		global $wpdb;
		$table          = $wpdb->prefix . 'bwfan_engagement_tracking';
		$date_col       = "created_at";
		$interval_query = '';
		$group_by       = '';
		$order_by       = ' ID ';
		$where          = '';
		if ( 'interval' === $is_interval ) {
			$get_interval   = self::get_interval_format_query( $interval, $date_col );
			$interval_query = $get_interval['interval_query'];
			$interval_group = $get_interval['interval_group'];
			$group_by       = "GROUP BY " . $interval_group;
			$order_by       = ' time_interval ';
		}

		if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
			$where = $wpdb->prepare( " AND " . $date_col . " >= %s AND `" . $date_col . "` <= %s ", $start_date . ' 00:00:00', $end_date . ' 23:59:59' ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$base_query = "SELECT  sum( open ) as email_open" . $interval_query . "  FROM `" . $table . "` WHERE 1 = 1 " . $where . " and c_status = 2 " . $group_by . " ORDER BY " . $order_by . " ASC";

		return $wpdb->get_results( $base_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- interval format stripped to date-format chars in date_format(); tables from $wpdb->prefix
	}

	/**
	 * @param $start_date
	 * @param $end_date
	 * @param $is_interval
	 * @param $interval
	 *
	 * @return array|object|null
	 */
	public static function get_total_email_click( $start_date, $end_date, $is_interval, $interval ) {
		$start_date = preg_replace( '/[^0-9 :-]/', '', (string) $start_date );
		$end_date   = preg_replace( '/[^0-9 :-]/', '', (string) $end_date );
		global $wpdb;

		$table          = $wpdb->prefix . 'bwfan_engagement_tracking';
		$date_col       = "created_at";
		$interval_query = '';
		$group_by       = '';
		$order_by       = ' ID ';
		$where          = '';
		if ( 'interval' === $is_interval ) {
			$get_interval   = self::get_interval_format_query( $interval, $date_col );
			$interval_query = $get_interval['interval_query'];
			$interval_group = $get_interval['interval_group'];
			$group_by       = "GROUP BY " . $interval_group;
			$order_by       = ' time_interval ';
		}

		if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
			$where = $wpdb->prepare( " AND " . $date_col . " >= %s AND `" . $date_col . "` <= %s ", $start_date . ' 00:00:00', $end_date . ' 23:59:59' ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$base_query = "SELECT  sum( click ) as email_click" . $interval_query . "  FROM `" . $table . "` WHERE 1 = 1 " . $where . " and c_status = 2 " . $group_by . " ORDER BY " . $order_by . " ASC";

		return $wpdb->get_results( $base_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- interval format stripped to date-format chars in date_format(); tables from $wpdb->prefix
	}

	/**
	 * @param $start_date
	 * @param $end_date
	 * @param $is_interval
	 * @param $interval
	 *
	 * @return array|object|null
	 */
	public static function get_total_orders( $start_date, $end_date, $is_interval, $interval ) {
		$start_date = preg_replace( '/[^0-9 :-]/', '', (string) $start_date );
		$end_date   = preg_replace( '/[^0-9 :-]/', '', (string) $end_date );
		global $wpdb;

		$table          = $wpdb->prefix . 'bwfan_conversions';
		$date_col       = "c.date";
		$interval_query = '';
		$group_by       = '';
		$order_by       = ' c.ID';
		$where          = '';
		if ( 'interval' === $is_interval ) {
			$get_interval   = self::get_interval_format_query( $interval, $date_col );
			$interval_query = $get_interval['interval_query'];
			$interval_group = $get_interval['interval_group'];
			$group_by       = "GROUP BY " . $interval_group;
			$order_by       = ' time_interval ';
		}

		if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
			$where = $wpdb->prepare( " AND " . $date_col . " >= %s AND " . $date_col . " <= %s ", $start_date . ' 00:00:00', $end_date . ' 23:59:59' ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$base_query = "SELECT  count( c.id ) as total_orders, sum( c.wctotal ) as total_revenue " . $interval_query . "  FROM  $table as c WHERE 1 = 1 " . $where . " and c.wcid != 0 " . $group_by . " ORDER BY " . $order_by . " ASC";

		return $wpdb->get_results( $base_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- interval format stripped to date-format chars in date_format(); tables from $wpdb->prefix
	}

	/**
	 * @param $newDate
	 * @param $period
	 *
	 * @return mixed
	 */
	public static function maybe_first_date( $newDate, $period ) {
		switch ( $period ) {
			case 'Y':
				$newDate->modify( 'first day of january ' . $newDate->format( 'Y' ) );
				break;
			case 'quarter':
				$month = $newDate->format( 'n' );
				if ( $month < 4 ) {
					$newDate->modify( 'first day of january ' . $newDate->format( 'Y' ) );
				} elseif ( $month > 3 && $month < 7 ) {
					$newDate->modify( 'first day of april ' . $newDate->format( 'Y' ) );
				} elseif ( $month > 6 && $month < 10 ) {
					$newDate->modify( 'first day of july ' . $newDate->format( 'Y' ) );
				} elseif ( $month > 9 ) {
					$newDate->modify( 'first day of october ' . $newDate->format( 'Y' ) );
				}
				break;
			case 'Y-m':
				$newDate->modify( 'first day of this month' );
				break;
			case 'W':
				$newDate->modify( ( $newDate->format( 'w' ) === '0' ) ? self::first_day_of_week() . ' last week' : self::first_day_of_week() . ' this week' );
				break;
		}

		return $newDate->format( self::$sql_datetime_format );
	}

	/**
	 * @param $newDate
	 * @param $period
	 *
	 * @return mixed
	 */
	public static function maybe_last_date( $newDate, $period ) {
		switch ( $period ) {
			case 'Y':
				$newDate->modify( 'last day of december ' . $newDate->format( 'Y' ) );
				break;
			case 'quarter':
				$month = $newDate->format( 'n' );

				if ( $month < 4 ) {
					$newDate->modify( 'last day of march ' . $newDate->format( 'Y' ) );
				} elseif ( $month > 3 && $month < 7 ) {
					$newDate->modify( 'last day of june ' . $newDate->format( 'Y' ) );
				} elseif ( $month > 6 && $month < 10 ) {
					$newDate->modify( 'last day of september ' . $newDate->format( 'Y' ) );
				} elseif ( $month > 9 ) {
					$newDate->modify( 'last day of december ' . $newDate->format( 'Y' ) );
				}
				break;
			case 'Y-m':
				$newDate->modify( 'last day of this month' );
				break;
			case 'W':
				$newDate->modify( ( $newDate->format( 'w' ) === '0' ) ? 'now' : self::last_day_of_week() . ' this week' );
				break;
		}

		return $newDate->format( 'Y-m-d 23:59:59 ' );
	}

	/**
	 * @return string
	 */
	public static function first_day_of_week() {
		$days_of_week = array(
			1 => 'monday',
			2 => 'tuesday',
			3 => 'wednesday',
			4 => 'thursday',
			5 => 'friday',
			6 => 'saturday',
			7 => 'sunday',
		);

		$day_number = absint( get_option( 'start_of_week' ) );

		return $days_of_week[ $day_number ];
	}

	/**
	 * @return string
	 */
	public static function last_day_of_week() {
		$days_of_week = array(
			1 => 'sunday',
			2 => 'saturday',
			3 => 'friday',
			4 => 'thursday',
			5 => 'wednesday',
			6 => 'tuesday',
			7 => 'monday',
		);

		$day_number = absint( get_option( 'start_of_week' ) );

		return $days_of_week[ $day_number ];
	}

	/**
	 * @param $all_data
	 * @param $interval_key
	 * @param $current_interval
	 *
	 * @return array|false
	 */
	public static function maybe_interval_exists( $all_data, $interval_key, $current_interval ) {
		if ( ! is_array( $all_data ) || 0 === count( $all_data ) ) {
			return false;
		}
		foreach ( $all_data as $data ) {
			if ( isset( $data[ $interval_key ] ) && $current_interval === $data[ $interval_key ] ) {
				return array( $data );
			}
		}

		return false;
	}

	/**
	 * @param $datetime_string
	 *
	 * @return DateTime
	 * @throws Exception
	 */
	public static function convert_local_datetime_to_gmt( $datetime_string ) {
		$datetime = new DateTime( $datetime_string, new \DateTimeZone( wp_timezone_string() ) );
		$datetime->setTimezone( new DateTimeZone( 'GMT' ) );

		return $datetime;
	}

	public static function get_recent_contacts() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'bwf_contact';
		$where      = "( email != '' OR email != NULL )";

		$query = "SELECT `ID`, `f_name`, `l_name`, `email`, `contact_no`, `creation_date` FROM $table_name WHERE $where ORDER BY `creation_date` DESC LIMIT 0,9";

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; table name from $wpdb->prefix
	}


	public static function get_recent_unsubsribers() {
		global $wpdb;

		$contact_table     = $wpdb->prefix . 'bwf_contact';
		$unsubscribe_table = $wpdb->prefix . 'bwfan_message_unsubscribe';

		$query = "SELECT sub.recipient as email, COALESCE(con.id, 0) as ID, COALESCE(con.f_name, '') as f_name, COALESCE(con.l_name, '') as l_name, sub.c_date AS creation_date from $unsubscribe_table as sub LEFT JOIN $contact_table as con ON sub.recipient = con.email ORDER BY sub.ID DESC LIMIT 0,9";

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; table names from $wpdb->prefix
	}

	/**
	 * @return array|stdClass[]
	 */
	/**
	 * @return array|stdClass[]
	 */
	public static function get_top_automations() {
		$automations = BWFAN_Model_Automations::get_top_automations();

		if ( empty( $automations ) ) {
			return [];
		}

		return array_map( function ( $automation ) {
			$automation_id       = $automation['aid'];
			$automation['name']  = ! empty( $automation['name'] ) ? $automation['name'] : BWFAN_Model_Automationmeta::get_meta( $automation_id, 'title' );
			$automation['event'] = BWFAN_Common::get_automation_event_name( $automation['event'] );

			return $automation;
		}, $automations );
	}

	/**
	 * Get total email sent via email steps
	 *
	 * @return int
	 */
	public static function get_total_sent_email() {
		/** Get all emails step ids */
		$step_ids = BWFAN_Model_Automation_Step::get_email_step_ids();

		return BWFAN_Model_Automation_Contact_Trail::get_completed_email_steps_count( $step_ids );
	}

	/**
	 * Get recent conversions
	 *
	 * @return array|object|stdClass[]|null
	 */
	public static function get_recent_conversions() {
		global $wpdb;

		$query = "SELECT con.cid AS ID, c.email,c.f_name,c.l_name,con.date AS creation_date FROM {$wpdb->prefix}bwfan_conversions AS con JOIN {$wpdb->prefix}bwf_contact AS c ON con.cid = c.id ORDER BY con.ID DESC LIMIT 0, 9";

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; table names from $wpdb->prefix
	}
}
