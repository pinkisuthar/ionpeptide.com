<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Automations extends BWFAN_Model {
	static $primary_key = 'ID';

	public static function count_rows( $dependency = null ) {
		global $wpdb;
		$table_name = self::_table();
		$sql        = 'SELECT COUNT(*) FROM ' . $table_name;

		if ( isset( $_GET['status'] ) && 'all' !== sanitize_text_field( wp_unslash( $_GET['status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			$status = sanitize_text_field( wp_unslash( $_GET['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			$status = ( 'active' === $status ) ? 1 : 2;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix table; status mapped to int and prepared
			$sql    = $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE status = %d", $status ); // WPCS: unprepared SQL OK
		}

		return $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix table; status mapped to int and prepared
	}

	/**
	 * Return Automation detail with its meta details
	 *
	 * @param $automation_id
	 *
	 * @return array|object|void|null
	 */
	public static function get_automation_with_data( $automation_id ) {
		$data = self::get( $automation_id );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return [];
		}

		$data['meta'] = BWFAN_Model_Automationmeta::get_automation_meta( $automation_id );

		return $data;
	}

	/**
	 * Get first automation id
	 */
	public static function get_first_automation_id() {
		global $wpdb;
		$query = "SELECT MIN(id) FROM " . self::_table();

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded query; prefix table
	}

	/**
	 * Get automation name
	 */
	public static function get_event_name( $automation_id ) {
		global $wpdb;
		$table_name = self::_table();
		$query      = $wpdb->prepare( "SELECT event FROM {$table_name} WHERE ID = %d", $automation_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- query built with $wpdb->prepare(); prefix table
		$result     = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); prefix table

		return isset( $result['event'] ) ? $result['event'] : '';
	}

	/**
	 * Get automation run count for a contact
	 *
	 * @param $automation_id
	 * @param $contact_id
	 *
	 * @return int
	 */
	public static function get_contact_automation_run_count( $automation_id = '', $contact_id = '' ) {
		if ( empty( $automation_id ) || empty( $contact_id ) ) {
			return 0;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bwfan_contact_automations';

		$query         = $wpdb->prepare( "SELECT count(*) as count FROM {$table} WHERE `contact_id` = %d AND `automation_id` = %d", $contact_id, $automation_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix table; ids bound via prepare() %d
		$running_count = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix table; ids bound via prepare() %d

		return absint( $running_count );
	}

	/**
	 * Get top 5 automations
	 *
	 * @return array|object|stdClass[]|null
	 */
	public static function get_top_automations() {
		global $wpdb;

		$automation_table = $wpdb->prefix . 'bwfan_automations';
		if ( ! bwfan_is_woocommerce_active() ) {
			$base_query = "SELECT COALESCE(a.`ID`,'') AS `aid`, COALESCE(a.`title`,'') AS `name`, a.`v`, a.event , SUM(IF(open>0, 1, 0)) AS `open_count` FROM {$automation_table} AS a LEFT JOIN {$wpdb->prefix}bwfan_engagement_tracking AS et ON et.oid = a.ID WHERE et.type = 1 GROUP BY et.`oid` ORDER BY `open_count` DESC LIMIT 0,5";
		} else {
			$conversion_table = $wpdb->prefix . 'bwfan_conversions';
			$base_query       = "SELECT COALESCE(a.`ID`,'') AS `aid`, COALESCE(a.`title`,'') AS `name`, a.`v`, a.event, SUM(c.`wctotal`) AS `total_revenue` FROM $automation_table AS a LEFT JOIN $conversion_table AS c ON c.`oid` = a.`ID` WHERE c.`otype` = 1 GROUP BY a.`ID` ORDER BY `total_revenue` DESC LIMIT 0,5";
		}

		return $wpdb->get_results( $base_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded query; prefix tables
	}

	/**
	 * @param string $where
	 *
	 * @return array|object|null
	 */
	public static function get_last_abandoned_cart( $where = '' ) {
		global $wpdb;
		$results = $wpdb->get_results( "SELECT `last_modified`, `items`, `total` FROM {$wpdb->prefix}bwfan_abandonedcarts {$where} ORDER BY `last_modified` DESC LIMIT 0,1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- callers pass WHERE built with $wpdb->prepare()

		return $results;
	}

	public static function get_tasks_for_contact( $contact_id ) {
		global $wpdb;
		$scheduled_tasks           = array();
		$contact_id                = absint( $contact_id );
		$get_contact_automation_id = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT automation_id from {$wpdb->prefix}bwfan_contact_automations where contact_id = %d", $contact_id ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $get_contact_automation_id ) ) {
			return $scheduled_tasks;
		}

		$stringPlaceholders     = array_fill( 0, count( $get_contact_automation_id ), '%s' );
		$placeholdersautomation = implode( ', ', $stringPlaceholders );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders generated; ids bound via prepare(); prefix tables
		$scheduled_tasks_query = $wpdb->prepare( "
								SELECT t.ID as id, t.integration_slug as slug, t.integration_action as action, t.automation_id as a_id, t.status as status, t.e_date as date
								FROM {$wpdb->prefix}bwfan_tasks as t
								LEFT JOIN {$wpdb->prefix}bwfan_taskmeta as m
								ON t.ID = m.bwfan_task_id
								WHERE t.automation_id IN ($placeholdersautomation)
								ORDER BY t.e_date DESC
								", $get_contact_automation_id ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated; ids bound via prepare(); prefix tables
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$scheduled_tasks       = $wpdb->get_results( $scheduled_tasks_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; ids bound via prepare(); prefix tables

		return $scheduled_tasks;
	}

	public static function get_logs_for_contact( $contact_id ) {
		$contact_logs = array();

		global $wpdb;
		$contact_id                = absint( $contact_id );
		$get_contact_automation_id = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT automation_id from {$wpdb->prefix}bwfan_contact_automations where contact_id = %d", $contact_id ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( empty( $get_contact_automation_id ) ) {
			return $contact_logs;
		}

		$stringPlaceholders     = array_fill( 0, count( $get_contact_automation_id ), '%s' );
		$placeholdersautomation = implode( ', ', $stringPlaceholders );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders generated; ids bound via prepare(); prefix tables
		$contact_logs_query = $wpdb->prepare( "
								SELECT l.ID as id, l.integration_slug as slug, l.integration_action as action, l.automation_id as a_id, l.status as status, l.e_date as date
								FROM {$wpdb->prefix}bwfan_logs as l
								LEFT JOIN {$wpdb->prefix}bwfan_logmeta as m
								ON l.ID = m.bwfan_log_id
								WHERE l.automation_id IN ($placeholdersautomation)
								ORDER BY l.e_date DESC
								", $get_contact_automation_id ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated; ids bound via prepare(); prefix tables
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$contact_logs       = $wpdb->get_results( $contact_logs_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; ids bound via prepare(); prefix tables

		return $contact_logs;
	}

	/**
	 * Get data with categories id.
	 *
	 * @param array|int $category_ids Category IDs to filter by
	 *
	 * @return array|object|stdClass[]|null
	 */
	public static function get_data_with_categories( $category_ids ) {
		if ( ! is_array( $category_ids ) ) {
			$category_ids = [ $category_ids ];
		}

		// Sanitize all category IDs to integers
		$category_ids = array_map( 'absint', $category_ids );
		$category_ids = array_filter( $category_ids ); // Remove any invalid values

		if ( empty( $category_ids ) ) {
			return [];
		}

		global $wpdb;
		$conditions = [];
		foreach ( $category_ids as $category_id ) {
			// absint() ensures numeric value, so no LIKE wildcards to escape
			$pattern      = '%"' . absint( $category_id ) . '"%';
			$conditions[] = $wpdb->prepare( "category LIKE %s", $pattern );
		}

		$sql = "SELECT id AS ID, category
           FROM {table_name}
           WHERE " . implode( ' OR ', $conditions );

		return self::get_results( $sql );
	}

}
