<?php

namespace BWFAN\Importers\Mailchimp\Calls;

/**
 * Get segments (tags) for a list
 */
#[\AllowDynamicProperties]
class Get_Tags extends Call {
	protected $required_fields = array( 'api_key', 'list_id' );

	public function request_endpoint_url() {
		return $this->get_endpoint_url( substr( strstr( $this->data['api_key'], '-' ), 1 ), 'lists/' . $this->data['list_id'] . '/segments' );
	}
}