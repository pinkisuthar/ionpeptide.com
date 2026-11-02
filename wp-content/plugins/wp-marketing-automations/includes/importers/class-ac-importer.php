<?php

namespace BWFAN\Importers;

defined( 'ABSPATH' ) || exit;

use BWFAN\Importers\AC\Calls\Get_Contact_All_Data;
use BWFAN\Importers\AC\Calls\Get_Contacts;
use BWFAN\Importers\AC\Calls\Get_Custom_Fields;
use BWFAN\Importers\AC\Calls\Get_Lists;
use BWFAN\Importers\AC\Calls\Get_Tags;
use BWFAN_Model_Terms;
use BWFCRM_Term_Type;
use WFCO_Common;
use WP_Error;


#[\AllowDynamicProperties]
class AC_Importer extends Importer implements Autoresponder_Importer_Interface {

	protected $import_type = 'ac';
	protected $mapping;
	protected $ac_tags;
	protected $ac_lists;
	protected $regular_tags;
	protected $auto_tags;
	protected $regular_lists;
	protected $auto_lists;
	protected $regular_tag_values;
	protected $regular_list_values;
	private $term_id_mapping = [
		'tags'  => [],
		'lists' => [],
	];

	/**
	 * Constructor for the ActiveCampaign class.
	 *
	 * @param array $params Parameters for the importer.
	 */
	public function __construct( $params = array() ) {
		$this->slug        = 'ac';
		$this->name        = __( 'ActiveCampaign', 'wp-marketing-automations' );
		$this->description = __( 'Connect to ActiveCampaign', 'wp-marketing-automations' );
		$this->logo_url    = esc_url( plugin_dir_url( BWFAN_PLUGIN_FILE ) . '/admin/assets/img/importer/ac.png' );
		$this->has_fields  = true;
		$this->group       = 1;
		$this->priority    = 12;

		$params = wp_parse_args( $params );
		$this->process_calls();

		parent::__construct( $params );
	}

