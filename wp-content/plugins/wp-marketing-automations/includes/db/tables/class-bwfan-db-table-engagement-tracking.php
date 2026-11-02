<?php
defined( 'ABSPATH' ) || exit;
/**
 * bwfan_engagement_tracking table class
 *
 */

if ( ! class_exists( 'BWFAN_DB_Table_Engagement_Tracking' ) && BWFAN_Common::is_pro_3_0() ) {

	#[\AllowDynamicProperties]
	class BWFAN_DB_Table_Engagement_Tracking extends BWFAN_DB_Tables_Base {
		public $table_name = 'bwfan_engagement_tracking';

		/**
		 * Get table's columns
		 *
		 * @return string[]
		 */
		public function get_columns() {
			return [
				"ID",
				"cid",
				"hash_code",
				"created_at",
				"updated_at",
				"mode",
				"send_to",
				"type",
				"open",
				"click",
				"oid",
				"sid",
				"author_id",
				"tid",
				"o_interaction",
				"f_open",
				"c_interaction",
				"f_click",
				"c_status",
				"day",
				"hour",
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
				'cid'       => [ 'cid' ],
				'created_at' => [ 'created_at' ],
				'mode'      => [ 'mode' ],
				'type'      => [ 'type' ],
				'oid'       => [ 'oid' ],
				'sid'       => [ 'sid' ],
				'tid'       => [ 'tid' ],
				'f_open'    => [ 'f_open' ],
				'f_click'   => [ 'f_click' ],
				'day'       => [ 'day' ],
				'hour'      => [ 'hour' ],
				'c_status'  => [ 'c_status' ],
			],
			'unique_keys' => [
				'hash_code' => [ 'hash_code' ],
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
 		    `ID` bigint(20) unsigned NOT NULL auto_increment,
			`cid` bigint(20) unsigned NOT NULL default 0,
			`hash_code` varchar(60) NOT NULL,
			`created_at` datetime NOT NULL default '0000-00-00 00:00:00',
			`updated_at` datetime default NULL,
			`mode` tinyint(1) unsigned not null default 1 COMMENT '1 - Email 2 - SMS',
			`send_to` varchar(255) NOT NULL,
			`type` tinyint(2) unsigned not null default 1 COMMENT '1 - Automation 2 - Broadcast 3 - Note 4 - Email 5 - SMS',
			`open` smallint(3) unsigned NOT NULL default 0,
			`click` smallint(3) unsigned NOT NULL default 0,
			`oid` bigint(20) unsigned NOT NULL,
			`sid` bigint(20) unsigned NOT NULL default 0 COMMENT 'Step ID',
			`author_id` bigint(10) unsigned NOT NULL default 1,
			`tid` int(20) unsigned NOT NULL default 0 COMMENT 'Template ID',
			`o_interaction` varchar(255),
			`f_open` datetime default NULL,
			`c_interaction` varchar(255),
			`f_click` datetime default NULL,
			`c_status` tinyint(2) unsigned default 1 COMMENT '1 - Draft 2 - Send 3 - Error 4 - Bounced',
			`day` tinyint(1) unsigned default NUll,
			`hour` tinyint(2) unsigned default NUll,
			PRIMARY KEY (`ID`),			
			KEY `cid` (`cid`),
			KEY `created_at` (`created_at`),
			KEY `mode` (`mode`),
			KEY `type` (`type`),
			KEY `oid` (`oid`),
			KEY `sid` (`sid`),
			KEY `tid` (`tid`),
			KEY `f_open` (`f_open`),
			KEY `f_click` (`f_click`),
			KEY `day` (`day`),
			KEY `hour` (`hour`),
			KEY `c_status` (`c_status`),
			UNIQUE KEY `hash_code` (`hash_code`)
		) $collate;";
		}
	}
}