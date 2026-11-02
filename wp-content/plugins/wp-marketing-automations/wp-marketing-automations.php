<?php
/**
 * Plugin Name: FunnelKit Automations
 * Plugin URI: https://funnelkit.com/wordpress-marketing-automation-autonami/
 * Description: Recover lost revenue with Abandoned Cart Recovery for WooCommerce. Increase retention with Post Purchase Follow-Up Emails. Send beautiful Newsletters.
 * Version: 3.8.6.2
 * Author: FunnelKit
 * Author URI: https://funnelkit.com
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wp-marketing-automations
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Tested up to: 7.1
 * WooFunnels: true
 *
 * FunnelKit Automations is free software.
 * You can redistribute it and/or modify it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * FunnelKit Automations is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with FunnelKit Automations. If not, see <http://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

#[AllowDynamicProperties]
final class BWFAN_Core {

	/**
	 * @var BWFAN_Core
	 */
	private static $_instance = null;
	private static $_registered_entity = array(
		'active'   => array(),
		'inactive' => array(),
	);
	/**
	 * @var BWFAN_Admin
	 */
	public $admin;

	/**
	 * @var BWFAN_Public
	 */
	public $public;

	/**
	 * @var BWFAN_Load_Integrations
	 */
	public $integration;

	/**
	 * @var BWFAN_Load_Sources
	 */
	public $sources;

	/**
	 * @var BWFAN_Rules_Loader
	 */
	public $rules;

	/**
	 * @var BWFAN_Shortcodes
	 */
	public $shortcodes;

	/**
	 * @var BWFAN_Logger
	 */
	public $logger;

	/**
	 * @var  BWFAN_Merge_Tag_Loader
	 */
	public $merge_tags;
	public $native_connectors;

	/**
	 * @var BWFAN_Automations
	 */
	public $automations;

	/**
	 * @var BWFAN_Automation_V2
	 */
	public $automations_v2;

	/**
	 * @var BWFAN_Tasks
	 */
	public $tasks;

	/**
	 * @var BWFAN_Logs
	 */
	public $logs;

	/**
	 * @var BWFAN_Abandoned_Cart
	 */
	public $abandoned;

	/**
	 * @var BWFAN_WooFunnels_Support
	 */
	public $support;

	/**
	 * @var BWFAN_Email_Conversations
	 */
	public $conversations;

	/**
	 * @var BWFAN_Conversation
	 */
	public $conversation;

	/**
	 * @var BWFAN_Conversions
	 */
	public $conversions;

	/**
	 * @var BWFAN_Connectors
	 */
	public $connectors;

	/**
	 * @var BWFAN_Load_Custom_Search
	 */
	public $custom_search;

	/**
	 * @var BWFAN_Subscribe_Link_Handler
	 */
	public $subscribe_link_handler;

	/**
	 * @var BWFAN_Importer
	 */
	public $importer;

	public $wfco_admin;

	public $db;
	public $bwfan_api;
	public $bwfan_recipe;
	public $automations_v2_contact;
	public $exporter;

	private function __construct() {
		add_filter( 'wfco_include_connector', function () {
			return true;
		} );
		/**
		 * Load important variables and constants
		 */
		$this->define_plugin_properties();

		/**
		 * Class autoloader: fallback for any FKA class referenced before its file is
		 * explicitly required. The classmap (includes/class-bwfan-autoload-map.php)
		 * is loaded lazily — only on the first miss — so this costs nothing when the
		 * existing eager requires keep firing first.
		 */
		spl_autoload_register( static function ( $class ) {
			static $map = null;
			if ( null === $map ) {
				$map_file = BWFAN_PLUGIN_DIR . '/includes/class-bwfan-autoload-map.php';
				if ( ! is_readable( $map_file ) ) {
					return; // transient miss (update file-swap mid-flight?) — don't cache failure, retry on next autoload
				}
				$loaded = require $map_file;
				if ( ! is_array( $loaded ) ) {
					return; // malformed read — same: leave $map null so the next autoload retries
				}
				// PHP class names are case-insensitive; normalise keys so a lookup
				// tolerates any casing the calling code used (e.g. BWFAN_WooCommerce_Compatibility).
				$map = array_change_key_case( $loaded, CASE_LOWER );
			}
			$key = strtolower( $class );
			if ( isset( $map[ $key ] ) ) {
				$path = BWFAN_PLUGIN_DIR . '/' . $map[ $key ];
				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		} );

		/**
		 * Load dependency classes like bwfan-functions.php
		 */
		$this->load_dependencies_support();
		/**
		 * Initiates and loads WooFunnels start file
		 */
		$this->load_woofunnels_core_classes();

		/**
		 * Loads common file
		 */
		$this->load_commons();
	}

	/**
	 * Defining constants
	 */
	public function define_plugin_properties() {
		define( 'BWFAN_VERSION', '3.8.6.2' );
		define( 'BWFAN_MIN_PRO_VERSION', '3.8.6.1' );
		define( 'BWFAN_MIN_WC_VERSION', '5.0' );
		define( 'BWFAN_SLUG', 'bwfan' );
		define( 'BWFAN_FULL_NAME', 'FunnelKit Automations' );
		define( 'BWFAN_BWF_VERSION', '1.10.12.87' );
		define( 'BWFAN_PLUGIN_FILE', __FILE__ );
		define( 'BWFAN_PLUGIN_DIR', __DIR__ );
		define( 'BWFAN_TEMPLATE_DIR', plugin_dir_path( BWFAN_PLUGIN_FILE ) . 'templates' );
		define( 'BWFAN_SINGLE_EXPORT_DIR', wp_upload_dir()['basedir'] . '/funnelkit/fka-single-export' );
		define( 'BWFAN_IMPORT_DIR', wp_upload_dir()['basedir'] . '/funnelkit/fka-import' );

		$plugin_url = untrailingslashit( plugin_dir_url( BWFAN_PLUGIN_FILE ) );
		if ( is_ssl() ) {
			$plugin_url = preg_replace( "/^http:/i", "https:", $plugin_url );
		}

		define( 'BWFAN_PLUGIN_URL', $plugin_url );
		define( 'BWFAN_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
		define( 'BWFAN_DB_VERSION', '1.0' );

		( ! defined( 'BWFCRM_REACT_ENVIRONMENT' ) ) ? define( 'BWFCRM_REACT_ENVIRONMENT', 1 ) : '';
		define( 'BWFAN_REACT_PROD_URL', BWFAN_PLUGIN_URL . '/admin/frontend/dist' );

		if ( ! defined( 'BWFAN_IS_DEV' ) ) {
			define( 'BWFAN_IS_DEV', false );
		}

		( defined( 'BWFAN_IS_DEV' ) && true === BWFAN_IS_DEV ) ? define( 'BWFAN_VERSION_DEV', time() ) : define( 'BWFAN_VERSION_DEV', BWFAN_VERSION );
	}

	/**
	 * Setting up event Dependency Classes
	 */
	public function load_dependencies_support() {
		require_once BWFAN_PLUGIN_DIR . '/includes/bwfan-functions.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/bwfan-options.php';
		// BWFAN_Plugin_Dependency now resolved lazily by the classmap autoloader.
	}

	public function load_woofunnels_core_classes() {
		/** Setting Up WooFunnels Core */
		require_once( BWFAN_PLUGIN_DIR . '/start.php' );
	}

	public function load_commons() {
		// BWFAN_Phone_Numbers now resolved lazily by the classmap autoloader.
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-common.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-woofunnel-support.php';
		require_once BWFAN_PLUGIN_DIR . '/libraries/action-scheduler/action-scheduler.php';

		BWFAN_Common::init();
		/**
		 * Loads common hooks
		 */
		$this->load_hooks();
	}

	public function load_hooks() {
		/** Initialize Localization */
		add_action( 'init', array( $this, 'localization' ) );
		add_action( 'plugins_loaded', array( $this, 'load_classes' ), 1 );
		add_action( 'plugins_loaded', array( $this, 'define_api_basename' ) );
		/** Redirecting Plugin to the settings page after activation */
		add_action( 'activated_plugin', array( $this, 'redirect_on_activation' ) );

		/** Loading API's */
		add_action( 'rest_api_init', array( $this, 'bwfan_load_apis' ) );

		/** Loading CLI */
		if ( version_compare( PHP_VERSION, '5.3', '>' ) ) {
			add_action( 'plugins_loaded', array( $this, 'load_cli' ), 20 );
		}
	}

	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self;
		}

		return self::$_instance;
	}

	public static function register( $short_name, $class, $overrides = null ) {
		/** Ignore classes that have been marked as inactive */
		if ( in_array( $class, self::$_registered_entity['inactive'], true ) ) {
			return;
		}

		/** Mark classes as active. Override existing active classes if they are supposed to be overridden */
		$index = array_search( $overrides, self::$_registered_entity['active'], true );
		if ( false !== $index ) {
			self::$_registered_entity['active'][ $index ] = $class;
		} else {
			self::$_registered_entity['active'][ $short_name ] = $class;
		}

		/** Mark overridden classes as inactive. */
		if ( ! empty( $overrides ) ) {
			self::$_registered_entity['inactive'][] = $overrides;
		}
	}

	/**
	 * Admin notice if Pro older version active
	 */
	public function maybe_show_old_pro_notice() {
		if ( ! bwfan_is_autonami_pro_active() ) {
			return;
		}
		if ( version_compare( BWFAN_PRO_VERSION, BWFAN_MIN_PRO_VERSION, '>=' ) ) {
			return;
		}
		?>
        <div class="notice notice-warning" style="display: block!important;">
            <p>
				<?php
				echo __( '<strong>Warning! Old version of FunnelKit Automations Pro is detected.</strong> We strongly recommend to update the latest version of FunnelKit Automations Pro.', 'wp-marketing-automations' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
            </p>
        </div>
		<?php
	}

	public static function define_api_basename() {
		$slug = 'autonami-app';
		if ( defined( 'BWFAN_PRO_VERSION' ) && version_compare( BWFAN_PRO_VERSION, '2.5.1', '<' ) ) {
			$slug = 'autonami-admin';
		}

		define( 'BWFAN_API_NAMESPACE', $slug );
	}

	public function load_classes() {
		/**
		 * Loads all the public
		 */
		$this->load_public();

		/**
		 * Loads all the admin
		 */
		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'maybe_show_old_pro_notice' ), 20 );
			$this->load_admin();
		}

		$this->register_abstract();

		/**
		 * Loads rule classes
		 */
		if ( bwfan_is_autonami_pro_active() || BWFAN_Common::is_automation_v1_active() ) {
			// BWFAN_Rules now resolved lazily by the classmap autoloader — pure
			// utility class with only static-method consumers (e.g. BWFAN_Rules::get_order_object()
			// in rules/rules/order.php). No file-scope side effects, and its constructor
			// is never instantiated, so eager loading was wasted work.
			require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-rules-loader.php';
		}

		/**
		 * Loads core classes
		 */
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-db.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-db-update.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-load-integrations.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-merge-tag-loader.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-load-sources.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-load-connectors.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-importer.php';

		require_once BWFAN_PLUGIN_DIR . '/compatibilities/class-bwfan-compatibilities.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-automations.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-tasks.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-logs.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-logger.php';
		// BWFAN_Dashboards now resolved lazily by the classmap autoloader.
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-connectors.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-load-custom-search.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-recipe-loader.php';

		/** Automation builder v2 */
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-automation-v2.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-automation-v2-contact.php';

		/** Subscribe link handler */
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-subscribe-link-handler.php';

		/** Remove duplicate contacts — admin-only dev tool (hooks only on admin_head) */
		if ( is_admin() ) {
			require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-dev-remove-cfields-duplicate-records.php';
		}

		// BWFAN_Recoverable_Carts now resolved lazily by the classmap autoloader
		// (was previously gated on bwfan_is_woocommerce_active(); the gate is no longer
		// needed because the class is only autoloaded if something references it).

		if ( bwfan_is_autonami_pro_active() ) {
			/** admin-only dev tool (hooks only on admin_head) */
			if ( is_admin() ) {
				require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-dev-get-broadcast-timing.php';
			}

			/** Added export handler */
			require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-exporter-handler.php';
		}
		/** Load contact-related classes */
		if ( BWFAN_Common::is_pro_3_0() ) {
			// Classmap-autoloaded (lazy): BWFCRM_Contact, BWFCRM_Lists, BWFCRM_Tag,
			// BWFCRM_Note, BWFAN_Funnels, BWFAN_Message, BWFCRM_Automations,
			// BWFCRM_Fields, BWFCRM_Group.
			// Eight of these (all except BWFAN_Funnels) wrap their class declaration in an
			// internal `if (BWFAN_Common::is_pro_3_0())` gate, so when Pro 3.0 isn't active
			// the autoloader resolves the file but the class is never declared — same effect
			// as the previous eager require, which was already gated on the same condition.
			// BWFAN_Funnels is declared unconditionally (no internal gate), but is safe to
			// autoload anyway: it has no parent class and no file-scope side effects, and its
			// only caller (`new BWFAN_Funnels()` inside the Pro-3.0-gated BWFCRM_Contact) is
			// never reached unless Pro 3.0 is active.
			// None of these files has any file-scope ::get_instance() — they are consumed via
			// static method calls or class_exists()-guarded `new`, so eager loading was wasted work.
			require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-email-conversations.php';
			require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-conversation.php';
			require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-conversions.php';
		}

		$this->register_controllers();

		do_action( 'bwfan_before_register_modules' );

		$this->register_modules();

		// After including class now initialize all class or functions
		$this->register_classes();
	}

	/**
	 * Load all the API classes
	 *
	 * @return void
	 */
	public function bwfan_load_apis() {
		$rest_route = isset( $_GET['rest_route'] ) ? $_GET['rest_route'] : ''; // phpcs:ignore
		if ( empty( $rest_route ) && isset( $_SERVER['REQUEST_URI'] ) ) {
			/** Path only: a namespace in the query string (/wp-json/?x=autonami-app/...) must not load admin routes */
			$rest_route = explode( '?', $_SERVER['REQUEST_URI'], 2 )[0]; // phpcs:ignore
		}
		if ( empty( $rest_route ) ) {
			return;
		}

		if ( false === strpos( $rest_route, BWFAN_API_NAMESPACE ) ) {
			return;
		}
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-api-loader.php';

		BWFAN_API_Loader::register_routes( $rest_route );
	}

	public function load_public() {
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-public.php';
	}

	public function load_admin() {
		require_once BWFAN_PLUGIN_DIR . '/admin/class-bwfan-admin.php';
		// BWFAN_Header now resolved lazily by the classmap autoloader.
	}

	private function register_abstract() {
		// Abstracts in includes/abstracts/ are now resolved lazily by the classmap
		// autoloader. They have no file-scope side effects except for one:
		// class-bwfan-ajax-controller.php registers ajax handlers via a top-level
		// BWFAN_AJAX_Controller::init() call, which must fire before WordPress
		// dispatches admin-ajax. That single file stays in the eager load path.
		require_once BWFAN_PLUGIN_DIR . '/includes/abstracts/class-bwfan-ajax-controller.php';
	}

	private function register_controllers() {
		// Controllers in includes/controllers/ are now resolved lazily by the classmap
		// autoloader. Previously this method globbed the directory and eager-loaded
		// every file on each request, but each controller is a pure class definition
		// with no file-scope side effects — perfect autoload candidates.
	}

	public function register_modules() {
		// Eager-load modules with file-scope side effects (file-scope ::get_instance(),
		// BWFAN_Core::register(), or instantiation that must fire at boot for hook wiring).
		// BWFAN_Notification_Email_Controller and BWFAN_Notification_Metrics_Controller are
		// pure class definitions — classmap-autoloaded on first `new` reference.
		require_once BWFAN_PLUGIN_DIR . '/modules/abandoned-cart/class-bwfan-ab-load-events.php';
		require_once BWFAN_PLUGIN_DIR . '/modules/abandoned-cart/class-bwfan-abandoned.php';
		require_once BWFAN_PLUGIN_DIR . '/modules/utm/class-bwfan-common-shortcodes.php';
		require_once BWFAN_PLUGIN_DIR . '/modules/utm/class-bwfan-manage-profile.php';
		require_once BWFAN_PLUGIN_DIR . '/modules/utm/class-bwfan-unsubscribe.php';

		// BWFAN_Notification_Email is now classmap-autoloaded. Its only two live hooks
		// (settings-save and Action-Scheduler cron callback) are wired here via thin
		// closures so the 600+ line class file loads only when one of them actually fires.
		add_action( 'bwfan_after_save_global_settings', static function ( $old_value, $value ) {
			BWFAN_Notification_Email::get_instance()->set_scheduler( $old_value, $value );
		}, 10, 2 );
		add_action( 'bwfan_run_notifications', static function () {
			BWFAN_Notification_Email::get_instance()->run_notifications();
		} );
	}

	public function register_classes() {
		$load_classes = self::get_registered_class();
		if ( is_array( $load_classes ) && count( $load_classes ) > 0 ) {
			foreach ( $load_classes as $access_key => $class ) {
				if ( ! method_exists( $class, 'get_instance' ) ) {
					continue;
				}
				$this->{$access_key} = $class::get_instance();
			}
			do_action( 'bwfan_loaded' );
		}
	}

	public static function get_registered_class() {
		return self::$_registered_entity['active'];
	}

	public function localization() {
		// Load text domain only if not already loaded (WordPress 6.7.0+ requirement: must load on init or later)
		if ( ! is_textdomain_loaded( 'wp-marketing-automations' ) ) {
			load_plugin_textdomain( 'wp-marketing-automations', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- lets translation files placed in the plugin /languages folder load
		}
	}

	/**
	 * Added redirection on plugin activation
	 *
	 * @param $plugin
	 */
	public function redirect_on_activation( $plugin ) {
		if ( defined( 'WP_CLI' ) || ( plugin_basename( __FILE__ ) !== $plugin ) ) {
			return;
		}

		wp_safe_redirect( add_query_arg( array(
			'page' => 'autonami',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function load_cli() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			/** v1 cli command register */
			require_once BWFAN_PLUGIN_DIR . '/libraries/action-scheduler-ct/class-bwfan-as-ct-cli.php';
			WP_CLI::add_command( 'autonami-tasks', 'BWFAN_AS_CT_CLI' );

			/** v2 cli command register */
			require_once BWFAN_PLUGIN_DIR . '/libraries/action-scheduler-v2/class-bwfan-as-ct-cli.php';
			WP_CLI::add_command( 'autonami-automation-contact', 'BWFAN_AS_CT_CLI' );
		}
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

if ( ! function_exists( 'BWFAN_Core' ) ) {

	/**
	 * Global Common function to load all the classes
	 * @return BWFAN_Core
	 */
	function BWFAN_Core() {  //@codingStandardsIgnoreLine
		return BWFAN_Core::get_instance();
	}
}

BWFAN_Core();
