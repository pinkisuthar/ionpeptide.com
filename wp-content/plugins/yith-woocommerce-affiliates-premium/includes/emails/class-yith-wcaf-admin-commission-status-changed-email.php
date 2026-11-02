<?php
/**
 * Commission status changed email
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Admin_Commission_Status_Changed_Email' ) ) {
	/**
	 * New affiliate email
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Admin_Commission_Status_Changed_Email extends YITH_WCAF_Abstract_Admin_Email {

		/**
		 * Set of commissions that changed status
		 *
		 * @var YITH_WCAF_Commissions_Collection
		 */
		protected $commissions;

		/**
		 * Action that triggers email sending
		 *
		 * @var string
		 */
		protected $trigger = array(
			'yith_wcaf_order_confirmed_commissions',
			'yith_wcaf_order_unconfirmed_commissions',
		);

		/**
		 * Constructor method, used to return object of the class to WC
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// set trigger.
			$this->trigger = array(
				'yith_wcaf_order_confirmed_commissions',
				'yith_wcaf_order_unconfirmed_commissions',
			);

			// set email data.
			$this->id          = 'commission_status_changed';
			$this->title       = 'YITH WooCommerce Affiliates - ' . _x( 'Commission status changed', '[EMAILS] Commission status changed email', 'yith-woocommerce-affiliates' );
			$this->description = _x( 'This email is sent to chosen recipient(s) each time a set of commissions switches status due to order changes; you can enable/disable this from YITH > Affiliates > General Options > Commissions & Payments.', '[EMAILS] Commission status changed email', 'yith-woocommerce-affiliates' );

			// set heading and subject.
			$this->heading = _x( 'Commission status changed', '[EMAILS] Commission status changed email', 'yith-woocommerce-affiliates' );
			$this->subject = _x( '[{site_title}] Commission status changed', '[EMAILS] Commission status changed email', 'yith-woocommerce-affiliates' );

			// set templates.
			$this->template_html  = 'emails/admin-commission-status-changed.php';
			$this->template_plain = 'emails/plain/admin-commission-status-changed.php';

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Method triggered to send email
		 *
		 * @param WC_order                         $order       Order.
		 * @param YITH_WCAF_Commissions_Collection $commissions Commissions.
		 *
		 * @return void
		 */
		public function trigger( $order, $commissions ) {
			if ( ! $this->is_enabled() || ! $this->get_recipient() || ! $order || ! $commissions || $commissions->is_empty() ) {
				return;
			}

			$this->object      = $order;
			$this->commissions = $commissions;

			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		/**
		 * Check if mail is enabled
		 *
		 * @return bool Whether email notification is enabled or not
		 * @since 1.0.0
		 */
		public function is_enabled() {
			$notify_admin = get_option( 'yith_wcaf_commission_pending_notify_admin' );

			return yith_plugin_fw_is_true( $notify_admin );
		}

		/**
		 * Get HTML content for the mail
		 *
		 * @return string HTML content of the mail
		 * @since 1.0.0
		 */
		public function get_content_html() {
			$current_action = current_action();
			$affiliate      = YITH_WCAF_Affiliate_Factory::get_affiliate_by_order_id( $this->object->get_id() );
			$confirmed      = 0 === strpos( $current_action, 'yith_wcaf_order_confirmed_commissions' );

			ob_start();
			yith_wcaf_get_template(
				$this->template_html,
				array(
					'order'         => $this->object,
					'commissions'   => $this->commissions,
					'affiliate'     => $affiliate,
					'confirmed'     => $confirmed,
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
			$current_action = current_action();
			$affiliate      = YITH_WCAF_Affiliate_Factory::get_affiliate_by_order_id( $this->object->get_id() );
			$confirmed      = 0 === strpos( $current_action, 'yith_wcaf_order_confirmed_commissions' );

			ob_start();
			yith_wcaf_get_template(
				$this->template_plain,
				array(
					'order'         => $this->object,
					'commissions'   => $this->commissions,
					'affiliate'     => $affiliate,
					'confirmed'     => $confirmed,
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
