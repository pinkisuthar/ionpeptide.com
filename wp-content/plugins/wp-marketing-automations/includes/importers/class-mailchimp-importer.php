<?php

namespace BWFAN\Importers;

defined( 'ABSPATH' ) || exit;

use BWFAN\Importers\Mailchimp\Calls\Connect;
use BWFAN\Importers\Mailchimp\Calls\Get_Lists;
use BWFAN\Importers\Mailchimp\Calls\Get_Members;
use BWFAN\Importers\Mailchimp\Calls\Get_Merge_Fields;
use BWFAN\Importers\Mailchimp\Calls\Get_Tags;
use BWFAN_Model_Terms;
use BWFCRM_Fields;
use BWFCRM_Term_Type;
use WFCO_Common;
use WP_Error;


#[\AllowDynamicProperties]
class Mailchimp_Importer extends Importer implements Autoresponder_Importer_Interface {

	protected $import_type = 'mailchimp';
	protected $mapping;
	protected $mailchimp_tags;
	protected $mailchimp_lists;
	protected $regular_tags;
	protected $auto_tags;
	protected $regular_lists;
	protected $auto_lists;
	protected $default_tags = [];
	protected $default_lists = [];
	private $term_id_mapping = [
		'tags'  => [],
		'lists' => []
	];

	/**
	 * Constructor for the Mailchimp class.
	 *
	 * @param array $params Parameters for the importer.
	 */
	public function __construct( $params = array() ) {

		$this->slug        = 'mailchimp';
		$this->name        = __( 'Mailchimp', 'wp-marketing-automations' );
		$this->description = __( 'Connect to Mailchimp', 'wp-marketing-automations' );
		$this->submit_text = __( 'Connect', 'wp-marketing-automations' );
		$this->logo_url    = esc_url( plugin_dir_url( BWFAN_PLUGIN_FILE ) . '/admin/assets/img/importer/mailchimp.png' );
		$this->has_fields  = true;
		$this->priority    = 11;
		$this->process_calls();

		$this->group = 1;
		$params      = wp_parse_args( $params );

		parent::__construct( $params );
	}

