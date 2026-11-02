<?php

namespace BWFAN\Importers\Kit\Calls;

/**
 * Get all custom fields from Kit
 */
#[\AllowDynamicProperties]
class Get_Custom_Fields extends Call {
	protected $required_fields = array( 'api_key' );

	/**
	 * Get endpoint URL for custom fields
	 *
	 * @return string Endpoint URL for custom fields
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url( 'custom_fields' );
	}

	/**
	 * Get request parameters for custom fields
	 *
	 * @return array Request parameters
	 */
	public function request_params() {
		$params = array();
		if ( isset( $this->data['after'] ) ) {
			$params['after'] = $this->data['after'];
		}
		if ( isset( $this->data['before'] ) ) {
			$params['before'] = $this->data['before'];
		}
		if ( isset( $this->data['per_page'] ) ) {
			$params['per_page'] = $this->data['per_page'];
		}

		return $params;
	}
} 