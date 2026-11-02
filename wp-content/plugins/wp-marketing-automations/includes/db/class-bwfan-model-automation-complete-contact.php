<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Automation_Complete_Contact extends BWFAN_Model {
	static $primary_key = 'ID';

	public static function get_automation_completed_contacts( $aid, $offset = 0, $limit = 25 ) {
		global $wpdb;
		$table_name = self::_table();

		$query = $wpdb->prepare( "SELECT cc.ID,cc.trail as tid, cc.cid, cc.aid, cc.c_date AS c_time, c.email, c.f_name, c.l_name, c.contact_no FROM {$table_name} as cc JOIN {$wpdb->prefix}bwf_contact AS c ON cc.cid = c.ID WHERE 1 = 1 AND cc.aid = %d ORDER BY cc.c_date DESC LIMIT %d OFFSET %d", absint( $aid ), absint( $limit ), absint( $offset ) ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$contacts = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above

		$contacts = array_map( function ( $contact ) {
			$contact['c_time'] = strtotime( $contact['c_time'] );

			return $contact;
		}, $contacts );

		return [
			'contacts' => $contacts,
			'total'    => self::get_complete_count( $aid )
		];
	}

	/**
	 * Return table name
	 *
	 * @return string
	 */
	protected static function _table() {
		global $wpdb;

		return $wpdb->prefix . 'bwfan_automation_complete_contact';
	}

	public static function get_complete_count( $aid = 0, $search = '' ) {
		global $wpdb;
		$table_name = self::_table();

		$where = '';
		$join  = '';
		$args  = [];
		if ( ! empty( $aid ) ) {
			$where  .= " AND cc.aid = %d";
			$args[] = $aid;
		}

		if ( ! empty( $search ) ) {
			$join   = " JOIN {$wpdb->prefix}bwf_contact AS c ON cc.cid = c.ID ";
			$where  .= " AND ( c.f_name LIKE %s OR c.l_name LIKE %s OR c.email LIKE %s ) ";
			$args[] = "%$search%";
			$args[] = "%$search%";
			$args[] = "%$search%";
		}

		$query = $wpdb->prepare( "SELECT COUNT(*) AS `count` FROM {$table_name} AS cc $join WHERE 1 = 1 $where", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- hardcoded fragments; aid and search bound via prepare()

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded fragments; aid and search bound via prepare()
	}

	public static function get_contacts_journey( $aid, $search = '', $limit = 10, $offset = 0, $contact_with_count = false, $more_data = false, $type = '', $cid = 0 ) {
		$where = '';
		if ( ! empty( $aid ) ) {
			$where .= " AND cc.aid = " . absint( $aid ) . " ";
		}

		if ( ! empty( $cid ) ) {
			$where .= " AND cc.cid = " . absint( $cid ) . " ";
		}

		$contacts = self::get_contacts( $where, $search, $limit, $offset, $more_data, $type );
		if ( true === $contact_with_count ) {
			return [
				'contacts' => $contacts,
				'total'    => self::get_complete_count( $aid, $search )
			];
		}

		return $contacts;
	}

	public static function get_contacts( $where, $search, $limit, $offset, $more_data = false, $status = '', $only_total = false ) {
		global $wpdb;
		$table_name = self::_table();
		$limit      = " LIMIT " . absint( $limit ) . " OFFSET " . absint( $offset );
		if ( ! empty( $search ) ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( " AND ( c.f_name LIKE %s OR c.l_name LIKE %s OR c.email LIKE %s )", $like, $like, $like );
		}
		if ( true === $only_total ) {
			$query = "SELECT  COUNT(cc.ID) FROM $table_name as cc JOIN {$wpdb->prefix}bwf_contact AS c ON cc.cid = c.ID WHERE 1=1 $where ";

			return intval( $wpdb->get_var( $query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- where from absint/prepare in-file callers; limit absint; search prepared
		}

		$query    = "SELECT  cc.ID,cc.cid, cc.aid, cc.trail, cc.c_date, c.email, c.f_name, c.l_name, c.contact_no, cc.s_date FROM $table_name as cc JOIN {$wpdb->prefix}bwf_contact AS c ON cc.cid = c.ID WHERE 1 = 1 $where ORDER BY cc.c_date DESC $limit";
		$contacts = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- where from absint/prepare in-file callers; limit absint; search prepared

		if ( empty( $contacts ) ) {
			return $contacts;
		}

		/** Batch fetch: error messages for failed contacts (status = 2) */
		$fail_data_map = array();
		if ( 2 === intval( $status ) && $more_data ) {
			$fail_data_map = self::batch_get_fail_data( $contacts, $wpdb );
		}

		/** Batch fetch: step data when more_data is requested */
		$step_data_map = array();
		if ( true === $more_data ) {
			$trail_ids = array_unique( array_filter( wp_list_pluck( $contacts, 'trail' ) ) );
			if ( ! empty( $trail_ids ) ) {
				$step_data_map = self::batch_get_step_data( $trail_ids, $wpdb );
			}
		}

		return array_map( function ( $contact ) use ( $more_data, $status, $fail_data_map, $step_data_map ) {
			$contact['e_time'] = $contact['c_date'];
			$contact['c_date'] = $contact['s_date'];
			unset( $contact['s_date'] );

			if ( true === $more_data ) {
				$contact['data'] = isset( $step_data_map[ $contact['trail'] ] ) ? $step_data_map[ $contact['trail'] ] : array();
			}

			if ( 2 === intval( $status ) && isset( $contact['trail'] ) && isset( $fail_data_map[ $contact['trail'] ] ) ) {
				$contact['error_msg'] = $fail_data_map[ $contact['trail'] ];
			}

			return $contact;
		}, $contacts );
	}

	public static function get_automation_complete_contact_count( $aids ) {
		global $wpdb;
		$table_name = self::_table();

		if ( ! is_array( $aids ) ) {
			$aids = explode( ',', $aids );
		}
		$aids        = array_map( 'absint', $aids );
		$placeholder = implode( ',', array_fill( 0, count( $aids ), '%d' ) );

		$query = $wpdb->prepare( "SELECT aid, count(aid) as count FROM $table_name WHERE aid IN ($placeholder) GROUP BY aid", $aids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- ids absint()ed; %d placeholders generated; prepared

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- ids absint()ed; %d placeholders generated; prepared
	}

	public static function get_automation_contacts( $cid, $search = '', $limit = 10, $offset = 0, $more_data = false, $type = '', $only_total = false ) {
		global $wpdb;
		$cid   = absint( $cid );
		$where = $wpdb->prepare( ' AND cc.cid = %d ', $cid );

		return self::get_contacts( $where, $search, $limit, $offset, $more_data, $type, $only_total );
	}

	public static function get_contact_automation_count( $cid ) {
		global $wpdb;
		$table_name = self::_table();

		$query = $wpdb->prepare( "SELECT COUNT(*) AS `count` FROM {$table_name} WHERE `cid` = %d", $cid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- query built with $wpdb->prepare(); prefix table

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); prefix table
	}

	public static function delete_automation_contact_by_aid( $aid ) {
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

	/**Get status */
	public static function get_status( $status ) {
		switch ( $status ) {
			case 'success':
				$status = 1;
				break;
			case 'wait':
				$status = 2;
				break;
			case 'failed':
				$status = 3;
				break;
		}

		return $status;
	}

	public static function is_contact_completed( $tid ) {
		if ( empty( $tid ) ) {
			return false;
		}
		global $wpdb;
		$table_name = self::_table();

		$query = $wpdb->prepare( "SELECT `ID` FROM {$table_name} WHERE `trail` = %s LIMIT 0,1", $tid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- query built with $wpdb->prepare(); prefix table

		$found = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); prefix table

		return intval( $found ) > 0;
	}

	/** Get all the contacts where automations already ran for the given automation range */
	public static function get_contacts_automation( $aid, $start_date, $end_date = '' ) {
		global $wpdb;
		$table_name = self::_table();

		$args = [ $aid ];
		if ( ! empty( $start_date ) && empty( $end_date ) ) {
			$where  = " AND `c_date` > %s";
			$args[] = $start_date;
		}

		if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
			$where  = " AND `c_date` > %s AND `c_date` < %s";
			$args[] = $start_date;
			$args[] = $end_date;
		}

		$query = $wpdb->prepare( "SELECT DISTINCT `cid` AS contact_id FROM {$table_name} WHERE `aid` = %d $where", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- hardcoded where fragments; values bound via prepare()

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded where fragments; values bound via prepare()
	}

	/**
	 * @param $start_date
	 * @param $end_date
	 * @param $is_interval
	 * @param $interval
	 *
	 * @return array|object|null
	 */
	public static function get_total_contacts( $aid, $start_date, $end_date, $is_interval, $interval ) {
		global $wpdb;
		$table          = self::_table();
		$date_col       = "s_date";
		$interval_query = '';
		$group_by       = '';
		$order_by       = ' ID ';

		if ( 'interval' === $is_interval ) {
			$get_interval   = BWFCRM_Dashboards::get_interval_format_query( $interval, $date_col );
			$interval_query = $get_interval['interval_query'];
			$interval_group = $get_interval['interval_group'];
			$group_by       = "GROUP BY " . $interval_group;
			$order_by       = ' time_interval ';
		}

		$base_query = "SELECT  count(ID) as contact_counts" . $interval_query . "  FROM `" . $table . "` WHERE 1=1 AND aid = " . absint( $aid ) . " AND " . $wpdb->prepare( "`$date_col` >= %s AND `$date_col` <= %s", $start_date, $end_date ) . " AND aid = " . absint( $aid ) . " " . $group_by . " ORDER BY " . $order_by . " ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- interval SQL comes from Pro BWFCRM_Dashboards helper; other values absint()ed

		return $wpdb->get_results( $base_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- interval SQL comes from Pro BWFCRM_Dashboards helper; other values absint()ed
	}

	public static function get_row_by_trail_id( $trail_id ) {
		global $wpdb;
		$table_name = self::_table();

		$query = $wpdb->prepare( "SELECT * FROM $table_name WHERE trail = %s LIMIT 0,1", $trail_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- query built with $wpdb->prepare(); prefix table

		return $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); prefix table
	}

	/**
	 * Get automation complete count
	 *
	 * @param $cid
	 * @param $aid
	 *
	 * @return string|null
	 */
	public static function get_automation_count_by_cid( $cid, $aid ) {
		global $wpdb;

		$table = self::_table();
		$query = $wpdb->prepare( 'SELECT COUNT(`aid`) as `count` FROM ' . $table . ' WHERE `cid` = %d AND `aid` = %d ORDER BY `ID` DESC', $cid, $aid ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prefix table; ids bound via prepare() %d

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix table; ids bound via prepare() %d
	}

	/**
	 * Check if contact recently (5 mins) completed, considering duplicate case
	 *
	 * @param $data
	 *
	 * @return bool
	 * @throws Exception
	 */
	public static function check_duplicate_automation_contact( $data, $wait_time = 5 ) {
		if ( empty( $data ) ) {
			return false;
		}
		$datetime = new DateTime( date( 'Y-m-d H:i:s', strtotime( $data['c_date'] ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- strtotime($string) round-trip into tz-less DateTime; gmdate would shift c_date
		$c_date   = $datetime->modify( "-$wait_time mins" )->format( 'Y-m-d H:i:s' );

		global $wpdb;

		$query = "SELECT `ID` FROM `{$wpdb->prefix}bwfan_automation_complete_contact` WHERE `cid` = %d AND `aid` = %d AND `event` = %s  AND `data` = %s AND `s_date` >= %s LIMIT 1";

		return intval( $wpdb->get_var( $wpdb->prepare( $query, $data['cid'], $data['aid'], $data['event'], $data['data'], $c_date ) ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded query; values bound via prepare()
	}

	/**
	 * Check if contact has completed the automation for a particular order related event
	 *
	 * @param $aid
	 * @param $cid
	 * @param $order_id
	 * @param $single_item
	 * @param $event
	 *
	 * @return bool
	 */
	public static function is_contact_with_same_order( $aid, $cid, $order_id, $single_item = 0, $event = 'wc_new_order' ) {
		global $wpdb;
		/** Both ids are placed inside LIKE literals below */
		$order_id    = intval( $order_id );
		$single_item = intval( $single_item );

		$like1 = '%"order_id":"' . $order_id . '"%';
		$like2 = '%"order_id":' . $order_id . '%';
		$data  = "( `data` LIKE '$like1' OR `data` LIKE '$like2' )";
		if ( ! empty( $single_item ) ) {
			$like1 = '%"wc_single_item_id":"' . $single_item . '"%';
			$like2 = '%"wc_single_item_id":' . $single_item . '%';
			$data  .= " AND (`data` LIKE '$like1' OR `data` LIKE '$like2')";
		}

		$query = "SELECT `ID`, `data` FROM `{$wpdb->prefix}bwfan_automation_complete_contact` WHERE `cid` = %d AND `aid` = %d AND `event` = %s  AND $data ORDER BY `ID` DESC LIMIT 0,1";
		$res   = $wpdb->get_row( $wpdb->prepare( $query, $cid, $aid, $event ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- order and item ids intval()ed above; remaining values prepared
		$data  = isset( $res['data'] ) ? json_decode( $res['data'], true ) : [];
		if ( empty( $data ) ) {
			return false;
		}

		$order_validation = false;
		if ( isset( $data['global']['order_id'] ) && ( intval( $order_id ) === intval( $data['global']['order_id'] ) ) ) {
			$order_validation = true;
		}

		if ( empty( $single_item ) || false === $order_validation ) {
			return $order_validation;
		}

		return ( isset( $data['global']['wc_single_item_id'] ) && ( intval( $single_item ) === intval( $data['global']['wc_single_item_id'] ) ) );
	}

	/**
	 * Get automation data by trail
	 *
	 * @param $trail_id
	 *
	 * @return array|mixed
	 */
	public static function get_data_by_trail( $trail_id ) {
		$table_name = self::_table();
		global $wpdb;

		$query = $wpdb->prepare( "SELECT `data` FROM $table_name WHERE trail = %s LIMIT 0,1", $trail_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- query built with $wpdb->prepare(); prefix table
		$data  = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); prefix table

		return ! empty( $data ) ? json_decode( $data, true ) : [];
	}

	/**
	 * Batch fetch error messages for failed contacts.
	 * Replaces N individual get_step_trail() calls with 1 query.
	 *
	 * @param array  $contacts List of contact rows (must have 'trail' and 'last' keys).
	 * @param object $wpdb     WordPress database object.
	 *
	 * @return array Map of trail_id => error_msg.
	 */
	private static function batch_get_fail_data( $contacts, $wpdb ) {
		$fail_data_map = array();
		$trail_table   = $wpdb->prefix . 'bwfan_automation_contact_trail';

		/** Collect (trail, last) pairs for failed contacts */
		$where_parts = array();
		$args        = array();
		foreach ( $contacts as $contact ) {
			if ( empty( $contact['trail'] ) || empty( $contact['last'] ) ) {
				continue;
			}
			$where_parts[] = '(t2.tid = %s AND t2.sid = %d)';
			$args[]        = $contact['trail'];
			$args[]        = intval( $contact['last'] );
		}

		if ( empty( $where_parts ) ) {
			return $fail_data_map;
		}

		$where_clause = implode( ' OR ', $where_parts );

		/**
		 * Get the latest trail row per (tid, sid) pair in a single query.
		 * Uses a subquery to find MAX(ID) per group, then joins back for full row.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- hardcoded where parts; values spread into prepare(), counts match
		$query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- hardcoded where parts; values spread into prepare(), counts match
			"SELECT t.tid, t.data FROM {$trail_table} AS t
			INNER JOIN (
				SELECT tid, sid, MAX(ID) AS max_id FROM {$trail_table} AS t2
				WHERE {$where_clause}
				GROUP BY tid, sid
			) AS latest ON t.ID = latest.max_id", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- hardcoded where parts; values spread into prepare(), counts match
			...$args
		); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded where parts; values spread into prepare(), counts match

		foreach ( $results as $row ) {
			if ( empty( $row['data'] ) ) {
				continue;
			}
			$data = json_decode( $row['data'], true );
			if ( isset( $data['error_msg'] ) ) {
				$fail_data_map[ $row['tid'] ] = $data['error_msg'];
			}
		}

		return $fail_data_map;
	}

	/**
	 * Batch fetch step data for multiple trail IDs.
	 * Replaces N individual get_step_by_trail() calls with 1 query.
	 *
	 * @param array  $trail_ids List of trail IDs.
	 * @param object $wpdb      WordPress database object.
	 *
	 * @return array Map of trail_id => step data array.
	 */
	private static function batch_get_step_data( $trail_ids, $wpdb ) {
		$step_data_map = array();
		$trail_table   = $wpdb->prefix . 'bwfan_automation_contact_trail';
		$step_table    = $wpdb->prefix . 'bwfan_automation_step';
		$placeholders  = implode( ',', array_fill( 0, count( $trail_ids ), '%s' ) );

		/**
		 * Get the latest trail+step row per trail_id in a single query.
		 * Replicates the logic of BWFAN_Model_Automation_Step::get_step_by_trail() in batch.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix tables; placeholders generated; ids spread into prepare()
		$query = $wpdb->prepare(
			"SELECT ct.tid, ct.c_time AS run_time, st.action, st.type
			FROM {$trail_table} AS ct
			JOIN {$step_table} AS st ON ct.sid = st.ID
			INNER JOIN (
				SELECT tid, MAX(ID) AS max_id FROM {$trail_table}
				WHERE tid IN ({$placeholders})
				GROUP BY tid
			) AS latest ON ct.ID = latest.max_id", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- prefix tables; placeholders generated; ids spread into prepare()
			...$trail_ids
		); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix tables; placeholders generated; ids spread into prepare()

		foreach ( $results as $row ) {
			$tid              = $row['tid'];
			$row['run_time']  = date( 'Y-m-d H:i:s', $row['run_time'] ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
			$action           = ! empty( $row['action'] ) ? json_decode( $row['action'], true ) : array();
			$nice_name        = '';

			if ( 2 === absint( $row['type'] ) ) {
				$nice_name = isset( $action['action'] ) && BWFAN_Core()->integration->get_action( $action['action'] ) instanceof BWFAN_Action ? BWFAN_Core()->integration->get_action( $action['action'] )->get_name() : '';
			} elseif ( 3 === absint( $row['type'] ) ) {
				$nice_name = isset( $action['benchmark'] ) && BWFAN_Core()->sources->get_event( $action['benchmark'] ) instanceof BWFAN_Event ? BWFAN_Core()->sources->get_event( $action['benchmark'] )->get_name() : '';
			}

			$row['action'] = $nice_name;
			unset( $row['tid'] );

			$step_data_map[ $tid ] = $row;
		}

		return $step_data_map;
	}
}
