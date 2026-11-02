<?php

namespace BWFAN\Importers\AC\Calls;

#[\AllowDynamicProperties]
class Get_Contacts extends Call {
	/**
	 * Returns the endpoint URL for making a request to retrieve contacts.
	 *
	 * @return string The endpoint URL.
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url( $this->data['api_url'], 'contacts' );
	}

	/**
	 * Returns the request parameters for retrieving contacts.
	 *
	 * @return array The request parameters.
	 */
	public function request_params() {
		return [
			'limit'  => $this->data['limit'] ?? 100,
			'offset' => $this->data['offset'] ?? 0,
		];
	}
}
