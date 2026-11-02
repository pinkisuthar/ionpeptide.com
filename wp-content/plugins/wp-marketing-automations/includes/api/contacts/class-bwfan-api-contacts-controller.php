<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Contacts_Controller extends BWFAN_API_Controller {
	public static $ins;
	public $total_count = 0;
	public $count_data = array();
	public $contact;
	public $mode = null;
	private $conversation = null;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		$this->pagination = new stdClass();
		$this->pagination->offset = 0;
		$this->pagination->limit  = 10;
		parent::__construct();
	}

	public function default_args_values() {
		return array(
			'search'        => '',
			'filters'       => array(),
			'contact_id'    => 0,
			'first_name'    => '',
			'last_name'     => '',
			'meta'          => [],
			'lists'         => array(),
			'tags'          => array(),
			'notes'         => array(),
			'note_id'       => 0,
			'email'         => '',
			'fields'        => '',
			'mode'          => 0,
			'title'         => '',
			'message'       => '',
			'type'          => 'email',
			'automation_id' => '',
			'c_type'        => '',
		);
	}

	protected function register_routes() {
		/** Shared by both get_contacts() routes (GET /v3/contacts and POST /v3/contacts/query) so the two schemas cannot drift. */
		$get_contacts_args = array(
			'search'         => array(
				'description' => __( 'Search from email, first_name or last_name', 'wp-marketing-automations' ),
				'type'        => 'string',
			),
			'offset'         => array(
				'description' => __( 'Contacts list Offset', 'wp-marketing-automations' ),
				'type'        => 'integer',
			),
			'limit'          => array(
				'description' => __( 'Per page limit', 'wp-marketing-automations' ),
				'type'        => 'integer',
			),
			'get_wc'         => array(
				'description' => __( 'Get WC Data as well', 'wp-marketing-automations' ),
				'type'        => 'boolean',
			),
			'grab_totals'    => array(
				'description' => __( 'Grab total contact count as well', 'wp-marketing-automations' ),
				'type'        => 'boolean',
			),
			'start_indexing' => array(
				'description' => __( 'Start Indexing of Contacts in case indexing is pending', 'wp-marketing-automations' ),
				'type'        => 'boolean',
			),
		);

		// /v3/contacts (GET+POST+DELETE)
		$this->add_route( '/v3/contacts', array(
			array( 'GET', 'get_contacts', $get_contacts_args ),
			array( 'POST', 'create_contact' ),
			array( 'DELETE', 'delete_contacts' ),
		) );

		/**
		 * /v3/contacts/query (POST) — same handler as GET /v3/contacts, filters travel in the body.
		 *
		 * An audience with several OR groups produces a filter tree of tens of kilobytes. On a GET
		 * it rides in the query string, where it meets two ceilings: PHP's max_input_vars, which
		 * truncates it silently so the count comes back wrong, and the web server's URL limit,
		 * which answers 414 before PHP is reached at all. Neither can be raised from PHP, so the
		 * payload has to leave the URL.
		 *
		 * POST is already taken on /v3/contacts by create_contact, hence a separate path. The GET
		 * route stays exactly as it was, so existing callers are unaffected.
		 *
		 * `filters` is not the only payload that can grow: `include_ids` carries one entry per
		 * selected contact and blows the same ceiling on a large selection.
		 */
		$this->add_route( '/v3/contacts/query', array(
			array( 'POST', 'get_contacts', $get_contacts_args ),
		) );

		// /v3/contacts/listing (GET+POST) — POST for large filter payloads, see /v3/contacts/query above.
		$this->add_route( '/v3/contacts/listing', array(
			array( 'GET', 'get_listing' ),
			array( 'POST', 'get_listing' ),
		) );

		// /v3/contacts/{id} (GET+PUT+DELETE)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)', array(
			array( 'GET', 'get_contact', array(
				'contact_id' => array(
					'description' => __( 'Contact ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
			array( 'PUT', 'update_contact' ),
			array( 'DELETE', 'delete_contact' ),
		) );

		// /v3/contacts/{id}/lists (GET+PUT+DELETE)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/lists', array(
			array( 'GET', 'get_contact_lists', array(
				'search' => array(
					'description' => __( 'Search from list name', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'offset' => array(
					'description' => __( 'list Offset', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'limit'  => array(
					'description' => __( 'Per page limit', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
			array( 'PUT', 'update_contact_lists' ),
			array( 'DELETE', 'delete_contact_lists' ),
		) );

		// /v3/contacts/{id}/tags (GET+PUT+DELETE)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/tags', array(
			array( 'GET', 'get_contact_tags', array(
				'search' => array(
					'description' => __( 'Search from tag name', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'offset' => array(
					'description' => __( 'Tags list Offset', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'limit'  => array(
					'description' => __( 'Per page limit', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
			array( 'PUT', 'update_contact_tags' ),
			array( 'DELETE', 'delete_contact_tags', array(
				'tags' => array(
					'description' => __( 'Tags to remove', 'wp-marketing-automations' ),
					'type'        => 'array',
				),
			) ),
		) );

		// /v3/contacts/{id}/notes (GET+POST)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/notes', array(
			array( 'GET', 'get_contact_notes' ),
			array( 'POST', 'create_contact_note' ),
		) );

		// /v3/contacts/{id}/notes/{note_id} (PUT+DELETE)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/notes/(?P<note_id>[\\d]+)', array(
			array( 'PUT', 'update_contact_note' ),
			array( 'DELETE', 'delete_contact_note' ),
		) );

		// /v3/contacts/{id}/fields (PUT)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/fields', array(
			array( 'PUT', 'apply_field' ),
		) );

		// /v3/contacts/{id}/resubscribe (DELETE)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/resubscribe', array(
			array( 'DELETE', 'resubscribe_contact', array(
				'contact_id' => array(
					'description' => __( 'Contact ID to resubscribe contact', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
		) );

		// /v3/contacts/{id}/execute_status_action/{status} (POST)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/execute_status_action/(?P<status>[a-zA-Z0-9-]+)', array(
			array( 'POST', 'execute_status_action' ),
		) );

		// /v3/contacts/{id}/unsubscribe (POST)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/unsubscribe', array(
			array( 'POST', 'unsubscribe_contact', array(
				'contact_id' => array(
					'description' => __( 'Contact ID to unsubscribe contact', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
		) );

		// /v3/contact/{id}/automations (GET) — note: /v3/contact not /v3/contacts
		$this->add_route( '/v3/contact/(?P<contact_id>[\\d]+)/automations', array(
			array( 'GET', 'get_contact_automations' ),
		) );

		// /v3/contacts/{id}/funnels (GET)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/funnels', array(
			array( 'GET', 'get_contact_funnels' ),
		) );

		// /v3/contacts/{id}/orders (GET)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/orders', array(
			array( 'GET', 'get_contact_orders' ),
		) );

		// /v3/contacts/{id}/sendmessage (POST)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/sendmessage', array(
			array( 'POST', 'send_message', array(
				'contact_id' => array(
					'description' => __( 'Contact ID to send message', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
		) );

		// /contact/{id}/resync-order (GET) — note: /contact not /v3/contacts
		$this->add_route( '/contact/(?P<contact_id>[\\d]+)/resync-order', array(
			array( 'GET', 'resync_contact_order' ),
		) );

		// /v3/contacts/{id}/engagement/{engagement_id} (GET)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/engagement/(?P<engagement_id>[\\d]+)', array(
			array( 'GET', 'get_conversation', array(
				'contact_id'    => array(
					'description' => __( 'Contact ID', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'engagement_id' => array(
					'description' => __( 'Engagement ID', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
		) );

		// /v3/contacts/{id}/engagements (GET)
		$this->add_route( '/v3/contacts/(?P<contact_id>[\\d]+)/engagements', array(
			array( 'GET', 'get_conversations', array(
				'contact_id' => array(
					'description' => __( 'Contact ID for which engagements to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
		) );
	}

	// =========================================================================
	// 1. /v3/contacts — GET (from class-bwfan-api-contacts.php get_items)
	// =========================================================================
	public function get_contacts() {
		$additional_info = array(
			'grab_totals'          => $this->get_sanitized_arg( 'grab_totals', 'bool' ),
			'only_count'           => $this->get_sanitized_arg( 'only_count', 'bool' ),
			'fetch_base'           => $this->get_sanitized_arg( 'fetch_base', 'text_field' ),
			'exclude_unsubs'       => $this->get_sanitized_arg( 'exclude_unsubs', 'bool' ),
			'exclude_unsubs_lists' => $this->get_sanitized_arg( 'exclude_unsubs_lists', 'bool' ),
			'grab_custom_fields'   => $this->get_sanitized_arg( 'grab_custom_fields', 'bool' ),
			'include_soft_bounce'  => $this->get_sanitized_arg( 'includeSoftBounce', 'bool' ), // specific for broadcast
			'include_unverified'   => $this->get_sanitized_arg( 'includeUnverified', 'bool' ),
			'include_unsubscribe'  => $this->get_sanitized_arg( 'include_unsubs', 'bool' ),
			'include_ids'          => isset( $this->args['include_ids'] ) ? $this->args['include_ids'] : [],
			'is_broadcast'         => $this->get_sanitized_arg( 'is_broadcast', 'bool' ),
		);

		$un_open_broadcast = $this->get_sanitized_arg( 'unopen_broadcast', 'key' );
		if ( ! empty( $un_open_broadcast ) ) {
			return $this->get_unopened_broadcast_contacts( $un_open_broadcast, $additional_info );
		}

		$order    = $this->get_sanitized_arg( 'order', 'text_field' );
		$order_by = $this->get_sanitized_arg( 'order_by', 'text_field' );
		if ( ! empty( $order ) && ! empty( $order_by ) ) {
			$additional_info['order']    = $order;
			$additional_info['order_by'] = $order_by;
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$additional_info['customer_data'] = $this->get_sanitized_arg( 'get_wc', 'bool' );
		}

		$search             = $this->get_sanitized_arg( 'search', 'text_field' );
		$filters_collection = empty( $this->args['filters'] ) ? array() : $this->args['filters'];

		if ( false === $additional_info['exclude_unsubs'] ) {
			$additional_info['exclude_unsubs'] = apply_filters( 'bwfan_force_exclude_unsubscribe_contact', false );
		}

		$contacts = BWFCRM_Contact::get_contacts( $search, $this->pagination->offset, $this->pagination->limit, $filters_collection, $additional_info );
		if ( ! is_array( $contacts ) ) {
			$this->response_code = 500;

			return $this->error_response( is_string( $contacts ) ? $contacts : __( 'Unknown error occurred', 'wp-marketing-automations' ) );
		}

		if ( isset( $contacts['total_count'] ) ) {
			$this->total_count = absint( $contacts['total_count'] );
		}

		if ( ! isset( $contacts['contacts'] ) || empty( $contacts['contacts'] ) ) {
			return $this->success_response( array() );
		}

		$this->response_code = 200;

		return $this->success_response( $contacts['contacts'] );
	}

	// =========================================================================
	// 1. /v3/contacts — POST (from class-bwfan-api-contacts.php create_item)
	// =========================================================================
	public function create_contact() {
		$email = $this->get_sanitized_arg( 'email', 'email' );
		if ( false === $email || ! is_email( $email ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Email is not valid', 'wp-marketing-automations' ) );
		}

		$params = array(
			'f_name'         => isset( $this->args['f_name'] ) ? $this->get_sanitized_arg( 'f_name', 'text_field', $this->args['f_name'] ) : '',
			'l_name'         => isset( $this->args['l_name'] ) ? $this->get_sanitized_arg( 'l_name', 'text_field', $this->args['l_name'] ) : '',
			'create_wp_user' => isset( $this->args['create_wp_user'] ) ? rest_sanitize_boolean( $this->args['create_wp_user'] ) : '',
			'wp_password'    => isset( $this->args['wp_password'] ) ? $this->args['wp_password'] : '',
			'contact_no'     => isset( $this->args['contact_no'] ) ? $this->get_sanitized_arg( 'contact_no', 'text_field', $this->args['contact_no'] ) : '',
			'source'         => isset( $this->args['source'] ) ? $this->get_sanitized_arg( 'source', 'text_field', $this->args['source'] ) : '',
			'status'         => isset( $this->args['status'] ) ? $this->get_sanitized_arg( 'status', 'text_field', $this->args['status'] ) : '',
		);

		foreach ( $this->args as $key => $value ) {
			if ( ! is_numeric( $key ) ) {
				continue;
			}
			$params[ $key ] = $value;
		}

		$contact = new BWFCRM_Contact( $email, true, $params );
		if ( isset( $this->args['tags'] ) ) {
			$contact->set_tags( $this->args['tags'], true, false );
		}

		if ( isset( $this->args['lists'] ) ) {
			$contact->set_lists( $this->args['lists'], true, false );
		}

		$contact->save();

		if ( $contact->already_exists ) {
			$this->response_code = 422;

			return $this->error_response( __( 'Contact already exists', 'wp-marketing-automations' ) );
		}

		if ( $contact->is_contact_exists() ) {
			$this->response_code = 200;

			return $this->success_response( $contact->get_array( false, class_exists( 'WooCommerce' ) ), __( 'Contact created', 'wp-marketing-automations' ) );
		}

		$this->response_code = 500;

		return $this->error_response( __( 'Unable to create contact', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// 1. /v3/contacts — DELETE (from class-bwfan-api-contacts.php delete_item)
	// =========================================================================
	public function delete_contacts() {
		$contact_ids = $this->args['contacts'];
		if ( empty( $contact_ids ) || ! is_array( $contact_ids ) ) {
			return $this->error_response( __( 'Contact ids are missing.', 'wp-marketing-automations' ), null, 500 );
		}

		BWFCRM_Model_Contact::delete_multiple_contacts( $contact_ids );

		$this->response_code = 200;

		return $this->success_response( array( 'contacts' => $contact_ids ), __( 'Contacts deleted!', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// 2. /v3/contacts/{id} — GET (from class-bwfan-api-contact-single.php get_items)
	// =========================================================================
	public function get_contact() {
		$id      = $this->get_sanitized_arg( 'contact_id', 'key' );
		$contact = new BWFCRM_Contact( $id );
		if ( $contact->is_contact_exists() ) {
			try {
				$data = $contact->get_array( false, true, ! class_exists( 'BWFCRM_WCS_Handler' ), true, true );
			} catch ( Error $e ) {
				$this->response_code = 404;

				return $this->error_response( $e->getMessage() );
			}

			return $this->success_response( $data );
		}
		$this->response_code = 404;

		return $this->error_response( __( "No contact found with given id #", 'wp-marketing-automations' ) . $id );
	}

	// =========================================================================
	// 2. /v3/contacts/{id} — PUT (from class-bwfan-api-contact-single.php update_item)
	// =========================================================================
	public function update_contact() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'key' );

		if ( empty( $contact_id ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Contact ID is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );

		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$contact_basic_info = array();

		$contact_first_name = $this->get_sanitized_arg( 'first_name', 'text_field' );
		$contact_last_name  = $this->get_sanitized_arg( 'last_name', 'text_field' );
		$contact_email      = $this->get_sanitized_arg( 'email', 'text_field' );

		if ( ! empty( $contact_first_name ) ) {
			$contact_basic_info['first_name'] = $contact_first_name;
		}

		if ( ! empty( $contact_last_name ) ) {
			$contact_basic_info['last_name'] = $contact_last_name;
		}

		if ( ! empty( $contact_email ) ) {
			$contact_basic_info['email'] = $contact_email;
		}

		if ( ! empty( $this->args['meta'] ) && is_array( $this->args['meta'] ) ) {
			$contact_basic_info['meta'] = $this->args['meta'];
		}

		if ( empty( $contact_basic_info ) && empty( $this->args['lists'] ) && empty( $this->args['tags'] ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Unable to update contact as update data missing', 'wp-marketing-automations' ) );
		}

		$contact_field_updated = false;
		if ( ! empty( $contact_basic_info ) ) {
			$contact_basic_info['id'] = $contact_id;
			$contact_field_updated    = $contact->update( $contact_basic_info );
		}

		$added_lists = array();
		$lists_added = array();
		if ( ! empty( $this->args['lists'] ) && is_array( $this->args['lists'] ) ) {
			$lists       = $this->args['lists'];
			$added_lists = $contact->set_lists( $lists );
			$lists_added = array_map( function ( $list ) {
				return $list->get_array();
			}, $added_lists );
		}

		$added_tags = array();
		$tags_added = array();
		if ( ! empty( $this->args['tags'] ) && is_array( $this->args['tags'] ) ) {
			$tags       = $this->args['tags'];
			$added_tags = $contact->set_tags( $tags );
			$tags_added = array_map( function ( $tags ) {
				return $tags->get_array();
			}, $added_tags );
		}

		$contact->save();

		if ( false === $contact_field_updated && empty( $added_lists ) && empty( $added_tags ) ) {
			$this->response_code = 200;

			return $this->error_response( __( 'Unable to update contact fields', 'wp-marketing-automations' ) );
		}
		$result = [];
		if ( ! empty( $contact_field_updated ) ) {
			$result['contact'] = $contact_field_updated;
		}
		if ( ! empty( $lists_added ) ) {
			$result['lists'] = $lists_added;
		}

		if ( ! empty( $tags_added ) ) {
			$result['tags'] = $tags_added;
		}

		return $this->success_response( $result, __( 'Contact updated', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// 2. /v3/contacts/{id} — DELETE (from class-bwfan-api-contact-single.php delete_item)
	// =========================================================================
	public function delete_contact() {
		$contact_id = intval( $this->get_sanitized_arg( 'contact_id', 'text_field' ) );
		if ( empty( $contact_id ) ) {
			return $this->error_response( __( 'Contact id is missing.', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );

		if ( ! $contact instanceof BWFCRM_Contact || 0 === $contact->get_id() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$delete_contact_result = BWFCRM_Model_Contact::delete_contact( $contact_id );

		if ( ! $delete_contact_result ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Some error occurred during deletion of contact and its data.', 'wp-marketing-automations' ) );
		}

		$this->response_code = 200;

		return $this->success_response( array( 'contact_id' => $contact_id ), __( 'Contact deleted', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// 3. /v3/contacts/{id}/lists — GET (from class-bwfan-api-contact-lists.php get_items)
	// =========================================================================
	public function get_contact_lists() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'text_field' );
		$search     = $this->get_sanitized_arg( 'search', 'text_field' );

		if ( empty( $contact_id ) ) {
			return $this->error_response( __( 'Contact id is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );

		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$contact_terms = $contact->get_all_lists();
		if ( empty( $contact_terms ) ) {
			$this->response_code = 404;
			/* translators: %1$s: Search term */
			$error_message       = ! empty( $search ) ? sprintf( __( 'No Searched lists found for contact with name %1$s', 'wp-marketing-automations' ), " '$search'" ) : __( 'No contacts lists found', 'wp-marketing-automations' );

			return $this->error_response( $error_message );
		}

		$this->total_count   = count( $contact_terms );
		$this->response_code = 200;
		$success_message     = ! empty( $search ) ? sprintf( __( 'Searched lists for contact ', 'wp-marketing-automations' ), " '$search'" ) : __( 'Got all contacts lists', 'wp-marketing-automations' );

		return $this->success_response( $contact_terms, $success_message );
	}

	// =========================================================================
	// 3. /v3/contacts/{id}/lists — PUT (from class-bwfan-api-contact-lists.php update_item)
	// =========================================================================
	public function update_contact_lists() {
		$contact_id = $this->get_sanitized_arg( 'contact_id' );
		if ( empty( $contact_id ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Contact ID is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );

		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$lists = $this->args['lists'];
		$lists = array_filter( array_values( $lists ) );
		if ( empty( $lists ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Required Lists missing', 'wp-marketing-automations' ) );
		}

		$lists       = BWFAN_Common::check_for_comma_seperated( $lists );
		$added_lists = $contact->add_lists( $lists );

		if ( is_wp_error( $added_lists ) ) {
			$this->response_code = 500;

			return $this->error_response( '', $added_lists );
		}

		if ( empty( $added_lists ) ) {
			$this->response_code = 200;

			return $this->success_response( '', __( 'Provided lists are applied already.', 'wp-marketing-automations' ) );
		}

		$result      = [];
		$lists_added = array_map( function ( $list ) {
			return $list->get_array();
		}, $added_lists );

		$message = __( 'List(s) assigned', 'wp-marketing-automations' );
		if ( count( $lists ) !== count( $added_lists ) ) {
			$added_lists_names = array_map( function ( $list ) {
				return $list->get_name();
			}, $added_lists );

			$added_lists_names   = implode( ', ', $added_lists_names );
			$this->response_code = 200;
			/* translators: %1$s: Comma-separated list names */
			$message             = sprintf( __( 'Some lists are applied already. Applied Lists are: %1$s', 'wp-marketing-automations' ), $added_lists_names );
		}
		$result['list_added']    = is_array( $lists_added ) ? array_values( $lists_added ) : $lists_added;
		$result['last_modified'] = $contact->contact->get_last_modified();

		return $this->success_response( $result, $message );
	}

	// =========================================================================
	// 3. /v3/contacts/{id}/lists — DELETE (from class-bwfan-api-contact-lists.php delete_item)
	// =========================================================================
	public function delete_contact_lists() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'key' );
		$lists      = $this->get_sanitized_arg( '', 'key', $this->args['lists'] );

		if ( empty( $lists ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'No Lists provided', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );
		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$lists = array_map( 'absint', $lists );
		$lists = array_filter( $lists );
		if ( empty( absint( $lists ) ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'No list found', 'wp-marketing-automations' ) );
		}

		$removed_lists     = $contact->remove_lists( $lists );
		$contact->save();
		$lists_not_removed = array_diff( $lists, $removed_lists );

		if ( count( $lists_not_removed ) === count( $lists ) ) {
			$this->response_code = 500;

			return $this->error_response( __( 'Unable to remove the list', 'wp-marketing-automations' ) );
		}
		$result   = [];
		$response = __( 'Lists Unassigned', 'wp-marketing-automations' );
		if ( ! empty( $lists_not_removed ) ) {
			$removed_lists_text = implode( ', ', $removed_lists );
			/* translators: %1$s: Comma-separated list names */
			$response           = sprintf( __( 'Some lists removed: %1$s', 'wp-marketing-automations' ), $removed_lists_text );
		}
		$result['last_modified'] = $contact->contact->get_last_modified();

		return $this->success_response( $result, $response );
	}

	// =========================================================================
	// 4. /v3/contacts/{id}/tags — GET (from class-bwfan-api-contact-tags.php get_items)
	// =========================================================================
	public function get_contact_tags() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'text_field' );

		if ( empty( $contact_id ) ) {
			return $this->error_response( __( 'Contact ID is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );
		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$contact_terms       = $contact->get_all_tags();
		$this->response_code = 200;

		return $this->success_response( $contact_terms );
	}

	// =========================================================================
	// 4. /v3/contacts/{id}/tags — PUT (from class-bwfan-api-contact-tags.php update_item)
	// =========================================================================
	public function update_contact_tags() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'key' );
		if ( empty( $contact_id ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Contact ID is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );

		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found related with contact id : %1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$tags = $this->args['tags'];
		$tags = array_filter( array_values( $tags ) );
		if ( empty( $tags ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'No Tags provided', 'wp-marketing-automations' ) );
		}

		$tags       = BWFAN_Common::check_for_comma_seperated( $tags );
		$added_tags = $contact->add_tags( $tags );
		if ( is_wp_error( $added_tags ) ) {
			$this->response_code = 500;

			return $this->error_response( '', $added_tags );
		}

		if ( empty( $added_tags ) ) {
			$this->response_code = 200;

			return $this->success_response( '', __( 'Provided tags are applied already.', 'wp-marketing-automations' ) );
		}
		$tags_added = array_map( function ( $tag ) {
			return $tag->get_array();
		}, $added_tags );
		$result     = [];
		$message    = __( 'Tag(s) added', 'wp-marketing-automations' );
		if ( count( $tags ) !== count( $added_tags ) ) {
			$applied_tags_names  = array_map( function ( $tag ) {
				return $tag->get_name();
			}, $added_tags );
			$applied_tags_names  = implode( ', ', $applied_tags_names );
			$this->response_code = 200;
			/* translators: %1$s: Comma-separated tag names */
			$message             = sprintf( __( 'Some tags are applied already. Applied Tags are: %1$s', 'wp-marketing-automations' ), $applied_tags_names );
		}
		$result['tags_added']    = is_array( $tags_added ) ? array_values( $tags_added ) : $tags_added;
		$result['last_modified'] = $contact->contact->get_last_modified();

		return $this->success_response( $result, $message );
	}

	// =========================================================================
	// 4. /v3/contacts/{id}/tags — DELETE (from class-bwfan-api-contact-tags.php delete_item)
	// =========================================================================
	public function delete_contact_tags() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'key' );
		$tags       = $this->get_sanitized_arg( '', 'key', $this->args['tags'] );

		if ( empty( $tags ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'No Tags provided', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );
		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		if ( ! is_array( $tags ) ) {
			if ( is_string( $tags ) && false !== strpos( $tags, ',' ) ) {
				$tags = explode( ',', $tags );
			} else if ( empty( absint( $tags ) ) ) {
				$this->response_code = 400;

				return $this->error_response( __( 'No tag found', 'wp-marketing-automations' ) );
			} else {
				$tags = array( absint( $tags ) );
			}
		}

		$tags = array_map( 'absint', $tags );
		$tags = array_filter( $tags );
		if ( empty( absint( $tags ) ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Invalid Tags Provided', 'wp-marketing-automations' ) );
		}

		$removed_tags = $contact->remove_tags( $tags );
		$contact->save();
		$tags_not_removed = array_diff( $tags, $removed_tags );
		if ( count( $tags_not_removed ) === count( $tags ) ) {
			$this->response_code = 500;

			return $this->error_response( __( 'Unable to remove any tag', 'wp-marketing-automations' ) );
		}
		$result   = [];
		$response = __( 'Tag removed', 'wp-marketing-automations' );
		if ( ! empty( $tags_not_removed ) ) {
			$removed_tags_text = implode( ', ', $removed_tags );
			/* translators: %1$s: Comma-separated tag names */
			$response          = sprintf( __( 'Some tags removed: %1$s', 'wp-marketing-automations' ), $removed_tags_text );
		}
		$result['last_modified'] = $contact->contact->get_last_modified();

		return $this->success_response( $result, $response );
	}

	// =========================================================================
	// 5. /v3/contacts/{id}/notes — GET (from class-bwfan-api-contact-notes.php get_items)
	// =========================================================================
	public function get_contact_notes() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'text_field' );
		$offset     = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? $this->get_sanitized_arg( 'offset', 'absint' ) : $this->pagination->offset;
		$limit      = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : $this->pagination->limit;

		if ( empty( $contact_id ) ) {
			return $this->error_response( __( 'Contact ID is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );
		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$contact_notes = $contact->get_contact_notes_array( $offset, $limit );

		if ( empty( $contact_notes ) ) {
			$this->response_code = 200;

			/* translators: %1$d: Contact ID */
			return $this->success_response( [], sprintf( __( 'No contact notes found related with contact id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}
		$all_contact_notes   = $contact->get_contact_notes_array( 0, 0 );
		$this->total_count   = count( $all_contact_notes );
		$this->response_code = 200;

		/* translators: %1$d: Contact ID */
		return $this->success_response( $contact_notes, sprintf( __( 'Contact notes related to contact id : #%1$d', 'wp-marketing-automations' ), $contact_id ) );
	}

	// =========================================================================
	// 5. /v3/contacts/{id}/notes — POST (from class-bwfan-api-contact-notes.php create_item)
	// =========================================================================
	public function create_contact_note() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'key' );
		if ( empty( $contact_id ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Contact ID is mandatory', 'wp-marketing-automations' ) );
		}

		$notes = $this->args['notes'];
		if ( empty( $notes ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Note is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );
		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$note_added = $contact->add_note_to_contact( $notes );
		if ( false === $note_added || is_wp_error( $note_added ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Unable to add note to contact', 'wp-marketing-automations' ) );
		}

		$this->response_code = 200;

		return $this->success_response( [], __( 'Contact note added', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// 6. /v3/contacts/{id}/notes/{note_id} — PUT (from class-bwfan-api-contact-note-single.php update_item)
	// =========================================================================
	public function update_contact_note() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'key' );
		if ( empty( $contact_id ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Contact ID is mandatory', 'wp-marketing-automations' ) );
		}

		$note_id = $this->get_sanitized_arg( 'note_id', 'key' );
		if ( empty( $note_id ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Note ID is mandatory', 'wp-marketing-automations' ) );
		}

		$notes = $this->args['notes'];
		if ( empty( $notes ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Notes are mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );
		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$note_updated = $contact->update_contact_note( $notes, $note_id );
		if ( false === $note_updated || is_wp_error( $note_updated ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Unable to update note of contact', 'wp-marketing-automations' ) );
		}

		$this->response_code = 200;

		return $this->success_response( [], __( 'Contact note updated', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// 6. /v3/contacts/{id}/notes/{note_id} — DELETE (from class-bwfan-api-contact-note-single.php delete_item)
	// =========================================================================
	public function delete_contact_note() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'text_field' );
		if ( empty( $contact_id ) ) {
			return $this->error_response( __( 'Contact id is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );

		if ( ! $contact instanceof BWFCRM_Contact || 0 === $contact->get_id() ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}
		$note_id                    = intval( $this->get_sanitized_arg( 'note_id', 'key' ) );
		$delete_contact_note_result = $contact->delete_notes( $note_id );

		if ( ! $delete_contact_note_result ) {
			$this->response_code = 404;

			/* translators: %1$d: Contact ID */
			return $this->error_response( sprintf( __( 'Unable to delete the note for contact #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$this->response_code = 200;

		return $this->success_response( array( 'contact_id' => $contact_id ), __( 'Contact notes deleted', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// 7. /v3/contacts/{id}/fields — PUT (from class-bwfan-api-apply-field.php process_api_call)
	// =========================================================================
	public function apply_field() {
		$contact = $this->get_contact_by_id_or_email( 'contact_id', 'email' );
		$email   = $this->get_sanitized_arg( 'email', 'text_field' );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			$email = $contact->contact->get_email();
		}

		$sanitize_cb = 'text_field';
		if ( is_array( $this->args['fields'] ) ) {
			$ids = array_keys( $this->args['fields'] );
			$ids = array_filter( $ids, [ $this, 'check_field_type_textarea' ] );
			if ( count( $ids ) > 0 ) {
				$sanitize_cb = 'textarea_field';
			}
		}

		$fields = $this->get_sanitized_arg( '', $sanitize_cb, $this->args['fields'] );

		if ( empty( $fields ) ) {
			$response            = __( 'Required Fields missing', 'wp-marketing-automations' );
			$this->response_code = 400;

			return $this->error_response( $response );
		}

		$field_email = $this->get_sanitized_arg( 'email', 'text_field', $this->args['fields'] );
		if ( ! empty( $field_email ) && $email !== $field_email ) {
			if ( ! is_email( $field_email ) ) {
				$this->response_code = 400;

				return $this->error_response( __( 'Email is not valid.', 'wp-marketing-automations' ) );
			}

			$check_contact = new BWFCRM_Contact( $field_email );
			/** If email is already exists with other contacts*/
			if ( $check_contact->is_contact_exists() ) {

				/** If Only email field to be updated then return error response */
				if ( 1 === count( $this->args['fields'] ) ) {
					$this->response_code = 400;

					return $this->error_response( __( 'Email is already associated with other contact.', 'wp-marketing-automations' ) );
				}

				/**If other fields also available for update then unset the email */
				unset( $fields['email'] );
			}
		}

		$response = $contact->update_custom_fields( $fields );
		if ( ! ( $response ) || empty( $response ) ) {
			$this->response_code = 200;

			return $this->success_response( '', __( 'Unable to Update fields', 'wp-marketing-automations' ) );
		}

		/** If email changed and email is set for change then hook added */
		if ( ! empty( $field_email ) && $email !== $field_email ) {
			do_action( 'bwfan_contact_email_changed', $field_email, $email, $contact );
		}

		return $this->success_response( $contact->get_array( false, true, true, true ), __( 'Contact updated', 'wp-marketing-automations' ) );
	}

	/**
	 * Helper for apply_field: check if a custom field is of type textarea.
	 */
	public function check_field_type_textarea( $id ) {
		$fields = BWFCRM_Fields::get_custom_fields( null, null, null );
		$fields = array_filter( $fields, function ( $val ) {
			if ( isset( $val['type'] ) && 3 == $val['type'] ) {
				/** 3 is textarea */
				return true;
			}

			return false;
		} );
		if ( is_array( $fields ) && array_key_exists( $id, $fields ) ) {
			return true;
		}

		return false;
	}

	// =========================================================================
	// 8. /v3/contacts/listing — GET (from class-bwfan-api-contact-listing.php process_api_call)
	// =========================================================================
	public function get_listing() {
		/** checking if search present in params */
		$search             = $this->get_sanitized_arg( 'search', 'text_field' );
		$filters_collection = empty( $this->args['filters'] ) ? array() : $this->args['filters'];

		/** Resolve audience_id from URL → override filters with audience's saved filters when valid */
		$audience_id              = absint( $this->get_sanitized_arg( 'audience_id', 'text_field' ) );
		$applied_audience_filters = null;
		if ( $audience_id > 0 && class_exists( 'BWFCRM_Audience' ) ) {
			$audience = new BWFCRM_Audience( $audience_id );
			if ( $audience->is_audience_exists() ) {
				$audience_data = $audience->get_array();
				$decoded       = is_array( $audience_data ) && isset( $audience_data['data'] )
					? json_decode( $audience_data['data'], true )
					: null;
				if ( is_array( $decoded ) && ! empty( $decoded['filters'] ) ) {
					$filters_collection       = $decoded['filters'];
					$applied_audience_filters = $decoded['filters'];
				}
			}
		}

		$get_wc_data          = $this->get_sanitized_arg( 'get_wc', 'bool' );
		$grab_totals          = $this->get_sanitized_arg( 'grab_totals', 'bool' );
		$only_count           = $this->get_sanitized_arg( 'only_count', 'bool' );
		$contact_mode         = $this->get_sanitized_arg( 'fetch_base', 'text_field' );
		$exclude_unsubs       = $this->get_sanitized_arg( 'exclude_unsubs', 'bool' );
		$exclude_unsubs_lists = $this->get_sanitized_arg( 'exclude_unsubs_lists', 'bool' );
		$grab_custom_fields   = $this->get_sanitized_arg( 'grab_custom_fields', 'bool' );
		$order                = $this->get_sanitized_arg( 'order', 'text_field' );
		$order_by             = $this->get_sanitized_arg( 'order_by', 'text_field' );
		$additional_info      = array(
			'grab_totals'          => $grab_totals,
			'only_count'           => $only_count,
			'fetch_base'           => $contact_mode,
			'exclude_unsubs'       => $exclude_unsubs,
			'exclude_unsubs_lists' => $exclude_unsubs_lists,
			'grab_custom_fields'   => $grab_custom_fields,
		);

		if ( ! empty( $order ) && ! empty( $order_by ) ) {
			$additional_info['order']    = $order;
			$additional_info['order_by'] = $order_by;
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$additional_info['customer_data'] = $get_wc_data;
		}

		$normalized_filters = [];
		$filter_match       = 'all';
		if ( bwfan_is_autonami_pro_active() ) {
			$filter_match       = isset( $filters_collection['match'] ) && ! empty( $filters_collection['match'] ) ? $filters_collection['match'] : 'all';
			$filter_match       = ( 'any' === $filter_match ? ' OR ' : ' AND ' );
			$normalized_filters = BWFCRM_Filters::_normalize_input_filters( $filters_collection );
		}

		if ( class_exists( 'BWFCRM_Load_Filters' ) ) {
			$additional_info['preferences'] = true;
			$contacts                       = BWFCRM_Model_Contact::get_contacts_from_filters( $search, $this->pagination->limit, $this->pagination->offset, $filters_collection, $additional_info );
		} else {
			$contacts = BWFCRM_Model_Contact::get_contact_listing( $search, $this->pagination->limit, $this->pagination->offset, $normalized_filters, $additional_info, $filter_match );
		}

		$this->count_data  = BWFAN_Common::get_contact_data_counts();
		$this->total_count = $contacts['total'];

		$this->response_code = 200;

		$response = $this->success_response( $contacts['contacts'] );
		if ( null !== $applied_audience_filters && $response instanceof WP_REST_Response ) {
			$data                    = $response->get_data();
			$data['applied_filters'] = $applied_audience_filters;
			$response->set_data( $data );
		}

		return $response;
	}

	// =========================================================================
	// 9. /v3/contacts/{id}/resubscribe — DELETE (from class-bwfan-api-contact-resubscribe.php process_api_call)
	// =========================================================================
	public function resubscribe_contact() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'text_field' );
		$contact    = new BWFCRM_Contact( absint( $contact_id ) );
		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Contact doesn\'t exist', 'wp-marketing-automations' ) );
		}

		$unsubscribe_data = $contact->check_contact_unsubscribed( false );

		if ( empty( $unsubscribe_data ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Contact already subscribed', 'wp-marketing-automations' ) );
		}

		foreach ( $unsubscribe_data as $data ) {

			BWFAN_Model_Message_Unsubscribe::delete( $data['ID'] );
		}
		$contact->save_last_modified();
		$this->response_code = 200;

		return $this->success_response( [ 'contact_id' => $contact_id ], __( 'Contact subscribed', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// 10. /v3/contacts/{id}/execute_status_action/{status} — POST (from class-bwfan-api-contact-status-change.php process_api_call)
	// =========================================================================
	public function execute_status_action() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'text_field' );
		$status     = $this->get_sanitized_arg( 'status', 'text_field' );

		$contact = new BWFCRM_Contact( absint( $contact_id ) );
		if ( ! $contact->is_contact_exists() ) {
			return $this->error_response( __( 'Contact does not exists', 'wp-marketing-automations' ) );
		}

		$result  = false;
		$message = __( 'Unable to perform requested action', 'wp-marketing-automations' );
		switch ( $status ) {
			case 'resubscribe':
				$result = $contact->resubscribe();
				if ( true === $result ) {
					$message = __( 'Contact resubscribed', 'wp-marketing-automations' );
				}
				break;
			case 'unsubscribe':
				$result = $contact->unsubscribe();
				if ( true === $result ) {
					$message = __( 'Contact unsubscribed', 'wp-marketing-automations' );
				}
				break;
			case 'verify':
				$result = $contact->verify();
				if ( true === $result ) {
					$message = __( 'Contact subscribed', 'wp-marketing-automations' );
				}
				break;
			case 'unverify':
				$result = $contact->unverify();
				if ( true === $result ) {
					$message = __( 'Contact unverified', 'wp-marketing-automations' );
				}
				break;
			case 'bounced':
				$result = $contact->mark_as_bounced();
				if ( true === $result ) {
					$message = __( 'Contact bounced', 'wp-marketing-automations' );
				}
				break;
			case 'softbounced':
				$result = $contact->mark_as_soft_bounced();
				if ( true === $result ) {
					$message = __( 'Contact soft bounced', 'wp-marketing-automations' );
				}
				break;
			case 'complaint':
				$result = $contact->mark_as_complaint();
				if ( true === $result ) {
					$message = __( 'Contact complaint', 'wp-marketing-automations' );
				}
				break;
		}

		if ( ! $result ) {
			return $this->error_response( $message, null, 500 );
		}

		return $this->success_response( [
			'status' => $contact->get_display_status(),
			'data'   => $contact->get_array( false, true, true, true, true ),
		], $message );
	}

	// =========================================================================
	// 11. /v3/contacts/{id}/unsubscribe — POST (from class-bwfan-api-contact-unsubscribe.php process_api_call)
	// =========================================================================
	public function unsubscribe_contact() {
		$contact_id    = $this->get_sanitized_arg( 'contact_id', 'text_field' );
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$c_type        = $this->get_sanitized_arg( 'c_type', 'text_field' );
		$contact       = new BWFCRM_Contact( absint( $contact_id ) );
		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Contact doesn\'t exist', 'wp-marketing-automations' ) );
		}

		$unsubscribed = $contact->check_contact_unsubscribed();

		if ( ! empty( $unsubscribed['ID'] ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Contact already unsubscribed', 'wp-marketing-automations' ) );
		}
		$recipients   = [];
		$email        = $contact->contact->get_email();
		$phone        = $contact->contact->get_contact_no();
		$recipients[] = $email;
		if ( ! empty( $phone ) ) {
			$recipients[] = $phone;
		}

		foreach ( $recipients as $recipient ) {
			$insert_data = array(
				'recipient'     => $recipient,
				'mode'          => is_email( $recipient ) ? 1 : 2,
				'c_date'        => current_time( 'mysql', 1 ),
				'automation_id' => ! empty( $automation_id ) ? absint( $automation_id ) : get_current_user_id(),
				'c_type'        => ! empty( $c_type ) ? absint( $c_type ) : 3
			);

			BWFAN_Model_Message_Unsubscribe::insert( $insert_data );
			/** hook when any contact unsubscribed  */
			do_action( 'bwfcrm_after_contact_unsubscribed', $insert_data );
		}
		$contact->save_last_modified();

		return $this->success_response( [ 'contact_id' => $contact_id ], __( 'Contact unsubscribed', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// 12. /v3/contact/{id}/automations — GET (from class-bwfan-api-get-contact-automations.php process_api_call)
	// =========================================================================
	public function get_contact_automations() {
		/** checking if id or email present in params **/
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'key' );
		$offset     = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? absint( $this->get_sanitized_arg( 'offset', 'absint' ) ) : 0;
		$limit      = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;

		/** contact id missing than return  */
		if ( empty( $contact_id ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Contact id is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );
		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: 1: Contact ID */

			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$contact_automations = [];
		if ( class_exists( 'BWFAN_Common' ) ) {
			$contact_automations = BWFAN_Common::get_automations_for_contact( $contact_id, $limit, $offset );
		}

		if ( empty( $contact_automations ) ) {
			$this->response_code = 200;

			/* translators: 1: Contact ID */

			return $this->error_response( sprintf( __( 'No contact automation found related with contact id : %1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$contact_automations['contacts'] = array_map( function ( $contact ) {

			/** Get event name */
			$event_slug = BWFAN_Model_Automations::get_event_name( $contact['aid'] );
			$event_obj  = BWFAN_Core()->sources->get_event( $event_slug );
			$event_name = ! empty( $event_obj ) ? $event_obj->get_name() : '';

			$automation_meta = BWFAN_Core()->automations->get_automation_data_meta( $contact['aid'] );

			$contact['event']            = $event_name;
			$contact['automation_title'] = $automation_meta['title'];

			return $contact;
		}, $contact_automations['contacts'] );


		$this->total_count   = isset( $contact_automations['total'] ) ? $contact_automations['total'] : 0;
		$this->response_code = 200;
		$success_message     = __( 'Got all contact automations', 'wp-marketing-automations' );

		return $this->success_response( $contact_automations, $success_message );
	}

	// =========================================================================
	// 13. /v3/contacts/{id}/funnels — GET (from class-bwfan-api-get-contact-funnels.php process_api_call)
	// =========================================================================
	public function get_contact_funnels() {
		/** checking if search present in params **/

		$contact_id = $this->get_sanitized_arg( 'contact_id', 'key' );
		$offset     = $this->get_sanitized_arg( 'offset', 'absint' );
		$limit      = $this->get_sanitized_arg( 'limit', 'absint' );

		$offset = empty( $offset ) ? $this->pagination->offset : $offset;
		$limit  = empty( $offset ) ? $this->pagination->limit : $limit;

		if ( empty( $contact_id ) ) {
			return $this->error_response( __( 'Contact ID is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );

		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: 1: Contact ID */

			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$contacts_funnel           = array();
		$contacts_funnel['funnel'] = $contact->get_contact_funnels_array();

		if ( function_exists( 'WFACP_Core' ) ) {
			$contacts_funnel['funnel']['checkout'] = [];
		}

		if ( function_exists( 'WFOB_Core' ) ) {
			$contacts_funnel['funnel']['order_bump'] = [];
		}

		if ( function_exists( 'WFOPP_Core' ) ) {
			$optin_etries = $contact->get_contact_optin_array();

			if ( is_array( $optin_etries ) && ! empty( $optin_etries ) ) {
				/** Add email in optin entry */
				$optin_etries = array_map( function ( $optin ) {
					$entry = json_decode( $optin['entry'], true );
					if ( ! empty( $optin['email'] ) && ! empty( $entry ) ) {
						$entry['optin_email'] = $optin['email'];
					}
					$optin['entry'] = wp_json_encode( $entry );

					return $optin;
				}, $optin_etries );
			}

			$contacts_funnel['funnel']['optin'] = $optin_etries;
		}

		if ( function_exists( 'WFOCU_Core' ) ) {
			$contacts_funnel['funnel']['upsells'] = [];
		}

		$this->response_code = 200;
		$success_message     = __( 'Contacts funnels', 'wp-marketing-automations' );

		return $this->success_response( $contacts_funnel, $success_message );
	}

	// =========================================================================
	// 14. /v3/contacts/{id}/orders — GET (from class-bwfan-api-get-contact-orders.php process_api_call)
	// =========================================================================
	public function get_contact_orders() {
		/** checking if id or email present in params **/
		$contact_id = $this->get_sanitized_arg( 'contact_id' );

		/** contact id missing than return  */
		if ( empty( $contact_id ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Contact id is mandatory', 'wp-marketing-automations' ) );
		}

		$contact = new BWFCRM_Contact( $contact_id );

		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 404;

			/* translators: 1: Contact ID */

			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$limit     = $this->get_sanitized_arg( 'limit', 'absint' );
		$offset    = $this->get_sanitized_arg( 'offset', 'absint' );
		$limit     = ! empty( $limit ) ? $limit : 10;
		$offset    = ! empty( $offset ) ? $offset : 0;
		$order_ids = $contact->get_orders( $offset, $limit );
		$orders    = $this->get_contact_activity_records( $contact_id, $order_ids );
		if ( empty( $orders ) ) {
			$response            = [
				'orders' => [],
			];
			$this->response_code = 200;

			/* translators: 1: Contact ID */

			return $this->success_response( $response, sprintf( __( 'No contact order found related with contact id : %1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		$response = [
			'orders' => $orders,
		];

		$this->total_count   = $contact->get_orders_count();
		$this->response_code = 200;
		$success_message     = __( 'Got all contact orders', 'wp-marketing-automations' );

		return $this->success_response( $response, $success_message );
	}

	/**
	 * Helper for get_contact_orders: build order activity records.
	 * (from class-bwfan-api-get-contact-orders.php get_contact_activity_records)
	 */
	public function get_contact_activity_records( $cid, $order_ids, $funnel_id = '' ) {
		if ( ! is_array( $order_ids ) || count( $order_ids ) === 0 ) {
			return [];
		}

		$all_orders = [];
		$sales_data = [];
		foreach ( $order_ids as $order_id ) {
			$conv_order   = $tags = $categories = [];
			$product_data = $this->get_single_order_info( $order_id, $cid );

			$conv_data  = apply_filters( 'wffn_conversion_tracking_data_activity', [], $cid, $order_id );
			$conv_order = array_merge( $conv_order, $conv_data );

			$conv_order['products'] = $product_data['products'];

			/** Get Products Categories name */
			if ( empty( $product_data['cat_ids'] ) ) {
				$categories = array_map( function ( $term_id ) {
					$cat = get_term( absint( $term_id ) );

					return $cat instanceof WP_Term ? $cat->name : false;
				}, array_unique( $product_data['cat_ids'] ) );
				$categories = array_values( array_filter( $categories ) );
			}

			/** Get Products Tags name */
			if ( ! empty( $product_data['tag_ids'] ) ) {
				$tags = array_map( function ( $term_id ) {
					$tag = get_term( absint( $term_id ) );

					return $tag instanceof WP_Term ? $tag->name : false;
				}, array_unique( $product_data['tag_ids'] ) );
				$tags = array_values( array_filter( $tags ) );
			}

			$conv_order['tags']       = $tags;
			$conv_order['categories'] = $categories;
			$sales_data               = array_merge( $sales_data, $product_data['timeline_data'] );
			$funnel_ids               = array_unique( array_column( $conv_order['products'], 'fid' ) );
			$conv_order['order_id']   = $order_id;

			if ( isset( $conv_order['overview'] ) ) {
				unset( $conv_order['overview'] );
			}

			if ( isset( $conv_order['conversion'] ) ) {
				$conv_order['conversion']['funnel_link']  = '';
				$conv_order['conversion']['funnel_title'] = '';
				$s_funnel_id                              = ! empty( $funnel_id ) ? $funnel_id : ( ( is_array( $funnel_ids ) && count( $funnel_ids ) > 0 ) ? $funnel_ids[0] : 0 );
				$conv_order['conversion']['funnel_id']    = $s_funnel_id;

				$get_funnel = new WFFN_Funnel( $s_funnel_id );
				if ( $get_funnel instanceof WFFN_Funnel && 0 !== $get_funnel->get_id() ) {
					$funnel_link                              = ( $get_funnel->get_id() === WFFN_Common::get_store_checkout_id() ) ? admin_url( "admin.php?page=bwf&path=/store-checkout" ) : admin_url( "admin.php?page=bwf&path=/funnels/$s_funnel_id" );
					$conv_order['conversion']['funnel_link']  = $funnel_link;
					$conv_order['conversion']['funnel_title'] = $get_funnel->get_title();
				}
			}

			$conv_order['customer_info'] = array(
				'email'            => '',
				'phone'            => '',
				'billing_address'  => '',
				'shipping_address' => '',
			);
			$conv_order['coupons']       = [];

			if ( ! empty( $order_id ) && absint( $order_id ) > 0 && function_exists( 'wc_get_order' ) ) {
				$order_data = wc_get_order( $order_id );
				if ( $order_data instanceof WC_Order ) {
					$conv_order['coupons']       = $order_data->get_coupon_codes();
					$conv_order['currency']      = BWFAN_Automations::get_currency( $order_data->get_currency() );
					$conv_order['customer_info'] = [
						'email'            => $order_data->get_billing_email(),
						'phone'            => $order_data->get_billing_phone(),
						'billing_address'  => wp_kses_post( $order_data->get_formatted_billing_address() ),
						'shipping_address' => wp_kses_post( $order_data->get_formatted_shipping_address() ),
						'purchased_on'     => ! empty( $order_data->get_date_created() ) ? $order_data->get_date_created()->date( 'Y-m-d H:i:s' ) : $product_data['date_added'],
						'payment_method'   => $order_data->get_payment_method_title(),
					];
					$conv_order['status']        = [
						'label' => ( 'trash' === $order_data->get_status() ) ? __( 'Trashed', 'wp-marketing-automations' ) : ucwords( wc_get_order_status_name( $order_data->get_status() ) ),
						'value' => 'wc-' . $order_data->get_status()
					];
				}
			}

			$all_orders[] = $conv_order;
		}


		return $all_orders;
	}

	/**
	 * Helper for get_contact_orders: get currency info.
	 * (from class-bwfan-api-get-contact-orders.php get_currency)
	 */
	public function get_currency( $currency ) {
		$currency        = ! is_null( $currency ) ? $currency : get_option( 'woocommerce_currency' );
		$currency_symbol = get_woocommerce_currency_symbol( $currency );

		return [
			'code'              => $currency,
			'precision'         => wc_get_price_decimals(),
			'symbol'            => html_entity_decode( $currency_symbol, ENT_QUOTES | ENT_HTML401 ),
			'symbolPosition'    => get_option( 'woocommerce_currency_pos' ),
			'decimalSeparator'  => wc_get_price_decimal_separator(),
			'thousandSeparator' => wc_get_price_thousand_separator(),
			'priceFormat'       => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES | ENT_HTML401 ),
		];
	}

	/**
	 * Helper for get_contact_orders: get single order info.
	 * (from class-bwfan-api-get-contact-orders.php get_single_order_info)
	 */
	public function get_single_order_info( $order_id, $cid = '' ) {

		$timeline_data = [];
		$products      = [];
		$data          = [
			'products'      => $products,
			'date_added'    => '',
			'timeline_data' => $timeline_data,
			'tag_ids'       => [],
			'cat_ids'       => [],
		];

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return $data;
		}

		$items    = $order->get_items();
		$subtotal = 0;
		$i        = 0;
		foreach ( $items as $item ) {
			$product       = new stdClass();
			$key           = 'checkout';
			$product->date = '';

			/**
			 * create data for show timeline data
			 */
			if ( class_exists( 'WFACP_Contacts_Analytics' ) && ! empty( $cid ) && 0 === $i ) {
				/*
				 * show checkout in timeline only one time per order
				 */
				$aero_obj         = WFACP_Contacts_Analytics::get_instance();
				$checkout_records = $aero_obj->get_contacts_revenue_records( $cid, $order_id );
				if ( is_array( $checkout_records ) && ! isset( $checkout_records['db_error'] ) && isset( $checkout_records[0] ) ) {
					$data['date_added']      = $checkout_records[0]->date;
					$data['timeline_data'][] = $checkout_records[0];
				}

			}
			if ( 'yes' === $item->get_meta( '_upstroke_purchase' ) ) {
				$key = 'upsell';
				if ( class_exists( 'WFOCU_Contacts_Analytics' ) ) {
					$upsell_obj     = WFOCU_Contacts_Analytics::get_instance();
					$upsell_records = $upsell_obj->get_contacts_revenue_records( $cid, $order_id );

					if ( is_array( $upsell_records ) && ! isset( $upsell_records['db_error'] ) && isset( $upsell_records[0] ) ) {
						$data['date_added']      = empty( $data['date_added'] ) ? $upsell_records[0]->date : $data['date_added'];
						$data['timeline_data'][] = $upsell_records[0];

					}

				}
			}
			if ( 'yes' === $item->get_meta( '_bump_purchase' ) ) {
				$key = 'bump';
				if ( class_exists( 'WFOB_Contacts_Analytics' ) ) {
					$bump_obj     = WFOB_Contacts_Analytics::get_instance();
					$bump_records = $bump_obj->get_contacts_revenue_records( $cid, $order_id );

					if ( is_array( $bump_records ) && ! isset( $bump_records['db_error'] ) && isset( $bump_records[0] ) ) {
						$data['date_added']      = empty( $data['date_added'] ) ? $bump_records[0]->date : $data['date_added'];
						$data['timeline_data'][] = $bump_records[0];
					}
				}
			}

			$sub_total          = $item->get_subtotal();
			$product->name      = $item->get_name();
			$product->revenue   = $sub_total;
			$product->type      = $key;
			$data['products'][] = $product;
			$subtotal           += $sub_total;
			$i ++;

			/** Fetch tags and categories */
			$item_data       = $item->get_data();
			$wc_product      = intval( $item_data['product_id'] ) ? wc_get_product( $item_data['product_id'] ) : '';
			$tag_ids         = $wc_product instanceof WC_Product ? $wc_product->get_tag_ids() : [];
			$cat_ids         = $wc_product instanceof WC_Product ? $wc_product->get_category_ids() : [];
			$data['tag_ids'] = array_merge( $data['tag_ids'], $tag_ids );
			$data['cat_ids'] = array_merge( $data['cat_ids'], $cat_ids );
		}
		$order_total      = $order->get_total();
		$total_discount   = $order->get_total_discount();
		$remaining_amount = $order_total - ( $subtotal - $total_discount );
		if ( $remaining_amount > 0 ) {
			$shipping_tax          = new stdClass();
			$shipping_tax->name    = __( 'Including shipping and taxes ,other costs', 'wp-marketing-automations' );
			$shipping_tax->revenue = round( $remaining_amount, 2 );
			$shipping_tax->type    = 'shipping';
			$data['products'][]    = $shipping_tax;
		}
		if ( $order->get_total_discount() > 0 ) {
			$discount           = new stdClass();
			$discount->name     = __( 'Discount', 'wp-marketing-automations' );
			$discount->revenue  = $order->get_total_discount();
			$discount->type     = 'discount';
			$data['products'][] = $discount;
		}

		return $data;
	}

	// =========================================================================
	// 15. v3/contacts/{id}/sendmessage — POST (from class-bwfan-api-sendmessage.php process_api_call)
	// =========================================================================
	public function send_message() {
		$contact_id = $this->get_sanitized_arg( 'contact_id', 'text_field' );
		$title      = $this->get_sanitized_arg( 'title', 'text_field' );
		$message    = $this->args['message'];
		$type       = $this->get_sanitized_arg( 'type', 'text_field' );

		if ( BWFAN_Common::is_pro_3_0() ) {
			$this->conversation = BWFAN_Core()->conversation;
		} else {
			$this->conversation = BWFCRM_Core()->conversation;
		}

		$contact = new BWFCRM_Contact( absint( $contact_id ) );
		if ( ! $contact->is_contact_exists() ) {
			$this->response_code = 400;

			/* translators: 1: Contact ID */

			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $contact_id ) );
		}

		/** Check if contacts status is bounced */
		if ( 4 === $contact->get_display_status() ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Contact status is bounced', 'wp-marketing-automations' ) );
		}

		if ( 'email' === $type && empty( $title ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Title is mandatory', 'wp-marketing-automations' ) );
		}

		if ( empty( $message ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Message is mandatory', 'wp-marketing-automations' ) );
		}

		$author_id = get_current_user_id();

		$mode                                = BWFAN_Email_Conversations::$MODE_EMAIL;
		$this->conversation->engagement_type = BWFAN_Email_Conversations::$TYPE_EMAIL;
		switch ( $type ) {
			case 'sms':
				$mode                                = BWFAN_Email_Conversations::$MODE_SMS;
				$this->conversation->engagement_type = BWFAN_Email_Conversations::$TYPE_SMS;
				break;
			case 'whatsapp':
				$mode = BWFAN_Email_Conversations::$MODE_WHATSAPP;
				break;
			case 'push-notification':
				$mode = BWFAN_Email_Conversations::$MODE_NOTIFICATION;
				break;
		}

		BWFAN_Merge_Tag_Loader::set_data( array(
			'contact_id'   => $contact->get_id(),
			'contact_mode' => $mode
		) );
		$title        = BWFAN_Common::decode_merge_tags( $title );
		$message      = BWFAN_Common::decode_merge_tags( ( 1 === $mode ? wpautop( $message ) : $message ) );
		$template     = [
			'subject'  => ( empty( $title ) ? '' : $title ),
			'template' => ( empty( $message ) ? '' : $message )
		];
		$message_data = [];
		if ( BWFAN_Email_Conversations::$MODE_NOTIFICATION === $mode ) {
			$url = $this->get_sanitized_arg( 'url', 'text_field' );
			if ( ! empty( $url ) ) {
				$template['url']                  = $url;
				$message_data['notification_url'] = $url;
			}
			$image_url = $this->get_sanitized_arg( 'image_url', 'text_field' );
			if ( ! empty( $image_url ) ) {
				$template['image_url']              = $image_url;
				$message_data['notification_image'] = $image_url;
			}
		}


		$conversation = $this->conversation->create_campaign_conversation( $contact, 0, 0, $author_id, $mode, true, $template, BWFAN_Email_Conversations::$MODE_NOTIFICATION === $mode ? 8 : 0 );

		if ( empty( $conversation['conversation_id'] ) ) {
			return $this->error_response( $conversation );
		}

		if ( class_exists( 'BWFAN_Message' ) ) {
			$message_obj = new BWFAN_Message();
			$message_obj->set_message( 0, $conversation['conversation_id'], $title, $message );
			$message_obj->set_data( $message_data );
			$messageid = $message_obj->save();
			if ( $messageid && property_exists( BWFAN_Core()->conversation, 'template_id' ) ) {
				/** Save the message id in conversation */
				BWFAN_Core()->conversation->template_id = $messageid;
			}
		}

		$conversation['template'] = $message;
		$conversation['subject']  = $title;

		if ( 'sms' === $type ) {
			$sent = $this->send_single_sms( $conversation, $contact );
		} else if ( 'whatsapp' === $type ) {
			$sent = $this->send_single_whatsapp_message( $conversation, $contact );
		} else if ( 'push-notification' === $type ) {
			$sent = $this->send_single_push_notification( $conversation, $contact, $template );

		} else {
			$sent = $this->send_single_email( $title, $conversation, $contact );
		}

		if ( true === $sent ) {
			return $this->success_response( [], __( 'Message sent', 'wp-marketing-automations' ) );
		}

		return $this->error_response( $sent, null, 500 );
	}

	/**
	 * Send Single Push Notification
	 * (from class-bwfan-api-sendmessage.php send_single_push_notification)
	 */
	public function send_single_push_notification( $conversation, $contact, $template ) {
		if ( ! bwfan_is_autonami_pro_active() ) {
			return __( 'FunnelKit automations Pro is not active ', 'wp-marketing-automations' );
		}

		$contact_id           = $contact->contact->get_id();
		$notification_message = $this->conversation->prepare_notification_data( $conversation['conversation_id'], $contact_id, $conversation['hash_code'], $conversation['template'] );

		/** Append tracking code in url */
		$notification_url = $this->conversation->prepare_notification_data( $conversation['conversation_id'], $contact_id, $conversation['hash_code'], $template['url'] );

		$data = [
			'contact_id'           => $contact_id,
			'notification_message' => $notification_message,
			'notification_url'     => $notification_url,
			'notification_title'   => $template['subject'],
			'notification_image'   => isset( $template['image_url'] ) ? $template['image_url'] : '',
		];

		$res = $this->send_notification( $data );

		if ( $res instanceof WP_Error ) {
			$this->conversation->fail_the_conversation( $conversation['conversation_id'], $res->get_error_message() );

			return $res->get_error_message();
		}

		/** Save the date of last sent engagement **/
		$data = array( 'cid' => $contact_id );
		$this->conversation::save_last_sent_engagement( $data );

		$this->conversation->update_conversation_status( $conversation['conversation_id'], BWFAN_Email_Conversations::$STATUS_SEND );

		return true;
	}

	/**
	 * @param $title
	 * @param $conversation
	 * @param $contact BWFCRM_Contact
	 *
	 * @return string|true|null
	 * (from class-bwfan-api-sendmessage.php send_single_email)
	 */
	public function send_single_email( $title, $conversation, $contact ) {
		$conversation_id = $conversation['conversation_id'];
		$contact_id      = $contact->contact->get_id();

		$email_subject = $this->prepare_email_subject( $title, $contact_id );
		try {
			$email_body = $this->conversation->prepare_email_body( $conversation['conversation_id'], $contact_id, $conversation['hash_code'], 'rich', $conversation['template'] );
		} catch ( Error $e ) {
			$this->conversation->fail_the_conversation( $conversation_id, $e->getMessage() );

			return $e->getMessage();
		}
		if ( is_wp_error( $email_body ) ) {
			$this->conversation->fail_the_conversation( $conversation_id, $email_body->get_error_message() );

			return $email_body->get_error_message();
		}

		$to = $contact->contact->get_email();
		if ( ! is_email( $to ) ) {
			$message = __( 'No email found for this contact: #', 'wp-marketing-automations' ) . $contact_id;
			$this->conversation->fail_the_conversation( $conversation_id, $message );

			return $message;
		}

		$global_email_settings = BWFAN_Common::get_global_settings();

		$headers = array(
			'MIME-Version: 1.0',
			'Content-type: text/html;charset=UTF-8'
		);

		$from = '';
		if ( isset( $global_email_settings['bwfan_email_from_name'] ) && ! empty( $global_email_settings['bwfan_email_from_name'] ) ) {
			$from = 'From: ' . $global_email_settings['bwfan_email_from_name'];
		}

		if ( isset( $global_email_settings['bwfan_email_from'] ) && ! empty( $global_email_settings['bwfan_email_from'] ) ) {

			$headers[] = $from . ' <' . $global_email_settings['bwfan_email_from'] . '>';
		}

		if ( isset( $global_email_settings['bwfan_email_reply_to'] ) && ! empty( $global_email_settings['bwfan_email_reply_to'] ) ) {
			$reply_to_from_name = isset( $global_email_settings['bwfan_email_from_name'] ) ? $global_email_settings['bwfan_email_from_name'] : '';
			$headers[]          = BWFAN_Common::build_reply_to_header( $global_email_settings['bwfan_email_reply_to'], '', $reply_to_from_name );
		}

		/** Set unsubscribe link in header */
		$unsubscribe_link = BWFAN_Common::get_unsubscribe_link( [ 'uid' => $contact->contact->get_uid() ] );
		if ( ! empty( $unsubscribe_link ) ) {
			$headers[] = "List-Unsubscribe: <$unsubscribe_link>";
			$headers[] = "List-Unsubscribe-Post: List-Unsubscribe=One-Click";
		}

		$headers = apply_filters( 'bwfan_email_headers', $headers );

		BWFAN_Common::bwf_remove_filter_before_wp_mail();
		$result = wp_mail( $to, $email_subject, $email_body, $headers );
		if ( true === $result ) {
			/** Save the time of last sent engagement **/
			$data = array( 'cid' => $contact_id );
			$this->conversation::save_last_sent_engagement( $data );

			$this->conversation->update_conversation_status( $conversation_id, BWFAN_Email_Conversations::$STATUS_SEND );
		} else {
			$this->conversation->fail_the_conversation( $conversation_id, __( 'Email not sent', 'wp-marketing-automations' ) );

			return __( 'Email not sent', 'wp-marketing-automations' );
		}

		return $result;
	}

	/**
	 * Send Single Sms
	 * (from class-bwfan-api-sendmessage.php send_single_sms)
	 */
	public function send_single_sms( $conversation, $contact ) {
		if ( ! bwfan_is_autonami_pro_active() ) {
			return __( 'FunnelKit automations Pro is not active ', 'wp-marketing-automations' );
		}

		$conversation_id = $conversation['conversation_id'];
		$contact_id      = $contact->contact->get_id();
		$sms_body        = $this->conversation->prepare_sms_body( $conversation['conversation_id'], $contact_id, $conversation['hash_code'], $conversation['template'] );

		if ( is_wp_error( $sms_body ) ) {
			$this->conversation->fail_the_conversation( $conversation_id, $sms_body->get_error_message() );

			return $sms_body->get_error_message();
		}

		$to = BWFAN_Common::get_contact_full_number( $contact->contact );

		if ( empty( $to ) ) {
			$message = __( 'No phone number found for this contact: #', 'wp-marketing-automations' ) . $contact_id;
			$this->conversation->fail_the_conversation( $conversation_id, $message );

			return $message;
		}

		$send_sms_result = BWFCRM_Common::send_sms( array(
			'to'   => $to,
			'body' => $sms_body,
		) );

		if ( $send_sms_result instanceof WP_Error ) {
			$this->conversation->fail_the_conversation( $conversation_id, $send_sms_result->get_error_message() );

			return $send_sms_result->get_error_message();
		}

		/** Save the date of last sent engagement **/
		$data = array( 'cid' => $contact_id );
		$this->conversation::save_last_sent_engagement( $data );

		$this->conversation->update_conversation_status( $conversation_id, BWFAN_Email_Conversations::$STATUS_SEND );

		return true;
	}

	/**
	 * Send Single Whatsapp Message
	 * (from class-bwfan-api-sendmessage.php send_single_whatsapp_message)
	 */
	public function send_single_whatsapp_message( $conversation, $contact ) {
		if ( ! bwfan_is_autonami_pro_active() ) {
			return __( 'FunnelKit automations Pro is not active ', 'wp-marketing-automations' );
		}
		$conversation_id = $conversation['conversation_id'];
		$contact_id      = $contact->contact->get_id();
		$message_body    = $this->conversation->prepare_sms_body( $conversation['conversation_id'], $contact_id, $conversation['hash_code'], $conversation['template'] );

		if ( is_wp_error( $message_body ) ) {
			$this->conversation->fail_the_conversation( $conversation_id, $message_body->get_error_message() );

			return $message_body->get_error_message();
		}

		$to = BWFAN_Common::get_contact_full_number( $contact->contact );

		if ( empty( $to ) ) {
			$message = __( 'No phone number found for this contact: #', 'wp-marketing-automations' ) . $contact_id;
			$this->conversation->fail_the_conversation( $conversation_id, $message );

			return $message;
		}

		$response = $this->conversation->send_whatsapp_message( $to, array(
			array(
				'type' => 'text',
				'data' => $message_body,
			)
		) );

		if ( is_array( $response ) && isset( $response['status'] ) && $response['status'] == true ) {
			/** Save the time of last sent engagement **/
			$data = array( 'cid' => $contact_id );
			$this->conversation::save_last_sent_engagement( $data );
			$this->conversation->update_conversation_status( $conversation_id, BWFAN_Email_Conversations::$STATUS_SEND );

			return true;
		}
		$error_message = $this->get_error_message_from_response( $response );
		$this->conversation->fail_the_conversation( $conversation_id, $error_message );
		return $error_message;
	}

	/**
	 * Extract error message from API response
	 * (from class-bwfan-api-sendmessage.php get_error_message_from_response)
	 *
	 * @param mixed $response
	 *
	 * @return string
	 */
	private function get_error_message_from_response( $response ) {
		$default_error = __( 'Unable to send message', 'wp-marketing-automations' );

		if ( ! is_array( $response ) ) {
			return $default_error;
		}

		// Check for error message in different response formats
		$error_keys = [ 'msg', 'message', 'error', 'error_message' ];
		foreach ( $error_keys as $key ) {
			if ( isset( $response[ $key ] ) && ! empty( $response[ $key ] ) ) {
				return $response[ $key ];
			}
		}

		return $default_error;
	}

	/**
	 * Prepare Email Subject
	 * (from class-bwfan-api-sendmessage.php prepare_email_subject)
	 */
	public function prepare_email_subject( $subject, $contact_id ) {
		BWFAN_Merge_Tag_Loader::set_data( array(
			'contact_id'   => $contact_id,
			'contact_mode' => 1
		) );

		return BWFAN_Common::decode_merge_tags( $subject );
	}

	/**
	 * Send Notification
	 * (from class-bwfan-api-sendmessage.php send_notification)
	 */
	public function send_notification( $args ) {
		// fetching provider for test sms
		$provider = isset( $args['push_provider'] ) ? $args['push_provider'] : '';

		if ( empty( $provider ) ) {
			$global_settings = BWFAN_Common::get_global_settings();
			$provider        = isset( $global_settings['bwfan_push_service'] ) && ! empty( $global_settings['bwfan_push_service'] ) ? $global_settings['bwfan_push_service'] : BWFAN_Common::get_default_push_provider();
		}

		/** If no provider selected OR selected provider is not within active providers, then select default provider */
		if ( empty( $provider ) || ! isset( $active_services[ $provider ] ) ) {
			$provider = BWFAN_Common::get_default_push_provider();
		}

		$provider = explode( 'bwfco_', $provider );
		$provider = isset( $provider[1] ) ? $provider[1] : '';
		$provider = ! empty( $provider ) ? BWFAN_Core()->integration->get_integration( $provider ) : '';
		if ( ! $provider instanceof BWFAN_Integration || ! method_exists( $provider, 'send_message' ) ) {
			return new WP_Error( 'connector_not_found', __( 'Connector Integration not found', 'wp-marketing-automations' ) );
		}

		return $provider->send_message( $args );
	}

	// =========================================================================
	// 16. /contact/{id}/resync-order — GET (from class-bwfan-api-update-contact-order.php process_api_call)
	// =========================================================================
	public function resync_contact_order() {
		$cid = $this->get_sanitized_arg( 'contact_id', 'key' );

		//Check contact id is founded
		if ( empty( $cid ) ) {
			$this->error_response( __( 'Contact ID no found', 'wp-marketing-automations' ), '', 404 );
		}

		// Check if contact exist
		$contact = new BWFCRM_Contact( $cid );
		if ( ! $contact->is_contact_exists() ) {
			return $this->error_response( __( 'No Contact found', 'wp-marketing-automations' ), '', 404 );
		}

		// Check for dependencies
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return $this->error_response( __( 'Dependencies Missing', 'wp-marketing-automations' ), '', 404 );
		}

		$args = [ 'cid' => $cid ];
		$hook = 'bwf_reindex_contact_orders';

		/**
		 * Stale completion sentinel: '-1' with no cleanup action queued (AS
		 * trimmed/failed). Reset the cursor options only — actionscheduler rows are
		 * never touched from an HTTP request — so the inline loop below runs a real
		 * reindex now instead of reporting instant success. Leftover recurring
		 * occurrences finish via the normal '-1' + end-action flow after this run.
		 * GET_LOCK guard: skip the reset while a reindex pass is executing.
		 */
		if ( intval( get_option( "bwf_contact_orders_{$cid}", 0 ) ) < 0 && ! as_has_scheduled_action( 'bwf_reindex_contact_orders_end', $args, 'funnelkit' ) ) {
			global $wpdb;
			$lock_name = "bwf_reindex_{$cid}";
			$got_lock  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			if ( '1' === (string) $got_lock ) {
				delete_option( "bwf_contact_orders_{$cid}" );
				delete_option( "bwf_contact_orders_runs_{$cid}" );
				$bwf_contact = new WooFunnels_Contact( '', '', '', $cid );
				$bwf_contact->delete_meta( 'processed_order_ids' );
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			}
		}

		// If already scheduled
		if ( as_has_scheduled_action( $hook, $args, 'funnelkit' ) ) {
			return $this->success_response( [], __( 'Action already scheduled for the user', 'wp-marketing-automations' ) );
		}

		// Start the indexing for 10 seconds
		$start_time = time();
		$ins        = WooFunnels_DB_Updater::get_instance();
		$batches    = 0;

		/**
		 * Hard cap independent of the time budget. Each batch is 10 orders via a fast, indexed
		 * lookup, so on a contact with a very large order history this loop can run through far
		 * more batches than 10s of wall clock implies - accumulating WC_Order objects in the
		 * request-lifetime cache with no ceiling, since nothing here ever flushes it. That's a
		 * fatal (memory or execution-time kill) with no log line, since a hard kill never reaches
		 * PHP's own fatal-error handler. Once the cap is hit $response is still 0 (not complete),
		 * so the existing fallback below schedules the recurring background action for the
		 * remainder - same outcome as hitting the time budget, just reached sooner for these
		 * contacts instead of crashing the request.
		 */
		$max_batches = (int) apply_filters( 'bwf_resync_contact_order_max_batches', 10, $cid );

		do {
			$response = $ins->bwf_reindex_contact_orders( $cid );
			$batches ++;
			if ( 1 === intval( $response ) || false === $response ) {
				break;
			}
		} while ( ( time() - $start_time ) < 10 && $batches < $max_batches );

		switch ( $response ) {
			case 0:
			case '0': // If not completed in 10 seconds, schedule the action
				$schedule_id = as_schedule_recurring_action( time(), 60, $hook, $args, 'funnelkit' );

				// Unable to schedule action
				if ( ! $schedule_id ) {
					return $this->error_response( __( 'Unable to schedule action for syncing.', 'wp-marketing-automations' ), '', 404 );
				}

				return $this->success_response( [
					'schedule_id' => $schedule_id,
				], __( 'Syncing order has been started, data will be update in a while.', 'wp-marketing-automations' ) );
			case 1:
			case '1': // Completed successfully
				break;
			default: // Unknown error
				return $this->error_response( __( 'Unknown error occurred.', 'wp-marketing-automations' ), '', 404 );
		}

		// Reload contact fresh from DB to get updated customer data
		$contact     = new BWFCRM_Contact( $cid );
		$new_contact = $contact->get_customer_as_array();

		return $this->success_response( [
			'wc' => $new_contact,
		], __( 'Contact orders synced successfully', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// 17. v3/contacts/{id}/engagement/{engagement_id} — GET (from class-bwfan-get-conversation.php process_api_call)
	// =========================================================================
	public function get_conversation() {
		/** checking if id or email present in params */
		$id            = $this->get_sanitized_arg( 'contact_id', 'key' );
		$engagement_id = $this->get_sanitized_arg( 'engagement_id', 'key' );
		if ( ! class_exists( 'BWFAN_Email_Conversations' ) || ! isset( BWFAN_Core()->conversations ) || ! BWFAN_Core()->conversations instanceof BWFAN_Email_Conversations ) {
			return $this->error_response( __( 'Unable to find Funnelkit Automations message tracking module', 'wp-marketing-automations' ), null, 500 );
		}

		$this->contact = new BWFCRM_Contact( absint( $id ) );
		if ( ! $this->contact instanceof BWFCRM_Contact || ! $this->contact->is_contact_exists() ) {
			/* translators: 1: Contact ID */
			return $this->error_response( sprintf( __( 'No contact found with given id #%1$d', 'wp-marketing-automations' ), $id ), null, 500 );
		}

		$conversation = $this->contact->get_conversation_email( $engagement_id );
		if ( $conversation instanceof WP_Error || ( is_array( $conversation ) && isset( $conversation['error'] ) ) ) {
			return $this->error_response( ! is_array( $conversation ) ? __( 'Unknown error occurred', 'wp-marketing-automations' ) : $conversation['error'], $conversation instanceof WP_Error ? $conversation : null, 500 );
		}

		return $this->success_response( $conversation );
	}

	// =========================================================================
	// 18. v3/contacts/{id}/engagements — GET (from class-bwfan-get-conversations.php process_api_call)
	// =========================================================================
	public function get_conversations() {
		/** checking if id or email present in params **/
		$id         = $this->get_sanitized_arg( 'contact_id', 'key' );
		$this->mode = $this->get_sanitized_arg( 'mode', 'key' );
		$limit      = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;
		$offset     = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? $this->get_sanitized_arg( 'offset', 'absint' ) : 0;

		if ( ! class_exists( 'BWFAN_Email_Conversations' ) || ! isset( BWFAN_Core()->conversations ) || ! BWFAN_Core()->conversations instanceof BWFAN_Email_Conversations ) {
			return $this->error_response( __( 'Unable to find conversations module', 'wp-marketing-automations' ), null, 500 );
		}

		$this->contact = new BWFCRM_Contact( absint( $id ) );

		if ( ! $this->contact instanceof BWFCRM_Contact || ! $this->contact->is_contact_exists() ) {
			return $this->error_response( __( 'Contact doesn\'t exists', 'wp-marketing-automations' ), null, 500 );
		}

		$conversation = $this->contact->get_conversations( $this->mode, $offset, $limit );
		if ( $conversation instanceof WP_Error ) {
			return $this->error_response( '', $conversation, 500 );
		}

		return $this->success_response( $conversation );
	}

	// =========================================================================
	// Helper: get_unopened_broadcast_contacts (from class-bwfan-api-contacts.php)
	// =========================================================================
	private function get_unopened_broadcast_contacts( $broadcast_id, $additional_info ) {
		$additional_info['offset'] = $this->pagination->offset;
		$additional_info['limit']  = $this->pagination->limit;

		$contacts = BWFCRM_Core()->campaigns->get_unopen_broadcast_contacts( absint( $broadcast_id ), $additional_info );

		if ( ! is_array( $contacts ) ) {
			$this->response_code = 500;

			return $this->error_response( is_string( $contacts ) ? $contacts : __( 'Unknown error occurred', 'wp-marketing-automations' ) );
		}

		if ( isset( $contacts['total_count'] ) ) {
			$this->total_count = absint( $contacts['total_count'] );
		}

		if ( ! isset( $contacts['contacts'] ) || empty( $contacts['contacts'] ) ) {
			return $this->success_response( array() );
		}

		$this->response_code = 200;

		return $this->success_response( $contacts['contacts'] );
	}

	// =========================================================================
	// Result helpers (for pagination responses)
	// =========================================================================
	public function get_result_total_count() {
		return $this->total_count;
	}

	public function get_result_count_data() {
		return $this->count_data;
	}
}

BWFAN_API_Contacts_Controller::get_instance();
