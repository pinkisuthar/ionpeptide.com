<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class BWFAN_WC_Order_Admin_Edit_Link
 *
 * Merge tag outputs order admin edit link
 *
 * Since 2.0.6
 */
#[\AllowDynamicProperties]
class BWFAN_WC_Order_Admin_Edit_Link extends BWFAN_Merge_Tag {

	private static $instance = null;

	public function __construct() {
		$this->tag_name        = 'order_admin_edit_link';
		$this->tag_description = __( 'Admin Order Edit Link', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_order_admin_edit_link', array( $this, 'parse_shortcode' ) );
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

		if ( empty( $order_id ) ) {
			$order = BWFAN_Merge_Tag_Loader::get_data( 'wc_order' );
			if ( $order instanceof WC_Order ) {
				$order_id = $order->get_id();
			} else {
				return $this->parse_shortcode_output( '', $attr );
			}
		}

		$link = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

		return $this->parse_shortcode_output( $link, $attr );
	}

	/**
	 * Show dummy value of the current merge tag.
	 *
	 * @return string
	 */
	public function get_dummy_preview() {
		return site_url();
	}
}

/**
 * Register this merge tag to a group.
 */
if ( bwfan_is_woocommerce_active() ) {
	BWFAN_Merge_Tag_Loader::register( 'wc_order', 'BWFAN_WC_Order_Admin_Edit_Link', null, __( 'Order', 'wp-marketing-automations' ) );
}
