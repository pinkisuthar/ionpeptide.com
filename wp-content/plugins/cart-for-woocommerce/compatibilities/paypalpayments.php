<?php
/**
 *Official Paypal Payment
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\PaypalPayment' ) ) {
	class PaypalPayment {
		public function __construct() {
			add_filter( 'woocommerce_paypal_payments_mini_cart_button_renderer_hook', [ $this, 'replace_mini_cart_hook' ], 99 );
			add_filter( 'fkcart_smart_buttons', [ $this, 'register_smart_button' ] );
			add_action( 'fkcart_paypalpayments_smart_button', [ $this, 'leave_hook' ] );
			add_filter( 'fkcart_update_cart_cookie', [ $this, 'dis_allow_cookie_save' ] );
		}

		public function register_smart_button( $buttons ) {

			$settings = get_option( 'woocommerce-ppcp-settings' );
			if ( isset( $settings['smart_button_locations'] ) && in_array( 'cart', $settings['smart_button_locations'] ) ) {
				$buttons['paypalpayments'] = [ 'hook' => 'fkcart_paypalpayments_smart_button' ];

			}

			return $buttons;
		}

		public function replace_mini_cart_hook() {
			return 'fkcart_before_checkout_button_paypal_wrapper';
		}

		public function leave_hook() {
			do_action( 'fkcart_before_checkout_button_paypal_wrapper' );
		}

		/**
		 * Prevent re-saving the cookie, as PayPal payments can cause the cart to be emptied during the current AJAX request. When the cart is empty, our cookie is reset to its default value of 0.
		 *
		 * @param $status
		 *
		 * @return false|mixed
		 */
		public function dis_allow_cookie_save( $status ) {
			if ( did_action( 'wc_ajax_ppc-simulate-cart' ) ) {
				$status = false;
			}

			return $status;
		}
	}

	new PaypalPayment();
}