<?php
defined( 'ABSPATH' ) || exit;
/**
 * bwfan_import_export table class
 *
 */

if ( ! class_exists( 'BWFAN_DB_Table_Lite_Import_Export' ) ) {
	#[\AllowDynamicProperties]
	class BWFAN_DB_Table_Lite_Import_Export extends BWFAN_DB_Tables_Base {
		public $table_name = 'bwfan_import_export';

		/**
		 * Get table's columns
		 *
		 * @return string[]
		 */
		public function get_columns() {
			return [
				"id",
				"offset",
				"processed",
				"count",
				"type",
				"status",
				"meta",
				"last_modified",
				"created_date",
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
 		  `id` bigint(20) unsigned NOT NULL auto_increment,
		  `offset` bigint(20) unsigned NOT NULL,
		  `processed` bigint(20) unsigned NOT NULL,
		  `count` bigint(20) unsigned NOT NULL,
		  `type` tinyint(1) unsigned not null default 1,
		  `status` tinyint(1) unsigned not null default 1,
		  `meta` longtext NOT NULL,
		  `last_modified` datetime default null,
		  `created_date` datetime,
		  PRIMARY KEY (`id`),
		  KEY `type` (`type`),
		  KEY `status` (`status`)
		  ) $collate;";
		}
	}

}

