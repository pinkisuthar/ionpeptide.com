<?php
/**
 * REST API bootstrap.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\API
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_REST_Install' ) ) {
	/**
	 * Init class.
	 */
	class YITH_WCAF_REST_Install {

		use YITH_WCAF_Trait_Singleton;

		/**
		 * Boostrap REST API.
		 */
		public function __construct() {
			// REST API extensions init.
			add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		}

		/**
		 * Init REST API.
		 */
		public function rest_api_init() {
			$controllers = array(
				'YITH_WCAF_REST_Affiliates_Controller',
				'YITH_WCAF_REST_Clicks_Controller',
				'YITH_WCAF_REST_Commissions_Controller',
				'YITH_WCAF_REST_Payments_Controller',
			);

			/**
			 * APPLY_FILTERS: yith_wcaf_enable_report
			 *
			 * Filters whether to enable the reports.
			 *
			 * @param bool $enable_reports Whether to enable the reports or not.
			 */
			if ( apply_filters( 'yith_wcaf_enable_report', true ) ) {
				$analytics_controllers = array(
					'YITH_WCAF_REST_Reports_Controller',
					'YITH_WCAF_REST_Reports_Affiliates_Controller',
					'YITH_WCAF_REST_Reports_Products_Controller',
					'YITH_WCAF_REST_Reports_Affiliates_Stats_Controller',
				);

				$controllers = array_merge( $controllers, $analytics_controllers );
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_admin_rest_controllers
			 *
			 * Filters the list of report controllers.
			 *
			 * @param array $controllers List of report controllers.
			 */
			$controllers = apply_filters( 'yith_wcaf_admin_rest_controllers', $controllers );

			foreach ( $controllers as $controller ) {
				if ( ! class_exists( $controller ) ) {
					continue;
				}

				$controller_obj = new $controller();
				$controller_obj->register_routes();
			}
		}
	}
}
