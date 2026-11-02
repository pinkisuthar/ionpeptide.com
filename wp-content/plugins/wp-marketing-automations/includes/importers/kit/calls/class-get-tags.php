<?php

namespace BWFAN\Importers\Kit\Calls;

/**
 * Get all tags from Kit
 */
#[\AllowDynamicProperties]
class Get_Tags extends Call {
	protected $required_fields = array( 'api_key' );

	/**
	 * Get endpoint URL for tags
	 *
	 * @return string Endpoint URL for tags
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url( 'tags' );
	}

	/**
	 * Get request parameters for tags
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