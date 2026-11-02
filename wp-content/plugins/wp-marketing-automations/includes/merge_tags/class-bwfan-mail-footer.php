<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Bwfan Mail Footer
 *
 * @since 1.0.0
 */
#[\AllowDynamicProperties]
class BWFAN_Mail_Footer extends BWFAN_Merge_Tag {

	private static $instance = null;

	/** Set while the footer is being decoded, so a self-referencing footer setting cannot re-enter */
	private static $is_decoding = false;

	/**
	 *   Construct
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->tag_name        = 'mail_footer';
		$this->tag_description = __( 'Mail Footer', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_mail_footer', array( $this, 'parse_shortcode' ) );
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
		$global_settings = BWFAN_Common::get_global_settings();
		if ( ! isset( $global_settings['bwfan_email_footer_setting'] ) ) {
			return '';
		}
		$data = BWFAN_Merge_Tag_Loader::get_data();
		if ( ! empty( $data['is_preview'] ) ) {
			$footer = self::decode_footer( $global_settings['bwfan_email_footer_setting'] );

			return $this->parse_shortcode_output( $footer, $attr );
		}
		$footer = $global_settings['bwfan_email_footer_setting'];

		/**
		 * The escaping below exists because the footer is normally expanded into a template body that
		 * is stored once and replayed for every recipient, so its inner tags have to survive that trip
		 * and be resolved per recipient later.
		 *
		 * Transactional mails have no such trip. They expand the footer inside their own recipient
		 * loop with that recipient's merge tag data already set, and mail the result out directly, so
		 * escaping there only strands {_{tag}_} in the sent email - nothing on that path decodes it
		 * back. Resolve the inner tags right here instead.
		 */
		if ( empty( $data['bwfan_transactional_mail'] ) ) {
			$merge_tags       = BWFAN_Common::fetch_merge_tags( $footer );
			$business_address = array_search( '{{business_address}}', $merge_tags, true );
			if ( false !== $business_address && isset( $merge_tags[ $business_address ] ) ) {
				unset( $merge_tags[ $business_address ] );
			}
			$business_name = array_search( '{{business_name}}', $merge_tags, true );
			if ( false !== $business_name && isset( $merge_tags[ $business_name ] ) ) {
				unset( $merge_tags[ $business_name ] );
			}
			/** Temporarily replace merge tags to prevent recursive processing */
			foreach ( $merge_tags as $tag ) {
				$new_tag = str_replace( '{{', '{_{', $tag );
				$new_tag = str_replace( '}}', '}_}', $new_tag );
				$footer  = str_replace( $tag, $new_tag, $footer );
			}
		}

		$footer = self::decode_footer( $footer );

		return $this->parse_shortcode_output( $footer, $attr );
	}

	/**
	 * Decode the footer, refusing to re-enter.
	 *
	 * The footer setting is free text, so it can contain {{mail_footer}} or [bwfan_mail_footer]
	 * itself. Decoding it then calls this merge tag again, which decodes the footer again, with no
	 * bottom - a stack overflow mid-send. The template path's escaping happens to cover the {{...}}
	 * form; nothing covers the shortcode form, and the transactional path escapes neither.
	 *
	 * @param string $footer
	 *
	 * @return string
	 */
	private static function decode_footer( $footer ) {
		if ( self::$is_decoding ) {
			return '';
		}

		self::$is_decoding = true;
		try {
			return BWFAN_Common::decode_merge_tags( $footer );
		} finally {
			self::$is_decoding = false;
		}
	}

}

/**
 * Register this merge tag to a group.
 */
BWFAN_Merge_Tag_Loader::register( 'bwfan_default', 'BWFAN_Mail_Footer', null, __( 'General', 'wp-marketing-automations' ) );
