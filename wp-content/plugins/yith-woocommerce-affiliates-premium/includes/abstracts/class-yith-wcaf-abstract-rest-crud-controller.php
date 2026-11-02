<?php
/**
 * REST CRUD API abstract controller
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Abstract_REST_CRUD_Controller' ) ) {
	/**
	 * CRUD REST API abstract controller class.
	 *
	 * @extends WC_REST_CRUD_Controller
	 */
	abstract class YITH_WCAF_Abstract_REST_CRUD_Controller extends WC_REST_Controller {

		/**
		 * Endpoint namespace.
		 *
		 * @var string
		 */
		protected $namespace = YITH_WCAF_REST_NAMESPACE;

		/**
		 * Object type
		 * Will be used to retrieve data store and base classes
		 *
		 * @var string
		 */
		protected $object;

		/**
		 * Data store object
		 *
		 * @var WC_Data_Store
		 */
		protected $data_store;

		/**
		 * Constructor method
		 */
		public function __construct() {
			$this->init_data_store();
		}

		/**
		 * Init data store that will be used by this controller
		 */
		protected function init_data_store() {
			try {
				$this->data_store = WC_Data_Store::load( $this->object );
			} catch ( Exception $e ) {
				wp_die( esc_html_x( 'There was an error while initializing REST endpoint', '[REST API] Api error', 'yith-woocommerce-affiliates' ) );
			}
		}

		/* === ROUTES HANDLING === */

		/**
		 * Register the routes for products.
		 */
		public function register_routes() {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_items' ),
						'permission_callback' => array( $this, 'get_items_permissions_check' ),
						'args'                => $this->get_collection_params(),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'create_item' ),
						'permission_callback' => array( $this, 'create_item_permissions_check' ),
						'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
					),
					'schema' => array( $this, 'get_public_item_schema' ),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>[\d]+)',
				array(
					'args'   => array(
						'id' => array(
							'description' => _x( 'Unique identifier for the resource.', '[REST API] Request param', 'yith-woocommerce-affiliates' ),
							'type'        => 'integer',
						),
					),
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_item' ),
						'permission_callback' => array( $this, 'get_item_permissions_check' ),
						'args'                => array(
							'context' => $this->get_context_param(
								array(
									'default' => 'view',
								)
							),
						),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this, 'update_item' ),
						'permission_callback' => array( $this, 'update_item_permissions_check' ),
						'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( $this, 'delete_item' ),
						'permission_callback' => array( $this, 'delete_item_permissions_check' ),
						'args'                => array(
							'force' => array(
								'default'     => false,
								'description' => _x( 'Whether to bypass trash and force deletion.', '[REST API] Request param', 'yith-woocommerce-affiliates' ),
								'type'        => 'boolean',
							),
						),
					),
					'schema' => array( $this, 'get_public_item_schema' ),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/batch',
				array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this, 'batch_items' ),
						'permission_callback' => array( $this, 'batch_items_permissions_check' ),
						'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
					),
					'schema' => array( $this, 'get_public_batch_schema' ),
				)
			);
		}

		/**
		 * Get a single item.
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 * @return WP_Error|WP_REST_Response
		 */
		public function get_item( $request ) {
			$object = $this->get_object( (int) $request['id'] );

			if ( ! $object || is_wp_error( $object ) || 0 === $object->get_id() ) {
				return new WP_Error( "yith_wcaf_rest_{$this->object}_invalid_id", _x( 'Invalid ID.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), array( 'status' => 404 ) );
			}

			$data     = $this->prepare_object_for_response( $object, $request );
			$response = rest_ensure_response( $data );

			return $response;
		}

		/**
		 * Get a collection of objects.
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 * @return WP_Error|WP_REST_Response
		 */
		public function get_items( $request ) {
			$query_args = $this->get_query_args( $request );
			$collection = $this->get_objects( $query_args );

			$objects = array();
			foreach ( $collection as $object ) {
				$data      = $this->prepare_object_for_response( $object, $request );
				$objects[] = $this->prepare_response_for_collection( $data );
			}

			$page      = (int) $collection->get_current_page();
			$max_pages = (int) $collection->get_total_pages();

			$response = rest_ensure_response( $objects );
			$response->header( 'X-WP-Total', $collection->get_total_items() );
			$response->header( 'X-WP-TotalPages', $max_pages );

			$base          = $this->rest_base;
			$attrib_prefix = '(?P<';
			if ( strpos( $base, $attrib_prefix ) !== false ) {
				$attrib_names = array();
				preg_match( '/\(\?P<[^>]+>.*\)/', $base, $attrib_names, PREG_OFFSET_CAPTURE );
				foreach ( $attrib_names as $attrib_name_match ) {
					$beginning_offset = strlen( $attrib_prefix );
					$attrib_name_end  = strpos( $attrib_name_match[0], '>', $attrib_name_match[1] );
					$attrib_name      = substr( $attrib_name_match[0], $beginning_offset, $attrib_name_end - $beginning_offset );
					if ( isset( $request[ $attrib_name ] ) ) {
						$base = str_replace( "(?P<$attrib_name>[\d]+)", $request[ $attrib_name ], $base );
					}
				}
			}
			$base = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $base ) ) );

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
		 * Create a single item.
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 * @return WP_Error|WP_REST_Response
		 */
		public function create_item( $request ) {
			if ( ! empty( $request['id'] ) ) {
				// translators: %s: post type.
				return new WP_Error( "yith_wcaf_rest_{$this->object}_exists", sprintf( _x( 'Cannot create existing %s.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), $this->object ), array( 'status' => 400 ) );
			}

			$object = $this->save_object( $request, true );

			if ( is_wp_error( $object ) ) {
				return $object;
			}

			try {
				$this->update_additional_fields_for_object( $object, $request );

				/**
				 * DO_ACTION: yith_wcaf_rest_insert_$object_object
				 *
				 * Allows to trigger some action after a single object is created or updated via the REST API.
				 * <code>$object</code> will be replaced with the object type.
				 *
				 * @param WC_Data         $object    Inserted object.
				 * @param WP_REST_Request $request   Request object.
				 * @param boolean         $creating  True when creating object, false when updating.
				 */
				do_action( "yith_wcaf_rest_insert_{$this->object}_object", $object, $request, true );
			} catch ( Exception $e ) {
				$object->delete();
				return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
			}

			$request->set_param( 'context', 'edit' );
			$response = $this->prepare_object_for_response( $object, $request );
			$response = rest_ensure_response( $response );
			$response->set_status( 201 );
			$response->header( 'Location', rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $object->get_id() ) ) );

			return $response;
		}

		/**
		 * Update a single item.
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 * @return WP_Error|WP_REST_Response
		 */
		public function update_item( $request ) {
			$object = $this->get_object( (int) $request['id'] );

			if ( ! $object || is_wp_error( $object ) || 0 === $object->get_id() ) {
				return new WP_Error( "yith_wcaf_rest_{$this->object}_invalid_id", _x( 'Invalid ID.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), array( 'status' => 400 ) );
			}

			$object = $this->save_object( $request, false );

			if ( is_wp_error( $object ) ) {
				return $object;
			}

			try {
				$this->update_additional_fields_for_object( $object, $request );

				/**
				 * Fires after a single object is created or updated via the REST API.
				 *
				 * @param WC_Data         $object    Inserted object.
				 * @param WP_REST_Request $request   Request object.
				 * @param boolean         $creating  True when creating object, false when updating.
				 */
				do_action( "yith_wcaf_rest_insert_{$this->object}_object", $object, $request, false );
			} catch ( Exception $e ) {
				return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
			}

			$request->set_param( 'context', 'edit' );
			$response = $this->prepare_object_for_response( $object, $request );
			return rest_ensure_response( $response );
		}

		/**
		 * Delete a single item.
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function delete_item( $request ) {
			$force  = (bool) $request['force'];
			$object = $this->get_object( (int) $request['id'] );

			if ( ! $object || is_wp_error( $object ) || 0 === $object->get_id() ) {
				return new WP_Error( "yith_wcaf_rest_{$this->object}_invalid_id", _x( 'Invalid ID.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), array( 'status' => 404 ) );
			}

			$request->set_param( 'context', 'edit' );
			$response = $this->prepare_object_for_response( $object, $request );

			$object->delete( $force );
			$result = 0 === $object->get_id();

			if ( ! $result ) {
				// translators: %s: post type.
				return new WP_Error( 'yith_wcaf_rest_cannot_delete', sprintf( _x( 'The %s cannot be deleted.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), $this->object ), array( 'status' => 500 ) );
			}

			/**
			 * Fires after a single object is deleted or trashed via the REST API.
			 *
			 * @param WC_Data          $object   The deleted or trashed object.
			 * @param WP_REST_Response $response The response data.
			 * @param WP_REST_Request  $request  The request sent to the API.
			 */

			/**
			 * DO_ACTION: yith_wcaf_rest_delete_$object_object
			 *
			 * Allows to trigger some action after a single object is deleted or trashed via the REST API.
			 * <code>$object</code> will be replaced with the object type.
			 *
			 * @param WC_Data          $object   The deleted or trashed object.
			 * @param WP_REST_Response $response The response data.
			 * @param WP_REST_Request  $request  The request sent to the API.
			 */
			do_action( "yith_wcaf_rest_delete_{$this->object}_object", $object, $response, $request );

			return $response;
		}

		/* === REQUEST HANDLING === */

		/**
		 * Returns an array of valid values for orderby param
		 * Should be overridden on dedicated class to have object-aware response
		 *
		 * @return array
		 */
		public function get_valid_orderby_values() {
			return array();
		}

		/**
		 * Get the query params for collections of attachments.
		 *
		 * @return array
		 */
		public function get_collection_params() {
			$params                       = array();
			$params['context']            = $this->get_context_param();
			$params['context']['default'] = 'view';

			$params['page']     = array(
				'description'       => _x( 'Current page of the collection.', '[REST API] Affiliate collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
				'minimum'           => 1,
			);
			$params['per_page'] = array(
				'description'       => _x( 'Maximum number of items to be returned in result set.', '[REST API] Affiliate collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			);
			$params['search']   = array(
				'description'       => _x( 'Limit results to those matching a string.', '[REST API] Affiliate collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			);
			$params['after']    = array(
				'description'       => _x( 'Limit response to resources published after a given ISO 8601 compliant date.', '[REST API] Affiliate collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'string',
				'format'            => 'date-time',
				'validate_callback' => 'rest_validate_request_arg',
			);
			$params['before']   = array(
				'description'       => _x( 'Limit response to resources published before a given ISO 8601 compliant date.', '[REST API] Affiliate collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'string',
				'format'            => 'date-time',
				'validate_callback' => 'rest_validate_request_arg',
			);
			$params['order']    = array(
				'description'       => _x( 'Order sort attribute ascending or descending.', '[REST API] Affiliate collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'string',
				'default'           => 'desc',
				'enum'              => array( 'asc', 'desc' ),
				'validate_callback' => 'rest_validate_request_arg',
			);
			$params['exclude']  = array(
				'description'       => _x( 'Ensure result set excludes specific IDs.', '[REST API] Affiliate collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'array',
				'items'             => array(
					'type' => 'integer',
				),
				'default'           => array(),
				'sanitize_callback' => 'wp_parse_id_list',
			);
			$params['include']  = array(
				'description'       => _x( 'Limit result set to specific IDs.', '[REST API] Affiliate collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'array',
				'items'             => array(
					'type' => 'integer',
				),
				'default'           => array(),
				'sanitize_callback' => 'wp_parse_id_list',
			);
			$params['orderby']  = array(
				'description'       => _x( 'Sort collection by object attribute.', '[REST API] Affiliate collection params.', 'yith-woocommerce-affiliates' ),
				'type'              => 'string',
				'default'           => 'date',
				'enum'              => $this->get_valid_orderby_values(),
				'validate_callback' => 'rest_validate_request_arg',
			);

			/**
			 * APPLY_FILTERS: yith_wcaf_rest_$object_collection_params
			 *
			 * Filters the array with the parameters to get the collections.
			 * <code>$object</code> will b replaced with the object to get the collections.
			 *
			 * @param array $params Array with parameters.
			 */
			return apply_filters( "yith_wcaf_rest_{$this->object}_collection_params", $params );
		}

		/* === OBJECTS HANDLING === */

		/**
		 * Returns class of objects used in this controller
		 *
		 * @return string|bool Object class or false on failure.
		 */
		protected function get_object_class() {
			$object       = ucfirst( $this->object );
			$object_class = "YITH_WCAF_{$object}";

			if ( ! class_exists( $object_class ) ) {
				return false;
			}

			return $object_class;
		}

		/**
		 * Get object.
		 *
		 * @param  int $id Object ID.
		 * @return WC_Data|WP_Error WC_Data object or WP_Error object.
		 */
		protected function get_object( $id ) {
			$object_class = $this->get_object_class();

			if ( ! class_exists( $object_class ) ) {
				return new WP_Error( 'yith_wcaf_invalid_object', _x( 'There was an error while retrieving the object.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), array( 'status' => 406 ) );
			}

			try {
				// TODO: review this code to use a general Factory instead!
				return new $object_class( $id );
			} catch ( Exception $e ) {
				return new WP_Error( 'yith_wcaf_invalid_object', _x( 'There was an error while retrieving the object.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), array( 'status' => 406 ) );
			}
		}

		/**
		 * Get a collection of objects.
		 *
		 * @param array $query_args Arguments to apply to the query.
		 * @return YITH_WCAF_Abstract_Objects_Collection
		 */
		protected function get_objects( $query_args = array() ) {
			return $this->data_store->query( $query_args );
		}

		/**
		 * Save an object data.
		 *
		 * @param  WP_REST_Request $request  Full details about the request.
		 * @param  bool            $creating If is creating a new object.
		 * @return WC_Data|WP_Error
		 */
		protected function save_object( $request, $creating = false ) {
			try {
				$object = $this->prepare_object_for_database( $request, $creating );

				if ( is_wp_error( $object ) ) {
					return $object;
				}

				$object->save();

				return $this->get_object( $object->get_id() );
			} catch ( Exception $e ) {
				return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
			}
		}

		/**
		 * Creates query args to use to query objects
		 *
		 * @param  WP_REST_Request $request Request object.
		 * @return array Array of query args.
		 */
		protected function get_query_args( $request ) {
			$query_args = array();
			$params     = $this->get_collection_params();

			foreach ( $params as $param => $value ) {
				$value = isset( $request[ $param ] ) ? $request[ $param ] : false;

				if ( ! $value ) {
					continue;
				}

				switch ( $param ) {
					case 'before':
					case 'after':
						if ( ! isset( $query_args['interval'] ) ) {
							$query_args['interval'] = array();
						}

						$value     = gmdate( 'Y-m-d H:i:s', strtotime( $value ) );
						$query_arg = 'after' === $param ? 'start_date' : 'end_date';

						$query_args['interval'][ $query_arg ] = $value;
						break;
					case 'per_page':
						$query_args['limit'] = $value;
						break;
					case 'page':
						$per_page = isset( $request['per_page'] ) ? (int) $request['per_page'] : 10;

						$query_args['offset'] = ( $value - 1 ) * $per_page;
						break;
					case 'search':
						$query_args['s'] = $value;
						break;
					default:
						$query_args[ $param ] = $value;
				}
			}

			return $query_args;
		}

		/**
		 * Prepare links for the request.
		 *
		 * @param WC_Data         $object  Object data.
		 * @param WP_REST_Request $request Request object.
		 *
		 * @return array Links for the given post.
		 */
		protected function prepare_links( $object, $request ) {
			$links = array(
				'self'       => array(
					'href' => rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $object->get_id() ) ),
				),
				'collection' => array(
					'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
				),
			);

			return $links;
		}

		/**
		 * Prepares the object for the REST response.
		 *
		 * @param  WC_Data         $object  Object data.
		 * @param  WP_REST_Request $request Request object.
		 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
		 */
		protected function prepare_object_for_response( $object, $request ) {
			$schema     = $this->get_item_schema();
			$properties = $schema['properties'];
			$data       = array();

			foreach ( array_keys( $properties ) as $property ) {
				$getter = "get_{$property}";
				$value  = '';

				// special properties handling.
				switch ( $property ) {
					case 'name':
						if ( method_exists( $object, 'get_formatted_name' ) ) {
							$value = $object->get_formatted_name( $request['context'] );
						} elseif ( method_exists( $object, 'get_name' ) ) {
							$value = $object->get_name( $request['context'] );
						}
						break;
					case 'user_email':
						if ( ! method_exists( $object, 'get_user' ) ) {
							break;
						}

						$user  = $object->get_user( $request['context'] );
						$value = $user ? $user->user_email : false;
						break;
					case 'user_login':
						if ( ! method_exists( $object, 'get_user' ) ) {
							break;
						}

						$user  = $object->get_user( $request['context'] );
						$value = $user ? $user->user_login : false;
						break;
				}

				// default properties handling.
				if ( ! $value ) {
					$value = method_exists( $object, $getter ) ? $object->$getter( $request['context'] ) : $object->get_meta( $property );
				}

				$data[ $property ] = $value;
			}

			$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
			$data    = $this->add_additional_fields_to_object( $data, $request );
			$data    = $this->filter_response_by_context( $data, $context );

			$response = rest_ensure_response( $data );

			$response->add_links( $this->prepare_links( $object, $request ) );

			return $response;
		}

		/**
		 * Prepares one object for create or update operation.
		 *
		 * @param  WP_REST_Request $request Request object.
		 * @param  bool            $creating If is creating a new object.
		 * @return WP_Error|WC_Data The prepared item, or WP_Error object on failure.
		 */
		protected function prepare_object_for_database( $request, $creating = false ) {
			$schema     = $this->get_item_schema();
			$properties = $schema['properties'];

			// TODO: review this code to use a general Factory instead!
			$object_class = $this->get_object_class();

			if ( ! class_exists( $object_class ) ) {
				return new WP_Error( 'yith_wcaf_invalid_object', _x( 'There was an error while retrieving the object.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), array( 'status' => 406 ) );
			}

			if ( $creating ) {
				$object = new $object_class();
			} else {
				$object = new $object_class( (int) $request['id'] );
			}

			foreach ( array_keys( $properties ) as $property ) {
				$value  = $request[ $property ];
				$setter = "set_{$property}";

				if ( method_exists( $object, $setter ) ) {
					$object->$setter( $value );
				} else {
					$object->update_meta_data( $property, $value );
				}
			}

			return $object;
		}

		/* === PERMISSIONS HANDLING === */

		/**
		 * Check if a given request has access to read an item.
		 *
		 * @param  WP_REST_Request $request Full details about the request.
		 * @return bool|WP_Error
		 */
		public function get_item_permissions_check( $request ) {
			$object = $this->get_object( (int) $request['id'] );

			if ( ! class_exists( 'YITH_WCAF_Admin' ) ) {
				return false;
			}

			if ( $object && 0 !== $object->get_id() && ! $object->current_user_can( 'read' ) ) {
				return new WP_Error( 'yith_wcaf_rest_cannot_view', _x( 'Sorry, you cannot view this resource.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), array( 'status' => rest_authorization_required_code() ) );
			}

			return true;
		}

		/**
		 * Checks if a given request has access to get items.
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 * @return bool|WP_Error True if the request has read access, WP_Error object otherwise.
		 */
		public function get_items_permissions_check( $request ) {
			if ( ! class_exists( 'YITH_WCAF_Admin' ) ) {
				return false;
			}

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				return new WP_Error( 'yith_wcaf_rest_cannot_view', _x( 'Sorry, you cannot view this resource.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), array( 'status' => rest_authorization_required_code() ) );
			}

			return true;
		}

		/**
		 * Check if a given request has access to update an item.
		 *
		 * @param  WP_REST_Request $request Full details about the request.
		 * @return bool|WP_Error
		 */
		public function update_item_permissions_check( $request ) {
			$object = $this->get_object( (int) $request['id'] );

			if ( ! class_exists( 'YITH_WCAF_Admin' ) ) {
				return false;
			}

			if ( $object && ! is_wp_error( $object ) && 0 !== $object->get_id() && ! $object->current_user_can( 'edit' ) ) {
				return new WP_Error( 'yith_wcaf_rest_cannot_edit', _x( 'Sorry, you are not allowed to edit this resource.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), array( 'status' => rest_authorization_required_code() ) );
			}

			return true;
		}

		/**
		 * Check if a given request has access to delete an item.
		 *
		 * @param  WP_REST_Request $request Full details about the request.
		 * @return bool|WP_Error
		 */
		public function delete_item_permissions_check( $request ) {
			$object = $this->get_object( (int) $request['id'] );

			if ( ! class_exists( 'YITH_WCAF_Admin' ) ) {
				return false;
			}

			if ( $object && ! is_wp_error( $object ) && 0 !== $object->get_id() && ! $object->current_user_can( 'delete' ) ) {
				return new WP_Error( 'yith_wcaf_rest_cannot_delete', _x( 'Sorry, you are not allowed to delete this resource.', '[REST API] Api error', 'yith-woocommerce-affiliates' ), array( 'status' => rest_authorization_required_code() ) );
			}

			return true;
		}
	}
}
