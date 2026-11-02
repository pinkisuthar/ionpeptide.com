<?php
/**
 * Report Stats REST API abstract controller
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

use \Automattic\WooCommerce\Admin\API\Reports\TimeInterval;

if ( ! class_exists( 'YITH_WCAF_Abstract_REST_Reports_Stats_Controller' ) ) {
	/**
	 * Report Stats REST API abstract controller class.
	 *
	 * @extends YITH_WCAF_Abstract_REST_Reports_Controller
	 */
	abstract class YITH_WCAF_Abstract_REST_Reports_Stats_Controller extends YITH_WCAF_Abstract_REST_Reports_Controller {

		/**
		 * Array of intervals for current stats calculation
		 *
		 * @var array
		 */
		protected $intervals = array();

		/**
		 * Register the number of intervals for the request, without any pagination applied
		 *
		 * @var int
		 */
		protected $intervals_count;

		/**
		 * A list of statistics that will be provided for each segment, for each interval.
		 *
		 * @var array
		 */
		protected $stats = array();

		/**
		 * Get the query params for collections.
		 *
		 * @return array
		 */
		public function get_collection_params() {
			$params = parent::get_collection_params();

			// remove unused params.
			unset( $params['extended_info'] );

			// add segmentation param.
			$params['segmentby'] = array(
				'description'       => _x( 'Segment the response by an additional constraint.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'string',
				'validate_callback' => 'rest_validate_request_arg',
			);

			return $params;
		}

		/* === QUERY HANDLING === */

		/**
		 * Returns an array of query args starting from request
		 *
		 * @param WP_REST_Request $request Request object.
		 *
		 * @return array Array of arguments for the query.
		 */
		public function get_query_args( $request ) {
			$query_args = parent::get_query_args( $request );

			// add intervals params from request.
			$query_args = $this->get_intervals_query_args( $query_args, $request );

			// add order-by param.
			$query_args['orderby'] = 'time_interval';

			return $query_args;
		}

		/**
		 * Converts page/per_page parameters in limit/offset, that can be used by data store to paginate results.
		 *
		 * @param array           $query_args Arguments for the query.
		 * @param WP_REST_Request $request    Request object.
		 *
		 * @return array Array of filtered query arguments.
		 */
		public function get_pagination_query_args( $query_args, $request ) {
			// no pagination is needed for intervals.
			return $query_args;
		}

		/* === INTERVALS HANDLING === */

		/**
		 * Build and add to query args an array of intervals for which query should collect data
		 *
		 * @param array           $query_args Query arguments.
		 * @param WP_REST_Request $request    Request object.
		 *
		 * @return array Array of affiliates id, ordered as expected by query_args
		 */
		protected function get_intervals_query_args( $query_args, $request ) {
			$this->maybe_populate_intervals( $request );

			$query_args['group_by']  = 'time_interval';
			$query_args['intervals'] = $this->intervals;

			return $query_args;
		}

		/**
		 * Retrieve intervals for current request, if it didn't already
		 *
		 * @param WP_REST_Request $request Request object.
		 *
		 * @return array Array of intervals, formatted as described in {@see \YITH_WCAF_Abstract_REST_Reports_Stats_Controller::populate_intervals]
		 */
		protected function maybe_populate_intervals( $request ) {
			if ( ! $this->intervals ) {
				$this->populate_intervals( $request );
			}

			return $this->intervals;
		}

		/**
		 * Retrieve intervals for current request
		 *
		 * @param WP_REST_Request $request Request object.
		 *
		 * @return array Array of intervals, formatted as follows
		 * [
		 *     '2022-01-25' => [
		 *         'time_interval'  => '2022-01-25',                             // unique interval identifier
		 *         'start_date'     => '2022-01-25 00:00:00',                    // mysql start date
		 *         'start_datetime' => WC_DateTime,                              // DateTime object for start date
		 *         'end_date'       => '2022-01-25 23:59:59',                    // mysql end date
		 *         'end_datetime'   => WC_DateTime,                              // DateTime object for end date
		 *         'db_interval'    => '2022-01-25 00:00:00-2022-01-25 23:59:59' // interval as formatted in db results
		 *     ],
		 *     ...
		 * ]
		 */
		protected function populate_intervals( $request ) {
			$local_tz = new \DateTimeZone( wc_timezone_string() );

			try {
				$before = isset( $request['before'] ) ? new WC_DateTime( $request['before'], $local_tz ) : TimeInterval::default_before();
				$after  = isset( $request['after'] ) ? new WC_DateTime( $request['after'], $local_tz ) : TimeInterval::default_after();
			} catch ( Exception $e ) {
				return array();
			}

			$intervals      = array();
			$time_interval  = isset( $request['interval'] ) ? $request['interval'] : 'day';
			$start_datetime = $after;
			$end_datetime   = $before;

			while ( $start_datetime <= $end_datetime ) {
				$next_start = TimeInterval::iterate( $start_datetime, $time_interval );
				$time_id    = TimeInterval::time_interval_id( $time_interval, $start_datetime );
				$interval   = array(
					'time_interval'  => $time_id,
					'start_datetime' => $start_datetime,
					'start_date'     => $start_datetime->format( 'Y-m-d H:i:s' ),
				);

				if ( $next_start > $end_datetime ) {
					$interval['end_datetime'] = $end_datetime;
					$interval['end_date']     = $end_datetime->format( 'Y-m-d H:i:s' );
				} else {
					$prev_end_timestamp = (int) $next_start->format( 'U' ) - 1;
					$prev_end           = new \DateTime();
					$prev_end->setTimestamp( $prev_end_timestamp );
					$prev_end->setTimezone( $local_tz );

					$interval['end_datetime'] = $prev_end;
					$interval['end_date']     = $prev_end->format( 'Y-m-d H:i:s' );
				}

				$interval['db_interval'] = "{$interval['start_date']}-{$interval['end_date']}";

				$intervals[ $time_id ] = $interval;

				$start_datetime = $next_start;
			}

			$this->intervals_count = count( $intervals );

			if ( isset( $request['per_page'] ) ) {
				$limit  = (int) $request['per_page'];
				$page   = isset( $request['page'] ) ? (int) $request['page'] : 1;
				$offset = ( $page - 1 ) * $limit;

				$intervals = array_slice( $intervals, $offset, $limit );
			}

			$this->intervals = $intervals;

			return $this->intervals;
		}

		/* === SEGMENTS HANDLING === */

		/**
		 * Returns segments to apply to current request
		 * By default it will return a single segment, but child classed may override this method to change its behaviour.
		 *
		 * @param WP_REST_Request $request Request object.
		 *
		 * @return array Array of formatted segments; result is formatted as follows
		 * [
		 *     [
		 *         'segment_id'    => 147,               // unique segment id
		 *         'segment_label' => 'John Doe (#147)', // segment label
		 *         'query_args'    => [ ... ],           // array of query arguments to apply for current segment
		 *     ],
		 *     ...
		 * ]
		 */
		protected function get_segments( $request ) {
			$segments = array();

			$segments[] = array(
				'segment_id'    => false,
				'segment_label' => '',
				'query_args'    => $this->get_query_args( $request ),
			);

			return $segments;
		}

		/**
		 * Retrieves data for each segment defined {@see \YITH_WCAF_Abstract_REST_Reports_Stats_Controller::get_segment_data},
		 * and combine those in one single result {@see \YITH_WCAF_Abstract_REST_Reports_Stats_Controller::get_combined_data}
		 *
		 * @param WP_REST_Request $request Request object.
		 *
		 * @return array Array of combined results for current request and segments;
		 *               format will be the same returned by {@see \YITH_WCAF_Abstract_REST_Reports_Stats_Controller::get_combined_data}
		 */
		protected function get_segmented_data( $request ) {
			$segments = $this->get_segments( $request );

			foreach ( $segments as &$segment ) {
				$segment['data'] = $this->get_segment_data( $segment, $request );
			}

			return $this->get_combined_data( $segments, $request );
		}

		/**
		 * Returns totals for each of the segments
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return array Array of segmented totals.
		 */
		protected function get_segmented_totals( $request ) {
			return $this->get_default_stats( $request );
		}

		/**
		 * Merge together data for the passed segments, and returns a result set, containing segments as well as subtotals, subdivided by intervals
		 *
		 * @param array           $segments Array of segments; it uses a slightly different version of the segments returned by
		 *                                  {@see \YITH_WCAF_Abstract_REST_Reports_Stats_Controller::get_segments}, that also include 'data'
		 *                                  index, containing data for that segment, subdivided by intervals.
		 * @param WP_REST_Request $request  Request object.
		 *
		 * @return array Array of formatted data. It could be formatted as follows
		 * [
		 *     '2021-01-25' => [
		 *         'interval'       => '2022-01-25',                             // unique interval identifier
		 *         'start_date'     => '2022-01-25 00:00:00',                    // mysql start date
		 *         'start_date_gmt' => '2022-01-25 00:00:00',                    // same as before, but GMT
		 *         'end_date'       => '2022-01-25 00:00:00',                      // mysql end date
		 *         'end_date_gmt'   => '2022-01-25 00:00:00',                      // same as before, but GMT,
		 *         'subtotals'      => [
		 *             // stats subtotals for current interval and all segments...
		 *             'segments' => [
		 *                 'segment_id'    => 147,               // unique segment id
		 *                 'segment_label' => 'John Doe (#147)', // segment label
		 *                 'subtotals'     => [
		 *                     // stats subtotals for current interval and segment...
		 *                 ]
		 *             ]
		 *         ]
		 *     ],
		 *     ...
		 * ]
		 */
		protected function get_combined_data( $segments, $request ) {
			$single_segment = 1 === count( $segments ) && empty( $segments[0]['segment_id'] );
			$intervals      = $this->maybe_populate_intervals( $request );
			$combined_data  = array();
			$defaults       = $this->get_default_stats( $request );

			foreach ( $intervals as $interval_id => $interval ) {
				$combined_data[ $interval_id ] = array(
					'interval'       => $interval_id,
					'date_start'     => $interval['start_date'],
					'date_start_gmt' => gmdate( 'Y-m-d H:i:s', $interval['start_datetime']->getTimestamp() ),
					'date_end'       => $interval['end_date'],
					'date_end_gmt'   => gmdate( 'Y-m-d H:i:s', $interval['end_datetime']->getTimestamp() ),
					'subtotals'      => array(),
				);

				if ( $single_segment ) {
					$interval_subtotals = isset( $segments[0]['data'][ $interval_id ] ) ? $segments[0]['data'][ $interval_id ] : array();

					$combined_data[ $interval_id ]['subtotals'] = wp_parse_args( $interval_subtotals, $defaults );
				} else {
					$combined_data[ $interval_id ]['subtotals'] = array(
						'segments' => array(),
					);

					foreach ( $segments as $segment ) {
						$segment_interval_subtotals = isset( $segment['data'][ $interval_id ] ) ? $segment['data'][ $interval_id ] : array();

						$combined_data[ $interval_id ]['subtotals']['segments'][] = array(
							'segment_id'    => $segment['segment_id'],
							'segment_label' => $segment['segment_label'],
							'subtotals'     => wp_parse_args( $segment_interval_subtotals, $defaults ),
						);
					}

					$combined_data[ $interval_id ]['subtotals'] = array_merge(
						$this->get_combined_totals( wp_list_pluck( $combined_data[ $interval_id ]['subtotals']['segments'], 'subtotals' ), $request ),
						$combined_data[ $interval_id ]['subtotals']
					);
				}
			}

			return $combined_data;
		}

		/**
		 * Combine together data from different intervals/segments to calculate totals
		 *
		 * @param array           $data_to_combine Stats to combine together.
		 * @param WP_REST_Request $request         Request object.
		 *
		 * @return array Array of combined stats.
		 */
		protected function get_combined_totals( $data_to_combine, $request ) {
			$totals = $this->get_default_stats( $request );

			foreach ( $totals as $stat => $empty ) {
				$total = $this->calculate_stat_total( $stat, wp_list_pluck( $data_to_combine, $stat ) );

				$totals[ $stat ] = $total;
			}

			return $totals;
		}

		/**
		 * Combine data for a specific stat
		 *
		 * @param string $stat            Stat whose data needs to be combined.
		 * @param array  $data_to_combine Array of data to combine for current stat.
		 *
		 * @return scalar Single value, combination of stat data.
		 */
		protected function calculate_stat_total( $stat, $data_to_combine ) {
			return array_sum( $data_to_combine );
		}

		/**
		 * Returns an aryay of default stats to use for empty segments/intervals
		 * Default value for every stat is 0, but method may be overridden to use custom values for specific stats.
		 *
		 * @param WP_REST_Request $request Request object.
		 *
		 * @return array Array of default stats.
		 */
		protected function get_default_stats( $request ) {
			$stats = isset( $request['fields'] ) ? array_intersect( $request['fields'], $this->stats ) : $this->stats;

			return array_combine( $stats, array_pad( array(), count( $stats ), 0 ) );
		}

		/**
		 * Returns data for a specific segment (child classes must override this)
		 *
		 * @param array           $segment A specific segment, as formatted by {@see \YITH_WCAF_Abstract_REST_Reports_Stats_Controller::get_segments}.
		 * @param WP_REST_Request $request Request object.
		 *
		 * @return array Array of stats for current segment.
		 */
		protected function get_segment_data( $segment, $request ) {
			return $this->get_default_stats( $request );
		}

		/* === PREPARE RESPONSE === */

		/**
		 * Init pagination params for the response
		 *
		 * @param array            $stats      Array of stats, grouped by intervals.
		 * @param WP_REST_Request  $request    Request object.
		 * @param WP_REST_Response $response   Response object.
		 *
		 * @return WP_REST_Response Response object with pagination parameters.
		 */
		public function prepare_pagination_params( $stats, $request, $response ) {
			$intervals = $this->maybe_populate_intervals( $request );
			$per_page  = (int) $request['per_page'];
			$page      = (int) $request['page'];
			$items     = $this->intervals_count;
			$max_pages = ceil( $items / $per_page );

			$response->header( 'X-WP-Total', $items );
			$response->header( 'X-WP-TotalPages', $max_pages );

			$base = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ) );

			if ( $page > 1 ) {
				$prev_page = $page - 1;
				if ( $prev_page > $max_pages ) {
					$prev_page = $max_pages;
				}
				$prev_link = add_query_arg( 'page', $prev_page, $base );
				$response->link_header( 'prev', $prev_link );
			}

			if ( $max_pages > $page ) {
				$next_page = $page + 1;
				$next_link = add_query_arg( 'page', $next_page, $base );
				$response->link_header( 'next', $next_link );
			}

			return $response;
		}
	}
}
