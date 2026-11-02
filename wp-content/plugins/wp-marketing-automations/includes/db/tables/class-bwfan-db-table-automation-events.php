<?php

#[\AllowDynamicProperties]
class BWFAN_DB_Table_Automation_Events extends BWFAN_DB_Tables_Base {
	public $table_name = 'bwfan_automation_events';

	/**
	 * Get table's columns
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return [
			"ID",
			"creation_time",
			"execution_time",
			"args",
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
				'execution_time' => [ 'execution_time' ],
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
		  `creation_time` datetime NOT NULL,
		  `execution_time` bigint(12) unsigned NOT NULL,
		  `args` longtext,
		  PRIMARY KEY (`ID`),
		  KEY `execution_time` (`execution_time`)
		) $collate;";
	}
}
