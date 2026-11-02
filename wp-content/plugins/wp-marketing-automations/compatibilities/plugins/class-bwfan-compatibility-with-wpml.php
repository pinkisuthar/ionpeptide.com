<?php
defined( 'ABSPATH' ) || exit;

/**
 * WordPress Multilingual Plugin
 * https://wpml.org/
 */
if ( ! class_exists( 'BWFAN_Compatibility_With_WPML' ) ) {
	class BWFAN_Compatibility_With_WPML {

		private $intercept_post_id = null;
		private $intercept_funnel_id = null;

		public function __construct() {
			add_action( 'bwfan_email_setup_locale', [ $this, 'translate_email_body' ] );
			add_filter( 'wffn_wfty_filter_page_ids', [ $this, 'ensure_funnel_meta_for_translated_checkout' ], 5, 2 );
			add_filter( 'wffn_wfty_filter_page_ids', [ $this, 'remove_funnel_meta_intercept' ], 11, 2 );
			add_action( 'woocommerce_checkout_order_created', [ $this, 'maybe_save_order_language' ], 999 );
		}

		/**
		 * Ensure funnel association meta is accessible for translated checkout pages.
		 *
		 * Translated checkout pages lack _bwf_in_funnel meta (only the default language post has it).
		 * Instead of translating _wfacp_post_id on the order (which corrupts language detection in
		 * filter_thankyou_by_language at priority 10), we intercept get_post_meta reads to return
		 * the funnel ID from the default language post — keeping _wfacp_post_id intact so the
		 * language filter correctly identifies the customer's checkout language.
		 *
		 * @param array    $thankyou_page_ids
		 * @param WC_Order $order
		 *
		 * @return array
		 */
		public function ensure_funnel_meta_for_translated_checkout( $thankyou_page_ids, $order ) {
			if ( ! $order instanceof WC_Order ) {
				return $thankyou_page_ids;
			}

			$aero_id = $order->get_meta( '_wfacp_post_id' );
			if ( empty( $aero_id ) || 0 === absint( $aero_id ) ) {
				return $thankyou_page_ids;
			}

			$aero_id = absint( $aero_id );

			$funnel_id = get_post_meta( $aero_id, '_bwf_in_funnel', true );
			if ( ! empty( $funnel_id ) ) {
				return $thankyou_page_ids;
			}

			$default_lang = apply_filters( 'wpml_default_language', null );
			if ( empty( $default_lang ) ) {
				return $thankyou_page_ids;
			}

			$post_type = get_post_type( $aero_id );
			if ( empty( $post_type ) ) {
				return $thankyou_page_ids;
			}

			$original_id = apply_filters( 'wpml_object_id', $aero_id, $post_type, true, $default_lang );

			if ( empty( $original_id ) || absint( $original_id ) === $aero_id ) {
				return $thankyou_page_ids;
			}

			$default_funnel_id = get_post_meta( absint( $original_id ), '_bwf_in_funnel', true );
			if ( empty( $default_funnel_id ) ) {
				return $thankyou_page_ids;
			}

			$this->intercept_post_id   = $aero_id;
			$this->intercept_funnel_id = $default_funnel_id;
			add_filter( 'get_post_metadata', [ $this, 'intercept_funnel_meta' ], 10, 4 );

			return $thankyou_page_ids;
		}

		/**
		 * Intercept _bwf_in_funnel meta reads for translated checkout posts.
		 *
		 * @param mixed  $value     Current meta value (null = not yet retrieved).
		 * @param int    $object_id Post ID being queried.
		 * @param string $meta_key  Meta key being queried.
		 * @param bool   $single    Whether to return a single value.
		 *
		 * @return mixed
		 */
		public function intercept_funnel_meta( $value, $object_id, $meta_key, $single ) {
			if ( (int) $object_id !== $this->intercept_post_id || '_bwf_in_funnel' !== $meta_key ) {
				return $value;
			}

			if ( $single ) {
				return $this->intercept_funnel_id;
			}

			return array( $this->intercept_funnel_id );
		}

		/**
		 * Remove the funnel meta intercept after the thankyou page filter chain completes.
		 *
		 * @param array    $thankyou_page_ids
		 * @param WC_Order $order
		 *
		 * @return array
		 */
		public function remove_funnel_meta_intercept( $thankyou_page_ids, $order ) {
			if ( null !== $this->intercept_post_id ) {
				remove_filter( 'get_post_metadata', [ $this, 'intercept_funnel_meta' ], 10 );
				$this->intercept_post_id   = null;
				$this->intercept_funnel_id = null;
			}

			return $thankyou_page_ids;
		}

		/**
		 * Save wpml_language meta on order creation when WPML is active and meta is absent.
		 *
		 * @param WC_Order $order
		 */
		public function maybe_save_order_language( $order ) {
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			if ( ! defined( 'ICL_LANGUAGE_CODE' ) ) {
				return;
			}

			$existing = $order->get_meta( 'wpml_language' );
			if ( ! empty( $existing ) ) {
				return;
			}

			$order->update_meta_data( 'wpml_language', ICL_LANGUAGE_CODE );
			$order->save_meta_data();
		}

		/**
		 * setup locale for email with translation plugins
		 *
		 * @param $lang
		 */
		public function translate_email_body( $lang ) {
			if ( empty( $lang ) ) {
				return;
			}

			global $woocommerce_wpml;
			if ( ! class_exists( 'woocommerce_wpml' ) || ! $woocommerce_wpml instanceof woocommerce_wpml ) {
				return;
			}
			$woocommerce_wpml->emails->change_email_language( $lang );
		}

		/**
		 * Get translated term IDs dynamically based on the context
		 *
		 * @param array $term_ids The original term IDs
		 * @param string $taxonomy_name The taxonomy name
		 * @param array $automation_data Automation context data
		 *
		 * @return array An array of translated term IDs
		 */
		public static function get_translated_term_ids( $term_ids, $taxonomy_name, $automation_data = [] ) {
			if ( empty( $term_ids ) ) {
				return $term_ids;
			}

			$language_code = self::detect_language_from_sources( $automation_data );
			if ( empty( $language_code ) ) {
				return $term_ids;
			}

			$all_translated_ids = [];
			foreach ( $term_ids as $term_id ) {
				$all_translated_ids[] = $term_id;

				$translated_id = apply_filters( 'wpml_object_id', $term_id, $taxonomy_name, false, $language_code );

				if ( $translated_id ) {
					$all_translated_ids[] = $translated_id;
				}
			}

			$ids = array_unique( $all_translated_ids );
			sort( $ids );

			return $ids;
		}

		/**
		 * Detect language from various possible sources
		 *
		 * @param $automation_data
		 *
		 * @return array|mixed|string
		 */
		private static function detect_language_from_sources( $automation_data ) {
			if ( isset( $automation_data['global']['language'] ) ) {
				return $automation_data['global']['language'];
			}

			global $sitepress;
			$default_language = $sitepress->get_default_language();
			$sitepress->get_current_language();
			if ( ! isset( $automation_data['global']['order_id'] ) ) {
				return $default_language;
			}
			$order = $automation_data['global']['order_id'];
			$order = wc_get_order( $order );
			if ( ! $order instanceof WC_Order ) {
				return $default_language;
			}
			$order_language = $order->get_meta( 'wpml_language' );

			return ! empty( $order_language ) ? $order_language : $default_language;
		}
	}

	new BWFAN_Compatibility_With_WPML();
}
