<?php
/**
 * Opt-in snippet abilities: read, create, update and change the status of snippets.
 *
 * @package WPCode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPCode_Abilities_Snippets.
 */
class WPCode_Abilities_Snippets {

	use WPCode_Abilities_Guards;

	/**
	 * Register the snippet abilities.
	 *
	 * @return void
	 */
	public function register() {
		$this->register_get_snippet_ability();
		$this->register_create_snippet_ability();
		$this->register_update_snippet_ability();
		$this->register_set_snippet_status_ability();
		$this->register_install_library_snippet_ability();
	}

	/**
	 * Register ability to install a snippet from the WPCode Library.
	 *
	 * @return void
	 */
	private function register_install_library_snippet_ability() {
		wp_register_ability(
			'wpcode/install-library-snippet',
			array(
				'label'               => __( 'Install Library Snippet', 'insert-headers-and-footers' ),
				'description'         => __( 'Installs a snippet from the WPCode Library as an inactive snippet', 'insert-headers-and-footers' ),
				'category'            => 'wpcode-management',
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'library_id' => array(
							'type'        => 'integer',
							'description' => __( 'The snippet ID in the WPCode Library, as returned by search-library', 'insert-headers-and-footers' ),
						),
					),
					'required'   => array( 'library_id' ),
				),
				'output_schema'       => $this->snippet_output_schema(),
				'execute_callback'    => array( $this, 'execute_install_library_snippet' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Register ability to get a snippet, including its code.
	 *
	 * @return void
	 */
	private function register_get_snippet_ability() {
		wp_register_ability(
			'wpcode/get-snippet',
			array(
				'label'               => __( 'Get Code Snippet', 'insert-headers-and-footers' ),
				'description'         => __( 'Returns a snippet with its code and settings', 'insert-headers-and-footers' ),
				'category'            => 'wpcode-management',
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'Snippet ID', 'insert-headers-and-footers' ),
						),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array( 'type' => 'integer' ),
						'title'          => array( 'type' => 'string' ),
						'code'           => array( 'type' => 'string' ),
						'code_type'      => array( 'type' => 'string' ),
						'active'         => array( 'type' => 'boolean' ),
						'auto_insert'    => array( 'type' => 'boolean' ),
						'location'       => array( 'type' => 'string' ),
						'insert_number'  => array( 'type' => 'integer' ),
						'location_extra' => array( 'type' => 'string' ),
						'priority'       => array( 'type' => 'integer' ),
						'tags'           => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'note'           => array( 'type' => 'string' ),
						'has_error'      => array( 'type' => 'boolean' ),
						'last_error'     => array( 'type' => 'string' ),
						'edit_url'       => array( 'type' => 'string' ),
						'last_update'    => array( 'type' => 'string' ),
						'testing_mode'   => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_get_snippet' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Register ability to create a snippet.
	 *
	 * @return void
	 */
	private function register_create_snippet_ability() {
		$properties                           = $this->snippet_input_properties();
		$properties['auto_insert']['default'] = true;
		$properties['priority']['default']    = 10;
		$properties['active']['default']      = false;

		wp_register_ability(
			'wpcode/create-snippet',
			array(
				'label'               => __( 'Create Code Snippet', 'insert-headers-and-footers' ),
				'description'         => __( 'Creates a new snippet. New snippets are inactive unless active is true', 'insert-headers-and-footers' ),
				'category'            => 'wpcode-management',
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array( 'code', 'code_type' ),
				),
				'output_schema'       => $this->snippet_output_schema(),
				'execute_callback'    => array( $this, 'execute_create_snippet' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Register ability to update a snippet.
	 *
	 * @return void
	 */
	private function register_update_snippet_ability() {
		$properties = array_merge(
			array(
				'id' => array(
					'type'        => 'integer',
					'description' => __( 'Snippet ID', 'insert-headers-and-footers' ),
				),
			),
			$this->snippet_input_properties()
		);

		wp_register_ability(
			'wpcode/update-snippet',
			array(
				'label'               => __( 'Update Code Snippet', 'insert-headers-and-footers' ),
				'description'         => __( 'Updates a snippet. Only the fields that are sent change', 'insert-headers-and-footers' ),
				'category'            => 'wpcode-management',
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						// Not idempotent for REST: destructive + idempotent abilities run over DELETE, with the input (code) in the query string.
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array( 'id' ),
				),
				'output_schema'       => $this->snippet_output_schema(),
				'execute_callback'    => array( $this, 'execute_update_snippet' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Register ability to activate or deactivate a snippet.
	 *
	 * @return void
	 */
	private function register_set_snippet_status_ability() {
		wp_register_ability(
			'wpcode/set-snippet-status',
			array(
				'label'               => __( 'Set Code Snippet Status', 'insert-headers-and-footers' ),
				'description'         => __( 'Activates or deactivates a snippet. PHP code is test-run before it is activated', 'insert-headers-and-footers' ),
				'category'            => 'wpcode-management',
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => __( 'Snippet ID', 'insert-headers-and-footers' ),
						),
						'active' => array(
							'type'        => 'boolean',
							'description' => __( 'True to activate the snippet, false to deactivate it', 'insert-headers-and-footers' ),
						),
					),
					'required'   => array( 'id', 'active' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'active' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_set_snippet_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * The snippet fields shared by create and update, without defaults.
	 *
	 * @return array
	 */
	private function snippet_input_properties() {
		return array(
			'title'          => array(
				'type'        => 'string',
				'description' => __( 'Snippet title', 'insert-headers-and-footers' ),
			),
			'code'           => array(
				'type'        => 'string',
				'description' => __( 'Snippet code', 'insert-headers-and-footers' ),
			),
			'code_type'      => array(
				'type'        => 'string',
				'enum'        => $this->writable_code_types(),
				'description' => __( 'Code type. SCSS snippets can be read and their other fields changed, but their code is compiled in the snippet editor', 'insert-headers-and-footers' ),
			),
			'auto_insert'    => array(
				'type'        => 'boolean',
				'description' => __( 'Insert the snippet automatically in its location (false: shortcode only)', 'insert-headers-and-footers' ),
			),
			'location'       => array(
				'type'        => 'string',
				'description' => __( 'Auto-insert location, must be available for the code type', 'insert-headers-and-footers' ),
			),
			'insert_number'  => array(
				'type'        => 'integer',
				'description' => __( 'Insert after this number of items, for locations that use it', 'insert-headers-and-footers' ),
			),
			'location_extra' => array(
				'type'        => 'string',
				'description' => __( 'Extra location data, for locations that use it', 'insert-headers-and-footers' ),
			),
			'priority'       => array(
				'type'        => 'integer',
				'description' => __( 'Priority, lower runs first', 'insert-headers-and-footers' ),
			),
			'tags'           => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Tags', 'insert-headers-and-footers' ),
			),
			'note'           => array(
				'type'        => 'string',
				'description' => __( 'Internal note', 'insert-headers-and-footers' ),
			),
			'active'         => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the snippet is active', 'insert-headers-and-footers' ),
			),
		);
	}

	/**
	 * The output schema for create and update.
	 *
	 * @return array
	 */
	private function snippet_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'integer' ),
				'title'     => array( 'type' => 'string' ),
				'code_type' => array( 'type' => 'string' ),
				'active'    => array( 'type' => 'boolean' ),
				'edit_url'  => array( 'type' => 'string' ),
			),
		);
	}

	// ========================================
	// PERMISSION CALLBACKS
	// ========================================

	/**
	 * Check the minimum capability. Code type, object and activation checks run in the execute callbacks.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'wpcode_edit_snippets' );
	}

	// ========================================
	// EXECUTION METHODS
	// ========================================

	/**
	 * Execute get snippet.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array|WP_Error
	 */
	public function execute_get_snippet( $input ) {
		$snippet = $this->load_snippet( isset( $input['id'] ) ? $input['id'] : 0 );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$last_error = $snippet->get_last_error();

		return array(
			'id'             => $snippet->get_id(),
			'title'          => $snippet->get_title(),
			'code'           => $snippet->get_code(),
			'code_type'      => $snippet->get_code_type(),
			'active'         => $snippet->is_active(),
			'auto_insert'    => 1 === $snippet->get_auto_insert(),
			'location'       => (string) $snippet->get_location(),
			'insert_number'  => (int) $snippet->get_auto_insert_number(),
			'location_extra' => (string) $snippet->get_location_extra(),
			'priority'       => (int) $snippet->get_priority(),
			'tags'           => array_values( (array) $snippet->get_tags() ),
			'note'           => (string) $snippet->get_note(),
			'has_error'      => ! empty( $last_error ),
			'last_error'     => ! empty( $last_error['message'] ) ? (string) $last_error['message'] : '',
			'edit_url'       => $snippet->get_edit_url(),
			'last_update'    => (string) get_the_modified_date( 'c', $snippet->get_id() ),
			'testing_mode'   => wpcode_testing_mode_enabled(),
		);
	}

	/**
	 * Execute create snippet.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array|WP_Error
	 */
	public function execute_create_snippet( $input ) {
		$error = $this->testing_mode_error();
		if ( $error ) {
			return $error;
		}

		$code_type = $input['code_type'];
		if ( ! WPCode_Snippet_Input::can_edit( $code_type ) ) {
			return $this->forbidden_error( __( 'You are not allowed to create snippets of this code type.', 'insert-headers-and-footers' ) );
		}

		$error = $this->php_disabled_error( $code_type );
		if ( $error ) {
			return $error;
		}
		if ( 'scss' === $code_type ) {
			return $this->scss_error();
		}

		$auto_insert = isset( $input['auto_insert'] ) ? (bool) $input['auto_insert'] : true;
		$error       = $this->location_error( $input, $code_type, $auto_insert );
		if ( $error ) {
			return $error;
		}

		$activate = ! empty( $input['active'] );
		if ( $activate && $auto_insert && empty( $input['location'] ) ) {
			return $this->missing_location_error();
		}

		$code = $this->prepare_code( $input['code'], $code_type );
		if ( is_wp_error( $code ) ) {
			return $code;
		}

		$data = array_merge(
			array(
				'auto_insert' => 1,
				'priority'    => 10,
			),
			WPCode_Snippet_Input::sanitize_fields( $this->field_input( $input ) ),
			array(
				'code'      => $code,
				'code_type' => $code_type,
			)
		);

		$snippet = new WPCode_Snippet( $data );
		if ( $activate && ! $this->can_activate( $snippet ) ) {
			return $this->forbidden_error( __( 'You are not allowed to activate snippets.', 'insert-headers-and-footers' ) );
		}
		if ( $activate ) {
			$snippet->active = true;
		}

		$error = $this->save_refused_error( $snippet );
		if ( $error ) {
			return $error;
		}

		if ( $activate ) {
			$result = $this->test_run( $snippet );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$id = $this->save_snippet( $snippet, $data, $activate );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->snippet_response( new WPCode_Snippet( $id ) );
	}

	/**
	 * Execute install library snippet. The snippet is always installed inactive.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array|WP_Error
	 */
	public function execute_install_library_snippet( $input ) {
		$error = $this->testing_mode_error();
		if ( $error ) {
			return $error;
		}

		WPCode_Abilities_API::ensure_library_loaded();

		$data = wpcode()->library->grab_snippet_from_api( absint( $input['library_id'] ) );
		if ( empty( $data ) || ! is_array( $data ) || empty( $data['code'] ) ) {
			return new WP_Error(
				'wpcode_library_unavailable',
				__( 'This snippet could not be loaded from the WPCode Library. It may require connecting your site to the library or a WPCode Pro plan.', 'insert-headers-and-footers' ),
				array( 'status' => 400 )
			);
		}

		$code_type = isset( $data['code_type'] ) ? $data['code_type'] : '';
		if ( ! WPCode_Snippet_Input::can_edit( $code_type ) ) {
			return $this->forbidden_error( __( 'You are not allowed to create snippets of this code type.', 'insert-headers-and-footers' ) );
		}

		$error = $this->php_disabled_error( $code_type );
		if ( $error ) {
			return $error;
		}
		if ( 'scss' === $code_type ) {
			return $this->scss_error();
		}

		// Library snippets are never activated by the ability. The payload is unslashed and save() unslashes it.
		$data['active'] = false;
		$data['code']   = wp_slash( $data['code'] );

		$error = $this->save_refused_error( new WPCode_Snippet( $data ) );
		if ( $error ) {
			return $error;
		}

		wpcode()->error->clear_errors();
		$snippet = wpcode()->library->create_snippet_from_data( $data );
		if ( ! $snippet ) {
			$message = wpcode()->error->get_last_error_message();
			wpcode()->error->clear_errors();

			return new WP_Error(
				'wpcode_save_failed',
				$message ? $message : __( 'The snippet could not be saved.', 'insert-headers-and-footers' ),
				array( 'status' => 500 )
			);
		}

		return $this->snippet_response( new WPCode_Snippet( $snippet->get_id() ) );
	}

	/**
	 * Execute update snippet. Only the fields that are sent change.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array|WP_Error
	 */
	public function execute_update_snippet( $input ) {
		$error = $this->testing_mode_error();
		if ( $error ) {
			return $error;
		}

		$snippet = $this->load_snippet( isset( $input['id'] ) ? $input['id'] : 0 );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		$id = $snippet->get_id();

		$fields     = $this->field_input( $input );
		$has_code   = isset( $input['code'] );
		$has_active = isset( $input['active'] );
		if ( empty( $fields ) && ! $has_code && ! isset( $input['code_type'] ) && ! $has_active ) {
			return new WP_Error(
				'wpcode_missing_input',
				__( 'Nothing to update. Send at least one snippet field.', 'insert-headers-and-footers' ),
				array( 'status' => 400 )
			);
		}

		$stored_type  = $snippet->get_code_type();
		$code_type    = isset( $input['code_type'] ) ? $input['code_type'] : $stored_type;
		$type_changed = $code_type !== $stored_type;
		if ( $type_changed && ! WPCode_Snippet_Input::can_edit( $code_type, $id ) ) {
			return $this->forbidden_error( __( 'You are not allowed to edit snippets of this code type.', 'insert-headers-and-footers' ) );
		}

		// The admin blocks PHP and Universal snippets entirely when PHP is disabled.
		$error = $this->php_disabled_error( $code_type );
		if ( $error ) {
			return $error;
		}
		// SCSS is compiled in the editor: its code, or a change to or from SCSS, cannot be written here.
		if ( ( $has_code || $type_changed ) && in_array( 'scss', array( $stored_type, $code_type ), true ) ) {
			return $this->scss_error();
		}

		$auto_insert = isset( $input['auto_insert'] ) ? (bool) $input['auto_insert'] : 1 === absint( get_post_meta( $id, '_wpcode_auto_insert', true ) );
		$error       = $this->location_error( $input, $code_type, $auto_insert );
		if ( $error ) {
			return $error;
		}

		$data = WPCode_Snippet_Input::sanitize_fields( $fields );
		// A type change prepares the stored code for the new type too, as the editor does when it sends the code
		// again: without it, code stored as Blocks would become a Text snippet without the kses filter.
		if ( $has_code || $type_changed ) {
			$code = $this->prepare_code( $has_code ? $input['code'] : $snippet->get_code(), $code_type );
			if ( is_wp_error( $code ) ) {
				return $code;
			}
			$data['code'] = $code;
		}
		if ( $type_changed ) {
			$data['code_type'] = $code_type;
		}
		// save() stores a location only while auto_insert is exactly 1, and clears the location when
		// auto_insert is set without one: send the stored value for the half that was not given.
		if ( isset( $data['location'] ) && ! isset( $data['auto_insert'] ) ) {
			$data['auto_insert'] = $auto_insert ? 1 : 0;
		}
		if ( isset( $data['auto_insert'] ) && 1 === $data['auto_insert'] && ! isset( $data['location'] ) ) {
			$data['location'] = $snippet->get_location();
		}

		$stored_active = 'publish' === get_post_status( $id );
		$active        = $has_active ? (bool) $input['active'] : $stored_active;

		// An active auto-insert snippet without a location runs nowhere. Checked when this call activates the
		// snippet or changes its auto-insert settings, so other edits of an existing snippet are not blocked.
		if ( $active && ( ! $stored_active || isset( $data['auto_insert'] ) || isset( $data['location'] ) ) ) {
			$location = isset( $data['location'] ) ? $data['location'] : $snippet->get_location();
			if ( $auto_insert && empty( $location ) ) {
				return $this->missing_location_error();
			}
		}

		// Editing an active snippet, or activating one, needs the activation capability for the old and the new code type.
		if ( $stored_active || $active ) {
			$allowed = $this->can_activate( $snippet );
			$snippet->load_from_array( $data );
			if ( ! $allowed || ! $this->can_activate( $snippet ) ) {
				return $this->forbidden_error( __( 'You are not allowed to edit an active snippet or change its status.', 'insert-headers-and-footers' ) );
			}
		} else {
			$snippet->load_from_array( $data );
		}

		if ( $active !== $stored_active ) {
			$snippet->active = $active;
		}
		$this->keep_compress_output( $snippet );

		$error = $this->save_refused_error( $snippet );
		if ( $error ) {
			return $error;
		}

		// save() runs no test-run when the status does not change, so test the new code here.
		$test = $active && ( $has_code || $type_changed || ! $stored_active );
		if ( $test ) {
			$result = $this->test_run( $snippet );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$id = $this->save_snippet( $snippet, $data, $test );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->snippet_response( new WPCode_Snippet( $id ) );
	}

	/**
	 * Execute set snippet status.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array|WP_Error
	 */
	public function execute_set_snippet_status( $input ) {
		$error = $this->testing_mode_error();
		if ( $error ) {
			return $error;
		}

		$snippet = $this->load_snippet( isset( $input['id'] ) ? $input['id'] : 0 );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		$id = $snippet->get_id();

		if ( ! $this->can_activate( $snippet ) ) {
			return $this->forbidden_error( __( 'You are not allowed to change the snippet status.', 'insert-headers-and-footers' ) );
		}

		$active        = ! empty( $input['active'] );
		$stored_active = 'publish' === get_post_status( $id );
		if ( $active === $stored_active ) {
			// Already in the requested state: nothing to run or write.
			return array(
				'id'     => $id,
				'active' => $stored_active,
			);
		}

		if ( $active ) {
			$error = $this->php_disabled_error( $snippet->get_code_type() );
			if ( $error ) {
				return $error;
			}
		}

		$snippet->active = $active;
		$this->keep_compress_output( $snippet );

		$error = $this->save_refused_error( $snippet );
		if ( $error ) {
			return $error;
		}

		if ( $active ) {
			$result = $this->test_run( $snippet );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$result = $this->save_snippet( $snippet, array(), $active );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'     => $id,
			'active' => 'publish' === get_post_status( $id ),
		);
	}

	// ========================================
	// HELPERS
	// ========================================

	/**
	 * Pick the simple snippet fields that were sent.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array
	 */
	private function field_input( $input ) {
		$keys = array( 'title', 'location', 'location_extra', 'tags', 'note', 'insert_number', 'auto_insert', 'priority' );

		return array_intersect_key( (array) $input, array_flip( $keys ) );
	}

	/**
	 * Check that a sent location is available for the code type.
	 *
	 * @param array  $input       Input parameters.
	 * @param string $code_type   The code type the snippet will have.
	 * @param bool   $auto_insert Whether the snippet will be auto-inserted.
	 *
	 * @return WP_Error|null
	 */
	private function location_error( $input, $code_type, $auto_insert ) {
		if ( ! isset( $input['location'] ) || ! $auto_insert ) {
			return null;
		}

		foreach ( wpcode()->auto_insert->get_types() as $type ) {
			if ( 'all' !== $type->code_type && $type->code_type !== $code_type ) {
				continue;
			}
			// Upgrade-only types (Lite's PRO pill) list locations they never insert snippets into.
			if ( ! empty( $type->label_pill ) ) {
				continue;
			}
			if ( array_key_exists( $input['location'], $type->get_locations() ) ) {
				return null;
			}
		}

		return new WP_Error(
			'wpcode_invalid_location',
			__( 'This location is not available for the snippet code type.', 'insert-headers-and-footers' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Build the error for an active auto-insert snippet without a location.
	 *
	 * @return WP_Error
	 */
	private function missing_location_error() {
		return new WP_Error(
			'wpcode_invalid_location',
			__( 'A location is required to activate an auto-insert snippet.', 'insert-headers-and-footers' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * The code types the abilities can write: those with an executor class on this site (Lite has no Blocks or
	 * SCSS executor). SCSS stays listed where it runs so existing SCSS snippets can be edited; its code is refused.
	 *
	 * @return string[]
	 */
	private function writable_code_types() {
		$types = array();
		foreach ( wpcode()->execute->get_types() as $type => $info ) {
			if ( ! empty( $info['class'] ) && class_exists( $info['class'] ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * Prepare code for storage as the editor does. Blocks code also gets the kses filter without unfiltered_html:
	 * the Text/Blocks tier allows no HTML code, and the editor never sends Blocks code.
	 *
	 * @param string $code      The code, unslashed.
	 * @param string $code_type The code type.
	 *
	 * @return string|WP_Error
	 */
	private function prepare_code( $code, $code_type ) {
		$code = WPCode_Snippet_Input::prepare_code( $code, $code_type );
		if ( ! is_wp_error( $code ) && 'blocks' === $code_type && ! current_user_can( 'unfiltered_html' ) ) {
			$code = wp_kses_post( $code );
		}

		return $code;
	}

	/**
	 * Keep the stored compress output flag: save() deletes it when the property is not set.
	 *
	 * @param WPCode_Snippet $snippet The snippet to save.
	 *
	 * @return void
	 */
	private function keep_compress_output( $snippet ) {
		if ( get_post_meta( $snippet->get_id(), '_wpcode_compress_output', true ) ) {
			$snippet->compress_output = true;
		}
	}

	/**
	 * Save a snippet whose checks have all passed.
	 *
	 * @param WPCode_Snippet $snippet The snippet.
	 * @param array          $data    The unslashed values set on the snippet.
	 * @param bool           $tested  Whether the code was already test-run in this request.
	 *
	 * @return int|WP_Error The snippet id.
	 */
	private function save_snippet( $snippet, $data, $tested ) {
		// Ability input is unslashed and save() unslashes post fields and meta: slash the text values.
		$slashed = array_intersect_key( $data, array_flip( array( 'title', 'code', 'note', 'location_extra', 'tags' ) ) );
		$snippet->load_from_array( wp_slash( $slashed ) );

		// The code was test-run already: a second run would redeclare its functions.
		$skip = function ( $skip, $checked ) use ( $snippet ) {
			return $checked === $snippet ? true : $skip;
		};
		if ( $tested ) {
			add_filter( 'wpcode_skip_activation_checks', $skip, 10, 2 );
		}

		wpcode()->error->clear_errors();
		try {
			$id = $snippet->save();
		} finally {
			remove_filter( 'wpcode_skip_activation_checks', $skip, 10 );
		}

		if ( empty( $id ) || is_wp_error( $id ) ) {
			$message = wpcode()->error->get_last_error_message();
			wpcode()->error->clear_errors();

			return new WP_Error(
				'wpcode_save_failed',
				$message ? $message : __( 'The snippet could not be saved.', 'insert-headers-and-footers' ),
				array( 'status' => 500 )
			);
		}

		return (int) $id;
	}

	/**
	 * Build a forbidden error.
	 *
	 * @param string $message The message.
	 *
	 * @return WP_Error
	 */
	private function forbidden_error( $message ) {
		return new WP_Error( 'wpcode_forbidden', $message, array( 'status' => 403 ) );
	}
}
