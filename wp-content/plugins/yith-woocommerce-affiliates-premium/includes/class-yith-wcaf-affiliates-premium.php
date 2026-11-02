<?php
/**
 * Affiliate Handler Premium class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Affiliates_Premium' ) ) {
	/**
	 * WooCommerce Affiliate Handler Premium
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Affiliates_Premium extends YITH_WCAF_Affiliates {

		/**
		 * Constructor method
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			add_filter( 'yith_wcaf_affiliate_is_valid', array( $this, 'filter_valid_affiliates' ), 10, 3 );
		}

		/**
		 * Set affiliate as invalid when related user is excluded
		 *
		 * @param bool                $is_valid     Whether current affiliate is valid or not.
		 * @param int                 $affiliate_id Current affiliate id.
		 * @param YITH_WCAF_Affiliate $affiliate    Current affiliate.
		 *
		 * @return bool Whether current affiliate is valid.
		 */
		public function filter_valid_affiliates( $is_valid, $affiliate_id, $affiliate ) {
			$user_id = $affiliate->get_user_id();

			if ( ! $user_id ) {
				return $is_valid;
			}

			return $is_valid && ! $this->is_user_excluded_affiliate( $user_id );
		}

		/* === HELPER METHODS === */

		/**
		 * Checks whether current affiliate has been excluded from affiliation program
		 *
		 * @param int|bool $user_id Id of the user to check; false if currently logged in user should be considered.
		 *
		 * @return bool Whether user is a valid affiliate or not
		 * @since 1.2.5
		 */
		public function is_user_excluded_affiliate( $user_id = false ) {
			if ( ! $user_id ) {
				$user_id = get_current_user_id();
			}

			if ( ! $user_id ) {
				return false;
			}

			$user = get_userdata( $user_id );

			if ( ! $user || is_wp_error( $user ) ) {
				return false;
			}

			$enable_user_exclusions = get_option( 'yith_wcaf_user_exclusions_enabled', 'no' );

			if ( 'yes' !== $enable_user_exclusions ) {
				return false;
			}

			$excluded_users      = get_option( 'yith_wcaf_excluded_users', array() );
			$excluded_user_roles = get_option( 'yith_wcaf_excluded_user_roles', array() );

			if ( ! isset( $excluded_users ) || ! is_array( $excluded_users ) ) {
				$excluded_users = array();
			} else {
				$excluded_users = array_map( 'intval', $excluded_users );
			}

			if ( ! isset( $excluded_user_roles ) || ! is_array( $excluded_user_roles ) ) {
				$excluded_user_roles = array();
			}

			$is_excluded = false;

			if ( in_array( $user_id, $excluded_users, true ) ) {
				$is_excluded = true;
			} elseif ( array_intersect( $user->roles, $excluded_user_roles ) ) {
				$is_excluded = true;
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_is_user_excluded_affiliate
			 *
			 * Filters whether the user is an excluded affiliate.
			 *
			 * @param bool $is_excluded Whether the user is an excluded affiliate or not.
			 * @param int  $user_id     User id.
			 */
			return apply_filters( 'yith_wcaf_is_user_excluded_affiliate', $is_excluded, $user_id );
		}
	}
}
