<?php

namespace BWFAN\Importers\AC\Calls;

#[\AllowDynamicProperties]
class Get_Contact_All_Data extends Call {
	protected $required_fields = array( 'api_key', 'api_url' );

	/**
	 * Get the request endpoint URL for retrieving contacts with all related data.
	 *
	 * @return string The endpoint URL.
	 */
	public function request_endpoint_url() {
		return $this->get_endpoint_url( $this->data['api_url'], 'contacts' );
	}

	/**
	 * Returns the request parameters for retrieving contacts with all related data.
	 *
	 * @return array The request parameters.
	 */
	public function request_params() {
		$params = [
			'limit'  => $this->data['limit'] ?? 100,
			'offset' => $this->data['offset'] ?? 0,
		];

		// Add includes parameter
		$includes = [];

		// Always include field values by default
		$includes[] = 'fieldValues';

		// Add contact tags if specified
		if ( ! empty( $this->data['include_tags'] ) ) {
			$includes[] = 'contactTags';
		}

		// Add contact lists if specified
		if ( ! empty( $this->data['include_lists'] ) ) {
			$includes[] = 'contactLists';
		}

		// Add account contacts if specified
		if ( ! empty( $this->data['include_account_contacts'] ) ) {
			$includes[] = 'accountContacts';
		}

		// Add custom includes if provided
		if ( ! empty( $this->data['includes'] ) && is_array( $this->data['includes'] ) ) {
			$includes = array_merge( $includes, $this->data['includes'] );
		}

		// Remove duplicates and set the include parameter
		$includes = array_unique( $includes );
		if ( ! empty( $includes ) ) {
			$params['include'] = implode( ',', $includes );
		}

		return $params;
	}
}