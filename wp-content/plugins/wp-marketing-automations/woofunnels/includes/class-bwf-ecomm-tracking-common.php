<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Class BWF_Ecomm_Tracking_Common
 */
if ( ! class_exists( 'BWF_Ecomm_Tracking_Common' ) ) {
	#[\AllowDynamicProperties]
	class BWF_Ecomm_Tracking_Common {
		public $api_events    = array();
		public $gtag_rendered = false;
		private static $ins   = null;

		private $conv_table = 'bwf_conversion_tracking';

		private $journeyControl = 'enable';

		public function __construct() {

			if ( ! class_exists( 'WFFN_Core' ) ) {
				return;
			}
			add_action( 'wp_enqueue_scripts', array( $this, 'render' ), 1 );
			add_action( 'wffn_optin_form_submit', array( $this, 'update_optin_tracking_data' ), 10, 2 );
			add_filter( 'bwf_add_db_table_schema', array( $this, 'create_db_tables' ), 10, 2 );
			add_action( 'add_meta_boxes', array( $this, 'add_single_order_meta_box' ), 50, 2 );

			add_action( 'woocommerce_checkout_create_order', array( $this, 'add_tracking_data_in_order_meta' ), 12, 1 );
			// Block-based checkout (Cart/Checkout blocks via Store API) does not fire
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'add_tracking_data_in_order_meta' ), 12, 1 );
			add_action( 'wfocu_offer_accepted_and_processed', array( $this, 'insert_tracking_data_in_upsell_order' ), 10, 4 );
			add_action( 'woocommerce_thankyou', array( $this, 'insert_tracking_data_from_order_meta' ), 9, 1 );
			add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_insert_pending_tracking_data' ), 9, 4 );
			add_action( 'woocommerce_order_status_processing_to_cancelled', array( $this, 'removed_cod_order_after_canceled' ), 10, 2 );

			/***
			 * conversion delete row process on order delete and fully refunded
			 */
			add_action( 'woocommerce_order_fully_refunded', array( $this, 'delete_conversion_row' ) );
			add_action( 'woocommerce_order_partially_refunded', array( $this, 'partially_refunded_process' ), 10, 2 );

			add_action( 'delete_post', array( $this, 'delete_conversion_row' ) );

			add_action( 'woocommerce_delete_order', array( $this, 'delete_conversion_row' ) );

			add_action( 'bwf_conversion_tracking_index_completed', array( $this, 'update_conversion_table' ), 10, 2 );

			add_action( 'fk_fb_every_4_minute', array( $this, 'maybe_execute_thankyou_hook' ), 999 );

			add_action( 'admin_init', array( $this, 'maybe_update_conversion_journey_column' ) );

			/**
			 * Append accepted one-click upsell offer views to the order journey.
			 */
			add_action( 'wc_ajax_wfocu_front_register_views', array( __CLASS__, 'update_upsell_journey' ) );
		}

		/**
		 * @return BWF_Ecomm_Tracking_Common|null
		 */
		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self();
			}

			return self::$ins;
		}

		public function render() {
			$this->render_js_to_track_referer();
		}

		/**
		 * Render UTM js to fire events
		 */
		public function render_js_to_track_referer() {
			$min = '.min';
			if ( defined( 'WFFN_IS_DEV' ) && true === WFFN_IS_DEV ) {
				$min = '';
			}

			if ( class_exists( 'WFFN_Common' ) ) {
				$is_preview_mode = WFFN_Common::is_page_builder_preview();
				if ( $is_preview_mode ) {
					return;
				}
			}

			/**
			 * Disable client-side journey recording on one-click upsell offer pages.
			 * The offer view is appended to the journey server-side in update_upsell_journey().
			 *
			 * WFOCU_Core() is defined at file level by the Upsells plugin, but its `public`
			 * property is only populated once load_classes()/register_classes() run, which is
			 * skipped when WooCommerce is inactive or below the minimum WP/WC version. Guard
			 * the property before calling into it.
			 */
			if ( class_exists( 'WFFN_Common' ) && WFFN_Common::wffn_is_funnel_pro_active() && function_exists( 'WFOCU_Core' ) && isset( WFOCU_Core()->public ) && is_callable( array( WFOCU_Core()->public, 'if_is_offer' ) ) && true === WFOCU_Core()->public->if_is_offer() ) {
				$this->journeyControl = 'disable';
			}

			/**
			 * On the thank-you page the journey has already been persisted onto the order,
			 * so the tracker drops the cookie to start the next journey clean.
			 */
			$is_thankyou_page = ( class_exists( 'WFFN_Core' ) && isset( WFFN_Core()->thank_you_pages ) && is_callable( array( WFFN_Core()->thank_you_pages, 'is_wfty_page' ) ) && true === WFFN_Core()->thank_you_pages->is_wfty_page() ) ? 1 : 0;

			/**
			 * Cookie domain for the tracking/journey cookies. Empty by default → the JS scopes
			 * them to the current host. To share the journey across sub-sites (e.g. a front site
			 * funnelkit.com and an account/checkout subdomain myaccount.funnelkit.com) set the
			 * WordPress COOKIE_DOMAIN constant to the shared parent (".funnelkit.com") on both
			 * sites, or hook the wffn_tracking_cookie_domain filter. A single shared cookie avoids
			 * the duplicate per-subdomain cookie that otherwise fragments the journey.
			 */
			$cookie_domain = self::get_tracking_cookie_domain();

			$data = apply_filters(
				'wffn_conversion_tracking_localize_data',
				array(
					'journeyControl'     => $this->journeyControl,
					'cookie_domain'      => $cookie_domain,
					'is_thankyou_page'   => $is_thankyou_page,
					'page_id'            => function_exists( 'get_queried_object_id' ) ? absint( get_queried_object_id() ) : 0,
					'utc_offset'         => esc_attr( $this->get_timezone_offset() ),
					'site_url'           => esc_url( site_url() ),
					'genericParamEvents' => wp_json_encode( $this->get_generic_event_params() ),
					'cookieKeys'         => array( 'flt', 'timezone', 'is_mobile', 'browser', 'fbclid', 'gclid', 'referrer', 'referrer_last', 'fl_url', 'utm_source_last', 'utm_medium_last', 'utm_campaign_last', 'utm_term_last', 'utm_content_last' ),
					'excludeDomain'      => array( 'paypal.com', 'klarna.com', 'quickpay.net' ),

				)
			);

			wp_enqueue_script(
				'wfco-utm-tracking',
				plugin_dir_url( WooFunnel_Loader::$ultimate_path ) . 'woofunnels/assets/js/utm-tracker' . $min . '.js',
				array(),
				WooFunnel_Loader::$version,
				array(
					'in_footer' => false,
					'strategy'  => 'defer',
				)
			);
			wp_localize_script( 'wfco-utm-tracking', 'wffnUtm', $data );
		}

		/**
		 * Add Generic event params to the data in events
		 *
		 * @return array
		 */
		public function get_generic_event_params() {
			$user = wp_get_current_user();
			if ( $user->ID !== 0 ) {
				$user_roles = implode( ',', $user->roles );
			} else {
				$user_roles = 'guest';
			}

			return array(
				'user_roles' => $user_roles,
				'plugin'     => 'Funnel Builder',
			);
		}


		/**
		 * Create DB tables
		 * Actions and bwf_conversion_tracking
		 */
		public function create_db_tables( $args, $tables ) {

			if ( $tables['version'] !== BWF_DB_VERSION || ! in_array( $this->conv_table, $tables['tables'], true ) ) {
				$args[] = array(
					'name'   => $this->conv_table,
					'schema' => $this->conversion_table_schema(),
				);
			}

			return $args;
		}

		/**
		 * update conversion table after migration
		 *
		 * @return void
		 */
		public function update_conversion_table() {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			global $wpdb;
			$charset_collate = '';
			if ( $wpdb->has_cap( 'collation' ) ) {
				$charset_collate = $wpdb->get_charset_collate();
			}

			$schema = $this->conversion_table_schema();
			$schema = str_replace( array( '{table_prefix}', '{table_collate}' ), array( $wpdb->prefix, $charset_collate ), $schema );

			dbDelta( $schema );
			if ( ! empty( $wpdb->last_error ) ) {
				WFFN_Core()->logger->log( 'migration process failed update table ' . $wpdb->last_error . ' last query ' . $wpdb->last_query, 'fk_conv_migration', true );
			}

			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'bwf_conversion_tracking WHERE funnel_id = %d OR  source = %d', 0, 0 ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! empty( $wpdb->last_error ) ) {
				WFFN_Core()->logger->log( 'migration process failed optimised referrer table ' . $wpdb->last_error . ' last query ' . $wpdb->last_query, 'fk_conv_migration', true );

			}

			if ( function_exists( 'wffn_update_currency_switcher_data' ) ) {
				wffn_update_currency_switcher_data();
			}
		}

		/**
		 * Filter referrer to be saved inside the database
		 *
		 * @param string $url
		 *
		 * @return string
		 */
		public function filter_referrer( $url ) {
			$domain = get_site_url();

			$url    = str_replace( array( 'http://', 'https://' ), '', $url );
			$domain = str_replace( array( 'http://', 'https://' ), '', $domain );

			/**
			 * if its a same site referrer then return empty
			 */
			if ( false !== strpos( $url, $domain ) ) {
				return '';
			}

			/**
			 * Remove trailing slash from the end of the url
			 */
			$url = rtrim( $url, '/' );

			return $this->parse_url_query_param( $url );
		}

		/**
		 * One-time conversion-table `journey` column migration.
		 *
		 * Hooked on admin_init and gated by the `wffn_conversion_tracking_db_updater`
		 * option (default 1.0). While below 1.1 it adds the `journey` column when missing
		 * (matching the conversion table schema — nullable longtext) and then bumps the
		 * option, so the schema lookup/ALTER runs once; every later request is a single
		 * autoloaded option read. The option is only advanced once the column is confirmed
		 * present, so a failed ALTER is retried on the next request.
		 *
		 * @return void
		 */
		public function maybe_update_conversion_journey_column() {
			if ( version_compare( get_option( 'wffn_conversion_tracking_db_updater', '1.0' ), '1.1', '>=' ) ) {
				return;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . $this->conv_table;
			$is_col     = $wpdb->get_col( $wpdb->prepare( "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND table_name = %s AND column_name = 'journey'", $wpdb->dbname, $table_name ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( empty( $is_col ) ) {
				$wpdb->query( "ALTER TABLE `{$table_name}` ADD `journey` longtext AFTER `referrer`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time schema migration on plugin table; name from prefix + const
				if ( ! empty( $wpdb->last_error ) ) {
					return;
				}
			}

			update_option( 'wffn_conversion_tracking_db_updater', '1.1', true );
		}

		public function conversion_table_schema() {
			$max_index_length = 191;
			$blank            = "NOT NULL DEFAULT ''";

			return 'CREATE TABLE `{table_prefix}' . $this->conv_table . "` (
						`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						`contact_id` bigint(20) unsigned NOT NULL default 0,
						`funnel_id` bigint(20) unsigned NOT NULL default 0,
						`step_id` bigint(20) unsigned NOT NULL default 0,
						`source_id` bigint(20) unsigned NOT NULL default 0 COMMENT 'save checkout revenue source',
						`automation_id` bigint(20) unsigned NOT NULL default 0,
						`source` bigint(20) unsigned NOT NULL default 0,
						`order_number` varchar(100) NOT NULL DEFAULT '' COMMENT 'stores sequential/custom order number',
						`type` tinyint(2) unsigned COMMENT '1- optin 2- wc_order 3- edd_order',
						`country` char(2),
						`value` double DEFAULT 0 NOT NULL,
						`checkout_total` double DEFAULT 0 NOT NULL,
						`bump_total` double DEFAULT 0 NOT NULL,
						`offer_total` double DEFAULT 0 NOT NULL,
						`bump_accepted` varchar(255)" . $blank . ',
						`bump_rejected` varchar(255) ' . $blank . ',
						`offer_accepted` varchar(255) ' . $blank . ',
						`offer_rejected` varchar(255) ' . $blank . ',
						`first_click` DateTime NOT NULL,
						`device` varchar(100) ' . $blank . ',
						`browser` varchar(100) ' . $blank . ',
						`first_landing_url` varchar(255) ' . $blank . ',
						`referrer` varchar(255) ' . $blank . ',
						`utm_source` varchar(255) ' . $blank . ',
						`utm_medium` varchar(255) ' . $blank . ',
						`utm_campaign` varchar(255) ' . $blank . ',
						`utm_term` varchar(255) ' . $blank . ',
						`utm_content` varchar(255) ' . $blank . ',
						`referrer_last` varchar(255) ' . $blank . ',
						`utm_source_last` varchar(255) ' . $blank . ',
						`utm_medium_last` varchar(255) ' . $blank . ',
						`utm_campaign_last` varchar(255) ' . $blank . ',
						`utm_term_last` varchar(255) ' . $blank . ',
						`utm_content_last` varchar(255) ' . $blank . ',
						`click_id` varchar(255) ' . $blank . ",
						`journey` longtext,
						`timestamp` DateTime NOT NULL,
						PRIMARY KEY (`id`),
						KEY `id` (`id`),
						KEY `step_id` (`step_id`),
						KEY `funnel_id` (`funnel_id`),
						KEY `contact_id` (`contact_id`),
						KEY `source_id` (`source_id`),
						KEY `utm_source` (utm_source($max_index_length)),
						KEY `utm_medium` (utm_medium($max_index_length)),
						KEY `utm_campaign` (utm_campaign($max_index_length)),
						KEY `utm_term` (utm_term($max_index_length)),
						KEY `utm_content` (utm_content($max_index_length)),
						KEY `utm_source_last` (utm_source_last($max_index_length)),
						KEY `utm_medium_last` (utm_medium_last($max_index_length)),
						KEY `utm_campaign_last` (utm_campaign_last($max_index_length)),
						KEY `utm_term_last` (utm_term_last($max_index_length)),
						KEY `utm_content_last` (utm_content_last($max_index_length)),
						KEY `bump_accepted` (`bump_accepted`),
						KEY `bump_rejected` (`bump_rejected`),
						KEY `offer_accepted` (`offer_accepted`),
						KEY `offer_rejected` (`offer_rejected`),
						KEY `value` (`value`),
						KEY `source` (`source`),
						KEY `order_number` (`order_number`),
						KEY `first_landing_url` (`first_landing_url`),
						KEY `referrer` (`referrer`),
						KEY `referrer_last` (`referrer_last`),
						KEY `timestamp` (`timestamp`)
						) {table_collate};";
		}

		/**
		 * @param $optin_id
		 * @param $posted_data
		 *
		 * @return void
		 */
		public function update_optin_tracking_data( $optin_id, $posted_data ) {
			$get_data = $this->get_common_tracking_data( true );

			$funnel_id = get_post_meta( $optin_id, '_bwf_in_funnel', true );

			$args = array(
				'contact_id'     => ! empty( $posted_data['cid'] ) ? $posted_data['cid'] : 0,
				'type'           => 1,
				'value'          => 0,
				'step_id'        => $optin_id,
				'funnel_id'      => ! ( empty( $funnel_id ) ) ? $funnel_id : 0,
				'automation_id'  => 0,
				'source'         => isset( $posted_data['optin_entry_id'] ) ? $posted_data['optin_entry_id'] : 0,
				'country'        => isset( $get_data['country'] ) ? $get_data['country'] : '',
				'timestamp'      => current_time( 'mysql' ), //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				'checkout_total' => 0,
				'bump_total'     => 0,
				'offer_total'    => 0,
				'bump_accepted'  => '',
				'bump_rejected'  => '',
				'offer_accepted' => '',
				'offer_rejected' => '',
			);

			$get_data = array_merge( $get_data, $args );

			$this->insert_tracking_data( $get_data );
		}

		/**
		 * @param $order
		 *
		 * @return void
		 */
		public function add_tracking_data_in_order_meta( $order ) {
			$order = apply_filters( 'bwf_tracking_insert_order', $order );

			if ( ! $order instanceof WC_Order ) {
				return;
			}
			/** save tracking data in order meta data */

			$get_data = $this->get_common_tracking_data();
			$order->update_meta_data( '_wffn_tracking_data', $get_data );
		}

		/**
		 * @param $offer_id
		 * @param $package
		 * @param WC_Order $parent_order
		 * @param $new_order
		 *
		 *  insert tracking data in newly created upsell order
		 *
		 * @return void
		 */
		public function insert_tracking_data_in_upsell_order( $offer_id, $package, $parent_order, $new_order ) {
			// Handle case if primary order already processed
			if ( in_array( $parent_order->get_status(), wc_get_is_paid_statuses(), true ) ) {
				global $wpdb;
				$get_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$this->conv_table} WHERE type = %d AND source = %d", 2, $parent_order->get_id() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix + private constant prop; values bound via %d

				if ( $get_data ) {

					$get_data['offer_total']    = ! empty( $get_data['offer_total'] ) ? floatval( $get_data['offer_total'] ) + floatval( $this->get_price_value_for_db( $package['total'], $parent_order->get_currency() ) ?? 0 ) : ( $this->get_price_value_for_db( $package['total'], $parent_order->get_currency() ) ?? 0 );
					$accepted_offer             = json_decode( $get_data['offer_accepted'] ?? '[]', true );
					$accepted_offer[]           = (string) $offer_id;
					$get_data['offer_accepted'] = wp_json_encode( $accepted_offer );

					if ( ! empty( $new_order ) && is_object( $new_order ) ) {
						unset( $get_data['id'] );
						$upsell_id = get_post_meta( $offer_id, '_funnel_id', true );
						$funnel_id = get_post_meta( $upsell_id, '_bwf_in_funnel', true );

						$get_data['funnel_id']      = $funnel_id;
						$get_data['step_id']        = 0;
						$get_data['value']          = ! empty( $package['total'] ) ? $this->get_price_value_for_db( $package['total'], $parent_order->get_currency() ) : 0;
						$get_data['source']         = $new_order->get_id();
						$get_data['checkout_total'] = 0;
						$get_data['bump_total']     = 0;
						$get_data['bump_accepted']  = '';
						$get_data['bump_rejected']  = '';
						$get_data['offer_total']    = ! empty( $package['total'] ) ? $this->get_price_value_for_db( $package['total'], $parent_order->get_currency() ) : 0;
						$get_data['offer_accepted'] = wp_json_encode( array( (string) $offer_id ) );
						$get_data['offer_rejected'] = '';
						$get_data['timestamp']      = current_time( 'mysql' );
						$this->insert_tracking_data( $get_data );
					} else {

						/**
						 * Case of exception where its a case of batching but primary order already normalised when upsell accepts
						 */
						$get_data['value'] = $this->get_price_value_for_db( $parent_order->get_total(), $parent_order->get_currency() );
						/**
						 * Copy data form parent row and modify and insert offer data
						 */
						$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpdb->update on plugin conversion table; parameterized by core
							"{$wpdb->prefix}{$this->conv_table}",
							$get_data,
							array(
								'type'   => 2,
								'source' => $parent_order->get_id(),
							)
						); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					}

					return;
				}
			}

			if ( empty( $new_order ) || ! is_object( $new_order ) ) {
				return;
			}

			$tracking_data = BWF_WC_Compatibility::get_order_meta( $parent_order, '_wffn_tracking_data' );

			/**
			 * 1. Handle case when upsell parent order not have tracking meta
			 * 2. And second case upsell parent order not have any record in conversion table
			 * In both case upsell create new row in conversion table
			 */
			if ( in_array( $parent_order->get_status(), wc_get_is_paid_statuses(), true ) && empty( $tracking_data ) ) {
				$tracking_data = $this->get_common_tracking_data();
			}

			if ( empty( $tracking_data ) || ! is_array( $tracking_data ) ) {
				return;
			}

			$date_created = $new_order->get_date_created();
			if ( ! empty( $date_created ) ) {
				$timezone = new DateTimeZone( wp_timezone_string() );
				$date_created->setTimezone( $timezone );
				$date_created = $date_created->format( 'Y-m-d H:i:s' );
			}

			$upsell_id = get_post_meta( $offer_id, '_funnel_id', true );
			$funnel_id = get_post_meta( $upsell_id, '_bwf_in_funnel', true );

			if ( 0 === absint( $funnel_id ) ) {
				return;
			}

			$source_id = class_exists( 'WFFN_Core' ) ? WFFN_Core()->data->get( 'source_id', 0 ) : 0;

			$args = array(
				'contact_id'     => ! empty( $parent_order->get_meta( '_woofunnel_cid' ) ) ? $parent_order->get_meta( '_woofunnel_cid' ) : 0,
				'type'           => 2,
				'value'          => ! empty( $package['total'] ) ? $this->get_price_value_for_db( $package['total'], $parent_order->get_currency() ) : 0,
				'step_id'        => 0,
				'funnel_id'      => $funnel_id,
				'automation_id'  => 0,
				'source'         => $new_order->get_id(),
				'order_number'   => $new_order->get_order_number(),
				'country'        => $new_order->get_billing_country(),
				'timestamp'      => empty( $date_created ) ? current_time( 'mysql' ) : $date_created,
				'checkout_total' => 0,
				'bump_total'     => 0,
				'offer_total'    => ! empty( $package['total'] ) ? $this->get_price_value_for_db( $package['total'], $parent_order->get_currency() ) : 0,
				'bump_accepted'  => '',
				'bump_rejected'  => '',
				'offer_accepted' => wp_json_encode( array( (string) $offer_id ) ),
				'offer_rejected' => '',
				'source_id'      => $source_id,
			);

			$tracking_data = array_merge( $tracking_data, $args );

			if ( ! in_array( $new_order->get_status(), wc_get_is_paid_statuses(), true ) ) {
				$new_order->update_meta_data( '_wffn_tracking_data', $tracking_data );
				$new_order->update_meta_data( '_wffn_need_normalize', 'yes' );
				$new_order->update_meta_data( '_wfocu_offer_id', $offer_id );
				$new_order->save_meta_data();

				return;
			}

			// Insert data
			$this->insert_tracking_data( $tracking_data );
		}

		/**
		 * @param $order_id
		 *
		 * @return false|void
		 */
		public function insert_tracking_data_from_order_meta( $order_id ) {
			$order = apply_filters( 'bwf_tracking_insert_order', wc_get_order( $order_id ) );

			if ( ! $order instanceof WC_Order || $this->is_order_renewal( $order ) ) {
				return;
			}

			/**
			 * Prevent duplicate insertion for ipn gateway and handel by order status changed
			 * or data not insert if order status not paid
			 */
			$payment_method = $order->get_payment_method();
			if ( in_array( $payment_method, $this->get_ipn_gateways(), true ) || ! in_array( $order->get_status(), wc_get_is_paid_statuses(), true ) ) {
				$order->update_meta_data( '_wffn_need_normalize', 'yes' );
				$order->save_meta_data();

				return false;
			}

			$tracking_data = BWF_WC_Compatibility::get_order_meta( $order, '_wffn_tracking_data' );

			if ( ! empty( $tracking_data ) && is_array( $tracking_data ) ) {
				$this->insert_tracking_order( $order, $tracking_data );
			}
		}

		/**
		 *  Insert tracking data on change order status which is not
		 *  insert on thankyou hook due to order status not paid
		 *
		 * @param $order_id
		 * @param $from
		 * @param $to
		 * @param $order
		 *
		 * @return false|void
		 */
		public function maybe_insert_pending_tracking_data( $order_id, $from, $to, $order ) {

			if ( ! class_exists( 'WFACP_Core' ) ) {
				return false;
			}

			if ( in_array( $from, wc_get_is_paid_statuses(), true ) ) {
				return false;
			}

			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$payment_method = $order->get_payment_method();

			/**
			 * If this is a renewal order then delete the meta if exists and return straight away
			 */
			if ( $this->is_order_renewal( $order ) ) {
				return false;
			}

			$tracking_data = BWF_WC_Compatibility::get_order_meta( $order, '_wffn_tracking_data' );

			if ( empty( $tracking_data ) || ! is_array( $tracking_data ) ) {
				return false;
			}

			$ipn_gateways = $this->get_ipn_gateways();

			/**
			 * condition1 : if one of IPN gateways
			 * condition2: Thankyou page hook with pending status ran on this order
			 * condition3: In case thankyou page not open and order mark complete by IPN
			 */
			if ( in_array( $payment_method, $ipn_gateways, true ) || 'yes' === BWF_WC_Compatibility::get_order_meta( $order, '_wffn_need_normalize' ) || ( class_exists( 'WC_Geolocation' ) && ( $order->get_customer_ip_address() !== WC_Geolocation::get_ip_address() ) ) ) {
				/**
				 * reaching this code means, 1) we have a ipn gateway OR 2) we have meta stored during thankyou
				 */
				if ( $order_id > 0 && in_array( $to, wc_get_is_paid_statuses(), true ) ) {
					$this->insert_data_without_thankyou( $order, $tracking_data );

				}
			}
		}

		/**
		 * Remove cod order from analytics if it marked cancelled
		 *
		 * @param $order_id
		 * @param $from
		 * @param $to
		 * @param $order
		 *
		 * @return void
		 */
		public function removed_cod_order_after_canceled( $order_id, $order ) {

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$payment_method = $order->get_payment_method();

			if ( 'cod' !== $payment_method ) {
				return;
			}

			/**
			 * delete order data from conversion table
			 */
			$this->delete_conversion_row( $order_id );

			if ( class_exists( 'WFACP_Core' ) ) {
				WFACP_Core()->reporting->delete_report_for_order( $order_id );
			}

			if ( class_exists( 'WFOB_Reporting' ) ) {
				WFOB_Reporting::get_instance()->delete_report_for_order( $order_id );
			}

			if ( class_exists( 'WFOCU_Core' ) ) {
				WFOCU_Core()->admin->clear_session_record_on_shop_order_delete( $order_id );
			}
		}

		/**
		 * @param WC_Order $order
		 * @param $tracking_data
		 *
		 * @return void
		 * @throws DateInvalidTimeZoneException
		 */
		public function insert_tracking_order( $order, $tracking_data ) {//phpcs:ignore WordPressVIPMinimum.Hooks.AlwaysReturnInFilter.MissingReturnStatement
			/**
			 * prepare checkout data for insert
			 */
			$wfacp_report_data = BWF_WC_Compatibility::get_order_meta( $order, '_wfacp_report_data' );
			$step_id           = BWF_WC_Compatibility::get_order_meta( $order, '_wfacp_post_id' );
			$funnel_id         = get_post_meta( $step_id, '_bwf_in_funnel', true );

			// Cart checkout: no wfacp_id. Use store checkout first checkout if configured.
			if ( empty( $step_id ) && class_exists( 'WFFN_Common' ) && ( $store_id = WFFN_Common::get_store_checkout_id() ) > 0 && true === wffn_string_to_bool( WFFN_Core()->get_dB()->get_meta( $store_id, 'status' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
				$funnel = new WFFN_Funnel( $store_id );
				if ( wffn_is_valid_funnel( $funnel ) ) {
					foreach ( $funnel->get_steps() as $step ) {
						if ( isset( $step['type'] ) && 'wc_checkout' === $step['type'] && ! empty( $step['id'] ) && 'publish' === get_post_status( $step['id'] ) ) {
							$step_id   = $step['id'];
							$funnel_id = get_post_meta( $step_id, '_bwf_in_funnel', true );
							break;
						}
					}
					if ( empty( $step_id ) ) {
						$step_id   = apply_filters( 'wfacp_global_checkout_page_id', 0 );
						$funnel_id = $store_id;
					}
				}
			}

			$cid            = BWF_WC_Compatibility::get_order_meta( $order, '_woofunnel_cid' );
			$checkout_total = ( is_array( $wfacp_report_data ) && isset( $wfacp_report_data['wfacp_total'] ) ) ? abs( $wfacp_report_data['wfacp_total'] ) : 0;
			if ( empty( $checkout_total ) && ! empty( $step_id ) ) {
				$checkout_total = $this->get_price_value_for_db( $order->get_total(), $order->get_currency() );
			}

			/**
			 * prepare bump data for insert
			 */
			$bump_data     = BWF_WC_Compatibility::get_order_meta( $order, '_wfob_report_data' );
			$bump_accepted = array();
			$bump_rejected = array();
			$bump_total    = 0;

			if ( is_array( $bump_data ) && count( $bump_data ) > 0 ) {
				foreach ( $bump_data as $id => $b_item ) {
					if ( 1 === absint( $b_item['converted'] ) ) {
						$bump_total      = floatval( $bump_total ) + floatval( $b_item['total'] );
						$bump_accepted[] = (string) $id;
					} else {
						$bump_rejected[] = (string) $id;
					}
				}
			}

			$date_created = $order->get_date_created();
			if ( ! empty( $date_created ) ) {
				$timezone = new DateTimeZone( wp_timezone_string() );
				$date_created->setTimezone( $timezone );
				$date_created = $date_created->format( 'Y-m-d H:i:s' );
			}

			$args = array(
				'contact_id'     => ! ( empty( $cid ) ) ? $cid : 0,
				'type'           => 2,
				'value'          => $this->get_price_value_for_db( $order->get_total(), $order->get_currency() ),
				'step_id'        => ! ( empty( $step_id ) ) ? $step_id : 0,
				'funnel_id'      => ! ( empty( $funnel_id ) ) ? $funnel_id : 0,
				'automation_id'  => 0,
				'source'         => $order->get_id(),
				'order_number'   => $order->get_order_number(),
				'country'        => $order->get_billing_country(),
				'timestamp'      => empty( $date_created ) ? current_time( 'mysql' ) : $date_created,
				'checkout_total' => $checkout_total,
				'bump_total'     => $bump_total,
				'offer_total'    => 0,
				'bump_accepted'  => ! empty( $bump_accepted ) ? wp_json_encode( $bump_accepted ) : '',
				'bump_rejected'  => ! empty( $bump_rejected ) ? wp_json_encode( $bump_rejected ) : '',
				'offer_accepted' => '',
				'offer_rejected' => '',
			);

			$tracking_data = array_merge( $tracking_data, $args );

			$tracking_data = $this->maybe_get_offer_data( $tracking_data, $order );

			if ( empty( $tracking_data['funnel_id'] ) && ( ! class_exists( 'WFFN_Common' ) || ! method_exists( 'WFFN_Common', 'get_store_checkout_id' ) || 0 === WFFN_Common::get_store_checkout_id() || false === wffn_string_to_bool( WFFN_Core()->get_dB()->get_meta( WFFN_Common::get_store_checkout_id(), 'status' ) ) ) ) {
				// No valid funnel ID found, set to 0 and return
				$tracking_data['funnel_id'] = 0;

				// Order is not attributable to any funnel -> nothing to insert. Remove the meta so
				// it stops re-qualifying for this recovery query on every run.
				$order->delete_meta_data( '_wffn_tracking_data' );
				$order->save();
				return;
			}

			// If we are here, it means we have a potential funnel ID
			if ( empty( $tracking_data['funnel_id'] ) ) {
				$funnel = new WFFN_Funnel( WFFN_Common::get_store_checkout_id() );

				if ( ! wffn_is_valid_funnel( $funnel ) || false === $funnel->is_funnel_has_native_checkout() ) {
					// Invalid funnel, set to 0 and return
					$tracking_data['funnel_id'] = 0;

					$order->delete_meta_data( '_wffn_tracking_data' );
					$order->save();
					return;
				}

				// Valid funnel, assign the ID
				$tracking_data['funnel_id'] = WFFN_Common::get_store_checkout_id();
			}

			/**
			 * returning from here implies that we do not find any funnel ID to process any further
			 * And in this case we no longer need to insert the tracking data
			 */
			if ( empty( $tracking_data['funnel_id'] ) ) {
				$order->delete_meta_data( '_wffn_tracking_data' );
				$order->save();
				return;
			}

			/**
			 * Append the current (thank-you) page to the journey before persisting.
			 * No-op when not rendered on a page (e.g. cron/IPN recovery).
			 */
			$tracking_data = $this->maybe_add_thankyou_data( $tracking_data );

			/** Insert data */
			$lastId = $this->insert_tracking_data( $tracking_data );

			if ( intval( $lastId ) > 0 ) {
				/** Carry the journey across to any sibling/upsell order rows. */
				$this->update_upsell_order_journey_data( $order, $tracking_data );
				$order->delete_meta_data( '_wffn_tracking_data' );
				$order->save();
			}
		}

		/**
		 * Shared cookie/journey domain across sub-sites. Empty by default → cookies and
		 * journey URLs are scoped to the current host (single-site). Non-empty (set via the
		 * COOKIE_DOMAIN constant or the wffn_tracking_cookie_domain filter) means the journey
		 * cookie is shared across sub-sites, so each journey entry must store an absolute URL
		 * to keep its real host — see the journey writers below.
		 *
		 * @return string
		 */
		public static function get_tracking_cookie_domain() {
			$cookie_domain = ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';

			return apply_filters( 'wffn_tracking_cookie_domain', $cookie_domain );
		}

		/**
		 * Normalize any decoded journey value into the v2 { j, s } shape.
		 * Legacy flat journeys ({ ts => { u,t,i } }) are wrapped as { j: <legacy>, s: [] }.
		 *
		 * @param mixed $data
		 * @return array
		 */
		public static function journey_normalize( $data ) {
			if ( is_array( $data ) && isset( $data['j'] ) && is_array( $data['j'] ) ) {
				if ( ! isset( $data['s'] ) || ! is_array( $data['s'] ) ) {
					$data['s'] = array();
				}
				return $data;
			}
			return array( 'j' => is_array( $data ) ? $data : array(), 's' => array() );
		}

		/**
		 * Index of $origin in $store['s'], assigning the next integer (from 1) if absent.
		 *
		 * @param array  $store passed by reference; its 's' map may be extended.
		 * @param string $origin scheme://host
		 * @return int
		 */
		public static function journey_site_index( &$store, $origin ) {
			foreach ( $store['s'] as $idx => $val ) {
				if ( $val === $origin ) {
					return (int) $idx;
				}
			}
			$next = 1;
			foreach ( array_keys( $store['s'] ) as $idx ) {
				if ( (int) $idx >= $next ) {
					$next = (int) $idx + 1;
				}
			}
			$store['s'][ $next ] = $origin;
			return $next;
		}

		/**
		 * Origin (scheme://host[:port]) of the current site.
		 *
		 * @return string
		 */
		public static function site_origin() {
			$parts  = wp_parse_url( home_url() );
			$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
			$host   = isset( $parts['host'] ) ? $parts['host'] : '';
			$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
			return $scheme . '://' . $host . $port;
		}

		/**
		 * Reconstruct a full URL for a journey entry across the three supported shapes.
		 *
		 * @param string $path     decoded relative-or-absolute path from entry['u']
		 * @param mixed  $site_idx entry['s'] (v2) or null
		 * @param array  $sites    the journey 's' map
		 * @param string $home_url home_url('/') of the rendering site
		 * @return string
		 */
		public static function journey_resolve_url( $path, $site_idx, $sites, $home_url ) {
			if ( null !== $site_idx && isset( $sites[ $site_idx ] ) ) {
				return rtrim( $sites[ $site_idx ], '/' ) . '/' . ltrim( $path, '/' );
			}
			if ( 0 === strpos( $path, 'http' ) ) {
				return $path;
			}
			return rtrim( $home_url, '/' ) . '/' . ltrim( $path, '/' );
		}

		/**
		 * Append the one-click upsell offer view to the parent order journey.
		 *
		 * Hooked on wc_ajax_wfocu_front_register_views; the offer page is recorded
		 * server-side because client journey tracking is disabled on offer pages.
		 *
		 * @return void
		 */
		public static function update_upsell_journey() {
			if ( ! isset( $_POST['data'] ) || ! function_exists( 'WFOCU_Core' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
				return;
			}

			$get_order = WFOCU_Core()->data->get_parent_order();
			if ( ! $get_order instanceof WC_Order ) {
				return;
			}

			$tracking_data = BWF_WC_Compatibility::get_order_meta( $get_order, '_wffn_tracking_data' );
			if ( empty( $tracking_data ) || ! is_array( $tracking_data ) || empty( $tracking_data['journey'] ) ) {
				return;
			}

			$get_current_offer = WFOCU_Core()->data->get_current_offer();
			$link              = WFOCU_Core()->offers->get_the_link( $get_current_offer );
			$parsed_url        = wp_parse_url( $link );
			$relative_path     = ( is_array( $parsed_url ) && ! empty( $parsed_url['path'] ) ) ? ltrim( $parsed_url['path'], '/' ) : '';

			$data     = wc_clean( wp_unslash( $_POST['data'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() sanitizes recursively
			$products = ( isset( $data['products'] ) && is_array( $data['products'] ) ) ? array_values( $data['products'] ) : array();
			$name     = ( ! empty( $products ) && isset( $products[0]['name'] ) ) ? $products[0]['name'] : '';

			$store  = self::journey_normalize( json_decode( $tracking_data['journey'], true ) );
			$origin = self::site_origin();
			$idx    = self::journey_site_index( $store, $origin );

			$store['j'][ (int) round( microtime( true ) * 1000 ) ] = array(
				'u' => '/' . ltrim( $relative_path, '/' ),
				't' => $name,
				'i' => $get_current_offer,
				's' => $idx,
			);

			$tracking_data['journey'] = wp_json_encode( $store, JSON_UNESCAPED_SLASHES );
			$get_order->update_meta_data( '_wffn_tracking_data', $tracking_data );
			$get_order->save_meta_data();
		}

		/**
		 * Copy the journey onto the conversion rows of sibling/upsell orders so the
		 * order metabox shows the full path on those orders too.
		 *
		 * @param WC_Order $order
		 * @param array    $tracking_data
		 * @param bool     $upsell_order whether $order itself is an upsell order
		 *
		 * @return void
		 */
		public function update_upsell_order_journey_data( $order, $tracking_data, $upsell_order = false ) {
			if ( ! $order instanceof WC_Order || ! is_array( $tracking_data ) || ! isset( $tracking_data['journey'] ) ) {
				return;
			}

			$orders   = ( true === $upsell_order ) ? array( $order->get_id() ) : array();
			$get_meta = $order->get_meta( '_wfocu_sibling_order', false );
			if ( is_array( $get_meta ) && ! empty( $get_meta ) ) {
				foreach ( $get_meta as $meta ) {
					$value = $meta->get_data()['value'];
					$orders[] = ( $value instanceof WC_Order ) ? $value->get_id() : absint( $value );
				}
			}

			if ( 0 === count( $orders ) ) {
				return;
			}

			global $wpdb;
			foreach ( $orders as $order_id ) {
				$wpdb->update( $wpdb->prefix . $this->conv_table, array( 'journey' => $tracking_data['journey'] ), array( 'type' => 2, 'source' => $order_id ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}

		/**
		 * Append the current page (thank-you page) to the journey.
		 * Only runs while rendering a page; a no-op during cron/background recovery.
		 *
		 * @param array $tracking_data
		 *
		 * @return array
		 */
		public function maybe_add_thankyou_data( $tracking_data ) {
			if ( ! is_array( $tracking_data ) || empty( $tracking_data['journey'] ) ) {
				return $tracking_data;
			}
			if ( ! function_exists( 'get_the_ID' ) || empty( get_the_ID() ) ) {
				return $tracking_data;
			}

			$store  = self::journey_normalize( json_decode( $tracking_data['journey'], true ) );
			$origin = self::site_origin();
			$idx    = self::journey_site_index( $store, $origin );

			$store['j'][ (int) round( microtime( true ) * 1000 ) ] = array(
				'u' => '/' . ltrim( substr( get_permalink(), strlen( home_url( '/' ) ) ), '/' ),
				't' => get_the_title(),
				'i' => get_the_ID(),
				's' => $idx,
			);

			$tracking_data['journey'] = wp_json_encode( $store, JSON_UNESCAPED_SLASHES );

			return $tracking_data;
		}

		public function get_common_tracking_data( $is_optin = false ) {
			$click_id = '';
			$get_data = $_COOKIE; //phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
			if ( isset( $get_data['wffn_fbclid'] ) ) {
				$click_id = $get_data['wffn_fbclid'];
			} elseif ( isset( $get_data['wffn_gclid'] ) ) {
				$click_id = $get_data['wffn_gclid'];
			}

			/**
			 * Insert step landing and optin source id for checkout revenue
			 * Source id insert only for create order row on optin submit is always insert 0
			 */
			$source_id = class_exists( 'WFFN_Core' ) ? WFFN_Core()->data->get( 'source_id', 0 ) : 0;

			/**
			 * The journey cookie holds the page-by-page path as JSON. The browser stores it
			 * with addslashes() applied (see utm-tracker.js), so strip those before decoding.
			 * Stored in the existing `journey` column — no schema change.
			 */
			$journey_data = isset( $get_data['wffn_journey'] ) ? json_decode( stripcslashes( $get_data['wffn_journey'] ), true ) : array();
			$journey_data = is_array( $journey_data ) ? $journey_data : array();
			$journey_data = self::journey_normalize( $journey_data );

			$args = array(
				'utm_source'        => isset( $get_data['wffn_utm_source'] ) ? $this->string_length( $this->strip_emojis( bwf_clean( $get_data['wffn_utm_source'] ) ) ) : '',
				'utm_medium'        => isset( $get_data['wffn_utm_medium'] ) ? $this->string_length( $this->strip_emojis( bwf_clean( $get_data['wffn_utm_medium'] ) ) ) : '',
				'utm_campaign'      => isset( $get_data['wffn_utm_campaign'] ) ? $this->string_length( $this->strip_emojis( bwf_clean( $get_data['wffn_utm_campaign'] ) ) ) : '',
				'utm_term'          => isset( $get_data['wffn_utm_term'] ) ? $this->string_length( $this->strip_emojis( bwf_clean( $get_data['wffn_utm_term'] ) ) ) : '',
				'utm_content'       => isset( $get_data['wffn_utm_content'] ) ? $this->string_length( $this->strip_emojis( bwf_clean( $get_data['wffn_utm_content'] ) ) ) : '',
				'utm_source_last'   => isset( $get_data['wffn_utm_source_last'] ) ? $this->string_length( $this->strip_emojis( bwf_clean( $get_data['wffn_utm_source_last'] ) ) ) : '',
				'utm_medium_last'   => isset( $get_data['wffn_utm_medium_last'] ) ? $this->string_length( $this->strip_emojis( bwf_clean( $get_data['wffn_utm_medium_last'] ) ) ) : '',
				'utm_campaign_last' => isset( $get_data['wffn_utm_campaign_last'] ) ? $this->string_length( $this->strip_emojis( bwf_clean( $get_data['wffn_utm_campaign_last'] ) ) ) : '',
				'utm_term_last'     => isset( $get_data['wffn_utm_term_last'] ) ? $this->string_length( $this->strip_emojis( bwf_clean( $get_data['wffn_utm_term_last'] ) ) ) : '',
				'utm_content_last'  => isset( $get_data['wffn_utm_content_last'] ) ? $this->string_length( $this->strip_emojis( bwf_clean( $get_data['wffn_utm_content_last'] ) ) ) : '',
				'first_landing_url' => isset( $get_data['wffn_fl_url'] ) ? bwf_clean( $get_data['wffn_fl_url'] ) : '',
				'browser'           => isset( $get_data['wffn_browser'] ) ? bwf_clean( $get_data['wffn_browser'] ) : '',
				'first_click'       => isset( $get_data['wffn_flt'] ) ? bwf_clean( $get_data['wffn_flt'] ) : '',
				'device'            => isset( $get_data['wffn_is_mobile'] ) ? ( true === bwf_string_to_bool( $get_data['wffn_is_mobile'] ) ? 'mobile' : 'desktop' ) : '',
				'click_id'          => $click_id,
				'referrer'          => isset( $get_data['wffn_referrer'] ) ? $this->filter_referrer( $get_data['wffn_referrer'] ) : '',
				'referrer_last'     => isset( $get_data['wffn_referrer_last'] ) ? $this->filter_referrer( $get_data['wffn_referrer_last'] ) : '',
				'journey'           => ( ! empty( $journey_data['j'] ) ) ? wp_json_encode( $journey_data, JSON_UNESCAPED_SLASHES ) : '',
				'source_id'         => $source_id,
			);

			if ( true === $is_optin ) {
				$timezone        = isset( $get_data['wffn_timezone'] ) ? $this->string_length( bwf_clean( $get_data['wffn_timezone'] ) ) : '';
				$country_data    = $this->get_country_and_timezone( $timezone );
				$args['country'] = ( is_array( $country_data ) && isset( $country_data['country_code'] ) ) ? $country_data['country_code'] : '';
			}

			return $args;
		}

		/**
		 * Get referrer domain based on known UTM sources
		 *
		 * This method maps common UTM sources to their corresponding referrer domains.
		 * It helps populate the referrer field when document.referrer is unavailable
		 * but we have UTM source information from tracking parameters.
		 *
		 * @param string $utm_source The UTM source parameter
		 *
		 * @return string The corresponding referrer domain or empty string if no match
		 */
		public function get_referrer_from_utm_source( $utm_source ) {
			if ( empty( $utm_source ) ) {
				return '';
			}

			$utm_source = strtolower( trim( $utm_source ) );

			// Map of known UTM sources to their referrer domains (only known URLs)
			// Split into short keys (≤4 letters) and long keys (>4 letters) for different matching logic
			$utm_to_referrer_map_short = array(
				// Short keys (≤4 letters): Allow partial matches with delimiters
				'fb'    => 'facebook.com',
				'ig'    => 'instagram.com',
				'tw'    => 'twitter.com',
				'yt'    => 'youtube.com',
				'pin'   => 'pinterest.com',
				'snap'  => 'snapchat.com',
				'gclid' => 'google.com',
				'bing'  => 'bing.com',
			);

			$utm_to_referrer_map_long = array(
				// Long keys (>4 letters): Must be exact whole word match
				'google'     => 'google.com',
				'googleads'  => 'google.com',
				'facebook'   => 'facebook.com',
				'fbclid'     => 'facebook.com',
				'instagram'  => 'instagram.com',
				'twitter'    => 'twitter.com',
				'linkedin'   => 'linkedin.com',
				'youtube'    => 'youtube.com',
				'tiktok'     => 'tiktok.com',
				'pinterest'  => 'pinterest.com',
				'snapchat'   => 'snapchat.com',
				'reddit'     => 'reddit.com',
				'quora'      => 'quora.com',
				'yahoo'      => 'yahoo.com',
				'duckduckgo' => 'duckduckgo.com',
				'whatsapp'   => 'whatsapp.com',
			);

			// Check for exact match first in both maps
			if ( isset( $utm_to_referrer_map_short[ $utm_source ] ) ) {
				return $utm_to_referrer_map_short[ $utm_source ];
			}
			if ( isset( $utm_to_referrer_map_long[ $utm_source ] ) ) {
				return $utm_to_referrer_map_long[ $utm_source ];
			}

			// For short keys (≤4 letters): Allow partial matches with delimiters
			// Match if key appears at start, end, or surrounded by delimiters (hyphen, underscore, space, or word boundary)
			// This prevents false matches (e.g., "activecampaign" matching "ig") while allowing valid cases like "fb_mob"
			foreach ( $utm_to_referrer_map_short as $utm_key => $referrer ) {
				$escaped_key = preg_quote( $utm_key, '/' );
				// Match: (start of string OR delimiter OR word boundary) + key + (delimiter OR word boundary OR end of string)
				// Delimiters: hyphen, underscore, space
				$pattern = '/(?:^|[-_\s]|\b)' . $escaped_key . '(?:[-_\s]|\b|$)/';
				if ( preg_match( $pattern, $utm_source ) ) {
					return $referrer;
				}
			}

			// For long keys (>4 letters): Must be exact whole word match
			// Match only if key is surrounded by spaces or at start/end of string
			// This allows "mobile facebook" but prevents "facebook-ads" or "facebook_ads"
			foreach ( $utm_to_referrer_map_long as $utm_key => $referrer ) {
				$escaped_key = preg_quote( $utm_key, '/' );
				// Match: (start of string OR space) + key + (space OR end of string)
				// This ensures it's a standalone word, not part of a compound with delimiters
				$pattern = '/(?:^|\s)' . $escaped_key . '(?:\s|$)/';
				if ( preg_match( $pattern, $utm_source ) ) {
					return $referrer;
				}
			}

			return '';
		}

		/**
		 * Populate referrer from UTM source if referrer is empty
		 *
		 * This method automatically populates the referrer field based on UTM source
		 * when the original referrer is empty. This helps improve analytics data
		 * when document.referrer is unavailable but UTM tracking is present.
		 *
		 * @param array $args The tracking data array
		 *
		 * @return array Modified tracking data array
		 */
		public function maybe_populate_referrer_from_utm( $args ) {
			// Only populate if referrer is empty and we have a UTM source
			if ( empty( $args['referrer'] ) && ! empty( $args['utm_source'] ) ) {
				$referrer_from_utm = $this->get_referrer_from_utm_source( $args['utm_source'] );

				if ( ! empty( $referrer_from_utm ) ) {
					$args['referrer'] = $referrer_from_utm;
				}
			}

			return $args;
		}

		/**
		 * Insert tracking data
		 *
		 * @param $args
		 *
		 * @return int
		 */
		public function insert_tracking_data( $args ) {
			global $wpdb;

			try {
				$args = apply_filters( 'bwf_insert_conversion_tracking_data', $args );

				// Populate referrer from UTM source if referrer is empty
				$args = $this->maybe_populate_referrer_from_utm( $args );

				// Ensure the necessary keys exist
				if ( isset( $args['checkout_total'], $args['bump_total'], $args['offer_total'], $args['value'] ) ) {
					// Calculate sum up to 2 decimal places
					$sumTotal     = round( $args['checkout_total'] + $args['offer_total'] + $args['bump_total'], 2 );
					$valueRounded = round( $args['value'], 2 );

					// If mismatched, adjust checkout_total
					if ( $sumTotal !== $valueRounded ) {
						$difference = $valueRounded - $sumTotal;

						// If positive, add to checkout_total
						if ( $difference > 0 ) {
							$args['checkout_total'] = round( $args['checkout_total'] + $difference, 2 );
						}
					}
				}
				$lastId = 0;

				$conv_table = $wpdb->prefix . $this->conv_table;
				$get_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$conv_table} WHERE type = %d AND source = %d", intval($args['type']), $args['source'] ) ); //phpcs:ignore
				if ( ! empty( $get_id ) && absint( $get_id ) > 0 ) {
					$lastId = $get_id;
					$wpdb->update( $conv_table, $args, array( 'id' => $get_id ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				} else {
					$inserted = $wpdb->insert( $conv_table, $args ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					if ( $inserted ) {
						$lastId = $wpdb->insert_id;
					}
				}

				if ( ! empty( $wpdb->last_error ) ) {
					if ( strpos( $wpdb->last_error, 'Unknown column' ) !== false ) {
						// Regenerate column
						$current_tables = get_option( '_bwf_db_table_list' );
						if ( ! empty( $current_tables['tables'] ) && is_array( $current_tables['tables'] ) ) {
							$key = array_search( $this->conv_table, $current_tables['tables'], true );

							if ( $key !== false ) {
								unset( $current_tables['tables'][ $key ] );
								update_option( '_bwf_db_table_list', $current_tables, true );
							}
						}
					}
					BWF_Logger::get_instance()->log( 'Get last error in ' . $this->conv_table . ' : ' . $wpdb->last_error . ' --- Last query ' . $wpdb->last_query, 'woofunnel-failed-actions', 'buildwoofunnels', true );
				}
			} catch ( Exception $e ) {
				BWF_Logger::get_instance()->log( 'insert_tracking_data last error in ' . $e->getMessage(), 'woofunnel-failed-actions', 'buildwoofunnels', true );
			}

			return $lastId;
		}

		/**
		 * @param $timezone
		 *
		 * @return array|string
		 */
		public function get_country_and_timezone( $timezone ) {
			$result = '';
			if ( '' === $timezone ) {
				return $result;
			}

			$list = bwf_get_country_timezone_list();

			$country_list = wp_list_pluck( $list, 'timezone' );

			// check valid timezone
			foreach ( $country_list as $key => $item ) {
				if ( false !== array_search( $timezone, $item, true ) ) {
					$result = array(
						'country_code' => $key,
						'timezone'     => $timezone,
					);
					break;
				}
			}

			return $result;
		}

		/**
		 * get the timezone offset in minutes
		 *
		 * @return float|int
		 */
		public function get_timezone_offset() {
			$offset                 = 0;
			$offset_diff_in_seconds = current_time( 'timestamp' ) - time();
			if ( absint( $offset_diff_in_seconds ) > 0 ) {
				$offset = $offset_diff_in_seconds / 60;
			}

			return $offset;
		}

		public function add_single_order_meta_box( $post_type, $post ) {

			if ( ! class_exists( 'WFFN_Common' ) ) {
				return;
			}

			if ( ! WFFN_Common::wffn_is_funnel_pro_active() ) {
				return;
			}

			if ( 'shop_order' !== $post_type && 'woocommerce_page_wc-orders' !== $post_type ) {
				return;
			}
			$order_id = 0;

			if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verification not required for admin page identification
				$order_id = absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verification not required for admin page identification
			}

			if ( 0 === absint( $order_id ) && $post instanceof WP_Post ) {
				$order_id = $post->ID;
			}

			if ( 0 === absint( $order_id ) ) {
				return;
			}

			/**
			 * @todo we will update code showing for funnel meta box currently not have exact mata for check order create by funnel
			 * so we run query in conversion table and check order created by funnel
			 */ global $wpdb;
			$query    = $wpdb->prepare( 'SELECT * from ' . $wpdb->prefix . $this->conv_table . ' WHERE type = %s AND source = %d', 2, $order_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table from prefix + private constant prop; values bound via prepare
			$get_data = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- runs string fully built by prepare at 1319
			if ( empty( $get_data ) ) {
				return;
			}

			$data = array(
				'bwf_meta_data' => $get_data,
			);

			add_meta_box(
				'bwfan_utm_info_box',
				__( 'Conversion Tracking', 'wp-marketing-automations' ),
				array( // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				$this,
				'order_meta_box_data',
				),
				function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order',
				'side',
				'default',
				$data
			);
		}

		public function is_order_renewal( $order ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}
			$subscription_renewal = BWF_WC_Compatibility::get_order_data( $order, '_subscription_renewal' );

			return ! empty( $subscription_renewal );
		}

		public function maybe_get_offer_data( $args, $order ) {

			if ( ! class_exists( 'WFOCU_Core' ) ) {
				return $args;
			}

			global $wpdb;

			/**
			 * offer data not save in upsell created order
			 */
			$sibling_order = BWF_WC_Compatibility::get_order_meta( $order, '_wfocu_sibling_order' );

			if ( ! empty( $sibling_order ) ) {
				return $args;
			}

			$order_id       = $order->get_id();
			$offer_accepted = array();
			$offer_rejected = array();
			$offer_total    = 0;

			$offer_query = $wpdb->prepare(
				'SELECT events.object_type as object_type, events.id as event_id, events.object_id as object_id, events.action_type_id as action_type_id, events.value as total FROM ' . $wpdb->prefix . 'wfocu_event AS events
                  LEFT JOIN ' . $wpdb->prefix . "wfocu_session AS session ON ( events.sess_id = session.id ) WHERE 1=1 AND events.object_type = 'offer' AND (events.action_type_id = '4' OR events.action_type_id = '6' ) AND session.order_id = %s",
				$order_id
			);

			$offer_data = $wpdb->get_results( $offer_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- runs prepared string; tables from prefix, order id bound

			if ( is_array( $offer_data ) && count( $offer_data ) > 0 ) {
				foreach ( $offer_data as $o_item ) {
					if ( 4 === absint( $o_item['action_type_id'] ) ) {
						$offer_total      = floatval( $offer_total ) + floatval( $o_item['total'] );
						$offer_accepted[] = (string) $o_item['object_id'];
						/**
						 * remove from reject offer array
						 */
						$reject_key = array_search( $o_item['object_id'], $offer_rejected, true );
						if ( false !== $reject_key ) {
							unset( $offer_rejected[ $reject_key ] );
							$offer_rejected = array_values( $offer_rejected );
						}
					} elseif ( 6 === absint( $o_item['action_type_id'] ) ) {
						$offer_rejected[] = (string) $o_item['object_id'];
					}
				}
			}

			/**
			 * Restricted insert data for offer if find sibling order
			 * because for accepted offer data already enter in offer newly created data
			 */
			$is_sibling = BWF_WC_Compatibility::get_order_meta( $order, '_wfocu_sibling_order' );

			if ( ! empty( $is_sibling ) ) {
				$offer_accepted = array();
			}

			$args['offer_total']    = $this->get_price_value_for_db( $offer_total, $order->get_currency() );
			$args['offer_accepted'] = ! empty( $offer_accepted ) ? wp_json_encode( $offer_accepted ) : '';
			$args['offer_rejected'] = ! empty( $offer_rejected ) ? wp_json_encode( $offer_rejected ) : '';

			return $args;
		}

		public function order_meta_box_data( $post, $meta_data ) {

			if ( ! is_array( $meta_data ) || ! isset( $meta_data['args'] ) || ! isset( $meta_data['args']['bwf_meta_data'] ) ) {
				return;
			}
			$get_data = $meta_data['args']['bwf_meta_data'];

			$first_click = ( isset( $get_data['first_click'] ) && '0000-00-00 00:00:00' !== $get_data['first_click'] ) ? $get_data['first_click'] : '';
			$timestamp   = isset( $get_data['timestamp'] ) ? $get_data['timestamp'] : '';

			$funnel_id = isset( $get_data['funnel_id'] ) ? $get_data['funnel_id'] : 0;
			$funnel    = $funnel_id;

			if ( class_exists( 'WFFN_Funnel' ) && class_exists( 'WFFN_Common' ) ) {
				$funnel_obj = new WFFN_Funnel( $funnel_id );
				if ( $funnel_obj instanceof WFFN_Funnel && $funnel_obj->get_id() > 0 ) {
					$link         = WFFN_Common::get_funnel_edit_link( $funnel_obj->get_id() );
					$funnel_title = ! empty( $funnel_obj->get_title() ) ? $funnel_obj->get_title() : $funnel_obj->get_id();
					$funnel       = '<a href="' . $link . '" target="_blank">' . $funnel_title . '</a>';
				}
			}
			$diff = '';
			$ref  = '';
			$data = array();
			if ( ! empty( $first_click ) ) {
				$d1   = strtotime( $timestamp );
				$d2   = strtotime( $first_click );
				$diff = human_time_diff( $d1, $d2 );
			}

			if ( isset( $get_data['referrer'] ) && $get_data['referrer'] !== '' ) {
				$ref = explode( '?', $get_data['referrer'] );
			}
			$data['funnel'] = array(
				'name'  => __( 'Funnel', 'wp-marketing-automations' ),  // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'value' => $funnel,
			);
			if ( '' !== $first_click ) {
				$data['first_click'] = array(
					'name'  => __( 'First Interaction', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => $first_click,
				);
			}
			if ( '' !== $diff ) {
				$data['convert'] = array(
					'name'  => __( 'Conversion Time', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => $diff,
				);
			}
			if ( isset( $get_data['utm_source'] ) && '' !== $get_data['utm_source'] ) {
				$data['utm_source'] = array(
					'name'  => __( 'UTM Source', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => ucfirst( $get_data['utm_source'] ),
				);
			}
			if ( isset( $get_data['utm_medium'] ) && '' !== $get_data['utm_medium'] ) {
				$data['utm_medium'] = array(
					'name'  => __( 'UTM Medium', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => ucfirst( $get_data['utm_medium'] ),
				);
			}
			if ( isset( $get_data['utm_campaign'] ) && '' !== $get_data['utm_campaign'] ) {
				$data['utm_campaign'] = array(
					'name'  => __( 'UTM Campaign', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => $get_data['utm_campaign'],
				);
			}
			if ( isset( $get_data['utm_term'] ) && '' !== $get_data['utm_term'] ) {
				$data['utm_term'] = array(
					'name'  => __( 'UTM Term', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => $get_data['utm_term'],
				);
			}
			if ( isset( $get_data['utm_content'] ) && '' !== $get_data['utm_content'] ) {
				$data['utm_content'] = array(
					'name'  => __( 'UTM Content', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => $get_data['utm_content'],
				);
			}
			if ( isset( $get_data['referrer'] ) && '' !== $get_data['referrer'] ) {
				$data['referrer'] = array(
					'name'  => __( 'Referrer', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => ( is_array( $ref ) && isset( $ref[0] ) ) ? '<a href="' . esc_url( $ref[0] ) . '" target="_blank">' . esc_html( $ref[0] ) . '</a>' : '',
				);
			}
			if ( isset( $get_data['click_id'] ) ) {
				$data['click_id'] = array(
					'name'  => __( 'Click ID', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => ( '' !== $get_data['click_id'] ) ? __( 'Yes', 'wp-marketing-automations' ) : __( 'No', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				);
			}
			if ( isset( $get_data['device'] ) && '' !== $get_data['device'] ) {
				$data['device'] = array(
					'name'  => __( 'Device', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => ucfirst( $get_data['device'] ),
				);
			}
			if ( isset( $get_data['browser'] ) && '' !== $get_data['browser'] ) {
				$data['browser'] = array(
					'name'  => __( 'Browser', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'value' => $get_data['browser'],
				);
			}

			/**
			 * First-touch UTM / referrer — stored in the existing *_last columns.
			 */
			$first_touch_labels = array(
				'utm_source_last'   => __( 'First UTM Source', 'wp-marketing-automations' ),   // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'utm_medium_last'   => __( 'First UTM Medium', 'wp-marketing-automations' ),   // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'utm_campaign_last' => __( 'First UTM Campaign', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'utm_term_last'     => __( 'First UTM Term', 'wp-marketing-automations' ),     // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'utm_content_last'  => __( 'First UTM Content', 'wp-marketing-automations' ),  // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'referrer_last'     => __( 'First Referrer', 'wp-marketing-automations' ),     // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			);
			foreach ( $first_touch_labels as $first_key => $first_label ) {
				if ( empty( $get_data[ $first_key ] ) ) {
					continue;
				}
				if ( 'referrer_last' === $first_key ) {
					$first_ref   = explode( '?', $get_data[ $first_key ] );
					$first_value = isset( $first_ref[0] ) ? '<a href="' . esc_url( $first_ref[0] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $first_ref[0] ) . '</a>' : '';
				} else {
					$first_value = esc_html( $get_data[ $first_key ] );
				}
				$data[ $first_key ] = array(
					'name'  => $first_label,
					'value' => $first_value,
				);
			}

			/**
			 * Customer journey — the page-by-page path that led to the conversion.
			 *
			 * Hidden by default. Return true from the wffn_show_customer_journey_meta
			 * filter to surface the Customer Journey row on the order UTM metabox.
			 */
			if ( ! empty( $get_data['journey'] ) && true === apply_filters( 'wffn_show_customer_journey_meta', false, $post, $meta_data ) ) {
				$journey = json_decode( $get_data['journey'], true );
				if ( is_array( $journey ) && ! empty( $journey ) ) {
					$entries  = ( isset( $journey['j'] ) && is_array( $journey['j'] ) ) ? $journey['j'] : $journey;
					$sites    = ( isset( $journey['s'] ) && is_array( $journey['s'] ) ) ? $journey['s'] : array();
					$home_url = home_url( '/' );
					ksort( $entries, SORT_NUMERIC );
					$journey_count = 0;
					$journey_html  = '<ol class="bwf-journey-list">';
					foreach ( $entries as $entry ) {
						if ( ! is_array( $entry ) || empty( $entry['u'] ) ) {
							continue;
						}
						$path     = stripslashes( rawurldecode( $entry['u'] ) );
						$title    = ! empty( $entry['t'] ) ? rawurldecode( $entry['t'] ) : $path;
						$site_idx = isset( $entry['s'] ) ? $entry['s'] : null;
						$url      = self::journey_resolve_url( $path, $site_idx, $sites, $home_url );

						$journey_html .= '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a></li>';
						++$journey_count;
					}
					$journey_html .= '</ol>';

					// Only surface the Customer Journey row when at least one entry was rendered;
					// a normalized-but-empty journey ({"j":[],"s":[]}) must not show an empty list.
					if ( $journey_count > 0 ) {
						$data['journey'] = array(
							'name'  => __( 'Customer Journey', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
							'value' => $journey_html,
						);
					}
				}
			}

			$data = apply_filters( 'bwf_utm_tracking_meta_box', $data, $meta_data, $post );
			if ( empty( $data ) ) {
				return;
			}
			?>
			<style>
				.bwf-utm-box-data {
					margin: 10px 0;
				}

				.bwf-utm-box-data > div > span:nth-child(1) {
					font-weight: 500;
					width: 80px;
					display: inline-block;
					min-width: 105px;
				}

				.bwf-utm-box-data > div {
					margin-bottom: 8px;
					display: flex;
					word-break: break-all;
				}

				.bwf-utm-box-data .bwf-utm-data-gap {
					display: block;
					clear: both;
					height: 1px;
					border-bottom: 1px solid #eee;
					margin-bottom: 10px;
				}

				.bwf-utm-box-data .bwf-journey-list {
					margin: 4px 0 0;
					padding-left: 18px;
				}

				.bwf-utm-box-data .bwf-journey-list li {
					margin-bottom: 4px;
				}
			</style>
			<div class="bwf-utm-box-data">
				<div class="bwf-utm-data-gap"></div>
				<?php
				foreach ( $data as $item ) {
					?>

					<div>
						<span class="bwf-utm-lable"><?php echo esc_html( $item['name'] ) . ': '; ?></span>
						<span class="bwf-utm-text"><?php echo wp_kses_post( $item['value'] ); ?></span>
					</div>

					<?php
				}
				?>
			</div>
			<?php
		}

		public function string_length( $string, $length = 255 ) {
			return ( strlen( $string ) > $length ) ? substr( $string, 0, $length ) : $string;
		}

		/**
		 * Remove emojis and 4-byte UTF-8 characters from string
		 * Uses a single efficient regex pattern
		 *
		 * @param string $string The string to clean
		 * @return string Cleaned string without emojis
		 */
		public function strip_emojis( $string ) {
			if ( empty( $string ) ) {
				return $string;
			}

			// Remove emojis and 4-byte UTF-8 characters in one go
			$string = preg_replace( '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1FA70}-\x{1FAFF}\x{10000}-\x{10FFFF}]/u', '', $string );

			// Clean up extra spaces
			$string = preg_replace( '/\s+/', ' ', trim( $string ) );

			return $string;
		}


		/**
		 * remove query param from url
		 *
		 * @param $url
		 * @param $is_journey
		 *
		 * @return mixed|string
		 */
		public function parse_url_query_param( $url, $is_journey = false ) {
			$get_referrer = ! empty( $url ) ? wp_parse_url( $url ) : '';
			$referrer_url = '';
			if ( is_array( $get_referrer ) ) {
				if ( ! $is_journey && isset( $get_referrer['host'] ) ) {
					$referrer_url = $get_referrer['host'] . ( isset( $get_referrer['path'] ) ? $get_referrer['path'] : '' );
				} elseif ( isset( $get_referrer['path'] ) ) {
					$referrer_url = $get_referrer['path'];

				}
			}

			return $this->string_length( $referrer_url );
		}

		/**
		 * Delete order entry from conversion table on order delete
		 *
		 * @param $order_id
		 *
		 * @return void
		 */
		public function delete_conversion_row( $order_id ) {

			if ( empty( $order_id ) || absint( 0 === $order_id ) ) {
				return;
			}

			if ( 0 < did_action( 'delete_post' ) ) {
				$get_post_type = get_post_type( $order_id );
				if ( 'shop_order' !== $get_post_type ) {
					return;
				}
			}
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . $this->conv_table . ' WHERE type= 2 AND source = %d ', $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table from prefix + const; order id bound via %d
		}

		public function partially_refunded_process( $order_id, $refund_id ) {
			global $wpdb;

			/**
			 * check if db updated
			 */
			if ( ! function_exists( 'wffn_conversion_tracking_migrator' ) ) {
				return;
			}

			if ( ! in_array( absint( wffn_conversion_tracking_migrator()->get_upgrade_state() ), array( 3, 4 ), true ) ) {
				return;
			}

			$order           = wc_get_order( $order_id );
			$refund          = wc_get_order( $refund_id );
			$total_refund    = 0;
			$checkout_refund = 0;
			$offer_refund    = 0;
			$bump_refund     = 0;

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			if ( ! $refund instanceof WC_Order_Refund ) {
				return;
			}

			$types = apply_filters(
				'wfacp_order_type_to_group',
				array(
					'line_item',
					'tax',
					'shipping',
					'fee',
					'coupon',
				)
			);
			if ( 0 < count( $refund->get_items( $types ) ) ) {
				foreach ( $refund->get_items( $types ) as $refund_item ) {
					$item_id = $refund_item->get_meta( '_refunded_item_id', true );
					if ( empty( $item_id ) ) {
						continue;
					}
					$item = $order->get_item( $item_id );
					if ( ! $item instanceof WC_Order_Item ) {
						continue;
					}
					$_bump_purchase     = $item->get_meta( '_bump_purchase' );
					$_upstroke_purchase = $item->get_meta( '_upstroke_purchase' );
					$total_refund      += abs( $refund_item->get_total() );

					if ( '' !== $_bump_purchase ) {
						$bump_refund += abs( $refund_item->get_total() );
					} elseif ( '' !== $_upstroke_purchase ) {
						$offer_refund += abs( $refund_item->get_total() );
					} else {
						$checkout_refund += abs( $refund_item->get_total() );
					}
				}
			} else {
				$total_refund = BWF_WC_Compatibility::get_order_meta( $refund, '_refund_amount' );
			}

			if ( $total_refund > 0 ) {
				$get_totals = $wpdb->get_row( $wpdb->prepare( "SELECT value, checkout_total, bump_total, offer_total FROM {$wpdb->prefix}{$this->conv_table} WHERE type = 2 AND source = %d", absint( $order_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix + private constant prop; id bound via %d

				if ( is_array( $get_totals ) && count( $get_totals ) > 0 ) {
					/**
					 * get save totals amount from conversion table
					 * Set 0 for checkout, bump and upsell amount if total refund amount is 0
					 */
					$total_value    = ! empty( $get_totals['value'] ) ? (float) $get_totals['value'] : 0;
					$checkout_total = ! empty( $get_totals['checkout_total'] ) ? (float) $get_totals['checkout_total'] : 0;
					$bump_total     = ! empty( $get_totals['bump_total'] ) ? (float) $get_totals['bump_total'] : 0;
					$offer_total    = ! empty( $get_totals['offer_total'] ) ? (float) $get_totals['offer_total'] : 0;

					$update_args = array(
						'value'          => ( $total_value <= $total_refund ) ? 0 : $total_value - $total_refund,
						'checkout_total' => ( $total_refund === 0 || $checkout_total <= $checkout_refund ) ? 0 : $checkout_total - $checkout_refund,
						'bump_total'     => ( $total_refund === 0 || $bump_total <= $bump_refund ) ? 0 : $bump_total - $bump_refund,
						'offer_total'    => ( $total_refund === 0 || $offer_total <= $offer_refund ) ? 0 : $offer_total - $offer_refund,
					);

					if ( 0 === $update_args['bump_total'] && 0 === $update_args['offer_total'] ) {
						$update_args['checkout_total'] = $update_args['value'];
					}

					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wpdb->update on plugin conversion table; parameterized by core
						$wpdb->prefix . $this->conv_table,
						$update_args,
						array(
							'type'   => 2,
							'source' => absint( $order_id ),
						)
					); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				}
			}
		}

		public function get_ipn_gateways() {
			if ( ! class_exists( 'WFACP_Core' ) ) {
				return array();
			}

			return WFACP_Core()->reporting->get_ipn_gateways();
		}

		public function conversion_table_name() {
			return $this->conv_table;
		}

		public function get_price_value_for_db( $total, $currency ) {
			return class_exists( 'BWF_Plugin_Compatibilities' ) ? round( BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $total, $currency ), 2 ) : $total;
		}

		/**
		 * We are handling cases of insert funnel analytics for which order thankyou page not open
		 */
		public function maybe_execute_thankyou_hook() {
			// Ensure WooCommerce is active or WFOCU_Core is available before proceeding
			if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WFOCU_Core' ) ) {
				return;
			}

			global $wpdb;

			// Get paid statuses for WooCommerce orders
			$status_to_query = wc_get_is_paid_statuses();
			$status_in       = implode(
				',',
				array_map(
					function ( $a ) {
						return "'" . esc_sql( 'wc-' . $a ) . "'";
					},
					$status_to_query
				)
			);

			// Set the start time for the batch process
			WFOCU_Common::$start_time = time();

			/**
			 * Only look at recently created orders. Stragglers needing recovery are always
			 * recent (the cron runs every few minutes); older orders carrying the meta are
			 * unpaid/orphaned and never match the paid-status filter. Without this bound the
			 * planner walks the entire orders table by date whenever nothing matches (the
			 * common steady state), causing multi-second full scans that pile up under load.
			 */
			if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
				$order_table      = $wpdb->prefix . 'wc_orders';
				$order_meta_table = $wpdb->prefix . 'wc_orders_meta';
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare
				$query            = $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb prefix + hardcoded wc_orders
					"SELECT ord.id as ID FROM {$order_table} ord
                                INNER JOIN {$order_meta_table} om ON (ord.id = om.order_id AND om.meta_key = '_wffn_tracking_data')
                                WHERE ord.type = %s
                                AND ord.status IN ({$status_in})
                                AND ord.date_created_gmt > ( UTC_TIMESTAMP() - INTERVAL 30 DAY )
                                ORDER BY ord.date_created_gmt DESC LIMIT 0, 10",
					'shop_order'
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			} else {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare
				$query = $wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
                                INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_wffn_tracking_data')
                                WHERE p.post_type = %s
                                AND p.post_status IN ({$status_in})
                                AND p.post_date > ( NOW() - INTERVAL 30 DAY )
                                ORDER BY p.post_date DESC LIMIT 0, 10",
					'shop_order'
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			$query_results = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare

			if ( ! empty( $query_results ) && is_array( $query_results ) ) {

				$get_orders = array_values( array_filter(
					array_map(
						function ( $query_instance ) {
							return wc_get_order( $query_instance->ID );
						},
						$query_results
					)
				) );

				/**
				 * Process each fetched order exactly once. Previously this used a hand-managed
				 * index with unset()-on-success to terminate, which spun on null (and never
				 * emptied the array) whenever an order was skipped, burning CPU until the time
				 * guard tripped. A simple foreach with a time/memory break avoids that entirely.
				 */
				foreach ( $get_orders as $order ) {

					if ( WFOCU_Common::time_exceeded() || WFOCU_Common::memory_exceeded() ) {
						break;
					}

					// Skip if the order is invalid
					if ( empty( $order ) || ! $order instanceof WC_Order ) {
						continue;
					}

					try {

						$tracking_data = BWF_WC_Compatibility::get_order_meta( $order, '_wffn_tracking_data' );

						// Missing or invalid tracking data can never be processed: remove it so the
						// order stops re-qualifying for this recovery query on every run.
						if ( empty( $tracking_data ) || ! is_array( $tracking_data ) ) {
							$order->delete_meta_data( '_wffn_tracking_data' );
							$order->save();
							continue;
						}

						// Insert data for orders without the thank-you page
						if ( $this->is_order_renewal( $order ) ) {
							$order->delete_meta_data( '_wffn_tracking_data' );
							$order->save();

						} else {
							$this->insert_data_without_thankyou( $order, $tracking_data );
						}
					} catch ( Error | Exception $e ) {
						WFOCU_Core()->log->log( 'Upsell schedule Error occurred on insert funnel analytics - order id #' . $order->get_id() . ' error ' . $e->getMessage() );
					}
				}
			}

			$this->maybe_pending_report_data( $status_in );
		}

		public function maybe_pending_report_data( $status_in ) {
			try {
				global $wpdb;

				// run this snippet for recover checkout data
				if ( class_exists( 'WFACP_Reporting' ) ) {

					/**
					 * Only scan recently created orders. Like the thank-you recovery above, stragglers
					 * needing report recovery are always recent (the cron runs every few minutes); older
					 * orders still carrying this meta are unrecoverable leftovers. Without this bound the
					 * planner walks the entire orders table by date whenever nothing matches (the common
					 * steady state), causing multi-second full scans that pile up under load.
					 */
					if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
						$order_table      = $wpdb->prefix . 'wc_orders';
						$order_meta_table = $wpdb->prefix . 'wc_orders_meta';
						// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare
						$wfacp_query      = $wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb prefix + hardcoded wc_orders
							"SELECT ord.id as ID FROM {$order_table} ord
                                INNER JOIN {$order_meta_table} om ON (ord.id = om.order_id AND om.meta_key = '_wfacp_report_data')
                                WHERE ord.type = %s
                                AND ord.status IN ({$status_in})
                                AND ord.date_created_gmt > ( UTC_TIMESTAMP() - INTERVAL 30 DAY )
                                ORDER BY ord.date_created_gmt DESC LIMIT 0, 10",
							'shop_order'
						);
						// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

					} else {
						// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare
						$wfacp_query = $wpdb->prepare(
							"SELECT p.ID FROM {$wpdb->posts} p
                                INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_wfacp_report_data')
                                WHERE p.post_type = %s
                                AND p.post_status IN ({$status_in})
                                AND p.post_date > ( NOW() - INTERVAL 30 DAY )
                                ORDER BY p.post_date DESC LIMIT 0, 10",
							'shop_order'
						);
						// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}

					$wfacp_results = $wpdb->get_results( $wfacp_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare

					if ( is_array( $wfacp_results ) && count( $wfacp_results ) > 0 ) {
						$wfacp_report = WFACP_Reporting::get_instance();
						foreach ( $wfacp_results as $result ) {
							$post_id = isset( $result['ID'] ) ? absint( $result['ID'] ) : ( isset( $result['ID'] ) ? absint( $result['ID'] ) : 0 );

							if ( ( WFOCU_Common::time_exceeded() || WFOCU_Common::memory_exceeded() ) ) {
								break;
							}

							if ( 0 !== $post_id ) {
								$aero_order = wc_get_order( $post_id );
								if ( $aero_order instanceof WC_Order ) {
									if ( $this->is_order_renewal( $aero_order ) ) {
										$aero_order->delete_meta_data( '_wfacp_report_data' );
										$aero_order->delete_meta_data( '_wfacp_report_needs_normalization' );
										$aero_order->save();
									} else {
										$wfacp_report->updating_reports_from_orders( $post_id );
									}
								}
							}
						}
					}
				}

				/**
				 * run this snippet for recover OrderBump data
				 * order bump need always run after checkout
				 */
				if ( class_exists( 'WFOB_Reporting' ) ) {
					if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
						$order_table      = $wpdb->prefix . 'wc_orders';
						$order_meta_table = $wpdb->prefix . 'wc_orders_meta';
						// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare
						$ob_query         = $wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from wpdb prefix + hardcoded wc_orders
							"SELECT ord.id as ID FROM {$order_table} ord
                                INNER JOIN {$order_meta_table} om ON (ord.id = om.order_id AND om.meta_key = '_wfob_report_data')
                                WHERE ord.type = %s
                                AND ord.status IN ({$status_in})
                                AND ord.date_created_gmt > ( UTC_TIMESTAMP() - INTERVAL 30 DAY )
                                ORDER BY ord.date_created_gmt DESC LIMIT 0, 10",
							'shop_order'
						);
						// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

					} else {
						// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare
						$ob_query = $wpdb->prepare(
							"SELECT p.ID FROM {$wpdb->posts} p
                                INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_wfob_report_data')
                                WHERE p.post_type = %s
                                AND p.post_status IN ({$status_in})
                                AND p.post_date > ( NOW() - INTERVAL 30 DAY )
                                ORDER BY p.post_date DESC LIMIT 0, 10",
							'shop_order'
						);
						// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}

					$ob_results = $wpdb->get_results( $ob_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare

					if ( is_array( $ob_results ) && count( $ob_results ) > 0 ) {
						$ob_report = WFOB_Reporting::get_instance();
						foreach ( $ob_results as $ob_result ) {
							if ( ( WFOCU_Common::time_exceeded() || WFOCU_Common::memory_exceeded() ) ) {
								break;
							}
							$ob_id    = isset( $ob_result['ID'] ) ? absint( $ob_result['ID'] ) : ( isset( $ob_result['ID'] ) ? absint( $ob_result['ID'] ) : 0 );
							$ob_order = wc_get_order( $ob_id );
							if ( $ob_order instanceof WC_Order ) {
								if ( $this->is_order_renewal( $ob_order ) ) {
									$ob_order->delete_meta_data( '_wfob_report_data' );
									$ob_order->delete_meta_data( '_wfob_report_needs_normalization' );
									$ob_order->save();
								} else {
									$ob_report->insert_custom_row_from_meta( $ob_id );
								}
							}
						}
					}
				}
			} catch ( Error | Exception $e ) {
				WFOCU_Core()->log->log( 'Upsell schedule Error occurred on insert funnel analytics - order id # error ' . $e->getMessage() );

				if ( isset( $ob_order ) ) {
					$ob_order->delete_meta_data( '_wfob_report_data' );
					$ob_order->delete_meta_data( '_wfob_report_needs_normalization' );
					$ob_order->save();
				}
				if ( isset( $aero_order ) ) {
					$aero_order->delete_meta_data( '_wfacp_report_data' );
					$aero_order->delete_meta_data( '_wfacp_report_needs_normalization' );
					$aero_order->save();
				}
			}
		}

		public function insert_data_without_thankyou( $order, $tracking_data ) {

			if ( ! $order instanceof WC_Order || $this->is_order_renewal( $order ) ) {
				return false;
			}

			$offer_id = BWF_WC_Compatibility::get_order_meta( $order, '_wfocu_offer_id' );
			if ( ! empty( $offer_id ) ) {
				$this->insert_tracking_data( $tracking_data );
				$order->delete_meta_data( '_wffn_need_normalize' );
				$order->delete_meta_data( '_wfocu_offer_id' );
				$order->delete_meta_data( '_wffn_tracking_data' );
				$order->save_meta_data();

			} else {
				$this->insert_tracking_order( $order, $tracking_data );
				$order->delete_meta_data( '_wffn_need_normalize' );
				$order->save_meta_data();
			}
		}
	}

	BWF_Ecomm_Tracking_Common::get_instance();
}
