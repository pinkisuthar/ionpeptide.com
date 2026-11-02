<?php
/**
 * Stripe for WooCommerce official
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\WCStripe' ) ) {
	class WCStripe {
		/**
		 * Remove Smart Button when Quick View OPen
		 * @return void
		 */
		public function remove_smart_buttons() {
			$instance = \WC_Stripe_Payment_Request::instance();
			remove_action( 'woocommerce_after_add_to_cart_quantity', [ $instance, 'display_payment_request_button_html' ], 1 );
			remove_action( 'woocommerce_after_add_to_cart_quantity', [ $instance, 'display_payment_request_button_separator_html' ], 2 );
		}

		public function is_enable() {
			return function_exists( 'woocommerce_gateway_stripe' );
		}
	}

	Compatibility::register( new WCStripe(), 'wc_stripe' );
}
