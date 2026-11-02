<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class BWFAN_API_Loader
 * @package Autonami
 * @author XlPlugins
 */
#[AllowDynamicProperties]
class BWFAN_API_Loader {
	private static $ins = null;

	/**
	 * @var array $registered_apis
	 */
	private static $registered_apis = array();

	/**
	 * Supported HTTP Method Constants
	 */
	const POST = 'POST';
	const GET = 'GET';
	const PUT = 'PUT';
	const PATCH = 'PATCH';
	const DELETE = 'DELETE';

	/**
	 * Route prefix → API subdirectory mapping.
	 *
	 * Two-level keys (e.g. 'v3/contacts') are checked first for longest match.
	 * Single-level keys checked second.
	 *
	 * Strict matching: unmapped prefix → load nothing → endpoint 404s.
	 * Every Lite-owned prefix MUST be listed here.
	 *
	 * New files added to existing directories are auto-discovered by glob.
	 * Only new route PREFIX patterns require a map entry.
	 */
	private static $route_map = array(
		// Two-level prefixes — checked first (v3/ routes span 7 dirs)
		'v3/contacts'       => array( 'contacts' ),
		'v3/contact'        => array( 'contacts' ),
		'v3/fields'         => array( 'fields' ),
		'v3/all'            => array( 'fields' ),
		'v3/groupfields'    => array( 'groupfields' ),
		'v3/lists'          => array( 'lists' ),
		'v3/list'           => array( 'lists' ),
		'v3/tags'           => array( 'tags' ),
		'v3/email-activity' => array( 'email-activity' ),
		'v3/automation'     => array( 'automation' ),
		'v1/fetch-announcements' => array( 'announcement' ),
		'v1/announcement_action' => array( 'announcement' ),

		// Single-level prefixes
		'automation'        => array( 'automation', 'recipe' ),
		'automations'       => array( 'automation', 'autonami' ),
		'automations-stats' => array( 'automation', 'autonami' ),
		'autonami'          => array( 'autonami', 'wc' ),
		'actions'           => array( 'autonami' ),
		'events'            => array( 'autonami' ),
		'event'             => array( 'autonami' ),
		'license'           => array( 'autonami' ),
		'recipe'            => array( 'autonami', 'recipe' ),
		'recipes'           => array( 'recipe' ),
		'search'            => array( 'autonami' ),
		'carts'             => array( 'carts' ),
		'analytics'         => array( 'carts' ),
		'contacts'          => array( 'importer' ),
		'contact'           => array( 'contacts' ),
		'importer-log'      => array( 'importer' ),
		'connector'         => array( 'connectors' ),
		'connectors'        => array( 'connectors' ),
		'dashboard'         => array( 'dashboard' ),
		'db-update'         => array( 'db-update' ),
		'engagement'        => array( 'email-activity' ),
		'export'            => array( 'exporter' ),
		'plugin'            => array( 'plugin_status' ),
		'plugin-connect-link' => array( 'plugin_status' ),
		'send-test-notification' => array( 'notification' ),
		'settings'          => array( 'settings' ),
		'block-migrator'    => array( 'settings' ),
		'setup-wizard'      => array( 'setup' ),
		'update-option-data' => array( 'user-preference' ),
		'v2/preferences'    => array( 'user-preference' ),
		'upload-file'       => array( 'user-preference' ),
		'user-preference'   => array( 'user-preference' ),
		'preferences'       => array( 'user-preference' ),
		'custom-search'     => array( 'automation' ),
		'bulk-action'       => array( 'automation' ),
		'wc-category'       => array( 'wc' ),
		'wc-coupons'        => array( 'wc' ),
		'bwf-products'      => array( 'wc' ),
		'wp-users'          => array( 'wp' ),
	);

	/**
	 * BWFAN_API_Loader constructor.
	 */
	public function __construct() {
	}

	/**
	 * Return the object of current class
	 *
	 * @return null|BWFAN_API_Loader
	 */
	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	/**
	 * Load API class files only from specified subdirectories.
	 *
	 * @param array $dirs Directory names under /includes/api/ (e.g. ['contacts', 'tags']).
	 */
	private static function load_api_classes_for_dirs( $dirs ) {
		$api_dir = __DIR__ . '/api';

		foreach ( $dirs as $dir_name ) {
			$dir_path = $api_dir . '/' . $dir_name;
			if ( ! is_dir( $dir_path ) ) {
				continue;
			}
			foreach ( glob( $dir_path . '/class-*.php' ) as $_field_filename ) {
				if ( ! is_readable( $_field_filename ) ) { // survive update file-swap / partial deploy
					continue;
				}
				require_once $_field_filename;
			}
		}

		do_action( 'bwfan_api_classes_loaded' );
	}

