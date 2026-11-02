<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_User_Preference_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $contact;

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
			'user_id'     => 0,
			'data'        => [],
			'optionkey'   => '',
			'optionval'   => '',
			'path'        => '',
		);
	}

	protected function register_routes() {
		// GET + PUT /user-preference/{user_id}
		$this->add_route( '/user-preference/(?P<user_id>[\\d]+)', array(
			array( 'GET', 'get_user_preference', array(
				'user_id' => array(
					'description' => __( 'User ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
			array( 'PUT', 'update_user_preference' ),
		) );

		// PUT /update-option-data/
		$this->add_route( '/update-option-data/', array(
			array( 'PUT', 'update_option_data' ),
		) );

		// POST /upload-file/
		$this->add_route( '/upload-file/', array(
			array( 'POST', 'upload_file' ),
		) );

		// GET v2/preferences (admin-authenticated — uses the controller's default permission check)
		$this->add_route( 'v2/preferences', array(
			array( 'GET', 'list_user_preferences' ),
		) );
	}

	/**
	 * GET /user-preference/{user_id} — fetch user preferences.
	 */
	public function get_user_preference() {
		/** Preferences are per-viewer UI meta; bind to the authenticated user, never a URL-supplied id. */
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$user_exists = (bool) get_users( array(
				'include' => $user_id,
				'fields'  => 'ID',
			) );
			if ( ! $user_exists ) {
				$this->response_code = 404;

				return $this->error_response( __( "Contact doesn't exists with the ID: ", 'wp-marketing-automations' ) . $user_id );
			}

			$data = [
				'contact_column'  => get_user_meta( $user_id, '_bwfan_contact_columns', true ),
				'campaign_column' => get_user_meta( $user_id, '_bwfan_broadcast_columns', true )
			];

			return $this->success_response( $data );
		} else {
			$this->response_code = 404;

			return $this->error_response( __( "Please provide the user for the data", 'wp-marketing-automations' ) );
		}
	}

	/**
	 * PUT /user-preference/{user_id} — update user preferences.
	 */
	public function update_user_preference() {
		/** Preferences are per-viewer UI meta; bind to the authenticated user, never a URL-supplied id. */
		$user_id = get_current_user_id();
		if ( empty( $user_id ) ) {
			$this->response_code = 404;

			return $this->error_response( __( "Please provide the user for the data.", 'wp-marketing-automations' ) );
		}

		$user_exists = (bool) get_users( array(
			'include' => $user_id,
			'fields'  => 'ID',
		) );
		if ( ! $user_exists ) {
			$this->response_code = 404;

			return $this->error_response( __( "Contact doesn't exists with the id : ", 'wp-marketing-automations' ) . $user_id );
		}

		if ( ! empty( $this->args['data'] ) && is_array( $this->args['data'] ) ) {
			$data = $this->args['data'];
		}

		if ( isset( $data['contact_column'] ) ) {
			update_user_meta( $user_id, '_bwfan_contact_columns', $data['contact_column'] );
		}
		if ( isset( $data['contact_columnv2'] ) ) {
			update_user_meta( $user_id, '_bwfan_contact_columns_v2', $data['contact_columnv2'] );
		}
		if ( isset( $data['campaign_column'] ) ) {
			update_user_meta( $user_id, '_bwfan_broadcast_columns', $data['campaign_column'] );
		}
		if ( isset( $data['welcome_note_dismiss'] ) ) {
			update_user_meta( $user_id, '_bwfan_welcome_note_dismissed', $data['welcome_note_dismiss'] );
		}
		if ( isset( $data['bwfan_header_notification'] ) ) {
			$userdata   = get_user_meta( $user_id, '_bwfan_header_notification', true );
			$userdata   = empty( $userdata ) && ! is_array( $userdata ) ? [] : $userdata;
			$userdata[] = $data['bwfan_header_notification'];
			$userdata   = array_values( array_filter( array_unique( $userdata ) ) );
			update_user_meta( $user_id, '_bwfan_header_notification', $userdata );
		}
		if ( isset( $data['bwfan_failed_automations'] ) ) {
			update_user_meta( $user_id, 'bwfan_failed_automations', [
				'count' => 0,
				'time'  => time()
			] );
		}
		if ( isset( $data['table_sort_data'] ) ) {
			update_user_meta( $user_id, '_bwfan_table_sort_data', $data['table_sort_data'] );
		}

		return $this->success_response( [], __( "Preferences Updated ", 'wp-marketing-automations' ) );
	}

	/**
	 * Allow-list of option keys this endpoint may write, with expected value type.
	 *
	 * Why: update_option() with an unrestricted key allows site takeover via
	 * writes to options like `active_plugins`, `users_can_register`, `default_role`.
	 */
	protected function get_allowed_options() {
		return apply_filters( 'bwfan_allowed_options_save', array(
			'_bwfan_onboarding_completed'     => 'bool',
			'bwfan_smtp_recommend'            => 'int',
			'bwf_global_block_editor_setting' => 'array',
		) );
	}

	/**
	 * PUT /update-option-data/
	 */
	public function update_option_data() {
		$this->response_code = 404;
		if ( empty( $this->args['optionkey'] ) || ! isset( $this->args['optionval'] ) ) {
			return $this->error_response( __( "Some data missing", 'wp-marketing-automations' ) );
		}
		$option_key = $this->args['optionkey'];
		$option_val = $this->args['optionval'];

		$allowed = $this->get_allowed_options();
		if ( ! isset( $allowed[ $option_key ] ) ) {
			$this->response_code = 403;

			return $this->error_response( __( "Unsupported option key.", 'wp-marketing-automations' ) );
		}

		switch ( $allowed[ $option_key ] ) {
			case 'bool':
				$option_val = (bool) $option_val;
				break;
			case 'int':
				$option_val = absint( $option_val );
				break;
			case 'array':
				if ( ! is_array( $option_val ) ) {
					$this->response_code = 400;

					return $this->error_response( __( "Invalid option value.", 'wp-marketing-automations' ) );
				}
				break;
		}

		$result = update_option( $option_key, $option_val, true );

		if ( $result ) {
			$this->response_code = 200;

			return $this->success_response( __( "Preference updated", 'wp-marketing-automations' ) );
		}

		return $this->error_response( __( "Some error occurred, unable to update the preference", 'wp-marketing-automations' ) );
	}

	/**
	 * POST /upload-file/
	 */
	public function upload_file() {
		$files = $this->args['files'];
		$path  = $this->args['path'];

		// Validate file exists and is uploaded
		if ( empty( $files ) || ! is_array( $files['file'] ) || ! is_uploaded_file( $files['file']['tmp_name'] ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Invalid file upload', 'wp-marketing-automations' ) );
		}

		$file = $files['file'];

		// Validate path - ensure it's within allowed import directory
		if ( empty( $path ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Upload path is required', 'wp-marketing-automations' ) );
		}

		// Use constant for allowed path instead of user input
		$allowed_path = defined( 'BWFAN_IMPORT_DIR' ) ? BWFAN_IMPORT_DIR : wp_upload_dir()['basedir'] . '/funnelkit/fka-import';

		// Ensure allowed directory exists before validation
		if ( ! file_exists( $allowed_path ) ) {
			wp_mkdir_p( $allowed_path );
		}

		// Validate path is within allowed directory using realpath
		$real_path     = realpath( $path );
		$real_allowed  = realpath( $allowed_path );

		if ( false === $real_path || false === $real_allowed || strpos( $real_path, $real_allowed ) !== 0 ) {
			$this->response_code = 403;

			return $this->error_response( __( 'Invalid upload path', 'wp-marketing-automations' ) );
		}

		// Validate file type using wp_check_filetype_and_ext
		$file_info = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$allowed_types = array(
			'csv' => 'text/csv',
			'txt' => 'text/plain',
		);

		// Check extension
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			$this->response_code = 400;

			return $this->error_response( __( 'File must have .csv extension', 'wp-marketing-automations' ) );
		}

		// Validate MIME type
		if ( empty( $file_info['type'] ) || ! in_array( $file_info['type'], $allowed_types, true ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'Invalid file type. Only CSV files allowed.', 'wp-marketing-automations' ) );
		}

		// Ensure directory exists
		if ( ! file_exists( $real_path ) ) {
			wp_mkdir_p( $real_path );
		}

		// Generate secure filename
		$file['name'] = wp_generate_password( 32, false ) . '.csv';

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		/** Point this one upload at the import folder; PHP_INT_MAX so other upload_dir filters can't redirect it */
		$upload_dir_cb = function ( $uploads ) use ( $real_path ) {
			$uploads['path']   = $real_path;
			$uploads['subdir'] = '';

			return $uploads;
		};
		add_filter( 'upload_dir', $upload_dir_cb, PHP_INT_MAX );
		/** Type already validated above, so skip core's second (upload_mimes-dependent) check */
		$uploaded = wp_handle_upload( $file, array(
			'test_form' => false,
			'test_type' => false,
		) );
		remove_filter( 'upload_dir', $upload_dir_cb, PHP_INT_MAX );

		if ( ! is_array( $uploaded ) || empty( $uploaded['file'] ) || isset( $uploaded['error'] ) ) {
			$this->response_code = 500;

			return $this->error_response( ! empty( $uploaded['error'] ) ? $uploaded['error'] : __( 'Unable to upload file', 'wp-marketing-automations' ) );
		}

		$file_data['file'] = $uploaded['file'];

		return $this->success_response( $file_data, __( 'File uploaded successfully', 'wp-marketing-automations' ) );
	}

	/**
	 * GET v2/preferences — list user preferences (requires authentication).
	 */
	public function list_user_preferences() {
		$get_preferences = $this->get_user_preferences_data();
		$selections      = apply_filters( 'bwfcrm_get_export_custom_selections', [] );
		$selections      = is_array( $selections ) ? $selections : [];

		// Convert old format to new format if needed
		$selections = array_map( function ( $selection ) {
			if ( isset( $selection['selections'] ) ) {
				$selection['title']    = $selection['name'];
				$selection['rules']    = array_values( array_map( function ( $rule, $index ) {
					return array(
						'slug' => $rule['slug'],
						'name' => $rule['name'],
					);
				}, $selection['selections'], array_keys( $selection['selections'] ) ) );
				$selection['priority'] = isset( $selection['priority'] ) ? $selection['priority'] : 999;
				unset( $selection['selections'], $selection['name'] );
			}

			return $selection;
		}, $selections );

		$final_selections = array_merge( $get_preferences, $selections );

		uasort( $final_selections, function ( $a, $b ) {
			$a_priority = isset( $a['priority'] ) ? (int) $a['priority'] : 999;
			$b_priority = isset( $b['priority'] ) ? (int) $b['priority'] : 999;

			return $a_priority <=> $b_priority;
		} );

		return $this->success_response( array_values( $final_selections ) );
	}

	/**
	 * REST slug for a CRM filter group key (BWFCRM_Load_Filters::register first argument).
	 *
	 * @param string $group_key e.g. contact_details, contact_custom_fields.
	 *
	 * @return string
	 */
	private function preference_group_slug_from_key( $group_key ) {
		if ( 'contact_custom_fields' === $group_key ) {
			return 'custom-fields';
		}

		return str_replace( '_', '-', $group_key );
	}

	/**
	 * Build preferences from filter classes (Pro). Only rules flagged as preference are included.
	 *
	 * @return array
	 */
	private function get_preferences_from_filters() {
		if ( ! bwfan_is_autonami_pro_active() || ! class_exists( 'BWFCRM_Load_Filters' ) ) {
			return array();
		}

		$filters     = BWFCRM_Load_Filters::get_instance()->get_filters();
		$preferences = array();

		foreach ( $filters as $group_key => $group ) {
			if ( empty( $group['rules'] ) || ! is_array( $group['rules'] ) ) {
				continue;
			}

			$preference_rules = array_filter( $group['rules'], function ( $rule ) {
				return ! empty( $rule['preference'] );
			} );

			if ( empty( $preference_rules ) ) {
				continue;
			}

			$group_title = isset( $group['title'] ) ? $group['title'] : '';
			if ( '' === $group_title ) {
				continue;
			}

			$group_priority  = isset( $group['priority'] ) ? (int) $group['priority'] : 999;
			$preference_slug = $this->preference_group_slug_from_key( $group_key );

			$rules = array_values( array_map( function ( $rule ) {
				return array(
					'slug'     => $rule['slug'],
					'name'     => isset( $rule['name'] ) ? $rule['name'] : $rule['slug'],
					'priority' => isset( $rule['priority'] ) ? (int) $rule['priority'] : 1,
				);
			}, $preference_rules ) );

			$preferences[] = array(
				'slug'     => $preference_slug,
				'title'    => $group_title,
				'rules'    => $rules,
				'priority' => $group_priority,
			);
		}

		return $preferences;
	}

	/**
	 * User preferences for contact columns and export (dynamic filters only).
	 *
	 * @return array
	 */
	public function get_user_preferences_data() {
		return apply_filters( 'bwfan_set_preferences_v2', $this->get_preferences_from_filters() );
	}
}

BWFAN_API_User_Preference_Controller::get_instance();
