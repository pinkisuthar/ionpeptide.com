<?php

namespace BWFAN\Importers;

/**
 * Interface for Autoresponder Importer.
 */
interface Autoresponder_Importer_Interface {
	/**
	 * Set API credentials.
	 *
	 * @param array $api_credentials The API credentials.
	 */
	public function set_api_credentials( $api_credentials = array() );

	/**
	 * Get API credentials.
	 *
	 * @return array The API credentials.
	 */
	public function get_api_credentials();

	/**
	 * Connect to the autoresponder service.
	 */
	public function connect( $call_class, $api_credentials = array() );

	/**
	 * Retrieves the mapping options for the autoresponder importer.
	 *
	 * @return array The available mapping options.
	 */
	public function get_mapping_options();

	/**
	 * Interface for autoresponder importers.
	 */
	public function get_contacts( $args = array() );

	/**
	 * Retrieves the count of contacts.
	 *
	 * @return int The number of contacts.
	 */
	public function get_contacts_count();

	/**
	 * Prepares a contact for import.
	 *
	 * @param array $contact The contact data to be prepared.
	 *
	 * @return void
	 */
	public function prepare_contact( $contact = array() );

	/**
	 * Retrieves the custom fields associated with the autoresponder importer.
	 *
	 * @return array An array of custom fields.
	 */
	public function get_custom_fields();

	/**
	 * Get available lists from the autoresponder service.
	 *
	 * @return array The available lists.
	 */
	public function get_lists();

	/**
	 * Get available tags from the autoresponder service.
	 *
	 * @return array The available tags.
	 */
	public function get_tags();
}
