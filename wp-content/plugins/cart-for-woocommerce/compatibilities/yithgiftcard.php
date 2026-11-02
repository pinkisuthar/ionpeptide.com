<?php

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\YithGiftCard' ) ) {
	class YithGiftCard {
		public function __construct() {
			add_filter( 'fkcart_is_ajax_add_to_cart_enabled', [ $this, 'disabled_ajax_add_to_cart' ] );
		}

		public function is_enabled() {
			return defined( 'YITH_YWGC_FREE' ) || defined( 'YITH_YWGC_PREMIUM' );

		}

		public function disabled_ajax_add_to_cart( $status ) {
			if ( 'no' === $status ) {
				return $status;
			}

			global $post;
			if ( ! $post instanceof \WP_Post ) {
				return $status;
			}

			$product = wc_get_product( $post->ID );
			if ( ! $product instanceof \WC_Product ) {
				return $status;
			}

			return ( 'gift-card' === $product->get_type() ) ? 'no' : $status;
		}
	}

	Compatibility::register( new YithGiftCard(), 'yithgiftcard' );
}
