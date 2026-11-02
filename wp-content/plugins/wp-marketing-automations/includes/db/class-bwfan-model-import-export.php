<?php
defined( 'ABSPATH' ) || exit;

if ( ( defined( 'BWFAN_PRO_VERSION' ) && version_compare( BWFAN_PRO_VERSION, '3.7.0', '>=' ) && ! class_exists( 'BWFAN_Model_Import_Export' )) || ! bwfan_is_autonami_pro_active() ) {

	#[\AllowDynamicProperties]
	class BWFAN_Model_Import_Export extends BWFAN_Model {
		/** Status 1: In-Progress, 2: Failed, 3: Success */

		public static function get_export_import( $type, $limit, $offset ) {
			global $wpdb;
			$table = "{$wpdb->prefix}bwfan_import_export";

			$type   = absint( $type );
			$limit  = absint( $limit );
			$offset = absint( $offset );

			$query = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE type = %d ORDER BY ID DESC LIMIT %d, %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type,
				$offset,
				$limit
			);
			$count_query = $wpdb->prepare(
				"SELECT COUNT(id) FROM {$table} WHERE type = %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type
			);
			$result      = [
				'exports'     => self::get_results( $query ),
				'total_count' => $wpdb->get_var( $count_query ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared with absint type, or hardcoded literal; prefix table
			];

			return $result;
		}

		/**
		 * Get first export id
		 */
		public static function get_first_export_id() {
			global $wpdb;
			$table = "{$wpdb->prefix}bwfan_import_export";
			$query = " SELECT MIN(`id`) FROM $table WHERE type = 2 ";

			return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared with absint type, or hardcoded literal; prefix table
		}
	}
}
