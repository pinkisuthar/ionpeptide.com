<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Export_Import extends BWFAN_Model {
	/**
	 * Status
	 * 0:Pending/Draft
	 * 1: In-Progress
	 * 2: Failed
	 * 3: Success
	 */

	public static $table = 'bwfan_import_export';

	/**
	 * Get first export id
	 */
	public static function get_first_export_id() {
		global $wpdb;

		$query = $wpdb->prepare( "SELECT MIN(`id`) FROM {table_name} WHERE type = %d", 2 );

		return self::get_var( $query );
	}

	/**
	 * Get list fron table
	 *
	 * @param int $offset offset
	 * @param int $limit limit
	 * @param int $type 1:import, 2:export
	 * @param array|int $exclude_status exclude status
	 *
	 * @return array
	 */
	public static function get_lists( $offset, $limit, $type = 1, $exclude_status = [] ) {
		global $wpdb;
		$status_query = '';
		if ( ! empty( $exclude_status ) ) {
			if ( is_array( $exclude_status ) ) {
				// Sanitize array values to integers
				$exclude_status = array_map( 'absint', $exclude_status );
				if ( ! empty( $exclude_status ) ) {
					// Create placeholders for each status value
					$placeholders = implode( ',', array_fill( 0, count( $exclude_status ), '%d' ) );
					// Use spread operator to pass array values as individual arguments
					$status_query = $wpdb->prepare( " AND status NOT IN ({$placeholders}) ", ...$exclude_status ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders via array_fill; statuses absint()ed
				}
			} else if ( is_numeric( $exclude_status ) ) {
				$status_query = $wpdb->prepare( " AND status != %d ", absint( $exclude_status ) );
			}
		}

		$query = $wpdb->prepare( "SELECT * FROM {table_name} WHERE type = %d $status_query ORDER BY id DESC LIMIT %d OFFSET %d", $type, $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $status_query built with $wpdb->prepare() above

		return self::get_results( $query );
	}

	/**
	 * Get total count
	 *
	 * @param int $type import/export
	 *
	 * @return int
	 */
	public static function total_count( $type = 1 ) {
		global $wpdb;

		$query = $wpdb->prepare( "SELECT COUNT(*) FROM {table_name} WHERE type = %d", $type );

		return intval(self::get_var( $query ));
	}
}
