<?php
/**
 * Coupons Collection
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Coupons_Collection' ) ) {
	/**
	 * Behaves as an array of YITH_WCAF_Coupons
	 */
	class YITH_WCAF_Coupons_Collection extends YITH_WCAF_Abstract_Objects_Collection {

		/**
		 * Retrieves a specific object, given the id
		 *
		 * @param int $coupon_id Id of the object to retrieve.
		 *
		 * @return WC_Coupon Object retrieved.
		 */
		public function get_object( $coupon_id ) {
			return new WC_Coupon( $coupon_id );
		}
	}
}
