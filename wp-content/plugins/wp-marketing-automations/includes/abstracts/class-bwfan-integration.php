<?php

#[AllowDynamicProperties]
abstract class BWFAN_Integration {
	/**
	 * Return Save setting of integration
	 * @var array
	 */
	protected $integration_settings = [];
	/**
	 * this property use for admin builder ui
	 * @var array
	 */
	protected $localize_data = [];
	/**
	 * Return Nice Human readable name of integration
	 * @var null
	 */
	protected $nice_name = 'WordPress';

	/**
	 * Return integration belong native WordPress or other like activecampaign
	 * @var bool
	 */
	protected $native_integration = false;
	/**
	 * If you add new integration and have respective action then please override this property in child class
	 * @var string
	 */
	protected $action_dir = __DIR__;
	/**
	 * slug => filename inside {action_dir}/actions.
	 * Declared ⇒ this integration's actions resolve on demand.
	 * Empty ⇒ today's eager glob, unchanged — the fallback that keeps every
	 * old and third-party integration working with zero changes.
	 *
	 * Entries are declared in filename (glob) order.
	 * Generate with bin/generate-action-maps.php.
	 *
	 * @var array
	 */
	protected $action_map = [];
	/**
	 * Subset of $action_map slugs whose classes declare a real load_hooks().
	 * These materialize immediately during load_actions(), because their WP
	 * hooks (wp_ajax_*, filters) must exist before anything asks for the
	 * action.
	 *
	 * @var array
	 */
	protected $eager_actions = [];
	/**
	 * Return Unique Slug of Integration
	 * @var string
	 */
	protected $slug = '';
	/**
	 * Return used connector slug in integration
	 * @var string
	 */
	protected $connector_slug = '';

	/**
	 * Return integration need a separate connector
	 * @var bool
	 */
	protected $need_connector = false;

	protected $group_name = '';
	protected $group_slug = '';

	protected $priority = 10;

	/**
	 * Loads all actions of current integration.
	 *
	 * With a declared $action_map, actions register into a runtime index and
	 * resolve on demand through get_action(); only $eager_actions load here.
	 * Without one, the original eager glob runs unchanged.
	 */
	public function load_actions() {
		$slug = $this->get_slug();

		if ( empty( $this->action_map ) ) {
			$resource_dir = $this->action_dir . '/actions';

			if ( file_exists( $resource_dir ) ) {
				foreach ( glob( $resource_dir . '/class-*.php' ) as $_field_filename ) {
					if ( ! is_readable( $_field_filename ) ) { // survive update file-swap / partial deploy
						continue;
					}
					$file_data = pathinfo( $_field_filename );
					if ( isset( $file_data['basename'] ) && 'index.php' === $file_data['basename'] ) {
						continue;
					}
					$action_class = require_once( $_field_filename );

					if ( is_string( $action_class ) && method_exists( $action_class, 'get_instance' ) ) {
						/**
						 * @var $action_obj BWFAN_Action
						 */
						$action_obj = $action_class::get_instance();
						$action_obj->load_hooks();
						if ( method_exists( $action_obj, 'admin_enqueue_assets' ) && is_admin() && BWFAN_Common::is_automation_v1_active() && BWFAN_Common::is_autonami_page() ) {
							// Add action to avoid enqueueing assets on every admin page load
							add_action( 'admin_enqueue_scripts', array( $action_obj, 'admin_enqueue_assets' ), 98 );
						}
						$action_obj->set_integration_type( $this->get_slug() );
						BWFAN_Load_Integrations::register_actions( $action_obj );
						$this->do_after_action_registration( $action_obj );

					}
				}
			}
		} else {
			BWFAN_Load_Integrations::register_lazy_actions( $slug, $this->action_dir . '/actions', $this->action_map, true );

			$eager = $this->eager_actions;
			if ( is_admin() && BWFAN_Common::is_automation_v1_active() && BWFAN_Common::is_autonami_page() ) {
				/**
				 * The v1 admin screen needs every action's admin_enqueue_assets
				 * registered BEFORE admin_enqueue_scripts@98 fires — load the
				 * whole map here at init, not later from a getter. Failure is
				 * silent (no error, just missing assets).
				 */
				$eager = array_keys( $this->action_map );
			}
			foreach ( $eager as $eager_slug ) {
				BWFAN_Load_Integrations::materialize( $eager_slug );
			}

			if ( defined( 'BWFAN_IS_DEV' ) && BWFAN_IS_DEV ) {
				$this->verify_action_map();
			}
		}

		do_action( 'bwfan_' . $slug . '_actions_loaded', $this );
	}

