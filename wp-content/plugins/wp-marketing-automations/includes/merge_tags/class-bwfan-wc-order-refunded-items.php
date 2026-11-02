<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_WC_Order_Refunded_Items extends Merge_Tag_Abstract_Product_Display {

	private static $instance = null;

	public $supports_order_table = true;

	public function __construct() {
		$this->tag_name        = 'order_refunded_items';
		$this->tag_description = __( 'Order Refunded Items', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_order_refunded_items', array( $this, 'parse_shortcode' ) );
		$this->support_fallback = false;
		$this->priority         = 1;
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
	 * @return mixed|void
	 */
	public function parse_shortcode( $attr ) {
		if ( false === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			$order_id  = BWFAN_Merge_Tag_Loader::get_data( 'order_id' );
			$refund_id = BWFAN_Merge_Tag_Loader::get_data( 'refund_id' );

			$order       = wc_get_order( $order_id );
			$refund      = wc_get_order( $refund_id );
			$this->order = $order;
			if ( ! $order || ! $refund || ! $order instanceof WC_Order ) {
				return $this->parse_shortcode_output( '', $attr );
			}

			$total_refund = $refund->get_amount();
			$order_total  = $order->get_total();

			// Check if the total refund amount equals the order total (full refund)
			$is_full_refund = ( $total_refund == $order_total );

			$items             = $is_full_refund ? $order->get_items() : $refund->get_items();
			$products          = [];
			$products_quantity = [];
			$products_price    = [];
			$tax_display       = get_option( 'woocommerce_tax_display_cart' );

			foreach ( $items as $item ) {
				if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
					continue;
				}

				$product = $item->get_product();
				if ( ! $product instanceof WC_Product ) {
					continue;
				}

				$products[]                              = $product;
				$products_quantity[ $product->get_id() ] = $item->get_quantity();
				$line_total                              = ( 'excl' === $tax_display ) ? BWFAN_Common::get_line_subtotal( $item ) : BWFAN_Common::get_line_subtotal( $item ) + BWFAN_Common::get_line_subtotal_tax( $item );
				$products_price[ $product->get_id() ]    = $line_total;
			}

			$this->products          = $products;
			$this->products_quantity = $products_quantity;
			$this->products_price    = $products_price;
		}

		$output = $this->process_shortcode( $attr );

		return $this->parse_shortcode_output( $output, $attr );
	}

	/**
	 * Return merge tag schema
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
				'value' => 'order-table',
				'label' => __( 'WooCommerce Order Summary Layout', 'wp-marketing-automations' ),
			],
			[
				'value' => 'list-comma-separated',
				'label' => __( 'List - Comma Separated (Product Names only)', 'wp-marketing-automations' ),
			],
			[
				'value' => 'list-comma-separated-with-quantity',
				'label' => __( 'List - Comma Separated (Product Names with Quantity)', 'wp-marketing-automations' ),
			]
		];

		return [
			[
				'id'          => 'template',
				'type'        => 'select',
				'options'     => $options,
				'label'       => __( 'Select Template', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => __( 'Product Grid - 2 Column', 'wp-marketing-automations' ),
				"required"    => false,
				"description" => ""
			],
		];
	}
}

/**
 * Register this merge tag to a group.
 */
if ( bwfan_is_woocommerce_active() ) {
	BWFAN_Merge_Tag_Loader::register( 'wc_order_refund', 'BWFAN_WC_Order_Refunded_Items', null, __( 'Order', 'wp-marketing-automations' ) );
}
