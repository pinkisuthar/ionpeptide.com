<?php

namespace FKCart\compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\WPML_Multicurrency' ) ) {
	class WPML_Multicurrency {
		public function __construct() {
			add_filter( 'fkcart_re_run_get_slide_cart_ajax', [ $this, 'need_to_run_get_slide_ajax' ] );
		}

		public function need_to_run_get_slide_ajax( $status ) {
			if ( $this->is_enable() && class_exists( 'FKCart\Pro\Rewards' ) ) {
				$status = true;
			}

			return $status;
		}

		public function is_enable() {
			global $woocommerce_wpml;

			return ( class_exists( '\woocommerce_wpml' ) && $woocommerce_wpml instanceof \woocommerce_wpml );
		}

		/**
		 *
		 * Modifies the amount for the fixed discount given by the admin in the currency selected.
		 *
		 * @param integer|float $price
		 *
		 * @return float
		 */
		public function alter_fixed_amount( $price, $currency = null ) {


			global $woocommerce_wpml;
			if ( defined( 'WCML_MULTI_CURRENCIES_INDEPENDENT' ) && WCML_MULTI_CURRENCIES_INDEPENDENT !== $woocommerce_wpml->settings['enable_multi_currency'] ) {
				return $price;
			}

			return $woocommerce_wpml->get_multi_currency()->prices->convert_price_amount( $price );
		}
	}

	Compatibility::register( new WPML_Multicurrency(), 'woowpmlmulticurrency' );
}
