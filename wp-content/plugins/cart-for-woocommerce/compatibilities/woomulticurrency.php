<?php
/**
 * CURCY - Multi Currency for WooCommerce
 *  https://villatheme.com/extensions/woo-multi-currency/
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Woomulticurrency' ) ) {
	class Woomulticurrency {
		public function __construct() {
			add_filter( 'fkcart_re_run_get_slide_cart_ajax', [ $this, 'need_to_run_get_slide_ajax' ] );
		}

		public function need_to_run_get_slide_ajax( $status ) {
			if ( true === $status ) {
				return true;
			}

			if ( $this->is_enable() && class_exists( 'FKCart\Pro\Rewards' ) ) {
				$status = true;
			}

			return $status;
		}

		public function is_enable() {
			return defined( 'WOOMULTI_CURRENCY_F_VERSION' ) || defined( 'WOOMULTI_CURRENCY_VERSION' );
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
			return \wmc_get_price( $price, $currency );
		}
	}

	Compatibility::register( new Woomulticurrency(), 'woomulticurrency' );
}
