<?php
defined( 'ABSPATH' ) || exit;
/**
 * bwfan_message table class
 *
 */

if ( ! class_exists( 'BWFAN_DB_Table_Message' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_DB_Table_Message extends BWFAN_DB_Tables_Base {
		public $table_name = 'bwfan_message';

		/**
		 * Get table's columns
		 *
		 * @return string[]
		 */
		public function get_columns() {
			return [
				"ID",
				"track_id",
				"sub",
				"body",
				"date",
				"data",
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
			'indexes'    => [
				'track_id' => [ 'track_id' ],
			],
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
		  `track_id` bigint(20) unsigned NOT NULL,
		  `sub` varchar(255) NOT NULL,
		  `body` longtext,
		  `date` datetime DEFAULT NULL,
		  `data` longtext,
		  PRIMARY KEY (`ID`),
		  KEY `track_id` (`track_id`)
		) $collate;";
		}
	}
}