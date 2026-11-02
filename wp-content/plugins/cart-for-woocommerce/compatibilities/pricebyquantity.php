<?php

namespace FKCart\Compatibilities;
/**
 * https://woocommerce.com/products/price-by-quantity-for-woocommerce/
 */
if ( ! class_exists( '\FKCart\Compatibilities\PriceByQuantity' ) ) {
	class PriceByQuantity {
		public function __construct() {
			add_action( 'fkcart_before_add_to_cart', [ $this, 'set_add_to_cart_parameter' ], 10, 3 );
			add_action( 'woocommerce_add_to_cart_validation', [ $this, 'unset_add_to_cart_parameter' ], 11 );
		}

		/**
		 * Verify nonce for pbq_qty_pricing.
		 *
		 * @return bool
		 */
		private function verify_nonce(): bool {
			return isset( $_POST['pbq_qty_pricing_nonce'] ) && wp_verify_nonce( wc_clean( $_POST['pbq_qty_pricing_nonce'] ), 'pbq_qty_pricing_nonce' );
		}

		/**
		 * Set add to cart parameter
		 *
		 * @param int $product_id
		 * @param int $quantity
		 * @param int $variation_id
		 *
		 * @return void
		 */
		public function set_add_to_cart_parameter( $product_id, $quantity, $variation_id ) {
			try {
				if ( ! $this->verify_nonce() ) {
					return null;
				}
				$_POST['quantity'] = $quantity;
				if ( $variation_id > 0 ) {
					$_POST['variation_id'] = $variation_id;
				} else {
					$_POST['add-to-cart'] = $product_id;
				}
			} catch ( \Exception $e ) {
				fkcart_log_error( $e->getMessage() );
			}
		}

		/**
		 * Unset add to cart parameter
		 *
		 * @return void
		 */
		public function unset_add_to_cart_parameter() {
			if ( ! $this->verify_nonce() ) {
				return;
			}
			if ( isset( $_POST['variation_id'] ) ) {
				unset( $_POST['variation_id'] );
			}
			if ( isset( $_POST['add-to-cart'] ) ) {
				unset( $_POST['add-to-cart'] );
			}
		}

		public function is_enable() {
			return true;
		}
	}

	Compatibility::register( new PriceByQuantity(), 'PriceByQuantity' );
}
