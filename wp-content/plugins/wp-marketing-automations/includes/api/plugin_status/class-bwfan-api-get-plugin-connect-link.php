<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BWFAN_API_Get_Plugin_Connect_Link
 *
 * Returns the "connect" URL a freshly installed add-on needs (today: Stripe).
 * Route: GET /autonami-app/plugin-connect-link?plugin=stripe
 */
class BWFAN_API_Get_Plugin_Connect_Link extends BWFAN_API_Base {
	public static $ins;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		parent::__construct();
		$this->method = WP_REST_Server::READABLE;
		$this->route  = '/plugin-connect-link';
	}

	public function process_api_call() {
		$plugin = isset( $this->args['plugin'] ) ? sanitize_key( $this->args['plugin'] ) : '';

		if ( empty( $plugin ) ) {
			return $this->error_response( __( 'Plugin name is missing', 'wp-marketing-automations' ) );
		}

		switch ( $plugin ) {
			case 'stripe':
				if ( ! class_exists( '\FKWCS\Gateway\Stripe\Admin' ) ) {
					return $this->error_response( __( 'Stripe plugin is not installed', 'wp-marketing-automations' ) );
				}
				$link = \FKWCS\Gateway\Stripe\Admin::get_instance()->get_connect_url();
				break;
			default:
				return $this->error_response( __( 'Invalid plugin name', 'wp-marketing-automations' ) );
		}

		return $this->success_response( [ 'link' => $link ] );
	}
}

BWFAN_API_Loader::register( 'BWFAN_API_Get_Plugin_Connect_Link' );
