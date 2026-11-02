<?php
/**
 * Static class that will handle all crons for the plugin
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Crons_Handler_Premium' ) ) {
	/**
	 * WooCommerce Affiliates Crons Handler
	 *
	 * @since 3.0.0
	 */
	class YITH_WCAF_Crons_Handler_Premium extends YITH_WCAF_Crons_Handler {

		/**
		 * Add premium crons to the list of supported ones
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return array Array of filtered crons.
		 */
		public static function get_crons( $context = 'view' ) {
			if ( empty( self::$crons ) ) {
				self::$crons = array_merge(
					parent::get_crons( 'edit' ),
					array(
						'yith_wcaf_pay_commissions' => array(
							'schedule'  => 'daily',
							'callback'  => array( self::class, 'pay_commissions' ),
							'condition' => function() {
								$gateway_id = get_option( 'yith_wcaf_payment_default_gateway' );
								$payment_type = get_option( 'yith_wcaf_payment_type', 'manually' );

								return ! empty( $gateway_id ) && in_array( $payment_type, array( 'automatically_on_date', 'automatically_on_threshold', 'automatically_on_both', 'automatically_every_day' ), true );
							},
						),
						'yith_wcaf_delete_clicks'   => array(
							'schedule'  => 'daily',
							'callback'  => array( self::class, 'delete_clicks' ),
							'condition' => function() {
								return yith_plugin_fw_is_true( get_option( 'yith_wcaf_click_auto_delete', 'no' ) );
							},
						),
					)
				);
			}

			if ( 'view' === $context ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_crons
				 *
				 * Filters the registered crons.
				 *
				 * @param array $crons Registered crons.
				 */
				return apply_filters( 'yith_wcaf_crons', self::$crons );
			}

			return self::$crons;
		}

		/* === CRON HANDLERS === */

		/**
		 * Execute periodically clicks deletion
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public static function delete_clicks() {
			$schedule   = get_option( 'yith_wcaf_click_auto_delete', 'no' );
			$expiration = yith_wcaf_duration_to_secs( get_option( 'yith_wcaf_click_auto_delete_expiration', 30 * DAY_IN_SECONDS ) );

			if ( ! yith_plugin_fw_is_true( $schedule ) ) {
				return;
			}

			$time = gmdate( 'Y-m-d 00:00:00', time() - $expiration );

			try {
				$data_store = WC_Data_Store::load( 'click' );

				$data_store->delete_all(
					array(
						'interval' => array(
							'end_date' => $time,
						),
					)
				);
			} catch ( Exception $e ) {
				return;
			}
		}

		/**
		 * Execute necessary operations for affiliate payment, during scheduled action
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public static function pay_commissions() {
			$gateway_id               = get_option( 'yith_wcaf_payment_default_gateway' );
			$payment_type             = get_option( 'yith_wcaf_payment_type', 'manually' );
			$payment_date             = (int) get_option( 'yith_wcaf_payment_date', 15 );
			$payment_threshold        = (float) get_option( 'yith_wcaf_payment_threshold', 50 );
			$payment_commission_age   = (int) get_option( 'yith_wcaf_payment_commission_age', 15 );
			$pay_only_old_commissions = 'yes' === get_option( 'yith_wcaf_payment_pay_only_old_commissions', 'no' );

			if ( ! self::should_schedule( 'yith_wcaf_pay_commissions' ) ) {
				return;
			}

			// check whether user supplied a valid payment_date, if payment is scheduled to happen on a specific date.
			if ( in_array( $payment_type, array( 'automatically_on_date', 'automatically_on_both' ), true ) && empty( $payment_date ) ) {
				return;
			}

			// check whether we're in the correct date, if payment is scheduled to happen on a specific date.
			if ( in_array( $payment_type, array( 'automatically_on_date', 'automatically_on_both' ), true ) ) {
				$current_day = gmdate( 'j' );

				if ( $current_day !== (string) $payment_date ) {
					return;
				}
			}

			// set minimum balance that affiliate must match.
			$min_balance = 0.01;

			// use payment threshold as minimum required balance, if set.
			if ( in_array( $payment_type, array( 'automatically_on_threshold', 'automatically_on_both' ), true ) && ! empty( $payment_threshold ) ) {
				$min_balance = $payment_threshold;
			}

			$gateway_id = YITH_WCAF_Gateways::is_valid_gateway( $gateway_id ) ? $gateway_id : false;
			$affiliates = YITH_WCAF_Affiliate_Factory::get_affiliates(
				array(
					'balance' => array(
						'min' => $min_balance,
					),
				)
			);

			if ( ! $affiliates ) {
				return;
			}

			$additional_commissions_query_args = array(
				'status' => 'pending',
			);

			if ( $pay_only_old_commissions && ! empty( $payment_commission_age ) ) {
				$threshold_time = time() - ( $payment_commission_age * DAY_IN_SECONDS );

				$additional_commissions_query_args = array_merge(
					$additional_commissions_query_args,
					array(
						'interval' => array(
							'end_date' => gmdate( 'Y-m-d H:i:s', $threshold_time ),
						),
					)
				);
			}

			foreach ( $affiliates as $affiliate ) {
				// check if affiliate reached earning threshold, when needed.
				if ( in_array( $payment_type, array( 'automatically_on_threshold', 'automatically_on_both' ), true ) && ! empty( $payment_threshold ) ) {
					$commissions    = $affiliate->get_commissions( $additional_commissions_query_args );
					$total_earnings = $commissions->get_total_amount();

					if ( $total_earnings <= $payment_threshold ) {
						continue;
					}
				}

				// pay all affiliate matching commissions.
				YITH_WCAF_Payments()->pay_all_affiliate_commissions( $affiliate->get_id(), (bool) $gateway_id, $gateway_id, $additional_commissions_query_args );
			}
		}
	}
}
