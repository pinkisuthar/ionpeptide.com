<?php

namespace BWFAN\Importers\Kit\Calls;

/**
 * Get all subscribers from Kit
 */
#[\AllowDynamicProperties]
class Get_Subscribers extends Call {
	protected $required_fields = array( 'api_key' );

	/**
	 * Get endpoint URL for subscribers
	 *
	 * @return string Endpoint URL for subscribers
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url( 'subscribers' );
	}

	/**
	 * Get request parameters for subscribers
	 *
	 * @return array Request parameters
	 */
	public function request_params() {

		// Add more Kit API supported params as needed
		return [
			'per_page'            => $this->data['limit'] ?? 100,
			'after'               => $this->data['offset'] ?? '',
			'status'              => $this->data['status'] ?? 'all',
			'include_total_count' => $this->data['include_total_count'] ?? false
		];
	}
} 