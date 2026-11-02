<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class BWFAN_Load_Connectors
 * @package Autonami
 * @author XlPlugins
 */
#[AllowDynamicProperties]
class BWFAN_Load_Connectors {
	/**
	 * Saves all the main trigger's object
	 * @var array
	 */
	public static $sources = array();
	/**
	 * Saves all the action's object
	 * @var array
	 */
	public static $sources_resources = array();
	public static $sources_events = array();
	private static $ins = null;
	private static $_registered_entity = array(
		'active' => array(),
	);
	private static $_registered_resource_entity = array();

	public $show_in_ui = true;

	/**
	 * BWFAN_Load_Connectors constructor.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'load_native_integrations' ], 8 );
		add_action( 'init', [ $this, 'register_classes' ], 9 );

		add_action( 'current_screen', [ $this, 'load_connectors' ], 9 );
		add_action( 'rest_api_init', [ $this, 'load_connectors_rest_call' ] );
		add_action( 'admin_init', [ $this, 'load_connectors_ajax_call' ] );
	}

	/**
	 * Register the source when the source file is included
	 *
	 * @param $shortName
	 * @param $class
	 * @param null $overrides
	 */
	public static function register( $shortName, $class, $overrides = null ) {
		//Ignore classes that have been marked as inactive
		if ( isset( self::$_registered_entity['inactive'] ) && in_array( $class, self::$_registered_entity['inactive'], true ) ) {
			return;
		}

		//Mark classes as active. Override existing active classes if they are supposed to be overridden
		$index = array_search( $overrides, self::$_registered_entity['active'], true );
		if ( false !== $index ) {
			self::$_registered_entity['active'][ $index ] = $class;
		} else {
			self::$_registered_entity['active'][ sanitize_title( $class ) ] = $class;
		}

		//Mark overridden classes as inactive.
		if ( ! empty( $overrides ) ) {
			self::$_registered_entity['inactive'][] = $overrides;
		}
	}

	/**
	 * Include all the Sources's files
	 */
	public static function load_native_integrations() {
		do_action( 'bwfan_before_automations_loaded' );
		$integration_dir = __DIR__ . '/native-connectors';
		foreach ( glob( $integration_dir . '/*/class-*.php' ) as $_field_filename ) {
			if ( ! is_readable( $_field_filename ) ) { // survive update file-swap / partial deploy
				continue;
			}
			require_once( $_field_filename );
		}
	}

	/**
	 * Registers every source as a system source
	 */
	public function register_classes() {
		$load_classes = self::get_registered_sources();
		if ( is_array( $load_classes ) && count( $load_classes ) > 0 ) {
			foreach ( $load_classes as $access_key => $class ) {
				self::$sources[ $access_key ] = $class::get_instance();
			}
		}
	}

	/**
	 * Return the registered integrations
	 *
	 * @return mixed
	 */
	public static function get_registered_sources() {
		return self::$_registered_entity['active'];
	}

	/**
	 * Return the object of current class
	 *
	 * @return null|BWFAN_Load_Connectors
	 */
	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function load_connectors() {
		$screen = get_current_screen();
		if ( ! is_object( $screen ) ) {
			return;
		}

		if ( in_array( $screen->id, [ 'toplevel_page_autonami', 'funnelkit-automations_page_autonami-automations' ], true ) ) {
			$this->run_connectors();
		}
	}

	public function run_connectors() {
		WFCO_Common::get_connectors_data();

		do_action( 'bwfan_automations_loaded' );
		do_action( 'bwfan_load_connector_rules' );
	}

	public function load_connectors_rest_call() {
		$rest_route = isset( $_GET['rest_route'] ) ? esc_url_raw( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request detection only; decides which code loads, changes nothing
		if ( empty( $rest_route ) ) {
			$rest_route = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		}
		if ( empty( $rest_route ) ) {
			return;
		}
		if ( strpos( $rest_route, 'autonami/' ) === false && strpos( $rest_route, 'woofunnel_customer/' ) === false && strpos( $rest_route, 'funnelkit-app' ) === false && strpos( $rest_route, 'autonami-app' ) === false && strpos( $rest_route, 'funnelkit-automations' ) === false && strpos( $rest_route, 'autonami-webhook' ) === false && strpos( $rest_route, 'woofunnels/' ) === false ) {
			return;
		}

		/** Include connectors code */
		$this->run_connectors();
	}

	public function load_connectors_ajax_call() {
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return;
		}
		$ajax_action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only: action name only decides whether connector classes load
		$array       = [ 'wfco', 'bwfan' ];
		foreach ( $array as $value ) {
			if ( strpos( $ajax_action, $value ) !== false ) {
				$this->run_connectors();
				break;
			}
		}
	}
}

if ( class_exists( 'BWFAN_Load_Connectors' ) ) {
	BWFAN_Core::register( 'native_connectors', 'BWFAN_Load_Connectors' );
}
