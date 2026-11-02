<?php
defined( 'ABSPATH' ) || exit;
/**
 * bwfan_link_metrics table class
 *
 */

if ( ! class_exists( 'BWFAN_DB_Table_Link_Metrics' ) ) {
	#[\AllowDynamicProperties]
	class BWFAN_DB_Table_Link_Metrics extends BWFAN_DB_Tables_Base {
		public $table_name = 'bwfan_link_metrics';

		/**
		 * Get table's columns
		 *
		 * @return string[]
		 */
		public function get_columns() {
			return [
				"ID",
				"link_id",
				"contact_id",
				"ip_address",
				"count",
				"created_at",
				"updated_at"
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
				'link_id'    => [ 'link_id' ],
				'contact_id' => [ 'contact_id' ],
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
					    `link_id` int(12) unsigned NOT NULL default 0,
					    `contact_id` bigint(20) unsigned NOT NULL default 0,
					    `ip_address` varchar(45) NULL,
					    `count` int(7) unsigned NOT NULL,
					    `created_at` datetime NOT NULL,
					  PRIMARY KEY (`ID`),
					  KEY `link_id` (`link_id`),
					  KEY `contact_id` (`contact_id`)
					) $collate;";
		}
	}
}
