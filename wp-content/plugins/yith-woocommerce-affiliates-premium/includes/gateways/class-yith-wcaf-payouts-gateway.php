<?php
/**
 * Paypal Payouts Gateway class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_PayOuts_Gateway' ) ) {
	/**
	 * Paypal PayOuts Gateway
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_PayOuts_Gateway extends YITH_WCAF_Abstract_Gateway {

		/**
		 * Constructor method
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// init class attributes.
			$this->id       = 'payouts';
			$this->name     = _x( 'PayPal Payouts', '[ADMIN] Gateway name', 'yith-woocommerce-affiliates' );
			$this->supports = array(
				'masspay' => true,
			);

			parent::__construct();

			if ( $this->is_available() ) {
				add_action( 'yith_paypal_payout_item_change_status', array( $this, 'change_affiliate_payment_status' ), 10, 3 );
				add_filter( 'yith_payouts_receivers', array( $this, 'yith_remove_affiliate_from_receiver_list' ), 10, 1 );
				add_filter( 'yith_payout_receiver_email', array( $this, 'yith_return_affiliate_paypal_email' ), 10, 2 );
			}

			// set legacy preferences for the affiliate, when needed.
			add_filter( 'yith_wcaf_affiliate_payouts_gateway_preferences', array( $this, 'set_legacy_preferences' ), 10, 3 );
		}

		/**
		 * Set legacy preferences for any affiliate that doesn't have more recent data.
		 *
		 * @param array               $preferences  Affiliate gateway preferences.
		 * @param int                 $affiliate_id Affiliate id.
		 * @param YITH_WCAF_Affiliate $affiliate    Affiliate object.
		 *
		 * @return array Filtered array of preferences.
		 */
		public function set_legacy_preferences( $preferences, $affiliate_id, $affiliate ) {
			// make sure we're dealing with an array.
			$preferences = (array) $preferences;

			// set legacy preferences, if we're missing more accurate data.
			if ( empty( $preferences['paypal_email'] ) ) {
				$preferences['paypal_email'] = $affiliate->get_payment_email();
			}

			return $preferences;
		}

		/**
		 * Init fields for the gateway
		 *
		 * @return void
		 */
		protected function init_fields() {
			$this->fields = array(
				'paypal_email' => array(
					'label'   => _x( 'PayPal account email', '[ADMIN] PayPal gateway affiliate settings.', 'yith-woocommerce-affiliates' ),
					'type'    => 'email',
					'desc'    => _x( 'Enter the email address of the PayPal account where you want to receive payments.', '[ADMIN] PayPal gateway affiliate settings.', 'yith-woocommerce-affiliates' ),
					'default' => '',
				),
			);
		}

		/**
		 * Returns true if gateway is available
		 *
		 * @return bool Whether current gateway is enabled.
		 */
		public function is_available() {
			return class_exists( 'YITH_PayPal_PayOuts' );
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

			$landing = 'https://yithemes.com/themes/plugins/yith-paypal-payouts-for-woocommerce/';
			$name    = 'YITH PayPal Payouts for WooCommerce';

			// translators: 1. Url to plugin langing page. 2. Name of the required plugin.
			return sprintf( _x( 'Plugin <a href="%1$s">%2$s</a> is required', '[ADMIN] Gateway messages.', 'yith-woocommerce-affiliates' ), $landing, $name );
		}

		/* === PAYMENT METHODS === */

		/**
		 * Execute a mass payment
		 *
		 * @param array $payment_ids Array of registered payments to issue to paypal servers.
		 *
		 * @return mixed Array with operation status and messages
		 * @since 1.0.0
		 */
		public function process_payment( $payment_ids ) {
			$payout_items      = array();
			$mass_pay_payments = array();
			$status            = true;
			$errors            = array();

			if ( ! function_exists( 'YITH_PayOuts_Service' ) ) {
				YITH_PayPal_Payouts()->load_payouts_classes();
			}

			if ( ! YITH_PayOuts_Service()->check_service_configuration() ) {
				$payouts_query_args = array(
					'page' => 'yith_wc_paypal_payouts_panel',
					'tab'  => 'general-settings',
				);

				$payouts_url = esc_url( add_query_arg( $payouts_query_args, admin_url( 'admin.php' ) ) );
				$message     = sprintf(
					'%s <a href="%s">%s</a>',
					_x( 'Cannot use Payouts service, please check the Payout configuration', '[ADMIN] PayPal PayOuts messages.', 'yith-woocommerce-affiliates' ),
					$payouts_url,
					_x( 'here', '[ADMIN] PayPal PayOuts messages. (part of:  Cannot use PayOuts service, please check the Payout configuration here)', 'yith-woocommerce-affiliates' )
				);

				$this->log( _x( 'YITH PayPal Payouts for WooCommerce is not installed', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), 'error' );

				return array(
					'status'   => false,
					'messages' => esc_html( $message ),
				);

			}

			// if single payment id, convert it to array.
			$payment_ids = (array) $payment_ids;
			$currency    = get_woocommerce_currency();

			// translators: 1. Payment IDs.
			$this->log( sprintf( _x( 'Trying to pay %s with Payouts', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), implode( ', ', $payment_ids ) ) );

			foreach ( $payment_ids as $payment_id ) {
				$single_payment = YITH_WCAF_Payment_Factory::get_payment( (int) $payment_id );

				/**
				 * APPLY_FILTERS: yith_wcaf_skip_payout_payment
				 *
				 * Filters whether to skip the payout payment.
				 *
				 * @param bool              $skip_payment Whether to skip the payout payment or not.
				 * @param YITH_WCAF_Payment $payment      Payment object.
				 */
				if ( apply_filters( 'yith_wcaf_skip_payout_payment', ! $single_payment, $single_payment ) ) {
					// translators: 1. Payment ID.
					$this->log( sprintf( _x( 'Unable to find payment object (#%s)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $payment_id ), 'warning' );

					continue;
				}

				$affiliate = $single_payment->get_affiliate();

				if ( ! $affiliate ) {
					// translators: 1. Payment ID.
					$message = sprintf( _x( 'Unable to find affiliate for payment (#%s)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $payment_id );

					$this->log( $message, 'warning' );
					$single_payment->add_note( $message );

					continue;
				}

				if ( ! $this->can_pay_affiliate( $affiliate ) ) {
					// translators: 1. Affiliate ID.
					$message = sprintf( _x( 'Cannot pay affiliate #%s with this gateway', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $affiliate->get_id() );

					$this->log( $message, 'warning' );
					$single_payment->add_note( $message );

					continue;
				}

				$payment_gateway_details   = $single_payment->get_gateway_details();
				$affiliate_gateway_details = $affiliate->get_gateway_preferences( $this->id );

				if ( ! empty( $payment_gateway_details['paypal_email'] ) ) {
					$paypal_email = $payment_gateway_details['paypal_email'];
				} elseif ( $single_payment->get_email() ) {
					$paypal_email = $single_payment->get_email();
				} elseif ( ! empty( $affiliate_gateway_details['paypal_email'] ) ) {
					$paypal_email = $affiliate_gateway_details['paypal_email'];
				} elseif ( $affiliate->get_payment_email() ) {
					$paypal_email = $affiliate->get_payment_email();
				} else {
					// translators: 1. Payment ID.
					$message = sprintf( _x( 'Missing required information for payment %s (paypal_email)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $payment_id );

					$this->log( $message, 'warning' );
					$single_payment->add_note( $message );

					continue;
				}

				$single_payout = array(
					'recipient_type' => 'EMAIL',
					'receiver'       => $paypal_email,
					'note'           => 'Thank you.',
					'sender_item_id' => 'affiliate_payment_' . $payment_id,
					'amount'         => array(
						'value'    => number_format( $single_payment->get_amount(), 2 ),
						'currency' => $currency,
					),
				);

				$payout_items[]      = $single_payout;
				$mass_pay_payments[] = array(
					'payment'  => $single_payment,
					'receiver' => $paypal_email,
				);
			}

			if ( empty( $payout_items ) ) {
				$message = _x( 'No record could be processed for PayPal payment', '[ADMIN] PayPal PayOuts messages', 'yith-woocommerce-affiliates' );

				$this->log( $message, 'warning' );

				$status   = false;
				$errors[] = $message;
			} else {
				$register_args = array(
					'sender_batch_id' => 'affiliate_' . uniqid(),
					'payout_mode'     => 'affiliate',
					'order_id'        => '',
					'items'           => $payout_items,
				);
				YITH_PayOuts_Service()->register_payouts( $register_args );

				unset( $register_args['order_id'] );
				unset( $register_args['items'] );
				unset( $register_args['payout_mode'] );
				unset( $register_args['items'] );

				$register_args['sender_items'] = $payout_items;
				$register_args['commission_ids'] = $payment_ids;
				$payout = YITH_PayOuts_Service()->PayOuts( $register_args );

				if ( $payout instanceof \PayPal\Api\PayoutBatch && $payout->getBatchHeader()->getErrors() ) {
					$status   = false;
					$errors[] = $payout->getBatchHeader()->getErrors()->getMessage();
				}

				if ( $status && ! empty( $mass_pay_payments ) ) {
					foreach ( $mass_pay_payments as $mass_pay_payment ) {
						$payment  = $mass_pay_payment['payment'];
						$receiver = $mass_pay_payment['receiver'];

						$payment->add_note( _x( 'Payment correctly issued to PayPal.', '[ADMIN] Payment notes.', 'yith-woocommerce-affiliates' ) );
						$payment->set_status( 'pending' );
						$payment->set_email( $receiver );
						$payment->set_gateway_details(
							array(
								'paypal_email' => $receiver,
							)
						);
						$payment->save();

						// translators: 1. Payment id.
						$this->log( sprintf( _x( 'Payment %s issued successfully to PayPal.', '[ADMIN] Gateway log.', 'yith-woocommerce-affiliates' ), $payment->get_id() ) );

						/**
						 * DO_ACTION: yith_wcaf_payment_sent
						 *
						 * Allows to trigger some action when the payment is sent.
						 *
						 * @param YITH_WCAF_Payment $payment Payment object.
						 */
						do_action( 'yith_wcaf_payment_sent', $payment );
					}
				}
			}

			return array(
				'status'   => $status,
				'messages' => count( $errors ) > 0 ? $errors : _x( 'Payment sent', '[ADMIN] PayPal PayOuts messages', 'yith-woocommerce-affiliates' ),
			);
		}

		/**
		 * Change status of the payment when confirmation comes from PayPal
		 *
		 * @param string $payout_item_id     Payout item id.
		 * @param string $transaction_status Status of the transaction.
		 * @param array  $resource           Trasaction object.
		 */
		public function change_affiliate_payment_status( $payout_item_id, $transaction_status, $resource ) {

			$sender_item_id = isset( $resource['payout_item']['sender_item_id'] ) ? $resource['payout_item']['sender_item_id'] : '';
			$sender_item_id = str_replace( 'affiliate_payment_', '', $sender_item_id );
			$transaction_id = isset( $resource['transaction_id'] ) ? $resource['transaction_id'] : '';

			if ( ! $sender_item_id || 'success' !== $transaction_status ) {
				return;
			}

			$payment = YITH_WCAF_Payment_Factory::get_payment( $sender_item_id );

			if ( ! $payment ) {
				return;
			}

			$payment->add_note( _x( 'Payment correctly paid via PayPal.', '[ADMIN] Payment notes', 'yith-woocommerce-affiliates' ) );
			$payment->set_status( 'completed' );
			$payment->set_transaction_key( $transaction_id );
			$payment->save();

			// translators: 1. Payment id.
			$this->log( sprintf( _x( 'Payment %s correctly paid via PayPal', '[ADMIN] Gateway log.', 'yith-woocommerce-affiliates' ), $payment->get_id() ) );
		}

		/**
		 * Fix receivers array
		 *
		 * @param array $receivers Array of receivers.
		 *
		 * @return array
		 * @since  1.0.0
		 */
		public function yith_remove_affiliate_from_receiver_list( $receivers ) {
			$new_receivers = array();

			foreach ( $receivers as $key => $receiver ) {
				$user_id = $receiver['user_id'];

				if ( ! YITH_WCAF_Affiliates()->is_user_valid_affiliate( $user_id ) ) {
					$new_receivers[] = $receiver;
				}
			}

			return $new_receivers;
		}

		/**
		 * Get the affiliate paypal email for show payouts details on myaccount
		 *
		 * @param string $paypal_email Paypal email.
		 * @param int    $user_id      User id.
		 *
		 * @return string
		 * @since  1.0.0
		 *
		 */
		public function yith_return_affiliate_paypal_email( $paypal_email, $user_id ) {
			if ( empty( $paypal_email ) && YITH_WCAF_Affiliates()->is_user_valid_affiliate( $user_id ) ) {
				$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_user_id( $user_id );

				$payment_details = $affiliate->get_gateway_preferences( $this->id );

				if ( ! empty( $payment_details['paypal_email'] ) ) {
					$paypal_email = $payment_details['paypal_email'];
				} else {
					$paypal_email = $affiliate->get_payment_email();
				}
			}

			return $paypal_email;
		}
	}
}
