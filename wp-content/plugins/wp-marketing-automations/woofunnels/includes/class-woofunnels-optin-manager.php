<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OptIn manager class is to handle all scenarios occurs for opting the user
 * @author: WooFunnels
 * @since 0.0.1
 * @package WooFunnels
 */
if ( ! class_exists( 'WooFunnels_OptIn_Manager' ) ) {
	#[AllowDynamicProperties]
	class WooFunnels_OptIn_Manager {

		public static $should_show_optin = true;

		/**
		 * Initialization to execute several hooks
		 */
		public static function init() {
			//push notification for optin

			add_action( 'admin_init', array( __CLASS__, 'maybe_push_optin_notice' ), 15 );
			add_action( 'admin_init', array( __CLASS__, 'maybe_clear_optin' ), 15 );

			// Async tracking: Schedule individual tracker collections at midnight
			// This replaces the old synchronous maybe_track_usage() for scheduled tracking
			add_action( 'fk_fb_every_day', array( __CLASS__, 'schedule_tracker_collections' ), 5 );

			// JIT (just-in-time) tracking callback - for immediate tracking when user opts in
			// This is separate from scheduled tracking and uses synchronous collection
			add_action( 'bwf_track_usage_scheduled_single', array( __CLASS__, 'maybe_track_usage' ), 10, 2 );

			// Async tracking: Handle individual tracker collection
			add_action( 'fk_tracker_collect', array( __CLASS__, 'handle_tracker_collection' ), 10, 1 );

			// Async tracking: Final collection and send at 1:00 AM
			add_action( 'fk_send_tracking_data', array( __CLASS__, 'collect_and_send_tracking_data' ), 10 );

			//initializing schedules
			add_action( 'admin_footer', array( __CLASS__, 'initiate_schedules' ) );

			// For testing license notices, uncomment this line to force checks on every page load

			/** optin ajax call */
			add_action( 'wp_ajax_woofunnelso_optin_call', array( __CLASS__, 'woofunnelso_optin_call' ) );

			// optin yes track callback
			add_action( 'woofunnels_optin_success_track_scheduled', array( __CLASS__, 'optin_track_usage' ), 10 );

			add_filter( 'cron_schedules', array( __CLASS__, 'register_weekly_schedule' ), 10 );
		}

		/**
		 * Set function to block
		 */
		public static function block_optin() {
			update_option( 'bwf_is_opted', 'no', true );
		}

		public static function maybe_clear_optin() {
			if ( wp_verify_nonce( filter_input( INPUT_GET, '_nonce', FILTER_UNSAFE_RAW ), 'bwf_tools_action' ) && isset( $_GET['woofunnels_tracking'] ) && ( 'reset' === sanitize_text_field( wp_unslash( $_GET['woofunnels_tracking'] ) ) ) ) {
				self::reset_optin();
				wp_safe_redirect( admin_url( 'admin.php?page=woofunnels&tab=tools' ) );
				exit;
			}
		}

		/**
		 * Reset optin
		 */
		public static function reset_optin() {
			$get_action = filter_input( INPUT_GET, 'action', FILTER_UNSAFE_RAW );
			if ( 'yes' === $get_action ) {
				self::Allow_optin();
			} else {
				delete_option( 'bwf_is_opted' );
			}

		}

		/**
		 * Set function to allow
		 */
		public static function Allow_optin( $pass_jit = false, $product = 'FB' ) {
			if ( 'yes' === self::get_optIn_state() ) {
				return;
			}
			if ( false !== wp_next_scheduled( 'bwf_track_usage_scheduled_single' ) ) {
				wp_clear_scheduled_hook( 'bwf_track_usage_scheduled_single' );
			}
			if ( true === $pass_jit ) {
				wp_schedule_single_event( current_time( 'timestamp' ), 'bwf_track_usage_scheduled_single', array( 'yes', $product ) );
			} else {
				wp_schedule_single_event( current_time( 'timestamp' ), 'bwf_track_usage_scheduled_single', array( false, $product ) );
			}
			update_option( 'bwf_is_opted', 'yes', true );
		}

		/**
		 * Collect core data (WP, WC, plugins, theme)
		 * This is the base data collected by WooFunnels core
		 *
		 * @return array Core tracking data
		 */
		public static function collect_core_data() {
			global $wpdb, $woocommerce;

			// Get ALL installed plugins (not just WooFunnels plugins)
			// Use WordPress core get_plugins() function to fetch all installed plugins
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed_plugs_raw = get_plugins();

			// Get active plugins list
			$active_plugins_raw = get_option( 'active_plugins', array() );

			// Enrich active_plugins with plugin details (name, version, author) using base name as key
			// Follow the same structure as installed_plugins had: [plugin_file => ['name' => ..., 'version' => ..., 'author' => ...]]
			$active_plugins = array();
			foreach ( $active_plugins_raw as $plugin_file ) {
				if ( isset( $installed_plugs_raw[ $plugin_file ] ) ) {
					$plugin_data = $installed_plugs_raw[ $plugin_file ];
					$active_plugins[ $plugin_file ] = array(
						'name'    => isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : '',
						'version' => isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '',
						'author'  => isset( $plugin_data['AuthorName'] ) ? $plugin_data['AuthorName'] : ( isset( $plugin_data['Author'] ) ? $plugin_data['Author'] : '' ),
					);
				}
			}
			// License info collection removed - no longer tracking license data
			$theme               = array();
			$get_theme_info      = wp_get_theme();
			// Get parent theme if child theme is active (parent theme tells us the primary theme in use)
			$parent_stylesheet = $get_theme_info->get( 'Template' );
			if ( ! empty( $parent_stylesheet ) ) {
				// This is a child theme, get the parent theme instead
				$get_theme_info = wp_get_theme( $parent_stylesheet );
			}
			$theme['name']       = $get_theme_info->get( 'Name' );
			$theme['uri']        = $get_theme_info->get( 'ThemeURI' );
			$theme['version']    = $get_theme_info->get( 'Version' );
			$theme['author']     = $get_theme_info->get( 'Author' );
			$theme['author_uri'] = $get_theme_info->get( 'AuthorURI' );
			$sections            = array();

			if ( class_exists( 'WooCommerce' ) ) {
				$payment_gateways = WC()->payment_gateways->payment_gateways();
				foreach ( $payment_gateways as $gateway_key => $gateway ) {
					if ( 'yes' === $gateway->enabled ) {
						$sections[] = esc_html( $gateway_key );
					}
				}
			}

			/** Product Count */
			$product_count = array();
			if ( class_exists( 'WooCommerce' ) ) {
				$product_count_data     = wp_count_posts( 'product' );
				$product_count['total'] = property_exists( $product_count_data, 'publish' ) ? $product_count_data->publish : 0;

				$product_statuses = get_terms( array( 'taxonomy' => 'product_type', 'hide_empty' => 0 ) );
				if ( is_array( $product_statuses ) && count( $product_statuses ) > 0 ) {
					foreach ( $product_statuses as $product_status ) {
						$product_count[ $product_status->name ] = $product_status->count;
					}
				}
			}

			/** Order Count */
			$order_count = array();
			if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_orders_count' ) ) {
				foreach ( wc_get_order_statuses() as $status_slug => $status_name ) {
					$order_count[ $status_slug ] = wc_orders_count( $status_slug );
				}

			}

			/** Base country slug */
			$base_country = '';
			if ( class_exists( 'WooCommerce' ) ) {
				$base_country = get_option( 'woocommerce_default_country', false );
				if ( ! empty( $base_country ) ) {
					$base_country = substr( $base_country, 0, 2 );
				}
			}


			$return = array(
				'url'                => home_url(),
				'email'              => get_option( 'admin_email' ),
				'locale'             => get_locale(),
				'is_mu'              => is_multisite() ? 'yes' : 'no',
				'wp'                 => get_bloginfo( 'version' ),
				'php'                => phpversion(),
				'mysql'              => $wpdb->db_version(),
				'WooFunnels_version' => WooFunnel_Loader::$version,
				'theme_info'         => $theme,
				'users_count'        => self::get_user_counts(),
			);

			// Group all WooCommerce-specific data under 'woocommerce' key
			if ( class_exists( 'WooCommerce' ) ) {
				$return['woocommerce'] = array(
					'version'        => $woocommerce->version,
					'country'        => $base_country,
					'currency'       => get_woocommerce_currency(),
					'calc_taxes'     => get_option( 'woocommerce_calc_taxes' ),
					'guest_checkout' => get_option( 'woocommerce_enable_guest_checkout' ),
					'product_count'  => $product_count,
					'order_count'    => $order_count,
					'gateways'       => $sections,
				);
			}

			$return['active_plugins'] = $active_plugins;
			// License info removed - no longer tracking license data

			return $return;
		}

		/**
		 * Collect all usage data from core and all registered collectors
		 * Used for JIT (just-in-time) tracking when user opts in immediately
		 *
		 * @return array Complete usage data
		 */
		public static function collect_all_usage_data() {
			$start_time = microtime( true );

			// Collect core data (WP, WC, plugins, theme)
			$data = self::collect_core_data();

			// Collect data from all registered collectors synchronously (for JIT tracking)
			if ( class_exists( 'WooFunnels_Usage_Registry' ) ) {
				$collectors = WooFunnels_Usage_Registry::get_collectors();

				foreach ( array_keys( $collectors ) as $plugin_id ) {
					$collector = WooFunnels_Usage_Registry::get_collector( $plugin_id );
					if ( $collector && method_exists( $collector, 'collect' ) ) {
						try {
							$plugin_data = $collector->collect();
							if ( ! empty( $plugin_data ) ) {
								// Special handling for WooCommerce tracker: merge into core 'woocommerce' key
								if ( 'woocommerce' === $plugin_id && isset( $data['woocommerce'] ) && is_array( $data['woocommerce'] ) ) {
									// Merge WooCommerce tracker data into existing core woocommerce key
									$data['woocommerce'] = array_merge( $data['woocommerce'], $plugin_data );
								} elseif ( 'woocommerce' === $plugin_id ) {
									// If core woocommerce key doesn't exist, create it
									$data['woocommerce'] = $plugin_data;
								} else {
									// For other trackers, add as separate key
									$data[ $plugin_id ] = $plugin_data;
								}
							}
						} catch ( Throwable $e ) {
							// If one tracker fails, continue with others
							// Error is silently caught to prevent breaking usage collection
						}
					}
				}
			}

			// Track script generation time (similar to WooCommerce Tracker)
			$data['snapshot_generation_time'] = microtime( true ) - $start_time;

			return $data;
		}





		/**
		 * Collect some data and let the hook left for our other plugins to add some more info that can be tracked down
		 *
		 * @deprecated Use collect_all_usage_data() instead
		 *
		 * @return array data to track
		 */
		public static function collect_data() {
			return self::collect_all_usage_data();
		}

		/**
		 * Get user totals based on user role.
		 * @return array
		 */
		private static function get_user_counts() {
			$user_count          = array();
			$user_count_data     = count_users();
			$user_count['total'] = $user_count_data['total_users'];

			// Get user count based on user role
			foreach ( $user_count_data['avail_roles'] as $role => $count ) {
				$user_count[ $role ] = $count;
			}

			return $user_count;
		}

		public static function update_optIn_referer( $referer ) {
			update_option( 'woofunnels_optin_ref', $referer, false );
		}

		/**
		 * Checking the opt-in state and if we have scope for notification then push it
		 */
		public static function maybe_push_optin_notice() {
			if ( self::get_optIn_state() === false && apply_filters( 'woofunnels_optin_notif_show', self::$should_show_optin ) ) {
				do_action( 'bwf_maybe_push_optin_notice_state_action' );
			}
		}

		/**
		 * Get current optin status from database
		 *
		 * @return mixed|void
		 */
		public static function get_optIn_state() {
			return get_option( 'bwf_is_opted' );
		}

		/**
		 * Check if tracking should proceed
		 * Lite: checks opt-in using wrapper methods
		 * Pro: can override this method to bypass opt-in
		 *
		 * @return bool
		 */
		public static function should_track() {


			// Lite mode - check opt-in using wrapper methods
			return ( true === self::is_optin_allowed() && 'yes' === self::get_optIn_state() );
		}

		/**
		 * Callback function for JIT (just-in-time) tracking
		 * Used when user opts in immediately - collects and sends data synchronously
		 *
		 * Note: Scheduled tracking (midnight cron) is now handled by async mode via
		 * schedule_tracker_collections() and collect_and_send_tracking_data()
		 *
		 * Pro can override this by removing the action and adding their own
		 *
		 * @param bool|string $is_jit Whether this is a just-in-time tracking call
		 * @param string      $product Product identifier
		 */
		public static function maybe_track_usage( $is_jit = false, $product = 'FB' ) {
			// Check if tracking should proceed (uses wrapper methods)
			if ( ! self::should_track() ) {
				return;
			}

			// This method is now only used for JIT tracking (when user opts in)
			// Collect all usage data synchronously
			$data = self::collect_all_usage_data();

			if ( $is_jit === 'yes' ) {
				$data['jit'] = 'yes';
			}
			$data['product'] = $product;

			// Post data to API
			if ( class_exists( 'WooFunnels_API' ) ) {
				WooFunnels_API::post_tracking_data( $data );
			}
		}

		/**
		 * Schedule individual tracker collections with staggered times
		 * Called by midnight cron (fk_fb_every_day) or manually for testing
		 * Discovers all registered collectors and schedules individual single events
		 *
		 * Always uses current time as base, regardless of when midnight cron runs.
		 * This ensures schedules are created correctly even if midnight cron is delayed.
		 *
		 * @param int|null $base_time Optional base timestamp. If null, uses current time
		 */
		public static function schedule_tracker_collections( $base_time = null ) {
			// Check if tracking should proceed
			if ( ! self::should_track() ) {
				return;
			}

			if ( ! class_exists( 'WooFunnels_Usage_Registry' ) ) {
				return;
			}

			// Always use current UTC time as base (not midnight time)
			// wp_schedule_single_event() requires UTC timestamp
			// This ensures schedules work correctly even if midnight cron is delayed
			if ( null === $base_time || empty( $base_time ) ) {
				// Use time() for UTC timestamp, or current_time('timestamp', true) for UTC
				$base_time = time();
			}


			// Get all registered collectors
			$collectors = WooFunnels_Usage_Registry::get_collectors();

			if ( empty( $collectors ) ) {
				// Even if no collectors, schedule final send to handle core data
				// This ensures the system works even if no plugin collectors are registered
				$final_send_time = $base_time + HOUR_IN_SECONDS;
				wp_clear_scheduled_hook( 'fk_send_tracking_data' );
				wp_schedule_single_event( $final_send_time, 'fk_send_tracking_data' );
				return;
			}

			// Start scheduling at 15 minutes after current time, increment by 2 minutes per tracker
			$base_delay = 15 * MINUTE_IN_SECONDS; // 15 minutes
			$delay_increment = 2 * MINUTE_IN_SECONDS; // 2 minutes
			$current_delay = $base_delay;

			foreach (array_keys($collectors)	 as $plugin_id) {
				// Get collector instance to check if it should collect
				$collector = WooFunnels_Usage_Registry::get_collector( $plugin_id );

				// Skip scheduling if collector doesn't exist or shouldn't collect
				if ( ! $collector || ! $collector->should_collect() ) {
					continue;
				}

				// Calculate scheduled time from current time
				$scheduled_time = $base_time + $current_delay;

				// Schedule single event for this tracker
				$hook_name = 'fk_tracker_collect';
				$args = array( $plugin_id );

				// Clear any existing schedule for this tracker
				wp_clear_scheduled_hook( $hook_name, $args );

				// Schedule new single event
				wp_schedule_single_event( $scheduled_time, $hook_name, $args );

				// Increment delay for next tracker
				$current_delay += $delay_increment;
			}

			// Schedule final collection and send at current time + 1 hour
			// This should always be scheduled, even if there are no collectors
			$final_send_time = $base_time + HOUR_IN_SECONDS;
			wp_clear_scheduled_hook( 'fk_send_tracking_data' );
			wp_schedule_single_event( $final_send_time, 'fk_send_tracking_data' );
		}

		/**
		 * Handle individual tracker collection
		 * Action callback for individual tracker schedules
		 * Calls setup_data() on the specific collector
		 *
		 * @param string $plugin_id Plugin identifier
		 */
		public static function handle_tracker_collection( $plugin_id ) {
			if ( empty( $plugin_id ) || ! class_exists( 'WooFunnels_Usage_Registry' ) ) {
				return;
			}

			// Get the collector instance
			// allowing plugins to dynamically load their collector classes
			$collector = WooFunnels_Usage_Registry::get_collector( $plugin_id );

			if ( ! $collector || ! method_exists( $collector, 'setup_data' ) ) {
				return;
			}

			// Call setup_data() to collect and save data
			try {
				$collector->setup_data();
			} catch ( Throwable $e ) {
				// Silently fail - don't break other trackers
			}
		}

		/**
		 * Collect all saved tracking data and send to server
		 * Scheduled at 1:00 AM to collect all data from options table and send
		 */
		public static function collect_and_send_tracking_data() {
			// Check if tracking should proceed
			if ( ! self::should_track() ) {
				return;
			}

			$start_time = microtime( true );

			// Collect core data
			$data = self::collect_core_data();

			// Collect data from all registered collectors
			if ( class_exists( 'WooFunnels_Usage_Registry' ) ) {
				$collectors = WooFunnels_Usage_Registry::get_collectors();

				foreach ( array_keys( $collectors ) as $plugin_id ) {
					$collector = WooFunnels_Usage_Registry::get_collector( $plugin_id );
					if ( $collector && method_exists( $collector, 'return_data' ) ) {
						try {
							$plugin_data = $collector->return_data();
							if ( ! empty( $plugin_data ) ) {
								// Special handling for WooCommerce tracker: merge into core 'woocommerce' key
								if ( 'woocommerce' === $plugin_id && isset( $data['woocommerce'] ) && is_array( $data['woocommerce'] ) ) {
									// Merge WooCommerce tracker data into existing core woocommerce key
									$data['woocommerce'] = array_merge( $data['woocommerce'], $plugin_data );
								} elseif ( 'woocommerce' === $plugin_id ) {
									// If core woocommerce key doesn't exist, create it
									$data['woocommerce'] = $plugin_data;
								} else {
									// For other trackers, add as separate key
									$data[ $plugin_id ] = $plugin_data;
								}
							}
						} catch ( Throwable $e ) {
							// If one tracker fails, continue with others
							// Error is silently caught to prevent breaking usage collection
						}
					}
				}
			}

			// Track script generation time (similar to WooCommerce Tracker)
			$data['snapshot_generation_time'] = microtime( true ) - $start_time;

			// Send data to server
			if ( ! empty( $data ) ) {
				try {
					WooFunnels_API::post_tracking_data( $data );

					// Clean up saved data from options table after successful send
					if ( class_exists( 'WooFunnels_Usage_Registry' ) ) {
						$collectors = WooFunnels_Usage_Registry::get_collectors();
						foreach ( array_keys( $collectors ) as $plugin_id ) {
							$collector = WooFunnels_Usage_Registry::get_collector( $plugin_id );
							if ( $collector && method_exists( $collector, 'get_option_key' ) ) {
								try {
									$option_key = $collector->get_option_key();
									delete_option( $option_key );
								} catch ( Throwable $e ) {
									// Silently continue if cleanup fails
								}
							}
						}
					}
				} catch ( Throwable $e ) {
					// If sending fails, data remains in options table for next attempt
					// Error is silently caught to prevent breaking usage collection
				}
			}

			// Post data to API
			if ( class_exists( 'WooFunnels_API' ) ) {
				WooFunnels_API::post_tracking_data( $data );
			}

			// Clean up saved data from options table after sending
			// This prevents stale data accumulation
			if ( class_exists( 'WooFunnels_Usage_Registry' ) ) {
				$collectors = WooFunnels_Usage_Registry::get_collectors();
				foreach ( $collectors as $plugin_id => $collector_info ) {
					$collector = WooFunnels_Usage_Registry::get_collector( $plugin_id );
					if ( $collector && method_exists( $collector, 'get_option_key' ) ) {
						try {
							$option_key = $collector->get_option_key();
							if ( ! empty( $option_key ) ) {
								delete_option( $option_key );
							}
						} catch ( Throwable $e ) {
							// Silently continue if cleanup fails
						}
					}
				}
			}
		}

		/**
		 * Initiate schedules in order to start tracking data regularly.
		 *
		 */
		public static function initiate_schedules() {

			// Run only in the admin dashboard

			try {
				if ( ! is_admin() ) {
					return;
				}
				if ( ! current_user_can( 'administrator' ) ) {
					return;
				}
				if ( ! wp_next_scheduled( 'fk_fb_every_day' ) ) {
					wp_schedule_event( self::get_midnight_store_time(), 'daily', 'fk_fb_every_day' );
				} else {

					$scheduled_time = wp_next_scheduled( 'fk_fb_every_day' );
					$midnight_time = self::get_midnight_store_time();

					if ( $scheduled_time && abs( $scheduled_time - $midnight_time ) > 3600 ) {
						do_action( 'fk_fb_every_day' );
						wp_clear_scheduled_hook( 'fk_fb_every_day' );
					}
				}

				// Note: Individual tracker schedules and final send schedule are created
				// dynamically by schedule_tracker_collections() when midnight cron fires
				// No need to register them here statically

				$legacy_schedules = array(
					'wfocu_schedule_mails_for_bacs_and_cheque',
					'wfocu_schedule_pending_emails',
					'wfocu_schedule_normalize_order_statuses',
					'wfocu_schedule_thankyou_action',
					'wfocu_remove_orphaned_transients',
					'wffn_remove_orphaned_transients',
					'woofunnels_license_check',
				);
				foreach ( $legacy_schedules as $schedule ) {

					if ( strpos( $schedule, 'wfocu_' ) === 0 && ! wp_next_scheduled( 'fk_fb_every_4_minute' ) ) {
						continue;
					}
					if ( wp_next_scheduled( $schedule ) ) {
						wp_clear_scheduled_hook( $schedule );
					}
				}
			} catch ( Exception $e ) {

			}


		}


		/**
		 * @return int|void
		 */
		public static function get_midnight_store_time() {

			try {
				$timezone = new DateTimeZone( wp_timezone_string() );
				$date     = new DateTime();
				$date->modify( '+1 days' );
				$date->setTimezone( $timezone );
				$date->setTime( 0, 0, 0 );

				return $date->getTimestamp();
			} catch ( Exception $e ) {

			}
		}

		public static function is_optin_allowed() {
			return apply_filters( 'buildwoofunnels_optin_allowed', true );
		}

		public static function woofunnelso_optin_call() {
			check_ajax_referer( 'bwf_secure_key' );
			if ( is_array( $_POST ) && count( $_POST ) > 0 ) {
				$_POST['domain'] = home_url();
				$_POST['ip']     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- REMOTE_ADDR is IP address, wp_unslash is safe
				WooFunnels_API::post_optin_data( $_POST );

				/** scheduling track call when success */
				if ( isset( $_POST['status'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified with check_ajax_referer
					wp_schedule_single_event( time() + 2, 'woofunnels_optin_success_track_scheduled' );
				}
			}
			wp_send_json( array(
				'status' => 'success',
			) );
			exit;
		}

		/**
		 * Callback function to run on schedule hook (opt-in success)
		 */
		public static function optin_track_usage() {
			/** update week day for tracking */
			$track_week_day = gmdate( 'w' );
			update_option( 'woofunnels_track_day', $track_week_day, false );

			// Collect all usage data
			$data = self::collect_all_usage_data();

			// Posting data to API
			WooFunnels_API::post_tracking_data( $data );
		}

		public static function maybe_default_optin() {
			return;
		}

		public static function register_weekly_schedule( $schedules ) {
			$schedules['weekly_bwf'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => 'Weekly BWF', // not translated: cron_schedules can run before init, see class-woofunnels-as-ds.php
			);

			return $schedules;
		}
	}

}
