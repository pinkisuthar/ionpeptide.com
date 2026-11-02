<?php
/**
 * Frontend Class for WC Bundles.
 *
 * @package WooCommerce Waitlist
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! class_exists( 'Pie_WCWL_Frontend_Bundle' ) ) {
	/**
	 * Loads up the waitlist for bundled products
	 *
	 * @package  WooCommerce Waitlist
	 */
	class Pie_WCWL_Frontend_Bundle {

		/**
		 * Current product Bundle
		 * PHPStan ignores due to this class only loading if we have a WC_Product_Bundle class but it is not loaded at the time of QIT tests so fails
		 * 
		 * @var WC_Product_Bundle $product // @phpstan-ignore class.notFound
		 */
		public static $product;

		/**
		 * Load up hooks if product is out of stock
		 *
		 * @param WC_Product $product WC_Product_Bundle.
		 */
		public function init( WC_Product $product ) {
			self::$product = $product;
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_bundle_script_data' ) );
		}

		/**
		 * Load required data to modify bundle HTML with JS
		 *
		 * @return void
		 */
		public function enqueue_bundle_script_data() {
			// PHPStan ignores due to this class only loading if we have a WC_Product_Bundle class but it is not loaded at the time of QIT tests so fails
			$data = array(
				'waitlist_html'     => wcwl_get_waitlist_fields( self::$product->get_id() ), // @phpstan-ignore class.notFound
				'backorder_allowed' => WooCommerce_Waitlist_Plugin::enable_waitlist_for_backorder_products( self::$product->get_id() ), // @phpstan-ignore class.notFound
			);
			wp_localize_script( 'wcwl_frontend', 'wcwl_bundle_data', $data );
		}
	}
}
