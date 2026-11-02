<?php

namespace BWFAN\Importers\Mailchimp\Calls;

use WFCO_Call;
use BWF_CO;

/**
 * Class Call
 *
 * Base class for all Mailchimp API calls.
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
	 * Get the Mailchimp API endpoint URL
	 *
	 * @param string $dc Data center from API key
	 * @param string $api_action API endpoint path
	 *
	 * @return string Complete API endpoint URL
	 */
	public function get_endpoint_url( $dc, $api_action = '' ) {
		if ( ! self::is_valid_dc( $dc ) ) {
			return '';
		}

		return "https://{$dc}.api.mailchimp.com/3.0/" . $api_action;
	}

	/**
	 * Validate the data-center token derived from the API key. Mailchimp data centers are
	 * alphanumeric (e.g. us21); rejecting anything else stops a crafted API key from
	 * injecting a host into the endpoint URL (SSRF). An empty return fails closed.
	 *
	 * @param mixed $dc
	 *
	 * @return bool
	 */
	protected static function is_valid_dc( $dc ) {
		return is_string( $dc ) && 1 === preg_match( '/^[a-z0-9]+$/', $dc );
	}

	/**
	 * Extract data center from API key and construct endpoint URL
	 *
	 * @return string Complete endpoint URL for the request
	 */
	public function request_endpoint_url() {
		$dc = substr( strstr( $this->data['api_key'], '-' ), 1 );

		return $this->get_endpoint_url( $dc );
	}

	/**
	 * Default request parameters
	 *
	 * @return array Default parameters for API requests
	 */
	public function request_params() {
		return array(
			'count'  => 100,
			'offset' => 0,
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
				'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $this->data['api_key'] ),
				'Content-Type'  => 'application/json',
			), BWF_CO::$GET );
	}
}