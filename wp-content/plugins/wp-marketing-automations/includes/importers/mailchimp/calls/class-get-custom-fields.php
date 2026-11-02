<?php

namespace BWFAN\Importers\Mailchimp\Calls;

/**
 * Get merge fields (custom fields) for a list
 */
#[\AllowDynamicProperties]
class Get_Merge_Fields extends Call {
	protected $required_fields = array( 'api_key', 'list_id' );

	public function request_endpoint_url() {
		return $this->get_endpoint_url( substr( strstr( $this->data['api_key'], '-' ), 1 ), 'lists/' . $this->data['list_id'] . '/merge-fields' );
	}
}