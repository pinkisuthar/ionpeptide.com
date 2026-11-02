<?php
/**
 * Payment Handler Premium class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Payments_Premium' ) ) {
	/**
	 * WooCommerce Payment Handler Premium
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Payments_Premium extends YITH_WCAF_Payments {

		/* === HELPER METHODS === */

		/**
		 * Register payments for a bunch of commissions; will create different mass pay foreach affiliate referred by commissions
		 *
		 * @param int[]|int   $commission_ids       Array of commissions' ids to pay, or single commission id.
		 * @param bool        $proceed_with_payment Whether to call gateways to pay, or just register payments.
		 * @param bool|string $gateway_id           Gateway to use for payments; default value (false) force to use default gateway.
		 *
		 * @return mixed Array with payment status, when \$proceed_with_payment is enabled; false otherwise
		 */
		public function register_payment( $commission_ids, $proceed_with_payment = true, $gateway_id = false ) {
			$res = parent::register_payment( $commission_ids, $proceed_with_payment, $gateway_id );

			list ( $status, $payments ) = yith_plugin_fw_extract( $res, 'status', 'payments' );

			if ( ! $status || empty( $payments ) ) {
				return $res;
			}

			$to_pay = array();

			foreach ( $payments as $payment ) {
				$payment_id = $payment->get_id();

				/**
				 * APPLY_FILTERS: yith_wcaf_proceed_with_payment
				 *
				 * Filters whether is possible to proceed with the payment.
				 *
				 * @param bool              $proceed_with_payment Whether to proceed with the payment or not.
				 * @param int               $payment_id           Payment id.
				 * @param YITH_WCAF_Payment $payment              Payment object.
				 * @param string            $gateway_id           Gateway id to use for payments.
				 */
				if ( apply_filters( 'yith_wcaf_proceed_with_payment', $proceed_with_payment, $payment_id, $payment, $gateway_id ) ) {
					$to_pay[] = $payment_id;
				}
			}

			if ( empty( $to_pay ) ) {
				return $res;
			}

			return array_merge(
				$res,
				$this->pay( $gateway_id, $to_pay )
			);
		}

		/**
		 * Register payment for all pending commission of an affiliate; will create different mass pay foreach affiliate referred by commissions
		 *
		 * @param int         $affiliate_id         Affiliate id.
		 * @param bool        $proceed_with_payment Whether to call gateways to pay, or just register payments.
		 * @param bool|string $gateway_id           Gateway to use for payments; default value (false) force to use default gateway.
		 * @param array       $args                 Additional arguments for commission filtering.
		 *
		 * @return mixed Array with payment status, when \$proceed_with_payment is enabled; false otherwise
		 */
		public function pay_all_affiliate_commissions( $affiliate_id, $proceed_with_payment = true, $gateway_id = false, $args = array() ) {
			/**
			 * APPLY_FILTERS: yith_wcaf_pay_all_affiliates_commissions_args
			 *
			 * Filters the array of arguments to pay the commissions.
			 *
			 * @param array $args Array of arguments.
			 */
			$args = apply_filters(
				'yith_wcaf_pay_all_affiliates_commissions_args',
				array_merge(
					$args,
					array(
						'affiliate_id' => $affiliate_id,
						'status'       => 'pending',
						'fields'       => 'ids',
					)
				)
			);

			$commissions = YITH_WCAF_Commission_Factory::get_commissions( $args );

			if ( empty( $commissions ) ) {
				return array(
					'status'         => false,
					'message'        => __( 'Affiliate needs to have at least one unpaid commission', 'yith-woocommerce-affiliates' ),
					'can_be_paid'    => array(),
					'cannot_be_paid' => array(),
				);
			}

			return $this->register_payment( $commissions, $proceed_with_payment, $gateway_id );
		}

		/**
		 * Register payments for all commissions older then a specific timestamp; will create different mass pay foreach affiliate referred by commissions
		 *
		 * @param int         $threshold_timestamp  Timestamp that should be used as threshold; older pending commissions will be paid.
		 * @param bool        $proceed_with_payment Whether to call gateways to pay, or just register payments.
		 * @param bool|string $gateway_id           Gateway to use for payments; default value (false) force to use default gateway.
		 * @param array       $args                 Additional arguments for commission filtering.
		 *
		 * @return mixed Array with payment status, when \$proceed_with_payment is enabled; false otherwise
		 */
		public function pay_all_commissions_older_than( $threshold_timestamp, $proceed_with_payment = true, $gateway_id = false, $args = array() ) {
			$commissions = YITH_WCAF_Commission_Factory::get_commissions(
				array_merge(
					$args,
					array(
						'status'   => 'pending',
						'interval' => array(
							'end_date' => gmdate( 'Y-m-d H:i:s', $threshold_timestamp ),
						),
						'fields'   => 'ids',
					)
				)
			);

			if ( empty( $commissions ) ) {
				return array(
					'status'         => false,
					'message'        => __( 'Affiliate needs to have at least one unpaid commission', 'yith-woocommerce-affiliates' ),
					'can_be_paid'    => array(),
					'cannot_be_paid' => array(),
				);
			}

			return $this->register_payment( $commissions, $proceed_with_payment, $gateway_id );
		}

		/* === GATEWAYS HANDLING METHODS === */

		/**
		 * Pay a payment instance previously created
		 *
		 * @param string    $gateway_id   A valid gateway id.
		 * @param int|int[] $payment_ids  Payment id(s).
		 *
		 * @return bool|mixed Payment status; false on failure
		 */
		public function pay( $gateway_id, $payment_ids ) {
			// if no payment id is passed, skip.
			if ( ! $payment_ids ) {
				return array(
					'status'   => true,
					'messages' => _x( 'No payments to process.', '[ADMIN] Payment messages.', 'yith-woocommerce-affiliates' ),
				);
			}

			// if no gateway is provided, mark as completed.
			if ( ! $gateway_id ) {
				foreach ( $payment_ids as $payment_id ) {
					$payment = YITH_WCAF_Payment_Factory::get_payment( $payment_id );

					if ( ! $payment ) {
						continue;
					}

					$payment->set_status( 'completed' );
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

				/**
				 * DO_ACTION: yith_wcaf_payments_sent
				 *
				 * Allows to trigger some action when the payments are sent.
				 *
				 * @param array $payment_ids Array with the ids of the payments sent.
				 */
				do_action( 'yith_wcaf_payments_sent', (array) $payment_ids );

				return array(
					'status'   => true,
					'messages' => _x( 'Payments registered correctly.', '[ADMIN] Payment messages.', 'yith-woocommerce-affiliates' ),
				);
			}

			// if not a registered gateway, return false.
			if ( ! YITH_WCAF_Gateways::is_valid_gateway( $gateway_id ) ) {
				return array(
					'status'   => false,
					'messages' => _x( 'The gateway supplied is invalid.', '[ADMIN] Payment messages.', 'yith-woocommerce-affiliates' ),
				);
			}

			$gateway = YITH_WCAF_Gateways::get_gateway( $gateway_id );
			$res     = $gateway->pay( $payment_ids );

			if ( $res['status'] ) {
				do_action( 'yith_wcaf_payments_sent', (array) $payment_ids );
			}

			return $res;
		}
	}
}
