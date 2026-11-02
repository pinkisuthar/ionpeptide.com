<?php
/**
 * New affiliate payment Email class
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_New_Affiliate_Payment_Email' ) ) {
	/**
	 * New affiliate email
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_New_Affiliate_Payment_Email extends YITH_WCAF_Abstract_Affiliate_Email {

		/**
		 * Affiliate for current set of commissions
		 *
		 * @var YITH_WCAF_Affiliate
		 */
		protected $affiliate;

		/**
		 * Constructor method, used to return object of the class to WC
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			$this->trigger = 'yith_wcaf_payment_status_completed';

			$this->id             = 'customer_payment_sent';
			$this->title          = 'YITH WooCommerce Affiliates - ' . _x( 'Affiliate\'s Payment Sent', '[EMAILS] New affiliate payment', 'yith-woocommerce-affiliates' );
			$this->description    = _x( 'This email is sent to affiliate each time a payment is issued to his/her account; this notification email can be enabled/disabled by the affiliate from his/her dashboard settings.', '[EMAILS] New affiliate payment', 'yith-woocommerce-affiliates' );
			$this->customer_email = true;
			$this->manual         = true;

			$this->heading = _x( 'A payment was sent to your account', '[EMAILS] New affiliate payment', 'yith-woocommerce-affiliates' );
			$this->subject = _x( 'A payment was sent to your account', '[EMAILS] New affiliate payment', 'yith-woocommerce-affiliates' );

			$this->content_html = $this->get_option( 'content_html' );
			$this->content_text = $this->get_option( 'content_text' );

			$this->template_html  = 'emails/new-affiliate-payment.php';
			$this->template_plain = 'emails/plain/new-affiliate-payment.php';

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Method triggered to send email
		 *
		 * @param int $payment_id Payment id.
		 *
		 * @return void
		 */
		public function trigger( $payment_id ) {
			$this->object    = YITH_WCAF_Payment_Factory::get_payment( $payment_id );
			$this->affiliate = $this->object->get_affiliate();

			if ( ! $this->is_enabled() || ! $this->get_recipient() || ! $this->object ) {
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
			if ( ! $this->affiliate ) {
				return false;
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_notify_user_paid_commission
			 *
			 * Filters whether to notify the affiliate when a commission is paid.
			 *
			 * @param bool $notify_user Whether to notify the user or not.
			 */
			return apply_filters( 'yith_wcaf_notify_user_paid_commission', $this->affiliate->should_notify( 'paid_commission' ) );
		}

		/**
		 * Retrieve recipient address
		 *
		 * @return string Email address
		 * @since 1.0.0
		 */
		public function get_recipient() {
			if ( ! $this->affiliate ) {
				return false;
			}

			return $this->get_recipient_from_affiliate( $this->affiliate );
		}

		/**
		 * Set custom replace value for this email
		 *
		 * @return void
		 */
		public function set_replaces() {
			$placeholders = array(
				'{payment_id}'     => $this->object->get_id(),
				'{payment_amount}' => $this->object->get_formatted_amount(),
				'{confirmed_date}' => $this->object->get_completed_at( 'edit' )->date_i18n( wc_date_format() ),
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
					'payment'       => $this->object,
					'commissions'   => $this->object->get_commissions(),
					'affiliate'     => $this->affiliate,
					/**
					 * APPLY_FILTERS: yith_wcaf_email_currency
					 *
					 * Filters the commission currency in the commissions table.
					 *
					 * @param string   $currency Commission currency.
					 * @param WC_Email $email    Email object.
					 */
					'currency'      => apply_filters( 'yith_wcaf_email_currency', $this->object->get_currency(), $this ),
					'display_name'  => $this->affiliate->get_formatted_name(),
					'user'          => $this->affiliate->get_user(),
					'email_heading' => $this->get_heading(),
					'email'         => $this,
					'sent_to_admin' => true,
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
					'payment'       => $this->object,
					'commissions'   => $this->object->get_commissions(),
					'affiliate'     => $this->affiliate,
					'currency'      => apply_filters( 'yith_wcaf_email_currency', $this->object->get_currency(), $this ),
					'display_name'  => $this->affiliate->get_formatted_name(),
					'user'          => $this->affiliate->get_user(),
					'email_heading' => $this->get_heading(),
					'email'         => $this,
					'sent_to_admin' => true,
					'plain_text'    => true,
				)
			);

			return $this->format_string( ob_get_clean() );
		}

		/**
		 * Init form fields to display in WC admin pages
		 */
		public function init_form_fields() {
			parent::init_form_fields();

			unset( $this->form_fields['tos_label'] );
			unset( $this->form_fields['tos_url'] );
		}
	}
}
