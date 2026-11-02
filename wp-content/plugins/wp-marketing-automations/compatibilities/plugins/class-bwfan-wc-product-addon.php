<?php
defined( 'ABSPATH' ) || exit;
/**
 * WooCommerce Product Add-Ons Compatibility
 * https://wpml.org/
 */

if ( ! class_exists( 'BWFAN_Compatibility_With_WC_Product_Addon' ) ) {

	class BWFAN_Compatibility_With_WC_Product_Addon {

		public function __construct() {
			add_filter( 'bwfan_abandoned_modify_cart_item_data', [ $this, 'abandoned_modify_cart_item_data' ], 10, 1 );
		}

		/**
		 * Modify cart item data for abandoned carts.
		 *
		 * @param array $item_data Cart item data.
		 *
		 * @return array Modified cart item data.
		 */
		public function abandoned_modify_cart_item_data( $item_data ) {
			if ( defined( 'PEWC_PLUGIN_VERSION' ) && isset( $item_data['product_extras'] ) ) {
				remove_filter( 'woocommerce_add_cart_item_data', 'pewc_add_cart_item_data' );
			}

			return $item_data;
		}
	}

	new BWFAN_Compatibility_With_WC_Product_Addon();
}
