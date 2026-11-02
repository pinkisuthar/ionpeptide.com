<?php

/**
 * FunnelKit Order Bumps
 * by FunnelKit
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Bump' ) ) {
	class Bump {
		public function __construct() {
			add_filter( 'fkcart_cart_item_is_sold_individually', [ $this, 'is_bump_product' ], 10, 2 );
		}

		public function is_enable() {
			return class_exists( 'WFOB_Core' );
		}

		public function is_bump_product( $status, $cart_item ) {
			return isset( $cart_item['_wfob_options'] ) ? true : $status;
		}
	}

	Compatibility::register( new Bump(), 'bump' );
}

