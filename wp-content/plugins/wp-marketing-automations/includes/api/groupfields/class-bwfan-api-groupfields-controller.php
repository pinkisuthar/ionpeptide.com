<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Groupfields_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $fields = array();
	public $count_data = [];

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		$this->pagination         = new stdClass();
		$this->pagination->offset = 0;
		$this->pagination->limit  = 25;
		parent::__construct();
	}

	public function default_args_values() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		$args = array(
			'group_id'      => '',
			'group_name'    => '',
			'move_to_group' => '',
			'move_group_id' => '',
			'field_sort'    => [],
		);

		return $args;
	}

	protected function register_routes() {
		// GET + POST /v3/groupfields
		$this->add_route( '/v3/groupfields', array(
			array( 'GET', 'get_groups_with_fields' ),
			array( 'POST', 'create_group' ),
		) );

		// GET + PUT + DELETE /v3/groupfields/{group_id}
		$this->add_route( '/v3/groupfields/(?P<group_id>[\\d]+)', array(
			array( 'GET', 'get_group_fields', array(
				'group_id' => array(
					'description' => __( 'Group ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
			array( 'PUT', 'update_group' ),
			array( 'DELETE', 'delete_group' ),
		) );

		// PUT /v3/groupfields/move
		$this->add_route( '/v3/groupfields/move', array(
			array( 'PUT', 'move_fields_to_group' ),
		) );

		// POST /v3/groupfields/sort
		$this->add_route( '/v3/groupfields/sort', array(
			array( 'POST', 'sort_group_fields' ),
		) );
	}

	/**
	 * GET /v3/groupfields — get all groups with fields
	 */
	public function get_groups_with_fields() {
		$response = '';
		try {
			$fields = BWFCRM_Fields::get_groups_with_fields( false, true, true );
		} catch ( Error $e ) {
			$response = $e->getMessage();
		}
		if ( empty( $fields ) ) {
			$this->response_code = 500;
			$response            = empty( $response ) ? __( "No fields found", "wp-marketing-automations" ) : $response;

			return $this->error_response( $response );
		}

		$this->fields     = $fields;
		$address_fields   = BWFCRM_Fields::get_address_fields_from_db();
		$final_result     = array(
			'fields'       => $this->fields,
			'extra_fields' => $address_fields
		);
		$this->count_data = BWFAN_Common::get_contact_data_counts();

		return $this->success_response( $final_result, __( 'Got ALL Groups with Fields', 'wp-marketing-automations' ) );
	}

	/**
	 * POST /v3/groupfields — create a group
	 */
	public function create_group() {
		$group_name = $this->get_sanitized_arg( 'group_name', 'text_field' );

		if ( empty( $group_name ) ) {
			$this->response_code = 400;

			return $this->error_response( __( "Required group missing", "wp-marketing-automations" ) );
		}

		$group = BWFCRM_Group::add_group( $group_name );

		$response = __( 'Field group created', 'wp-marketing-automations' );

		return $this->success_response( $group, $response );
	}

	/**
	 * GET /v3/groupfields/{group_id} — get group fields
	 */
	public function get_group_fields() {
		$group_id = $this->get_sanitized_arg( 'group_id', 'text_field' );
		$fields   = BWFCRM_Fields::get_group_fields( $group_id );

		if ( empty( $fields ) ) {
			$this->response_code = 404;
			$response            = __( "No fields found", "wp-marketing-automations" );

			return $this->error_response( $response );
		}
		$this->fields = $fields;

		return $this->success_response( $fields, __( 'Got All Group\'s Fields', 'wp-marketing-automations' ) );
	}

	/**
	 * PUT /v3/groupfields/{group_id} — update group
	 */
	public function update_group() {

		$group_id = $this->get_sanitized_arg( 'group_id', 'text_field' );

		if ( empty( $group_id ) ) {
			$this->response_code = 400;
			$response            = __( "Group Id is missing", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$group_name = $this->get_sanitized_arg( 'group_name', 'text_field' );

		if ( empty( $group_name ) ) {
			$this->response_code = 400;
			$response            = __( "Group name is missing", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$data = array(
			'name' => $group_name,
		);

		$where = array(
			'ID' => $group_id,
		);

		$update_group = BWFAN_Model_Field_Groups::update( $data, $where );

		if ( 0 === $update_group ) {

			$this->response_code = 400;

			/* translators: 1: Group ID */

			return $this->error_response( sprintf( __( 'Unable to update group with group id %1$d', 'wp-marketing-automations' ), $group_id ) );
		}
		$group  = BWFCRM_Group::get_groupby_id( $group_id );
		$fields = BWFCRM_Fields::get_group_fields( $group_id );
		if ( ! empty( $fields ) ) {
			$group[0]['fields'] = $fields['fields'];
		}

		return $this->success_response( $group, __( 'Field group updated', 'wp-marketing-automations' ) );
	}

	/**
	 * DELETE /v3/groupfields/{group_id} — delete group
	 */
	public function delete_group() {

		$group_id      = $this->get_sanitized_arg( 'group_id', 'text_field' );
		$move_group_id = $this->get_sanitized_arg( 'move_to_group', 'text_field' );
		$move_group_id = ! empty( $move_group_id ) ? $move_group_id : 0;

		if ( empty( $group_id ) ) {
			$this->response_code = 400;
			$response            = __( "Group Id is missing", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		BWFCRM_Fields::field_move_to_group( $group_id, $move_group_id );

		$delete_group = BWFAN_Model_Field_Groups::delete( $group_id );


		if ( 0 === $delete_group ) {

			$this->response_code = 400;

			/* translators: 1: Group ID */

			return $this->error_response( sprintf( __( 'Unable to delete group with group id %1$d', 'wp-marketing-automations' ), $group_id ) );
		}

		return $this->success_response( __( 'Field group deleted', 'wp-marketing-automations' ) );
	}

	/**
	 * PUT /v3/groupfields/move
	 */
	public function move_fields_to_group() {

		$group_id      = $this->get_sanitized_arg( 'group_id', 'text_field' );
		$move_group_id = $this->get_sanitized_arg( 'move_group_id', 'text_field' );

		if ( ! isset( $group_id ) ) {
			$this->response_code = 400;
			$response            = __( "Group Id is missing", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		if ( ! isset( $move_group_id ) ) {
			$this->response_code = 400;
			$response            = __( "Move Group Id is missing", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$moved = BWFCRM_Fields::field_move_to_group( $group_id, $move_group_id );

		if ( 0 === $moved ) {

			$this->response_code = 400;

			return $this->error_response( __( 'Unable to move the field to new group', 'wp-marketing-automations' ) );
		}

		return $this->success_response( __( 'Field updated', 'wp-marketing-automations' ) );
	}

	/**
	 * POST /v3/groupfields/sort
	 */
	public function sort_group_fields() {

		if ( empty( $this->args['field_sort'] ) ) {
			$this->response_code = 404;
			$response            = __( "Required parameter is missing.", "wp-marketing-automations" );

			return $this->error_response( $response );
		}
		update_option( 'bwf_crm_field_sort', $this->args['field_sort'] );

		return $this->success_response( $this->args['field_sort'], __( 'Fields order updated', 'wp-marketing-automations' ) );
	}

	public function get_result_total_count() {
		return count( $this->fields );
	}

	public function get_result_count_data() {
		return $this->count_data;
	}
}

BWFAN_API_Groupfields_Controller::get_instance();
