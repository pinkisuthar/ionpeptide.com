<?php

namespace FKCart\Compatibilities;
/**
 * https://wordpress.org/plugins/order-minimum-amount-for-woocommerce/
 */
if ( ! class_exists( '\FKCart\Compatibilities\WpfactoryMinMaxAmount' ) ) {
	class WpfactoryMinMaxAmount {
		public function __construct() {
			add_action( 'wp_footer', [ $this, 'declare_cart' ], 1 );
			add_action( 'fkcart_before_body', [ $this, 'show_minimum_order_error' ] );
		}

		public function declare_cart() {
			add_filter( 'woocommerce_is_cart', '__return_true' );
		}

		/**
		 * Show WooCommerce error notices (e.g., minimum order amount) above cart items.
		 */
		public function show_minimum_order_error() {
			add_filter( 'alg_wc_oma_get_notices', [ $this, 'check_error_registered' ] );
			echo "<div class='fkcart_print_notice_wrap'>";

			do_action( 'woocommerce_before_cart' );

			echo "</div>";
		}

		public function check_error_registered( $data ) {
			if ( isset( $data['flat_notices'] ) && count( $data['flat_notices'] ) > 0 ) {
				add_action( 'fkcart_before_body', [ $this, 'js' ], 11 );
			}

			return $data;
		}

		/**
		 * Add custom JavaScript to modify the cart checkout button.
		 * This is necessary to ensure compatibility with the plugin's requirements.
		 */
		public function js() {
			?>
            <script>
                (function () {
                    jQuery('#fkcart-checkout-button').parents('.fkcart-checkout-info').addClass('wc-proceed-to-checkout')
                })();
            </script>
			<?php
		}

		public function is_enable() {
			return true;
		}
	}

	Compatibility::register( new WpfactoryMinMaxAmount(), 'WpfactoryMinMaxAmount' );
}
