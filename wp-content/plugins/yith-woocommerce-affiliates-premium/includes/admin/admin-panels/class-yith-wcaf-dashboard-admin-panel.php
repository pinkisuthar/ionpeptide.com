<?php
/**
 * Dashboard tab handling
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Dashboard_Admin_Panel' ) ) {
	/**
	 * Affiliates admin panel handling
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Dashboard_Admin_Panel extends YITH_WCAF_Abstract_Admin_Panel {

		/**
		 * Current tab name
		 *
		 * @var string
		 */
		protected $tab = 'dashboard';

		/**
		 * Init Affiliates admin panel
		 */
		public function __construct() {
			// print premium tab.
			add_action( 'yith_wcaf_dashboard', array( $this, 'print_dashboard' ) );

			// add required body classes.
			add_filter( 'admin_body_class', array( $this, 'add_body_classes' ) );

			// enqueue tab assets.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			// call parent constructor.
			parent::__construct();
		}

		/**
		 * Adds required body classes for this section
		 *
		 * @param string $classes List of default body classes.
		 * @return string Filtered body classes.
		 */
		public function add_body_classes( $classes ) {
			$classes .= ' woocommerce-page';

			return $classes;
		}

		/**
		 * Enqueue required assets for current panel.
		 *
		 * @return void.
		 */
		public function enqueue_assets() {
			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style( 'wc-components' );

			YITH_WCAF_Scripts::enqueue( 'yith-wcaf-admin-' . $this->tab, 'admin', array( 'wp-mediaelement' ), true );

			$this->localize_scripts();
		}

		/**
		 * Returns variable to localize for current panel
		 *
		 * @return array Array of variables to localize.
		 */
		public function get_localize() {
			$localize = parent::get_localize();
			$code     = get_woocommerce_currency();

			return array_merge(
				$localize,
				array(
					'currency' => array(
						'code'              => $code,
						'precision'         => wc_get_price_decimals(),
						'symbol'            => html_entity_decode( get_woocommerce_currency_symbol( $code ) ),
						'symbolPosition'    => get_option( 'woocommerce_currency_pos' ),
						'decimalSeparator'  => wc_get_price_decimal_separator(),
						'thousandSeparator' => wc_get_price_thousand_separator(),
						'priceFormat'       => html_entity_decode( get_woocommerce_price_format() ),
					),
					'base_url' => YITH_WCAF_Admin()->get_panel_url(),
				)
			);
		}


		/**
		 * Prints premium tab
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function print_dashboard() {
			include YITH_WCAF_DIR . 'views/dashboard/tab-content.php';
		}
	}
}
