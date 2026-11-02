<?php
/**
 * Frontend Class for Simple Products.
 *
 * @package WooCommerce Waitlist
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! class_exists( 'Pie_WCWL_Frontend_Simple' ) ) {
	/**
	 * Loads up the waitlist for simple products
	 *
	 * @package  WooCommerce Waitlist
	 */
	class Pie_WCWL_Frontend_Simple {

		/**
		 * Current product ID
		 *
		 * @var int
		 */
		public static $product_id;
		
		/**
		 * Load up hooks if product is out of stock
		 *
		 * @param WC_Product $product Current product object.
		 */
		public function init( WC_Product $product ) {
			self::$product_id = $product->get_id();
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'output_waitlist_elements' ), 35 );
			/**
			 * Filter to disable outputting waitlist elements for simple products when using Elementor
			 * 
			 * @since 2.4.0
			 */
			if ( did_action( 'elementor_pro/init' ) && apply_filters( 'wcwl_add_waitlist_for_elementor', true ) ) {
				add_filter( 'elementor/widget/render_content', array( __CLASS__, 'output_waitlist_with_elementor' ), 10, 2 );
			}
		}

		/**
		 * Output waitlist elements via waitlist-single template
		 * 
		 * phpcs ignore due to template having sufficient escaping and being directly output
		 * 
		 * @return void
		 */
		public static function output_waitlist_elements() {
			echo wcwl_get_waitlist_fields( self::$product_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * Output waitlist elements for simple products when using Elementor
		 * Widget_Base is an Elementor class that is not available during WC testing
		 *
		 * @param string $content
		 * @param Widget_Base $widget widget class
		 * @return string $content
		 */
		public static function output_waitlist_with_elementor( $content, $widget ) { // @phpstan-ignore class.notFound
			if ( 'woocommerce-product-add-to-cart' === $widget->get_name() ) { // @phpstan-ignore class.notFound
				$content .= wcwl_get_waitlist_fields( self::$product_id );
			}
			return $content;
		}
	}
}
