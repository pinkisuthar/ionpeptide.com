<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Automation_Contact_Trail extends BWFAN_Model {
	static $primary_key = 'ID';

	/**
	 * @param $step_ids
	 * @param $status
	 * @param $after_time
	 *
	 * @return array|false|object|stdClass[]|null
	 */
	public static function get_bulk_step_count( $step_ids = [], $status = 1, $after_time = 0 ) {
		global $wpdb;
		$table_name = self::_table();
		if ( empty( $step_ids ) ) {
			return false;
		}

		$string_placeholder = array_fill( 0, count( $step_ids ), '%d' );
		$placeholder        = implode( ', ', $string_placeholder );

		$args             = array_merge( $step_ids, [ $status ] );
		$after_time_query = '';
		if ( ! empty( $after_time ) ) {
			$after_time_query .= " AND `c_time` > %d ";
			$args[]           = $after_time;
		}

		$query = "SELECT `sid`, COUNT(DISTINCT `tid`) AS `count` FROM {$table_name} WHERE `sid` IN ($placeholder) AND `status` = %d $after_time_query GROUP BY `sid`";

		return $wpdb->get_results( $wpdb->prepare( $query, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; generated %d placeholders; prepared at call
	}

	/**
	 * Get counts for all statuses (completed, queued, failed, skipped) in a single query.
	 * Replaces 4 separate get_bulk_step_count() calls with 1 query.
	 *
	 * @param array $step_ids List of step IDs.
	 *
	 * @return array Map of sid => array( 'completed' => int, 'queued' => int, 'failed' => int, 'skipped' => int ).
	 */
	public static function get_all_step_status_counts( $step_ids = array() ) {
		global $wpdb;
		$table_name = self::_table();

		if ( empty( $step_ids ) ) {
			return array();
		}

		$step_ids     = array_map( 'absint', $step_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $step_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; %d placeholders generated; ids absint()ed + spread
		$query = $wpdb->prepare(
			"SELECT `sid`,
				COUNT( DISTINCT CASE WHEN `status` = 1 THEN `tid` END ) AS completed,
				COUNT( DISTINCT CASE WHEN `status` = 2 THEN `tid` END ) AS queued,
				COUNT( DISTINCT CASE WHEN `status` = 3 THEN `tid` END ) AS failed,
				COUNT( DISTINCT CASE WHEN `status` = 4 THEN `tid` END ) AS skipped
			FROM {$table_name}
			WHERE `sid` IN ({$placeholders})
			AND `status` IN (1, 2, 3, 4)
			GROUP BY `sid`", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from $wpdb->prefix; %d placeholders generated; ids absint()ed + spread
			...$step_ids
		); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results    = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; %d placeholders generated; ids absint()ed + spread
		if ( empty( $results ) || ! is_array( $results ) ) {
			return [];
		}

		return array_column( $results, null, 'sid' );
	}

	/**
	 * Return table name
	 *
	 * @return string
	 */
	protected static function _table() {
		global $wpdb;

		return $wpdb->prefix . 'bwfan_automation_contact_trail';
	}

	/**
	 * Update all steps trails status to success
	 *
	 * @param $trail_id
	 *
	 * @return false|void
	 */
	public static function update_all_step_trail_status_complete( $trail_id ) {
		if ( empty( $trail_id ) ) {
			return false;
		}

		global $wpdb;
		$table_name = self::_table();
		$query      = $wpdb->prepare( "UPDATE {$table_name} SET `status`= 1 WHERE `tid` = %s AND `status` = 2", $trail_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()

		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()
	}

	/**
	 * Get step trail data
	 *
	 * @param $trail_id
	 * @param $step_id
	 *
	 * @return array|false|object|void|null
	 */
	public static function get_step_trail( $trail_id, $step_id ) {
		global $wpdb;
		$table_name = self::_table();

		if ( empty( $trail_id ) || empty( $step_id ) ) {
			return false;
		}

		$query = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE `tid` = %s AND `sid` = %d ORDER BY ID DESC LIMIT 0,1", $trail_id, $step_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()

		return $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()
	}

	/**
	 * Get steps 'completed contacts' data
	 *
	 * @param $aid
	 * @param $step_id
	 * @param $queued
	 * @param $offset
	 * @param $limit
	 *
	 * @return array
	 */
	public static function get_step_completed_contacts( $aid, $step_id, $type = '', $offset = 0, $limit = 25, $path = 0, $data_key = 'path' ) {
		global $wpdb;
		$table_name = self::_table();
		switch ( $type ) {
			case 'queued':
				$status = 2;
				break;
			case 'skipped':
				$status = 4;
				break;
			case 'failed':
				$status = 3;
				break;
			default:
				$status = 1;
				break;
		}

		$is_desc = apply_filters( 'bwfan_step_automation_contact_trail_is_desc', true );
		$order_by = (true === $is_desc) ? 'DESC' : 'ASC';

		if ( ! empty( $path ) ) {
			/** Whitelist the JSON key — `path` (split) or `branch` (branches) */
			$data_key     = 'branch' === $data_key ? 'branch' : 'path';
			/** Branch matches anchor to the JSON start - a floating LIKE would also hit branch ids inside the `errors` array */
			$path_pattern = 'branch' === $data_key ? '{"branch":"' . $wpdb->esc_like( $path ) . '"%' : '%"path":"' . $wpdb->esc_like( $path ) . '"%';
			$sub_query    = $wpdb->prepare( "JOIN ( SELECT tid, MAX(c_time) AS latest_time FROM {$table_name} WHERE aid = %d AND sid = %d AND status = %d AND data LIKE %s GROUP BY tid ORDER BY latest_time {$order_by} LIMIT %d OFFSET %d ) AS latest ON ct.tid = latest.tid AND ct.c_time = latest.latest_time ", $aid, $step_id, $status, $path_pattern, $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- order_by whitelisted to DESC/ASC; sub_query built with $wpdb->prepare()
		} else {
			$sub_query = $wpdb->prepare( "JOIN ( SELECT tid, MAX(c_time) AS latest_time FROM {$table_name} WHERE aid = %d AND sid = %d AND status = %d GROUP BY tid ORDER BY latest_time {$order_by} LIMIT %d OFFSET %d ) AS latest ON ct.tid = latest.tid AND ct.c_time = latest.latest_time ", $aid, $step_id, $status, $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- order_by whitelisted to DESC/ASC; sub_query built with $wpdb->prepare()
		}
		$query    = $wpdb->prepare( "SELECT ct.ID, ct.cid, ct.aid, ct.data, ct.tid, ct.c_time, c.email, c.f_name, c.l_name, c.contact_no FROM {$table_name} as ct {$sub_query} JOIN {$wpdb->prefix}bwf_contact AS c ON ct.cid = c.ID WHERE ct.sid = %d ORDER BY ct.ID DESC ", $step_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- order_by whitelisted to DESC/ASC; sub_query built with $wpdb->prepare()
		$contacts = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- order_by whitelisted to DESC/ASC; sub_query built with $wpdb->prepare()

		return [
			'contacts' => $contacts,
			'total'    => self::get_step_count( $step_id, $status, $path, $data_key )
		];
	}

	/**
	 * Get step count, complete or wait both
	 *
	 * @param $step_id
	 * @param $completed
	 *
	 * @return string|null
	 */
	public static function get_step_count( $step_id, $status = true, $path = '', $data_key = 'path' ) {
		global $wpdb;
		$table_name = self::_table();
		$status     = ( true === $status ) ? 1 : $status;

		$query = $wpdb->prepare( " SELECT COUNT(*) AS `count` FROM {$table_name} as ct JOIN ( SELECT tid,MAX(c_time) AS latest_time FROM {$table_name} WHERE sid = %d AND status = %d  GROUP BY tid ) AS latest ON ct.tid = latest.tid AND ct.c_time = latest.latest_time  WHERE ct.sid = %d", $step_id, $status, $step_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()
		if ( ! empty( $path ) ) {
			/** Whitelist the JSON key — `path` (split) or `branch` (branches) */
			$data_key     = 'branch' === $data_key ? 'branch' : 'path';
			/** Branch matches anchor to the JSON start - a floating LIKE would also hit branch ids inside the `errors` array */
			$path_pattern = 'branch' === $data_key ? '{"branch":"' . $wpdb->esc_like( $path ) . '"%' : '%"path":"' . $wpdb->esc_like( $path ) . '"%';
			$query        .= $wpdb->prepare( " AND `data` LIKE %s ", $path_pattern );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prepared base + prepared LIKE fragment; table from $wpdb->prefix
		return intval( $wpdb->get_var( $query ) );
	}

	/**
	 * Get step trails for automation contact
	 *
	 * @param $tid
	 *
	 * @return array|object|null
	 */
	public static function get_trail( $tid ) {
		global $wpdb;
		$table_name = self::_table();

		$query = $wpdb->prepare( "SELECT tr.*, st.type, st.data AS step_data, st.action, st.status AS step_status FROM {$table_name} AS tr LEFT JOIN {$wpdb->prefix}bwfan_automation_step AS st ON tr.sid = st.ID  WHERE tid = %s ORDER BY tr.c_time ASC", $tid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()
	}

	public static function delete_automation_trail_by_id( $aid ) {
		global $wpdb;
		$table_name = self::_table();

		$where = "aid = %d";
		$args  = array( $aid );
		if ( is_array( $aid ) ) {
			$aid = array_map( 'absint', $aid );
			if ( empty( $aid ) ) {
				return false;
			}
			$placeholders = implode( ', ', array_fill( 0, count( $aid ), '%d' ) );
			$where        = "aid IN ({$placeholders})";
			$args         = $aid;
		}

		$query = $wpdb->prepare( "DELETE FROM {$table_name} WHERE {$where}", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- where is generated %d placeholders; ids absint()ed

		return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- where is generated %d placeholders; ids absint()ed
	}

	public static function delete_row_by_trail_by( $trail_id ) {
		global $wpdb;
		$table_name = self::_table();

		$query = $wpdb->prepare( "DELETE FROM $table_name WHERE tid = %s", $trail_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()

		return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()
	}

	public static function update_multiple_trail_status( $tids, $sid, $status = 1 ) {
		if ( empty( $tids ) || ! is_array( $tids ) ) {
			return false;
		}

		/** One placeholder per trail id: the ids used to be imploded into a single %s, so with 2+ ids nothing matched */
		$args         = array_map( 'strval', array_values( $tids ) );
		$placeholders = implode( ', ', array_fill( 0, count( $args ), '%s' ) );

		global $wpdb;
		$table_name = self::_table();
		$query      = "UPDATE {$table_name} SET `status`= " . intval( $status ) . " WHERE `tid` IN ($placeholders) ";

		if ( absint( $sid ) > 0 ) {
			$query  .= " AND `sid` = %d ";
			$args[] = $sid;
		}

		$query = $wpdb->prepare( $query, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- status intval()ed; tids and sid bound via placeholders

		return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query prepared above; status intval()ed
	}

	/**
	 * Delete trail if already exist for specific step id
	 *
	 * @param $arr
	 *
	 * @return void
	 */
	public static function delete_if_trail_exists( $arr ) {
		if ( ! is_array( $arr ) || ! isset( $arr['tid'] ) || ! isset( $arr['aid'] ) || ! isset( $arr['cid'] ) || ! isset( $arr['sid'] ) ) {
			return;
		}

		global $wpdb;
		$table_name = self::_table();

		$query = $wpdb->prepare( "SELECT COUNT(*) AS `count` FROM {$table_name} WHERE `tid` = %s AND `cid` = %d AND `aid` = %d AND `sid` = %d", $arr['tid'], $arr['cid'], $arr['aid'], $arr['sid'] ); //phpcs:ignore WordPress.DB.PreparedSQL
		$count = BWFAN_Model_Automation_Contact_Trail::get_var( $query );
		if ( intval( $count ) > 0 ) {
			/** Delete existing */
			$query = $wpdb->prepare( "DELETE FROM {$table_name} WHERE `tid` = %s AND `cid` = %d AND `aid` = %d AND `sid` = %d", $arr['tid'], $arr['cid'], $arr['aid'], $arr['sid'] ); //phpcs:ignore WordPress.DB.PreparedSQL
			BWFAN_Model_Automation_Contact_Trail::query( $query );
		}
	}

	/**
	 * Get completed email step count
	 *
	 * @param $sids
	 *
	 * @return int
	 */
	public static function get_completed_email_steps_count( $sids ) {
		global $wpdb;
		$table_name = self::_table();

		$string_placeholder = array_fill( 0, count( $sids ), '%d' );
		$placeholder        = implode( ', ', $string_placeholder );
		$args               = array_merge( $sids, [ 1 ] );
		$query              = $wpdb->prepare( "SELECT COUNT(ID) FROM {$table_name} WHERE `sid` IN ($placeholder) AND `status` = %d ", $args ); //phpcs:ignore WordPress.DB.PreparedSQL

		return intval( $wpdb->get_var( $query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- generated %d placeholders; values bound via prepare
	}

	/**
	 * Get path's contact count
	 *
	 * @param $split_id
	 * @param $path
	 *
	 * @return string|null
	 */
	public static function get_path_contact_count( $split_id, $path ) {
		global $wpdb;
		$table_name = self::_table();
		$path       = '%"path":"' . $wpdb->esc_like( $path ) . '"%';

		$query = $wpdb->prepare( "SELECT count(`sid`) as `count` FROM {$table_name} WHERE `sid`= %d AND `data` LIKE %s GROUP BY `sid`", intval( $split_id ), $path ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; values bound in $wpdb->prepare()
	}

	/**
	 * Per-branch contact counts for branches steps - ONE query for
	 * all steps instead of one per chip. process_branches() records
	 * routing JSON ({"branch":"<id>"}, plus optional trimmed errors) in the
	 * trail `data` column with `sid` = the branches step's DB id; the branch
	 * id is extracted in SQL from the JSON's leading key, so each step's
	 * trail is scanned once regardless of chip count and eval-error entries
	 * can never inflate a sibling branch's count.
	 *
	 * @param int[] $step_ids Branches (branches) DB step ids.
	 *
	 * @return array map of step id => [ branch id => contact count ]
	 */
	public static function get_branch_contact_counts( $step_ids ) {
		global $wpdb;
		$step_ids = array_filter( array_map( 'intval', (array) $step_ids ) );
		if ( empty( $step_ids ) ) {
			return array();
		}
		$table_name   = self::_table();
		$placeholders = implode( ',', array_fill( 0, count( $step_ids ), '%d' ) );
		/**
		 * Branch id extracted in SQL: routing JSON always starts `{"branch":"`
		 * (11 chars), so the id is the text from position 12 up to the next
		 * quote. Grouping by it (instead of raw `data`) keeps the result at
		 * one row per branch even when contact-specific `errors` payloads make
		 * every row's JSON distinct, and string ops stay safe on legacy rows
		 * with truncated/invalid JSON.
		 *
		 * COUNT(DISTINCT `tid`) — not COUNT(*) — so a chip counts contact runs,
		 * the same unit get_all_step_status_counts() uses for the parent step's
		 * card. A jump back-edge can loop one contact through the branches step
		 * more than once in a single trail; counting rows made the chips sum to
		 * more than the step they hang off. `status` = 1 matches that card's
		 * completed bucket too.
		 */
		$query = $wpdb->prepare( "SELECT `sid`, SUBSTRING_INDEX( SUBSTRING( `data`, 12 ), '\"', 1 ) as `branch`, COUNT( DISTINCT `tid` ) as `count` FROM {$table_name} WHERE `sid` IN ( {$placeholders} ) AND `status` = 1 AND `data` LIKE '{\"branch\":\"%'  GROUP BY `sid`, `branch`", ...$step_ids ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN() placeholders are built at runtime from intval'd step ids so the sniff cannot see them; the LIKE prefix is a constant with no user input.; ids intval()ed; generated %d placeholders spread into prepare
		$rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- ids intval()ed; generated %d placeholders spread into prepare

		$counts = array();
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $row['branch'] ) || '' === $row['branch'] ) {
				continue;
			}
			$counts[ intval( $row['sid'] ) ][ (string) $row['branch'] ] = intval( $row['count'] );
		}

		return $counts;
	}


}
