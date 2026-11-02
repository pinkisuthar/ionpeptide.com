<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/*if ( ! class_exists( 'WFCO_Model' ) ) {
	require_once __DIR__ . '/class-wfco-model.php';
}*/
if ( ! class_exists( 'WFCO_Model_Report_views' ) ) {
	#[AllowDynamicProperties]
	class WFCO_Model_Report_views extends WFCO_Model {
		static $primary_key = 'ID';

		public static function count_rows( $dependency = null ) {
			global $wpdb;
			$table_name = self::_table();
			$sql        = 'SELECT COUNT(*) FROM ' . $table_name;

			if ( 'all' !== filter_input( INPUT_GET, 'status', FILTER_UNSAFE_RAW ) ) {
				$status = filter_input( INPUT_GET, 'status', FILTER_UNSAFE_RAW );
				$status = ( 'active' === $status ) ? 1 : 2;
				$sql    = $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE status = %d", $status ); //phpcs:ignore WordPress.DB.PreparedSQL
			}

			return $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table from class name; GET status mapped to 1 or 2 and bound
		}

		private static function _table() {
			global $wpdb;
			$table_name = strtolower( get_called_class() );
			$table_name = str_replace( 'wfco_model_', 'wfco_', $table_name );

			return $wpdb->prefix . $table_name;
		}

		/**
		 * @param string $date Date(Y-m-d)
		 * @param string $object_id post_id or unique_id
		 * @param int $type 1=abandoned,2=upstroke,3=aero,4=bump
		 */

		public static function update_data( $date = '', $object_id = '', $type = 1 ) {
			global $wpdb;
			$has_object_id = ( '' !== $object_id );
			$object_id     = absint( $object_id );
			$type          = absint( $type );
			$insert        = [];

			if ( $date !== '' ) {
				$date = sanitize_text_field( $date );
			} else {
				$date = date( 'Y-m-d' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			}
			$insert['date'] = $date;

			if ( $has_object_id ) {
				$insert['object_id'] = $object_id;
			}
			$insert['type'] = $type;

			$table = self::_table();

			if ( $has_object_id ) {
				$result = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE `date` = %s AND `object_id` = %d AND `type` = %d", $date, $object_id, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- `$table` is a trusted internal identifier from self::_table(); date/object_id/type bound via prepare().; table from class name; values bound via prepare
			} else {
				$result = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE `date` = %s AND `type` = %d", $date, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- `$table` is a trusted internal identifier from self::_table(); date/type bound via prepare().; table from class name; values bound via prepare
			}

			if ( ! empty( $result ) ) {
				$primary_id = absint( $result[0]['id'] );
				$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET no_of_sessions = no_of_sessions + 1 WHERE id = %d", $primary_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table from class name; id absint and bound via %d
			} else {
				$wpdb->insert( $table, $insert ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $wpdb->insert() parameterizes internally; safe.
			}
		}

		public static function get_data( $date = '', $object_id = '', $type = 1, $interval = false ) {
			global $wpdb;
			$has_object_id = ( '' !== $object_id );
			$object_id     = absint( $object_id );
			$type          = absint( $type );
			$table         = self::_table();

			if ( $date !== '' ) {
				if ( true === $interval ) {
					// Validate $date is a safe date-range fragment before interpolation.
					if ( ! preg_match( '/^`date`\s+BETWEEN\s+\'[\d\s:-]+\'\s+AND\s+\'[\d\s:-]+\'$/i', $date ) ) {
						return [];
					}
					if ( $has_object_id ) {
						return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE {$date} AND `object_id` = %d AND `type` = %d", $object_id, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- `$table` from self::_table() (trusted); $date regex-validated above; ids bound via %d.; table from class; date fragment regex-whitelisted; ids bound
					}

					return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE {$date} AND `type` = %d", $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- `$table` from self::_table() (trusted); $date regex-validated above; type bound via %d.; table from class; date fragment regex-whitelisted; type bound
				}

				$date = sanitize_text_field( $date );
				if ( $has_object_id ) {
					return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE `date` = %s AND `object_id` = %d AND `type` = %d", $date, $object_id, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- `$table` from self::_table() (trusted); values bound via prepare().; table from class name; values bound via prepare
				}

				return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE `date` = %s AND `type` = %d", $date, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- `$table` from self::_table() (trusted); values bound via prepare().; table from class name; values bound via prepare
			}

			$date = date( 'Y-m-d' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			if ( $has_object_id ) {
				return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE `date` = %s AND `object_id` = %d AND `type` = %d", $date, $object_id, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- `$table` from self::_table() (trusted); values bound via prepare().; table from class name; values bound via prepare
			}

			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE `date` = %s AND `type` = %d", $date, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- `$table` from self::_table() (trusted); values bound via prepare().; table from class name; values bound via prepare
		}


	}
}