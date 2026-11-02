<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_WC_Cart_Items extends Merge_Tag_Abstract_Product_Display {

	private static $instance = null;

	public $supports_cart_table = true;

	public function __construct() {
		$this->tag_name        = 'cart_items';
		$this->tag_description = __( 'Cart Items', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_cart_items', array( $this, 'parse_shortcode' ) );
		$this->support_fallback = false;
		$this->priority         = 2;
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Parse the merge tag and return its value.
	 *
	 * @param $attr
	 *
	 * @return mixed|string|void
	 */
	public function parse_shortcode( $attr ) {
		if ( false !== BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			$random_products = BWFAN_Common::$random_products;
			if ( empty( $random_products ) ) {
				$args                          = array(
					'posts_per_page' => 3,
					'orderby'        => 'rand',
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'fields'         => 'ids',
				);
				$random_products               = get_posts( $args );
				BWFAN_Common::$random_products = $random_products;
			}
			$cart          = [];
			$product_qty   = [];
			$product_sku   = [];
			$product_price = [];
			$total         = 0;
			foreach ( $random_products as $product_id ) {
				if ( intval( $product_id ) <= 0 ) {
					continue;
				}
				$cart_item = [];

				$pro_obj = wc_get_product( $product_id );
				if ( ! $pro_obj instanceof WC_Product ) {
					continue;
				}

				$price = $pro_obj->get_price();
				if ( empty( $price ) ) {
					$price = 0;
				}
				$cart_item['data']                   = $pro_obj;
				$cart_item['line_subtotal']          = $price;
				$cart_item['line_subtotal_tax']      = 0;
				$cart_item['line_total']             = $price;
				$cart_item['line_tax']               = 0;
				$product_qty[ $pro_obj->get_id() ]   = wp_rand( 1, 5 );
				$product_sku[ $pro_obj->get_id() ]   = $pro_obj->get_sku();
				$product_price[ $pro_obj->get_id() ] = ! empty( $price ) ? wc_price( $price ) : 0;

				$cart[ $pro_obj->get_id() ] = $cart_item;

				$total = $total + $price;
			}
			$this->cart              = $cart;
			$this->products_quantity = $product_qty;
			$this->products_sku      = $product_sku;
			$this->products_price    = $product_price;
			$shipping_total          = 10.00;
			$discount_total          = 15.00;
			$fees                    = [
				(object) [
					'name'  => WC()->countries->tax_or_vat(),
					'total' => 5.00,
				]
			];

			$coupons    = [
				[
					'code'              => 'PREVIEW15',
					'discount_incl_tax' => $discount_total,
					'discount_excl_tax' => $discount_total,
					'discount_tax'      => 0
				]
			];
			$this->data = [
				'coupons'            => $coupons,
				'fees'               => $fees,
				'shipping_total'     => $shipping_total,
				'shipping_tax_total' => 0,
				'total'              => $total,
				'currency'           => get_woocommerce_currency(),
				'lang'               => get_locale()
			];

			$result = $this->process_shortcode( $attr );

			return $this->parse_shortcode_output( $result, $attr );
		}

		$cart_details = BWFAN_Merge_Tag_Loader::get_data( 'cart_details' );

		if ( empty( $cart_details ) ) {
			$abandoned_id = BWFAN_Merge_Tag_Loader::get_data( 'cart_abandoned_id' );
			$cart_details = BWFAN_Model_Abandonedcarts::get( $abandoned_id );
		}

		if ( empty( $cart_details ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$checkout_data    = isset( $cart_details['checkout_data'] ) ? $cart_details['checkout_data'] : '';
		$checkout_data    = json_decode( $checkout_data, true );
		$lang             = is_array( $checkout_data ) && isset( $checkout_data['lang'] ) ? $checkout_data['lang'] : '';
		$items            = apply_filters( 'bwfan_abandoned_cart_items_visibility', maybe_unserialize( $cart_details['items'] ) );
		$tax_display      = get_option( 'woocommerce_tax_display_cart' );
		$currency         = is_array( $cart_details ) & isset( $cart_details['currency'] ) ? maybe_unserialize( $cart_details['currency'] ) : '';
		$products         = [];
		$product_quantity = [];
		$product_sku      = [];
		$product_price    = [];
		foreach ( $items as $item ) {
			if ( ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$products[] = $item['data'];

			$product_quantity[ $item['data']->get_id() ] = $item['quantity'];
			$product_sku[ $item['data']->get_id() ]      = $item['data']->get_sku();

			$line_total = ( 'excl' === $tax_display ) ? BWFAN_Common::get_line_subtotal( $item ) : BWFAN_Common::get_line_subtotal( $item ) + BWFAN_Common::get_line_subtotal_tax( $item );

			$product_price[ $item['data']->get_id() ] = $line_total;
		}

		$this->cart              = $items;
		$this->products_quantity = $product_quantity;
		$this->products_sku      = $product_sku;
		$this->products_price    = $product_price;
		$this->data              = [
			'coupons'            => maybe_unserialize( $cart_details['coupons'] ),
			'fees'               => maybe_unserialize( $cart_details['fees'] ),
			'shipping_total'     => maybe_unserialize( $cart_details['shipping_total'] ),
			'shipping_tax_total' => maybe_unserialize( $cart_details['shipping_tax_total'] ),
			'total'              => maybe_unserialize( $cart_details['total'] ),
			'currency'           => $currency,
			'lang'               => $lang
		];
		$this->products          = $products;

		$result = $this->process_shortcode( $attr );

		return $this->parse_shortcode_output( $result, $attr );
	}

	/**
	 * Return mergetag schema
	 *
	 * @return array[]
	 */
	public function get_setting_schema() {

		$options = [
			[
				'value' => '',
				'label' => __( 'Product Grid - 2 Column', 'wp-marketing-automations' ),
			],
			[
				'value' => 'product-grid-3-col',
				'label' => __( 'Product Grid - 3 Column', 'wp-marketing-automations' ),
			],
			[
				'value' => 'product-rows',
				'label' => __( 'Product Rows', 'wp-marketing-automations' ),
			],
			[
				'value' => 'cart-table',
				'label' => __( 'Cart Table Layout', 'wp-marketing-automations' ),
			],
			[
				'value' => 'list',
				'label' => __( 'Product List  (Comma Separated)', 'wp-marketing-automations' ),
			],
			[
				'value' => 'line',
				'label' => __( 'Product List  (Line Separated)', 'wp-marketing-automations' ),
			],
		];

		$option_type      = [
			[
				'value' => 'comma-separated',
				'label' => __( 'Product Names', 'wp-marketing-automations' ),
			],
			[
				'value' => 'comma-separated-with-sku',
				'label' => __( 'Product SKU', 'wp-marketing-automations' ),
			],
			[
				'value' => 'comma-separated-with-quantity',
				'label' => __( 'Product Names with Quantity', 'wp-marketing-automations' ),
			],
			[
				'value' => 'comma-separated-with-name-sku',
				'label' => __( 'Product Names with SKU', 'wp-marketing-automations' ),
			],
			[
				'value' => 'comma-separated-with-name-price',
				'label' => __( 'Product Names with Price', 'wp-marketing-automations' ),
			],
			[
				'value' => 'comma-separated-product-ids',
				'label' => __( 'Product IDs - Comma Separated  ', 'wp-marketing-automations' ),
			]
		];
		$option_type_line = [
			[
				'value' => 'separated-product-name',
				'label' => __( 'Line Separated (Product Names)  ', 'wp-marketing-automations' ),
			],
			[
				'value' => 'separated-product-name-with-quantity',
				'label' => __( 'Line Separated (Product Names with Quantity)', 'wp-marketing-automations' ),
			],
		];

		return [
			[
				'id'          => 'template',
				'type'        => 'select',
				'options'     => $options,
				'label'       => __( 'Select Template', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => 'Product Grid - 2 Column',
				"required"    => false,
				"description" => ""
			],
			[
				'id'          => 'type',
				'type'        => 'select',
				'options'     => $option_type,
				'label'       => __( 'Select List Type', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => '',
				"required"    => false,
				"description" => "",
				"toggler"     => [
					'fields'   => [
						[
							'id'    => 'template',
							'value' => 'list'
						]
					],
					'relation' => 'AND',
				],
			],
			[
				'id'          => 'type_line',
				'type'        => 'select',
				'options'     => $option_type_line,
				'label'       => __( 'Select Line Type', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => '',
				"required"    => false,
				"description" => "",
				"toggler"     => [
					'fields'   => [
						[
							'id'    => 'template',
							'value' => 'line'
						]
					],
					'relation' => 'AND',
				],
			],
		];
	}

	/**
	 * Return merge tag default schema
	 *
	 * @return array
	 */
	public function get_default_values() {
		return [
			'template'  => '',
			'type'      => 'comma-separated',
			'type_line' => 'separated-product-name'
		];
	}
}

/**
 * Register this merge tag to a group.
 *
 */
if ( bwfan_is_woocommerce_active() ) {
	BWFAN_Merge_Tag_Loader::register( 'wc_ab_cart', 'BWFAN_WC_Cart_Items', null, __( 'Abandoned Cart', 'wp-marketing-automations' ) );
}