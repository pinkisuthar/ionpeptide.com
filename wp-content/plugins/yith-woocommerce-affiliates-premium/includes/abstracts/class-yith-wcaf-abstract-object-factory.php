<?php
/**
 * Abstract Object Factory class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Abstracts
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Abstract_Object_Factory' ) ) {
	/**
	 * Static class that offers methods to construct YITH_WCAF_Abstract_Object objects
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Abstract_Object_Factory {

		/**
		 * Type of objects the factory should build
		 *
		 * @var string
		 */
		protected static $object_type = '';

		/**
		 * Returns class name to be used when creating object for a specific id
		 *
		 * @param int $id Id of the object (can be null when creating a new object).
		 * @return string Class name to use.
		 */
		public static function get_class_name_for_id( $id = null ) {
			$object_type = static::$object_type;
			$object_class = str_replace( '_', ' ', ucwords( $object_type ) );
			$object_class = str_replace( ' ', '_', $object_class );

			/**
			 * APPLY_FILTERS: yith_wcaf_{$object_type}_class_name
			 *
			 *  Filters the class name used when creating the object for a specific id.
			 * <code>$object_type</code> will be replaced with current object type, depending on class implementation.
			 *
			 * @param string $class Class name.
			 * @param int    $id    ID of the object.
			 */
			return apply_filters( "yith_wcaf_{$object_type}_class_name", "YITH_WCAF_{$object_class}", $id );
		}

		/**
		 * Returns a list of objects matching filtering criteria
		 *
		 * @param array $args Filtering criteria (@see related DataStore to find a list of supported filter parameters).
		 *
		 * @return YITH_WCAF_Abstract_Objects_Collection|string[]|bool Result set; false on failure.
		 */
		public static function get_objects( $args = array() ) {
			try {
				$data_store = WC_Data_Store::load( static::$object_type );

				$res = $data_store->query( $args );
			} catch ( Exception $e ) {
				return false;
			}

			return $res;
		}

		/**
		 * Returns an object, given the id
		 *
		 * @param int $id Object's ID.
		 *
		 * @return YITH_WCAF_Abstract_Object|bool Found object, or false on failure
		 */
		public static function get_object( $id ) {
			if ( ! $id ) {
				return false;
			}

			try {
				$class_name = static::get_class_name_for_id( $id );
				return new $class_name( $id );
			} catch ( Exception $e ) {
				return false;
			}
		}

		/**
		 * Created a new object starting from a list of props
		 *
		 * @param array $args Array of params used to populate the object.
		 * @return YITH_WCAF_Abstract_Object|bool Object just created, false on failure.
		 */
		public static function create_object( $args = array() ) {
			try {
				$class_name = static::get_class_name_for_id( $args['id'] ?? null );
				return new $class_name( $args );
			} catch ( Exception $e ) {
				return false;
			}
		}
	}
}
