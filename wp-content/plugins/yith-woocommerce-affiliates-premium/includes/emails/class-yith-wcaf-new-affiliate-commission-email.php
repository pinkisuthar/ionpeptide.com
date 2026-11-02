<?php
/**
 * New Commission Email class
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_New_Affiliate_Commission_Email' ) ) {
	/**
	 * New affiliate email
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_New_Affiliate_Commission_Email extends YITH_WCAF_Abstract_Affiliate_Email {

		/**
		 * Affiliate for current set of commissions
		 *
		 * @var YITH_WCAF_Affiliate
		 */
		protected $affiliate;

		/**
		 * Set of commissions that changed status
		 *
		 * @var YITH_WCAF_Commissions_Collection
		 */
		protected $commissions;

		/**
		 * Constructor method, used to return object of the class to WC
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// set trigger.
			$this->trigger = 'yith_wcaf_order_confirmed_commissions';

			// set email data.
			$this->id             = 'customer_pending_commission';
			$this->title          = 'YITH WooCommerce Affiliates - ' . _x( 'New Affiliate\'s Commission', '[EMAILS] New affiliate commission', 'yith-woocommerce-affiliates' );
			$this->description    = _x( 'This email is sent to affiliates each time a new commission is confirmed for his/her account; this notification email can be enabled/disabled by the affiliate from his/her dashboard settings.', '[EMAILS] New affiliate commission', 'yith-woocommerce-affiliates' );
			$this->customer_email = true;
			$this->manual         = true;

			// set heading and subject.
			$this->heading = _x( 'Your commission is awaiting payment', '[EMAILS] New affiliate commission', 'yith-woocommerce-affiliates' );
			$this->subject = _x( 'Your {site_title} commission from {confirmed_date} is awaiting payment', '[EMAILS] New affiliate commission', 'yith-woocommerce-affiliates' );

			// set contents.
			$this->content_html = $this->get_option( 'content_html' );
			$this->content_text = $this->get_option( 'content_text' );

			// set templates.
			$this->template_html  = 'emails/new-affiliate-commission.php';
			$this->template_plain = 'emails/plain/new-affiliate-commission.php';

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Method triggered to send email
		 *
		 * @param WC_Order $order Order.
		 *
		 * @return void
		 */
		public function trigger( $order ) {
			$this->object    = $order;
			$this->affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_order_id( $this->object->get_id() );

			$commissions = YITH_WCAF_Commission_Factory::get_commissions(
				array(
					'order_id' => $order->get_id(),
					'status'   => 'pending',
				)
			);

			$this->commissions = $commissions;

			if ( ! $this->is_enabled() || ! $this->get_recipient() || ! $order || ! $commissions || $commissions->is_empty() ) {
				return;
			}

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
			 * APPLY_FILTERS: yith_wcaf_notify_user_pending_commission
			 *
			 * Filters whether to notify the affiliate when a commission is pending.
			 *
			 * @param bool $notify_user Whether to notify the user or not.
			 */
			return apply_filters( 'yith_wcaf_notify_user_pending_commission', $this->affiliate->should_notify( 'pending_commission' ) );
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
			$payment_threshold = get_option( 'yith_wcaf_payment_threshold', 0 );
			$payment_day       = get_option( 'yith_wcaf_payment_date', 15 );
			$current_day       = gmdate( 'j' );
			$current_month     = gmdate( 'F' );
			$next_month        = gmdate( 'F', strtotime( 'next month' ) );
			$pay_month         = $current_day > $payment_day ? $next_month : $current_month;
			$next_payment_date = date_i18n( wc_date_format(), strtotime( "{$payment_day} {$pay_month}" ) );

			$placeholders = array(
				'{order_id}'               => $this->object->get_order_number(),
				'{confirmed_date}'         => $this->object->get_date_completed() ? $this->object->get_date_completed()->date_i18n( wc_date_format() ) : '',
				'{payment_threshold}'      => $payment_threshold,
				'{payment_threshold_html}' => wc_price( $payment_threshold ),
				'{payment_day}'            => $payment_day,
				'{next_payment_date}'      => $next_payment_date,
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
					'order'         => $this->object,
					'commissions'   => $this->commissions,
					'affiliate'     => $this->affiliate,
					'display_name'  => $this->affiliate->get_formatted_name(),
					'user'          => $this->affiliate->get_user(),
					/**
					 * APPLY_FILTERS: yith_wcaf_email_currency
					 *
					 * Filters the commission currency in the commissions table.
					 *
					 * @param string   $currency Commission currency.
					 * @param WC_Email $email    Email object.
					 */
					'currency'      => apply_filters( 'yith_wcaf_email_currency', $this->object->get_currency(), $this ),
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
					'order'         => $this->object,
					'commissions'   => $this->commissions,
					'affiliate'     => $this->affiliate,
					'display_name'  => $this->affiliate->get_formatted_name(),
					'user'          => $this->affiliate->get_user(),
					'currency'      => apply_filters( 'yith_wcaf_email_currency', $this->object->get_currency(), $this ),
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
