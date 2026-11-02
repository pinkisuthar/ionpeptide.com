<?php

namespace FKCart\Compatibilities;

use FKCart\Includes\Data;

if ( ! class_exists( '\FKCart\Compatibilities\Shoptimizer' ) ) {

	class Shoptimizer {
		public function __construct() {
			if ( is_customize_preview() || is_admin() || ! wc_string_to_bool( Data::get_value( 'enable_cart' ) ) ) {
				return;
			}
			add_filter( 'theme_mod_shoptimizer_layout_woocommerce_single_product_ajax', '__return_false' );
			add_filter( 'theme_mod_shoptimizer_layout_woocommerce_enable_sidebar_cart', '__return_false' );
		}

		public function is_enable() {
			return function_exists( 'shoptimizer_header_cart' );
		}

	}

	Compatibility::register( new Shoptimizer(), 'Shoptimizer' );
}
