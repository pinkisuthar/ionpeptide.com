<?php
defined( 'ABSPATH' ) || exit;

/**
 * All-In-One Security (AIOS) - Security and Firewall
 * https://wordpress.org/plugins/all-in-one-wp-security-and-firewall/
 *
 * AIOS blocks REST API requests via the aios_whitelisted_rest_routes filter.
 * This compatibility class whitelists FunnelKit REST API routes.
 */
if ( ! class_exists( 'BWFAN_Compatibility_With_AIOS' ) ) {
	/**
	 * BWFAN Compatibility With All-In-One Security (AIOS)
	 *
	 * @since 3.6.5
	 */
	class BWFAN_Compatibility_With_AIOS {

		/**
		 * Constructor
		 *
		 * @since 3.6.5
		 */
		public function __construct() {
			// AIOS filter for whitelisting REST routes
			add_filter( 'aios_whitelisted_rest_routes', array( $this, 'whitelist_funnelkit_rest_routes' ), 10, 1 );
		}

		/**
		 * Whitelist FunnelKit REST API routes in AIOS.
		 *
		 * @param array $routes Array of whitelisted routes.
		 * @return array
		 */
		public function whitelist_funnelkit_rest_routes( $routes ) {
			if ( ! is_array( $routes ) ) {
				$routes = array();
			}

			// Add FunnelKit REST API namespaces/routes
			$funnelkit_routes = array(
				'autonami/v1',
				'autonami/v2',
				'autonami-app/v1',
				'woofunnels/v1',
				'woofunnel_customer/v1',
				'funnelkit-automations',
				'funnelkit',
			);

			return array_merge( $routes, $funnelkit_routes );
		}
	}

	new BWFAN_Compatibility_With_AIOS();
}
