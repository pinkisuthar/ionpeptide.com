<?php

namespace BWFAN\Importers\Kit\Calls;

use WFCO_Call;
use BWF_CO;

/**
 * Class Call
 *
 * Base class for all Kit API calls.
 * Handles authentication, endpoint construction, and common API functionality.
 */
#[\AllowDynamicProperties]
class Call extends WFCO_Call {
	/**
	 * Required fields for API calls
	 *
	 * @var array
	 */
	protected $required_fields = array( 'api_key' );

	/**
	 * Singleton instance
	 *
	 * @var null|Call
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return Call Instance of the class
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get the Kit API endpoint URL
	 *
	 * @param string $api_action API endpoint path
	 *
	 * @return string Complete API endpoint URL
	 */
	public function get_endpoint_url( $api_action = '' ) {
		return "https://api.kit.com/v4/" . $api_action;
	}

	/**
	 * Get the base endpoint URL for the request
	 *
	 * @return string Complete endpoint URL for the request
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url();
	}

	/**
	 * Default request parameters
	 *
	 * @return array Default parameters for API requests
	 */
	public function request_params() {
		return array(
			'api_key' => $this->data['api_key'],
		);
	}

	/**
	 * Process the API call
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