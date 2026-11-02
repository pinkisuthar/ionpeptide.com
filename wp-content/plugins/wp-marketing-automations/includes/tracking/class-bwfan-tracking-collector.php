<?php
/**
 * Automations Lite Tracking Collector Class
 *
 * Collects tracking data for FunnelKit Automations Lite
 *
 * @package FunnelKit Automations
 * @since 3.6.5
 */

defined( 'ABSPATH' ) || exit;

// Load the new usage collector class
if ( ! class_exists( 'BWFAN_Usage_Collector' ) ) {
	$usage_file = __DIR__ . '/../usage/class-bwfan-usage-collector.php';
	if ( file_exists( $usage_file ) ) {
		require_once $usage_file;
	}
}

if ( ! class_exists( 'BWFAN_Tracking_Collector' ) && class_exists( 'BWFAN_Usage_Collector' ) ) {

	/**
	 * Class BWFAN_Tracking_Collector
	 * @deprecated Use BWFAN_Usage_Collector instead
	 */
	#[\AllowDynamicProperties]
	class BWFAN_Tracking_Collector extends BWFAN_Usage_Collector {

		/**
		 * Plugin identifier
		 *
		 * @var string
		 */
		protected $plugin_id = 'automations';

		/**
		 * Module type
		 *
		 * @var string
		 */
		protected $module = 'lite';

		/**
		 * Tracking version (deprecated, use usage_version)
		 *
		 * @var string
		 */
		protected $tracking_version = '1.0.0';

		/**
		 * @var null
		 */
		private static $ins = null;

		/**
		 * BWFAN_Tracking_Collector constructor.
		 */
		public function __construct() {

			// Don't register if Pro is active (Pro will handle usage)
			if ( class_exists( 'BWFANCRM_Tracking_Collector' ) || class_exists( 'BWFANCRM_Usage_Collector' ) ) {
				return;
			}
			// Call parent constructor to register with registry
			parent::__construct();
		}

		/**
		 * Add this collector to the registry filter
		 *
		 * @param array $collectors Existing collectors
		 * @return array
		 */
		public static function add_to_registry( $collectors ) {

			// Don't register if Pro is active
			if ( class_exists( 'BWFANCRM_Tracking_Collector' ) || class_exists( 'BWFANCRM_Usage_Collector' ) ) {
				return $collectors;
			}
			$collectors['automations'] = array(
				'class'  => __CLASS__,
				'module' => 'lite',
			);
			return $collectors;
		}

		/**
		 * Get instance
		 *
		 * @return BWFAN_Tracking_Collector|null
		 */
		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self();
			}

			return self::$ins;
		}

		/**
		 * Collect tracking data
		 *
		 * @return array Tracking data
		 */
		public function collect() {
			// Check if should collect (uses wrapper methods from abstract)
			if ( ! $this->should_collect() ) {
				return array();
			}

			/** If opted time is empty return empty array */
			$opted_time = bwf_options_get( 'bwf_usage_tracking_opted_on' );
			if ( empty( $opted_time ) ) {
				return array();
			}

			try {
				// Get Lite data using internal methods
				$lite_data = $this->collect_lite_data();

				if ( empty( $lite_data ) || ! is_array( $lite_data ) ) {
					return array();
				}

				// Map current data structure to new format
				$installation_config = array();
				if ( isset( $lite_data['lite'] ) ) {
					$installation_config['lite'] = $lite_data['lite'];
				}
				if ( isset( $lite_data['install_date'] ) ) {
					$installation_config['install_date'] = $lite_data['install_date'];
				}

				$feature = array();
				if ( isset( $lite_data['features'] ) && is_array( $lite_data['features'] ) ) {
					$feature = $lite_data['features'];
				}

				$feature_performance = array();
				if ( isset( $lite_data['feature_performance'] ) && is_array( $lite_data['feature_performance'] ) ) {
					$feature_performance = $lite_data['feature_performance'];
				}

				// Build usage data structure
				return array(
					'version'             => $this->usage_version ?? $this->tracking_version,
					'module'              => $this->module,
					'plugin'              => $this->plugin_id,
					'installation_config' => $installation_config,
					'feature'             => $feature,
					'feature_performance' => $feature_performance,
				);

			} catch ( Throwable $e ) {
				// Return empty array if collection fails
				return array();
			}
		}

		/**
		 * Collect lite data (internal method)
		 *
		 * @return array lite data
		 */
		private function collect_lite_data() {
			$contacts_data = $this->get_contacts_data();
			$orders_data    = $this->get_orders_data();
			$carts_data     = $this->get_carts_data();
			$automations_data = $this->get_automations_data();
			$active_features = $this->get_active_features();

			return [
				'lite'           => BWFAN_VERSION,
				'install_date'   => get_option( 'bwfan_ver_1_0', gmdate( 'Y-m-d' ) ),
				'features'       => [
					'active'     => $active_features,
					'automation/total' => $automations_data['total_count'],
					'automation/active' => $automations_data['active_count'],
					'automation/events' => $automations_data['event_slugs'],
					'automation/goals' => $automations_data['goals_slugs'],
					'automation/steps/actions' => $automations_data['steps_actions'],
					'automation/steps/active' => $automations_data['steps_active_count'],
					'automation/conversion' => $automations_data['order_count'],
					"contact/total" => $contacts_data['total_count'],
					"contact/subscribed" => $contacts_data['subscribed_count'],
					"customer/orders" => $orders_data['total_orders'],
					"cart/total" => $carts_data['total_count'],
					"engagement/email" => $this->get_total_email_sent(),
				],
				'feature_performance' => [
					'automation/last_30_days_order_count' => $automations_data['last_30_days_order_count'],
					"cart/last_30_days_count" => $carts_data['last_30_days_count'],
				]
			];
		}

		/**
		 * Gets Automations data
		 *
		 * @return array automations data
		 */
		private function get_automations_data() {
			$count_data     = $this->get_automation_count();
			$conversion_data = $this->get_automation_conversion_data();

			return [
				'total_count'              => $count_data['total_count'],
				'active_count'             => $count_data['active_count'],
				'event_slugs'              => $count_data['event_slugs'],
				'goals_slugs'              => $count_data['goals_slugs'],
				'steps_actions'            => $count_data['steps_actions'],
				'steps_active_count'       => $count_data['steps_active_count'],
				'order_count'              => $conversion_data['order_count'],
				'last_30_days_order_count' => $conversion_data['last_30_days_order_count'],
			];
		}

		/**
		 * Gets automation count data
		 *
		 * @return array automation count data
		 */
		private function get_automation_count() {
			global $wpdb;

			$automations_table = $wpdb->prefix . 'bwfan_automations';

			$query = "SELECT COUNT(DISTINCT ID) as total_count,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_count,
            GROUP_CONCAT(CASE WHEN status = 1 AND event != '' THEN event ELSE NULL END) as events,
            GROUP_CONCAT(CASE WHEN status = 1 AND  benchmark != '' THEN benchmark ELSE NULL END SEPARATOR '<>' ) as benchmark
	        FROM {$automations_table}";

			$result = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; tables from $wpdb->prefix

			if ( ! is_array( $result ) || empty( $result ) ) {
				return [
					'total_count'  => 0,
					'active_count' => 0,
					'event_slugs'  => [],
				];
			}
			$events = ! empty( $result['events'] ) ? array_filter( array_values( array_unique( explode( ',', $result['events'] ) ) ) ) : [];
			$benchmark = ! empty( $result['benchmark'] ) ? explode( '<>', $result['benchmark'] ) : [];
			$benchmark_slugs = [];
			foreach( $benchmark as $item ) {
				$item = ! empty( $item ) && is_string( $item ) ? json_decode( $item, true ) : [];
				if( empty( $item ) || ! is_array( $item ) ) {
					continue;
				}
				$benchmark_slugs = array_merge( $benchmark_slugs, array_values( $item ) );
			}
			$steps_actions = $this->get_steps_data();
			$benchmark_slugs = array_filter( array_unique( $benchmark_slugs ) );
			return [
				'total_count'  => (int) ( $result['total_count'] ?? 0 ),
				'active_count' => (int) ( $result['active_count'] ?? 0 ),
				'event_slugs'  => array_values($events),
				'goals_slugs'  => array_values($benchmark_slugs),
				'steps_actions' => array_values($steps_actions['actions']),
				'steps_active_count' => $steps_actions['active_count'],
			];
		}

		/**
		 * Gets lite steps data
		 *
		 * @return array steps data
		 */
		private function get_steps_data() {
			global $wpdb;
			$automations_table = $wpdb->prefix . 'bwfan_automations';
			$steps_table = $wpdb->prefix . 'bwfan_automation_step';

			// need query here for getting the active status automation action steps count and actions column data <>' separated string
			$query = "SELECT COUNT(DISTINCT s.ID) as total_count,
			SUM(CASE WHEN s.status = 1 THEN 1 ELSE 0 END) as active_count,
			GROUP_CONCAT(CASE WHEN s.action != '' THEN s.action ELSE NULL END SEPARATOR '<>' ) as actions
			FROM {$steps_table} as s JOIN {$automations_table} as a ON s.aid = a.ID WHERE a.status = 1 AND s.type = 2 AND s.status = 1";
			$result = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; tables from $wpdb->prefix

			if( ! is_array( $result ) || empty( $result ) ) {
				return [
					'total_count' => 0,
					'active_count' => 0,
					'actions' => [],
				];
			}

			$actions = ! empty( $result['actions'] ) ? explode( '<>', $result['actions'] ) : [];
			$actions_slugs = [];
			if ( ! empty( $actions ) ) {
				foreach( $actions as $item ) {
					if( empty( $item ) ) {
						continue;
					}
					$item = ! empty( $item ) && is_string( $item ) ? json_decode( $item, true ) : [];
					if( empty( $item ) || ! is_array( $item ) || empty( $item['action'] ) ) {
						continue;
					}
					$actions_slugs[] = $item['action'];
				}
				$actions_slugs = array_filter( array_unique( $actions_slugs ) );
			}

			return [
				'total_count' => (int) ( $result['total_count'] ?? 0 ),
				'active_count' => (int) ( $result['active_count'] ?? 0 ),
				'actions' => array_values($actions_slugs),
			];
		}

		/**
		 * Gets automation conversion data (orders and revenue from automations)
		 *
		 * @return array Conversion data with order_count, last_30_days_order_count, revenue, last_30_days_revenue
		 */
		private function get_automation_conversion_data() {
			global $wpdb;

			$conversions_table = $wpdb->prefix . 'bwfan_conversions';

			// Check if table exists (Pro feature)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- hardcoded SQL; tables from $wpdb->prefix
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$conversions_table}'" ) !== $conversions_table ) {
				return [
					'order_count'              => 0,
					'last_30_days_order_count' => 0,
				];
			}

			// Total order count and revenue from all automation conversions
			$total_query = "SELECT COUNT(DISTINCT wcid) as order_count FROM {$conversions_table}
			WHERE otype = 1 AND wcid != 0";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; tables from $wpdb->prefix
			$total_result = $wpdb->get_row( $total_query, ARRAY_A );

			// Last 30 days order count and revenue from automation conversions where otype (object type) is 1 (Automation) and wcid (order ID) is not 0
			$last_30_days_query = "SELECT COUNT(DISTINCT wcid) as order_count FROM {$conversions_table} WHERE otype = 1 AND wcid != 0 AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; tables from $wpdb->prefix
			$last_30_days_result = $wpdb->get_row( $last_30_days_query, ARRAY_A );

			return [
				'order_count'              => (int) ( $total_result['order_count'] ?? 0 ),
				'last_30_days_order_count' => (int) ( $last_30_days_result['order_count'] ?? 0 ),
			];
		}

		/**
		 * Gets count of contacts
		 *
		 * @param bool $active_count Whether to get only subscribed/active contacts
		 * @return int Count of contacts
		 */
		private function get_contacts_count($active_count = false) {
			global $wpdb;

			$contacts_table = $wpdb->prefix . 'bwf_contact';

			$query = "SELECT COUNT(c.id) FROM {$contacts_table} AS c WHERE c.email != '' AND c.email IS NOT NULL";
			if ( true === $active_count ) {
				// Count of subscribed contacts where status is 1 (Active) and not exists in message_unsubscribe table (Email) and not exists in message_unsubscribe table (SMS)
				$query .= " AND c.status = 1 AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}bwfan_message_unsubscribe AS unsub WHERE unsub.recipient = c.email )";
				$query .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}bwfan_message_unsubscribe AS unsub1 WHERE unsub1.recipient = c.contact_no )";
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; tables from $wpdb->prefix
			return intval( $wpdb->get_var( $query ) );
		}

		/**
		 * Gets contacts data
		 *
		 * @return array contacts data
		 */
		private function get_contacts_data() {
			$data = [
				'total_count' => $this->get_contacts_count(),
				'subscribed_count' => $this->get_contacts_count(true),
			];
			return $data;
		}

		/**
		 * Gets array of active lite features based on settings
		 *
		 * @return array List of active features
		 */
		private function get_active_features() {
			$settings        = get_option( 'bwfan_global_settings', [] );
			$active_features = [];

			$feature_keys = [
				'bwfan_enable_notification'  => 'notifications',
				'bwfan_ab_enable'            => 'cart_abandonment',
				'bwfan_user_consent'         => 'user_consent',
				'bwfan_dob_field'            => 'dob_field',
				'bwfan_dob_field_my_account' => 'dob_field_my_account',
				'bwfan_sandbox_mode'         => 'sandbox_mode',
				'bwfan_make_logs'            => 'basic_logging',
				'bwfan_advance_logs'         => 'advance_logging',
			];

			foreach ( $feature_keys as $setting_key => $feature_name ) {
				if ( isset( $settings[ $setting_key ] ) && filter_var( $settings[ $setting_key ], FILTER_VALIDATE_BOOLEAN ) ) {
					$active_features[] = $feature_name;
				}
			}

			return array_unique( $active_features );
		}

		/**
		 * Gets total count of emails sent
		 *
		 * @return int Total count of emails sent
		 */
		private function get_total_email_sent() {
			global $wpdb;

			$engagement_table = $wpdb->prefix . 'bwfan_engagement_tracking';

			// Check if table exists (Pro feature)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- hardcoded SQL; tables from $wpdb->prefix
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$engagement_table}'" ) !== $engagement_table ) {
				return 0;
			}

			// Total count of emails sent where mode is 1 (Email) and c_status is 2 (Sent)
			$query = "SELECT COUNT(ID) FROM {$engagement_table} WHERE mode = 1 AND c_status = 2";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; tables from $wpdb->prefix
			return intval( $wpdb->get_var( $query ) );
		}

		/**
		 * Gets orders data (total orders and revenue)
		 *
		 * @return array Orders data with total_orders and revenue
		 */
		private function get_orders_data() {
			global $wpdb;

			$customers_table = $wpdb->prefix . 'bwf_wc_customers';

			$query = "SELECT COALESCE(SUM(total_order_count), 0) as total_orders, COALESCE(SUM(total_order_value), 0) as revenue FROM {$customers_table}";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; tables from $wpdb->prefix
			$result = $wpdb->get_row( $query, ARRAY_A );

			if ( ! is_array( $result ) ) {
				return [
					'total_orders' => 0,
				];
			}

			return [
				'total_orders' => (int) ( $result['total_orders'] ?? 0 ),
			];
		}

		/**
		 * Gets abandoned carts data
		 *
		 * @return array Carts data with total_count and last_30_days_count
		 */
		private function get_carts_data() {
			global $wpdb;

			$carts_table = $wpdb->prefix . 'bwfan_abandonedcarts';

			// Total count of all captured carts
			$total_query = "SELECT COUNT(ID) FROM {$carts_table}";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; tables from $wpdb->prefix
			$total_count = intval( $wpdb->get_var( $total_query ) );

			// Count of carts captured in last 30 days with status 0 = pending, 1 = in-progress, 3 = skipped
			$last_30_days_query = "SELECT COUNT(ID) FROM {$carts_table}
			WHERE created_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; tables from $wpdb->prefix
			$last_30_days_count = intval( $wpdb->get_var( $last_30_days_query ) );

			return [
				'total_count'        => $total_count,
				'last_30_days_count' => $last_30_days_count,
			];
		}
	}

	// Register collector via filter hook (lazy registration - only when class is loaded)
}

