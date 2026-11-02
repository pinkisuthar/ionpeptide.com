<?php
/**
 * Affiliates' coupons handling class - LEGACY
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes\Legacy
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Coupons_Legacy' ) ) {
	/**
	 * Legacy Coupon Handler
	 *
	 * @deprecated 2.0.0
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Coupons_Legacy {
		/**
		 * Returns single instance of the class
		 *
		 * @return \YITH_WCAF_Coupons_Legacy
		 * @since 1.0.0
		 */
		public static function get_instance() {
			_deprecated_function( __METHOD__, '2.0.0', '\YITH_WCAF_Coupons::get_instance' );

			return YITH_WCAF_Coupons::get_instance();
		}
	}
}

/**
 * Create class alias, to allow for interaction with the legacy class, with its previous name.
 *
 * @since 2.0.0
 */
class_alias( 'YITH_WCAF_Coupons_Legacy', 'YITH_WCAF_Coupon_Handler' );

/**
 * Unique access to instance of YITH_WCAF_Coupon_Handler class
 *
 * @return \YITH_WCAF_Coupons_Legacy
 * @since 1.0.0
 */
function YITH_WCAF_Coupon_Handler() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	_deprecated_function( __FUNCTION__, '2.0.0', '\YITH_WCAF_Coupons::get_instance' );

	return YITH_WCAF_Coupons::get_instance();
}
