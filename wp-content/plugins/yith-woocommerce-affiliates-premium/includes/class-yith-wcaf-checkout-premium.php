<?php
/**
 * Checkout Handler class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Checkout_Premium' ) ) {
	/**
	 * Checkout handler
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Checkout_Premium extends YITH_WCAF_Checkout {

		/**
		 * Constructor method
		 */
		public function __construct() {
			parent::__construct();

			// add affiliate form on the checkout.
			add_action( 'woocommerce_before_checkout_form', array( $this, 'print_affiliate_form_on_checkout' ) );
		}

		/* ==== FRONTEND METHODS === */

		/**
		 * Print affiliate form on checkout page, if option is enabled
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function print_affiliate_form_on_checkout() {
			if ( ! yith_plugin_fw_is_true( get_option( 'yith_wcaf_show_checkout_box', 'no' ) ) ) {
				return;
			}

			echo do_shortcode( '[yith_wcaf_set_referrer]' );
		}

		/* === CHECKOUT HANDLING METHODS === */

		/**
		 * Process checkout handling, registering order meta data
		 *
		 * @param int|\WC_Order $order Order id or order object.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function process_checkout( $order ) {
			// retrieve order.
			$order = wc_get_order( $order );

			if ( ! $order ) {
				return;
			}

			// retrieve affiliate from applied coupons, if any.
			if ( YITH_WCAF_Coupons()->are_coupons_enabled() ) {
				$coupon_affiliate = false;
				$coupon_items     = $order->get_items( 'coupon' );

				// check if order contains any coupon bound to an affiliate account.
				if ( ! empty( $coupon_items ) ) {
					foreach ( $coupon_items as $item ) {
						/**
						 * Every coupon item for current order
						 *
						 * @var $item \WC_Order_Item_Coupon
						 */
						$coupon_affiliate = YITH_WCAF_Coupons()->get_coupon_affiliate( $item->get_code() );

						if ( $coupon_affiliate ) {
							// stop at first occurrence, even if more coupons needs to be processed.
							break;
						}
					}
				}

				// if an affiliate's coupon was found, reset the class to grant correct commissions.
				if ( $coupon_affiliate ) {
					YITH_WCAF_Session()->set_token( $coupon_affiliate->get_token(), 'coupon' );
				}
			}

			// generate commissions.
			parent::process_checkout( $order );

			// register history.
			$this->register_history( $order );
		}

		/**
		 * Register affiliates history within order metas
		 *
		 * @param int $order_id Order id.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function register_history( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order && 'yes' === get_option( 'yith_wcaf_history_cookie_enable', 'yes' ) ) {
				$order->update_meta_data( '_yith_wcaf_referral_history', YITH_WCAF_Session()->get_history() );
				$order->save();
			}

			// delete history cookie.
			$this->delete_history_cookie_after_process();
		}

		/**
		 * Delete cookie after an order is processed with current token
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function delete_history_cookie_after_process() {
			YITH_WCAF_Session()->delete_history_cookie();
		}

		/**
		 * Delete cookie after an order is processed with current token
		 *
		 * @return void
		 * @since 1.0.7
		 */
		public function delete_cookie_after_process() {
			if ( 'yes' === get_option( 'yith_wcaf_delete_cookie_after_checkout', 'yes' ) ) {
				parent::delete_cookie_after_process();
			}
		}
	}
}
