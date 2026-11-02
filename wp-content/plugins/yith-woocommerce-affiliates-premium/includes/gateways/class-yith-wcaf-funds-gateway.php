<?php
/**
 * YITH Account Funds Gateway class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Funds_Gateway' ) ) {
	/**
	 * WooCommerce Paypal Gateway
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Funds_Gateway extends YITH_WCAF_Abstract_Gateway {

		/**
		 * Constructor method
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// init class attributes.
			$this->id       = 'funds';
			$this->name     = _x( 'Account funds', '[ADMIN] Gateway name', 'yith-woocommerce-affiliates' );
			$this->supports = array(
				'masspay' => true,
			);

			parent::__construct();
		}

		/**
		 * Returns true if gateway is available
		 *
		 * @return bool Whether current gateway is enabled.
		 */
		public function is_available() {
			return class_exists( 'YITH_Funds' );
		}

		/**
		 * Returns a message describing why gateway is not available at the moment.
		 *
		 * @return string|bool Message for the admin, or false if gateway is available.
		 */
		public function why_not_available() {
			if ( $this->is_available() ) {
				return false;
			}

			$landing = 'https://yithemes.com/themes/plugins/yith-woocommerce-account-funds/';
			$name    = 'YITH WooCommerce Account Funds';

			// translators: 1. Url to plugin langing page. 2. Name of the required plugin.
			return sprintf( _x( 'Plugin <a href="%1$s">%2$s</a> is required', '[ADMIN] Gateway messages.', 'yith-woocommerce-affiliates' ), $landing, $name );
		}

		/* === PAYMENT METHODS === */

		/**
		 * Execute a mass payment
		 *
		 * @param int|int[] $payment_ids Array of registered payments to credit to funds.
		 *
		 * @return mixed Array with operation status and messages
		 * @since 1.0.0
		 */
		public function process_payment( $payment_ids ) {

			if ( ! class_exists( 'YITH_YWF_Customer' ) ) {
				$this->log( _x( 'YITH WooCommerce Account Funds is not installed', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), 'error' );

				return array(
					'status'   => false,
					'messages' => _x( 'There was an issue with the gateway installation; please, contact technical support', '[ADMIN] Gateway messages.', 'yith-woocommerce-affiliates' ),
				);
			}

			$mass_pay_payments = array();

			// if single payment id, convert it to array.
			$payment_ids = (array) $payment_ids;

			// translators: 1. Payment IDs.
			$this->log( sprintf( _x( 'Trying to pay %s with funds', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), implode( ', ', $payment_ids ) ) );

			foreach ( $payment_ids as $payment_id ) {
				$payment = YITH_WCAF_Payment_Factory::get_payment( (int) $payment_id );

				if ( ! $payment ) {
					// translators: 1. Payment ID.
					$this->log( sprintf( _x( 'Unable to find payment object (#%s)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $payment_id ), 'warning' );

					continue;
				}

				$affiliate = $payment->get_affiliate();

				if ( ! $affiliate ) {
					// translators: 1. Payment ID.
					$message = sprintf( _x( 'Unable to find affiliate for payment (#%s)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $payment_id );

					$this->log( $message, 'warning' );
					$payment->add_note( $message );

					continue;
				}

				if ( ! $this->can_pay_affiliate( $affiliate ) ) {
					// translators: 1. Affiliate ID.
					$message = sprintf( _x( 'Cannot pay affiliate #%s with this gateway', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $affiliate->get_id() );

					$this->log( $message, 'warning' );
					$payment->add_note( $message );

					continue;
				}

				$commissions     = $payment->get_commissions();
				$commissions_ids = $commissions->get_ids();

				$mass_pay_payments[]    = $payment;
				$customer_funds_handler = new YITH_YWF_Customer( $affiliate->get_user_id() );

				// credit funds to user account.
				$credit = round( $payment->get_amount(), wc_get_price_decimals() );
				$customer_funds_handler->add_funds( $credit );

				$payment->set_status( 'completed' );

				// translators: 1. Commission(s) id (when many, CSV list). 2. Payment ID.
				$message = sprintf( _nx( 'Funds credited to the user as payment for commission %1$s (Payment #%2$d)', 'Funds credited to user as payment for commissions %1$s (Payment #%2$d)', count( $commissions ), '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), implode( ', ', $commissions_ids ), $payment_id );

				// log operation.
				YWF_Log()->add_log(
					array(
						'user_id'        => $affiliate['user_id'],
						'order_id'       => '',
						'fund_user'      => $credit,
						'type_operation' => 'admin_op',
						'description'    => $message,
					)
				);
				$this->log( $message );
				$payment->add_note( _x( 'Payment credited successfully to the user\'s funds', '[ADMIN] Gateway messages.', 'yith-woocommerce-affiliates' ) );
				$payment->save();

				/**
				 * DO_ACTION: yith_wcaf_payment_sent
				 *
				 * Allows to trigger some action when the payment is sent.
				 *
				 * @param YITH_WCAF_Payment $payment Payment object.
				 */
				do_action( 'yith_wcaf_payment_sent', $payment );

			}

			return array(
				'status'   => true,
				'messages' => _x( 'Payment sent', '[ADMIN] Gateway messages.', 'yith-woocommerce-affiliates' ),
			);
		}
	}
}
