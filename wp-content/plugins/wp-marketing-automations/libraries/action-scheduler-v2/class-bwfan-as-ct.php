<?php

class BWFAN_AS_V2 {
	private static $instance;

	/**
	 * Plugin constructor.
	 */
	public function __construct() {
		global $wpdb;
		$wpdb->bwfan_automations                 = $wpdb->prefix . 'bwfan_automations';
		$wpdb->bwfan_automationmeta              = $wpdb->prefix . 'bwfan_automationmeta';
		$wpdb->bwfan_automation_step             = $wpdb->prefix . 'bwfan_automation_step';
		$wpdb->bwfan_automation_contact          = $wpdb->prefix . 'bwfan_automation_contact';
		$wpdb->bwfan_automation_contact_claim    = $wpdb->prefix . 'bwfan_automation_contact_claim';
		$wpdb->bwfan_automation_complete_contact = $wpdb->prefix . 'bwfan_automation_complete_contact';
	}

	/**
	 * Override the action store with our own
	 *
	 * @param string $class
	 *
	 * @return string
	 */
	public function set_store_class( $class ) {
		return BWFAN_AS_V2_Action_Store::class;
	}

	/**
	 * Override the logger with our own
	 *
	 * @param string $class
	 *
	 * @return string
	 */
	public function set_logger_class( $class ) {
		return BWFAN_AS_V2_Log_Store::class;
	}

	public function change_data_store() {
		/** Removing all action data store change filter and then assign ours */
		remove_all_filters( 'action_scheduler_store_class' );
		add_filter( 'action_scheduler_store_class', [ $this, 'set_store_class' ], 999999, 1 );

		/** Removing all log data store change filter and then assign ours */
		remove_all_filters( 'action_scheduler_logger_class' );
		add_filter( 'action_scheduler_logger_class', [ $this, 'set_logger_class' ], 999999, 1 );

		/** Removing all AS memory exceeds filter */
		remove_all_filters( 'action_scheduler_memory_exceeded' );
		add_filter( 'action_scheduler_memory_exceeded', [ $this, 'check_memory_exceeded' ], 1000000, 1 );
	}

	/**
	 * Override memory exceeded filter value
	 *
	 * @param $memory_exceeded
	 *
	 * @return bool
	 */
	public function check_memory_exceeded( $memory_exceeded ) {
		if ( true === $memory_exceeded ) {
			return $memory_exceeded;
		}

		$ins = BWF_AS::instance();

		return $ins->validate_time_breach();
	}

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Set claim_id 0 for orphaned actions where claim_id exists
	 *
	 * Uses a threshold based on the worker execution time to avoid clearing claims
	 * that are still being actively processed by a running worker.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function unset_orphaned_claims() {
		global $wpdb;

		/** Use worker execution time + buffer to determine orphan threshold */
		$per_call_time    = apply_filters( 'bwfan_as_per_call_time', 30 );
		$orphan_threshold = max( intval( $per_call_time ) + 120, 300 );

		$now = new DateTime( '', new DateTimeZone( 'UTC' ) );
		$now->modify( "-{$orphan_threshold} seconds" );
		$date_limit = $now->format( 'Y-m-d H:i:s' );

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT `ID` FROM `{$wpdb->prefix}bwfan_automation_contact_claim` WHERE `created_at` < %s", $date_limit ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! empty( $ids ) ) {
			$time = time();
			do {
				foreach ( $ids as $k => $id ) {
					$updated = self::query_with_deadlock_retry( $wpdb->prepare( "UPDATE `{$wpdb->prefix}bwfan_automation_contact` SET `claim_id` = 0 WHERE `claim_id` = %d", $id ) );
					if ( false === $updated ) {
						/** DB error — stop processing this batch */
						break 2;
					}
					if ( 0 === intval( $updated ) ) {
						/** No rows to update */
						$wpdb->delete( $wpdb->prefix . 'bwfan_automation_contact_claim', [ 'ID' => $id ] ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						unset( $ids[ $k ] );
					}
					if ( time() - 10 > $time ) {
						break;
					}
				}
				if ( time() - 10 > $time ) {
					break;
				}
			} while ( ! empty( $ids ) );
		}

		/**
		 * Fix contacts stuck by past deadlocks: claim_id is non-zero but the matching
		 * claim row no longer exists in the claim table (deleted or never committed).
		 * These contacts would never be picked up by normal orphan cleanup above.
		 *
		 * Includes all non-terminal statuses:
		 * 1 (Active), 3 (Paused), 4 (Wait), 6 (Retry)
		 * Status 3/4 can get stuck because unpause and goal-met transitions
		 * (update_status_by_multiple_ids) do not reset claim_id.
		 * Status 2 (Failed) and 5 (Terminate) are excluded — they won't re-enter the queue.
		 */
		$stuck_ids = $wpdb->get_col( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ac.`ID` FROM `{$wpdb->prefix}bwfan_automation_contact` ac
				LEFT JOIN `{$wpdb->prefix}bwfan_automation_contact_claim` acc ON ac.`claim_id` = acc.`ID`
				WHERE ac.`claim_id` != 0
				AND ac.`status` IN (%d, %d, %d, %d)
				AND acc.`ID` IS NULL
				LIMIT %d",
				1, 3, 4, 6, 100
			)
		);

		if ( ! empty( $stuck_ids ) ) {
			$placeholders = array_fill( 0, count( $stuck_ids ), '%d' );
			$format       = implode( ', ', $placeholders );
			$sql          = $wpdb->prepare(
				"UPDATE `{$wpdb->prefix}bwfan_automation_contact` SET `claim_id` = 0 WHERE `ID` IN ({$format})", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $format is a run of %d placeholders, one per stuck ID
				...$stuck_ids
			);
			self::query_with_deadlock_retry( $sql );
		}
	}

	/**
	 * Execute a query with deadlock retry logic
	 *
	 * @param string $sql Prepared SQL query
	 * @param int $max_retries Maximum number of retry attempts
	 *
	 * @return int|false Number of rows affected or false on failure
	 */
	public static function query_with_deadlock_retry( $sql, $max_retries = 3 ) {
		global $wpdb;

		for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
			/** Suppress wpdb's native error logging; deadlocks are handled here. last_error stays populated for detection below. */
			$suppress = $wpdb->suppress_errors( true );
			$result   = $wpdb->query( $sql ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- callers pass a query already built with $wpdb->prepare()
			$wpdb->suppress_errors( $suppress );

			if ( false !== $result ) {
				return $result;
			}

			/** Check if the error is a deadlock or a lock-wait timeout — both are transient contention errors worth retrying */
			$is_retryable = ( false !== strpos( $wpdb->last_error, 'Deadlock found' ) ) || ( false !== strpos( $wpdb->last_error, 'Lock wait timeout exceeded' ) );
			if ( ! $is_retryable ) {
				/** Not a retryable error, don't retry. Re-surface it since wpdb's native logging was suppressed, unless an outer context already suppressed errors. */
				if ( ! $suppress && '' !== $wpdb->last_error ) {
					$wpdb->print_error( $wpdb->last_error ); //phpcs:ignore
				}

				return false;
			}

			if ( $attempt < $max_retries ) {
				BWFAN_Common::log_test_data( 'BWFAN: Transient DB contention (deadlock/lock-wait) detected, retry attempt ' . ( $attempt + 1 ) . ' of ' . $max_retries, 'fka-db-deadlock', true );
				/** Brief pause before retry with exponential backoff */
				usleep( ( $attempt + 1 ) * 100000 );
			}
		}

		return false;
	}
}
