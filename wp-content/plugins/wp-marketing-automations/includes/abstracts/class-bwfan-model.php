<?php

#[AllowDynamicProperties]
abstract class BWFAN_Model {
	static $primary_key = 'id';
	static $count = 20;

	/**
	 * Table name
	 *
	 * @var string
	 */
//	protected static $table = '';

	static function set_id() {
	}

	static function get( $value ) {
		global $wpdb;

		$query = self::_fetch_sql( $value );

		return $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- query built by _fetch_sql() via $wpdb->prepare(); table from $wpdb->prefix
	}

	private static function _fetch_sql( $value ) {
		global $wpdb;
		$sql = sprintf( 'SELECT * FROM %s WHERE %s = %%s', self::_table(), static::$primary_key );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix and class props; value bound via %s placeholder
		return $wpdb->prepare( $sql, $value ); // WPCS: unprepared SQL OK
	}

	protected static function _table() {
		global $wpdb;
		if ( ! property_exists( get_called_class(), 'table' ) || static::$table === '' ) {
			$tablename = strtolower( get_called_class() );
			$tablename = str_replace( 'bwfan_model_', 'bwfan_', $tablename );
		} else {
			$tablename = static::$table;
		}

		return $wpdb->prefix . $tablename;
	}

	/**
	 * Rows inserted, or false on failure - wpdb returns false both for a failed query and for a
	 * pre-query bail-out (an unknown column, a charset or length it cannot resolve), and only the
	 * first of those sets $wpdb->last_error. Callers that must know whether the row landed have to
	 * read this; last_error alone misses the rest.
	 *
	 * @param array $data
	 *
	 * @return int|false
	 */
	static function insert( $data ) {
		global $wpdb;

		return $wpdb->insert( self::_table(), $data ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL
	}

	static function update( $data, $where ) {
		global $wpdb;

		return $wpdb->update( self::_table(), $data, $where ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL
	}

	static function delete( $value ) {
		global $wpdb;
		$sql = sprintf( 'DELETE FROM %s WHERE %s = %%s', self::_table(), static::$primary_key );

		return $wpdb->query( $wpdb->prepare( $sql, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/pk from class props; value bound via $wpdb->prepare()
	}

	/**
	 * Whether the last DB error indicates an InnoDB deadlock.
	 *
	 * @param string $last_error wpdb last_error string.
	 * @return bool
	 */
	protected static function is_deadlock_error( $last_error ) {
		if ( '' === $last_error ) {
			return false;
		}

		return false !== stripos( $last_error, 'deadlock' ) || false !== stripos( $last_error, 'lock wait timeout' );  
	}

	/**
	 * Run a pre-prepared write query with InnoDB deadlock detection and retry.
	 *
	 * Callers may use the literal `{table_name}` placeholder; it is substituted
	 * with the late-static-bound table name. Use for raw UPDATE/DELETE/INSERT
	 * statements that bypass `$wpdb->update/insert`.
	 *
	 * @param string $sql     Pre-prepared SQL statement.
	 * @param string $context Short label included in retry log lines.
	 * @return int|false Rows affected, or false on non-deadlock failure / retry exhaustion.
	 */
	public static function query_with_retry( $sql, $context = 'query' ) {
		global $wpdb;
		$sql         = str_replace( '{table_name}', self::_table(), $sql );
		$max_retries = 3;

		for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
			if ( $attempt > 0 ) {
				BWFAN_Common::log_test_data( 'BWFAN: Deadlock on ' . $context . ', retry attempt ' . $attempt . ' of ' . $max_retries, 'fka-db-deadlock', true );
				usleep( $attempt * 100000 );
			}

			/** Suppress wpdb's native error logging; deadlocks are handled here and logged via fka-db-deadlock. last_error stays populated for detection below. */
			$suppress = $wpdb->suppress_errors( true );
			$result   = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- caller passes $wpdb->prepare()d SQL; only {table_name} swapped for _table()
			$wpdb->suppress_errors( $suppress );

			if ( false !== $result || ! self::is_deadlock_error( $wpdb->last_error ) ) {
				/** Re-surface genuine (non-deadlock) failures that wpdb would normally have logged, unless an outer context already suppressed errors. */
				if ( false === $result && ! $suppress && '' !== $wpdb->last_error ) {
					$wpdb->print_error( $wpdb->last_error ); //phpcs:ignore
				}

				return $result;
			}
		}

		return false;
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
			if ( isset( $dependency['automation_id'] ) ) {
				$dependency['automation_id'] = absint( $dependency['automation_id'] );
			}
			if ( isset( $dependency['col_value'] ) && 'any' !== $dependency['col_value'] ) {
				$dependency['col_value'] = "'" . esc_sql( $dependency['col_value'] ) . "'";
			}
			$self_tbl = '`' . str_replace( '`', '``', self::_table() ) . '`';
			$dep_tbl  = '`' . str_replace( '`', '``', $dependency['dependency_table'] ) . '`';
			$dep_on   = '`' . str_replace( '`', '``', $dependency['dependent_col'] ) . '`';
			$dep_col  = '`' . str_replace( '`', '``', $dependency['dependency_col'] ) . '`';
			$col_name = '`' . str_replace( '`', '``', $dependency['col_name'] ) . '`';
			$join     = ' INNER JOIN ' . $dep_tbl . ' on ' . $self_tbl . '.' . $dep_on . '=' . $dep_tbl . '.' . $dep_col;

			$sql = 'SELECT COUNT(*) FROM ' . $self_tbl . $join . ' WHERE ' . $dep_tbl . '.' . $col_name . '=' . $dependency['col_value'];
			if ( isset( $dependency['automation_id'] ) ) {
				$auto_tbl  = '`' . str_replace( '`', '``', $dependency['automation_table'] ) . '`';
				$auto_col  = '`' . str_replace( '`', '``', $dependency['automation_col'] ) . '`';
				$auto_cond = ' AND ' . $auto_tbl . '.' . $auto_col . '=' . $dependency['automation_id'];

				$sql = 'SELECT COUNT(*) FROM ' . $self_tbl . $join . ' WHERE ' . $dep_tbl . '.' . $col_name . '=' . $dependency['col_value'] . $auto_cond;
				if ( 'any' === $dependency['col_value'] ) {
					$sql = 'SELECT COUNT(*) FROM ' . $self_tbl . $join . ' WHERE ' . $auto_tbl . '.' . $auto_col . '=' . $dependency['automation_id'];
				}
			}
		}

		return $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers backtick-escaped, col_value esc_sql() in quotes, automation_id absint()
	}

	static function count( $data = array() ) {
		global $wpdb;

		$sql        = 'SELECT COUNT(*) as `count` FROM ' . self::_table() . ' WHERE 1=1';
		$sql_params = [];
		if ( is_array( $data ) && count( $data ) > 0 ) {
			foreach ( $data as $key => $val ) {
				$column       = '`' . str_replace( '`', '``', $key ) . '`';
				$operator     = in_array( $val['operator'], array( '%s', '%d', '%f' ), true ) ? $val['operator'] : '%s';
				$sql          .= " AND {$column} LIKE {$operator}";
				$sql_params[] = $val['value'];
			}

			if ( ! empty( $sql_params ) ) {
				$sql = $wpdb->prepare( $sql, ...$sql_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		return $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- column backtick-escaped, operator whitelisted, values bound via prepare()
	}

	static function get_specific_rows( $where_key, $where_value, $offset = 0, $limit = 0 ) {
		global $wpdb;
		$table_name = self::_table();

		// Whitelist allowed column names to prevent column injection
		$allowed_columns = array( 'ID', 'id', 'recipient', 'slug', 'email', 'cid', 'wcid', 'trackid', 'type', 'aid', 'hash_code' );
		if ( ! in_array( $where_key, $allowed_columns, true ) ) {
			return array();
		}

		if ( ! empty( $limit ) ) {
			$query = $wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE `{$where_key}` = %s LIMIT %d, %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where_value,
				absint( $offset ),
				absint( $limit )
			);
		} else {
			$query = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE `{$where_key}` = %s", $where_value ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- where_key whitelisted; values bound via $wpdb->prepare()
	}

	static function get_rows( $only_query = false, $automation_ids = array() ) {
		global $wpdb;

		$table_name     = self::_table();
		$page_number    = 1;
		$count_per_page = self::$count;
		$next_offset    = ( $page_number - 1 ) * $count_per_page;
		$sql_query      = $wpdb->prepare( "SELECT * FROM $table_name ORDER BY c_date DESC LIMIT %d OFFSET %d", $count_per_page, $next_offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from _table() ($wpdb->prefix); limit/offset bound via %d

		if ( isset( $_GET['paged'] ) && $_GET['paged'] > 1 ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			$page_number = sanitize_text_field( wp_unslash( $_GET['paged'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			$next_offset = ( $page_number - 1 ) * $count_per_page;
			$sql_query   = $wpdb->prepare( "SELECT * FROM $table_name ORDER BY c_date DESC LIMIT %d OFFSET %d", $count_per_page, $next_offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from _table(); paged offset bound via %d placeholder
		}

		if ( isset( $_GET['status'] ) && 'all' !== $_GET['status'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			$status    = sanitize_text_field( wp_unslash( $_GET['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			$status    = ( 'active' === $status ) ? 1 : 2;
			$sql_query = $wpdb->prepare( "SELECT * FROM $table_name WHERE status = %d ORDER BY c_date DESC LIMIT %d OFFSET %d", $status, $count_per_page, $next_offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from _table(); status and paging bound via %d
		}

		if ( ( isset( $_GET['paged'] ) && $_GET['paged'] > 0 ) && ( isset( $_GET['status'] ) && '' !== $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			$page_number = sanitize_text_field( wp_unslash( $_GET['paged'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			$next_offset = ( $page_number - 1 ) * $count_per_page;
			$status      = sanitize_text_field( wp_unslash( $_GET['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			$sql_query   = $wpdb->prepare( "SELECT * FROM $table_name WHERE status = %d ORDER BY c_date DESC LIMIT %d OFFSET %d", $status, $count_per_page, $next_offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from _table(); request values bound via %d placeholders
		}

		$result = $wpdb->get_results( $sql_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above; table from _table()

		return $result;
	}

	static function get_results( $query, $args = array() ) {
		global $wpdb;
		$query   = str_replace( '{table_name}', self::_table(), $query );
		if ( ! empty( $args ) ) {
			$query = $wpdb->prepare( $query, ...$args ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- generic model wrapper; callers pass SQL built with $wpdb->prepare() or args prepared here

		return $results;
	}

	/**
	 * Get data by id.
	 *
	 * @param array|int $ids Array of IDs or single ID
	 *
	 * @return array Associative array keyed by ID
	 */
	static function get_data_by_id( $ids = [] ) {
		if ( empty( $ids ) ) {
			return [];
		}

		if ( ! is_array( $ids ) ) {
			$ids = [ $ids ];
		}

		// Sanitize all IDs to integers
		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids ); // Remove any invalid values

		if ( empty( $ids ) ) {
			return [];
		}

		global $wpdb;
		$table_name = self::_table();

		// Build placeholders for prepared statement
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$query        = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id IN ({$placeholders})", ...$ids ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders from array_fill('%d'); ids absint(); table from $wpdb->prefix

		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above; ids absint()

		if ( empty( $results ) ) {
			return [];
		}

		$data = [];
		foreach ( $results as $result ) {
			if ( isset( $result['ID'] ) ) {
				$data[ $result['ID'] ] = $result;
			}

			if ( isset( $result['id'] ) ) {
				$data[ $result['id'] ] = $result;
			}
		}

		return $data;
	}

	static function get_var( $query, $args = array() ) {
		global $wpdb;
		$query = str_replace( '{table_name}', self::_table(), $query );
		if ( ! empty( $args ) ) {
			$query = $wpdb->prepare( $query, ...$args ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- generic model wrapper; callers pass SQL built with $wpdb->prepare() or args prepared here
	}

	static function delete_multiple( $query, $args = array() ) {
		self::query( $query, $args );
	}

	static function query( $query, $args = array() ) {
		global $wpdb;
		$query = str_replace( '{table_name}', self::_table(), $query );
		if ( ! empty( $args ) ) {
			$query = $wpdb->prepare( $query, ...$args ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- generic model wrapper; callers pass SQL built with $wpdb->prepare() or args prepared here
	}

	static function update_multiple( $query, $args = array() ) {
		self::query( $query, $args );
	}

	static function get_current_date_time() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	static function insert_multiple( $values, $keys, $formats = [] ) {
		if ( ( ! is_array( $keys ) || empty( $keys ) ) || ( ! is_array( $values ) || empty( $values ) ) ) {
			return false;
		}

		global $wpdb;

		$values = array_map( function ( $value ) use ( $keys, $formats ) {
			global $wpdb;
			$return = array();
			foreach ( $keys as $index => $key ) {
				$format   = is_array( $formats ) && isset( $formats[ $index ] ) ? $formats[ $index ] : false;
				$format   = ! empty( $format ) ? $format : ( is_numeric( $value[ $key ] ) ? '%d' : '%s' );
				$return[] = $wpdb->prepare( $format, $value[ $key ] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- format is hardcoded %d/%s from caller; value bound via $wpdb->prepare()
			}

			return '(' . implode( ',', $return ) . ')';
		}, $values );
		$values = implode( ', ', $values );
		$keys   = '(' . implode( ', ', $keys ) . ')';
		$query  = 'INSERT INTO ' . self::_table() . ' ' . $keys . ' VALUES ' . $values;

		/** Every value above is already escaped via $wpdb->prepare(); the old outer prepare() had no placeholders and only raised a notice */
		return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- every value escaped with $wpdb->prepare() above; keys are hardcoded column names from callers
	}

	/**
	 * Insert query with Ignore
	 *
	 * @param $data
	 * @param $format
	 *
	 * @return bool|int|mysqli_result|null
	 */
	static function insert_ignore( $data, $format = null ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

		// Validate format if provided
		if ( ! is_null( $format ) && count( $format ) !== count( $data ) ) {
			$format = null; // Reset format if it doesn't match data count
		}

		$placeholders = is_null( $format ) ? array_fill( 0, count( $data ), '%s' ) : $format;
		$columns      = array_keys( $data );
		$table        = self::_table();
		global $wpdb;

		$sql = "INSERT IGNORE INTO `$table` (`" . implode( '`,`', $columns ) . "`) VALUES (" . implode( ',', $placeholders ) . ")";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- values bound via $wpdb->prepare(); columns hardcoded by callers; table from _table()
		$result = $wpdb->query( $wpdb->prepare( $sql, array_values( $data ) ) );
		if ( ! empty( $result ) ) {
			return $result;
		}

		/** If duplicate entry DB error come */
		if ( 0 === $result ) {
			$warnings = $wpdb->get_results( "SHOW WARNINGS", ARRAY_A );//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! empty( $warnings ) ) {
				foreach ( $warnings as $warning ) {
					if ( empty( $warning['Message'] ) || false === strpos( $warning['Message'], 'Duplicate entry' ) ) {
						continue;
					}
					BWFAN_Common::log_test_data( 'WP db error in ' . $table . ' : ' . $warning['Message'], 'fka-db-duplicate-error', true );
					BWFAN_Common::log_test_data( $data, 'fka-db-duplicate-error', true );
				}
			}
		}

		return false;
	}
}
