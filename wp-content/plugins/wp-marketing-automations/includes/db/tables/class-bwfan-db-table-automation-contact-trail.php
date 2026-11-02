<?php

#[\AllowDynamicProperties]
class BWFAN_DB_Table_Automation_Contact_Trail extends BWFAN_DB_Tables_Base {
	public $table_name = 'bwfan_automation_contact_trail';

	/**
	 * Get table's columns
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return [
			"ID",
			"tid",
			"cid",
			"aid",
			"sid",
			"c_time",
			"status",
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
				'tid'    => [ 'tid' ],
				'cid'    => [ 'cid' ],
				'sid'    => [ 'sid' ],
				'status' => [ 'status' ],
				'c_time' => [ 'c_time' ],
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
 		    `ID` bigint(20) UNSIGNED NOT NULL auto_increment,
			`tid` varchar(40) NOT NULL COMMENT 'Trail ID',
			`cid` bigint(12) UNSIGNED NOT NULL COMMENT 'Contact ID',
			`aid` bigint(10) UNSIGNED NOT NULL COMMENT 'Automation ID',
			`sid` bigint(10) UNSIGNED NOT NULL COMMENT 'Step ID',
			`c_time` bigint(12) UNSIGNED NOT NULL,
			`status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 - Success | 2 - Wait | 3 - Failed | 4 - Skipped',
			`data` varchar(255) NULL,
			PRIMARY KEY (`ID`),
			KEY `tid` (`tid`(40)),
			KEY `cid` (`cid`),
			KEY `sid` (`sid`),
			KEY `status` (`status`),
			KEY `c_time` (`c_time`)
		) $collate;";
	}
}
