<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Bwfan Wc Order Review
 *
 * @since 1.0.0
 */
#[\AllowDynamicProperties]
class BWFAN_WC_Order_Review extends Merge_Tag_Abstract_Product_Display {

	private static $instance = null;

	public $supports_order_table = true;

	/**
	 *   Construct
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->tag_name        = 'order_review';
		$this->tag_description = __( 'Order Review', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_order_review', array( $this, 'parse_shortcode' ) );
		$this->support_fallback = false;
		$this->support_v1       = false;
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
			$order_id    = BWFAN_Merge_Tag_Loader::get_data( 'order_id' );
			$order       = wc_get_order( $order_id );
			$this->order = $order;

			if ( ! $this->order instanceof WC_Order ) {
				return $this->parse_shortcode_output( '', $attr );
			}

			$items             = $order->get_items();
			$products          = [];
			$products_quantity = array();
			foreach ( $items as $item ) {
				/** added handling with wc product bundle */
				if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
					continue;
				}

				$product = $item->get_product();
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$products[]                              = $product;
				$products_quantity[ $product->get_id() ] = $item->get_quantity();
			}
			if ( empty( $products ) ) {
				return $this->parse_shortcode_output( ' ', $attr );
			}
			$this->products          = $products;
			$this->products_quantity = $products_quantity;
		}

		$output = $this->process_shortcode( $attr );

		return $this->parse_shortcode_output( $output, $attr );
	}

	/**
	 * Process Shortcode
	 *
	 * @since 1.0.0
	 */
	public function process_shortcode( $attr ) {
		$products          = [];
		$products_quantity = [];

		if ( is_array( $this->products ) && count( $this->products ) > 0 ) {
			$products = $this->products;
		}

		if ( is_array( $this->products_quantity ) && count( $this->products_quantity ) > 0 ) {
			$products_quantity = $this->products_quantity;
		}

		/** Filter products in case want to hide free products */
		$hide_free_products = BWFAN_Common::hide_free_products_cart_order_items();
		if ( true === $hide_free_products ) {
			/** $products */
			$products_mod = array_filter( $products, function ( $single_product ) {
				return ( $single_product->get_price() > 0 );
			} );
			$products     = $products_mod;
		}

		if ( true === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			$products = wc_get_products( array(
				'numberposts' => 3,
				'post_status' => 'published', // Only published products
			) );
		}
		if ( apply_filters( 'bwfan_current_integration_action', false ) ) {
			$product_names = [];
			foreach ( $products as $single_product ) {
				$product_names[] = BWFAN_Common::get_name( $single_product );
			}
			$product_names = wp_json_encode( $product_names );

			return $product_names;
		}
		$this->template = 'review-rows';

		$file_path = BWFAN_PLUGIN_DIR . '/templates/' . $this->template . '.php';
		$file_path = apply_filters( 'bwfan_order_review_template_path', $file_path, $products, $attr );
		ob_start();
		include $file_path;
		$response = ob_get_clean();

		return apply_filters( 'bwfan_alter_email_body', $response, $products, $this->template, $products_quantity );
	}

	/**
	 * @return array[]
	 * Field schema for tag
	 */
	public function get_setting_schema() {
		return [
			[
				'id'       => 'button_label',
				'label'    => __( 'Review Button Label', 'wp-marketing-automations' ),
				'type'     => 'text',
				'class'    => '',
				'hint'     => '<p>' . __( 'Using multiple languages? Use the filter below to translate this text based on the selected language.', 'wp-marketing-automations' ) . ' <a href="https://funnelkit.com/docs/autonami-2/troubleshooting/request-reviews-in-the-customers-language/" target="_blank">' . __( 'Learn more', 'wp-marketing-automations' ) . '</a></p>',
				'required' => false,
				'toggler'  => array(),
			],
			[
				'id'       => 'cu_notice',
				'type'     => 'notice',
				'class'    => '',
				'status'   => 'info',
				/* translators: %s: Learn more link */
				'message'  => sprintf( __( 'The review button is hidden for products where reviews are disabled at the product level. %s', 'wp-marketing-automations' ), '<a href="https://funnelkit.com/docs/autonami-2/troubleshooting/request-reviews-in-the-customers-language/" target="_blank">' . __( 'Learn more', 'wp-marketing-automations' ) . '</a>' ),
				'dismiss'  => true,
				'required' => false,
				'isHtml'   => true,
				'toggler'  => array(),
			],
		];
	}

	/**
	 * @return array
	 * Added default text
	 */
	public function get_default_values() {
		return [
			'button_label' => __( 'Leave a review', 'wp-marketing-automations' ),
		];
	}
}

/**
 * Register this merge tag to a group.
 */
if ( bwfan_is_woocommerce_active() ) {
	BWFAN_Merge_Tag_Loader::register( 'wc_order', 'BWFAN_WC_Order_Review', null, __( 'Order', 'wp-marketing-automations' ) );
}
