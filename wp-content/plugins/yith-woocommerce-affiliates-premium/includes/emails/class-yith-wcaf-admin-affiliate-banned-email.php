<?php
/**
 * Affiliate ban email
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes\Emails
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Admin_Affiliate_Banned_Email' ) ) {
	/**
	 * New affiliate email
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Admin_Affiliate_Banned_Email extends YITH_WCAF_Abstract_Admin_Email {

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
			$this->id          = 'admin_affiliate_banned';
			$this->title       = 'YITH WooCommerce Affiliates - ' . _x( 'Affiliate banned', '[EMAILS] Affiliate Banned email', 'yith-woocommerce-affiliates' );
			$this->description = _x( 'This email is sent to chosen recipient(s) when a new affiliate is banned; you can enable/disable this from YITH > Affiliates > General Options > Affiliates Registration.', '[EMAILS] Affiliate Banned email', 'yith-woocommerce-affiliates' );

			// set heading and subject.
			$this->heading = _x( 'Affiliate was banned', '[EMAILS] Affiliate Banned email', 'yith-woocommerce-affiliates' );
			$this->subject = _x( '[{site_title}] Affiliate was banned', '[EMAILS] Affiliate Banned email', 'yith-woocommerce-affiliates' );

			// set templates.
			$this->template_html  = 'emails/admin-affiliate-banned.php';
			$this->template_plain = 'emails/plain/admin-affiliate-banned.php';

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Method triggered to send email
		 *
		 * @param int                 $affiliate_id Id of the affiliate being banned.
		 * @param YITH_WCAF_Affiliate $affiliate    Affiliate being banned.
		 *
		 * @return void
		 */
		public function trigger( $affiliate_id, $affiliate ) {
			if ( ! $this->is_enabled() || ! $this->get_recipient() || ! $affiliate ) {
				return;
			}

			$this->object  = $affiliate;
			$this->message = $affiliate->get_message( 'ban' );

			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		/**
		 * Check if mail is enabled
		 *
		 * @return bool Whether email notification is enabled or not
		 * @since 1.0.0
		 */
		public function is_enabled() {
			$notify_admin = get_option( 'yith_wcaf_referral_notify_admin', array( 'new' ) );

			return in_array( 'ban', $notify_admin, true );
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
					'affiliate'              => $this->object,
					'additional_message'     => $this->message,
					'affiliate_referral_url' => $this->object->get_referral_url(),
					'email_heading'          => $this->get_heading(),
					'email'                  => $this,
					'sent_to_admin'          => true,
					'plain_text'             => false,
				)
			);

			return ob_get_clean();
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
					'affiliate'              => $this->object,
					'additional_message'     => $this->message,
					'affiliate_referral_url' => $this->object->get_referral_url(),
					'email_heading'          => $this->get_heading(),
					'email'                  => $this,
					'sent_to_admin'          => true,
					'plain_text'             => true,
				)
			);

			return ob_get_clean();
		}
	}
}
