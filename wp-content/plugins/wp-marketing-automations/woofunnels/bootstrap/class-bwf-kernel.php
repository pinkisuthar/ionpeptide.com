<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BWF_Lazy_Logger' ) ) {
	/**
	 * Lazy stand-in seeded into WooFunnels_Dashboard::$classes['BWF_Logger'].
	 *
	 * That slot is public API — external plugins call $classes['BWF_Logger']->log() directly
	 * without null-checking it — so it must always hold an object that responds to ->log().
	 * This proxy forwards every call to the real singleton, which autoloads on first use, so the
	 * 3.2KB BWF_Logger class is not parsed on requests that never log (e.g. plain frontend views).
	 */
	class BWF_Lazy_Logger {
		public function __call( $method, $args ) {
			return BWF_Logger::get_instance()->{$method}( ...$args );
		}
	}
}

/**
 * WooFunnels framework kernel.
 *
 * Owns the pieces every request needs: classmap autoloading, request-context
 * detection and the context-gated eager-load policy. Everything else in the
 * framework loads on demand. WooFunnels_Dashboard delegates here — its public
 * surface is frozen API for older consumer plugins and must not change.
 *
 * @package WooFunnels
 */
if ( ! class_exists( 'BWF_Kernel' ) ) {

	class BWF_Kernel {

		/**
		 * Class => file map for O(1) autoloading. Paths relative to WooFunnel_Loader::$ultimate_path.
		 * Keys lowercase (PHP class lookups are case-insensitive). Covers exactly the classes the
		 * stat-based fallback in WooFunnels_Dashboard::autoloader() can resolve — keep in sync when
		 * adding/renaming framework classes.
		 *
		 * @var array
		 */
		private static $classmap = array(
			'bwf_admin_breadcrumbs'                     => 'includes/class-bwf-admin-breadcrumbs.php',
			'bwf_admin_general_settings'                => 'includes/class-bwf-admin-general-settings.php',
			/**
			 * Custom Action Scheduler store. WooFunnels_AS_DS::load_files() skips the require-glob on plain
			 * frontend views, so these must stay autoloadable there: bwf_schedule_single_action() and
			 * bwf_schedule_recurring_action() gate on class_exists( 'BWF_AS_Actions_Crud' ) and silently
			 * return false when it is missing — a frontend schedule (abandoned cart, notifications) would
			 * be dropped with no error. Autoloading parses one file instead of the whole ~37KB glob.
			 * BWF_AS_CLI is deliberately absent: it extends WP_CLI_Command and CLI always loads the glob.
			 */
			'bwf_as'                                    => 'as-data-store/asct/class-bwf-as.php',
			'bwf_as_action_store'                       => 'as-data-store/asct/class-bwf-as-action-store.php',
			'bwf_as_actions_crud'                       => 'as-data-store/db/class-bwf-as-actions-crud.php',
			'bwf_as_log_store'                          => 'as-data-store/asct/class-bwf-as-log-store.php',
			'bwf_compatibility_with_aelia_cs'           => 'compatibilities/class-bwf-compatibility-with-aelia-cs.php',
			'bwf_compatibility_with_wc_price_based_on_country' => 'compatibilities/class-bwf-compatibility-with-wc-price-based-on-country.php',
			'bwf_compatibility_with_woocommerce_payments' => 'compatibilities/class-bwf-compatibility-with-woocommerce-payments.php',
			'bwf_compatibility_with_woocs'              => 'compatibilities/class-bwf-compatibility-with-woocs.php',
			'bwf_compatibility_with_woomulticurrency'   => 'compatibilities/class-bwf-compatibility-with-woomulticurrency.php',
			'bwf_compatibility_with_wpml_multicurrency' => 'compatibilities/class-bwf-compatibility-with-wpml-multicurrency.php',
			'bwf_compatibility_with_yaycurrency'        => 'compatibilities/class-bwf-compatibility-with-yaycurrency.php',
			/** Abstract connector base. Filename does not match the class name, so the legacy stat-based
			    autoloader cannot find it and WFCO_Admin's include_once used to be its only loader — which is
			    now skipped on frontend views. Every connector.php does `extends BWF_CO`, and frontend event
			    handling can reach WFCO_Load_Connectors::load_connectors_direct(), so it must be autoloadable. */
			'bwf_co'                                    => 'connector/class-wfco-connector.php',
			'bwf_contact_fns'                           => 'contact/class-bwf-contact-fns.php',
			'bwf_contacts'                              => 'contact/class-bwf-contacts.php',
			'bwf_customer_fns'                          => 'contact/class-bwf-customer-fns.php',
			'bwf_db_updater_fns'                        => 'contact/class-bwf-db-updater-fns.php',
			'bwf_customers'                             => 'contact/class-bwf-customers.php',
			'bwf_data_tags'                             => 'includes/class-bwf-data-tags.php',
			'bwf_ecomm_tracking_common'                 => 'includes/class-bwf-ecomm-tracking-common.php',
			'bwf_facebook_sdk'                          => 'includes/class-bwf-facebook-sdk.php',
			'bwf_facebook_sdk_factory'                  => 'includes/class-bwf-facebook-sdk-factory.php',
			'bwf_json_cache'                            => 'includes/class-bwf-json-cache.php',
			'bwf_logger'                                => 'includes/class-bwf-logger.php',
			'bwf_plugin_compatibilities'                => 'compatibilities/class-bwf-plugin-compatibilities.php',
			'bwf_wc_compatibility'                      => 'compatibilities/class-bwf-wc-compatibility.php',
			'wfco_admin'                                => 'connector/class-wfco-admin.php',
			'wfco_call'                                 => 'connector/class-wfco-call.php',
			'wfco_common'                               => 'connector/class-wfco-common.php',
			'wfco_connector_api'                        => 'connector/class-wfco-connector-api.php',
			'wfco_connector_screen'                     => 'connector/class-wfco-connector-screen.php',
			'wfco_connector_screen_factory'             => 'connector/class-wfco-connector-screen-factory.php',
			'wfco_db'                                   => 'connector/class-wfco-db.php',
			'wfco_load_connectors'                      => 'connector/class-wfco-load-connectors.php',
			'wfco_model'                                => 'includes/class-wfco-model.php',
			/** connector/db models: loaded on master by WFCO_Db::load_db_classes() at plugins_loaded|8, which
			    no longer runs on frontend views. Their directory is not one the legacy autoloader scans. */
			'wfco_model_connectormeta'                  => 'connector/db/class-wfco-model-connectormeta.php',
			'wfco_model_connectors'                     => 'connector/db/class-wfco-model-connectors.php',
			'wfco_model_report_views'                   => 'includes/class-wfco-model-report-views.php',
			'woofunnels_addons'                         => 'includes/class-woofunnels-addons.php',
			'woofunnels_admin_notifications'            => 'includes/class-woofunnels-admin-notifications.php',
			'woofunnels_api'                            => 'includes/class-woofunnels-api.php',
			'woofunnels_background_updater'             => 'contact/class-woofunnels-background-updater.php',
			'woofunnels_cache'                          => 'includes/class-woofunnels-cache.php',
			'woofunnels_contact'                        => 'contact/class-woofunnels-contact.php',
			'woofunnels_contacts_background_updater'    => 'contact/class-woofunnels-contacts-background-updater.php',
			'woofunnels_create_db_tables'               => 'contact/class-woofunnels-create-db-tables.php',
			'woofunnels_customer'                       => 'contact/class-woofunnels-customer.php',
			'woofunnels_dashboard_ui'                   => 'includes/class-woofunnels-dashboard-ui.php',
			'woofunnels_db_operations'                  => 'contact/class-woofunnels-db-operations.php',
			'woofunnels_db_tables'                      => 'contact/class-woofunnels-db-tables.php',
			'woofunnels_db_updater'                     => 'contact/class-woofunnels-db-updater.php',
			'woofunnels_deactivate'                     => 'includes/class-woofunnels-deactivate.php',
			'woofunnels_file_api'                       => 'includes/class-woofunnels-file-api.php',
			'woofunnels_funnel_builder_commons'         => 'includes/class-woofunnels-funnel-builder-commons.php',
			'woofunnels_license_check'                  => 'includes/class-woofunnels-license-check.php',
			'woofunnels_license_controller'             => 'includes/class-woofunnels-license-controller.php',
			'woofunnels_licenses'                       => 'includes/class-woofunnels-licenses.php',
			'woofunnels_notifications'                  => 'includes/class-woofunnels-notifications.php',
			'woofunnels_optin_manager'                  => 'includes/class-woofunnels-optin-manager.php',
			'woofunnels_process'                        => 'includes/class-woofunnels-process.php',
			'woofunnels_support'                        => 'includes/class-woofunnels-support.php',
			'woofunnels_tracking_collector_abstract'    => 'includes/abstracts/class-woofunnels-tracking-collector-abstract.php',
			'woofunnels_tracking_registry'              => 'includes/class-woofunnels-tracking-registry.php',
			'woofunnels_transient'                      => 'includes/class-woofunnels-transient.php',
			'woofunnels_updater_licenses_table'         => 'includes/class-woofunnels-updater-licenses-table.php',
			'woofunnels_usage_collector_abstract'       => 'includes/abstracts/class-woofunnels-usage-collector-abstract.php',
			'woofunnels_usage_registry'                 => 'includes/class-woofunnels-usage-registry.php',
		);

		/**
		 * O(1) classmap autoloader. Registered before the legacy stat-based
		 * WooFunnels_Dashboard::autoloader(), which stays as fallback for
		 * anything unmapped.
		 *
		 * @param string $class_name
		 */
		public static function autoload( $class_name ) {
			$class_key = strtolower( $class_name );
			if ( isset( self::$classmap[ $class_key ] ) ) {
				require_once WooFunnel_Loader::$ultimate_path . self::$classmap[ $class_key ];
			}
		}

		/**
		 * Whether the current request is a REST API request.
		 * Usable before REST_REQUEST is defined (e.g. on plugins_loaded).
		 *
		 * @return bool
		 */
		public static function is_rest_request() {
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return true;
			}
			if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request-context detection only
				return true;
			}
			if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
				return false;
			}

			return false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/' . rest_get_url_prefix() . '/' );
		}

		/**
		 * Backend (non-public-frontend) contexts: wp-admin & admin-ajax, wp-cron, WP-CLI and REST
		 * (incl. the AS worker, which executes recurring hooks over REST). Returns false only for a
		 * plain public front-end page view — the one context where backend/worker machinery is skipped.
		 *
		 * @return bool
		 */
		public static function is_backend_context() {
			/**
			 * ALTERNATE_WP_CRON runs the scheduler inside a normal front-end request that carries the
			 * ?doing_wp_cron query var. DOING_CRON is not defined until wp_loaded — after this gate is
			 * evaluated on init — so detect that request by its query var. Only the actual cron-carrying
			 * request counts as worker, NOT every request on a site that merely defines the constant:
			 * ALTERNATE_WP_CRON is set to true OR false, so defined() was the wrong test (true whenever
			 * the constant exists, which flipped every front-end request to worker and defeated the gating).
			 */
			return is_admin()
				|| wp_doing_cron()
				|| isset( $_GET['doing_wp_cron'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request-context detection only
				|| ( defined( 'WP_CLI' ) && WP_CLI )
				|| self::is_rest_request();
		}

		/**
		 * Context where background-process handlers can fire or be dispatched:
		 * admin-ajax (is_admin), cron healthcheck, CLI — and REST, because
		 * consumer REST tools endpoints (e.g. Funnel Builder indexing tools)
		 * call WooFunnels_DB_Updater methods that dereference the updaters.
		 * Only plain frontend views skip loading them.
		 *
		 * @return bool
		 */
		public static function is_background_updater_context() {
			return self::is_backend_context();
		}

		/**
		 * Context-gated list of framework classes to instantiate eagerly.
		 * Anything skipped here stays reachable through the autoloader.
		 *
		 * @return array class names, each exposing get_instance()
		 */
		public static function get_eager_classes() {
			$classes = array(
				'WooFunnels_DB_Updater',
			);
			/** BWF_Data_Tags + BWF_Logger load lazily — see wire_lazy_classes() (BWF_Logger's slot is seeded with a proxy there) */

			/** All hooks are admin-side, cron or license events — nothing fires on frontend views */
			if ( self::is_backend_context() ) {
				$classes[] = 'WooFunnels_process';
				/**
				 * Only a schema provider for the bwf_add_db_table_schema filter, which is consumed
				 * solely by Create_DB_Tables::create(). That reconciliation now runs only in worker
				 * contexts (WooFunnels_DB_Updater::maybe_create_db_tables), so DB_Tables is not needed
				 * on plain frontend views. Its $classes slot is not read by any external plugin.
				 */
				$classes[] = 'WooFunnels_DB_Tables';
				/**
				 * WFCO_Admin's constructor is the sole entry point that reads wfco_include_connector
				 * and pulls the ~50KB connector stack (email/SMS providers). Connectors are consumed
				 * only by action execution (cron/REST) and the admin connector UI — never a plain
				 * frontend page view. Its one frontend-reachable side effect, the
				 * bwf_add_db_table_schema filter (WFCO_Common::init), fires on admin_init, which is
				 * worker context. Nothing reads the stored instance, so skipping it on frontend is inert.
				 */
				$classes[] = 'WFCO_Admin';
			}

			/** Admin-surface-only hooks (admin_head/admin_footer/order list columns/wp_ajax) */
			if ( is_admin() ) {
				$classes[] = 'WooFunnels_Notifications';
				$classes[] = 'WooFunnels_Funnel_Builder_Commons';
			}

			/** Inert without Funnel Builder — its constructor early-returns when WFFN_Core is absent */
			if ( class_exists( 'WFFN_Core' ) ) {
				$classes[] = 'BWF_Ecomm_Tracking_Common';
			}

			return $classes;
		}

		/**
		 * Lazy wiring for classes whose only hooks fire rarely (verified: zero
		 * consumer code references these classes or their callback identities).
		 * The class file parses on first fire instead of on every request.
		 */
		public static function wire_lazy_classes() {
			/**
			 * BWF_Data_Tags — shortcode handlers resolve at render time.
			 * Instantiation re-registers the real handlers over these tags
			 * (add_shortcode replaces per tag), so resolution happens once.
			 */
			foreach ( array( 'get_cookie', 'get_url_parameter' ) as $code ) {
				add_shortcode( 'wf_' . $code, static function ( $attr = array() ) use ( $code ) {
					return BWF_Data_Tags::get_instance()->{$code}( $attr );
				} );
			}

			/**
			 * Seed the BWF_Logger slot with a lazy proxy instead of eager-instantiating the real
			 * logger. Keeps the $classes['BWF_Logger']->log() public contract working while the
			 * 3.2KB class autoloads only on the first forwarded call (never on a plain frontend view).
			 */
			if ( class_exists( 'WooFunnels_Dashboard' ) ) {
				WooFunnels_Dashboard::$classes['BWF_Logger'] = new BWF_Lazy_Logger();
			}
		}

		/**
		 * Context-gated list of framework classes to boot via static init().
		 *
		 * @return array
		 */
		public static function get_static_init_classes() {
			$classes = array();

			/** Usage tracking: admin notices/ajax + recurring collector hooks (cron/AS worker) */
			if ( self::is_backend_context() ) {
				$classes[] = 'WooFunnels_OptIn_Manager';
			}

			/** Deactivation survey: admin_footer/wp_ajax only */
			if ( is_admin() ) {
				$classes[] = 'WooFunnels_deactivate';
			}

			return $classes;
		}
	}

	spl_autoload_register( array( 'BWF_Kernel', 'autoload' ) );
}
