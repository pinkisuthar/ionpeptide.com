<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shield Security (WP Simple Firewall)
 * https://wordpress.org/plugins/wp-simple-firewall/
 */
if ( ! class_exists( 'BWFAN_Compatibility_With_Shield_Security' ) ) {
	/**
	 * BWFAN Compatibility With Shield Security
	 *
	 * Shield Security blocks anonymous REST API access via rest_authentication_errors.
	 * Uses Shield's official `shield/anonymous_rest_api_exclusions` filter to whitelist
	 * FunnelKit namespaces, with hook-removal fallback for rule-based blocking.
	 *
	 * Also addresses 503 blocks from IP blocking, rate limiting, and bot detection by
	 * using Shield's filters: shield/is_request_blocked, shield/is_ip_blocked_auto,
	 * and shield/is_trusted_request.
	 *
	 * @since 3.6.5
	 */
	class BWFAN_Compatibility_With_Shield_Security {

		/**
		 * FunnelKit REST API path/namespace identifiers for request detection.
		 *
		 * @var array
		 */
		private static $funnelkit_identifiers = array( 'autonami/v', 'woofunnels/v1', 'woofunnel_customer/v1', 'funnelkit-automations/' );

		/**
		 * Cached result of is_funnelkit_rest_request() per request.
		 *
		 * @var bool|null
		 */
		private static $is_funnelkit_rest_request_cache = null;

		/**
		 * Constructor
		 *
		 * @since 3.6.5
		 */
		public function __construct() {
			add_filter( 'shield/anonymous_rest_api_exclusions', array( $this, 'whitelist_funnelkit_namespaces' ) );
			add_filter( 'rest_jsonp_enabled', array( $this, 'bwfan_allow_rest_apis_with_shield_security' ), 100 );
			add_filter( 'shield/is_request_blocked', array( $this, 'bwfan_bypass_shield_ip_block' ), 10, 1 );
			add_filter( 'shield/is_ip_blocked_auto', array( $this, 'bwfan_bypass_shield_auto_block' ), 10, 1 );
			add_filter( 'shield/is_trusted_request', array( $this, 'bwfan_mark_funnelkit_as_trusted' ), 10, 2 );
		}

		/**
		 * Get the current REST route from the request.
		 *
		 * @return string
		 */
		private static function get_rest_route() {
			if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request detection only; decides which code loads, changes nothing
				return sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request detection only; decides which code loads, changes nothing
			}
			if ( isset( $_SERVER['REQUEST_URI'] ) ) {
				return esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			}
			return '';
		}

		/**
		 * Check if the current request is a FunnelKit REST API request.
		 *
		 * @return bool
		 */
		private static function is_funnelkit_rest_request() {
			if ( null !== self::$is_funnelkit_rest_request_cache ) {
				return self::$is_funnelkit_rest_request_cache;
			}

			$rest_route = self::get_rest_route();
			if ( '' === $rest_route ) {
				self::$is_funnelkit_rest_request_cache = false;
				return false;
			}

			foreach ( self::$funnelkit_identifiers as $id ) {
				if ( false !== strpos( $rest_route, $id ) ) {
					self::$is_funnelkit_rest_request_cache = true;
					return true;
				}
			}

			self::$is_funnelkit_rest_request_cache = false;
			return false;
		}

		/**
		 * Whitelist FunnelKit REST API namespaces via Shield's official filter.
		 *
		 * @param array $exclusions Existing exclusions.
		 * @return array
		 */
		public function whitelist_funnelkit_namespaces( $exclusions ) {
			$namespaces = array( 'woofunnels', 'autonami', 'autonami-app', 'funnelkit-automations', 'autonami-webhook', 'woofunnel_customer' );
			return array_merge( (array) $exclusions, $namespaces );
		}

		/**
		 * Fallback: Remove Shield's rule-based REST API blocking for FunnelKit endpoints.
		 *
		 * @param mixed $status rest_jsonp_enabled filter value.
		 * @return mixed
		 */
		public function bwfan_allow_rest_apis_with_shield_security( $status ) {
			try {
				$rest_route = self::get_rest_route();
				if ( '' === $rest_route ) {
					return $status;
				}
				if ( false === strpos( $rest_route, 'autonami' ) && false === strpos( $rest_route, 'woofunnel' ) && false === strpos( $rest_route, 'funnelkit-automations' ) ) {
					return $status;
				}

				$auth_errors_hooks = BWFAN_Common::get_list_of_attach_actions( 'rest_authentication_errors' );
				if ( ! is_array( $auth_errors_hooks ) || 0 === count( $auth_errors_hooks ) ) {
					return $status;
				}

				global $wp_filter;
				$rest_auth_filter = $wp_filter['rest_authentication_errors'] ?? null;
				if ( ! is_object( $rest_auth_filter ) ) {
					return $status;
				}
				foreach ( $auth_errors_hooks as $value ) {
					$path = $value['class_path'] ?? $value['function_path'] ?? '';
					if ( '' === $path || false === strpos( $path, '/wp-simple-firewall' ) ) {
						continue;
					}
					if ( isset( $rest_auth_filter->callbacks[ $value['priority'] ][ $value['index'] ] ) ) {
						unset( $rest_auth_filter->callbacks[ $value['priority'] ][ $value['index'] ] );
					}
				}
			} catch ( \Throwable $e ) {
				return $status;
			}

			return $status;
		}

		/**
		 * Bypass Shield IP block (503) for FunnelKit REST API requests.
		 *
		 * When Shield would block an IP and return 503, allow FunnelKit endpoints through.
		 *
		 * @param bool $blocked Whether the request is blocked.
		 * @return bool
		 */
		public function bwfan_bypass_shield_ip_block( $blocked ) {
			return ( $blocked && self::is_funnelkit_rest_request() ) ? false : $blocked;
		}

		/**
		 * Bypass Shield auto-block for FunnelKit REST API requests.
		 *
		 * @param bool $blocked Whether the IP is auto-blocked.
		 * @return bool
		 */
		public function bwfan_bypass_shield_auto_block( $blocked ) {
			return ( $blocked && self::is_funnelkit_rest_request() ) ? false : $blocked;
		}

		/**
		 * Mark FunnelKit REST requests as trusted to bypass bot/antibot blocking.
		 *
		 * @param bool   $trusted Whether the request is trusted.
		 * @param object $request Shield's ThisRequest object.
		 * @return bool
		 */
		public function bwfan_mark_funnelkit_as_trusted( $trusted, $request ) {
			return ( ! $trusted && self::is_funnelkit_rest_request() ) ? true : $trusted;
		}
	}

	new BWFAN_Compatibility_With_Shield_Security();
}
