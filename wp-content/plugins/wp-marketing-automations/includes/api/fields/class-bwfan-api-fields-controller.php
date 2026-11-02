<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Fields_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $total_count = 0;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		$this->response_code = 200;
		parent::__construct();
	}

	public function default_args_values() {
		return array(
			'group_id'    => '',
			'field'       => [],
			'name'        => '',
			'type'        => '',
			'slug'        => '',
			'options'     => [],
			'placeholder' => '',
			'field_id'    => '',
		);
	}

	protected function register_routes() {
		// GET+POST /v3/fields — list fields / create a field
		$this->add_route( '/v3/fields', array(
			array( 'GET', 'get_fields' ),
			array( 'POST', 'create_field' ),
		) );

		// PUT+DELETE /v3/fields/{id} — update/delete field
		$this->add_route( '/v3/fields/(?P<field_id>[\\d]+)', array(
			array( 'PUT', 'update_field' ),
			array( 'DELETE', 'delete_field' ),
		) );

		// GET /v3/all/fields — get all fields (including system fields)
		$this->add_route( '/v3/all/fields', array(
			array( 'GET', 'get_all_fields' ),
		) );
	}

	/**
	 * GET /v3/fields — list fields
	 */
	public function get_fields() {
		$type              = isset( $this->args['type'] ) ? $this->get_sanitized_arg( 'type', 'text_field', $this->args['type'] ) : null;
		$all_fields        = isset( $this->args['all'] ) ? $this->get_sanitized_arg( 'all', 'text_field', $this->args['all'] ) : false;
		$fields            = BWFCRM_Fields::get_custom_fields( null, $all_fields ? 1 : null, null, $all_fields ? false : true, $all_fields ? null : 1, $type );
		$fields            = method_exists( 'BWFCRM_Fields', 'get_sorted_fields' ) ? BWFCRM_Fields::get_sorted_fields( $fields ) : $fields;
		$this->total_count = count( $fields );

		return $this->success_response( $fields, empty( $fields ) ? __( 'No Fields found.', 'wp-marketing-automations' ) : __( 'Got all fields', 'wp-marketing-automations' ) );
	}

	/**
	 * POST /v3/fields — create a field
	 */
	public function create_field() {

		/**
		 *  getting post data
		 */
		$field    = $this->get_sanitized_arg( '', 'text_field', $this->args['field'] );
		$group_id = $this->get_sanitized_arg( 'group_id', 'text_field' );
		$group_id = ! empty( $group_id ) && is_numeric( $group_id ) ? $group_id : 0;
		if ( empty( $field['name'] ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Field name is mandatory', 'wp-marketing-automations' ) );
		}
		/** Checking field slug is reserved key or not */
		if ( in_array( sanitize_title( $field['name'] ), BWFCRM_Fields::$reserved_keys, true ) ) {
			$this->response_code = 400;

			/* translators: 1: Field name */

			return $this->error_response( sprintf( __( '%1$s is a reserved key', 'wp-marketing-automations' ), sanitize_title( $field['name'] ) ) );
		}

		/**
		 *  Check group exist.
		 */
		$group = BWFCRM_Group::get_groupby_id( $group_id );
		if ( $group_id > 0 && empty( $group ) ) {
			$this->response_code = 400;

			/* translators: 1: Group ID */

			return $this->error_response( sprintf( __( 'Group with id %1$d not found', 'wp-marketing-automations' ), $group_id ) );  // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
		}

		if ( empty( $field['type'] ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Field type is mandatory', 'wp-marketing-automations' ) );
		}
		$field_name = $field['name'];
		$type       = $field['type'];
		$mode       = isset( $field['mode'] ) ? absint( $field['mode'] ) : 1;
		$vmode      = isset( $field['vmode'] ) ? absint( $field['vmode'] ) : 1;
		$search     = isset( $field['search'] ) ? absint( $field['search'] ) : 1;

		if ( ! empty( $this->args['field']['options'] ) ) {
			$options = $this->get_sanitized_arg( '', 'text_field', $this->args['field']['options'] );
		}
		$options = ! empty( $options ) && is_array( $options ) ? $options : [];

		$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
		$field       = BWFCRM_Fields::add_field( $field_name, $type, $options, $placeholder, $mode, $vmode, $search, $group_id );
		if ( is_wp_error( $field ) ) {
			return $this->error_response( '', $field, $field->get_error_code() );
		}

		if ( isset( $field['err_msg'] ) ) {
			$this->response_code = 400;

			/* translators: 1: Error message */

			return $this->error_response( sprintf( __( 'Cannot create a field. Error: %1$s. Contact Funnelkit support.', 'wp-marketing-automations' ), $field['err_msg'] ) );  // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
		}

		$field['merge_tag'] = BWFAN_Core()->merge_tags->get_field_tag( $field['slug'] );

		return $this->success_response( $field, __( 'Field created', 'wp-marketing-automations' ) );
	}

	/**
	 * PUT /v3/fields/{id} — update field
	 */
	public function update_field() {
		$field_id = $this->get_sanitized_arg( 'field_id', 'absint' );
		$group_id = $this->get_sanitized_arg( 'group_id', 'text_field' );
		$group_id = ! empty( $group_id ) && is_numeric( $group_id ) ? $group_id : 0;
		if ( empty( $field_id ) ) {
			$this->response_code = 400;
			$response            = __( "Field Id is missing", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$slug = $this->get_sanitized_arg( 'slug', 'text_field' );
		/** Checking field slug is reserved key or not */
		if ( in_array( sanitize_title( $slug ), BWFCRM_Fields::$reserved_keys, true ) ) {
			$this->response_code = 400;

			/* translators: 1: Field slug */

			return $this->error_response( sprintf( __( '%1$s is a reserved key', 'wp-marketing-automations' ), sanitize_title( $slug ) ) );
		}

		$group = BWFCRM_Group::get_groupby_id( $group_id );
		if ( $group_id > 0 && empty( $group ) ) {
			$this->response_code = 400;

			/* translators: 1: Group ID */

			return $this->error_response( sprintf( __( 'Field Group ID %1$d is mandatory', 'wp-marketing-automations' ), $group_id ) );  // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
		}

		if ( ! isset( $this->args['group_id'] ) ) {
			$group_id = false;
		}

		$field_name = $this->get_sanitized_arg( 'name', 'text_field' );

		$type        = $this->get_sanitized_arg( 'type', 'text_field' );
		$options     = $this->get_sanitized_arg( '', 'text_field', $this->args['options'] );
		$placeholder = $this->get_sanitized_arg( 'placeholder', 'text_field' );
		$mode        = $this->get_sanitized_arg( 'mode', 'text_field' );
		$vmode       = $this->get_sanitized_arg( 'vmode', 'text_field' );
		$search      = $this->get_sanitized_arg( 'search', 'text_field' );

		if ( empty( $slug ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Field slug is mandatory', 'wp-marketing-automations' ) );
		}


		$update_field = BWFCRM_Fields::update_field( $field_id, $group_id, $field_name, $type, $options, $placeholder, $slug, $mode, $vmode, $search );

		$this->response_code = $update_field['status'];
		if ( $update_field['status'] == 404 ) {
			return $this->error_response( $update_field['message'] );
		}
		$field         = BWFAN_Model_Fields::get( $field_id );
		$meta          = json_decode( $field['meta'] );
		$field['meta'] = $meta;

		$field['merge_tag'] = BWFAN_Core()->merge_tags->get_field_tag( $slug );

		return $this->success_response( $field, __( 'Field updated', 'wp-marketing-automations' ) );
	}

	/**
	 * DELETE /v3/fields/{id} — delete field
	 */
	public function delete_field() {

		$field_id = $this->get_sanitized_arg( 'field_id', 'absint' );

		$delete_field = BWFCRM_Fields::delete_field( $field_id );

		if ( 0 === $delete_field ) {

			$this->response_code = 400;

			/* translators: 1: Field ID */

			return $this->error_response( sprintf( __( 'Unable to delete field with id #%1$d', 'wp-marketing-automations' ), $field_id ) );
		}

		return $this->success_response( __( 'Field deleted', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /v3/all/fields — get all fields (including system fields)
	 */
	public function get_all_fields() {
		$all_fields = BWFCRM_Fields::get_fields( '', 1 );
		if ( isset( $all_fields['creation_date'] ) ) {
			unset( $all_fields['creation_date'] );
		}
		$fields            = $all_fields;
		$select_fields     = [ 'timezone', 'status', 'country' ];
		$all_fields        = array_map( function ( $field ) use ( $fields, $select_fields ) {
			if ( ! is_array( $field ) ) {
				$field_id = array_search( $field, $fields );
				$field    = [
					'group_id' => 0,
					'ID'       => $field_id,
					'type'     => in_array( $field_id, $select_fields ) ? 4 : 1,
					'name'     => $field,
					'meta'     => [],
				];

				if ( 'status' === $field_id ) {
					$field['meta']['options'] = [
						'0' => 'Unverified',
						'1' => 'Subscribed',
						'3' => 'Unsubscribed',
						'2' => 'Bounced'
					];
				}
			}

			return $field;
		}, $all_fields );
		$this->total_count = count( $all_fields );

		return $this->success_response( $all_fields, empty( $all_fields ) ? __( 'No Fields found.', 'wp-marketing-automations' ) : __( 'Got all fields', 'wp-marketing-automations' ) );
	}

	public function get_result_total_count() {
		return $this->total_count;
	}
}

BWFAN_API_Fields_Controller::get_instance();
