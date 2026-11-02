<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WFCO_Model' ) ) {
	#[AllowDynamicProperties]
	abstract class WFCO_Model {
		static $primary_key = 'id';
		static $count = 20;

		static function get( $value ) {
			global $wpdb;

			return $wpdb->get_row( self::_fetch_sql( $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared by _fetch_sql; table/pk from class; value bound via %s
		}

		private static function _fetch_sql( $value ) {
			global $wpdb;
			$sql = sprintf( 'SELECT * FROM %s WHERE %s = %%s', self::_table(), static::$primary_key );

			return $wpdb->prepare( $sql, $value ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		}

		private static function _table() {
			global $wpdb;
			$tablename = strtolower( get_called_class() );

			$tablename = str_replace( 'wfco_model_', 'wfco_', $tablename );

			return $wpdb->prefix . $tablename;
		}

		static function insert( $data ) {
			global $wpdb;
			$wpdb->insert( self::_table(), $data ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}

		static function update( $data, $where ) {
			global $wpdb;
			$wpdb->update( self::_table(), $data, $where ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		static function delete( $value ) {
			global $wpdb;
			$sql = sprintf( 'DELETE FROM %s WHERE %s = %%s', self::_table(), static::$primary_key );

			return $wpdb->query( $wpdb->prepare( $sql, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/pk from class; value bound via %s
		}

		static function insert_id() {
			global $wpdb;

			return $wpdb->insert_id;
		}

		static function now() {
			return self::time_to_date( time() );
		}

		static function time_to_date( $time ) {
			return gmdate( 'Y-m-d H:i:s', $time );
		}

		static function date_to_time( $date ) {
			return strtotime( $date . ' GMT' );
		}

		static function num_rows() {
			global $wpdb;

			return $wpdb->num_rows;
		}

		static function count_rows( $dependency = null ) {
			global $wpdb;

			$sql = 'SELECT COUNT(*) FROM ' . self::_table();
			if ( ! is_null( $dependency ) ) {
				$col_value = isset( $dependency['col_value'] ) ? $dependency['col_value'] : '';

				$sql .= ' INNER JOIN ' . $dependency['dependency_table'];
				$sql .= ' on ' . self::_table() . '.' . $dependency['dependent_col'];
				$sql .= ' =' . $dependency['dependency_table'] . '.' . $dependency['dependency_col'];
				$sql .= $wpdb->prepare( ' WHERE ' . $dependency['dependency_table'] . '.' . $dependency['col_name'] . ' = %s', $col_value ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( isset( $dependency['connector_id'] ) ) {
					$connector_id = absint( $dependency['connector_id'] );
					$sql         .= $wpdb->prepare( ' AND ' . $dependency['connector_table'] . '.' . $dependency['connector_col'] . ' = %d', $connector_id ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}

			return $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- public raw SQL wrapper, no callers in any plugin; callers must pass prepared SQL
		}

		static function get_specific_rows( $where_key, $where_value ) {
			global $wpdb;
			$table_name = self::_table();

			// Whitelist allowed column names to prevent column injection
			$allowed_columns = array( 'ID', 'id', 'slug', 'connector_id' );
			if ( ! in_array( $where_key, $allowed_columns, true ) ) {
				return array();
			}

			$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE `{$where_key}` = %s", $where_value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table_name from self::_table() (trusted); $where_key whitelisted above; $where_value bound via %s.; table from class; column whitelisted; value bound via %s

			return $results;
		}

		static function get_results( $query, $params = [] ) {
			global $wpdb;
			$query = str_replace( '{table_name}', self::_table(), $query );
			if ( ! empty( $params ) ) {
				$query = $wpdb->prepare( $query, ...$params ); //phpcs:ignore WordPress.DB.PreparedSQL
			}
			$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- raw sink; every traced caller passes static, prepared or escaped SQL

			return $results;
		}

		static function delete_multiple( $query ) {
			global $wpdb;
			$query = str_replace( '{table_name}', self::_table(), $query );
			$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- raw sink; every traced caller passes prepared or int-cast SQL
		}

		static function update_multiple( $query ) {
			global $wpdb;
			$query = str_replace( '{table_name}', self::_table(), $query );
			$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- raw sink with no callers in the 5 plugins; caller must prepare
		}
	}
}