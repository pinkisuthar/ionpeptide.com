<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Settings_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $total_count = 0;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		$this->pagination         = new stdClass();
		$this->pagination->offset = 0;
		$this->pagination->limit  = 25;
		parent::__construct();
	}

	public function default_args_values() {
		return array(
			'bwfan_global_settings' => array(),
			'action_type'           => '',
			'search'                => '',
			'offset'                => 0,
			'limit'                 => 25,
			'unsubscribers'         => '',
			'unsubscribers_ids'     => '',
			'log_selected'          => '',
			'all'                   => false,
			'unlayer_data'          => [],
			'domain'                => '',
			'key'                   => '',
		);
	}

	protected function register_routes() {
		// GET + PUT /settings
		$this->add_route( '/settings', array(
			array( 'GET', 'get_settings' ),
			array( 'PUT', 'save_settings' ),
		) );

		// GET + POST /settings/tools
		$this->add_route( '/settings/tools', array(
			array( 'GET', 'get_tools_settings' ),
			array( 'POST', 'run_tool_action' ),
		) );

		// GET + POST + DELETE /settings/unsubscribers
		$this->add_route( '/settings/unsubscribers', array(
			array( 'GET', 'get_unsubscribers', array(
				'search' => array(
					'description' => __( 'Unsubscribers search', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'offset' => array(
					'description' => __( 'Unsubscribers list Offset', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'limit'  => array(
					'description' => __( 'Per page limit', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
			array( 'POST', 'add_unsubscribers' ),
			array( 'DELETE', 'delete_unsubscribers' ),
		) );

		// GET /settings/log-files
		$this->add_route( '/settings/log-files', array(
			array( 'GET', 'get_log_files' ),
		) );

		// GET /settings/log/view
		$this->add_route( '/settings/log/view', array(
			array( 'GET', 'view_log_file' ),
		) );

		// DELETE /settings/log/delete
		$this->add_route( '/settings/log/delete', array(
			array( 'DELETE', 'delete_log_file' ),
		) );

		// GET /settings/status/tables
		$this->add_route( '/settings/status/tables', array(
			array( 'GET', 'get_table_status' ),
		) );

		// GET /settings/status/autonami
		$this->add_route( '/settings/status/autonami', array(
			array( 'GET', 'get_status_autonami_worker' ),
		) );

		// GET /settings/status/event
		$this->add_route( '/settings/status/event', array(
			array( 'GET', 'get_status_event_worker' ),
		) );

		// GET /settings/status/woofunnels
		$this->add_route( '/settings/status/woofunnels', array(
			array( 'GET', 'get_status_woofunnels_endpoint' ),
		) );

		// POST /block-migrator
		$this->add_route( '/block-migrator', array(
			array( 'PUT', 'block_migrator' ),
		) );
	}

	/**
	 * GET /settings — fetch global settings and schema.
	 */
	public function get_settings() {
		$settings_values = BWFAN_Common::get_global_settings();
		$setting_schema  = BWFAN_Common::get_setting_schema();

		$this->response_code = 200;

		return $this->success_response(
			array(
				'schema' => $setting_schema,
				'values' => $settings_values,
			),
			__( 'Settings fetched successfully', 'wp-marketing-automations' )
		);
	}

	/**
	 * PUT /settings — save global settings.
	 */
	public function save_settings() {
		$settings = $this->args['bwfan_global_settings'];

		if ( empty( $settings ) ) {
			return $this->error_response( [], __( 'Settings Data is missing.', 'wp-marketing-automations' ) );
		}

		$old_value = get_option( 'bwfan_global_settings' );

		update_option( 'bwfan_global_settings', $settings, true );

		do_action( 'bwfan_after_save_global_settings', $old_value, $settings );

		$this->response_code = 200;

		$updated_settings = get_option( 'bwfan_global_settings' );
		$setting_schema   = BWFAN_Common::get_setting_schema();

		return $this->success_response( array(
			'schema' => $setting_schema,
			'values' => $updated_settings,
		), __( 'Settings updated', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /settings/tools — fetch available tool settings.
	 */
	public function get_tools_settings() {
		$tool_settings = [
			[
				"title"          => __( "Optimize Engagement Tracking Meta table", "wp-marketing-automations" ),
				"description"    => __( "Once this tool is run the personalisation merge tags will not be available on single contact profile. There will be no changes to open/click tracking data.", "wp-marketing-automations" ),
				"task"           => "delete_engagement_tracking_meta",
				"task_text"      => __( "Optimize", "wp-marketing-automations" ),
				"processingText" => __( "We are optimizing the engagement tracking meta table", "wp-marketing-automations" ),
			],
			[
				"title"          => __( "Verify Database Tables", "wp-marketing-automations" ),
				"description"    => __( "This will verify FunnelKit Automations all the base tables which are required for smooth functioning.", "wp-marketing-automations" ),
				"task"           => "bwfan_validate_db_tables",
				"task_text"      => __( "Verify", "wp-marketing-automations" ),
				"processingText" => __( "Validate required tables in database", "wp-marketing-automations" ),
			],
			[
				"title"          => __( "Verify Table Collations and columns", "wp-marketing-automations" ),
				"description"    => __( "Verifies the collation of all tables and columns and updates it to utf8mb4. Also verifies and fixes primary keys and auto-increment settings.", "wp-marketing-automations" ),
				"task"           => "bwfan_check_collation",
				"task_text"      => __( "Verify", "wp-marketing-automations" ),
				"processingText" => __( "We are verifying and fixing table collations, primary keys, and indexes", "wp-marketing-automations" ),
			],
		];

		if ( bwfan_is_woocommerce_active() ) {
			$tool_settings[] = array(
				"title"          => __( "Re-index Cart and Orders conversions for multi-currency setups", "wp-marketing-automations" ),
				"description"    => __( "This will reindex cart and conversion table for order price.", "wp-marketing-automations" ),
				"task"           => "re_index_cart_orders_conversion",
				"task_text"      => __( "Re-index", "wp-marketing-automations" ),
				"processingText" => __( "We are Re-indexing all cart total & conversion total", "wp-marketing-automations" ),
			);
			$tool_settings[] = array(
				"title"          => __( "Delete Lost Carts", "wp-marketing-automations" ),
				"description"    => __( "This will schedule the process to delete all the lost carts.", "wp-marketing-automations" ),
				"task"           => "delete_lost_carts",
				"task_text"      => __( "Delete", "wp-marketing-automations" ),
				"processingText" => __( "We are deleting all the lost carts", "wp-marketing-automations" ),
			);
			$tool_settings[] = array(
				"title"          => __( "Delete Dynamically Generated Coupons", "wp-marketing-automations" ),
				"description"    => __( "This will schedule the process to delete all expired coupons generated by FunnelKit Automations.", "wp-marketing-automations" ),
				"task"           => "delete_expired_coupons",
				"task_text"      => __( "Delete", "wp-marketing-automations" ),
				"processingText" => __( "We are deleting all dynamically generated expired coupons", "wp-marketing-automations" ),
			);
		}

		if ( BWFAN_Common::is_automation_v1_active() ) { // check for single autonami active
			$tool_settings[] = array(
				"title"          => __( "Run All Queued Tasks (Automations Legacy)", "wp-marketing-automations" ),
				"description"    => __( "This will schedule the process to  run now all the queued tasks.", "wp-marketing-automations" ),
				"task"           => "run_all_tasks",
				"task_text"      => __( "Schedule", "wp-marketing-automations" ),
				"processingText" => __( "We are schedule all the queued tasks to run now", "wp-marketing-automations" ),
			);

			$tool_settings[] = array(
				"title"          => __( "Delete All Completed Tasks (Automations Legacy)", "wp-marketing-automations" ),
				"description"    => __( "This will schedule the process to delete all the completed tasks.", "wp-marketing-automations" ),
				"task"           => "delete_completed_tasks",
				"task_text"      => __( "Delete", "wp-marketing-automations" ),
				"processingText" => __( "We are deleting all the completed tasks", "wp-marketing-automations" ),
			);

			$tool_settings[] = array(
				"title"          => __( "Delete All Failed Tasks (Automations Legacy)", "wp-marketing-automations" ),
				"description"    => __( "This will schedule the process to delete all the failed tasks.", "wp-marketing-automations" ),
				"task"           => "delete_failed_tasks",
				"task_text"      => __( "Delete", "wp-marketing-automations" ),
				"processingText" => __( "We are deleting all the failed tasks", "wp-marketing-automations" ),
			);
		}
		$is_opted        = WooFunnels_OptIn_Manager::get_optIn_state();
		$tool_settings[] = array(
			"title"          => __( "Usage Tracking", "wp-marketing-automations" ),
			"type"           => 'toggle',
			"description"    => __( "This action controls Usage Tracking", "wp-marketing-automations" ),
			"task"           => "toggle_usage_tracking",
			"status"         => ! empty( $is_opted ),
			"processingText" => __( "Updating usage tracking", "wp-marketing-automations" ),
		);

		$tool_settings = apply_filters( 'bwfan_global_tools_settings', $tool_settings );

		$this->response_code = 200;

		return $this->success_response( $tool_settings, __( 'Tool settings found', 'wp-marketing-automations' ) );
	}

	/**
	 * POST /settings/tools — execute a tool action.
	 */
	public function run_tool_action() {
		$action_type = $this->args['action_type'];

		if ( empty( $action_type ) ) {
			return $this->error_response( __( 'Action type is missing', 'wp-marketing-automations' ) );
		}

		$result = BWFAN_Common::run_global_tools( $action_type, $this->args );
		if ( isset( $result['status'] ) && false === $result['status'] ) {
			return $this->success_response( $result, __( 'Unable to execute action', 'wp-marketing-automations' ) );
		}

		$this->response_code = 200;
		$message             = $result['msg'] ?? __( 'Action executed', 'wp-marketing-automations' );

		return $this->success_response( $result, $message );
	}

	/**
	 * GET /settings/unsubscribers — list unsubscribers with pagination.
	 */
	public function get_unsubscribers() {
		$search = $this->get_sanitized_arg( 'search', 'text_field' );
		$offset = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? $this->get_sanitized_arg( 'offset', 'absint' ) : 0;
		$limit  = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;

		$get_unsubscribers = BWFAN_Common::get_unsubscribers( $search, $offset, $limit );
		if ( isset( $get_unsubscribers['found_posts'] ) ) {
			$this->total_count = $get_unsubscribers['found_posts'];
			unset( $get_unsubscribers['found_posts'] );
		}
		$this->response_code = 200;

		return $this->success_response( $get_unsubscribers, __( 'Unsubscribers found', 'wp-marketing-automations' ) );
	}

	/**
	 * POST /settings/unsubscribers — add new unsubscribers.
	 */
	public function add_unsubscribers() {
		$unsubscribers = $this->args['unsubscribers'];

		if ( empty( $unsubscribers ) ) {
			return $this->error_response( __( 'Unsubscriber data missing', 'wp-marketing-automations' ) );
		}

		$already_unsubscribe = array();
		foreach ( $unsubscribers as $email ) {
			$email            = sanitize_email( $email );
			$unsubscribe_data = BWFAN_Model_Message_Unsubscribe::get_specific_rows( 'recipient', $email );
			if ( ! empty( $unsubscribe_data[0] ) ) {
				$already_unsubscribe[] = $email;
				continue;
			}
			$insert_data = array(
				'recipient' => $email,
				'c_date'    => current_time( 'mysql' ),
				'c_type'    => 3,
			);

			BWFAN_Model_Message_Unsubscribe::insert( $insert_data );
			/** hook when any contact unsubscribed  */
			do_action( 'bwfcrm_after_contact_unsubscribed', array( $insert_data ) );
		}

		if ( ! empty( $already_unsubscribe ) && is_array( $already_unsubscribe ) ) {
			$message = implode( ',', $already_unsubscribe );

			return $this->error_response( __( 'Recipient already unsubscribe : ', 'wp-marketing-automations' ) . $message );
		}

		$this->response_code = 200;

		return $this->success_response( [], __( 'Recipient unsubscribed', 'wp-marketing-automations' ) );
	}

	/**
	 * DELETE /settings/unsubscribers — remove unsubscribers by IDs.
	 */
	public function delete_unsubscribers() {
		$unsubscribers_ids = $this->args['unsubscribers_ids'];

		if ( empty( $unsubscribers_ids ) ) {
			return $this->error_response( __( 'Unsubscribers IDs missing', 'wp-marketing-automations' ) );
		}

		$failed_ids = array();

		foreach ( $unsubscribers_ids as $id ) {
			$id = absint( $id );
			if ( empty( $id ) ) {
				continue;
			}

			$data = BWFAN_Model_Message_Unsubscribe::get( $id );
			if ( empty( $data ) ) {
				$failed_ids[] = $id;
				continue;
			}

			$where = array(
				'ID' => $id,
			);
			BWFAN_Model_Message_Unsubscribe::delete_message_unsubscribe_row( $where );

			do_action( 'bwfan_delete_unsubscriber', $data );
		}

		if ( ! empty( $failed_ids ) ) {
			/* translators: 1: comma seperated ids  */
			return $this->error_response( sprintf( __( 'Unable to delete some of unsubscribers with IDs: %1$s', 'wp-marketing-automations' ), implode( ', ', $failed_ids ) ) );
		}

		do_action( 'bwfan_bulk_delete_unsubscribers' );

		$this->response_code = 200;

		return $this->success_response( [], __( 'Unsubscribers deleted', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /settings/log-files
	 */
	public function get_log_files() {
		$file_list = [];
		if ( ! class_exists( 'BWF_Logger' ) ) {
			return $this->success_response( $file_list, __( 'No log files found', 'wp-marketing-automations' ) );
		}
		$logger_obj        = BWF_Logger::get_instance();
		$final_logs_result = $logger_obj->get_log_options();

		if ( isset( $final_logs_result['autonami-logs'] ) && ! empty( $final_logs_result['autonami-logs'] ) ) {
			foreach ( $final_logs_result['autonami-logs'] as $file_slug => $file_name ) {
				$option_value = 'autonami-logs/' . $file_slug;
				$file_list[]  = array(
					'value' => $option_value,
					'label' => $file_name,
				);
			}
		}

		$this->response_code = 200;

		return $this->success_response( $file_list, 'Log files found' );
	}

	/**
	 * GET /settings/log/view
	 */
	public function view_log_file() {
		$selected_log_file = $this->get_sanitized_arg( 'log_selected', 'text_field' );
		if ( empty( $selected_log_file ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Selected log file missing', 'wp-marketing-automations' ) );
		}
		$folder_prefix    = explode( '/', $selected_log_file );
		$folder_file_name = $folder_prefix[1];
		$folder_prefix    = $folder_prefix[0];
		$file_api         = new WooFunnels_File_Api( $folder_prefix );

		// View log submit is clicked, get the content from the selected file
		$content = $file_api->get_contents( $folder_file_name );
		if ( $content !== false ) {
			$this->response_code = 200;

			return $this->success_response( $content, 'Log file data fetched' );
		}
		$this->response_code = 404;

		return $this->error_response( __( 'Selected log file not found', 'wp-marketing-automations' ) );
	}

	/**
	 * DELETE /settings/log/delete
	 */
	public function delete_log_file() {
		$selected_log_file = $this->get_sanitized_arg( 'log_selected', 'text_field' );
		$all               = $this->get_sanitized_arg( 'all', 'bool' );

		if ( true === $all ) {
			$file_api = new WooFunnels_File_Api( 'autonami-logs' );

			$delete = $file_api->delete_all( 'autonami-logs', true );
			if ( $delete ) {
				$this->response_code = 200;

				return $this->success_response( [], 'Log files deleted' );
			}

			$this->response_code = 404;

			return $this->error_response( __( 'Log files not found', 'wp-marketing-automations' ) );
		}

		if ( empty( $selected_log_file ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Selected log file missing', 'wp-marketing-automations' ) );
		}
		$folder_prefix    = explode( '/', $selected_log_file );
		$folder_file_name = $folder_prefix[1];
		$folder_prefix    = $folder_prefix[0];
		$file_api         = new WooFunnels_File_Api( $folder_prefix );

		// View log submit is clicked, get the content from the selected file
		$delete = $file_api->delete_file( $folder_file_name );
		if ( $delete ) {
			$this->response_code = 200;

			return $this->success_response( [], 'Log file deleted' );
		}
		$this->response_code = 404;

		return $this->error_response( __( 'Selected log file not found', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /settings/status/tables
	 */
	public function get_table_status() {
		$automations_table_result = BWFAN_Common::checking_all_tables_exists();
		if ( is_array( $automations_table_result ) ) {
			$this->response_code = 404;
			$message             = 'Some tables are not created. ' . implode( ',', $automations_table_result );

			return $this->error_response( $message );
		}

		$this->response_code = 200;

		return $this->success_response( [], 'Tables created' );
	}

	/**
	 * GET /settings/status/autonami
	 */
	public function get_status_autonami_worker() {
		$url             = rest_url( 'autonami/v1/worker' ) . '?' . time();
		$body_data       = array(
			'worker'     => true,
			'unique_key' => get_option( 'bwfan_u_key', false ),
		);
		$timing          = isset( $_GET['time'] ) ? sanitize_text_field( wp_unslash( $_GET['time'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only worker status check; value only compared with a timestamp
		$args1           = bwf_get_remote_rest_args( $body_data );
		$autonami_worker = wp_remote_post( $url, $args1 );

		if ( $autonami_worker instanceof WP_Error ) {
			$this->response_code = 404;

			return $this->error_response( $autonami_worker->get_error_message() );
		}

		if ( isset( $autonami_worker['response'] ) && 200 === absint( $autonami_worker['response']['code'] ) ) {
			$this->response_code = 200;
			$body                = ! empty( $autonami_worker['body'] ) ? json_decode( $autonami_worker['body'], true ) : '';
			$time                = isset( $body['time'] ) ? strtotime( $body['time'] ) : time();
			if ( ! empty( $timing ) ) {
				if ( $time > $timing ) {
					return $this->success_response( array( 'time' => $time, 'cached' => false ), __( 'Not Cached', 'wp-marketing-automations' ) );
				} else {
					return $this->success_response( array( 'time' => $time, 'cached' => true ), __( 'Cached', 'wp-marketing-automations' ) );
				}
			}

			return $this->success_response( array( 'time' => $time, 'cached' => false ), __( 'Working', 'wp-marketing-automations' ) );
		}

		$message             = __( 'Not working', 'wp-marketing-automations' );
		$this->response_code = 404;

		return $this->error_response( $message );
	}

	/**
	 * GET /settings/status/event
	 */
	public function get_status_event_worker() {
		$url                   = rest_url( 'autonami/v1/events' );
		$body_data             = array(
			'worker'     => true,
			'unique_key' => get_option( 'bwfan_u_key', false ),
		);
		$args2                 = bwf_get_remote_rest_args( $body_data );
		$autonami_event_worker = wp_remote_post( $url, $args2 );
		$timing                = isset( $_GET['time'] ) ? sanitize_text_field( wp_unslash( $_GET['time'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only worker status check; value only compared with a timestamp

		if ( $autonami_event_worker instanceof WP_Error ) {
			$this->response_code = 404;

			return $this->error_response( $autonami_event_worker->get_error_message() );
		}

		if ( isset( $autonami_event_worker['response'] ) && 200 === absint( $autonami_event_worker['response']['code'] ) ) {
			$this->response_code = 200;
			$body                = ! empty( $autonami_event_worker['body'] ) ? json_decode( $autonami_event_worker['body'], true ) : '';
			$time                = isset( $body['time'] ) ? $body['time'] : time();
			if ( ! empty( $timing ) ) {
				if ( $time > $timing ) {
					return $this->success_response( array( 'time' => $time, 'cached' => false ), 'Not Cached' );
				} else {
					return $this->success_response( array( 'time' => $time, 'cached' => true ), 'Cached' );
				}
			}

			return $this->success_response( array( 'time' => $time, 'cached' => false ), "Working" );
		}

		$message             = 'Not working';
		$this->response_code = 404;

		return $this->error_response( $message );
	}

	/**
	 * GET /settings/status/woofunnels
	 */
	public function get_status_woofunnels_endpoint() {
		$url               = rest_url( '/woofunnels/v1/worker' ) . '?' . time();
		$args              = [ 'method' => 'GET', 'sslverify' => false, ];
		$woofunnels_worker = wp_remote_post( $url, $args );
		$timing            = isset( $_GET['time'] ) ? sanitize_text_field( wp_unslash( $_GET['time'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only worker status check; value only compared with a timestamp

		if ( $woofunnels_worker instanceof WP_Error ) {
			$this->response_code = 404;

			return $this->error_response( $woofunnels_worker->get_error_message() );
		}

		if ( isset( $woofunnels_worker['response'] ) && 200 === absint( $woofunnels_worker['response']['code'] ) ) {
			$this->response_code = 200;
			$body                = ! empty( $woofunnels_worker['body'] ) ? json_decode( $woofunnels_worker['body'], true ) : '';
			$time                = isset( $body['time'] ) ? strtotime( $body['time'] ) : time();
			if ( ! empty( $timing ) ) {
				if ( $time > $timing ) {
					return $this->success_response( array( 'time' => $time, 'cached' => false ), 'Not Cached' );
				} else {
					return $this->success_response( array( 'time' => $time, 'cached' => true ), 'Cached' );
				}
			}

			return $this->success_response( array( 'time' => $time, 'cached' => false ), "Working" );
		}

		$message             = 'Not working';
		$this->response_code = 404;

		return $this->error_response( $message );
	}

	/**
	 * POST /block-migrator
	 */
	public function block_migrator() {
		$unlayer_json = isset( $this->args['unlayer_data'] ) ? $this->args['unlayer_data'] : [];
		$domain       = isset( $this->args['domain'] ) ? $this->args['domain'] : '';
		$key          = isset( $this->args['key'] ) ? $this->args['key'] : '';

		if ( empty( $unlayer_json ) || empty( $domain ) || empty( $key ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Required data not found', 'wp-marketing-automations' ) );
		}
		if ( is_multisite() ) {
			$active_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $active_plugins ) && ( in_array( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ), true ) || array_key_exists( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ) ) ) && ! is_main_site() ) {
				$domain = get_site_url( get_main_site_id() );
			}
		}

		$body = [
			'domain'       => urldecode( $domain ),
			'key'          => $key,
			'unlayer_data' => $unlayer_json
		];

		$request = wp_remote_post( 'https://license.funnelkit.com/?wc-api=am-software-api&request=migrate_email', [
			'method'  => 'POST',
			'body'    => json_encode( $body ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		] );

		$result = json_decode( wp_remote_retrieve_body( $request ), true );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 || empty( $result ) || ! isset( $result['status'] ) || ! $result['status'] ) {
			$message = $result['message'] ?? __( 'Error occurred while migrating block', 'wp-marketing-automations' );
			$data    = [
				'status' => false,
			];

			if ( isset( $result['license_error'] ) ) {
				$data['license_error'] = ! empty( $result['license_error'] ) ? $result['license_error'] : __( 'License error occurred', 'wp-marketing-automations' );
			}

			return $this->success_response( $data, $message );
		}

		if ( empty( $result['block_data'] || empty( $result['block_data']['body'] ) || empty( $result['block_data']['setting'] ) ) ) {
			return $this->success_response( [
				'status' => false,
			], __( 'Error occurred while migrating block, data not found.', 'wp-marketing-automations' ) );
		}

		return $this->success_response( [
			'status'     => true,
			'block_data' => $result['block_data']
		], __( 'Data fetched successfully', 'wp-marketing-automations' ) );
	}

	public function get_result_total_count() {
		return $this->total_count;
	}
}

BWFAN_API_Settings_Controller::get_instance();
