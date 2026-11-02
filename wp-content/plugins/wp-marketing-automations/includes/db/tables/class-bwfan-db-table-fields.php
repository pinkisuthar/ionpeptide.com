<?php
defined( 'ABSPATH' ) || exit;
/**
 * bwfan_fields table class
 *
 */

if ( ! class_exists( 'BWFAN_DB_Table_Fields' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_DB_Table_Fields extends BWFAN_DB_Tables_Base {
		public $table_name = 'bwfan_fields';

		/**
		 * Get table's columns
		 *
		 * @return string[]
		 */
		public function get_columns() {
			return [
				"ID",
				"name",
				"slug",
				"type",
				"gid",
				"meta",
				"mode",
				"vmode",
				"search",
				"view",
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
			'indexes'    => [
				'slug'  => [ 'slug' ],
				'gid'   => [ 'gid' ],
				'mode'  => [ 'mode' ],
				'vmode' => [ 'vmode' ],
				'search' => [ 'search' ],
				'view'  => [ 'view' ],
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
			`name` varchar(255) NOT NULL,
			`slug` varchar(255) NOT NULL,
			`type` tinyint(2) unsigned NOT NULL,
			`gid` bigint(20) unsigned NOT NULL,
			`meta` text NOT NULL,
			`mode` tinyint(2) unsigned NOT NULL default 1 COMMENT '1 - Editable 2 - Non-editable',
			`vmode` tinyint(2) unsigned NOT NULL default 1 COMMENT '1 - Editable 2 - Non-editable',
			`search` tinyint(1) unsigned NOT NULL default 2 COMMENT '1 - Searchable 2 - Non-searchable',
			`view` tinyint(1) unsigned NOT NULL default 1 COMMENT '1 - Viwable 2 - Non-Viwable',
			`created_at` datetime,
			PRIMARY KEY (`ID`),
			KEY `slug` (`slug`($this->max_index_length)),
			KEY `gid` (`gid`),
			KEY `mode` (`mode`),
			KEY `vmode` (`vmode`),
			KEY `search` (`search`),
			KEY `view` (`view`)
			) $collate;";
		}
	}


}