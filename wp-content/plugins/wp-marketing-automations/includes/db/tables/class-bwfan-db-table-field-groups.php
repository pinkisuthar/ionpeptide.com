<?php
defined( 'ABSPATH' ) || exit;
/**
 * bwfan_field_groups table class
 *
 */

if ( ! class_exists( 'BWFAN_DB_Table_Field_Groups' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_DB_Table_Field_Groups extends BWFAN_DB_Tables_Base {
		public $table_name = 'bwfan_field_groups';

		/**
		 * Get table's columns
		 *
		 * @return string[]
		 */
		public function get_columns() {
			return [
				"ID",
				"name",
				"created_at",
			];
		}

	/**
	 * Get primary key and indexes definition
	 *
	 * @return array
	 */
	public function get_indexes() {
		return [
			'primary_key' => 'ID',
			'indexes'    => [],
			'unique_keys' => [],
		];
	}

	/**
	 * Get query for create table
	 *
	 * @return string
	 */
	public function get_create_table_query() {
			global $wpdb;
			$collate = $this->get_collation();

			return "CREATE TABLE {$wpdb->prefix}$this->table_name (
 		    `ID` bigint(20) unsigned NOT NULL auto_increment,
			`name` varchar(255) NOT NULL,
			`created_at` datetime,
			PRIMARY KEY (`ID`)
		  ) $collate;";
		}
	}
}