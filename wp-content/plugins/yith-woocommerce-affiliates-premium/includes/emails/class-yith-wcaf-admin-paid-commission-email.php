<?php
/**
 * Paid Commission email
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Admin_Paid_Commission_Email' ) ) {
	/**
	 * New affiliate email
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Admin_Paid_Commission_Email extends YITH_WCAF_Abstract_Admin_Email {

		/**
		 * Constructor method, used to return object of the class to WC
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// set trigger.
			$this->trigger = array(
				'yith_wcaf_payments_sent',
				'yith_wcaf_after_process_withdraw_request',
			);

			// set email data.
			$this->id          = 'payment_sent';
			$this->title       = 'YITH WooCommerce Affiliates - ' . _x( 'Payment Sent', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
			$this->description = _x( 'This email is sent to admins each time a payment is issued to an affiliate; you can enable/disable this from YITH > Affiliates > General Options > Commissions & Payments.', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );

			// set heading and subject.
			$this->heading = _x( 'Affiliate payment sent', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
			$this->subject = _x( '[{site_title}] New payment sent to an affiliate', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );

			// set templates.
			$this->template_html  = 'emails/admin-paid-commission.php';
			$this->template_plain = 'emails/plain/admin-paid-commission.php';

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Method triggered to send email
		 *
		 * @param int[] $payment_ids Array of payment ids.
		 *
		 * @return void
		 */
		public function trigger( $payment_ids ) {
			$this->object = new YITH_WCAF_Payments_Collection( (array) $payment_ids );

			if ( ! $this->is_enabled() || ! $this->get_recipient() || $this->object->is_empty() ) {
				return;
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
			$notify_admin = get_option( 'yith_wcaf_payment_pending_notify_admin' );

			return yith_plugin_fw_is_true( $notify_admin );
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
					'payments'      => $this->object,
					/**
					 * APPLY_FILTERS: yith_wcaf_email_currency
					 *
					 * Filters the commission currency in the commissions table.
					 *
					 * @param string   $currency Commission currency.
					 * @param WC_Email $email    Email object.
					 */
					'currency'      => apply_filters( 'yith_wcaf_email_currency', get_woocommerce_currency(), $this ),
					'email_heading' => $this->get_heading(),
					'email'         => $this,
					'sent_to_admin' => true,
					'plain_text'    => false,
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
					'payments'      => $this->object,
					'currency'      => apply_filters( 'yith_wcaf_email_currency', get_woocommerce_currency(), $this ),
					'email_heading' => $this->get_heading(),
					'email'         => $this,
					'sent_to_admin' => true,
					'plain_text'    => true,
				)
			);

			return ob_get_clean();
		}
	}
}
