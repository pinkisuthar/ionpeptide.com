<?php
/**
 * Stripe by checkout plugins
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\CheckoutPluginStripe' ) ) {

	class CheckoutPluginStripe {

		/**
		 * Remove Smart Button when Quick View OPen
		 * @return void
		 */
		public function remove_smart_buttons() {
			$settings         = \CPSW\Inc\Helper::get_gateway_settings();
			$express_checkout = $settings['express_checkout_enabled'];
			if ( 'yes' !== $express_checkout || 'yes' !== $settings['enabled'] ) {
				return;
			}

			$product_page_action   = 'woocommerce_after_add_to_cart_quantity';
			$product_page_priority = 10;
			if ( 'below' === $settings['express_checkout_product_page_position'] || 'inline' === $settings['express_checkout_product_page_position'] ) {
				$product_page_action   = 'woocommerce_after_add_to_cart_button';
				$product_page_priority = 1;
			}
			remove_action( $product_page_action, [ \CPSW\Gateway\Stripe\Payment_Request_Api::get_instance(), 'payment_request_button' ], $product_page_priority );
		}

		public function is_enable() {
			return class_exists( '\CPSW\Inc\Helper' );
		}
	}

	Compatibility::register( new CheckoutPluginStripe(), 'checkout_plugin_stripe' );
}
