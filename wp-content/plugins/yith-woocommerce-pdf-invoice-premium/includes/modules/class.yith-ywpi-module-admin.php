<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * YITH_YWPI_Module_Admin Class.
 * Collection of common hooks and methods for the admin section of a module
 *
 * @class   YITH_YWPI_Module_Admin
 * @since   5.0.0
 * @author  YITH
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

if ( ! class_exists( 'YITH_YWPI_Module_Admin' ) ) {
	/**
	 * Class YITH_YWPI_Module_Admin
	 */
	abstract class YITH_YWPI_Module_Admin {

		/**
		 * The module ID
		 *
		 * @var string
		 */
		protected $module_id = '';

		/**
		 * Constructor
		 *
		 * @since  5.0.0
		 */
		public function __construct() {
			$this->init();
		}

		/**
		 * Init
		 *
		 * @since  5.0.0
		 */
		protected function init() {
			if ( empty( $this->module_id ) ) {
				return;
			}

			add_filter( 'yith_plugin_panel_item_options_path', array( $this, 'module_options_path' ), 10, 4 );
			add_filter( 'yith_ywpi_admin_tabs', array( $this, 'add_tabs' ), 10, 1 );

		}


		/**
		 * Register the module options path
		 *
		 * @since  5.0.0
		 * @param string                       $path         Absolute options path.
		 * @param string                       $options_path Relative options path.
		 * @param string                       $item         Current options item.
		 * @param YIT_Plugin_Panel_WooCommerce $panel        Current panel.
		 * @return string
		 */
		public function module_options_path( $path, $options_path, $item, $panel ) {

			if ( isset( $_GET['page'] ) && 'yith_woocommerce_pdf_invoice_panel' === $_GET['page'] && array_key_exists( $item, $this->get_tabs() ) ) {
				$path = $this->get_module_path() . "options/{$item}-options.php";
			}

			return $path;
		}

		/**
		 * Add module tabs to the plugin panel
		 *
		 * @since  5.0.0
		 * @param array $tabs The panel tabs array.
		 * @return array
		 */
		public function add_tabs( $tabs ) {
			$module_tabs = $this->get_tabs();
			if ( ! empty( $module_tabs ) ) {
				$ref_pos = array_search( 'modules', array_keys( $tabs ), true );
				$tabs    = array_slice( $tabs, 0, $ref_pos + 1, true ) + $module_tabs + array_slice( $tabs, $ref_pos + 1, count( $tabs ) - 1, true );
			}

			return $tabs;
		}


		/**
		 * Get the module path
		 *
		 * @since  5.0.0
		 * @return string
		 */
		protected function get_module_path() {
			$key      = strtoupper( str_replace( '-', '_', $this->module_id ) );
			$constant = "YITH_YWPI_{$key}_MODULE_PATH";
			return defined( $constant ) ? constant( $constant ) : YITH_YWPI_INC_DIR . "modules/{$this->module_id}/";
		}

		/**
		 * Get module tabs
		 *
		 * @since  3.0.0
		 * @return array
		 */
		protected function get_tabs() {
			return array();
		}
	}
}
