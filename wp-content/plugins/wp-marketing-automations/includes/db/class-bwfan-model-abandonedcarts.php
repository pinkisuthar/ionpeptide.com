<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Abandonedcarts extends BWFAN_Model {
	public static $primary_key = 'ID';

	public static function get_abandoned_data( $where = '', $offset = '', $per_page = '', $order_by = 'ID', $output = OBJECT ) {
		global $wpdb;

		$limit_string = '';
		if ( '' !== $offset ) {
			$limit_string = "LIMIT %d";
			$limit_string = $wpdb->prepare( $limit_string, $offset ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		}
		if ( '' !== $per_page && '' !== $limit_string ) {
			$limit_string = "LIMIT %d, %d";
			$limit_string = $wpdb->prepare( $limit_string, $offset, $per_page ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( ! empty( $order_by ) ) {
			$allowed_order = array( 'ID', 'last_modified', 'created_time', 'status', 'total', 'email' );
			$order_by      = in_array( $order_by, $allowed_order, true ) ? $order_by : 'last_modified';
			$order_by      = ' ORDER BY `' . str_replace( '`', '``', $order_by ) . '` DESC';
		}

		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bwfan_abandonedcarts {$where} {$order_by} {$limit_string}", $output ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- callers pass WHERE built with $wpdb->prepare(); order_by whitelisted; limit prepared
	}

	public static function delete_abandoned_cart_row( $data ) {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'bwfan_abandonedcarts';

		// Whitelist allowed column names
		$allowed_columns = array( 'ID', 'email', 'user_id', 'checkout_page_id' );
		$where_clauses   = array();
		$where_values    = array();

		foreach ( $data as $key => $value ) {
			// Only allow whitelisted columns
			if ( ! in_array( $key, $allowed_columns, true ) || empty( $value ) ) {
				continue;
			}

			if ( 'string' === gettype( $value ) ) {
				$where_clauses[] = "`{$key}` = %s";
				$where_values[]  = $value;
			} else {
				$where_clauses[] = "`{$key}` = %d";
				$where_values[]  = $value;
			}
		}

		if ( empty( $where_clauses ) ) {
			return;
		}

		$where = implode( ' AND ', $where_clauses );
		$query = $wpdb->prepare( "DELETE FROM {$table_name} WHERE {$where}", ...$where_values ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- columns whitelisted; values bound via prepare() with spread

		return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- columns whitelisted; values bound via prepare() with spread
	}

	/**
	 * Check if any carts available for execution.
	 *
	 * @param $abandoned_time
	 *
	 * @return bool
	 */
	public static function maybe_run( $abandoned_time = 0 ) {
		global $wpdb;
		$table          = self::_table();
		$abandoned_time = intval( $abandoned_time );
		$query          = "SELECT `ID` FROM {$table} WHERE `status` IN (0, 4)";
		if ( $abandoned_time > 0 ) {
			$query .= $wpdb->prepare( " AND TIMESTAMPDIFF(MINUTE,last_modified,UTC_TIMESTAMP) >= %d", $abandoned_time );
		}
		$count = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; time intval()ed and prepared

		return ! empty( $count );
	}


	/**
	 * Get duplicate cart in last 12 hrs
	 *
	 * @return array|object|stdClass[]|null
	 */
	public static function get_duplicate_entry() {
		global $wpdb;
		$start_time = gmdate( 'Y-m-d H:i:s', strtotime( '-12 hours' ) );

		$query = "SELECT GROUP_CONCAT(`ID`) AS `pkey` FROM `{$wpdb->prefix}bwfan_abandonedcarts` WHERE `created_time` > %s AND (`status` = 0 OR `status` = 3) GROUP BY `email` HAVING COUNT(`email`) > 1";
		$query = $wpdb->prepare( $query, $start_time ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above

		$result = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		if ( empty( $result ) ) {
			return [];
		}
		$result    = array_column( $result, 'pkey' );
		$final_ids = [];
		foreach ( $result as $item ) {
			$values = array_map( 'trim', explode( ',', $item ) );
			$values = array_map( 'intval', $values );
			array_pop( $values );
			$final_ids = array_merge( $final_ids, $values );
		};

		return $final_ids;
	}
}
