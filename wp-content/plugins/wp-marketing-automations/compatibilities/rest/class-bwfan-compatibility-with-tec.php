<?php
defined( 'ABSPATH' ) || exit;

/**
 * The Events Calendar
 * https://wordpress.org/plugins/the-events-calendar/
 */
if ( ! class_exists( 'BWFAN_Compatibility_With_TEC' ) ) {
	/**
	 * Bwfan Compatibility With Tec
	 *
	 * @since 1.0.0
	 */
	class BWFAN_Compatibility_With_TEC {

		/**
		 *   Construct
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			add_action( 'action_scheduler_init', [ $this, 'remove_tec_hook' ], 9 );
		}

		/**
		 * Remove events calendar plugin hook
		 *
		 * @return void
		 */
		public function remove_tec_hook() {
			$rest_route = filter_input( INPUT_GET, 'rest_route' );
			if ( empty( $rest_route ) ) {
				$rest_route = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			}
			if ( empty( $rest_route ) ) {
				return;
			}
			$rest_route = bwf_clean( $rest_route );

			if ( false !== strpos( $rest_route, '/woofunnels/v1/worker' ) || false !== strpos( $rest_route, '/autonami/v2/worker' ) || false !== strpos( $rest_route, '/autonami/v1/worker' ) ) {
				BWFAN_Common::remove_actions( 'init', 'TEC\Common\StellarWP\Shepherd\Regulator', 'schedule_cleanup_task' );
			}
		}
	}

	new BWFAN_Compatibility_With_TEC();
}
