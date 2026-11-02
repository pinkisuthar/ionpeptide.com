<?php
/**
 * REST API Products controller.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\API\Reports
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_REST_Reports_Products_Controller' ) ) {
	/**
	 * REST API Reports controller class.
	 */
	class YITH_WCAF_REST_Reports_Products_Controller extends YITH_WCAF_Abstract_REST_Reports_Controller {

		/**
		 * Products list
		 *
		 * @var array
		 */
		private $response_collection;

		/**
		 * Part of the url after $this::rest_base.
		 *
		 * @var string
		 */
		protected $rest_path = 'products';

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
			$args = $this->get_stats_query_args( $request );

			try {
				$data_store = WC_Data_Store::load( 'commission' );
				$products   = $data_store->get_stats( $args );
			} catch ( Exception $e ) {
				$products = array();
			}

			$data = array();

			if ( ! empty( $products ) ) {
				$this->response_collection = $products;

				foreach ( $products as $product ) {
					$item   = $this->prepare_item_for_response( $product, $request );
					$data[] = $this->prepare_response_for_collection( $item );
				}
			}

			$response = rest_ensure_response( $data );
			$response = $this->prepare_pagination_params( $products, $request, $response );

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
						'description' => _x( 'Product ID.', '[REST API] Product schema', 'yith-woocommerce-affiliates' ),
					),
					'post_id'                       => array(
						'type'        => 'integer',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'Product ID (if product is a variation, this will contain parent ID).', '[REST API] Product schema', 'yith-woocommerce-affiliates' ),
					),
					'name'                          => array(
						'type'        => 'string',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'Product name.', '[REST API] Product schema', 'yith-woocommerce-affiliates' ),
					),
					'commissions_count'             => array(
						'type'        => 'integer',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'Count of commissions created for the affiliate in the specified interval.', '[REST API] Product schema', 'yith-woocommerce-affiliates' ),
					),
					'commissions_total_earnings'    => array(
						'type'        => 'number',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'Affiliate total commissions earnings in the specified interval.', '[REST API] Product schema', 'yith-woocommerce-affiliates' ),
					),
					'commissions_total_refunds'     => array(
						'type'        => 'number',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'Affiliate total refunded commissions in the specified interval.', '[REST API] Product schema', 'yith-woocommerce-affiliates' ),
					),
					'commissions_total_paid'        => array(
						'type'        => 'number',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'Affiliate total paid commissions in the specified interval.', '[REST API] Product schema', 'yith-woocommerce-affiliates' ),
					),
					'commissions_store_gross_total' => array(
						'type'        => 'number',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'Store gross earnings produced by affiliate in the specified interval.', '[REST API] Product schema', 'yith-woocommerce-affiliates' ),
					),
					'commissions_store_net_total'   => array(
						'type'        => 'number',
						'readonly'    => true,
						'context'     => array( 'view', 'edit' ),
						'description' => _x( 'Store net earnings produced by affiliate in the specified interval.', '[REST API] Product schema', 'yith-woocommerce-affiliates' ),
					),
					'extended_info'                 => array(
						'image' => array(
							'type'        => 'string',
							'readonly'    => true,
							'context'     => array( 'view', 'edit' ),
							'description' => _x( 'Product image.', '[REST API] Product schema', 'yith-woocommerce-affiliates' ),
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

		/* === PREPARE RESPONSE === */

		/**
		 * Prepare an YITH_WCAF_Abstract_Object object for serialization.
		 *
		 * @param YITH_WCAF_Abstract_Object|object|array $object  Data object.
		 * @param WP_REST_Request                        $request Request object.
		 *
		 * @return WP_REST_Response
		 */
		public function prepare_item_for_response( $object, $request ) {
			return parent::prepare_item_for_response( $this->serialize_object( $object ), $request );
		}

		/**
		 * Serialize an YITH_WCAF_Abstract_Object, according to schema
		 *
		 * @param YITH_WCAF_Abstract_Object|object|array $object     Data object.
		 * @param array                                  $properties Properties to retrieve, defaults to entire schema.
		 *
		 * @return array Serialized object.
		 */
		protected function serialize_object( $object, $properties = array() ) {
			$object    = (array) $object;
			$formatted = array();
			$product   = wc_get_product( $object['product_id'] );

			if ( empty( $properties ) ) {
				$schema     = $this->get_item_schema();
				$properties = isset( $schema['properties'] ) ? array_keys( $schema['properties'] ) : array();
			}

			if ( ! $product || empty( $properties ) ) {
				return $formatted;
			}

			foreach ( $properties as $property ) {
				switch ( $property ) {
					case 'extended_info':
						$schema        = $this->get_item_schema();
						$extended_info = isset( $schema['properties'] ) && isset( $schema['properties']['extended_info'] ) ? array_keys( $schema['properties']['extended_info'] ) : array();

						if ( $extended_info ) {
							$value = $this->serialize_object( $object, $extended_info );
						}

						break;
					case 'id':
						$value = $object['product_id'];
						break;
					case 'post_id':
						$value = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
						break;
					case 'name':
						$value = $object['product_name'];
						break;
					case 'image':
						$image_id = $product->get_image_id();

						if ( ! $image_id ) {
							$value = wc_placeholder_img_src( array( 50, 50 ) );
						} else {
							$value = wp_get_attachment_image_url( $image_id, array( 50, 50 ) );
						}
						break;
					default:
						$index = str_replace( 'commissions_', '', $property );
						$value = isset( $object[ $index ] ) ? $object[ $index ] : false;

						break;
				}

				$formatted[ $property ] = $value;
			}

			return $formatted;
		}



		/* === QUERY HANDLING === */

		/**
		 * Returns an array of valid values for orderby param
		 * Should be overridden on dedicated class to have report-aware response
		 *
		 * @return array
		 */
		public function get_valid_orderby_values() {
			return array_merge(
				array(
					'product_name',
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
			$valid_values = $this->get_valid_orderby_values();
			$orderby      = isset( $query_args['orderby'] ) ? $query_args['orderby'] : false;

			if ( ! $orderby || ! in_array( $orderby, $valid_values, true ) ) {
				$orderby = array_shift( $valid_values );
			}

			return str_replace( 'commissions_', '', $orderby );
		}

		/**
		 * Returns arguments used to query database for collection stats
		 *
		 * @param WP_REST_Request $request Original request.
		 *
		 * @return array Array of query arguments.
		 */
		private function get_stats_query_args( $request ) {
			$query_args = $this->get_query_args( $request );

			$query_args['group_by'] = 'product_id';
			$query_args['orderby']  = $this->get_correct_orderby( $query_args );

			return $query_args;
		}
	}
}
