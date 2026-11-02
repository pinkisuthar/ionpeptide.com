<?php
/**
 * Affiliate status change email
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes\Emails
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Admin_Affiliate_Status_Changed_Email' ) ) {
	/**
	 * New affiliate email
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Admin_Affiliate_Status_Changed_Email extends YITH_WCAF_Abstract_Admin_Email {

		/**
		 * Previous affiliate status
		 *
		 * @var string
		 */
		protected $old_status = '';

		/**
		 * Current affiliate status
		 *
		 * @var string
		 */
		protected $new_status = '';

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
			$this->trigger = 'yith_wcaf_affiliate_status_changed';

			// set email data.
			$this->id          = 'admin_affiliate_status_changed';
			$this->title       = 'YITH WooCommerce Affiliates - ' . _x( 'Affiliate\'s status changed', '[EMAILS] Affiliate status changed email', 'yith-woocommerce-affiliates' );
			$this->description = _x( 'This email is sent to chosen recipient(s) when a new affiliate changes status; you can enable/disable this from YITH > Affiliates > General Options > Affiliates Registration.', '[EMAILS] Affiliate status changed email', 'yith-woocommerce-affiliates' );

			// set heading and subject.
			$this->heading = _x( 'Affiliate\'s status changed', '[EMAILS] Affiliate status changed email', 'yith-woocommerce-affiliates' );
			$this->subject = _x( '[{site_title}] Affiliate status changed', '[EMAILS] Affiliate status changed email', 'yith-woocommerce-affiliates' );

			// set templates.
			$this->template_html  = 'emails/admin-affiliate-status-changed.php';
			$this->template_plain = 'emails/plain/admin-affiliate-status-changed.php';

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Method triggered to send email
		 *
		 * @param int    $affiliate_id Affiliate id.
		 * @param string $new_status   Current affiliate status.
		 * @param string $old_status   Previous affiliate status.
		 *
		 * @return void
		 */
		public function trigger( $affiliate_id, $new_status, $old_status ) {
			$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate( $affiliate_id );

			if ( ! $this->is_enabled() || ! $this->get_recipient() || ! $affiliate ) {
				return;
			}

			$available_statuses = YITH_WCAF_Affiliates::get_available_statuses();
			$available_statuses = array_combine( wp_list_pluck( $available_statuses, 'slug' ), $available_statuses );

			$this->object     = $affiliate;
			$this->old_status = isset( $available_statuses[ $old_status ] ) ? $available_statuses[ $old_status ]['name'] : $old_status;
			$this->new_status = isset( $available_statuses[ $new_status ] ) ? $available_statuses[ $new_status ]['name'] : $new_status;

			if ( 'disabled' === $new_status ) {
				$this->message = $affiliate->get_message( 'reject' );
			}

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

			return in_array( 'change', $notify_admin, true );
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
					'old_status'             => $this->old_status,
					'new_status'             => $this->new_status,
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
					'old_status'             => $this->old_status,
					'new_status'             => $this->new_status,
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
