<?php
namespace BWFAN\Importers\AC\Calls;

use WFCO_Call;
use BWF_CO;

/**
 * Class Call
 *
 * This class extends the WFCO_Call class and represents a call object.
 * It provides methods to retrieve the instance, get the endpoint URL, and set required fields.
 */
#[\AllowDynamicProperties]
class Call extends WFCO_Call {
	/**
	 * The required fields for the Call class.
	 *
	 * @var array
	 */
	protected $required_fields = array( 'api_key', 'api_url' );

	/**
	 * Represents a singleton instance of the Call class.
	 *
	 * @var null|Call
	 */
	private static $instance = null;

	/**
	 * Get the instance of the Call class.
	 * If the instance does not exist, create a new one.
	 *
	 * @return Call The instance of the Call class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get the endpoint URL for the API call.
	 *
	 * @param string $api_url The base API URL.
	 * @param string $api_action The API action.
	 * @return string The complete endpoint URL.
	 */
	public function get_endpoint_url( $api_url, $api_action = '' ) {
		if ( ! self::is_valid_api_url( $api_url ) ) {
			return '';
		}

		return $api_url . '/api/3/' . $api_action;
	}

	/**
	 * Validate the admin-supplied ActiveCampaign account URL before it is fetched, so the
	 * importer cannot be pointed at an internal or non-HTTPS host (SSRF). An empty return
	 * makes the request fail closed in make_wp_requests().
	 *
	 * @param mixed $api_url
	 *
	 * @return bool
	 */
	protected static function is_valid_api_url( $api_url ) {
		return is_string( $api_url ) && 0 === stripos( $api_url, 'https://' ) && false !== wp_http_validate_url( $api_url );
	}

	/**
	 * Returns the endpoint URL for making a request.
	 *
	 * @return string The endpoint URL.
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url( $this->data['api_url'] );
	}

	/**
	 * Returns an array of request parameters for the call.
	 *
	 * @return array The request parameters.
	 */
	public function request_params() {
		return array(
			'limit'  => 100,
			'offset' => 0,
		);
	}

	/**
	 * Process the call data.
	 *
	 * This method checks if the required fields are present in the data and makes the necessary API requests.
	 *
	 * @return array
	 */
	public function process() {
		$is_required_fields_present = $this->check_fields( $this->data, $this->required_fields );
		if ( false === $is_required_fields_present ) {
			return $this->show_fields_error();
		}

		return $this->make_wp_requests(
			$this->request_endpoint_url(),
			$this->request_params(),
			array(
				'Api-Token' => $this->data['api_key'],
			),
			BWF_CO::$GET
		);
	}
}
