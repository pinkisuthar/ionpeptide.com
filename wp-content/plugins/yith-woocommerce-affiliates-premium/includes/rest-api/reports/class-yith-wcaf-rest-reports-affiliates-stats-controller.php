<?php
/**
 * REST API Affiliates Stats controller.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\API\Reports
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_REST_Reports_Affiliates_Stats_Controller' ) ) {
	/**
	 * REST API Reports controller class.
	 */
	class YITH_WCAF_REST_Reports_Affiliates_Stats_Controller extends YITH_WCAF_Abstract_REST_Reports_Stats_Controller {

		/**
		 * Mapping between external parameter name and name used in query class.
		 *
		 * @var array
		 */
		protected $param_mapping = array(
			'affiliates' => 'affiliate_id',
			'fields'     => 'stats',
		);

		/**
		 * Part of the url after $this::rest_base.
		 *
		 * @var string
		 */
		protected $rest_path = 'affiliates/stats';

		/**
		 * A list of statistics that will be provided for each segment, for each interval.
		 *
		 * @var array
		 */
		protected $stats = array(
			'commissions_count',
			'commissions_total_earnings',
			'commissions_total_refunds',
			'commissions_total_paid',
			'commissions_store_gross_total',
			'commissions_store_net_total',
			'clicks_count',
			'clicks_count_converted',
			'clicks_conversion_rate',
			'clicks_avg_conversion_time',
		);

		/**
		 * Get all reports.
		 *
		 * @param \WP_REST_Request $request Request data.
		 * @return \WP_Rest_Response|\WP_Error
		 */
		public function get_items( $request ) {
			$stats = $this->get_segmented_data( $request );
			$data  = array();

			foreach ( $stats as $interval_data ) {
				$item                = $this->prepare_item_for_response( $interval_data, $request );
				$data['intervals'][] = $this->prepare_response_for_collection( $item );
			}

			$data['totals'] = $this->get_segmented_totals( $request );

			$response = rest_ensure_response( $data );
			$response = $this->prepare_pagination_params( $stats, $request, $response );

			return $response;
		}

		/**
		 * Get the Report's schema, conforming to JSON Schema.
		 *
		 * @return array
		 */
		public function get_item_schema() {
			$data_values = array(
				'commissions_count'             => array(
					'type'        => 'integer',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'description' => _x( 'Count of commissions created for the affiliate in the specified interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
				),
				'commissions_total_earnings'    => array(
					'type'        => 'number',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'description' => _x( 'Affiliate total commissions earnings in the specified interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
				),
				'commissions_total_refunds'     => array(
					'type'        => 'number',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'description' => _x( 'Affiliate total refunded commissions in the specified interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
				),
				'commissions_total_paid'        => array(
					'type'        => 'number',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'description' => _x( 'Affiliate total paid commissions in the specified interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
				),
				'commissions_store_gross_total' => array(
					'type'        => 'number',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'description' => _x( 'Store gross earnings produced by affiliate in the specified interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
				),
				'commissions_store_net_total'   => array(
					'type'        => 'number',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'description' => _x( 'Store net earnings produced by affiliate in the specified interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
				),
				'clicks_count'                  => array(
					'type'        => 'integer',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'description' => _x( 'Number of visits registered for the affiliate in the specified interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
				),
				'clicks_count_converted'        => array(
					'type'        => 'integer',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'description' => _x( 'Number of visits converted for the affiliate in the specified interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
				),
				'clicks_conversion_rate'        => array(
					'type'        => 'integer',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'description' => _x( 'Percentage of converted visits registered for the affiliate in the specified interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
				),
				'clicks_avg_conversion_time'    => array(
					'type'        => 'integer',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'description' => _x( 'Average conversion time for affiliate\'s visit in the specified interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
				),
			);

			$segments = array(
				'segments' => array(
					'description' => _x( 'Reports data grouped by segment condition.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'segment_id'    => array(
								'description' => _x( 'Segment identificator.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
								'type'        => 'integer',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'segment_label' => array(
								'description' => _x( 'Human readable segment label, either product or variation name.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
								'enum'        => array( 'day', 'week', 'month', 'year' ),
							),
							'subtotals'     => array(
								'description' => _x( 'Interval subtotals.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
								'type'        => 'object',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
								'properties'  => $data_values,
							),
						),
					),
				),
			);

			$totals = array_merge( $data_values, $segments );

			$schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'report_affiliate_stats',
				'type'       => 'object',
				'properties' => array(
					'totals'    => array(
						'description' => _x( 'Totals data.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
						'properties'  => $totals,
					),
					'intervals' => array(
						'description' => _x( 'Reports data grouped by intervals.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'array',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'interval'       => array(
									'description' => _x( 'Type of interval.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
									'type'        => 'string',
									'context'     => array( 'view', 'edit' ),
									'readonly'    => true,
									'enum'        => array( 'day', 'week', 'month', 'year' ),
								),
								'date_start'     => array(
									'description' => _x( "The date the report starts, in the site's timezone.", '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
									'type'        => 'date-time',
									'context'     => array( 'view', 'edit' ),
									'readonly'    => true,
								),
								'date_start_gmt' => array(
									'description' => _x( 'The date the report starts, as GMT.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
									'type'        => 'date-time',
									'context'     => array( 'view', 'edit' ),
									'readonly'    => true,
								),
								'date_end'       => array(
									'description' => _x( "The date the report ends, in the site's timezone.", '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
									'type'        => 'date-time',
									'context'     => array( 'view', 'edit' ),
									'readonly'    => true,
								),
								'date_end_gmt'   => array(
									'description' => _x( 'The date the report ends, as GMT.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
									'type'        => 'date-time',
									'context'     => array( 'view', 'edit' ),
									'readonly'    => true,
								),
								'subtotals'      => array(
									'description' => _x( 'Interval subtotals.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
									'type'        => 'object',
									'context'     => array( 'view', 'edit' ),
									'readonly'    => true,
									'properties'  => $totals,
								),
							),
						),
					),
				),
			);

			return $this->add_additional_fields_schema( $schema );
		}

		/**
		 * Get the query params for collections.
		 *
		 * @return array
		 */
		public function get_collection_params() {
			$params = parent::get_collection_params();

			$params['affiliates'] = array(
				'description'       => _x( 'Limit results to items with specified product IDs.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'array',
				'sanitize_callback' => 'wp_parse_id_list',
				'validate_callback' => 'rest_validate_request_arg',
				'items'             => array(
					'type' => 'integer',
				),
			);
			$params['segmentby']  = array(
				'description'       => _x( 'Segment the response by an additional constraint.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'string',
				'enum'              => array(
					'affiliate',
				),
				'validate_callback' => 'rest_validate_request_arg',
			);
			$params['fields']     = array(
				'description'       => _x( 'Limit stats fields to the specified items.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'array',
				'sanitize_callback' => 'wp_parse_slug_list',
				'validate_callback' => 'rest_validate_request_arg',
				'items'             => array(
					'type' => 'string',
				),
			);

			return $params;
		}

		/* === QUERY HANDLING === */

		/**
		 * Filters default query arguments, to match a specific query context (note that this may differ from request context)
		 *
		 * @param string $query_context Query context.
		 * @param array  $query_args    Default query arguments, as retrieved by {@see \YITH_WCAF_Abstract_REST_Reports_Stats_Controller::get_query_args}.
		 *
		 * @return array Array of filtered query arguments
		 */
		public function get_query_args_by_context( $query_context, $query_args ) {
			if ( ! empty( $query_args['stats'] ) ) {
				$stats = array();

				foreach ( $query_args['stats'] as $stat ) {
					if ( 0 !== strpos( $stat, $query_context ) ) {
						continue;
					}

					$stats[] = str_replace( "{$query_context}_", '', $stat );
				}

				$query_args['stats'] = $stats;
			}

			return $query_args;
		}

		/* === SEGMENTS HANDLING === */

		/**
		 * Returns segments for current request
		 *
		 * @param WP_REST_Request $request Request object.
		 */
		protected function get_segments( $request ) {
			if ( ! isset( $request['segmentby'] ) || ! isset( $request['affiliates'] ) || 'affiliate' !== $request['segmentby'] ) {
				// if no segmentation is requested, return one single segment with current query args.
				$segments = parent::get_segments( $request );
			} else {
				// otherwise calculate segments depending on request data.
				$segments   = array();
				$query_args = $this->get_query_args( $request );
				$affiliates = $request['affiliates'];

				foreach ( $affiliates as $affiliate_id ) {
					$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate( $affiliate_id );

					if ( ! $affiliate ) {
						continue;
					}

					$segments[] = array(
						'segment_id'    => $affiliate_id,
						'segment_label' => $affiliate->get_formatted_name(),
						'query_args'    => array_merge(
							$query_args,
							array(
								'affiliate_id' => $affiliate_id,
							)
						),
					);
				}
			}

			return $segments;
		}

		/**
		 * Returns data for a specific segment
		 *
		 * @param array           $segment Array describing the segment; it contains id and label of the segment,
		 *                                 as well as query_args to use for that segment.
		 * @param WP_REST_Request $request Request object.
		 *
		 * @return array Array of db data for that specific interval.
		 */
		protected function get_segment_data( $segment, $request ) {
			$intervals    = $this->maybe_populate_intervals( $request );
			$segment_data = array();

			foreach ( array( 'commissions', 'clicks' ) as $context ) {
				try {
					$data_store = rtrim( $context, 's' );
					$data_store = WC_Data_Store::load( $data_store );

					$query_args = $this->get_query_args_by_context( $context, $segment['query_args'] );

					$stats = $data_store->get_stats( $query_args );
				} catch ( Exception $e ) {
					continue;
				}

				if ( ! empty( $stats ) ) {
					foreach ( $intervals as $interval_id => $interval ) {
						if ( ! isset( $segment_data[ $interval_id ] ) ) {
							$segment_data[ $interval_id ] = array();
						}

						$interval_stats = isset( $stats[ $interval['db_interval'] ] ) ? $stats[ $interval['db_interval'] ] : array();

						foreach ( $interval_stats as $stat => $value ) {
							if ( in_array( $stat, array( 'affiliate_id', 'time_interval' ), true ) ) {
								continue;
							}

							$segment_data[ $interval_id ][ "{$context}_{$stat}" ] = (float) $value;
						}
					}
				}
			}

			return $segment_data;
		}

		/**
		 * Returns totals for each of the segments
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return array Array of segmented totals.
		 */
		protected function get_segmented_totals( $request ) {
			$segments       = $this->get_segments( $request );
			$single_segment = 1 === count( $segments ) && empty( $segments[0]['segment_id'] );
			$defaults       = $this->get_default_stats( $request );

			$query_args = $this->get_query_args( $request );

			$query_args['orderby']   = '';
			$query_args['limit']     = 0;
			$query_args['group_by']  = ! $single_segment ? 'affiliate_id' : '';
			$query_args['intervals'] = array( $query_args['interval'] );

			foreach ( array( 'commissions', 'clicks' ) as $context ) {
				try {
					$data_store = rtrim( $context, 's' );
					$data_store = WC_Data_Store::load( $data_store );

					$context_query_args = $this->get_query_args_by_context( $context, $query_args );

					$stats = $data_store->get_stats( $context_query_args );
				} catch ( Exception $e ) {
					continue;
				}

				if ( ! empty( $stats ) ) {
					if ( ! $single_segment ) {
						$stats = array_combine( wp_list_pluck( $stats, 'affiliate_id' ), $stats );
					} else {
						$stats = array( $stats );
					}
				}

				foreach ( $segments as &$stat_segment ) {
					$segment_id = (int) $stat_segment['segment_id'];

					if ( ! isset( $stat_segment['data'] ) ) {
						$stat_segment['data'] = $defaults;
					}

					if ( ! isset( $stats[ $segment_id ] ) ) {
						continue;
					}

					foreach ( $stats[ $segment_id ] as $stat => $value ) {
						if ( in_array( $stat, array( 'affiliate_id', 'time_interval' ), true ) ) {
							continue;
						}

						$stat_segment['data'][ "{$context}_{$stat}" ] = (float) $value;
					}
				}
			}

			if ( $single_segment ) {
				$totals = wp_parse_args( $segments[0]['data'], $defaults );
			} else {
				$totals = array(
					'segments' => array(),
				);

				foreach ( $segments as $segment ) {
					$totals['segments'][] = array(
						'segment_id'    => $segment['segment_id'],
						'segment_label' => $segment['segment_label'],
						'subtotals'     => wp_parse_args( $segment['data'], $defaults ),
					);
				}

				$totals = array_merge(
					$totals,
					$this->get_combined_totals( wp_list_pluck( $segments, 'data' ), $request )
				);
			}

			return $totals;
		}

		/**
		 * Combine data from various intervals, to generate subtotals and totals
		 *
		 * @param array           $data_to_combine Array of data to combine.
		 * @param WP_REST_Request $request         Request object.
		 *
		 * @return array An array of data,resulted from the merge of all arrays to combine.
		 */
		protected function get_combined_totals( $data_to_combine, $request ) {
			$totals = $this->get_default_stats( $request );

			foreach ( $totals as $stat => $empty ) {
				switch ( $stat ) {
					case 'clicks_conversion_rate':
						$total = $totals['clicks_count'] ? $totals['clicks_count_converted'] / $totals['clicks_count'] * 100 : 0;
						break;
					case 'clicks_avg_conversion_time':
						$sum = array_reduce(
							$data_to_combine,
							function( $carry, $item ) {
								$carry += $item['clicks_avg_conversion_time'] * $item['clicks_count'];

								return $carry;
							},
							0
						);

						$total = $totals['clicks_count'] ? $sum / $totals['clicks_count'] : 0;
						break;
					default:
						$total = $this->calculate_stat_total( $stat, wp_list_pluck( $data_to_combine, $stat ) );
						break;
				}

				$totals[ $stat ] = $total;
			}

			return $totals;
		}
	}
}
