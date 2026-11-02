<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Connectors_Controller extends BWFAN_API_Controller {

	public static $ins;

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
		return array(
			'wfco_connector' => '',
			'id'             => '',
		);
	}

	protected function register_routes() {
		// GET /connectors
		$this->add_route( '/connectors', array(
			array( 'GET', 'get_connectors' ),
		) );

		// POST /connector/save
		$this->add_route( '/connector/save', array(
			array( 'POST', 'save_connector' ),
		) );

		// PUT /connector/update
		$this->add_route( '/connector/update', array(
			array( 'PUT', 'update_connector' ),
		) );

		// POST /connector/sync
		$this->add_route( '/connector/sync', array(
			array( 'POST', 'sync_connector' ),
		) );

		// DELETE /connector/disconnect
		$this->add_route( '/connector/disconnect', array(
			array( 'DELETE', 'delete_connector' ),
		) );
	}

	/**
	 * GET /connectors
	 */
	public function get_connectors() {
		$connectors = BWFAN_Core()->connectors->get_connectors_for_listing();
		if ( ! bwfan_is_autonami_connector_active() ) {
			$connectors = array_map( function ( $connector ) {
				$name = $connector['name'];
				$name = strtolower( str_replace( [ ' ', '/' ], [ '-', '' ], $name ) ) . '.png';
				if ( isset( $connector['logo'] ) && ! empty( $connector['logo'] ) ) {
					if ( wp_http_validate_url( $connector['logo'] ) ) {
						return $connector;
					}

					$name = $connector['logo'] . '.png';
				}

				$connector['logo'] = BWFAN_PLUGIN_URL . '/includes/connectors-logo/' . $name;

				return $connector;
			}, $connectors );
		}

		return $this->success_response( $connectors, '' );
	}

	/**
	 * POST /connector/save
	 */
	public function save_connector() {
		$wfco_connector = $this->get_sanitized_arg( 'wfco_connector', 'text_field', $this->args['wfco_connector'] );
		if ( empty( $wfco_connector ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Provided connector is empty.', 'wp-marketing-automations' ) );
		}

		$active_connectors = WFCO_Load_Connectors::get_active_connectors();
		$connector         = $active_connectors[ sanitize_text_field( $wfco_connector ) ];
		if ( ! $connector instanceof BWF_CO ) {
			$message             = __( 'Something is wrong, connector isn\'t available.', 'wp-marketing-automations' );
			$this->response_code = 500;

			return $this->error_response( $message );
		}

		$id     = $this->get_sanitized_arg( 'id', 'text_field' );
		$action = empty( absint( $id ) ) ? 'save' : 'update';

		/** Do Wizard Connector Handling (Mailchimp, GetResponse, Mautic) */
		if ( BWFAN_Core()->connectors->is_wizard_connector( $connector ) ) {
			$next_step = $connector->get_next_step( $this->args );
			/** Error in Data provided for next step */
			if ( is_wp_error( $next_step ) ) {
				return $this->error_response( '', $next_step, 500 );
			}

			/** If step type = 'handle_settings_with_params', then go through handle_settings_form with new params */
			if ( is_array( $next_step ) && isset( $next_step['step_type'] ) && 'handle_settings_with_params' === $next_step['step_type'] ) {
				$this->args = $next_step['params'];
			} elseif ( true !== $next_step ) {
				do_action( 'bwfan_connector_connected', $wfco_connector );

				/** If true, then go through handle_settings_form, else get next step data */
				return $this->success_response( $next_step );
			}
		}

		$response = $connector->handle_settings_form( $this->args, $action );
		$status   = in_array( $response['status'], [ 'success', 'rerun_api', 'not_connected' ] ) ? true : false;
		$message  = $response['message'];
		/** Error occurred */
		if ( false === $status ) {
			$this->response_code = 500;

			return $this->error_response( $message );
		}
		do_action( 'bwfan_connector_connected', $wfco_connector );

		return $this->success_response( $response, __( 'Connector updated', 'wp-marketing-automations' ) );
	}

	/**
	 * PUT /connector/update
	 */
	public function update_connector() {
		$wfco_connector = $this->get_sanitized_arg( 'wfco_connector', 'text_field' );
		$id             = $this->get_sanitized_arg( 'id', 'text_field' );
		if ( empty( $wfco_connector ) || empty( $id ) ) {
			return $this->error_response( __( 'Connector saved data missing, kindly disconnect and connect again.', 'wp-marketing-automations' ), null, 400 );
		}

		$active_connectors = WFCO_Load_Connectors::get_active_connectors();
		if ( ! $active_connectors[ sanitize_text_field( $wfco_connector ) ] instanceof BWF_CO ) {
			$message = __( 'Something is wrong, connector isn\'t available.', 'wp-marketing-automations' );

			return $this->error_response( $message, null, 500 );
		}

		$response = $active_connectors[ sanitize_text_field( $wfco_connector ) ]->handle_settings_form( $this->args, 'update' );
		$status   = ( 'success' === $response['status'] ) ? true : false;
		$message  = $response['message'];
		/** Error occurred */
		if ( false === $status ) {
			return $this->error_response( $message, null, 500 );
		}

		return $this->success_response( $response, __( 'Connector updated', 'wp-marketing-automations' ) );
	}

	/**
	 * POST /connector/sync
	 */
	public function sync_connector() {
		$wfco_connector = $this->get_sanitized_arg( 'wfco_connector', 'text_field' );
		$id             = $this->get_sanitized_arg( 'id', 'text_field' );
		if ( empty( $wfco_connector ) || empty( ( $id ) ) ) {
			return $this->error_response( __( 'Connector saved data missing, kindly disconnect and connect again.', 'wp-marketing-automations' ), null, 400 );
		}

		$current_connector = WFCO_Load_Connectors::get_connector( $wfco_connector );
		if ( ! $current_connector instanceof BWF_CO ) {
			$message = __( 'Something is wrong, connector isn\'t available.', 'wp-marketing-automations' );

			return $this->error_response( $message, null, 500 );
		}
		try {
			$response = $current_connector->handle_settings_form( $this->args, 'sync' );
			$status   = ( 'success' === $response['status'] ) ? true : false;
			$message  = $response['message'];
		} catch ( Error $e ) {
			$message = $e->getMessage();

			return $this->error_response( $message, null, 500 );
		}

		/** Error occurred */
		if ( false === $status ) {
			return $this->error_response( $message, null, 500 );
		}

		return $this->success_response( $response, __( 'Connector updated', 'wp-marketing-automations' ) );
	}

	/**
	 * DELETE /connector/disconnect
	 */
	public function delete_connector() {
		$slug = $this->get_sanitized_arg( 'wfco_connector', 'text_field' );
		if ( empty( $slug ) ) {
			return $this->error_response( __( 'Connector saved data missing, kindly disconnect and connect again.', 'wp-marketing-automations' ), null, 400 );
		}

		/** Handling for Bitly */
		if ( 'bwfco_bitly' === $slug ) {
			$bitly = BWFCO_Bitly::get_instance();
			if ( $bitly->is_connected() ) {
				$bitly->disconnect();

				return $this->success_response( array(), __( 'Connector deleted', 'wp-marketing-automations' ) );
			}
		}

		$connector_details = WFCO_Model_Connectors::get_specific_rows( 'slug', $slug );
		if ( empty( $connector_details ) ) {
			return $this->error_response( __( 'Connector not exist.', 'wp-marketing-automations' ), null, 500 );
		}

		$connector = WFCO_Load_Connectors::get_connector( $slug );
		if ( method_exists( $connector, 'disconnect' ) ) {
			$connector->disconnect();
		}

		global $wpdb;
		$connector_ids = array_map( function ( $connector ) {
			return absint( $connector['ID'] );
		}, $connector_details );
		$placeholder = implode( ',', array_fill( 0, count( $connector_ids ), '%d' ) );

		$connector_sql      = $wpdb->prepare( "DELETE from {table_name} where ID IN ($placeholder)", $connector_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated as %d list; ids absint()ed; array passed to prepare
		$connector_meta_sql = $wpdb->prepare( "DELETE from {table_name} where connector_id IN ($placeholder)", $connector_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated as %d list; ids absint()ed; array passed to prepare
		WFCO_Model_ConnectorMeta::delete_multiple( $connector_meta_sql );
		WFCO_Model_Connectors::delete_multiple( $connector_sql );
		do_action( 'bwfan_connector_disconnected', $slug );

		return $this->success_response( array(), __( 'Connector deleted', 'wp-marketing-automations' ) );
	}
}

BWFAN_API_Connectors_Controller::get_instance();
