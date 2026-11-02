<?php
/**
 * Handle YITH WooCommerce PDF Invoice Modules
 *
 * @class   YITH_YWPI_Modules
 * @since   5.0.0
 * @author YITH
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'YITH_YWPI_Modules' ) ) {
	/**
	 * Class YITH_YWPI_Modules
	 *
	 * @since  5.0.0
	 */
	class YITH_YWPI_Modules {

		/**
		 * Active modules option
		 *
		 * @const string
		 */
		const ACTIVE_MODULES = 'ywpi_modules_active';

		/**
		 * Modules base path.
		 *
		 * @const string
		 */
		const BASE_PATH = YITH_YWPI_INC_DIR . 'modules/';


		/**
		 * Constructor
		 * Initialize plugin and registers actions and filters to be used.
		 *
		 * @since  5.0.0
		 */
		public static function init() {


			add_action( 'ywpi_modules_tab', array( __CLASS__, 'output_modules_tab' ) );

			add_action( 'init', array( __CLASS__, 'load_modules' ), 0 );
		}

		/**
		 * Get loaded modules list.
		 *
		 * @since  3.0.0
		 * @return array
		 */
		public static function get_modules() {
			static $modules;
			if ( is_null( $modules ) && file_exists( self::BASE_PATH . 'modules.php' ) ) {
				$modules = include self::BASE_PATH . 'modules.php';
			}

			return apply_filters( 'ywsbs_modules_list', (array) $modules );
		}

		/**
		 * Load available modules.
		 *
		 * @since  3.0.0
		 */
		public static function load_modules() {
			foreach ( self::get_modules() as $module_id => $module ) {
				if ( ! self::is_module_active( $module_id ) ) { // do not go further with init.
					continue;
				}

				// Load init class.
				$init = self::get_module_init( $module_id );
				if ( $init ) {
					include_once $init;

					do_action( "ywsbs_module_{$module_id}_loaded" );
				}
			}
		}

		/**
		 * Get module init file
		 *
		 * @since  3.0.0
		 * @param string $module_id The module id.
		 * @return string
		 */
		protected static function get_module_init( $module_id ) {
			// Load init class.
			$init = self::BASE_PATH . $module_id . DIRECTORY_SEPARATOR . 'init.php';

			return file_exists( $init ) ? $init : '';
		}

		/**
		 * Check if given module is active.
		 *
		 * @since  3.0.0
		 * @param string $module_id The module ID to check.
		 * @return boolean
		 */
		public static function is_module_active( $module_id ) {
			static $active_modules;
			if ( is_null( $active_modules ) ) {
				$active_modules = get_option( self::ACTIVE_MODULES, array() );
			}

			return in_array( $module_id, $active_modules, true );
		}

		/**
		 * Check if given module is available in modules list.
		 *
		 * @since  3.0.0
		 * @param string $module_id The module ID to check.
		 * @return boolean
		 */
		public static function is_module_available( $module_id ) {
			return array_key_exists( $module_id, self::get_modules() );
		}

		/**
		 * Activate given module ID.
		 *
		 * @since  3.0.0
		 * @param string $module_id The module ID to activate.
		 * @return boolean
		 * @throws Exception Error on module switch activation.
		 */
		public static function activate( $module_id ) {
			if ( ! self::is_module_available( $module_id ) ) {
				// translators: %s stand for the module ID.
				throw new Exception( sprintf( _x( 'Module #%s is not a valid module ID.', 'Module activation switch error', 'yith-woocommerce-subscription' ), $module_id ) ); // phpcs:ignore
			}

			$active_modules   = get_option( self::ACTIVE_MODULES, array() );
			$active_modules[] = $module_id;

			if ( update_option( self::ACTIVE_MODULES, array_unique( $active_modules ) ) ) {

				// Make sure to load module init.
				$init = self::get_module_init( $module_id );
				if ( empty( $init ) ) {
					// translators: %s is the module id.
					throw new Exception( sprintf( _x( 'No valid init file found for module %s.', 'Module init file not found', 'yith-woocommerce-subscription' ), $module_id ) ); // phpcs:ignore
				}

				include_once $init;

				do_action( "ywsbs_module_{$module_id}_activated" );
				return true;
			}

			return false;
		}

		/**
		 * Deactivate given module ID.
		 *
		 * @since  3.0.0
		 * @param string $module_id The module ID to activate.
		 * @return boolean
		 * @throws Exception Error on module switch activation.
		 */
		public static function deactivate( $module_id ) {
			// It's not needed to check if key is valid.
			$active_modules = get_option( self::ACTIVE_MODULES, array() );
			$index          = array_search( $module_id, $active_modules, true );
			if ( false !== $index ) {
				unset( $active_modules[ $index ] );

				if ( update_option( self::ACTIVE_MODULES, $active_modules ) ) {
					// In this case we do not need to load init since the module is already loaded.
					do_action( "ywsbs_module_{$module_id}_deactivated" );
					return true;
				}
			}

			return false;
		}

		/**
		 * Switch module activation
		 *
		 * @since  3.0.0
		 * @param string $module_id The module ID to activate.
		 * @return boolean
		 * @throws Exception Error on module switch activation.
		 */
		public static function switch_activation( $module_id ) {
			if ( ! self::is_module_available( $module_id ) ) {
				// translators: %s stand for the module ID.
				throw new Exception( sprintf( _x( 'Module #%s is not a valid module ID.', 'Module activation switch error', 'yith-woocommerce-subscription' ), $module_id ) ); //phpcs:ignore
			}

			$method = self::is_module_active( $module_id ) ? 'deactivate' : 'activate';
			return self::$method( $module_id );
		}


		/**
		 * Gets the module ID from the init file.
		 * This method extracts the ID of a module from its filename.
		 *
		 * @since 3.0.0
		 *
		 * @param string $file The filename of module.
		 * @return string The module key.
		 */
		protected static function module_basename( $file ) {
			$file       = wp_normalize_path( $file );
			$plugin_dir = wp_normalize_path( self::BASE_PATH );

			// Get relative path from plugins directory.
			$file = str_replace( array( $plugin_dir, 'init.php' ), '', $file );
			$file = trim( $file, '/' );
			return $file;
		}

		/**
		 * Register a module activation hook
		 *
		 * @since  3.0.0
		 * @param string   $file     The filename init of module.
		 * @param callable $callback The function hooked to the 'ywsbs_module_{$module_id}_activated' action.
		 * @return void
		 */
		public static function register_module_activation_hook( $file, $callback ) {
			$module_id = self::module_basename( $file );
			add_action( "ywsbs_module_{$module_id}_activated", $callback );
		}

		/**
		 * Register a module deactivation hook
		 *
		 * @since  3.0.0
		 * @param string   $file     The filename init of module.
		 * @param callable $callback The function hooked to the 'ywsbs_module_{$module_id}_deactivated' action.
		 * @return void
		 */
		public static function register_module_deactivation_hook( $file, $callback ) {
			$module_id = self::module_basename( $file );
			add_action( "ywsbs_module_{$module_id}_deactivated", $callback );
		}


		/**
		 * Modules list tab
		 *
		 * @since  3.0.0
		 * @return void
		 */
		public static function output_modules_tab() {
			$modules_tab = YITH_YWPI_VIEWS_PATH . '/panel/modules.php';

			if ( file_exists( $modules_tab ) ) {
				$modules = self::get_modules();
				include_once $modules_tab;
			}
		}
	}
}
