<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFCRM_Group' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFCRM_Group {

		public static $default_groups = array();
		public static $_instance = null;

		public function __construct() {

		}

		public static function get_instance() {
			if ( null === self::$_instance ) {
				self::$_instance = new self;
			}

			return self::$_instance;
		}

		/**
		 * get group from the bwfcrm_group table
		 **/
		public static function get_groups( $group_ids ) {
			global $wpdb;

			$query = "select ID,name from {table_name} ";
			if ( ! empty( $group_ids ) ) {
				$group_ids   = array_map( 'absint', $group_ids );
				$placeholder = implode( ' ,', array_fill( 0, count( $group_ids ), '%d' ) );
				$query      .= $wpdb->prepare( " WHERE ID NOT IN ($placeholder)", $group_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated in code; ids absint()ed
			}

			$groups = BWFAN_Model_Field_Groups::get_results( $query );

			$default_groups = self::get_default_groups();
			if ( empty( $groups ) ) {

				return self::get_default_groups();
			}
			$group_data = array();
			foreach ( $groups as $group ) {
				$group_data[] = [
					'id'   => $group['ID'],
					'name' => $group['name']
				];
			}

			$group_data = array_merge( $group_data, $default_groups );

			return $group_data;
		}

		/**
		 * add new group
		 **/
		public static function add_group( $group_name ) {

			$data = array(
				'name'       => $group_name,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			);

			BWFAN_Model_Field_Groups::insert( $data );

			$group_id = BWFAN_Model_Field_Groups::insert_id();

			return self::get_groupby_id( $group_id );
		}

		/**
		 * function to return default custom group
		 **/
		public static function get_default_groups() {
			return apply_filters( 'bwfcrm_get_custom_groups', self::$default_groups );
		}

		/**
		 *  function to get the group details by group name
		 */

		public static function get_groupby_name( $group_name ) {
			$query      = "select * from {table_name} where name = %s";
			$group_data = BWFAN_Model_Field_Groups::get_results( $query, array( $group_name ) );

			return $group_data;
		}

		/**
		 *  function to get the group details by group id
		 */

		public static function get_groupby_id( $group_id ) {
			$query      = "select ID as id,name,created_at from {table_name} where id = %d";
			$group_data = BWFAN_Model_Field_Groups::get_results( $query, array( absint( $group_id ) ) );

			return $group_data;
		}

		/**
		 * function to get group slug
		 */

		public static function generate_group_slug( $group_name ) {
			$group_slug = strtolower( $group_name );
			$group_slug = str_replace( ' ', '_', $group_slug );

			return $group_slug;
		}
	}
}