<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Lists_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $total_count = 0;
	public $count_data = [];
	public $lists = array();

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		parent::__construct();
		// Override the base default (0); set after parent so it is not reset.
		$this->pagination->limit = 50;
	}

	public function default_args_values() {
		return array(
			's'           => '',
			'lists'       => array(),
			'list_id'     => '',
			'list_name'   => '',
			'name'        => '',
			'description' => '',
			'list_ids'    => [],
		);
	}

	protected function register_routes() {
		// GET + POST /v3/lists
		$this->add_route( '/v3/lists', array(
			array( 'GET', 'get_lists' ),
			array( 'POST', 'create_lists' ),
		) );

		// PUT + DELETE /v3/lists/{list_id}
		$this->add_route( '/v3/lists/(?P<list_id>[\\d]+)', array(
			array( 'PUT', 'update_list' ),
			array( 'DELETE', 'delete_list' ),
		) );

		// POST /v3/list (create single list)
		$this->add_route( '/v3/list', array(
			array( 'POST', 'create_single_list' ),
		) );

		// GET /v3/lists/contacts
		$this->add_route( '/v3/lists/contacts', array(
			array( 'GET', 'get_list_contacts_count' ),
		) );
	}

	/**
	 * GET /v3/lists — list all lists
	 */
	public function get_lists() {

		$search   = $this->get_sanitized_arg( 'search', 'text_field' );
		$list_ids = empty( $this->args['ids'] ) ? array() : explode( ',', $this->args['ids'] );
		$limit    = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 0;
		$offset   = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? $this->get_sanitized_arg( 'offset', 'absint' ) : 0;

		$list_data = BWFCRM_Lists::get_lists( $list_ids, $search, $offset, $limit, ARRAY_A );

		if ( ! is_array( $list_data ) ) {
			$list_data = array();
		}
		$list_count = BWFAN_Model_Terms::get_terms_count( 2, $search, $list_ids );

		$this->total_count   = $list_count;
		$this->lists         = $list_data;
		$this->response_code = 200;
		$this->count_data    = BWFAN_Common::get_contact_data_counts();

		return $this->success_response( $list_data );
	}

	/**
	 * POST /v3/lists — create list(s)
	 */
	public function create_lists() {
		$lists = $this->get_sanitized_arg( '', 'text_field', $this->args['lists'] );
		/** IN CASE Lists PARAMS ARE MISSING **/
		if ( empty( $lists ) ) {
			$this->response_code = 404;
			$response            = __( 'No list names provided to create', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$list_data = array();

		foreach ( $lists as $key => $list ) {
			if ( ! isset( $list_data[ $key ] ) ) {
				$list_data[ $key ] = [];
			}
			$list_data[ $key ]['id']    = 0;
			$list_data[ $key ]['value'] = $list;
		}

		$lists = BWFCRM_Term::get_or_create_terms( $list_data, BWFCRM_Term_Type::$LIST, true, true );
		if ( is_wp_error( $lists ) ) {
			$this->response_code = 500;

			return $this->error_response( '', $lists );
		}

		if ( ! isset( $lists['existing'] ) || ! isset( $lists['created'] ) ) {
			$this->response_code = 500;

			return $this->error_response( __( 'Some error occurred', 'wp-marketing-automations' ) );
		}

		$existing_lists = BWFCRM_Term::get_collection_array( $lists['existing'] );
		$created_lists  = BWFCRM_Term::get_collection_array( $lists['created'] );
		$all_lists      = array_merge( $created_lists, $existing_lists );

		if ( empty( $created_lists ) ) {
			return $this->success_response( $all_lists, __( 'Given list already exists.', 'wp-marketing-automations' ) );
		}

		return $this->success_response( $all_lists, __( 'New list added', 'wp-marketing-automations' ) );
	}

	/**
	 * PUT /v3/lists/{list_id} — update list
	 */
	public function update_list() {
		$list_id = $this->get_sanitized_arg( 'list_id', 'text_field' );
		if ( empty( $list_id ) ) {
			$this->response_code = 400;
			$response            = __( 'List ID is mandatory', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$list = new BWFCRM_Lists( absint( $list_id ) );
		if ( ! $list->is_exists() ) {
			$this->response_code = 404;
			$response            = __( 'List doesn\'t exists', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$list_name = $this->get_sanitized_arg( 'list_name', 'text_field' );
		if ( empty( $list_name ) ) {
			$this->response_code = 404;
			$response            = __( 'List name is mandatory', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$current_name = $list->get_name();
		if ( $list_name !== $current_name ) {
			/** Check if list is already exists */
			$already_exists = BWFCRM_Tag::get_terms( BWFCRM_Term_Type::$LIST, [], $list_name, 0, 0, ARRAY_A, 'exact' );
			if ( ! empty( $already_exists ) ) {
				$this->response_code = 404;
				/* translators: 1: List name */
				$response = sprintf( __( 'List already exists with name: %1$s', 'wp-marketing-automations' ), $list_name );

				return $this->error_response( $response );
			}

			$list->set_name( $list_name );
		}

		$description = $this->get_sanitized_arg( 'description', 'text_field' );
		$list->set_description( $description );

		if ( empty( $list->save() ) ) {
			return $this->error_response( __( 'Unable to update the list', 'wp-marketing-automations' ), null, 500 );
		}

		return $this->success_response( __( 'List updated', 'wp-marketing-automations' ) );
	}

	/**
	 * DELETE /v3/lists/{list_id} — delete list
	 */
	public function delete_list() {

		$list_id = $this->get_sanitized_arg( 'list_id', 'key' );

		if ( empty( $list_id ) ) {
			$this->response_code = 404;
			$response            = __( 'List ID is mandatory ', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		/** checking if the provided id is list id or not $data */
		$check_list = BWFCRM_Lists::get_terms( BWFCRM_Term_Type::$LIST, array( $list_id ) );

		if ( empty( $check_list ) ) {
			$this->response_code = 404;
			/* translators: 1: List ID */
			$response = sprintf( __( 'List not exist with ID #%1$d', 'wp-marketing-automations' ), $list_id );

			return $this->error_response( $response );
		}

		$delete_list = BWFCRM_Lists::delete_list( absint( $list_id ) );

		if ( false === $delete_list ) {
			$this->response_code = 404;
			/* translators: 1: List ID */
			$response = sprintf( __( 'Unable to delete the list with ID #%1$d', 'wp-marketing-automations' ), $list_id );

			return $this->error_response( $response );
		}

		$this->response_code = 200;
		$success_message     = __( 'List deleted', 'wp-marketing-automations' );

		return $this->success_response( [], $success_message );

	}

	/**
	 * POST /v3/list — create a single list
	 */
	public function create_single_list() {
		$name        = $this->get_sanitized_arg( 'name', 'text_field' );
		$description = $this->get_sanitized_arg( 'description', 'text_field' );
		if ( empty( $name ) ) {
			$this->response_code = 400;
			$response            = __( 'Name is required', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		/** Check if list is already exists */
		$already_exists = BWFCRM_Tag::get_terms( BWFCRM_Term_Type::$LIST, [], $name, 0, 0, ARRAY_A, 'exact' );
		if ( ! empty( $already_exists ) ) {
			$this->response_code = 404;
			/* translators: 1: List name */
			$response = sprintf( __( 'List already exists with name: %1$s', 'wp-marketing-automations' ), $name );

			return $this->error_response( $response );
		}

		$list = new BWFCRM_Lists();
		$list->set_name( $name );
		if ( ! empty( $description ) ) {
			$list->set_description( $description );
		}

		if ( empty( $list->save() ) ) {
			return $this->error_response( __( 'Unable to create new list', 'wp-marketing-automations' ), null, 500 );
		}

		return $this->success_response( $list->get_array(), __( 'List Created', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /v3/lists/contacts — get contact counts per list
	 */
	public function get_list_contacts_count() {

		$list_ids = $this->get_sanitized_arg( '', 'text_field', $this->args['list_ids'] );
		$data     = [];
		if ( empty( $list_ids ) ) {
			return $this->success_response( $data );
		}

		foreach ( $list_ids as $list_id ) {
			if ( ! isset( $data[ $list_id ] ) ) {
				$data[ $list_id ] = [];
			}
			$count_data                            = self::get_contact_count( $list_id );
			$data[ $list_id ]['contact_count']     = $count_data['count'];
			$data[ $list_id ]['customer_count']    = $count_data['customers'];
			$data[ $list_id ]['total_revenue']     = $count_data['revenue'];
			$data[ $list_id ]['subscribers_count'] = self::get_contact_count( $list_id, true );
		}

		$this->response_code = 200;

		return $this->success_response( $data );
	}

	/**
	 * @param $list_id
	 * @param $exclude_unsubs
	 *
	 * @return array|int|int[]
	 */
	public static function get_contact_count( $list_id, $exclude_unsubs = false ) {
		global $wpdb;
		$list_id = '%"' . $wpdb->esc_like( $list_id ) . '"%';
		if ( true === $exclude_unsubs ) {
			$query = "SELECT COUNT(DISTINCT c.id) FROM {$wpdb->prefix}bwf_contact as c   WHERE 1=1 AND ( c.email != '' AND c.email IS NOT NULL ) AND ( c.lists LIKE %s  )";
			$query .= " AND ( c.status = 1 )    AND (   NOT EXISTS   (SELECT 1 FROM {$wpdb->prefix}bwfan_message_unsubscribe AS unsub WHERE c.email = unsub.recipient ) AND   NOT EXISTS  (SELECT 1 FROM {$wpdb->prefix}bwfan_message_unsubscribe AS unsub1 WHERE c.contact_no = unsub1.recipient ))";

			return intval( $wpdb->get_var( $wpdb->prepare( $query, $list_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- literal SQL with prefix tables; list id esc_like()d and bound via prepare()
		}

		$query     = "SELECT COUNT(DISTINCT c.`id`) AS contacts, COUNT(DISTINCT wc.`id`) AS customers, SUM( wc.`total_order_value`) AS total_revenue  FROM {$wpdb->prefix}bwf_contact as c LEFT JOIN {$wpdb->prefix}bwf_wc_customers AS wc ON c.`id`=wc.`cid` WHERE 1=1 AND ( c.email != '' AND c.email IS NOT NULL ) AND ( c.lists LIKE %s  )";
		$customers = $wpdb->get_row( $wpdb->prepare( $query, $list_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- literal SQL with prefix tables; list id esc_like()d and bound via prepare()
		if ( ! empty( $customers ) ) {
			return [
				'count'     => $customers['contacts'],
				'customers' => $customers['customers'],
				'revenue'   => $customers['total_revenue'] ?? 0,
			];
		}

		return [
			'count'     => 0,
			'customers' => 0,
			'revenue'   => 0,
		];
	}

	public function get_result_total_count() {
		return $this->total_count;
	}

	public function get_result_count_data() {
		return $this->count_data;
	}
}

BWFAN_API_Lists_Controller::get_instance();
