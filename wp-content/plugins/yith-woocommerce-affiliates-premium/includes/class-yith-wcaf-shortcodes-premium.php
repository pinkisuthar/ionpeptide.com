<?php
/**
 * Shortcode Premium class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Classes\Affiliates
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Shortcodes_Premium' ) ) {
	/**
	 * Affiliate Shortcode Premium
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Shortcodes_Premium extends YITH_WCAF_Shortcodes {

		/**
		 * Registers shortcodes available on premium version only.
		 *
		 * @param string $context Context of the operation.
		 * @return array Filtered array of shortcodes.
		 */
		public static function get_shortcodes( $context = 'view' ) {
			if ( empty( self::$shortcodes ) ) {
				self::$shortcodes = array_merge(
					parent::get_shortcodes( 'edit' ),
					array(
						'set_referrer',
						'show_coupons',
						'current_affiliate',
					)
				);
			}

			if ( 'view' === $context ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_shortcodes
				 *
				 * Filters the available shortcodes.
				 *
				 * @param array $shortcodes Available shortcodes.
				 */
				return apply_filters( 'yith_wcaf_shortcodes', self::$shortcodes );
			}

			return self::$shortcodes;
		}
	}
}
