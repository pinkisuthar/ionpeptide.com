<?php
defined( 'ABSPATH' ) || exit;

/**
 * WordPress REST API Authentication
 * By miniOrange
 * https://wordpress.org/plugins/wp-rest-api-authentication/
 */
if ( ! class_exists( 'BWFAN_Compatibility_With_WP_Rest_Authenticate' ) ) {
	class BWFAN_Compatibility_With_WP_Rest_Authenticate {

		public function __construct() {
			add_filter( 'dra_allow_rest_api', [ $this, 'bwfan_allow_rest_apis' ] );
		}

		/**
		 * Allow Autonami and WooFunnels endpoints in rest calls
		 *
		 * @return bool
		 */
		public function bwfan_allow_rest_apis() {
			$rest_route = $GLOBALS['wp']->query_vars['rest_route'];
			if ( false !== strpos( $rest_route, 'autonami' ) || false !== strpos( $rest_route, 'woofunnel' ) || false !== strpos( $rest_route, 'funnelkit-automations' ) ) {
				return true;
			}

			return false;
		}
	}

	new BWFAN_Compatibility_With_WP_Rest_Authenticate();
}
