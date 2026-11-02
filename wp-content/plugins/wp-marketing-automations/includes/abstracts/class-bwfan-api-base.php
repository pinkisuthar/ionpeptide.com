<?php

#[AllowDynamicProperties]
abstract class BWFAN_API_Base {

	/**
	 * @var string $route
	 */
	public $route = null;

	/**
	 * @var string $method
	 */
	public $method = null;

	/**
	 * @var stdClass $pagination
	 *
	 * It contains two keys: Limit and Offset, for pagination purposes
	 */
	public $pagination = null;

	public $response_code = 200;

	public $args = array();

	public $request_args = array();

	public $public_api = false;

	public function __construct() {
		// Only initialise when a subclass has not already set its own pagination
		// defaults (some controllers set limit/offset BEFORE calling parent::__construct()).
		if ( null === $this->pagination ) {
			$this->pagination         = new stdClass();
			$this->pagination->limit  = 0;
			$this->pagination->offset = 0;
		}
	}

	public function api_call( WP_REST_Request $request ) {
		BWFAN_Common::nocache_headers();
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

		try {

			/**
			 * Include necessary classes so that can run admin rest endpoints.
			 */
			do_action( 'bwfan_rest_call' );

			return $this->process_api_call();
		} catch ( Error $e ) {
			$this->response_code = 500;

			return $this->error_response( $e->getMessage() );
		}
	}

	public function default_args_values() {
		return array();
	}

	/** To be implemented in Child Class. Override in Child Class */
	public function get_result_total_count() {
		return 0;
	}

	/** To set count data */
	public function get_result_count_data() {
		return 0;
	}

	public function error_response( $message = '', $wp_error = null, $code = 0 ) {
		if ( 0 !== absint( $code ) ) {
			$this->response_code = $code;
		}

		$data = array();
		if ( $wp_error instanceof WP_Error ) {
			$message = $wp_error->get_error_message();
			$data    = $wp_error->get_error_data();
		}

		return new WP_Error( $this->response_code, $message, array( 'status' => $this->response_code, 'error_data' => $data ) );
	}

	public function error_response_200( $message = '', $wp_error = null, $code = 0 ) {
		if ( 0 !== absint( $code ) ) {
			$this->response_code = $code;
		} else if ( empty( $this->response_code ) ) {
			$this->response_code = 500;
		}

		$data = array();
		if ( $wp_error instanceof WP_Error ) {
			$message = $wp_error->get_error_message();
			$data    = $wp_error->get_error_data();
		}

		return new WP_Error( $this->response_code, $message, array( 'status' => 200, 'error_data' => $data ) );
	}

	public function success_response( $result_array, $message = '' ) {
		$response = BWFAN_Common::format_success_response( $result_array, $message, $this->response_code );

		/** Total Count */
		$total_count = $this->get_result_total_count();
		if ( ! empty( $total_count ) ) {
			$response['total_count'] = $total_count;
		}

		/** Count Data */
		$count_data = $this->get_result_count_data();
		if ( ! empty( $count_data ) ) {
			$response['count_data'] = $count_data;
		}

		/** Pagination */
		if ( isset( $this->pagination->limit ) && ( 0 === $this->pagination->limit || ! empty( $this->pagination->limit ) ) ) {
			$response['limit'] = absint( $this->pagination->limit );
		}

		if ( isset( $this->pagination->offset ) && ( 0 === $this->pagination->offset || ! empty( $this->pagination->offset ) ) ) {
			$response['offset'] = absint( $this->pagination->offset );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * @param string $key
	 * @param string $is_a
	 * @param string $collection
	 *
	 * @return bool|array|mixed
	 */
	public function get_sanitized_arg( $key = '', $is_a = 'key', $collection = '' ) {
		if ( 'bool' === $is_a ) {
			$sanitize_method = 'rest_sanitize_boolean';
		} elseif ( in_array( $is_a, array( 'absint', 'int', 'integer' ), true ) ) {
			// Force integer sanitization for numeric args (e.g. pagination limit/offset) to neutralize SQL injection.
			$sanitize_method = 'absint';
		} else {
			$sanitize_method = 'sanitize_' . $is_a;
		}
		if ( ! is_array( $collection ) ) {
			$collection = $this->args;
		}

		if ( ! empty( $key ) && isset( $collection[ $key ] ) && ! empty( $collection[ $key ] ) ) {
			return call_user_func( $sanitize_method, $collection[ $key ] );
		}

		if ( ! empty( $key ) ) {
			return false;
		}

		return array_map( $sanitize_method, $collection );
	}

	/**
	 * rest api permission callback
	 *
	 * @return bool
	 */
	public function rest_permission_callback( WP_REST_Request $request ) {
		if ( 0 === get_current_user_id() ) {
			$query_params = $request->get_query_params();
			$expected     = (string) get_option( 'bwfan_unique_secret', '' );
			$provided     = isset( $query_params['bwf-nonce'] ) ? (string) $query_params['bwf-nonce'] : '';
			if ( '' !== $expected && hash_equals( $expected, $provided ) ) {
				return true;
			}
		}
		$permissions = BWFAN_Common::access_capabilities();
		foreach ( $permissions as $permission ) {
			if ( current_user_can( $permission ) ) {
				return true;
			}
		}

		return false;
	}

	abstract public function process_api_call();


	/**
	 * @param $id_key
	 * @param $email_key
	 *
	 * @return BWFCRM_Contact|WP_Error
	 */
	public function get_contact_by_id_or_email( $id_key, $email_key ) {
		$email       = $this->get_sanitized_arg( $email_key, 'text_field' );
		$id          = $this->get_sanitized_arg( $id_key, 'text_field' );
		$id_or_email = ( is_numeric( $id ) && absint( $id ) > 0 ? absint( $id ) : ( is_email( $email ) ? $email : '' ) );

		$contact = new BWFCRM_Contact( $id_or_email, true );

		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;
			if ( is_numeric( $id_or_email ) ) {
				$response = __( 'Unable to get contact with contact ID : ', 'wp-marketing-automations' ) . $id_or_email;
			} else {
				$response = __( 'Contact not exists with email: ', 'wp-marketing-automations' ) . $id_or_email;
			}

			return $this->error_response( $response );
		}

		return $contact;
	}

}
