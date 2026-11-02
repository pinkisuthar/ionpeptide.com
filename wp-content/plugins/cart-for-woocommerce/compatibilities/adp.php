<?php
/**
 * Advanced dynamic pricing by algol plus
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Adp' ) ) {
	class Adp {
		public function __construct() {
			add_filter( 'adp_product_get_price', [ $this, 'set_price_to_zero' ], 10, 6 );
		}

		public function is_enable() {
			return defined( 'WC_ADP_PLUGIN_FILE' );
		}

		/**
		 * @param $price
		 * @param $p2
		 * @param $p3
		 * @param $p4
		 * @param $p5
		 * @param $facade  \ADP\BaseVersion\Includes\WC\WcCartItemFacade;
		 *
		 * @return int|mixed
		 */
		public function set_price_to_zero( $price, $p2, $p3, $p4, $p5, $facade ) {
			if ( $facade instanceof \ADP\BaseVersion\Includes\WC\WcCartItemFacade ) {
				$item = $facade->getCartItemData();
				if ( isset( $item['_fkcart_free_gift'] ) ) {
					return 0;
				}
			}

			return $price;
		}
	}

	Compatibility::register( new Adp(), 'adp' );
}