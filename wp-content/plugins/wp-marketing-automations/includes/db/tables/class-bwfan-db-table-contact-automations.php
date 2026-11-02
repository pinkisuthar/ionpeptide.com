<?php

#[\AllowDynamicProperties]
class BWFAN_DB_Table_Contact_Automations extends BWFAN_DB_Tables_Base {
	public $table_name = 'bwfan_contact_automations';

	/**
	 * Get table's columns
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return [
			"ID",
			"contact_id",
			"automation_id",
			"time",
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
				'contact_id'   => [ 'contact_id' ],
				'automation_id' => [ 'automation_id' ],
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
		  `contact_id` bigint(20) NOT NULL,
		  `automation_id` bigint(20) NOT NULL,
		  `time` bigint(12) NOT NULL,
		  PRIMARY KEY (`ID`),
		  KEY `contact_id` (`contact_id`),
		  KEY `automation_id` (`automation_id`)
		) $collate;";
	}
}
