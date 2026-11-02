<?php
/**
 * "Current affiliate" shortcode
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Current_Affiliate_Shortcode' ) ) {
	/**
	 * Offer methods for basic shortcode handling
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Current_Affiliate_Shortcode extends YITH_WCAF_Abstract_Shortcode {

		/* === INIT === */

		/**
		 * Performs any required init operation
		 */
		public function init() {
			// configure shortcode basics.
			$this->tag         = 'yith_wcaf_current_affiliate';
			$this->title       = _x( 'YITH Affiliates "Current affiliate"', '[BUILDERS] Shortcode name', 'yith-woocommerce-affiliates' );
			$this->template    = 'current-affiliate.php';
			$this->description = _x( 'Show affiliate set for current session, if any', '[BUILDERS] Shortcode description', 'yith-woocommerce-affiliates' );
		}

		/**
		 * Returns attributes accepted for current shortcode
		 *
		 * @return array Array of supported attributes.
		 */
		public function get_atts() {
			if ( empty( $this->attributes ) ) {
				$this->attributes = array(
					'show_gravatar'        => array(
						'type'    => 'select',
						'label'   => _x( 'Enable to show gravatar, if any', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
						'default' => 'yes',
						'options' => array(
							'yes' => _x( 'Show gravatar', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
							'no'  => _x( 'Hide gravatar', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
						),
					),
					'show_real_name'       => array(
						'type'    => 'select',
						'label'   => _x( 'Enable to show extended affiliate\'s name', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
						'default' => 'yes',
						'options' => array(
							'yes' => _x( 'Show name', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
							'no'  => _x( 'Hide name', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
						),
					),
					'show_email'           => array(
						'type'    => 'select',
						'label'   => _x( 'Enable to show affiliate\'s email address', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
						'default' => 'yes',
						'options' => array(
							'yes' => _x( 'Show email', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
							'no'  => _x( 'Hide email', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
						),
					),
					'no_affiliate_message' => array(
						'type'    => 'text',
						'label'   => _x( 'Enter message to show when there is no affiliate for current session', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ),
						/**
						 * APPLY_FILTERS: yith_wcaf_no_affiliate_message
						 *
						 * Filters the message shown when there is no affiliate for the current session.
						 *
						 * @param string $message Message.
						 */
						'default' => apply_filters( 'yith_wcaf_no_affiliate_message', _x( 'There isn\'t any affiliate selected for current session', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates' ) ),
					),
				);
			}

			return $this->attributes;
		}

		/**
		 * Filters variable submitted to template, in order to add section-specific values
		 *
		 * @param array $atts General shortcode attributes, as entered for the shortcode, or as default values.
		 *
		 * @return array Array of filtered template variables.
		 */
		public function get_template_atts( $atts ) {
			$affiliate = YITH_WCAF_Affiliate_Factory::get_current_affiliate( 'session' );
			$user      = $affiliate ? $affiliate->get_user() : false;

			return parent::get_template_atts(
				array_merge(
					$atts,
					compact( 'affiliate', 'user' ),
					// backward compatibility: these attributes are submitted only to support old versions of the templates.
					array(
						'current_affiliate' => $affiliate,
					)
				)
			);
		}
	}
}
