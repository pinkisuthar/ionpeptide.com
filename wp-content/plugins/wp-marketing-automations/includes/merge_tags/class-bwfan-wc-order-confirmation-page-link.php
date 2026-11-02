<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_WC_Order_Confirmation_Page_Link extends BWFAN_Merge_Tag {

	private static $instance = null;

	public function __construct() {
		$this->tag_name        = 'order_confirmation_page_link';
		$this->tag_description = __( 'Order Confirmation Page URL', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_order_confirmation_page_link', array( $this, 'parse_shortcode' ) );
		$this->support_fallback = false;
		$this->priority         = 3;
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

		$get_data = BWFAN_Merge_Tag_Loader::get_data();
		$order    = $this->get_order_object( $get_data );
		if ( empty( $order ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		/** Switch WPML language to order's language before generating URL */
		$switched = false;
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && $order instanceof WC_Order ) {
			global $sitepress;
			$order_lang = $order->get_meta( 'wpml_language' );
			if ( ! empty( $order_lang ) && isset( $sitepress ) && class_exists( 'SitePress' ) && $sitepress instanceof SitePress ) {
				$current_lang = $sitepress->get_current_language();
				if ( $current_lang !== $order_lang ) {
					$sitepress->switch_lang( $order_lang );
					$switched = $current_lang;
				}
			}
		}

		$order_received_url = '';

		try {
			$order_received_url = $order->get_checkout_order_received_url();
		} finally {
			/** Restore original language */
			if ( false !== $switched ) {
				global $sitepress;
				$sitepress->switch_lang( $switched );
			}
		}

		return $this->parse_shortcode_output( $order_received_url, $attr );
	}

	/**
	 * Show dummy value of the current merge tag.
	 *
	 * @return integer
	 */
	public function get_dummy_preview() {
		return site_url();
	}
}

/**
 * Register this merge tag to a group.
 */
if ( bwfan_is_woocommerce_active() ) {
	BWFAN_Merge_Tag_Loader::register( 'wc_order', 'BWFAN_WC_Order_Confirmation_Page_Link', null, __( 'Order', 'wp-marketing-automations' ) );
}
