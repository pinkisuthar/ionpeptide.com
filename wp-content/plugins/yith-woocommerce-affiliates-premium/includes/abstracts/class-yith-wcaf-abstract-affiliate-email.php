<?php
/**
 * General affiliate email handling
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Abstract_Affiliate_Email' ) ) {
	/**
	 * Wraps general method used in affiliate email sending
	 *
	 * @since 1.0.0
	 */
	abstract class YITH_WCAF_Abstract_Affiliate_Email extends YITH_WCAF_Abstract_Email {

		/**
		 * HTML content for the email
		 *
		 * @var string
		 */
		protected $content_html = '';

		/**
		 * HTML content for the email
		 *
		 * @var string
		 */
		protected $content_text = '';

		/**
		 * List of placeholders that can be used in email content.
		 *
		 * @var array
		 */
		protected $available_placeholders = array();

		/**
		 * List of placeholders that can be used in plain email content.
		 *
		 * @var array
		 */
		protected $available_text_placeholders = array();

		/**
		 * Init form fields to display in WC admin pages
		 */
		public function init_form_fields() {
			parent::init_form_fields();

			// unset recipients field.
			unset( $this->form_fields['recipient'] );

			// add content options.
			$content_options = array(
				'content_html' => array(
					'title'       => _x( 'Content (HTML)', '[EMAILS] General options', 'yith-woocommerce-affiliates' ),
					'type'        => 'textarea',
					'description' => _x( 'Enter the text that you want to send in your email (HTML version).', '[EMAILS] General options', 'yith-woocommerce-affiliates' ) . $this->get_placeholders_for_description(),
					'placeholder' => '',
					'default'     => $this->content_html,
				),
				'content_text' => array(
					'title'       => _x( 'Content (plain text)', '[EMAILS] General options', 'yith-woocommerce-affiliates' ),
					'type'        => 'textarea',
					'description' => _x( 'Enter the text that you want to send in your email (plain text version).', '[EMAILS] General options', 'yith-woocommerce-affiliates' ) . $this->get_placeholders_for_description( 'text' ),
					'placeholder' => '',
					'default'     => $this->content_text,
				),
				'tos_label'    => array(
					'title'       => _x( 'Terms & Conditions label', '[EMAILS] Affiliate enabled email', 'yith-woocommerce-affiliates' ),
					'type'        => 'text',
					'description' => _x( 'Enter here the label used to print the Terms & Conditions page reference using <code>{tos_link}</code> and <code>{tos_plain_link}</code> placeholders.', '[EMAILS] General options', 'yith-woocommerce-affiliates' ),
					'placeholder' => '',
					'default'     => get_option( 'yith_wcaf_referral_registration_terms_anchor_text', _x( 'Terms & Conditions', '[EMAILS] Affiliate enabled email', 'yith-woocommerce-affiliates' ) ),
				),
				'tos_url'      => array(
					'title'       => _x( 'Terms & Conditions URL', '[EMAILS] Affiliate enabled email', 'yith-woocommerce-affiliates' ),
					'type'        => 'text',
					'description' => _x( 'Enter here the URL used to print the Terms & Conditions page reference using <code>{tos_link}</code> and <code>{tos_plain_link}</code> placeholders.', '[EMAILS] General options', 'yith-woocommerce-affiliates' ),
					'placeholder' => '',
					'default'     => get_option( 'yith_wcaf_referral_registration_terms_anchor_url', home_url() ),
				),
			);

			$this->form_fields = yith_wcaf_append_items( $this->form_fields, 'heading', $content_options );
		}

		/**
		 * Set custom replace value for this email
		 *
		 * @return void
		 */
		public function set_replaces() {
			// add formatted content text, using placeholders that we just add.
			$this->placeholders = array_merge(
				$this->placeholders,
				array(
					'{content_html}' => $this->format_string( $this->content_html ),
					'{content_text}' => $this->format_string( $this->content_text ),
				)
			);
		}

		/**
		 * Returns recipient email from Affiliate object
		 *
		 * @param YITH_WCAF_Affiliate $affiliate Affiliate object.
		 * @return string Affiliate's user email
		 */
		public function get_recipient_from_affiliate( $affiliate ) {
			if ( ! $affiliate ) {
				return false;
			}

			$user = $affiliate->get_user();

			if ( ! $user || is_wp_error( $user ) ) {
				return false;
			}

			return $user->user_email;
		}

		/**
		 * Returns part of option description that contains placeholders that can be used
		 *
		 * @param string $type Type of placeholders (html/text).
		 * @return string Formatted placeholders description.
		 */
		protected function get_placeholders_for_description( $type = 'html' ) {
			$placeholders = array();

			if ( 'text' === $type ) {
				$placeholders = $this->available_text_placeholders;
			}

			if ( ! $placeholders ) {
				$placeholders = $this->available_placeholders;
			}

			if ( ! $placeholders ) {
				return '';
			}

			// translators: 1. Comma-separated list of placeholders inside <code> tags.
			$placeholders_text = _x( 'You can use the following placeholders: %s.', '[EMAILS] General description', 'yith-woocommerce-affiliates' );
			$placeholders      = array_map(
				function ( $placeholder ) {
					return "<code>{{$placeholder}}</code>";
				},
				$placeholders
			);

			return sprintf( $placeholders_text, implode( ', ', $placeholders ) );
		}

	}
}
