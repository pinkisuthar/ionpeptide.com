<?php
/**
 * New affiliate email
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes\Emails
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Admin_New_Affiliate_Email' ) ) {
	/**
	 * New affiliate email
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Admin_New_Affiliate_Email extends YITH_WCAF_Abstract_Admin_Email {

		/**
		 * Constructor method, used to return object of the class to WC
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// set trigger.
			$this->trigger = 'yith_wcaf_new_affiliate';

			// set email data.
			$this->id          = 'admin_new_affiliate';
			$this->title       = 'YITH WooCommerce Affiliates - ' . _x( 'New affiliate', '[EMAILS] New Affiliate email', 'yith-woocommerce-affiliates' );
			$this->description = _x( 'This email is sent to chosen recipient(s) when a new affiliate is created; you can enable/disable this from YITH > Affiliates > General Options > Affiliates Registration.', '[EMAILS] New Affiliate email', 'yith-woocommerce-affiliates' );

			// set heading and subject.
			$this->heading = _x( 'New affiliate registered', '[EMAILS] New Affiliate email', 'yith-woocommerce-affiliates' );
			$this->subject = _x( '[{site_title}] New affiliate', '[EMAILS] New Affiliate email', 'yith-woocommerce-affiliates' );

			// set templates.
			$this->template_html  = 'emails/admin-new-affiliate.php';
			$this->template_plain = 'emails/plain/admin-new-affiliate.php';

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Method triggered to send email
		 *
		 * @param int                 $affiliate_id New affiliate id.
		 * @param YITH_WCAF_Affiliate $affiliate    New Affiliate.
		 *
		 * @return void
		 */
		public function trigger( $affiliate_id, $affiliate ) {
			if ( ! $this->is_enabled() || ! $this->get_recipient() || ! $affiliate ) {
				return;
			}

			$this->object = $affiliate;

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

			return in_array( 'new', $notify_admin, true );
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
