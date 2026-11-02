<?php

/**
 * API handler for woofunnels
 * @package WooFunnels
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'WooFunnels_API' ) ) :

	/**
	 * WooFunnels_License Class
	 */
	#[AllowDynamicProperties]
	class WooFunnels_API {

		public static $woofunnels_api_url = 'https://track.funnelkit.com';
		public static $is_ssl = true;

		/**
		 * Get all the plugins that can be pushed from the API
		 * @return Mixed False on failure and array on success
		 */
		public static function get_woofunnels_list() {
			$woofunnels_modules = get_transient( 'woofunnels_get_modules' );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
				$woofunnels_modules = '';
			}
			if ( ! empty( $woofunnels_modules ) ) {
				return $woofunnels_modules;
			}

			$api_params = self::get_api_args( array(
				'action' => 'get_woofunnels_plugins',
				'attrs'  => array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- argument sent to the remote FunnelKit API, not a local query
						array(
							'key'     => 'is_visible_in_dashboard',
							'value'   => 'yes',
							'compare' => '=',
						),
					),
				),
			) );

			$request_args = self::get_request_args( array(
				'timeout'   => 30,
				'sslverify' => self::$is_ssl,
				'body'      => urlencode_deep( $api_params ),
			) );

			$request = wp_remote_post( self::get_api_url( self::$woofunnels_api_url ), $request_args );

			if ( is_wp_error( $request ) ) {
				return false;
			}

			$request = json_decode( wp_remote_retrieve_body( $request ) );

			if ( ! $request ) {
				return false;
			}

			$woofunnels_modules = $request;

			set_transient( 'woofunnels_get_modules', $request, 60 * 60 * 12 );

			return ! empty( $woofunnels_modules ) ? $woofunnels_modules : false;
		}

		/**
		 * Post tracking data to the Server
		 *
		 * @param $data
		 *
		 * @return array|void|WP_Error
		 */
		public static function post_tracking_data( $data ) {

			if ( empty( $data ) ) {
				return;
			}

			$api_params = self::get_api_args( array(
				'action' => 'get_tracking_data',
				'data'   => $data,
			) );

			$request_args = self::get_request_args( array(
				'timeout'   => 30,
				'sslverify' => self::$is_ssl,
				'body'      => urlencode_deep( $api_params ),
			) );

			$api_url = self::get_api_url( self::$woofunnels_api_url );

			// Log tracking data being sent to API
			self::log_tracking_data( $data, $api_url, $api_params );

			$request = wp_remote_post( $api_url, $request_args );

			return $request;
		}

		/**
		 * Log tracking data being sent to API
		 *
		 * @param array  $data Collected tracking data
		 * @param string $api_url API endpoint URL
		 * @param array  $api_params API parameters
		 */
		private static function log_tracking_data( $data, $api_url, $api_params ) {
			// Use WFFN logger if available
			if ( class_exists( 'WFFN_Core' ) ) {
				$logger = WFFN_Core()->logger;
				if ( $logger && method_exists( $logger, 'log' ) ) {
					// Log full data structure
					$log_data = array(
						'timestamp'  => current_time( 'mysql' ),
						'api_url'    => $api_url,
						'data_size'  => strlen( wp_json_encode( $data ) ) . ' bytes',
						'data_keys'  => array_keys( $data ),
						'api_action' => isset( $api_params['action'] ) ? $api_params['action'] : 'unknown',
						'full_data'  => $data, // Include complete data
					);
					$logger->log( '[Tracking API] Sending tracking data: ' . wp_json_encode( $log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'wffn-tracking', true );
				}
			}
		}

		/**
		 * @param $data
		 *
		 * @return array|bool|mixed|object|void|WP_Error|null
		 */
		public static function post_support_request( $data ) {
			if ( empty( $data ) ) {
				return;
			}

			$api_params = self::get_api_args( array(
				'action' => 'submit_support_request',
				'data'   => $data,
			) );

			$request_args = self::get_request_args( array(
				'timeout'   => 30,
				'sslverify' => self::$is_ssl,
				'body'      => urlencode_deep( $api_params ),
			) );

			$request = wp_remote_post( self::get_api_url( self::$woofunnels_api_url ), $request_args );

			if ( ! is_wp_error( $request ) ) {
				$request = json_decode( wp_remote_retrieve_body( $request ) );

				return $request;
			}

			return false;
		}

		/**
		 * Filter function to modify args
		 *
		 * @param $args
		 *
		 * @return mixed|void
		 */
		public static function get_api_args( $args ) {
			return apply_filters( 'woofunnels_api_call_args', $args );
		}

		/**
		 * Filter function for request args
		 *
		 * @param $args
		 *
		 * @return mixed|void
		 */
		public static function get_request_args( $args ) {
			return apply_filters( 'woofunnels_api_call_request_args', $args );
		}

		/**
		 * All the data about the deactivation popups
		 *
		 * @param $deactivations
		 * @param $licenses
		 *
		 * @return array|WP_Error
		 */
		public static function post_deactivation_data( $deactivations ) {
			$get_deactivation_data = array(
				'site'          => home_url(),
				'deactivations' => $deactivations,
				'logged_errors' => apply_filters( 'send_filter_deactivations_data', [] )
			);

			$api_params = self::get_api_args( array(
				'action'   => 'get_deactivation_data',
				'data'     => $get_deactivation_data,
				'licenses' => [],
			) );

			$request_args = self::get_request_args( array(
				'sslverify' => self::$is_ssl,
				'body'      => urlencode_deep( $api_params ),
			) );
			$request      = wp_remote_post( self::get_api_url( self::$woofunnels_api_url ), $request_args );

			return $request;
		}

		/**
		 * Get for API url
		 *
		 * @param string $link
		 *
		 * @return string
		 */
		public static function get_api_url( $link ) {
			return $link;
		}

		public static function get_woofunnels_status() {
			//do a woofunnels_status_check
			return true;
		}

		/**
		 * @param $data
		 *
		 * @return array|void|WP_Error
		 */
		public static function post_optin_data( $data ) {
			if ( empty( $data ) ) {
				return;
			}

			$api_params   = self::get_api_args( array(
				'action' => 'woofunnelsapi_optin',
				'data'   => $data,
			) );
			$request_args = self::get_request_args( array(
				'timeout'   => 30,
				'sslverify' => self::$is_ssl,
				'body'      => urlencode_deep( $api_params ),
			) );
			$request      = wp_remote_post( self::get_api_url( self::$woofunnels_api_url ), $request_args );

			return $request;
		}

	}

endif; // end class_exists check
