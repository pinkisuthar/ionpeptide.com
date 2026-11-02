<?php
namespace BWFAN\Importers\AC\Calls;

#[\AllowDynamicProperties]
class Get_Tags extends Call {
	/**
	 * Returns the endpoint URL for making a request to retrieve tags.
	 *
	 * @return string The endpoint URL.
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url( $this->data['api_url'], 'tags' );
	}
}
