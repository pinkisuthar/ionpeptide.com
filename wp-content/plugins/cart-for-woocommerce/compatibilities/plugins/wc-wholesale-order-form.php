<?php
/**
 * WooCommerce Wholesale Order Form by Rymera Web Co 3.0.6
 *
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\WC_Wholesale_Order_Form' ) ) {
	class WC_Wholesale_Order_Form {
		public function __construct() {
			add_action( 'wp_footer', [ $this, 'inline_js' ], 11 );
		}

		/**
		 * Enqueue JS in the footer
		 *
		 * @return void
		 */
		public function inline_js() {
			?>
            <script>
                window.addEventListener("load", function () {
                    if (jQuery(".ant-layout-content").length === 0) {
                        return;
                    }
                    jQuery("body").on("wc_fragment_refresh", function () {
                        try {
                            jQuery("body").trigger("fkcart_update_side_cart", [true]);
                        } catch (error) {
                            console.error("Error triggered in fkcart_update_side_cart:", error);
                        }
                    });
                });
            </script>
			<?php
		}
	}

	Compatibility::register( new WC_Wholesale_Order_Form(), 'woocommerce-wholesale-order-form' );
}
