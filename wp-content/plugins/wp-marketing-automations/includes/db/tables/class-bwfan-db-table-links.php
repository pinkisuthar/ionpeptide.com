<?php
defined( 'ABSPATH' ) || exit;
/**
 * bwfan_links table class
 *
 */

if ( ! class_exists( 'BWFAN_DB_Table_Links' ) ) {
	#[\AllowDynamicProperties]
	class BWFAN_DB_Table_Links extends BWFAN_DB_Tables_Base {
		public $table_name = 'bwfan_links';

		/**
		 * Get table's columns
		 *
		 * @return string[]
		 */
		public function get_columns() {
			return [
				"ID",
				"url",
				"l_hash",
				"created_at",
				"clean_url",
				"oid",
				"sid",
				"tid",
				"type"
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
				'clean_url' => [ 'clean_url' ],
				'oid'       => [ 'oid' ],
				'sid'       => [ 'sid' ],
				'tid'       => [ 'tid' ],
				'type'      => [ 'type' ],
			],
			'unique_keys' => [
				'l_hash' => [ 'l_hash' ],
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
			            `ID` int(12) unsigned NOT NULL auto_increment,
					    `url` text NOT NULL,
					    `l_hash` varchar(40) NOT NULL,
					    `clean_url` varchar(190) NOT NULL,
					    `created_at` datetime NOT NULL,
					    `oid` bigint(20) UNSIGNED NOT NULL default 0 COMMENT 'Object ID',
						`sid` int(12) UNSIGNED NOT NULL default 0 COMMENT 'Step ID',
						`tid` int(12) UNSIGNED NOT NULL default 0 COMMENT 'Template ID',
						`type` tinyint(1) UNSIGNED NOT NULL default 1 COMMENT '1 - Automation | 2 - Broadcast | 3 - Note | 4 - Email | 5 - SMS | 6 - Form | 9 - Transactional',
					  PRIMARY KEY (`ID`),
					  UNIQUE KEY `l_hash` (`l_hash`),
					  KEY `clean_url` (`clean_url`),
					  KEY `oid` (`oid`),
					  KEY `sid` (`sid`),
					  KEY `tid` (`tid`),
					  KEY `type` (`type`)
					) $collate;";
		}
	}
}