	/**
	 * Returns the field schema for the ActiveCampaign importer.
	 *
	 * @return array The field schema for the importer.
	 */
	public function get_field_schema() {
		return [
			[
				'id'          => 'api_url',
				'label'       => __( 'API URL', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_ac_api_url',
				'placeholder' => __( 'Type Here', 'wp-marketing-automations' ),
				'errorMsg'    => __( 'Enter a valid API URL', 'wp-marketing-automations' ),
				'hint'        => __( 'Get your API key at ActiveCampaign Account > API keys ', 'wp-marketing-automations' ) . ' <a href="https://funnelkit.com/docs/autonami-2/contacts/import-contacts-from-activecampaign/" target="_blank" rel="noopener noreferrer">' . __( 'View Docs', 'wp-marketing-automations' ) . '</a>',
				'required'    => true
			],
			[
				'id'          => 'api_key',
				'label'       => __( 'API Key', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_ac_api_key',
				'placeholder' => __( 'Type Here', 'wp-marketing-automations' ),
				'errorMsg'    => __( 'Enter a valid API Key', 'wp-marketing-automations' ),
				'required'    => true
			]
		];
	}

	/**
	 * Returns the API credentials for the Mailchimp Importer.
	 *
	 * @return array The API credentials.
	 */
	public function get_default_values() {
		$stored_credentials = $this->get_api_credentials();

		if ( ! isset( $stored_credentials ) && empty( $stored_credentials ) ) {
			$saved_data = class_exists( 'WFCO_Common' ) ? WFCO_Common::$connectors_saved_data : array();
			$old_data   = ( isset( $saved_data['bwfco_activecampaign'] ) && is_array( $saved_data['bwfco_activecampaign'] ) && count( $saved_data['bwfco_activecampaign'] ) > 0 ) ? $saved_data['bwfco_activecampaign'] : array();

			if ( ! empty( $old_data ) && isset( $old_data['api_key'] ) && isset( $old_data['api_url'] ) ) {
				$stored_credentials = array(
					'api_key' => $old_data['api_key'],
					'api_url' => $old_data['api_url']
				);
			}
		}

		return [
			'api_url' => ! empty( $stored_credentials['api_url'] ) ? $stored_credentials['api_url'] : '',
			'api_key' => ! empty( $stored_credentials['api_key'] ) ? $stored_credentials['api_key'] : ''
		];
	}

	public function process_calls() {
		$calls_dir = __DIR__ . '/ac/calls';

		if ( is_dir( $calls_dir ) ) {
			\BWFAN_Importer::load_class_files( $calls_dir );
		}
	}

	/**
	 * Prepares the import data for creating a new import.
	 *
	 * @param array $import_data The import data.
	 *
	 * @return array The modified import data.
	 */
	public function prepare_create_import_data( $import_data = array(), $fields = array() ) {

		$import_data['count'] = $this->get_contacts_count();

		return $import_data;
	}

	/**
	 * Prepare the data for importing.
	 *
	 * This method retrieves contacts from the ActiveCampaign API and prepares the data for importing.
	 * It sets the raw_data property with an array of contact IDs.
	 *
	 * @return mixed|void|WP_Error
	 */
	public function populate_contact_data() {
		$contacts = $this->get_all_contact_data();

		if ( $contacts instanceof WP_Error || empty( $contacts ) ) {

			return $contacts;
		}

		return $this->prepare_contact( $contacts );
	}

	/**
	 * Get source-specific data from the prepared contact data
	 *
	 * @param mixed $data The contact data
	 *
	 * @return array|WP_Error The formatted source data or error
	 */
	protected function get_source_specific_data( $data ) {
		if ( empty( $data ) ) {
			return new WP_Error( 'bwfcrm_empty_data', __( 'No data provided.', 'wp-marketing-automations' ) );
		}

		$source_data = [
			'email'      => $data['email'] ?? '',
			'first_name' => $data['first_name'] ?? '',
			'last_name'  => $data['last_name'] ?? '',
			'phone'      => $data['phone'] ?? '',
			'orgname'    => $data['orgname'] ?? '',
			'job_title'  => $data['job_title'] ?? '',
		];

		foreach ( $data as $key => $value ) {
			// Process custom field IDs
			if ( is_numeric( $key ) ) {
				$source_data[ $key ] = $value;
			}
		}

		return $source_data;
	}

	/**
	 * Formats a selected value by converting double-pipe separated strings into comma-separated values.
	 *
	 * @param mixed $value The value to format, expected to be a string with || separators
	 *
	 * @return mixed The formatted string with comma separation, or original value if not applicable
	 *
	 */
	protected function format_select_value( $value ) {
		if ( is_string( $value ) && strpos( $value, '||' ) !== false ) {
			$value  = trim( $value, '||' );
			$values = array_filter( array_map( 'trim', explode( '||', $value ) ) );

			return implode( ', ', $values );
		}

		return $value;
	}

	/**
	 * Prepare contact data for import.
	 *
	 * @param array $contacts The contact data to be prepared for import.
	 *
	 * @return array The prepared contact data.
	 */
	public function prepare_contact( $contacts = array() ) {
		if ( empty( $contacts ) ) {
			return [];
		}

		$account_contacts = $contacts['accountContacts'] ?? [];
		$field_values     = $contacts['fieldValues'] ?? [];
		$contact_tags     = $contacts['contactTags'] ?? [];
		$contact_lists    = $contacts['contactLists'] ?? [];

		// Initialize data arrays
		$all_account_contacts = $all_field_values = $all_tags = $all_lists = $contact_status = [];

		// Process account contacts if available
		if ( ! empty( $account_contacts ) && is_array( $account_contacts ) && isset( $this->mapping['job_title'] ) ) {
			foreach ( $account_contacts as $acc_contact ) {
				$all_account_contacts[ $acc_contact['contact'] ] = $acc_contact['jobTitle'] ?? '';
			}
		}

		// Process field values
		if ( ! empty( $field_values ) && is_array( $field_values ) ) {
			foreach ( $field_values as $field_value ) {
				if ( ! isset( $all_field_values[ $field_value['contact'] ] ) ) {
					$all_field_values[ $field_value['contact'] ] = [];
				}
				$all_field_values[ $field_value['contact'] ][ $field_value['field'] ] = $field_value['value'];
			}
		}

		// Process tags
		if ( ! empty( $this->auto_tags ) && ! empty( $contact_tags ) && is_array( $contact_tags ) ) {
			foreach ( $contact_tags as $tag ) {
				if ( ! isset( $this->auto_tags[ $tag['tag'] ] ) ) {
					continue;
				}
				if ( ! isset( $all_tags[ $tag['contact'] ] ) ) {
					$all_tags[ $tag['contact'] ] = [];
				}
				$all_tags[ $tag['contact'] ][] = $tag['tag'];

			}
		}

		// Process lists
		if ( ! empty( $this->auto_lists ) && ! empty( $contact_lists ) && is_array( $contact_lists ) ) {
			foreach ( $contact_lists as $list ) {
				if ( ! isset( $this->auto_lists[ $list['list'] ] ) ) {
					continue;
				}
				if ( ! isset( $all_lists[ $list['contact'] ] ) ) {
					$all_lists[ $list['contact'] ] = [];
				}
				$all_lists[ $list['contact'] ][] = $list['list'];
			}
		}

		// Process contact status
		if ( ! empty( $contact_lists ) && is_array( $contact_lists ) ) {
			foreach ( $contact_lists as $list ) {
				if ( isset( $list['status'] ) ) {
					$contact_status[ $list['contact'] ] = $list['status'];
				}
			}
		}

		$all_contacts      = $contacts['contacts'] ?? [];
		$prepared_contacts = [];
		foreach ( $all_contacts as $contact ) {
			$contact_id = $contact['id'] ?? 0;
			if ( empty( $contact_id ) ) {
				continue;
			}

			$prepared_contact = [
				'first_name' => $contact['firstName'] ?? '',
				'last_name'  => $contact['lastName'] ?? '',
				'email'      => $contact['email'] ?? '',
				'phone'      => $contact['phone'] ?? '',
				'orgname'    => $contact['orgname'] ?? ''
			];

			// Add status
			if ( ! empty( $contact_status[ $contact_id ] ) ) {
				$prepared_contact['status'] = $this->get_contact_status( $contact_status[ $contact_id ] );
			}

			// Add job title
			if ( isset( $this->mapping['job_title'], $all_account_contacts[ $contact_id ] ) ) {
				$prepared_contact['job_title'] = $all_account_contacts[ $contact_id ];
			}

			// Add field values
			if ( isset( $all_field_values[ $contact_id ] ) ) {
				$prepared_contact += $all_field_values[ $contact_id ];
			}

			// Add tags
			if ( ! empty( $this->regular_tag_values ) || isset( $all_tags[ $contact_id ] ) ) {
				$prepared_contact['tags'] = isset( $all_tags[ $contact_id ] ) ? array_merge( $this->regular_tag_values, $all_tags[ $contact_id ] ) : $this->regular_tag_values;
			}

			// Add lists
			if ( ! empty( $this->regular_list_values ) || isset( $all_lists[ $contact_id ] ) ) {
				$prepared_contact['lists'] = isset( $all_lists[ $contact_id ] ) ? array_merge( $this->regular_list_values, $all_lists[ $contact_id ] ) : $this->regular_list_values;
			}

			$prepared_contacts[] = $prepared_contact;
		}

		return $prepared_contacts;
	}

	/**
	 * Prepares the tag and list data for the import process.
	 *
	 * This method initializes and separates tags and lists into two categories:
	 *
	 * @return void
	 */
	public function prepare_tag_list() {
		// Initialize mapping data
		$this->mapping  = $this->get_import_meta( 'mapped_fields' ) ?? [];
		$this->ac_tags  = $this->get_import_meta( 'ac_tags' ) ?? [];
		$this->ac_lists = $this->get_import_meta( 'ac_lists' ) ?? [];

		// Process tags
		$this->regular_tags = [];
		$this->auto_tags    = [];
		foreach ( $this->ac_tags as $tag_id => $tag_data ) {
			if ( ! empty( $tag_data['auto'] ) ) {
				$this->auto_tags[ $tag_id ] = $tag_data;
			} else {
				$this->regular_tags[ $tag_id ] = $tag_id;
			}
		}

		// Process lists
		$this->regular_lists = [];
		$this->auto_lists    = [];
		foreach ( $this->ac_lists as $list_id => $list_data ) {
			if ( ! empty( $list_data['auto'] ) ) {
				$this->auto_lists[ $list_id ] = $list_data;
			} else {
				$this->regular_lists[ $list_id ] = $list_id;
			}
		}

		// Pre-calculate values
		$this->regular_tag_values  = array_values( $this->regular_tags );
		$this->regular_list_values = array_values( $this->regular_lists );
	}

	/**
	 * Returns an array of general fields for importing contacts.
	 *
	 * @return array An array of general fields.
	 */
	public function get_general_fields() {
		return array(
			'email'      => __( 'Email', 'wp-marketing-automations' ),
			'first_name' => __( 'First Name', 'wp-marketing-automations' ),
			'last_name'  => __( 'Last Name', 'wp-marketing-automations' ),
			'phone'      => __( 'Phone', 'wp-marketing-automations' ),
			'orgname'    => __( 'Account', 'wp-marketing-automations' ),
			'job_title'  => __( 'Job Title', 'wp-marketing-automations' ),
		);
	}

	/**
	 * Retrieves the custom fields from the ActiveCampaign API.
	 *
	 * @return array The custom fields retrieved from the API.
	 */
	public function get_custom_fields() {
		$fields = array();

		$custom_fields_call = new Get_Custom_Fields();
		$custom_fields_call->set_data( $this->get_api_credentials() );
		$custom_fields = $custom_fields_call->process();

		if ( 200 === $custom_fields['response'] ) {
			if ( ! empty( $custom_fields['body']['fields'] ) ) {
				foreach ( $custom_fields['body']['fields'] as $field ) {
					$fields[ $field['id'] ] = $field['title'];
				}
			}
		}

		return $fields;
	}

	/**
	 * Retrieves all contact data including field values, tags, lists, and account contacts in a single API call.
	 *
	 * @param array $args Optional arguments to customize the API request
	 *
	 * @return mixed|WP_Error
	 */
	public function get_all_contact_data( $args = [] ) {
		// Prepare call arguments
		$call_args = array_merge( $this->get_api_credentials(), [
			'offset'                   => $this->get_offset(),
			'limit'                    => self::LIMIT,
			'include_tags'             => $this->auto_tags,
			'include_lists'            => $this->auto_lists,
			'include_account_contacts' => isset( $this->mapping['job_title'] )
		], $args );

		// Make the API call
		$data_call = new Get_Contact_All_Data();
		$data_call->set_data( $call_args );

		$response = $data_call->process();

		if ( 200 === $response['response'] ) {
			return $response['body'];
		}

		/** There is no error message coming in response */
		return new WP_Error( 'invalid_credentials', __( 'Invalid API credentials', 'wp-marketing-automations' ) );
	}

	/**
	 * Retrieves the lists from the ActiveCampaign API.
	 *
	 * @return array The prepared lists with their IDs as keys and names as values.
	 */
	public function get_lists() {
		$args = $this->get_api_credentials();

		$lists_call = new Get_Lists();
		$lists_call->set_data( $args );
		$lists = $lists_call->process();

		if ( 200 === $lists['response'] ) {
			if ( ! empty( $lists['body']['lists'] ) ) {
				$prepared_lists = array();
				foreach ( $lists['body']['lists'] as $list ) {
					$prepared_lists[ $list['id'] ] = $list['name'];
				}

				return $prepared_lists;
			}
		}

		return array();
	}

	/**
	 * Retrieves the tags from the ActiveCampaign API.
	 *
	 * @return array The prepared tags retrieved from the API.
	 */
	public function get_tags() {
		$args = $this->get_api_credentials();

		$tags_call = new Get_Tags();
		$tags_call->set_data( $args );
		$tags = $tags_call->process();

		if ( 200 === $tags['response'] ) {
			if ( ! empty( $tags['body']['tags'] ) ) {
				$filtered_tags = wp_list_filter( $tags['body']['tags'], array( 'tagType' => 'contact' ) );
				$prepared_tags = array();
				foreach ( $filtered_tags as $tag ) {
					$prepared_tags[ $tag['id'] ] = $tag['tag'];
				}

				return $prepared_tags;
			}
		}

		return array();
	}

	/**
	 * Retrieves contacts from the ActiveCampaign API.
	 *
	 * @param array $args Optional arguments to customize the API request.
	 *
	 * @return array An array of contacts retrieved from the API.
	 */
	public function get_contacts( $args = array() ) {

		// Prepare call arguments
		$call_args = array_merge( $this->get_api_credentials(), [
			'offset'                   => $this->get_offset(),
			'limit'                    => self::LIMIT,
			'include_tags'             => $this->auto_tags,
			'include_lists'            => $this->auto_lists,
			'include_account_contacts' => isset( $this->mapping['job_title'] )
		], $args );

		// Make the API call
		$data_call = new Get_Contact_All_Data();
		$data_call->set_data( $call_args );

		$response = $data_call->process();

		return 200 === intval( $response['response'] ) ? $response['response'] : [];
	}

	/**
	 * Retrieves the count of contacts from ActiveCampaign API.
	 *
	 * @return int The total count of contacts.
	 */
	public function get_contacts_count() {
		$args = array_merge( array( 'limit' => 1 ), $this->get_api_credentials() );

		$contacts_call = new Get_Contacts();
		$contacts_call->set_data( $args );
		$contacts = $contacts_call->process();

		if ( 200 === $contacts['response'] ) {
			return absint( $contacts['body']['meta']['total'] );
		}

		return 0;
	}

	/**
	 * Retrieves the mapping options for the AC Importer.
	 *
	 * This function returns an array of mapping options, including fields, lists, and tags.
	 * The 'from' array contains options for the source data, while the 'to' array contains options for the destination data.
	 *
	 * @return array The mapping options array.
	 */
	public function get_mapping_options() {
		return array(
			'from' => array(
				'fields' => array(
					'general' => $this->get_general_fields(),
					'custom'  => $this->get_custom_fields(),
				),
				'lists'  => $this->get_lists(),
				'tags'   => $this->get_tags(),
			),
			'to'   => array(
				'fields' => $this->get_crm_fields(),
			),
		);
	}

	/**
	 * Load term mappings for both tags and lists
	 *
	 * @return void
	 */
	public function load_term_mappings() {
		$ac_tags  = $this->get_import_meta( 'ac_tags' ) ?? [];
		$ac_lists = $this->get_import_meta( 'ac_lists' ) ?? [];

		// Process both tags and lists
		$term_types = [
			'tags'  => [
				'terms' => $ac_tags,
				'type'  => BWFCRM_Term_Type::$TAG
			],
			'lists' => [
				'terms' => $ac_lists,
				'type'  => BWFCRM_Term_Type::$LIST
			]
		];

		foreach ( $term_types as $term_key => $data ) {
			if ( empty( $data['terms'] ) ) {
				continue;
			}

			$ac_terms  = $data['terms'];
			$term_type = $data['type'];

			// Extract term names and build ID mapping
			$term_names    = [];
			$ac_id_to_name = [];

			foreach ( $ac_terms as $ac_id => $term_data ) {
				if ( isset( $term_data['title'] ) ) {
					$term_names[]            = $term_data['title'];
					$ac_id_to_name[ $ac_id ] = $term_data['title'];
				}
			}

			// If no terms found, skip to next type
			if ( empty( $term_names ) ) {
				continue;
			}

			// Get all term IDs in one go
			$name_to_crm_id = BWFAN_Model_Terms::get_term_ids_by_names( $term_names, $term_type );

			// Create mapping from ActiveCampaign ID to CRM ID
			foreach ( $ac_id_to_name as $ac_id => $name ) {
				if ( isset( $name_to_crm_id[ $name ] ) ) {
					$this->term_id_mapping[ $term_key ][ $ac_id ] = $name_to_crm_id[ $name ];
				}
			}
		}
	}

	/**
	 * Get term IDs for a specific contact
	 *
	 * @param array $value Contact's term data
	 * @param string $type 'tags' or 'lists'
	 *
	 * @return array CRM term IDs for this contact
	 */
	public function prepare_terms( $value, $type ) {
		// Make sure mappings are loaded
		if ( empty( $this->term_id_mapping[ $type ] ) ) {
			$this->load_term_mappings();
		}

		$terms = [];

		// Process contact's terms
		if ( ! empty( $value ) && is_array( $value ) ) {
			foreach ( $value as $term_id ) {
				if ( isset( $this->term_id_mapping[ $type ][ $term_id ] ) ) {
					$terms[] = $this->term_id_mapping[ $type ][ $term_id ];
				}
			}
		}

		return $terms;
	}

	/**
	 * Formats a field value by converting AC format (|| separated) to CRM format (comma separated).
	 *
	 * This method processes string values that use double pipe (||) as separators and converts them to a comma-separated format.
	 *
	 *
	 * @param mixed $value
	 * @param $field_type
	 *
	 * @return mixed The formatted string with comma separation, or original value if not applicable
	 */
	protected function format_field_value( $value, $field_type ) {
		if ( empty( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			// Remove extra || from start and end
			$value = trim( $value, '||' );

			// Split by || and filter empty values
			if ( strpos( $value, '||' ) !== false ) {
				$values = array_filter( array_map( 'trim', explode( '||', $value ) ) );

				return implode( ', ', $values );
			}
		}

		return $value;
	}

	/**
	 * Get the log headers for this importer.
	 *
	 * @return array
	 */
	protected function get_log_headers() {
		return [ 'ID', 'Email', 'Status' ];
	}

	/**
	 * Prepare log data for a single item.
	 *
	 * @param mixed $data
	 * @param mixed $result
	 *
	 * @return array
	 */
	protected function prepare_log_data( $data, $result ) {
		$mapped_fields = is_array( $this->get_import_meta( 'mapped_fields' ) ) ? $this->get_import_meta( 'mapped_fields' ) : [];
		$email_index   = array_search( 'email', $mapped_fields );

		return [
			'ID'     => is_wp_error( $result ) ? 0 : $result['id'],
			'Email'  => ( $email_index !== false && isset( $data[ $email_index ] ) ) ? $data[ $email_index ] : '',
			'Status' => is_wp_error( $result ) ? __( 'Failed', 'wp-marketing-automations' ) : ( isset( $result['skipped'] ) && $result['skipped'] ? __( 'Skipped', 'wp-marketing-automations' ) : __( 'Success', 'wp-marketing-automations' ) ),
		];
	}


	/**
	 * Get the second step fields for AC importer
	 *
	 * @return array
	 */
	public function get_second_step_fields( $fields ) {
		$api_url = ! empty( $fields['api_url'] ) ? $fields['api_url'] : '';
		$api_key = ! empty( $fields['api_key'] ) ? $fields['api_key'] : '';

		$api_credentials = [
			'api_url' => $api_url,
			'api_key' => $api_key,
		];

		// Validate API credentials
		$is_valid = $this->connect( 'BWFAN\Importers\AC\Calls\Connect', $api_credentials );
		if ( is_wp_error( $is_valid ) ) {
			return [
				'status'  => 3,
				'message' => $is_valid->get_error_message()
			];
		}

		// If API is valid, proceed with getting mapping options
		$mapping_options = $this->get_mapping_options();

		if ( ! is_array( $mapping_options ) ) {
			return [
				'error' => __( 'Unable to retrieve mapping options', 'wp-marketing-automations' )
			];
		}

		$mapped_fields_options = [
			[
				'key'   => '',
				'label' => __( 'Do Not Import This Field', 'wp-marketing-automations' ),
			]
		];
		$fields_list           = [];
		foreach ( $mapping_options['from']['fields']['general'] as $field_key => $field_label ) {
			$fields_list[] = [
				'slug'  => $field_key,
				'title' => $field_label,
			];
		}
		foreach ( $mapping_options['from']['fields']['custom'] as $field_key => $field_label ) {
			$fields_list[] = [
				'slug'  => $field_key,
				'title' => $field_label,
			];
		}

		$field_options = \BWFAN_Importer::get_formatted_fields( $mapping_options['to']['fields'] );
		if ( ! empty( $field_options ) ) {
			$mapped_fields_options = array_merge( $mapped_fields_options, $field_options );
		}

		// Prepare tags
		$tag_list = array_map( function ( $slug, $title ) {
			return [
				'slug'  => $slug,
				'title' => $title,
				'auto'  => false,
			];
		}, array_keys( $mapping_options['from']['tags'] ), $mapping_options['from']['tags'] );

		// Prepare lists
		$list_list = array_map( function ( $slug, $title ) {
			return [
				'slug'  => $slug,
				'title' => $title,
				'auto'  => false,
			];
		}, array_keys( $mapping_options['from']['lists'] ), $mapping_options['from']['lists'] );

		$default_mapping = [
			'email'      => 'email',
			'first_name' => 'f_name',
			'last_name'  => 'l_name',
			'phone'      => 'contact_no',
		];

		if ( ! empty( $fields_list ) ) {
			$schema[] = [
				'id'           => 'mapped_fields',
				'type'         => 'fields',
				'defaultValue' => $default_mapping,
				'label'        => __( 'ActiveCampaign Contact Fields', 'wp-marketing-automations' ),
				'options'      => $mapped_fields_options,
				'fieldsList'   => $fields_list,
			];
		}

		if ( ! empty( $tag_list ) ) {
			$schema[] = [
				'id'      => 'ac_tags',
				'type'    => 'tags',
				'label'   => __( 'ActiveCampaign Tag', 'wp-marketing-automations' ),
				'tagList' => $tag_list,
			];
		}

		if ( ! empty( $list_list ) ) {
			$schema[] = [
				'id'      => 'ac_lists',
				'type'    => 'lists',
				'label'   => __( 'ActiveCampaign List', 'wp-marketing-automations' ),
				'tagList' => $list_list,
			];
		}

		return $schema;
	}

	/**
	 * Handle the update process for AC import.
	 *
	 * @return array|WP_Error The result of the update process or an error.
	 */
	public function handle_update( $data ) {
		$mapped_fields = isset( $data['mapped_fields'] ) && is_array( $data['mapped_fields'] ) ? $data['mapped_fields'] : array();

		if ( count( $mapped_fields ) > 0 && ! in_array( 'email', $mapped_fields, true ) ) {
			return new WP_Error( 'bwfcrm_missing_email_mapping', __( 'Email mapping is required', 'wp-marketing-automations' ) );
		}

		return array(
			'mapped_fields' => $mapped_fields,
			'ac_tags'       => ! empty( $data['ac_tags'] ) && is_array( $data['ac_tags'] ) ? $data['ac_tags'] : array(),
			'ac_lists'      => ! empty( $data['ac_lists'] ) && is_array( $data['ac_lists'] ) ? $data['ac_lists'] : array()
		);
	}

	/**
	 * Necessary fields passed to contact profile.
	 *
	 * @return array
	 */
	public function contact_profile_fields() {
		return [
			'dont_update_blank',
			'update_existing_fields',
			'disable_events',
		];
	}

	/**
	 * Get contact status.
	 *
	 * @param $status
	 *
	 * @return int
	 */
	private function get_contact_status( $status ) {
		switch ( $status ) {
			case 1: // active
				return 1;
			case 2: // unsubscribed
				return 3;
			case 3: // bounced
				return 2;
			default:
				return 0;
		}
	}

	/**
	 * Get API credentials.
	 * Priority: 1. Instance credentials, 2. Import meta, 3. bwf_options
	 *
	 * @return array The API credentials.
	 */
	public function get_api_credentials() {
		if ( ! empty( $this->api_credentials ) && is_array( $this->api_credentials ) ) {
			return $this->api_credentials;
		}

		if ( $this->get_import_id() > 0 ) {
			$import_meta = $this->get_import_meta();
			if ( ! empty( $import_meta['fields'] ) && ! empty( $import_meta['fields']['api_key'] ) && ! empty( $import_meta['fields']['api_url'] ) ) {
				$this->api_credentials = $import_meta['fields'];

				return $this->api_credentials;
			}

			return [];
		}
		$saved_credentials = bwf_options_get( 'funnelkit_' . $this->slug . '_api_credentials' );
		if ( ! empty( $saved_credentials ) && is_array( $saved_credentials ) && ! empty( $saved_credentials['api_key'] ) ) {
			$this->api_credentials = $saved_credentials;

			return $this->api_credentials;
		}

		return [];
	}
}

BWFAN_Core()->importer->register( 'ac', 'BWFAN\Importers\AC_Importer' );
