<?php
/**
 * "Set referrer" box shortcode
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Set_Referrer_Shortcode' ) ) {
	/**
	 * Offer methods for basic shortcode handling
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Set_Referrer_Shortcode extends YITH_WCAF_Abstract_Shortcode {

		/* === INIT === */

		/**
		 * Performs any required init operation
		 */
		public function init() {
			// configure shortcode basics.
			$this->tag         = 'yith_wcaf_set_referrer';
			$this->title       = _x( 'YITH Affiliates "Set Referrer" box', '[BUILDERS] Shortcode name', 'yith-woocommerce-affiliates' );
			$this->template    = 'form-referrer.php';
			$this->description = _x( 'Show "Set referrer" box', '[BUILDERS] Shortcode description', 'yith-woocommerce-affiliates' );
		}

		/**
		 * Returns attributes accepted for current shortcode
		 *
		 * @return array Array of supported attributes.
		 */
		public function get_atts() {
			if ( empty( $this->attributes ) ) {
				$this->attributes = array(
					'affiliate_token' => array(
						'type'    => 'text',
						'label'   => _x( 'Initial affiliate token to show', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
						'default' => '',
					),
				);
			}

			return $this->attributes;
		}

		/**
		 * Renders shortcode
		 *
		 * @param array  $atts    Array of shortcode attributes.
		 * @param string $content Shortcode content.
		 *
		 * @return string Shortcode content.
		 */
		public function render( $atts = array(), $content = '' ) {
			// skip when disabled.
			if ( ! yith_plugin_fw_is_true( get_option( 'yith_wcaf_show_checkout_box' ) ) ) {
				return '';
			}

			$atts = $this->get_template_atts( $this->parse_atts( $atts ) );

			// enqueue required assets.
			$this->enqueue();

			// retrieve shortcode template.
			ob_start();
			yith_wcaf_get_template( $this->template, $atts, 'shortcodes' );

			return ob_get_clean();
		}

		/**
		 * Filters variable submitted to template, in order to add section-specific values
		 *
		 * @param array $atts General shortcode attributes, as entered for the shortcode, or as default values.
		 *
		 * @return array Array of filtered template variables.
		 */
		public function get_template_atts( $atts ) {
			list( $affiliate_token ) = yith_plugin_fw_extract( $atts, 'affiliate_token' );

			if ( ! $affiliate_token ) {
				$affiliate_token = YITH_WCAF_Session()->get_token();
			}

			$persistent   = yith_plugin_fw_is_true( get_option( 'yith_wcaf_commission_persistent_calculation' ) );
			$avoid_change = yith_plugin_fw_is_true( get_option( 'yith_wcaf_avoid_referral_change' ) );
			$editable     = ! $persistent || ! $avoid_change || ! $affiliate_token;

			return parent::get_template_atts(
				array_merge(
					$atts,
					compact( 'affiliate_token', 'editable' ),
					// backward compatibility: these attributes are submitted only to support old versions of the templates.
					array(
						'enabled'         => yith_plugin_fw_is_true( get_option( 'yith_wcaf_show_checkout_box', 'no' ) ),
						'affiliate'       => $affiliate_token,
						'permanent_token' => $persistent && $avoid_change,
					)
				)
			);
		}
	}
}
