<?php
/**
 * YITH WooCommerce Minimum Maximum Quantity Premium by YITH  1.53.0
 * Plugin URI: https://yithemes.com/themes/plugins/yith-woocommerce-minimum-maximum-quantity/
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\YithMinMaxQty' ) ) {
	class YithMinMaxQty {
		public function __construct() {
			add_action( 'fkcart_before_body', [ $this, 'run_validation' ] );
		}

		public function run_validation() {
			try {
				$error = new \WP_Error();
				\YITH_WC_Min_Max_Qty::get_instance()->checkout_validation( [], $error );

				echo "<div class='fkcart_print_notice_wrap'>";
				do_action( 'woocommerce_before_cart_items' );
				foreach ( $error->get_error_messages() as $message ) {
					wc_add_notice( $message, 'error' );
				}
				wc_print_notices();
				echo "</div>";
			} catch ( \Exception|\Error $e ) {
			}
		}

		public function is_enable() {
			return true;
		}
	}

	Compatibility::register( new YithMinMaxQty(), 'yith_min_max_qty' );
}
