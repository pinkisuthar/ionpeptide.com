<?php
/**
 * Affiliate banned Email
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Emails
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Affiliate_Banned_Email' ) ) {
	/**
	 * New affiliate email
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Affiliate_Banned_Email extends YITH_WCAF_Abstract_Affiliate_Email {

		/**
		 * Additional message to the affiliate
		 *
		 * @var string
		 */
		protected $message = '';

		/**
		 * Constructor method, used to return object of the class to WC
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// set trigger.
			$this->trigger = 'yith_wcaf_affiliate_banned';

			// set email data.
			$this->id             = 'affiliate_banned';
			$this->title          = 'YITH WooCommerce Affiliates - ' . _x( 'Affiliate banned', '[EMAILS] Affiliate Banned email', 'yith-woocommerce-affiliates' );
			$this->description    = _x( 'This email is sent to each affiliate when his/her account is banned by an admin; you can enable/disable this from YITH > Affiliates > General Options > Affiliates Registration.', '[EMAILS] Affiliate Banned email', 'yith-woocommerce-affiliates' );
			$this->customer_email = true;

			// set heading and subject.
			$this->heading = _x( 'Your account was disabled', '[EMAILS] Affiliate Banned email', 'yith-woocommerce-affiliates' );
			$this->subject = _x( 'Your {site_title} affiliate account was disabled', '[EMAILS] Affiliate Banned email', 'yith-woocommerce-affiliates' );

			// set available placeholders.
			$this->available_placeholders      = array( 'site_title', 'display_name', 'ban_message', 'tos_link' );
			$this->available_text_placeholders = array( 'site_title', 'display_name', 'ban_message', 'tos_plain_link' );

			// set contents.
			$this->content_html = $this->get_option(
				'content_html',
				_x(
					'<p>Your affiliate account on {site_title} has been disabled because we have found that your behavior does not comply with our fair-play standards.</p>
<p>Please, have a look at our {tos_link} for further information, and don\'t hesitate to contact us if you have any doubts.</p>',
					'[EMAILS] Affiliate Banned email',
					'yith-woocommerce-affiliates'
				)
			);
			$this->content_text = $this->get_option(
				'content_text',
				_x(
					'Your affiliate account on {site_title} has been disabled because we have found that your behavior does not comply with our fair-play standards.
Please, have a look at our {tos_plain_link} for further information, and don\'t hesitate to contact us if you have any doubts.',
					'[EMAILS] Affiliate Banned email',
					'yith-woocommerce-affiliates'
				)
			);

			// set templates.
			$this->template_html  = 'emails/affiliate-banned.php';
			$this->template_plain = 'emails/plain/affiliate-banned.php';

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Method triggered to send email
		 *
		 * @param int                 $affiliate_id Affiliate id.
		 * @param YITH_WCAF_Affiliate $affiliate    Affiliate.
		 *
		 * @return void
		 */
		public function trigger( $affiliate_id, $affiliate ) {
			$this->object = $affiliate;

			if ( ! $this->is_enabled() || ! $affiliate || ! $this->get_recipient() ) {
				return;
			}

			$this->message = $affiliate->get_message( 'ban' );

			// set replaces.
			$this->set_replaces();

			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		/**
		 * Check if mail is enabled
		 *
		 * @return bool Whether email notification is enabled or not
		 * @since 1.0.0
		 */
		public function is_enabled() {
			$notify_affiliates = get_option( 'yith_wcaf_referral_notify_affiliate', array( 'new', 'change', 'ban' ) );

			return in_array( 'ban', $notify_affiliates, true );
		}

		/**
		 * Retrieve recipient address
		 *
		 * @return string Email address
		 * @since 1.0.0
		 */
		public function get_recipient() {
			return $this->get_recipient_from_affiliate( $this->object );
		}

		/**
		 * Set custom replace value for this email
		 *
		 * @return void
		 */
		public function set_replaces() {
			/**
			 * APPLY_FILTERS: yith_wcaf_emails_terms_url
			 *
			 * Filters the 'Terms & Conditions' url in the email.
			 *
			 * @param string   $url   Terms & Conditions url.
			 * @param WC_Email $email Email object.
			 */
			$tos_url = apply_filters( 'yith_wcaf_emails_terms_url', $this->get_option( 'tos_url' ), $this );

			/**
			 * APPLY_FILTERS: yith_wcaf_emails_terms_label
			 *
			 * Filters the 'Terms & Conditions' label in the email.
			 *
			 * @param string   $label Terms & Conditions label.
			 * @param WC_Email $email Email object.
			 */
			$tos_label      = apply_filters( 'yith_wcaf_emails_terms_label', $this->get_option( 'tos_label' ), $this );
			$tos_link       = '';
			$tos_plain_link = '';

			if ( $tos_url ) {
				$tos_label = $tos_label ? $tos_label : _x( 'Terms & Conditions', '[EMAILS] Affiliate Banned email', 'yith-woocommerce-affiliates' );

				$tos_link       = sprintf( '<a href="%s" target="_blank">%s</a>', $tos_url, $tos_label );
				$tos_plain_link = sprintf( '%s [%s]', $tos_label, $tos_url );
			}

			$placeholders = array(
				'{display_name}'   => $this->object->get_formatted_name(),
				'{ban_message}'    => $this->message,
				'{tos_link}'       => $tos_link,
				'{tos_plain_link}' => $tos_plain_link,
			);

			$this->placeholders = array_merge(
				$this->placeholders,
				$placeholders
			);

			// add formatted content text, using placeholders that we just add.
			parent::set_replaces();
		}

		/**
		 * Get HTML content for the mail
		 *
		 * @return string HTML content of the mail
		 * @since 1.0.0
		 */
		public function get_content_html() {
			ob_start();
			yith_wcaf_get_template(
				$this->template_html,
				array(
					'affiliate'        => $this->object,
					'additional_notes' => $this->message,
					'user'             => $this->object->get_user(),
					'display_name'     => $this->object->get_formatted_name(),
					'email_heading'    => $this->get_heading(),
					'email'            => $this,
					'sent_to_admin'    => false,
					'plain_text'       => false,
				)
			);

			return $this->format_string( ob_get_clean() );
		}

		/**
		 * Get plain text content of the mail
		 *
		 * @return string Plain text content of the mail
		 * @since 1.0.0
		 */
		public function get_content_plain() {
			ob_start();
			yith_wcaf_get_template(
				$this->template_plain,
				array(
					'affiliate'        => $this->object,
					'additional_notes' => $this->message,
					'user'             => $this->object->get_user(),
					'display_name'     => $this->object->get_formatted_name(),
					'email_heading'    => $this->get_heading(),
					'email'            => $this,
					'sent_to_admin'    => false,
					'plain_text'       => true,
				)
			);

			return $this->format_string( ob_get_clean() );
		}
	}
}
