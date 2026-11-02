<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_Site_Logo extends BWFAN_Merge_Tag {

	private static $instance = null;

	public function __construct() {
		$this->tag_name        = 'site_logo';
		$this->tag_description = __( 'Site Logo', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_site_logo', array( $this, 'parse_shortcode' ) );
		$this->support_fallback = false;
		$this->is_crm_broadcast = true;
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
		$block_editor_setting = BWFAN_Common::get_block_editor_settings();
		if ( ! isset( $block_editor_setting['site'] ) || ! isset( $block_editor_setting['site']['logo'] ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		return $this->parse_shortcode_output( $block_editor_setting['site']['logo'], $attr );
	}

}

/**
 * Register this merge tag to a group.
 */
BWFAN_Merge_Tag_Loader::register( 'bwfan_default', 'BWFAN_Site_Logo', null, __( 'General', 'wp-marketing-automations' ) );
