<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for controller-style API routes.
 *
 * One class registers ALL routes under a common prefix.
 * Each route maps to a named handler method on the class.
 * Extends BWFAN_API_Base for utility methods (success_response,
 * error_response, get_sanitized_arg, pagination, etc).
 *
 * Usage in child class:
 *   protected function register_routes() {
 *       $this->add_route( '/v3/contacts', [
 *           [ 'GET', 'get_contacts', [ 'search' => [...] ] ],
 *           [ 'POST', 'create_contact' ],
 *           [ 'DELETE', 'delete_contacts' ],
 *       ]);
 *       $this->add_route( '/v3/contacts/(?P<contact_id>[\d]+)', [
 *           [ 'GET', 'get_contact' ],
 *       ]);
 *   }
 *
 * File bottom: ClassName::get_instance(); (no BWFAN_API_Loader::register)
 */
abstract class BWFAN_API_Controller extends BWFAN_API_Base {

	/**
	 * Whether this controller's routes are public (no auth).
	 *
	 * @var bool
	 */
	public $public_api = false;

	public function __construct() {
		parent::__construct();
		$this->register_routes();
	}

	/**
	 * Child classes override this to register all their routes.
	 */
	abstract protected function register_routes();

	/**
	 * Not used in controllers — dispatch happens via named handlers.
	 */
	public function process_api_call() {
		return $this->error_response( __( 'Not supported', 'wp-marketing-automations' ), null, 500 );
	}

	/**
	 * Register a route with one or more HTTP method handlers.
	 *
	 * @param string $route    Route path (e.g. '/v3/contacts/(?P<contact_id>[\d]+)').
	 * @param array  $endpoints Array of [ HTTP_METHOD, handler_method_name, ?args ].
	 */
	protected function add_route( $route, $endpoints ) {
		$wp_methods = array(
			'GET'    => WP_REST_Server::READABLE,
			'POST'   => WP_REST_Server::CREATABLE,
			'PUT'    => WP_REST_Server::EDITABLE,
			'PATCH'  => WP_REST_Server::EDITABLE,
			'DELETE' => WP_REST_Server::DELETABLE,
		);

		$route_args = array();
		foreach ( $endpoints as $endpoint ) {
			$http_method  = $endpoint[0];
			$handler_name = $endpoint[1];
			$args         = isset( $endpoint[2] ) ? $endpoint[2] : array();

			if ( ! isset( $wp_methods[ $http_method ] ) ) {
				continue;
			}

			$wp_method = $wp_methods[ $http_method ];

			$route_args[] = array(
				'methods'             => $wp_method,
				'callback'            => $this->make_callback( $handler_name, $wp_method ),
				'permission_callback' => $this->public_api
					? '__return_true'
					: array( $this, 'rest_permission_callback' ),
				'args'                => $args,
				'show_in_index'       => false,
			);
		}

		if ( ! empty( $route_args ) ) {
			register_rest_route( BWFAN_API_NAMESPACE, $route, $route_args );
		}
	}

	/**
	 * Create a REST callback closure that does standard request setup
	 * then calls the named handler method.
	 *
	 * @param string $handler_name Method name on this class.
	 * @param string $wp_method    WP_REST_Server method constant.
	 *
	 * @return callable
	 */
	private function make_callback( $handler_name, $wp_method ) {
		$controller = $this;

		return function ( WP_REST_Request $request ) use ( $controller, $handler_name, $wp_method ) {
			return $controller->handle_request( $request, $handler_name, $wp_method );
		};
	}

	/**
	 * Standard request handling: parse params, set pagination, fire hooks, call handler.
	 *
	 * Mirrors BWFAN_API_Base::api_call() logic so all existing business code works.
	 *
	 * @param WP_REST_Request $request      The REST request.
	 * @param string          $handler_name Method name to call.
	 * @param string          $wp_method    WP_REST_Server method constant.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( WP_REST_Request $request, $handler_name, $wp_method ) {
		BWFAN_Common::nocache_headers();

		$this->method = $wp_method;

		$params = WP_REST_Server::EDITABLE === $this->method ? $request->get_params() : false;

		if ( false === $params ) {
			$query_params   = $request->get_query_params();
			$query_params   = is_array( $query_params ) ? $query_params : array();
			$request_params = $request->get_params();
			$request_params = is_array( $request_params ) ? $request_params : array();
			$params         = array_replace( $query_params, $request_params );
		}

		if ( isset( $params['content'] ) && ! empty( $params['content'] ) && is_string( $params['content'] ) ) {
			$replace_array = BWFAN_Common::get_mail_replace_string();
			if ( ! empty( $replace_array ) ) {
				$params['content'] = str_replace( array_values( $replace_array ), array_keys( $replace_array ), $params['content'] );
			}
		}

		$params['files'] = $request->get_file_params();

		$this->pagination->limit  = ! empty( $params['limit'] ) ? absint( $params['limit'] ) : $this->pagination->limit;
		$this->pagination->offset = ! empty( $params['offset'] ) ? absint( $params['offset'] ) : 0;
		$this->args               = wp_parse_args( $params, $this->default_args_values() );

		// Reset response code for each request
		$this->response_code = 200;

		try {
			do_action( 'bwfan_rest_call' );

			return $this->$handler_name();
		} catch ( Error $e ) {
			$this->response_code = 500;

			return $this->error_response( $e->getMessage() );
		}
	}
}
