<?php
defined( 'ABSPATH' ) || exit;
/**
 * bwfan_contact_note table class
 *
 */

if ( ! class_exists( 'BWFAN_DB_Table_Contact_Note' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_DB_Table_Contact_Note extends BWFAN_DB_Tables_Base {
		public $table_name = 'bwfan_contact_note';

		/**
		 * Get table's columns
		 *
		 * @return string[]
		 */
		public function get_columns() {
			return [
				"id",
				"cid",
				"type",
				"created_by",
				"created_date",
				"private",
				"title",
				"body",
				"modified_by",
				"modified_date",
				"date_time",
			];
		}

	/**
	 * Get primary key and indexes definition
	 *
	 * @return array
	 */
	public function get_indexes() {
		return [
			'primary_key' => 'id',
			'indexes'    => [
				'cid' => [ 'cid' ],
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
 		      `id` bigint(20) unsigned NOT NULL auto_increment,
			  `cid` bigint(20) unsigned NOT NULL,
			  `type` varchar(255) NOT NULL,
			  `created_by` bigint(20),
			  `created_date` datetime,
			  `private` tinyint(1) unsigned not null default 0,
			  `title` varchar(255),
			  `body` longtext,
			  `modified_by` bigint(20) default null,
			  `modified_date` datetime default null,
			  `date_time` datetime default null,
			  PRIMARY KEY (`id`),
			  KEY `cid` (`cid`)
		  ) $collate;";
		}
	}
}