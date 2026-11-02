<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_WC_Cart_Abandoned_Data extends Cart_Merge_Tag {

	private static $instance = null;

	public function __construct() {
		$this->tag_name        = 'cart_abandoned_data';
		$this->tag_description = __( 'Cart Abandoned Data', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_cart_abandoned_data', array( $this, 'parse_shortcode' ) );
		$this->support_fallback = false;
		$this->support_date     = true;
		$this->priority         = 5;
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Show the html in popup for the merge tag.
	 */
	public function get_view() {
		$this->get_back_button();
		$this->data_key();
		if ( $this->support_fallback ) {
			$this->get_fallback();
		}

		$this->get_preview();
		$this->get_copy_button();
	}

	/**
	 * Parse the merge tag and return its value.
	 *
	 * @param $attr
	 *
	 * @return mixed|string|void
	 */
	public function parse_shortcode( $attr ) {
		if ( true === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			return $this->parse_shortcode_output( $this->get_dummy_preview( $attr ), $attr );
		}

		if ( empty( $attr['key'] ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$key = $attr['key'];

		$cart_details = BWFAN_Merge_Tag_Loader::get_data( 'cart_details' );

		if ( empty( $cart_details ) ) {
			$abandoned_id = BWFAN_Merge_Tag_Loader::get_data( 'cart_abandoned_id' );
			$cart_details = BWFAN_Model_Abandonedcarts::get( $abandoned_id );
		}

		if ( empty( $cart_details ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$field_value = $this->get_cart_value( $key, $cart_details );

		$type = isset( $attr['type'] ) ? $attr['type'] : '';

		switch ( $type ) {
			case 'date':
				$field_value = $this->get_date_value( $field_value, $attr );
				break;
			case 'price':
				$field_value = $this->get_price_value( $field_value, $attr, $cart_details );
				break;
			default:
				break;
		}

		return $this->parse_shortcode_output( $field_value, $attr );
	}

	/**
	 * Show dummy value of the current merge tag.
	 *
	 * @param array $attr
	 *
	 * @return string
	 */
	public function get_dummy_preview( $attr = array() ) {
		$type = isset( $attr['type'] ) ? $attr['type'] : '';
		// If type is price, show formatted price preview
		switch ( $type ) {
			case 'date':
				if ( ! is_array( $attr ) || ! isset( $attr['format'] ) || empty( $attr['format'] ) ) {
					return __( 'Key value', 'wp-marketing-automations' );
				}

				return $this->format_datetime( gmdate( 'Y-m-d H:i:s' ), $attr );
			case 'price':
				return $this->get_price_value( 1255.50, $attr );
			default:
				return __( 'Key value', 'wp-marketing-automations' ) . ' (' . ( $attr['key'] ?? 'key' ) . ')';
		}
	}

	/**
	 * Get formatted date value
	 *
	 * @param $value
	 * @param $attr
	 *
	 * @return false|string
	 */
	public function get_date_value( $value, $attr ) {
		if ( ! is_array( $attr ) || ! isset( $attr['type'] ) || 'date' !== $attr['type'] || ! isset( $attr['format'] ) || empty( $attr['format'] ) ) {
			return $value;
		}
		$is_gmt = ! empty( $attr['is_gmt'] ) && 'false' !== $attr['is_gmt'];

		return $this->format_datetime( $value, $attr, $is_gmt );
	}

	/**
	 * Get formatted price value
	 *
	 * @param $value
	 * @param $attr
	 * @param $cart_details
	 *
	 * @return string|float
	 */
	public function get_price_value( $value, $attr, $cart_details = null ) {
		if ( ! is_array( $attr ) || ! isset( $attr['type'] ) || 'price' !== $attr['type'] ) {
			return $value;
		}

		// Get cart details for currency if available
		$cart_details = empty( $cart_details ) ? BWFAN_Merge_Tag_Loader::get_data( 'cart_details' ) : $cart_details;
		$order        = null;

		// Try to get order from cart details if available
		if ( ! empty( $cart_details ) && isset( $cart_details['order_id'] ) && ! empty( $cart_details['order_id'] ) ) {
			$order = wc_get_order( $cart_details['order_id'] );
		}

		// Convert value to float for price formatting
		$price_value = is_numeric( $value ) ? floatval( $value ) : 0;

		// Map price_display to format for compatibility with get_formatting_for_wc_price
		$price_attr = $attr;
		if ( isset( $attr['price_display'] ) ) {
			$price_attr['format'] = $attr['price_display'];
		}

		// Get formatting options
		$formatting = BWFAN_Common::get_formatting_for_wc_price( $price_attr, $order );

		// Without an order, use the currency stored with the cart instead of the store currency
		if ( ! $order instanceof WC_Order && ! empty( $formatting['currency'] ) && ! empty( $cart_details['currency'] ) ) {
			$cart_symbol = '';
			if ( method_exists( 'BWF_Plugin_Compatibilities', 'get_currency_symbol' ) ) {
				$cart_symbol = BWF_Plugin_Compatibilities::get_currency_symbol( $cart_details['currency'] );
			}
			$cart_symbol            = empty( $cart_symbol ) ? get_woocommerce_currency_symbol( $cart_details['currency'] ) : $cart_symbol;
			$formatting['currency'] = html_entity_decode( $cart_symbol, ENT_QUOTES | ENT_HTML401 );
		}

		// Format the price
		return BWFAN_Common::get_formatted_price_wc( $price_value, $formatting['raw'], $formatting['currency'] );
	}

	/**
	 * Return mergetag schema
	 *
	 * @return array[]
	 */
	public function get_setting_schema() {
		$formats      = $this->date_formats;
		$date_formats = [];
		foreach ( $formats as $data ) {
			if ( isset( $data['format'] ) ) {
				$date_time      = gmdate( $data['format'] );
				$date_formats[] = [
					'value' => $data['format'],
					'label' => $date_time,
				];
			}
		}

		return [
			[
				'id'          => 'key',
				'label'       => __( 'Meta Key', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => '',
				'placeholder' => '',
				'hint'        => __( 'Input the correct meta key in order to get the data', 'wp-marketing-automations' ),
				'required'    => true,
				'toggler'     => array(),
			],
			[
				'id'          => 'type',
				'type'        => 'select',
				'options'     => [
					[
						'value' => '',
						'label' => __( 'Text', 'wp-marketing-automations' ),
					],
					[
						'value' => 'date',
						'label' => __( 'Date', 'wp-marketing-automations' ),
					],
					[
						'value' => 'price',
						'label' => __( 'Price', 'wp-marketing-automations' ),
					]
				],
				'label'       => __( 'Meta Field Type', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"required"    => false,
				'placeholder' => __( 'Select', 'wp-marketing-automations' ),
			],
			[
				'id'          => 'input_format',
				'type'        => 'select',
				'options'     => $date_formats,
				'label'       => __( 'Date Saved Format', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => __( 'Select', 'wp-marketing-automations' ),
				"required"    => false,
				'hint'        => __( 'Select the date format in which date value is saved on the meta key', 'wp-marketing-automations' ),
				'toggler'     => array(
					'fields'   => array(
						array(
							'id'    => 'type',
							'value' => 'date',
						),
					),
					'relation' => 'AND',
				)
			],
			[
				'id'          => 'format',
				'type'        => 'select',
				'options'     => $date_formats,
				'label'       => __( 'Date Output Format', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => __( 'Select', 'wp-marketing-automations' ),
				"required"    => false,
				'hint'        => __( 'Desired date output format', 'wp-marketing-automations' ),
				'toggler'     => array(
					'fields'   => array(
						array(
							'id'    => 'type',
							'value' => 'date',
						),
					),
					'relation' => 'AND',
				)
			],
			[
				'id'            => 'is_gmt',
				'type'          => 'checkbox',
				'checkboxlabel' => __( 'In GMT time ( Default in store time )', 'wp-marketing-automations' ),
				'description'   => '',
				"toggler"       => array(
					'fields'   => array(
						array(
							'id'    => 'type',
							'value' => 'date',
						),
					),
					'relation' => 'AND',
				)
			],
			[
				'id'          => 'price_display',
				'type'        => 'select',
				'options'     => [
					[
						'value' => 'raw',
						'label' => __( 'Raw', 'wp-marketing-automations' ),
					],
					[
						'value' => 'formatted',
						'label' => __( 'Formatted', 'wp-marketing-automations' ),
					],
					[
						'value' => 'formatted-currency',
						'label' => __( 'Formatted with currency', 'wp-marketing-automations' ),
					],
				],
				'label'       => __( 'Display', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => __( 'Raw', 'wp-marketing-automations' ),
				"required"    => false,
				"description" => "",
				'toggler'     => array(
					'fields'   => array(
						array(
							'id'    => 'type',
							'value' => 'price',
						),
					),
					'relation' => 'AND',
				)
			]
		];
	}
}

/**
 * Register this merge tag to a group.
 */
if ( bwfan_is_woocommerce_active() ) {
	BWFAN_Merge_Tag_Loader::register( 'wc_ab_cart', 'BWFAN_WC_Cart_Abandoned_Data', null, __( 'Abandoned Cart', 'wp-marketing-automations' ) );
}
