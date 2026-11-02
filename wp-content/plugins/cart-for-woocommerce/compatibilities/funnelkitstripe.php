<?php
/**
 * Stripe for WooCommerce by FunnelKit
 */

namespace FKCart\Compatibilities;

use FKWCS\Gateway\Stripe\GooglePay;

if ( ! class_exists( '\FKCart\Compatibilities\FunnelKitStripe' ) ) {

	class FunnelKitStripe {
		public function __construct() {

			add_filter( 'fkcart_smart_buttons', [ $this, 'register_smart_button' ] );
			add_action( 'fkcart_fkwcs_smart_button', [ $this, 'print_smart_button' ] );
			add_action( 'fkcart_fkwcs_smart_button_gpay', [ $this, 'print_smart_button_gpay' ] );
		}

		public function register_smart_button( $buttons ) {
			$buttons['funnelkit_stripe']      = [ 'hook' => 'fkcart_fkwcs_smart_button', 'show' => false ];
			$buttons['funnelkit_stripe_gpay'] = [ 'hook' => 'fkcart_fkwcs_smart_button_gpay' ];

			return $buttons;
		}

		public function print_smart_button_gpay() {
			if ( ! class_exists( 'FKWCS\Gateway\Stripe\GooglePay', false ) ) {
				return false;
			}
			if ( GooglePay::get_instance()->is_configured() ) {
				remove_action( 'fkcart_before_checkout_button', [ GooglePay::get_instance(), 'add_mini_cart_wrapper' ] );
				GooglePay::get_instance()->add_mini_cart_wrapper();
				do_action( 'fkcart_after_smart_payment_buttons', $this );
			}
		}

		public function print_smart_button() {
			if ( ! class_exists( '\FKWCS\Gateway\Stripe\SmartButtons' ) ) {
				return;
			}
			add_filter( 'fkwcs_express_buttons_is_only_buttons', '__return_true', 20 );
			$instance = \FKWCS\Gateway\Stripe\SmartButtons::get_instance();
			// Credit card enabled checking already Handled in Payment_request_button settings below
			$instance->payment_request_button( true );
		}

		/**
		 * Remove Smart Button when Quick View OPen
		 * @return void
		 */
		public function remove_smart_buttons() {
			$product_page_action   = 'woocommerce_after_add_to_cart_quantity';
			$product_page_priority = 10;
			$instance              = \FKWCS\Gateway\Stripe\SmartButtons::get_instance();
			if ( isset( $instance->local_settings['express_checkout_product_page_position'] ) && ( 'below' === $instance->local_settings['express_checkout_product_page_position'] || 'inline' === $instance->local_settings['express_checkout_product_page_position'] ) ) {
				$product_page_action   = 'woocommerce_after_add_to_cart_button';
				$product_page_priority = 1;
			}
			remove_action( $product_page_action, [ $instance, 'payment_request_button' ], $product_page_priority );
		}

		public function is_enable() {
			return class_exists( '\FKWCS_Gateway_Stripe' );
		}
	}

	Compatibility::register( new FunnelKitStripe(), 'funnelkit_stripe' );
}
