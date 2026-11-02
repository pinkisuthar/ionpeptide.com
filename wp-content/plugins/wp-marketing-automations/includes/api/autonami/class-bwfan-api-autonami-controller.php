<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consolidated controller for all Autonami API routes.
 *
 * Replaces 24 individual API files in includes/api/autonami/ with a single
 * controller class that registers all routes and contains all handler methods.
 */
class BWFAN_API_Autonami_Controller extends BWFAN_API_Controller {

	public static $ins;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	/** Properties carried over from individual classes */
	public $total_count = 0;
	public $count_data  = 0;

	protected function register_routes() {

		/** Email Preview — PUT /autonami/email-preview */
		$this->add_route( '/autonami/email-preview', array(
			array( 'PUT', 'email_preview', array(
				'content' => array(
					'default' => 0,
				),
			) ),
		) );

		/** Send Test Email — POST /autonami/send-test-email */
		$this->add_route( '/autonami/send-test-email', array(
			array( 'POST', 'send_test_email', array(
				'email'   => array(
					'default' => '',
				),
				'content' => array(
					'default' => 0,
				),
			) ),
		) );

		/** Get Automations — GET /automations */
		$this->add_route( '/automations', array(
			array( 'GET', 'get_automations', array(
				'search'   => array(
					'description' => __( 'Autonami Search', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'status'   => array(
					'description' => __( 'Autonami Status', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'offset'   => array(
					'description' => __( 'Autonami list Offset', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'limit'    => array(
					'description' => __( 'Per page limit', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'category' => array(
					'description' => __( 'Autonami list Category', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
			) ),
		) );

		/** Create Automation — POST /automations/create */
		$this->add_route( '/automations/create', array(
			array( 'POST', 'create_automation', array(
				'title' => array(
					'default' => '',
				),
			) ),
		) );

		/** Delete Automations — DELETE /automations/ */
		$this->add_route( '/automations/', array(
			array( 'DELETE', 'delete_automations', array(
				'automation_ids' => array(
					'default' => array(),
				),
			) ),
		) );

		/** Toggle Automation State — PUT /automations/toggle-state */
		$this->add_route( '/automations/toggle-state', array(
			array( 'PUT', 'toggle_automation_state', array(
				'state'         => array(
					'default' => false,
				),
				'automation_id' => array(
					'default' => null,
				),
			) ),
		) );

		/** Import Automation — POST /automations/import */
		$this->add_route( '/automations/import', array(
			array( 'POST', 'import_automation', array(
				'json' => array(
					'default' => null,
				),
			) ),
		) );

		/** Export Automations — GET /automations/export/ */
		$this->add_route( '/automations/export/', array(
			array( 'GET', 'export_automations' ),
		) );

		/** Export Single Automation — GET /automations/(?P<automation_id>[\d]+)/export/ */
		$this->add_route( '/automations/(?P<automation_id>[\\d]+)/export/', array(
			array( 'GET', 'export_automation_single', array(
				'automation_id' => array(
					'default' => null,
				),
			) ),
		) );

		/** Duplicate Automation — POST /automations/(?P<automation_id>[\d]+)/duplicate/ */
		$this->add_route( '/automations/(?P<automation_id>[\\d]+)/duplicate/', array(
			array( 'POST', 'duplicate_automation', array(
				'automation_id' => array(
					'default' => null,
				),
			) ),
		) );

		/** Delete Logs — DELETE /automations/logs/ */
		$this->add_route( '/automations/logs/', array(
			array( 'DELETE', 'delete_logs', array(
				'log_ids' => array(
					'default' => array(),
				),
			) ),
		) );

		/** Delete Tasks — DELETE /automations/tasks/ */
		$this->add_route( '/automations/tasks/', array(
			array( 'DELETE', 'delete_tasks', array(
				'task_ids' => array(
					'default' => array(),
				),
			) ),
		) );

		/** Run Automation Task — PUT /automations/task/ */
		$this->add_route( '/automations/task/', array(
			array( 'PUT', 'run_automation_task', array(
				'task_id' => array(
					'default' => '',
				),
			) ),
		) );

		/** Run Automation Tasks — PUT /automations/run-tasks/ */
		$this->add_route( '/automations/run-tasks/', array(
			array( 'PUT', 'run_automation_tasks', array(
				'task_ids' => array(
					'default' => '',
				),
			) ),
		) );

		/** Execute Automation Tasks — PUT /automations/execute-tasks/ */
		$this->add_route( '/automations/execute-tasks/', array(
			array( 'PUT', 'execute_automation_tasks', array(
				'task_ids' => array(
					'default' => '',
				),
			) ),
		) );

		/** Get Task Filters — GET /automations/task-filters */
		$this->add_route( '/automations/task-filters', array(
			array( 'GET', 'get_task_filters' ),
		) );

		/** Get Task History — GET /automations/task-history */
		$this->add_route( '/automations/task-history', array(
			array( 'GET', 'get_task_history', array(
				'status'        => array(
					'description' => __( 'Task Status', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'search'        => array(
					'description' => __( 'Search', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'automation_id' => array(
					'description' => __( 'Automation ID', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'action_slug'   => array(
					'description' => __( 'Action Slug', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'offset'        => array(
					'description' => __( 'Task list Offset', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'limit'         => array(
					'description' => __( 'Per page limit', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
		) );

		/** Get Automation Stats — GET /automations-stats */
		$this->add_route( '/automations-stats', array(
			array( 'GET', 'get_automation_stats' ),
		) );

		/** Get Actions — GET /actions */
		$this->add_route( '/actions', array(
			array( 'GET', 'get_actions' ),
		) );

		/** Get Events — GET /events */
		$this->add_route( '/events', array(
			array( 'GET', 'get_events' ),
		) );

		/** Get Event Data — GET /event/ */
		$this->add_route( '/event/', array(
			array( 'GET', 'get_event_data', array(
				'source'     => array(
					'description' => __( 'Source for get actions.', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'event_slug' => array(
					'description' => __( 'Event slug for get event\'s data', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
			) ),
		) );

		/** Search Automations — GET /search/automations */
		$this->add_route( '/search/automations', array(
			array( 'GET', 'search_automations', array(
				'search' => array(
					'description' => __( 'Automation Search', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
			) ),
		) );

		/** Import Recipe — POST /recipe/import */
		$this->add_route( '/recipe/import', array(
			array( 'POST', 'import_recipe', array(
				'recipe_slug' => array(
					'default' => '',
				),
				'title'       => array(
					'default' => '',
				),
			) ),
		) );

		/** License — POST /license */
		$this->add_route( '/license', array(
			array( 'POST', 'license', array(
				'action' => array(
					'default' => '',
				),
				'key'    => array(
					'default' => '',
				),
				'name'   => array(
					'default' => '',
				),
			) ),
		) );
	}

	/**
	 * Default args — used by handle_request() for wp_parse_args().
	 * Merged from all original default_args_values() methods.
	 */
	public function default_args_values() {
		return array(
			'content'        => 0,
			'email'          => '',
			'title'          => '',
			'automation_ids' => array(),
			'automation_id'  => null,
			'log_ids'        => array(),
			'task_ids'       => '',
			'task_id'        => '',
			'state'          => false,
			'json'           => null,
			'recipe_slug'    => '',
			'action'         => '',
			'key'            => '',
			'name'           => '',
			'search'         => '',
			'status'         => 'all',
			'offset'         => 0,
			'limit'          => 25,
			'category'       => '',
			'action_slug'    => null,
			'ids'            => array(),
		);
	}

	// =========================================================================
	// Handler: Email Preview
	// From: class-bwfan-api-autonami-email-preview.php
	// =========================================================================

	public function email_preview() {

		$content = '';
		$type    = ! empty( $this->args['type'] ) ? $this->args['type'] : 'rich';
		if ( ! empty( $this->args['content'] ) ) {
			$content = $this->args['content'];
		}
		$data = [];

		/** getting the template type in id */
		switch ( $type ) {
			case 'rich':
				$data['template'] = 1;
				break;
			case 'wc':
				if ( class_exists( 'WooCommerce' ) ) {
					$data['template'] = 2;
				}
				break;
			case 'html':
				$data['template'] = 3;
				break;
			case 'editor':
				if ( bwfan_is_autonami_pro_active() ) {
					$data['template'] = 4;
				}
				break;
			case 'block':
				if ( bwfan_is_autonami_pro_active() ) {
					$data['template'] = 5;
				}
				break;
			default:
				$data['template'] = 1;
		}

		BWFAN_Common::bwfan_before_send_mail( $type );
		BWFAN_Merge_Tag_Loader::set_data( array(
			'is_preview' => true,
		) );

		$body = BWFAN_Common::correct_shortcode_string( $content, $type );
		$body = BWFAN_Common::decode_merge_tags( $body );
		$body = BWFAN_Common::bwfan_correct_protocol_url( $body );

		$data['body']  = $body;
		$action_object = BWFAN_Core()->integration->get_action( 'wp_sendemail' );
		if ( null === $action_object && class_exists( 'BWFAN_Wp_Sendemail' ) ) {
			/** Try to get the instance directly */
			$action_object = BWFAN_Wp_Sendemail::get_instance();
		}
		if ( null === $action_object || ! method_exists( $action_object, 'email_content_v2' ) ) {
			$this->response_code = 500;

			return $this->error_response( __( 'Email action handler not available', 'wp-marketing-automations' ) );
		}

		$body                = $action_object->email_content_v2( $data );
		$this->response_code = 200;

		return $this->success_response( [ 'body' => $body ] );
	}

	// =========================================================================
	// Handler: Send Test Email
	// From: class-bwfan-api-send-test-email.php
	// =========================================================================

	public function send_test_email() {
		$content = isset( $this->args['content'] ) ? $this->args['content'] : [];
		if ( empty( $content ) ) {
			return $this->error_response( __( 'No mail data found', 'wp-marketing-automations' ) );
		}

		$content = BWFAN_Common::is_json( $content ) ? json_decode( $content, true ) : $content;
		if ( isset( $content['mail_data'] ) && is_array( $content['mail_data'] ) ) {
			$mail_data = $content['mail_data'];
			unset( $content['mail_data'] );
			$content = array_replace( $content, $mail_data );
		}
		$content['email'] = $this->get_sanitized_arg( 'email', 'text_field' );
		if ( BWFAN_Common::send_test_email( $content ) ) {
			return $this->success_response( '', __( 'Test email sent', 'wp-marketing-automations' ) );
		}

		return $this->error_response( __( 'Unable to send test email', 'wp-marketing-automations' ), null, 500 );
	}

	// =========================================================================
	// Handler: Get Automations
	// From: class-bwfan-api-get-automations.php
	// =========================================================================

	public function get_automations() {
		$status       = $this->get_sanitized_arg( 'status', 'text_field' );
		$search       = $this->get_sanitized_arg( 'search', 'text_field' );
		$offset_arg   = $this->get_sanitized_arg( 'offset', 'absint' );
		$offset       = ! empty( $offset_arg ) ? absint( $offset_arg ) : 0;
		$limit_arg    = $this->get_sanitized_arg( 'limit', 'absint' );
		$limit        = ! empty( $limit_arg ) ? absint( $limit_arg ) : 25;
		$version      = isset( $this->args['version'] ) ? $this->args['version'] : 1;
		$category_arg = $this->get_sanitized_arg( 'category', 'text_field' );
		$category     = ! empty( $category_arg ) ? $category_arg : '';
		$get_automations = BWFAN_Common::get_all_automations( $search, $status, $offset, $limit, false, $version, $category );

		if ( ! is_array( $get_automations ) || ! isset( $get_automations['automations'] ) || ! is_array( $get_automations['automations'] ) ) {
			return $this->error_response( __( 'Unable to fetch automations', 'wp-marketing-automations' ), null, 500 );
		}

		/** Check if worker call is late */
		$last_run = bwf_options_get( 'fk_core_worker_let' );
		if ( '' !== $last_run && ( ( time() - $last_run ) > BWFAN_Common::get_worker_delay_timestamp() ) ) {
			/** Worker is running late */
			$get_automations['worker_delayed'] = time() - $last_run;
		}

		/** Check basic worker last run time and status code check */
		$resp = BWFAN_Common::validate_core_worker();
		if ( isset( $resp['response_code'] ) ) {
			$get_automations['response_code'] = $resp['response_code'];
		}

		$this->total_count = isset( $get_automations['total_records'] ) ? absint( $get_automations['total_records'] ) : 0;
		$this->count_data  = BWFAN_Common::get_automation_data_count( $version );

		return $this->success_response( $get_automations, __( 'Automations found', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Create Automation
	// From: class-bwfan-api-create-automation.php
	// =========================================================================

	public function create_automation() {
		$title   = $this->args['title'];
		$version = $this->args['version'];

		if ( empty( $title ) ) {
			return $this->error_response( __( 'Title is missing', 'wp-marketing-automations' ) );
		}

		if ( $version ) {
			$data = [
				'title'  => $title,
				'status' => 2,
				'v'      => 2
			];

			$automation_id = BWFAN_Model_Automations_V2::create_new_automation( $data );

			if ( intval( $automation_id ) > 0 ) {
				global $wpdb;
				$metatable = $wpdb->prefix . 'bwfan_automationmeta';
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; automation_id is absint() insert_id
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; automation_id is absint() insert_id
				$wpdb->query( "INSERT INTO $metatable ( bwfan_automation_id, meta_key, meta_value ) VALUES
					( $automation_id, 'steps', '' ),
					( $automation_id, 'links', '' ),
					( $automation_id, 'count', 0 ),
                   	( $automation_id, 'requires_update', 0 ),
                   	( $automation_id, 'step_iteration_array', '' ),
                   	( $automation_id, 'merger_points', '' )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; automation_id is absint() insert_id
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		} else {
			$automation_id = BWFAN_Core()->automations->create_automation( $title );
		}

		$this->response_code = 200;

		return $this->success_response( [ 'automation_id' => $automation_id ], __( 'Automation created', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Delete Automations
	// From: class-bwfan-api-delete-automations.php
	// =========================================================================

	public function delete_automations() {
		$automation_ids          = $this->args['automation_ids'];
		$not_deleted_automations = array();

		if ( empty( $automation_ids ) || ! is_array( $automation_ids ) ) {
			return $this->error_response( __( 'Automations ids is missing.', 'wp-marketing-automations' ) );
		}

		foreach ( $automation_ids as $automation_id ) {
			$event_details = BWFAN_Model_Automations::get( $automation_id );
			$ids           = array( $automation_id );
			if ( empty( $event_details ) ) {
				$not_deleted_automations[] = $automation_id;
				continue;
			}

			/** Initiate automation object */
			$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );
			if ( ! empty( $automation_obj->error ) ) {
				continue;
			}
			$data = $automation_obj instanceof BWFAN_Automation_V2 ? $automation_obj->get_automation_data() : [];
			if ( 2 === intval( $data['v'] ) ) {
				$automation_obj->delete_migrations( $automation_id );
			}
			BWFAN_Core()->automations->delete_automation( $ids );
			BWFAN_Core()->automations->delete_automationmeta( $ids );

			if ( 1 === intval( $data['v'] ) ) {
				BWFAN_Core()->tasks->delete_tasks( array(), $ids );
				BWFAN_Core()->logs->delete_logs( array(), $ids );

				// Set status of logs to 0, so that run now option for those logs can be hide
				BWFAN_Model_Logs::update( array(
					'status' => 0,
				), array(
					'automation_id' => $automation_id,
				) );
			}
			BWFAN_Core()->automations->set_automation_id( $automation_id );
			do_action( 'bwfan_automation_deleted', $automation_id );
		}

		if ( ! empty( $not_deleted_automations ) ) {
			$message = __( 'Unable to Delete Automations with ids', 'wp-marketing-automations' ) . ': ' . implode( ', ', $not_deleted_automations );

			return $this->success_response( [], $message );
		}

		return $this->success_response( [], __( 'Automations deleted', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Toggle Automation State
	// From: class-bwfan-api-toggle-automation-state.php
	// =========================================================================

	public function toggle_automation_state() {
		$state         = $this->get_sanitized_arg( 'state', 'text_field' );
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'text_field' );

		if ( empty( $automation_id ) ) {
			return $this->error_response( 'Automation ID is missing', 'wp-marketing-automations' );
		}

		$automation['status'] = 2;
		if ( 1 === absint( $state ) ) {
			$automation['status'] = 1;
		}

		if ( 4 === absint( $state ) ) {
			$result = BWFAN_Model_Automationmeta::insert_automation_meta_data( $automation_id, [
				'v1_migrate' => true,
			] );
		} else {
			$result = BWFAN_Core()->automations->toggle_state( $automation_id, $automation );
		}

		if ( false === $result ) {
			return $this->error_response( 'Unable to update automation', 'wp-marketing-automations' );
		}
		$this->response_code = 200;

		return $this->success_response( [], __( 'Automation updated', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Import Automation
	// From: class-bwfan-api-import-automation.php
	// =========================================================================

	public function import_automation() {
		$this->response_code = 404;

		$files   = $this->args['files'];
		$version = isset( $this->args['version'] ) ? $this->args['version'] : 1;

		if ( empty( $files ) || ! isset( $files['files'] ) || ! is_uploaded_file( $files['files']['tmp_name'] ) ) {
			return $this->error_response( __( 'Import File missing.', 'wp-marketing-automations' ) );
		}

		$file = $files['files'];

		// Validate file extension
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'json' !== $ext ) {
			return $this->error_response( __( 'Invalid file type. Only JSON files allowed.', 'wp-marketing-automations' ) );
		}

		$file_data = file_get_contents( $file['tmp_name'] );

		if ( false === $file_data ) {
			return $this->error_response( __( 'Unable to read import file', 'wp-marketing-automations' ) );
		}

		$import_file_data = json_decode( $file_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $this->error_response( __( 'Invalid JSON file format', 'wp-marketing-automations' ) );
		}

		if ( empty( $import_file_data ) || ! is_array( $import_file_data ) ) {
			return $this->error_response( __( 'Import file data missing', 'wp-marketing-automations' ) );
		}
		/** Importing Automation */
		$automation_id = BWFAN_Core()->automations->import( $import_file_data, '', [], false, $version );

		if ( empty( $automation_id ) ) {
			return $this->error_response( __( 'Invalid json file', 'wp-marketing-automations' ) );
		}

		if ( is_array( $automation_id ) && isset( $automation_id['version'] ) ) {
			$next_gen = ( 2 === absint( $version ) ) ? " Next Gen" : '';

			/* translators: 1: Automation version */

			return $this->error_response( sprintf( __( 'You are trying to import an unsupported JSON format. Ensure that imported file belongs to Autonami %1$s', 'wp-marketing-automations' ), $next_gen ) );
		}

		$this->response_code = 200;

		return $this->success_response( [ 'automation_id' => $automation_id ], __( 'Automation imported', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Export Automations
	// From: class-bwfan-api-export-automations.php
	// =========================================================================

	public function export_automations() {
		$version                     = ( isset( $this->args['version'] ) && '' !== $this->args['version'] ) ? $this->args['version'] : 1;
		$ids                         = isset( $this->args['ids'] ) ? explode( ',', $this->args['ids'] ) : [];
		$get_export_automations_data = BWFAN_Core()->automations->get_json( $ids, $version );

		$this->response_code = 200;

		return $this->success_response( $get_export_automations_data, __( 'Automations exported', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Export Single Automation
	// From: class-bwfan-api-export-automation-single.php
	// =========================================================================

	public function export_automation_single() {
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'key' );

		$get_export_automations_data = BWFAN_Core()->automations->get_json( $automation_id );

		$this->response_code = 200;

		return $this->success_response( $get_export_automations_data, __( 'Automation exported', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Duplicate Automation
	// From: class-bwfan-api-duplicate-automations.php
	// =========================================================================

	public function duplicate_automation() {
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'key' );

		if ( empty( $automation_id ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Automation id is missing', 'wp-marketing-automations' ) );
		}

		$id = BWFAN_Core()->automations->duplicate( $automation_id );

		if ( empty( $id ) ) {
			$this->response_code = 400;

			/* translators: 1: Automation ID */

			return $this->error_response( sprintf( __( 'Unable to create Duplicate automation for id: %1$d', 'wp-marketing-automations' ), $automation_id ) );
		}

		$automation_data = BWFAN_Model_Automations::get( $id );

		$this->response_code = 200;

		return $this->success_response( $automation_data, __( 'Automation duplicated', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Delete Logs
	// From: class-bwfan-api-delete-logs.php
	// =========================================================================

	public function delete_logs() {
		$log_ids = $this->args['log_ids'];

		if ( empty( $log_ids ) || ! is_array( $log_ids ) ) {
			return $this->error_response( __( 'Logs ids is missing.', 'wp-marketing-automations' ) );
		}

		BWFAN_Core()->logs->delete_logs( $log_ids );

		return $this->success_response( [], __( 'Logs deleted', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Delete Tasks
	// From: class-bwfan-api-delete-tasks.php
	// =========================================================================

	public function delete_tasks() {
		$task_ids = $this->args['task_ids'];

		if ( empty( $task_ids ) || ! is_array( $task_ids ) ) {
			return $this->error_response( __( 'Tasks ids is missing.', 'wp-marketing-automations' ), null, 404 );
		}

		BWFAN_Core()->tasks->delete_tasks( $task_ids, array() );
		BWFAN_Core()->logs->delete_logs( $task_ids, array() );

		return $this->success_response( [], __( 'Tasks deleted', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Run Automation Task (single)
	// From: class-bwfan-api-run-automation-task.php
	// =========================================================================

	public function run_automation_task() {
		$task_id = $this->args['task_id'];

		if ( empty( $task_id ) ) {
			return $this->error_response( __( 'Task Id is missing', 'wp-marketing-automations' ) );
		}

		$resp = array(
			'msg'    => __( 'Task Executed Successfully', 'wp-marketing-automations' ),
			'status' => true,
		);

		try {
			BWFAN_Core()->tasks->bwfan_ac_execute_task( $task_id );
		} catch ( Exception $exception ) {
			$resp['status'] = false;
			$resp['msg']    = $exception->getMessage();

			return $this->error_response( __( 'Unable to Execute tasks', 'wp-marketing-automations' ), $resp );
		}

		if ( BWFAN_Core()->tasks->ajax_status ) {
			return $this->success_response( $resp );
		}

		$resp = array(
			'msg'    => BWFAN_Core()->tasks->ajax_msg,
			'status' => BWFAN_Core()->tasks->ajax_status,
		);

		$this->response_code = 200;

		return $this->success_response( $resp, __( 'Tasks executed', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Run Automation Tasks (multiple)
	// From: class-bwfan-api-run-automation-tasks.php
	// =========================================================================

	public function run_automation_tasks() {
		$task_ids = $this->args['task_ids'];

		if ( empty( $task_ids ) ) {
			return $this->error_response( __( 'Task Id is missing', 'wp-marketing-automations' ) );
		}

		$resp = array(
			'msg'    => __( 'Task Executed Successfully', 'wp-marketing-automations' ),
			'status' => true,
		);

		try {
			BWFAN_Core()->tasks->bwfan_ac_execute_task( $task_ids );
		} catch ( Exception $exception ) {
			$resp['status']      = false;
			$resp['msg']         = $exception->getMessage();
			$this->response_code = 404;

			return $this->error_response( $resp );
		}

		if ( BWFAN_Core()->tasks->ajax_status ) {
			$this->response_code = 200;

			return $this->success_response( $resp, __( 'Tasks executed', 'wp-marketing-automations' ) );
		}
		$resp = array(
			'msg'    => BWFAN_Core()->tasks->ajax_msg,
			'status' => BWFAN_Core()->tasks->ajax_status,
		);

		if ( 3 !== absint( BWFAN_Core()->tasks->ajax_status ) ) {
			$this->response_code = 404;

			return $this->success_response( $resp, BWFAN_Core()->tasks->ajax_msg );
		}

		$this->response_code = 200;


		return $this->success_response( $resp, __( 'Task executed', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Execute Automation Tasks (reschedule)
	// From: class-bwfan-api-execute-automation-tasks.php
	// =========================================================================

	public function execute_automation_tasks() {
		$task_ids = $this->args['task_ids'];

		if ( empty( $task_ids ) ) {
			return $this->error_response( __( 'Task Id is missing', 'wp-marketing-automations' ) );
		}

		BWFAN_Core()->tasks->rescheduled_tasks( true, $task_ids );
		BWFAN_Core()->logs->rescheduled_logs( $task_ids );

		$this->response_code = 200;

		return $this->success_response( [], __( 'Tasks rescheduled', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Get Task Filters
	// From: class-bwfan-api-get-task-filters.php
	// =========================================================================

	public function get_task_filters() {

		$all_actions           = BWFAN_Common::get_actions_filter_data();
		$all_automations       = BWFAN_Common::get_automations_filter_data();
		$result['automations'] = $all_automations;
		$result['actions']     = $all_actions;

		return $this->success_response( $result, __( 'Tasks found', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Get Task History
	// From: class-bwfan-api-get-task-history.php
	// =========================================================================

	public function get_task_history() {
		$status        = $this->get_sanitized_arg( 'status', 'text_field' );
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$action_slug   = $this->get_sanitized_arg( 'action_slug', 'text_field' );
		$search        = $this->get_sanitized_arg( 'search', 'text_field' );
		$offset        = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? $this->get_sanitized_arg( 'offset', 'absint' ) : 0;
		$limit         = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;

		if ( $status === 't_0' || $status === 't_1' ) {
			$get_task_history = BWFAN_Core()->tasks->get_history( $status, $automation_id, $action_slug, $search, $offset, $limit );
		} else {
			$get_task_history = BWFAN_Core()->logs->get_history( $status, $automation_id, $action_slug, $search, $offset, $limit );
		}

		$get_task_history['scheduled_count'] = BWFAN_Core()->tasks->fetch_tasks_count( 0, 0 );
		$get_task_history['paused_count']    = BWFAN_Core()->tasks->fetch_tasks_count( 0, 1 );
		$get_task_history['completed_count'] = BWFAN_Core()->logs->fetch_logs_count( 1 );
		$get_task_history['failed_count']    = BWFAN_Core()->logs->fetch_logs_count( 0 );

		$this->count_data = BWFAN_Common::get_automation_data_count();

		if ( empty( $get_task_history ) ) {
			$this->response_code = 200;

			return $this->success_response( $get_task_history, __( 'No tasks found', 'wp-marketing-automations' ) );
		}


		if ( isset( $get_task_history['found_posts'] ) ) {
			$this->total_count = $get_task_history['found_posts'];
			unset( $get_task_history['found_posts'] );
		}

		$this->response_code = 200;

		return $this->success_response( $get_task_history, __( 'Tasks found', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Get Automation Stats
	// From: class-bwfan-api-get-automation-stats.php
	// =========================================================================

	public function get_automation_stats() {
		$automation_ids = isset( $this->args['automation_ids'] ) ? $this->args['automation_ids'] : [];
		$version        = $this->get_sanitized_arg( 'version', 'text_field' );
		$ids            = implode( ',', array_map( 'absint', array_filter( $automation_ids ) ) );

		if ( 2 === absint( $version ) ) {
			$data       = [];
			$cached_key = 'bwfan_automation_v2_stats';
			$force      = filter_input( INPUT_GET, 'force' );
			$exp        = BWFAN_Common::get_admin_analytics_cache_lifespan();

			/** Get active, paused, completed and failed count for v2 automations */
			if ( 'false' === $force ) {
				$stats = get_transient( $cached_key );
				if ( ! empty( $stats ) ) {
					$automation_ids = array_filter( $automation_ids, function ( $id ) use ( $stats ) {
						return ! array_key_exists( $id, $stats );
					} );
					if ( count( $automation_ids ) > 0 ) {
						sort( $automation_ids );
					}
					$data = $stats;
				}
			}

			if ( is_array( $automation_ids ) && count( $automation_ids ) > 0 ) {
				/** Single query for active, failed, paused counts grouped by aid */
				$status_counts       = BWFAN_Model_Automation_Contact::get_all_status_counts_by_aids( $automation_ids );
				$complete_automation = BWFAN_Model_Automation_Complete_Contact::get_automation_complete_contact_count( $ids );
				$complete_map        = array();
				foreach ( $complete_automation as $row ) {
					$complete_map[ $row['aid'] ] = intval( $row['count'] );
				}

				foreach ( $automation_ids as $aid ) {
					$data[ $aid ] = [
						'active'   => isset( $status_counts[ $aid ]['active'] ) ? $status_counts[ $aid ]['active'] : 0,
						'complete' => isset( $complete_map[ $aid ] ) ? $complete_map[ $aid ] : 0,
						'failed'   => isset( $status_counts[ $aid ]['failed'] ) ? $status_counts[ $aid ]['failed'] : 0,
						'paused'   => isset( $status_counts[ $aid ]['paused'] ) ? $status_counts[ $aid ]['paused'] : 0,
					];
				}
				BWFAN_Common::validate_scheduled_recurring_actions();
				set_transient( $cached_key, $data, $exp );
			}

			return $this->success_response( $data, __( 'Automations found', 'wp-marketing-automations' ) );
		}

		/** For v1 automations */
		$data = $this->get_v1_automations_count( $automation_ids );

		return $this->success_response( $data, __( 'Automations found', 'wp-marketing-automations' ) );
	}

	/**
	 * Get all scheduled and paused task count (v1 automations helper)
	 *
	 * @param $automation_ids
	 *
	 * @return array
	 */
	public function get_v1_automations_count( $automation_ids ) {
		global $wpdb;
		$data       = [];
		$cached_key = 'bwfan_automation_v1_stats';
		$force      = filter_input( INPUT_GET, 'force' );
		$exp        = BWFAN_Common::get_admin_analytics_cache_lifespan();

		if ( 'false' === $force ) {
			$stats = get_transient( $cached_key );
			if ( ! empty( $stats ) ) {
				$automation_ids = array_filter( $automation_ids, function ( $id ) use ( $stats ) {
					return ! array_key_exists( $id, $stats );
				} );
				if ( count( $automation_ids ) > 0 ) {
					sort( $automation_ids );
				}
				$data = $stats;
			}
		}

		if ( is_array( $automation_ids ) && count( $automation_ids ) > 0 ) {
			$ids = implode( ',', array_map( 'absint', array_filter( $automation_ids ) ) );

			$tasks_table = "{$wpdb->prefix}bwfan_tasks";
			$tasks_query = "SELECT `automation_id`, count(`ID`) AS `total_scheduled`, `status` FROM $tasks_table WHERE `automation_id` IN ($ids) GROUP BY `automation_id`, `status`";
			$tasks       = $wpdb->get_results( $tasks_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; ids absint()ed and imploded
			$total_tasks = array();
			foreach ( $tasks as $automation_tasks ) {
				$status = absint( $automation_tasks['status'] ) === 1 ? 'paused' : 'scheduled';
				if ( absint( $automation_tasks['automation_id'] ) ) {
					$total_tasks[ $status ][ absint( $automation_tasks['automation_id'] ) ] = $automation_tasks['total_scheduled'];
				}
			}

			/** Get completed and failed task count */
			$logs_table = "{$wpdb->prefix}bwfan_logs";
			$logs_query = "SELECT `automation_id`, count(`ID`) AS `total_logs`, `status` FROM $logs_table WHERE `automation_id` IN ($ids) AND `status` IN (0, 1) GROUP BY `automation_id`, `status`";
			$logs       = $wpdb->get_results( $logs_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; ids absint()ed and imploded
			$total_logs = array();
			foreach ( $logs as $automation_logs ) {
				$status = absint( $automation_logs['status'] ) === 1 ? 'completed' : 'failed';
				if ( absint( $automation_logs['automation_id'] ) ) {
					$total_logs[ $status ][ absint( $automation_logs['automation_id'] ) ] = $automation_logs;
				}
			}

			foreach ( $automation_ids as $id ) {
				$data[ $id ]['scheduled'] = isset( $total_tasks['scheduled'][ $id ] ) ? $total_tasks['scheduled'][ $id ] : 0;
				$data[ $id ]['paused']    = isset( $total_tasks['paused'][ $id ] ) ? $total_tasks['paused'][ $id ] : 0;
				$data[ $id ]['completed'] = isset( $total_logs['completed'][ $id ]['total_logs'] ) ? $total_logs['completed'][ $id ]['total_logs'] : 0;
				$data[ $id ]['failed']    = isset( $total_logs['failed'][ $id ]['total_logs'] ) ? $total_logs['failed'][ $id ]['total_logs'] : 0;
			}

			set_transient( $cached_key, $data, $exp );
		}

		return $data;
	}

	// =========================================================================
	// Handler: Get Actions
	// From: class-bwfan-api-get-actions.php
	// =========================================================================

	public function get_actions() {
		$actions = BWFAN_Core()->automations->get_all_actions();
		if ( ! is_array( $actions ) || empty( $actions ) ) {
			return $this->error_response( __( 'Unable to fetch actions', 'wp-marketing-automations' ), null, 500 );
		}

		return $this->success_response( $actions, __( 'Actions found', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Get Events
	// From: class-bwfan-api-get-events.php
	// =========================================================================

	public function get_events() {
		$events       = BWFAN_Load_Sources::get_sources_events_arr();
		$all_triggers = BWFAN_Core()->sources->get_source_localize_data();
		uasort( $all_triggers, function ( $a, $b ) {
			return $a['priority'] <= $b['priority'] ? - 1 : 1;
		} );
		$event_data = array_map( function ( $all_trigger ) use ( $events ) {
			if ( isset( $events[ $all_trigger['slug'] ] ) ) {
				$all_trigger = array_replace( $all_trigger, $events[ $all_trigger['slug'] ] );
			}

			return $all_trigger;
		}, $all_triggers );
		if ( ! is_array( $event_data ) || empty( $event_data ) ) {
			return $this->error_response( __( 'Unable to fetch events', 'wp-marketing-automations' ), null, 500 );
		}

		return $this->success_response( $event_data, __( 'Events found', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Get Event Data
	// From: class-bwfan-api-get-event-data.php
	// =========================================================================

	public function get_event_data() {
		$source     = ! empty( $this->get_sanitized_arg( 'source', 'key' ) ) ? $this->get_sanitized_arg( 'source', 'text_field' ) : '';
		$event_slug = ! empty( $this->get_sanitized_arg( 'event_slug', 'key' ) ) ? $this->get_sanitized_arg( 'event_slug', 'text_field' ) : '';
		if ( empty( $source ) || empty( $event_slug ) ) {
			return $this->error_response( __( 'Required parameter is missing', 'wp-marketing-automations' ), null, 500 );
		}
		$actions = BWFAN_Core()->automations->get_all_actions();
		if ( ! isset( $actions[ $source ]['actions'] ) ) {
			/* translators: 1: Event slug */
			return $this->error_response( sprintf( __( 'Action not found for this source %1$s', 'wp-marketing-automations' ), $source ), null, 500 );
		}

		$event = BWFAN_Core()->sources->get_event( $event_slug );
		if ( empty( $event ) ) {
			return $this->error_response( __( 'Event not exist', 'wp-marketing-automations' ), null, 500 );
		}

		$data            = [];
		$data['actions'] = $actions[ $source ]['actions'];

		/**
		 *  Get Event's rules
		 * @var BWFAN_EVENT
		 **/

		$event_rule_groups = $event->get_rule_group();
		$all_rules_group   = BWFAN_Core()->rules->get_all_groups();
		$all_rules         = apply_filters( 'bwfan_rule_get_rule_types', array() );

		$rules = [];
		foreach ( $event_rule_groups as $rule_group ) {
			if ( isset( $all_rules_group[ $rule_group ] ) ) {
				$rules[ $rule_group ] = $all_rules_group[ $rule_group ];
			}
			if ( isset( $all_rules[ $rule_group ] ) ) {
				$rules[ $rule_group ]['rules'] = $all_rules[ $rule_group ];
			}
			if ( ! isset( $rules[ $rule_group ]['rules'] ) ) {
				unset( $rules[ $rule_group ] );
			}
		}

		$data['rules'] = $rules;

		/**
		 * Get Event's all merge_tags
		 **/

		$all_merge_tags         = BWFAN_Core()->merge_tags->get_all_merge_tags();
		$event_merge_tag_groups = $event->get_merge_tag_groups();

		$mergetags = [];
		foreach ( $event_merge_tag_groups as $merge_tag_group ) {
			if ( isset( $all_merge_tags[ $merge_tag_group ] ) ) {
				$tag_data = array_map( function ( $tags ) {
					return [
						'tag_name'        => $tags->get_name(),
						'tag_description' => $tags->get_description()
					];
				}, $all_merge_tags[ $merge_tag_group ] );

				$mergetags[ $merge_tag_group ] = array_replace( $mergetags, $tag_data );
			}
		}
		$data['merge_tags'] = $mergetags;

		return $this->success_response( $data, __( 'Events found', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Search Automations
	// From: class-bwfan-api-search-automation.php
	// =========================================================================

	public function search_automations() {
		$search  = $this->get_sanitized_arg( 'search', 'text_field' );
		$version = isset( $this->args['version'] ) ? intval( $this->args['version'] ) : 1;
		$ids     = empty( $this->args['ids'] ) ? array() : explode( ',', $this->args['ids'] );
		$limit   = isset( $this->args['limit'] ) ? intval( $this->args['limit'] ) : 10;
		$offset  = isset( $this->args['offset'] ) ? intval( $this->args['offset'] ) : 0;

		$get_automations = BWFAN_Common::get_automation_by_title( $search, $version, $ids, $limit, $offset );

		return $this->success_response( $get_automations, __( 'Automations found', 'wp-marketing-automations' ) );
	}

	// =========================================================================
	// Handler: Import Recipe
	// From: class-bwfan-api-import-recipe.php
	// =========================================================================

	public function import_recipe() {
		$recipe_slug = $this->args['recipe_slug'];
		$title       = $this->args['title'];

		if ( empty( $recipe_slug ) ) {
			return $this->error_response( __( 'Recipe is missing', 'wp-marketing-automations' ), null, 404 );
		}

		$automation = BWFAN_Recipes::create_automation_by_recipe( $recipe_slug, $title );

		if ( $automation['status'] ) {
			$this->response_code = 200;

			return $this->success_response( [ 'automation_id' => $automation['id'] ], __( 'Recipe imported', 'wp-marketing-automations' ) );
		} else {
			return $this->error_response( __( 'Unable to import recipe', 'wp-marketing-automations' ), null, 404 );
		}
	}

	// =========================================================================
	// Handler: License
	// From: class-bwfan-api-license.php
	// =========================================================================

	public function license() {
		$action      = $this->get_sanitized_arg( 'action', 'text_field', $this->args['action'] );
		$key         = $this->get_sanitized_arg( 'key', 'text_field', $this->args['key'] );
		$plugin_name = $this->get_sanitized_arg( 'name', 'text_field', $this->args['name'] );

		if ( empty( $key ) || empty( $plugin_name ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'License key is missing', 'wp-marketing-automations' ) );
		}

		$resp = call_user_func_array( array( $this, $plugin_name . '_license' ), [ 'action' => $action, 'key' => $key ] );

		if ( isset( $resp['code'] ) && 200 === $resp['code'] ) {
			$this->response_code = 200;

			return $this->success_response( BWFAN_Common::get_setting_schema(), $resp['msg'] );
		}

		$this->response_code = 400;

		return $this->error_response( $resp['msg'] );
	}

	/**
	 * License helper: Autonami Pro
	 */
	protected function autonami_pro_license( $action, $key ) {
		$return = [ 'code' => 400 ];

		if ( false === class_exists( 'BWFAN_Pro_WooFunnels_Support' ) ) {
			return $return;
		}

		/** Activation cannot succeed when the Pro folder slug differs from the canonical name — the remote license record is keyed by canonical basename. */
		if ( 'activate' === $action && true === BWFAN_Common::is_pro_folder_renamed() ) {
			return [
				'code' => 400,
				'msg'  => wp_kses_post( __( 'We have detected that plugin directory has been renamed. Please restore the directory to its original name or download the latest files from', 'wp-marketing-automations' ) ) . ' <a class="bwf-a-no-underline bwf-normal-a-t" href="' . esc_url( BWFAN_Common::get_fk_site_links( 'account' ) ) . '">' . esc_html__( 'your account', 'wp-marketing-automations' ) . '</a> ' . wp_kses_post( __( 'or', 'wp-marketing-automations' ) ) . ' <a class="bwf-a-no-underline bwf-normal-a-t" href="' . esc_url( BWFAN_Common::get_fk_site_links( 'support' ) ) . '">' . esc_html__( 'contact support', 'wp-marketing-automations' ) . '</a> ' . wp_kses_post( __( 'for assistance.', 'wp-marketing-automations' ) ),
			];
		}

		$ins  = BWFAN_Pro_WooFunnels_Support::get_instance();
		$resp = $this->process_license_call( $ins, $key, $action );

		return $resp;
	}

	/**
	 * License helper: Autonami Connector
	 */
	protected function autonami_connector_license( $action, $key ) {
		$return = [ 'code' => 400 ];

		if ( false === class_exists( 'BWFAN_Basic_Connector_Support' ) ) {
			return $return;
		}

		/** Activation cannot succeed when the Connectors folder slug differs from the canonical name — the remote license record is keyed by canonical basename. */
		if ( 'activate' === $action && true === BWFAN_Common::is_connector_folder_renamed() ) {
			return [
				'code' => 400,
				'msg'  => wp_kses_post( __( 'We have detected that plugin directory has been renamed. Please restore the directory to its original name or download the latest files from', 'wp-marketing-automations' ) ) . ' <a class="bwf-a-no-underline bwf-normal-a-t" href="' . esc_url( BWFAN_Common::get_fk_site_links( 'account' ) ) . '">' . esc_html__( 'your account', 'wp-marketing-automations' ) . '</a> ' . wp_kses_post( __( 'or', 'wp-marketing-automations' ) ) . ' <a class="bwf-a-no-underline bwf-normal-a-t" href="' . esc_url( BWFAN_Common::get_fk_site_links( 'support' ) ) . '">' . esc_html__( 'contact support', 'wp-marketing-automations' ) . '</a> ' . wp_kses_post( __( 'for assistance.', 'wp-marketing-automations' ) ),
			];
		}

		$ins  = BWFAN_Basic_Connector_Support::get_instance();
		$resp = $this->process_license_call( $ins, $key, $action );

		return $resp;
	}

	/**
	 * License helper: process activate/deactivate call
	 */
	protected function process_license_call( $ins, $key, $action ) {
		BWFAN_Common::log_test_data( "Key: {$key} & Action: {$action}", 'bwfan-license' );

		/** Deactivate call */
		if ( 'deactivate' === $action ) {
			$result = $ins->process_deactivation_api();
			BWFAN_Common::log_test_data( $result, 'bwfan-license' );
			if ( isset( $result['deactivated'] ) && $result['deactivated'] == true ) {
				$msg = __( 'License deactivated successfully.', 'wp-marketing-automations' );

				return [ 'code' => 200, 'msg' => $msg ];
			} else {
				$msg = __( 'Some error occurred', 'wp-marketing-automations' );
				if ( isset( $result['error'] ) ) {
					$msg = $result['error'];
				}

				return [ 'code' => 400, 'msg' => $msg ];
			}
		}

		/** Activate call */
		if ( 'activate' === $action ) {
			$data = $ins->process_activation_api( $key );
			BWFAN_Common::log_test_data( $data, 'bwfan-license' );
			if ( isset( $data['error'] ) ) {
				$msg = __( 'Some error occurred', 'wp-marketing-automations' );
				if ( isset( $data['error'] ) ) {
					$msg = $data['error'];
				}

				return [ 'code' => 400, 'msg' => $msg ];
			}
			$license_data = '';
			if ( isset( $data['activated'] ) && true === $data['activated'] && isset( $data['data_extra'] ) ) {
				$license_data = $data['data_extra'];
			}

			$msg = __( 'License activated successfully.', 'wp-marketing-automations' );

			return [ 'code' => 200, 'msg' => $msg, 'license_data' => $license_data ];
		}
	}

	// =========================================================================
	// Helpers carried over from original classes
	// =========================================================================

	public function get_result_total_count() {
		return $this->total_count;
	}

	public function get_result_count_data() {
		return $this->count_data;
	}
}

BWFAN_API_Autonami_Controller::get_instance();
