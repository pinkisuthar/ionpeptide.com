<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Automation_Contact extends BWFAN_Model {
	static $primary_key = 'ID';

	/**
	 * Override BWFAN_Model::update() to retry on InnoDB deadlocks.
	 *
	 * `wp_bwfan_automation_contact` rows are written from up to 5 concurrent
	 * Action Scheduler workers (controller progression, claim release, status
	 * sweeps, goal updates), which causes recurring deadlocks under load.
	 * Retry up to 3 times with 100/200/300ms backoff; non-deadlock errors
	 * return immediately. Other models keep the abstract's raw $wpdb->update.
	 *
	 * @param array $data
	 * @param array $where
	 * @return int|false Rows affected, or false on non-deadlock failure / retry exhaustion.
	 */
	public static function update( $data, $where ) {
		global $wpdb;

		$table       = self::_table();
		$max_retries = 3;

		for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
			if ( $attempt > 0 ) {
				BWFAN_Common::log_test_data( 'BWFAN: Deadlock on automation_contact update, retry attempt ' . $attempt . ' of ' . $max_retries, 'fka-db-deadlock', true );
				usleep( $attempt * 100000 );
			}

			/** Suppress wpdb's native error logging; deadlocks are handled here and logged via fka-db-deadlock. last_error stays populated for detection below. */
			$suppress = $wpdb->suppress_errors( true );
			$result   = $wpdb->update( $table, $data, $where ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL
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

	/**
	 * Override BWFAN_Model::insert() to retry on InnoDB deadlocks.
	 *
	 * The contact-queueing INSERT (BWFAN_Event::handle_automation_run_v2) runs
	 * synchronously inside the WC payment transaction and competes for row/index
	 * locks on `wp_bwfan_automation_contact`, producing recurring deadlocks under
	 * concurrent checkout load. The abstract insert is a bare $wpdb->insert with
	 * no retry, so a deadlocked INSERT is silently dropped and the contact never
	 * enters the automation. Retry up to 3 times with 100/200/300ms backoff;
	 * non-deadlock errors return immediately. Mirrors self::update().
	 *
	 * @param array $data
	 * @return int|false Rows inserted, or false on non-deadlock failure / retry exhaustion.
	 */
	public static function insert( $data ) {
		global $wpdb;

		$table       = self::_table();
		$max_retries = 3;

		for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
			if ( $attempt > 0 ) {
				BWFAN_Common::log_test_data( 'BWFAN: Deadlock on automation_contact insert, retry attempt ' . $attempt . ' of ' . $max_retries, 'fka-db-deadlock', true );
				usleep( $attempt * 100000 );
			}

			/** Suppress wpdb's native error logging; deadlocks are handled here and logged via fka-db-deadlock. last_error stays populated for detection below. */
			$suppress = $wpdb->suppress_errors( true );
			$result   = $wpdb->insert( $table, $data ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL
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

	public static function get_data( $id ) {
		global $wpdb;

		$table = self::_table();
		$query = $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE `ID` = %d', $id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; ids bound via prepare()

		return $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; ids bound via prepare()
	}

	/**
	 * Returns table name
	 *
	 * @return string
	 */
	protected static function _table() {
		global $wpdb;

		return $wpdb->prefix . 'bwfan_automation_contact';
	}

	public static function get_automation_contact( $automation_id, $contact_id ) {
		global $wpdb;

		$table = self::_table();
		$query = $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE `aid` = %d AND `cid` = %d ORDER BY `ID` DESC LIMIT 0,1', $automation_id, $contact_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; ids bound via prepare()

		return $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; ids bound via prepare()
	}

	/**
	 * Get Automation Contact by abandoned id
	 *
	 * */
	public static function get_automation_contact_by_ab_id( $ab_id, $cid ) {
		global $wpdb;

		$table        = self::_table();
		$abandoned_id = '%' . $wpdb->esc_like( '"cart_abandoned_id":"' . intval( $ab_id ) . '"' ) . '%';
		$query        = $wpdb->prepare( "SELECT * FROM $table WHERE `cid` = %d AND `data` LIKE %s", $cid, $abandoned_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- id intval()ed and LIKE value bound via %s with esc_like()

		return $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query prepared above
	}

	/**
	 * Resolve the automation id (aid) that ran for an abandoned cart.
	 *
	 * The abandoned cart table has no automation_id column, so the id is read
	 * from the automation contact row whose `data` JSON references the cart's
	 * `cart_abandoned_id`. The contact may still be active or already completed
	 * (moved to the complete table), so both tables are checked. Returns 0 when
	 * no automation ran for this cart.
	 *
	 * The lookup is limited to the given contacts so it stays on the `cid` index:
	 * the complete table grows without bound and `data` cannot be indexed.
	 *
	 * @param int|string $ab_id Abandoned cart row id.
	 * @param int[] $cids Contact ids the cart can belong to.
	 *
	 * @return int
	 */
	public static function get_automation_id_by_ab_id( $ab_id, $cids ) {
		global $wpdb;

		$ab_id = absint( $ab_id );
		$cids  = array_values( array_unique( array_filter( array_map( 'absint', (array) $cids ) ) ) );
		if ( 0 === $ab_id || empty( $cids ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $cids ), '%d' ) );
		$args         = array_merge( $cids, [ '%' . $wpdb->esc_like( '"cart_abandoned_id":"' . $ab_id . '"' ) . '%' ] );
		foreach ( [ self::_table(), $wpdb->prefix . 'bwfan_automation_complete_contact' ] as $table ) {
			$aid = $wpdb->get_var( $wpdb->prepare( "SELECT `aid` FROM $table WHERE `cid` IN ($placeholders) AND `data` LIKE %s ORDER BY `ID` DESC LIMIT 0,1", ...$args ) ); //phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $table is one of two fixed plugin tables and $placeholders is a run of %d, one per cid
			if ( ! empty( $aid ) ) {
				return absint( $aid );
			}
		}

		return 0;
	}

	/**
	 * Check if any contact is in automation to execute
	 *
	 * @return bool
	 */
	public static function maybe_can_execute() {
		global $wpdb;
		$time  = current_time( 'timestamp', 1 );
		$table = self::_table();
		$query = $wpdb->prepare( "SELECT MAX(`ID`) FROM {$table} WHERE `e_time` < %s AND `status` IN (1,6)", $time ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; values bound via prepare()
		$count = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; values bound via prepare()
		if ( empty( $count ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if contact is active in the automation
	 *
	 * @param $cid
	 * @param $aid
	 *
	 * @return bool
	 */
	public static function maybe_contact_in_automation( $cid, $aid ) {
		global $wpdb;
		$table = self::_table();
		$query = $wpdb->prepare( "SELECT MAX(`ID`) FROM {$table} WHERE `cid` = %d AND `aid` = %d AND `status` IN (%d,%d,%d) ", $cid, $aid, 1, 4, 6 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; values bound via prepare()
		$count = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; values bound via prepare()
		if ( empty( $count ) ) {
			return false;
		}

		return true;
	}

	public static function get_contacts_journey( $aid, $search = '', $limit = 10, $offset = 0, $contact_with_count = false, $more_data = false, $status = '', $where = '', $cid = 0 ) {
		if ( ! empty( $aid ) ) {
			$where .= " AND cc.aid = " . absint( $aid ) . " ";
		}

		if ( ! empty( $cid ) ) {
			$where .= " AND cc.cid = " . absint( $cid ) . " ";
		}
		$order_by = "ORDER BY cc.c_date DESC ";

		/** If automation is inactive then no need to check automation contact status */
		if ( ! empty( $status ) && 'inactive' !== $status ) {
			if ( 'active' === $status ) {
				$is_desc = apply_filters( 'bwfan_active_automation_contact_is_desc', false );
				$order_by = (true === $is_desc) ? 'DESC' : 'ASC';
				$where    .= " AND (cc.status = 1 OR cc.status = 4 OR cc.status = 6 ) ";
				$order_by = " ORDER BY cc.c_date {$order_by}, cc.e_time {$order_by} ";
			} elseif ( 'delayed' === $status ) {
				$where    .= " AND (cc.status = 1 OR cc.status = 6 ) ";
				$order_by = " ORDER BY cc.e_time ASC ";
			} else {
				$status = self::get_status( $status );
				$where  .= " AND cc.status = " . intval( $status );
			}
		}

		if ( 'inactive' === $status || 2 === intval( $status ) || 3 === intval( $status ) ) {
			$order_by = " ORDER BY cc.last_time DESC ";
		}

		$automation_status = 'inactive' === $status ? " AND am.status = 2" : " AND am.status = 1";
		global $wpdb;
		$where    .= " AND (EXISTS ( SELECT 1 FROM {$wpdb->prefix}bwfan_automations AS am WHERE am.ID = cc.aid $automation_status ) )";
		$contacts = self::get_contacts( $where, $search, $limit, $offset, $more_data, $status, false, $order_by );
		if ( true === $contact_with_count ) {
			return [
				'contacts' => $contacts,
				'total'    => self::get_active_count( $aid, $status, $search, $cid, $where )
			];
		}

		return $contacts;
	}


	/**Get status */
	public static function get_status( $status ) {
		switch ( $status ) {
			case 'active':
				$status = 1;
				break;
			case 'failed':
				$status = 2;
				break;
			case 'paused':
				$status = 3;
				break;
			case 'wait':
				$status = 4;
				break;
			case 'terminate':
				$status = 5;
				break;
			case 'Retry':
				$status = 6;
				break;
		}

		return $status;
	}

	public static function get_contacts( $where, $search, $limit, $offset, $more_data, $status = '', $only_total = false, $order_by = '' ) {
		global $wpdb;
		$table_name = self::_table();
		$limit      = " LIMIT " . absint( $limit ) . " OFFSET " . absint( $offset );
		if ( ! empty( $search ) ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( " AND ( c.f_name LIKE %s OR c.l_name LIKE %s OR c.email LIKE %s )", $like, $like, $like );
		}

		if ( true === $only_total ) {
			$query = "SELECT  COUNT(cc.ID) FROM $table_name as cc JOIN {$wpdb->prefix}bwf_contact AS c ON cc.cid = c.ID WHERE 1=1 $where  ORDER BY cc.c_date DESC ";

			return intval( $wpdb->get_var( $query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- where parts absint()ed/hardcoded/prepared; limit absint()ed
		}

		$more_columns = '';
		if ( true === $more_data ) {
			$more_columns = ", cc.e_time, cc.status, cc.last ";
		}
		$order_by = ! empty( $order_by ) ? $order_by : "ORDER BY cc.c_date DESC";

		$query    = "SELECT  cc.ID,cc.cid, cc.aid, cc.trail, cc.c_date, c.email, c.f_name, c.l_name, c.contact_no, c.creation_date as date $more_columns FROM $table_name as cc JOIN {$wpdb->prefix}bwf_contact AS c ON cc.cid = c.ID WHERE 1=1 $where  $order_by $limit";
		$contacts = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- where parts absint()ed/hardcoded/prepared; limit absint()ed

		if ( empty( $contacts ) ) {
			return $contacts;
		}

		/** Batch fetch: error messages for failed contacts (status = 2) */
		$fail_data_map = array();
		if ( 2 === intval( $status ) ) {
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
			$contact['e_time'] = isset( $contact['e_time'] ) ? gmdate( 'Y-m-d H:i:s', $contact['e_time'] ) : '';

			if ( 2 === intval( $status ) && isset( $contact['trail'] ) && isset( $fail_data_map[ $contact['trail'] ] ) ) {
				$contact['error_msg'] = $fail_data_map[ $contact['trail'] ];
			}

			if ( true === $more_data && isset( $step_data_map[ $contact['trail'] ] ) ) {
				$contact['data'] = $step_data_map[ $contact['trail'] ];
			}

			return $contact;
		}, $contacts );
	}

	public static function get_active_count( $aid = '', $status = '', $search = '', $cid = '', $where = '' ) {
		global $wpdb;
		$table_name = self::_table();

		$args    = [];
		$columns = '';
		$join    = '';
		if ( is_array( $aid ) ) {
			$columns = " cc.aid, ";
			$aids    = implode( ',', array_map( 'absint', $aid ) );
			$where   .= " AND cc.aid IN ($aids)";
		}

		if ( empty( $where ) && absint( $aid ) > 0 ) {
			$where  .= " AND cc.aid = %d";
			$args[] = $aid;
		}

		if ( intval( $cid ) > 1 ) {
			$where  .= " AND cc.cid = %d";
			$args[] = $cid;
		}

		/** If automation is inactive then no need to check automation contact status */
		if ( ! empty( $status ) && 'inactive' !== $status ) {
			if ( 'active' === $status ) {
				$where .= " AND ( cc.status = 1 OR cc.status = 4 OR cc.status = 6 )";
			} elseif ( 'delayed' === $status ) {
				$where .= " AND ( cc.status = 1 OR cc.status = 6 )";
			} elseif ( absint( $status ) ) {
				$where  .= " AND cc.status = %d";
				$args[] = $status;
			}
		}

		if ( ! empty( $search ) ) {
			$join   = " JOIN {$wpdb->prefix}bwf_contact AS c ON cc.cid = c.ID ";
			$where  .= " AND ( c.f_name LIKE %s OR c.l_name LIKE %s OR c.email LIKE %s ) ";
			$args[] = "%$search%";
			$args[] = "%$search%";
			$args[] = "%$search%";
		}

		$automation_status = 'inactive' === $status ? " AND am.status = 2" : " AND am.status = 1";
		$where             .= " AND (EXISTS ( SELECT 1 FROM {$wpdb->prefix}bwfan_automations AS am WHERE am.ID = cc.aid $automation_status ) )";
		$where             .= is_array( $aid ) ? " GROUP BY cc.aid " : '';
		$query             = $wpdb->prepare( "SELECT $columns COUNT(cc.ID) AS `count` FROM {$table_name} AS cc $join WHERE 1 = 1 $where ", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fragments hardcoded/absint()ed; values bound via prepare()
		if ( is_array( $aid ) ) {
			return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- fragments hardcoded/absint()ed; values bound via prepare()
		}

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- fragments hardcoded/absint()ed; values bound via prepare()
	}

	/**
	 * Get active, failed and paused counts for multiple automation IDs in a single query.
	 * Replaces 3 separate get_active_count() calls with 1 query using conditional aggregation.
	 *
	 * @param array $aids Array of automation IDs.
	 *
	 * @return array Map of aid => array( 'active' => int, 'failed' => int, 'paused' => int ).
	 */
	public static function get_all_status_counts_by_aids( $aids ) {
		global $wpdb;
		$table_name = self::_table();

		if ( ! is_array( $aids ) || empty( $aids ) ) {
			return [];
		}

		$aids         = array_map( 'absint', $aids );
		$placeholders = implode( ',', array_fill( 0, count( $aids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders generated in code; ids absint()ed
		$query = $wpdb->prepare(
			"SELECT cc.aid,
				SUM( CASE WHEN cc.status IN (1, 4, 6) THEN 1 ELSE 0 END ) AS active,
				SUM( CASE WHEN cc.status = 2 THEN 1 ELSE 0 END ) AS failed,
				SUM( CASE WHEN cc.status = 3 THEN 1 ELSE 0 END ) AS paused
			FROM {$table_name} AS cc
			WHERE cc.aid IN ({$placeholders})
			AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}bwfan_automations AS am WHERE am.ID = cc.aid AND am.status = 1 )
			GROUP BY cc.aid", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated in code; ids absint()ed
			...$aids
		); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results    = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated in code; ids absint()ed
		if ( empty( $results ) || ! is_array( $results ) ) {
			return [];
		}

		return array_column( $results, null, 'aid' );
	}

	public static function get_automation_contact_count( $aids ) {
		global $wpdb;
		$table_name = self::_table();

		if ( ! is_array( $aids ) ) {
			$aids = explode( ',', $aids );
		}
		$aids        = array_map( 'absint', $aids );
		$placeholder = implode( ',', array_fill( 0, count( $aids ), '%d' ) );

		$query = $wpdb->prepare( "SELECT aid, count(aid) as count FROM $table_name WHERE aid IN ($placeholder) GROUP BY aid", $aids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated in code; ids absint()ed

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated in code; ids absint()ed
	}

	public static function get_automation_contacts( $cid, $search = '', $limit = 10, $offset = 0, $more_data = false, $only_total = false ) {
		global $wpdb;
		$cid   = absint( $cid );
		$where = $wpdb->prepare( ' AND cc.cid = %d ', $cid );

		return self::get_contacts( $where, $search, $limit, $offset, $more_data, '', $only_total );
	}

	public static function delete_automation_contact_by_aid( $aid ) {
		global $wpdb;
		$table_name = self::_table();

		if ( is_array( $aid ) ) {
			$aid = array_filter( array_map( 'absint', $aid ) );
			if ( empty( $aid ) ) {
				return 0;
			}
			$placeholder = implode( ',', array_fill( 0, count( $aid ), '%d' ) );
			$query       = $wpdb->prepare( "DELETE FROM $table_name WHERE aid IN ($placeholder)", $aid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated in code; ids absint()ed
		} else {
			$query = $wpdb->prepare( "DELETE FROM $table_name WHERE aid = %d", intval( $aid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders generated in code; ids absint()ed
		}

		return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated in code; ids absint()ed
	}

	public static function get_row_by_trail_id( $trail_id ) {
		global $wpdb;
		$table_name = self::_table();

		$query = $wpdb->prepare( "SELECT * FROM $table_name WHERE trail = %s LIMIT 0,1", $trail_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; value bound via prepare()

		return $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; value bound via prepare()
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

		$query = $wpdb->prepare( "SELECT DISTINCT `cid` AS contact_id FROM {$table_name} WHERE `aid` = %d $where", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- where fragment hardcoded; values bound via prepare()

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- where fragment hardcoded; values bound via prepare()
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
		$date_col       = "c_date";
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

		$contact_counts = $wpdb->get_results( $base_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- interval SQL comes from Pro BWFCRM_Dashboards helper; other values absint()ed

		return $contact_counts;
	}

	/**
	 * Get automation contact by last step id
	 *
	 * @param $sid
	 *
	 * @return array
	 */
	public static function get_automation_contact_by_sid( $sid, $step_type = '', $status = 1 ) {
		global $wpdb;
		$table = self::_table();
		$where = " AND `status` = $status ";
		if ( 'goal' === $step_type ) {
			$where = " AND ( `status`= 4 OR `status` = 1 ) ";
		}

		// if ( $aid > 0 ) {
		// 	$where .= " AND aid = $aid";
		// }

		$query = "SELECT ID FROM {$table} WHERE `last` = %d $where";
		$query = $wpdb->prepare( $query, $sid ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- status hardcoded at all callers; sid bound via prepare()

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- status hardcoded at all callers; sid bound via prepare()
	}

	public static function get_automation_count_by_cid( $cid, $aid ) {
		global $wpdb;

		$table = self::_table();
		$query = $wpdb->prepare( 'SELECT COUNT(`aid`) as `count` FROM ' . $table . ' WHERE `cid` = %d AND `aid` = %d ORDER BY `ID` DESC', $cid, $aid ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; ids bound via prepare()

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; ids bound via prepare()
	}

	public static function update_status_by_multiple_ids( $ac_ids, $status = 1, $e_time = '', $last = false ) {
		if ( empty( $ac_ids ) && ! is_array( $ac_ids ) ) {
			return false;
		}

		global $wpdb;
		$table_name = self::_table();
		$set_e_time = '';
		$args       = [];
		if ( ! empty( $e_time ) ) {
			$set_e_time = " , `e_time` = %d ";
			$args[]     = $e_time;
		}

		/** Update last column if need */
		$set_last = '';
		if ( true === $last ) {
			$set_last = " , `last_time` = %d, `last` = %d, `claim_id` = %d, `attempts` = %d";
			array_push( $args, $e_time, 0, 0, 0 );
		}

		$string_placeholders   = array_fill( 0, count( $ac_ids ), '%d' );
		$prepared_placeholders = implode( ', ', $string_placeholders );
		$args                  = array_merge( $args, $ac_ids );

		$query = "UPDATE {$table_name} SET `status`= $status $set_e_time $set_last WHERE `ID` IN ($prepared_placeholders) ";
		$query = $wpdb->prepare( $query, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- status hardcoded at all callers; values bound via prepare()

		return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- status hardcoded at all callers; values bound via prepare()
	}

	public static function get_multiple_automation_contact( $aids, $contact_id ) {
		global $wpdb;
		$args = [];
		if ( ! is_array( $aids ) ) {
			$args = [ $aids ];
		} else {
			$args = array_merge( $args, $aids );
		}

		$placeholders = array_fill( 0, count( $args ), '%d' );
		$placeholders = implode( ', ', $placeholders );
		$args[]       = $contact_id;

		$table = self::_table();
		$query = $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE `aid` IN (' . $placeholders . ') AND `cid` = %d  ORDER BY `ID` DESC', $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated in code; values bound via prepare()

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated in code; values bound via prepare()
	}

	/**
	 * Get next run time
	 *
	 * @param $trail
	 *
	 * @return string|null
	 */
	public static function get_next_run_time_by_trail( $trail ) {
		global $wpdb;
		$table = self::_table();

		return $wpdb->get_var( $wpdb->prepare( "SELECT `e_time` FROM {$table} WHERE `trail` = %s ", $trail ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; value bound via prepare()
	}

	/**
	 * Get last (time range) failed count
	 *
	 * @param $time_range
	 *
	 * @return string|null
	 */
	public static function get_failed_count( $time_range = '' ) {
		global $wpdb;
		$table_name = self::_table();

		/** Failed status 2 */
		$args = [ 2 ];

		$where = '';
		if ( ! empty( $time_range ) ) {
			$date = new DateTime();
			$date->modify( "- $time_range" );
			$last   = $date->format( 'Y-m-d H:i:s' );
			$where  .= " AND `c_date` >  %s ";
			$args[] = $last;
		}

		$query = $wpdb->prepare( "SELECT COUNT(*) AS `count` FROM {$table_name}  WHERE 1 = 1 AND `status` = %d  $where", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- where fragment hardcoded; values bound via prepare()

		return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- where fragment hardcoded; values bound via prepare()
	}

	/**
	 * Check if duplicate contact data, already exists with same data in 5 mins in the same automation
	 *
	 * @param $data
	 *
	 * @return bool
	 * @throws Exception
	 */
	public static function check_duplicate_automation_contact( $data ) {
		if ( empty( $data ) ) {
			return false;
		}

		$wait_time = apply_filters( 'bwfan_duplicate_automation_contact_wait_time', 5, $data );
		$wait_time = intval( $wait_time ) > 0 ? $wait_time : 5;

		/** Check if contact already exists in automation contact complete table */
		$already_exists = BWFAN_Model_Automation_Complete_Contact::check_duplicate_automation_contact( $data, $wait_time );
		if ( $already_exists ) {
			return true;
		}

		$datetime = new DateTime( date( 'Y-m-d H:i:s', strtotime( $data['c_date'] ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- strtotime($string) round-trip into tz-less DateTime; gmdate would shift c_date
		$c_date   = $datetime->modify( "-$wait_time mins" )->format( 'Y-m-d H:i:s' );

		global $wpdb;

		$query = "SELECT `ID` FROM `{$wpdb->prefix}bwfan_automation_contact` WHERE `cid` = %d AND `aid` = %d AND `event` = %s  AND `data` = %s AND `c_date` >= %s LIMIT 1";

		return intval( $wpdb->get_var( $wpdb->prepare( $query, $data['cid'], $data['aid'], $data['event'], $data['data'], $c_date ) ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare()
	}

	/**
	 * Check if contact is in automation for a particular order related event
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

		$query = "SELECT `ID`, `data` FROM `{$wpdb->prefix}bwfan_automation_contact` WHERE `cid` = %d AND `aid` = %d AND `event` = %s  AND $data ORDER BY `ID` DESC LIMIT 0,1";
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
	 * Update e_time of multiple rows
	 *
	 * @param $ids
	 * @param $e_time
	 *
	 * @return bool|int|mysqli_result|null
	 */
	public static function update_e_time_col_of_ids( $ids, $e_time = '' ) {
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return 0;
		}

		global $wpdb;
		$table_name = self::_table();

		if ( empty( $e_time ) ) {
			$e_time = current_time( 'timestamp', 1 );
		}
		$args = [ $e_time ];

		$string_placeholders   = array_fill( 0, count( $ids ), '%d' );
		$prepared_placeholders = implode( ', ', $string_placeholders );
		$args                  = array_merge( $args, $ids );

		$query = "UPDATE {$table_name} SET `e_time` = %d WHERE `ID` IN ($prepared_placeholders) ";
		$query = $wpdb->prepare( $query, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated in code; values bound via prepare()

		return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated in code; values bound via prepare()
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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- where built from hardcoded placeholders; values spread into prepare()
		$query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- where built from hardcoded placeholders; values spread into prepare()
			"SELECT t.tid, t.data FROM {$trail_table} AS t
			INNER JOIN (
				SELECT tid, sid, MAX(ID) AS max_id FROM {$trail_table} AS t2
				WHERE {$where_clause}
				GROUP BY tid, sid
			) AS latest ON t.ID = latest.max_id", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- where built from hardcoded placeholders; values spread into prepare()
			...$args
		); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- where built from hardcoded placeholders; values spread into prepare()

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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders generated in code; values spread into prepare()
		$query = $wpdb->prepare(
			"SELECT ct.tid, ct.c_time AS run_time, st.action, st.type
			FROM {$trail_table} AS ct
			JOIN {$step_table} AS st ON ct.sid = st.ID
			INNER JOIN (
				SELECT tid, MAX(ID) AS max_id FROM {$trail_table}
				WHERE tid IN ({$placeholders})
				GROUP BY tid
			) AS latest ON ct.ID = latest.max_id", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated in code; values spread into prepare()
			...$trail_ids
		); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated in code; values spread into prepare()

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
