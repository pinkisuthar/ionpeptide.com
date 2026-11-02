<?php

namespace BWFAN\Importers\Kit\Calls;

use BWF_CO;

/**
 * Class Connect
 *
 * Handles Kit API connection validation
 */
#[\AllowDynamicProperties]
class Connect extends Call {
	protected $required_fields = array( 'api_key' );

	/**
	 * Get endpoint URL for account validation
	 *
	 * @return string Endpoint URL for validation
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url( 'account' );
	}

	/**
	 * API Secret parameter for validation
	 *
	 * @return array API Secret parameter
	 */
	public function request_params() {
		return array(
			'api_key' => $this->data['api_key'],
		);
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
			'Content-Type'  => 'application/json',
			'X-Kit-Api-Key' => $this->data['api_key'],
		), BWF_CO::$GET );
	}
} 