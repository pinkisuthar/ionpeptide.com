<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- legacy filename kept for back-compat
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * This class is a mail loader class for dashboard page , controls and sets up all the necessary actions
 *
 * @author woofunnels
 * @package WooFunnels
 */


define( 'BWF_VERSION', '1.10.12.87' );
define( 'BWF_DB_VERSION', '1.0.7' );

/** Kernel: classmap autoloader + request-context detection + eager-load policy */
require_once __DIR__ . '/../bootstrap/class-bwf-kernel.php';

if ( ! class_exists( 'WooFunnels_Dashboard' ) ) {
	#[AllowDynamicProperties]
	class WooFunnels_Dashboard {

		public static $currentPage;
		public static $parent;
		public static $selected = '';
		public static $pagefullurl = '';
		public static $is_dashboard_page = false;
		public static $loader_url = '';
		public static $is_core_menu = false;
		public static $classes = [];
		protected static $expectedurl;
		protected static $expectedslug;


		/**
		 * Dashboard-page UI lives in WooFunnels_Dashboard_UI (autoloaded on demand).
		 * These shims keep the public surface of this class intact for consumers.
		 */
		public static function load_page() {
			WooFunnels_Dashboard_UI::load_page();
		}

		public static function register_dashboard() {
			WooFunnels_Dashboard_UI::register_dashboard();
		}

		public static function woofunnels_dashboard_scripts() {
			WooFunnels_Dashboard_UI::woofunnels_dashboard_scripts();
		}

		/**
		 * Init function hooked on `admin_init`
		 * Set the required variables and register some important hooks
		 */
		public static function init() {


			self::$loader_url = WooFunnel_Loader::$ultimate_path;
			$selected         = null !== sanitize_text_field( filter_input( INPUT_GET, 'tab', FILTER_DEFAULT ) ) ? sanitize_text_field( filter_input( INPUT_GET, 'tab', FILTER_DEFAULT ) ) : 'plugins'; // phpcs:ignore WordPressVIPMinimum.Security.PHPFilterFunctions.RestrictedFilter

			self::$selected = $selected;

			/**
			 * Function to trigger error message at WordPress plugins page when we have update but license invalid
			 */
			self::add_notice_unlicensed_product();

			/**
			 * Retained for back-compat only -- see localization(), which is now a no-op.
			 * It previously hooked 'init' from here, which runs on admin_init (after init has
			 * already fired), so the callback never executed either way.
			 */
			self::localization();


			add_action( 'admin_head', [ __CLASS__, 'apply_scroll_fix_css' ] );
		}

		/**
		 * Getting and parsing all our licensing products and checking if there update is available
		 */
		public static function add_notice_unlicensed_product() {
			/**
			 * Getting necessary data
			 */
			$licenses = WooFunnels_licenses::get_instance()->get_data();

			/**
			 * Looping over to check how many licenses are invalid and pushing notification and error accordingly
			 */
			if ( $licenses && count( $licenses ) > 0 ) {
				foreach ( $licenses as $key => $license ) {
					if ( $license['product_status'] === 'invalid' ) {
						add_action( 'in_plugin_update_message-' . $key, array( __CLASS__, 'need_license_message' ), 10, 2 );
					}
				}
			}
		}

		public static function localization() {
			/**
			 * Intentionally empty. The framework no longer loads a text domain of its own.
			 *
			 * A second domain inside one plugin cannot be served by WordPress.org -- GlotPress
			 * builds a project per plugin slug, so 'woofunnels' was never reachable by translators
			 * and no .mo was ever shipped for it. The call this replaced was inert anyway: it
			 * pointed at woofunnels/includes/languages while the files sat in woofunnels/languages.
			 *
			 * These strings now belong to the host plugin's domain, which WordPress loads on its
			 * own. Kept as an empty method rather than deleted because it is public API and is
			 * still called from self::init(). Mirrors the bundled copy in Funnel Builder.
			 */
		}

		/**
		 * Message displayed if license not activated. <br/>
		 *
		 * @param array $plugin_data
		 * @param object $r
		 *
		 * @return void
		 */
		public static function need_license_message( $plugin_data, $r ) {
			if ( empty( $r->package ) ) {
				echo wp_kses_post( '<div class="woofunnels-updater-plugin-upgrade-notice">' . __( 'To enable this update please activate your FunnelKit license by visiting the Dashboard Page.', 'wp-marketing-automations' ) . '</div>' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			}
		}


		public static function woofunnels_licenses_data() {
			return WooFunnels_Dashboard_UI::woofunnels_licenses_data();
		}

		public static function woofunnels_tools_data() {
			return WooFunnels_Dashboard_UI::woofunnels_tools_data();
		}

		public static function woofunnels_support_data( $data ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			return WooFunnels_Dashboard_UI::woofunnels_support_data( $data );
		}

		public static function woofunnels_logs_data( $data ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			return WooFunnels_Dashboard_UI::woofunnels_logs_data( $data );
		}

		public static function show_right_area() {
			WooFunnels_Dashboard_UI::show_right_area();
		}

		public static function add_logs_tabs( $tabs ) {
			return WooFunnels_Dashboard_UI::add_logs_tabs( $tabs );
		}


		public static function autoloader( $class_name ) {
			if ( 0 === strpos( $class_name, 'WooFunnels_' ) || 0 === strpos( $class_name, 'BWF_' ) ) {
				$path         = WooFunnel_Loader::$ultimate_path . 'includes/class-' . self::slugify_classname( $class_name ) . '.php';
				$contact_path = WooFunnel_Loader::$ultimate_path . 'contact/class-' . self::slugify_classname( $class_name ) . '.php';
				$abstract_path = WooFunnel_Loader::$ultimate_path . 'includes/abstracts/class-' . self::slugify_classname( $class_name ) . '.php';

				if ( is_file( $path ) ) {
					require_once $path;
				}
				if ( is_file( $contact_path ) ) {
					require_once $contact_path;
				}
				if ( is_file( $abstract_path ) ) {
					require_once $abstract_path;
				}

				/**
				 * loading compatibility classes
				 */
				if ( false !== strpos( $class_name, 'Compatibility' ) || false !== strpos( $class_name, 'Compatibilities' ) ) {
					$path = WooFunnel_Loader::$ultimate_path . 'compatibilities/class-' . self::slugify_classname( $class_name ) . '.php';

					if ( is_file( $path ) ) {
						require_once $path;
					}

				}
			}
			if ( 0 === strpos( $class_name, 'WFCO_' ) ) {
				$path       = WooFunnel_Loader::$ultimate_path . 'connector/class-' . self::slugify_classname( $class_name ) . '.php';
				$model_path = WooFunnel_Loader::$ultimate_path . 'includes/class-' . self::slugify_classname( $class_name ) . '.php';

				if ( is_file( $path ) ) {
					require_once $path;
				}
				if ( is_file( $model_path ) ) {
					require_once $model_path;
				}
			}
		}

		/**
		 * Slug-ify the class name and remove underscores and convert it to filename
		 * Helper function for the auto-loading
		 *
		 * @param $class_name
		 *
		 *
		 * @return mixed|string
		 * @see WooFunnels_Dashboard::autoloader();
		 *
		 */
		public static function slugify_classname( $class_name ) {
			$classname = self::custom_sanitize_title( $class_name );
			$classname = str_replace( '_', '-', $classname );


			return $classname;
		}

		/**
		 * Custom sanitize title method to avoid conflicts with WordPress hooks on sanitize_title
		 *
		 * @param string $title The title to sanitize
		 * @return string The sanitized title
		 */
		private static function custom_sanitize_title( $title ) {
			$title = remove_accents( $title );
			$title = sanitize_title_with_dashes( $title );

			return $title;
		}

		public static function load_core_classes() {
			require dirname( __DIR__ ) . '/includes/bwf-functions.php';
			// Customer wrappers merged into woofunnels-contact-functions.php — one fewer frontend file load.
			require dirname( __DIR__ ) . '/contact/woofunnels-contact-functions.php';
			/**
			 * Loaded in every context on purpose. The two batch-indexing wrappers in here are indeed
			 * background-only, but the file also defines BWF_THRESHOLD_ORDERS / BWF_ORDERS_PER_BATCH,
			 * and WooFunnels_DB_Updater — which stays eager on EVERY request — dereferences
			 * BWF_THRESHOLD_ORDERS in bwf_add_indexing_consent_button(). On PHP 8 an undefined constant
			 * is a fatal Error, so gating this 852-byte file trades a negligible saving for a latent
			 * fatal on any frontend request that reaches that method (its hook,
			 * `woofunnels_tools_add_tables_row_start`, is public and could be fired anywhere).
			 */
			require dirname( __DIR__ ) . '/contact/woofunnels-db-updater-functions.php';

			// Tracking classes will be autoloaded on demand via autoloader
			// No need to load them here

			/** Context-gated lists — see BWF_Kernel; skipped classes stay reachable via autoloader */
			foreach ( BWF_Kernel::get_eager_classes() as $class ) {
				self::$classes[ $class ] = $class::get_instance();
			}

			BWF_Kernel::wire_lazy_classes();

			foreach ( BWF_Kernel::get_static_init_classes() as $class ) {
				$class::init();
			}


			/** WooFunnels AS Data Store */
			include_once dirname( __DIR__ ) . '/as-data-store/class-woofunnels-as-ds.php';
			add_action( 'shutdown', [ __CLASS__, 'fetch_template_json' ] );
			add_action( 'admin_init', array( __CLASS__, 'index_templates' ) );
		}

		public static function apply_scroll_fix_css() {
			global $current_screen;
			if ( $current_screen instanceof WP_Screen && strpos( $current_screen->base, 'woofunnels' ) !== false ) {
				/** Allow for woofunnels pages */
			} elseif ( false === apply_filters( 'bwf_scroll_fix_allow', false ) ) {
				return;
			}
			ob_start();
			?>
			<style>
                #adminmenuwrap {
                    position: relative !important;
                    top: 0 !important;
                }

                body, html {
                    height: auto;
                    position: relative
                }
			</style>
			<?php
			echo ob_get_clean(); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}


		public static function index_templates() {
			$get_templates = filter_input( INPUT_GET, 'wffn_parse_template', FILTER_DEFAULT ); // phpcs:ignore WordPressVIPMinimum.Security.PHPFilterFunctions.RestrictedFilter

			if ( 'yes' === $get_templates && current_user_can( 'manage_options' ) ) {
				self::get_all_templates( true );
				wp_safe_redirect( admin_url( 'admin.php?page=woofunnels&tab=tools' ) );
				exit;
			}
		}

		public static function get_all_templates( $force = false ) {
			return WooFunnels_Dashboard_UI::get_all_templates( $force );
		}

		public static function get_remote_templates() {
			return WooFunnels_Dashboard_UI::get_remote_templates();
		}

		public static function get_template_api_url() {
			return WooFunnels_Dashboard_UI::get_template_api_url();
		}

		public static function fetch_template_json() {
			if ( 'yes' === filter_input( INPUT_GET, 'activated', FILTER_DEFAULT ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPressVIPMinimum.Security.PHPFilterFunctions.RestrictedFilter
				flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
				self::get_all_templates();
			}
		}


	}
}
spl_autoload_register( array( 'WooFunnels_dashboard', 'autoloader' ) );
WooFunnels_dashboard::load_core_classes();

//Initialize the instance so that some necessary hooks can run on each load
add_action( 'admin_init', array( 'WooFunnels_dashboard', 'init' ), 11 );
