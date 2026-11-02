<?php

namespace BWFAN\Importers\Mailchimp\Calls;

/**
 * Get all lists (audiences)
 */
#[\AllowDynamicProperties]
class Get_Lists extends Call {
	protected $required_fields = array( 'api_key' );

	public function request_endpoint_url() {
		return $this->get_endpoint_url( substr( strstr( $this->data['api_key'], '-' ), 1 ), 'lists' );
	}
}