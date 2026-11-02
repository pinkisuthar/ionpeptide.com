<?php

#[\AllowDynamicProperties]
class BWFAN_DB_Table_AbandonedCarts extends BWFAN_DB_Tables_Base {
	public $table_name = 'bwfan_abandonedcarts';

	/**
	 * Get table's columns
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return [
			"ID",
			"email",
			"status",
			"user_id",
			"last_modified",
			"created_time",
			"items",
			"coupons",
			"fees",
			"shipping_tax_total",
			"shipping_total",
			"total",
			"total_base",
			"token",
			"currency",
			"cookie_key",
			"checkout_data",
			"order_id",
			"checkout_page_id",
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
				'status'        => [ 'status' ],
				'user_id'       => [ 'user_id' ],
				'email'         => [ 'email' ],
				'last_modified' => [ 'last_modified' ],
				'token'         => [ 'token' ],
				'cookie_key'    => [ 'cookie_key' ],
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
		  `email` varchar(100) NOT NULL,
		  `status` int(1) NOT NULL default 0,
		  `user_id` bigint(20) NOT NULL default 0,
		  `last_modified` datetime NOT NULL,
		  `created_time` datetime NOT NULL,
		  `items` longtext,
		  `coupons` longtext,
		  `fees` longtext,
		  `shipping_tax_total` varchar(32),
		  `shipping_total` varchar(32),
		  `total` varchar(32),
		  `total_base` varchar(32),
		  `token` varchar(32) NOT NULL,
		  `currency` varchar(8) NOT NULL,
		  `cookie_key` varchar(32) NOT NULL,
		  `checkout_data` longtext,
		  `order_id` bigint(20) NOT NULL,
		  `checkout_page_id` bigint(20) NOT NULL,
		  PRIMARY KEY (`ID`),
		  KEY `status` (`status`),
		  KEY `user_id` (`user_id`),
		  KEY `email` (`email`),
		  KEY `last_modified` (`last_modified`),
		  KEY `token` (`token`),
		  KEY `cookie_key` (`cookie_key`)
		) $collate;";
	}
}