	/**
	 * Dev-only: catch declaration drift the day it happens.
	 * A forgotten map entry or hook flag fails SILENTLY in production —
	 * this is the insurance that makes a hand-declared map trustworthy.
	 */
	protected function verify_action_map() {
		$dir   = $this->action_dir . '/actions';
		$files = array_map( 'basename', (array) glob( $dir . '/class-*.php' ) );

		foreach ( array_diff( $files, $this->action_map ) as $unmapped ) {
			error_log( sprintf( '[BWFAN action_map] %s: file not in map: %s', get_class( $this ), $unmapped ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- developer diagnostic, only runs when BWFAN_IS_DEV is defined
		}
		foreach ( array_diff( $this->action_map, $files ) as $stale ) {
			error_log( sprintf( '[BWFAN action_map] %s: mapped file missing: %s', get_class( $this ), $stale ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- developer diagnostic, only runs when BWFAN_IS_DEV is defined
		}
		if ( array_values( $this->action_map ) !== array_values( array_intersect( $files, $this->action_map ) ) ) {
			error_log( sprintf( '[BWFAN action_map] %s: map not in filename order - builder order will drift', get_class( $this ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- developer diagnostic, only runs when BWFAN_IS_DEV is defined
		}

		/** Hook flags: materialize everything and compare against Reflection. */
		$declared = [];
		foreach ( $this->action_map as $map_slug => $filename ) {
			$obj = BWFAN_Load_Integrations::materialize( $map_slug );
			if ( $obj instanceof BWFAN_Action && 'BWFAN_Action' !== ( new ReflectionMethod( get_class( $obj ), 'load_hooks' ) )->getDeclaringClass()->getName() ) {
				$declared[] = $map_slug;
			}
		}
		if ( array_values( $declared ) !== array_values( $this->eager_actions ) ) {
			error_log( sprintf( '[BWFAN action_map] %s: $eager_actions is [%s], classes with real load_hooks() are [%s]', get_class( $this ), implode( ',', $this->eager_actions ), implode( ',', $declared ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- developer diagnostic, only runs when BWFAN_IS_DEV is defined
		}
	}

	/**
	 * Load one action file and register the action it returns.
	 *
	 * Performs exactly the sequence load_actions() performs today, in the
	 * same order. $dir is a parameter rather than $this->action_dir because
	 * an injector's files may live in another plugin: Pro WC ships files
	 * under Pro while its actions belong to Lite's `wc` integration.
	 *
	 * Public so BWFAN_Load_Integrations can delegate here —
	 * do_after_action_registration() is protected, so only this class may
	 * call it, and $this-> dispatches polymorphically to every override.
	 *
	 * @param string $dir         absolute path to the actions directory
	 * @param string $filename    basename of the action file
	 * @param bool   $calls_after whether to run do_after_action_registration()
	 *
	 * @return BWFAN_Action|false|null BWFAN_Action on success; false if the
	 *                                  file could not be read (transient — an
	 *                                  update's file swap); null if the file
	 *                                  loaded and its registration guard
	 *                                  declined (permanent for this request).
	 */
	public function materialize_action( $dir, $filename, $calls_after = true ) {
		$file = $dir . '/' . $filename;

		/**
		 * Survive an update's file swap, exactly as the glob loop does.
		 *
		 * Returns false, not null, so the caller can tell a transient read
		 * failure from a guarded file that loaded and declined to register.
		 * Only the latter is safe to memoize.
		 */
		if ( ! is_readable( $file ) ) {
			return false;
		}

		$action_class = require_once( $file );

		/**
		 * A guarded file — `if ( bwfan_is_sublium_active() )`, self-redeclare
		 * guards — returns nothing when the guard fails. Today's behaviour,
		 * preserved: the map entry exists, the action does not.
		 */
		if ( ! is_string( $action_class ) || ! method_exists( $action_class, 'get_instance' ) ) {
			return null;
		}

		/** @var BWFAN_Action $action_obj */
		$action_obj = $action_class::get_instance();
		$action_obj->load_hooks();

		if ( method_exists( $action_obj, 'admin_enqueue_assets' ) && is_admin() && BWFAN_Common::is_automation_v1_active() && BWFAN_Common::is_autonami_page() ) {
			// Add action to avoid enqueueing assets on every admin page load
			add_action( 'admin_enqueue_scripts', array( $action_obj, 'admin_enqueue_assets' ), 98 );
		}

		$action_obj->set_integration_type( $this->get_slug() );
		BWFAN_Load_Integrations::register_actions( $action_obj );

		if ( $calls_after ) {
			$this->do_after_action_registration( $action_obj );
		}

		return $action_obj;
	}

	/**
	 * Get slug of an integration
	 * Note: Used in change_in_automations method of BWFAN_Automations class
	 *
	 * @return mixed|string
	 */
	public function get_slug() {
		$this->slug = str_replace( array( 'bwfan_', '_integration' ), '', sanitize_title( get_class( $this ) ) );

		return $this->slug;
	}

	protected function do_after_action_registration( BWFAN_Action $action_object ) {

	}

	public function get_localize_data() {
		$this->localize_data = [
			'nice_name'          => $this->get_name(),
			'slug'               => $this->get_slug(),
			'connector_slug'     => $this->get_connector_slug(),
			'native_integration' => $this->native_integration(),
			'group_slug'         => $this->get_group_slug(),
			'group_name'         => $this->get_group_name(),
			'priority'           => $this->get_priority()
		];

		return $this->localize_data;
	}

	public function get_name() {
		return trim( $this->nice_name );
	}

	public function get_connector_slug() {
		return $this->connector_slug;
	}

	public function get_group_slug() {
		return $this->group_slug;
	}

	public function get_group_name() {
		return $this->group_name;
	}

	public function get_priority() {
		return $this->priority;
	}

	public function native_integration() {
		return $this->native_integration;
	}

	public function set_settings( $data ) {
		if ( is_array( $data ) ) {
			$this->integration_settings = $data;
		}
	}

	public function get_settings( $key = '' ) {
		if ( ! empty( $key ) ) {
			return isset( $this->integration_settings[ $key ] ) ? $this->integration_settings[ $key ] : '';
		}

		return $this->integration_settings;
	}

	public function need_connector() {
		return $this->need_connector;
	}

	public function handle_response( $result, $connector_slug, $action_call_class_slug, $action_data = null ) {
		return $result;
	}

	/**
	 * to avoid unserialize of the current class
	 */
	public function __wakeup() {
		throw new ErrorException( 'BWFAN_Core can`t converted to string' );
	}

	/**
	 * to avoid serialize of the current class
	 */
	public function __sleep() {
		throw new ErrorException( 'BWFAN_Core can`t converted to string' );
	}

	/**
	 * To avoid cloning of current class
	 */
	protected function __clone() {
	}

}
