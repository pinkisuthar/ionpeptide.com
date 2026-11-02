<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWF_JSON_Cache' ) ) {
	#[\AllowDynamicProperties]
	class BWF_JSON_Cache {

		public static function run_json_endpoints_cache_handling() {
			/** Litespeed cache */
			self::check_litespeed();

			/** WordPress REST API Authentication */
			self::check_wp_rest_api_authentication();

			/** WP rocket cache */
			self::check_wp_rocket();

			/** WP fastest cache */
			self::check_wp_fastest_cache();
		}

		/**
		 * For litespeed cache
		 * https://wordpress.org/plugins/litespeed-cache/
		 *
		 * @return void
		 */
		public static function check_litespeed() {
			if ( ! defined( 'LSCWP_V' ) ) {
				return;
			}

			/** Exclude json endpoints */
			$exc_endpoints = get_option( 'litespeed.conf.cache-exc', '' );
			$exc_endpoints = self::make_array( $exc_endpoints );

			if ( defined( 'BWFAN_API_NAMESPACE' ) ) {
				$exc_endpoints[] = "^/wp-json/" . BWFAN_API_NAMESPACE . "/";
			}

			$exc_endpoints[] = "^/wp-json/woofunnels/";
			$exc_endpoints[] = "^/wp-json/funnelkit-automations/";
			$exc_endpoints   = self::maybe_clear_fb_endpoints( $exc_endpoints );

			$exc_endpoints = self::unique( $exc_endpoints );
			update_option( 'litespeed.conf.cache-exc', wp_json_encode( $exc_endpoints ) );

			/** Exclude role */
			$exc_role = get_option( 'litespeed.conf.cache-exc_roles', '' );
			$exc_role = self::make_array( $exc_role );

			$exc_role[] = "administrator";

			$exc_role = self::unique( $exc_role );
			update_option( 'litespeed.conf.cache-exc_roles', wp_json_encode( $exc_role ) );

			/** Exclude query string */
			$exc_qs = get_option( 'litespeed.conf.cache-exc_qs', '' );
			$exc_qs = self::make_array( $exc_qs );

			$exc_qs[] = "bwfan-track-id";
			$exc_qs[] = "bwfan-track-action";
			$exc_qs[] = "bwfan-link-trigger";

			$exc_qs = self::unique( $exc_qs );
			update_option( 'litespeed.conf.cache-exc_qs', wp_json_encode( $exc_qs ) );
		}

		/**
		 * For WordPress rest api authentication
		 * https://wordpress.org/plugins/wp-rest-api-authentication/
		 *
		 * @return void
		 */
		public static function check_wp_rest_api_authentication() {
			if ( ! defined( 'MINIORANGE_API_AUTHENTICATION_VERSION' ) ) {
				return;
			}

			/** json endpoints */
			$endpoints = get_option( 'mo_api_authentication_protectedrestapi_route_whitelist', '' );
			if ( empty( $endpoints ) ) {
				return;
			}

			$endpoints = maybe_unserialize( $endpoints );

			$exc_endpoints = [
				"/woofunnels/v1",
				"/autonami/v1",
				"/autonami/v2",
				"/funnelkit-automations",
			];
			$exc_endpoints = self::maybe_clear_fb_endpoints( $exc_endpoints, 'api-auth' );

			$endpoints = array_filter( $endpoints, function ( $endpoint ) use ( $exc_endpoints ) {
				foreach ( $exc_endpoints as $url ) {
					if ( false !== strpos( $endpoint, $url ) ) {
						return false;
					}
				}

				return true;
			} );
			$endpoints = self::unique( $endpoints );
			update_option( 'mo_api_authentication_protectedrestapi_route_whitelist', $endpoints );
		}


		/**
		 * For WP rocket cache
		 * https://wp-rocket.me/
		 *
		 * @return void
		 */
		public static function check_wp_rocket() {
			if ( ! defined( 'WP_ROCKET_VERSION' ) || ! function_exists( 'get_rocket_option' ) || ! function_exists( 'update_rocket_option' ) || ! function_exists( 'rocket_generate_config_file' ) ) {
				return;
			}

			$exc_endpoints = (array) get_rocket_option( 'cache_reject_uri', [] );
			$exc_endpoints = self::make_array( $exc_endpoints );

			if ( defined( 'BWFAN_API_NAMESPACE' ) ) {
				$exc_endpoints[] = "^/wp-json/" . BWFAN_API_NAMESPACE . "/";
			}

			$exc_endpoints[] = "/wp-json/woofunnels/*";
			$exc_endpoints[] = "/wp-json/funnelkit-automations/*";
			$exc_endpoints   = self::maybe_clear_fb_endpoints( $exc_endpoints, 'wp_rocket' );
			$exc_endpoints   = self::unique( $exc_endpoints );

			/** Update the "Never cache the following pages" option */
			update_rocket_option( 'cache_reject_uri', $exc_endpoints );

			/** Update config file */
			rocket_generate_config_file();
		}

		/**
		 * For WP fastest cache
		 * https://wordpress.org/plugins/wp-fastest-cache/
		 *
		 * @return void
		 */
		public static function check_wp_fastest_cache() {
			if ( ! class_exists( 'WpFastestCache' ) ) {
				return;
			}
			$existing_data  = get_option( 'WpFastestCacheExclude' );
			$existing_rules = self::make_array( $existing_data );

			$new_rules_array = [
				[
					"prefix"  => "contain",
					"content" => "bwfan-action",
					"type"    => "page"
				],
				[
					"prefix"  => "contain",
					"content" => "bwfan-track-id",
					"type"    => "page"
				],
				[
					"prefix"  => "contain",
					"content" => "bwfan-link-trigger",
					"type"    => "page"
				],
				[
					"prefix"  => "contain",
					"content" => "bwfan-track-action",
					"type"    => "page"
				],
				[
					"prefix"  => "contain",
					"content" => "wp-json/woofunnels/",
					"type"    => "page"
				],
				[
					"prefix"  => "contain",
					"content" => "wp-json/autonami/",
					"type"    => "page"
				],
				[
					"prefix"  => "contain",
					"content" => "wp-json/funnelkit-automations",
					"type"    => "page"
				],
				[
					"prefix"  => "contain",
					"content" => "wp-json/funnelkit-app",
					"type"    => "page"
				],
				[
					"prefix"  => "contain",
					"content" => "wp-json/woofunnel_customer/",
					"type"    => "page"
				],
				[
					"prefix"  => "contain",
					"content" => "wp-json/autonami-app",
					"type"    => "page"
				],
				[
					"prefix"  => "contain",
					"content" => "wp-json/autonami-webhook",
					"type"    => "page"
				]
			];

			$new_rules = array_filter( $new_rules_array, function ( $new_rule ) use ( $existing_rules ) {
				return ! in_array( $new_rule, $existing_rules, true );
			} );

			if ( empty( $new_rules ) ) {
				return;
			}

			$updated_array = array_merge( $existing_rules, $new_rules );
			$updated_json  = wp_json_encode( $updated_array );

			update_option( 'WpFastestCacheExclude', $updated_json );
		}

		/**
		 * Convert string/ json to array
		 *
		 * @param $value
		 *
		 * @return array
		 */
		public static function make_array( $value ) {
			$value = self::is_json( $value ) ? json_decode( $value, true ) : $value;

			return empty( $value ) || ! is_array( $value ) ? [] : $value;
		}

		/**
		 * Check if string is a json
		 *
		 * @param $string
		 *
		 * @return bool
		 */
		public static function is_json( $string ) {
			if ( ! is_string( $string ) ) {
				return false;
			}
			json_decode( $string );

			return ( json_last_error() === JSON_ERROR_NONE );
		}

		/**
		 * Array unique and sort
		 *
		 * @param $value
		 *
		 * @return array|mixed
		 */
		public static function unique( $value ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			$value = array_unique( $value );
			sort( $value );

			return $value;
		}

		/**
		 * @param $endpoints
		 * @param $plugin_slug
		 *
		 * @return array|mixed
		 */
		public static function maybe_clear_fb_endpoints( $endpoints, $plugin_slug = 'litespeed' ) {
			if ( ! class_exists( 'WFFN_Core' ) ) {
				return $endpoints;
			}

			$endpoints = ( ! is_array( $endpoints ) ) ? [] : $endpoints;

			$db_options = get_option( 'bwf_gen_config', [] );
			$cl_slug    = is_array( $db_options ) && isset( $db_options['checkout_page_base'] ) ? $db_options['checkout_page_base'] : '';
			$of_slug    = is_array( $db_options ) && isset( $db_options['wfocu_page_base'] ) ? $db_options['wfocu_page_base'] : '';

			if ( 'api-auth' === $plugin_slug ) {
				$endpoints[] = "/funnelkit-app/";

				if ( ! empty( $cl_slug ) ) {
					$endpoints[] = '/' . $cl_slug . '/';
				}
				if ( ! empty( $of_slug ) ) {
					$endpoints[] = '/' . $of_slug . '/';
				}

				return $endpoints;
			}

			if ( 'litespeed' === $plugin_slug ) {
				$endpoints[] = "^/wp-json/funnelkit-app/";
				if ( ! empty( $cl_slug ) ) {
					$endpoints[] = '^/' . $cl_slug . '/';
				}
				if ( ! empty( $of_slug ) ) {
					$endpoints[] = '^/' . $of_slug . '/';
				}

				return $endpoints;
			}

			if ( 'wp_rocket' === $plugin_slug ) {
				$endpoints[] = "^/wp-json/funnelkit-app/(.*)";
				if ( ! empty( $cl_slug ) ) {
					$endpoints[] = '^/' . $cl_slug . '/(.*)';
				}
				if ( ! empty( $of_slug ) ) {
					$endpoints[] = '^/' . $of_slug . '/(.*)';
				}

				return $endpoints;
			}

			return $endpoints;
		}
	}
}
