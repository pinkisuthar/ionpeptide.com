<?php
defined( 'ABSPATH' ) || exit;

/**
 * Security & Malware scan by CleanTalk
 * https://wordpress.org/plugins/security-malware-firewall/
 */
if ( ! class_exists( 'BWFAN_Compatibility_With_Security_By_CleanTalk' ) ) {
	/**
	 * Bwfan Compatibility With Security By Cleantalk
	 *
	 * @since 1.0.0
	 */
	class BWFAN_Compatibility_With_Security_By_CleanTalk {

		/**
		 *   Construct
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			add_filter( 'rest_jsonp_enabled', array( $this, 'bwfan_allow_rest_apis_with_force_login' ), 100 );
		}

		/**
		 * Allow FKA and FB endpoints in the rest calls
		 *
		 * @param $status
		 *
		 * @return mixed
		 */
		public function bwfan_allow_rest_apis_with_force_login( $status ) {
			global $spbc;
			if ( empty( $spbc ) || ( ! $spbc instanceof CleantalkSP\SpbctWP\State ) || empty( $spbc->settings['wp__disable_rest_api_for_non_authenticated'] ) ) {
				return $status;
			}

			try {
				$rest_route = filter_input( INPUT_GET, 'rest_route' );
				if ( empty( $rest_route ) ) {
					$rest_route = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				}

				if ( empty( $rest_route ) ) {
					return $status;
				}

				if ( false === strpos( $rest_route, 'autonami' ) && false === strpos( $rest_route, 'woofunnel' ) && false === strpos( $rest_route, 'funnelkit-automations' ) ) {
					return $status;
				}

				$auth_errors_hooks = BWFAN_Common::get_list_of_attach_actions( 'rest_authentication_errors' );
				if ( ! is_array( $auth_errors_hooks ) || count( $auth_errors_hooks ) == 0 ) {
					return $status;
				}

				global $wp_filter;
				foreach ( $auth_errors_hooks as $value ) {
					if ( ! isset( $value['function_path'] ) ) {
						continue;
					}
					if ( false !== strpos( $value['function_path'], '/security-malware-firewall' ) && isset( $wp_filter['rest_authentication_errors']->callbacks[ $value['priority'] ][ $value['index'] ] ) ) {
						unset( $wp_filter['rest_authentication_errors']->callbacks[ $value['priority'] ][ $value['index'] ] );
					}
				}
			} catch ( Error|Exception $e ) {
				return $status;
			}

			return $status;
		}
	}

	new BWFAN_Compatibility_With_Security_By_CleanTalk();
}
