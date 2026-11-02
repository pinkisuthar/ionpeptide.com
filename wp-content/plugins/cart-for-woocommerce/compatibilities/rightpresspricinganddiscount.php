<?php
/**
 * WooCommerce Dynamic Pricing & Discounts
 * Author RightPress
 */

namespace FKCart\compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\RightPressPricingAndDiscount' ) ) {

	class RightPressPricingAndDiscount {
		public function __construct() {
			add_filter( 'fkcart_re_run_get_slide_cart_ajax', [ $this, 'enable_re_run_slide_ajax' ] );
		}

		public function is_enable() {
			return defined( 'RP_WCDPD_PLUGIN_PATH' );
		}

		public function enable_re_run_slide_ajax() {
			return true;
		}
	}

	Compatibility::register( new RightPressPricingAndDiscount(), 'rightpress_pricing_discount' );
}
