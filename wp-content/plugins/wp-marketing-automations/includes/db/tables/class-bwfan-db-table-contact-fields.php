<?php
defined( 'ABSPATH' ) || exit;
/**
 * bwf_contact_fields table class
 *
 */

if ( ! class_exists( 'BWFAN_DB_Table_Contact_Fields' ) && BWFAN_Common::is_pro_3_0() ) {

	#[\AllowDynamicProperties]
	class BWFAN_DB_Table_Contact_Fields extends BWFAN_DB_Tables_Base {
		public $table_name = 'bwf_contact_fields';

		/**
		 * Get table's columns
		 *
		 * @return string[]
		 */
		public function get_columns() {
			return [
				"ID",
				"cid",
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
			'unique_keys' => [
				'cid' => [ 'cid' ],
			],
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
		   `cid` bigint(20) unsigned NOT NULL,
		  PRIMARY KEY (`ID`),
		  UNIQUE KEY `cid` (`cid`)
		) $collate;";
		}
	}
}