<?php
/**
 * WooCommerce Germanized
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Germanized' ) ) {
	class Germanized {
		public function __construct() {
			add_action( 'fkcart_get_cart_item', [ $this, 'attach_actions' ] );
			add_action( 'fkcart_after_upsell_price', [ $this, 'print_unit_price' ] );
		}

		public function attach_actions() {
			if ( function_exists( 'wc_gzd_cart_product_delivery_time' ) ) {
				add_filter( 'woocommerce_cart_item_name', 'wc_gzd_cart_product_delivery_time', 10, 3 );
			}
			if ( function_exists( 'wc_gzd_cart_product_units' ) ) {
				add_filter( 'woocommerce_cart_item_name', 'wc_gzd_cart_product_units', 11, 3 );
			}

			if ( function_exists( 'woocommerce_gzd_template_mini_cart_taxes' ) ) {
				add_action( 'fkcart_before_checkout_button', [ $this, 'display_tax' ] );
			}
			add_filter( 'fkcart_enable_mini_cart_widget_quantity_filter', '__return_true' );
		}

		public function display_tax() {
			?>
            <style>
                .fkcart_mini_cart_widget_quantity .wc-gzd-cart-info.units-info {
                    display: none;
                }
            </style>
            <div class="fkcart-order-summary fkcart-panel fkcart-germanized">
                <div class="fkcart-summary-line-item fkcart-subtotal-wrap ">
                    <div class="fkcart-summary-text"><strong><?php woocommerce_gzd_template_mini_cart_taxes() ?></strong></div>
                    <div class="fkcart-summary-amount"></div>
                </div>
                <div class="fkcart-text-light"></div>
            </div>
			<?php
		}

		public function print_unit_price( $product_id ) {
			$content = do_shortcode( "[gzd_product_unit_price product='{$product_id}']" );
			if ( empty( $content ) ) {
				return;
			}
			echo '<div class="fkcart-item-price">' . $content . '</div>';
		}
	}

	Compatibility::register( new Germanized(), 'woocommerce_germanized' );
}
