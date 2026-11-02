<?php
/**
 * Affiliates' coupons handling class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Coupons' ) ) {
	/**
	 * Coupon Handler
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Coupons extends YITH_WCAF_Coupons_Legacy {

		use YITH_WCAF_Trait_Singleton;

		/**
		 * Enable coupon handling for affiliates
		 *
		 * @var bool
		 */
		protected $coupons_enable = false;

		/**
		 * Show affiliate section just to affiliates with coupons
		 *
		 * @var bool
		 */
		protected $coupons_limit_section = false;

		/**
		 * Constructor method
		 */
		public function __construct() {
			// init class.
			$this->retrieve_options();

			// dashboard handling.
			add_filter( 'yith_wcaf_get_dashboard_endpoints', array( $this, 'maybe_hide_coupons_section' ) );

			// implement custom coupon restrictions.
			add_filter( 'yith_wcaf_create_order_commissions', array( $this, 'should_process_commissions_creation' ), 10, 3 );
			add_filter( 'yith_wcaf_affiliate_rate', array( $this, 'maybe_decrease_commissions_rate' ), 10, 4 );

			// endpoint fix.
			add_action( 'update_option_yith_wcaf_coupon_enable', array( $this, 'fix_coupon_endpoint' ), 10, 2 );
		}

		/* === INIT METHODS === */

		/**
		 * Retrieve options for payment from db
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function retrieve_options() {
			$this->coupons_enable        = yith_plugin_fw_is_true( get_option( 'yith_wcaf_coupon_enable', 'no' ) );
			$this->coupons_limit_section = yith_plugin_fw_is_true( get_option( 'yith_wcaf_coupon_limit_section', 'no' ) );
		}

		/* === CUSTOM RESTRICTION === */

		/**
		 * Check if we should process commissions creation, depending on the coupon added to the order
		 *
		 * @param bool   $process  Whether commissions should be processed or not.
		 * @param int    $order_id Id of the order being registered.
		 * @param string $token    Affiliate token in current session.
		 *
		 * @return bool Whether to process referrer commissions or not.
		 */
		public function should_process_commissions_creation( $process, $order_id, $token ) {
			$order = wc_get_order( $order_id );

			if ( ! $order || ! $token ) {
				return $process;
			}

			$order_coupons = $order->get_coupons();

			if ( ! $order_coupons ) {
				return $process;
			}

			foreach ( $order_coupons as $coupon_item ) {
				$coupon_code = $coupon_item->get_code();
				$coupon      = new WC_Coupon( $coupon_code );

				if ( ! $coupon->get_id() ) {
					continue;
				}

				if ( $coupon->get_meta( 'skip_commissions' ) ) {
					return false;
				}
			}

			return $process;
		}

		/**
		 * Decrease commissions for the affiliate, according to the discount applied to the order
		 * Only percentage coupons that have the related flag checked, will concur to decrease commission rate
		 *
		 * @param float      $rate      Commission rate.
		 * @param array      $affiliate Current affiliate.
		 * @param WC_Product $product   Current product.
		 * @param int        $order_id  Current order.
		 *
		 * @return float Filtered commission rate.
		 */
		public function maybe_decrease_commissions_rate( $rate, $affiliate, $product, $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order || ! $rate ) {
				return $rate;
			}

			$order_coupons = $order->get_coupons();

			if ( ! $order_coupons ) {
				return $rate;
			}

			foreach ( $order_coupons as $coupon_item ) {
				$coupon_code = $coupon_item->get_code();
				$coupon      = new WC_Coupon( $coupon_code );

				if ( ! $coupon->get_id() || ! $coupon->is_type( 'percent' ) ) {
					continue;
				}

				if ( ! $coupon->get_meta( 'subtract_from_commissions' ) ) {
					continue;
				}

				$rate = max( apply_filters( 'yith_wcaf_subtract_from_commissions_rate', $rate - $coupon->get_amount(), $rate, $coupon->get_amount() ), 0 );

				if ( $rate < 0.01 ) {
					$rate = 0;
					break;
				}
			}

			return $rate;
		}

		/* === AFFILIATE DASHBOARD METHODS === */

		/**
		 * Hide coupons section when it is not required for current user
		 *
		 * @param array $endpoints Currently available endpoints.
		 * @return array Filtered array of endpoints.
		 */
		public function maybe_hide_coupons_section( $endpoints ) {
			if ( ! $this->coupons_enable || ! is_user_logged_in() ) {
				return $endpoints;
			}

			$user_id   = get_current_user_id();
			$affiliate = YITH_WCAF_Affiliates()->get_affiliate_by_user_id( $user_id );

			if ( isset( $endpoints['coupons'] ) && ( ! $affiliate || ! $this->can_affiliate_see_coupons_section( $affiliate ) ) ) {
				unset( $endpoints['coupons'] );
			}

			return $endpoints;
		}

		/**
		 * Mark rewrite rules for flush when adding coupon endpoint
		 *
		 * @param string $old_value Old yith_wcaf_coupon_enable option value.
		 * @param string $value     New yith_wcaf_coupon_enable option value.
		 *
		 * @return void
		 * @since 1.3.0
		 */
		public function fix_coupon_endpoint( $old_value, $value ) {
			if ( 'yes' === $value ) {
				update_option( '_yith_wcaf_flush_rewrite_rules', true );
			}
		}

		/* === HELPER METHODS === */

		/**
		 * Returns true if coupon handling is enabled
		 *
		 * @return bool Whether coupon handling is enabled
		 */
		public function are_coupons_enabled() {
			/**
			 * APPLY_FILTERS: yith_wcaf_are_coupons_enabled
			 *
			 * Filters whether the coupon management is enabled for affiliates.
			 *
			 * @param bool $coupons_enabled Whether the coupon management is enabled or not.
			 */
			return apply_filters( 'yith_wcaf_are_coupons_enabled', ! ! $this->coupons_enable );
		}

		/**
		 * Checks whether a specific affiliate can see coupon endpoint or not in their Affiliate Dashboard
		 *
		 * @param YITH_WCAF_Affiliate $affiliate Affiliate object.
		 * @return bool
		 */
		public function can_affiliate_see_coupons_section( $affiliate ) {
			/**
			 * APPLY_FILTERS: yith_wcaf_show_coupon_section
			 *
			 * Filters whether to show the coupon section in the Affiliate Dashboard.
			 *
			 * @param bool $show_coupon_section Whether to show coupon section or not.
			 */
			return apply_filters( 'yith_wcaf_show_coupon_section', $this->coupons_enable && ( ! $this->coupons_limit_section || ! ! $affiliate->count_coupons() ), $affiliate, $this );
		}

		/**
		 * Checks whether passed coupon has an affiliate registered or not
		 *
		 * @param string|WC_Coupon $coupon Coupon to check.
		 * @return bool Test result
		 */
		public function has_coupon_affiliate( $coupon ) {
			return ! ! $this->get_coupon_affiliate( $coupon );
		}

		/**
		 * Retrieves affiliate for passed coupon
		 *
		 * @param string|WC_Coupon $coupon Coupon to check.
		 * @return YITH_WCAF_Affiliate|bool Affiliate assigned to coupon, or false if none.
		 */
		public function get_coupon_affiliate( $coupon ) {
			if ( ! $coupon instanceof WC_Coupon ) {
				$coupon = new WC_Coupon( $coupon );
			}

			if ( 0 === $coupon->get_id() ) {
				return false;
			}

			$coupon_referrer = $coupon->get_meta( 'coupon_referrer' );

			if ( ! $coupon_referrer ) {
				return false;
			}

			return YITH_WCAF_Affiliates()->get_affiliate_by_id( $coupon_referrer );
		}

		/**
		 * Returns true if affiliate has at least one coupon
		 *
		 * @param  int $affiliate_id Affiliate ID.
		 * @return bool Whether affiliates has at least one coupon
		 */
		public function has_affiliate_coupons( $affiliate_id ) {
			return (bool) $this->count_affiliate_coupons( $affiliate_id );
		}

		/**
		 * Returns number of coupons bind to affiliate
		 *
		 * @param  int $affiliate_id Affiliate ID.
		 * @return int Number of coupons bind to affiliate
		 */
		public function count_affiliate_coupons( $affiliate_id ) {
			try {
				$data_store = WC_Data_Store::load( 'affiliate_coupon' );
			} catch ( Exception $e ) {
				return 0;
			}

			$args = array(
				'affiliate_id' => $affiliate_id,
			);

			$res = $data_store->count( $args );

			return $res;
		}

		/**
		 * Returns list of coupons ID related to a specific affiliate
		 *
		 * @param int   $affiliate_id Affiliate ID.
		 * @param array $args         Array of arguments for the query.
		 * @return array Array of coupon ids
		 */
		public function get_affiliate_coupons( $affiliate_id, $args = array() ) {
			try {
				$data_store = WC_Data_Store::load( 'affiliate_coupon' );
			} catch ( Exception $e ) {
				return array();
			}

			$args = array_merge(
				$args,
				array(
					'affiliate_id' => $affiliate_id,
					'fields'       => 'ids',
				)
			);

			$res = $data_store->query( $args );

			return $res;
		}

		/* === PROMO METHODS === */

		/**
		 * Returns parameter used in query string to trigger apply promo handling
		 *
		 * @return string Apply promo query string param
		 */
		public function get_apply_promo_param() {
			/**
			 * APPLY_FILTERS: yith_wcaf_apply_promo_param
			 *
			 * Filters the parameter used in the query string to trigger the apply promo handling.
			 *
			 * @param string $parameter Query string parameter.
			 */
			return apply_filters( 'yith_wcaf_apply_promo_param', 'apply-promo' );
		}
	}
}

/**
 * Unique access to instance of YITH_WCAF_Coupon_Handler class
 *
 * @return \YITH_WCAF_Coupons
 * @since 1.0.0
 */
function YITH_WCAF_Coupons() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return YITH_WCAF_Coupons::get_instance();
}
