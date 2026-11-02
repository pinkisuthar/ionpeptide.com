<?php
/**
 * REST API Affiliates controller.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\API\Reports
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

use Automattic\WooCommerce\Admin\API\Reports\ExportableInterface;

if ( ! class_exists( 'YITH_WCAF_REST_Reports_Affiliates_Controller' ) ) {
	/**
	 * REST API Reports controller class.
	 */
	class YITH_WCAF_REST_Reports_Affiliates_Controller extends YITH_WCAF_Abstract_REST_Reports_Controller {

		/**
		 * Contains collection that will be returned through API
		 *
		 * @var YITH_WCAF_Affiliates_Collection
		 */
		private $response_collection;

		/**
		 * Contains stats for each item of the response collection
		 *
		 * @var array
		 */
		private $response_stats;

		/**
		 * Part of the url after $this::rest_base.
		 *
		 * @var string
		 */
		protected $rest_path = 'affiliates';

		/**
		 * A list of properties that should be retrieved as external stats
		 *
		 * @var array
		 */
		protected $stat_properties = array(
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
		 * Stores correct order of affiliates id to show in the end result
		 * Will be used to order subsequent stats queries
		 *
		 * @var int[]
		 */
		protected $items_order = array();

		/**
		 * Get all reports.
		 *
		 * @param \WP_REST_Request $request Request data.
		 * @return \WP_Rest_Response|\WP_Error
		 */
		public function get_items( $request ) {
			$args = $this->get_query_args( $request );

			$affiliates = YITH_WCAF_Affiliate_Factory::get_affiliates( $args );
			$data       = array();

			if ( $affiliates && ! $affiliates->is_empty() ) {
				$this->response_collection = $affiliates;

				foreach ( $affiliates as $affiliate ) {
					$item   = $this->prepare_item_for_response( $affiliate, $request );
					$data[] = $this->prepare_response_for_collection( $item );
				}
			}

			$response = rest_ensure_response( $data );
			$response = $this->prepare_pagination_params( $affiliates, $request, $response );

			return $response;
		}

		/**
		 * Get the Report's schema, conforming to JSON Schema.
		 *
		 * @return array
		 */
		public function get_item_schema() {
			$schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'report_affiliates',
				'type'       => 'object',
				'properties' => array(
					'id'                            => array(
						'type'        => 'integer',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'Affiliate ID.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
					),
					'token'                         => array(
						'type'        => 'string',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'Affiliate token.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
					),
					'user_id'                       => array(
						'type'        => 'integer',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'User ID.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
					),
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
					'extended_info'                 => array(
						'name'   => array(
							'type'        => 'string',
							'readonly'    => true,
							'context'     => array( 'view', 'edit' ),
							'description' => _x( 'Affiliate formatted name.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						),
						'rate'   => array(
							'type'        => 'number',
							'readonly'    => true,
							'context'     => array( 'view', 'edit' ),
							'description' => _x( 'Affiliate rate.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						),
						'email'  => array(
							'type'        => 'string',
							'readonly'    => true,
							'context'     => array( 'view', 'edit' ),
							'description' => _x( 'Affiliate email.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						),
						'avatar' => array(
							'type'        => 'string',
							'readonly'    => true,
							'context'     => array( 'view', 'edit' ),
							'description' => _x( 'Affiliate avatar.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						),
						'status' => array(
							'type'        => 'string',
							'readonly'    => true,
							'context'     => array( 'view', 'edit' ),
							'description' => _x( 'Affiliate formatted status.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						),
						'banned' => array(
							'type'        => 'integer',
							'readonly'    => true,
							'context'     => array( 'view', 'edit' ),
							'description' => _x( 'Whether affiliate is banned or not.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
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

			// add params specific to this controller.
			$params['affiliates'] = array(
				'description'       => _x( 'Limit result to items with specified affiliate IDs.', '[REST API] Affiliate collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'array',
				'sanitize_callback' => 'wp_parse_id_list',
				'validate_callback' => 'rest_validate_request_arg',
				'items'             => array(
					'type' => 'integer',
				),

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

			// replace orderby when required.
			$query_args['orderby'] = $this->get_correct_orderby( $query_args );

			// removes interval from items query; we use it only for stats.
			if ( isset( $query_args['interval'] ) ) {
				unset( $query_args['interval'] );
			}

			return $query_args;
		}

		/**
		 * Returns an array of valid values for orderby param
		 * Should be overridden on dedicated class to have report-aware response
		 *
		 * @return array
		 */
		public function get_valid_orderby_values() {
			return array_merge(
				array(
					'user_login',
				),
				$this->stat_properties
			);
		}

		/**
		 * Retrieves correct affiliates order, depending on the ordering requested via query_args
		 *
		 * @param array $query_args Query arguments.
		 *
		 * @return array Array of affiliates id, ordered as expected by query_args
		 */
		protected function get_correct_orderby( $query_args ) {
			if ( ! empty( $this->items_order ) ) {
				return $this->items_order;
			}

			if ( in_array( $query_args['orderby'], $this->stat_properties, true ) ) {
				$components = explode( '_', $query_args['orderby'] );
				$data_store = rtrim( array_shift( $components ), 's' );

				try {
					$data_store = WC_Data_Store::load( $data_store );
					$orderby    = implode( '_', $components );
					$items      = $data_store->get_stats(
						array_merge(
							$query_args,
							array(
								'group_by' => 'affiliate_id',
								'orderby'  => $orderby,
							)
						)
					);

					$order = array_keys( $items );
				} catch ( Exception $e ) {
					$order = array();
				}
			} else {
				$order = YITH_WCAF_Affiliate_Factory::get_affiliates(
					array_merge(
						$query_args,
						array(
							'orderby' => $query_args['orderby'],
							'fields'  => 'ids',
						)
					)
				);
			}

			if ( 'desc' === $query_args['order'] ) {
				$order = array_reverse( $order );
			}

			$this->items_order = array(
				'affiliate_id' => $order,
			);

			return $this->items_order;
		}

		/* === STATS HANDLING === */

		/**
		 * Retrieves stats for each item of the response
		 *
		 * @param array           $prepared Array of prepared data to be returned.
		 * @param WP_REST_Request $request  Original request.
		 *
		 * @return array Array of statistics to be added to the object.
		 */
		protected function get_object_stats( $prepared, $request ) {
			if ( ! isset( $prepared['id'] ) ) {
				return array();
			}

			$affiliate_id = $prepared['id'];

			// if we still didn't retrieve any stats, perform necessary query.
			$stats = $this->maybe_populate_response_stats( $request );

			return isset( $stats[ $affiliate_id ] ) ? $stats[ $affiliate_id ] : array();
		}

		/**
		 * Retrieves affiliate statistics for the current request, if not retrieved yet
		 *
		 * @param WP_REST_Request $request Request object.
		 */
		private function maybe_populate_response_stats( $request ) {
			if ( empty( $this->response_stats ) ) {
				$this->populate_response_stats( $request );
			}

			return $this->response_stats;
		}

		/**
		 * Retrieves affiliate statistics for the current request
		 *
		 * @param WP_REST_Request $request Request object.
		 */
		private function populate_response_stats( $request ) {
			$query_args = $this->get_stats_query_args( $request );

			foreach ( array( 'commissions', 'clicks' ) as $context ) {
				try {
					$data_store = WC_Data_Store::load( rtrim( $context, 's' ) );
				} catch ( Exception $e ) {
					continue;
				}

				$stats = $data_store->get_stats( $query_args );

				if ( ! empty( $stats ) ) {
					foreach ( $stats as $affiliate_id => $affiliate_stats ) {
						if ( ! isset( $this->response_stats[ $affiliate_id ] ) ) {
							$this->response_stats[ $affiliate_id ] = array();
						}

						foreach ( $affiliate_stats as $stat => $value ) {
							if ( in_array( $stat, array( 'affiliate_id', 'time_interval' ), true ) ) {
								continue;
							}

							$this->response_stats[ $affiliate_id ][ "{$context}_{$stat}" ] = $value;
						}
					}
				}
			}
		}

		/**
		 * Returns arguments used to query database for collection stats
		 *
		 * @param WP_REST_Request $request Original request.
		 *
		 * @return array Array of query arguments.
		 */
		private function get_stats_query_args( $request ) {
			$query_args = array();

			if ( ! $this->response_collection || $this->response_collection->is_empty() ) {
				return $query_args;
			}

			$query_args['group_by'] = 'affiliate_id';

			if ( isset( $request['before'] ) || isset( $request['after'] ) ) {
				$query_args = $this->get_interval_query_args( $query_args, $request );
			}

			$query_args['orderby'] = $this->get_correct_orderby( $query_args );

			return $query_args;
		}
	}
}
