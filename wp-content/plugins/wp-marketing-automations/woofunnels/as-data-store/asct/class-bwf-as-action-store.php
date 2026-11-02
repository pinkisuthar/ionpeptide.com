<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'BWF_AS_Action_Store' ) ) {
	#[AllowDynamicProperties]
	class BWF_AS_Action_Store extends ActionScheduler_Store {
		public $bwf_action_data = [];
		/**
		 * Action ids whose next occurrence could not be inserted, mapped to the row that should have been
		 * created. Request-scoped only: if the process dies between the failed insert and mark_complete()/
		 * mark_failure() (e.g. the host killing the request, one of the triggers this exists for), this map
		 * is lost and the row is picked up as a plain failure on the next run instead of being recycled.
		 * bwf_worker_before_as_run is the backstop for that gap, not this property.
		 */
		public $recurring_reschedule_failed = [];
		public $action_table = '';
		public $claim_table = '';
		public $p_key = '';

		public function init() {
			global $wpdb;
			$this->action_table = BWF_AS_Actions_Crud::_table();
			$this->claim_table  = $wpdb->bwf_action_claim;
			$this->p_key        = BWF_AS_Actions_Crud::$primary_key;
		}

		public function save_action( ActionScheduler_Action $action, ?DateTime $date = null ) {
			/** Not scheduling any new action while processing our requests */
		}

		public function fetch_action( $action_id ) {
			global $wpdb;

			if ( empty( $action_id ) ) {
				return $this->get_null_action();
			}

			$this->log( 'fetch running action id: ' . $action_id );

			if ( class_exists( 'BWFAN_Merge_Tag_Loader' ) ) {
				BWFAN_Merge_Tag_Loader::reset_data();
			}

			/**
			 * Below code will run between these 2 hooks action_scheduler_begin_execute and action_scheduler_after_execute
			 */
			if ( true === BWF_AS::is_execution_started() ) {
				/** Changing status to running i.e. 1 on action */
				$wpdb->update( $this->action_table, [ 'status' => 1 ], [ $this->p_key => $action_id ], [ '%d' ], [ '%d' ] ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

			$data = $this->get_action_data( $action_id );

			if ( empty( $data ) || ! ( $data instanceof StdClass ) ) {
				return $this->get_null_action();
			}

			if ( true === BWF_AS::is_execution_started() ) {
				/** Scheduling recurring action if possible */
				$this->schedule_recurring_action( $action_id );
			}

			/** Fetching action data again as status may be altered */
			$data = $this->get_action_data( $action_id );

			/** If hook not present, return null action */
			if ( empty( $data->hook ) || ! ( $data instanceof StdClass ) ) {
				return $this->get_null_action();
			}

			return $this->make_action_from_db_record( $data );
		}

		protected function get_null_action() {
			return new ActionScheduler_NullAction();
		}

		public function log( $msg ) {
			BWF_Logger::get_instance()->log( WooFunnels_AS_DS::$unique . ' - ' . $msg, 'woofunnel-as' );
		}

		protected function get_action_data( $action_id ) {
			$cache_key = 'bwf_fetch_action_' . $action_id;

			if ( isset( $this->bwf_action_data[ $action_id ] ) ) {
				return $this->bwf_action_data[ $action_id ];
			}

			$data = wp_cache_get( $cache_key, __CLASS__ );
			if ( false === $data ) {
				$data = BWF_AS_Actions_Crud::get_single_action( $action_id );

				/** Saving data to local scope and cache */
				if ( is_object( $data ) ) {
					$this->bwf_action_data[ $action_id ] = $data;
					wp_cache_set( $cache_key, $data, __CLASS__, ( 60 ) );
				}
			}

			return $data;
		}

		/**
		 * Helper method to schedule recurring action before execution itself.
		 * This make sure recurring action should be scheduled.
		 *
		 * @param $action_id
		 */
		protected function schedule_recurring_action( $action_id ) {
			global $wpdb;

			$data = $this->get_action_data( $action_id );

			/** Checking if recurring action */
			if ( ! ( $data instanceof StdClass ) || false === $this->action_is_recurring( $data ) ) {
				return;
			}

			$args  = ( is_array( $data->args ) && count( $data->args ) > 0 ) ? $data->args : array();
			$group = ( ! empty( $data->group_slug ) ) ? $data->group_slug : '';

			/** Checking if already running then change the status to failed and schedule new action */
			$count = bwf_scheduled_action_count( $data->hook, $args, $group, '1', 'recurring' );
			if ( false === $count || 1 < $count ) {
				$this->log( __FUNCTION__ . ' id ' . $action_id . ', recurring action already running count: ' . $count );

				/** Changing status to failed i.e. 2 on action */
				$wpdb->update( $this->action_table, [ 'status' => 2 ], [ $this->p_key => $action_id ], [ '%d' ], [ '%d' ] ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				/** Modify action object cache data status */
				if ( isset( $this->bwf_action_data[ $action_id ] ) ) {
					$this->bwf_action_data[ $action_id ]->status = '2';
				}
			}

			/** Validate non-running actions */
			$count = bwf_scheduled_action_count( $data->hook, $args, $group, '0', 'recurring' );
			if ( 1 === $count ) {
				/** if one current and one more action non-running found then no need to schedule */
				return;
			}
			if ( 1 < $count ) {
				/** delete all non running actions i.e. 0 except current action  */
				bwf_delete_scheduled_recurring_action( $data->hook, 0, $action_id );
			}

			/** Scheduling new action */
			$curr_time = current_time( 'mysql', 1 );
			$exec_time = time() + (int) $data->recurring_interval;
			$exec_time = apply_filters( 'bwf_recurring_action_' . $data->hook . '_execution_time', $exec_time, $data );
			$new_data  = array(
				'c_date'             => $curr_time,
				'e_time'             => $exec_time,
				'hook'               => $data->hook,
				'recurring_interval' => (int) $data->recurring_interval,
				'claim_id'           => 0
			);
			if ( is_array( $data->args ) && count( $data->args ) > 0 ) {
				$new_data['args'] = wp_json_encode( $data->args );
			}
			if ( ! empty( $data->group_slug ) ) {
				$new_data['group_slug'] = $data->group_slug;
			}

			/**
			 * The current row is deleted once this action finishes (mark_complete/mark_failure). If this INSERT
			 * is silently dropped - deadlock, lost connection, host killing the request - the recurring chain
			 * ends here and nothing ever re-creates it. Check it, retry once, and if it still fails remember
			 * the action so the row we are about to consume can be recycled instead of deleted.
			 */
			$inserted = BWF_AS_Actions_Crud::insert( $new_data );
			if ( false === $inserted ) {
				/**
				 * A retry can duplicate the row if the first INSERT committed and only the response was lost.
				 * That is the safe direction to fail in: the duplicate is picked up by the non-running count
				 * check above on the next run and removed by bwf_delete_scheduled_recurring_action().
				 */
				$this->log_forced( __FUNCTION__ . ' id ' . $action_id . ', next occurrence insert failed for hook ' . $data->hook . ', retrying. DB error: ' . $wpdb->last_error );
				$inserted = BWF_AS_Actions_Crud::insert( $new_data );
			}

			if ( false === $inserted ) {
				/** Keep the payload, the recycle step falls back to a fresh insert when the row is already gone */
				$this->recurring_reschedule_failed[ $action_id ] = $new_data;
				$this->log_forced( 'Failed to schedule next occurrence of recurring hook ' . $data->hook . ' (action id ' . $action_id . '). DB error: ' . $wpdb->last_error );
			}
		}

		/**
		 * Helper method. Re-arms the row we were about to consume when its replacement could not be created.
		 * Returns true when the current row has been turned into the next occurrence and must not be deleted.
		 *
		 * @param $action_id
		 *
		 * @return bool
		 */
		protected function maybe_recycle_recurring_action( $action_id ) {
			if ( ! isset( $this->recurring_reschedule_failed[ $action_id ] ) ) {
				return false;
			}

			global $wpdb;

			$pending = $this->recurring_reschedule_failed[ $action_id ];
			unset( $this->recurring_reschedule_failed[ $action_id ] );

			$updated = $wpdb->update( $this->action_table, [ //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				'status'   => 0,
				'e_time'   => $pending['e_time'],
				'claim_id' => 0,
			], [ $this->p_key => $action_id ], [ '%d', '%d', '%d' ], [ '%d' ] );

			if ( false === $updated ) {
				$this->log_forced( 'Could not recycle recurring action id ' . $action_id . ' after a failed reschedule. DB error: ' . $wpdb->last_error );

				return $this->recreate_after_failed_recycle( $pending, $action_id );
			}

			/**
			 * Zero rows changed. The status always moves here (1 running, or 2 when a duplicate run was
			 * detected, down to 0 pending), so the row either no longer exists or already holds these exact
			 * values. A concurrent worker can delete an in-flight row: ActionScheduler_QueueCleaner::mark_failures()
			 * selects status 1 rows whose `modified` is past action_scheduler_failure_period, and this store maps
			 * `modified` onto `e_time` (see get_query_actions_sql()). `e_time` is the time the action was due,
			 * not the time it started, so on a backed-up queue a row can already be past that cutoff the moment
			 * it begins running. Confirm which of the two it is instead of reporting success blindly.
			 */
			if ( 0 === (int) $updated ) {
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT `{$this->p_key}` FROM {$this->action_table} WHERE `{$this->p_key}` = %d", $action_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from prefix, pkey constant 'id', action_id bound via %d

				/**
				 * get_var() returns null both when the row is gone and when the SELECT itself failed. A DB
				 * error is not confirmation the row still holds the right values, so it is treated the same
				 * as "gone" rather than falling through to report success on an unverified row - recreate is
				 * the safe direction to fail in here, same reasoning as the retry above.
				 */
				if ( null === $exists ) {
					$this->log_forced( 'Recurring action id ' . $action_id . ( '' === $wpdb->last_error ? ' disappeared' : ' could not be verified (DB error: ' . $wpdb->last_error . ')' ) . ' before it could be recycled.' );

					return $this->recreate_after_failed_recycle( $pending, $action_id );
				}
			}

			$this->log_forced( 'Recycled recurring action id ' . $action_id . ' as its own next occurrence after a failed reschedule.' );

			/** Row is alive again, drop the stale cached copy */
			unset( $this->bwf_action_data[ $action_id ] );
			wp_cache_delete( 'bwf_fetch_action_' . $action_id, __CLASS__ );

			return true;
		}

		/**
		 * Helper method. Last resort once the current row could not be turned into the next occurrence:
		 * create the replacement row on its own. Always returns false so the caller keeps its usual delete,
		 * the replacement carries a different id and is not affected by it.
		 *
		 * Reached from the failed-UPDATE path this can duplicate the row, for the same reason the INSERT retry
		 * in schedule_recurring_action() can: if that UPDATE committed and only the response was lost, the row
		 * is recycled and a replacement is written on top of it. Same safe direction to fail in, and the same
		 * cleanup picks it up - the non-running count check in schedule_recurring_action() removes the extra
		 * row through bwf_delete_scheduled_recurring_action() on the next run.
		 *
		 * @param $new_data
		 * @param $action_id
		 *
		 * @return bool
		 */
		protected function recreate_after_failed_recycle( $new_data, $action_id ) {
			global $wpdb;

			if ( false !== BWF_AS_Actions_Crud::insert( $new_data ) ) {
				$this->log_forced( 'Re-created the next occurrence of recurring hook ' . $new_data['hook'] . ' after action id ' . $action_id . ' could not be recycled.' );

				return false;
			}

			$this->log_forced( 'Failed to re-create the next occurrence of recurring hook ' . $new_data['hook'] . ' (action id ' . $action_id . '); the hook is now unscheduled. DB error: ' . $wpdb->last_error );

			return false;
		}

		/**
		 * Helper method. Writes to the failed-actions log regardless of the site's logging setting.
		 *
		 * @param $msg
		 */
		protected function log_forced( $msg ) {
			add_filter( 'bwf_logs_allowed', array( $this, 'overriding_bwf_logging' ), 99999, 2 );
			BWF_Logger::get_instance()->log( $msg, 'woofunnel-failed-actions' );
			remove_filter( 'bwf_logs_allowed', array( $this, 'overriding_bwf_logging' ), 99999 );
		}

		/**
		 * Helper method that checks if an action is recurring.
		 *
		 * @param $data
		 *
		 * @return bool
		 */
		protected function action_is_recurring( $data ) {
			if ( ! is_object( $data ) || ! isset( $data->recurring_interval ) ) {
				return false;
			}
			if ( (int) $data->recurring_interval < 1 ) {
				return false;
			}

			return true;
		}

		/**
		 * Initiate action class object with needful data
		 *
		 * @param $data
		 *
		 * @return ActionScheduler_Action|ActionScheduler_FinishedAction
		 */
		protected function make_action_from_db_record( $data ) {
			$hook = $data->hook;
			/**
			 * Re-index args to a positional (numeric-keyed) list before execution.
			 *
			 * On PHP 8+, when an action's args are an associative array, passing them through
			 * call_user_func_array() (inside do_action_ref_array()) treats the string keys as
			 * named arguments, fataling with "Unknown named parameter $x" when the hook callback
			 * has no matching parameter name. Recent Action Scheduler guards this with array_values()
			 * at execute time, but an older AS copy (e.g. one bundled by another active plugin that
			 * wins the version race) does not. Flattening here makes execution AS-version agnostic and
			 * mirrors what modern AS does, so registered callbacks keep receiving positional args.
			 */
			$args = ( is_array( $data->args ) && count( $data->args ) > 0 ) ? array_values( $data->args ) : [];

			/** creating fresh schedule */
			$schedule = new ActionScheduler_NullSchedule();
			$group    = ( ! empty( $data->group_slug ) ) ? $data->group_slug : '';
			if ( $this->verify_status( $data->status ) ) {
				$action = new ActionScheduler_Action( $hook, $args, $schedule, $group );
			} else {
				/** status not 0 - finishing AS action (status won't occur as we are fetching 0 status actions only) */
				$action = new ActionScheduler_FinishedAction( $hook, $args, $schedule, $group );
			}

			return $action;
		}

		/**
		 * Helper method: If pending action then bool true otherwise false
		 *
		 * @param $status
		 *
		 * @return bool
		 */
		protected function verify_status( $status ) {
			return ( 0 == $status || 1 == $status ) ? true : false;
		}

		/**
		 * @param string $hook
		 * @param array $params
		 *
		 * @return string
		 */
		public function find_action( $hook, $params = [] ) {
			/** This is invoked during unscheduled or next schedule, we are not doing anything, so blank */
			return '';
		}

		/**
		 * @param array $query
		 * @param string $query_type
		 *
		 * @return array|string ids array or count
		 */
		public function query_actions( $query = [], $query_type = 'select' ) {
			global $wpdb;
			/** cleanup call handling */
			if ( ! isset( $query['status'] ) || in_array( $query['status'], array( 'complete', 'canceled', 'failed' ), true ) || is_array( $query['status'] ) ) {
				return array();
			}

			if ( 'pending' === $query['status'] ) {
				$query['status'] = '0';
			} elseif ( 'in-progress' === $query['status'] ) {
				$query['status'] = '1';
			}

			/** Code will through in case of pending status i.e. 0 */
			$sql = $this->get_query_actions_sql( $query, $query_type );

			$value = ( 'count' === $query_type ) ? $wpdb->get_var( $sql ) : $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Built by get_query_actions_sql: values bound, comparator/order whitelisted
			if ( empty( $value ) ) {
				return array();
			}
			$this->log( __FUNCTION__ . ': status ' . $query['status'] . ' - (' . $this->get_as_defined_status_val( $query['status'] ) . ')' . ' query result: ' . implode( ',', $value ) );

			return $value;
		}

		/**
		 * Returns the SQL statement to query (or count) actions.
		 *
		 * @param array $query Filtering options
		 * @param string $select_or_count Whether the SQL should select and return the IDs or just the row count
		 *
		 * @return string SQL statement. The returned SQL is already properly escaped.
		 * 'status'           => ActionScheduler_Store::STATUS_PENDING,
		 * 'modified'         => $cutoff,
		 * 'modified_compare' => '<=',
		 * 'claimed'          => true,
		 * 'per_page'         => $this->get_batch_size(),
		 */
		protected function get_query_actions_sql( array $query, $select_or_count = 'select' ) {
			if ( ! in_array( $select_or_count, array( 'select', 'count' ), true ) ) {
				throw new InvalidArgumentException( esc_html__( 'Invalid value for select or count parameter. Cannot query actions.', 'action-scheduler' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			}

			$query = wp_parse_args( $query, [
				'hook'             => '',
				'args'             => null,
				'date'             => null,
				'date_compare'     => '<=',
				'modified'         => null,
				'modified_compare' => '<=',
				'group'            => '',
				'status'           => '0',
				'claimed'          => null,
				'per_page'         => 5,
				'offset'           => 0,
				'orderby'          => 'date',
				'order'            => 'ASC',
			] );

			global $wpdb;
			$sql        = 'SELECT ';
			$sql        .= ( 'count' === $select_or_count ) ? "count({$this->p_key})" : "{$this->p_key} ";
			$sql        .= "FROM {$this->action_table}";
			$sql_params = [];

			$sql .= ' WHERE 1=1';

			if ( ! empty( $query['group'] ) ) {
				$sql          .= ' AND group_slug=%s';
				$sql_params[] = $query['group'];
			}

			if ( $query['hook'] ) {
				$sql          .= ' AND hook=%s';
				$sql_params[] = $query['hook'];
			}
			if ( ! is_null( $query['args'] ) ) {
				$sql          .= ' AND args=%s';
				$sql_params[] = wp_json_encode( $query['args'] );
			}
			/** 0 or 1 in our case */
			if ( '' !== $query['status'] ) {
				$sql          .= ' AND status=%s';
				$sql_params[] = $query['status'];
			}

			if ( $query['date'] instanceof DateTime ) {
				$date = clone $query['date'];
				$date->setTimezone( new DateTimeZone( 'UTC' ) );
				$date_string  = $date->format( 'Y-m-d H:i:s' );
				$comparator   = $this->validate_sql_comparator( $query['date_compare'] );
				$sql          .= " AND e_time $comparator %s";
				$sql_params[] = $date_string;
			}

			if ( $query['claimed'] === true ) {
				$sql .= ' AND claim_id != 0';
			} elseif ( $query['claimed'] === false ) {
				$sql .= ' AND claim_id = 0';
			} elseif ( ! is_null( $query['claimed'] ) ) {
				$sql          .= ' AND claim_id = %d';
				$sql_params[] = $query['claimed'];
			}

			if ( $query['modified'] instanceof DateTime ) {
				$modified = clone $query['modified'];
				$modified->setTimezone( new DateTimeZone( 'UTC' ) );
				$date_string  = $modified->getTimestamp();
				$comparator   = $this->validate_sql_comparator( $query['modified_compare'] );
				$sql          .= " AND e_time $comparator %s";
				$sql_params[] = $date_string;
			}

			if ( 'select' === $select_or_count ) {
				switch ( $query['orderby'] ) {
					case 'date':
					default:
						$orderby = 'e_time';
						break;
				}
				$order = ( strtoupper( $query['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

				$sql .= " ORDER BY $orderby $order";
				if ( $query['per_page'] > 0 ) {
					$sql          .= ' LIMIT %d, %d';
					$sql_params[] = $query['offset'];
					$sql_params[] = $query['per_page'];
				}
			}

			if ( ! empty( $sql_params ) ) {
				$sql = $wpdb->prepare( $sql, $sql_params ); //phpcs:ignore WordPress.DB.PreparedSQL
			}

			return $sql;
		}

		protected function get_as_defined_status_val( $status ) {
			switch ( $status ) {
				case '0':
					return 'pending';
				case '1':
					return 'in-progress';
				case '2':
					return 'canceled';
			}

			return $status;
		}

		/**
		 * Get a count of all actions in the store, grouped by status; used in native actions listing. Not used in our scope.
		 */
		public function action_counts() {
			return [];
		}

		/**
		 * @param string $action_id
		 *
		 * @return void
		 * @throws InvalidArgumentException
		 */
		public function cancel_action( $action_id ) {
			$this->log( __FUNCTION__ . ' id ' . $action_id );

			return;
		}

		/**
		 * @param string $action_id
		 */
		public function delete_action( $action_id ) {
			$this->log( __FUNCTION__ . ' id ' . $action_id );

			return;
		}

		/**
		 * don't know where using
		 *
		 * @param string $action_id
		 *
		 * @return DateTime The local date the action is scheduled to run, or the date that it ran.
		 * @throws InvalidArgumentException
		 */
		public function get_date( $action_id ) {
			$date = $this->get_date_gmt( $action_id );
			ActionScheduler_TimezoneHelper::set_local_timezone( $date );

			return $date;
		}

		/**
		 * @param string $action_id
		 *
		 * @return DateTime The GMT date the action is scheduled to run, or the date that it ran.
		 * @throws InvalidArgumentException
		 */
		protected function get_date_gmt( $action_id ) {

			$record = BWF_AS_Actions_Crud::get_single_action( $action_id );
			if ( empty( $record ) ) {
				throw new InvalidArgumentException( esc_html( sprintf( __( 'Unidentified action %s', 'action-scheduler' ), $action_id ) ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment
			}
			if ( $this->verify_status( $record->status ) ) {
				return as_get_datetime_object( $record->e_time );
			}
		}

		/**
		 * @param int $max_actions
		 * @param DateTime $before_date Jobs must be schedule before this date. Defaults to now.
		 *
		 * @return ActionScheduler_ActionClaim
		 */
		public function stake_claim( $max_actions = 10, ?DateTime $before_date = null, $hooks = array(), $group = '' ) {
			$claim_id = $this->generate_claim_id();
			$this->log( __FUNCTION__ . ' claim id: ' . $claim_id );
			$this->claim_actions( $claim_id, $max_actions, $before_date, $hooks, $group );
			$action_ids = $this->find_actions_by_claim_id( $claim_id );

			return new ActionScheduler_ActionClaim( $claim_id, $action_ids );
		}

		/**
		 * Generate claim id of current date time
		 *
		 * @return int
		 */
		protected function generate_claim_id() {

			global $wpdb;
			$now = as_get_datetime_object();
			//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $this->claim_table, [
				'date' => $now->format( 'Y-m-d H:i:s' ),
			] );

			return $wpdb->insert_id;
		}

		/**
		 * Claim actions which are executable based on given inputs
		 *
		 * @param string $claim_id
		 * @param int $limit
		 * @param DateTime $before_date Should use UTC timezone.
		 *
		 * @return int The number of actions that were claimed
		 * @throws RuntimeException
		 *
		 */
		protected function claim_actions( $claim_id, $limit, ?DateTime $before_date = null, $hooks = array(), $group = '' ) {
			global $wpdb;

			/**
			 * Atomic, deadlock-safe claim.
			 *
			 * On MySQL 8.0+ / MariaDB 10.6+ the claimable rows are picked with FOR UPDATE SKIP LOCKED inside a
			 * derived table and stamped in the SAME statement, so two concurrent runners never wait on each
			 * other (no 1213 deadlock against the finished-action DELETE) and never double-claim a row. Older
			 * servers fall back to the single guarded UPDATE. A transient deadlock / lock-wait timeout is
			 * retried with a short backoff instead of throwing, matching core Action Scheduler behaviour.
			 *
			 * The claimed rows are read back afterwards via find_actions_by_claim_id().
			 */
			$where    = 'WHERE `claim_id` = 0 AND `e_time` <= %d AND `status` = 0';
			$params   = array( time() );

			if ( ! empty( $group ) ) {
				$where    .= ' AND `group_slug` = %s';
				$params[] = $group;
			}

			$where   .= ' ORDER BY `e_time` ASC LIMIT %d';
			$params[] = $limit;

			$attempts = 0;
			do {
				$attempts ++;

				if ( $this->supports_skip_locked() ) {
					/** Derived table materialises, so the same table can be read (FOR UPDATE SKIP LOCKED) and updated in one atomic statement. */
					$sql = "UPDATE {$this->action_table} t1 JOIN ( SELECT `{$this->p_key}` FROM {$this->action_table} {$where} FOR UPDATE SKIP LOCKED ) t2 ON t1.`{$this->p_key}` = t2.`{$this->p_key}` SET t1.`claim_id` = %d";
					$sql = $wpdb->prepare( $sql, array_merge( $params, array( $claim_id ) ) ); //phpcs:ignore WordPress.DB.PreparedSQL
				} else {
					$sql = "UPDATE {$this->action_table} SET `claim_id` = %d {$where}";
					$sql = $wpdb->prepare( $sql, array_merge( array( $claim_id ), $params ) ); //phpcs:ignore WordPress.DB.PreparedSQL
				}

				$rows_affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared at 659/662; only table/pkey identifiers interpolated
				if ( false !== $rows_affected ) {
					return (int) $rows_affected; // Zero claimable rows is a valid result, not an error.
				}

				$error = (string) $wpdb->last_error;
				if ( false === stripos( $error, 'deadlock' ) && false === stripos( $error, 'lock wait timeout' ) ) {
					break; // Non-transient DB error: stop retrying and surface it.
				}

				usleep( 50000 * $attempts ); // 50ms, then 100ms backoff before re-claiming.
			} while ( $attempts < 3 );

			$db_error = empty( $wpdb->last_error ) ? esc_html_x( 'unknown', 'database error', 'action-scheduler' ) : esc_html( $wpdb->last_error ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			/* translators: %s: database error message. */
			throw new RuntimeException( sprintf( esc_html__( 'Unable to claim actions. Database error: %s.', 'action-scheduler' ), $db_error ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message already escaped with esc_html() / esc_html__() above
		}

		/**
		 * Whether the DB server supports `SKIP LOCKED` (MySQL 8.0.1+ / MariaDB 10.6+).
		 *
		 * @return bool
		 */
		private function supports_skip_locked() {
			global $wpdb;
			static $supported = null;

			if ( null !== $supported ) {
				return $supported;
			}

			$info = strtolower( (string) $wpdb->db_server_info() );
			if ( false !== strpos( $info, 'mariadb' ) ) {
				$ver       = preg_replace( '/[^0-9.].*$/', '', str_replace( '5.5.5-', '', $info ) );
				$supported = ( '' !== $ver && version_compare( $ver, '10.6', '>=' ) );
			} else {
				$supported = version_compare( $wpdb->db_version(), '8.0.1', '>=' );
			}

			return $supported;
		}

		/**
		 * Get Actions against a claim_id
		 *
		 * @param string $claim_id
		 *
		 * @return array
		 */
		public function find_actions_by_claim_id( $claim_id ) {
			global $wpdb;

			$cache_key = 'bwf_action_ids_for_claim_id_' . $claim_id;

			$cache_available = wp_cache_get( $cache_key, __CLASS__ );
			if ( false !== $cache_available ) {
				return $cache_available;
			}

			$sql        = "SELECT `{$this->p_key}` FROM {$this->action_table} WHERE claim_id=%d ORDER BY e_time ASC";
			$sql        = $wpdb->prepare( $sql, $claim_id ); //phpcs:ignore WordPress.DB.PreparedSQL
			$action_ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared at 725; table from prefix, pkey constant

			$return = array_map( 'intval', $action_ids );
			wp_cache_set( $cache_key, $return, __CLASS__, ( HOUR_IN_SECONDS / 4 ) );

			$this->log( 'Found ids: ' . implode( ', ', $return ) );

			return $return;
		}

		/**
		 * Return unique claim id counts
		 *
		 * @return int
		 */
		public function get_claim_count() {
			global $wpdb;

			/** status 0 have actions which are executable */
			$sql = "SELECT COUNT(DISTINCT claim_id) FROM {$this->action_table} WHERE claim_id != 0 AND status = 0";

			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static query; only prefixed table name interpolated
		}

		/**
		 * Return an action's claim ID, as stored in the claim_id column
		 *
		 * @param string $action_id
		 *
		 * @return mixed
		 */
		public function get_claim_id( $action_id ) {
			$this->log( __FUNCTION__ . ' ' . $action_id );

			$action = BWF_AS_Actions_Crud::get_single_action( $action_id, 'claim_id' );

			/**
			 * get_single_action() returns a stdClass (null when the row is gone), so the claim_id property must be
			 * read explicitly. Casting the object directly (the previous behaviour)
			 * always evaluated to 1. That was harmless until Action Scheduler 4.0,
			 * whose QueueRunner::do_batch() re-verifies each action with
			 * `$claim_id !== $store->get_claim_id( $action_id )`. The bogus 1 made that
			 * check fail on the first action of every batch, so the runner dropped the
			 * claim and processed nothing from the custom store. (AS 3.x used
			 * find_actions_by_claim_id() instead, which is why this stayed dormant.)
			 */
			return ( is_object( $action ) && isset( $action->claim_id ) ) ? (int) $action->claim_id : 0;
		}

		/**
		 * Releasing the claim
		 *
		 * @param ActionScheduler_ActionClaim $claim
		 */
		public function release_claim( ActionScheduler_ActionClaim $claim ) {
			$this->log( __FUNCTION__ . ' id ' . $claim->get_id() );

			global $wpdb;
			$wpdb->update( $this->action_table, [ 'claim_id' => 0 ], [ 'claim_id' => $claim->get_id() ], [ '%d' ], [ '%d' ] ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$wpdb->delete( $this->claim_table, [ 'id' => $claim->get_id() ], [ '%d' ] ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		/**
		 * Unclaim pending actions that have not been run within a given time limit.
		 * Default 300
		 * Called inside reset_timeouts method
		 *
		 * @param string $action_id
		 *
		 * @return void
		 */
		public function unclaim_action( $action_id ) {
			$this->log( __FUNCTION__ . ' id ' . $action_id );

			global $wpdb;
			$wpdb->update( $this->action_table, [ 'claim_id' => 0 ], [ $this->p_key => $action_id ], [ '%d' ], [ '%d' ] ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		/**
		 * @param string $action_id
		 * @param null $e
		 */
		public function mark_failure( $action_id, $e = null ) {
			$this->log( __FUNCTION__ . ' for action id ' . $action_id );

			/** Log failure data */
			$this->log_failure_data( $action_id );

			/** Keep the row as the next occurrence if its replacement could not be created */
			if ( true === $this->maybe_recycle_recurring_action( $action_id ) ) {
				return;
			}

			/** Deleting existing action */
			BWF_AS_Actions_Crud::delete( $action_id );
		}

		/**
		 * Helper method
		 * Log failure action data in bwf logs
		 *
		 * @param $action_id
		 */
		protected function log_failure_data( $action_id ) {
			$data = $this->get_action_data( $action_id );

			if ( $data instanceof StdClass ) {
				$log_arr = array(
					'action_id'      => $action_id,
					'creation_date'  => $data->c_date,
					'execution_time' => $data->e_time,
					'hook'           => $data->hook,
					'arguments'      => $data->args,
					'group'          => $data->group_slug,
					'recurring'      => $data->recurring_interval,
					'error'          => error_get_last(),
				);

				$this->log_forced( print_r( $log_arr, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- forced diagnostic log when claiming actions fails
			}

		}

		/**
		 * @param string $action_id
		 *
		 * @return void
		 */
		public function log_execution( $action_id ) {
			/** no need to log as we are managing logs differently, even attempts */
		}

		/**
		 * @param string $action_id
		 */
		public function mark_complete( $action_id ) {
			$this->log( __FUNCTION__ . ' for action id ' . $action_id );

			/** Keep the row as the next occurrence if its replacement could not be created */
			if ( true === $this->maybe_recycle_recurring_action( $action_id ) ) {
				return;
			}

			/** Deleting existing action */
			BWF_AS_Actions_Crud::delete( $action_id );
		}

		public function get_status( $action_id ) {
			$this->log( __FUNCTION__ . ' of action id ' . $action_id );

			$status = BWF_AS_Actions_Crud::get_action_status( $action_id );
			if ( null === $status ) {
				throw new InvalidArgumentException( esc_html__( 'Invalid action ID. No status found.', 'action-scheduler' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			} else {
				return $this->get_as_defined_status_val( $status );
			}
		}

		public function overriding_bwf_logging( $value, $filename ) {
			return true;
		}

		/**
		 * Cancel pending actions by hook.
		 *
		 * @param string $hook
		 *
		 * @since 3.0.0 Action Scheduler and 1.9.15 Core
		 */
		public function cancel_actions_by_hook( $hook ) {
			return;
		}

		/**
		 * Cancel pending actions by group.
		 *
		 * @param string $group
		 *
		 * @since 3.0.0 Action Scheduler and 1.9.15 Core
		 */
		public function cancel_actions_by_group( $group ) {
			return;
		}

	}
}