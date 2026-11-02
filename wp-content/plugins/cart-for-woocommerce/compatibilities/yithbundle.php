<?php
/**
 *Yith Product Bundle
 * By YIth
 * https://yithemes.com/themes/plugins/yith-woocommerce-product-bundles
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\YithBundle' ) ) {
	class YithBundle {
		public function __construct() {
			add_filter( 'fkcart_is_child_item', [ $this, 'is_child_product' ], 10, 2 );
			add_filter( 'fkcart_enable_item_link', [ $this, 'add_item_meta' ], 99 );

		}


		public function is_enable() {

			return defined( 'YITH_WCPB_VERSION' );
		}


		public function is_child_product( $status, $cart_item ) {
			if ( ! isset( $cart_item['bundled_by'] ) ) {
				return false;
			}

			return isset( $cart_item['bundled_by'] ) ? true : $status;
		}

		public function add_item_meta( $status ) {

			if ( ! $this->is_enable() ) {
				return $status;
			}
			add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'update_price' ), 99999, 3 );


			return $status;


		}

		public function update_price( $price, $cart_item, $cart_item_key ) {

			try {
				if ( ! class_exists( '\YITH_WCPB_Frontend' ) ) {
					return $price;
				}

				$bundle_price = \YITH_WCPB_Frontend::get_instance()->bundles_item_subtotal( $price, $cart_item, $cart_item_key );

				return $bundle_price;
			} catch ( \Exception $e ) {

			}
		}
	}

	Compatibility::register( new YithBundle(), 'YithBundle' );
}
