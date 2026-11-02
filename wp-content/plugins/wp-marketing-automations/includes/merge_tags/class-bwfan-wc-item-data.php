<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_WC_Item_Data extends BWFAN_Cart_Display {

	private static $instance = null;


	public function __construct() {
		$this->tag_name        = 'item_data';
		$this->tag_description = __( 'Purchased Item Data', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_item_data', array( $this, 'parse_shortcode' ) );
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
			return $this->get_dummy_preview( $attr );
		}

		$this->item_type = 'item_data';
		try {
			$value = $this->get_item_details( $attr );
		} catch ( Exception $e ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$type = isset( $attr['type'] ) ? $attr['type'] : '';

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
				return $this->get_date_value( gmdate( 'Y-m-d H:i:s' ), $attr );
			case 'price':
				return $this->get_price_value( 1255.50, $attr );
			default:
				return __( 'Value of the key', 'wp-marketing-automations' ) . ' (' . ( $attr['key'] ?? 'key' ) . ')';
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

		return $this->format_datetime( $value, $attr );
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
		$item_id = BWFAN_Merge_Tag_Loader::get_data( 'wc_single_item_id' );
		$order   = null;

		if ( ! empty( $item_id ) ) {
			try {
				$item = new WC_Order_Item_Product( $item_id );
				if ( $item instanceof WC_Order_Item_Product ) {
					$order = $item->get_order();
				}
			} catch ( Exception $e ) {
				// Order not found, continue without it
			}
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
				'hint'        => __( 'Input the correct meta key in order to get the data', 'wp-marketing-automations' ),
				'required'    => true,
				'toggler'     => array(),
			],
			[
				'id'          => 'format',
				'type'        => 'select',
				'options'     => $date_formats,
				'label'       => __( 'Date Format', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"placeholder" => __( 'Select', 'wp-marketing-automations' ),
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
	BWFAN_Merge_Tag_Loader::register( 'wc_items', 'BWFAN_WC_Item_Data', null, __( 'Order Item', 'wp-marketing-automations' ) );
}
