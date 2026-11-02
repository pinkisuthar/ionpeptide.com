<?php
/**
 * Frontend Class for Archive Pages
 *
 * @package WooCommerce Waitlist
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! class_exists( 'Pie_WCWL_Frontend_Shop' ) ) {
	/**
	 * Loads up the waitlist for shop page
	 */
	class Pie_WCWL_Frontend_Shop {
		
		/**
		 * Load up hooks if product is out of stock
		 *
		 * @since  1.8.0
		 */
		public function init() {
			add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'output_waitlist_elements' ), 15 );
		}

		/**
		 * Output the waitlist button HTML to text string
		 * 
		 * phpcs ignore due to template having sufficient escaping and being directly output
		 *
		 * @since  1.9.0
		 * @return void
		 */
		public static function output_waitlist_elements() {
			global $post;
			$product = wc_get_product( $post );
			if ( ! $product || ! WooCommerce_Waitlist_Plugin::is_simple( $product ) ) {
				return;
			}
			echo wcwl_get_waitlist_for_archive( $product->get_id(), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
