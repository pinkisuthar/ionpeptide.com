<?php
/**
 * WooCommerce Product Bundles
 * By WooCommerce
 */

namespace FKCart\Compatibilities;


if ( ! class_exists( '\FKCart\Compatibilities\WooCommerceProductBundles' ) ) {
	class WooCommerceProductBundles {

		public function __construct() {
			add_filter( 'fkcart_is_child_item', [ $this, 'is_child_product' ], 10, 2 );
			add_filter( 'fkcart_item_hide_you_saved_text', [ $this, 'is_hide_you_saved_text' ], 10, 2 );
			add_action( 'fkcart_before_cart_items', [ $this, 'remove_actions' ] );
			add_action( 'fkcart_before_add_to_cart', [ $this, 'check_bundle_sells' ] );


		}

		public function is_enable() {
			return class_exists( '\WC_Bundles' );
		}

		public function is_child_product( $status, $cart_item ) {
			// Early return if not a bundled item
			if ( ! isset( $cart_item['bundled_by'] ) ) {
				return $status;
			}

			// Early return if bundle_item_id is missing
			if ( ! isset( $cart_item['bundled_item_id'] ) || empty( $cart_item['bundled_item_id'] ) ) {
				return $status;
			}

			$bundle_item_id = $cart_item['bundled_item_id'];

			// Handle optional bundled items
			// If not an optional item that was selected, mark as child product
			if ( ! ( isset( $cart_item['stamp'] ) && isset( $cart_item['stamp'][ $bundle_item_id ] ) && isset( $cart_item['stamp'][ $bundle_item_id ]['optional_selected'] ) && 'yes' === $cart_item['stamp'][ $bundle_item_id ]['optional_selected'] ) ) {
				// Do not consider optional item as child product
				$status = true;
			}


			// Get the parent bundle directly
			$parent_cart_item = WC()->cart->get_cart_item( $cart_item['bundled_by'] );

			// Early return if parent bundle not found
			if ( ! $parent_cart_item || ! isset( $parent_cart_item['data'] ) ) {
				return $status;
			}

			// Get parent product ID
			$product_id = $parent_cart_item['data']->get_id();

			// Early return if product ID invalid or WC_Bundled_Item class doesn't exist
			if ( $product_id <= 0 || ! class_exists( '\WC_Bundled_Item' ) ) {
				return $status;
			}

			// Try to create bundled item safely
			try {
				$bundle_item = new \WC_Bundled_Item( $bundle_item_id, $product_id );

				// Check pricing structure and update status if needed
				if ( true === $bundle_item->is_priced_individually() ) {
					$status = false;
				}
			} catch ( \Exception $e ) {
				// Log error but don't modify status on failure
				error_log( 'Error creating bundled item: ' . $e->getMessage() );
			}

			return $status;
		}

		public function is_hide_you_saved_text( $status, $cart_item ) {
			if ( isset( $cart_item['bundled_by'] ) ) {
				$status = true;// Hide you saved text for child item including optional item
			}

			return $status;
		}

		public function remove_actions() {
			if ( class_exists( '\WC_PB_Display' ) ) {
				remove_filter( 'woocommerce_cart_item_name', array( \WC_PB_Display::instance(), 'cart_item_title' ), 10 );
			}
		}

		public function check_bundle_sells( $product_id ) {
			try {
				if ( class_exists( '\WC_PB_BS_Cart' ) && method_exists( '\WC_PB_BS_Cart', 'get_posted_bundle_sells_configuration' ) ) {

					$product                    = wc_get_product( $product_id );
					$bundle_sells_configuration = \WC_PB_BS_Cart::get_posted_bundle_sells_configuration( $product );
					if ( ! empty( $bundle_sells_configuration ) ) {
						$_REQUEST['add-to-cart'] = $product_id;
					}
				}
			} catch ( \Exception|\Error $e ) {

			}
		}

	}

	Compatibility::register( new WooCommerceProductBundles(), 'woocommerce-product-bundles' );
}
