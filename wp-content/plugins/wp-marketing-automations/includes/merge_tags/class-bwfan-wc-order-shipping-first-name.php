<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class BWFAN_WC_Order_Shipping_First_Name
 *
 * Merge tag outputs order shipping first name
 *
 * Since 2.0.6
 */
#[\AllowDynamicProperties]
class BWFAN_WC_Order_Shipping_First_Name extends BWFAN_Merge_Tag {

	private static $instance = null;

	public function __construct() {
		$this->tag_name        = 'order_shipping_first_name';
		$this->tag_description = __( 'Order Shipping First Name', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_order_shipping_first_name', array( $this, 'parse_shortcode' ) );
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
		if ( true === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			return $this->parse_shortcode_output( $this->get_dummy_preview(), $attr );
		}
		$order_id = BWFAN_Merge_Tag_Loader::get_data( 'order_id' );
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$shipping_first_name = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_shipping_first_name' );

		if ( empty( $shipping_first_name ) && true === apply_filters( 'bwfan_shipping_values_override_from_billing', true ) ) {
			$shipping_first_name = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_billing_first_name' );
		}

		return $this->parse_shortcode_output( $shipping_first_name, $attr );
	}

	/**
	 * Show dummy value of the current merge tag.
	 *
	 * @return string
	 */
	public function get_dummy_preview() {
		$contact    = $this->get_contact_data();
		$first_name = 'John';
		/** check for contact instance and the contact id */
		if ( ! $contact instanceof WooFunnels_Contact || 0 === absint( $contact->get_id() ) ) {
			return $first_name;
		}

		/** If empty */
		if ( empty( $contact->get_f_name() ) ) {
			return $first_name;
		}

		return ucfirst( $contact->get_f_name() );
	}
}

/**
 * Register this merge tag to a group.
 */
if ( bwfan_is_woocommerce_active() ) {
	BWFAN_Merge_Tag_Loader::register( 'wc_order', 'BWFAN_WC_Order_Shipping_First_Name', null, __( 'Order', 'wp-marketing-automations' ) );
}
