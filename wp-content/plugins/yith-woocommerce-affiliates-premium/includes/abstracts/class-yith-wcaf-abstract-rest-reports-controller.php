<?php
/**
 * Report REST API abstract controller
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Abstract_REST_Reports_Controller' ) ) {
	/**
	 * Report REST API abstract controller class.
	 *
	 * @extends WC_REST_Reports_Controller
	 */
	abstract class YITH_WCAF_Abstract_REST_Reports_Controller extends WC_REST_Reports_Controller {

		/**
		 * Endpoint namespace.
		 *
		 * @var string
		 */
		protected $namespace = YITH_WCAF_REST_NAMESPACE;

		/**
		 * Rest sub-path
		 *
		 * @var string
		 */
		protected $rest_path = '';

		/**
		 * Mapping between external parameter name and name used in query class.
		 *
		 * @var array
		 */
		protected $param_mapping = array();

		/**
		 * Init controller
		 */
		public function __construct() {
			$this->rest_base .= "/{$this->rest_path}";
		}

		/* === ROUTES & REQUEST HANDLING === */

		/**
		 * Register the routes for reports.
		 *
		 * Register base path, to retrieve items in this endpoint; should be overridden
		 * to define other paths.
		 */
		public function register_routes() {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base,
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_items' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => $this->get_collection_params(),
					),
					'schema' => array( $this, 'get_public_item_schema' ),
				)
			);
		}

		/**
		 * Get the query params for collections.
		 *
		 * @return array
		 */
		public function get_collection_params() {
			$orderby_values = $this->get_valid_orderby_values();

			$params                  = array();
			$params['context']       = $this->get_context_param( array( 'default' => 'view' ) );
			$params['page']          = array(
				'description'       => _x( 'Current page of the collection.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
				'minimum'           => 1,
			);
			$params['per_page']      = array(
				'description'       => _x( 'Maximum number of items to be returned in result set.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			);
			$params['after']         = array(
				'description'       => _x( 'Limit response to resources published after a given ISO 8601 compliant date.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'string',
				'format'            => 'date-time',
				'validate_callback' => 'rest_validate_request_arg',
			);
			$params['before']        = array(
				'description'       => _x( 'Limit response to resources published before a given ISO 8601 compliant date.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'string',
				'format'            => 'date-time',
				'validate_callback' => 'rest_validate_request_arg',
			);
			$params['order']         = array(
				'description'       => _x( 'Order sort attribute ascending or descending.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'string',
				'default'           => 'desc',
				'enum'              => array( 'asc', 'desc' ),
				'validate_callback' => 'rest_validate_request_arg',
			);
			$params['extended_info'] = array(
				'description'       => _x( 'Add an additional piece of information about each item to the report.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'wc_string_to_bool',
				'validate_callback' => 'rest_validate_request_arg',
			);

			if ( ! empty( $orderby_values ) ) {
				$params['orderby'] = array(
					'description'       => _x( 'Sort collection by object attribute.', '[REST API] General collection params.', 'yith-woocommerce-affiliates' ),
					'type'              => 'string',
					'default'           => $orderby_values[0],
					'enum'              => $orderby_values,
					'validate_callback' => 'rest_validate_request_arg',
				);
			}

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
			$query_args = array();
			$query_vars = array_keys( $this->get_collection_params() );

			if ( empty( $query_vars ) ) {
				return $query_args;
			}

			foreach ( $query_vars as $param_name ) {
				if ( ! isset( $request[ $param_name ] ) ) {
					continue;
				}

				if ( isset( $this->param_mapping[ $param_name ] ) ) {
					$query_args[ $this->param_mapping[ $param_name ] ] = $request[ $param_name ];
				} else {
					$query_args[ $param_name ] = $request[ $param_name ];
				}
			}

			// converts request parameter to query args.
			$query_args = $this->get_interval_query_args( $query_args, $request );
			$query_args = $this->get_pagination_query_args( $query_args, $request );

			return $query_args;
		}

		/**
		 * Converts date parameters in the request in an interval, used by data store to filter out results.
		 *
		 * @param array           $query_args Arguments for the query.
		 * @param WP_REST_Request $request    Request object.
		 *
		 * @return array Array of filtered query arguments.
		 */
		public function get_interval_query_args( $query_args, $request ) {
			if ( ! empty( $request['before'] ) || ! empty( $request['after'] ) ) {
				$query_args['interval'] = array_merge(
					isset( $request['after'] ) ? array(
						'start_date' => gmdate( 'Y-m-d H:i:s', strtotime( $request['after'] ) ),
					) : array(),
					isset( $request['before'] ) ? array(
						'end_date' => gmdate( 'Y-m-d H:i:s', strtotime( $request['before'] ) ),
					) : array()
				);
			}

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
			if ( ! empty( $request['per_page'] ) && 0 < $request['per_page'] ) {
				$page     = ! empty( $request['page'] ) ? (int) $request['page'] : 1;
				$per_page = (int) $request['per_page'];

				$query_args['limit']  = $per_page;
				$query_args['offset'] = ( $page - 1 ) * $per_page;
			}

			return $query_args;
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
			$context = ! empty( $request['context'] ) ? $request['context'] : 'view';

			$data = $object instanceof YITH_WCAF_Abstract_Object ? $this->serialize_object( $object ) : (array) $object;
			$data = $this->add_additional_fields_to_object( $data, $request );
			$data = $this->add_stats_to_object( $data, $request );
			$data = $this->filter_response_by_context( $data, $context );

			// Wrap the data in a response object.
			$response = rest_ensure_response( $data );
			$response->add_links( $this->prepare_item_links( $object ) );

			/**
			 * Filter a report returned from the API.
			 *
			 * Allows modification of the report data right before it is returned.
			 *
			 * @param WP_REST_Response $response The response object.
			 * @param object           $object     The original report object.
			 * @param WP_REST_Request  $request  Request used to generate the response.
			 */

			/**
			 * APPLY_FILTERS: yith_wcaf_rest_prepare_report_object
			 *
			 * Filters the report returned from the API.
			 *
			 * @param WP_REST_Response $response The response object.
			 * @param object           $object   The original report object.
			 * @param WP_REST_Request  $request  Request used to generate the response.
			 */
			return apply_filters( 'yith_wcaf_rest_prepare_report_object', $response, $object, $request, $this );
		}

		/**
		 * Init pagination params for the response
		 *
		 * @param YITH_WCAF_Abstract_Objects_Collection|array $collection Reference collection, or array of items.
		 * @param WP_REST_Request                             $request    Request object.
		 * @param WP_REST_Response                            $response   Response object.
		 *
		 * @return WP_REST_Response Response object with pagination parameters.
		 */
		public function prepare_pagination_params( $collection, $request, $response ) {
			// if we're dealing with a plain array, we have no meta regarding pagination.
			if ( is_array( $collection ) ) {
				return $response;
			}

			$response->header( 'X-WP-Total', (int) $collection->get_total_items() );
			$response->header( 'X-WP-TotalPages', (int) $collection->get_total_pages() );

			$page      = $collection->get_current_page();
			$max_pages = $collection->get_total_pages();
			$base      = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ) );

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

		/**
		 * Returns an array of valid values for orderby param
		 * Should be overridden on dedicated class to have report-aware response
		 *
		 * @return array
		 */
		public function get_valid_orderby_values() {
			return array();
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
			if ( ! $object instanceof YITH_WCAF_Abstract_Object ) {
				return (array) $object;
			}

			$formatted = array();

			if ( empty( $properties ) ) {
				$schema     = $this->get_item_schema();
				$properties = isset( $schema['properties'] ) ? array_keys( $schema['properties'] ) : array();
			}

			if ( empty( $properties ) ) {
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
					case 'email':
						$user = method_exists( $object, 'get_user' ) ? $object->get_user() : false;

						if ( ! $user ) {
							$value = '';
							break;
						}

						$value = $user->user_email;
						break;
					case 'avatar':
						$user_id = method_exists( $object, 'get_user_id' ) ? $object->get_user_id() : false;

						if ( ! $user_id ) {
							$value = '';
							break;
						}

						$value = get_avatar_url(
							$user_id,
							array(
								'size' => 50,
							)
						);
						break;
					case 'name':
					case 'status':
						$method = "get_formatted_{$property}";

						if ( method_exists( $object, $method ) ) {
							$value = $object->$method();
							break;
						}

						// if formatted value can't be retrieved, fallthrough default handling.
					default:
						$method = "get_{$property}";

						// if getter doesn't exist, try with meta.
						if ( ! method_exists( $object, $method ) ) {
							$value = $object->get_meta( $property );
						} else {
							$value = $object->$method();
						}

						break;
				}

				$formatted[ $property ] = $value;
			}

			return $formatted;
		}

		/**
		 * Prepare links for the request.
		 * Should be overridden to add links specific to the item.
		 *
		 * @param array $object Object data.
		 *
		 * @return array Links for the given post.
		 */
		protected function prepare_item_links( $object ) {
			return array();
		}

		/* === STATS HANDLING === */

		/**
		 * Retrieves stats for each item of the response
		 *
		 * @param array           $prepared Array of prepared data to be returned.
		 * @param WP_REST_Request $request  Original request.
		 *
		 * @return array Array of statistics to be added to the object; array must use property name (from item schema) as index, and data as value
		 * [
		 *     'earnings' => 1234.56,
		 *     'click'    => 56,
		 *     ...
		 * ]
		 */
		protected function get_object_stats( $prepared, $request ) {
			return array();
		}

		/**
		 * Adds statistic info to the prepared data, to be returned through REST
		 * It may override some of the propertied already prepares, depending on stats retrieved
		 *
		 * @param array           $prepared Array of prepared data to be returned.
		 * @param WP_REST_Request $request  Original request.
		 *
		 * @return array Array of filtered prepared data.
		 */
		protected function add_stats_to_object( $prepared, $request ) {
			$stats = $this->get_object_stats( $prepared, $request );

			if ( ! $stats ) {
				return $prepared;
			}

			foreach ( $stats as $key => $value ) {
				$prepared[ $key ] = $value;
			}

			return $prepared;
		}
	}
}