	/**
	 * Returns the field schema for the Mailchimp Importer.
	 *
	 * @return array The field schema for the importer.
	 */
	public function get_field_schema() {
		return [
			[
				'id'          => 'api_key',
				'label'       => __( 'API Key', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_mailchimp_api_key',
				'placeholder' => __( 'Type Here', 'wp-marketing-automations' ),
				'errorMsg'    => __( 'Enter your Mailchimp API Key', 'wp-marketing-automations' ),
				'hint'        => __( 'Get your API key at MailChimp Account > Extra > API keys', 'wp-marketing-automations' ) . ' <a href="https://funnelkit.com/docs/autonami-2/contacts/import-contacts-from-mailchimp/" target="_blank" rel="noopener noreferrer">' . __( 'View Docs', 'wp-marketing-automations' ) . '</a>',
				'required'    => true,
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
			$old_data   = ( isset( $saved_data['bwfco_mailchimp'] ) && is_array( $saved_data['bwfco_mailchimp'] ) && count( $saved_data['bwfco_mailchimp'] ) > 0 ) ? $saved_data['bwfco_mailchimp'] : array();

			if ( ! empty( $old_data ) && isset( $old_data['api_key'] ) && isset( $old_data['default_list'] ) ) {
				$this->api_credentials = array(
					'api_key'      => $old_data['api_key'],
					'default_list' => $old_data['default_list']
				);
			}
		}

		return [
			'api_key' => ! empty( $stored_credentials['api_key'] ) ? $stored_credentials['api_key'] : '',
		];
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
			if ( ! empty( $import_meta['fields'] ) && ! empty( $import_meta['fields']['api_key'] ) && ! empty( $import_meta['fields']['list_id'] ) ) {
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

	public function process_calls() {
		$calls_dir = __DIR__ . '/mailchimp/calls';

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
	 * This method retrieves contacts from the Mailchimp API and prepares the data for importing.
	 * It sets the raw_data property with an array of contact IDs.
	 *
	 *
	 * @return array|void|WP_Error
	 */
	public function populate_contact_data() {
		$contacts = $this->get_contacts( [
			'offset' => $this->get_offset(),
			'limit'  => self::LIMIT,
		] );

		if ( $contacts instanceof WP_Error || empty( $contacts ) ) {
			return $contacts;
		}

		return $this->prepare_contact( $contacts );
	}

	/**
	 * Get source-specific data from the Mailchimp contact data
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
			'email'    => $data['email'] ?? '',
			'FNAME'    => $data['first_name'] ?? '',
			'LNAME'    => $data['last_name'] ?? '',
			'PHONE'    => $data['phone'] ?? '',
			'COMPANY'  => $data['orgname'] ?? '',
			'ADDRESS'  => $data['address'] ?? '',
			'BIRTHDAY' => $data['birthday'] ?? ''
		];

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && strpos( $key, 'MMERGE' ) === 0 ) {
				$source_data[ $key ] = $value;
			}
		}

		return $source_data;
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

		$prepared_contacts = [];
		foreach ( $contacts as $contact ) {
			$prepared_contact = [
				'email'      => $contact['email_address'] ?? '',
				'first_name' => $contact['merge_fields']['FNAME'] ?? '',
				'last_name'  => $contact['merge_fields']['LNAME'] ?? '',
				'phone'      => $contact['merge_fields']['PHONE'] ?? '',
				'orgname'    => $contact['merge_fields']['COMPANY'] ?? ''
			];

			// Handle status if available
			if ( ! empty( $contact['status'] ) ) {
				$prepared_contact['status'] = $this->get_contact_status( $contact['status'] );
			}

			// Handle address if available
			if ( ! empty( $contact['merge_fields']['ADDRESS'] ) && is_array( $contact['merge_fields']['ADDRESS'] ) ) {
				// Create a structured address array with named keys
				$prepared_contact['address'] = [
					'address_1' => $contact['merge_fields']['ADDRESS']['addr1'] ?? '',
					'address_2' => $contact['merge_fields']['ADDRESS']['addr2'] ?? '',
					'city'      => $contact['merge_fields']['ADDRESS']['city'] ?? '',
					'state'     => $contact['merge_fields']['ADDRESS']['state'] ?? '',
					'postcode'  => $contact['merge_fields']['ADDRESS']['zip'] ?? '',
					'country'   => $contact['merge_fields']['ADDRESS']['country'] ?? ''
				];

				// Filter out empty values
				$prepared_contact['address'] = array_filter( $prepared_contact['address'] );
			}

			// Handle birthday if available
			if ( ! empty( $contact['merge_fields']['BIRTHDAY'] ) ) {
				$prepared_contact['birthday'] = $contact['merge_fields']['BIRTHDAY'];
			}

			foreach ( $contact['merge_fields'] as $key => $value ) {
				if ( strpos( $key, 'MMERGE' ) === 0 ) {
					$prepared_contact[ $key ] = $value;
				}
			}

			// Handle lists
			if ( ! empty( $contact['list_id'] ) ) {
				$contact_lists = [];
				if ( isset( $this->auto_lists[ $contact['list_id'] ] ) ) {
					$contact_lists[] = [
						'id'   => $contact['list_id'],
						'name' => $this->auto_lists[ $contact['list_id'] ]['title'] ?? ''
					];
				}
				if ( ! empty( $this->default_lists ) ) {
					$contact_lists = array_merge( $contact_lists, $this->default_lists );
				}
				if ( ! empty( $contact_lists ) ) {
					$prepared_contact['lists'] = $contact_lists;
				}
			} elseif ( ! empty( $this->default_lists ) ) {
				$prepared_contact['lists'] = $this->default_lists;
			}

			// Handle tags
			if ( ! empty( $contact['tags'] ) ) {
				$contact_tags = [];
				foreach ( $contact['tags'] as $tag ) {
					if ( isset( $this->auto_tags[ $tag['id'] ] ) ) {
						$contact_tags[] = [
							'id'   => $tag['id'],
							'name' => $tag['name']
						];
					}
				}
				if ( ! empty( $this->default_tags ) ) {
					$contact_tags = array_merge( $contact_tags, $this->default_tags );
				}
				if ( ! empty( $contact_tags ) ) {
					$prepared_contact['tags'] = $contact_tags;
				}
			}

			// Handle custom field mappings
			foreach ( $this->mapping as $crm_field => $mailchimp_field ) {
				if ( ! isset( $prepared_contact[ $crm_field ] ) && isset( $contact['merge_fields'][ $mailchimp_field ] ) ) {
					$prepared_contact[ $crm_field ] = $contact['merge_fields'][ $mailchimp_field ];
				}
			}

			$prepared_contacts[] = $prepared_contact;
		}

		return $prepared_contacts;
	}

	/**
	 * Maps Mailchimp address data to the appropriate CRM fields
	 *
	 * @param array $address_data The address data array
	 *
	 * @return array Mapped field IDs and values
	 */
	// In Mailchimp_Importer class (paste-2.txt)
	protected function map_address_to_crm_fields( $address_data ) {
		// Get contact fields mapping from slug to ID
		$contact_fields = BWFCRM_Fields::get_contact_fields_from_db( 'slug' );

		// Create a mapping of address parts to CRM field slugs
		$field_mapping = [
			'address_1' => 'address-1',
			'address_2' => 'address-2',
			'city'      => 'city',
			'state'     => 'state',
			'postcode'  => 'postcode',
			'country'   => 'country'
		];

		$mapped_fields = [];

		foreach ( $address_data as $key => $value ) {
			if ( isset( $field_mapping[ $key ] ) && ! empty( $value ) ) {
				$slug = $field_mapping[ $key ];

				if ( in_array( $slug, [ 'state', 'country' ] ) ) {
					$mapped_fields[ $slug ] = $value;
				} elseif ( isset( $contact_fields[ $slug ] ) && isset( $contact_fields[ $slug ]['ID'] ) ) {
					$field_id = $contact_fields[ $slug ]['ID'];
					if ( $field_id > 0 ) {
						$mapped_fields[ $field_id ] = $value;
					}
				}
			}
		}

		return $mapped_fields;
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
		$this->mapping         = $this->get_import_meta( 'mapped_fields' ) ?? [];
		$this->mailchimp_tags  = $this->get_import_meta( 'mailchimp_tags' ) ?? [];
		$this->mailchimp_lists = $this->get_import_meta( 'mailchimp_lists' ) ?? [];

		// Process tags
		$this->regular_tags = [];
		$this->auto_tags    = [];
		foreach ( $this->mailchimp_tags as $tag_id => $tag_data ) {
			if ( ! empty( $tag_data['auto'] ) ) {
				$this->auto_tags[ $tag_id ] = $tag_data;
			} else {
				$this->regular_tags[ $tag_id ] = $tag_id;
			}
		}

		if ( ! empty( $this->regular_tags ) ) {
			foreach ( $this->regular_tags as $tag_id ) {
				$this->default_tags[] = [
					'id'   => $tag_id,
					'name' => $this->mailchimp_tags[ $tag_id ]['title'] ?? ''
				];
			}
		}

		// Process lists
		$this->regular_lists = [];
		$this->auto_lists    = [];
		foreach ( $this->mailchimp_lists as $list_id => $list_data ) {
			if ( ! empty( $list_data['auto'] ) ) {
				$this->auto_lists[ $list_id ] = $list_data;
			} else {
				$this->regular_lists[ $list_id ] = $list_id;
			}
		}

		if ( ! empty( $this->regular_lists ) ) {
			foreach ( $this->regular_lists as $list_id ) {
				$this->default_lists[] = [
					'id'   => $list_id,
					'name' => $this->mailchimp_lists[ $list_id ]['title'] ?? ''
				];
			}
		}

	}

	/**
	 * Returns an array of general fields for importing contacts.
	 *
	 * @return array An array of general fields.
	 */
	public function get_general_fields() {
		return array(
			'email' => __( 'Email', 'wp-marketing-automations' ),
		);
	}

	/**
	 * Retrieves the custom fields from the Mailchimp API.
	 *
	 * @return array The custom fields retrieved from the API.
	 */
	public function get_custom_fields() {
		$args = $this->get_api_credentials();

		if ( empty( $args['list_id'] ) ) {
			return array();
		}

		$merge_fields_call = new Get_Merge_Fields();
		$merge_fields_call->set_data( $args );
		$response = $merge_fields_call->process();

		// Define the order of primary fields
		$primary_fields = [ 'FNAME', 'LNAME', 'COMPANY', 'ADDRESS', 'BIRTHDAY' ];
		$custom_fields  = [];

		// First add the primary fields in the specified order
		if ( 200 === $response['response'] && ! empty( $response['body']['merge_fields'] ) ) {
			$all_fields = [];

			// Create a mapping of all available fields
			foreach ( $response['body']['merge_fields'] as $field ) {
				$all_fields[ $field['tag'] ] = $field['name'];
			}

			// Add primary fields first in specified order
			foreach ( $primary_fields as $field_key ) {
				if ( isset( $all_fields[ $field_key ] ) ) {
					$custom_fields[ $field_key ] = $all_fields[ $field_key ];
					unset( $all_fields[ $field_key ] );
				}
			}

			foreach ( $all_fields as $key => $name ) {
				$custom_fields[ $key ] = $name;
			}
		}

		return $custom_fields;
	}

	/**
	 * Retrieves the lists from the Mailchimp API.
	 *
	 * @param array $data Optional arguments to customize the API request.
	 *
	 * @return array The prepared lists with their IDs as keys and names as values.
	 */
	public function get_lists( $data = [] ) {
		$args = empty( $data ) ? $this->get_api_credentials() : $data;

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
	 * Retrieves the tags from the Mailchimp API.
	 *
	 * @return array The prepared tags retrieved from the API.
	 */
	public function get_tags() {
		$args = array_merge( $this->get_api_credentials() );

		$tags_call = new Get_Tags();
		$tags_call->set_data( $args );
		$tags = $tags_call->process();

		$prepared_tags = array();
		if ( 200 === $tags['response'] && ! empty( $tags['body']['segments'] ) ) {
			foreach ( $tags['body']['segments'] as $tag ) {
				if ( 'static' === $tag['type'] ) {
					$prepared_tags[ $tag['id'] ] = $tag['name'];
				}
			}
		}

		return $prepared_tags;
	}

	/**
	 * Retrieves contacts from the Mailchimp API.
	 *
	 * @param array $args Optional arguments to customize the API request.
	 *
	 * @return WP_Error An array of contacts retrieved from the API.
	 */
	public function get_contacts( $args = array() ) {
		$args = array_merge( $this->get_api_credentials() );

		$contacts_call = new Get_Members();
		$contacts_call->set_data( $args );
		$contacts = $contacts_call->process();
		if ( 200 === $contacts['response'] ) {
			return $contacts['body']['members'];
		}

		return new WP_Error( $contacts['response'], $contacts['body'][0] ?? '' );
	}

	/**
	 * Retrieves the count of contacts from Mailchimp API.
	 *
	 * @return int The total count of contacts.
	 */
	public function get_contacts_count() {
		$args = array_merge( array( 'limit' => 1 ), $this->get_api_credentials() );

		$contacts_call = new Get_Members();
		$contacts_call->set_data( $args );
		$contacts = $contacts_call->process();

		if ( 200 === $contacts['response'] ) {
			return absint( $contacts['body']['total_items'] );
		}

		return 0;
	}

	/**
	 * Retrieves the mapping options for the Mailchimp Importer.
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
				'fields' => $this->get_crm_fields( false ),
			),
		);
	}

	/**
	 * Load term mappings for both tags and lists
	 *
	 * @return void
	 */
	public function load_term_mappings() {
		$mailchimp_tags  = $this->get_import_meta( 'mailchimp_tags' ) ?? [];
		$mailchimp_lists = $this->get_import_meta( 'mailchimp_lists' ) ?? [];

		// Process both tags and lists
		$term_types = [
			'tags'  => [
				'terms' => $mailchimp_tags,
				'type'  => BWFCRM_Term_Type::$TAG
			],
			'lists' => [
				'terms' => $mailchimp_lists,
				'type'  => BWFCRM_Term_Type::$LIST
			]
		];

		foreach ( $term_types as $term_key => $data ) {
			if ( empty( $data['terms'] ) ) {
				continue;
			}

			$mailchimp_terms = $data['terms'];
			$term_type       = $data['type'];

			// Extract term names and build ID mapping
			$term_names    = [];
			$mc_id_to_name = [];

			foreach ( $mailchimp_terms as $mc_id => $term_data ) {
				if ( isset( $term_data['title'] ) ) {
					$term_names[]            = $term_data['title'];
					$mc_id_to_name[ $mc_id ] = $term_data['title'];
				}
			}

			// If no terms found, skip to next type
			if ( empty( $term_names ) ) {
				continue;
			}

			// Get all term IDs in one go
			$name_to_crm_id = BWFAN_Model_Terms::get_term_ids_by_names( $term_names, $term_type );

			// Create mapping from Mailchimp ID to CRM ID
			foreach ( $mc_id_to_name as $mc_id => $name ) {
				if ( isset( $name_to_crm_id[ $name ] ) ) {
					$this->term_id_mapping[ $term_key ][ $mc_id ] = $name_to_crm_id[ $name ];
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
			foreach ( $value as $term ) {
				if ( ! is_array( $term ) || ! isset( $term['id'] ) ) {
					continue;
				}

				$mc_term_id = $term['id'];

				// Look up the CRM term ID from our mapping
				if ( isset( $this->term_id_mapping[ $type ][ $mc_term_id ] ) ) {
					$terms[] = $this->term_id_mapping[ $type ][ $mc_term_id ];
				}
			}
		}

		return $terms;
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
	 * Get the second step fields for Mailchimp importer
	 *
	 * @return array
	 */
	public function get_second_step_fields( $fields ) {
		$api_key = ! empty( $fields['api_key'] ) ? $fields['api_key'] : '';
		$list_id = ! empty( $fields['list_id'] ) ? $fields['list_id'] : '';

		$api_credentials = [
			'api_key' => $api_key,
			'list_id' => $list_id,
		];

		if ( ! empty( $api_key ) && empty( $list_id ) ) {
			return $this->get_additional_lists_schema( $api_key );
		}

		// Validate API credentials
		$is_valid = $this->connect( 'BWFAN\Importers\Mailchimp\Calls\Connect', $api_credentials );
		if ( is_wp_error( $is_valid ) ) {
			return [
				'status'  => 3,
				'message' => $is_valid->get_error_message()
			];
		}
		$this->set_api_credentials( $api_credentials );
		// Get mapping options
		$mapping_options = $this->get_mapping_options();

		if ( ! is_array( $mapping_options ) ) {
			return [
				'error' => __( 'Unable to retrieve mapping options', 'wp-marketing-automations' )
			];
		}

		// Prepare mapped fields
		$fields_list = [];
		// Add general fields first
		foreach ( $mapping_options['from']['fields']['general'] as $field_key => $field_label ) {
			$fields_list[] = [
				'slug'  => $field_key,
				'title' => $field_label,
			];
		}
		// Add custom fields
		foreach ( $mapping_options['from']['fields']['custom'] as $field_key => $field_label ) {
			$fields_list[] = [
				'slug'  => $field_key,
				'title' => $field_label,
			];
		}

		$mapped_fields_options = [
			[
				'key'   => '',
				'label' => __( 'Do Not Import This Field', 'wp-marketing-automations' ),
			]
		];

		$field_options = \BWFAN_Importer::get_formatted_fields( $mapping_options['to']['fields'] );
		if ( ! empty( $field_options ) ) {
			$mapped_fields_options = array_merge( $mapped_fields_options, $field_options );
		}

		// Prepare tags and lists for mapping
		$tag_list = array_map( function ( $slug, $title ) {
			return [
				'slug'  => $slug,
				'title' => $title,
				'auto'  => false,
			];
		}, array_keys( $mapping_options['from']['tags'] ), $mapping_options['from']['tags'] );

		$list_list = array_map( function ( $slug, $title ) {
			return [
				'slug'  => $slug,
				'title' => $title,
				'auto'  => false,
			];
		}, array_keys( $mapping_options['from']['lists'] ), $mapping_options['from']['lists'] );

		$schema          = [];
		$default_mapping = [
			'email' => 'email',
			'FNAME' => 'f_name',
			'LNAME' => 'l_name',
			'PHONE' => 'contact_no',
		];

		if ( ! empty( $fields_list ) ) {
			$schema[] = [
				'id'           => 'mapped_fields',
				'type'         => 'fields',
				'defaultValue' => $default_mapping,
				'label'        => __( 'MailChimp Contact Field', 'wp-marketing-automations' ),
				'options'      => $mapped_fields_options,
				'fieldsList'   => $fields_list,
			];
		}

		if ( ! empty( $tag_list ) ) {
			$schema[] = [
				'id'      => 'mailchimp_tags',
				'type'    => 'tags',
				'label'   => __( 'Mailchimp Tag', 'wp-marketing-automations' ),
				'tagList' => $tag_list,
			];
		}

		if ( ! empty( $list_list ) ) {
			$schema[] = [
				'id'      => 'mailchimp_lists',
				'type'    => 'lists',
				'label'   => __( 'Mailchimp List', 'wp-marketing-automations' ),
				'tagList' => $list_list,
			];
		}

		return $schema;
	}

	/**
	 * Get additional lists schema for Mailchimp importer
	 *
	 * @param string $api_key The API key for Mailchimp
	 *
	 * @return array
	 */
	public function get_additional_lists_schema( $api_key = '' ) {
		if ( empty( $api_key ) ) {
			return [
				'status'  => 3,
				'message' => __( 'API key is required', 'wp-marketing-automations' )
			];
		}

		// Validate API credentials
		$lists = $this->get_lists( [ 'api_key' => $api_key, 'bwfan_con_source' => 'autonami' ] );

		if ( empty( $lists ) ) {
			return [
				'status'  => 3,
				'message' => __( 'Unable to connect lists', 'wp-marketing-automations' )
			];
		}
		$field_schema = [
			[
				'id'          => 'api_key',
				'label'       => __( 'Enter API Key', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_mailchimp_api_key',
				'placeholder' => $api_key,
				'required'    => true,
				'disabled'    => true,
			]
		];
		// Prepare lists for mapping
		$list_options = [
			[
				'label' => __( 'Select List', 'wp-marketing-automations' ),
				'value' => '',
			]
		];

		foreach ( $lists as $id => $name ) {
			$list_options[] = [
				'label' => $name,
				'value' => $id,
			];
		}

		// Add list selection to field schema
		$field_schema[] = array(
			'id'       => 'list_id',
			'type'     => 'wp_select',
			'label'    => __( 'Select Mailchimp List', 'wp-marketing-automations' ),
			'class'    => 'bwfan_mailchimp_list_id',
			'required' => true,
			'options'  => $list_options,
		);

		return [
			'updated_schema' => true,
			'fields'         => $field_schema,
			'submit_text'    => __( 'Import', 'wp-marketing-automations' ),
		];
	}

	/**
	 * Handle the update process for Mailchimp import.
	 *
	 * @return array|WP_Error The result of the update process or an error.
	 */
	public function handle_update( $data ) {
		$mapped_fields = isset( $data['mapped_fields'] ) && is_array( $data['mapped_fields'] ) ? $data['mapped_fields'] : array();

		if ( count( $mapped_fields ) > 0 && ! in_array( 'email', $mapped_fields, true ) ) {
			return new WP_Error( 'bwfcrm_missing_email_mapping', __( 'Email mapping is required', 'wp-marketing-automations' ) );
		}

		return array(
			'mapped_fields'   => $mapped_fields,
			'mailchimp_tags'  => ! empty( $data['mailchimp_tags'] ) && is_array( $data['mailchimp_tags'] ) ? $data['mailchimp_tags'] : array(),
			'mailchimp_lists' => ! empty( $data['mailchimp_lists'] ) && is_array( $data['mailchimp_lists'] ) ? $data['mailchimp_lists'] : array()
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
	 * @param string $status Contact status.
	 *
	 * @return int
	 */
	private function get_contact_status( $status ) {
		switch ( $status ) {
			case 'subscribed':
			case 'transactional':
				return 1;
			case 'unsubscribed':
				return 3;
			case 'cleaned':
				return 2;
			default:
				return 0;
		}
	}
}

BWFAN_Core()->importer->register( 'mailchimp', 'BWFAN\Importers\Mailchimp_Importer' );
