<?php
/**
 * Affiliate enabled Email
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Affiliate_Enabled_Email' ) ) {
	/**
	 * New affiliate email
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Affiliate_Enabled_Email extends YITH_WCAF_Abstract_Affiliate_Email {

		/**
		 * Constructor method, used to return object of the class to WC
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// set trigger.
			$this->trigger = 'yith_wcaf_affiliate_status_enabled';

			// set email data.
			$this->id             = 'affiliate_enabled';
			$this->title          = 'YITH WooCommerce Affiliates - ' . _x( 'Affiliate enabled', '[EMAILS] Affiliate enabled email', 'yith-woocommerce-affiliates' );
			$this->description    = _x( 'This email is sent to each affiliate when his/her account is enabled; you can enable/disable this from YITH > Affiliates > General Options > Affiliates Registration.', '[EMAILS] Affiliate enabled email', 'yith-woocommerce-affiliates' );
			$this->customer_email = true;

			// set heading and subject.
			$this->heading = _x( 'Welcome to our affiliate program!', '[EMAILS] Affiliate enabled email', 'yith-woocommerce-affiliates' );
			$this->subject = _x( 'Your {site_title} affiliate account is now enabled', '[EMAILS] Affiliate enabled email', 'yith-woocommerce-affiliates' );

			// set available placeholders.
			$this->available_placeholders      = array( 'site_title', 'display_name', 'affiliate_rate', 'reject_message', 'tos_link', 'affiliate_dashboard_link' );
			$this->available_text_placeholders = array( 'site_title', 'display_name', 'affiliate_rate', 'reject_message', 'tos_plain_link', 'affiliate_dashboard_plain_link' );

			// set contents.
			$this->content_html = $this->get_option(
				'content_html',
				_x(
					'<p>Welcome to our affiliate program, your request has been accepted.</p>
<p>You can now start sharing your referral link. Access your {affiliate_dashboard_link} to generate it.</p>
<p>You\'ll earn a <b>{affiliate_rate} commission</b> on every sale coming through your referral link (the shop administrator can set up product exceptions that are not specified here. You can read more in our {tos_link}.)</p>
<p>Let\'s make great things together!</p>',
					'[EMAILS] Affiliate enabled email',
					'yith-woocommerce-affiliates'
				)
			);
			$this->content_text = $this->get_option(
				'content_text',
				_x(
					"Welcome to our affiliate program, your request has been accepted.
You can now start sharing your referral link. Access your {affiliate_dashboard_plain_link} to generate it.
You'll earn a {affiliate_rate} commission on every sale coming through your referral link (the shop administrator can set up product exceptions that are not specified here. You can read more in our {tos_plain_link}.)
Let's make great things together!",
					'[EMAILS] Affiliate enabled email',
					'yith-woocommerce-affiliates'
				)
			);

			// set templates.
			$this->template_html  = 'emails/affiliate-enabled.php';
			$this->template_plain = 'emails/plain/affiliate-enabled.php';

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Method triggered to send email
		 *
		 * @param int $affiliate_id Affiliate id.
		 * @return void
		 */
		public function trigger( $affiliate_id ) {
			$affiliate    = YITH_WCAF_Affiliate_Factory::get_affiliate( $affiliate_id );
			$this->object = $affiliate;

			if ( ! $this->is_enabled() || ! $affiliate || ! $this->get_recipient() ) {
				return;
			}

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

			return in_array( 'change', $notify_affiliates, true );
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
				$tos_label = $tos_label ? $tos_label : _x( 'Terms & Conditions', '[EMAILS] Affiliate enabled email', 'yith-woocommerce-affiliates' );

				$tos_link       = sprintf( '<a href="%s" target="_blank">%s</a>', $tos_url, $tos_label );
				$tos_plain_link = sprintf( '%s [%s]', $tos_label, $tos_url );
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_customer_status_change_dashboard_url
			 *
			 * Filters the url to the Affiliate Dashboard in the email.
			 *
			 * @param string   $url   Affiliate Dashboard url.
			 * @param WC_Email $email Email object.
			 */
			$affiliate_dashboard_url        = apply_filters( 'yith_wcaf_customer_status_change_dashboard_url', YITH_WCAF_Dashboard()->get_dashboard_url( 'generate-link' ), $this );
			$affiliate_dashboard_link       = sprintf( '<a href="%s">%s</a>', $affiliate_dashboard_url, _x( 'Affiliate Dashboard', '[EMAILS] Affiliate enabled email', 'yith-woocommerce-affiliates' ) );
			$affiliate_dashboard_plain_link = sprintf( '%s [%s]', _x( 'Affiliate Dashboard', '[EMAILS] Affiliate enabled email', 'yith-woocommerce-affiliates' ), $affiliate_dashboard_url );

			$user_rate = yith_wcaf_get_formatted_rate( $this->object );

			$placeholders = array(
				'{display_name}'                   => $this->object->get_formatted_name(),
				'{tos_link}'                       => $tos_link,
				'{tos_plain_link}'                 => $tos_plain_link,
				'{affiliate_dashboard_link}'       => $affiliate_dashboard_link,
				'{affiliate_dashboard_plain_link}' => $affiliate_dashboard_plain_link,
				'{affiliate_rate}'                 => $user_rate,
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
					'affiliate'     => $this->object,
					'user'          => $this->object->get_user(),
					'display_name'  => $this->object->get_formatted_name(),
					'email_heading' => $this->get_heading(),
					'email'         => $this,
					'sent_to_admin' => false,
					'plain_text'    => false,
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
					'affiliate'     => $this->object,
					'user'          => $this->object->get_user(),
					'display_name'  => $this->object->get_formatted_name(),
					'email_heading' => $this->get_heading(),
					'email'         => $this,
					'sent_to_admin' => false,
					'plain_text'    => true,
				)
			);

			return $this->format_string( ob_get_clean() );
		}
	}
}
