<?php
/**
 * Payment Plugins Stripe For WooCommerce
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\PaymentPluginStripe' ) ) {
	class PaymentPluginStripe {
		/**
		 * Remove Smart Button when Quick View OPen
		 * @return void
		 */
		public function remove_smart_buttons() {
			remove_action( 'woocommerce_before_add_to_cart_form', 'WC_Stripe_Field_Manager::before_add_to_cart' );
		}

		public function is_enable() {
			return class_exists( '\WC_Stripe_Manager' );
		}
	}

	Compatibility::register( new PaymentPluginStripe(), 'payment_plugin_stripe' );
}
