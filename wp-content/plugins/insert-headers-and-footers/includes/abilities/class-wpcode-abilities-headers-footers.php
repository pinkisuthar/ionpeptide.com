<?php
/**
 * Opt-in header and footer abilities: read and update the site-wide code.
 *
 * @package WPCode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPCode_Abilities_Headers_Footers.
 */
class WPCode_Abilities_Headers_Footers {

	use WPCode_Abilities_Guards;

	/**
	 * The sections, in the order the options are written. Pro creates a revision when the body option
	 * is updated, so the body must come last.
	 *
	 * @var string[]
	 */
	private $sections = array( 'header', 'footer', 'body' );

	/**
	 * Register the header and footer abilities.
	 *
	 * @return void
	 */
	public function register() {
		$this->register_get_headers_footers_ability();
		$this->register_update_headers_footers_ability();
	}

	/**
	 * Output schema shared by both abilities.
	 *
	 * @return array
	 */
	private function code_properties() {
		return array(
			'header' => array( 'type' => 'string' ),
			'body'   => array( 'type' => 'string' ),
			'footer' => array( 'type' => 'string' ),
		);
	}

	/**
	 * Register ability to read the headers and footers.
	 *
	 * @return void
	 */
	private function register_get_headers_footers_ability() {
		wp_register_ability(
			'wpcode/get-headers-footers',
			array(
				'label'               => __( 'Get Headers & Footers', 'insert-headers-and-footers' ),
				'description'         => __( 'Returns the site-wide header, body and footer code', 'insert-headers-and-footers' ),
				'category'            => 'wpcode-management',
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array_merge(
						$this->code_properties(),
						array(
							'body_supported' => array( 'type' => 'boolean' ),
							'testing_mode'   => array( 'type' => 'boolean' ),
						)
					),
				),
				'execute_callback'    => array( $this, 'execute_get_headers_footers' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Register ability to update the headers and footers.
	 *
	 * @return void
	 */
	private function register_update_headers_footers_ability() {
		wp_register_ability(
			'wpcode/update-headers-footers',
			array(
				'label'               => __( 'Update Headers & Footers', 'insert-headers-and-footers' ),
				'description'         => __( 'Adds to or replaces the site-wide header, body and footer code. At least one section is required', 'insert-headers-and-footers' ),
				'category'            => 'wpcode-management',
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'header' => array(
							'type'        => 'string',
							'description' => __( 'Code for the header, printed in the head section', 'insert-headers-and-footers' ),
						),
						'body'   => array(
							'type'        => 'string',
							'description' => __( 'Code printed right after the opening body tag', 'insert-headers-and-footers' ),
						),
						'footer' => array(
							'type'        => 'string',
							'description' => __( 'Code for the footer, printed before the closing body tag', 'insert-headers-and-footers' ),
						),
						'mode'   => array(
							'type'        => 'string',
							'enum'        => array( 'append', 'replace' ),
							'default'     => 'append',
							'description' => __( 'append adds the code on a new line after the existing code, replace overwrites it', 'insert-headers-and-footers' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => $this->code_properties(),
				),
				'execute_callback'    => array( $this, 'execute_update_headers_footers' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check that the user can change the site-wide code, as the Headers & Footers page does.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'unfiltered_html' ) && current_user_can( 'wpcode_activate_snippets' );
	}

	/**
	 * Read a section as the admin page shows it (the options are stored slashed).
	 *
	 * @param string $section header, body or footer.
	 *
	 * @return string
	 */
	private function read_section( $section ) {
		return (string) wp_unslash( get_option( 'ihaf_insert_' . $section, '' ) );
	}

	/**
	 * Get all sections.
	 *
	 * @return array
	 */
	private function read_all() {
		return array(
			'header' => $this->read_section( 'header' ),
			'body'   => $this->read_section( 'body' ),
			'footer' => $this->read_section( 'footer' ),
		);
	}

	/**
	 * Execute get headers and footers.
	 *
	 * @param array|null $input Input parameters (not used).
	 *
	 * @return array
	 */
	public function execute_get_headers_footers( $input = null ) {
		return array_merge(
			$this->read_all(),
			array(
				'body_supported' => function_exists( 'wp_body_open' ),
				'testing_mode'   => wpcode_testing_mode_enabled(),
			)
		);
	}

	/**
	 * Execute update headers and footers.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array|WP_Error
	 */
	public function execute_update_headers_footers( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$provided = array();
		foreach ( $this->sections as $section ) {
			if ( isset( $input[ $section ] ) && is_string( $input[ $section ] ) ) {
				$provided[ $section ] = $input[ $section ];
			}
		}

		if ( empty( $provided ) ) {
			return new WP_Error(
				'wpcode_missing_input',
				__( 'Provide at least one of header, body or footer.', 'insert-headers-and-footers' ),
				array( 'status' => 400 )
			);
		}

		$testing_mode = $this->testing_mode_error();
		if ( $testing_mode ) {
			return $testing_mode;
		}

		// Pro remote-only mode: site code is managed from the WPCode Library, not changed locally.
		if ( class_exists( 'WPCode_Access' ) && method_exists( 'WPCode_Access', 'remote_only' ) && WPCode_Access::remote_only() ) {
			return new WP_Error(
				'wpcode_forbidden',
				__( 'Code on this site is managed from the WPCode Library. Make your changes there and deploy them to this site.', 'insert-headers-and-footers' ),
				array( 'status' => 403 )
			);
		}

		foreach ( $provided as $code ) {
			if ( WPCode_Snippet_Execute::is_code_not_allowed( $code ) ) {
				return new WP_Error(
					'wpcode_restricted_code',
					__( 'The code contains restricted functions and was not saved.', 'insert-headers-and-footers' ),
					array( 'status' => 400 )
				);
			}
		}

		$append = ! isset( $input['mode'] ) || 'replace' !== $input['mode'];
		$values = $this->read_all();
		foreach ( $provided as $section => $code ) {
			if ( $append && '' !== trim( $values[ $section ] ) ) {
				$values[ $section ] .= "\n" . $code;
			} else {
				$values[ $section ] = $code;
			}
		}

		// Write every section, header and footer first and body last: Pro creates one revision with all
		// three values when the body option is updated. Sections that were not provided are written back as
		// they are stored, which WordPress skips when nothing changed.
		foreach ( $this->sections as $section ) {
			$option = 'ihaf_insert_' . $section;
			if ( isset( $provided[ $section ] ) ) {
				update_option( $option, wp_slash( $values[ $section ] ) );
			} else {
				update_option( $option, get_option( $option ) );
			}
		}

		/** This filter is documented in includes/admin/pages/class-wpcode-admin-page-headers-footers.php */
		if ( apply_filters( 'wpcode_clear_cache_on_global_save', true ) ) {
			wpcode_clear_all_plugins_page_cache( 'global' );
		}

		return $this->read_all();
	}
}
