<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @todo things to do
 * AS data store cli
 */
if ( ! class_exists( 'WooFunnels_AS_DS' ) ) {
	#[AllowDynamicProperties]
	final class WooFunnels_AS_DS {
		public static $unique = '';
		private static $ins = null;
		public $dir = __DIR__;

		/**
		 * WooFunnels_Actions constructor.
		 */
		public function __construct() {
			$enable_as_ds = apply_filters( 'enable_woofunnels_as_ds', false );
			if ( true !== $enable_as_ds && ! class_exists( 'BWFAN_Core' ) ) {
				return;
			}

			add_action( 'action_scheduler_pre_init', array( $this, 'load_files' ) );

			/** Rest API endpoint */
			add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );

			/** BWF Action Scheduler custom table worker callback */
			add_action( 'bwf_as_run_queue', array( $this, 'run_as_ct_worker' ) );
			add_action( 'action_scheduler_pre_init', array( $this, 'as_pre_init_cb' ) );

			/** Needs to code */
			add_action( 'action_scheduler_pre_init', array( $this, 'as_pre_init_cli_cb' ) );

			/** Creating tables */
			add_action( 'bwf_after_action_scheduler_load', [ $this, 'bwf_after_action_scheduler_load' ] );
		}

		/**
		 * @return WooFunnels_AS_DS instance
		 */
		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self();
			}

			return self::$ins;
		}

		/**
		 * Load files
		 */
		public function load_files() {

			/**
			 * These custom AS store/logger classes are only instantiated once change_data_store()
			 * has swapped AS over to them — which happens exclusively in worker (/woofunnels/v1/worker)
			 * and CLI contexts. On a plain frontend page view AS uses its default store, so parsing
			 * ~37KB here is pure waste. Load in every context EXCEPT plain frontend views (admin, cron,
			 * CLI and all REST requests — incl. the worker — still load them, so the worker path is
			 * unchanged). BWF_Kernel absent (older/sibling framework copy) => load unconditionally as before.
			 */
			if ( class_exists( 'BWF_Kernel' ) && ! BWF_Kernel::is_backend_context() ) {
				do_action( 'bwf_after_action_scheduler_load' );

				return;
			}

			foreach ( glob( $this->dir . '/db/class-*.php' ) as $file_name ) {
				require_once( $file_name );
			}
			foreach ( glob( $this->dir . '/asct/class-*.php' ) as $file_name ) {
				if ( false !== strpos( $file_name, '-cli.php' ) ) {
					/** Will load CLI when need to run */
					continue;
				}
				require_once( $file_name );
			}

			/** Loading WooFunnels Actions CLI */
			if ( version_compare( PHP_VERSION, '5.3', '>' ) ) {
				$this->load_cli();
			}

			do_action( 'bwf_after_action_scheduler_load' );
		}

		/**
		 * Load CLI file
		 */
		public function load_cli() {
			/** Not including files if Action Scheduler doesn't exist */
			if ( ! class_exists( 'ActionScheduler' ) ) {
				return;
			}

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				require_once $this->dir . '/asct/class-bwf-as-cli.php';
				WP_CLI::add_command( 'woofunnels-actions', 'BWF_AS_CLI' );
			}
		}

		/**
		 * Load Hooks after Action Scheduler is loaded
		 */
		public function bwf_after_action_scheduler_load() {
			/** Create action scheduler custom tables */
			add_filter( 'bwf_add_db_table_schema', [ $this, 'create_db_tables' ], 10, 2 );

			/** Un-schedule older WP cron event */
			add_action( 'admin_init', [ $this, 'maybe_set_bwf_ct_worker' ], 9 );

			/** Registering custom schedule */
			add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		}

		/**
		 * Create DB tables
		 * Actions and Action_Claim
		 */
		public function create_db_tables( $args, $tables ) {
			$max_index_length = 191;

			if ( $tables['version'] !== BWF_DB_VERSION || ! in_array( 'bwf_actions', $tables['tables'], true ) ) {
				$args[] = [
					'name'   => 'bwf_actions',
					'schema' => "CREATE TABLE `{table_prefix}bwf_actions` (
			          id bigint(20) unsigned NOT NULL auto_increment,
			          c_date datetime NOT NULL default '0000-00-00 00:00:00',
			          e_time int(12) NOT NULL default 0,
			          hook varchar(255) not null,
			          args longtext null,
			          status int(1) not null default 0 COMMENT '0 - Pending | 1 - Running',
			          recurring_interval int(10) not null default 0,
			          group_slug varchar(255) not null default 'woofunnels',
			          claim_id bigint(20) unsigned NOT NULL default 0,
					  PRIMARY KEY (id),
					  KEY id (id),
					  KEY e_time (e_time),
					  KEY hook (hook($max_index_length)),
					  KEY status (status),
					  KEY group_slug (group_slug($max_index_length)),
					  KEY claim_id (claim_id)
					) {table_collate};",
				];
			}

			if ( $tables['version'] !== BWF_DB_VERSION || ! in_array( 'bwf_action_claim', $tables['tables'], true ) ) {
				$args[] = [
					'name'   => 'bwf_action_claim',
					'schema' => "CREATE TABLE `{table_prefix}bwf_action_claim` (
					  id bigint(20) unsigned NOT NULL auto_increment,
					  date datetime NOT NULL default '0000-00-00 00:00:00',
					  PRIMARY KEY (id),
					  KEY date (date)
					) {table_collate};",
				];
			}

			return $args;
		}

		public function maybe_set_bwf_ct_worker() {
			$hook = 'bwf_as_ct_1min_worker';
			if ( wp_next_scheduled( $hook ) ) {
				$timestamp = wp_next_scheduled( $hook );

				wp_unschedule_event( $timestamp, $hook );
			}
		}

		public function add_cron_schedule( $schedules ) {
			$schedules['bwf_every_minute'] = apply_filters( 'bwf_every_minute_cron', array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => 'Every minute', // not translated: cron_schedules runs before init (WP_Site_Health schedules its weekly check pre-init) and __() there trips _load_textdomain_just_in_time
			) );

			return $schedules;
		}

		/**
		 * 1 min worker callback
		 */
		public function run_as_ct_worker() {
			$url  = rest_url( '/woofunnels/v1/worker' ) . '?' . time();
			$args = bwf_get_remote_rest_args( [], 'GET' );
			wp_remote_post( $url, $args );
		}

		/**
		 * Register WooFunnels Core WP endpoints
		 */
		public function register_endpoints() {
			register_rest_route( 'woofunnels/v1', '/worker', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_worker_callback' ),
				'permission_callback' => '__return_true',
			) );
		}

		/**
		 * action_scheduler_pre_init action hook
		 */
		public function as_pre_init_cb() {
			$is_worker_request = false;
			if ( isset( $_GET['rest_route'] ) && false !== strpos( bwf_clean( wp_unslash( $_GET['rest_route'] ) ), '/woofunnels/v1/worker' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only REST route detection; bwf_clean() sanitizes
				$is_worker_request = true;
			} else if ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( bwf_clean( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/woofunnels/v1/worker' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only route detection; bwf_clean() sanitizes
				$is_worker_request = true;
			}

			if ( false === $is_worker_request ) {
				return;
			}

			/** Rate-limit the worker endpoint. Loopback URL is unauthenticated; this caps how often it can swap the datastore and drain the queue. */
			if ( self::is_worker_rate_limited() ) {
				wp_send_json( array(
					'msg'  => 'rate_limited',
					'time' => date_i18n( 'Y-m-d H:i:s' ),
				), 429 );
			}

			if ( ! class_exists( 'BWF_AS' ) ) {
				return;
			}

			/** BWF_AS instance */
			$as_ct_ins = BWF_AS::instance();

			/** Set new AS CT data store */
			$as_ct_ins->change_data_store();

			/** Set unique key */
			self::$unique = time();
		}

		/**
		 * Returns true if the previous worker run is within the rate-limit window.
		 * Window is filterable via `bwf_worker_rate_limit_window`; default 20 seconds.
		 * Returning a non-positive number from the filter disables the limit.
		 *
		 * @return bool
		 */
		public static function is_worker_rate_limited() {
			if ( ! function_exists( 'bwf_options_get' ) ) {
				return false;
			}
			$last_run = absint( bwf_options_get( 'fk_core_worker_let', '', 0 ) );
			if ( $last_run <= 0 ) {
				return false;
			}
			$window = (int) apply_filters( 'bwf_worker_rate_limit_window', 20 );
			if ( $window <= 0 ) {
				return false;
			}

			return ( time() - $last_run ) < $window;
		}

		/**
		 * action_scheduler_pre_init action hook for autonami cli
		 */
		public function as_pre_init_cli_cb() {
			global $argv;

			if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
				return;
			}

			/**
			 * $argv holds arguments passed to script
			 * https://www.php.net/manual/en/reserved.variables.argv.php
			 */

			if ( ! is_array( $argv ) || 0 === count( $argv ) ) {
				WP_CLI::log( 'WooFunnels WP CLI arguments not found.' );

				return;
			}

			if ( ! isset( $argv[1] ) || 'woofunnels-actions' !== $argv[1] ) {
				return;
			}
			if ( ! isset( $argv[2] ) || 'run' !== $argv[2] ) {
				return;
			}

			if ( ! class_exists( 'BWF_AS' ) ) {
				WP_CLI::log( "BWF_AS class not found." );
			}

			/** BWF_AS instance */
			$as_ct_ins = BWF_AS::instance();

			/** Set new AS CT data store */
			$as_ct_ins->change_data_store();
		}

		/**
		 * Callback function for running WooFunnels actions
		 *
		 * @param WP_REST_Request $request
		 */
		public function rest_worker_callback( WP_REST_Request $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			/** Update execution time */
			if ( function_exists( 'bwf_options_update' ) ) {
				bwf_options_update( 'fk_core_worker_let', time() );
			}

			$this->worker_as_run();
			$resp = [];
			$resp['msg']       = 'success';
			$resp['time']      = date_i18n( 'Y-m-d H:i:s' );
			$resp['datastore'] = get_class( ActionScheduler_Store::instance() );

			/** Logs */
			if ( ( defined( 'BWF_CHECK_CRON_SCHEDULE' ) && true === BWF_CHECK_CRON_SCHEDULE ) || true === apply_filters( 'bwf_check_cron_schedule_logging', false ) ) {
				add_filter( 'bwf_logs_allowed', '__return_true', PHP_INT_MAX );
				$logger_obj = BWF_Logger::get_instance();
				$logger_obj->log( $resp['time'], 'fka-cron-check', 'autonami' );
			}
			wp_send_json( $resp );
		}

		/**
		 * Helper method to run action scheduler
		 */
		public function worker_as_run() {
			if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
				return;
			}

			/** Modify Action Scheduler filters */
			$this->modify_as_filters();;

			/**
			 * Runs on every worker request, i.e. also on stores where nobody opens wp-admin. Fired after
			 * modify_as_filters(), which calls remove_all_filters() on 7 AS filter names - firing this
			 * before that would let it silently strip any of those 7 filters a listener adds here.
			 * Plugins use this to re-arm their own recurring hooks if the row has gone missing.
			 */
			do_action( 'bwf_worker_before_as_run' );

			$as_ins = ActionScheduler_QueueRunner::instance();

			/** Run Action Scheduler worker */
			$as_ins->run();
		}

		public function modify_as_filters() {
			/** Remove all existing filters */
			remove_all_filters( 'action_scheduler_queue_runner_time_limit' );
			remove_all_filters( 'action_scheduler_queue_runner_batch_size' );
			remove_all_filters( 'action_scheduler_queue_runner_concurrent_batches' );
			remove_all_filters( 'action_scheduler_timeout_period' );
			remove_all_filters( 'action_scheduler_cleanup_batch_size' );
			remove_all_filters( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded' );
			remove_all_filters( 'action_scheduler_failure_period' );

			/** Adding all filters for Autonami Action Scheduler only */
			add_filter( 'action_scheduler_queue_runner_time_limit', function () {
				return 20;
			}, 998 );
			add_filter( 'action_scheduler_queue_runner_batch_size', function () {
				return 20;
			}, 998 );
			add_filter( 'action_scheduler_queue_runner_concurrent_batches', function () {
				return 5;
			}, 998 );
			add_filter( 'action_scheduler_timeout_period', function () {
				return 300;
			}, 998 );
			add_filter( 'action_scheduler_cleanup_batch_size', function () {
				return 20;
			}, 998 );
			add_filter( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded', function ( $val, $ins, $processed_actions, $execution_time, $max_execution_time ) {
				return ( $execution_time > $max_execution_time );
			}, 998, 5 );
			add_filter( 'action_scheduler_failure_period', function () {
				return 180;
			}, 999 );
		}

		/**
		 * Deprecated
		 * Since v1.9.82
		 */
		public function fallback_execution_on_heartbeat() {

			/**
			 * Added the filter so that we can keep this heartbeat off by default and any plugin from our family can hook into it
			 */
			if ( ( true === apply_filters( 'bwf_as_ds_should_register_heartbeat', false ) ) || class_exists( 'BWFAN_Core' ) ) {
				add_action( 'heartbeat_tick', [ $this, 'heartbeat_callback' ] );
			}
		}

		public function heartbeat_callback() {
			$save_time = get_option( 'bwf_heartbeat_run', time() );
			if ( time() < $save_time ) {
				return;
			}

			$url  = rest_url( '/woofunnels/v1/worker' ) . '?' . time();
			$args = bwf_get_remote_rest_args( [], 'GET' );
			wp_remote_post( $url, $args );

			/** Value changes every minute — keep it out of alloptions so persistent object caches are not invalidated on every write */
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( 'bwf_heartbeat_run', false );
			}
			update_option( 'bwf_heartbeat_run', ( time() + 60 ), false );
		}

	}

	WooFunnels_AS_DS::get_instance();
}
/**
 * Schedule single action
 *
 * @param $timestamp
 * @param $hook
 * @param array $args
 * @param string $group
 *
 * @return bool|int
 */
