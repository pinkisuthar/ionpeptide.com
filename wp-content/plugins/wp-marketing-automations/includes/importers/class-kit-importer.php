<?php

namespace BWFAN\Importers;

defined( 'ABSPATH' ) || exit;

use BWFAN\Importers\Kit\Calls\Connect;
use BWFAN\Importers\Kit\Calls\Get_Subscribers;
use BWFAN\Importers\Kit\Calls\Get_Subscriber_Tags;
use BWFAN\Importers\Kit\Calls\Get_Tags;
use BWFAN\Importers\Kit\Calls\Get_Custom_Fields;
use BWFAN_Model_Terms;
use BWFCRM_Term_Type;
use WFCO_Common;
use WP_Error;

#[\AllowDynamicProperties]
class Kit_Importer extends Importer implements Autoresponder_Importer_Interface {
	/**
	 * Set limit to 50
	 *
	 * @var int
	 */
	public const LIMIT = 50;

	protected $import_type = 'kit';
	protected $mapping;
	protected $kit_tags;
	protected $regular_tags;
	protected $auto_tags;
	protected $regular_tag_values;
	private $term_id_mapping = [
		'tags'  => [],
		'lists' => [],
	];
	protected $default_tags = [];

	/**
	 * Constructor for the Kit class.
	 *
	 * @param array $params Parameters for the importer.
	 */
	public function __construct( $params = array() ) {
		$this->slug        = 'kit';
		$this->name        = __( 'Kit', 'wp-marketing-automations' );
		$this->description = __( 'Connect to Kit', 'wp-marketing-automations' );
		$this->submit_text = __( 'Connect', 'wp-marketing-automations' );
		$this->logo_url    = esc_url( plugin_dir_url( BWFAN_PLUGIN_FILE ) . '/admin/assets/img/importer/kit.png' );
		$this->has_fields  = true;
		$this->priority    = 13;
		$this->process_calls();

		$this->group = 1;
		$params      = wp_parse_args( $params );

		parent::__construct( $params );
	}

