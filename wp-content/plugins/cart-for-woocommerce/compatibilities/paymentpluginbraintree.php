<?php
/**
 * Payment Plugins Braintree For WooCommerce
 *  */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\PaymentPluginBraintree' ) ) {
	class PaymentPluginBraintree {
		/**
		 * Remove Smart Button when Quick View OPen
		 * @return void
		 */
		public function remove_smart_buttons() {
			remove_action( 'woocommerce_before_add_to_cart_form', 'WC_Braintree_Field_Manager::before_add_to_cart' );
		}

		public function is_enable() {
			return class_exists( '\WC_Braintree_Field_Manager' );
		}
	}

	Compatibility::register( new PaymentPluginBraintree(), 'payment_plugin_braintree' );

}
