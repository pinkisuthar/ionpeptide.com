<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BWF_Data_Tags' ) ) {
	#[AllowDynamicProperties]
	class BWF_Data_Tags {
		public $shortcodes = array(
			'get_cookie',
			'get_url_parameter',

		);

		// The pattern to match restricted cookies 'wordpress_*', '_fk_contact_uid', 'wp-settings-*', 'PHPSESSID', 'wordpress_logged_in_*', 'wp_woocommerce_session_*'
		public $restricted_cookie_pattern = "/^(wordpress_.*|_fk_contact_uid|wp-settings-.*|PHPSESSID|wordpress_logged_in_.*|wp_woocommerce_session_.*)$/";

		public function __construct() {
			foreach ( $this->shortcodes as $code ) {
				add_shortcode( 'wf_' . $code, array( $this, $code ) );
			}

		}

		private static $ins = null;

		/**
		 * @return BWF_Optin_Tags|null
		 */
		public static function get_instance() {

			if ( null === self::$ins ) {
				self::$ins = new self;
			}

			return self::$ins;
		}


		public function get_first_name( $attr ) {
			if ( isset( $this->get_optin()->optin_first_name ) && ! empty( $this->get_optin()->optin_first_name ) ) {
				return $this->get_optin()->optin_first_name;
			}

			return $this->get_default( $attr, 'first_name' );

		}

		public function get_cookie( $attr ) {
			$attr = shortcode_atts( array(
				'key' => '',
			), $attr );

			if ( empty( $attr['key'] ) ) {
				return '';
			}

			// Check if the cookie key is restricted
			if (preg_match($this->restricted_cookie_pattern, $attr['key'])) {
				return '';
			}

			$data = isset( $_COOKIE[ $attr['key'] ] ) ? bwf_clean( wp_unslash( $_COOKIE[ $attr['key'] ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading cookie data for display, nonce verification not required; bwf_clean() sanitizes recursively

			/*** read cookie when drop cookie on page **/
			if ( empty( $data ) ) {
				$key  = str_replace( 'bwf_', '', $attr['key'] );
				$data = isset( $_GET[ $key ] ) ? bwf_clean( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading URL parameter for display, nonce verification not required; bwf_clean() sanitizes recursively
			}

			return esc_html( $data );

		}

		public function get_url_parameter( $attr ) {

			$attr = shortcode_atts( array(
				'key' => '',
			), $attr );

			if ( empty( $attr['key'] ) ) {
				return '';
			}

			return esc_html( isset( $_GET[ $attr['key'] ] ) ? bwf_clean( wp_unslash( $_GET[ $attr['key'] ] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading URL parameter for display, nonce verification not required; bwf_clean() sanitizes recursively

		}


	}

	BWF_Data_Tags::get_instance();
}
