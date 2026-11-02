<?php

namespace BWFAN\Importers\Mailchimp\Calls;

/**
 * Get all members from a list
 */
#[\AllowDynamicProperties]
class Get_Members extends Call {
	protected $required_fields = array( 'api_key', 'list_id' );

	public function request_endpoint_url() {
		return $this->get_endpoint_url( substr( strstr( $this->data['api_key'], '-' ), 1 ), 'lists/' . $this->data['list_id'] . '/members' );
	}

	public function request_params() {
		return array(
			'count'  => $this->data['limit'] ?? 100,
			'offset' => $this->data['offset'] ?? 0,
		);
	}
}