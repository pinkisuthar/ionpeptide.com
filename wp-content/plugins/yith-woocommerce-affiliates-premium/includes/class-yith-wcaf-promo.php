<?php
/**
 * Affiliates' promo handling class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Promo' ) ) {
	/**
	 * Promo Handler
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Promo {

		use YITH_WCAF_Trait_Singleton;

		/**
		 * Name of the cookie used to store current promo
		 *
		 * @var string
		 */
		protected $cookie_name = 'yith_wcaf_applied_promo';

		/**
		 * Constructor method
		 */
		public function __construct() {
			if ( ! YITH_WCAF_Coupons()->are_coupons_enabled() ) {
				return;
			}

			add_filter( 'woocommerce_update_cart_action_cart_updated', array( $this, 'on_cart_update' ) );
			add_action( 'woocommerce_add_to_cart', array( $this, 'on_cart_update' ) );
			add_action( 'woocommerce_cart_item_removed', array( $this, 'on_cart_update' ) );
			add_action( 'woocommerce_applied_coupon', array( $this, 'on_cart_update' ) );
			add_action( 'woocommerce_removed_coupon', array( $this, 'on_cart_update' ) );
			add_action( 'woocommerce_calculated_shipping', array( $this, 'on_cart_update' ) );
		}

		/**
		 * Stored currently saved promo
		 *
		 * @var string
		 */
		protected $saved_promo;

		/* === STATIC UTILS === */

		/**
		 * Returns parameter used in query string to trigger apply promo handling
		 *
		 * @return string Apply promo query string param
		 */
		public static function get_apply_promo_param() {
			/**
			 * APPLY_FILTERS: yith_wcaf_apply_promo_param
			 *
			 * Filters the parameter used in the url to trigger the apply promo handling.
			 *
			 * @param string $apply_promo_param Param used in the url.
			 */
			return apply_filters( 'yith_wcaf_apply_promo_param', 'apply-promo' );
		}

		/**
		 * Get url to automatically apply coupon code to current session
		 *
		 * @param string $coupon_code Coupon code to apply.
		 * @return string Apply coupon url.
		 */
		public static function get_apply_promo_url( $coupon_code ) {
			$apply_promo_param = self::get_apply_promo_param();
			$affiliate         = YITH_WCAF_Coupons()->get_coupon_affiliate( $coupon_code );

			$args = array(
				$apply_promo_param => rawurlencode( $coupon_code ),
			);

			/**
			 * APPLY_FILTERS: yith_wcaf_add_referrer_to_apply_promo_url
			 *
			 * Filters whether to add the referral token to the url to apply the coupon code.
			 *
			 * @param bool $add_referral_token Whether to add the referral token to the apply promo url or not.
			 */
			if ( $affiliate && $affiliate->get_token() && apply_filters( 'yith_wcaf_add_referrer_to_apply_promo_url', true ) ) {
				$ref_name = get_option( 'yith_wcaf_referral_var_name', 'ref' );

				$args[ $ref_name ] = $affiliate->get_token();
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_apply_promo_url
			 *
			 * Filters the url to apply automatically the coupon code.
			 *
			 * @param string $apply_promo_url Url to apply the coupon code.
			 * @param string $coupon_code     Coupon code.
			 */
			return apply_filters( 'yith_wcaf_apply_promo_url', add_query_arg( $args, home_url() ), $coupon_code );
		}

		/* === PROMO METHODS === */

		/**
		 * Apply promo to current cart; if cart is empty, save promo for future usage
		 *
		 * @param string|bool $coupon_code Coupon code to apply; if empty, saved coupon will be assumed instead.
		 * @return void.
		 */
		public function apply( $coupon_code = false ) {
			if ( ! $coupon_code ) {
				$coupon_code = $this->get_saved();
			}

			if ( ! $coupon_code ) {
				return;
			}

			// apply coupon to cart.
			$res = $this->apply_coupon( $coupon_code );

			// finally, remove saved promo, if coupon was applied correctly.
			if ( $res ) {
				$this->delete();
			} else {
				$this->save( $coupon_code );
			}
		}

		/**
		 * Process when cart is updated, and apply promo when needed/possible.
		 *
		 * @param bool|int $value Depending on current filter, it may change; anyway the only thing we're interested in, is that it is not falsy.
		 */
		public function on_cart_update( $value ) {
			if ( $value ) {
				$this->apply();
			}

			return $value;
		}

		/**
		 * Returns true if there is a saved promo
		 * If optional param is passed, function will compare saved promo with passed one
		 *
		 * @param string|bool $coupon_code Coupon code to compare with saved one.
		 * @return bool Whether a promo is saved, and optionally if it matches the passed coupon_code.
		 */
		protected function is_saved( $coupon_code = false ) {
			$saved = $this->get_saved();

			if ( ! $coupon_code ) {
				return ! ! $saved;
			}

			return $coupon_code === $saved;
		}

		/**
		 * Returns promo saved in the cookie
		 *
		 * @return string Saved promo.
		 */
		protected function get_saved() {
			if ( ! $this->saved_promo && isset( $_COOKIE[ $this->cookie_name ] ) ) {
				$this->saved_promo = sanitize_text_field( wp_unslash( $_COOKIE[ $this->cookie_name ] ) );
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_saved_promo
			 *
			 * Filters the saved promo in the cookie.
			 *
			 * @param string $saved_promo Saved promo.
			 */
			return apply_filters( 'yith_wcaf_saved_promo', $this->saved_promo );
		}

		/**
		 * Saves current promo in cookie, for future usage
		 *
		 * @param string $coupon_code Coupon code to save in cookie.
		 * @return void
		 */
		protected function save( $coupon_code ) {
			/**
			 * APPLY_FILTERS: yith_wcaf_set_promo_cookie
			 *
			 * Filters whether to set the promo cookie.
			 *
			 * @param bool $set_promo_cookie Whether to set promo cookie or not.
			 */
			if ( $this->is_saved( $coupon_code ) || ! apply_filters( 'yith_wcaf_set_promo_cookie', true ) ) {
				return;
			}

			yith_wcaf_set_cookie( $this->cookie_name, $coupon_code, WEEK_IN_SECONDS );
		}

		/**
		 * Deletes currently saved promo.
		 *
		 * @param string $coupon_code Optional coupon code to delete; when missing, any coupon would be cancelled from cookie.
		 * @return void
		 */
		protected function delete( $coupon_code = false ) {
			if ( $coupon_code && ! $this->is_saved( $coupon_code ) ) {
				return;
			}

			yith_wcaf_delete_cookie( $this->cookie_name );
		}

		/**
		 * Apply coupon to cart
		 *
		 * @param string $coupon_code Coupon code to add to cart.
		 * @return bool True if the coupon is applied, false if it does not exist or cannot be applied.
		 */
		protected function apply_coupon( $coupon_code ) {
			$cart   = WC()->cart;

			// if cart is empty, we can't consistently apply coupon.
			if ( $cart->is_empty() ) {
				return false;
			}

			// if coupon was already added to cart, consider this attempt to add it a success.
			if ( $cart->has_discount( $coupon_code ) ) {
				return true;
			}

			// change default "Apply coupon" message.
			add_filter( 'woocommerce_coupon_message', array( $this, 'change_coupon_message' ), 10, 3 );

			$res = $cart->apply_coupon( $coupon_code );

			// reset default "Apply coupon" message.
			remove_filter( 'woocommerce_coupon_message', array( $this, 'change_coupon_message' ) );

			return $res;
		}

		/* === FRONTEND METHODS === */

		/**
		 * Change default "Coupon Applied" message, to show affiliate name
		 *
		 * @param string    $msg      Default Message.
		 * @param int       $msg_code Status code.
		 * @param WC_Coupon $coupon   Coupon object.
		 *
		 * @return string Filtered coupon message
		 */
		public function change_coupon_message( $msg, $msg_code, $coupon ) {
			$coupon_referrer = $coupon->get_meta( 'coupon_referrer', true );

			if ( ! $coupon_referrer ) {
				return $msg;
			}

			$affiliate = YITH_WCAF_Affiliates()->get_affiliate_by_id( $coupon_referrer );
			$user      = get_userdata( $affiliate['user_id'] );

			if ( ! $user ) {
				return $msg;
			}

			$user_name = trim( "{$user->first_name} {$user->last_name}" );
			$user_name = empty( $user_name ) ? $user->user_login : $user_name;

			if ( empty( $user_name ) ) {
				return $msg;
			}

			// translators: 1. User name.
			$new_message = sprintf( __( '%s has offered you a nice discount! Coupon has been added to cart; proceed with purchases to enjoy your discount', 'yith-woocommerce-affiliates' ), $user_name );

			/**
			 * APPLY_FILTERS: yith_wcaf_affiliate_coupon_message
			 *
			 * Filters the message shown when the affiliate's coupon code is applied.
			 *
			 * @param string              $new_message New message.
			 * @param WC_Coupon           $coupon      Coupon object.
			 * @param YITH_WCAF_Affiliate $affiliate   Affiliate object.
			 */
			return apply_filters( 'yith_wcaf_affiliate_coupon_message', $new_message, $coupon, $affiliate );
		}
	}
}

/**
 * Unique access to instance of YITH_WCAF_Promo class
 *
 * @return \YITH_WCAF_Promo
 * @since 1.0.0
 */
function YITH_WCAF_Promo() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return YITH_WCAF_Promo::get_instance();
}