	/**
	 * Returns the field schema for the Kit Importer.
	 *
	 * @return array The field schema for the importer.
	 */
	public function get_field_schema() {
		return [
			[
				'id'          => 'api_key',
				'label'       => __( 'API Key', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_kit_api_key',
				'placeholder' => __( 'Type Here', 'wp-marketing-automations' ),
				'errorMsg'    => __( 'Enter your Kit API Key', 'wp-marketing-automations' ),
				'hint'        => __( 'Get your API key at Kit Account > Settings > API Key', 'wp-marketing-automations' ). ' <a href="https://funnelkit.com/docs/autonami-2/contacts/import-contacts-from-kit/" target="_blank" rel="noopener noreferrer">' . __( 'View Docs', 'wp-marketing-automations' ) . '</a>',
				'required'    => true,
			]
		];
	}

	/**
	 * Returns the API credentials for the Kit Importer.
	 * Gets default values from bwf_options if available.
	 *
	 * @return array The API credentials.
	 */
	public function get_default_values() {
		$stored_credentials = $this->get_api_credentials();

		if ( empty( $stored_credentials ) ) {
			$saved_data = class_exists( 'WFCO_Common' ) ? WFCO_Common::$connectors_saved_data : array();
			$old_data   = ( isset( $saved_data['bwfco_convertkit'] ) && is_array( $saved_data['bwfco_convertkit'] ) && count( $saved_data['bwfco_convertkit'] ) > 0 ) ? $saved_data['bwfco_convertkit'] : array();

			if ( ! empty( $old_data ) && isset( $old_data['api_key'] ) ) {
				$stored_credentials = array(
					'api_key' => $old_data['api_key'],
				);
			}
		}

		return [
			'api_key' => ! empty( $stored_credentials['api_key'] ) ? $stored_credentials['api_key'] : '',
		];
	}

	public function process_calls() {
		$calls_dir = __DIR__ . '/kit/calls';

		if ( is_dir( $calls_dir ) ) {
			\BWFAN_Importer::load_class_files( $calls_dir );
		}
	}

	/**
	 * Prepares the import data for creating a new import.
	 * Saves API credentials in import meta.
	 *
	 * @param array $import_data The import data.
	 * @param array $fields The form fields.
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
	 * This method retrieves subscribers from the Kit API and prepares the data for importing.
	 * It sets the raw_data property with an array of subscriber IDs.
	 *
	 * @return mixed|void|WP_Error
	 */
	public function populate_contact_data() {
		$subscribers = $this->get_contacts( [
			'offset' => $this->get_import_meta( 'kit_after' ),
			'limit'  => self::LIMIT,
		] );

		if ( $subscribers instanceof WP_Error || empty( $subscribers ) ) {
			return $subscribers;
		}

		if ( ! empty( $subscribers['pagination'] ) ) {
			// Handle pagination if needed
			$this->import_meta['kit_new_after'] = $subscribers['pagination']['end_cursor'] ?? '';
		}

		$contacts = $this->prepare_contact( $subscribers['subscribers'] );
		return $contacts;
	}

	public function handle_contact_loop_error() {
		if ( ! empty( $this->import_meta['kit_new_after'] ) ) {
			unset( $this->import_meta['kit_new_after'] );
		}
	}

	public function modify_import_records() {
		if ( ! empty( $this->import_meta['kit_new_after'] ) ) {
			$this->import_meta['kit_after'] = $this->import_meta['kit_new_after'];
			unset( $this->import_meta['kit_new_after'] );
		}
	}

	/**
	 * Get source-specific data from the Kit subscriber data
	 *
	 * @param mixed $data The subscriber data
	 *
	 * @return array|WP_Error The formatted source data or error
	 */
	protected function get_source_specific_data( $data ) {
		if ( empty( $data ) ) {
			return new WP_Error( 'bwfcrm_empty_data', __( 'No data provided.', 'wp-marketing-automations' ) );
		}

		// Initialize source data with safe defaults
		$source_data = [
			'email'      => $data['email'],
			'first_name' => $data['first_name'],
		];

		// Handle custom fields safely
		if ( isset( $data['fields'] ) && is_array( $data['fields'] ) ) {
			foreach ( $data['fields'] as $field_key => $field_value ) {
				if ( is_string( $field_key ) && ! empty( $field_key ) ) {
					$source_data[ $field_key ] = is_scalar( $field_value ) ? (string) $field_value : '';
				}
			}
		}

		return $source_data;
	}

	/**
	 * Prepare contact data for import.
	 *
	 * @param array $subscribers The subscriber data to be prepared for import.
	 *
	 * @return array The prepared contact data.
	 */
	public function prepare_contact( $subscribers = array() ) {
		if ( empty( $subscribers ) ) {
			return [];
		}


		$prepared_subscribers = [];
		foreach ( $subscribers as $subscriber ) {
			$prepared_subscriber = [
				'email'      => $subscriber['email_address'] ?? '',
				'first_name' => $subscriber['first_name'] ?? '',
				'status'     => $this->get_contact_status( $subscriber['state'] ?? '' ),
				'fields'     => $subscriber['fields'] ?? [],
			];

			// Process tags
			$formatted_tags = [];
			$is_fetched_tags = apply_filters( 'bwfan_is_fetched_tags_for_kit_subscriber', true, $subscriber );
			if ( $is_fetched_tags ) {
				$contact_tags = $this->get_contact_tags( [ 'subscriber_id' => $subscriber['id'] ] );
				if ( is_array( $contact_tags ) && ! empty( $contact_tags ) ) {
					foreach ( $contact_tags as $id => $tag ) {
						if ( isset( $this->auto_tags[ $id ] ) ) {
							$formatted_tags[] = [
								'id'   => $id,
								'name' => $tag
							];
						}
					}
				}
			}

			// Apply default tags if configured (even if no contact-specific tags found)
			if ( ! empty( $this->default_tags ) ) {
				$formatted_tags = array_merge( $formatted_tags, $this->default_tags );
			}

			// Set tags if any tags exist (formatted or default)
			if ( ! empty( $formatted_tags ) ) {
				$prepared_subscriber['tags'] = $formatted_tags;
			}

			$prepared_subscribers[] = $prepared_subscriber;
		}

		return $prepared_subscribers;
	}

	/**
	 * Prepare tag and list data for import.
	 *
	 * @return void
	 */
	public function prepare_tag_list() {
		$this->mapping      = $this->get_import_meta( 'mapped_fields' ) ?? [];
		$this->kit_tags     = $this->get_import_meta( 'kit_tags' ) ?? [];
		$this->regular_tags = [];
		$this->auto_tags    = [];
		foreach ( $this->kit_tags as $tag_id => $tag_data ) {
			if ( ! empty( $tag_data['auto'] ) ) {
				$this->auto_tags[ $tag_id ] = $tag_data;
			} else {
				$this->regular_tags[ $tag_id ] = $tag_id;
			}
		}

		if ( ! empty( $this->regular_tags ) ) {
			foreach ( $this->regular_tags as $tag_id ) {
				// Ensure tag_id exists in kit_tags before accessing
				if ( isset( $this->kit_tags[ $tag_id ] ) && is_array( $this->kit_tags[ $tag_id ] ) ) {
					$this->default_tags[] = [
						'id'   => $tag_id,
						'name' => $this->kit_tags[ $tag_id ]['title'] ?? ''
					];
				}
			}
		}
	}

	/**
	 * Get general fields for mapping.
	 *
	 * @return array
	 */
	public function get_general_fields() {
		return [
			'email'      => __( 'Email', 'wp-marketing-automations' ),
			'first_name' => __( 'First Name', 'wp-marketing-automations' ),
		];
	}

	/**
	 * Get custom fields from Kit.
	 *
	 * @return array
	 */
	public function get_custom_fields( $args = [] ) {
		$api_credentials = $this->get_api_credentials();

		if ( empty( $api_credentials['api_key'] ) ) {
			return [];
		}

		$call = new Get_Custom_Fields();
		$call->set_data( array_merge( $api_credentials, $args ) );
		$result = $call->process();

		$fields = [];

		if ( 200 !== $result['response'] ) {
			return $fields;
		}

		$custom_fields = $result['body']['custom_fields'] ?? [];

		if ( isset( $args['after'] ) ) {
			return $custom_fields;
		}

		if ( ! empty( $result['body']['pagination'] ) && ! empty( $result['body']['pagination']['has_next_page'] ) ) {
			// Handle pagination if needed
			$args['after'] = $result['body']['pagination']['end_cursor'] ?? '';
			$next_fields   = $this->get_custom_fields( $args );
			$custom_fields = array_merge( $custom_fields, $next_fields );
		}

		foreach ( $custom_fields as $field ) {
			$field_key   = $field['key'] ?? '';
			$field_label = $field['label'] ?? $field_key;

			if ( ! empty( $field_key ) ) {
				$fields[ $field_key ] = $field_label;
			}
		}

		return $fields;
	}

	/**
	 * Get all subscriber data from Kit.
	 *
	 * @param array $args Arguments for getting subscribers.
	 *
	 * @return array|WP_Error
	 */
	public function get_contacts( $args = [] ) {
		$api_credentials = $this->get_api_credentials();

		$args = array_merge( $args, [ 'include_total_count' => true ] );
		if ( empty( $api_credentials ) || ! is_array( $api_credentials ) || empty( $api_credentials['api_key'] ) ) {
			return new WP_Error( 'kit_no_credentials', __( 'API credentials not found. Please configure Kit API Key.', 'wp-marketing-automations' ) );
		}

		$call = new Get_Subscribers();
		$call->set_data( array_merge( $api_credentials, $args ) );
		$result = $call->process();
		if ( 200 === $result['response'] ) {
			return $result['body'];
		}

		return new WP_Error( $result['response'], $result['body'][0] ?? '' );
	}

	/**
	 * Get all subscriber data from Kit.
	 *
	 * @param array $args Arguments for getting subscribers.
	 *
	 * @return array|WP_Error
	 */
	public function get_contact_tags( $args = [] ) {
		$api_credentials = $this->get_api_credentials();
		if ( empty( $api_credentials ) || ! is_array( $api_credentials ) || empty( $api_credentials['api_key'] ) ) {
			return new WP_Error( 'kit_no_credentials', __( 'API credentials not found. Please configure Kit API Key.', 'wp-marketing-automations' ) );
		}

		$result_tags = [];
		$call        = new Get_Subscriber_Tags();
		$call->set_data( array_merge( $api_credentials, $args ) );
		$result = $call->process();

		if ( 200 === $result['response'] && ! empty( $result['body'] ) && ! empty( $result['body']['tags'] ) ) {
			$result_tags = $result['body']['tags'];
		}

		if ( ! empty( $result['body']['pagination'] ) && ! empty( $result['body']['pagination']['has_next_page'] ) ) {
			$data = $args;
			// Handle pagination if needed
			$data['after'] = $result['body']['pagination']['end_cursor'] ?? '';
			$next_tags     = $this->get_tags( $data );
			$result_tags   = array_merge( $result_tags, $next_tags );
		}
		if ( isset( $args['after'] ) ) {
			return $result_tags;
		}

		$prepared_tags = [];

		foreach ( $result_tags as $tag ) {
			$prepared_tags[ $tag['id'] ] = $tag['name'];
		}

		return $prepared_tags;
	}

	/**
	 * Kit don't provide lists.
	 *
	 * @param array $data Additional data for the API call.
	 *
	 * @return array Empty array.
	 */
	public function get_lists( $data = [] ) {
		return array();
	}

	/**
	 * Get tags from Kit.
	 *
	 * @param array $data Additional data for the API call.
	 *
	 * @return array
	 */
	public function get_tags( $data = [] ) {
		$api_credentials = $this->get_api_credentials();

		if ( empty( $api_credentials ) || ! is_array( $api_credentials ) || empty( $api_credentials['api_key'] ) ) {
			return [];
		}

		$result_tags = [];

		$call = new Get_Tags();
		$call->set_data( array_merge( $api_credentials, $data ) );
		$result = $call->process();

		if ( 200 === $result['response'] && ! empty( $result['body'] ) && ! empty( $result['body']['tags'] ) ) {
			$result_tags = $result['body']['tags'];
		}
		if ( ! empty( $result['body']['pagination'] ) && ! empty( $result['body']['pagination']['has_next_page'] ) ) {
			$args = $data;
			// Handle pagination if needed
			$args['after'] = $result['body']['pagination']['end_cursor'] ?? '';
			$next_tags     = $this->get_tags( $args );
			$result_tags   = array_merge( $result_tags, $next_tags );
		}
		if ( isset( $data['after'] ) ) {
			return $result_tags;
		}
		$prepared_tags = array();
		foreach ( $result_tags as $tag ) {
			$prepared_tags[ $tag['id'] ] = $tag['name'];
		}

		return $prepared_tags;
	}

	/**
	 * Get the count of subscribers.
	 *
	 * @return int
	 */
	public function get_contacts_count() {
		$subscribers = $this->get_contacts( [ 'limit' => 1 ] );

		if ( isset( $subscribers['pagination']['total_count'] ) ) {
			return $subscribers['pagination']['total_count'];
		}

		return 0;
	}

	/**
	 * Get mapping options for Kit.
	 *
	 * @return array
	 */
	public function get_mapping_options() {
		return array(
			'from' => array(
				'fields' => array(
					'general' => $this->get_general_fields(),
					'custom'  => $this->get_custom_fields(),
				),
				'tags'   => $this->get_tags(),
			),
			'to'   => array(
				'fields' => $this->get_crm_fields( false ),
			),
		);
	}

	/**
	 * Load term mappings for tags and lists.
	 *
	 * @return void
	 */
	public function load_term_mappings() {
		$tags = $this->get_import_meta( 'kit_tags' ) ?? [];

		$term_names     = [];
		$kit_id_to_name = [];
		foreach ( $tags as $kit_id => $term_data ) {
			if ( isset( $term_data['title'] ) ) {
				$term_names[]              = $term_data['title'];
				$kit_id_to_name[ $kit_id ] = $term_data['title'];
			}
		}

		if ( empty( $term_names ) ) {
			return;
		}

		$term_type = BWFCRM_Term_Type::$TAG;

		// Get all term IDs in one go
		$name_to_crm_id = BWFAN_Model_Terms::get_term_ids_by_names( $term_names, $term_type );

		// Create mapping from ActiveCampaign ID to CRM ID
		foreach ( $kit_id_to_name as $kit_id => $name ) {
			if ( isset( $name_to_crm_id[ $name ] ) ) {
				$this->term_id_mapping['tags'][ $kit_id ] = $name_to_crm_id[ $name ];
			}
		}
	}

	/**
	 * Prepare terms (tags/lists) for import.
	 *
	 * @param mixed $value The term values to prepare.
	 * @param string $type The type of terms (tags or lists).
	 *
	 * @return array The prepared terms.
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

				$kit_term_id = $term['id'];

				// Look up the CRM term ID from our mapping
				if ( isset( $this->term_id_mapping[ $type ][ $kit_term_id ] ) ) {
					$terms[] = $this->term_id_mapping[ $type ][ $kit_term_id ];
				}
			}
		}

		return $terms;
	}

	/**
	 * Get log headers for Kit import.
	 *
	 * @return array
	 */
	protected function get_log_headers() {
		return [ 'ID', 'Email', 'Status' ];
	}

	/**
	 * Prepare log data for Kit import.
	 *
	 * @param array $data Contact data.
	 * @param array $result Import result.
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
	 * Get second step fields for Kit import.
	 * This is the key method that was causing the connection issue.
	 *
	 * @param array $fields Initial fields.
	 *
	 * @return array
	 */
	public function get_second_step_fields( $fields ) {
		$api_key         = ! empty( $fields['api_key'] ) ? $fields['api_key'] : '';
		$api_credentials = [
			'api_key' => $api_key,
		];

		$is_valid = $this->connect( 'BWFAN\Importers\Kit\Calls\Connect', $api_credentials );
		if ( is_wp_error( $is_valid ) ) {
			return [
				'status'  => 3,
				'message' => $is_valid->get_error_message()
			];
		}

		$this->set_api_credentials( $api_credentials );
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

		$fields_list = [];

		// Add general fields
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

		$default_mapping = [
			'email'      => 'email',
			'first_name' => 'f_name',
		];

		$schema = [];

		if ( ! empty( $fields_list ) ) {
			$schema[] = [
				'id'           => 'mapped_fields',
				'type'         => 'fields',
				'defaultValue' => $default_mapping,
				'label'        => __( 'Kit Contact Field', 'wp-marketing-automations' ),
				'options'      => $mapped_fields_options,
				'fieldsList'   => $fields_list,
			];
		}

		if ( ! empty( $tag_list ) ) {
			$schema[] = [
				'id'      => 'kit_tags',
				'type'    => 'tags',
				'label'   => __( 'Kit Tag', 'wp-marketing-automations' ),
				'tagList' => $tag_list,
			];
		}

		return $schema;
	}

	/**
	 * Handle update for existing contacts.
	 *
	 * @param array $data Contact data.
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
			'kit_tags'      => ! empty( $data['kit_tags'] ) && is_array( $data['kit_tags'] ) ? $data['kit_tags'] : array(),
		);
	}

	/**
	 * Get contact profile fields.
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
	 * Get contact status from Kit state.
	 *
	 * @param string $state Kit subscriber state.
	 *
	 * @return int CRM status.
	 */
	private function get_contact_status( $state ) {
		switch ( $state ) {
			case 'active':
				return 1;
			case 'bounced':
				return 2;
			case 'inactive':
			case 'cancelled':
				return 3;
			case 'complained' :
				return 6;
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
		if ( ! empty( $this->api_credentials ) && is_array( $this->api_credentials ) && ! empty( $this->api_credentials['api_key'] ) ) {
			return $this->api_credentials;
		}
		if ( $this->get_import_id() > 0 ) {
			$import_meta = $this->get_import_meta();
			if ( ! empty( $import_meta['fields'] ) && ! empty( $import_meta['fields']['api_key'] ) ) {
				$this->api_credentials = $import_meta['fields'];

				return $this->api_credentials;
			}
		}
		$saved_credentials = bwf_options_get( 'funnelkit_' . $this->slug . '_api_credentials' );
		if ( ! empty( $saved_credentials ) && is_array( $saved_credentials ) && ! empty( $saved_credentials['api_key'] ) ) {
			$this->api_credentials = $saved_credentials;

			return $this->api_credentials;
		}

		return [];
	}
}

// Register the Kit importer
BWFAN_Core()->importer->register( 'kit', 'BWFAN\Importers\Kit_Importer' );
