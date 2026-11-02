<?php
defined( 'ABSPATH' ) || exit;
/**
 * bwfan_conversions table class
 *
 */

if ( ! class_exists( 'BWFAN_DB_Table_Conversions' ) && BWFAN_Common::is_pro_3_0() ) {

	#[\AllowDynamicProperties]
	class BWFAN_DB_Table_Conversions extends BWFAN_DB_Tables_Base {
		public $table_name = 'bwfan_conversions';

		/**
		 * Get table's columns
		 *
		 * @return string[]
		 */
		public function get_columns() {
			return [
				"ID",
				"wcid",
				"cid",
				"trackid",
				"oid",
				"otype",
				"wctotal",
				"date",
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
				'cid'     => [ 'cid' ],
				'oid'     => [ 'oid' ],
				'trackid' => [ 'trackid' ],
				'otype'   => [ 'otype' ],
				'date'    => [ 'date' ],
			],
			'unique_keys' => [],
		];
	}

	/**
	 * Get a query for create table
	 *
	 * @return string
	 */
	public function get_create_table_query() {
			global $wpdb;
			$collate = $this->get_collation();

			return "CREATE TABLE {$wpdb->prefix}$this->table_name (
 		    `ID` bigint(20) unsigned NOT NULL auto_increment,
			`wcid` bigint(20) unsigned NOT NULL,
			`cid` bigint(20) unsigned NOT NULL,
			`trackid` bigint(20) unsigned NOT NULL,
			`oid` bigint(20) unsigned NOT NULL,
			`otype` tinyint(2) unsigned not null COMMENT '1 - Automation 2 - Campaign 3 - Note 4 - Email 5 - SMS',
			`wctotal` varchar(32),
			`date` datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY (`ID`),
			KEY `cid` (`cid`),
			KEY `oid` (`oid`),
			KEY `trackid` (`trackid`),
			KEY `otype` (`otype`),
			KEY `date` (`date`)
		) $collate;";
		}
	}
}
