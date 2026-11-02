<?php

namespace BWFAN\Importers\AC\Calls;

use BWF_CO;

#[\AllowDynamicProperties]
class Connect extends Call {

	/**
	 * Get endpoint url.
	 *
	 * @param string $api_key
	 * @param string $api_url
	 * @param string $api_action
	 *
	 * @return string
	 */
	public static function endpoint( $api_key = '', $api_url = '', $api_action = '' ) {
		if ( ! self::is_valid_api_url( $api_url ) ) {
			return '';
		}
		$base = '';
		if ( ! preg_match( '/https:\/\/www.activecampaign.com/', $api_url ) ) {
			$base = '/admin';
		}
		if ( preg_match( '/\/$/', $api_url ) ) {
			// remove trailing slash
			$api_url = substr( $api_url, 0, strlen( $api_url ) - 1 );
		}
		if ( $api_key ) {
			$api_url = "{$api_url}{$base}/api.php?api_key={$api_key}";
		}

		return "{$api_url}&api_action={$api_action}&api_output=serialize";
	}

	/**
	 * Returns the endpoint URL for the validation request.
	 *
	 * @return string The endpoint URL.
	 */
	public function request_endpoint_url() {
		return self::endpoint( $this->data['api_key'], $this->data['api_url'], 'user_me' );
	}

	/**
	 * Returns an empty array of request parameters.
	 *
	 * @return array The array of request parameters.
	 */
	public function request_params() {
		return array();
	}

	/**
	 * Process the API validation call.
	 *
	 * @return array|WP_Error The response from the API or a WP_Error object.
	 */
	public function process() {
		$is_required_fields_present = $this->check_fields( $this->data, $this->required_fields );
		if ( false === $is_required_fields_present ) {
			return $this->show_fields_error();
		}

		return $this->make_wp_requests( $this->request_endpoint_url(), $this->request_params(), array(), BWF_CO::$GET );
	}
}