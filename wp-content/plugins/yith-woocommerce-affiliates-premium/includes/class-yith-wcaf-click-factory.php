<?php
/**
 * Click Factory class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Click_Factory' ) ) {
	/**
	 * Static class that offers methods to construct YITH_WCAF_Click objects
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Click_Factory extends YITH_WCAF_Abstract_Object_Factory {

		/**
		 * Type of objects the factory should build
		 *
		 * @var string
		 */
		protected static $object_type = 'click';

		/**
		 * Returns a list of click matching filtering criteria
		 *
		 * @param array $args Filtering criteria (@see \YITH_WCAF_Click_Data_Store::query).
		 *
		 * @return YITH_WCAF_Clicks_Collection|string[]|bool Result set; false on failure.
		 */
		public static function get_clicks( $args = array() ) {
			return self::get_objects( $args );
		}

		/**
		 * Returns a click, given the id
		 *
		 * @param int $id Click's ID.
		 *
		 * @return YITH_WCAF_Click|bool Click object, or false on failure
		 */
		public static function get_click( $id ) {
			return self::get_object( $id );
		}

		/**
		 * Created a new click object starting from a list of props
		 *
		 * @param array $args Array of params used to populate the click object.
		 * @return YITH_WCAF_Click|bool Click object, or false on failure.
		 */
		public static function create_click( $args = array() ) {
			return self::create_object( $args );
		}
	}
}
