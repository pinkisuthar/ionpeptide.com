<?php
/**
 * Rate rule Factory class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Rate_Rule_Factory' ) ) {
	/**
	 * Static class that offers methods to construct YITH_WCAF_Rate_Rule objects
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Rate_Rule_Factory extends YITH_WCAF_Abstract_Object_Factory {

		/**
		 * Type of objects the factory should build
		 *
		 * @var string
		 */
		protected static $object_type = 'rate_rule';

		/**
		 * Returns a list of rules matching filtering criteria
		 *
		 * @param array $args Filtering criteria (@see \YITH_WCAF_Rate_Rule_Data_Store::query).
		 *
		 * @return YITH_WCAF_Rate_Rules_Collection|string[]|bool Result set; false on failure.
		 */
		public static function get_rules( $args = array() ) {
			return self::get_objects( $args );
		}

		/**
		 * Returns a rule, given the id
		 *
		 * @param int $id Rule's ID.
		 *
		 * @return YITH_WCAF_Rate_Rule|bool Rule object, or false on failure
		 */
		public static function get_rule( $id ) {
			return self::get_object( $id );
		}

		/**
		 * Created a new rule object starting from a list of props
		 *
		 * @param array $args Array of params used to populate the rule object.
		 * @return YITH_WCAF_Rate_Rule|bool Rule object, or false on failure.
		 */
		public static function create_rule( $args = array() ) {
			return self::create_object( $args );
		}
	}
}
