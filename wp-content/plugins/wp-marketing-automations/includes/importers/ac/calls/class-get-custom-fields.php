<?php
namespace BWFAN\Importers\AC\Calls;

#[\AllowDynamicProperties]
class Get_Custom_Fields extends Call {
	/**
	 * Get the request endpoint URL.
	 * 
	 * @return string The endpoint URL for retrieving custom fields.
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url( $this->data['api_url'], 'fields' );
	}
}
