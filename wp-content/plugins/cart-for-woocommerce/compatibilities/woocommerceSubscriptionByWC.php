<?php
/**
 * WooCommerce Subscriptions by WooCommerce
 * https://woocommerce.com/
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\woocommerceSubscriptionByWC' ) ) {
	class woocommerceSubscriptionByWC {
		public function __construct() {
			add_action( 'fkcart_after_order_summary', array( $this, 'add_summary' ) );
		}

		public function add_summary() {
			if ( ! $this->is_enable() ) {
				return;
			}

			if ( \WC_Subscriptions_Cart::cart_contains_subscription() && ! empty( WC()->cart->recurring_carts ) ) {
				foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {
					?>
                    <div class="fkcart-summary-line-item fkcart-subscription-wrap">
                        <div class="fkcart-summary-text"><?php esc_html_e( 'Recurring total', 'woocommerce-subscriptions' ); ?></div>
                        <div class="fkcart-summary-amount"><?php wcs_cart_totals_order_total_html( $recurring_cart ); ?></div>
                    </div>
					<?php
				}
			}
		}

		public function is_enable() {
			return function_exists( 'wcs_cart_totals_order_total_html' );
		}
	}

	Compatibility::register( new woocommerceSubscriptionByWC(), 'wc_subscription' );
}
