<?php

namespace BWFAN\Importers\Mailchimp\Calls;

use BWF_CO;

/**
 * Class Connect
 *
 * Handles Mailchimp API connection validation
 */
#[\AllowDynamicProperties]
class Connect extends Call {
	/**
	 * Get static endpoint URL
	 *
	 * @param string $api_key API key
	 * @param string $dc Data center
	 * @param string $api_action API action path
	 *
	 * @return string Complete endpoint URL
	 */
	public static function endpoint( $api_key = '', $dc = '', $api_action = '' ) {
		if ( empty( $dc ) && ! empty( $api_key ) ) {
			$dc = substr( strstr( $api_key, '-' ), 1 );
		}
		if ( ! self::is_valid_dc( $dc ) ) {
			return '';
		}

		return "https://{$dc}.api.mailchimp.com/3.0/{$api_action}";
	}

	/**
	 * Get endpoint URL for ping/validation request
	 *
	 * @return string Endpoint URL for validation
	 */
	public function request_endpoint_url() {
		$dc = substr( strstr( $this->data['api_key'], '-' ), 1 );

		return self::endpoint( $this->data['api_key'], $dc, 'ping' );
	}

	/**
	 * No parameters needed for validation
	 *
	 * @return array Empty array
	 */
	public function request_params() {
		return array();
	}

	/**
	 * Process the validation request
	 *
	 * @return array Response from the API
	 */
	public function process() {
		$is_required_fields_present = $this->check_fields( $this->data, $this->required_fields );
		if ( false === $is_required_fields_present ) {
			return $this->show_fields_error();
		}

		return $this->make_wp_requests( $this->request_endpoint_url(), $this->request_params(), array(
				'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $this->data['api_key'] ),
				'Content-Type'  => 'application/json',
			), BWF_CO::$GET );
	}
}