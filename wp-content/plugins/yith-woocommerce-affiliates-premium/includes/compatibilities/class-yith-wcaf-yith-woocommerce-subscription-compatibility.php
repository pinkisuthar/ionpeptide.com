<?php
/**
 * Static class that will perform required operations to offer support for YITH WooCommerce Subscription
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_YITH_WooCommerce_Subscription_Compatibility' ) ) {
	/**
	 * This class is only included when YITH WooCommerce Subscription is active
	 * It adds options and functionality to make YITH WooCommerce Affiliates work with this plugin
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_YITH_WooCommerce_Subscription_Compatibility {

		/**
		 * Performs all required add_actions to init class
		 */
		public static function init() {
			// filters plugins options.
			add_filter( 'yith_wcaf_commissions_payments_settings', array( self::class, 'filter_options' ), 15 );

			// removes affiliates meta from renew order.
			add_filter( 'ywsbs_renew_order_item_meta_data', array( self::class, 'remove_renew_item_meta' ), 10, 3 );

			// init integration.
			add_action( 'init', array( self::class, 'init_integration' ), 15 );

			// display custom coupons type correctly on dashboard table.
			add_filter( 'yith_wcaf_coupon_signup_fixed_amount', array( self::class, 'render_coupon_type_amount' ), 10, 2 );
			add_filter( 'yith_wcaf_coupon_signup_percent_amount', array( self::class, 'render_coupon_type_amount' ), 10, 2 );
			add_filter( 'yith_wcaf_coupon_recurring_fixed_amount', array( self::class, 'render_coupon_type_amount' ), 10, 2 );
			add_filter( 'yith_wcaf_coupon_recurring_percent_amount', array( self::class, 'render_coupon_type_amount' ), 10, 2 );
		}

		/**
		 * Init integration, depending on option value
		 */
		public static function init_integration() {
			$handle_subscription = get_option( 'yith_wcaf_subscription_renew_handling', 'none' );

			if ( 'only_after_activation' === $handle_subscription ) {
				add_action( 'ywsbs_subscription_status_trial_to_active', array( self::class, 'generate_commissions_for_subscription_renews' ), 10, 1 );
			} elseif ( 'all_renews' === $handle_subscription ) {
				add_action( 'ywsbs_renew_order_payed', array( self::class, 'generate_commissions_for_subscription_renews' ), 10, 1 );
			}
		}

		/**
		 * Adds options specific to current integration
		 *
		 * @param array $options Available options.
		 * @return array Filtered options.
		 */
		public static function filter_options( $options ) {
			$commission_options = $options['settings-commissions-payments'];
			$specific_options   = array(
				'commission-subscription-renews-handling' => array(
					'title'     => __( 'Enable commission handling for YITH Subscriptions\' renews', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'select',
					'class'     => 'wc-enhanced-select',
					'desc'      => __( 'Generate commission for YITH WooCommerce Subscription renews when first order was registered to an affiliate', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_subscription_renew_handling',
					'default'   => 'none',
					'options'   => array(
						'none'                  => __( 'Do not handle renews', 'yith-woocommerce-affiliates' ),
						'only_after_activation' => __( 'Register only first renew, when subscriptions switches from trial to active', 'yith-woocommerce-affiliates' ),
						'all_renews'            => __( 'Register all renews', 'yith-woocommerce-affiliates' ),
					),
				),
			);

			$options['settings-commissions-payments'] = yith_wcaf_append_items( $commission_options, 'commission-pending-notify-admin', $specific_options );

			return $options;
		}

		/**
		 * Return false to avoid YITH Subscription cloning specific affiliates meta into renew orders
		 *
		 * @param bool   $register      Whether to register meta to renew or not.
		 * @param int    $order_item_id Order item id.
		 * @param string $meta_key      Order item meta key.
		 *
		 * @return bool Whether to register meta to renew order
		 * @since 1.2.4
		 */
		public static function remove_renew_item_meta( $register, $order_item_id, $meta_key ) {
			if (
				in_array(
					$meta_key,
					array(
						'_yith_wcaf_commission_id',
						'_yith_wcaf_commission_rate',
						'_yith_wcaf_commission_amount',
					),
					true
				)
			) {
				return false;
			}

			return $register;
		}

		/**
		 * Generate commissions for subscription renew
		 *
		 * @param int $subscription_id Subscription object or subscription id.
		 *
		 * @since 1.2.4
		 */
		public static function generate_commissions_for_subscription_renews( $subscription_id ) {
			$subscription = ywsbs_get_subscription( $subscription_id );

			$first_order_id = $subscription->order_id;
			$renew_order_id = $subscription->renew_order;

			if ( ! $first_order_id || ! $renew_order_id ) {
				return;
			}

			$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_order_id( $first_order_id );

			if ( ! $affiliate ) {
				return;
			}

			YITH_WCAF_Orders()->create_commissions( $renew_order_id, $affiliate->get_token() );
		}

		/**
		 * Render the coupon amount based on the coupon discount type created with WC Subscription
		 *
		 * @param string    $amount Coupon amount.
		 * @param WC_Coupon $coupon Coupon object.
		 *
		 * @return string
		 */
		public static function render_coupon_type_amount( $amount, $coupon ) {
			$type = $coupon->get_discount_type();

			if ( in_array( $type, array( 'signup_percent', 'recurring_percent' ), true ) ) {
				$amount = yith_wcaf_rate_format( $amount );
			} elseif ( in_array( $type, array( 'signup_fixed', 'recurring_fixed' ), true ) ) {
				$amount = wc_price( $amount );
			}

			return $amount;
		}
	}
}
