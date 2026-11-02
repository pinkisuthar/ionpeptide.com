<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WFCO_Load_Connectors' ) ) {
	#[AllowDynamicProperties]
	class WFCO_Load_Connectors {
		/** @var class instance */
		private static $ins = null;

		/** @var array All connectors with object */
		private static $connectors = array();

		/** @var array All calls with object */
		private static $registered_calls = array();

		/** @var array All calls objects group by connectors */
		private static $registered_connectors_calls = array();

		/** @var array call_slug => [ connector_slug, filename ] — runtime only, never persisted */
		private static $call_index = array();

		/** @var array connector_slug => calls dir */
		private static $call_dirs = array();

		public function __construct() {
			add_action( 'rest_api_init', [ $this, 'load_connectors_rest_call' ], 8 );
			add_action( 'current_screen', [ $this, 'load_connectors_admin' ], 8 );
			add_action( 'admin_init', [ $this, 'load_connectors_admin_ajax' ], 8 );
		}

		/**
		 * Return class instance
		 *
		 * @return class|WFCO_Load_Connectors
		 */
		public static function get_instance() {
			if ( null == self::$ins ) {
				self::$ins = new self();
			}

			return self::$ins;
		}

		/**
		 * Include all connectors files directly
		 *
		 * @return void
		 */
		public static function load_connectors_direct() {
			do_action( 'wfco_load_connectors' );
		}

		/**
		 * Include all connectors files on rest endpoints
		 *
		 * @return void
		 */
		public static function load_connectors_rest_call() {
			if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verification not required for REST API route detection; route paths contain slashes as part of path structure
				$rest_route = sanitize_text_field( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended -- read-only REST route detection; decides whether connectors load
			} else {
				$rest_route = '';
			}
			if ( empty( $rest_route ) ) {
				$rest_route = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- REQUEST_URI is part of the URL structure, slashes are intentional
			}
			if ( empty( $rest_route ) ) {
				return;
			}
			if ( strpos( $rest_route, 'autonami/' ) === false && strpos( $rest_route, 'woofunnel_customer/' ) === false && strpos( $rest_route, 'funnelkit-app' ) === false && strpos( $rest_route, 'autonami-app' ) === false && strpos( $rest_route, 'funnelkit-automations' ) === false && strpos( $rest_route, 'autonami-webhook' ) === false && strpos( $rest_route, 'woofunnels/' ) === false && strpos( $rest_route, '/omapp/' ) === false ) {
				return;
			}

			do_action( 'wfco_load_connectors' );
		}

		/**
		 * Include all connectors files on admin screen
		 *
		 * @return void
		 */
		public static function load_connectors_admin() {
			$screen = get_current_screen();
			if ( ! is_object( $screen ) ) {
				return;
			}
			if ( empty( $screen->id ) || ( 'toplevel_page_autonami' !== $screen->id && 'funnelkit-automations_page_autonami-automations' !== $screen->id ) ) {
				return;
			}
			do_action( 'wfco_load_connectors' );
		}

		/**
		 * Include all connectors files on admin ajax call
		 *
		 * @return void
		 */
		public static function load_connectors_admin_ajax() {
			if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
				return;
			}
			$ajax_action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX action verification handled by WordPress AJAX system
			if ( empty( $ajax_action ) ) {
				return;
			}
			$array = [ 'wfco', 'bwfan', 'bwf_' ];
			foreach ( $array as $value ) {
				if ( strpos( $ajax_action, $value ) !== false ) {
					do_action( 'wfco_load_connectors' );
					break;
				}
			}
		}

		/**
		 * Register a connector with their object
		 * Assign to static property $connectors
		 * Load connector respective calls
		 *
		 * @param $class
		 */
		public static function register( $class ) {
			if ( ! class_exists( $class ) && ! method_exists( $class, 'get_instance' ) ) {
				return;
			}

			$temp_ins = $class::get_instance();
			if ( ! $temp_ins instanceof BWF_CO ) {
				return;
			}

			$slug = $temp_ins->get_slug();

			self::$connectors[ $slug ] = $temp_ins;
			$temp_ins->load_calls();
		}

		/**
		 * Register a call with their object
		 * Assign to static property $registered_calls
		 * Assign to static property $registered_connectors_calls
		 *
		 * @param WFCO_Call $call_obj
		 */
		public static function register_calls( WFCO_Call $call_obj ) {
			if ( method_exists( $call_obj, 'get_instance' ) ) {
				$slug           = $call_obj->get_slug();
				$connector_slug = $call_obj->get_connector_slug();

				self::$registered_connectors_calls[ $connector_slug ][ $slug ] = self::$registered_calls[ $slug ] = $call_obj;
			}
		}

		/**
		 * Register a declared map of lazily loadable calls.
		 *
		 * Eagerly populates the per-connector KEYS in $registered_connectors_calls
		 * (the integration gate and recipes read them) with filename-string lazy
		 * refs; instanceof WFCO_Call is the materialized/pending discriminator.
		 * The flat $registered_calls never holds strings.
		 *
		 * @param string $connector_slug
		 * @param string $dir absolute path to the calls directory
		 * @param array  $map call_slug => filename
		 */
		public static function register_lazy_calls( $connector_slug, $dir, array $map ) {
			foreach ( $map as $slug => $filename ) {
				self::$call_index[ $slug ] = array( $connector_slug, $filename );

				/** Never demote an already materialized call back to a lazy ref. */
				if ( isset( self::$registered_connectors_calls[ $connector_slug ][ $slug ] ) && self::$registered_connectors_calls[ $connector_slug ][ $slug ] instanceof WFCO_Call ) {
					continue;
				}
				self::$registered_connectors_calls[ $connector_slug ][ $slug ] = $filename;
			}
			self::$call_dirs[ $connector_slug ] = $dir;
		}

		/**
		 * Load the one file behind an indexed call slug.
		 *
		 * When $connector_slug is given it is authoritative: the file is resolved
		 * inside that connector rather than through the slug-keyed global index,
		 * so a slug two connectors happen to share can never cross over.
		 *
		 * @param string $slug
		 * @param string $connector_slug optional owning connector
		 *
		 * @return WFCO_Call|null
		 */
		public static function materialize_call( $slug, $connector_slug = '' ) {
			if ( isset( self::$registered_calls[ $slug ] ) ) {
				$existing = self::$registered_calls[ $slug ];
				if ( '' === $connector_slug || $existing->get_connector_slug() === $connector_slug ) {
					return $existing;
				}
			}

			$filename = '';
			if ( '' !== $connector_slug ) {
				if ( ! isset( self::$registered_connectors_calls[ $connector_slug ][ $slug ] ) ) {
					return null;
				}
				$ref = self::$registered_connectors_calls[ $connector_slug ][ $slug ];
				if ( $ref instanceof WFCO_Call ) {
					return $ref;
				}
				$filename = is_string( $ref ) ? $ref : '';
			} elseif ( isset( self::$call_index[ $slug ] ) ) {
				list( $connector_slug, $filename ) = self::$call_index[ $slug ];
			}

			if ( '' === $filename || ! isset( self::$call_dirs[ $connector_slug ] ) ) {
				return null;
			}

			$file = self::$call_dirs[ $connector_slug ] . '/' . $filename;

			if ( ! is_readable( $file ) ) { // survive update file-swap / partial deploy
				return null;
			}
			$call_class = require_once( $file );
			if ( ( ! is_object( $call_class ) && ! is_string( $call_class ) ) || ! method_exists( $call_class, 'get_instance' ) ) {
				return null; // guarded file declined — exactly as under the glob
			}

			$call_obj = $call_class::get_instance();
			$call_obj->set_connector_slug( $connector_slug );
			self::register_calls( $call_obj ); // object replaces the string ref in BOTH arrays

			/**
			 * register_calls() keys by the class's own get_slug(). If a declared map
			 * key ever drifts from it, the slot for $slug stays unset and the next
			 * materialize would hit an already-included file — require_once then
			 * returns true, neither object nor string, and we would hand back null
			 * for a call that resolved a moment ago. Key it under what was asked too.
			 *
			 * Both indexes, not just the global one: where two connectors share a
			 * drifted slug, whichever resolves last owns the global alias, and the
			 * other one falls back to its per-connector slot to stay resolvable.
			 */
			if ( $call_obj->get_slug() !== $slug ) {
				self::$registered_calls[ $slug ] = $call_obj;
				self::$registered_connectors_calls[ $connector_slug ][ $slug ] = $call_obj;
			}

			return $call_obj;
		}

		/**
		 * Return all the connectors with their calls objects
		 *
		 * @return array
		 */
		public static function get_all_connectors() {
			return self::$registered_connectors_calls;
		}


		/**
		 * Returns Instance of single connector
		 *
		 * @param $connector
		 *
		 * @return BWF_CO
		 */
		public static function get_connector( $connector ) {
			return isset( self::$connectors[ $connector ] ) ? self::$connectors[ $connector ] : null;
		}


		/**
		 * Returns all the active connectors i.e. plugin active
		 *
		 * @return array
		 */
		public static function get_active_connectors() {
			return self::$connectors;
		}

		/**
		 * Return one call object when a slug is passed, or every call object
		 * registered so far when it is not.
		 *
		 * The slug form resolves on demand: where the owning connector declared a
		 * $calls_map, the file behind the slug is loaded here, so this is equivalent
		 * to get_call(). The no-slug form loads nothing — under a declared map it
		 * returns only what has already been materialized, never the whole declared
		 * set, so do not use it to enumerate a connector's calls.
		 *
		 * @param string $slug call slug, or '' for the set registered so far
		 *
		 * @return array|WFCO_Call|null the registered set when $slug is empty, the
		 *                              call object when the slug resolves, else null
		 */
		public function get_calls( $slug = '' ) {
			if ( empty( $slug ) ) {
				return self::$registered_calls;
			}
			if ( isset( self::$registered_calls[ $slug ] ) ) {
				return self::$registered_calls[ $slug ];
			}
			if ( isset( self::$call_index[ $slug ] ) ) {
				return self::materialize_call( $slug );
			}

			return null;
		}

		/**
		 * Return a call object based on the given slug.
		 *
		 * @param string $slug call slug
		 *
		 * @return WFCO_Call | null
		 */
		public function get_call( $slug ) {
			if ( empty( $slug ) ) {
				return null;
			}
			if ( isset( self::$registered_calls[ $slug ] ) ) {
				return self::$registered_calls[ $slug ];
			}
			if ( isset( self::$call_index[ $slug ] ) ) {
				return self::materialize_call( $slug );
			}

			return null;
		}
	}

	/**
	 * Initiate the class as soon as it is included
	 */
	WFCO_Load_Connectors::get_instance();
}
