<?php

namespace BWFAN\Importers\Kit\Calls;

/**
 * Get all subscribers from Kit
 */
#[\AllowDynamicProperties]
class Get_Subscriber_tags extends Call {
	protected $required_fields = array( 'api_key', 'subscriber_id' );

	/**
	 * Get endpoint URL for subscribers
	 *
	 * @return string Endpoint URL for subscribers
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url(  '/subscribers/' . $this->data['subscriber_id'] . '/tags' );
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