<?php
/**
 * Automations Lite Usage Collector Class
 *
 * Collects usage data for FunnelKit Automations Lite
 *
 * @package FunnelKit Automations
 * @since 3.6.5
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFAN_Usage_Collector' ) && class_exists( 'WooFunnels_Usage_Collector_Abstract' ) ) {

	/**
	 * Class BWFAN_Usage_Collector
	 */
	#[\AllowDynamicProperties]
	class BWFAN_Usage_Collector extends WooFunnels_Usage_Collector_Abstract {

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
		 * Usage version
		 *
		 * @var string
		 */
		protected $usage_version = '1.0.0';

		/**
		 * @var null
		 */
		private static $ins = null;

		/**
		 * BWFAN_Usage_Collector constructor.
		 */
		public function __construct() {

			// Don't register if Pro is active (Pro will handle usage)
			if ( class_exists( 'BWFANCRM_Usage_Collector' ) ) {
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
			if ( class_exists( 'BWFANCRM_Usage_Collector' ) ) {
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
		 * @return BWFAN_Usage_Collector|null
		 */
		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self();
			}

			return self::$ins;
		}

		/**
		 * Check if collector should collect data
		 * Overrides abstract method to include Automations-specific opt-in check
		 *
		 * @return bool
		 */
		public function should_collect() {
			// First check opt-in status (from parent)
			if ( ! parent::should_collect() ) {
				return false;
			}

			// Then check Automations-specific opt-in
			$opted_time = bwf_options_get( 'bwf_usage_tracking_opted_on' );
			if ( empty( $opted_time ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Collect usage data
		 *
		 * @return array Usage data
		 */
		public function collect() {
			// Check if should collect (includes opt-in checks)
			if ( ! $this->should_collect() ) {
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

				$settings = array();
				if ( isset( $lite_data['settings'] ) && is_array( $lite_data['settings'] ) ) {
					$settings = $lite_data['settings'];
				}

				// Build usage data structure
				return array(
					'version'             => $this->usage_version,
					'module'              => $this->module,
					'plugin'              => $this->plugin_id,
					'installation_config' => $installation_config,
					'feature'             => $feature,
					'feature_performance' => $feature_performance,
					'settings'            => $settings,
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
			$duplicate_contacts_data = $this->get_duplicate_contacts_data();
			$lists_tags_data = $this->get_lists_tags_data();
			$custom_fields_data = $this->get_custom_fields_data();
			$automation_v1_data = $this->get_automation_v1_data();
			$settings_data = $this->get_settings_data();

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
					"contact/duplicate_emails" => $duplicate_contacts_data['duplicate_emails_count'],
					"contact/duplicate_phones" => $duplicate_contacts_data['duplicate_phones_count'],
					"customer/orders" => $orders_data['total_orders'],
					"cart/total" => $carts_data['total_count'],
					"engagement/email" => $this->get_total_email_sent(),
					"lists/total" => $lists_tags_data['lists_count'],
					"tags/total" => $lists_tags_data['tags_count'],
					"custom_fields/total" => $custom_fields_data['total_count'],
					"custom_fields/types" => $custom_fields_data['types'],
					"automation/v1/active" => $automation_v1_data['active'],
					"automation/v1/total" => $automation_v1_data['total_count'],
					"automation/v1/active_slugs" => $automation_v1_data['active_slugs'],
				],
				'settings' => $settings_data,
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

			$result = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL

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
			$result = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL

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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$conversions_table}'" ) !== $conversions_table ) {
				return [
					'order_count'              => 0,
					'last_30_days_order_count' => 0,
				];
			}

			// Total order count and revenue from all automation conversions
			$total_query = "SELECT COUNT(DISTINCT wcid) as order_count FROM {$conversions_table}
			WHERE otype = 1 AND wcid != 0";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
			$total_result = $wpdb->get_row( $total_query, ARRAY_A );

			// Last 30 days order count and revenue from automation conversions where otype (object type) is 1 (Automation) and wcid (order ID) is not 0
			$last_30_days_query = "SELECT COUNT(DISTINCT wcid) as order_count FROM {$conversions_table} WHERE otype = 1 AND wcid != 0 AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$engagement_table}'" ) !== $engagement_table ) {
				return 0;
			}

			// Total count of emails sent where mode is 1 (Email) and c_status is 2 (Sent)
			$query = "SELECT COUNT(ID) FROM {$engagement_table} WHERE mode = 1 AND c_status = 2";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
			$total_count = intval( $wpdb->get_var( $total_query ) );

			// Count of carts captured in last 30 days with status 0 = pending, 1 = in-progress, 3 = skipped
			$last_30_days_query = "SELECT COUNT(ID) FROM {$carts_table}
			WHERE created_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
			$last_30_days_count = intval( $wpdb->get_var( $last_30_days_query ) );

		return [
			'total_count'        => $total_count,
			'last_30_days_count' => $last_30_days_count,
		];
	}

	/**
	 * Gets duplicate contacts data
	 *
	 * @return array Duplicate contacts data with duplicate_emails_count and duplicate_phones_count
	 */
	private function get_duplicate_contacts_data() {
		global $wpdb;

		$contacts_table = $wpdb->prefix . 'bwf_contact';

		// Count duplicate emails (contacts with same email address)
		$duplicate_emails_query = "SELECT COUNT(*) as duplicate_count
			FROM (
				SELECT email, COUNT(*) as cnt
				FROM {$contacts_table}
				WHERE email != '' AND email IS NOT NULL
				GROUP BY email
				HAVING cnt > 1
			) as duplicates";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
		$duplicate_emails_count = intval( $wpdb->get_var( $duplicate_emails_query ) );

		// Count duplicate phone numbers (contacts with same contact_no)
		$duplicate_phones_query = "SELECT COUNT(*) as duplicate_count
			FROM (
				SELECT contact_no, COUNT(*) as cnt
				FROM {$contacts_table}
				WHERE contact_no != '' AND contact_no IS NOT NULL
				GROUP BY contact_no
				HAVING cnt > 1
			) as duplicates";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
		$duplicate_phones_count = intval( $wpdb->get_var( $duplicate_phones_query ) );

		return [
			'duplicate_emails_count' => $duplicate_emails_count,
			'duplicate_phones_count' => $duplicate_phones_count,
		];
	}

	/**
	 * Gets lists and tags data
	 *
	 * @return array Lists and tags data with lists_count and tags_count
	 */
	private function get_lists_tags_data() {
		global $wpdb;

		$terms_table = $wpdb->prefix . 'bwfan_terms';

		// Check if table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$terms_table}'" ) !== $terms_table ) {
			return [
				'lists_count' => 0,
				'tags_count'  => 0,
			];
		}

		// Count lists (type = 2) and tags (type = 1)
		$query = "SELECT
			SUM(CASE WHEN type = 2 THEN 1 ELSE 0 END) as lists_count,
			SUM(CASE WHEN type = 1 THEN 1 ELSE 0 END) as tags_count
			FROM {$terms_table}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
		$result = $wpdb->get_row( $query, ARRAY_A );

		if ( empty( $result ) ) {
			return [
				'lists_count' => 0,
				'tags_count'  => 0,
			];
		}

		return [
			'lists_count' => intval( $result['lists_count'] ?? 0 ),
			'tags_count'  => intval( $result['tags_count'] ?? 0 ),
		];
	}

	/**
	 * Gets custom fields data
	 *
	 * @return array Custom fields data with total_count and types array
	 */
	private function get_custom_fields_data() {
		global $wpdb;

		$fields_table = $wpdb->prefix . 'bwfan_fields';

		// Check if table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$fields_table}'" ) !== $fields_table ) {
			return [
				'total_count' => 0,
				'types'       => [],
			];
		}

		// Get total count and group by type with text labels
		$query = "SELECT
			CASE
				WHEN type = 1 THEN 'text'
				WHEN type = 2 THEN 'number'
				WHEN type = 3 THEN 'textarea'
				WHEN type = 4 THEN 'select'
				WHEN type = 5 THEN 'radio'
				WHEN type = 6 THEN 'checkbox'
				WHEN type = 7 THEN 'date'
				WHEN type = 8 THEN 'datetime'
				WHEN type = 9 THEN 'time'
				ELSE CONCAT('type_', type)
			END as type_text,
			COUNT(*) as count
			FROM {$fields_table}
			GROUP BY type";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
		$results = $wpdb->get_results( $query, ARRAY_A );

		$total_count = 0;
		$types       = [];

		if ( ! empty( $results ) && is_array( $results ) ) {
			foreach ( $results as $row ) {
				$type_text = $row['type_text'] ?? '';
				$count     = intval( $row['count'] ?? 0 );
				$total_count += $count;
				if ( ! empty( $type_text ) ) {
					$types[ $type_text ] = $count;
				}
			}
		}

		return [
			'total_count' => $total_count,
			'types'       => $types,
		];
	}

	/**
	 * Gets automation v1 data
	 *
	 * @return array Automation v1 data with active (1 or 0), total_count, and active_slugs
	 */
	private function get_automation_v1_data() {
		global $wpdb;

		$automations_table = $wpdb->prefix . 'bwfan_automations';

		// Get v1 automations data (v = 1)
		$query = "SELECT
			COUNT(DISTINCT ID) as total_count,
			SUM(CASE WHEN status = 1 AND v = 1 THEN 1 ELSE 0 END) as active_count,
			GROUP_CONCAT(DISTINCT CASE WHEN status = 1 AND v = 1 AND event != '' THEN event ELSE NULL END) as active_events
			FROM {$automations_table}
			WHERE v = 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL
		$result = $wpdb->get_row( $query, ARRAY_A );

		if ( empty( $result ) ) {
			return [
				'active'      => 0,
				'total_count' => 0,
				'active_slugs' => [],
			];
		}

		$total_count = intval( $result['total_count'] ?? 0 );
		$active_count = intval( $result['active_count'] ?? 0 );
		$active = $active_count > 0 ? 1 : 0;

		// Parse active event slugs
		$active_slugs = [];
		if ( ! empty( $result['active_events'] ) ) {
			$events = explode( ',', $result['active_events'] );
			$active_slugs = array_filter( array_values( array_unique( $events ) ) );
		}

		return [
			'active'       => $active,
			'total_count'  => $total_count,
			'active_slugs' => array_values( $active_slugs ),
		];
	}

	/**
	 * Gets settings data
	 *
	 * @return array Settings data with all requested settings
	 */
	private function get_settings_data() {
		$settings = get_option( 'bwfan_global_settings', [] );

		return [
			'wait_period'                          => intval( $settings['bwfan_ab_init_wait_time'] ?? 0 ),
			'cool_off_period'                      => intval( $settings['bwfan_disable_abandonment_days'] ?? 0 ),
			'lost_cart_days'                       => intval( $settings['bwfan_ab_mark_lost_cart'] ?? 0 ),
			'gdpr_consent'                         => intval( $settings['bwfan_ab_email_consent'] ?? 0 ),
			'email_marketing_consent'              => intval( $settings['bwfan_user_consent'] ?? 0 ),
			'subscribe_page_manage_list_active'    => intval( $settings['bwfan_subscribe_page_manage_list_active'] ?? 0 ),
			'delete_expired_coupon'                => intval( $settings['bwfan_delete_expired_coupon'] ?? 0 ),
			'delete_engagement_tracking_meta_days' => intval( $settings['bwfan_delete_engagement_tracking_meta_days'] ?? 0 ),
		];
	}

	/**
	 * Setup data - Collects data and saves to options table
	 * Called by individual tracker schedule
	 *
	 * @return bool True on success, false on failure
	 */
	public function setup_data() {
			// Check if we should collect data
			if ( ! $this->should_collect() ) {
				return false;
			}

			$data = $this->collect();
			if ( ! empty( $data ) ) {
				$option_key = $this->get_option_key();
				update_option( $option_key, array(
					'timestamp' => time(),
					'data'      => $data,
				), false );
				return true;
			}
			return false;
		}

		/**
		 * Return data - Returns saved data from options table
		 * Called by final collector to retrieve saved data
		 *
		 * @return array Saved data or empty array if not found
		 */
		public function return_data() {
			$option_key = $this->get_option_key();
			$saved_data = get_option( $option_key, array() );
			if ( isset( $saved_data['data'] ) && is_array( $saved_data['data'] ) ) {
				return $saved_data['data'];
			}
			return array();
		}

		/**
		 * Get option key - Returns the option key name for this tracker's data
		 *
		 * @return string Option key name
		 */
		public function get_option_key() {
			return 'fk_usage_data_' . $this->plugin_id;
		}
	}

	// Register collector via filter hook (lazy registration - only when class is loaded)
}