	/**
	 * Determine which API subdirectories match the current REST request URL.
	 *
	 * Strict matching:
	 *   - Empty route, missing namespace, or unmapped prefix → null (skip loading)
	 *   - Mapped prefix                                      → array of directory names
	 *
	 * @param string $rest_route The raw REST route URL (from $_GET['rest_route'] or REQUEST_URI).
	 *
	 * @return array|null Array of directory names; null means no match.
	 */
	private static function get_matching_directories( $rest_route ) {
		if ( empty( $rest_route ) || ! defined( 'BWFAN_API_NAMESPACE' ) ) {
			return null;
		}

		// Strip query string first, so a namespace inside a query parameter cannot match
		$qpos = strpos( $rest_route, '?' );
		if ( false !== $qpos ) {
			$rest_route = substr( $rest_route, 0, $qpos );
		}

		// Strip namespace prefix to get the API path
		$ns  = BWFAN_API_NAMESPACE;
		$pos = strpos( $rest_route, $ns );
		if ( false === $pos ) {
			return null;
		}

		$api_path = substr( $rest_route, $pos + strlen( $ns ) );
		$api_path = ltrim( $api_path, '/' );

		if ( empty( $api_path ) ) {
			return null; // Root/bare-namespace request — no dirs matched, nothing loaded
		}

		$parts = explode( '/', $api_path );

		// Two-level match first (longest match wins)
		if ( count( $parts ) >= 2 ) {
			$two_level = $parts[0] . '/' . $parts[1];
			if ( isset( self::$route_map[ $two_level ] ) ) {
				return self::$route_map[ $two_level ];
			}
		}

		// Single-level match — skip numeric segments (e.g., "123/..." can't map)
		if ( is_numeric( $parts[0] ) ) {
			return null;
		}

		if ( isset( self::$route_map[ $parts[0] ] ) ) {
			return self::$route_map[ $parts[0] ];
		}

		// Strict: no prefix match → load NOTHING → route is never registered → 404.
		// There is NO load-all fallback. Every Lite-owned route prefix MUST be in $route_map.
		return null;
	}

	public static function register( $api_class ) {
		if ( ! class_exists( $api_class ) || ! method_exists( $api_class, 'get_instance' ) ) {
			return;
		}

		if ( empty( $api_class ) ) {
			return;
		}

		$api_slug = strtolower( $api_class );
		if ( false === strpos( $api_slug, 'bwfan_api_' ) ) {
			return;
		}

		$api_slug = explode( 'bwfan_api_', $api_slug )[1];
		/** @var BWFAN_API_Base $api_obj */
		$api_obj = $api_class::get_instance();

		if ( empty( $api_obj->route ) ) {
			return;
		}

		self::$registered_apis[ $api_obj->route ][ $api_slug ] = $api_obj;
	}

	/**
	 * Load API classes and register REST routes.
	 *
	 * @param string $rest_route Optional. The current REST route URL for route-level loading.
	 */
	public static function register_routes( $rest_route = '' ) {
		$dirs = self::get_matching_directories( $rest_route );
		
		if ( ! empty( $dirs ) ) {
			self::load_api_classes_for_dirs( $dirs );
		}

		self::do_register_routes();
	}

	/**
	 * Register all collected API routes with WordPress REST API.
	 * Handles both legacy single-method classes (BWFAN_API_Base)
	 * and consolidated multi-method classes (BWFAN_API_Route).
	 */
	private static function do_register_routes() {
		foreach ( self::$registered_apis as $route => $registered_api ) {
			if ( empty( $registered_api ) ) {
				continue;
			}

			$api_group = array();
			foreach ( $registered_api as $api ) {
				/** @var BWFAN_API_Base $api */
				if ( empty( $api->method ) ) {
					continue;
				}

				$route_args = array(
					'methods'             => $api->method,
					'callback'            => array( $api, 'api_call' ),
					'permission_callback' => '__return_true',
					'show_in_index'       => false,
				);

				if ( false === $api->public_api ) {
					$route_args['permission_callback'] = [ $api, 'rest_permission_callback' ];
				}

				if ( is_array( $api->request_args ) && ! empty( $api->request_args ) ) {
					$route_args['args'] = $api->request_args;
				}

				$api_group[] = $route_args;
			}

			$api_group = array_filter( $api_group );
			$api_group = array_values( $api_group );

			register_rest_route( BWFAN_API_NAMESPACE, $route, $api_group );
		}

		do_action( 'bwfan_api_routes_registered', self::$registered_apis );
	}
}

if ( class_exists( 'BWFAN_API_Loader' ) ) {
	BWFAN_Core::register( 'bwfan_api', 'BWFAN_API_Loader' );
}
