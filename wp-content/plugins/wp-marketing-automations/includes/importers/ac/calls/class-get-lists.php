<?php
namespace BWFAN\Importers\AC\Calls;

#[\AllowDynamicProperties]
class Get_Lists extends Call {
	/**
	 * Returns the endpoint URL for the request.
	 *
	 * @return string The endpoint URL.
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url( $this->data['api_url'], 'lists' );
	}
}
