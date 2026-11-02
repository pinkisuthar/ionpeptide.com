<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Bwfan Wc Order Data
 *
 * @since 1.0.0
 */
#[\AllowDynamicProperties]
class BWFAN_WC_Order_Data extends BWFAN_Merge_Tag {

	private static $instance = null;


	/**
	 * Construct
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->tag_name        = 'order_data';
		$this->tag_description = __( 'Order Data', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_order_data', array( $this, 'parse_shortcode' ) );
		$this->priority          = 5;
		$this->support_date      = true;
		$this->is_delay_variable = true;
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
		if ( ! is_array( $attr ) || empty( $attr['key'] ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$item_key = $attr['key'];
		$order    = $this->get_order_obj();
		if ( ! $order instanceof WC_Order ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$value = $this->get_value_of_nested_key( $item_key, $order );

		if ( class_exists( 'WFACP_Common' ) ) {
			$value = $this->get_wfacp_label( $item_key, $value );
		}

		if ( empty( $value ) && strpos( $item_key, '_order' ) === 0 ) {
			$item_key = substr( $item_key, 6 );
			$value    = $this->get_value_of_nested_key( $item_key, $order );
		}

		$type = isset( $attr['type'] ) ? $attr['type'] : '';

		// Only apply nl2br for text (non-date, non-price) fields
		if ( empty( $type ) && ! empty( $value ) && is_string( $value ) ) {
			$value = nl2br( $value );
		}

		switch ( $type ) {
			case 'date':
				$value = $this->get_date_value( $value, $attr );
				break;
			case 'price':
				$value = $this->get_price_value( $value, $attr );
				break;
			default:
				break;
		}

		return $this->parse_shortcode_output( $value, $attr );
	}

	/**
	 * Get the value of the key from the nested array
	 *
	 * @param string   $item_key The meta key, potentially with dot notation for nested values.
	 * @param WC_Order $order    The WooCommerce order object.
	 *
	 * @return mixed The meta value or empty string if not found.
	 */
	public function get_value_of_nested_key( $item_key, $order ) {
		$keys = explode( '.', $item_key );

		// Single key, no nesting
		if ( count( $keys ) === 1 ) {
			return $order->get_meta( $item_key );
		}

		// Get the first level meta value
		$value = $order->get_meta( $keys[0] );

		// Traverse nested keys
		for ( $i = 1; $i < count( $keys ); $i++ ) {
			if ( ! is_array( $value ) || ! isset( $value[ $keys[ $i ] ] ) ) {
				return '';
			}
			$value = $value[ $keys[ $i ] ];
		}

		return $value;
	}

	/**
	 * Show dummy value of the current merge tag.
	 *
	 * @return string
	 */
	public function get_dummy_preview( $attr ) {
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
				return __( 'Key value', 'wp-marketing-automations' );
		}
	}

	/**
	 * Get Order Obj
	 *
	 * @since 1.0.0
	 */
	protected function get_order_obj() {
		$order = BWFAN_Merge_Tag_Loader::get_data( 'wc_order' );
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		$order_id = BWFAN_Merge_Tag_Loader::get_data( 'order_id' );
		if ( empty( $order_id ) ) {
			return '';
		}

		$order = wc_get_order( $order_id );

		return ( $order instanceof WC_Order ) ? $order : '';
	}

	/**
	 * fetch label for dropdown and radio type
	 *
	 * @param $order_id
	 * @param $item_key
	 * @param $return_value
	 *
	 * @return mixed
	 */
	public function get_wfacp_label( $item_key, $return_value ) {
		$order = $this->get_order_obj();
		if ( ! $order instanceof WC_Order ) {
			return $return_value;
		}

		$wfacp_id = $order->get_meta( '_wfacp_post_id' );
		if ( empty( $wfacp_id ) ) {
			return $return_value;
		}

		$custom_field = WFACP_Common::get_checkout_fields( $wfacp_id );
		if ( empty( $custom_field ) || ! isset( $custom_field['advanced'] ) || ! isset( $custom_field['advanced'][ $item_key ] ) ) {
			return $return_value;
		}

		$valid_field_types = [ 'select', 'wfacp_radio' ];

		$field = $custom_field['advanced'][ $item_key ];
		if ( ! isset( $field['type'] ) || ! in_array( $field['type'], $valid_field_types, true ) ) {
			return $return_value;
		}

		if ( ! isset( $field['options'] ) || empty( $field['options'] ) || ! isset( $field['options'][ $return_value ] ) ) {
			return $return_value;
		}

		return empty( $field['options'][ $return_value ] ) ? $return_value : $field['options'][ $return_value ];
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
	 *
	 * @return string|float
	 */
	public function get_price_value( $value, $attr ) {
		if ( ! is_array( $attr ) || ! isset( $attr['type'] ) || 'price' !== $attr['type'] ) {
			return $value;
		}

		// Get the order for currency formatting
		$order = $this->get_order_obj();

		// Convert value to float for price formatting
		$price_value = is_numeric( $value ) ? floatval( $value ) : 0;

		// Map price_display to format for compatibility with get_formatting_for_wc_price
		$price_attr = $attr;
		if ( isset( $attr['price_display'] ) ) {
			$price_attr['format'] = $attr['price_display'];
		}

		// Get formatting options
		$formatting = BWFAN_Common::get_formatting_for_wc_price( $price_attr, $order );

		// Format the price
		return BWFAN_Common::get_formatted_price_wc( $price_value, $formatting['raw'], $formatting['currency'] );
	}

	/**
	 * Return merge tag schema
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
				'hint'        => __( 'Input the correct meta key in order to get the data. If the meta key is nested, use dot (.) to separate the keys.', 'wp-marketing-automations' ),
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
				'label'       => __( 'Date Field Format', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => 'Select',
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
				'label'       => __( 'Output Format', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => 'Select',
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

	/**
	 * Return merge tag delay step schema
	 *
	 * @return array[]
	 */
	public function get_delay_setting_schema() {
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
				'hint'        => __( 'Input the correct meta key in order to get the data. If the meta key is nested, use dot (.) to separate the keys.', 'wp-marketing-automations' ),
				'required'    => true,
				'toggler'     => array(),
			],
			[
				'id'          => 'format',
				'type'        => 'select',
				'options'     => $date_formats,
				'label'       => __( 'Date Format', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => 'Select',
				'hint'        => __( 'Select the date format in which date value is saved on the meta key', 'wp-marketing-automations' ),
				"required"    => false,
				"description" => "",
				'toggler'     => array()
			],
		];
	}
}

/**
 * Register this merge tag to a group.
 */
if ( bwfan_is_woocommerce_active() ) {
	BWFAN_Merge_Tag_Loader::register( 'wc_order', 'BWFAN_WC_Order_Data', null, __( 'Order', 'wp-marketing-automations' ) );
}
