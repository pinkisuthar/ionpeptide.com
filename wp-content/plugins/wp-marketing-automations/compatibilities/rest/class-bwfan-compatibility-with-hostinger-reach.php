<?php
defined( 'ABSPATH' ) || exit;

/**
 * Hostinger Reach
 * https://wordpress.org/plugins/hostinger-reach/
 */
if ( ! class_exists( 'BWFAN_Compatibility_With_Hostinger_Reach' ) ) {
	/**
	 * Bwfan Compatibility With Hostinger Reach
	 *
	 * @since 1.0.0
	 */
	class BWFAN_Compatibility_With_Hostinger_Reach {

		/**
		 *   Construct
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			add_action( 'action_scheduler_init', [ $this, 'remove_hostinger_reach_hooks' ], 9 );
		}

		/**
		 * Remove Hostinger Reach plugin hooks
		 *
		 * @return void
		 */
		public function remove_hostinger_reach_hooks() {
			$rest_route = filter_input( INPUT_GET, 'rest_route' );
			if ( empty( $rest_route ) ) {
				$rest_route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			}
			if ( empty( $rest_route ) ) {
				return;
			}
			$rest_route = bwf_clean( $rest_route );

			if ( str_contains( $rest_route, '/woofunnels/v1/worker' ) || str_contains( $rest_route, '/autonami/v2/worker' ) || str_contains( $rest_route, '/autonami/v1/worker' ) ) {
				$this->remove_hostinger_job_closures();
			}
		}

		/**
		 * Remove Hostinger Reach job initialization closures from init hook
		 *
		 * @return void
		 */
		private function remove_hostinger_job_closures() {
			global $wp_filter;

			if ( ! isset( $wp_filter['init'] ) || ! ( $wp_filter['init'] instanceof WP_Hook ) ) {
				return;
			}

			$hooks = $wp_filter['init']->callbacks;

			foreach ( $hooks as $priority => $reference ) {
				if ( ! is_array( $reference ) || empty( $reference ) ) {
					continue;
				}

				foreach ( $reference as $index => $calls ) {
					if ( ! isset( $calls['function'] ) || ! is_object( $calls['function'] ) || ! ( $calls['function'] instanceof Closure ) ) {
						continue;
					}

				try {
					$reflection = new ReflectionFunction( $calls['function'] );
					$file       = $reflection->getFileName();

					/** Validate file path before string operations */
					if ( false === $file || ! is_string( $file ) ) {
						continue;
					}

					if ( str_contains( $file, 'hostinger-reach' ) && str_contains( $file, 'JobsProvider' ) ) {
						unset( $wp_filter['init']->callbacks[ $priority ][ $index ] );
					}
				} catch ( ReflectionException $e ) {
					continue;
				}
				}
			}
		}

	}

	new BWFAN_Compatibility_With_Hostinger_Reach();
}
