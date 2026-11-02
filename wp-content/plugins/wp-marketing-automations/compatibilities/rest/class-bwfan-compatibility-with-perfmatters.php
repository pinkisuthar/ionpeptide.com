<?php
defined( 'ABSPATH' ) || exit;

/**
 * Perfmatters
 * https://perfmatters.io/
 */
if ( ! class_exists( 'BWFAN_Compatibility_With_Perfmatters' ) ) {
	class BWFAN_Compatibility_With_Perfmatters {

		private $is_public_page = null;

		public function __construct() {
			add_filter( 'rest_jsonp_enabled', array( $this, 'bwfan_allow_rest_apis_with_perfmatters' ), 100 );

			/** Disable Perfmatters delay/defer JS entirely on unsubscribe and profile pages */
			add_filter( 'perfmatters_delay_js', array( $this, 'maybe_disable_delay_js' ) );
			add_filter( 'perfmatters_defer_js', array( $this, 'maybe_disable_defer_js' ) );

			/** Also exclude FunnelKit scripts from Perfmatters delay/defer exclusion lists (secondary safety net) */
			add_filter( 'perfmatters_delay_js_exclusions', array( $this, 'exclude_scripts_from_delay' ) );
			add_filter( 'perfmatters_defer_js_exclusions', array( $this, 'exclude_scripts_from_defer' ) );
		}

		/**
		 * Allow Autonami and WooFunnels endpoints in rest calls
		 *
		 * @param $status
		 *
		 * @return mixed
		 */
		public function bwfan_allow_rest_apis_with_perfmatters( $status ) {
			if ( ! is_array( $GLOBALS['wp']->query_vars ) || ! isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
				return $status;
			}

			$rest_route = $GLOBALS['wp']->query_vars['rest_route'];

			if ( strpos( $rest_route, 'autonami' ) !== false || strpos( $rest_route, 'woofunnel' ) !== false ) {
				remove_filter( 'rest_authentication_errors', 'perfmatters_rest_authentication_errors', 20 );
			}

			return $status;
		}

		/**
		 * Disable Perfmatters delay JS on unsubscribe and profile pages.
		 *
		 * The perfmatters_delay_js filter controls whether delay JS is active for the current page.
		 * Returning false disables it entirely, equivalent to Perfmatters' per-page exclusion meta.
		 * This covers both "Delay All" and "Individual Delay" modes.
		 *
		 * @param bool $enabled Whether delay JS is enabled.
		 *
		 * @return bool
		 */
		public function maybe_disable_delay_js( $enabled ) {
			if ( $this->is_fka_public_page() ) {
				return false;
			}

			return $enabled;
		}

		/**
		 * Disable Perfmatters defer JS on unsubscribe and profile pages.
		 *
		 * @param bool $enabled Whether defer JS is enabled.
		 *
		 * @return bool
		 */
		public function maybe_disable_defer_js( $enabled ) {
			if ( $this->is_fka_public_page() ) {
				return false;
			}

			return $enabled;
		}

		/**
		 * Exclude scripts from Perfmatters delay JS on unsubscribe and profile pages
		 *
		 * When Perfmatters delays JavaScript, the unsubscribe/profile button click handler
		 * is not attached, causing the button to navigate to # instead of firing the AJAX request.
		 *
		 * @param array $exclusions
		 *
		 * @return array
		 */
		public function exclude_scripts_from_delay( $exclusions ) {
			if ( ! $this->is_fka_public_page() ) {
				return $exclusions;
			}

			$exclusions[] = 'bwfan-public';
			$exclusions[] = 'jquery';

			return $exclusions;
		}

		/**
		 * Exclude scripts from Perfmatters defer JS on unsubscribe and profile pages
		 *
		 * @param array $exclusions
		 *
		 * @return array
		 */
		public function exclude_scripts_from_defer( $exclusions ) {
			if ( ! $this->is_fka_public_page() ) {
				return $exclusions;
			}

			$exclusions[] = 'bwfan-public';

			return $exclusions;
		}

		/**
		 * Check if current page is unsubscribe or manage profile page
		 *
		 * @return bool
		 */
		private function is_fka_public_page() {
			if ( is_admin() || ! class_exists( 'BWFAN_Public' ) || ! class_exists( 'BWFAN_Common' ) ) {
				return false;
			}

			if ( null !== $this->is_public_page ) {
				return $this->is_public_page;
			}

			$settings             = BWFAN_Common::get_global_settings();
			$this->is_public_page = ( BWFAN_Public::get_instance()->is_unsubscribe_page( $settings ) || BWFAN_Public::get_instance()->is_manage_profile_page( $settings ) );

			return $this->is_public_page;
		}
	}

	new BWFAN_Compatibility_With_Perfmatters();
}
