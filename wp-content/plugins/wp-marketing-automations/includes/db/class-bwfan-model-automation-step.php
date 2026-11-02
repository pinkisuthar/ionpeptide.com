<?php

/**
 * Automation step modal class
 */
#[\AllowDynamicProperties]
class BWFAN_Model_Automation_Step extends BWFAN_Model {
	static $primary_key = 'ID';

	/**
	 * Step lifecycle status values stored in the `status` column.
	 *
	 * @since 3.8.1
	 */
	const STATUS_ACTIVE     = 1; // Default — runs at runtime
	const STATUS_INCOMPLETE = 2; // Required config fields missing (visual-only via bwf-draft-node)
	const STATUS_ARCHIVED   = 4; // Set by delete (update_steps_status)
	const STATUS_DISABLED   = 5; // User-toggled "skip me at runtime"

	/**
	 * Get Steps
	 *
	 * @param int $aid
	 * @param int $offset
	 * @param int $limit
	 * @param string $search
	 * @param string $order
	 * @param string $order_by
	 * @param array $ids
	 * @param bool $get_total
	 *
	 * @return array
	 */
	public static function get_all_automation_steps( $aid = 0, $offset = 0, $limit = 0, $search = '', $order = 'DESC', $order_by = 'ID', $ids = [], $get_total = false, $get_deleted_nodes = false ) {
		global $wpdb;

		/**
		 * Default response
		 */
		$response = [
			'steps' => [],
			'total' => 0
		];

		$table = self::_table();

		$sql = "SELECT * FROM {$table}  ";

		$where_sql = ' WHERE 1=1';

		/**
		 * If automation id is provided
		 */
		$aid = intval( $aid );
		if ( 0 !== $aid ) {
			$where_sql .= " AND `aid` = {$aid}";
		}

		/**
		 * If search needed
		 */
		if ( ! empty( $search ) ) {
			$where_sql .= $wpdb->prepare( " AND `title` LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		/** Get by Status */
		if ( ! $get_deleted_nodes ) {
			$where_sql .= " AND `status` NOT IN ( 3, 4 )";
		}

		if ( ! empty( $ids ) ) {
			$where_sql .= " AND `ID` IN(" . implode( ',', array_map( 'absint', $ids ) ) . ")";
		}

		/** Set Pagination */
		$pagination_sql = '';
		$limit          = ! empty( $limit ) ? absint( $limit ) : 0;
		$offset         = ! empty( $offset ) ? absint( $offset ) : 0;
		if ( ! empty( $limit ) || ! empty( $offset ) ) {
			$pagination_sql = " LIMIT $offset, $limit";
		}

		/** Order By */
		$order     = ( 'ASC' === strtoupper( (string) $order ) ) ? 'ASC' : 'DESC';
		$order_by  = '`' . str_replace( '`', '``', (string) $order_by ) . '`';
		$order_sql = " ORDER BY {$order_by} {$order}";

		/** Form sql query */
		$sql = $sql . $where_sql . $order_sql . $pagination_sql;

		$response['steps'] = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- ints cast; search prepared; order whitelisted; order_by quoted identifier

		/**
		 * Get total
		 */
		if ( $get_total ) {
			$total_sql         = "SELECT count(*) FROM {$table} " . $where_sql;
			$response['total'] = absint( $wpdb->get_var( $total_sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- ints cast; search prepared; order whitelisted; order_by quoted identifier
		}

		return $response;
	}

	/**
	 * Return table name
	 *
	 * @return string
	 */
	protected static function _table() {
		global $wpdb;

		return $wpdb->prefix . 'bwfan_automation_step';
	}

	/**
	 * Insert new automation to db
	 *
	 * @param $data
	 *
	 * @return int
	 */
	public static function create_new_automation_step( $data ) {
		if ( empty( $data ) ) {
			return;
		}
		self::insert( $data );

		return absint( self::insert_id() );
	}

	/**
	 * Update automation step data by id
	 *
	 * @param $id
	 * @param $data
	 *
	 * @return bool
	 */
	public static function update_automation_step_data( $id, $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}

		return ! ! self::update( $data, array(
			'id' => absint( $id ),
		) );
	}

	/**
	 * Delete Automation steps
	 *
	 * @param $ids
	 *
	 * @return mixed
	 */
	public static function delete_automation_steps( $ids = [] ) {
		if ( empty( $ids ) ) {
			return false;
		}

		global $wpdb;
		$table_name = self::_table();

		if ( ! is_array( $ids ) ) {
			$ids = [ $ids ];
		}

		$ids          = array_filter( array_map( 'intval', $ids ) );
		$placeholders = array_fill( 0, count( $ids ), '%d' );

		$query = $wpdb->prepare( "DELETE FROM $table_name WHERE `ID` IN ( " . implode( ',', $placeholders ) . " ) AND status = 0", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- ids intval()ed; %d placeholders generated; prepared

		return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- ids intval()ed; %d placeholders generated; prepared
	}
	
	/**	
	 * Get step data by id
	 *
	 * @param $step_id
	 *
	 * @return array|bool
	 */
	public static function get_step_data_by_id( $step_id ) {
		$result = BWFAN_Model_Automation_Step::get_specific_rows( 'ID', $step_id );

		if ( empty( $result ) && ! is_array( $result ) ) {
			return false;
		}

		return isset( $result[0] ) ? $result[0] : false;
	}

	public static function delete_steps_by_aid( $aid ) {
		global $wpdb;
		$table_name = self::_table();

		if ( is_array( $aid ) ) {
			$aid = array_filter( array_map( 'intval', $aid ) );
			if ( empty( $aid ) ) {
				return 0;
			}
			$placeholders = implode( ',', array_fill( 0, count( $aid ), '%d' ) );
			$query        = $wpdb->prepare( "DELETE FROM $table_name WHERE `aid` IN ( $placeholders )", $aid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- ids intval()ed; %d placeholders generated; prepared
		} else {
			$query = $wpdb->prepare( "DELETE FROM $table_name WHERE `aid` = %d", intval( $aid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids intval()ed; %d placeholders generated; prepared
		}

		return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- ids intval()ed; %d placeholders generated; prepared
	}

	public static function get_step_by_trail( $trail ) {
		global $wpdb;
		$table_name = self::_table();

		$query   = $wpdb->prepare( "SELECT ct.c_time AS run_time, st.action, st.type FROM {$wpdb->prefix}bwfan_automation_contact_trail AS ct JOIN {$table_name} AS st ON ct.sid=st.ID WHERE ct.tid=%s ORDER BY ct.ID DESC LIMIT 1", $trail ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trail id bound via %s; table names from $wpdb->prefix
		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query prepared above

		return $results;
	}

	/**
	 * Get automation steps ids
	 *
	 * @param int $aid
	 *
	 * @return array
	 */
	public static function get_automation_step_ids( $aid ) {
		if ( empty( $aid ) ) {
			return [];
		}

		global $wpdb;
		$table = self::_table();

		$query   = $wpdb->prepare( "SELECT ID FROM {$table} WHERE `status` != %d AND aid = %d", 3, $aid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix table; values bound via prepare() %d
		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix table; values bound via prepare() %d

		return $results;
	}

	/**
	 * Return ID of the active step i.e. not equal to 3
	 *
	 * @param $id
	 *
	 * @return int|string|null
	 */
	public static function is_step_active( $id ) {
		if ( empty( $id ) ) {
			return 0;
		}

		global $wpdb;
		$table = self::_table();

		$query = $wpdb->prepare( "SELECT ID FROM {$table} WHERE `status` != %d AND ID = %d", 3, $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix table; values bound via prepare() %d

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix table; values bound via prepare() %d
	}

	/**
	 * Routable check for the runtime hot path (jump / wait-skip targets).
	 * Returns the step ID if the target is Active (1) OR Disabled (5).
	 *
	 * A disabled target is intentionally allowed through: the contact is routed
	 * onto it and process_disabled_step() then fast-forwards past it — the same
	 * graceful skip a normal edge into a disabled step already gets. Deleted
	 * (row gone), Archived (4) and Incomplete (2) targets remain unroutable.
	 *
	 * Distinct from is_step_active(), whose looser "not archived (!= 3)" semantic
	 * is required by editor-load (so disabling a jump target does not silently
	 * clear jump_to).
	 *
	 * @param int $id step ID
	 *
	 * @since 3.8.1
	 *
	 * @return string|null
	 */
	public static function is_step_runtime_routable( $id ) {
		if ( empty( $id ) ) {
			return 0;
		}

		global $wpdb;
		$table = self::_table();
		$query = $wpdb->prepare( "SELECT ID FROM {$table} WHERE `status` IN ( %d, %d ) AND ID = %d", self::STATUS_ACTIVE, self::STATUS_DISABLED, $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix table; values bound via prepare() %d

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix table; values bound via prepare() %d
	}

	/**
	 * Fresh list of disabled (status 5) step IDs for an automation.
	 * The automation meta `steps` array caches a stale step_status after a disable
	 * toggle, so callers that need the live disabled set (e.g. the async goal-jump
	 * traversal) must read the DB directly.
	 *
	 * @param int $aid automation id
	 *
	 * @since 3.8.1
	 *
	 * @return int[] step IDs
	 */
	public static function get_disabled_step_ids( $aid ) {
		if ( empty( $aid ) ) {
			return [];
		}

		global $wpdb;
		$table = self::_table();
		$query = $wpdb->prepare( "SELECT ID FROM {$table} WHERE `aid` = %d AND `status` = %d", $aid, self::STATUS_DISABLED ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix table; values bound via prepare() %d

		return array_map( 'intval', (array) $wpdb->get_col( $query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix table; values bound via prepare() %d
	}

	/**
	 * Get email step ids
	 *
	 * @return array
	 */
	public static function get_email_step_ids() {
		global $wpdb;
		$table = self::_table();
		$sql   = "SELECT `ID` FROM {$table}  WHERE `action` LIKE '%s' AND `status`= %d ";

		return $wpdb->get_col( $wpdb->prepare( $sql, '%wp_sendemail%', 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded query and values; prepared
	}

	/**
	 * @param $stepids
	 * @param $action
	 *
	 * @return array
	 */
	public static function get_messaging_steps( $stepids, $action = 'wp_sendemail' ) {

		global $wpdb;

		$placeholder = array_fill( 0, count( $stepids ), '%d' );
		$placeholder = implode( ", ", $placeholder );
		$args        = $stepids;
		$args[]      = '%' . $action . '"%';


		$query = "SELECT `ID` FROM {$wpdb->prefix}bwfan_automation_step WHERE `ID` IN($placeholder) AND `action` LIKE %s";
		$query = $wpdb->prepare( $query, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; values bound via prepare() array

		return $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; values bound via prepare() array
	}

	/**
	 * Change steps status to archive
	 *
	 * @param $step_ids
	 *
	 * @return bool|int|mysqli_result|resource|null
	 */
	public static function update_steps_status( $step_ids ) {
		if ( empty( $step_ids ) ) {
			return false;
		}
		global $wpdb;

		$table_name = self::_table();

		$placeholder = array_fill( 0, count( $step_ids ), '%d' );
		$placeholder = implode( ", ", $placeholder );
		$args        = array_merge( [ 4 ], $step_ids );

		$query = "UPDATE $table_name SET `status` = %d WHERE `ID` IN ($placeholder)";

		return $wpdb->query( $wpdb->prepare( $query, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; values bound via prepare(), counts match
	}

	public static function get_step_data( $step_id ) {
		$step = self::get_step_data_by_id( $step_id );
		if ( empty( $step ) ) {
			return [];
		}

		$step['data'] = ! empty( $step['data'] ) ? json_decode( $step['data'], true ) : [];

		return $step;
	}

	/**
	 * Get Benchmark event name by step id
	 *
	 * @param $stepids
	 *
	 * @return array
	 */
	public static function get_benchmark_events( $stepids = [] ) {
		if ( empty( $stepids ) ) {
			return [];
		}

		global $wpdb;

		$placeholder = array_fill( 0, count( $stepids ), '%d' );
		$placeholder = implode( ", ", $placeholder );
		$args        = $stepids;
		$table_name  = self::_table();

		$query = "SELECT `ID`, `action` FROM $table_name WHERE `ID` IN ($placeholder) AND `type` = 3;";
		$query = $wpdb->prepare( $query, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; ids bound via prepare() %d

		$results      = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; ids bound via prepare() %d
		$return_array = [];
		if ( is_array( $results ) && count( $results ) > 0 ) {
			foreach ( $results as $val ) {
				if ( ! empty( $val['action'] ) ) {
					$benchmark = json_decode( $val['action'], true );
					$benchmark = $benchmark['benchmark'] ?? '';
					if ( ! empty( $benchmark ) ) {
						$return_array[ $val['ID'] ] = $benchmark;
					}
				}
			}
		}

		return $return_array;
	}

	/**
	 * Get step data by IDs
	 *
	 * @param $step_ids
	 *
	 * @return array|object|stdClass[]|null
	 */
	public static function get_step_data_by_ids( $step_ids ) {
		if ( empty( $step_ids ) ) {
			return [];
		}
		global $wpdb;

		$table_name = self::_table();

		$placeholder = array_fill( 0, count( $step_ids ), '%d' );
		$placeholder = implode( ", ", $placeholder );
		$query       = "SELECT * FROM $table_name WHERE `ID` IN ($placeholder) AND `status` = 1;";
		$query       = $wpdb->prepare( $query, $step_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; ids bound via prepare() %d

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; ids bound via prepare() %d
		return $wpdb->get_results( $query, ARRAY_A );
	}
}
