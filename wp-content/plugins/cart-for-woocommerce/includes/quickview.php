<?php

namespace FKCart\Includes;

use FKCart\Compatibilities\Compatibility;
use FKCart\Includes\Traits\Instance;

if ( ! class_exists( '\FKCart\Includes\Quickview' ) ) {
	class Quickview {
		use Instance;

		public $product_obj = null;
		public $product_id = null;
		public $variation_id = null;
		public $cart_key = '';

		private function __construct() {
			add_action( 'fkcart_quick_before_view_content', [ $this, 'remove_action' ] );

			add_action( 'woocommerce_before_add_to_cart_form', [ $this, 'map_attributes' ] );
		}

		/**
		 * @param $product_obj \WC_Product
		 * @param $variation_id
		 * @param $cart_key
		 *
		 * @return void
		 */
		public function set_product_data( $product_obj, $variation_id = 0, $cart_key = '' ) {
			$this->product_obj  = $product_obj;
			$this->product_id   = $product_obj->get_id();
			$this->variation_id = $variation_id;
			$this->cart_key     = $cart_key;

		}

		public function map_attributes() {
			if ( ! $this->product_obj instanceof \WC_Product ) {
				return;
			}
			$cart_key = $this->cart_key;
			if ( empty( $this->cart_key ) ) {
				return;
			}
			$item = WC()->cart->get_cart_item( $cart_key );
			foreach ( $item['variation'] as $a_name => $a_val ) {
				$_REQUEST[ $a_name ] = $a_val;
			}
		}

		/**
		 * Unhook actions
		 *
		 * @return void
		 */
		public function remove_action() {
			$this->remove_native_hook();
			$this->override_native_hook();
			Compatibility::remove_smart_buttons();
		}

		/**
		 * Remove WC single product summary hooks in quick view
		 *
		 * @return void
		 */
		private function remove_native_hook() {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 10 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
			remove_action( 'woocommerce_single_product_summary', [ 'WC_Structured_Data', 'generate_product_data' ], 60 );
			remove_action( 'woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20 );
			remove_action( 'woocommerce_single_variation', 'woocommerce_single_variation', 10 );
		}

		/**
		 * Override native WC hooks
		 *
		 * @return void
		 */
		private function override_native_hook() {
			add_action( 'woocommerce_single_variation', [ $this, 'woocommerce_single_variation_add_to_cart_button' ], 20 );
			add_action( 'woocommerce_single_variation', [ $this, 'get_variable_price' ], 21 );
			add_action( 'woocommerce_single_variation', 'woocommerce_single_variation', 22 );
			add_filter( 'woocommerce_get_stock_html', [ $this, 'woo_product_in_stock' ], 10, 2 );
		}

		/**
		 * Get single product add to cart button
		 *
		 * @return void
		 */
		public function get_forms() {
			global $product;


			/** Load the template */
			fkcart_get_template_part( 'cart/quick-view/add-to-cart-variable', '', array(
				'available_variations' => $product->get_available_variations(),
				'attributes'           => $product->get_variation_attributes(),
				'selected_attributes'  => $product->get_default_attributes(),
				'cart_key'             => $this->cart_key,
				'variation_id'         => $this->variation_id,
			) );
		}

		/**
		 * Get WC single variation product add to cart button
		 *
		 * @return void
		 */
		public function woocommerce_single_variation_add_to_cart_button() {
			fkcart_get_template_part( 'cart/quick-view/variation-add-to-cart-button' );
		}

		/**
		 * @param $markup
		 * @param $product \WC_Product
		 *
		 * @return false|mixed|string
		 */
		public function woo_product_in_stock( $markup, $product ) {
			$availability = __( 'In stock', 'woocommerce' );
			if ( ! $product->is_in_stock() ) {
				$availability = __( 'Out of stock', 'woocommerce' );
			}
			$product_avail = $product->get_availability();
			$avail_class   = $product_avail['class'];
			if ( ! empty( $availability ) ) {
				ob_start();
				?>
                <p class="fkcart-stock-detail">
                    <span class="stock <?php echo esc_html( $avail_class ); ?>"><?php echo esc_html( $availability ); ?></span>
                </p>
				<?php
				$markup = ob_get_clean();
			}

			return $markup;
		}

		/**
		 * Get variable product price HTML
		 *
		 * @return void
		 */
		public function get_variable_price() {
			global $product;
			if ( ! fkcart_is_variable_product_type( $product->get_type() ) ) {
				return;
			}
			?>
            <div class="fkcart-item-product-stock">
                <div class="fkcart-item-price">
					<?php echo wp_kses_post( $product->get_price_html() ); ?>
                </div>
            </div>
            <div class="fkcart-item-custom fkcart-hide">
                <div class="fkcart-item-price">
					<?php echo wp_kses_post( $product->get_price_html() ); ?>
                </div>
            </div>
			<?php
		}
	}
}