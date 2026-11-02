<?php
/**
 * Affiliate Dashboard shortcode - Payments
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Show_Settings_Shortcode_Premium' ) ) {
	/**
	 * Offer methods for basic shortcode handling
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Show_Settings_Shortcode_Premium extends YITH_WCAF_Show_Settings_Shortcode {

		/* === SECTION HANDLING === */

		/**
		 * Filters variable submitted to template, in order to add section-specific values
		 *
		 * @param array $atts General shortcode attributes, as entered for the shortcode, or as default values.
		 *
		 * @return array Array of filtered template variables.
		 */
		public function get_template_atts( $atts ) {
			$affiliate = YITH_WCAF_Affiliate_Factory::get_current_affiliate();
			$fields    = YITH_WCAF_Affiliates_Profile::get_settings_fields( 'view' );

			// fields to show.
			$show_additional_fields = ! empty( $fields );
			$show_billing_fields    = YITH_WCAF_Affiliates_Invoice_Profile::should_show_fields();
			$invoice_fields         = YITH_WCAF_Affiliates_Invoice_Profile::get_billing_fields( 'edit' );
			$number_pos             = array_search( 'number', $invoice_fields, true );

			// notification preferences.
			$notify_pending_commissions = $affiliate->should_notify( 'pending_commission' );
			$notify_paid_commissions    = $affiliate->should_notify( 'paid_commission' );

			if ( false !== $number_pos ) {
				unset( $invoice_fields[ $number_pos ] );
			}

			/**
			 * APPLY_FILTERS: $tag_shortcode_template_atts
			 *
			 * Filters the array with the attritubes needed for the shortcode template.
			 * <code>$tag</code> will be replaced with the shortcode tag.
			 *
			 * @param array $shortcode_atts Attributes for the shortcode template.
			 */
			return apply_filters(
				"{$this->tag}_shortcode_template_atts",
				array_merge(
					parent::get_template_atts( $atts ),
					compact( 'affiliate', 'show_billing_fields', 'show_additional_fields' ),
					// backward compatibility: these attributes are submitted only to support old versions of the templates.
					array(
						'notify_pending_commissions' => $notify_pending_commissions ? 'yes' : 'no',
						'notify_paid_commissions'    => $notify_paid_commissions ? 'yes' : 'no',
					),
					empty( $fields ) ? array() : array(
						'show_website_field'             => array_key_exists( 'website', $fields ) ? 'yes' : 'no',
						'show_promotional_methods_field' => array_key_exists( 'how_promote', $fields ) ? 'yes' : 'no',
						'affiliate_website'              => $affiliate->get_meta( 'website' ),
						'promotional_method'             => $affiliate->get_meta( 'promotional_method' ),
						'custom_promotional_method'      => $affiliate->get_meta( 'custom_method' ),
					),
					! $show_billing_fields ? array() : array(
						'invoice_fields'  => $invoice_fields,
						'invoice_profile' => $affiliate->get_invoice_profile(),
					)
				)
			);
		}
	}
}
