<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woo_Variation_Swatches_Compatibility' ) ) {
	class Woo_Variation_Swatches_Compatibility {

		protected static $_instance = null;

		protected function __construct() {
			$this->includes();
			$this->hooks();
			$this->init();
			do_action( 'woo_variation_swatches_manage_compatibility_loaded', $this );
		}

		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}

		protected function includes() {
			include_once dirname( __FILE__ ) . '/themes-support.php';
		}

		protected function hooks() {
			add_filter( 'wp_kses_allowed_html', array( $this, 'elementor_pro_compatibility' ) );
			add_filter( 'woo_variation_swatches_get_available_variation', array(
				$this,
				'the_7_theme_add_to_cart_compatibility'
			), 10, 3 );


			add_action('woo_variation_swatches_rest_get_product_variations', array( $this, 'yith_wcwtl_compatibility' ));

		}

		protected function init() {

		}

		// Start

		public function elementor_pro_compatibility( $tags ) {
			if ( class_exists( 'ElementorPro\\Plugin' ) ) {
				$tags['select'] = array(
					'class'                 => array(),
					'id'                    => array(),
					'name'                  => array(),
					'type'                  => array(),
					'style'                 => array(),
					'data-attribute_name'   => array(),
					'data-show_option_none' => array(),
				);

				$tags['option'] = array(
					'selected' => array(),
					'value'    => array(),
				);
			}

			return $tags;
		}

		public function the_7_theme_add_to_cart_compatibility( $available_variation, $variation, $product ) {

			if ( function_exists( 'the7_get_wc_product_add_to_cart_icon' ) ) {
				$available_variation['add_to_cart_text'] = sprintf( '<span class="filter-popup">%s</span><i class="popup-icon %s"></i>', $variation->add_to_cart_text(), the7_get_wc_product_add_to_cart_icon( $variation ) );
			}

			return $available_variation;
		}

		// YITH WooCommerce Waitlist Compatibility.
		public function yith_wcwtl_compatibility() {
			if ( class_exists( 'YITH_WCWTL_Frontend' ) ) {
				$object = YITH_WCWTL_Frontend::get_instance();
				add_filter( 'woocommerce_get_stock_html', array( $object, 'output_form_3_0' ), 20, 2 );
			}
		}
	}
}

