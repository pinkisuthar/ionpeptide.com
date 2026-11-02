<?php
/**
 * Paypal Gateway class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Paypal_Gateway' ) ) {
	/**
	 * Paypal Gateway
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Paypal_Gateway extends YITH_WCAF_Abstract_Gateway {

		/**
		 * Status for payments correctly sent
		 *
		 * @cont  string Status for payments correctly sent
		 * @since 1.0.0
		 */
		const PAYMENT_STATUS_OK = 'Success';

		/**
		 * Status for payments failed
		 *
		 * @cont  string Status for payments failed
		 * @since 1.0.0
		 */
		const PAYMENT_STATUS_FAIL = 'Failure';

		/**
		 * Constructor method
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// init class attributes.
			$this->id       = 'paypal';
			$this->name     = _x( 'PayPal MassPay', '[ADMIN] Gateway name', 'yith-woocommerce-affiliates' );
			$this->supports = array(
				'masspay' => true,
			);

			parent::__construct();

			// handle notification.
			if ( $this->is_available() ) {
				add_action( 'init', array( $this, 'handle_notification' ), 15 );
			}

			// set legacy preferences for the affiliate, when needed.
			add_filter( 'yith_wcaf_affiliate_paypal_gateway_preferences', array( $this, 'set_legacy_preferences' ), 10, 3 );
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
		 * Init settings for the gateway
		 *
		 * @return void
		 */
		protected function init_settings() {
			$this->settings = array(
				'enable_sandbox'       => array(
					'label'   => _x( 'Enable PayPal sandbox', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'type'    => 'onoff',
					'desc'    => _x( 'Enable to issue payments to PayPal sandbox server for testing purposes.', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'default' => 'no',
				),

				'enable_log'           => array(
					'label'   => _x( 'Save PayPal logs', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'type'    => 'onoff',
					'desc'    => _x( 'Enable to log PayPal operations in a dedicated log file.', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'default' => 'yes',
				),

				'api_username'         => array(
					'label'   => _x( 'PayPal API username', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'type'    => 'text',
					'desc'    => _x( 'Enter the PayPal API username.', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'default' => '',
				),

				'api_password'         => array(
					'label'   => _x( 'PayPal API password', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'type'    => 'text',
					'desc'    => _x( 'Enter PayPal API password.', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'default' => '',
				),

				'api_signature'        => array(
					'label'   => _x( 'PayPal API signature', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'type'    => 'text',
					'desc'    => _x( 'Enter PayPal API signature.', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'default' => '',
				),

				'email_subject'        => array(
					'label'   => _x( 'Payment email subject', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'type'    => 'text',
					'desc'    => _x( 'Enter the subject of the email sent by PayPal to customers when a payment request is registered.', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'default' => '',
				),

				'ipn_notification_url' => array(
					'label'             => _x( 'PayPal notification URL', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'type'              => 'text',
					'desc'              => _x( 'Copy this URL and set it into the PayPal admin panel to receive IPN from their server.', '[ADMIN] PayPal gateway settings.', 'yith-woocommerce-affiliates' ),
					'default'           => site_url() . '/?paypal_ipn_response=true',
					'custom_attributes' => array(
						'readonly' => 'readonly',
					),
				),
			);
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

		/* === GETTERS === */

		/**
		 * Returns true if Sandbox Mode is enabled
		 *
		 * @return bool Whether Sandbox  mode is enabled or not.
		 */
		public function is_sandbox_mode_enabled() {
			return yith_plugin_fw_is_true( $this->get_option( 'enable_sandbox' ) );
		}

		/* === PAYMENT METHODS === */

		/**
		 * Execute a mass payment
		 *
		 * @param int|int[] $payment_ids Array of registered payments to issue to paypal servers.
		 *
		 * @return mixed Array with operation status and messages
		 * @since 1.0.0
		 */
		public function process_payment( $payment_ids ) {
			// retrieve required options.
			list( $api_username, $api_password, $api_signature, $email_subject ) = yith_plugin_fw_extract( $this->get_options(), 'api_username', 'api_password', 'api_signature', 'email_subject' );

			// include required libraries.
			require_once YITH_WCAF_INC . 'third-party/PayPal/PayPal.php';

			if ( empty( $api_username ) || empty( $api_password ) || empty( $api_signature ) ) {
				$message = _x( 'Missing required parameters in PayPal\'s configuration', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' );

				$this->log( $message, 'error' );

				return array(
					'status'   => false,
					'messages' => $message,
				);
			}

			$mass_pay_payments = array();

			// make sure we're dealing with an array of ids.
			$payment_ids = (array) $payment_ids;

			// translators: 1. Payment IDs.
			$this->log( sprintf( _x( 'Trying to pay %s with PayPal', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), implode( ', ', $payment_ids ) ) );

			// group payments per currency, and create arguments for each item to pay.
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

				$payment_gateway_details   = $payment->get_gateway_details();
				$affiliate_gateway_details = $affiliate->get_gateway_preferences( $this->id );

				if ( ! empty( $payment_gateway_details['paypal_email'] ) ) {
					$paypal_email = $payment_gateway_details['paypal_email'];
				} elseif ( $payment->get_email() ) {
					$paypal_email = $payment->get_email();
				} elseif ( ! empty( $affiliate_gateway_details['paypal_email'] ) ) {
					$paypal_email = $affiliate_gateway_details['paypal_email'];
				} elseif ( $affiliate->get_payment_email() ) {
					$paypal_email = $affiliate->get_payment_email();
				} else {
					// translators: 1. Payment ID.
					$message = sprintf( _x( 'Missing required information for payment %s (paypal_email)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $payment_id );

					$this->log( $message, 'warning' );
					$payment->add_note( $message );

					continue;
				}

				$currency = $payment->get_currency();

				if ( ! isset( $mass_pay_payments[ $currency ] ) ) {
					$mass_pay_payments[ $currency ] = array();
				}

				$mass_pay_payments[ $currency ][] = array(
					'payment'  => $payment,
					'receiver' => $paypal_email,
					'item'     => array(
						'l_email'    => $paypal_email,
						'l_amt'      => round( $payment->get_amount(), wc_get_price_decimals() ),
						'l_uniqueid' => $payment->get_id(),
					),
				);
			}

			// if no record is set to be paid, return.
			if ( empty( $mass_pay_payments ) ) {
				$message = _x( 'No record could be processed for this PayPal payment', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' );

				$this->log( $message, 'warning' );

				return array(
					'status'   => false,
					'messages' => $message,
				);
			}

			// create basic process configuration.
			$paypal_config = array(
				'Sandbox'      => $this->is_sandbox_mode_enabled(),
				'APIUsername'  => $api_username,
				'APIPassword'  => $api_password,
				'APISignature' => $api_signature,
				'PrintHeaders' => true,
				'LogResults'   => false,
			);

			// create processor object.
			$processor = new angelleye\PayPal\PayPal( $paypal_config );

			// start sending payments for each currency.
			$sent   = true;
			$errors = array();

			foreach ( $mass_pay_payments as $currency => $payments_per_currency ) {
				// Prepare request arrays.
				$fields = array(
					'emailsubject' => $email_subject,
					'currencycode' => $currency,
					'receivertype' => 'EmailAddress',
				);

				$items_per_currency = wp_list_pluck( $payments_per_currency, 'item' );

				// create request with general arguments and items for current currency.
				$request = array(
					'MPFields' => $fields,
					'MPItems'  => $items_per_currency,
				);

				// submit request.
				$result = $processor->MassPay( $request );

				$this->log_data( _x( 'Request sent correctly', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $request );
				$this->log_data( _x( 'PayPal server response received correctly', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $result );

				if ( self::PAYMENT_STATUS_FAIL === $result['ACK'] ) {
					// if request fails, system will report operation as overall failed.
					$sent = false;

					foreach ( $result['ERRORS'] as $error ) {
						$errors[] = $error['L_LONGMESSAGE'];
					}
				} elseif ( self::PAYMENT_STATUS_OK === $result['ACK'] ) {
					// if request succeed, change payment status and register notes.
					foreach ( $payments_per_currency as $mass_pay_payment ) {
						$sent_payment = $mass_pay_payment['payment'];

						$sent_payment->add_note( _x( 'Payment correctly issued to PayPal.', '[ADMIN] Payment notes.', 'yith-woocommerce-affiliates' ) );
						$sent_payment->set_status( 'pending' );
						$sent_payment->set_email( $mass_pay_payment['receiver'] );
						$sent_payment->set_gateway_details(
							array(
								'paypal_email' => $mass_pay_payment['receiver'],
							)
						);

						$sent_payment->save();

						// translators: 1. Payment id.
						$this->log( sprintf( _x( 'Payment %s issued successfully to PayPal.', '[ADMIN] Gateway log.', 'yith-woocommerce-affiliates' ), $sent_payment->get_id() ) );

						/**
						 * DO_ACTION: yith_wcaf_payment_sent
						 *
						 * Allows to trigger some action when the payment is sent.
						 *
						 * @param YITH_WCAF_Payment $payment Payment object.
						 */
						do_action( 'yith_wcaf_payment_sent', $sent_payment );
					}
				}
			}

			// returns a status array to the caller.
			return array(
				'status'   => $sent,
				'messages' => $sent ? $errors : _x( 'Payments sent', '[ADMIN] Gateway messages.', 'yith-woocommerce-affiliates' ),
			);
		}

		/**
		 * Handle PayPal IPN
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function handle_notification() {
			if ( ! isset( $_GET['paypal_ipn_response'] ) || true !== (bool) $_GET['paypal_ipn_response'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}

			$verified = false;

			// include required libraries.
			require_once YITH_WCAF_INC . '/third-party/IPNListener/ipnlistener.php';

			$listener = new IpnListener();

			/**
			 * APPLY_FILTERS: yith_wcaf_ipn_listener_force_ssl_v4
			 *
			 * Filters whether to force the use of SSL v4.
			 *
			 * @param bool $force_ssl_v4  Whether to force the use of SSL v4 or not.
			 */
			$listener->force_ssl_v4 = apply_filters( 'yith_wcaf_ipn_listener_force_ssl_v4', false );
			$listener->use_sandbox  = $this->is_sandbox_mode_enabled();

			try {
				// process IPN request, require validation to PayPal server.
				$listener->requirePostMethod();
				$verified = $listener->processIpn();
			} catch ( Exception $e ) {
				// fatal error trying to process IPN.
				$this->log( _x( 'Couldn\'t verify IPN', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), 'error' );
				$this->log( $listener->getTextReport() );
				die();
			}

			// if PayPal says IPN is valid, process content.
			if ( $verified ) {
				// nonce verification isn't possible in this context;
				// anyway, previous validation assures us that requests comes from PayPal and it isn't forged.
				// phpcs:ignore WordPress.Security.NonceVerification
				$request_data = $_POST;

				if ( ! isset( $request_data['payment_status'] ) ) {
					$this->log( _x( 'Invalid payment status', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), 'warning' );
					die();
				}

				// format payment data.
				$payment_data = array();

				for ( $i = 1; isset( $request_data[ 'status_' . $i ] ); $i ++ ) {
					$data_index = array_keys( $request_data );

					foreach ( $data_index as $index ) {
						if ( false === strpos( $index, '_' . $i ) ) {
							continue;
						}

						$not_cardinal_index = str_replace( '_' . $i, '', $index );

						$payment_data[ $i ][ $not_cardinal_index ] = sanitize_text_field( wp_unslash( $request_data[ $index ] ) );
						unset( $request_data[ $index ] );
					}
				}

				if ( ! empty( $payment_data ) ) {
					foreach ( $payment_data as $record ) {
						if ( ! isset( $record['unique_id'] ) ) {
							continue;
						}

						$args                   = array();
						$args['unique_id']      = (int) $record['unique_id'];
						$args['gross']          = round( (float) $record['mc_gross'], 2 );
						$args['status']         = sanitize_text_field( wp_unslash( $record['status'] ) );
						$args['receiver_email'] = sanitize_email( wp_unslash( $record['receiver_email'] ) );
						$args['currency']       = sanitize_text_field( wp_unslash( $record['mc_currency'] ) );
						$args['txn_id']         = sanitize_text_field( wp_unslash( $record['masspay_txn_id'] ) );

						$this->log( 'IPN received - ' . $record['unique_id'] );
						$this->log( $listener->getTextReport(), true );

						$payment = YITH_WCAF_Payment_Factory::get_payment( $record['unique_id'] );

						// if cannot find payment, skip execution.
						if ( ! $payment ) {
							// translators: 1. Received payment id.
							$this->log( sprintf( _x( 'Couldn\'t find IPN-related payment (received #%s)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $record['unique_id'] ), 'warning' );
							continue;
						}

						// if payment is not pending, skip execution.
						if ( ! $payment->has_status( 'pending' ) ) {
							// translators: 1. Payment did. 2. Payment status.
							$message = sprintf( _x( 'IPN-related payment #%1$s found in unexpected status (expected pending -> found %2$s)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $payment->get_id(), $payment->get_status() );

							$this->log( $message, 'warning' );
							$payment->add_note( $message );
							continue;
						}

						// if amount does not match, skip execution.
						if ( round( $payment->get_amount(), 2 ) !== $args['gross'] ) {
							// translators: 1. Payment id. 2. Expected amount (float). 3. Received amount (float).
							$message = sprintf( _x( 'PayPal returned a notification for payment #%1$d with an unexpected amount (expected %2$.2f -> returned %3$.2f)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $payment->get_id(), $payment->get_amount(), $args['gross'] );

							$payment->add_note( $message );
							$this->log_data(
								$message,
								array(
									'incoming_amount' => $args['gross'],
									'expected_amount' => $payment->get_amount(),
								),
								'warning'
							);

							continue;
						}

						// if currency does not match, skip execution.
						if ( $payment->get_currency() !== $args['currency'] ) {
							// translators: 1. Payment id. 2. Expected currency. 3. Received currency.
							$message = sprintf( _x( 'PayPal returned a notification for payment #%1$d indicating a different currency (expected %2$s -> returned %3$s)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $payment->get_id(), $payment->get_currency(), $args['currency'] );

							$payment->add_note( $message );
							$this->log_data(
								$message,
								array(
									'incoming_currency' => $args['currency'],
									'expected_currency' => $payment->get_currency(),
								),
								'warning'
							);

							continue;
						}

						// translators: 1. Payment id. 2. Transaction id for the payment.
						$message = sprintf( _x( 'IPN response received correctly from PayPal\'s server for payment #%1$s (txn_id: %2$s)', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), $payment->get_id(), $args['txn_id'] );

						$payment->add_note( $message );
						$this->log( $message );

						// update payment status according to IPN data.
						if ( 'Completed' === $args['status'] ) {
							$new_status = 'completed';
						} elseif ( in_array( $args['status'], array( 'Failed', 'Returned', 'Reversed', 'Blocked' ), true ) ) {
							$new_status = 'cancelled';
						} else {
							$new_status = false;
						}

						if ( ! $new_status ) {
							continue;
						}

						$payment->set_status( $new_status );

						if ( 'completed' === $new_status ) {
							$payment->set_transaction_key( $args['txn_id'] );
						}

						$payment->save();
					}
				}

				die();
			} else {
				$this->log( _x( 'Invalid request', '[ADMIN] Gateway logs.', 'yith-woocommerce-affiliates' ), 'error' );
				$this->log( $listener->getTextReport() );
				die();
			}
		}

		/* === LOG === */

		/**
		 * Log messages for the gateway
		 *
		 * @param string $message Message to log.
		 * @param string $level   Message level (@see \YITH_WCAF_Abstract_Gateway::log).
		 */
		public function log( $message, $level = 'info' ) {
			$enable_log = yith_plugin_fw_is_true( $this->get_option( 'enable_log' ) );

			if ( ! $enable_log ) {
				return;
			}

			parent::log( $message, $level );
		}
	}
}
