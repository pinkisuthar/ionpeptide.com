<?php
declare(strict_types=1);

#[\AllowDynamicProperties]
abstract class BWFAN_DB_Tables_Base {
	public $table_name = '';
	public $db_errors = '';
	public $max_index_length = 191;

	public $collation = null;

	/**
	 * Checking table exists or not
	 *
	 * @return bool
	 */
	public function is_exists() {
		global $wpdb;

		$safe_table_name = esc_sql( $wpdb->prefix . $this->table_name );
		return ! empty( $wpdb->query( "SHOW TABLES LIKE '{$safe_table_name}'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix + hardcoded class property
	}

	/**
	 * Check missing columns and return missing ones only
	 *
	 * @return array
	 */
	public function check_missing_columns() {
		global $wpdb;

		/** Get defined columns */
		$columns = $this->get_columns();
		/** Get columns from db */
		$safe_table_name = esc_sql( $wpdb->prefix . $this->table_name );
		$db_columns = $wpdb->get_results( "DESCRIBE `{$safe_table_name}`", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix + hardcoded class property

		$result = array_diff( $columns, array_column( $db_columns, 'Field' ) );
		sort( $result );

		return $result;
	}

	public function get_columns() {
		return [];
	}

	/**
	 * Get primary key and indexes definition
	 *
	 * @return array Array with 'primary_key', 'indexes', and 'unique_keys' keys
	 *              Format: [
	 *                  'primary_key' => 'column_name',
	 *                  'indexes' => [
	 *                      'index_name' => ['column1', 'column2'], // single or composite index
	 *                  ],
	 *                  'unique_keys' => [
	 *                      'unique_name' => ['column1'], // single or composite unique key
	 *                  ]
	 *              ]
	 */
	public function get_indexes() {
		return [
			'primary_key' => null,
			'indexes'     => [],
			'unique_keys' => [],
		];
	}

	/**
	 * Create table
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;
		$sql = $this->get_create_table_query();
		if ( empty( $sql ) ) {
			return;
		}

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		if ( ! empty( $wpdb->last_error ) ) {
			$this->db_errors = $this->table_name . ' create table method triggered an error - ' . $wpdb->last_error;
		}
	}

	/**
	 * Align the table and its text columns with WordPress's configured collation.
	 *
	 * Converts when the current collation differs from $wpdb->collate — covers both
	 * the legacy latin1/utf8 → utf8mb4 upgrade AND same-family drift (e.g.
	 * utf8mb4_unicode_ci vs utf8mb4_unicode_520_ci) that triggers "Illegal mix of
	 * collations" on cross-table predicates. If $wpdb->collate is not configured,
	 * skips the conversion rather than running an ALTER with an empty COLLATE.
	 *
	 * @return array|bool Array of error strings, true on success, false if table is missing.
	 */
	public function check_table_collation() {
		global $wpdb;
		$table_name = $wpdb->prefix . $this->table_name;

		$table_data = $wpdb->get_row( $wpdb->prepare( "SHOW TABLE STATUS LIKE %s", $table_name ), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( empty( $table_data ) ) {
			return false;
		}

		$safe_table_name   = esc_sql( $table_name );
		$target_charset    = $wpdb->charset;
		$target_collate    = $wpdb->has_cap( 'collation' ) ? $wpdb->collate : '';
		$current_collation = isset( $table_data['Collation'] ) ? (string) $table_data['Collation'] : '';
		$db_errors         = [];

		if ( empty( $target_collate ) ) {
			/** No configured target — don't risk ALTER ... COLLATE '' */
			return true;
		}

		$needs_convert    = $current_collation !== $target_collate;
		$convert_success  = false;

		if ( $needs_convert ) {
			$wpdb->query( $wpdb->prepare( "ALTER TABLE `{$safe_table_name}` CONVERT TO CHARACTER SET %s COLLATE %s", $target_charset, $target_collate ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table from $wpdb->prefix + hardcoded class property; DB upgrade: converts plugin tables to the site charset/collation
			if ( ! empty( $wpdb->last_error ) ) {
				$db_errors[] = "Error converting `{$table_name}` to {$target_collate}: " . $wpdb->last_error;
			} else {
				$convert_success = true;
			}
		}

		/**
		 * CONVERT TO ... COLLATE normalizes every text column in one shot, so the
		 * per-column sweep is only needed when CONVERT TO failed or was skipped
		 * because the table header already matched (but individual columns might
		 * still be out of sync via column-level overrides).
		 */
		if ( $convert_success ) {
			return true;
		}

		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$safe_table_name}`", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix + hardcoded class property
		if ( ! is_array( $columns ) ) {
			$db_errors[] = ! empty( $wpdb->last_error )
				? "Error fetching column metadata for `{$table_name}`: " . $wpdb->last_error
				: "Error fetching column metadata for `{$table_name}`.";

			return $db_errors;
		}
		foreach ( $columns as $column ) {
			$column_collation = isset( $column['Collation'] ) ? (string) $column['Collation'] : '';

			/** Skip non-text columns — they report an empty Collation. */
			if ( empty( $column_collation ) ) {
				continue;
			}

			/** Column already aligned with the target collation. */
			if ( $column_collation === $target_collate ) {
				continue;
			}

			$extra = isset( $column['Extra'] ) ? (string) $column['Extra'] : '';

			/**
			 * Skip generated / virtual columns — their definition needs
			 * GENERATED ALWAYS AS (...) and can't be safely round-tripped
			 * through a plain MODIFY COLUMN.
			 */
			if ( stripos( $extra, 'generated' ) !== false || stripos( $extra, 'virtual' ) !== false || stripos( $extra, 'stored' ) !== false ) {
				continue;
			}

			$field            = $column['Field'];
			$safe_field       = esc_sql( $field );
			$safe_column_type = esc_sql( $column['Type'] );

			/**
			 * MODIFY COLUMN is a full column redefinition — anything we omit
			 * silently reverts to SQL defaults, dropping NOT NULL / DEFAULT /
			 * COMMENT. Rebuild the definition from SHOW FULL COLUMNS so the
			 * existing constraints survive the collation change.
			 */
			$is_nullable = isset( $column['Null'] ) && 'YES' === strtoupper( (string) $column['Null'] );
			$null_clause = $is_nullable ? ' NULL' : ' NOT NULL';

			$default_clause = '';
			if ( array_key_exists( 'Default', $column ) && null !== $column['Default'] ) {
				$default_value = (string) $column['Default'];
				/** CURRENT_TIMESTAMP is an expression, not a literal — don't quote it. */
				if ( 0 === strcasecmp( $default_value, 'CURRENT_TIMESTAMP' ) ) {
					$default_clause = ' DEFAULT CURRENT_TIMESTAMP';
				} else {
					$default_clause = " DEFAULT '" . esc_sql( $default_value ) . "'";
				}
			} elseif ( $is_nullable ) {
				$default_clause = ' DEFAULT NULL';
			}

			$comment_clause = '';
			if ( ! empty( $column['Comment'] ) ) {
				$comment_clause = " COMMENT '" . esc_sql( (string) $column['Comment'] ) . "'";
			}

			$query = $wpdb->prepare( "ALTER TABLE `{$safe_table_name}` MODIFY COLUMN `{$safe_field}` {$safe_column_type} CHARACTER SET %s COLLATE %s{$null_clause}{$default_clause}{$comment_clause}", $target_charset, $target_collate ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema metadata from SHOW COLUMNS; literals esc_sql()d; charset via %s
			$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- schema metadata from SHOW COLUMNS; literals esc_sql()d; charset via %s
			if ( ! empty( $wpdb->last_error ) ) {
				$db_errors[] = "Error altering `{$table_name}`.`{$field}` to {$target_collate}: " . $wpdb->last_error;
			}
		}

		return ! empty( $db_errors ) ? $db_errors : true;
	}

	/**
	 * Check primary key, auto-increment, and indexes, fix if missing, return errors if any
	 *
	 * @return array|bool
	 */
	public function check_primary_key_auto_increment() {
		global $wpdb;
		$table_name = $wpdb->prefix . $this->table_name;

		$table_data = $wpdb->get_row( $wpdb->prepare("SHOW TABLE STATUS LIKE %s", $table_name), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( empty( $table_data ) ) {
			return false;
		}

		$db_errors = [];
		$safe_table_name = esc_sql( $table_name );

		/** Get current table structure */
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$safe_table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix + hardcoded class property
		if ( empty( $columns ) ) {
			return false;
		}

		/** Create column lookup array for O(1) access */
		$column_lookup = array();
		foreach ( $columns as $column ) {
			$column_lookup[ $column['Field'] ] = $column;
		}

		/** Get all existing keys from database */
		$existing_keys = $wpdb->get_results( "SHOW KEYS FROM {$safe_table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix + hardcoded class property
		$existing_key_names = array();
		$existing_primary_key = null;
		foreach ( $existing_keys as $key ) {
			if ( 'PRIMARY' === $key['Key_name'] ) {
				$existing_primary_key = $key['Column_name'];
			} else {
				$existing_key_names[ $key['Key_name'] ] = true;
			}
		}

		/** Get expected indexes from table class */
		$expected_indexes = $this->get_indexes();
		$expected_pk_column = ! empty( $expected_indexes['primary_key'] ) ? $expected_indexes['primary_key'] : null;
		$expected_indexes_list = ! empty( $expected_indexes['indexes'] ) ? $expected_indexes['indexes'] : array();
		$expected_unique_keys = ! empty( $expected_indexes['unique_keys'] ) ? $expected_indexes['unique_keys'] : array();

		/** Check and fix primary key */
		if ( ! empty( $expected_pk_column ) ) {
			$pk_was_added = false;
			$safe_pk_column = esc_sql( $expected_pk_column );

			/** If no primary key exists, clean duplicates before adding it */
			if ( empty( $existing_primary_key ) ) {
				if ( isset( $column_lookup[ $expected_pk_column ] ) ) {
					/** Clean duplicate entries in primary key column before adding PRIMARY KEY constraint */
					$db_errors = array_merge( $db_errors, $this->clean_duplicate_primary_key_values( $safe_table_name, $safe_pk_column, $expected_pk_column ) );

					/** Add primary key after cleaning duplicates */
					$query = "ALTER TABLE `{$safe_table_name}` ADD PRIMARY KEY (`{$safe_pk_column}`)"; //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- table/pk column hardcoded in class definition
					if ( ! empty( $wpdb->last_error ) ) {
						$db_errors[] = "Error adding primary key to {$this->table_name}: " . $wpdb->last_error;
					} else {
						$existing_primary_key = $expected_pk_column;
						$pk_was_added = true;
					}
				} else {
					$db_errors[] = "Primary key column '{$expected_pk_column}' not found in table {$this->table_name}";
				}
			}

			/** Check if primary key column has AUTO_INCREMENT */
			/** Refresh column data if primary key was just added */
			if ( $pk_was_added ) {
				$pk_column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$safe_table_name}` WHERE Field = %s", $existing_primary_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix + hardcoded class property
				if ( ! empty( $pk_column ) ) {
					$column_lookup[ $existing_primary_key ] = $pk_column;
				}
			}

			/** Check AUTO_INCREMENT for primary key */
			if ( ! empty( $existing_primary_key ) && isset( $column_lookup[ $existing_primary_key ] ) ) {
				$pk_column_data = $column_lookup[ $existing_primary_key ];
				$extra = strtolower( trim( $pk_column_data['Extra'] ) );
				$has_auto_increment = ( 'auto_increment' === $extra || str_contains( $extra, 'auto_increment' ) );

				if ( ! $has_auto_increment ) {
					/** Build ALTER TABLE query to add AUTO_INCREMENT */
					$column_type = $pk_column_data['Type'];
					$column_null = ( 'YES' === $pk_column_data['Null'] ) ? 'NOT NULL' : '';
					$column_default = '';
					if ( ! empty( $pk_column_data['Default'] ) && 'NULL' !== strtoupper( $pk_column_data['Default'] ) ) {
						$escaped_default = esc_sql( $pk_column_data['Default'] );
						$column_default = "DEFAULT '{$escaped_default}'";
					}

					/** Build query parts efficiently */
					$modify_parts = array( $column_type );
					if ( ! empty( $column_null ) ) {
						$modify_parts[] = $column_null;
					}
					if ( ! empty( $column_default ) ) {
						$modify_parts[] = $column_default;
					}
					$modify_parts[] = 'AUTO_INCREMENT';

					$query = "ALTER TABLE `{$safe_table_name}` MODIFY COLUMN `{$safe_pk_column}` " . implode( ' ', $modify_parts ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

					$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded table/pk; type from SHOW COLUMNS; default esc_sql()d
					if ( ! empty( $wpdb->last_error ) ) {
						$db_errors[] = "Error adding AUTO_INCREMENT to primary key column '{$existing_primary_key}' in table {$this->table_name}: " . $wpdb->last_error;
					}
				}
			}
		}

		/** Check and fix regular indexes and unique keys using shared method */
		$db_errors = array_merge( $db_errors, $this->check_and_fix_indexes( $safe_table_name, $expected_indexes_list, $existing_key_names, $column_lookup, false ) );
		$db_errors = array_merge( $db_errors, $this->check_and_fix_indexes( $safe_table_name, $expected_unique_keys, $existing_key_names, $column_lookup, true ) );

		return ! empty( $db_errors ) ? $db_errors : true;
	}

	/**
	 * Clean duplicate values in primary key column before adding PRIMARY KEY constraint
	 *
	 * @param string $safe_table_name Escaped table name
	 * @param string $safe_pk_column Escaped primary key column name
	 * @param string $pk_column_name Primary key column name (for error messages)
	 *
	 * @return array Array of errors
	 */
	private function clean_duplicate_primary_key_values( $safe_table_name, $safe_pk_column, $pk_column_name ) {
		global $wpdb;
		$db_errors = array();

		/** Check for NULL values first */
		$null_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$safe_table_name}` WHERE `{$safe_pk_column}` IS NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/pk from class definition; values bound via prepare
		if ( $null_count > 1 ) {
			/** Delete all but one NULL entry */
			$delete_count = $null_count - 1;
			$delete_null_query = $wpdb->prepare( "DELETE FROM `{$safe_table_name}` WHERE `{$safe_pk_column}` IS NULL LIMIT %d", $delete_count ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/pk from class definition; values bound via prepare
			$wpdb->query( $delete_null_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/pk from class definition; values bound via prepare
			if ( ! empty( $wpdb->last_error ) ) {
				$db_errors[] = "Error deleting NULL entries from table {$this->table_name}: " . $wpdb->last_error;
			}
		}

		/** Check for duplicate non-NULL values and delete them */
		$duplicates_query = "SELECT `{$safe_pk_column}`, COUNT(*) as count FROM `{$safe_table_name}` WHERE `{$safe_pk_column}` IS NOT NULL GROUP BY `{$safe_pk_column}` HAVING count > 1"; //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$duplicates = $wpdb->get_results( $duplicates_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/pk from class definition; values bound via prepare

		if ( ! empty( $duplicates ) ) {
			/** Delete duplicate entries, keeping the first one (lowest value) */
			foreach ( $duplicates as $duplicate ) {
				$duplicate_value = $duplicate[ $pk_column_name ];
				$duplicate_count = intval( $duplicate['count'] );
				$delete_count = $duplicate_count - 1;

				/** Delete all but one duplicate entry */
				$delete_query = $wpdb->prepare( "DELETE FROM `{$safe_table_name}` WHERE `{$safe_pk_column}` = %s LIMIT %d", $duplicate_value, $delete_count ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/pk from class definition; values bound via prepare
				$wpdb->query( $delete_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table/pk from class definition; values bound via prepare
				if ( ! empty( $wpdb->last_error ) ) {
					$db_errors[] = "Error deleting duplicate entries with {$pk_column_name} = '{$duplicate_value}' from table {$this->table_name}: " . $wpdb->last_error;
				}
			}
		}

		return $db_errors;
	}

	/**
	 * Check and fix indexes or unique keys
	 *
	 * @param string $safe_table_name Escaped table name
	 * @param array  $expected_keys Expected keys array (index_name => [columns])
	 * @param array  $existing_key_names Array of existing key names
	 * @param array  $column_lookup Column lookup array for fast access
	 * @param bool   $is_unique Whether this is a unique key
	 *
	 * @return array Array of errors
	 */
	private function check_and_fix_indexes( $safe_table_name, $expected_keys, $existing_key_names, $column_lookup, $is_unique = false ) {
		global $wpdb;
		$db_errors = array();
		$key_type = $is_unique ? 'unique key' : 'index';

		foreach ( $expected_keys as $key_name => $key_columns ) {
			if ( ! is_array( $key_columns ) ) {
				$key_columns = array( $key_columns );
			}

			if ( ! isset( $existing_key_names[ $key_name ] ) ) {
				/** Verify all columns exist before creating key */
				$missing_columns = array();
				foreach ( $key_columns as $col ) {
					if ( ! isset( $column_lookup[ $col ] ) ) {
						$missing_columns[] = $col;
					}
				}

				if ( ! empty( $missing_columns ) ) {
					$db_errors[] = sprintf(
						"%s column(s) '%s' not found in table %s for %s '%s'",
						ucfirst( $key_type ),
						implode( "', '", $missing_columns ),
						$this->table_name,
						$key_type,
						$key_name
					);
					continue;
				}

				/** Build and execute query */
				$safe_columns = array_map( function( $col ) {
					return "`" . esc_sql( $col ) . "`";
				}, $key_columns );
				$columns_str = implode( ', ', $safe_columns );
				$safe_key_name = esc_sql( $key_name );
				$key_sql = $is_unique ? 'UNIQUE KEY' : 'INDEX';
				$query = "ALTER TABLE `{$safe_table_name}` ADD {$key_sql} `{$safe_key_name}` ({$columns_str})"; //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

				$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- key/column names from class definition, esc_sql()d
				if ( ! empty( $wpdb->last_error ) ) {
					$db_errors[] = "Error adding {$key_type} '{$key_name}' to table {$this->table_name}: " . $wpdb->last_error;
				}
			}
		}

		return $db_errors;
	}

	public function get_create_table_query() {
		return '';
	}

	public function get_collation() {
		if ( ! is_null( $this->collation ) ) {
			return $this->collation;
		}

		global $wpdb;
		$collate = '';
		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$this->collation = $collate;

		return $collate;
	}
}
