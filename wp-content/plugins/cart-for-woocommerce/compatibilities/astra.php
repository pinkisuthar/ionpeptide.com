<?php
/**
 * Astra Theme
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Astra' ) ) {
	class Astra {
		public function __construct() {
			add_action( 'fkcart_quick_before_view_content', [ $this, 'remove_action' ] );
			add_action( 'fkcart_before_cart_items', [ $this, 'remove_woocommerce_cart_item_name' ] );

		}

		public function is_enable() {
			return defined( 'ASTRA_THEME_VERSION' );
		}

		public function remove_action() {
			if ( ! class_exists( '\Astra_Woocommerce' ) ) {
				return;
			}
			remove_action( 'woocommerce_single_product_summary', array( \Astra_Woocommerce::get_instance(), 'single_product_content_structure' ), 10 );
			remove_action( 'woocommerce_single_product_summary', array( \Astra_Woocommerce::get_instance(), 'astra_woo_product_in_stock' ), 10 );
			remove_filter( 'woocommerce_get_stock_html', 'astra_woo_product_in_stock', 10 );
		}

		public function remove_woocommerce_cart_item_name() {
			if ( ! class_exists( '\ASTRA_Ext_WooCommerce_Markup' ) ) {
				return;
			}

			$instance = \ASTRA_Ext_WooCommerce_Markup::get_instance();
			remove_filter( 'woocommerce_cart_item_name', array( $instance, 'add_cart_product_image' ), 10, 3 );
		}
	}

	Compatibility::register( new Astra(), 'astra' );
}