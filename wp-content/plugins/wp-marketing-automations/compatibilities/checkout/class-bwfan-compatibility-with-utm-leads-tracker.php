<?php
defined( 'ABSPATH' ) || exit;

/**
 * UTM Leads Tracker - XLPlugins
 * https://wordpress.org/plugins/utm-leads-tracker-lite/
 */
if ( ! class_exists( 'BWFAN_Compatibility_With_UTM_Leads_Tracker' ) ) {
	class BWFAN_Compatibility_With_UTM_Leads_Tracker {

		public function __construct() {
			add_filter( 'bwfan_ab_change_checkout_data_for_external_use', array( $this, 'bwfan_populate_utm_lead_data_cart' ), 99 );
			add_action( 'bwfan_ab_handle_checkout_data_externally', array( $this, 'bwfan_set_utm_lead_cookie' ), 9 );
		}

		/**
		 * Set XL UTM data in cookies on cart restore
		 *
		 * @param $checkout_data
		 */
		public function bwfan_set_utm_lead_cookie( $checkout_data ) {
			if ( ! isset( $checkout_data['xlutm_data'] ) || empty( $checkout_data['xlutm_data'] ) ) {
				return;
			}
			$xlutm_data = $checkout_data['xlutm_data'];

			$cookie_val = json_encode( $xlutm_data );
			$secure     = is_ssl();
			setcookie( 'xlutm_params_utm', $cookie_val, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, $secure, true );
		}

		/**
		 * Append UTM data from cookies to the cart
		 *
		 * @param $abandoned_data
		 *
		 * @return mixed
		 */
		public function bwfan_populate_utm_lead_data_cart( $abandoned_data ) {

			if ( ! isset( $_COOKIE['xlutm_params_utm'] ) || ! is_string( $_COOKIE['xlutm_params_utm'] ) ) {
				return $abandoned_data;
			}

			$abandoned_data['xlutm_data'] = map_deep( json_decode( wp_unslash( $_COOKIE['xlutm_params_utm'] ), true ), function ( $value ) { return is_string( $value ) ? sanitize_text_field( $value ) : $value; } ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded string values are sanitize_text_field()ed, other JSON types kept

			return $abandoned_data;
		}
	}

	new BWFAN_Compatibility_With_UTM_Leads_Tracker();
}
