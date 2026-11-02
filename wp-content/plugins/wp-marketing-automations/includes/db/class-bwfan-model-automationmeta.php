<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Automationmeta extends BWFAN_Model {
	static $primary_key = 'ID';

	public static function get_meta( $id, $key ) {
		$rows  = self::get_automation_meta( $id );
		$value = false;
		if ( count( $rows ) > 0 && isset( $rows[ $key ] ) ) {
			$value = $rows[ $key ];
		}

		return $value;
	}

	public static function get_automation_meta( $automation_id, $cache = true ) {
		if ( empty( $automation_id ) ) {
			return [];
		}
		$meta                 = [];
		$WooFunnels_Cache_obj = WooFunnels_Cache::get_instance();
		if ( $cache ) {
			$meta = $WooFunnels_Cache_obj->get_cache( 'bwfan_automations_meta_' . $automation_id, 'autonami' );
		}

		if ( empty( $meta ) ) {
			global $wpdb;
			$table     = self::_table();
			$sql_query = "SELECT bwfan_automation_id, meta_key, meta_value FROM {$table} WHERE bwfan_automation_id =%d";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
			$sql_query = $wpdb->prepare( $sql_query, $automation_id ); // WPCS: unprepared SQL OK
			$result    = $wpdb->get_results( $sql_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
			$meta      = [];

			if ( is_array( $result ) && count( $result ) > 0 ) {
				foreach ( $result as $meta_values ) {
					$key          = $meta_values['meta_key'];
					$meta[ $key ] = maybe_unserialize( $meta_values['meta_value'] );
				}
			}
			$WooFunnels_Cache_obj->set_cache( 'bwfan_automations_meta_' . $automation_id, $meta, 'autonami' );
		}

		return $meta;
	}

	public static function update_automation_meta_values( $automation_id, $data ) {
		if ( empty( $automation_id ) || empty( $data ) ) {
			return false;
		}
		global $wpdb;
		$table = self::_table();
		foreach ( $data as $key => $value ) {
			//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, [
				'meta_value' => $value // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
			], [
				'bwfan_automation_id' => intval( $automation_id ),
				'meta_key'            => $key // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
			] );
		}

		return true;
	}

	public static function insert_automation_meta_data( $automation_id, $data ) {
		if ( empty( $automation_id ) || empty( $data ) ) {
			return false;
		}
		global $wpdb;
		$table = self::_table();
		foreach ( $data as $key => $value ) {
			//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, [
				'bwfan_automation_id' => intval( $automation_id ),
				'meta_key'            => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
				'meta_value'          => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
			] );
		}

		return true;
	}

	public static function get_automations_meta( $aids, $meta_key = '' ) {
		if ( empty( $aids ) || ! is_array( $aids ) ) {
			return [];
		}
		global $wpdb;
		$table       = self::_table();
		$aids        = array_map( 'absint', $aids );
		$placeholder = implode( ', ', array_fill( 0, count( $aids ), '%d' ) );
		$sql_query   = $wpdb->prepare( "SELECT bwfan_automation_id, meta_key, meta_value FROM {$table} WHERE bwfan_automation_id IN ($placeholder)", $aids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated in code; ids absint()ed

		if ( ! empty( $meta_key ) ) {
			$sql_query .= " AND meta_key= 'event_meta'";
		}

		$result = $wpdb->get_results( $sql_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated in code; ids absint()ed
		$meta   = [];

		if ( is_array( $result ) && count( $result ) > 0 ) {
			foreach ( $result as $meta_values ) {
				$key                                                 = $meta_values['meta_key'];
				$meta[ $meta_values['bwfan_automation_id'] ][ $key ] = maybe_unserialize( $meta_values['meta_value'] );
			}
		}

		return $meta;
	}

	public static function delete_automation_meta( $aid, $meta_key ) {
		global $wpdb;
		$table = self::_table();
		$query = "DELETE FROM {$table} WHERE `bwfan_automation_id` = %d AND `meta_key` = %s ";

		$wpdb->query( $wpdb->prepare( $query, $aid, $meta_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare()
	}
}
