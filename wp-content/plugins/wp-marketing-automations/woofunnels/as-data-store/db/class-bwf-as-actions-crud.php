<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'BWF_AS_Actions_Crud' ) ) {
	#[AllowDynamicProperties]
	class BWF_AS_Actions_Crud {
		public static $primary_key = 'id';

		/**
		 * Return single action data
		 *
		 * @param $action_id
		 * @param string $return_vars
		 *
		 * @return stdClass|null Null when the action row does not exist (or the query failed).
		 */
		public static function get_single_action( $action_id, $return_vars = '*' ) {
			global $wpdb;
			$allowed_cols = [ '*', 'id', 'c_date', 'e_time', 'hook', 'args', 'status', 'recurring_interval', 'group_slug', 'claim_id' ];
			if ( ! in_array( $return_vars, $allowed_cols, true ) ) {
				$return_vars = '*';
			}
			$table = self::_table();
			$p_key = self::$primary_key;
			$sql   = "SELECT {$return_vars} FROM {$table} WHERE {$p_key}=%d";
			$sql   = $wpdb->prepare( $sql, $action_id ); //phpcs:ignore WordPress.DB.PreparedSQL

			$status = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column whitelisted, table from prefix, action_id bound via %d

			if ( ! is_array( $status ) || ! isset( $status[0] ) || ! is_array( $status[0] ) || 0 === count( $status[0] ) ) {
				/** Empty stdClass here passed every instanceof/empty() guard downstream, causing undefined-property warnings on deleted actions */
				return null;
			}

			$return = new stdClass();
			foreach ( $status[0] as $key => $value ) {
				$value = maybe_unserialize( $value );
				if ( true === self::is_json( $value ) ) {
					$value = json_decode( $value, true );
				}
				$return->$key = $value;
			}

			return $return;
		}

		public static function _table() {
			global $wpdb;
			$table_name = strtolower( 'BWF_Actions' );

			return $wpdb->prefix . $table_name;
		}

		public static function is_json( $string ) {
			if ( empty( $string ) ) {
				return false;
			}
			json_decode( $string );

			return ( json_last_error() === JSON_ERROR_NONE );
		}

		public static function get_executable_actions( $status, $group ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		}

		/**
		 * Find actions based on column inputs
		 *
		 * @param $args
		 *
		 * @return array|bool
		 */
		public static function find_actions( $args ) {
			global $wpdb;

			$table = self::_table();
			$p_key = self::$primary_key;

			$sql        = "SELECT `{$p_key}` FROM $table WHERE 1=1";
			$sql_params = [];

			if ( isset( $args['hook'] ) && ! empty( trim( $args['hook'] ) ) ) {
				$sql          .= ' AND `hook` LIKE %s';
				$sql_params[] = trim( $args['hook'] );
			}

			if ( isset( $args['args'] ) && is_array( $args['args'] ) && count( $args['args'] ) > 0 ) {
				$sql          .= ' AND `args` LIKE %s';
				$sql_params[] = wp_json_encode( $args['args'] );
			}

			if ( isset( $args['group_slug'] ) && ! empty( trim( $args['group_slug'] ) ) ) {
				$sql          .= ' AND `group_slug` LIKE %s';
				$sql_params[] = trim( $args['group_slug'] );
			}

			if ( isset( $args['status'] ) && '' !== $args['status'] ) {
				$sql          .= ' AND `status` LIKE %d';
				$sql_params[] = $args['status'];
			}

			/** Always != hard */
			if ( isset( $args['recurring_interval'] ) && '' !== $args['recurring_interval'] ) {
				$sql          .= ' AND `recurring_interval` != %d';
				$sql_params[] = $args['recurring_interval'];
			}

			$action_ids = $wpdb->get_results( $wpdb->prepare( $sql, $sql_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql assembled from static clause fragments with trusted {$p_key}/$table identifiers; all values bound via $sql_params.; Static fragments, values bound via prepare; custom bwf_actions table
			if ( is_array( $action_ids ) && count( $action_ids ) > 0 ) {
				$action_ids = array_column( $action_ids, 'id' );

				return $action_ids;
			}

			return false;
		}

		public static function get_action_status( $action_id ) {
			global $wpdb;
			$table  = self::_table();
			$p_key  = self::$primary_key;
			$sql    = "SELECT `status` FROM {$table} WHERE {$p_key}=%d";
			$sql    = $wpdb->prepare( $sql, $action_id ); //phpcs:ignore WordPress.DB.PreparedSQL
			$status = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared at 125; only table/pkey identifiers interpolated

			return $status;
		}

		/**
		 * Insert an action row.
		 *
		 * @param $data
		 *
		 * @return int|false Number of rows inserted, or false on failure. Callers scheduling a recurring
		 *                   action MUST check this: a silently dropped INSERT breaks the recurring chain.
		 */
		public static function insert( $data ) {
			global $wpdb;

			return $wpdb->insert( self::_table(), $data ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}

		public static function delete( $value ) {
			global $wpdb;
			if ( empty( $value ) ) {
				return;
			}

			$resp = $wpdb->delete( self::_table(), array( static::$primary_key => $value ), array( '%d' ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			return $resp;
		}

		public static function delete_actions( $action_ids ) {
			global $wpdb;
			$table = self::_table();
			$p_key = self::$primary_key;

			if ( ! is_array( $action_ids ) || 0 === count( $action_ids ) ) {
				BWFAN_Core()->logger->log( 'no action ids to delete, blank array passed', 'sync' );

				return false;
			}

			$type   = array_fill( 0, count( $action_ids ), '%d' );
			$format = implode( ', ', $type );
			$query  = "DELETE FROM {$table} WHERE {$p_key} IN ({$format})";
			$sql    = $wpdb->prepare( $query, $action_ids ); //phpcs:ignore WordPress.DB.PreparedSQL

			$rows_affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- IN placeholders generated in code, ids bound via %d

			return $rows_affected;
		}

		public static function query( $query, $params = [] ) {
			global $wpdb;
			$query = str_replace( '{table_name}', self::_table(), $query );
			if ( ! empty( $params ) ) {
				$query = $wpdb->prepare( $query, ...$params ); //phpcs:ignore WordPress.DB.PreparedSQL
			}
			$results = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- public raw SQL wrapper, no callers in any plugin; callers must pass prepared SQL

			return $results;
		}

		public static function insert_id() {
			global $wpdb;

			return $wpdb->insert_id;
		}

		public static function get_results( $query, $params = [] ) {
			global $wpdb;
			$query = str_replace( '{table_name}', self::_table(), $query );
			if ( ! empty( $params ) ) {
				$query = $wpdb->prepare( $query, ...$params ); //phpcs:ignore WordPress.DB.PreparedSQL
			}
			$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- public raw SQL wrapper, no callers in any plugin; callers must pass prepared SQL

			return $results;
		}

	}
}