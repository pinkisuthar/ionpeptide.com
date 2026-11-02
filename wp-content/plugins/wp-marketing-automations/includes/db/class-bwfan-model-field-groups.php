<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFAN_Model_Field_Groups' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_Model_Field_Groups extends BWFAN_Model {
		static $primary_key = 'ID';

		/**
		 * function to return group using term slug
		 **/
		static function get_group_by_slug( $slug ) {
			global $wpdb;
			$query     = $wpdb->prepare( "SELECT * from {table_name} where slug = %s", $slug );
			$groupdata = self::get_results( $query );
			$groupdata = is_array( $groupdata ) && ! empty( $groupdata ) ? $groupdata[0] : array();

			return $groupdata;
		}
	}
}