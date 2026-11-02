<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Taskmeta extends BWFAN_Model {
	static $primary_key = 'ID';

	public static function get_meta( $id, $key ) {
		$rows  = self::get_task_meta( $id );
		$value = '';
		if ( count( $rows ) > 0 && isset( $rows[ $key ] ) ) {
			$value = $rows[ $key ];
		}

		return $value;
	}

	public static function get_task_meta( $task_id ) {
		if ( empty( $task_id ) ) {
			return [];
		}

		global $wpdb;
		$table     = self::_table();
		$sql_query = "SELECT * FROM $table WHERE bwfan_task_id =%d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		$sql_query = $wpdb->prepare( $sql_query, $task_id ); // WPCS: unprepared SQL OK
		$result    = $wpdb->get_results( $sql_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
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
