<?php
/**
 * Rate Rules Collection
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Rate_Rules_Collection' ) ) {
	/**
	 * Behaves as an array of YITH_WCAF_Rate_Rule
	 */
	class YITH_WCAF_Rate_Rules_Collection extends YITH_WCAF_Abstract_Objects_Collection {

		/**
		 * Retrieves a specific object, given the id
		 *
		 * @param int $rule_id Id of the object to retrieve.
		 *
		 * @return YITH_WCAF_Rate_Rule Object retrieved.
		 */
		public function get_object( $rule_id ) {
			return YITH_WCAF_Rate_Rule_Factory::get_rule( $rule_id );
		}
	}
}
