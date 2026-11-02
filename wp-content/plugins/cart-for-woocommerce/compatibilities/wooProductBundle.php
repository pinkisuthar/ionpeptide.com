<?php
/**
 * WPC Product Bundles for WooCommerce
 * By WPClever
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\WooProductBundle' ) ) {
	class WooProductBundle {
		public function __construct() {
			add_filter( 'fkcart_is_child_item', [ $this, 'is_child_product' ], 10, 2 );
		}

		public function is_enable() {
			return defined( 'WOOSB_DIR' );
		}

		public function is_child_product( $status, $cart_item ) {
			if ( isset( $cart_item['woosb_ids'] ) ) {
				return false;
			}

			return isset( $cart_item['woosb_parent_id'] ) ? true : $status;
		}
	}

	Compatibility::register( new WooProductBundle(), 'WooProductBundle' );
}