function bwf_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	if ( ! class_exists( 'BWF_AS_Actions_Crud' ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Method is called before plugins_loaded hook.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	if ( ! class_exists( 'ActionScheduler' ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Action Scheduler class not found.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	if ( empty( $hook ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Hook is a required entity.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}

	$data = array(
		'c_date'   => current_time( 'mysql', 1 ),
		'e_time'   => (int) $timestamp,
		'hook'     => $hook,
		'claim_id' => 0
	);
	if ( is_array( $args ) && count( $args ) > 0 ) {
		$data['args'] = wp_json_encode( $args );
	}
	if ( ! empty( $group ) ) {
		$data['group_slug'] = $group;
	}

	/** $wpdb->insert_id keeps the previous statement's value on failure, so never read it without checking the insert first */
	if ( false === BWF_AS_Actions_Crud::insert( $data ) ) {
		return false;
	}

	$inserted_id = BWF_AS_Actions_Crud::insert_id();

	return $inserted_id;
}

/**
 * Schedule recurring action
 *
 * @param $timestamp
 * @param int $interval_in_seconds - should be min 1 otherwise not recurring
 * @param $hook
 * @param array $args
 * @param string $group
 *
 * @return bool|int
 */
function bwf_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args = array(), $group = '' ) {
	if ( ! class_exists( 'BWF_AS_Actions_Crud' ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Method is called before plugins_loaded hook.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	if ( ! class_exists( 'ActionScheduler' ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Action Scheduler class not found.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	if ( empty( $hook ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Hook is a required entity.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}

	$recurring_interval = ( (int) $interval_in_seconds > 0 ) ? (int) $interval_in_seconds : 0;

	$data = array(
		'c_date'             => current_time( 'mysql', 1 ),
		'e_time'             => (int) $timestamp,
		'hook'               => $hook,
		'recurring_interval' => $recurring_interval,
		'claim_id'           => 0
	);
	if ( is_array( $args ) && count( $args ) > 0 ) {
		$data['args'] = wp_json_encode( $args );
	}
	if ( ! empty( $group ) ) {
		$data['group_slug'] = $group;
	}

	/** $wpdb->insert_id keeps the previous statement's value on failure, so never read it without checking the insert first */
	if ( false === BWF_AS_Actions_Crud::insert( $data ) ) {
		return false;
	}

	$inserted_id = BWF_AS_Actions_Crud::insert_id();

	return $inserted_id;
}

/**
 * Unschedule actions based on given hook or args or group
 *
 * @param $hook
 * @param array $args
 * @param string $group
 *
 * @return bool
 */
function bwf_unschedule_actions( $hook, $args = array(), $group = '' ) {
	if ( ! class_exists( 'BWF_AS_Actions_Crud' ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Method is called before plugins_loaded hook.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	if ( empty( $hook ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Hook is a required entity.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	$arr = array(
		'hook' => $hook,
	);
	if ( is_array( $args ) && count( $args ) > 0 ) {
		$arr['args'] = $args;
	}
	if ( ! empty( $group ) ) {
		$arr['group_slug'] = $group;
	}

	$action_ids = BWF_AS_Actions_Crud::find_actions( $arr );
	if ( false === $action_ids ) {
		_doing_it_wrong( __FUNCTION__, __( 'No actions found for data: ', 'wp-marketing-automations' ) . print_r( $arr, true ), BWF_VERSION ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch, WordPress.PHP.DevelopmentFunctions.error_log_print_r
	}

	BWF_AS_Actions_Crud::delete_actions( $action_ids );

	return true;
}

/**
 * Check if action is already scheduled based on given hook or args or group
 *
 * @param $hook
 * @param array $args
 * @param string $group
 * @param null|int $status Match only rows in this status. Null (default) matches any status, which is what
 *                         callers asking "does this hook exist at all" want. Callers deciding whether to
 *                         re-schedule a hook should pass 0 (pending): a row stuck at 1 (running) or 2
 *                         (duplicate run detected) will never execute, and treating it as scheduled is what
 *                         leaves a recurring hook dead.
 *
 * @return bool
 */
function bwf_has_action_scheduled( $hook, $args = array(), $group = '', $status = null ) {
	if ( empty( $hook ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Hook is a required entity.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'bwf_actions';

	$sql          = "SELECT `id` FROM $table WHERE `hook` LIKE %s";
	$sql_params   = [];
	$sql_params[] = trim( $hook );

	if ( is_array( $args ) && count( $args ) > 0 ) {
		$sql          .= ' AND `args` LIKE %s';
		$sql_params[] = wp_json_encode( $args );
	}

	if ( ! empty( trim( $group ) ) ) {
		$sql          .= ' AND `group_slug` LIKE %s';
		$sql_params[] = trim( $group );
	}

	if ( ! is_null( $status ) ) {
		$sql          .= ' AND `status` = %d';
		$sql_params[] = (int) $status;
	}

	$sql .= ' LIMIT 0,1';

	$sql = $wpdb->prepare( $sql, $sql_params ); //phpcs:ignore WordPress.DB.PreparedSQL


	$action_ids = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared at 612; table from prefix, all values bound

	return ( intval( $action_ids ) > 0 );
}

/**
 * Check if action is running based on given hook or args or group
 *
 * @param $hook
 * @param array $args
 * @param string $group
 *
 * @return bool
 */
function bwf_is_action_running( $hook, $args = array(), $group = '' ) {
	if ( ! class_exists( 'BWF_AS_Actions_Crud' ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Method is called before plugins_loaded hook.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	if ( empty( $hook ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Hook is a required entity.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	$arr = array(
		'hook' => $hook,
	);
	if ( is_array( $args ) && count( $args ) > 0 ) {
		$arr['args'] = $args;
	}
	if ( ! empty( $group ) ) {
		$arr['group_slug'] = $group;
	}
	$arr['status'] = 1;

	$action_ids = BWF_AS_Actions_Crud::find_actions( $arr );
	if ( false === $action_ids ) {
		return false;
	}

	return $action_ids;
}

/**
 * Delete action by action id
 *
 * @param array $action_ids
 *
 * @return bool|int
 */
function bwf_delete_action( $action_ids = [] ) {
	if ( ! class_exists( 'BWF_AS_Actions_Crud' ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Method is called before plugins_loaded hook.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	if ( empty( $action_ids ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Action ID is required.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}

	$delete_count = BWF_AS_Actions_Crud::delete_actions( $action_ids );
	if ( false === $action_ids ) {
		return false;
	}

	return $delete_count;
}

/**
 * Get scheduled actions count based on given hook or args or group
 *
 * @param $hook
 * @param array $args
 * @param string $group
 * @param string $status
 * @param string $recurring
 *
 * @return bool|int
 */
function bwf_scheduled_action_count( $hook, $args = array(), $group = '', $status = '0', $recurring = 'all' ) {
	if ( ! class_exists( 'BWF_AS_Actions_Crud' ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Method is called before plugins_loaded hook.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	if ( empty( $hook ) ) {
		_doing_it_wrong( __FUNCTION__, __( 'Hook is a required entity.', 'wp-marketing-automations' ), BWF_VERSION ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch

		return false;
	}
	$arr = array(
		'hook' => $hook,
	);
	if ( is_array( $args ) && count( $args ) > 0 ) {
		$arr['args'] = $args;
	}
	if ( ! empty( $group ) ) {
		$arr['group_slug'] = $group;
	}
	if ( '' !== $status ) {
		$arr['status'] = $status;
	}
	if ( 'recurring' === $recurring ) {
		$arr['recurring_interval'] = '0';
	}

	$action_ids = BWF_AS_Actions_Crud::find_actions( $arr );

	if ( false === $action_ids ) {
		return false;
	}

	return count( $action_ids );
}

/**
 * Delete recurring action
 *
 * @param $hook
 * @param $status
 * @param $exclude_id
 *
 * @return void
 */
function bwf_delete_scheduled_recurring_action( $hook, $status, $exclude_id = '' ) {
	if ( empty( $hook ) ) {
		return;
	}
	global $wpdb;
	$query = "DELETE FROM {$wpdb->prefix}bwf_actions WHERE `hook`=%s AND `status` = %d";
	$args  = [ $hook, $status ];
	if ( ! empty( $exclude_id ) ) {
		$query  .= " AND `id` != %d";
		$args[] = $exclude_id;
	}
	$query = $wpdb->prepare( $query, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared: prefixed table, hook/status/exclude_id bound, counts match
	$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Executes string returned by prepare at 753, nothing appended
}
