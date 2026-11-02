<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Announcement_Action extends BWFAN_API_Base {
	public static $ins;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		parent::__construct();
		$this->method = WP_REST_Server::EDITABLE;
		$this->route  = '/v1/announcement_action';
	}

	public function default_args_values() {
		return array(
			'action'       => '', // 'last_seen' or 'hide_feature'
			'last_seen_id' => 0,
			'slug'         => '',
		);
	}

	public function process_api_call() {
		$action = $this->get_sanitized_arg( 'action' );

		if ( empty( $action ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'action is required', 'wp-marketing-automations' ) );
		}

		// Only the declared payload reaches the model (base args also carry files/limit/offset).
		$payload = array_intersect_key( $this->args, $this->default_args_values() );
		$data    = BWFAN_Announcements::update_announcement_data( 'fka', $action, $payload );

		if ( isset( $data['status'] ) && false === $data['status'] ) {
			return $this->error_response( $data['msg'] ?? __( 'Unable to perform the action', 'wp-marketing-automations' ), null, 400 );
		}

		return $this->success_response( array( 'success' => true ) );
	}
}

BWFAN_API_Loader::register( 'BWFAN_API_Announcement_Action' );
