<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_WC_Order_Refund_Reason extends BWFAN_Merge_Tag {

	private static $instance = null;

	public function __construct() {
		$this->tag_name        = 'order_refund_reason';
		$this->tag_description = __( 'Order Refund Reason', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_order_refund_reason', array( $this, 'parse_shortcode' ) );
		$this->support_fallback = false;
		$this->support_v2       = true;
		$this->support_v1       = false;
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function parse_shortcode( $attr ) {
		if ( true === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			return $this->get_dummy_preview( $attr );
		}

		$refund_id = BWFAN_Merge_Tag_Loader::get_data( 'refund_id' );
		if ( empty( $refund_id ) || ! class_exists( 'WC_Order_Refund' ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$refund = new WC_Order_Refund( $refund_id );
		if ( ! $refund instanceof WC_Order_Refund || empty( $refund->get_reason() ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		return $this->parse_shortcode_output( $refund->get_reason(), $attr );
	}

	/**
	 * Show dummy value of the current merge tag.
	 *
	 * @return string
	 */
	public function get_dummy_preview( $attr ) {
		return __( 'Refund reason here', 'wp-marketing-automations' );
	}
}

/**
 * Register this merge tag to a group.
 */
if ( bwfan_is_woocommerce_active() ) {
	BWFAN_Merge_Tag_Loader::register( 'wc_order_refund', 'BWFAN_WC_Order_Refund_Reason', null, __( 'Order', 'wp-marketing-automations' ) );
}
