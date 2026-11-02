<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Logmeta extends BWFAN_Model {
	static $primary_key = 'ID';

	public static function get_rows( $only_query = false, $automation_ids = array() ) {
		global $wpdb;

		$table_name = self::_table();

		if ( $only_query ) {
			// For Fetching the meta of automations
			$automationCount        = count( $automation_ids );
			$stringPlaceholders     = array_fill( 0, $automationCount, '%s' );
			$placeholdersautomation = implode( ', ', $stringPlaceholders );
			$sql_query              = "SELECT bwfan_log_id, meta_value, meta_key FROM $table_name WHERE bwfan_log_id IN ($placeholdersautomation)";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- generated %s placeholders; ids bound via prepare
			$sql_query              = $wpdb->prepare( $sql_query, $automation_ids ); // WPCS: unprepared SQL OK
		}

		$result = $wpdb->get_results( $sql_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- generated %s placeholders; ids bound via prepare

		return $result;
	}

	public static function get_meta( $id, $key ) {
		$rows  = self::get_log_meta( $id );
		$value = '';
		if ( count( $rows ) > 0 && isset( $rows[ $key ] ) ) {
			$value = $rows[ $key ];
		}

		return $value;
	}

	public static function get_log_meta( $task_id ) {
		if ( empty( $task_id ) ) {
			return [];
		}

		global $wpdb;
		$table     = self::_table();
		$sql_query = "SELECT * FROM $table WHERE bwfan_log_id =%d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; id bound via %d
		$sql_query = $wpdb->prepare( $sql_query, $task_id ); // WPCS: unprepared SQL OK
		$result    = $wpdb->get_results( $sql_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; id bound via %d
		$meta      = [];

		if ( is_array( $result ) && count( $result ) > 0 ) {
			foreach ( $result as $meta_values ) {
				$key          = $meta_values['meta_key'];
				$meta[ $key ] = maybe_unserialize( $meta_values['meta_value'] );
			}
		}

		return $meta;
	}
}
