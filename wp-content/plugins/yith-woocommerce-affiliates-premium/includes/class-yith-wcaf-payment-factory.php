<?php
/**
 * Payment Factory class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Payment_Factory' ) ) {
	/**
	 * Static class that offers methods to construct YITH_WCAF_Payment objects
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Payment_Factory extends YITH_WCAF_Abstract_Object_Factory {

		/**
		 * Type of objects the factory should build
		 *
		 * @var string
		 */
		protected static $object_type = 'payment';

		/**
		 * Returns a list of payments matching filtering criteria
		 *
		 * @param array $args Filtering criteria (@see \YITH_WCAF_Payment_Data_Store::query).
		 *
		 * @return YITH_WCAF_Payments_Collection|string[]|bool Result set; false on failure.
		 */
		public static function get_payments( $args = array() ) {
			return self::get_objects( $args );
		}

		/**
		 * Returns a payment, given the id
		 *
		 * @param int $id Payment's ID.
		 *
		 * @return YITH_WCAF_Payment|bool Payment object, or false on failure
		 */
		public static function get_payment( $id ) {
			return self::get_object( $id );
		}

		/**
		 * Created a new payment object starting from a list of props
		 *
		 * @param array $args Array of params used to populate the payment object.
		 * @return YITH_WCAF_Payment|bool Payment object, or false on failure.
		 */
		public static function create_payment( $args = array() ) {
			return self::create_object( $args );
		}
	}
}
