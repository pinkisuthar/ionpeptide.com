<?php
/**
 * Static class that will perform required operations to offer support for WooCommerce Subscription
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_WooCommerce_Subscription_Compatibility' ) ) {
	/**
	 * This class is only included when WooCommerce Subscription is active
	 * It adds options and functionality to make YITH WooCommerce Affiliates work with this plugin
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_WooCommerce_Subscription_Compatibility {

		/**
		 * Performs all required add_actions to init class
		 */
		public static function init() {
			// filters plugins options.
			add_filter( 'yith_wcaf_commissions_payments_settings', array( self::class, 'filter_options' ), 15 );

			// init integration.
			add_action( 'init', array( self::class, 'init_integration' ), 15 );

			// display custom coupons type correctly on dashboard table.
			add_filter( 'yith_wcaf_coupon_sign_up_fee_amount', array( self::class, 'render_coupon_type_amount' ), 10, 2 );
			add_filter( 'yith_wcaf_coupon_sign_up_fee_percent_amount', array( self::class, 'render_coupon_type_amount' ), 10, 2 );
			add_filter( 'yith_wcaf_coupon_recurring_fee_amount', array( self::class, 'render_coupon_type_amount' ), 10, 2 );
			add_filter( 'yith_wcaf_coupon_recurring_percent_amount', array( self::class, 'render_coupon_type_amount' ), 10, 2 );
		}

		/**
		 * Init integration, depending on option value
		 */
		public static function init_integration() {
			$handle_subscription = get_option( 'yith_wcaf_woo_subscription_renew_handling', 'none' );

			if ( 'all_renews' === $handle_subscription ) {
				add_filter( 'wcs_renewal_order_created', array( self::class, 'generate_commissions_for_subscription_renews' ), 10, 2 );
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
				'commission-woo-subscription-renews-handling' => array(
					'title'     => __( 'Enable commission handling for WC Subscriptions\' renews', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'select',
					'class'     => 'wc-enhanced-select',
					'desc'      => __( 'Generate commission for WC Subscription renews when first order was registered to an affiliate', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_woo_subscription_renew_handling',
					'default'   => 'none',
					'options'   => array(
						'none'       => __( 'Do not handle renews', 'yith-woocommerce-affiliates' ),
						'all_renews' => __( 'Register all renews', 'yith-woocommerce-affiliates' ),
					),
				),
			);

			$options['settings-commissions-payments'] = yith_wcaf_append_items( $commission_options, 'commission-pending-notify-admin', $specific_options );

			return $options;
		}

		/**
		 * Generate commissions for WC Subscription renew order
		 *
		 * @param \WC_Order|\WP_Error $renew        Renew order.
		 * @param \WC_Subscription    $subscription Subscription.
		 * @retrn \WC_Order|\WP_Error Unmodified input of the filter
		 */
		public static function generate_commissions_for_subscription_renews( $renew, $subscription ) {
			$original_order_id = $subscription->get_parent_id();

			if ( ! $original_order_id ) {
				return $renew;
			}

			$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_order_id( $original_order_id );

			if ( ! $affiliate || ! $renew || is_wp_error( $renew ) ) {
				return $renew;
			}

			YITH_WCAF_Orders()->create_commissions( $renew->get_id(), $affiliate->get_token() );

			return $renew;
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

			if ( in_array( $type, array( 'sign_up_fee_percent', 'recurring_percent' ), true ) ) {
				$amount = yith_wcaf_rate_format( $amount );
			} elseif ( in_array( $type, array( 'sign_up_fee', 'recurring_fee' ), true ) ) {
				$amount = wc_price( $amount );
			}

			return $amount;
		}
	}
}
