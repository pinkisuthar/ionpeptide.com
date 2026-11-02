<?php

/**
 * Class BWFAN_Common
 * Handles Common Functions For Admin as well as front end interface
 */
#[AllowDynamicProperties]
class BWFAN_Common {
	public static $http;

	public static $integrations_saved_data = array();
	public static $date_format = null;
	public static $time_format = null;
	public static $admin_email = null;
	public static $select2ajax_functions = null;
	public static $events_async_data = null;
	public static $time_periods = null;
	public static $taxonomy_post_type = array();
	public static $offer_product_types = array(
		'simple',
		'variable',
		'variation',
		'subscription',
		'variable-subscription',
		'subscription_variation',
	);

	public static $exec_task_id = null;

	public static $recurring_actions_db = [];

	/** For v2 automation - special property */
	public static $end_v2_current_contact_automation = false;
	public static $change_data_strore = false;
	public static $last_automation_cid = [];

	/** Cached DB Calls */
	public static $cached_db_data = [];

	public static $random_products = [];

	public static $dynamic_str = '';

	public static $unsubscribe_page_link = '';

	public static $block_editor_settings = [];

	public static $order_status_change = [];
	public static $show_tags_list_by_name = null;

	protected static $c_lk_d = null;
	protected static $c_lk_p = null;

	public static $crm_default_fields = [
		'address-1' => [
			'name'  => 'Address 1',
			'type'  => 1,
			'mode'  => 2,
			'vmode' => 1,
			'view'  => 2,
			'meta'  => [],
		],
		'address-2' => [
			'name'  => 'Address 2',
			'type'  => 1,
			'mode'  => 2,
			'vmode' => 1,
			'view'  => 2,
			'meta'  => []
		],
		'city'      => [
			'name'  => 'City',
			'type'  => 1,
			'mode'  => 2,
			'vmode' => 1,
			'view'  => 2,
			'meta'  => []
		],
		'postcode'  => [
			'name'  => 'Pincode',
			'type'  => 1,
			'mode'  => 2,
			'vmode' => 1,
			'view'  => 2,
			'meta'  => []
		],
		'company'   => [
			'name'  => 'Company',
			'type'  => 1,
			'mode'  => 2,
			'vmode' => 1,
			'view'  => 2,
			'meta'  => []
		],
		'gender'    => [
			'name'  => 'Gender',
			'type'  => 4,
			'mode'  => 2,
			'vmode' => 1,
			'view'  => 2,
			'meta'  => [ 'options' => [ 'Male', 'Female', 'Other' ] ]
		],
		'dob'       => [
			'name'  => 'Date of Birth',
			'type'  => 7,
			'mode'  => 2,
			'vmode' => 1,
			'view'  => 2,
			'meta'  => []
		]
	];

	public static $stop_async_call = null;

	public static function init() {
		self::$date_format           = self::bwfan_get_date_format();
		self::$time_format           = self::bwfan_get_time_format();
		self::$admin_email           = get_option( 'admin_email' );
		self::$select2ajax_functions = array( 'get_subscription_product', 'get_membership_plans', 'get_coupon' );

		register_deactivation_hook( BWFAN_PLUGIN_FILE, array( __CLASS__, 'deactivation' ) );

		/** Loading WooFunnels core */
		add_action( 'plugins_loaded', function () {
			WooFunnel_Loader::include_core();
		}, - 99 );

		add_action( 'plugins_loaded', [ __CLASS__, 'set_dynamic_string' ], 0 );

		add_filter( 'modify_set_data', array( __CLASS__, 'parse_default_merge_tags' ), 10, 1 );
		add_action( 'bwfan_delete_order_meta_payment_failed', array( __CLASS__, 'delete_order_meta' ), 10, 1 );
		add_filter( 'bwfan_select2_ajax_callable', array( __CLASS__, 'get_callable_object' ), 1, 2 );

		add_action( 'admin_notices', array( __CLASS__, 'bwfan_run_cron_test' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'add_plugin_endpoint' ) );
		add_filter( 'woocommerce_rest_product_cat_query', array( __CLASS__, 'normalize_term_rest_search' ), 10, 2 );
		add_filter( 'woocommerce_rest_product_tag_query', array( __CLASS__, 'normalize_term_rest_search' ), 10, 2 );

		/** showing consent text on checkout page */
		add_action( 'wp', array( __CLASS__, 'display_marketing_optin_checkbox' ) );

		add_filter( 'action_scheduler_queue_runner_batch_size', array( __CLASS__, 'ac_increase_queue_batch_size' ) );
		add_filter( 'action_scheduler_queue_runner_time_limit', array( __CLASS__, 'ac_increase_max_execution_time' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'make_custom_events_time' ) );

		/** Action Scheduler custom table worker callback */
		add_action( 'bwfan_run_queue', array( __CLASS__, 'run_as_ct_worker' ) );
		add_action( 'action_scheduler_pre_init', array( __CLASS__, 'as_pre_init_cb' ) );
		add_action( 'action_scheduler_pre_init', array( __CLASS__, 'as_pre_init_cli_cb' ) );

		/** Action Scheduler custom table v2 worker callback */
		add_action( 'bwfan_run_queue_v2', array( __CLASS__, 'run_as_ct_v2_worker' ) );
		add_action( 'action_scheduler_pre_init', array( __CLASS__, 'as_pre_init_v2_cb' ), 11 );
		add_action( 'action_scheduler_pre_init', array( __CLASS__, 'as_pre_init_cli_v2_cb' ), 12 );

		/** Action Scheduler to delete engagement meta records from tools */
		add_action( 'bwfan_delete_engagement_tracking_meta_tool_action', array( __CLASS__, 'delete_engagement_tracking_meta_tool_action' ) );

		/** Enable WooFunnels Action Scheduler Data Store */
		add_filter( 'enable_woofunnels_as_ds', '__return_true' );

		/** Convert all active abandoned rows to abandoned */
		add_action( 'bwfan_check_abandoned_carts', array( __CLASS__, 'check_for_abandoned_carts' ) );

		/** Delete all the old abandoned cart rows and their queued tasks */
		add_action( 'bwfan_delete_old_abandoned_carts', array( __CLASS__, 'delete_old_abandoned_carts' ) );
		add_action( 'bwfan_mark_abandoned_lost_cart', array( __CLASS__, 'mark_abandoned_lost_cart' ) );

		add_action( 'bwfan_delete_expired_autonami_coupons', array( __CLASS__, 'delete_expired_autonami_coupons' ) );

		add_action( 'bwfan_get_sources_events', array( __CLASS__, 'merge_pro_events' ) );

		add_action( 'woofunnels_woocommerce_thankyou', array( __CLASS__, 'hit_cron_to_run_tasks' ) );

		/** Auto deploy coupon in the cart */
		add_action( 'wp', array( __CLASS__, 'auto_apply_wc_coupon' ), 20 );
		/** Handling when restoring abandoned cart */
		add_action( 'bwfan_abandoned_cart_restored', array( __CLASS__, 'auto_apply_wc_coupon' ) );

		/** update order meta marketing_status details */
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'bwfan_update_order_user_consent' ) );

		/** Gutenberg/Block checkout: register consent field when block enqueues; then hide it for logged-in subscribed users (priority 5 runs before WooCommerce adds field data at 10). */
		add_action( 'woocommerce_blocks_checkout_enqueue_data', array( __CLASS__, 'register_blocks_checkout_consent_field' ), 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'bwfan_blocks_checkout_consent_from_request' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'bwfan_sync_blocks_checkout_consent' ), 5 );

		add_action( 'bwf_normalize_contact_meta_before_save', array( __CLASS__, 'save_marketing_status_for_user' ), 20, 2 );
		/** save marketing status if order is failed  */
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'woocommerce_order_status_failed' ), 999, 2 );

		/**
		 * Hooked over action_scheduler_pre_init
		 * Initiating core action scheduler
		 */
		add_action( 'bwf_after_action_scheduler_load', array( __CLASS__, 'bwf_after_action_scheduler_load' ), 11 );

		/**Update the execution time of v2 automations if delay time changed */
		add_action( 'bwfan_delay_step_updated', [ __CLASS__, 'bwfan_delay_step_updated' ], 10, 1 );

		add_action( 'bwfan_goal_step_updated', [ __CLASS__, 'bwfan_goal_step_updated' ], 10, 2 );

		add_action( 'bwfan_lost_cart_triggered', [ __CLASS__, 'bwfan_lost_cart_triggered' ] );

		add_action( 'bwfan_automation_step_deleted', [ __CLASS__, 'bwfan_automation_step_deleted' ], 10, 1 );

		add_action( 'bwfan_store_automation_completed_ids', [ __CLASS__, 'bwfan_store_automation_completed_ids' ] );
		add_action( 'bwfan_store_automation_active_ids', [ __CLASS__, 'bwfan_store_automation_active_ids' ] );

		/** Automation contact bulk action */
		add_action( 'bwfan_automation_contact_bulk_action', [ __CLASS__, 'bwfan_automation_contact_bulk_action' ], 10, 4 );

		/** Bulk action on listing pages */
		add_action( 'bwfan_bulk_action', [ __CLASS__, 'bwfan_bulk_action' ], 10, 3 );

		add_action( 'bwfan_delete_expired_coupons', array( __CLASS__, 'delete_expired_dynamic_coupons' ) );

		/** Update contact wp user id after a wp user is deleted */
		add_action( 'delete_user', array( __CLASS__, 'update_contact_wp_id' ) );

		add_action( 'bwfan_update_meta_automations_v2', [ __CLASS__, 'update_meta_automations_v2' ] );

		add_action( 'bwfan_delete_logs', [ __CLASS__, 'delete_bwfan_logs' ] );

		/** Bulk action for Automation contact listing */
		add_action( 'bwfan_automation_all_contact_bulk_action', [ __CLASS__, 'bwfan_automation_all_contact_bulk_action' ], 10, 3 );
		/** Update automation contact's step trail status */
		add_action( 'bwfan_update_contact_trail', [ __CLASS__, 'bwfan_update_contact_trail' ] );

		add_filter( 'fk_before_sending_order_status_change_async_request', [ __CLASS__, 'order_status_change' ], 10, 4 );
		add_action( 'fk_order_status_change_async_capture', [ __CLASS__, 'order_status_change_async_capture' ] );

		/** Cron 'every 8 hrs' schedule */
		add_filter( 'cron_schedules', [ __CLASS__, 'add_schedules' ], 100 );
		add_action( 'fka_clear_duplicate_actions', array( __CLASS__, 'remove_duplicate_actions' ) );

		/** Re-arm our own recurring hooks from the worker, the only path that runs on stores where nobody opens wp-admin */
		add_action( 'bwf_worker_before_as_run', array( __CLASS__, 'validate_recurring_actions_on_worker' ) );

		add_action( 'wffn_optin_form_submit', array( __CLASS__, 'set_uid_in_cookies' ), 10, 3 );
		add_action( 'bwfan_run_event_queue', [ __CLASS__, 'bwfan_run_event_queue' ] );
		add_action( 'bwfan_conversions_index', [ __CLASS__, 'bwfan_conversions_index' ] );

		add_action( 'bwfan_run_midnight_cron', array( 'BWFAN_Table_Validation_Controller', 'get_table_validate_option' ) );

		add_action( 'bwfan_run_midnight_cron', [ __CLASS__, 'schedule_notification' ] );

		/** Enable cron check logging if enabled in setting */
		add_filter( 'bwf_check_cron_schedule_logging', function () {
			return self::is_log_enabled( 'bwfan_cron_check_logging' );
		} );

		/** Modify midnight cron action scheduler's execution time to store time */
		$midnight_actions = [
			'bwfan_run_midnight_cron',
			'bwfan_delete_logs',
			'bwfan_delete_expired_autonami_coupons',
			'bwfan_mark_abandoned_lost_cart',
			'bwfan_run_midnight_connectors_sync',
		];
		foreach ( $midnight_actions as $action ) {
			add_filter( 'bwf_recurring_action_' . $action . '_execution_time', function ( $e_time ) {
				return self::get_midnight_store_time();
			} );
		}

		/** Add contact if not created */
		add_action( 'user_register', [ __CLASS__, 'bwfan_register_user' ], 10, 1 );

		add_action( 'bwfan_store_template_links', [ __CLASS__, 'bwfan_store_template_links' ] );

		// Hook for allowing whitelisted endpoints.
		add_filter( 'rest_authentication_errors', [ __CLASS__, 'bwfan_allow_whitelisted_endpoints' ], PHP_INT_MAX );

		/** Keep our routes out of the public REST index and OPTIONS schema; dispatch is unchanged */
		add_filter( 'rest_endpoints', [ __CLASS__, 'hide_rest_routes_from_index' ] );
		add_filter( 'rest_index', [ __CLASS__, 'hide_rest_namespaces_from_index' ] );
	}

	/**
	 * Clear a third-party plugin's REST authentication block for Autonami and
	 * WooFunnels endpoints, so security/cache plugins that deny all REST do not
	 * stop our loopback worker, webhooks and events.
	 *
	 * Two things keep this from becoming an authentication bypass:
	 *  - It reads the route WordPress actually dispatched, from
	 *    $GLOBALS['wp']->query_vars['rest_route'], not the request URL. A POST
	 *    body rest_route overrides the permalink in WP::parse_request(), so the
	 *    URL path is attacker-controlled and can address a whitelisted path
	 *    while core executes an arbitrary route such as /wp/v2/users.
	 *  - It never clears WordPress core's own cookie/nonce failure. On an
	 *    invalid (non-empty) nonce core keeps the logged-in user set, so nulling
	 *    rest_cookie_invalid_nonce would let a cross-site request act as that
	 *    user (CSRF). Those errors are left untouched; only another plugin's
	 *    block is cleared.
	 *  - When it does clear a block, it runs core's cookie/nonce check itself.
	 *    An earlier block makes rest_cookie_check_errors() return early, so
	 *    without this a cookie request with no nonce would keep the user set.
	 *
	 * @param WP_Error|null|true $result Authentication result
	 *
	 * @return WP_Error|null|true
	 */
	public static function bwfan_allow_whitelisted_endpoints( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		if ( 0 === strpos( (string) $result->get_error_code(), 'rest_cookie' ) ) {
			return $result;
		}

		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		if ( '' === $route ) {
			return $result;
		}

		if ( '/' !== substr( $route, 0, 1 ) ) {
			$route = '/' . $route;
		}

		$allowed_namespaces = [
			'/woofunnels/',
			'/autonami/',
			'/funnelkit-automations/',
			'/funnelkit-app/',
			'/autonami-app/',
			'/autonami-admin/',
			'/autonami-webhook/',
			'/woofunnel_customer/',
		];

		foreach ( $allowed_namespaces as $namespace ) {
			if ( 0 === strpos( $route, $namespace ) ) {
				return rest_cookie_check_errors( null );
			}
		}

		return $result;
	}

	/**
	 * REST namespaces dropped from the /wp-json/ namespaces list, root route included
	 *
	 * @return array
	 */
	public static function get_hidden_rest_prefixes() {
		$prefixes = array( 'autonami/v1', 'autonami/v2', 'autonami-webhook', 'autonami-app', 'woofunnels/v1', 'woofunnel_customer/v1' );
		if ( defined( 'BWFAN_API_NAMESPACE' ) ) {
			$prefixes[] = BWFAN_API_NAMESPACE;
		}

		return array_unique( array_filter( (array) apply_filters( 'bwfan_hidden_rest_prefixes', $prefixes ), 'is_string' ) );
	}

	/**
	 * Hide the routes no register_rest_route() call of ours can flag: the namespace
	 * root routes WordPress registers itself, and the woofunnels core routes.
	 * Every other route in these namespaces passes 'show_in_index' => false where it is registered.
	 *
	 * @param array $endpoints
	 *
	 * @return array
	 */
	public static function hide_rest_routes_from_index( $endpoints ) {
		if ( ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		$routes = array( '/woofunnels/v1/worker', '/woofunnel_customer/v1/offer_accepted', '/woofunnel_customer/v1/order_status_changed' );
		foreach ( self::get_hidden_rest_prefixes() as $prefix ) {
			$routes[] = '/' . $prefix;
		}

		foreach ( $routes as $route ) {
			if ( empty( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
				continue;
			}
			foreach ( $endpoints[ $route ] as $key => $handler ) {
				if ( is_int( $key ) && is_array( $handler ) ) {
					$endpoints[ $route ][ $key ]['show_in_index'] = false;
				}
			}
		}

		return $endpoints;
	}

	/**
	 * Remove hidden namespaces from the /wp-json/ namespaces list
	 *
	 * @param WP_REST_Response $response
	 *
	 * @return WP_REST_Response
	 */
	public static function hide_rest_namespaces_from_index( $response ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( empty( $data['namespaces'] ) || ! is_array( $data['namespaces'] ) ) {
			return $response;
		}

		$data['namespaces'] = array_values( array_diff( $data['namespaces'], self::get_hidden_rest_prefixes() ) );
		$response->set_data( $data );

		return $response;
	}

	public static function display_marketing_optin_checkbox() {
		/** check for woocommerce active **/
		if ( ! function_exists( 'bwfan_is_woocommerce_active' ) || false === bwfan_is_woocommerce_active() ) {
			return;
		}

		/** check for woocommerce checkout page **/
		if ( ! is_checkout() && ! wp_doing_ajax() ) {
			return;
		}

		$general_options = self::get_global_settings();
		$wc_endpoint     = filter_input( INPUT_GET, 'wc-ajax' );
		if ( ! is_null( $wc_endpoint ) && ! wp_doing_ajax() ) {
			return;
		}

		/** showing consent text on checkout page */
		if ( isset( $general_options['bwfan_user_consent_position'] ) && 'below_term' === $general_options['bwfan_user_consent_position'] ) {
			add_action( 'woocommerce_checkout_after_terms_and_conditions', function () {
				self::add_user_consent_after_terms_and_conditions();
			} );
		} elseif ( isset( $general_options['bwfan_user_consent_position'] ) && 'below_phone' === $general_options['bwfan_user_consent_position'] ) {
			/** Below Phone */
			add_filter( 'woocommerce_form_field', function ( $field, $key, $args, $value ) {
				if ( 'billing_phone' === $key ) {
					$field_priority = $args['priority'] ? $args['priority'] : '';
					$field          .= self::add_user_consent_after_terms_and_conditions( true, $field_priority );
				}

				return $field;
			}, 99, 4 );
		} else {
			/** Below Email */
			add_filter( 'woocommerce_form_field', function ( $field, $key, $args, $value ) {
				if ( 'billing_email' === $key ) {
					$field_priority = $args['priority'] ? $args['priority'] : '';
					$field          .= self::add_user_consent_after_terms_and_conditions( true, $field_priority );
				}

				return $field;
			}, 99, 4 );
		}
	}

	public static function get_global_settings() {
		$global_settings = get_option( 'bwfan_global_settings', array() );
		$global_settings = self::override_non_changeable_settings( $global_settings );
		$global_settings = wp_parse_args( $global_settings, self::get_default_global_settings( $global_settings ) );

		return apply_filters( 'bwfan_get_global_settings', $global_settings );
	}

	/** Deleting from in-db settings, as those were old Shortcodes saved before the update */
	public static function override_non_changeable_settings( $global_settings ) {
		if ( ! is_array( $global_settings ) ) {
			return $global_settings;
		}
		unset( $global_settings['bwfan_unsubscribe_button'] );
		unset( $global_settings['bwfan_subscriber_recipient'] );
		unset( $global_settings['bwfan_subscriber_name'] );

		return $global_settings;
	}

	/**
	 * fetching the providers from the array
	 *
	 * @param $providers
	 * @param $default
	 *
	 * @return false|mixed|string
	 */
	public static function get_provider_value( $providers, $default = '' ) {
		if ( ! empty( $default ) && isset( $providers[ $default ] ) ) {
			return $default;
		}

		$providers = array_keys( $providers );

		return end( $providers );
	}


	public static function get_default_global_settings( $global_settings = [] ) {
		$email_settings = self::get_global_email_settings( $global_settings );

		$defaults = array_replace( array(
			'bwfan_ac_b_s'                                 => 25,
			'bwfan_ac_t_l'                                 => 30,
			'bwfan_unsubscribe_button'                     => "[fka_unsubscribe_button label=__('Update my preference','wp-marketing-automations')]",
			'bwfan_subscriber_recipient'                   => '[fka_contact_email]',
			'bwfan_subscriber_name'                        => '[fka_contact_name]',
			'bwfan_unsubscribe_email_label'                => __( 'Unsubscribe', 'wp-marketing-automations' ),
			'bwfan_unsubscribe_data_success'               => __( 'Your subscription preference has been updated.', 'wp-marketing-automations' ),
			'bwfan_email_service'                          => self::get_default_email_provider(),
			'bwfan_sms_service'                            => self::get_default_sms_provider(),
			'bwfan_shortener_service'                      => self::get_default_shortener_provider(),
			'bwfan_whatsapp_service'                       => self::get_default_whatsapp_provider(),
			'bwfan_sandbox_mode'                           => false,
			'bwfan_make_logs'                              => 0,
			'bwfan_ab_enable'                              => 0,
			'bwfan_ab_exclude_users_cart_tracking'         => 0,
			'bwfan_ab_exclude_emails'                      => '',
			'bwfan_ab_exclude_roles'                       => array(),
			'bwfan_ab_init_wait_time'                      => 15,
			'bwfan_disable_abandonment_days'               => 15,
			'bwfan_ab_email_consent'                       => 0,
			'bwfan_ab_mark_lost_cart'                      => 15,
			'bwfan_order_tracking_conversion'              => 15,
			'bwfan_ab_restore_cart_message_success'        => '',
			'bwfan_ab_restore_cart_message_failure'        => __( 'Your cart could not be restored, it may have expired.', 'wp-marketing-automations' ),
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment
			'bwfan_ab_email_consent_message'               => sprintf( __( 'Your email and cart are saved so we can send you email reminders about this order. %s', 'wp-marketing-automations' ), '{{no_thanks label="' . __( 'No Thanks', 'wp-marketing-automations' ) . '"}}' ),
			'bwfan_user_consent'                           => 0,
			'bwfan_user_consent_message'                   => __( 'Keep me up to date on news and exclusive offers.', 'wp-marketing-automations' ),
			'bwfan_user_consent_eu'                        => '1',
			'bwfan_user_consent_non_eu'                    => '0',
			'bwfan_delete_autonami_generated_coupons_time' => 1,
			'bwfan_delete_engagement_tracking_meta'        => 0,
			'bwfan_user_consent_position'                  => 'below_term',
			'bwfan_user_consent_position_blocks'           => 'order',
			'bwfan_email_footer_setting'                   => '<p><span style="font-size: 14px; font-family: arial, helvetica, sans-serif;">{{business_name}}, {{business_address}}</span><br /><span style="font-size: 14px; font-family: arial, helvetica, sans-serif;">' . __( 'Don\'t want to stay in the loop? We\'ll be sad to see you go, but you can click here to', 'wp-marketing-automations' ) . ' <a href="{{unsubscribe_link}}">' . __( 'unsubscribe', 'wp-marketing-automations' ) . '</a></span></p>',
			'bwfan_sms_unsubscribe_text'                   => __( 'Reply STOP to unsubscribe', 'wp-marketing-automations' ),
			'bwfan_bounce_select'                          => '',
			'bwfan_unsubscribe_page'                       => '',
			'bwfan_unsubscribe_from_all_label'             => __( 'Unsubscribe from all Email Lists', 'wp-marketing-automations' ),
			'bwfan_unsubscribe_from_all_description'       => __( 'You will still receive important billing and transactional emails', 'wp-marketing-automations' ),
			'after_confirmation_type'                      => 'show_message',
			'bwfan_confirmation_message'                   => '<h2>' . __( 'Subscription Confirmed', 'wp-marketing-automations' ) . '</h2><p>' . __( 'Your subscription to our list has been confirmed.', 'wp-marketing-automations' ) . '</p><p>' . __( 'Thank you for subscribing!', 'wp-marketing-automations' ) . '</p><p>&nbsp;</p>',
			'bwfan_enable_notification'                    => 0,
			'bwfan_contact_export_logging'                 => '',
			'bwfan_contact_query_logging'                  => '',
			'bwfan_webhook_received_logging'               => '',
			'bwfan_unsubscribe_button_text'                => __( 'Update my preference', 'wp-marketing-automations' ),
			'bwfan_unsubscribe_page_type'                  => 'custom',
			'bwfan_unsubscribe_page_title'                 => __( 'Unsubscribe', 'wp-marketing-automations' ),
			// Profile Page Settings
			'bwfan_unsubscribe_show_manage_link'           => false,
			'bwfan_profile_page'                           => 0,
			'bwfan_profile_page_title'                     => __( 'Manage Profile', 'wp-marketing-automations' ),
			'bwfan_profile_page_type'                      => 'prebuild',
			'bwfan_profile_manage_fields'                  => [ 'f_name', 'l_name', 'contact_no' ],
			'bwfan_enable_profile_list_description'        => false,
			'bwfan_enable_profile_tags'                    => false,
			'bwfan_profile_tags'                           => [],
			'bwfan_enable_profile_lists'                   => false,
			'bwfan_profile_lists'                          => [],
			'bwfan_setting_business_logo'                  => '',
			'bwfan_profile_fields_text'                    => __( 'Manage Fields', 'wp-marketing-automations' ),
			'bwfan_profile_lists_text'                     => __( 'Manage Lists', 'wp-marketing-automations' ),
			'bwfan_profile_tags_text'                      => __( 'Manage Tags', 'wp-marketing-automations' ),
			'bwfan_profile_button_text'                    => __( 'Update Details', 'wp-marketing-automations' ),
			'bwfan_profile_message_text'                   => __( 'Changes Updated Successfully', 'wp-marketing-automations' ),
			'bwfan_unsubscribe_lists_text'                 => __( 'Manage Lists', 'wp-marketing-automations' ),
			'bwfan_delete_engagement_tracking_meta_enable' => false,
			'bwfan_delete_expired_coupon'                  => false,
		), $email_settings );

		if ( self::is_whatsapp_services_enabled() ) {
			$defaults['bwfan_whatsapp_gap_btw_message'] = 1;
			$services                                   = self::get_whatsapp_services();
			if ( ! empty( $services ) ) {
				$defaults['bwfan_primary_whats_app_service'] = [
					[
						'key'   => $services[0]['value'],
						'label' => $services[0]['label']
					]
				];
			}
		}

		if ( true === apply_filters( 'bwfan_ab_delete_inactive_carts', false ) ) {
			$defaults['bwfan_ab_remove_inactive_cart_time'] = 30;
		}

		return apply_filters( 'bwfan_default_global_settings', $defaults );
	}

	public static function get_global_email_settings( $global = [] ) {
		$global_settings = array();

		/** Email Settings */
		if ( ! isset( $global['bwfan_email_from'] ) || empty( $global['bwfan_email_from'] ) ) {
			if ( bwfan_is_woocommerce_active() ) {
				$global_settings['bwfan_email_from'] = get_option( 'woocommerce_email_from_address' );
				$global_settings['bwfan_email_from'] = sanitize_email( $global_settings['bwfan_email_from'] );
			} else {
				$global_settings['bwfan_email_from'] = sanitize_email( get_bloginfo( 'admin_email' ) );
			}
		} else {
			$global_settings['bwfan_email_from'] = $global['bwfan_email_from'];
		}

		if ( ! isset( $global['bwfan_email_from_name'] ) || empty( $global['bwfan_email_from_name'] ) ) {
			if ( bwfan_is_woocommerce_active() ) {
				$global_settings['bwfan_email_from_name'] = get_option( 'woocommerce_email_from_name' );
				$global_settings['bwfan_email_from_name'] = wp_specialchars_decode( esc_html( $global_settings['bwfan_email_from_name'] ), ENT_QUOTES );
			} else {
				$global_settings['bwfan_email_from_name'] = wp_specialchars_decode( esc_html( get_bloginfo( 'name' ) ), ENT_QUOTES );
			}
		} else {
			$global_settings['bwfan_email_from_name'] = $global['bwfan_email_from_name'];
		}

		$global_settings['bwfan_email_reply_to']         = $global_settings['bwfan_email_from'];
		$global_settings['bwfan_email_per_second_limit'] = 15;
		$global_settings['bwfan_email_daily_limit']      = 10000;

		return array(
			'bwfan_email_from'             => $global_settings['bwfan_email_from'],
			'bwfan_email_from_name'        => $global_settings['bwfan_email_from_name'],
			'bwfan_email_reply_to'         => $global_settings['bwfan_email_reply_to'],
			'bwfan_email_per_second_limit' => $global_settings['bwfan_email_per_second_limit'],
			'bwfan_email_daily_limit'      => $global_settings['bwfan_email_daily_limit'],
		);
	}

	/**
	 * fetching the default email provider
	 * @return false|mixed|string
	 */
	public static function get_default_email_provider() {
		$default   = apply_filters( 'bwfan_default_email_services', 'wp' );
		$providers = self::get_email_services();

		return self::get_provider_value( $providers, $default );
	}

	public static function get_email_services() {
		$services = apply_filters( 'bwfan_email_services', array() );

		return ! empty( $services ) ? $services : array();
	}

	/**
	 * fetching default sms provider
	 * @return false|mixed|string
	 */
	public static function get_default_sms_provider() {
		$default   = apply_filters( 'bwfan_default_sms_services', 'bwfco_twilio' );
		$providers = self::get_sms_services();

		return self::get_provider_value( $providers, $default );
	}

	public static function get_sms_services() {
		$services = apply_filters( 'bwfan_sms_services', array() );

		return ! empty( $services ) ? $services : array();
	}

	/**
	 * fetching default push notification provider
	 * @return false|mixed|string
	 */
	public static function get_default_push_provider() {
		$default   = apply_filters( 'bwfan_default_push_services', 'bwfco_pushengage' );
		$providers = self::get_push_services();

		return self::get_provider_value( $providers, $default );
	}

	public static function get_push_services() {
		$services = apply_filters( 'bwfan_push_services', array() );

		return ! empty( $services ) ? $services : array();
	}

	/**
	 * it fetches the default shortener provider
	 * @return int|string
	 */
	public static function get_default_shortener_provider() {
		$default   = apply_filters( 'bwfan_default_shortener_services', '' );
		$providers = self::get_shortener_services();

		return self::get_provider_value( $providers, $default );
	}

	/**
	 * it fetches the shortener services and also have the filter to add new shortener services
	 * @return array|void
	 */
	public static function get_shortener_services() {
		$services = apply_filters( 'bwfan_shortener_services', array() );

		return ! empty( $services ) ? $services : array();
	}

	/**
	 * fetching the default whatsapp service
	 *
	 * @return false|mixed|string
	 */
	public static function get_default_whatsapp_provider() {
		$default   = apply_filters( 'bwfan_default_whatsapp_services', '' );
		$providers = self::get_whatsapp_services();

		return self::get_provider_value( $providers, $default );
	}

	/**
	 * fetching all the whatsapp services available and active
	 *
	 * @return array|mixed|void
	 */
	public static function get_whatsapp_services() {
		$services = apply_filters( 'bwfan_whatsapp_services', array() );

		return ! empty( $services ) ? $services : array();
	}

	/**
	 * Check for whatsapp services
	 *
	 * @return bool
	 */
	public static function is_whatsapp_services_enabled() {
		if ( ! bwfan_is_autonami_pro_active() || ! class_exists( 'WFCO_Autonami_Connectors_Core' ) || is_null( BWFCRM_Core()->conversation ) ) {
			return false;
		}

		$services = self::get_whatsapp_services();;
		if ( is_array( $services ) && count( $services ) > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Consent checkbox is not rendered when the setting is off or the logged-in contact is already subscribed.
	 * In both cases consent is treated as granted (classic checkout emits a hidden bwfan_user_consent=1,
	 * block checkout renders nothing, so the abandoned-cart capture falls back to this check).
	 *
	 * @return bool
	 */
	public static function is_marketing_consent_implicit() {
		$global_settings = self::get_global_settings();

		return empty( $global_settings['bwfan_user_consent'] ) || self::is_logged_in_contact_subscribed();
	}

	public static function add_user_consent_after_terms_and_conditions( $return = false, $field_priority = '' ) {
		$global_settings = self::get_global_settings();

		if ( self::is_marketing_consent_implicit() ) {
			if ( ! $return ) {
				echo '<input id="bwfan_user_consent" name="bwfan_user_consent" value="1" type="hidden" />';

				return;
			} else {
				return '<input id="bwfan_user_consent" name="bwfan_user_consent" value="1" type="hidden" />';
			}
		}

		$country_code            = self::maybe_get_user_country_code();
		$tax_supported_countries = WC()->countries->get_european_union_countries();
		$check                   = in_array( $country_code, $tax_supported_countries, true );

		if ( true === $check ) {
			/** EU country */
			$checked = 'checked';
			if ( empty( $global_settings['bwfan_user_consent_eu'] ) ) {
				$checked = '';
			}
		} else {
			/** Non EU country */
			$checked = 'checked';
			if ( empty( $global_settings['bwfan_user_consent_non_eu'] ) ) {
				$checked = '';
			}
		}

		$user_consent_message = self::get_user_consent_message_in_site_language( $global_settings );

		if ( ! $return ) {
			echo '<p class="bwfan_user_consent wfacp-form-control-wrapper wfacp-col-full wfacp-consent-term-condition form-row">';
			echo '<label for="bwfan_user_consent" class="checkbox">';
			echo '<input id="bwfan_user_consent" name="bwfan_user_consent" class="input-checkbox" type="checkbox" value="1" ' . esc_html( $checked ) . ' />';
			echo wp_kses_post( $user_consent_message );
			echo '</label>';
			echo '</p>';
		} else {
			$field_priority = ! empty( $field_priority ) ? 'data-priority="' . ( absint( $field_priority ) + 5 ) . '"' : '';
			$return         = '<p class="bwfan_user_consent wfacp-form-control-wrapper wfacp-col-full wfacp-consent-term-condition form-row" ' . $field_priority . '>';
			$return         .= '<label for="bwfan_user_consent" class="checkbox">';
			$return         .= '<input id="bwfan_user_consent" name="bwfan_user_consent" class="input-checkbox" type="checkbox" value="1" ' . esc_html( $checked ) . ' />';
			$return         .= wp_kses_post( $user_consent_message );
			$return         .= '</label>';
			$return         .= '</p>';

			return $return;
		}
	}

	/**
	 * getting checkout consent message in site language
	 *
	 * @param $global_settings
	 *
	 * @return mixed
	 */
	public static function get_user_consent_message_in_site_language( $global_settings ) {
		$site_language = self::get_site_current_language();
		$site_language = ! empty( $site_language ) ? $site_language : get_locale();

		$message_index = 'bwfan_user_consent_message_' . $site_language;

		return isset( $global_settings[ $message_index ] ) && ! empty( $global_settings[ $message_index ] ) ? $global_settings[ $message_index ] : $global_settings['bwfan_user_consent_message'];
	}

	public static function get_site_current_language() {

		/** WPML */
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			global $sitepress;

			return $sitepress->get_current_language();
		}

		/** Polylang */
		if ( function_exists( 'pll_the_languages' ) ) {
			return pll_current_language( 'locale' );
		}

		/** TranslatePress **/
		if ( bwfan_is_translatepress_active() ) {
			global $TRP_LANGUAGE;

			return $TRP_LANGUAGE;
		}

		/** Weglot */
		if ( defined( 'BWFAN_VERSION' ) && version_compare( BWFAN_VERSION, '2.0.2', '>' ) && function_exists( 'bwfan_is_weglot_active' ) && bwfan_is_weglot_active() ) {
			$request_url_service = weglot_get_request_url_service();
			$lang_obj            = $request_url_service->get_current_language();
			$current_language    = $lang_obj->getExternalCode();
			$current_language    = empty( $current_language ) ? $lang_obj->getInternalCode() : $current_language;

			return $current_language;
		}

		/** GTranslate */
		if ( function_exists( 'bwfan_is_gtranslate_active' ) && bwfan_is_gtranslate_active() && class_exists( 'BWFAN_Compatibility_With_GTRANSLATE' ) ) {
			return BWFAN_Compatibility_With_GTRANSLATE::get_gtranslate_language();
		}

		/** Linguise */
		if ( function_exists( 'bwfan_is_linguise_active' ) && bwfan_is_linguise_active() && class_exists( '\Linguise\WordPress\Helper' ) ) {
			return \Linguise\WordPress\Helper::getLanguage();
		}
	}

	/**
	 * may be get user country from woocommerce session
	 * @return array|mixed|string
	 */
	public static function maybe_get_user_country_code() {

		if ( ! is_null( WC()->session ) ) {
			$country_code = WC()->session->get( 'bwfan_user_checkout_country', '' );
			if ( ! empty( $country_code ) ) {
				return $country_code;
			}

			$country_code = self::get_user_country();
			WC()->session->set( 'bwfan_user_checkout_country', $country_code );

			return $country_code;

		}

		$country_code = self::get_user_country();

		return $country_code;
	}

	/** get user country
	 * @return mixed
	 */
	public static function get_user_country() {
		$user_location = WC_Geolocation::geolocate_ip();
		$country_code  = $user_location['country'];

		return $country_code;
	}

	/**
	 * Restrict product link display for product layouts in emails
	 *
	 * @return mixed|void
	 */
	public static function disable_product_link() {
		return apply_filters( 'bwfan_disable_product_link', false );
	}

	/**
	 * Restrict thumbnails display for product layouts in emails
	 *
	 * @return mixed|void
	 */
	public static function disable_product_thumbnail() {
		return apply_filters( 'bwfan_disable_product_thumbnail', false );
	}

	/**
	 * Shift a site-time DateTime back to GMT.
	 *
	 * The offset is already signed, so it is negated rather than prefixed with '-'. Prefixing
	 * produced '--25200 seconds' for zones west of Greenwich, which PHP reads as a positive
	 * shift. It happened to be right here, and wrong-looking everywhere.
	 *
	 * @param DateTime $datetime
	 */
	public static function convert_to_gmt( $datetime ) {
		$datetime->modify( ( - self::get_timezone_offset() ) * HOUR_IN_SECONDS . ' seconds' );
	}

	/**
	 * Site UTC offset in hours, signed and possibly fractional (India +5.5, Nepal +5.75, Newfoundland -2.5).
	 *
	 * Resolved through `wp_timezone()` so this agrees with `wp_date()` / `date_i18n()` by construction.
	 * Reading `timezone_string` / `gmt_offset` directly made this a second, independent resolution of the
	 * site timezone. Both resolutions consult the same two options, so they agree on any untampered
	 * install — but they were separate reads, and anything filtering `option_timezone_string` /
	 * `option_gmt_offset` inconsistently could drive them apart. Callers would then shift by an offset
	 * the formatter did not agree with, and the output was out by the full divergence. Going through
	 * `wp_timezone()` deletes the second read rather than trying to keep two in step; it covers both
	 * cases already, falling back to a ±HH:MM zone built from `gmt_offset`.
	 *
	 * Prompted by #4834, where merge tag output was 14 hours out on a site whose timezone read as
	 * `America/Los_Angeles`. That divergence was never reproduced — the implementation replaced here
	 * returns -7 for that zone, not the +7 the reported output implies — so treat #4834 as the symptom
	 * that motivated this, not as a description of what it fixes.
	 *
	 * Known limitation: DST is evaluated at the current moment, not at the timestamp being converted,
	 * so a January date converted in July gets the summer offset. Pre-existing, and unchanged here
	 * because every caller shares it. Tracked separately.
	 *
	 * @return float|int Whole-hour zones come back as int, since PHP's `/` returns int when it divides evenly.
	 */
	public static function get_timezone_offset() {
		return wp_timezone()->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) ) / HOUR_IN_SECONDS;
	}

	public static function convert_to_site_time( $date ) {
		return self::convert_from_gmt( $date );
	}

	/**
	 * Shift a GMT DateTime to site time.
	 *
	 * The offset is already signed, so it is used as-is. Prefixing '+' produced
	 * '+-25200 seconds' for zones west of Greenwich: valid to PHP, but it reads as a bug
	 * and invited "corrections" that truncated fractional offsets such as India +5.5.
	 *
	 * @param DateTime $datetime
	 *
	 * @return mixed
	 */
	public static function convert_from_gmt( $datetime ) {
		return $datetime->modify( self::get_timezone_offset() * HOUR_IN_SECONDS . ' seconds' );
	}

	/**
	 * @param $screen_type
	 *
	 * @return bool
	 */
	public static function is_load_admin_assets( $screen_type = 'single' ) {
		$page = filter_input( INPUT_GET, 'page' );
		if ( empty( $page ) ) {
			return false;
		}
		if ( 'all' === $screen_type ) {
			$is_autonami = ( false !== strpos( $page, 'autonami' ) );
			if ( $page === 'autonami' || $is_autonami ) {
				return true;
			}
		} elseif ( 'builder' === $screen_type ) {
			if ( $page === 'autonami-automations' && filter_input( INPUT_GET, 'edit' ) > 0 ) {
				return true;
			}
		} elseif ( 'all' === $screen_type || 'builder' === $screen_type ) {
			if ( $page === 'autonami-automations' && filter_input( INPUT_GET, 'edit' ) > 0 ) {
				return true;
			}
		} elseif ( 'all' === $screen_type || 'settings' === $screen_type ) {
			if ( $page === 'autonami-settings' || false !== strpos( (string) filter_input( INPUT_GET, 'path' ), 'settings' ) ) {
				return true;
			}
		} elseif ( 'automation' === $screen_type ) {
			if ( $page === 'autonami-automations' && filter_input( INPUT_GET, 'edit' ) > 0 ) {
				return true;
			}
		} elseif ( 'recipe' === $screen_type ) {
			if ( $page === 'autonami-automations' && filter_input( INPUT_GET, 'tab' ) === 'recipe' ) {
				return true;
			}
		}
		$screen = get_current_screen();

		return apply_filters( 'bwfan_enqueue_scripts', false, $screen_type, $screen );
	}

	public static function array_flatten( $array ) {
		if ( ! is_array( $array ) ) {
			return false;
		}
		$result = iterator_to_array( new RecursiveIteratorIterator( new RecursiveArrayIterator( $array ) ), false );

		return $result;
	}

	public static function pr( $arr ) {
		echo '<pre>';
		print_r( $arr ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions
		echo '</pre>';
	}

	public static function pc( $val1, $val2 = '' ) {
		if ( ! class_exists( 'pc' ) ) {
			return;
		}
		pc::debug( $val1, $val2 ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	/**
	 * Slug-ify the class name and remove underscores and convert it to filename
	 * Helper function for the auto-loading
	 *
	 * @param $class_name
	 *
	 * @return mixed|string
	 * @see BWFAN_Gateways::integration_autoload();
	 */
	public static function slugify_classname( $class_name ) {
		$classname = sanitize_title( $class_name );
		$classname = str_replace( '_', '-', $classname );

		return $classname;
	}

	public static function maybe_convert_html_tag( $val ) {
		if ( false === is_string( $val ) ) {
			return $val;
		}
		$val = str_replace( '&lt;', '<', $val );
		$val = str_replace( '&gt;', '>', $val );

		return $val;
	}

	public static function string2hex( $string ) {
		$hex = '';
		for ( $i = 0; $i < strlen( $string ); $i ++ ) {
			$hex .= dechex( ord( $string[ $i ] ) );
		}

		return $hex;
	}

	/**
	 * Return sidebar options on single automation screen.
	 *
	 * @return mixed|void
	 */
	public static function get_sidebar_menu() {
		$sidebar_menu = array(

			'20' => array(
				'icon' => 'dashicons dashicons-networking',
				'name' => __( 'Automation', 'wp-marketing-automations' ),
				'key'  => 'automation',
			),
			'50' => array(
				'icon' => 'dashicons dashicons-admin-tools',
				'name' => __( 'Tools', 'wp-marketing-automations' ),
				'key'  => 'tools',
			),
		);

		return apply_filters( 'bwfan_builder_menu', $sidebar_menu );
	}

	/**
	 * Checks if the current page is autonami page or not.
	 *
	 * @return bool
	 */
	public static function is_autonami_page() {
		if ( isset( $_GET['page'] ) && 'autonami-automations' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}

		return false;
	}

	/**
	 * Remove autonami events on plugin deactivation.
	 */
	public static function deactivation() {
		if ( bwf_has_action_scheduled( 'bwfan_run_queue' ) ) {
			bwf_unschedule_actions( 'bwfan_run_queue' );
		}
		if ( bwf_has_action_scheduled( 'bwfan_check_abandoned_carts' ) ) {
			bwf_unschedule_actions( 'bwfan_check_abandoned_carts' );
		}
		if ( bwf_has_action_scheduled( 'bwfan_delete_expired_autonami_coupons' ) ) {
			bwf_unschedule_actions( 'bwfan_delete_expired_autonami_coupons' );
		}
		if ( bwf_has_action_scheduled( 'bwfan_mark_abandoned_lost_cart' ) ) {
			bwf_unschedule_actions( 'bwfan_mark_abandoned_lost_cart' );
		}
		if ( bwf_has_action_scheduled( 'bwfan_delete_old_abandoned_carts' ) ) {
			bwf_unschedule_actions( 'bwfan_delete_old_abandoned_carts' );
		}
		if ( class_exists( 'BWFAN_Announcements' ) ) {
			BWFAN_Announcements::deactivate( 'fka' );
		}
	}

	/**
	 * Send a remote call.
	 *
	 * @param $api_url
	 * @param $data
	 * @param string $method_type
	 *
	 * @return array|mixed|object|string|null
	 */
	public static function send_remote_call( $api_url, $data, $method_type = 'post' ) {
		if ( 'get' === $method_type ) {
			$httpPostRequest = self::http()->get( $api_url, array(
				'body'      => $data,
				'sslverify' => false,
				'timeout'   => 30,
			) );
		} else {
			$httpPostRequest = self::http()->post( $api_url, array(
				'body'      => $data,
				'sslverify' => false,
				'timeout'   => 30,
			) );
		}

		if ( isset( $httpPostRequest->errors ) ) {
			$response = null;
		} elseif ( isset( $httpPostRequest['body'] ) && '' !== $httpPostRequest['body'] ) {
			$body     = $httpPostRequest['body'];
			$response = json_decode( $body, true );
		} else {
			$response = 'No result';
		}

		return $response;
	}

	public static function http() {
		if ( null === self::$http ) {
			self::$http = new WP_Http();
		}

		return self::$http;
	}

	/**
	 * Send JSON response and close HTTP connection while keeping PHP process running
	 * This allows background processing after the client receives the response
	 *
	 * NOTE: On hosts without fastcgi_finish_request/litespeed_finish_request, the REST
	 * fallback registers a rest_pre_serve_request closure that emits $response_data as the
	 * body and returns true (already-served). This OVERRIDES any WP_REST_Response a REST
	 * callback might otherwise return. Do not call from a REST endpoint that needs to
	 * deliver a meaningful response body via the normal pipeline — only from "fire-and-forget"
	 * worker callbacks where the JSON payload here IS the entire response.
	 *
	 * @param array|mixed $response_data Response data to send as JSON
	 * @param int $status_code HTTP status code (default: 200)
	 *
	 * @return bool True when the client connection was actually detached (FastCGI/LiteSpeed)
	 *              and the caller may safely `exit;` after its background work. False when the
	 *              connection could not be detached (caller should return through the normal
	 *              REST pipeline so WordPress finishes the request without "headers already sent").
	 */
	public static function send_response_and_close_connection( $response_data = [], $status_code = 200 ) {
		static $already_called = false;

		// Prevent duplicate calls
		if ( $already_called ) {
			return false;
		}
		$already_called = true;

		// Check if headers already sent - function cannot work properly in this case
		if ( headers_sent( $file, $line ) ) {
			return false;
		}

		// Whether this host exposes a way to detach the client connection while PHP keeps running.
		$can_finish_request = function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );

		// Keep PHP running if the client disconnects (e.g. the loopback pinger's short timeout fires)
		// before background work finishes. Must run BEFORE the REST fallback below, which returns
		// without detaching the connection and would otherwise leave the worker unprotected from abort.
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true ); // disabled via disable_functions on some hosts (RunCloud default)
		}

		// Close session to release lock (if active)
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close();
		}

		/**
		 * Fallback: in a REST context with no way to detach the connection, committing output
		 * mid-dispatch (echo + buffer purge) flips headers_sent() true while WP_REST_Server and
		 * host cache layers still intend to emit headers, producing "headers already sent"
		 * warnings on every request. Instead, defer body emission to rest_pre_serve_request
		 * (fires before core sends headers) and report that the connection was NOT closed so the
		 * caller returns through the normal REST pipeline.
		 */
		if ( ! $can_finish_request && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			add_filter( 'rest_pre_serve_request', function ( $served ) use ( $response_data, $status_code ) {
				// status_header() is self-guarded against headers_sent(), so this is safe to call unconditionally
				// and lets a non-200 $status_code override the 200 WP queues from a null dispatch result.
				status_header( $status_code );
				if ( ! headers_sent() ) {
					header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
				}
				$json = wp_json_encode( $response_data );
				echo ( false === $json ) ? '{"success":false}' : $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body.

				return true; // Tell WordPress the request is already served.
			}, 10, 1 );

			return false;
		}

		// Disable compression BEFORE calculating Content-Length to ensure accurate byte count
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- turns off output compression so the streamed file Content-Length stays accurate

		// Clean (discard) all existing output buffers - prevents WordPress buffered content from interfering
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Encode response
		$json_response = wp_json_encode( $response_data );

		// Handle JSON encoding failure
		if ( false === $json_response ) {
			$json_response = wp_json_encode( array(
				'success' => false,
				'message' => 'Response encoding failed',
			) );
		}

		// Final fallback if encoding the error message also fails
		if ( false === $json_response ) {
			$json_response = '{"success":false}';
		}

		// Calculate content length in bytes
		$content_length = strlen( $json_response );

		// Check again if headers already sent before trying to set them
		// This can happen if WordPress REST API has already started sending headers
		if ( ! headers_sent() ) {
			// Set headers BEFORE any output
			status_header( $status_code );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			header( 'Content-Length: ' . $content_length );
			header( 'Connection: close' );
			header( 'X-Accel-Buffering: no' ); // Prevent Nginx proxy buffering
		}

		// Send output
		echo $json_response; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body.

		// Flush output to network
		flush();

		// Close connection - FastCGI/LiteSpeed provide the cleanest methods.
		// Returns true so the caller can `exit;` after background work: this prevents
		// WP_REST_Server and any host cache layer from calling header() against the
		// already-committed output (the "headers already sent" log flood).
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();

			return true;
		}

		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();

			return true;
		}

		return false;
	}

	/**
	 * Return all the merge tags from a string.
	 *
	 * @param $text
	 *
	 * @return array|null
	 */
	public static function get_merge_tags_from_text( $text ) {
		$merge_tags      = null;
		$more_merge_tags = null;
		if ( ! is_array( $text ) ) {
			preg_match_all( '/\{{(.*?)\}}/', $text, $more_merge_tags );
		}

		if ( is_array( $more_merge_tags[1] ) && count( $more_merge_tags[1] ) > 0 ) {
			$merge_tags = $more_merge_tags[1];
		}

		return $merge_tags;
	}
	/**
	 * WP stores term names HTML-encoded ("Dairy &amp; Eggs"), so a WC REST category/tag search for
	 * "Dairy & Eggs" never matches. Normalize the search to the stored (encoded) form.
	 */
	public static function normalize_term_rest_search( $args, $request ) {
		if ( ! empty( $args['search'] ) && is_string( $args['search'] ) ) {
			$args['search'] = htmlspecialchars( wp_specialchars_decode( $args['search'], ENT_QUOTES ), ENT_NOQUOTES );
		}

		return $args;
	}

	/**
	 * Return the merge tags which will behave as array.
	 *
	 * @param $merge_tags
	 * @param $integration_merge_tags
	 * @param $action_data
	 *
	 * @return array
	 */
	public static function initial_parse_merge_tags( $merge_tags, $integration_merge_tags, $action_data ) {
		$dynamic_array = array();
		if ( ! is_array( $action_data ) || count( $action_data ) === 0 ) {
			return $dynamic_array;
		}
		foreach ( $action_data as $key1 => $value1 ) {
			if ( ! is_array( $value1 ) || count( $value1 ) === 0 ) {
				$dynamic_array[ $key1 ] = $value1;
				continue;
			}

			foreach ( $value1 as $key2 => $value2 ) {
				if ( ! in_array( $key2, $merge_tags, true ) || ( ! is_array( $integration_merge_tags ) || ! in_array( $key2, $integration_merge_tags, true ) ) ) {
					$dynamic_array[ $key2 ] = $value2;
					continue;
				}

				if ( isset( $dynamic_array[ $key2 ] ) ) {
					array_push( $dynamic_array[ $key2 ], $value2 );
				} else {
					$dynamic_array[ $key2 ] = array( $value2 );
				}
			}
		}

		return $dynamic_array;
	}

	public static function filter_tasks( $all_tasks, $all_tasks_meta ) {
		$result = array();
		foreach ( $all_tasks_meta as $value1 ) {
			if ( isset( $value1['bwfan_task_id'] ) ) {
				$id = $value1['bwfan_task_id'];
			} elseif ( isset( $value1['bwfan_log_id'] ) ) {
				$id = $value1['bwfan_log_id'];
			}
			if ( isset( $all_tasks[ $id ] ) ) {
				$result['all_tasks'][ $id ] = array(
					$all_tasks[ $id ]['integration_slug'] => $all_tasks[ $id ]['integration_action'],
				);
				if ( 'integration_data' === $value1['meta_key'] ) {
					$meta                            = maybe_unserialize( $value1['meta_value'] );
					$meta['automation_id']           = $all_tasks[ $id ]['automation_id'];
					$result['all_tasks_meta'][ $id ] = $meta;
				}
				$result['all_tasks_status'][ $id ]        = $all_tasks[ $id ]['status'];
				$result['all_tasks_automation_id'][ $id ] = $all_tasks[ $id ]['automation_id'];
				$result['all_tasks_attempts'][ $id ]      = $all_tasks[ $id ]['attempts'];
			}
		}

		return $result;
	}

	/**
	 * Remove backslashes from $_POST content of the automation.
	 *
	 * @param $posted_data
	 *
	 * @return array
	 */
	public static function remove_back_slash_from_automation( $posted_data ) {
		if ( ! is_array( $posted_data ) || count( $posted_data ) === 0 ) {
			return $posted_data;
		}
		foreach ( $posted_data as $key1 => $value1 ) {
			if ( isset( $value1['ajax_data'] ) ) {
				$posted_data[ $key1 ]['ajax_data'] = self::remove_backslashes( $value1['ajax_data'] );
			}
			if ( isset( $value1['data']['field_value'] ) && ! is_array( $value1['data']['field_value'] ) ) {
				$posted_data[ $key1 ]['data']['field_value'] = self::remove_newlines( self::remove_backslashes( $value1['data']['field_value'] ) );
			}
		}

		return $posted_data;
	}

	public static function remove_backslashes( $string ) {
		return preg_replace( '/\\\\/', '', $string );
	}

	public static function remove_newlines( $string ) {
		return trim( preg_replace( '/\s+/', ' ', $string ) );
	}

	/**
	 * Get all the merge tags of all the events.
	 *
	 * @param $all_sources
	 *
	 * @return array
	 */
	public static function get_all_events_merge_tags() {
		$all_events_merge_tags = array();
		$merge_tags            = BWFAN_Core()->merge_tags->get_localize_tags_with_source();
		$source_events         = BWFAN_Core()->sources->get_events();

		$merge_tags = self::get_v1_supported_mergetag( $merge_tags );

		/**
		 * @var $event_object BWFAN_Event
		 */
		foreach ( $source_events as $event_key => $event_object ) {
			$event_merge_tags = $event_object->get_merge_tag_groups();
			if ( empty( $event_merge_tags ) ) {
				continue;
			}

			$curr_event_merge_tags = array();
			foreach ( $event_merge_tags as $head ) {
				if ( ! isset( $merge_tags[ $head ] ) ) {
					continue;
				}
				$curr_event_merge_tags[ $head ] = $merge_tags[ $head ];
			}
			$all_events_merge_tags[ $event_key ] = apply_filters( 'bwfan_default_merge_tags', $curr_event_merge_tags );
		}

		return $all_events_merge_tags;
	}

	/** get the supported v1 merge tag */
	public static function get_v1_supported_mergetag( $all_merge_tags ) {
		if ( empty( $all_merge_tags ) ) {
			return $all_merge_tags;
		}
		/** Filter v1 merge tags */
		foreach ( $all_merge_tags as $mergeGroup => $mergeTagList ) {
			if ( empty( $mergeTagList ) ) {
				continue;
			}
			$final_Arr = [];
			foreach ( $mergeTagList as $mergeTagKey => $mergeTagData ) {
				/** check if the merge tag not support v1 */
				if ( isset( $mergeTagData['support_v1'] ) && empty( $mergeTagData['support_v1'] ) ) {
					unset( $all_merge_tags[ $mergeGroup ][ $mergeTagKey ] );
					continue;
				}
				$final_Arr[ $mergeTagKey ] = $mergeTagData;
			}
			if ( ! empty( $final_Arr ) ) {
				$all_merge_tags[ $mergeGroup ] = $final_Arr;
			}
		}

		return $all_merge_tags;
	}

	/**
	 * @param $all_sources
	 *
	 * @return array
	 */
	public static function get_all_events_rules() {
		$all_rules_groups = array();
		$events           = BWFAN_Core()->sources->get_events();
		if ( empty( $events ) ) {
			return $all_rules_groups;
		}
		/** Null check */
		if ( ! is_object( BWFAN_Core()->rules ) || ! method_exists( BWFAN_Core()->rules, 'get_default_rule_groups' ) ) {
			return $all_rules_groups;
		}
		/**
		 * @var $event BWFAN_Event
		 */
		foreach ( $events as $slug => $event ) {
			$all_rules_groups[ $slug ] = array_merge( $event->get_rule_group(), BWFAN_Core()->rules->get_default_rule_groups() );
		}

		return $all_rules_groups;
	}

	public static function sort_automations( $all_automations ) {
		$all_automations_temp = array();
		$nice_names           = array();

		if ( is_array( $all_automations ) && count( $all_automations ) > 0 ) {
			foreach ( $all_automations as $int_slug => $int_obj ) {
				$nice_names[] = $int_obj->get_name();
			}
			asort( $nice_names );

			foreach ( $nice_names as $int_nice_name ) {
				foreach ( $all_automations as $int_slug => $int_obj ) {
					if ( $int_nice_name === $int_obj->get_name() ) {
						$all_automations_temp[ $int_slug ] = $int_obj;
					}
				}
			}

			$all_automations = $all_automations_temp;
		}

		return $all_automations;
	}

	/**
	 * Save integration data for a connector.
	 *
	 * @param $data
	 * @param $slug
	 * @param $status
	 *
	 * @return int
	 */
	public static function save_integration_data( $data, $slug, $status ) {
		$new_task_data                     = array();
		$new_task_data['last_sync']        = current_time( 'timestamp', 1 );
		$new_task_data['integration_slug'] = $slug;
		$new_task_data['api_data']         = maybe_serialize( $data );
		$new_task_data['status']           = $status;
		BWFAN_Model_Settings::insert( $new_task_data );

		return BWFAN_Model_Settings::insert_id();
	}

	/**
	 * Update integration data for a connector.
	 *
	 * @param $data
	 * @param $id
	 */
	public static function update_integration_data( $data, $id ) {
		$meta_data              = array();
		$meta_data['api_data']  = maybe_serialize( $data );
		$meta_data['last_sync'] = current_time( 'timestamp', 1 );
		$where                  = array(
			'ID' => $id,
		);
		BWFAN_Model_Settings::update( $meta_data, $where );
	}

	public static function get_parsed_time( $wp_date_format, $logs ) {
		$logs_temp = array();

		$logs_temp[ date( 'Y-m-d H:i:s' ) ] = __( 'Error in generating logs', 'wp-marketing-automations' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
		if ( ! is_array( $logs ) || count( $logs ) === 0 ) {
			return array_reverse( $logs_temp, true );
		}

		$logs_temp = array();
		foreach ( $logs as $timestamp => $message ) {
			$time = get_date_from_gmt( date( 'Y-m-d H:i:s', $timestamp ), $wp_date_format ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
			if ( empty( $message ) ) {
				$message = __( 'No response from API', 'wp-marketing-automations' );
			} else {
				$message = str_replace( "'", '', $message );
			}
			$logs_temp[ $time ] = $message;
		}

		return array_reverse( $logs_temp, true );
	}

	public static function string( $string ) {
		return sanitize_text_field( $string );
	}

	public static function add_default_merge_tags( $event_merge_tags ) {
		$default_merge_tags = self::get_default_merge_tags( false );
		foreach ( $default_merge_tags as $merge_tag => $details ) {
			$event_merge_tags[ $merge_tag ] = $details[0];
		}

		return $event_merge_tags;
	}

	public static function get_default_merge_tags( $load_values ) {
		$current_date      = null;
		$current_time      = null;
		$current_date_time = null;

		if ( $load_values ) {
			$cdt               = self::$date_format . ' ' . self::$time_format;
			$ct                = self::$time_format;
			$cd                = self::$date_format;
			$current_date_time = get_date_from_gmt( date( 'Y-m-d H:i:s' ), $cdt ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
			$current_time      = get_date_from_gmt( date( 'H:i:s' ), $ct ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
			$current_date      = get_date_from_gmt( date( 'Y-m-d' ), $cd ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
		}
		$default_merge_tags = array(
			'admin_email'       => array( __( 'Admin email', 'wp-marketing-automations' ), self::$admin_email ),
			'current_date'      => array( __( 'Current date when task will be executed', 'wp-marketing-automations' ), $current_date ),
			'current_time'      => array( __( 'Current time when task will be executed', 'wp-marketing-automations' ), $current_time ),
			'current_date_time' => array( __( 'Current date and time when task will be executed', 'wp-marketing-automations' ), $current_date_time ),
		);

		return apply_filters( 'bwfan_modify_default_merge_tags', $default_merge_tags );
	}

	public static function parse_default_merge_tags( $data, $recursive = false ) {
		if ( ! is_array( $data ) || count( $data ) === 0 ) {
			return $data;
		}

		$default_merge_tags_values = self::get_default_merge_tags( true );

		/**         *
		 * This function only decode two level array
		 */
		foreach ( $default_merge_tags_values as $merge_tag => $details ) {
			foreach ( $data as $key1 => $value1 ) {
				if ( in_array( gettype( $value1 ), array( 'int', 'boolean' ), true ) ) {
					continue;
				}

				if ( is_array( $value1 ) ) {
					if ( empty( $value1 ) ) {
						continue;
					}
					foreach ( $value1 as $key2 => $value2 ) {
						if ( is_array( $value2 ) ) {
							$data[ $key1 ][ $key2 ] = $value2;
						} else {
							if ( false !== strpos( $value2, '{{' . $merge_tag . '}}' ) ) {
								$data[ $key1 ][ $key2 ] = str_replace( '{{' . $merge_tag . '}}', $details[1], $value2 );
							}
						}
					}
				} else {

					if ( is_object( $value1 ) ) { // ignore if the value is object type in case of leandash events
						continue;
					}

					if ( $value1 && false !== strpos( $value1, '{{' . $merge_tag . '}}' ) ) {
						$data[ $key1 ] = str_replace( '{{' . $merge_tag . '}}', $details[1], $value1 );
						continue;
					}
				}
			}
		}

		return $data;
	}

	public static function filter_actions_conditions( $selected_actions, $automation_details ) {
		$automation_actions    = $automation_details['actions'];
		$automation_conditions = ( isset( $automation_details['condition'] ) ) ? $automation_details['condition'] : array();
		$temp_actions          = array();
		$temp_conditions       = array();

		foreach ( $selected_actions as $single_indexes ) {
			$single_ind                             = explode( '_', $single_indexes );
			$group_id                               = $single_ind[0];
			$child_id                               = $single_ind[1];
			$temp_actions[ $group_id ][ $child_id ] = $automation_actions[ $group_id ][ $child_id ];
		}

		if ( is_array( $automation_conditions ) && count( $automation_conditions ) > 0 ) {
			foreach ( $temp_actions as $group_id => $actions ) {
				/** Checking if group id not present in the condition */
				if ( ! isset( $automation_conditions[ $group_id ] ) ) {
					continue;
				}
				/** Checking if actions empty */
				if ( ! is_array( $actions ) || 0 === count( $actions ) ) {
					continue;
				}
				/** Checking if action id  condition not exist */
				foreach ( $actions as $action_id => $action_data ) {
					if ( ! isset( $automation_conditions[ $group_id ][ $action_id ] ) ) {
						continue;
					}
					if ( ! is_array( $action_data ) ) {
						continue;
					}
					$temp_conditions[ $group_id ][ $action_id ] = $action_data;
				}
			}
		}

		$automation_details['actions']   = $temp_actions;
		$automation_details['condition'] = $temp_conditions;

		return $automation_details;
	}

	/**
	 *
	 * Check for the user order count , if not found in the usermeta then fires wpdb query
	 *
	 * @param int $user_id
	 *
	 * @return int
	 * @since 2.7.1
	 */
	public static function get_customer_order_count( $user_id, $force = false ) {
		global $wpdb;

		$order_types      = array_values( (array) wc_get_order_types( 'order-count' ) );
		$trashed_statuses = array_values( (array) self::get_order_trashed_statuses() );

		// Fallback to array( '' ) so a filtered-out list never yields an invalid `IN ()`
		$order_types      = empty( $order_types ) ? array( '' ) : $order_types;
		$trashed_statuses = empty( $trashed_statuses ) ? array( '' ) : $trashed_statuses;
		$type_holders     = implode( ',', array_fill( 0, count( $order_types ), '%s' ) );
		$status_holders   = implode( ',', array_fill( 0, count( $trashed_statuses ), '%s' ) );
		$args             = array_merge( $order_types, $trashed_statuses, array( $user_id ) );

		if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
			$query = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders as orders WHERE orders.type IN ({$type_holders}) AND orders.status NOT IN ({$status_holders}) AND orders.customer_id = %d", ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $type_holders/$status_holders are array_fill() '%s' placeholder lists; every value is bound via ...$args
			$count = $wpdb->get_var( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

			return empty( $wpdb->last_error ) && ! empty( $count ) ? intval( $count ) : 0;
		}

		$query = $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts as posts LEFT JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id WHERE   meta.meta_key= '_customer_user' AND posts.post_type IN ({$type_holders}) AND posts.post_status NOT IN ({$status_holders}) AND meta.meta_value= %d", ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $type_holders/$status_holders are array_fill() '%s' placeholder lists; every value is bound via ...$args

		$count = $wpdb->get_var( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

		if ( ! empty( $wpdb->last_error ) ) {
			return 0;
		}

		return intval( $count );
	}

	public static function get_order_trashed_statuses() {
		return apply_filters( 'bwfan_get_order_trashed_statuses', array( 'wc-cancelled', 'wc-refunded', 'wc-failed', 'trash', 'draft' ) );
	}

	public static function get_funnel_data( $funnel_id ) {
		$data                = array();
		$data['funnel_id']   = $funnel_id;
		$funnel_details      = get_post( $funnel_id );
		$data['funnel_name'] = $funnel_details->post_title;

		return $data;
	}

	public static function get_offer_data( $offer_id ) {
		$data               = array();
		$data['offer_id']   = $offer_id;
		$offer_details      = get_post( $offer_id );
		$data['offer_name'] = $offer_details->post_title;
		$data['offer_type'] = get_post_meta( $offer_id, '_offer_type', true );

		return $data;
	}

	/**
	 * Return subscription products by searched term.
	 *
	 * @param $searched_term
	 *
	 * @return array
	 * @throws Exception
	 */
	public static function get_subscription_product( $searched_term ) {
		$subscription_products = array();
		$results               = array();
		$term                  = $searched_term;
		$include_variations    = true;
		$data_store            = WC_Data_Store::load( 'product' );
		$ids                   = $data_store->search_products( $term, '', (bool) $include_variations );
		$product_objects       = array_filter( array_map( 'wc_get_product', $ids ), 'wc_products_array_filter_readable' );

		foreach ( $product_objects as $product_object ) {
			if ( WC_Subscriptions_Product::is_subscription( $product_object ) ) {
				$results[] = array(
					'id'   => $product_object->get_id(),
					'text' => rawurldecode( $product_object->get_formatted_name() ),
				);
			}
		}
		$subscription_products['results'] = $results;

		return $subscription_products;
	}

	/**
	 * Get membership plans by searched term.
	 *
	 * @param $searched_term
	 *
	 * @return array
	 */
	public static function get_membership_plans( $searched_term ) {
		$membership_plans = array();
		$results          = array();
		$query_params     = array(
			'post_type'      => 'wc_membership_plan',
			'posts_per_page' => - 1,
		);

		if ( '' !== $searched_term ) {
			$query_params['s'] = $searched_term;
		}

		$query = new WP_Query( $query_params );

		if ( $query->found_posts > 0 ) {
			foreach ( $query->posts as $post ) {
				$results[] = array(
					'id'   => $post->ID,
					'text' => $post->post_title,
				);
			}
		}

		$membership_plans['results'] = $results;

		return $membership_plans;
	}

	/**
	 * Get membership names by membership ids.
	 *
	 * @param $membership_plans
	 *
	 * @return array
	 */
	public static function get_membership_pre_data( $membership_plans ) {
		$plans = array();
		if ( is_array( $membership_plans ) && count( $membership_plans ) > 0 ) {
			foreach ( $membership_plans as $id ) {
				$plan_name    = get_the_title( $id );
				$plans[ $id ] = $plan_name;
			}
		}

		return $plans;
	}

	/**
	 * Get subscription names by subscription ids.
	 *
	 * @param $subscription_products
	 *
	 * @return array
	 */
	public static function get_subscription_pre_data( $subscription_products ) {
		$products = array();
		if ( is_array( $subscription_products ) && count( $subscription_products ) > 0 ) {
			foreach ( $subscription_products as $id ) {
				$product         = wc_get_product( $id );
				$product_name    = $product->get_formatted_name();
				$products[ $id ] = $product_name;
			}
		}

		return $products;
	}

	public static function delete_order_meta( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->delete_meta_data( '_bwfan_poid' );
		$order->delete_meta_data( '_bwfan_package' );
		$order->delete_meta_data( '_bwfan_fun_id' );
		$order->save();
	}

	public static function get_sorted_automations( $rows ) {
		$result = array();
		if ( is_array( $rows ) && count( $rows ) > 0 ) {
			foreach ( $rows as $value1 ) {
				$result[ $value1['ID'] ] = $value1;
			}
		}

		return $result;
	}

	public static function get_callable_object( $is_empty, $data ) {
		if ( in_array( 'get_' . $data['type'], self::$select2ajax_functions, true ) ) {
			return array( __CLASS__, 'get_' . $data['type'] );
		} else {
			return $is_empty;
		}
	}

	public static function get_product_image( $product, $size = 'shop_catalog', $only_url = false, $img_width = '' ) {
		$image_id = $product->get_image_id();

		$image_url = '';
		if ( ! empty( $image_id ) ) {
			$image_url = wp_get_attachment_image_url( $image_id, $size );
		}

		if ( empty( $image_url ) && function_exists( 'wc_placeholder_img_src' ) ) {
			$image_url = wc_placeholder_img_src( $size );
		}

		/** Correcting the image URL if not an http link **/
		if ( 'http' !== substr( $image_url, 0, 4 ) ) {
			$image_url = site_url( $image_url );
		}

		if ( $only_url ) {
			$image = $image_url;
		} else {
			$style = ! empty( $img_width ) ? "width='{$img_width}'" : '';
			$image = '<img src="' . $image_url . '" ' . $style . ' class="bwfan-product-image" alt="' . sanitize_text_field( self::get_name( $product ) ) . '">';
		}

		return $image;
	}

	/**
	 * @param $product WC_Product
	 *
	 * @return mixed
	 */
	public static function get_name( $product ) {
		return $product->get_name();
	}

	/**
	 * @param $order WC_Order
	 *
	 * @return array
	 */
	public static function get_order_cross_sells( $order ) {
		$cross_sells = array();
		$in_order    = array();
		$items       = $order->get_items();

		foreach ( $items as $item ) {
			$product     = $item->get_product();
			$in_order[]  = self::is_variation( $product ) ? $product->get_parent_id() : $product->get_id();
			$cross_sells = array_merge( $product->get_cross_sell_ids(), $cross_sells );
		}

		return array_diff( $cross_sells, $in_order );
	}

	public static function is_variation( $product ) {
		return $product->is_type( array( 'variation', 'subscription_variation' ) );
	}

	/**
	 * @param $name
	 *
	 * @return mixed
	 */
	public static function get_cookie( $name ) {
		return isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : false;
	}

	/**
	 * Clear a cookie.
	 *
	 * @param $name
	 */
	public static function clear_cookie( $name ) {
		if ( isset( $_COOKIE[ $name ] ) ) {
			self::set_cookie( $name, '', time() - HOUR_IN_SECONDS );
		}
	}

	/**
	 * Set a cookie - wrapper for setcookie using WP constants.
	 *
	 * @param string $name Name of the cookie being set.
	 * @param string $value Value of the cookie.
	 * @param integer $expire Expiry of the cookie.
	 * @param bool $secure Whether the cookie should be served only over https.
	 * @param bool $httponly Whether the cookie is only accessible over HTTP, not scripting languages like JavaScript. @since 3.6.0.
	 */
	public static function set_cookie( $name, $value, $expire = 0, $secure = null, $httponly = true ) {
		if ( self::is_cli() || self::is_cron() || self::is_rest() ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		$domain   = apply_filters( 'bwfan_cookie_domain', COOKIE_DOMAIN );
		$secure   = ( null === $secure ) ? is_ssl() : (bool) $secure;
		$httponly = apply_filters( 'bwfan_cookie_httponly', $httponly, $name, $value, $expire, $secure );
		setcookie( $name, $value, $expire, COOKIEPATH ? COOKIEPATH : '/', $domain, $secure, $httponly ); //phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
	}

	/**
	 * Checks whether the current request is a WP CLI request
	 *
	 * @return bool
	 */
	public static function is_cli() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks whether the current request is a WP cron request
	 *
	 * @return bool
	 */
	public static function is_cron() {
		if ( defined( 'DOING_CRON' ) && true === DOING_CRON ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks whether the current request is a WP rest request
	 *
	 * @return bool
	 */
	public static function is_rest() {
		if ( defined( 'REST_REQUEST' ) && true === REST_REQUEST ) {
			return true;
		}

		return false;
	}

	public static function get_line_subtotal( $item ) {
		return isset( $item['line_subtotal'] ) ? floatval( $item['line_subtotal'] ) : 0;
	}

	/**
	 * @return float
	 */
	public static function get_line_subtotal_tax( $item ) {
		return isset( $item['line_subtotal_tax'] ) ? floatval( $item['line_subtotal_tax'] ) : 0;
	}

	public static function get_quantity( $item ) {
		return isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
	}

	public static function price( $price, $currency = '' ) {
		$args = array( 'currency' => $currency );

		return wc_price( $price, $args );
	}

	/**
	 * Get those coupons which are user made only.
	 *
	 * @param $searched_term
	 *
	 * @return array
	 */
	public static function get_coupon( $searched_term, $limit = - 1 ) {
		$membership_plans = array();
		$results          = array();
		$query_params     = array(
			'post_type'      => 'shop_coupon',
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin coupon search on shop_coupon posts; small table, limited
				array(
					'key'     => '_is_bwfan_coupon',
					'compare' => 'NOT EXISTS',
				),
			),
		);

		if ( '' !== $searched_term ) {
			$query_params['s'] = $searched_term;
		}

		$query = new WP_Query( $query_params );

		if ( $query->found_posts > 0 ) {
			foreach ( $query->posts as $post ) {
				$results[] = array(
					'id'   => $post->ID,
					'text' => $post->post_title,
				);
			}
		}

		$membership_plans['results'] = $results;

		return $membership_plans;
	}

	public static function validate_action_date_before_save( $all_actions ) {
		if ( ! is_array( $all_actions ) || 0 === count( $all_actions ) ) {
			return false;
		}

		$modified_actions = $all_actions;

		foreach ( $all_actions as $row_index => $row_actions ) {
			if ( null === $row_actions ) {
				continue;
			}
			if ( ! is_array( $row_actions ) || 0 === count( $row_actions ) ) {
				$modified_actions[ $row_index ] = array();
				continue;
			}

			foreach ( $row_actions as $action_index => $action_details ) {
				if ( isset( $action_details['temp_action_slug'] ) && ! empty( $action_details['temp_action_slug'] ) ) {
					$modified_actions[ $row_index ][ $action_index ]['temp_action_slug'] = '';
				}
			}
		}

		return $modified_actions;
	}

	/**
	 * Sort actions when automation is saved.
	 *
	 * @param $all_actions
	 *
	 * @return array
	 */
	public static function sort_actions( $all_actions ) {
		if ( ! is_array( $all_actions ) || 0 === count( $all_actions ) ) {
			return $all_actions;
		}

		foreach ( $all_actions as $row_index => $row_actions ) {
			if ( null === $row_actions && ! is_array( $row_actions ) || 0 === count( $row_actions ) ) {
				unset( $all_actions[ $row_index ] );
				continue;
			}

			foreach ( $row_actions as $action_index => $action_details ) {
				if ( ! is_array( $action_details ) || 0 === count( $action_details ) ) {
					unset( $all_actions[ $row_index ][ $action_index ] );
				}
			}
		}

		return $all_actions;
	}

	/**
	 * Attach default merge tags to every event.
	 *
	 * @param $all_events_merge_tags
	 * @param $all_merge_tags
	 *
	 * @return array
	 */
	public static function attach_default_merge_to_events( $all_events_merge_tags, $all_merge_tags ) {
		if ( ! is_array( $all_events_merge_tags ) || 0 === count( $all_events_merge_tags ) ) {
			return $all_events_merge_tags;
		}

		foreach ( $all_events_merge_tags as $event_slug => $groups ) {
			if ( ! is_array( $groups ) || 0 === count( $groups ) ) {
				continue;
			}

			$all_events_merge_tags[ $event_slug ]['bwfan_default'] = $all_merge_tags['bwfan_default'];
		}

		return $all_events_merge_tags;
	}

	/**
	 * Get wc products by searched term.
	 *
	 * @param bool $term
	 * @param bool $include_variations
	 * @param bool $return
	 *
	 * @return mixed|void
	 */
	public static function product_search( $term = false, $include_variations = false, $return = false ) {
		self::check_nonce();
		if ( empty( $term ) ) {

			if ( isset( $_POST['term'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$term = sanitize_text_field( wp_unslash( $_POST['term'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}

		// if ( empty( $term ) ) {
		// 	wp_die();
		// }

		$variations = true;
		if ( true !== $include_variations ) {
			$variations = false;
		}
		$ids = self::search_products( $term, $variations );

		/**
		 * Products types that are allowed in the offers
		 */
		$product_objects = array_filter( array_map( 'wc_get_product', $ids ), 'wc_products_array_filter_editable' );
		$products        = array();
		foreach ( $product_objects as $product_object ) {
			if ( 'pending' === $product_object->get_status() ) {
				continue;
			}
			$products[] = array(
				'id'   => $product_object->get_id(),
				'text' => rawurldecode( self::get_formatted_product_name( $product_object ) ),
			);
		}
		$data = apply_filters( 'bwfan_woocommerce_json_search_found_products', $products );
		if ( true === $return ) {
			return $data;
		}

		wp_send_json( $data );
	}

	/**
	 * Verify CSRF nonce AND admin capability for an AJAX request.
	 *
	 * Why: the old version OR'd nonce with capability, which let a logged-in
	 * admin's browser be CSRF'd into any handler that called this helper.
	 * Now both are required (AND), so this is the single authorization gate
	 * for all admin AJAX handlers that previously did their own cap check.
	 */
	public static function check_nonce() {
		if ( self::is_rest() || wp_is_rest_endpoint() ) {
			// REST endpoints handle nonces via X-WP-Nonce / app passwords / JWT,
			// but we still require WP to have resolved an authenticated user here
			// so an unauthenticated REST route can't bypass the cap check below.
			if ( is_user_logged_in() ) {
				$permissions = method_exists( 'BWFAN_Common', 'access_capabilities' ) ? self::access_capabilities() : array( 'manage_options' );
				foreach ( $permissions as $permission ) {
					if ( current_user_can( $permission ) ) {
						return;
					}
				}
			}
			wp_send_json( array(
				'msg'    => __( 'Invalid request, security validation failed.', 'wp-marketing-automations' ),
				'status' => false,
			) );

		}
        $nonce     = ( isset( $_REQUEST['_wpnonce'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification
        $bwf_nonce = ( isset( $_REQUEST['bwf_nonce'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['bwf_nonce'] ) ) : '';//phpcs:ignore WordPress.Security.NonceVerification
        // Some callers (custom addons, internal React components) post the bwfan-action-admin nonce under the key `nonce` instead of `_wpnonce`. Accept both.
        $alt_nonce = ( isset( $_REQUEST['nonce'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification
        if ( ! wp_verify_nonce( $bwf_nonce, 'bwf_secure_key' ) && ! wp_verify_nonce( $nonce, 'bwfan-action-admin' ) && ! wp_verify_nonce( $alt_nonce, 'bwfan-action-admin' ) ) {
            wp_send_json( array(
                'msg'    => __( 'Invalid request, security validation failed.', 'wp-marketing-automations' ),
                'status' => false,
            ) );
        }
        $permissions = method_exists( 'BWFAN_Common', 'access_capabilities' ) ? self::access_capabilities() : array( 'manage_options' );
        foreach ( $permissions as $permission ) {
            if ( current_user_can( $permission ) ) {
                return;
            }
        }
        wp_send_json( array(
            'msg'    => __( 'You are not authorized to perform this action', 'wp-marketing-automations' ),
            'status' => false,
        ) );
    }

	/**
	 * Get wc products by searched term.
	 *
	 * @param $term
	 * @param bool $include_variations
	 *
	 * @return array
	 */
	public static function search_products( $term, $include_variations = false, $limit = 10, $offset = 0 ) {
		self::check_nonce();
		global $wpdb;

		/**
		 * Filter to modify the product search limit
		 *
		 * @param int $limit The default limit (10)
		 * @param string $term The search term
		 * @param bool $include_variations Whether to include variations
		 * @param int $offset The offset for pagination
		 *
		 * @return int The modified limit
		 */
		$limit         = apply_filters( 'bwfan_product_search_limit', $limit, $term, $include_variations, $offset );
		$limit         = ( absint( $limit ) > 0 ) ? absint( $limit ) : 10;
		$offset        = absint( $offset );
		$like_term     = '%' . $wpdb->esc_like( $term ) . '%';
		$post_statuses = current_user_can( 'edit_private_products' ) ? array(
			'private',
			'publish',
			'draft',
		) : array( 'publish', 'draft' );
		/**
		 * Filter the post types searched by the product picker.
		 *
		 * Extensions that register course/membership/etc. post types sold via WooCommerce
		 * can add them to this list so they appear in the Order Created product selector.
		 *
		 * @param array  $post_types         Default post types included in the search.
		 * @param string $term               The search term.
		 * @param bool   $include_variations Whether product variations are included.
		 */
		$product_type = apply_filters( 'bwfan_search_product_post_types', [ 'product' ], $term, $include_variations );
		$product_type = is_array( $product_type ) && ! empty( $product_type ) ? array_values( array_unique( array_map( 'sanitize_key', $product_type ) ) ) : [ 'product' ];
		if ( true === $include_variations && ! in_array( 'product_variation', $product_type, true ) ) {
			$product_type[] = 'product_variation';
		}
		$query = $wpdb->prepare( "SELECT DISTINCT posts.ID FROM {$wpdb->posts} AS posts LEFT JOIN {$wpdb->wc_product_meta_lookup} AS wc_product_meta_lookup ON posts.ID = wc_product_meta_lookup.product_id WHERE (posts.post_title LIKE %s OR wc_product_meta_lookup.sku LIKE %s OR posts.ID LIKE %s) AND posts.post_status IN ('" . implode( "','", $post_statuses ) . "') AND posts.post_type IN ('" . implode( "','", $product_type ) . "') ORDER BY posts.post_parent ASC, posts.post_title ASC", $like_term, $like_term, $like_term ); //phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders
		if ( ! empty( $limit ) ) {
			$query .= " LIMIT %d, %d ";
			$query = $wpdb->prepare( $query, $offset, $limit );// phpcs:ignore WordPress.DB.PreparedSQL
		}
		$product_ids = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- post types sanitize_key()ed; values bound via prepare()

		if ( is_numeric( $term ) ) {
			$post_id   = absint( $term );
			$post_type = get_post_type( $post_id );

			if ( 'product_variation' === $post_type && $include_variations ) {
				$product_ids[] = $post_id;
			} elseif ( 'product' === $post_type ) {
				$product_ids[] = $post_id;
			}

			$product_ids[] = wp_get_post_parent_id( $post_id );
		}

		return wp_parse_id_list( $product_ids );
	}

	public static function get_formatted_product_name( $product ) {
		$formatted_variation_list = self::get_variation_attribute( $product );
		$arguments                = array();

		if ( ! empty( $formatted_variation_list ) && count( $formatted_variation_list ) > 0 ) {
			foreach ( $formatted_variation_list as $att => $att_val ) {
				if ( '' === $att_val ) {
					$att_val = __( 'any', 'wp-marketing-automations' );
				}
				$att         = strtolower( $att );
				$att_val     = strtolower( $att_val );
				$arguments[] = "$att: $att_val";
			}
		}

		return sprintf( '%s (#%d)%s', wp_strip_all_tags( $product->get_title() ), $product->get_id(), ( count( $arguments ) > 0 ) ? ' (' . implode( ',', $arguments ) . ')' : '' );
	}

	public static function get_variation_attribute( $variation ) {
		$variation_attributes = array();
		if ( is_a( $variation, 'WC_Product_Variation' ) ) {
			$variation_attributes = $variation->get_attributes();

		} else {
			if ( is_array( $variation ) ) {
				foreach ( $variation as $key => $value ) {
					$variation_attributes[ str_replace( 'attribute_', '', $key ) ] = $value;
				}
			}
		}

		return ( $variation_attributes );
	}

	public static function array_equal( $a, $b ) {
		return ( is_array( $a ) && is_array( $b ) && count( $a ) === count( $b ) && array_diff( $a, $b ) === array_diff( $b, $a ) ); //phpcs:ignore WordPress.PHP.StrictComparisons
	}

	public static function validate_string_multi( $actual_values, $compare_type, $expected_value ) {
		if ( empty( $expected_value ) ) {
			return false;
		}

		$actual_value = '';
		// look for at least one item that validates the text match
		foreach ( $actual_values as $coupon_code => $coupon_data ) {
			$actual_value = is_string( $coupon_code ) ? $coupon_code : $coupon_data;
			if ( self::validate_string( $actual_value, $compare_type, $expected_value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param $actual_value
	 * @param $compare_type
	 * @param $expected_value
	 *
	 * @return bool
	 */
	public static function validate_string( $actual_value, $compare_type, $expected_value ) {
		// case insensitive
		$actual_value   = strtolower( (string) $actual_value );
		$expected_value = strtolower( (string) $expected_value );
		$return_status  = false;
		switch ( $compare_type ) {

			case 'is':
				$return_status = ( $actual_value === $expected_value );//phpcs:ignore WordPress.PHP.StrictComparisons
				break;

			case 'is_not':
				$return_status = ( $actual_value !== $expected_value );//phpcs:ignore WordPress.PHP.StrictComparisons
				break;

			case 'contains':
				$return_status = strstr( $actual_value, $expected_value ) !== false;
				break;

			case 'not_contains':
				$return_status = strstr( $actual_value, $expected_value ) === false;
				break;

			case 'starts_with':
				$length = strlen( $expected_value );

				$return_status = substr( $actual_value, 0, $length ) === $expected_value;
				break;

			case 'ends_with':
				$length = strlen( $expected_value );

				if ( 0 === $length ) {
					$return_status = true;
				} else {
					$return_status = substr( $actual_value, - $length ) === $expected_value;
				}
				break;

			case 'blank':
				$return_status = empty( $actual_value );
				break;

			case 'not_blank':
				$return_status = ! empty( $actual_value );
				break;
		}

		return $return_status;
	}

	public static function get_bwf_customer( $email, $wpid ) {
		if ( function_exists( 'bwf_get_contact' ) ) {
			$get_contact = bwf_get_contact( $wpid, $email );

			return bwf_get_customer( $get_contact );
		}

		return null;
	}

	/**
	 * Run the check and update the status.
	 */
	public static function bwfan_run_cron_test( $forced = false ) {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {

			$message                    = __( 'The DISABLE_WP_CRON constant is set to true . WP-Cron is disabled and will not run on it\'s own.', 'wp-marketing-automations' );
			$url                        = rest_url( '/woofunnels/v1/worker' ) . '?' . time();
			$message                    .= '<br>' . __( 'Copy following URL and paste it in your Cpanel', 'wp-marketing-automations' );
			$message                    .= '<br><i>' . $url . '</i>';
			$current_version            = BWFAN_VERSION;
			$current_ver                = str_replace( '.', '_', $current_version );
			$version_key                = 'bwfan_version_' . $current_ver;
			$versionArr                 = array();
			$versionArr[ $version_key ] = array(
				'html' => $message,
				'type' => 'wf_error',
			);
			$versionStatus              = WooFunnels_Notifications::get_instance()->get_notification( $version_key, 'bwfan' );

			if ( isset( $versionStatus['error'] ) && $versionStatus['error'] == $version_key . ' Key or Notification group may be Not Available.' ) { //phpcs:ignore WordPress.PHP.StrictComparisons
				$notice_check_in_db = WooFunnels_Notifications::get_instance()->get_dismiss_notification_key( 'bwfan' );
				if ( is_array( $notice_check_in_db ) && false === in_array( $version_key, $notice_check_in_db ) ) {//phpcs:ignore WordPress.PHP.StrictInArray
					WooFunnels_Notifications::get_instance()->register_notification( $versionArr, 'bwfan' );
				}
			}
		}
	}

	/**
	 * Returns the timestamp in the blog's time and format.
	 */
	public static function bwfan_get_datestring( $timestamp = '' ) {
		if ( empty( $timestamp ) ) {
			$timestamp = time();
		}

		return get_date_from_gmt( date( 'Y-m-d H:i:s', $timestamp ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
	}

	/**
	 * Get unique actions from a single automation.
	 *
	 * @param $automation_details
	 * @param $selected_actions
	 *
	 * @return array
	 */
	public static function get_automation_selected_action_slugs( $automation_details, $selected_actions ) {
		$automation_actions = $automation_details['meta']['actions'];
		$action_slugs       = array();

		foreach ( $selected_actions as $value1 ) {
			$groups = explode( ',', $value1 );
			foreach ( $groups as $group_actions ) {
				$action_indexes = explode( '_', $group_actions );
				$group_index    = $action_indexes[0];
				$action_index   = $action_indexes[1];
				$action         = $automation_actions[ $group_index ][ $action_index ];
				$action_slugs[] = $action['integration_slug'] . ':' . $action['action_slug'];
			}
		}

		$action_slugs = array_unique( $action_slugs );

		return $action_slugs;
	}

	public static function get_actions_filter_data() {
		return self::get_all_actions_names();
	}

	/**
	 * Get all actions readable names with action slug
	 *
	 * @return array
	 */
	public static function get_all_actions_names() {
		global $wpdb;
		$filter_table           = null;
		$filtered_table_actions = null;

		if ( ( isset( $_GET['tab'] ) && 'tasks' === sanitize_text_field( wp_unslash( $_GET['tab'] ) ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification
			$filter_table = $wpdb->prefix . 'bwfan_tasks';
		}
		if ( ( isset( $_GET['tab'] ) && 'logs' === sanitize_text_field( wp_unslash( $_GET['tab'] ) ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification
			$filter_table = $wpdb->prefix . 'bwfan_logs';
		}

		$task_status = ( isset( $_GET['status'] ) && '' !== $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 't_0'; //phpcs:ignore WordPress.Security.NonceVerification
		if ( strpos( $task_status, '_' ) !== false ) {
			$task_status = explode( '_', $task_status );
			$task_status = intval( $task_status[1] );
		} else {
			$task_status = 0;
		}

		$params = array();

		if ( ! is_null( $filter_table ) ) {
			$query = 'SELECT DISTINCT(integration_action) as actions FROM ' . $filter_table;
			if ( ! is_null( $task_status ) ) {
				$query    .= ' WHERE `status` = %d';
				$params[] = $task_status;
			}
			$parsed_query     = $wpdb->prepare( $query, $params ); // phpcs:ignore WordPress.DB.PreparedSQL
			$distinct_actions = $wpdb->get_results( $parsed_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table hardcoded from $wpdb->prefix; status bound via %d
			if ( is_array( $distinct_actions ) && count( $distinct_actions ) > 0 ) {
				foreach ( $distinct_actions as $values ) {
					$filtered_table_actions[ $values['actions'] ] = $values['actions'];
				}
			}
		}

		$result      = array();
		$all_sources = BWFAN_Core()->integration->get_integrations();
		$all_actions = BWFAN_Load_Integrations::get_all_integrations();
		if ( is_array( $all_actions ) && count( $all_actions ) > 0 ) {
			foreach ( $all_actions as $source_slug => $source_actions ) {
				if ( ! is_array( $source_actions ) || 0 === count( $source_actions ) ) {
					break;
				}
				foreach ( $source_actions as $actions_slug => $action_object ) {
					if ( ! is_null( $filtered_table_actions ) && in_array( $actions_slug, $filtered_table_actions, true ) ) {
						$result[ $actions_slug ] = $all_sources[ $source_slug ]->get_name() . ': ' . $action_object->get_name();
						continue;
					} elseif ( is_null( $filtered_table_actions ) ) {
						$result[ $actions_slug ] = $all_sources[ $source_slug ]->get_name() . ': ' . $action_object->get_name();
					}
				}
			}
		}
		ksort( $result );

		return $result;
	}

	public static function modify_display_numbers( $value = false ) {
		if ( false === $value ) {
			return 0;
		}
		if ( 1000 > $value ) {
			return $value;
		}

		return intval( $value / 1000 ) . 'k';
	}

	/**
	 * Get automations with title
	 *
	 * @return array
	 */
	public static function get_automations_filter_data() {
		$result = array();
		global $wpdb;
		$automation_table      = $wpdb->prefix . 'bwfan_automations';
		$automation_meta_table = $wpdb->prefix . 'bwfan_automationmeta';
		$params                = array();
		$query                 = 'SELECT am.`bwfan_automation_id`, am.`meta_value` ';
		$query                 .= 'FROM ' . $automation_meta_table . ' AS am';
		$query                 .= ' INNER JOIN ' . $automation_table . ' AS aut ON am.`bwfan_automation_id` = aut.`ID`';
		$query                 .= ' WHERE 1=1';
		$query                 .= ' AND am.`meta_key` = %s';
		$params[]              = 'title';
		$parsed_query          = $wpdb->prepare( $query, $params ); // phpcs:ignore WordPress.DB.PreparedSQL
		$all_automations       = $wpdb->get_results( $parsed_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- tables from $wpdb->prefix; value bound via prepare()

		if ( false === is_array( $all_automations ) || 0 === count( $all_automations ) ) {
			return $result;
		}
		foreach ( $all_automations as $details ) {
			$result[ $details['bwfan_automation_id'] ] = $details['meta_value'];
		}

		return $result;
	}

	/**
	 * Increase the queue batch size while processing actions. default is 5.
	 *
	 * @param $batch_size
	 *
	 * @return mixed
	 */
	public static function ac_increase_queue_batch_size( $batch_size ) {
		$global_settings = self::get_global_settings();
		if ( isset( $global_settings['bwfan_ac_b_s'] ) && $global_settings['bwfan_ac_b_s'] > 0 ) {
			$batch_size = intval( $global_settings['bwfan_ac_b_s'] );
		}

		return $batch_size;
	}

	/**
	 * Increase the maximum execution time while processing actions. default is 30 seconds.
	 *
	 * @param $max_timeout
	 *
	 * @return mixed
	 */
	public static function ac_increase_max_execution_time( $max_timeout ) {
		$global_settings = self::get_global_settings();
		if ( isset( $global_settings['bwfan_ac_t_l'] ) && $global_settings['bwfan_ac_t_l'] > 0 ) {
			$max_timeout = intval( $global_settings['bwfan_ac_t_l'] );
		}

		return $max_timeout;
	}

	/**
	 * Make custom cron times for autonami events.
	 *
	 * @param $schedules
	 *
	 * @return mixed
	 */
	public static function make_custom_events_time( $schedules ) {
		/** Don't add localization due to load textdomain error */
		$schedules['bwfan_once_in_day']         = array(
			'interval' => 86400,
			'display'  => 'Once in a day',
		);
		$schedules['bwfan_once_in_two_minutes'] = array(
			'interval' => 120,
			'display'  => 'Once in 2 minutes',
		);
		$schedules['bwfan_every_minute']        = array(
			'interval' => 60,
			'display'  => 'Every minute',
		);
		$schedules['bwfan_once_in_week']        = array(
			'interval' => 604800,
			'display'  => 'Once in a week',
		);

		return $schedules;
	}

	/**
	 * Make a new endpoint which will receive the event data
	 */
	public static function add_plugin_endpoint() {
		register_rest_route( 'autonami/v1', '/events', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'capture_async_events' ),
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		) );
		register_rest_route( 'autonami/v1', '/worker', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'run_worker_tasks' ),
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		) );
		register_rest_route( 'autonami/v1', '/autonami-cron', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'run_autonami_cron_events' ),
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		) );
		register_rest_route( 'autonami/v1', '/delete-tasks', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'delete_automation_tasks_by_unique_action_ids' ),
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		) );
		register_rest_route( 'autonami/v1', '/update-contact-automation', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'update_contact_meta' ),
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		) );
		register_rest_route( 'autonami/v1', '/update-generated-increment', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'update_generated_increment' ),
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		) );
		/** v2 autonami endpoint */
		register_rest_route( 'autonami/v2', '/worker', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'run_worker_tasks' ),
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		) );

		register_rest_route( 'autonami/v2', '/worker', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'run_v2_worker_tasks' ),
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		) );
	}

	/**
	 * Callback function for receiving the event data
	 *
	 * @param WP_REST_Request $request
	 */
	public static function capture_async_events( WP_REST_Request $request ) {
		self::nocache_headers();

		$post_parameters = $request->get_body_params();
		self::event_advanced_logs( 'Event endpoint data received' );
		self::event_advanced_logs( $post_parameters );

		/** Check Unique key security */
		$unique_key = get_option( 'bwfan_u_key', false );
		if ( ! is_string( $unique_key ) || ! isset( $post_parameters['unique_key'] ) || ! is_string( $post_parameters['unique_key'] ) || ! hash_equals( $unique_key, $post_parameters['unique_key'] ) ) {
			return;
		}

		// Prepare and send response immediately to prevent timeout
		$resp = array(
			'msg'  => '',
			'time' => time(),
		);
		$connection_closed = self::send_response_and_close_connection( $resp );

		/** Delete row from automation events */
		if ( isset( $post_parameters['a_e_id'] ) ) {
			BWFAN_Model_Automation_Events::delete( $post_parameters['a_e_id'] );
		}

		// Now process events in background (client already received response)
		self::capture_async_helper( $post_parameters, false );

		self::event_advanced_logs( "Event endpoint callback completed" );

		/**
		 * When the connection was actually detached, terminate now so neither WP_REST_Server
		 * nor any host cache layer emits headers against the already-committed output.
		 */
		if ( true === $connection_closed ) {
			exit;
		}
	}

	public static function capture_async_helper( $post_parameters = [], $wp_send_json = true ) {
		if ( empty( $post_parameters ) ) {
			return;
		}

		$resp = array();

		if ( ( isset( $post_parameters['source'] ) && isset( $post_parameters['event'] ) ) && ( ! isset( $post_parameters['automation_id'] ) ) ) {
			/** Set posted params to static property */
			self::$events_async_data = $post_parameters;

			$event_slug  = $post_parameters['event'];
			$event       = BWFAN_Core()->sources->get_event( $event_slug );
			$resp['msg'] = 'success';

			/** Try to run v2 automations */
			self::maybe_run_v2_automations( $event_slug, $post_parameters );

			/** Check if the Event has active automations, used this check again for cases like Form Submissions trigger */
			if ( ! is_null( $event ) && false !== $event->get_current_event_automations() ) {
				try {
					$event->capture_async_data();
				} catch ( Exception $exception ) {
					$resp['msg'] = $exception->getMessage();
				}
			}
			if ( true === $wp_send_json ) {
				wp_send_json( $resp );
			}
		}
	}

	/**
	 * Find v2 active automations for event and run
	 *
	 * @param $event_slug
	 *
	 * @return false|void
	 */
	public static function maybe_run_v2_automations( $event_slug, $post_parameters ) {
		BWFAN_Core()->public->load_active_v2_automations( $event_slug );

		/** @var BWFAN_Event $event */
		$event = BWFAN_Core()->sources->get_event( $event_slug );
		if ( ! $event instanceof BWFAN_Event ) {
			return false;
		}

		/**
		 * Run Autonami async events
		 * Form submission, Automation goals, Normalize 3rd party tables
		 */
		self::extend_async_capture( $event, $post_parameters );

		$automations = $event->get_automations_data( 2 );
		if ( ! is_array( $automations ) || count( $automations ) === 0 ) {
			BWFAN_Core()->logger->log( 'No v2 active automations found. Event - ' . $event->get_slug(), $event->log_type );

			return false;
		}

		self::log_test_data( 'Found v2 automations. Event - ' . $event->get_slug() );
		self::log_test_data( 'IDs: ' . implode( ', ', array_keys( $automations ) ) );

		$any_automation_ran = false;
		foreach ( $automations as $automation_id => $automation_data ) {
			$automation_data = self::remove_extra_automation_data( $automation_data );
			if ( ! empty( $post_parameters ) && is_array( $post_parameters ) ) {
				$automation_data = array_merge( $automation_data, $post_parameters );
			}

			if ( empty( $automation_data ) ) {
				continue;
			}

			if ( false === $event->validate_v2_event_settings( $automation_data ) ) {
				continue;
			}

			$automation_data = $event->capture_v2_data( $automation_data );
			if ( empty( $automation_data ) ) {
				continue;
			}

			$event->global_data = $event->get_event_data( $automation_data );
			/** For modify data */
			$event->global_data = apply_filters( 'bwfan_modify_event_data', $event->global_data );
			$event->event_data  = $event->get_automation_event_data( $automation_data );

			/** Don't extend locally */
			$result = $event->handle_automation_run_v2( $automation_id, $automation_data );
			/** Set last run automation contact id */
			self::$last_automation_cid[ $automation_id ] = $result;

			$any_automation_ran = $any_automation_ran || ! empty( $result );
		}

		return $any_automation_ran;
	}

	/**
	 * @param BWFAN_Event $event
	 * @param array $post_parameters
	 */
	public static function extend_async_capture( $event, $post_parameters ) {
		/** Capture Async: Form Submission */
		if ( isset( $post_parameters['is_form_submission'] ) && 1 === absint( $post_parameters['is_form_submission'] ) && bwfan_is_autonami_pro_active() ) {
			try {
				BWFCRM_Core()->forms->capture_async_form_submission();
			} catch ( Error $e ) {
				BWFAN_Common::log_test_data( $event->get_slug() . ' : Capture Async: Form Submission try catch failed. Error: ' . $e->getMessage(), 'extend_async_capture' );
			}
		}

		/** Capture Async: Goal Controller */
		if ( $event->is_goal() ) {
			try {
				BWFAN_Common::log_test_data( $event->get_slug() . ' is a goal', 'goal-check' );
				BWFAN_Goal_Controller::capture_async_goal( $event, $post_parameters );
			} catch ( Error $e ) {
				BWFAN_Common::log_test_data( $event->get_slug() . ' : Goal execution try catch failed. Error: ' . $e->getMessage(), 'extend_async_capture' );
			}
		}

		if ( $event->is_db_normalize() ) {
			try {
				if ( method_exists( $event, 'execute_normalization' ) ) {
					$event->execute_normalization( $post_parameters );
				}
			} catch ( Error $e ) {
				BWFAN_Common::log_test_data( $event->get_slug() . ' : Normalization method not found. Error: ' . $e->getMessage(), 'extend_async_capture' );
			}
		}
	}

	/**
	 * Aman testing purpose only
	 *
	 * @param $data
	 * @param $file
	 * @param $force
	 *
	 * @return void
	 */
	public static function log_test_data( $data, $file = 'v2-automations', $force = false ) {
		if ( empty( $data ) ) {
			return;
		}
		if ( true === $force ) {
			add_filter( 'bwfan_before_making_logs', '__return_true' );
		}
		if ( is_array( $data ) ) {
			BWFAN_Core()->logger->log( self::$dynamic_str, $file );
			BWFAN_Core()->logger->log( print_r( $data, true ), $file ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions

			return;
		}
		BWFAN_Core()->logger->log( self::$dynamic_str . ' - ' . $data, $file );
	}

	public static function log_l2_data( $data, $file = 'v2-automations', $force = false ) {
		if ( empty( $data ) ) {
			return;
		}

		$should_log = apply_filters( 'bwfan_log_level_2_logs', false );
		if ( false === $should_log ) {
			return;
		}

		if ( true === $force ) {
			add_filter( 'bwfan_before_making_logs', '__return_true' );
		}
		if ( is_array( $data ) ) {
			BWFAN_Core()->logger->log( print_r( $data, true ), $file ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions

			return;
		}
		BWFAN_Core()->logger->log( $data, $file );
	}

	public static function remove_extra_automation_data( $automation_data ) {
		if ( array_key_exists( 'goal', $automation_data ) ) {
			unset( $automation_data['goal'] );
		}
		if ( array_key_exists( 'steps', $automation_data ) ) {
			unset( $automation_data['steps'] );
		}
		if ( array_key_exists( 'links', $automation_data ) ) {
			unset( $automation_data['links'] );
		}
		if ( array_key_exists( 'count', $automation_data ) ) {
			unset( $automation_data['count'] );
		}
		if ( array_key_exists( 'requires_update', $automation_data ) ) {
			unset( $automation_data['requires_update'] );
		}
		if ( array_key_exists( 'step_iteration_array', $automation_data ) ) {
			unset( $automation_data['step_iteration_array'] );
		}
		if ( array_key_exists( 'unique_key', $automation_data ) ) {
			unset( $automation_data['unique_key'] );
		}

		return $automation_data;
	}

	/**
	 * Callback function for running autonami tasks
	 *
	 * @param WP_REST_Request $request
	 */
	public static function run_worker_tasks( WP_REST_Request $request ) {
		self::nocache_headers();
		$post_parameters = $request->get_body_params();
		if ( ! is_array( $post_parameters ) || ! isset( $post_parameters['worker'] ) ) {
			return;
		}

		/**
		 * Check Unique key security
		 */
		$unique_key = get_option( 'bwfan_u_key', false );
		if ( ! is_string( $unique_key ) || ! isset( $post_parameters['unique_key'] ) || ! is_string( $post_parameters['unique_key'] ) || ! hash_equals( $unique_key, $post_parameters['unique_key'] ) ) {
			return;
		}

		$v = ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], 'autonami/v1/worker' ) !== false ) ? 1 : 2;// phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		/** Rate-limit re-entry: per-version key so v1 and v2 cooldowns are independent of each other and of WooFunnels' core worker. */
		if ( self::is_worker_rate_limited( $v ) ) {
			wp_send_json( array(
				'msg'  => 'rate_limited',
				'time' => date_i18n( 'Y-m-d H:i:s' ),
			), 429 );
		}

		/** Stamp run start so subsequent calls within the cooldown window get 429. */
		bwf_options_update( "bwfan_worker_let_v{$v}", time() );

		self::event_advanced_logs( "V{$v} worker callback received" );

		/** Logs */
		$cron_check = self::is_log_enabled( 'bwfan_cron_check_logging' );
		if ( true === $cron_check || ( defined( 'BWF_CHECK_CRON_SCHEDULE' ) && true === BWF_CHECK_CRON_SCHEDULE ) ) {
			add_filter( 'bwf_logs_allowed', '__return_true', PHP_INT_MAX );
			$logger_obj = BWF_Logger::get_instance();
			$logger_obj->log( date_i18n( 'Y-m-d H:i:s' ) . ' - before worker run', 'fka-cron-check-v' . $v, 'autonami' );
		}

		// Prepare and send response immediately to prevent timeout
		$resp         = array();
		$resp['msg']  = 'success';
		$resp['time'] = date_i18n( 'Y-m-d H:i:s' );
		$connection_closed = self::send_response_and_close_connection( $resp );

		// Now run worker tasks in background (client already received response)
		self::worker_as_run();

		self::event_advanced_logs( "V{$v} worker callback completed" );
		/** Logs */
		if ( true === $cron_check || ( defined( 'BWF_CHECK_CRON_SCHEDULE' ) && true === BWF_CHECK_CRON_SCHEDULE ) ) {
			if ( isset( $logger_obj ) ) {
				$logger_obj->log( date_i18n( 'Y-m-d H:i:s' ) . ' - after worker run', 'fka-cron-check-v' . $v, 'autonami' );
			}
		}

		/**
		 * When the connection was actually detached, terminate now so neither WP_REST_Server
		 * nor any host cache layer emits headers against the already-committed output.
		 */
		if ( true === $connection_closed ) {
			exit;
		}

		return;
	}

	public static function worker_as_run() {
		if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			return;
		}

		/** Check if Autonami is in sandbox mode */
		if ( true === BWFAN_Common::is_sandbox_mode_active() ) {
			return;
		}

		/** Modify Action Scheduler filters */
		self::modify_as_filters();

		/**
		 * Same contract as WooFunnels_AS_DS::worker_as_run(): runs on every worker request, after
		 * modify_as_filters() so a listener adding one of the 7 AS filters it clears is not stripped.
		 * Fired here too because the autonami/v1 and autonami/v2 worker endpoints land in this copy and
		 * never reach the woofunnels one - on a site with WP cron disabled, where the host cron only hits
		 * an autonami worker URL, that is the only path there is.
		 */
		do_action( 'bwf_worker_before_as_run' );

		$as_ins = ActionScheduler_QueueRunner::instance();

		/** Run Action Scheduler worker. Guard against any store-level exception (e.g. claim UPDATE DB error) escaping as a fatal on the worker endpoint. */
		try {
			$result = $as_ins->run();
			self::event_advanced_logs( "Actions processed: {$result}" );
		} catch ( \Throwable $e ) {
			self::log_test_data( 'BWFAN: Worker run aborted: ' . $e->getMessage(), 'fka-db-deadlock', true );
		}
	}

	/**
	 * Returns true if the previous autonami worker run for the given version is within the rate-limit window.
	 * Window is filterable via `bwfan_worker_rate_limit_window` (passed the version as second arg); default 20 seconds.
	 * A non-positive window disables the limit. Timestamp key is `bwfan_worker_let_v{1|2}` —
	 * per-version so v1 and v2 cooldowns are independent, and autonami-only so neither cross-blocks
	 * with WooFunnels' `fk_core_worker_let`.
	 *
	 * @param int $version 1 or 2.
	 *
	 * @return bool
	 */
	public static function is_worker_rate_limited( $version = 2 ) {
		if ( ! function_exists( 'bwf_options_get' ) ) {
			return false;
		}
		$version  = ( 1 === (int) $version ) ? 1 : 2;
		$last_run = absint( bwf_options_get( "bwfan_worker_let_v{$version}", '', 0 ) );
		if ( $last_run <= 0 ) {
			return false;
		}
		$window = (int) apply_filters( 'bwfan_worker_rate_limit_window', 20, $version );
		if ( $window <= 0 ) {
			return false;
		}

		return ( time() - $last_run ) < $window;
	}

	/**
	 * Check if sandbox is active or not
	 *
	 * @return bool
	 */
	public static function is_sandbox_mode_active() {
		if ( defined( 'BWFAN_SANDBOX_MODE' ) && true === BWFAN_SANDBOX_MODE ) {
			return true;
		}
		$global_settings = get_option( 'bwfan_global_settings', array() );
		if ( empty( $global_settings ) || ! isset( $global_settings['bwfan_sandbox_mode'] ) ) {
			return false;
		}
		if ( 1 === intval( $global_settings['bwfan_sandbox_mode'] ) ) {
			return true;
		}

		return false;
	}

	public static function modify_as_filters() {
		/** Remove all existing filters */
		remove_all_filters( 'action_scheduler_queue_runner_time_limit' );
		remove_all_filters( 'action_scheduler_queue_runner_batch_size' );
		remove_all_filters( 'action_scheduler_queue_runner_concurrent_batches' );
		remove_all_filters( 'action_scheduler_timeout_period' );
		remove_all_filters( 'action_scheduler_cleanup_batch_size' );
		remove_all_filters( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded' );
		remove_all_filters( 'action_scheduler_failure_period' );

		/** Adding all filters for Autonami Action Scheduler only */
		add_filter( 'action_scheduler_queue_runner_time_limit', function () {
			$as_per_call_time = apply_filters( 'bwfan_as_per_call_time', 30 );

			return intval( $as_per_call_time ) > 50 ? 50 : $as_per_call_time;
		}, 999 );
		add_filter( 'action_scheduler_queue_runner_batch_size', function () {
			return apply_filters( 'bwfan_as_per_call_batch_size', 30 );
		}, 999 );
		add_filter( 'action_scheduler_queue_runner_concurrent_batches', function () {
			return 5;
		}, 999 );
		add_filter( 'action_scheduler_timeout_period', function () {
			return 300;
		}, 999 );
		add_filter( 'action_scheduler_cleanup_batch_size', function () {
			return 20;
		}, 999 );
		add_filter( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded', function ( $val, $ins, $processed_actions, $execution_time, $max_execution_time ) {
			return ( $execution_time > $max_execution_time );
		}, 99999, 5 );
		add_filter( 'action_scheduler_failure_period', function () {
			return 180;
		}, 999 );
	}

	/**
	 * Check if cart abandonment is active or not
	 *
	 * @return bool
	 */
	public static function is_cart_abandonment_active() {
		$global_settings = get_option( 'bwfan_global_settings', array() );
		if ( empty( $global_settings ) || ! isset( $global_settings['bwfan_ab_enable'] ) || empty( $global_settings['bwfan_ab_enable'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * action_scheduler_pre_init action hook
	 */
	public static function as_pre_init_cb() {

		if ( ( ! isset( $_GET['rest_route'] ) || '/autonami/v1/worker' !== sanitize_text_field( $_GET['rest_route'] ) ) && false === strpos( $_SERVER['REQUEST_URI'], '/autonami/v1/worker' ) ) { //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			return;
		}

		/**
		 * Load the custom AS-CT store files directly instead of relying on the generated
		 * classmap (includes/class-bwfan-autoload-map.php). Its libraries/ entries are manual
		 * additions that the funnelkit-profiler generator can drop on regen; losing them would
		 * silently revert this worker to the core AS tables. Request-scoped (worker endpoint only).
		 * Do NOT remove as "redundant" — it is the load-bearing guarantee for the worker store.
		 */
		if ( ! class_exists( 'BWFAN_AS_CT', false ) ) {
			$as_ct_dir = BWFAN_PLUGIN_DIR . '/libraries/action-scheduler-ct/';
			require_once $as_ct_dir . 'class-bwfan-as-ct.php';
			require_once $as_ct_dir . 'action-store.php';
			require_once $as_ct_dir . 'logs-store.php';
		}
		if ( ! class_exists( 'BWFAN_AS_CT', false ) ) {
			return;
		}

		/** BWFAN_AS_CT instance */
		$as_ct_ins = BWFAN_AS_CT::instance();

		/** Set new AS CT data store */
		$as_ct_ins->change_data_store();
		self::$change_data_strore = true;
	}

	/**
	 * action_scheduler_pre_init action hook for version 2
	 */
	public static function as_pre_init_v2_cb() {

		if ( ( ! isset( $_GET['rest_route'] ) || '/autonami/v2/worker' !== sanitize_text_field( $_GET['rest_route'] ) ) && false === strpos( $_SERVER['REQUEST_URI'], '/autonami/v2/worker' ) ) { //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			return;
		}

		/**
		 * Load the custom AS-V2 store files directly instead of relying on the generated
		 * classmap (includes/class-bwfan-autoload-map.php). Its libraries/ entries are manual
		 * additions that the funnelkit-profiler generator can drop on regen; losing them would
		 * silently revert this worker to the core AS tables. Request-scoped (worker endpoint only).
		 * Do NOT remove as "redundant" — it is the load-bearing guarantee for the worker store.
		 */
		if ( ! class_exists( 'BWFAN_AS_V2', false ) ) {
			$as_v2_dir = BWFAN_PLUGIN_DIR . '/libraries/action-scheduler-v2/';
			require_once $as_v2_dir . 'class-bwfan-as-ct.php';
			require_once $as_v2_dir . 'action-store.php';
			require_once $as_v2_dir . 'logs-store.php';
		}
		if ( ! class_exists( 'BWFAN_AS_V2', false ) ) {
			return;
		}

		/** BWFAN_AS_V2 instance */
		$ins = BWFAN_AS_V2::instance();

		/** Unset orphaned claims */
		$ins->unset_orphaned_claims();

		/** Set new AS V2 data store */
		$ins->change_data_store();
		self::$change_data_strore = true;
	}

	/**
	 * action_scheduler_pre_init action hook for autonami cli
	 */
	public static function as_pre_init_cli_cb() {

		global $argv;

		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		/**
		 * $argv holds arguments passed to script
		 * https://www.php.net/manual/en/reserved.variables.argv.php
		 */

		if ( empty( $argv ) ) {
			WP_CLI::log( 'FunnelKit Automations WP CLI arguments not found.' );

			return;
		}

		if ( ! isset( $argv[1] ) || 'autonami-tasks' !== $argv[1] ) {
			return;
		}
		if ( ! isset( $argv[2] ) || 'run' !== $argv[2] ) {
			return;
		}

		if ( ! class_exists( 'BWFAN_AS_CT' ) ) {
			WP_CLI::log( 'BWFAN_AS_CT class not found.' );
		}

		/** BWFAN_AS_CT instance */
		$as_ct_ins = BWFAN_AS_CT::instance();

		/** Set new AS CT data store */
		$as_ct_ins->change_data_store();
		self::$change_data_strore = true;
	}

	/**
	 * action_scheduler_pre_init action hook for version 2 for autonami cli
	 */
	public static function as_pre_init_cli_v2_cb() {

		global $argv;

		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		/**
		 * $argv holds arguments passed to script
		 * https://www.php.net/manual/en/reserved.variables.argv.php
		 */

		if ( empty( $argv ) ) {
			WP_CLI::log( 'FunnelKit Automations V2 WP CLI arguments not found.' );

			return;
		}

		if ( ! isset( $argv[1] ) || 'autonami-tasks' !== $argv[1] ) {
			return;
		}
		if ( ! isset( $argv[2] ) || 'run' !== $argv[2] ) {
			return;
		}
		if ( ! class_exists( 'BWFAN_AS_V2' ) ) {
			WP_CLI::log( 'BWFAN_AS_V2 class not found.' );
		}

		/** BWFAN_AS_V2 instance */
		$ins = BWFAN_AS_V2::instance();

		/** Set new AS V2 data store */
		$ins->change_data_store();
		self::$change_data_strore = true;
	}

	/**
	 * This function is called when rest endpoint of cron is hit.
	 *
	 * @param WP_REST_Request $request
	 */
	public static function run_autonami_cron_events( WP_REST_Request $request ) {
		self::nocache_headers();
		$resp        = array();
		$resp['msg'] = 'success';

		if ( isset( $_GET['debug'] ) && 'yes' === $_GET['debug'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$resp['msg'] = 'connection established';
			wp_send_json( $resp );
		}

		self::run_as_ct_worker();

		wp_send_json( $resp );
	}

	/**
	 * 1 min worker callback
	 */
	public static function run_as_ct_worker() {
		/** Maybe v1 automation not active */
		if ( false === self::is_automation_v1_active() && bwf_has_action_scheduled( 'bwfan_run_queue' ) ) {
			bwf_unschedule_actions( 'bwfan_run_queue' );
		}

		/** Check if a single task is available for execution */
		if ( false === self::is_automation_v1_active() || false === BWFAN_Model_Tasks::maybe_tasks_available() ) {
			return;
		}

		$url             = rest_url( '/autonami/v1/worker' ) . '?' . time();
		$body_data       = array(
			'worker'     => true,
			'unique_key' => get_option( 'bwfan_u_key', false ),
		);
		$args            = bwf_get_remote_rest_args( $body_data );
		$args['timeout'] = apply_filters( 'bwfan_as_worker_timeout_v2', 2 ); // Allow time for WordPress to bootstrap and send early response
		wp_remote_post( $url, $args );
	}

	/**
	 * v2 automation 1 min worker callback
	 *
	 * @return void
	 */
	public static function run_as_ct_v2_worker() {
		/** Advanced logs */
		self::event_advanced_logs( 'V2 action callback' );

		/** Logs */
		$cron_check = self::is_log_enabled( 'bwfan_cron_check_logging' );
		if ( true === $cron_check || ( defined( 'BWF_CHECK_CRON_SCHEDULE' ) && true === BWF_CHECK_CRON_SCHEDULE ) ) {
			add_filter( 'bwf_logs_allowed', '__return_true', PHP_INT_MAX );
			$logger_obj = BWF_Logger::get_instance();
			$logger_obj->log( date_i18n( 'Y-m-d H:i:s' ) . ' - before worker hit', 'fka-cron-check-v2', 'autonami' );
		}

		/** Stop autonami V2 worker request by filter */
		if ( true === apply_filters( 'bwfan_stop_autonami_v2_worker_request', false ) ) {
			self::event_advanced_logs( 'V2 worker request stopped by filter' );

			return;
		}

		/** Check if any contact in automation can proceed */
		if ( false === BWFAN_Model_Automation_Contact::maybe_can_execute() ) {
			return;
		}

		$url             = rest_url( '/autonami/v2/worker' ) . '?' . time();
		$body_data       = array(
			'worker'     => true,
			'unique_key' => get_option( 'bwfan_u_key', false ),
		);
		$args            = bwf_get_remote_rest_args( $body_data );
		$args['timeout'] = apply_filters( 'bwfan_as_worker_timeout_v2', 2 ); // Allow time for WordPress to bootstrap and send early response

		$resp = wp_remote_post( $url, $args );
		self::event_advanced_logs( 'V2 worker response' );
		if ( is_wp_error( $resp ) ) {
			self::event_advanced_logs( $resp->get_error_message() );
		} elseif ( isset( $resp['body'] ) ) {
			self::event_advanced_logs( $resp['body'] );
			self::event_advanced_logs( $resp['response'] );
		}
	}

	/**
	 * phpcs:ignore WordPress.Security.NonceVerification
	 * Return the html for tasks links on tasks listing page.
	 *
	 * @return string
	 */
	public static function get_link_options_for_tasks() {
		$scheduled_count = BWFAN_Core()->tasks->fetch_tasks_count( 0, 0 );
		/* translators: %d is the number of scheduled tasks */
		$scheduled = sprintf( __( 'Scheduled (%d)', 'wp-marketing-automations' ), $scheduled_count );

		$paused_count = BWFAN_Core()->tasks->fetch_tasks_count( 0, 1 );
		/* translators: %d is the number of paused tasks */
		$paused          = sprintf( __( 'Paused (%d)', 'wp-marketing-automations' ), $paused_count );
		$completed_count = BWFAN_Core()->logs->fetch_logs_count( 1 );
		/* translators: %d is the number of completed tasks */
		$completed    = sprintf( __( 'Completed (%d)', 'wp-marketing-automations' ), $completed_count );
		$failed_count = BWFAN_Core()->logs->fetch_logs_count( 0 );
		/* translators: %d is the number of failed tasks */
		$failed                = sprintf( __( 'Failed (%d)', 'wp-marketing-automations' ), $failed_count );
		$get_campaign_statuses = apply_filters( 'bwfan_admin_trigger_nav', array(
			't_0' => $scheduled,
			't_1' => $paused,
			'l_1' => $completed,
			'l_0' => $failed,
		) );
		$html                  = '<ul class="subsubsub subsubsub_bwfan">';
		$html_inside           = array();
		$current_status        = 't_0';

		if ( isset( $_GET['status'] ) && '' !== $_GET['status'] ) { //phpcs:ignore WordPress.Security.NonceVerification
			$current_status = sanitize_text_field( wp_unslash( $_GET['status'] ) );//phpcs:ignore WordPress.Security.NonceVerification
		}

		// For listing screen
		$all_statuses = array(
			't_0' => array(
				'tab' => 'tasks',
			),
			't_1' => array(
				'tab' => 'tasks',
			),
			'l_0' => array(
				'tab' => 'logs',
			),
			'l_1' => array(
				'tab' => 'logs',
			),
		);

		foreach ( $get_campaign_statuses as $slug => $status ) {
			$need_class = '';
			if ( $slug === $current_status ) {
				$need_class = 'current';
			}

			$args = array(
				'status' => $slug,
			);

			$args['tab']   = $all_statuses[ $slug ]['tab'];
			$url           = add_query_arg( $args, admin_url( 'admin.php?page=autonami-automations' ) );
			$html_inside[] = sprintf( '<li><a href="%s" class="%s">%s</a> </li>', $url, $need_class, $status );
		}

		if ( is_array( $html_inside ) && count( $html_inside ) > 0 ) {
			$html .= implode( '', $html_inside );
		}
		$html .= '</ul>';

		return $html;
	}

	public static function get_logging_status() {
		$global_settings = self::get_global_settings();

		return ( isset( $global_settings['bwfan_make_logs'] ) && 1 === intval( $global_settings['bwfan_make_logs'] ) ) ? true : false;
	}

	/**
	 * Capture all the action ids of an automation and delete all its tasks except for completed tasks.
	 *
	 * @param WP_REST_Request $request
	 */
	public static function delete_automation_tasks_by_unique_action_ids( WP_REST_Request $request ) {
		self::nocache_headers();
		$post_parameters = $request->get_body_params();

		if ( false === is_array( $post_parameters ) || 0 === count( $post_parameters ) ) {
			return;
		}
		if ( ! isset( $post_parameters['automation_id'] ) || ! isset( $post_parameters['a_track_id'] ) || ! isset( $post_parameters['t_to_delete'] ) ) {
			return;
		}
		/**
		 * Check Unique key security
		 */
		$unique_key = get_option( 'bwfan_u_key', false );
		if ( ! is_string( $unique_key ) || ! isset( $post_parameters['unique_key'] ) || ! is_string( $post_parameters['unique_key'] ) || ! hash_equals( $unique_key, $post_parameters['unique_key'] ) ) {
			return;
		}

		$automation_id = sanitize_text_field( $post_parameters['automation_id'] );
		$a_track_id    = sanitize_text_field( $post_parameters['a_track_id'] );
		$t_to_delete   = $post_parameters['t_to_delete'];
		$t_to_delete   = self::is_json( $t_to_delete ) ? json_decode( $t_to_delete, true ) : $t_to_delete;

		if ( false === is_array( $t_to_delete ) || 0 === count( $t_to_delete ) ) {
			return;
		}
		foreach ( $t_to_delete as $key1 => $action_index ) {
			$t_to_delete[ $key1 ] = $a_track_id . '_' . $action_index;
		}

		BWFAN_Core()->tasks->delete_by_index_ids( $automation_id, $t_to_delete );
		BWFAN_Core()->logs->delete_by_index_ids( $automation_id, $t_to_delete );
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
	 * Clean variables using sanitize_text_field. Arrays are cleaned recursively.
	 * Non-scalar values are ignored.
	 *
	 * @param string|array $var Data to sanitize.
	 *
	 * @return string|array
	 */
	public static function bwfan_clean( $var ) {
		if ( is_array( $var ) ) {
			return array_map( 'self::bwfan_clean', $var );
		} else {
			return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
		}
	}

	/**
	 * Update contact automation table for current automation.
	 *
	 * @param WP_REST_Request $request
	 */
	public static function update_contact_meta( WP_REST_Request $request ) {
		self::nocache_headers();
		$post_parameters = $request->get_body_params();

		if ( false === is_array( $post_parameters ) || 0 === count( $post_parameters ) ) {
			return;
		}
		if ( ! isset( $post_parameters['automation_id'] ) || ! isset( $post_parameters['email'] ) || ! isset( $post_parameters['user_id'] ) ) {
			return;
		}
		/**
		 * Check Unique key security
		 */
		$unique_key = get_option( 'bwfan_u_key', false );
		if ( ! is_string( $unique_key ) || ! isset( $post_parameters['unique_key'] ) || ! is_string( $post_parameters['unique_key'] ) || ! hash_equals( $unique_key, $post_parameters['unique_key'] ) ) {
			return;
		}

		$automation_id = sanitize_text_field( $post_parameters['automation_id'] );
		$email         = sanitize_text_field( $post_parameters['email'] );
		$user_id       = sanitize_text_field( $post_parameters['user_id'] );
		$contact_obj   = bwf_get_contact( $user_id, $email );
		$contact_id    = $contact_obj->id;

		if ( ! isset( $contact_id ) || empty( $contact_id ) ) {
			return;
		}

		$data = array(
			'contact_id'    => $contact_id,
			'automation_id' => $automation_id,
			'time'          => time(),
		);

		BWFAN_Model_Contact_Automations::insert( $data );
	}

	public static function update_generated_increment( WP_REST_Request $request ) {
		self::nocache_headers();
		$post_parameters = $request->get_body_params();
		if ( false === is_array( $post_parameters ) || 0 === count( $post_parameters ) ) {
			return;
		}
		if ( ! isset( $post_parameters['id'] ) ) {
			return;
		}
		/**
		 * Check Unique key security
		 */
		$unique_key = get_option( 'bwfan_u_key', false );
		if ( ! is_string( $unique_key ) || ! isset( $post_parameters['unique_key'] ) || ! is_string( $post_parameters['unique_key'] ) || ! hash_equals( $unique_key, $post_parameters['unique_key'] ) ) {
			return;
		}

		$date = date( 'Y-m-d' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
		/**
		 * All calculations are done via this row and the stats displayed are fetched from this row only.
		 */
		WFCO_Model_Report_views::update_data( $date, 0, 1 );
		/**
		 * If AeroCheckout page
		 *
		 * This row is saved for future use
		 */
		if ( ! empty( $post_parameters['id'] ) ) {
			WFCO_Model_Report_views::update_data( $date, $post_parameters['id'], 1 );
		}
	}

	public static function create_token( $length = 25 ) {
		return wp_generate_password( $length, false );
	}

	/**
	 * Get human readable time format like 18 minutes 47 seconds ago
	 *
	 * @param $timestamp
	 * @param $date
	 *
	 * @return string
	 */
	public static function get_human_readable_time( $timestamp, $date ) {
		$current_timestamp = gmdate( 'U' );
		if ( $current_timestamp > $timestamp ) {
			$schedule_display_string = '<time title="' . $date . '">' . self::human_interval( gmdate( 'U' ) - $timestamp ) . __( ' ago', 'wp-marketing-automations' ) . '</time>';
		} else {
			$schedule_display_string = '<time title="' . $date . '">' . __( 'in ', 'wp-marketing-automations' ) . self::human_interval( $timestamp - gmdate( 'U' ) ) . '</time>';
		}

		return $schedule_display_string;
	}

	public static function human_interval( $interval, $periods_to_include = 2 ) {

		self::$time_periods = array(
			array(
				'seconds' => YEAR_IN_SECONDS,
				'names'   => _n_noop( '%s year', '%s years', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
			),
			array(
				'seconds' => MONTH_IN_SECONDS,
				'names'   => _n_noop( '%s month', '%s months', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
			),
			array(
				'seconds' => WEEK_IN_SECONDS,
				'names'   => _n_noop( '%s week', '%s weeks', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
			),
			array(
				'seconds' => DAY_IN_SECONDS,
				'names'   => _n_noop( '%s day', '%s days', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
			),
			array(
				'seconds' => HOUR_IN_SECONDS,
				'names'   => _n_noop( '%s hour', '%s hours', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
			),
			array(
				'seconds' => MINUTE_IN_SECONDS,
				'names'   => _n_noop( '%s minute', '%s minutes', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
			),
			array(
				'seconds' => 1,
				'names'   => _n_noop( '%s second', '%s seconds', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
			),
		);

		if ( $interval <= 0 ) {
			return __( 'Now!', 'wp-marketing-automations' );
		}

		$output = '';

		for ( $time_period_index = 0, $periods_included = 0, $seconds_remaining = $interval; $time_period_index < count( self::$time_periods ) && $seconds_remaining > 0 && $periods_included < $periods_to_include; $time_period_index ++ ) {

			$periods_in_interval = floor( $seconds_remaining / self::$time_periods[ $time_period_index ]['seconds'] );
			if ( $periods_in_interval > 0 ) {
				if ( ! empty( $output ) ) {
					$output .= ' ';
				}
				$output            .= sprintf( _n( self::$time_periods[ $time_period_index ]['names'][0], self::$time_periods[ $time_period_index ]['names'][1], $periods_in_interval, 'wp-marketing-automations' ), $periods_in_interval ); //phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralPlural, WordPress.WP.I18n.NonSingularStringLiteralSingle
				$seconds_remaining -= $periods_in_interval * self::$time_periods[ $time_period_index ]['seconds'];
				$periods_included ++;
			}
		}

		return $output;
	}

	/**
	 * Return seconds from 24 hr format.
	 *
	 * @param $str_time
	 *
	 * @return float|int
	 */
	public static function get_seconds_from_time_format( $str_time ) {
		$hours   = '';
		$minutes = '';
		$seconds = '';

		sscanf( $str_time, '%d:%d:%d', $hours, $minutes, $seconds );
		$time_seconds = ( isset( $hours ) && ! empty( $hours ) ) ? absint( $hours ) * 3600 + absint( $minutes ) * 60 + absint( $seconds ) : absint( $minutes ) * 60 + absint( $seconds );

		return $time_seconds;
	}

	/**
	 * Get the nearest date.
	 *
	 * @param $actual_timestamp
	 * @param $days_selected
	 *
	 * @return false|int
	 */
	public static function get_nearest_date( $actual_timestamp, $days_selected ) {
		$date_object = new DateTime( date( ( 'Y-m-d H:i:s' ), $actual_timestamp ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions

		for ( $h = 0; $h < 7; $h ++ ) {
			if ( in_array( $date_object->format( "N" ), $days_selected, true ) ) {
				return $date_object->getTimestamp();
			}
			$date_object->modify( '+1 days' );
		}

		return $actual_timestamp;
	}

	/**
	 * Find the closest matching date.
	 *
	 * @param $array
	 * @param $date
	 *
	 * @return mixed
	 */
	public static function find_closest( $array, $date ) {
		$interval = array();
		foreach ( $array as $day ) {
			$interval[] = abs( strtotime( $date ) - strtotime( $day ) );
		}

		asort( $interval );
		$closest = key( $interval );

		return $array[ $closest ];
	}

	/**
	 * @param $ids
	 * @param $status
	 */
	public static function update_abandoned_rows( $ids, $status ) {
		if ( ! is_array( $ids ) || count( $ids ) === 0 ) {
			return;
		}

		global $wpdb;
		$ids                    = array_map( 'absint', $ids );
		$status                 = absint( $status );
		$automationCount        = count( $ids );
		$stringPlaceholders     = array_fill( 0, $automationCount, '%d' );
		$placeholdersautomation = implode( ', ', $stringPlaceholders );
		$params                 = array_merge( array( $status ), $ids );
		$sql_query              = $wpdb->prepare( "Update {table_name} Set status = %d WHERE ID IN ($placeholdersautomation)", ...$params );// phpcs:ignore WordPress.DB.PreparedSQL

		BWFAN_Model_Abandonedcarts::query_with_retry( $sql_query, 'update_abandoned_rows' );
	}

	/**
	 * Delete all the tasks related to an abandoned cart row
	 *
	 * @param $abandoned_cart_id
	 */
	public static function delete_abandoned_cart_tasks( $abandoned_cart_id ) {
		global $wpdb;
		$meta_key = 'c_a_id';
		$query    = $wpdb->prepare( 'SELECT `bwfan_task_id` FROM {table_name} WHERE meta_key = %s AND meta_value = %s', $meta_key, $abandoned_cart_id );
		$result   = BWFAN_Model_Taskmeta::get_results( $query );

		if ( ! is_array( $result ) || count( $result ) === 0 ) {
			return;
		}

		$task_ids = array();
		foreach ( $result as $value1 ) {
			$task_ids[] = $value1['bwfan_task_id'];
		}

		BWFAN_Core()->tasks->delete_tasks( $task_ids );
	}

	/**
	 * This function checks for all the active carts. If last modified time of active carts exceeds the global cart timeout setting,
	 * then those carts will me made as abandoned.
	 */
	public static function check_for_abandoned_carts() {
		if ( ! class_exists( 'WooCommerce' ) && bwf_has_action_scheduled( 'bwfan_check_abandoned_carts' ) ) {
			bwf_unschedule_actions( 'bwfan_check_abandoned_carts' );

			return;
		}

		$global_settings = self::get_global_settings();
		if ( empty( $global_settings['bwfan_ab_enable'] ) ) {
			return;
		}

		/**
		 * Best-effort reentrancy guard against redundant work and double-processing:
		 * `get_eligible_abandoned_rows()` SELECTs by status without claiming rows up front,
		 * so two concurrent workers would otherwise process the same carts twice (duplicate
		 * automations, duplicate recovery emails). Deadlock retry at the model layer recovers
		 * from row-level contention but does not prevent that duplicate work.
		 *
		 * Not a distributed mutex: get_transient + set_transient are not atomic, and the 120s
		 * TTL self-heals if processing crashes or exceeds the window.
		 */
		if ( false !== get_transient( 'bwfan_ab_cart_processing' ) ) {
			return;
		}
		set_transient( 'bwfan_ab_cart_processing', 1, 120 );

		try {
			/** Maybe run */
			if ( false === BWFAN_Model_Abandonedcarts::maybe_run( $global_settings['bwfan_ab_init_wait_time'] ) ) {
				return;
			}

			$all_sources = BWFAN_Load_Sources::get_all_sources_obj();
			$all_sources['wc']['ab_cart_abandoned']->load_hooks();
			$all_sources['wc']['ab_cart_abandoned']->get_eligible_abandoned_rows();
		} finally {
			delete_transient( 'bwfan_ab_cart_processing' );
		}
	}

	/**
	 * Delete all the old abandoned rows from db table. This function runs once in a week.
	 */
	public static function delete_old_abandoned_carts() {
		if ( false === apply_filters( 'bwfan_ab_delete_inactive_carts', false ) ) {
			return;
		}

		global $wpdb;
		$global_settings        = self::get_global_settings();
		$abandoned_time_in_days = absint( $global_settings['bwfan_ab_remove_inactive_cart_time'] ) * 1440;
		$query                  = $wpdb->prepare( 'select T.ID from {table_name} T where TIMESTAMPDIFF(MINUTE,T.last_modified,UTC_TIMESTAMP) > %d', $abandoned_time_in_days );
		$abandoned_carts        = BWFAN_Model_Abandonedcarts::get_results( $query );

		if ( ! is_array( $abandoned_carts ) || count( $abandoned_carts ) === 0 ) {
			return;
		}

		$abandoned_cart_ids = array();
		foreach ( $abandoned_carts as $value1 ) {
			$abandoned_cart_ids[] = absint( $value1['ID'] );
		}

		$automationCount        = count( $abandoned_cart_ids );
		$stringPlaceholders     = array_fill( 0, $automationCount, '%d' );
		$placeholdersautomation = implode( ', ', $stringPlaceholders );
		$sql_query              = $wpdb->prepare( "Delete FROM {table_name} WHERE ID IN ($placeholdersautomation)", $abandoned_cart_ids );// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated via array_fill; ids absint()ed

		BWFAN_Model_Abandonedcarts::delete_multiple( $sql_query );
	}

	/**
	 * Scheduling recurring action for marking lost cart
	 *
	 * @return void
	 */
	public static function mark_abandoned_lost_cart() {
		if ( ! class_exists( 'WooCommerce' ) && bwf_has_action_scheduled( 'bwfan_mark_abandoned_lost_cart' ) ) {
			bwf_unschedule_actions( 'bwfan_mark_abandoned_lost_cart' );

			return;
		}

		if ( ! bwf_has_action_scheduled( 'bwfan_lost_cart_triggered' ) ) {
			bwf_schedule_recurring_action( time(), 120, 'bwfan_lost_cart_triggered' );
		}
	}

	/**
	 * Mark cart lost action callback
	 *
	 * @return void
	 */
	public static function bwfan_lost_cart_triggered() {
		global $wpdb;
		$global_settings        = self::get_global_settings();
		$abandoned_time_in_days = absint( $global_settings['bwfan_ab_mark_lost_cart'] ) * 1440;
		$start_time             = time();
		do {
			/** Get carts to mark lost carts */
			$query = "SELECT ID, email FROM {$wpdb->prefix}bwfan_abandonedcarts WHERE TIMESTAMPDIFF(MINUTE,last_modified,UTC_TIMESTAMP) > %d AND `status` !=  %d LIMIT %d";
			$query = $wpdb->prepare( $query, $abandoned_time_in_days, 2, 10 );// phpcs:ignore WordPress.DB.PreparedSQL
			$carts = $wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

			/** Un-schedule the action if no cart found */
			if ( empty( $carts ) ) {
				bwf_unschedule_actions( 'bwfan_lost_cart_triggered' );

				return;
			}

			/** Add lost tags & lists */
			self::add_lost_cart_tags( $carts );

			$ids                = array_column( $carts, 'ID' );
			$stringPlaceholders = array_fill( 0, count( $ids ), '%d' );
			$placeholder        = implode( ', ', $stringPlaceholders );
			/** Changing status recoverable into lost cart */
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bwfan_abandonedcarts SET `status` = 2 WHERE ID IN ( $placeholder )", $ids ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders
		} while ( ( time() - $start_time ) > 10 );
	}

	/**
	 * Add tags and list to lost carts
	 *
	 * @param $carts
	 *
	 * @return void
	 */
	public static function add_lost_cart_tags( $carts ) {
		if ( ! class_exists( 'BWFCRM_Contact' ) || ! is_array( $carts ) || 0 === count( $carts ) ) {
			return;
		}

		if ( false === method_exists( 'BWFAN_Model_Terms', 'get_crm_term_ids' ) ) {
			/** Pro version 2.4.4 or higher required */
			return;
		}

		$global_settings = self::get_global_settings();

		$lost_cart_tags = isset( $global_settings['bwfan_lostcart_tag_selector'] ) ? json_decode( $global_settings['bwfan_lostcart_tag_selector'], true ) : [];
		$lost_cart_tags = BWFAN_Model_Terms::get_crm_term_ids( $lost_cart_tags, BWFCRM_Term_Type::$TAG );

		$lost_cart_list = isset( $global_settings['bwfan_lostcart_list_selector'] ) ? $global_settings['bwfan_lostcart_list_selector'] : [];
		$lost_cart_list = BWFAN_Model_Terms::get_crm_term_ids( $lost_cart_list, BWFCRM_Term_Type::$LIST );

		/** If tags & lists are not set in setting */
		if ( empty( $lost_cart_tags ) && empty( $lost_cart_list ) ) {
			return;
		}

		foreach ( $carts as $cart ) {
			$contact = new BWFCRM_Contact( $cart['email'] );
			if ( ! $contact->is_contact_exists() ) {
				continue;
			}

			if ( ! empty( $lost_cart_tags ) ) {
				$contact->set_tags_v2( $lost_cart_tags );
			}

			if ( ! empty( $lost_cart_list ) ) {
				$contact->set_lists_v2( $lost_cart_list );
			}

			$contact->save();
		}
	}

	/**
	 * Delete all the old abandoned rows from db table. This function runs once in a week.
	 */
	public static function delete_expired_autonami_coupons() {
		if ( ! class_exists( 'WooCommerce' ) && bwf_has_action_scheduled( 'bwfan_delete_expired_autonami_coupons' ) ) {
			bwf_unschedule_actions( 'bwfan_delete_expired_autonami_coupons' );

			return;
		}

		global $wpdb;

		$global_settings = self::get_global_settings();

		if ( empty( $global_settings['bwfan_delete_expired_coupon'] ) ) {
			return;
		}

		/** 1 day = 1440 minutes */
		$coupon_time_in_days = absint( $global_settings['bwfan_delete_autonami_generated_coupons_time'] ) * 1440;
		if ( ( 30 * 1440 ) < $coupon_time_in_days ) {
			$coupon_time_in_days = 30 * 1440;
		}

		$query = $wpdb->prepare( "
				SELECT m1.post_id as id
				FROM {$wpdb->prefix}postmeta as m1
				LEFT JOIN {$wpdb->prefix}postmeta as m2
				ON m1.post_id = m2.post_id
				LEFT JOIN {$wpdb->prefix}postmeta as m3
				ON m1.post_id = m3.post_id
				WHERE m1.meta_key = %s
				AND m1.meta_value = %d
				AND m2.meta_key = %s
				AND TIMESTAMPDIFF(MINUTE,FROM_UNIXTIME(m2.meta_value),UTC_TIMESTAMP) > %d
				AND m3.meta_key= %s
				AND m3.meta_value <>''
				", '_is_bwfan_coupon', 1, 'date_expires', $coupon_time_in_days, 'date_expires' );

		$coupons = $wpdb->get_results( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		if ( empty( $coupons ) ) {
			return;
		}

		foreach ( $coupons as $coupon ) {
			wp_delete_post( $coupon->id, true );
		}
	}

	/**
	 * Get all the scheduled task of given automation ids and the contact email
	 *
	 * @param array $winback_automations
	 * @param string $email
	 *
	 * @return array
	 */
	public static function get_schedule_task_by_email( $winback_automations, $email ) {
		global $wpdb;
		$task_table_name      = $wpdb->prefix . 'bwfan_tasks';
		$task_meta_table_name = $wpdb->prefix . 'bwfan_taskmeta';
		$tasks_results        = array();

		if ( empty( $winback_automations ) || empty( $email ) ) {
			return $tasks_results;
		}

		foreach ( $winback_automations as $automation_id ) {
			$query = $wpdb->prepare( "SELECT t.ID FROM $task_table_name AS t JOIN $task_meta_table_name AS tm ON t.ID=tm.bwfan_task_id WHERE t.automation_id = %d AND tm.meta_key='integration_data' AND tm.meta_value LIKE %s", $automation_id, '%' . $email . '%' );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders

			$tasks_results[ $automation_id ] = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- tables from $wpdb->prefix; values bound via prepare()
		}

		return $tasks_results;
	}

	/**
	 * Get all the scheduled tasks of given automation ids and the contact phone number
	 *
	 * @param $winback_automations
	 * @param $phone
	 *
	 * @return array
	 */
	public static function get_schedule_task_by_phone( $winback_automations, $phone ) {
		global $wpdb;
		$task_table_name      = $wpdb->prefix . 'bwfan_tasks';
		$task_meta_table_name = $wpdb->prefix . 'bwfan_taskmeta';
		$tasks_results        = array();

		if ( empty( $winback_automations ) || empty( $phone ) ) {
			return $tasks_results;
		}

		foreach ( $winback_automations as $automation_id ) {
			$query = $wpdb->prepare( "SELECT t.ID FROM $task_table_name AS t JOIN $task_meta_table_name AS tm ON t.ID=tm.bwfan_task_id WHERE t.automation_id = %d AND tm.meta_key='integration_data' AND tm.meta_value LIKE %s", $automation_id, '%' . $phone . '%' );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders

			$tasks_results[ $automation_id ] = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- tables from $wpdb->prefix; values bound via prepare()
		}

		return $tasks_results;
	}

	public static function wc_get_cart_recovery_url( $token, $coupon = '', $lang = '', $data = [] ) {
		$checkout_id = get_option( 'woocommerce_checkout_page_id' );
		if ( isset( $data['aerocheckout_page_id'] ) && intval( $data['aerocheckout_page_id'] ) > 0 && 'publish' === get_post_status( $data['aerocheckout_page_id'] ) ) {
			$is_global_checkout = $data['aero_data']['wfacp_is_checkout_override'] ?? false;
			if ( empty( $is_global_checkout ) ) {
				$checkout_id = $data['aerocheckout_page_id'];
			}
		}

		/**
		 * Making checkout page compatible with the WPML
		 * Trying & getting the base language translation post to validate the checkout page
		 */
		$url = ! empty( $checkout_id ) ? self::get_permalink_by_language( $checkout_id, $lang ) : '';
		if ( empty( $url ) ) {
			$url = home_url();
		}

		$url = self::maybe_modify_recovery_link( $url, $data, $lang );

		$url = add_query_arg( array(
			'bwfan-ab-id' => $token,
		), $url );

		if ( ! empty( $coupon ) ) {
			$url = add_query_arg( array(
				'bwfan-coupon' => preg_replace( "/&#?[a-z0-9]{2,8};/i", "", $coupon ),
			), $url );
		}

		return apply_filters( 'bwfan_abandoned_cart_restore_link', $url, $token, $coupon );
	}

	/**
	 * @param $post_id
	 * @param string $lang
	 *
	 * Get permalink by language
	 *
	 * @return false|mixed|string|void
	 */
	public static function get_permalink_by_language( $post_id, $lang = '' ) {

		$url = get_permalink( $post_id );
		$url = empty( $url ) ? home_url() : $url;

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			global $sitepress;

			$language_code = $sitepress->get_default_language();

			if ( ! empty( $lang ) ) {
				$language_code = $lang;
			}
			if ( version_compare( ICL_SITEPRESS_VERSION, '3.2' ) > 0 ) {
				$post_id = apply_filters( 'wpml_object_id', $post_id, 'page', false, $language_code );
			} else {
				$post_id = wpml_object_id_filter( $post_id, 'page', false, $language_code );
			}

			$url = apply_filters( 'wpml_permalink', $url, $language_code );
		}

		/** in case of translatepress */
		if ( bwfan_is_translatepress_active() ) {
			$trp_settings  = get_option( 'trp_settings' );
			$language_code = $trp_settings['default-language'];
			if ( ! empty( $lang ) ) {
				$language_code = $lang;
			}
			$trp           = TRP_Translate_Press::get_trp_instance();
			$url_converter = $trp->get_component( 'url_converter' );
			$url           = $url_converter->get_url_for_language( $language_code, $url, '' );
		}

		/** for polylang language */
		if ( function_exists( 'pll_current_language' ) ) {
			$language_code = pll_default_language();

			if ( ! empty( $lang ) ) {
				$language_code = $lang;
			}

			$url = add_query_arg( array(
				'lang' => $language_code,
			), $url );
		}

		if ( function_exists( 'bwfan_is_weglot_active' ) && bwfan_is_weglot_active() ) {
			$language_code = weglot_get_original_language();

			if ( ! empty( $lang ) ) {
				$language_code = $lang;
			}

			$site_url = home_url();
			$url      = str_replace( $site_url, $site_url . '/' . $language_code, $url );
		}

		/** For GTranslate */ //
		if ( function_exists( 'bwfan_is_gtranslate_active' ) && bwfan_is_gtranslate_active() && ! empty( $lang ) ) {
			$url = class_exists( 'BWFAN_Compatibility_With_GTRANSLATE' ) ? BWFAN_Compatibility_With_GTRANSLATE::get_translated_domain_url( $url, $lang ) : $url;
		}

		return $url;
	}

	public static function maybe_modify_recovery_link( $url, $data = [], $lang = '' ) {
		/** for polylang language */
		if ( function_exists( 'pll_current_language' ) ) {
			return apply_filters( 'bwfan_modify_checkout_recovery_url_polylang', $url, $data, $lang );
		}

		return $url;
	}

	/**
	 * Get all the abandoned carts by email with status 1 and 2
	 *
	 * @param $email
	 *
	 * @return array|null|object|void
	 */
	public static function get_email_abandoned( $email ) {
		if ( empty( $email ) ) {
			return;
		}
		global $wpdb;
		$abandoned_table = $wpdb->prefix . 'bwfan_abandonedcarts';
		$email           = sanitize_email( $email );
		$abandoned_data  = $wpdb->get_results( $wpdb->prepare( "SELECT ID, last_modified FROM {$abandoned_table} WHERE status IN (1, 2) AND email = %s ORDER BY last_modified LIMIT 0, 3", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; email bound via %s

		return $abandoned_data;
	}

	/**
	 * Set the status 3 i.e. aborted of the abandoned cart
	 *
	 * @param $abandoned_id
	 */
	public static function set_email_cart_aborted( $abandoned_id ) {
		if ( empty( $abandoned_id ) ) {
			return;
		}

		$data           = [];
		$data['status'] = 3;
		$where          = array(
			'ID' => $abandoned_id,
		);

		BWFAN_Model_Abandonedcarts::update( $data, $where );
	}

	public static function merge_default_actions() {
		$all_automations = self::get_default_connector();
		$integrations    = self::get_default_actions();
		$default_data    = array();

		foreach ( $all_automations as $a_slug => $automation ) {
			$nice_name = $automation['nice_name'];
			if ( isset( $integrations[ $a_slug ] ) ) {
				$actions = $integrations[ $a_slug ];
				foreach ( $actions as $slug => $action ) {
					if ( ! class_exists( 'bwfan_' . $slug ) || ! bwfan_is_autonami_pro_active() ) {
						$default_data[ $nice_name ][ $slug ] = $action;
					}
				}
			}
		}

		return empty( $default_data ) ? new stdClass() : $default_data;
	}

	public static function get_default_connector() {
		return array(
			'wc'             => array(
				'nice_name'          => __( 'WooCommerce', 'wp-marketing-automations' ),
				'slug'               => 'wp_adv',
				'connector_slug'     => '',
				'native_integration' => true,
			),
			'wp_adv'         => array(
				'nice_name'          => __( 'WordPress Advanced', 'wp-marketing-automations' ),
				'slug'               => 'wp_adv',
				'connector_slug'     => '',
				'native_integration' => true,
			),
			'activecampaign' => array(
				'nice_name'          => __( 'ActiveCampaign', 'wp-marketing-automations' ),
				'slug'               => 'activecampaign',
				'connector_slug'     => 'bwfco_activecampaign',
				'native_integration' => false,
			),
			'drip'           => array(
				'nice_name'          => __( 'Drip', 'wp-marketing-automations' ),
				'slug'               => 'drip',
				'connector_slug'     => 'bwfco_drip',
				'native_integration' => false,
			),
			'google_sheets'  => array(
				'nice_name'          => __( 'Google Sheets', 'wp-marketing-automations' ),
				'slug'               => 'google_sheets',
				'connector_slug'     => 'bwfco_google_sheets',
				'native_integration' => false,
			),
			'slack'          => array(
				'nice_name'          => __( 'Slack', 'wp-marketing-automations' ),
				'slug'               => 'slack',
				'connector_slug'     => 'bwfco_slack',
				'native_integration' => false,
			),
			'zapier'         => array(
				'nice_name'          => __( 'Zapier', 'wp-marketing-automations' ),
				'slug'               => 'zapier',
				'connector_slug'     => '',
				'native_integration' => false,
			),
		);
	}

	public static function get_default_actions() {

		return array(
			'wc'             => array(
				'wc_change_order_status' => __( 'Change Order Status', 'wp-marketing-automations' ),
				'wc_add_order_note'      => __( 'Add Order Note', 'wp-marketing-automations' ),
				'wc_remove_coupon'       => __( 'Delete Coupon', 'wp-marketing-automations' ),
			),
			'activecampaign' => array(
				'ac_create_contact'        => __( 'Create Contact', 'wp-marketing-automations' ),
				'ac_add_tag'               => __( 'Add Tags', 'wp-marketing-automations' ),
				'ac_rmv_tag'               => __( 'Remove Tags', 'wp-marketing-automations' ),
				'ac_add_to_automation'     => __( 'Add Contact To Automation', 'wp-marketing-automations' ),
				'ac_rmv_from_automation'   => __( 'Remove Contact From Automation', 'wp-marketing-automations' ),
				'ac_add_to_list'           => __( 'Add Contact To List', 'wp-marketing-automations' ),
				'ac_rmv_from_list'         => __( 'Remove Contact From List', 'wp-marketing-automations' ),
				'ac_create_abandoned_cart' => __( 'Create Abandoned Cart', 'wp-marketing-automations' ),
				'ac_create_order'          => __( 'Create Order', 'wp-marketing-automations' ),
				'ac_create_deal'           => __( 'Create Deal', 'wp-marketing-automations' ),
				'ac_create_deal_note'      => __( 'Create Deal Note', 'wp-marketing-automations' ),
				'ac_update_deal'           => __( 'Update Deal', 'wp-marketing-automations' ),
				'ac_update_customfields'   => __( 'Update Fields', 'wp-marketing-automations' ),
			),
			'drip'           => array(
				'dr_create_subscriber' => __( 'Create / Update Subscriber', 'wp-marketing-automations' ),
				'dr_add_tags'          => __( 'Add Tags', 'wp-marketing-automations' ),
				'dr_rmv_tags'          => __( 'Remove Tags', 'wp-marketing-automations' ),
				'dr_add_to_campaign'   => __( 'Add Subscriber to Campaign', 'wp-marketing-automations' ),
				'dr_rmv_from_campaign' => __( 'Remove Subscriber from Campaign', 'wp-marketing-automations' ),
				'dr_add_to_workflow'   => __( 'Add Subscriber to Workflow', 'wp-marketing-automations' ),
				'dr_rmv_from_workflow' => __( 'Remove Subscriber from Workflow', 'wp-marketing-automations' ),
				'dr_add_cart'          => __( 'Cart Activity', 'wp-marketing-automations' ),
				'dr_add_order'         => __( 'Add A New Order', 'wp-marketing-automations' ),
				'dr_add_customfields'  => __( 'Update Custom fields of Subscriber', 'wp-marketing-automations' ),
			),
			'convertkit'     => array(
				'ck_add_customfields'  => __( 'Update Custom Fields', 'wp-marketing-automations' ),
				'ck_add_tags'          => __( 'Add Tags', 'wp-marketing-automations' ),
				'ck_rmv_tags'          => __( 'Remove Tags', 'wp-marketing-automations' ),
				'ck_add_to_sequence'   => __( 'Add Subscriber To Sequence', 'wp-marketing-automations' ),
				'ck_rmv_from_sequence' => __( 'Remove Subscriber from Sequence', 'wp-marketing-automations' ),
				'ck_add_order'         => __( 'Create A New Purchase', 'wp-marketing-automations' ),
			),
			'google_sheets'  => array(
				'gs_insert_data' => __( 'Insert Row', 'wp-marketing-automations' ),
				'gs_update_data' => __( 'Update Row', 'wp-marketing-automations' ),
			),
			'slack'          => array(
				'sl_message_user' => __( 'Sends a message to a user', 'wp-marketing-automations' ),
				'sl_message'      => __( 'Sends a message to a channel', 'wp-marketing-automations' ),
			),
			'zapier'         => array(
				'za_send_data' => __( 'Send data to zapier', 'wp-marketing-automations' ),
			),
			'twilio'         => array(
				'twilio_send_sms' => __( 'Send SMS', 'wp-marketing-automations' ),
			),
			'wp_adv'         => array(
				'wp_createuser'       => __( 'Create User', 'wp-marketing-automations' ),
				'wp_update_user_meta' => __( 'Update User Meta', 'wp-marketing-automations' ),
				'wp_http_post'        => __( 'HTTP Post', 'wp-marketing-automations' ),
				'wp_custom_callback'  => __( 'Custom Callback', 'wp-marketing-automations' ),
				'wp_debug'            => __( 'Debug', 'wp-marketing-automations' ),
			),
		);
	}

	public static function merge_pro_events( $events ) {

		$default = self::default_events();
		foreach ( $default as $slug => $data ) {
			if ( ! class_exists( 'BWFAN_' . $slug ) || ! bwfan_is_autonami_pro_active() ) {
				$source_type                                           = $data['source_type'];
				$events[ $source_type ]['events']['Upstroke'][ $slug ] = array(
					'name'      => $data['event_name'],
					'available' => 'no',
				);
			}
		}

		return $events;
	}

	public static function default_events() {
		return array();

		return array(
			'wf_offer_viewed'         => array(
				'source_type'         => 'wc',
				'event_name'          => __( 'Offer Viewed', 'wp-marketing-automations' ),
				'event_desc'          => 'This automation would trigger when an offer is viewed by the user.',
				'slug'                => 'wf_offer_viewed',
				'is_time_independent' => false,
				'excluded_actions'    => array(),
				'event_saved_data'    => array(),
				'available'           => 'no',
			),
			'wf_product_accepted'     => array(
				'source_type'         => 'wc',
				'event_name'          => __( 'Offer Accepted', 'wp-marketing-automations' ),
				'event_desc'          => 'This automation would trigger when an upstroke offer is accepted by the user.',
				'slug'                => 'wf_product_accepted',
				'is_time_independent' => false,
				'excluded_actions'    => array(),
				'event_saved_data'    => array(),
				'available'           => 'no',
			),
			'wf_offer_payment_failed' => array(
				'source_type'         => 'wc',
				'event_name'          => __( 'Offer Payment Failed', 'wp-marketing-automations' ),
				'event_desc'          => 'This automation would trigger when the payment is failed while accepting an offer.',
				'slug'                => 'wf_offer_payment_failed',
				'is_time_independent' => false,
				'excluded_actions'    => array(),
				'event_saved_data'    => array(),
				'available'           => 'no',
			),
			'wf_offer_rejected'       => array(
				'source_type'         => 'wc',
				'event_name'          => __( 'Offer Rejected', 'wp-marketing-automations' ),
				'event_desc'          => 'This automation would trigger when an offer is rejected by the user.',
				'slug'                => 'wf_offer_rejected',
				'is_time_independent' => false,
				'excluded_actions'    => array(),
				'event_saved_data'    => array(),
				'available'           => 'no',
			),
		);
	}

	/**
	 * Get Autonami notifications only
	 *
	 * @return array
	 */
	public static function get_autonami_notifications() {
		if ( ! class_exists( 'WooFunnels_Notifications' ) ) {
			return array();
		}
		$notifications_list = WooFunnels_Notifications::get_instance()->get_all_notifications();
		if ( ! is_array( $notifications_list ) || ! isset( $notifications_list['bwfan'] ) ) {
			return array();
		}
		if ( ! is_array( $notifications_list['bwfan'] ) || count( $notifications_list['bwfan'] ) === 0 ) {
			return array();
		}

		return array(
			'bwfan' => $notifications_list['bwfan'],
		);
	}

	/**
	 * Used as fallback to make sure all the tasks run on the thank you page
	 */
	public static function hit_cron_to_run_tasks() {
		$url  = rest_url( '/autonami/v1/autonami-cron' );
		$args = bwf_get_remote_rest_args( array(), 'GET' );

		$args['timeout'] = apply_filters( 'bwfan_as_worker_timeout_v2', 2 ); // Allow time for WordPress to bootstrap and send early response

		wp_remote_post( $url, $args );
	}

	public static function add_ordinal_number_suffix( $num ) {
		if ( ! in_array( ( $num % 100 ), array( 11, 12, 13 ), true ) ) {
			switch ( $num % 10 ) {
				// Handle 1st, 2nd, 3rd
				case 1:
					return $num . 'st';
				case 2:
					return $num . 'nd';
				case 3:
					return $num . 'rd';
			}
		}

		return $num . 'th';
	}

	public static function bwfan_recipe_list_template() {
		ob_start();

		include plugin_dir_path( __FILE__ ) . 'bwfan-recipe-list-template.php';

		return ob_get_clean();
	}

	public static function auto_apply_wc_coupon() {
		if ( ! isset( $_GET['bwfan-coupon'] ) || empty( $_GET['bwfan-coupon'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$coupon_code = $_GET['bwfan-coupon']; //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput

		if ( WC()->cart instanceof WC_Cart && ! empty( $coupon_code ) && ! WC()->cart->has_discount( $coupon_code ) ) {
			/** Keep existing wc notices */
			$wc_notices = wc_get_notices();

			WC()->cart->add_discount( $coupon_code );

			/** Add all old wc notices back */
			WC()->session->set( 'wc_notices', $wc_notices );
		}
	}

	public static function mark_automation_require_update( $automation_id, $state = true ) {
		if ( empty( $automation_id ) ) {
			return;
		}

		$meta_data = array(
			'meta_value' => ( true === $state ) ? 1 : 0, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		);
		$where     = array(
			'bwfan_automation_id' => $automation_id,
			'meta_key'            => 'requires_update', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		);
		BWFAN_Model_Automationmeta::update( $meta_data, $where );
	}

	/**
	 * checking plugin dependency
	 *
	 * @param $plugin_depend
	 *
	 * @return array|bool
	 */
	public static function plugin_dependency_check( $plugin_depend ) {
		if ( empty( $plugin_depend ) ) {
			return true;
		}
		$plugin_error = array();
		foreach ( $plugin_depend as $plugins ) {
			$function_name = 'bwfan_is_' . $plugins . '_active';

			/** checking if function exists */
			if ( ! function_exists( $function_name ) ) {
				continue;
			}

			if ( false === $function_name() ) {
				$nice_name      = self::plugin_dependency_nice_names( $plugins );
				$plugin_error[] = "{$nice_name} plugin is not active.";
			}
		}

		return empty( $plugin_error ) ? true : $plugin_error;
	}

	public static function plugin_dependency_nice_names( $slug ) {
		switch ( $slug ) {
			case 'woocommerce':
				$slug = 'WooCommerce';
				break;
			case 'edd':
				$slug = 'Easy Digital Downloads';
				break;
			case 'woocommerce_subscriptions':
				$slug = 'WooCommerce Subscriptions';
				break;
			case 'woocommerce_membership':
				$slug = 'WooCommerce Membership';
				break;
			case 'woofunnels_upstroke':
				$slug = 'FunnelKit Upsells';
				break;
			case 'autonami_pro':
				$slug = 'FunnelKit Automations Pro';
				break;
			case 'autonami_connector':
				$slug = 'FunnelKit Automations Connectors';
				break;
			case 'affiliatewp':
				$slug = 'AffiliateWP';
				break;
		}

		return $slug;
	}

	/**
	 * Load Hooks after Action Scheduler is loaded
	 */
	public static function bwf_after_action_scheduler_load() {
		/** Schedule WP cron event */
		add_action( 'admin_init', array( __CLASS__, 'maybe_set_bwf_ct_worker' ) );

	}

	/**
	 * Register WP cron schedules
	 *
	 * @param $schedules
	 *
	 * @return array
	 */
	public static function add_schedules( $schedules ) {
		$schedules['fka_eight_hours'] = array(
			'interval' => 28800,
			'display'  => 'Every Eight hours',
		);

		/** Register bwf_every_minute schedule if not already registered */
		if ( ! isset( $schedules['bwf_every_minute'] ) ) {
			$schedules['bwf_every_minute'] = apply_filters( 'bwf_every_minute_cron', array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => 'Every minute',
			) );
		}

		/** Register every_minute schedule for Action Scheduler if not already registered */
		/** This ensures the schedule is available during cron rescheduling, even if Action Scheduler hasn't initialized yet */
		if ( ! isset( $schedules['every_minute'] ) ) {
			$schedules['every_minute'] = array(
				'interval' => 60,
				'display'  => 'Every minute',
			);
		}

		return $schedules;
	}

	/**
	 * Scheduling event with core callback
	 */
	public static function maybe_set_bwf_ct_worker() {
		/** Ensure schedules are registered before scheduling events */
		$schedules = wp_get_schedules();

		if ( ! wp_next_scheduled( 'bwf_as_run_queue' ) ) {
			/** Check if bwf_every_minute schedule exists before scheduling */
			if ( isset( $schedules['bwf_every_minute'] ) ) {
				wp_schedule_event( time(), 'bwf_every_minute', 'bwf_as_run_queue' );
			} else {
				/** Schedule not registered yet - log error for debugging */
				if ( function_exists( 'BWFAN_Core' ) && method_exists( BWFAN_Core(), 'logger' ) ) {
					BWFAN_Core()->logger->log( 'bwf_every_minute schedule not found when scheduling bwf_as_run_queue. Ensure woofunnels/as-data-store is loaded.', 'cron_schedule_error' );
				}
			}
		}

		if ( ! wp_next_scheduled( 'fka_clear_duplicate_actions' ) ) {
			if ( isset( $schedules['fka_eight_hours'] ) ) {
				wp_schedule_event( time(), 'fka_eight_hours', 'fka_clear_duplicate_actions' );
			}
		}

	}

	public static function hide_free_products_cart_order_items() {
		return apply_filters( 'bwfan_items_display_hide_free_products', false );
	}

	/**
	 * Product names of a stored cart's items, keyed by product (or variation) id.
	 *
	 * The stored items embed WC_Product objects; waking each one costs a product
	 * load (post, meta, terms) and lets the stored string pick the class. Read
	 * them as plain arrays and take the titles from one primed post query.
	 *
	 * @param string $items      The cart row's serialized items.
	 * @param bool   $skip_free  Leave out lines with no line_total.
	 *
	 * @return array
	 */
	public static function cart_item_names( $items, $skip_free = false ) {
		if ( ! is_string( $items ) || ! is_serialized( $items ) ) {
			return array();
		}

		$items = @unserialize( trim( $items ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- objects refused; corrupt rows read as empty, as maybe_unserialize() does
		if ( ! is_array( $items ) ) {
			return array();
		}

		$ids = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ( $skip_free && empty( $item['line_total'] ) ) ) {
				continue;
			}
			$id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : ( isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return array();
		}

		_prime_post_caches( $ids, false, false );

		$names = array();
		foreach ( $ids as $id ) {
			$title = get_post_field( 'post_title', $id, 'raw' );
			if ( '' !== (string) $title ) {
				$names[ $id ] = wp_strip_all_tags( $title );
			}
		}

		return $names;
	}

	/**
	 * Return if emogrifier library is supported.
	 *
	 * @return bool
	 * @since 3.5.0
	 */
	public static function supports_emogrifier() {
		return class_exists( 'DOMDocument' ) && version_compare( PHP_VERSION, '5.5', '>=' );
	}

	public static function color_light_or_dark( $color, $dark = '#000000', $light = '#FFFFFF' ) {
		return self::color_hex_is_light( $color ) ? $dark : $light;
	}

	public static function color_hex_is_light( $color ) {
		$hex = str_replace( '#', '', $color );

		$c_r = hexdec( substr( $hex, 0, 2 ) );
		$c_g = hexdec( substr( $hex, 2, 2 ) );
		$c_b = hexdec( substr( $hex, 4, 2 ) );

		$brightness = ( ( $c_r * 299 ) + ( $c_g * 587 ) + ( $c_b * 114 ) ) / 1000;

		return $brightness > 155;
	}

	public static function maybe_clear_cache() {

		/**
		 * Clear WordPress cache
		 */
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		/**
		 * Checking if wp fastest cache installed
		 * Clear cache of wp fastest cache
		 */
		if ( class_exists( 'WpFastestCache' ) ) {
			global $wp_fastest_cache;
			if ( method_exists( $wp_fastest_cache, 'deleteCache' ) ) {
				$wp_fastest_cache->deleteCache();
			}

			// clear all cache
			if ( function_exists( 'wpfc_clear_all_cache' ) ) {
				wpfc_clear_all_cache( true );
			}
		}

		/**
		 * Checking if wp Autoptimize installed
		 * Clear cache of Autoptimize
		 */
		if ( class_exists( 'autoptimizeCache' ) ) {
			autoptimizeCache::clearall();
		}

		/**
		 * Checking if W3Total Cache plugin activated.
		 * Clear cache of W3Total Cache plugin
		 */
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
	}

	/**
	 * Updating marketing_status in order meta
	 *
	 * @param $order
	 *
	 * @return void
	 */
	public static function bwfan_update_order_user_consent( $order ) {
		$marketing_status = isset( $_POST['bwfan_user_consent'] ) ? absint( $_POST['bwfan_user_consent'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification
		$order->update_meta_data( 'marketing_status', $marketing_status );
		$order->save_meta_data();
	}

	/**
	 * Whether the current user is logged in and their contact is subscribed (so we should not show the marketing consent option).
	 * Used to hide "Keep me up to date on news and exclusive offers" on both block and shortcode checkout.
	 *
	 * @return bool True if user is logged in and contact is subscribed.
	 */
	public static function is_logged_in_contact_subscribed() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( ! class_exists( 'WooFunnels_DB_Operations' ) ) {
			return false;
		}
		$user        = wp_get_current_user();
		$ins         = WooFunnels_DB_Operations::get_instance();
		$contact_row = $ins->get_contact_by_wpid( $user->ID );
		if ( is_null( $contact_row ) || ! property_exists( $contact_row, 'status' ) ) {
			return false;
		}
		$status = self::get_contact_status( $contact_row->status, isset( $contact_row->email ) ? $contact_row->email : '', isset( $contact_row->contact_no ) ? $contact_row->contact_no : '' );

		return ( 1 === absint( $status['status'] ) && 1 === absint( $status['email_status'] ) && 1 === absint( $status['sms_status'] ) );
	}

	/**
	 * Register marketing consent as an additional checkout field for Gutenberg/Block checkout.
	 * Uses WooCommerce Additional Checkout Fields API so the checkbox appears on block-based checkout.
	 * For logged-in subscribed users the field is removed by deregister_blocks_checkout_consent_field_if_subscribed at priority 5.
	 *
	 * @return void
	 */
	public static function register_blocks_checkout_consent_field() {
		if ( self::is_logged_in_contact_subscribed() ) {
			return;
		}
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$settings = self::get_global_settings();
		if ( empty( $settings['bwfan_user_consent'] ) ) {
			return;
		}
		$location = isset( $settings['bwfan_user_consent_position_blocks'] ) ? $settings['bwfan_user_consent_position_blocks'] : 'order';
		if ( ! in_array( $location, array( 'contact', 'order' ), true ) ) {
			$location = 'order';
		}
		$label = self::get_user_consent_message_in_site_language( $settings );
		$label = wp_strip_all_tags( $label );
		if ( '' === $label ) {
			$label = __( 'Keep me up to date on news and exclusive offers.', 'wp-marketing-automations' );
		}
		woocommerce_register_additional_checkout_field( array(
			'id'                => 'bwfan/marketing-consent',
			'label'             => $label,
			'location'          => $location,
			'type'              => 'checkbox',
			'required'          => false,
			'sanitize_callback' => array( __CLASS__, 'sanitize_marketing_consent_checkbox' ),
		) );
	}

	/**
	 * Sanitize marketing consent checkbox so boolean true from Store API JSON is stored as '1'.
	 *
	 * @param mixed $value Raw value from request.
	 * @param array $field Field definition (unused).
	 *
	 * @return string '1' or '0'.
	 */
	public static function sanitize_marketing_consent_checkbox( $value, $field = array() ) {
		$checked = ( true === $value || '1' === $value || 1 === $value || 'true' === $value );

		return $checked ? '1' : '0';
	}

	/**
	 * Ensures marketing consent from the Store API request is stored on the order.
	 * Reads from request params and raw JSON so consent is set before sync runs.
	 *
	 * @param WC_Order $order Order from block checkout.
	 * @param WP_REST_Request $request Store API request.
	 */
	public static function bwfan_blocks_checkout_consent_from_request( $order, $request ) {
		if ( ! $order instanceof WC_Order || ! $request instanceof WP_REST_Request ) {
			return;
		}
		$key        = 'bwfan/marketing-consent';
		$additional = (array) ( $request->get_param( 'additional_fields' ) ?? [] );

		if ( ! array_key_exists( $key, $additional ) && $request->get_body() ) {
			$body = json_decode( $request->get_body(), true );
			if ( is_array( $body ) && ! empty( $body['additional_fields'] ) ) {
				$additional = array_merge( $additional, (array) $body['additional_fields'] );
			}
		}
		$value = isset( $additional[ $key ] ) ? $additional[ $key ] : null;

		if ( empty( $value ) ) {
			return;
		}
		$order->update_meta_data( '_wc_other/bwfan/marketing-consent', '1' );
	}

	/**
	 * Sync marketing consent from Block checkout additional field to order meta (marketing_status).
	 * Runs when order is placed via Store API so contact subscription logic sees the same meta.
	 *
	 * @param WC_Order $order Order created from block checkout.
	 */
	public static function bwfan_sync_blocks_checkout_consent( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$marketing_status = 0;
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Package' ) && class_exists( 'Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' ) ) {
			$checkout_fields  = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
			$value            = $checkout_fields->get_field_from_object( 'bwfan/marketing-consent', $order, 'other' );
			$marketing_status = ( '1' === $value || true === $value || 1 === $value ) ? 1 : 0;
		}
		if ( 0 === $marketing_status && function_exists( 'bwf_get_contact' ) ) {
			$user_id = $order->get_user_id();
			if ( $user_id > 0 ) {
				$contact = bwf_get_contact( $user_id, $order->get_billing_email() );
				if ( $contact && 1 === intval( $contact->get_status() ) ) {
					$marketing_status = 1;
				}
			}
		}
		/** Remove the additional field from the order meta data */
		$order->delete_meta_data( '_wc_other/bwfan/marketing-consent' );

		$order->update_meta_data( 'marketing_status', $marketing_status );
		$order->save_meta_data();
	}

	/**
	 * After shortcode checkout order is processed: ensure contact is marked subscribed when consent was checked.
	 *
	 * @param int $order_id Order ID.
	 * @param array $posted_data Posted checkout data.
	 * @param WC_Order $order Order object.
	 */
	public static function bwfan_apply_checkout_consent_to_contact_by_order_id( $order_id, $posted_data, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( absint( $order_id ) );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! function_exists( 'bwf_get_contact' ) ) {
			return;
		}
		$contact = bwf_get_contact( $order->get_user_id(), $order->get_billing_email() );
		if ( empty( $contact ) || ! $contact->get_id() ) {
			return;
		}
		self::save_marketing_status_for_user( $contact, $order_id );
	}

	/** updating contact marketing status
	 *
	 * @param WooFunnels_Contact $contact
	 * @param int $order_id
	 */
	public static function save_marketing_status_for_user( $contact, $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$marketing_status = $order->get_meta( 'marketing_status' );
		if ( empty( $marketing_status ) ) {
			return;
		}

		/** Added filter to allow double optin for the contact */
		$is_single_optin = apply_filters( 'bwfcrm_contact_enable_single_optin', true, $contact, $order, $marketing_status );

		$settings = self::get_global_settings();

		/** If marketing consent setting is not enabled and single optin is disabled, then return. This is for the case when user consent is not enabled and single optin is disabled, then we don't need to mark the contact as subscribed */
		if ( ( empty( $settings['bwfan_user_consent'] ) && false === $is_single_optin ) ) {
			return;
		}

		/** Below code will mark the contact subscribe as we get user consent from the checkout */

		/** If unsubscribed rows found for the contact, then remove */
		$has_unsubscribed = self::maybe_delete_unsubscribe_rows( $contact );

		/** in case contact already subscribed and earlier not unsubscribe than return */
		if ( 1 === intval( $contact->get_status() ) && false === $has_unsubscribed ) {
			return;
		}

		/** Added filter to allow double optin and can still make the contact unverified in case of consent is checked */
		if ( false === $is_single_optin ) {
			$contact->set_status( 0 );
			$contact->save();

			return;
		}

		/** Manually set the prop to trigger the subscribe event run in case it was unsubscribed earlier */
		if ( true === $has_unsubscribed ) {
			$contact->is_subscribed = true;
		}

		$contact->set_status( 1 );
		$contact->save();
	}

	/**
	 * Save marketing status if order failed
	 *
	 * @param $order_id
	 * @param $order
	 *
	 * @return void
	 */
	public static function woocommerce_order_status_failed( $order_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$cid     = $order->get_meta( '_woofunnel_cid' );
		$contact = '';
		if ( 0 === intval( $cid ) ) {
			$contact = new WooFunnels_Contact( '', '', '', $cid );
		}

		/** Get contact object by email or user id */
		if ( empty( $contact ) || empty( $contact->get_id() ) ) {
			$contact = bwf_get_contact( $order->get_user_id(), $order->get_billing_email() );
		}

		if ( empty( $contact ) || empty( $contact->get_id() ) ) {
			return;
		}

		self::save_marketing_status_for_user( $contact, $order_id );
	}

	public static function get_form_submit_events() {
		return apply_filters( 'bwfan_get_form_submit_events', array( 'BWFAN_CF7_Form_Submit' ) );
	}

	/**
	 * Get automations by title for different versions
	 *
	 * @param $search
	 * @param $version
	 * @param $ids
	 * @param $limit
	 * @param $offset
	 *
	 * @return array|object|stdClass[]|null
	 */
	public static function get_automation_by_title( $search, $version = 0, $ids = [], $limit = 10, $offset = 0 ) {
		global $wpdb;

		/** Search all automation from v1 and v2 */
		if ( 0 === absint( $version ) ) {
			return self::search_automation_by_title( $search, $limit, $offset );
		}

		$query    = '';
		$id_query = '';

		if ( ! empty( $ids ) ) {
			$id_query = " AND ID IN(" . implode( ',', array_map( 'absint', $ids ) ) . ") ";
		}

		$where = $wpdb->prepare( "AND v = %d ", $version );
		switch ( absint( $version ) ) {
			case  2 :
				if ( ! empty( $search ) ) {
					$where .= $wpdb->prepare( " AND title LIKE %s ", '%' . $search . '%' );
				}
				$query = "SELECT ID as id, title  FROM {$wpdb->prefix}bwfan_automations WHERE 1=1 $where $id_query ORDER BY title ASC";
				break;
			case  1 :
				if ( ! empty( $search ) ) {
					$where .= $wpdb->prepare( " AND am.meta_value LIKE %s AND am.meta_key = 'title'", '%' . $search . '%' );
				}
				$query = "SELECT am.bwfan_automation_id AS id,am.meta_value AS title FROM {$wpdb->prefix}bwfan_automationmeta AS am WHERE 1=1 $where $id_query GROUP BY am.bwfan_automation_id ORDER BY am.meta_value ASC";
				break;
		}

		if ( ! empty( $limit ) ) {
			$query .= $wpdb->prepare( " LIMIT %d, %d", $offset, $limit );
		}

		return ! empty( $query ) ? $wpdb->get_results( $query, ARRAY_A ) : []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- ids absint()ed; other values bound via prepare()
	}

	/**
	 * Get automations by title
	 *
	 * @param $search
	 * @param $limit
	 * @param $offset
	 *
	 * @return array|object|stdClass[]|null
	 */
	public static function search_automation_by_title( $search = '', $limit = 10, $offset = 0 ) {
		global $wpdb;
		$where = '';
		/** Search automations by title form automation table */
		if ( ! empty( $search ) ) {
			$where = $wpdb->prepare( " WHERE title LIKE %s ", '%' . $search . '%' );
		}
		$query = "SELECT ID as id, title FROM {$wpdb->prefix}bwfan_automations $where ORDER BY title ASC";
		if ( ! empty( $limit ) ) {
			$query .= $wpdb->prepare( " LIMIT %d, %d", $offset, $limit );
		}
		$automations = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- query built from $wpdb->prepare() fragments

		/** Search automations by title form automation meta table */
		if ( ! empty( $search ) ) {
			$where = $wpdb->prepare( " WHERE am.meta_value LIKE %s AND am.meta_key = 'title'", '%' . $search . '%' );
		}
		$meta_query = "SELECT am.bwfan_automation_id AS id,am.meta_value AS title FROM {$wpdb->prefix}bwfan_automationmeta AS am $where GROUP BY am.bwfan_automation_id ORDER BY am.meta_value ASC";
		if ( ! empty( $limit ) ) {
			$meta_query .= $wpdb->prepare( " LIMIT %d, %d", $offset, $limit );
		}

		$automation_meta = $wpdb->get_results( $meta_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- query built from $wpdb->prepare() fragments

		return array_merge( $automations, $automation_meta );
	}

	/**
	 * @param $search
	 * @param $offset
	 * @param $limit
	 */
	public static function get_unsubscribers( $search = '', $offset = 0, $limit = 25 ) {
		global $wpdb;
		$where = '';
		$args  = [];
		/** Check for search unsubscriber */
		if ( ! empty( $search ) ) {
			$where             = $wpdb->prepare( " WHERE `recipient` LIKE %s ", '%' . $search . '%' );
			$args['recipient'] = array(
				'operator' => '%s',
				'value'    => "%" . $search . "%",
			);
		}

		/** Query to fetch unsubscribers data from DB */
		$unsubscribers = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bwfan_message_unsubscribe $where ORDER BY ID DESC LIMIT %d, %d ", $offset, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where built with $wpdb->prepare() before interpolation
		if ( empty( $unsubscribers ) ) {
			return array();
		}

		$found_posts = array();
		$items       = array();

		foreach ( $unsubscribers as $unsubscriber ) {
			$c_type = absint( $unsubscriber->c_type );
			$oid    = empty( $unsubscriber->automation_id ) ? 0 : absint( $unsubscriber->automation_id );
			$otype  = class_exists( 'BWFCRM_Contact' ) ? BWFCRM_Contact::get_unsubscribe_source_label( $c_type ) : __( 'Manual', 'wp-marketing-automations' );
			$oname  = '';

			if ( 1 === $c_type ) {
				$automation_meta = BWFAN_Core()->automations->get_automation_data_meta( $oid );
				$oname           = isset( $automation_meta['title'] ) && ! empty( $automation_meta['title'] ) ? $automation_meta['title'] : __( 'No Title', 'wp-marketing-automations' );
			} elseif ( 2 === $c_type ) {
				$broadcast = bwfan_is_autonami_pro_active() ? BWFAN_Model_Broadcast::get( $oid ) : array();
				$oname     = isset( $broadcast['title'] ) ? $broadcast['title'] : __( 'No Title', 'wp-marketing-automations' );
			}

			$items[] = array(
				'id'              => $unsubscriber->ID,
				'recipient'       => $unsubscriber->recipient,
				'date'            => date( self::get_date_format(), strtotime( $unsubscriber->c_date ) ),// phpcs:ignore WordPress.DateTime.RestrictedFunctions
				'automation_id'   => $oid,
				'automation_name' => empty( $oname ) && empty( $otype ) ? '-' : ( empty( $oname ) ? $otype : "$otype: $oname" ),
				'source_type'     => $c_type,
			);
		}

		$count = BWFAN_Model_Message_Unsubscribe::count( $args );

		$found_posts['found_posts'] = empty( $count ) ? 0 : absint( $count );
		$found_posts['items']       = $items;

		return $found_posts;
	}

	public static function get_date_format() {
		return self::bwfan_get_date_format() . ' ' . self::bwfan_get_time_format();
	}

	/** run global tools
	 *
	 * @param $tool_type
	 * @param $data
	 *
	 * @return array
	 */
	public static function run_global_tools( $tool_type, $data = [] ) {
		global $wpdb;
		$result = array();

		switch ( $tool_type ) {
			case 'run_all_tasks':
				$result['msg'] = __( 'All queued tasks have been scheduled to run now', 'wp-marketing-automations' );
				$all_tasks     = BWFAN_Core()->tasks->get_all_tasks();
				if ( ! is_array( $all_tasks ) || 0 === count( $all_tasks ) ) {
					$result['msg']    = __( 'There are no tasks.', 'wp-marketing-automations' );
					$result['status'] = false;

					return $result;
				}

				$task_ids = array();
				foreach ( $all_tasks as $task_id => $task_details ) {  //phpcs:ignore WordPressVIPMinimum.Variables.VariableAnalysis
					$task_ids[] = $task_id;
				}
				BWFAN_Core()->tasks->rescheduled_tasks( true, $task_ids );

				break;
			case 'delete_completed_tasks':
				//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$logs = $wpdb->get_results( $wpdb->prepare( "
									SELECT ID
									FROM {$wpdb->prefix}bwfan_logs
									WHERE `status` = %d
									", 1 ) );

				if ( ! empty( $logs ) ) {
					$completed_tasks = array();
					foreach ( $logs as $log ) {
						$completed_tasks[] = $log->ID;
					}
					BWFAN_Core()->logs->delete_logs( $completed_tasks );
				}

				$result['msg'] = __( 'All completed tasks successfully deleted.', 'wp-marketing-automations' );

				break;
			case 'delete_failed_tasks':
				//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$logs = $wpdb->get_results( $wpdb->prepare( "
									SELECT ID
									FROM {$wpdb->prefix}bwfan_logs
									WHERE `status` = %d
									", 0 ) );

				if ( ! empty( $logs ) ) {
					$failed_tasks = array();
					foreach ( $logs as $log ) {
						$failed_tasks[] = $log->ID;
					}
					BWFAN_Core()->logs->delete_logs( $failed_tasks );
				}

				$result['msg'] = __( 'All failed tasks successfully deleted.', 'wp-marketing-automations' );

				break;
			case 'delete_previous_logs_automation':
				if ( false !== bwf_has_action_scheduled( 'bwfan_delete_older_logs' ) ) {
					$result['msg']    = __( 'A process is already scheduled for deleting old logs of this automation', 'wp-marketing-automations' );
					$result['status'] = false;

					return $result;
				}

				$data_inputs           = isset( $_POST['data_inputs'] ) ? json_decode( self::remove_backslashes( $_POST['data_inputs'] ), true ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
				$automation_id         = $data_inputs['automation_id'];
				$result['msg']         = __( 'Process scheduled for deleting old logs of this automation', 'wp-marketing-automations' );
				$data                  = array();
				$data['days']          = apply_filters( 'bwfan_logs_days_deletion_limit', 15 );
				$data['limit']         = apply_filters( 'bwfan_logs_days_deletion_count', 200 );
				$data['automation_id'] = intval( $automation_id );

				bwf_schedule_single_action( time(), 'bwfan_delete_older_logs', $data );

				break;
			case 'delete_expired_coupons':
				if ( false !== bwf_has_action_scheduled( 'bwfan_delete_expired_coupons' ) ) {
					$result['msg']    = __( 'A process is already scheduled for deleting expired Funnelkit Automation generated coupons', 'wp-marketing-automations' );
					$result['status'] = false;

					return $result;
				}

				$result['msg'] = __( 'Process scheduled for deleting expired Funnelkit Automation generated coupons', 'wp-marketing-automations' );

				bwf_schedule_recurring_action( time(), MINUTE_IN_SECONDS * 2, 'bwfan_delete_expired_coupons', array() );

				break;
			case 'delete_lost_carts':
				//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "
									DELETE
									FROM {$wpdb->prefix}bwfan_abandonedcarts
									WHERE `status` = %d
									", 2 ) );

				$result['msg'] = __( 'All lost carts successfully deleted.', 'wp-marketing-automations' );

				break;
			case 'delete_engagement_tracking_meta':
				if ( false !== bwf_has_action_scheduled( 'bwfan_delete_engagement_tracking_meta_tool_action' ) ) {
					$result['msg']    = __( 'A process is already scheduled to optimize the Engagement Tracking meta table', 'wp-marketing-automations' );
					$result['status'] = false;

					return $result;
				}

				global $wpdb;

				$query  = $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}bwfan_engagement_trackingmeta WHERE `meta_key` = %s LIMIT 1", 'merge_tags' );
				$row_id = $wpdb->get_var( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

				if ( empty( $row_id ) ) {
					$result['msg'] = __( 'No optimization is needed for the Engagement Tracking meta table', 'wp-marketing-automations' );

					return $result;
				}

				bwf_schedule_recurring_action( time(), MINUTE_IN_SECONDS * 5, 'bwfan_delete_engagement_tracking_meta_tool_action' );

				$result['msg'] = __( 'Process scheduled for Engagement Tracking meta table', 'wp-marketing-automations' );

				break;
			case 're_index_cart_orders_conversion':
				if ( false !== bwf_has_action_scheduled( 'bwfan_reindex_cart_conversions_base_total' ) ) {
					$result['msg']    = __( 'A process is already scheduled for re-indexing cart & conversion value', 'wp-marketing-automations' );
					$result['status'] = false;

					return $result;
				}

				$result['msg'] = __( 'Process scheduled for re-indexing cart & conversion value', 'wp-marketing-automations' );

				bwf_options_update( 're_index_data_type', 'conversion' );
				bwf_options_update( 'last_index_id', 0 );
				bwf_schedule_recurring_action( time(), 60, 'bwfan_reindex_cart_conversions_base_total' );

				break;
			case 'bwfan_validate_db_tables':
				$missing_tables = BWFAN_Table_Validation_Controller::check_missing_tables();
				if ( isset( $missing_tables['error'] ) ) {
					$result['msg'] = $missing_tables['error'];
					break;
				}

				if ( empty( $missing_tables ) ) {
					$result['msg'] = __( 'All required tables are present in the database.', 'wp-marketing-automations' );
					bwf_options_update( 'bwfan_table_validation_error', 0 );
					break;
				}
				$table_created = BWFAN_Table_Validation_Controller::create_missing_tables( $missing_tables );
				$result['msg'] = $table_created === true ? __( 'The following tables were created: ', 'wp-marketing-automations' ) . implode( ', ', $missing_tables ) : __( 'Error while creating table', 'wp-marketing-automations' );
				break;
			case 'bwfan_check_collation':
				$existing_tables = BWFAN_Table_Validation_Controller::get_existing_tables();
				if ( isset( $existing_tables['error'] ) ) {
					$result['msg'] = $existing_tables['error'];
					break;
				}

				$verified      = BWFAN_Table_Validation_Controller::check_collation( $existing_tables );
				$result['msg'] = ( $verified === true ) ? __( 'Table collations and primary keys verified', 'wp-marketing-automations' ) : __( 'Error while verifying table collations and primary keys', 'wp-marketing-automations' );
				break;
			case 'toggle_usage_tracking':
				if ( isset( $data['value'] ) && true === $data['value'] ) {
					WooFunnels_optIn_Manager::Allow_optin( true, 'FKA' );
					bwf_options_update( 'bwf_usage_tracking_opted_on', current_time( 'timestamp' ) );
				} else {
					WooFunnels_optIn_Manager::reset_optin();
				}

				$result['msg']    = __( 'Status Updated', 'wp-marketing-automations' );
				$result['status'] = true;
				break;
			default:
				$result = apply_filters( 'bwfan_run_global_tool_' . $tool_type, $result, $data );
				break;
		}

		return $result;
	}

	/**
	 * Get setting schema
	 *
	 * @return mixed|null
	 * @throws Exception
	 */
	public static function get_setting_schema() {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$editable_roles = get_editable_roles();
		$user_roles     = array();
		if ( $editable_roles ) {
			foreach ( $editable_roles as $role => $details ) {
				$name                         = translate_user_role( $details['name'] );
				$user_roles[ $role ]['label'] = $name;
				$user_roles[ $role ]['value'] = $role;
			}
		}
		$user_roles = array_values( $user_roles );

		/** SMS Service Providers */
		$sms_options         = self::get_sms_services();
		$is_twilio_connected = ( is_array( $sms_options ) && isset( $sms_options['bwfco_twilio'] ) );
		$sms_options         = array_map( function ( $sms, $slug ) {
			return array(
				'label' => $sms,
				'value' => $slug,
			);
		}, $sms_options, array_keys( $sms_options ) );

		/** Email Service Providers */
		$email_options = self::get_email_services();
		$email_options = array_map( function ( $email, $slug ) {
			return array(
				'label' => $email,
				'value' => $slug,
			);
		}, $email_options, array_keys( $email_options ) );

		$bounce_settings_schema = self::get_bounce_settings_schema();

		$show_fields = false;

		if ( function_exists( 'bwfan_is_autonami_pro_active' ) && bwfan_is_autonami_pro_active() ) {
			$show_fields = true;
		}

		$bounce_settings_schema = array_merge( $bounce_settings_schema, array(
			array(
				'id'          => 'bwfan_email_per_second_limit',
				'type'        => 'textwithaffix',
				'inner_type'  => 'number',
				'label'       => __( 'Max Sending Limit', 'wp-marketing-automations' ),
				'suffix'      => __( 'Per Sec', 'wp-marketing-automations' ),
				'hint'        => __( 'Enter maximum email sending limit. Note: This is an indicative limit and not the actual sending rate for Funnelkit Automation. ', 'wp-marketing-automations' ) . '<strong>' . __( 'The real sending rate would vary based on site configuration, plugin used and even SMTP API connection.', 'wp-marketing-automations' ) . '</strong>',
				'required'    => false,
				"min"         => '0',
				'placeholder' => '15',
				'show'        => $show_fields,
				'toggler'     => array(),
			),
			array(
				'id'         => 'bwfan_email_daily_limit',
				'type'       => 'textwithaffix',
				'inner_type' => 'number',
				'label'      => __( 'Max Sending Limit', 'wp-marketing-automations' ),
				'suffix'     => __( 'Per Day', 'wp-marketing-automations' ),
				'hint'       => __( 'Set the maximum emails allowed per 24 hours. Once this limit is reached, sending pauses and resumes after the quota resets. This ensures manageable daily output and compliance with email provider policies.', 'wp-marketing-automations' ),
				'required'   => false,
				'show'       => $show_fields,
				'toggler'    => array(),
			),
		) );

		$email_field = array(
			array(
				'id'          => 'bwfan_email_from_name',
				'label'       => __( 'From Name', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_email_from_name',
				'placeholder' => __( 'Enter Name', 'wp-marketing-automations' ),
				'hint'        => __( 'Name that will be used to send emails', 'wp-marketing-automations' ),
				'required'    => false,
				'toggler'     => array(),
			),
			array(
				'id'          => 'bwfan_email_from',
				'label'       => __( 'From Email', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_email_from',
				'placeholder' => __( 'Enter Email', 'wp-marketing-automations' ),
				'hint'        => __( 'Valid email address that will be used to send emails', 'wp-marketing-automations' ),
				'required'    => false,
				'toggler'     => array(),
			),
			array(
				'id'          => 'bwfan_email_reply_to',
				'label'       => __( 'Reply To Email', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_email_reply_to',
				'placeholder' => __( 'Enter Email', 'wp-marketing-automations' ),
				'hint'        => __( 'Valid email address that will be used to receive replies', 'wp-marketing-automations' ),
				'required'    => false,
				'toggler'     => array(),
			),
			array(
				'id'          => 'bwfan_email_footer_setting',
				'label'       => __( 'Footer Text', 'wp-marketing-automations' ),
				'type'        => 'richeditor',
				'class'       => 'bwfan_setting_business_name',
				'required'    => false,
				'wrap_before' => '<h3>' . __( 'Footer', 'wp-marketing-automations' ) . '</h3>',
				'toggler'     => array(),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment, WordPress.WP.I18n.UnorderedPlaceholdersText
				'hint'        => __( 'Anti-spam laws require you to put a physical address and an unsubscribe link at the bottom of every email.', 'wp-marketing-automations' ),
			),
			array(
				'id'      => 'bwfan-email_footer_shortcode_text',
				'type'    => 'shortcodelist',
				'class'   => 'bwfan-profile-page-text bwf-mt-12-i',
				'label'   => '',
				'heading' => __( 'Use dynamic shortcodes in the email footer', 'wp-marketing-automations' ),
				'data'    => [
					[
						'label' => __( 'Business Name', 'wp-marketing-automations' ),
						'value' => '{{business_name}}',
					],
					[
						'label' => __( 'Business Address', 'wp-marketing-automations' ),
						'value' => '{{business_address}}',
					],
					[
						'label' => __( 'Unsubscribe Link', 'wp-marketing-automations' ),
						'value' => '{{unsubscribe_link}}',
					],
					[
						'label' => __( 'Profile Link', 'wp-marketing-automations' ),
						'value' => '{{profile_link}}',
					],

				],
			),
		);

		if ( ! empty( $bounce_settings_schema ) ) {
			$email_field = array_merge( $email_field, $bounce_settings_schema );
		}

		/** added email provider setting in case of multiple provider */
		if ( count( $email_options ) > 1 ) {
			$email_provider_schema = array(
				array(
					'id'       => 'bwfan_email_service',
					'label'    => __( 'Default Email Provider', 'wp-marketing-automations' ),
					'type'     => 'select',
					'class'    => '',
					'options'  => $email_options,
					'required' => false,
					'multiple' => false,
					'hint'     => __( 'Select default provider for sending emails', 'wp-marketing-automations' ),
					'toggler'  => array(),
				),
			);
			$email_field           = array_merge( $email_field, $email_provider_schema );
		}

		$twilio_sms_unsubscribe_settings = [];
		$twilio_sms_sort_url             = [];
		$unsubscribe_text                = [];
		$messaging_service_sid           = [];

		/** getting the twilio sms webhook link for unsubscribes in case connector and class exists */
		if ( $is_twilio_connected ) {
			if ( bwfan_is_autonami_connector_active() && class_exists( 'BWFAN_Twilio_Webhook_Setup' ) && method_exists( 'BWFAN_Twilio_Webhook_Setup', 'get_webhooks' ) ) {
				$twilio_sms_unsub_instance       = BWFAN_Twilio_Webhook_Setup::get_instance();
				$twilio_webhook                  = $twilio_sms_unsub_instance->get_webhooks();
				$webhook_link                    = ! empty( $twilio_webhook['link'] ) ? $twilio_webhook['link'] : '';
				$twilio_sms_unsubscribe_settings = array(
					'id'          => 'bwfan_sms_webhook_twilio',
					'label'       => __( 'Webhook URL', 'wp-marketing-automations' ),
					'type'        => 'copier',
					'class'       => 'bwfan_sms_webhook_twilio',
					'hint'        => __( "Paste this URL into your Twilio's settings to send requests. <a href='https://funnelkit.com/docs/autonami-2/sms-broadcasts/unsubscribe-text/' target='_blank'>Learn more</a> on how to set up.", 'wp-marketing-automations' ),
					'required'    => false,
					'copy_text'   => $webhook_link,
					'wrap_before' => '<h3>' . __( 'Twilio Settings', 'wp-marketing-automations' ) . '</h3>',
				);
				if ( true === apply_filters( 'bwfan_enable_twilio_advanced_settings', false ) ) {
					$twilio_sms_sort_url   = array(
						'id'            => 'bwfan_twilio_shorten_url',
						'label'         => __( 'Enable URL Shortening', 'wp-marketing-automations' ),
						'type'          => 'checkbox',
						'checkboxlabel' => __( "Twilio's native URL shortening", 'wp-marketing-automations' ),
						'hint'          => __( "Enable URL shortening by Twilio. <a href='https://www.twilio.com/docs/messaging/features/link-shortening' target='_blank'>Click here</a> to understand more.", "wp-marketing-automations" ),
						'required'      => false,
					);
					$messaging_service_sid = array(
						'id'       => 'bwfan_twilio_messaging_service_sid',
						'label'    => __( 'Messaging Service ID', 'wp-marketing-automations' ),
						'type'     => 'text',
						'hint'     => __( "Messaging Service ID is required for shortening.", 'wp-marketing-automations' ),
						'required' => false,
						'toggler'  => array(
							'fields'   => array(
								array(
									'id'    => 'bwfan_twilio_shorten_url',
									'value' => true,
								),
							),
							'relation' => 'OR',
						),
					);
				}
			}
			$unsubscribe_text = array(
				'id'           => 'bwfan_sms_unsubscribe_text',
				'label'        => __( 'Unsubscribe Text', 'wp-marketing-automations' ),
				'type'         => 'text',
				'class'        => 'bwfan_sms_unsubscribe_text',
				'placeholder'  => __( 'Enter Unsubscribe Text', 'wp-marketing-automations' ),
				'required'     => true,
				'disabled'     => method_exists( 'BWFCRM_Common', 'get_sms_provider_slug' ) && ! empty( BWFCRM_Common::get_sms_provider_slug() ) ? false : true,
				'isProSetting' => true,
			);
		}
		$sms_fields = array(
			$twilio_sms_unsubscribe_settings,
			$twilio_sms_sort_url,
			$unsubscribe_text,
			$messaging_service_sid,
			array(
				'id'            => 'bwfan_disable_sms_tracking',
				'label'         => __( 'Disable Click Tracking', 'wp-marketing-automations' ),
				'type'          => 'checkbox',
				'checkboxlabel' => __( 'Disable Click Tracking for all SMS Broadcasts and Automations', 'wp-marketing-automations' ),
				'hint'          => __( 'Click tracking adds special variables to the URL that increases the SMS character limit. Enable this setting to reduce the character limit. Note: Funnelkit Automation cannot track conversion rate if click tracking is disabled.', 'wp-marketing-automations' ),
				'class'         => 'bwfan_disable_sms_tracking',
				'required'      => false,
			),
		);

		/** added email provider setting in case of multiple provider */
		if ( count( $sms_options ) > 1 ) {
			$sms_provider_schema = array(
				array(
					'id'       => 'bwfan_sms_service',
					'label'    => __( 'Default SMS Provider', 'wp-marketing-automations' ),
					'type'     => 'select',
					'class'    => '',
					'options'  => $sms_options,
					'required' => false,
					'multiple' => false,
					'hint'     => __( 'Select default provider for sending SMS', 'wp-marketing-automations' ),
					'toggler'  => array(),
				),
			);
			$sms_fields          = array_merge( $sms_fields, $sms_provider_schema );
		}

		$general_fields = [];

		if ( is_multisite() ) {
			/**
			 * Check if sitewide installed, if yes then get the plugin info from primary site
			 */
			$active_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $active_plugins ) && ( in_array( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ), true ) || array_key_exists( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ) ) ) && ! is_main_site() ) {
				$main_site_id        = 1; // Main site ID in Multisite
				$main_site_admin_url = get_site_url( $main_site_id, 'wp-admin/admin.php?page=autonami&path=/settings' );
				$general_fields[]    = array(
					'id'          => 'no_license',
					'type'        => 'multisitenotice',
					'linkButton'  => esc_url( $main_site_admin_url ),
					'label'       => __( 'FunnelKit Automations Pro', 'wp-marketing-automations' ),
					'msg'         => __( 'Manage your FunnelKit Automations Pro license centrally from the main site of your multisite network. Simply head there to manage. ', 'wp-marketing-automations' ),
					'wrap_before' => '<h3 style="margin-bottom: 0;">' . __( 'License', 'wp-marketing-automations' ) . '</h3>',
					'hint'        => '',
				);
			} else {
				$general_fields[] = array(
					'id'          => 'autonami_pro',
					'label'       => __( 'FunnelKit Automations Pro', 'wp-marketing-automations' ),
					'type'        => 'license',
					'license'     => self::get_pro_license( false ),
					'wrap_before' => '<h3 style="margin-bottom: 0;">' . __( 'License', 'wp-marketing-automations' ) . '</h3>',
					'toggler'     => array(),
				);
				if ( true === bwfan_is_autonami_pro_active() && true === bwfan_is_autonami_connector_active() ) {
					$general_fields[] = array(
						'id'               => 'autonami_connector',
						'label'            => __( 'FunnelKit Automations Connectors', 'wp-marketing-automations' ),
						'type'             => 'license',
						'isConnectorField' => true,
						'license'          => self::get_connector_license( false ),
						'toggler'          => array(),
					);
				}
			}
		} else {
			$general_fields[] = array(
				'id'          => 'autonami_pro',
				'label'       => __( 'FunnelKit Automations Pro', 'wp-marketing-automations' ),
				'type'        => 'license',
				'license'     => self::get_pro_license( false ),
				'wrap_before' => '<h3 style="margin-bottom: 0;">' . __( 'License', 'wp-marketing-automations' ) . '</h3>',
				'toggler'     => array(),
			);
			if ( true === bwfan_is_autonami_pro_active() && true === bwfan_is_autonami_connector_active() ) {
				$general_fields[] = array(
					'id'               => 'autonami_connector',
					'label'            => __( 'FunnelKit Automations Connectors', 'wp-marketing-automations' ),
					'type'             => 'license',
					'isConnectorField' => true,
					'license'          => self::get_connector_license( false ),
					'toggler'          => array(),
				);
			}
		}

		$general_fields[] = array(
			'id'          => 'bwfan_setting_business_name',
			'label'       => __( 'Business Name', 'wp-marketing-automations' ),
			'type'        => 'text',
			'class'       => 'bwfan_setting_business_name bwf-mt-16-i',
			'placeholder' => __( 'Enter Business Name', 'wp-marketing-automations' ),
			'wrap_before' => '<h3>' . __( 'Business Details', 'wp-marketing-automations' ) . '</h3><p>' . __( 'Anti-spam laws require you to put a physical address at the bottom of every email where you can be reached.', 'wp-marketing-automations' ) . '</p>',
			'hint'        => '',
			'required'    => true,
			'toggler'     => array(),
		);
		$general_fields[] = array(
			'id'          => 'bwfan_setting_business_address',
			'label'       => __( 'Business Address', 'wp-marketing-automations' ),
			'type'        => 'text',
			'class'       => 'bwfan_setting_business_address',
			'placeholder' => __( 'Enter Business Address', 'wp-marketing-automations' ),
			'hint'        => '',
			'required'    => true,
			'toggler'     => array(),
		);

		$general_fields[] = array(
			'id'          => 'bwfan_setting_business_logo',
			'label'       => __( 'Brand logo', 'wp-marketing-automations' ),
			'type'        => 'image_upload',
			'class'       => 'bwf-w-620 bwf-mt-16-i',
			'hint'        => '',
			'required'    => false,
			'wrap_before' => '<h3>' . __( 'Brand Styles', 'wp-marketing-automations' ) . '</h3><p>' . __( 'You can set the brand styles of Subscribe and Contact Profile Pages.', 'wp-marketing-automations' ) . '</p>',
			'toggler'     => array(),
		);

		$general_fields[] = array(
			'id'           => 'bwfan_setting_business_color',
			'label'        => __( 'Brand Color', 'wp-marketing-automations' ),
			'type'         => 'color_picker',
			'class'        => 'bwf-w-620',
			'hint'         => '',
			'defaultColor' => '#000000',
			'required'     => false,
			'toggler'      => array(),
		);

		$checkout_consent_fields         = [];
		$ab_email_consent_message_fields = [];
		$checkout_consent_fields[]       = array(
			'id'       => 'bwfan_user_consent_message',
			'label'    => __( 'Text', 'wp-marketing-automations' ),
			'type'     => 'textarea',
			'class'    => '',
			'required' => true,
			'toggler'  => array(
				'fields'   => array(
					array(
						'id'    => 'bwfan_user_consent',
						'value' => true,
					),
				),
				'relation' => 'OR',
			),
		);

		$ab_email_consent_message_fields[] = array(
			'id'       => 'bwfan_ab_email_consent_message',
			'type'     => 'textarea',
			'label'    => 'Text',
			'class'    => 'bwfan_ab_email_consent_message',
			'required' => true,
			'hint'     => __( "Use merge tag {{no_thanks label='No Thanks'}} to let users opt out of cart tracking.", 'wp-marketing-automations' ),
			'toggler'  => array(
				'fields'   => array(
					array(
						'id'    => 'bwfan_ab_enable',
						'value' => true,
					),
					array(
						'id'    => 'bwfan_ab_email_consent',
						'value' => true,
					),
				),
				'relation' => 'AND',
			),
		);
		if ( bwfan_is_autonami_pro_active() && method_exists( 'BWFAN_PRO_Common', 'get_language_settings' ) ) {
			$language_data = BWFAN_PRO_Common::get_language_settings();
			if ( is_array( $language_data ) && isset( $language_data['lang_options'] ) && ! empty( $language_data['lang_options'] ) ) {
				$default_language = strval( get_locale() );

				/** Change default language field label */
				$checkout_consent_fields[0]['label'] = __( 'Text (Default)', 'wp-marketing-automations' );

				foreach ( $language_data['lang_options'] as $lang_slug => $lang_name ) {
					if ( $default_language === strval( $lang_slug ) ) {
						continue;
					}
					$checkout_consent_fields[] = array(
						'id'       => 'bwfan_user_consent_message_' . $lang_slug,
						'label'    => sprintf( __( 'Text (%s)', 'wp-marketing-automations' ), $lang_name ),  // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
						'type'     => 'textarea',
						'class'    => '',
						'required' => false,
						'toggler'  => array(
							'fields'   => array(
								array(
									'id'    => 'bwfan_user_consent',
									'value' => true,
								),
							),
							'relation' => 'OR',
						),
					);

					$ab_email_consent_message_fields[] = array(
						'id'       => 'bwfan_ab_email_consent_message_' . $lang_slug,
						'type'     => 'textarea',
						'class'    => 'bwfan_ab_email_consent_message',
						'required' => false,
						'hint'     => '( ' . $lang_name . ' ) ' . __( "Use merge tag {{no_thanks label='No Thanks'}} to let users opt out of cart tracking.", 'wp-marketing-automations' ),
						'toggler'  => array(
							'fields'   => array(
								array(
									'id'    => 'bwfan_ab_enable',
									'value' => true,
								),
								array(
									'id'    => 'bwfan_ab_email_consent',
									'value' => true,
								),
							),
							'relation' => 'AND',
						),
					);
				}
			}
		}

		$engagement_meta_record_delete = [];
		if ( bwfan_is_autonami_pro_active() && method_exists( 'BWFAN_PRO_Common', 'delete_engagement_tracking_meta' ) ) {
			$engagement_meta_record_delete = [
				array(
					'id'          => 'debug_notice',
					'type'        => 'notice',
					'class'       => '',
					'status'      => 'warning',
					'message'     => __( 'It is recommend to enable this setting specifically if you are a high volume sender. This setting optimises the database table size by removing personalised merge tag data after certain days.', 'wp-marketing-automations' ),
					'dismiss'     => false,
					'required'    => false,
					'isHtml'      => true,
					'wrap_before' => '<br/><h3>' . __( 'Auto Delete Personalized Merge Tags', 'wp-marketing-automations' ) . '</h3>',
					'toggler'     => array(),
				),
				array(
					'id'            => 'bwfan_delete_engagement_tracking_meta_enable',
					'label'         => __( 'Auto Delete Merge Tags', 'wp-marketing-automations' ),
					'type'          => 'checkbox',
					'checkboxlabel' => __( 'Enable', 'wp-marketing-automations' ),
					'hint'          => __( 'Automatically remove dynamic personalized merge tags from emails [X] days after they have been sent.', 'wp-marketing-automations' ),
					'class'         => '',
					'required'      => false,
					'isWooField'    => true,
					'isProField'    => true,
					'toggler'       => array(),
				),
				array(
					'id'         => 'bwfan_delete_engagement_tracking_meta',
					'type'       => 'textwithaffix',
					'inner_type' => 'number',
					'suffix'     => __( 'Days', 'wp-marketing-automations' ),
					'isWooField' => true,
					'isProField' => true,
					'required'   => false,
					'toggler'    => array(
						'fields' => array(
							array(
								'id'    => 'bwfan_delete_engagement_tracking_meta_enable',
								'value' => true,
							),
						),
					),
				),
			];
		}

		$twilio_webhook_log_data = [];
		if ( class_exists( 'BWFCO_Twilio' ) && BWFAN_Core()->connectors->is_connected( 'bwfco_twilio' ) ) {
			$twilio_webhook_log_data = array(
				'id'            => 'bwfan_twilio_webhook_received_logging',
				'type'          => 'checkbox',
				'label'         => ' ',
				'checkboxlabel' => __( 'Enable Logs for Twilio SMS Received event automation', 'wp-marketing-automations' ),
				'hint'          => __( "It logs the received data on a 'Twilio SMS Received' automation. Logs are captured in file named <i>fka-twilio-sms-webhooks-xxx</i>", 'wp-marketing-automations' ),
				'class'         => 'bwfan_make_logs',
				'required'      => false,
				'wrap_before'   => '',
				'isProField'    => true,
				'toggler'       => array(
					'fields' => array(
						array(
							'id'    => 'bwfan_advance_logs',
							'value' => true,
						),
					),
				),
			);
		}

		$short_url_log_data = [];

		if ( ! empty( self::get_shortener_services() ) ) {
			$short_url_log_data = array(
				'id'            => 'bwfan_short_url_response_log',
				'type'          => 'checkbox',
				'label'         => ' ',
				'checkboxlabel' => __( 'Enable Logs for Shortener URL Service', 'wp-marketing-automations' ),
				'hint'          => __( "It logs the received data on a 'URL Shortening' operation. Logs are captured in a file named <i>fka-short-url-response-xxx</i>", 'wp-marketing-automations' ),
				'class'         => 'bwfan_make_logs',
				'required'      => false,
				'wrap_before'   => '',
				'isProField'    => true,
				'toggler'       => array(
					'fields' => array(
						array(
							'id'    => 'bwfan_advance_logs',
							'value' => true,
						),
					),
				),
			);
		}

		$settings = array(
			array(
				'key'     => 'general',
				'label'   => __( 'General', 'wp-marketing-automations' ),
				'heading' => 'General Settings',
				'tabs'    => array(
					array(
						'key'     => 'general',
						'label'   => __( 'General', 'wp-marketing-automations' ),
						'heading' => __( 'General', 'wp-marketing-automations' ),
						'fields'  => $general_fields,
					),
					array(
						'key'     => 'emails',
						'label'   => __( 'Email', 'wp-marketing-automations' ),
						'heading' => __( 'Email Settings', 'wp-marketing-automations' ),
						'fields'  => $email_field,
					),
					array(
						'key'         => 'sms',
						'label'       => __( 'SMS', 'wp-marketing-automations' ),
						'heading'     => __( 'SMS Settings', 'wp-marketing-automations' ),
						"showSection" => bwfan_is_autonami_pro_active(),
						'fields'      => $sms_fields,
					),
					array(
						"key"         => 'whatsapp',
						"label"       => __( 'WhatsApp', 'wp-marketing-automations' ),
						"heading"     => __( 'WhatsApp', 'wp-marketing-automations' ),
						"showSection" => bwfan_is_autonami_pro_active() ? BWFAN_Core()->conversation->is_whatsapp_service_available() : false,
						'fields'      => self::get_whatsapp_services_fields(),
					),
					array(
						"key"          => 'abandonment',
						"label"        => __( 'Cart', 'wp-marketing-automations' ),
						"heading"      => __( 'Cart', 'wp-marketing-automations' ),
						"isWooSection" => true,
						"fields"       => array_merge( array(
							array(
								'id'            => 'bwfan_ab_enable',
								'label'         => __( 'Enable Cart Tracking', 'wp-marketing-automations' ),
								'type'          => 'checkbox',
								'checkboxlabel' => __( "Enable to live capture buyer's email & cart details", 'wp-marketing-automations' ),
								'class'         => 'bwfan_ab_enable',
								'required'      => false,
								'toggler'       => array(),
							),
							array(
								'id'         => 'bwfan_ab_init_wait_time',
								'type'       => 'textwithaffix',
								'label'      => __( 'Wait Period', 'wp-marketing-automations' ),
								'inner_type' => 'number',
								'suffix'     => __( 'Minutes', 'wp-marketing-automations' ),
								'hint'       => __( 'Wait for a given time before the cart is marked as Recoverable', 'wp-marketing-automations' ),
								'required'   => false,
								'min'        => 0,
								'toggler'    => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							),
							array(
								'id'          => 'bwfan_disable_abandonment_days',
								'type'        => 'textwithaffix',
								'inner_type'  => 'number',
								'label'       => __( 'Cool Off Period', 'wp-marketing-automations' ),
								'suffix'      => __( 'Days', 'wp-marketing-automations' ),
								'placeholder' => '15',
								"min"         => '0',
								'required'    => false,
								'hint'        => __( 'Exclude customers from cart abandonment tracking if the order was placed days ago (recommended 15 days)', 'wp-marketing-automations' ),
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							),
							array(
								'id'          => 'bwfan_ab_mark_lost_cart',
								'type'        => 'textwithaffix',
								'inner_type'  => 'number',
								'label'       => __( 'Lost Cart', 'wp-marketing-automations' ),
								'suffix'      => __( 'Days', 'wp-marketing-automations' ),
								'required'    => false,
								'placeholder' => '15',
								"min"         => '0',
								'hint'        => __( 'Mark the user as Lost if the order is not made within the given days', 'wp-marketing-automations' ),
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							),
							array(
								'id'            => 'bwfan_ab_email_consent',
								'label'         => __( 'Notice', 'wp-marketing-automations' ),
								'type'          => 'checkbox',
								'checkboxlabel' => __( 'When entering email addresses, inform customers that their email and cart data are saved to send abandonment reminders', 'wp-marketing-automations' ),
								'class'         => 'bwfan_ab_email_consent',
								'required'      => false,
								'wrap_before'   => '<h3>' . __( 'GDPR Consent', 'wp-marketing-automations' ) . '</h3>',
								'toggler'       => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							)
						), $ab_email_consent_message_fields, array(
							array(
								'id'            => 'bwfan_ab_capture_ip_address',
								'label'         => __( 'Capture IP Address', 'wp-marketing-automations' ),
								'type'          => 'checkbox',
								'checkboxlabel' => __( 'Store the visitor\'s IP address with abandoned cart data for fraud and bot analysis', 'wp-marketing-automations' ),
								'class'         => 'bwfan_ab_capture_ip_address',
								'required'      => false,
								'toggler'       => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							),
							array(
								'id'            => 'bwfan_ab_capture_device',
								'label'         => __( 'Capture Device', 'wp-marketing-automations' ),
								'type'          => 'checkbox',
								'checkboxlabel' => __( 'Store the visitor\'s device, browser and language with abandoned cart data', 'wp-marketing-automations' ),
								'class'         => 'bwfan_ab_capture_device',
								'required'      => false,
								'toggler'       => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							)
						), array(
							array(
								'id'          => 'bwfan_ab_tag_selector',
								'label'       => __( 'Add Tag', 'wp-marketing-automations' ),
								'type'        => 'tagselector',
								'class'       => '',
								'placeholder' => '',
								'required'    => false,
								'isProField'  => true,
								'wrap_before' => '<br/><h3>' . __( 'Cart is Abandoned', 'wp-marketing-automations' ) . '</h3>',
								'hint'        => __( 'The selected tag(s) will be added when cart is abandoned. The tag(s) will be automatically removed when cart recovers', 'wp-marketing-automations' ),
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'AND',
								),
							),
							array(
								'id'                  => 'bwfan_ab_list_selector',
								'label'               => __( 'Add list', 'wp-marketing-automations' ),
								"type"                => 'search',
								'autocompleter'       => 'lists',
								"allowFreeTextSearch" => false,
								'required'            => false,
								'isProField'          => true,
								'wrap_before'         => '',
								'hint'                => __( 'The selected lists(s) will be added when cart is abandoned. The list(s) will be automatically removed when cart recovers', 'wp-marketing-automations' ),
								'toggler'             => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'AND',
								),
							),
							array(
								'id'          => 'bwfan_lostcart_tag_selector',
								'label'       => __( 'Add Tag', 'wp-marketing-automations' ),
								'type'        => 'tagselector',
								'class'       => '',
								'placeholder' => '',
								'required'    => false,
								'isProField'  => true,
								'wrap_before' => '<br/><h3>' . __( 'Cart is Lost', 'wp-marketing-automations' ) . '</h3>',
								'hint'        => __( 'The selected tag(s) will be added when cart is lost. The tag(s) will be automatically removed when cart recovers', 'wp-marketing-automations' ),
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'AND',
								),
							),
							array(
								'id'                  => 'bwfan_lostcart_list_selector',
								'label'               => __( 'Add list', 'wp-marketing-automations' ),
								"type"                => 'search',
								'autocompleter'       => 'lists',
								"allowFreeTextSearch" => false,
								'required'            => false,
								'isProField'          => true,
								'wrap_before'         => '',
								'hint'                => __( 'The selected lists(s) will be added when cart is lost. The list(s) will be automatically removed when cart recovers', 'wp-marketing-automations' ),
								'toggler'             => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'AND',
								),
							),
							array(
								'id'            => 'bwfan_ab_exclude_users_cart_tracking',
								'label'         => __( 'Exclude User Roles', 'wp-marketing-automations' ),
								'type'          => 'checkbox',
								'checkboxlabel' => __( 'Exclude user roles from cart tracking', 'wp-marketing-automations' ),
								'class'         => 'bwfan_ab_exclude_users_cart_tracking',
								'required'      => false,
								'toggler'       => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							),
							array(
								'id'          => 'bwfan_ab_exclude_roles',
								'type'        => 'select',
								'class'       => '',
								'options'     => $user_roles,
								'required'    => false,
								'inlineTags'  => false,
								'multiple'    => true,
								'placeholder' => 'Select',
								'hint'        => __( 'Carts for selected user roles will not be tracked', 'wp-marketing-automations' ),
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
										array(
											'id'    => 'bwfan_ab_exclude_users_cart_tracking',
											'value' => true,
										),
									),
									'relation' => 'AND',
								),
							),
							array(
								'id'          => 'bwfan_ab_exclude_emails',
								'label'       => __( 'Emails', 'wp-marketing-automations' ),
								'type'        => 'textarea',
								'class'       => '',
								'required'    => false,
								'wrap_before' => '<br/><h3>' . __( 'Blacklist Emails', 'wp-marketing-automations' ) . '</h3>',
								'hint'        => __( 'Enter emails, domains or partials to exclude from cart  abandonment tracking separated by comma(,) or in new line
                                            <br>You can add full emails (i.e. foo@example.com) or domains (i.e. domain.com), or partials (i.e. john)', 'wp-marketing-automations' ),
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'AND',
								),
							),
							array(
								'id'          => 'bwfan_ab_restore_cart_message_success',
								'label'       => __( 'Cart Success Notice', 'wp-marketing-automations' ),
								'type'        => 'text',
								'class'       => '',
								'placeholder' => '',
								'wrap_before' => '<h3>' . __( 'Checkout Notice', 'wp-marketing-automations' ) . '</h3>',
								'required'    => false,
								'hint'        => __( "Notice when cart is successfully restored. Leave blank in case you don't want to show a notice.", 'wp-marketing-automations' ),
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							),
							array(
								'id'          => 'bwfan_ab_restore_cart_message_failure',
								'label'       => __( 'Cart Failure Notice', 'wp-marketing-automations' ),
								'type'        => 'text',
								'class'       => '',
								'placeholder' => '',
								'required'    => false,
								'hint'        => __( "Notice when cart fails to restore. Leave blank in case you don't want to show a notice.", 'wp-marketing-automations' ),
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_ab_enable',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							)
						) ),
					),
					/*
					array(
						"key"     => 'conversion',
						"label"   => 'Conversions', // label is used for left side tab item
						"heading" => 'Conversions',
						"fields"  => [
							array(
								"id"          => 'bwfan_order_tracking_conversion',
								"label"       => __( 'Order Tracking Conversion', 'wp-marketing-automations' ),
								"type"        => 'number',
								"class"       => 'bwfan_order_tracking_conversion',
								"placeholder" => "Days to track order",
								"hint"        => __( "Days to Track order details for conversion.", 'wp-marketing-automations' ),
								"required"    => false,
								"toggler"     => array(),
							),
						],
					),*/
					array(
						'key'          => 'optin',
						'label'        => __( 'Checkout Consent', 'wp-marketing-automations' ),
						'heading'      => __( 'Checkout Consent', 'wp-marketing-automations' ),
						'isWooSection' => true,
						'fields'       => array_merge( array(
							array(
								'id'            => 'bwfan_user_consent',
								'label'         => __( 'Enable Marketing Consent', 'wp-marketing-automations' ),
								'type'          => 'checkbox',
								'checkboxlabel' => __( 'Enable an optin on checkout to ask for the consent of marketing emails.', 'wp-marketing-automations' ),
								'hint'          => __( 'Note: For logged in users, this field would not be visible to Contacts if they are subscribed', 'wp-marketing-automations' ),
								'class'         => 'bwfan_user_consent',
								'required'      => false,
								'wrap_before'   => '',
								'toggler'       => array(),
							)
						), $checkout_consent_fields, array(
							array(
								'id'       => 'bwfan_user_consent_position',
								'label'    => __( 'Consent Field Position', 'wp-marketing-automations' ),
								'type'     => 'select',
								'multiple' => false,
								'class'    => 'bwfan_user_consent_position',
								'options'  => array(
									array(
										'value' => 'below_term',
										'label' => __( 'Below Terms & Condition', 'wp-marketing-automations' ),
									),
									array(
										'value' => 'below_email',
										'label' => __( 'Below Email Field', 'wp-marketing-automations' ),
									),
									array(
										'value' => 'below_phone',
										'label' => __( 'Below Phone Field', 'wp-marketing-automations' ),
									),
								),
								'required' => false,
								'toggler'  => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_user_consent',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							),
							array(
								'id'       => 'bwfan_user_consent_position_blocks',
								'label'    => __( 'Gutenberg / Block Checkout Position', 'wp-marketing-automations' ),
								'type'     => 'select',
								'multiple' => false,
								'class'    => 'bwfan_user_consent_position_blocks',
								'options'  => array(
									array(
										'value' => 'contact',
										'label' => __( 'Contact information', 'wp-marketing-automations' ),
									),
									array(
										'value' => 'order',
										'label' => __( 'Order information', 'wp-marketing-automations' ),
									),
								),
								'required' => false,
								'hint'     => __( 'Where to show the marketing consent on the block-based (Gutenberg) checkout. Shortcode checkout uses the position above.', 'wp-marketing-automations' ),
								'toggler'  => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_user_consent',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							),
							array(
								'id'          => 'bwfan_user_consent_eu',
								'label'       => __( 'EU Contacts', 'wp-marketing-automations' ),
								'type'        => 'select',
								'multiple'    => false,
								'class'       => 'bwfan_user_consent_eu',
								'options'     => array(
									array(
										'value' => '1',
										'label' => __( 'Checked', 'wp-marketing-automations' ),
									),
									array(
										'value' => '0',
										'label' => __( 'Unchecked', 'wp-marketing-automations' ),
									),
								),
								'required'    => false,
								'hint'        => __( 'EU contacts are determined by their IP address. To respect GDPR, keep it unchecked.', 'wp-marketing-automations' ),
								'wrap_before' => '<br/><h3>' . __( 'Consent Checked Behaviour', 'wp-marketing-automations' ) . '</h3>',
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_user_consent',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							),
							array(
								'id'       => 'bwfan_user_consent_non_eu',
								'label'    => __( 'Non-EU Contacts', 'wp-marketing-automations' ),
								'type'     => 'select',
								'multiple' => false,
								'class'    => 'bwfan_user_consent_non_eu',
								'options'  => array(
									array(
										'value' => '1',
										'label' => __( 'Checked', 'wp-marketing-automations' ),
									),
									array(
										'value' => '0',
										'label' => __( 'Unchecked', 'wp-marketing-automations' ),
									),
								),
								'required' => false,
								'toggler'  => array(
									'fields'   => array(
										array(
											'id'    => 'bwfan_user_consent',
											'value' => true,
										),
									),
									'relation' => 'OR',
								),
							)
						) ),
					),
					array(
						'key'     => 'pages',
						'label'   => __( 'Contact Pages', 'wp-marketing-automations' ),
						'heading' => __( 'Contact Pages', 'wp-marketing-automations' ),
						'tabs'    => [
							array(
								'key'     => 'subscribe-page',
								'label'   => __( 'Subscribe Page', 'wp-marketing-automations' ),
								'heading' => __( 'Subscribe Page', 'wp-marketing-automations' ),
								'fields'  => self::get_unsubscribe_page_schema(),
							),
							array(
								'key'     => 'profile-page',
								'label'   => __( 'Profile Page', 'wp-marketing-automations' ),
								'heading' => __( 'Profile Page ', 'wp-marketing-automations' ),
								'fields'  => self::get_manage_profile_schema(),
							),
						]
					),
					array(
						'key'          => 'double-optin',
						'label'        => __( 'Double Opt-in', 'wp-marketing-automations' ),
						'heading'      => __( 'Double Opt-in', 'wp-marketing-automations' ),
						'isProSection' => true,
						'fields'       => array(
							array(
								'id'          => 'after_confirmation_type',
								'label'       => __( 'After Confirmation Type', 'wp-marketing-automations' ),
								'type'        => 'radio',
								'options'     => [
									[
										'label'   => __( "Show Message", 'wp-marketing-automations' ),
										'value'   => 'show_message',
										'tooltip' => ""
									],
									[
										'label'   => __( "Redirect to an URL", 'wp-marketing-automations' ),
										'value'   => 'redirect',
										'tooltip' => ""
									]
								],
								'hint'        => __( "Please Select what will happen after contact clicked on subscribe link", 'wp-marketing-automations' ),
								'class'       => 'bwfan_confirmation_type',
								'required'    => false,
								'wrap_before' => '',
							),
							array(
								'id'          => 'bwfan_confirmation_message',
								'label'       => __( 'After Confirmation Message', 'wp-marketing-automations' ),
								'type'        => 'richeditor',
								'class'       => 'bwfan_confirmation_message',
								'required'    => false,
								'wrap_before' => '<h3></h3>',
								'hint'        => '',
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'after_confirmation_type',
											'value' => 'show_message',
										)
									),
									'relation' => 'OR',
								),
							),
							array(
								'id'          => 'bwfan_confirmation_redirect_url',
								'label'       => __( 'Redirect URL', 'wp-marketing-automations' ),
								'type'        => 'text',
								'class'       => 'bwfan_confirmation_redirect_url',
								'placeholder' => 'Redirect URL',
								'required'    => false,
								'hint'        => __( 'Please provide redirect URL after contact confirmation', 'wp-marketing-automations' ),
								'toggler'     => array(
									'fields'   => array(
										array(
											'id'    => 'after_confirmation_type',
											'value' => 'redirect',
										)
									),
									'relation' => 'OR',
								),
							),
						),
					),
					array(
						'key'     => 'advanced',
						'label'   => __( 'Advanced', 'wp-marketing-automations' ),
						'heading' => __( 'Advanced', 'wp-marketing-automations' ),
						'fields'  => array(
							array(
								'id'            => 'bwfan_sandbox_mode',
								'label'         => __( 'Sandbox Mode', 'wp-marketing-automations' ),
								'type'          => 'checkbox',
								'checkboxlabel' => __( 'Enable Sandbox Mode', 'wp-marketing-automations' ),
								'hint'          => bwfan_is_autonami_pro_active() ? __( "Automations and Broadcasts will not be processed when you enable sandbox mode.", 'wp-marketing-automations' ) : __( "Automations will not be processed when you enable sandbox mode.", 'wp-marketing-automations' ),
								'class'         => 'bwfan_sandbox_mode',
								'required'      => false,
								'wrap_before'   => '',
								'toggler'       => array(),
							),
							array(
								'id'            => 'bwfan_delete_expired_coupon',
								'label'         => __( 'Auto Delete Coupons', 'wp-marketing-automations' ),
								'type'          => 'checkbox',
								'checkboxlabel' => __( 'Enable', 'wp-marketing-automations' ),
								'hint'          => __( 'Automatically delete WooCommerce coupons generated by FunnelKit Automations [X] days after they expire.', 'wp-marketing-automations' ),
								'class'         => '',
								'required'      => false,
								'isWooField'    => true,
								'isProField'    => true,
								'wrap_before'   => '<br/><h3>' . __( 'Auto Delete Expired Coupons', 'wp-marketing-automations' ) . '</h3>',
								'toggler'       => array(),
							),
							array(
								'id'         => 'bwfan_delete_autonami_generated_coupons_time',
								'type'       => 'textwithaffix',
								'inner_type' => 'number',
								'suffix'     => __( 'Days', 'wp-marketing-automations' ),
								'isWooField' => true,
								'isProField' => true,
								'required'   => false,
								'toggler'    => array(
									'fields' => array(
										array(
											'id'    => 'bwfan_delete_expired_coupon',
											'value' => true,
										),
									),
								),
							),
							...$engagement_meta_record_delete,
							array(
								'id'          => 'debug_notice',
								'type'        => 'notice',
								'class'       => '',
								'status'      => 'warning',
								'message'     => __( 'It is highly recommended to disable logging once troubleshooting is complete.', 'wp-marketing-automations' ),
								'wrap_before' => '<br/><h3>' . __( 'Debug Logs (For Developers)', 'wp-marketing-automations' ) . '</h3>',
								'dismiss'     => false,
								'required'    => false,
								'toggler'     => array(),
							),
							array(
								'id'            => 'bwfan_make_logs',
								'label'         => __( 'Basic Logging', 'wp-marketing-automations' ),
								'type'          => 'checkbox',
								'checkboxlabel' => __( 'Enable Basic Logs', 'wp-marketing-automations' ),
								'hint'          => __( 'These are basic operational logs. Logs will be saved on path <b>Funnelkit Automation > Settings > Logs</b>. Disable this settings after debugging is finished.', 'wp-marketing-automations' ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'toggler'       => array(),
							),
							array(
								'id'            => 'bwfan_advance_logs',
								'label'         => __( 'Advance Logging', 'wp-marketing-automations' ),
								'type'          => 'checkbox',
								'checkboxlabel' => __( 'Enable Advanced Logs', 'wp-marketing-automations' ),
								'hint'          => __( 'These are advanced logs for deeper troubleshooting for specific modules. Logs will be saved on path <b>Funnelkit Automation > Settings > Logs</b>. Disable this settings after debugging is finished.', 'wp-marketing-automations' ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'wrap_before'   => '',
								'toggler'       => array(),
							),
							array(
								'id'            => 'bwfan_cron_check_logging',
								'type'          => 'checkbox',
								'label'         => ' ',
								'checkboxlabel' => __( 'Enable Logs for Cron Execution Time', 'wp-marketing-automations' ),
								'hint'          => __( "It logs the execution time of FunnelKit Automation worker. Logs are captured in file named <i>fka-cron-check-xxx</i>", 'wp-marketing-automations' ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'wrap_before'   => '',
								'toggler'       => array(
									'fields' => array(
										array(
											'id'    => 'bwfan_advance_logs',
											'value' => true,
										),
									),
								),
							),
							array(
								'id'            => 'bwfan_end_point_logging',
								'type'          => 'checkbox',
								'label'         => ' ',
								'checkboxlabel' => __( "Enable Logs for Event JSON endpoint", "wp-marketing-automations" ),
								'hint'          => __( "It logs the payload and response of the call. Logs are captured in file named <i>fka-event-endpoint-check-xxx</i>", 'wp-marketing-automations' ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'wrap_before'   => '',
								'toggler'       => array(
									'fields' => array(
										array(
											'id'    => 'bwfan_advance_logs',
											'value' => true,
										),
									),
								),
							),
							array(
								'id'            => 'bwfan_step_logging',
								'label'         => ' ',
								'type'          => 'checkbox',
								'checkboxlabel' => __( 'Enable Logs for Automation Steps', 'wp-marketing-automations' ),
								'hint'          => __( "It logs step by step execution in Automation. Logs are captured in file named <i>fka-automation-step-id-xxx</i>", 'wp-marketing-automations' ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'wrap_before'   => '',
								'toggler'       => array(
									'fields' => array(
										array(
											'id'    => 'bwfan_advance_logs',
											'value' => true,
										),
									),
								),
							),
							array(
								'id'            => 'bwfan_broadcast_logging',
								'type'          => 'checkbox',
								'label'         => ' ',
								'checkboxlabel' => __( 'Enable Logs for Broadcast', 'wp-marketing-automations' ),
								'hint'          => __( "It logs step by step execution in Broadcast. Logs are captured in file named <i>fka-broadcast-xxx</i>", 'wp-marketing-automations' ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'wrap_before'   => '',
								'isProField'    => true,
								'toggler'       => array(
									'fields' => array(
										array(
											'id'    => 'bwfan_advance_logs',
											'value' => true,
										),
									),
								),
							),
							array(
								'id'            => 'bwfan_bulk_action_logging',
								'type'          => 'checkbox',
								'label'         => ' ',
								'checkboxlabel' => __( 'Enable Logs for Bulk Actions', 'wp-marketing-automations' ),
								'hint'          => __( "It logs step by step execution in Bulk Action. Logs are captured in file named <i>fka-bulk-action-xxx</i>", "wp-marketing-automations" ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'wrap_before'   => '',
								'isProField'    => true,
								'toggler'       => array(
									'fields' => array(
										array(
											'id'    => 'bwfan_advance_logs',
											'value' => true,
										),
									),
								),
							),
							array(
								'id'            => 'bwfan_contact_export_logging',
								'type'          => 'checkbox',
								'label'         => ' ',
								'checkboxlabel' => __( 'Enable Logs for Contact Export Process', 'wp-marketing-automations' ),
								'hint'          => __( "It logs step by step execution in Contact Export. Logs are captured in file named <i>fka-contact-export-xxx</i>", 'wp-marketing-automations' ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'wrap_before'   => '',
								'isProField'    => true,
								'toggler'       => array(
									'fields' => array(
										array(
											'id'    => 'bwfan_advance_logs',
											'value' => true,
										),
									),
								),
							),
							array(
								'id'            => 'bwfan_contact_query_logging',
								'type'          => 'checkbox',
								'label'         => ' ',
								'checkboxlabel' => __( 'Enable Logs for Contact Query', 'wp-marketing-automations' ),
								'hint'          => __( "It logs the contact fetching query and time spent in fetching the data. Logs are captured in file named <i>fka-contacts-query-xxx</i>", 'wp-marketing-automations' ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'wrap_before'   => '',
								'isProField'    => true,
								'toggler'       => array(
									'fields' => array(
										array(
											'id'    => 'bwfan_advance_logs',
											'value' => true,
										),
									),
								),
							),
							array(
								'id'            => 'bwfan_email_bounce_logging',
								'type'          => 'checkbox',
								'label'         => ' ',
								'checkboxlabel' => __( 'Enable Logs for Email Bounce Webhook', 'wp-marketing-automations' ),
								'hint'          => __( "It logs the received data from email service provider related to bounce and complaint. Logs are captured in file named <i>fka-email-webhook-request-xxx</i>", 'wp-marketing-automations' ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'wrap_before'   => '',
								'isProField'    => true,
								'toggler'       => array(
									'fields' => array(
										array(
											'id'    => 'bwfan_advance_logs',
											'value' => true,
										),
									),
								),
							),
							array(
								'id'            => 'bwfan_webhook_received_logging',
								'type'          => 'checkbox',
								'label'         => ' ',
								'checkboxlabel' => __( 'Enable Logs for Webhook Received event automation', 'wp-marketing-automations' ),
								'hint'          => __( "It logs the received data on a 'Webhook Received' automation. Logs are captured in file named <i>fka-webhook-logs-xxx</i>", 'wp-marketing-automations' ),
								'class'         => 'bwfan_make_logs',
								'required'      => false,
								'wrap_before'   => '',
								'isProField'    => true,
								'toggler'       => array(
									'fields' => array(
										array(
											'id'    => 'bwfan_advance_logs',
											'value' => true,
										),
									),
								),
							),
							$twilio_webhook_log_data,
							$short_url_log_data
						),
					),
					array(
						'key'    => 'unsubscribers',
						'label'  => __( 'Unsubscribers', 'wp-marketing-automations' ),
						'fields' => [
							array(
								'id'   => 'bwfan_unsubscribers',
								'type' => 'bwfan_unsubscribers',
							),
						],
					),
					array(
						'key'     => 'tools',
						'label'   => __( 'Tools', 'wp-marketing-automations' ),
						'heading' => __( 'Tools', 'wp-marketing-automations' ),
						'tabs'    => [
							[
								'key'     => 'tools',
								'label'   => __( 'Tools', 'wp-marketing-automations' ),
								'heading' => __( 'Tools', 'wp-marketing-automations' ),
								'fields'  => [
									array(
										'id'   => 'bwfan_tools',
										'type' => 'bwfan_tools',
									),
								],
							],
							[
								'key'     => 'logs',
								'label'   => __( 'Logs', 'wp-marketing-automations' ),
								'heading' => __( 'Logs', 'wp-marketing-automations' ),
								'fields'  => [
									array(
										'id'   => 'bwfan_logs',
										'type' => 'bwfan_logs',
									),
								],
							],
						],
					),
					array(
						'key'     => 'notifications',
						'label'   => 'Notifications', // label is used for left side tab item
						'heading' => 'Notifications',
						'fields'  => self::get_notification_settings_schema(),
					),
				),
			),
		);

		return apply_filters( 'bwfan_admin_settings_schema', $settings );
	}

	public static function get_select_options( $type = 'hour' ) {
		$options = [];

		switch ( $type ) {
			case 'hour':
				for ( $i = 1; $i <= 12; $i ++ ) {
					$options[] = [
						'value' => sprintf( "%02d", $i ),
						'label' => sprintf( "%02d", $i ),
					];
				}
				break;
			case 'minute':
				for ( $i = 0; $i <= 59; $i ++ ) {
					$options[] = [
						'value' => sprintf( "%02d", $i ),
						'label' => sprintf( "%02d", $i ),
					];
				}
				break;
		}

		return $options;
	}

	public static function get_notification_settings_schema() {
		return [
			[
				'id'    => 'bwfan_enable_notification',
				'label' => __( 'Enable Email Performance Summary', 'wp-marketing-automations' ),
				'class' => 'bwf-tooglecontrol-settings',
				'type'  => 'toggle',
			],
			[
				'id'          => 'bwf_notification_frequency',
				'label'       => __( 'Frequency', 'wp-marketing-automations' ),
				'type'        => 'checkbox_grid',
				'class'       => '',
				'placeholder' => '',
				'required'    => false,
				'options'     => [
					'weekly'  => __( 'Weekly', 'wp-marketing-automations' ),
					'monthly' => __( 'Monthly', 'wp-marketing-automations' ),
				],
				'hint'        => __( 'Emails will be skipped if there are no metrics to show', 'wp-marketing-automations' ),
				'toggler'     => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_enable_notification',
							'value' => true,
						)
					),
					'relation' => 'AND',
				),
			],
			array(
				'id'                  => 'bwf_notification_user_selector',
				'label'               => __( 'Users', 'wp-marketing-automations' ),
				"type"                => 'search',
				'autocompleter'       => 'users',
				"allowFreeTextSearch" => false,
				'required'            => false,
				'wrap_before'         => '',
				'toggler'             => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_enable_notification',
							'value' => true,
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'       => 'bwfan_external_user',
				'label'    => __( 'Other Recipient', 'wp-marketing-automations' ),
				'type'     => 'addrecipient',
				'class'    => '',
				'hint'     => __( 'If no users or recipients are added, the email summary will be sent to the site admin.', 'wp-marketing-automations' ),
				'required' => false,
				'toggler'  => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_enable_notification',
							'value' => true,
						),
					),
					'relation' => 'AND',
				),
			),
			[
				'id'          => 'bwfan_notification_time',
				'type'        => 'expression',
				'expression'  => " {{hours/}} {{minutes /}} {{ampm /}}",
				'label'       => __( 'Send Time', 'wp-marketing-automations' ),
				'fields'      => [
					[
						"id"          => 'hours',
						"label"       => '',
						"type"        => 'select',
						"options"     => self::get_select_options( 'hour' ),
						"class"       => 'bwf-wid-90',
						"placeholder" => "HH",
						"description" => "",
						"required"    => false,
					],
					[
						"id"          => 'minutes',
						"label"       => '',
						"type"        => 'select',
						"options"     => self::get_select_options( 'minute' ),
						"class"       => 'bwf-wid-90',
						"placeholder" => "MM",
						"description" => "",
						"required"    => false,
					],
					[
						'id'          => 'ampm',
						'type'        => 'select',
						"class"       => 'bwf-wid-90',
						'label'       => '',
						'placeholder' => __( 'Select', 'wp-marketing-automations' ),
						'options'     => [
							[
								'value' => 'am',
								'label' => __( 'AM', 'wp-marketing-automations' ),
							],
							[
								'value' => 'pm',
								'label' => __( 'PM', 'wp-marketing-automations' ),
							]
						],
					]
				],
				"description" => "",
				'toggler'     => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_enable_notification',
							'value' => true,
						),
					),
					'relation' => 'AND',
				),
			],
			array(
				'id'      => 'send_test_mail',
				'type'    => 'testmail',
				'label'   => '',
				'class'   => 'bwf-position-test-mail-bottom',
				'toggler' => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_enable_notification',
							'value' => true,
						),
					),
					'relation' => 'AND',
				),
			)
		];
	}

	public static function get_bounce_settings_schema() {
		if ( ! bwfan_is_autonami_pro_active() ) {
			return array(
				array(
					'id'            => 'bwfan_enable_bounce_handling',
					'label'         => __( 'Bounce Tracking', 'wp-marketing-automations' ),
					'type'          => 'checkbox',
					'checkboxlabel' => __( 'Enable to capture bounced emails from the email service and mark Contact as Bounced', 'wp-marketing-automations' ),
					'class'         => 'bwfan_user_consent',
					'required'      => false,
					'wrap_before'   => '<h3>' . __( 'Email Service Provider', 'wp-marketing-automations' ) . '</h3>',
					'disabled'      => true,
					'isProSetting'  => true,
				),
			);
		}
		$bounce_settings        = bwfan_is_autonami_pro_active() ? BWFCRM_Core()->email_webhooks->get_webhooks() : array();
		$bounce_options         = array(
			array(
				'label' => __( 'Select Email Service', 'wp-marketing-automations' ),
				'value' => '',
			),
		);
		$bounce_settings_schema = ! empty( $bounce_settings ) ? array_map( function ( $webhook, $slug ) use ( &$bounce_options ) {
			if ( empty( $slug ) || empty( $webhook ) || ! is_array( $webhook ) ) {
				return false;
			}
			$bounce_options[] = array(
				'label' => $webhook['name'],
				'value' => strtolower( $webhook['name'] ),
			);

			return array(
				'id'        => 'bwfan_email_webhook_' . $slug,
				'type'      => 'copier',
				'class'     => 'bwfan_email_webhook',
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'hint'      => sprintf( __( "Paste this URL into your %s's Webhook settings to enable Bounce Handling", 'wp-marketing-automations' ), $webhook['name'] ),
				'required'  => false,
				'copy_text' => $webhook['link'],
				'toggler'   => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_bounce_select',
							'value' => strtolower( $webhook['name'] ),
						),
						array(
							'id'    => 'bwfan_enable_bounce_handling',
							'value' => true,
						),
					),
					'relation' => 'AND',
				),
			);
		}, $bounce_settings, array_keys( $bounce_settings ) ) : array();

		$bounce_settings_schema = array_merge( array(
			array(
				'id'            => 'bwfan_enable_bounce_handling',
				'label'         => __( 'Bounce Tracking', 'wp-marketing-automations' ),
				'type'          => 'checkbox',
				'checkboxlabel' => __( 'Enable to capture bounced emails from the email service and mark Contact as Bounced', 'wp-marketing-automations' ),
				'class'         => 'bwfan_user_consent',
				'required'      => false,
				'wrap_before'   => '<h3>' . __( 'Email Service Provider', 'wp-marketing-automations' ) . '</h3>',
			),
			array(
				'id'       => 'bwfan_bounce_select',
				'type'     => 'select',
				'class'    => '',
				'options'  => $bounce_options,
				'required' => false,
				'multiple' => false,
				'toggler'  => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_enable_bounce_handling',
							'value' => true,
						),
					),
					'relation' => 'AND',
				),
			),
		), $bounce_settings_schema );

		return is_array( $bounce_settings_schema ) ? array_filter( $bounce_settings_schema ) : array();
	}

	public static function get_pro_license( $onlyKey = true ) {
		$bwf_licenses = get_option( 'woofunnels_plugins_info', false );
		if ( is_multisite() ) {
			$active_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $active_plugins ) && ( in_array( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ), true ) || array_key_exists( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ) ) ) && ! is_main_site() ) {
				$bwf_licenses = get_blog_option( get_network()->site_id, 'woofunnels_plugins_info', [] );
			}
		}
		if ( ! defined( 'BWFAN_PRO_ENCODE' ) || empty( $bwf_licenses ) || ! is_array( $bwf_licenses ) ) {
			return false;
		}
		if ( isset( $bwf_licenses[ BWFAN_PRO_ENCODE ] ) && isset( $bwf_licenses[ BWFAN_PRO_ENCODE ]['activated'] ) && $bwf_licenses[ BWFAN_PRO_ENCODE ]['activated'] ) {
			if ( $onlyKey ) {
				return $bwf_licenses[ BWFAN_PRO_ENCODE ]['data_extra']['api_key'];
			} else {
				$license_data = [
					'manually_deactivated' => isset( $bwf_licenses[ BWFAN_PRO_ENCODE ]['manually_deactivated'] ) ? 1 : 0,
					'expired'              => empty( $bwf_licenses[ BWFAN_PRO_ENCODE ]['expired'] ) ? 0 : 1,
				];
				if ( isset( $bwf_licenses[ BWFAN_PRO_ENCODE ]['data_extra']['api_key'] ) ) {
					$license_data['api_key'] = 'xxxxxxxxxxxxxxxxxxxxxxxxxx' . substr( $bwf_licenses[ BWFAN_PRO_ENCODE ]['data_extra']['api_key'], - 6 );
				}
				if ( isset( $bwf_licenses[ BWFAN_PRO_ENCODE ]['data_extra']['expires'] ) ) {
					$license_data['expires'] = $bwf_licenses[ BWFAN_PRO_ENCODE ]['data_extra']['expires'];
				}

				return $license_data;
			}
		}

		$plugin_sha = sha1( plugin_basename( 'wp-marketing-automations-pro/wp-marketing-automations-pro.php' ) );
		if ( ! $onlyKey && $plugin_sha !== BWFAN_PRO_ENCODE ) {
			return [
				'error_msg' => wp_kses_post( __( 'We have detected that plugin directory has been renamed. Please restore the directory to its original name or download the latest files from', 'wp-marketing-automations' ) ) . ' <a href="' . esc_url( BWFAN_Common::get_fk_site_links( 'account' ) ) . '">' . esc_html__( 'your account', 'wp-marketing-automations' ) . '</a> ' . wp_kses_post( __( 'or', 'wp-marketing-automations' ) ) . ' <a href="' . esc_url( BWFAN_Common::get_fk_site_links( 'support' ) ) . '">' . esc_html__( 'contact support', 'wp-marketing-automations' ) . '</a> ' . wp_kses_post( __( 'for assistance.', 'wp-marketing-automations' ) )
			];
		}

		return false;
	}

	/**
	 * Whether the Pro plugin folder has been renamed in a way that blocks license activation.
	 *
	 * Detection is server/DB independent: it compares the canonical folder hash against the
	 * live BWFAN_PRO_ENCODE. The "already activated under the live hash" guard prevents false
	 * positives for legacy installs that were activated under a non-canonical folder slug.
	 *
	 * @since 3.8.2
	 *
	 * @return bool True when Pro is active, the live folder hash differs from the canonical
	 *              hash, and no activated license exists under the live hash; false otherwise.
	 */
	public static function is_pro_folder_renamed() {
		if ( false === bwfan_is_autonami_pro_active() || ! defined( 'BWFAN_PRO_ENCODE' ) ) {
			return false;
		}

		$canonical_sha = sha1( plugin_basename( 'wp-marketing-automations-pro/wp-marketing-automations-pro.php' ) );
		/* Folder is canonical (hash matches) — license can activate normally, nothing to warn about. */
		if ( hash_equals( $canonical_sha, BWFAN_PRO_ENCODE ) ) {
			return false;
		}

		$bwf_licenses = get_option( 'woofunnels_plugins_info', false );
		if ( is_multisite() ) {
			$active_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $active_plugins ) && ( in_array( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ), true ) || array_key_exists( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ) ) ) && ! is_main_site() ) {
				$bwf_licenses = get_blog_option( get_network()->site_id, 'woofunnels_plugins_info', [] );
			}
		}

		/* Legacy non-canonical install that activated under its own hash — license works, do not warn. */
		if ( is_array( $bwf_licenses ) && ! empty( $bwf_licenses[ BWFAN_PRO_ENCODE ]['activated'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the Connectors plugin folder has been renamed in a way that blocks license activation.
	 *
	 * Mirrors {@see self::is_pro_folder_renamed()} for the autonami-connectors plugin: compares
	 * the canonical folder hash against the live WFCO_AUTONAMI_CONNECTORS_ENCODE and exempts
	 * legacy installs already activated under the live hash.
	 *
	 * @since 3.8.2
	 *
	 * @return bool True when Connectors is active, the live folder hash differs from canonical,
	 *              and no activated license exists under the live hash; false otherwise.
	 */
	public static function is_connector_folder_renamed() {
		if ( false === class_exists( 'BWFAN_Basic_Connector_Support' ) || ! defined( 'WFCO_AUTONAMI_CONNECTORS_ENCODE' ) ) {
			return false;
		}

		$canonical_sha = sha1( plugin_basename( 'wp-marketing-automations-connectors/wp-marketing-automations-connectors.php' ) );
		if ( hash_equals( $canonical_sha, WFCO_AUTONAMI_CONNECTORS_ENCODE ) ) {
			return false;
		}

		$bwf_licenses = get_option( 'woofunnels_plugins_info', false );
		if ( is_multisite() ) {
			$active_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $active_plugins ) && ( in_array( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ), true ) || array_key_exists( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ) ) ) && ! is_main_site() ) {
				$bwf_licenses = get_blog_option( get_network()->site_id, 'woofunnels_plugins_info', [] );
			}
		}

		if ( is_array( $bwf_licenses ) && ! empty( $bwf_licenses[ WFCO_AUTONAMI_CONNECTORS_ENCODE ]['activated'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @return array
	 */
	public static function get_lk_data() {
		if ( ! is_null( self::$c_lk_d ) ) {
			return self::$c_lk_d;
		}

		$arr = [ 's' => 0, 'e' => '' ];
		if ( ! bwfan_is_autonami_pro_active() ) {
			self::$c_lk_d = $arr;

			return self::$c_lk_d;
		}

		$arr['s'] = 1;
		if ( is_multisite() ) {
			$active_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $active_plugins ) && ( in_array( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ), true ) || array_key_exists( BWFAN_PLUGIN_BASENAME, apply_filters( 'active_plugins', $active_plugins ) ) ) && ! is_main_site() ) {
				$l_data = get_blog_option( get_network()->site_id, 'woofunnels_plugins_info', [] );
			} else {
				$l_data = get_option( 'woofunnels_plugins_info', false );
			}
		} else {
			$l_data = get_option( 'woofunnels_plugins_info', false );
		}
		if ( ! defined( 'BWFAN_PRO_ENCODE' ) || empty( $l_data ) || ! is_array( $l_data ) || ! isset( $l_data[ BWFAN_PRO_ENCODE ] ) || ! isset( $l_data[ BWFAN_PRO_ENCODE ]['activated'] ) || 1 !== intval( $l_data[ BWFAN_PRO_ENCODE ]['activated'] ) ) {
			$arr['ad'] = bwf_options_get( 'fka_psd' );

			self::$c_lk_d = $arr;

			return self::$c_lk_d;
		}

		$arr['s'] = 2;
		$arr['e'] = $l_data[ BWFAN_PRO_ENCODE ]['data_extra']['expires'];
		$arr['h'] = isset( $l_data[ BWFAN_PRO_ENCODE ]['data_extra']['h'] ) ? $l_data[ BWFAN_PRO_ENCODE ]['data_extra']['h'] : '';

		self::$c_lk_d = $arr;

		return self::$c_lk_d;
	}

	public static function get_connector_license( $onlyKey = true ) {
		$bwf_licenses = get_option( 'woofunnels_plugins_info', false );
		if ( empty( $bwf_licenses ) || ! is_array( $bwf_licenses ) ) {
			return false;
		}

		if ( isset( $bwf_licenses[ WFCO_AUTONAMI_CONNECTORS_ENCODE ] ) && isset( $bwf_licenses[ WFCO_AUTONAMI_CONNECTORS_ENCODE ]['activated'] ) && $bwf_licenses[ WFCO_AUTONAMI_CONNECTORS_ENCODE ]['activated'] ) {
			if ( $onlyKey ) {
				return $bwf_licenses[ WFCO_AUTONAMI_CONNECTORS_ENCODE ]['data_extra']['api_key'];
			} else {
				$license_data = [
					'manually_deactivated' => isset( $bwf_licenses[ WFCO_AUTONAMI_CONNECTORS_ENCODE ]['manually_deactivated'] ) ? 1 : 0,
				];
				if ( isset( $bwf_licenses[ WFCO_AUTONAMI_CONNECTORS_ENCODE ]['data_extra']['api_key'] ) ) {
					$license_data['api_key'] = 'xxxxxxxxxxxxxxxxxxxxxxxxxx' . substr( $bwf_licenses[ WFCO_AUTONAMI_CONNECTORS_ENCODE ]['data_extra']['api_key'], - 6 );
				}
				if ( isset( $bwf_licenses[ WFCO_AUTONAMI_CONNECTORS_ENCODE ]['data_extra']['expires'] ) ) {
					$license_data['expires'] = $bwf_licenses[ WFCO_AUTONAMI_CONNECTORS_ENCODE ]['data_extra']['expires'];
				}

				return $license_data;
			}
		}

		$plugin_sha = sha1( plugin_basename( 'wp-marketing-automations-connectors/wp-marketing-automations-connectors.php' ) );
		if ( ! $onlyKey && $plugin_sha !== WFCO_AUTONAMI_CONNECTORS_ENCODE ) {
			return [
				'error_msg' => __( 'It appears that the original plugin folder has been renamed. Please restore the folder to its original name or reinstall the plugin to activate the license.', 'wp-marketing-automations' )
			];
		}

		return false;
	}

	/**
	 * Get settings fields for whatsapp services
	 *
	 * @return array|array[]
	 */
	public static function get_whatsapp_services_fields() {
		$fields = array();

		if ( bwfan_is_autonami_pro_active() && class_exists( 'WFCO_Autonami_Connectors_Core' ) ) {
			$services          = self::get_whatsapp_services();
			$additional_fields = apply_filters( 'bwfan_whatsapp_services_additional_fields', array(), $services );
			if ( count( $services ) > 0 ) {

				if ( count( $services ) > 1 ) {
					$fields[] = array(
						"id"       => 'bwfan_primary_whats_app_service',
						"label"    => __( 'Select Service', 'wp-marketing-automations' ),
						"type"     => 'select',
						"class"    => '',
						"options"  => $services,
						"required" => false,
						"multiple" => false,
						"toggler"  => array(),
					);
				}
				$fields = array_merge( $fields, array(
					array(
						"id"           => 'bwfan_whatsapp_gap_btw_message',
						"label"        => __( 'Time Between Each Message (secs)', 'wp-marketing-automations' ),
						"type"         => 'number',
						"min"          => '0',
						"class"        => 'bwfan_whatsapp_gap_btw_message',
						"placeholder"  => '1',
						"hint"         => __( "The time gap between messages in seconds", 'wp-marketing-automations' ),
						"required"     => false,
						"autocomplete" => 'off',
						"toggler"      => array(),
					),
				) );

				if ( ! empty( $additional_fields ) ) {
					$fields = array_merge( $fields, $additional_fields );
				}

			} else {
				$fields = array(
					array(
						'id'       => 'whatsapp_notice',
						'type'     => 'notice',
						'class'    => '',
						'status'   => 'error',
						'message'  => __( 'WhatsApp service is not configured yet.', 'wp-marketing-automations' ),
						'dismiss'  => false,
						'required' => false,
						'toggler'  => array(),
					),
					array(
						'id'           => 'redirect_button',
						'label'        => __( 'Click to configure Whatsapp Connector', 'wp-marketing-automations' ),
						'type'         => 'redirect_button',
						'redirect_url' => ( 'admin.php?page=autonami&path=/connectors' ),
						'class'        => '',
						'newtab'       => '_self',
						'btntype'      => 'secondary',
						'required'     => false,
						'toggler'      => array(),
					)
				);
			}
		}

		return $fields;
	}

	/**
	 * Subscripe page button
	 *
	 * @param $page_mode
	 *
	 * @return string
	 */
	public static function get_unsubscribe_page_button( $page_mode = '' ) {
		$page_url     = '';
		$html         = '';
		$page_preview = '';
		$setting      = self::get_global_settings();
		if ( isset( $setting['bwfan_unsubscribe_page'] ) && ! empty( $setting['bwfan_unsubscribe_page'] ) ) {
			$page_url     = get_edit_post_link( $setting['bwfan_unsubscribe_page'] );
			$page_preview = get_permalink( $setting['bwfan_unsubscribe_page'] ) . '?bwf-preview=' . ( $page_mode === 'prebuild' ? 'prebuild' : 'custom' );
		}

		if ( ! empty( $page_preview ) || ! empty( $page_url ) ) {

			$html .= '<div class="bwf-display-flex bwf-flex-start gap-8">';
			if ( $page_mode === 'prebuild' ) {
				$html .= '<a class="components-button is-secondary btn-font2 bwf-o-button" href="' . admin_url( 'admin.php?page=autonami&path=/settings' ) . '" >' . __( 'Manage Branding', 'wp-marketing-automations' ) . '</a>';
			} else if ( $page_url ) {
				$html .= '<a class="components-button is-secondary btn-font2 bwf-o-button" href="' . $page_url . '" target="_blank">' . __( 'Edit Page', 'wp-marketing-automations' ) . '</a>';
			}
			if ( $page_preview && filter_var( $page_preview, FILTER_VALIDATE_URL ) ) {
				$html .= '<a class="components-button is-secondary btn-font2 bwf-o-button" href="' . esc_url( $page_preview ) . '" target="_blank">' . __( 'Preview', 'wp-marketing-automations' ) . '</a>';
			}
			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Returns manage profile page button
	 *
	 * @param string $page_mode
	 *
	 * @return string
	 */
	public static function get_manage_profile_button( $page_mode = '' ) {
		$page_url     = '';
		$html         = '';
		$setting      = self::get_global_settings();
		$page_preview = '';
		if ( isset( $setting['bwfan_profile_page'] ) && ! empty( $setting['bwfan_profile_page'] ) ) {
			$page_url     = get_edit_post_link( $setting['bwfan_profile_page'] );
			$page_preview = get_permalink( $setting['bwfan_profile_page'] ) . '?bwf-preview=' . ( $page_mode === 'prebuild' ? 'prebuild' : 'custom' );
		}

		if ( ! empty( $page_preview ) || ! empty( $page_url ) ) {
			$html .= '<div class="bwf-display-flex bwf-flex-start gap-8">';
			if ( $page_mode === 'prebuild' ) {
				$html .= '<a class="components-button is-secondary btn-font2 bwf-o-button" href="' . admin_url( 'admin.php?page=autonami&path=/settings' ) . '" >' . __( 'Manage Branding', 'wp-marketing-automations' ) . '</a>';
			} else if ( $page_url ) {
				$html .= '<a class="components-button is-secondary btn-font2 bwf-o-button" href="' . $page_url . '" target="_blank">' . __( 'Edit Page', 'wp-marketing-automations' ) . '</a>';
			}
			if ( $page_preview && filter_var( $page_preview, FILTER_VALIDATE_URL ) ) {
				$html .= '<a class="components-button is-secondary btn-font2 bwf-o-button" href="' . esc_url( $page_preview ) . '" target="_blank">' . __( 'Preview', 'wp-marketing-automations' ) . '</a>';
			}
			$html .= '</div>';
		}

		return $html;
	}

	public static function get_fields_for_preferences() {

		$custom_fields = BWFCRM_Fields::get_fields( null, 1 );
		if ( empty( $custom_fields ) ) {
			return array();
		}

		$custom_fields_array = [
			'email' => __( 'Email', 'wp-marketing-automations' )
		];
		foreach ( $custom_fields as $key => $field ) {
			if ( $key === 'status' ) {
				continue;
			}

			if ( is_array( $field ) ) {
				$custom_fields_array[ $field['slug'] ] = $field['name'];
				continue;
			}
			$custom_fields_array[ $key ] = $field;
		}

		return $custom_fields_array;
	}

	public static function get_tags_for_preferences() {

		$tags = BWFAN_Model_Terms::get_all( BWFCRM_Term_Type::$TAG );
		if ( empty( $tags ) ) {
			return array();
		}

		$tags_array = array();
		foreach ( $tags as $tag ) {
			if ( empty( $tag['name'] ) ) {
				continue;
			}
			$tags_array[ $tag['ID'] ] = $tag['name'];
		}

		return $tags_array;
	}

	/**
	 * Validate manage profile page and its content
	 *
	 * @return array
	 */
	public static function is_profile_page_valid() {
		$arr     = [ 'status' => 0, 'message' => '' ];
		$setting = self::get_global_settings();

		/** Check if profile page is set */
		if ( ! isset( $setting['bwfan_profile_page'] ) || empty( $setting['bwfan_profile_page'] ) ) {
			return $arr;
		}

		$post = get_post( $setting['bwfan_profile_page'] );
		if ( ! $post instanceof WP_Post ) {
			return [
				'status'  => 3,
				'message' => __( 'In the settings of the Contact page, the selected Contact Profile page is no longer available. Please assign a new page for it to function correctly.', 'wp-marketing-automations' )
			];
		}

		// Checking if page is not published
		if ( 'publish' !== $post->post_status ) {
			return [
				'status'  => 3,
				'message' => __( 'The Contact Profile page must be published and accessible to all users.', 'wp-marketing-automations' ),
			];
		}

		/** No need of shortcode if prebuild mode is enabled */
		if ( ! empty( $setting['bwfan_profile_page_type'] ) && 'prebuild' === $setting['bwfan_profile_page_type'] ) {
			return [ 'status' => 1, 'message' => '' ]; // success.
		}

		$content       = self::get_page_content( $post );
		$has_shortcode = has_shortcode( $content, 'fka_contact_profile_form' ) || strpos( $content, '[fka_contact_profile_form' ) !== false;
		if ( empty( $content ) || ! $has_shortcode ) {
			return [
				'status'  => 2,
				'message' => __( 'The selected page for your Contact Profile is missing the necessary shortcode. Please add the required shortcode to the page for it to function correctly.', 'wp-marketing-automations' )
			];
		}

		return [ 'status' => 1, 'message' => '' ]; // success
	}

	/**
	 * Validate subscribe page and its content
	 *
	 * @return array
	 */
	public static function is_unsubscribe_page_valid() {
		$arr     = [ 'status' => 0, 'message' => '' ];
		$setting = self::get_global_settings();

		/** Check if unsubscribe page is set */
		if ( ! isset( $setting['bwfan_unsubscribe_page'] ) || empty( $setting['bwfan_unsubscribe_page'] ) ) {
			return $arr;
		}

		$post = get_post( $setting['bwfan_unsubscribe_page'] );
		if ( ! $post instanceof WP_Post ) {
			return [
				'status'  => 3,
				'message' => __( 'The selected Subscribe Page no longer exists. Please choose a valid page.', 'wp-marketing-automations' ),
			];
		}

		// Checking if page is not published
		if ( 'publish' !== $post->post_status ) {
			return [
				'status'  => 3,
				'message' => __( 'The subscribe page must be published and accessible to all users.', 'wp-marketing-automations' ),
			];
		}

		/** No need of shortcode if prebuild mode is enabled */
		if ( ! empty( $setting['bwfan_unsubscribe_page_type'] ) && 'prebuild' === $setting['bwfan_unsubscribe_page_type'] ) {
			return [ 'status' => 1, 'message' => '' ]; // success
		}

		$content       = self::get_page_content( $post );
		$has_shortcode = ( has_shortcode( $content, 'wfan_unsubscribe_button' ) || has_shortcode( $content, 'bwfan_unsubscribe_button' ) || has_shortcode( $content, 'fka_contact_subscribe_form' ) || has_shortcode( $content, 'fka_unsubscribe_button' ) || strpos( $content, 'id="bwfan_unsubscribe"' ) !== false || false !== strpos( $content, '[fka_contact_subscribe_form' ) || false !== strpos( $content, '[bwfan_unsubscribe_button' ) || false !== strpos( $content, '[wfan_unsubscribe_button' ) || false !== strpos( $content, '[fka_unsubscribe_button' ) );

		if ( empty( $content ) || ! $has_shortcode ) {
			return [
				'status'  => 2,
				'message' => __( 'The selected page for your Subscribe Page is missing the necessary shortcode. Please add the required shortcode to the page for it to function correctly.', 'wp-marketing-automations' ),
			];
		}

		return [ 'status' => 1, 'message' => '' ];
	}

	public static function get_page_content( $post ) {
		$content = $post->post_content;

		/** Check if Bricks is active AND this specific page was built with Bricks */
		if ( defined( 'BRICKS_VERSION' ) && defined( 'BRICKS_DB_PAGE_CONTENT' ) ) {
			$bricks_data = get_post_meta( $post->ID, BRICKS_DB_PAGE_CONTENT, true );
			if ( ! empty( $bricks_data ) ) {
				return self::get_bricks_content( $post->ID );
			}
		}

		/** If Breakdance */
		if ( bwfan_is_breakdance_active() ) {
			$breakdance_content = self::get_breakdance_content( $post->ID );
			if ( ! empty( $breakdance_content ) ) {
				return $breakdance_content;
			}
		}

		/** If Oxygen Builder (layout in post meta, not post_content) */
		if ( bwfan_is_oxygen_builder_active() ) {
			$oxygen_content = self::get_oxygen_builder_content( $post->ID );
			if ( ! empty( $oxygen_content ) ) {
				return trim( $content . ' ' . $oxygen_content );
			}
		}

		return $content;
	}

	/**
	 * Collect shortcode strings from Oxygen's ct_builder_json / ct_builder_shortcodes for page validation.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string Space-concatenated shortcodes and legacy builder string (may be empty).
	 */
	public static function get_oxygen_builder_content( $post_id ) {
		if ( empty( $post_id ) ) {
			return '';
		}

		$out = '';

		// Oxygen saves as _ct_builder_json / _ct_builder_shortcodes (see oxy_get_meta_prefix in Oxygen).
		$json = get_post_meta( $post_id, '_ct_builder_json', true );
		if ( empty( $json ) ) {
			$json = get_post_meta( $post_id, 'ct_builder_json', true );
		}
		if ( ! empty( $json ) && is_string( $json ) ) {
			$tree = json_decode( wp_unslash( $json ), true );
			if ( is_array( $tree ) && ! empty( $tree['children'] ) && is_array( $tree['children'] ) ) {
				foreach ( $tree['children'] as $child ) {
					$out .= self::extract_shortcodes_from_oxygen_tree( $child );
				}
			}
		}

		$shortcodes_meta = get_post_meta( $post_id, '_ct_builder_shortcodes', true );
		if ( empty( $shortcodes_meta ) ) {
			$shortcodes_meta = get_post_meta( $post_id, 'ct_builder_shortcodes', true );
		}
		if ( ! empty( $shortcodes_meta ) && is_string( $shortcodes_meta ) ) {
			$out .= ' ' . wp_unslash( $shortcodes_meta );
		}

		return trim( $out );
	}

	/**
	 * Recursively read Oxygen component tree for Shortcode elements (ct_shortcode) and optional ct_content.
	 *
	 * @param array $node Single component node.
	 *
	 * @return string
	 */
	private static function extract_shortcodes_from_oxygen_tree( $node ) {
		if ( ! is_array( $node ) ) {
			return '';
		}

		$shortcodes = '';

		if ( ! empty( $node['name'] ) && 'ct_shortcode' === $node['name'] && ! empty( $node['options']['original']['full_shortcode'] ) && is_string( $node['options']['original']['full_shortcode'] ) ) {
			$shortcodes .= $node['options']['original']['full_shortcode'] . ' ';
		}

		if ( ! empty( $node['options']['ct_content'] ) && is_string( $node['options']['ct_content'] ) && false !== strpos( $node['options']['ct_content'], '[' ) ) {
			$shortcodes .= $node['options']['ct_content'] . ' ';
		}

		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				$shortcodes .= self::extract_shortcodes_from_oxygen_tree( $child );
			}
		}

		return $shortcodes;
	}

	public static function get_bricks_content( $post_id ) {
		if ( ! defined( 'BRICKS_DB_PAGE_CONTENT' ) ) {
			return '';
		}

		$bricks_data = get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );

		return ! empty( $bricks_data ) ? self::convert_bricks_to_html( $bricks_data ) : '';
	}

	/**
	 * Recursively converts Bricks page builder elements data structure into an HTML string.
	 *
	 * @param array $elements The Bricks page builder elements data structure.
	 *
	 * @return string The generated HTML content.
	 */
	public static function convert_bricks_to_html( $elements ) {
		$content          = '';
		$shortcode_fields = [ 'text', 'content', 'code', 'shortcode' ];

		foreach ( $elements as $element ) {
			if ( ! empty( $element['settings'] ) ) {
				foreach ( $shortcode_fields as $field ) {
					if ( ! empty( $element['settings'][ $field ] ) ) {
						$content .= $element['settings'][ $field ] . ' ';
					}
				}
			}

			/** Check URL parameters for shortcodes (e.g., in buttons) */
			if ( ! empty( $element['settings']['link'] ) && ! empty( $element['settings']['link']['url'] ) ) {
				$content .= $element['settings']['link']['url'] . ' ';
			}

			/** Recursively process nested elements */
			if ( ! empty( $element['children'] ) ) {
				$content .= self::convert_bricks_to_html( $element['children'] );
			}
		}

		return $content;
	}

	/**
	 * Get the Breakdance post content
	 *
	 * @param $page_id
	 *
	 * @return string
	 */
	public static function get_breakdance_content( $page_id ) {
		if ( empty( $page_id ) ) {
			return '';
		}

		$data = get_post_meta( $page_id, '_breakdance_data', true );
		if ( empty( $data ) ) {
			return '';
		}

		$decoded_data = json_decode( $data, true );
		$tree_json    = $decoded_data['tree_json_string'] ?? '';
		if ( empty( $tree_json ) ) {
			return '';
		}

		$tree_data = json_decode( $tree_json, true );

		return ! empty( $tree_data['root']['children'] ) ? self::extract_shortcodes_from_breakdance( $tree_data['root']['children'] ) : '';
	}

	/**
	 * Extract shortcodes from Breakdance JSON tree.
	 *
	 * @param array $children
	 *
	 * @return string
	 */
	private static function extract_shortcodes_from_breakdance( $children ) {
		$shortcodes = '';
		foreach ( $children as $child ) {
			if ( isset( $child['data']['type'] ) && $child['data']['type'] === 'EssentialElements\\Shortcode' ) {
				$shortcodes .= $child['data']['properties']['content']['shortcode']['full_shortcode'] ?? '';
			}
			if ( ! empty( $child['children'] ) ) {
				$shortcodes .= self::extract_shortcodes_from_breakdance( $child['children'] );
			}
		}

		return $shortcodes;
	}

	/**
	 * Get Unsubscribe Page Schema
	 *
	 * @return array
	 */
	public static function get_unsubscribe_page_schema() {
		$settings = self::get_global_settings();
		$page     = ! empty( $settings['bwfan_unsubscribe_page'] ) ? $settings['bwfan_unsubscribe_page'] : null;

		return array(
			array(
				'id'         => 'bwfan_unsubscribe_page',
				'label'      => __( 'Page', 'wp-marketing-automations' ),
				'type'       => 'ajax',
				'multiple'   => false,
				'class'      => 'bwfan-unsubscribe-page',
				'options'    => array(),
				'required'   => true,
				'validation' => self::is_unsubscribe_page_valid(),
				'ajax_cb'    => 'bwfan_select_unsubscribe_page',
				"ajax_field" => [
					'bwf_page_nonce' => wp_create_nonce( 'bwf_page_nonce' )
				],
				'toggler'    => array(),
			),
			array(
				'id'       => 'bwfan_unsubscribe_page_type',
				'label'    => __( 'Page Type', 'wp-marketing-automations' ),
				'type'     => 'radio',
				'options'  => [
					[
						'label'   => __( "Pre-built Page", 'wp-marketing-automations' ),
						'value'   => 'prebuild',
						'tooltip' => ""
					],
					[
						'label'   => __( "Custom Page", 'wp-marketing-automations' ),
						'value'   => 'custom',
						'tooltip' => ""
					]
				],
				'required' => false,
			),
			array(
				'id'       => 'bwfan_unsubscribe_page_custom_text',
				'type'     => 'para',
				'class'    => 'bwfan-unsubscribe-page-text bwf-mt-12-i',
				'children' => __( 'Take control of your page’s design and content. You can easily adjust everything to match your style and needs, from layout to colors and text.', 'wp-marketing-automations' ),
				'toggler'  => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_unsubscribe_page_type',
							'value' => 'custom',
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'       => 'bwfan_unsubscribe_page_prebuild_text',
				'type'     => 'para',
				'class'    => 'bwfan-unsubscribe-page-text bwf-mt-12-i',
				'children' => __( 'Distraction-Free Mode simplifies the page by showing only essential content. It removes extra elements like navigation bars, ads, and side panels.', 'wp-marketing-automations' ) . ' <a href="https://funnelkit.com/docs/autonami-2/settings/subscribe-page/" target="_blank">' . __( 'View Doc', 'wp-marketing-automations' ) . '</a>',
				'isHtml'   => true,
				'toggler'  => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_unsubscribe_page_type',
							'value' => 'prebuild',
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'      => 'bwfan_unsubscribe_page_shortcode_text',
				'type'    => 'shortcodelist',
				'class'   => 'bwfan-unsubscribe-page-text bwf-mt-12-i',
				'label'   => '',
				'heading' => __( 'Use dynamic shortcodes on the page', 'wp-marketing-automations' ),
				'data'    => [
					[
						'label' => __( 'Contact Email', 'wp-marketing-automations' ),
						'value' => '[fka_contact_email]',
					],
					[
						'label' => __( 'Contact Name', 'wp-marketing-automations' ),
						'value' => '[fka_contact_name]',
					],
					[
						'label' => __( 'Contact First Name', 'wp-marketing-automations' ),
						'value' => '[fka_contact_first_name]',
					],
					[
						'label' => __( 'Contact Last Name', 'wp-marketing-automations' ),
						'value' => '[fka_contact_last_name]',
					],
					[
						'label' => __( 'Contact Subscribe Form', 'wp-marketing-automations' ),
						'value' => '[fka_contact_subscribe_form label="' . __( 'Update my preference', 'wp-marketing-automations' ) . '"]',
					],

				],
				'toggler' => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_unsubscribe_page_type',
							'value' => 'custom',
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'         => 'bwfan_unsubscribe_logo_notice',
				'type'       => 'notice',
				'status'     => 'warning',
				'class'      => 'bwfan-unsubscribe-logo-notice bwfan-setting-in-notice',
				/* translators: %s: Link to update the brand logo */
				'message'    => '<b>' . __( 'Alert', 'wp-marketing-automations' ) . ':</b> ' . sprintf( __( " No logo is currently displayed on your Subscribe page. Please update your logo to enhance your contact's appearance.  %s", 'wp-marketing-automations' ), "<a href='" . admin_url( '/admin.php?page=autonami&path=/settings/' ) . "' class='bwf-normal-a-t bwf-t-underline'>" . __( 'Update Brand Logo', 'wp-marketing-automations' ) . "</a>" ),
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'isHtml'     => true,
				'isProField' => true,
				'toggler'    => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_setting_business_logo',
							'value' => false,
						),
						array(
							'id'    => 'bwfan_unsubscribe_page_type',
							'value' => 'prebuild',
						)
					),
					'relation' => 'AND'
				),
			),
			array(
				'id'       => 'bwfan_unsubscribe_page_links',
				'type'     => 'para',
				'class'    => 'bwfan-unsubscribe-page-type',
				'isHtml'   => true,
				'children' => self::get_unsubscribe_page_button(),
				'toggler'  => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_unsubscribe_page',
							'value' => $page,
						),
						array(
							'id'    => 'bwfan_unsubscribe_page_type',
							'value' => 'custom',
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'       => 'bwfan_unsubscribe_page_links_prebuild',
				'type'     => 'para',
				'class'    => 'bwfan-unsubscribe-page-type',
				'isHtml'   => true,
				'children' => self::get_unsubscribe_page_button( 'prebuild' ),
				'toggler'  => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_unsubscribe_page',
							'value' => $page,
						),
						array(
							'id'    => 'bwfan_unsubscribe_page_type',
							'value' => 'prebuild',
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'         => 'bwfan_unsubscribe_page_notice',
				'type'       => 'notice',
				'status'     => 'warning',
				'class'      => 'bwfan_unsubscribe_page_notice bwfan-setting-in-notice',
				'message'    => '<b>' . __( 'Alert', 'wp-marketing-automations' ) . ':</b> ' . __( "Page links are available once you save the page settings.", 'wp-marketing-automations' ),
				'isProField' => true,
				'isHtml'     => true,
				'toggler'    => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_unsubscribe_page',
							'value' => $page,
						),
					),
					'relation' => 'NOT'
				),
			),
			array(
				'id'          => 'bwfan_unsubscribe_lists_enable',
				'label'       => __( 'Manage Lists', 'wp-marketing-automations' ),
				'type'        => 'toggle',
				'hint'        => __( 'Allow contacts to manage their lists', 'wp-marketing-automations' ),
				'class'       => 'bwf-tooglecontrol-settings',
				'wrap_before' => '<br/><h3>' . __( 'List Management', 'wp-marketing-automations' ) . '</h3>',
				'required'    => false,
			),
			array(
				'id'       => 'bwfan_unsubscribe_public_lists',
				'label'    => __( 'Select Lists', 'wp-marketing-automations' ),
				'type'     => 'checkbox_grid',
				'hint'     => __( 'The selected lists will be shown to contacts for managing their preferences', 'wp-marketing-automations' ),
				'class'    => 'bwfan_unsubscribe_public_lists',
				'options'  => self::get_lists_for_preferences(),
				'tip'      => __( 'Select the lists you want to show on the unsubscribe page', 'wp-marketing-automations' ),
				'required' => false,
				'toggler'  => array(
					'fields' => array(
						array(
							'id'    => 'bwfan_unsubscribe_lists_enable',
							'value' => true,
						),
					),
				),
			),
			array(
				'id'            => 'bwfan_unsubscribe_lists_visibility',
				'label'         => __( 'Filter List', 'wp-marketing-automations' ),
				'type'          => 'checkbox',
				'checkboxlabel' => __( 'Show contact their subscribed lists', 'wp-marketing-automations' ),
				'hint'          => __( 'If unchecked all the selected lists will be available to contacts', 'wp-marketing-automations' ),
				'class'         => 'bwfan_unsubscribe_lists_visibility',
				'required'      => false,
				'tip'           => __( 'If enabled, only the lists that the contact is subscribed to will be shown', 'wp-marketing-automations' ),
				'toggler'       => array(
					'fields' => array(
						array(
							'id'    => 'bwfan_unsubscribe_lists_enable',
							'value' => true,
						),
					),
				),
			),
			array(
				'id'          => 'bwfan_unsubscribe_from_all_label',
				'label'       => __( 'Unsubscribe All Lists Label', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_unsubscribe_from_all_label',
				'placeholder' => '',
				'required'    => false,
				'hint'        => __( 'Label for Unsubscribe from all list option', 'wp-marketing-automations' ),
				'wrap_before' => '<br/><h3>' . __( 'Customize Page Information', 'wp-marketing-automations' ) . '</h3>',
			),
			array(
				'id'          => 'bwfan_unsubscribe_from_all_description',
				'label'       => __( 'Unsubscribe All Lists Description', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_unsubscribe_from_all_description',
				'placeholder' => '',
				'required'    => false,
				'hint'        => __( 'Description for Unsubscribe from all list option', 'wp-marketing-automations' ),
			),
			array(
				'id'       => 'bwfan_unsubscribe_page_title',
				'label'    => __( 'Page Title', 'wp-marketing-automations' ),
				'type'     => 'text',
				'hint'     => '',
				'class'    => 'bwfan-unsubscribe-page-title',
				'required' => false,
			),
			array(
				'id'       => 'bwfan_unsubscribe_lists_text',
				'label'    => __( 'Lists Heading', 'wp-marketing-automations' ),
				'type'     => 'text',
				'hint'     => '',
				'class'    => 'bwfan-unsubscribe-lists-text',
				'required' => false,
			),
			array(
				'id'       => 'bwfan_unsubscribe_button_text',
				'label'    => __( 'Button', 'wp-marketing-automations' ),
				'type'     => 'text',
				'hint'     => __( 'Label on Unsubscribe submit button', 'wp-marketing-automations' ),
				'class'    => 'bwfan-profile-button-text',
				'required' => false,
				'toggler'  => array(
					'fields' => array(
						array(
							'id'    => 'bwfan_unsubscribe_page_type',
							'value' => 'prebuild',
						),
					),
				),
			),
			array(
				'id'          => 'bwfan_unsubscribe_data_success',
				'label'       => __( 'Confirmation Text', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => 'bwfan_unsubscribe_data_success',
				'placeholder' => '',
				'required'    => false,
				'hint'        => __( 'Confirmation message when lists subscription is updated', 'wp-marketing-automations' ),
				'toggler'     => array(),
			),
			array(
				'id'            => 'bwfan_unsubscribe_show_manage_link',
				'label'         => __( 'Show Contact Profile Button', 'wp-marketing-automations' ),
				'type'          => 'checkbox',
				'checkboxlabel' => __( 'Enable', 'wp-marketing-automations' ),
				'class'         => 'bwfan_unsubscribe_show_manage_link',
				'required'      => false,
				'toggler'       => array(
					'fields' => array(
						array(
							'id'    => 'bwfan_unsubscribe_page_type',
							'value' => 'prebuild',
						),
					),
				),
			),
		);
	}

	public static function get_manage_profile_schema() {
		$settings = self::get_global_settings();
		$page     = ! empty( $settings['bwfan_profile_page'] ) ? $settings['bwfan_profile_page'] : null;

		return array(
			array(
				'id'         => 'bwfan_profile_page',
				'label'      => __( 'Page', 'wp-marketing-automations' ),
				'type'       => 'ajax',
				'multiple'   => false,
				'class'      => 'bwfan-unsubscribe-page',
				'options'    => array(),
				'required'   => true,
				'validation' => self::is_profile_page_valid(),
				'ajax_cb'    => 'bwfan_select_unsubscribe_page',
				"ajax_field" => [
					'bwf_page_nonce' => wp_create_nonce( 'bwf_page_nonce' )
				],
				'toggler'    => array(),
			),
			array(
				'id'       => 'bwfan_profile_page_type',
				'label'    => __( 'Page Type', 'wp-marketing-automations' ),
				'type'     => 'radio',
				'options'  => [
					[
						'label'   => __( "Pre-built Page", 'wp-marketing-automations' ),
						'value'   => 'prebuild',
						'tooltip' => ""
					],
					[
						'label'   => __( "Custom Page", 'wp-marketing-automations' ),
						'value'   => 'custom',
						'tooltip' => ""
					]
				],
				'required' => false,
			),
			array(
				'id'       => 'bwfan_profile_page_custom_text',
				'type'     => 'para',
				'class'    => 'bwfan-profile-page-text bwf-mt-12-i',
				'children' => __( 'Take control of your page’s design and content. You can easily adjust everything to match your style and needs, from layout to colors and text.', 'wp-marketing-automations' ),
				'toggler'  => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_profile_page_type',
							'value' => 'custom',
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'       => 'bwfan_profile_page_prebuild_text',
				'type'     => 'para',
				'class'    => 'bwfan-profile-page-text bwf-mt-12-i',
				'children' => __( 'Distraction-Free Mode simplifies the page by showing only essential content. It removes extra elements like navigation bars, ads, and side panels.', 'wp-marketing-automations' ) . ' <a href="https://funnelkit.com/docs/autonami-2/settings/contact-pages/" target="_blank">' . __( 'View Doc', 'wp-marketing-automations' ) . '</a>',
				'isHtml'   => true,
				'toggler'  => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_profile_page_type',
							'value' => 'prebuild',
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'      => 'bwfan_profile_page_shortcode_text',
				'type'    => 'shortcodelist',
				'class'   => 'bwfan-profile-page-text bwf-mt-12-i',
				'label'   => '',
				'heading' => __( 'Use dynamic shortcodes on the page', 'wp-marketing-automations' ),
				'data'    => [
					[
						'label' => __( 'Contact Email', 'wp-marketing-automations' ),
						'value' => '[fka_contact_email]',
					],
					[
						'label' => __( 'Contact Name', 'wp-marketing-automations' ),
						'value' => '[fka_contact_name]',
					],
					[
						'label' => __( 'Contact First Name', 'wp-marketing-automations' ),
						'value' => '[fka_contact_first_name]',
					],
					[
						'label' => __( 'Contact Last Name', 'wp-marketing-automations' ),
						'value' => '[fka_contact_last_name]',
					],
					[
						'label' => __( 'Contact Profile Form', 'wp-marketing-automations' ),
						'value' => '[fka_contact_profile_form]',
					],

				],
				'toggler' => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_profile_page_type',
							'value' => 'custom',
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'         => 'bwfan_profile_logo_notice',
				'type'       => 'notice',
				'status'     => 'warning',
				'class'      => 'bwfan-profile-logo-notice bwfan-setting-in-notice',
				/* translators: %s: Link to update the brand logo */
				'message'    => '<b>' . __( 'Alert', 'wp-marketing-automations' ) . ':</b> ' . sprintf( __( " No logo is currently displayed on your Contact Profile page. Please update your logo to enhance your contact's appearance.  %s", 'wp-marketing-automations' ), "<a href='page=autonami&path=/settings/' class='bwf-normal-a-t bwf-t-underline'>" . __( 'Update Brand Logo', 'wp-marketing-automations' ) . "</a>" ),
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'isHtml'     => true,
				'isProField' => true,
				'toggler'    => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_setting_business_logo',
							'value' => false,
						),
						array(
							'id'    => 'bwfan_profile_page_type',
							'value' => 'prebuild',
						)
					),
					'relation' => 'AND'
				),
			),
			array(
				'id'       => 'bwfan_profile_page_links',
				'type'     => 'para',
				'class'    => 'bwfan-profile-page-type',
				'isHtml'   => true,
				'children' => self::get_manage_profile_button(),
				'toggler'  => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_profile_page',
							'value' => $page,
						),
						array(
							'id'    => 'bwfan_profile_page_type',
							'value' => 'custom',
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'       => 'bwfan_unsubscribe_page_links_prebuild',
				'type'     => 'para',
				'class'    => 'bwfan-unsubscribe-page-type',
				'isHtml'   => true,
				'children' => self::get_manage_profile_button( 'prebuild' ),
				'toggler'  => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_profile_page',
							'value' => $page,
						),
						array(
							'id'    => 'bwfan_profile_page_type',
							'value' => 'prebuild',
						),
					),
					'relation' => 'AND',
				),
			),
			array(
				'id'         => 'bwfan_profile_page_notice',
				'type'       => 'notice',
				'status'     => 'warning',
				'class'      => 'bwfan_profile_page_notice bwfan-setting-in-notice',
				'message'    => '<b>' . __( 'Alert', 'wp-marketing-automations' ) . ':</b> ' . __( "Page links are available once you save the page settings.", 'wp-marketing-automations' ),
				'isProField' => true,
				'isHtml'     => true,
				'toggler'    => array(
					'fields'   => array(
						array(
							'id'    => 'bwfan_profile_page',
							'value' => $page,
						),
					),
					'relation' => 'NOT'
				),
			),
			array(
				'id'          => 'bwfan_profile_manage_fields',
				'label'       => __( 'Fields', 'wp-marketing-automations' ),
				'type'        => 'checkbox_grid',
				'hint'        => '',
				'class'       => 'bwfan-profile-fields',
				'options'     => self::get_fields_for_preferences(),
				'required'    => false,
				'wrap_before' => '<br/><h3>' . __( 'Profile Management', 'wp-marketing-automations' ) . '</h3>',
			),
			array(
				'id'    => 'bwfan_enable_profile_lists',
				'label' => __( 'Lists', 'wp-marketing-automations' ),
				'type'  => 'toggle',
				'hint'  => __( 'Allow contacts to update their lists', 'wp-marketing-automations' ),
				'class' => 'bwf-tooglecontrol-settings',
			),
			array(
				'id'            => 'bwfan_enable_profile_list_description',
				'label'         => ' ',
				'type'          => 'checkbox',
				'checkboxlabel' => __( 'Show description', 'wp-marketing-automations' ),
				'class'         => 'bwfan-enable-lists-description',
				'required'      => false,
				'toggler'       => array(
					'fields' => array(
						array(
							'id'    => 'bwfan_enable_profile_lists',
							'value' => true,
						),
					),
				),
			),
			array(
				'id'       => 'bwfan_profile_lists',
				'label'    => ' ',
				'type'     => 'checkbox_grid',
				'hint'     => '',
				'class'    => 'bwfan_unsubscribe_public_lists',
				'options'  => self::get_lists_for_preferences(),
				'required' => false,
				'toggler'  => array(
					'fields' => array(
						array(
							'id'    => 'bwfan_enable_profile_lists',
							'value' => true,
						),
					),
				),
			),
			array(
				'id'       => 'bwfan_enable_profile_tags',
				'label'    => __( 'Tags', 'wp-marketing-automations' ),
				'hint'     => __( 'Allow contacts to update their tags', 'wp-marketing-automations' ),
				'class'    => 'bwf-tooglecontrol-settings',
				'type'     => 'toggle',
				'required' => false,
			),
			array(
				'id'       => 'bwfan_profile_tags',
				'label'    => ' ',
				'type'     => 'checkbox_grid',
				'class'    => 'bwfan-profile-tags',
				'options'  => self::get_tags_for_preferences(),
				'required' => false,
				'toggler'  => array(
					'fields' => array(
						array(
							'id'    => 'bwfan_enable_profile_tags',
							'value' => true,
						),
					),
				),
			),
			array(
				'id'          => 'bwfan_profile_page_title',
				'label'       => __( 'Page Title', 'wp-marketing-automations' ),
				'type'        => 'text',
				'hint'        => '',
				'class'       => 'bwfan-profile-page-title',
				'required'    => false,
				'wrap_before' => '<br/><h3>' . __( 'Customize Page Information ', 'wp-marketing-automations' ) . '</h3>',
			),
			array(
				'id'       => 'bwfan_profile_fields_text',
				'label'    => __( 'Fields Heading', 'wp-marketing-automations' ),
				'type'     => 'text',
				'hint'     => '',
				'class'    => 'bwfan-profile-fields-text',
				'required' => false,
			),
			array(
				'id'       => 'bwfan_profile_lists_text',
				'label'    => __( 'Lists Heading', 'wp-marketing-automations' ),
				'type'     => 'text',
				'hint'     => '',
				'class'    => 'bwfan-profile-lists-text',
				'required' => false,
			),
			array(
				'id'       => 'bwfan_profile_tags_text',
				'label'    => __( 'Tags Heading ', 'wp-marketing-automations' ),
				'type'     => 'text',
				'hint'     => '',
				'class'    => 'bwfan-profile-tags-text',
				'required' => false,
			),
			array(
				'id'       => 'bwfan_profile_button_text',
				'label'    => __( 'Submit Button', 'wp-marketing-automations' ),
				'type'     => 'text',
				'hint'     => '',
				'class'    => 'bwfan-profile-button-text',
				'required' => false,
			),
			array(
				'id'       => 'bwfan_profile_message_text',
				'label'    => __( 'Confirmation Message', 'wp-marketing-automations' ),
				'type'     => 'text',
				'hint'     => __( 'Confirmation message when information is updated.', 'wp-marketing-automations' ),
				'class'    => 'bwfan-profile-message-text',
				'required' => false,
			),
		);
	}

	public static function get_lists_for_preferences() {

		$lists = BWFAN_Model_Terms::get_all( BWFCRM_Term_Type::$LIST );
		if ( empty( $lists ) ) {
			return array();
		}

		$lists_array = array();
		foreach ( $lists as $list ) {
			if ( empty( $list['name'] ) ) {
				continue;
			}
			$lists_array[ $list['ID'] ] = $list['name'];
		}

		return $lists_array;
	}

	/**
	 * checking if all table created or not
	 */
	public static function checking_all_tables_exists() {
		global $wpdb;
		$result                  = true;
		$not_created_tables      = array();
		$mytables                = $wpdb->get_results( 'SHOW TABLES', ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables_array            = empty( $mytables ) ? [] : array_column( $mytables, 'Tables_in_' . $wpdb->dbname );
		$automations_table_array = self::get_tables_array();

		foreach ( $automations_table_array as $table ) {
			if ( ! in_array( $table, $tables_array, true ) ) {
				$not_created_tables[] = $table;
			}
		}

		if ( ! empty( $not_created_tables ) ) {
			return $not_created_tables;
		}

		return $result;
	}

	/** return all autonami tables
	 *
	 * @return mixed|void
	 */
	public static function get_tables_array() {
		global $wpdb;
		$automations_table_array = apply_filters( 'bwfan_automation_tables', array(
			$wpdb->prefix . 'bwfan_automations',
			$wpdb->prefix . 'bwfan_automationmeta',
			$wpdb->prefix . 'bwfan_tasks',
			$wpdb->prefix . 'bwfan_taskmeta',
			$wpdb->prefix . 'bwfan_task_claim',
			$wpdb->prefix . 'bwfan_logs',
			$wpdb->prefix . 'bwfan_logmeta',
			$wpdb->prefix . 'bwfan_message_unsubscribe',
			$wpdb->prefix . 'bwfan_contact_automations',
			$wpdb->prefix . 'bwfan_abandonedcarts',
			$wpdb->prefix . 'bwfan_import_export',
		) );

		sort( $automations_table_array );

		return $automations_table_array;
	}

	/**
	 * Get Format for Success Response
	 *
	 * @param $result_array
	 * @param string $message
	 * @param int $response_code
	 *
	 * @return array
	 */
	public static function format_success_response( $result_array, $message = '', $response_code = 200 ) {
		return array(
			'code'    => $response_code,
			'message' => $message,
			'result'  => $result_array,
		);
	}

	/** maybe create abandoned cart if enable
	 *
	 * @param $active_abandoned_cart
	 */
	public static function maybe_create_abandoned_contact( $active_abandoned_cart ) {
		$global_settings = self::get_global_settings();
		$abandoned_tag   = isset( $global_settings['bwfan_ab_tag_selector'] ) ? json_decode( $global_settings['bwfan_ab_tag_selector'], true ) : [];
		$abandoned_list  = isset( $global_settings['bwfan_ab_list_selector'] ) && is_array( $global_settings['bwfan_ab_list_selector'] ) ? $global_settings['bwfan_ab_list_selector'] : [];

		$abandoned_tag = array_map( function ( $tag ) {
			$tag['value'] = ! isset( $tag['value'] ) && isset( $tag['name'] ) ? $tag['name'] : '';
			unset( $tag['name'] );

			return $tag;
		}, $abandoned_tag );

		$abandoned_list = array_map( function ( $list ) {
			$list['value'] = ! isset( $list['value'] ) && isset( $list['name'] ) ? $list['name'] : '';
			unset( $list['name'] );

			return $list;
		}, $abandoned_list );

		if ( ! isset( $active_abandoned_cart['ID'] ) || empty( $active_abandoned_cart['ID'] ) ) {
			return;
		}

		$abandoned_id   = $active_abandoned_cart['ID'];
		$abandoned_data = BWFAN_Model_Abandonedcarts::get( $abandoned_id );
		if ( ! is_array( $abandoned_data ) ) {
			return;
		}

		$abandoned_user_id = $active_abandoned_cart['user_id'];
		$abandoned_email   = $abandoned_data['email'];
		if ( empty( $abandoned_email ) ) {
			return;
		}

		if ( empty( $abandoned_user_id ) ) {
			$abandoned_user_id = 0;
		}

		$contact = bwf_get_contact( $abandoned_user_id, $abandoned_email );
		if ( $contact instanceof WooFunnels_Contact && $contact->get_id() > 0 ) {
			$bwfcrm_contact = new BWFCRM_Contact( $contact ); // getting bwfcrm_contact object to add tags
			if ( ! empty( $abandoned_tag ) ) {
				$bwfcrm_contact->add_tags( $abandoned_tag );
			}

			if ( ! empty( $abandoned_list ) ) {
				$bwfcrm_contact->add_lists( $abandoned_list );
			}
		}

		if ( $abandoned_user_id > 0 ) {
			$wp_user         = get_user_by( 'id', $abandoned_user_id );
			$abandoned_email = $wp_user->user_email;
		}

		$abandoned_checkout_data = json_decode( $abandoned_data['checkout_data'], true );
		$pushengage_token        = $abandoned_checkout_data['pushengage_token'] ?? '';

		$bwf_contact = new WooFunnels_Contact( $abandoned_user_id, $abandoned_email );
		if ( ! empty( $abandoned_user_id ) ) {
			$bwf_contact->set_wpid( $abandoned_user_id );
		}

		$f_name     = self::get_field_with_fallback( $abandoned_checkout_data, 'first_name' );
		$l_name     = self::get_field_with_fallback( $abandoned_checkout_data, 'last_name' );
		$contact_no = self::get_field_with_fallback( $abandoned_checkout_data, 'phone' );
		$state      = self::get_field_with_fallback( $abandoned_checkout_data, 'state' );
		$country    = self::get_field_with_fallback( $abandoned_checkout_data, 'country' );
		$address_1  = self::get_field_with_fallback( $abandoned_checkout_data, 'address_1' );
		$address_2  = self::get_field_with_fallback( $abandoned_checkout_data, 'address_2' );
		$city       = self::get_field_with_fallback( $abandoned_checkout_data, 'city' );
		$postcode   = self::get_field_with_fallback( $abandoned_checkout_data, 'postcode' );
		$timezone   = $abandoned_checkout_data['fields']['timezone'] ?? '';

		if ( ! empty( $f_name ) ) {
			$bwf_contact->set_f_name( $f_name );
		}
		if ( ! empty( $l_name ) ) {
			$bwf_contact->set_l_name( $l_name );
		}
		if ( ! empty( $contact_no ) ) {
			$bwf_contact->set_contact_no( $contact_no );
		}
		if ( ! empty( $timezone ) ) {
			$bwf_contact->set_timezone( $timezone );
		}
		if ( ! empty( $state ) ) {
			$bwf_contact->set_state( $state );
		}
		if ( ! empty( $country ) ) {
			$bwf_contact->set_country( $country );
		}
		$bwf_contact->save();

		$bwfcrm_contact = new BWFCRM_Contact( $bwf_contact );  // getting bwfcrm_contact object to add tags

		if ( ! empty( $address_1 ) ) {
			$bwfcrm_contact->set_field_by_slug( 'address-1', $address_1 );
		}
		if ( ! empty( $address_2 ) ) {
			$bwfcrm_contact->set_field_by_slug( 'address-2', $address_2 );
		}
		if ( ! empty( $city ) ) {
			$bwfcrm_contact->set_field_by_slug( 'city', $city );
		}
		if ( ! empty( $postcode ) ) {
			$bwfcrm_contact->set_field_by_slug( 'postcode', $postcode );
		}
		if ( ! empty( $pushengage_token ) ) {
			$subs_tokens = $bwfcrm_contact->get_field_by_slug( 'push-engage-token' );
			$subs_tokens = json_decode( $subs_tokens, true );
			if ( empty( $subs_tokens ) || ! is_array( $subs_tokens ) ) {
				$subs_tokens = array();
			}

			if ( empty( $subs_tokens ) || ! in_array( $pushengage_token, $subs_tokens, true ) ) {
				$subs_tokens[] = $pushengage_token;
				$bwfcrm_contact->set_field_by_slug( 'push-engage-token', wp_json_encode( $subs_tokens ) );
			}
		}

		$year  = (int) ( $abandoned_checkout_data['fields']['dob_fields']['bwfan_birthday_date_yy'] ?? 0 );
		$month = (int) ( $abandoned_checkout_data['fields']['dob_fields']['bwfan_birthday_date_mm'] ?? 0 );
		$day   = (int) ( $abandoned_checkout_data['fields']['dob_fields']['bwfan_birthday_date_dd'] ?? 0 );
		$dob   = '';
		if ( $year >= 1900 && $year <= 2100 && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && checkdate( $month, $day, $year ) ) {
			$dob = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		}

		if ( ! empty( $dob ) ) {
			$bwfcrm_contact->set_field_by_slug( 'dob', $dob );
		}

		$bwfcrm_contact->save();
		if ( ! empty( $city ) || ! empty( $address_1 ) || ! empty( $address_2 ) || ! empty( $postcode ) || ! empty( $pushengage_token ) || ! empty( $dob ) ) {
			$bwfcrm_contact->save_fields();
		}
	}

	/**
	 * Get field value of checkout form field with fallback to shipping if billing is not available
	 *
	 * @param $cart_data
	 * @param $key
	 *
	 * @return mixed|string
	 */
	public static function get_field_with_fallback( $cart_data, $key ) {
		if ( empty( $cart_data ) || ! is_array( $cart_data ) || empty( $key ) ) {
			return '';
		}
		$value = $cart_data['fields'][ 'billing_' . $key ] ?? '';
		if ( ! empty( $value ) ) {
			return $value;
		}

		return $cart_data['fields'][ 'shipping_' . $key ] ?? '';
	}

	/**
	 * remove abandoned cart tags on cart recovered
	 *
	 * @param $order
	 */
	public static function bwfan_remove_abandoned_cart_tags( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		/** If pro not found */
		if ( ! bwfan_is_autonami_pro_active() || ! class_exists( 'BWFCRM_Contact' ) ) {
			return;
		}

		$global_settings        = self::get_global_settings();
		$removed_abandoned_tags = isset( $global_settings['bwfan_ab_tag_selector'] ) ? json_decode( $global_settings['bwfan_ab_tag_selector'], true ) : [];
		$removed_abandoned_list = isset( $global_settings['bwfan_ab_list_selector'] ) ? $global_settings['bwfan_ab_list_selector'] : [];

		if ( empty( $removed_abandoned_tags ) && empty( $removed_abandoned_list ) ) {
			return;
		}

		$remove_tag_data = $remove_list_data = array();

		if ( ! empty( $removed_abandoned_tags ) ) {
			foreach ( $removed_abandoned_tags as $remove_tag ) {
				$tag_id = $remove_tag['id'];
				if ( 0 === absint( $tag_id ) && bwfan_is_autonami_pro_active() ) {
					$tag_id = empty( $remove_tag['id'] ) ? BWFCRM_Term::get_terms( 1, [], $remove_tag['name'], 0, 0, ARRAY_A, 'exact' ) : $remove_tag['id'];
				}
				$remove_tag_data[] = is_array( $tag_id ) && isset( $tag_id[0]['ID'] ) ? $tag_id[0]['ID'] : $tag_id;
			}
		}

		if ( ! empty( $removed_abandoned_list ) ) {
			foreach ( $removed_abandoned_list as $remove_tag ) {
				$remove_list_data[] = $remove_tag['id'];
			}
		}

		$cid = $order->get_meta( '_woofunnel_cid' );
		if ( empty( $cid ) ) {
			return;
		}

		$bwfcrm_contact = new BWFCRM_Contact( $cid );
		if ( ! $bwfcrm_contact->is_contact_exists() ) {
			return;
		}

		if ( ! empty( $remove_tag_data ) ) {
			$bwfcrm_contact->remove_tags( $remove_tag_data );
		}

		if ( ! empty( $remove_list_data ) ) {
			$bwfcrm_contact->remove_lists( $remove_list_data );
		}

		$bwfcrm_contact->save();
	}

	public static function get_carts_count() {
		$recoverable_count = BWFAN_Recoverable_Carts::get_abandoned_carts( '', '', '', '', '', true );
		$recovered_count   = BWFAN_Recoverable_Carts::get_recovered_carts( '', '', '', true );
		$lost_carts        = BWFAN_Recoverable_Carts::get_abandoned_carts( '', '', '', '', 2, true );

		return [
			'carts_recoverable' => absint( $recoverable_count['total_count'] ) > 0 ? absint( $recoverable_count['total_count'] ) : 0,
			'carts_recovered'   => absint( $recovered_count['total_count'] ) > 0 ? absint( $recovered_count['total_count'] ) : 0,
			'carts_lost'        => absint( $lost_carts['total_count'] ) > 0 ? absint( $lost_carts['total_count'] ) : 0,
		];
	}

	public static function get_automation_data_count( $version = 1 ) {
		$automation_count = self::get_all_automations( '', 'all', 0, 0, true, $version );
		$active           = self::get_all_automations( '', '1', 0, 0, true, $version );
		$inactive         = absint( $automation_count['total_records'] ) - absint( $active['total_records'] );

		if ( 2 === intval( $version ) ) {
			return [
				'automations'      => absint( $automation_count['total_records'] ),
				'contact_activity' => absint( BWFAN_Model_Automation_Contact::get_active_count( '', 2, '', '' ) ),
				'status_count'     => [
					'active'   => absint( $active['total_records'] ),
					'inactive' => $inactive,
				]
			];
		}

		$scheduled_count    = BWFAN_Core()->tasks->fetch_tasks_count( 0, 0 );
		$paused_count       = BWFAN_Core()->tasks->fetch_tasks_count( 0, 1 );
		$completed_count    = BWFAN_Core()->logs->fetch_logs_count( 1 );
		$failed_count       = BWFAN_Core()->logs->fetch_logs_count( 0 );
		$task_history_count = $scheduled_count + $paused_count + $completed_count + $failed_count;

		return [
			'automations'      => absint( $automation_count['total_records'] ),
			'contact_activity' => absint( BWFAN_Model_Automation_Contact::get_active_count( '', 2, '', '' ) ),
			'status_count'     => [
				'active'   => absint( $active['total_records'] ),
				'inactive' => $inactive,
			],
			'task_history'     => $task_history_count
		];
	}

	/** get all automations data for the api
	 *
	 * @param string $status
	 * @param int $offset
	 * @param int $limit
	 *
	 * @return array
	 */
	public static function get_all_automations( $search, $status = 'all', $offset = 0, $limit = 25, $only_count = false, $version = 0, $category = '' ) {
		global $wpdb;
		$automations_table = $wpdb->prefix . 'bwfan_automations';
		$base_query        = array();
		$count_query       = array();
		$base_query[]      = "SELECT  distinct a.* FROM $automations_table as a LEFT JOIN {$wpdb->prefix}bwfan_automationmeta as am ON a.ID = am.bwfan_automation_id where 1=1 ";
		$count_query[]     = "SELECT count(DISTINCT a.ID) FROM $automations_table as a LEFT JOIN {$wpdb->prefix}bwfan_automationmeta as am ON a.ID = am.bwfan_automation_id where 1=1 ";

		if ( ! empty( $search ) && $only_count === false ) {
			$search = "%$search%";
			if ( 2 === absint( $version ) ) {
				$search_query = $wpdb->prepare( " AND a.title LIKE %s", $search );
			} else {
				$search_query = $wpdb->prepare( " AND am.meta_key='title' AND am.meta_value like %s", $search );
			}
			$base_query[]  = $search_query;
			$count_query[] = $search_query;
		}

		if ( intval( $version ) !== 0 ) {
			$version_query = $wpdb->prepare( ' AND a.v = %d ', $version );
			$base_query[]  = $version_query;
			$count_query[] = $version_query;
		}

		if ( $status !== 'all' ) {
			$status_query  = $wpdb->prepare( ' AND a.status = %d', $status );
			$base_query[]  = $status_query;
			$count_query[] = $status_query;
		}

		if ( ! empty( $category ) ) {
			$category_ids = array_map( 'absint', explode( ',', $category ) );
			$category_ids = array_filter( $category_ids ); // Remove any invalid values
			$conditions   = [];
			foreach ( $category_ids as $category_id ) {
				// absint() ensures numeric value, so no LIKE wildcards to escape
				$pattern      = '%"' . absint( $category_id ) . '"%';
				$conditions[] = $wpdb->prepare( "a.category LIKE %s", $pattern );
			}
			if ( ! empty( $conditions ) ) {
				$category_filter = "AND (" . implode( ' OR ', $conditions ) . ")";

				$base_query[]  = $category_filter;
				$count_query[] = $category_filter;
			}
		}

		$base_query[] = $wpdb->prepare( ' ORDER BY a.ID DESC LIMIT %d OFFSET %d', $limit, $offset );
		if ( $only_count === false ) {
			$all_automations = self::get_db_cache_data( implode( ' ', $base_query ) );
			if ( false === $all_automations ) {
				$all_automations = $wpdb->get_results( implode( ' ', $base_query ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- query built from prefix tables and prepare() fragments
				self::set_db_cache_data( implode( ' ', $base_query ), $all_automations );
			}
		}
		$overall_total = self::get_db_cache_data( implode( ' ', $count_query ) );
		if ( false === $overall_total ) {
			$overall_total = $wpdb->get_var( implode( ' ', $count_query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- query built from prefix tables and prepare() fragments
			self::set_db_cache_data( implode( ' ', $count_query ), $overall_total );
		}

		if ( $only_count === true ) {
			return array(
				'automations'   => array(),
				'total_records' => $overall_total,
			);
		}

		if ( empty( $all_automations ) ) {
			return array(
				'automations'   => array(),
				'total_records' => 0,
			);
		}

		$final_automation_data = array();
		$date_format           = self::get_date_format();

		$automation_ids = array_map( function ( $all_automation ) {
			return isset( $all_automation['ID'] ) ? absint( $all_automation['ID'] ) : false;
		}, $all_automations );

		$automation_ids     = array_filter( $automation_ids, 'intval' );
		$stringPlaceholders = array_fill( 0, count( $automation_ids ), '%d' );
		$stringPlaceholders = implode( ', ', $stringPlaceholders );


		/** Get all automation revenue total if pro active */
		$conversion_data = array();
		$query           = $wpdb->prepare( "SELECT bwc.oid, count(bwc.ID) as conversions, SUM(bwc.wctotal) as revenue FROM {$wpdb->prefix}bwfan_conversions as bwc JOIN {$wpdb->prefix}posts as p ON bwc.wcid=p.ID WHERE bwc.oid IN ( $stringPlaceholders ) AND bwc.otype=1 GROUP BY bwc.oid", $automation_ids );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders

		$conversions = self::get_db_cache_data( $query );
		if ( false === $conversions ) {
			$conversions = $wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			self::set_db_cache_data( $query, $conversions );
		}

		foreach ( $conversions as $conversion ) {
			if ( absint( $conversion['oid'] ) ) {
				$conversion_data[ absint( $conversion['oid'] ) ] = $conversion;
			}
		}

		$automations_meta = BWFAN_Model_Automationmeta::get_automations_meta( $automation_ids );

		foreach ( $all_automations as $automation ) {
			$id                 = absint( $automation['ID'] );
			$automation_actions = isset( $automations_meta[ $id ]['actions'] ) ? self::get_automation_actions( $automations_meta[ $id ]['actions'] ) : array();
			$source             = self::get_automation_source_name( $automation['source'] );
			$event              = self::get_automation_event_name( $automation['event'] );
			$run_count          = ! empty( $automations_meta[ $id ]['run_count'] ) ? $automations_meta[ $id ]['run_count'] : 0;
			$category_ids       = [];
			if ( ! empty( $automation['category'] ) && self::is_json( $automation['category'] ) ) {
				$decoded      = json_decode( $automation['category'], true );
				$category_ids = is_array( $decoded ) ? $decoded : [];
			}
			$data = array(
				'id'                 => $id,
				'source'             => empty( $source ) ? __( 'Not Found', 'wp-marketing-automations' ) : $source,
				'last_update'        => isset( $automations_meta[ $id ]['m_date'] ) ? get_date_from_gmt( $automations_meta[ $id ]['m_date'], $date_format ) : '',
				'name'               => 2 === absint( $automation['v'] ) && isset( $automation['title'] ) ? $automation['title'] : ( isset( $automations_meta[ $id ]['title'] ) ? $automations_meta[ $id ]['title'] : '' ),
				'event'              => $event,
				'status'             => $automation['status'],
				'priority'           => $automation['priority'],
				'automation_actions' => $automation_actions,
				'run_count'          => $run_count,
				'conversions'        => 0,
				'revenue'            => 0,
				'migrated'           => isset( $automations_meta[ $id ]['v1_migrate'] ) ? true : false,
				'v'                  => isset( $automation['v'] ) ? $automation['v'] : 1,
				'category'           => ( class_exists( 'BWFCRM_Category' ) && method_exists( 'BWFCRM_Category', 'get_category_by_id' ) && isset( BWFCRM_Category::$AUTOMATION ) ) ? BWFCRM_Category::get_category_by_id( BWFCRM_Category::$AUTOMATION, $category_ids ) : [],
			);

			if ( isset( $conversion_data[ $id ] ) ) {
				$data = array_replace( $data, $conversion_data[ $id ] );
			}

			$final_automation_data[ $id ] = $data;
		}

		return array(
			'automations'   => ! empty( $final_automation_data ) && is_array( $final_automation_data ) ? array_values( $final_automation_data ) : array(),
			'total_records' => ! empty( $overall_total ) ? absint( $overall_total ) : 0,
		);
	}

	/**
	 * @param $automation_actions
	 *
	 * @return array
	 */
	public static function get_automation_actions( $automation_actions ) {
		$actions = array();
		if ( empty( $automation_actions ) ) {
			return array();
		}

		$integration_data = $automation_actions;
		$unique_actions   = BWFAN_Core()->automations->get_unique_automation_actions( $integration_data );

		foreach ( $unique_actions as $action => $integration ) {
			$action_obj      = BWFAN_Core()->integration->get_action( $action );
			$integration_obj = BWFAN_Core()->integration->get_integration( $integration );
			if ( $integration_obj instanceof BWFAN_Integration && $action_obj instanceof BWFAN_Action ) {
				$nice_name               = $integration_obj->get_name();
				$actions[ $nice_name ][] = $action_obj->get_name();
			} else {
				$integration_name = self::get_entity_nice_name( 'integration', $integration );
				$action_name      = self::get_entity_nice_name( 'action', $action );
				if ( ! empty( $integration_name ) && ! empty( $action_name ) ) {
					$actions[ $integration_name ][] = $action_name;
				}
			}
		}

		return $actions;
	}

	public static function get_entity_nice_name( $key = 'source', $slug = 'wc' ) {
		$nice_names = self::get_default_event_action_names();
		if ( empty( $key ) || empty( $slug ) ) {
			return '';
		}
		if ( ! isset( $nice_names[ $key ] ) || ! isset( $nice_names[ $key ][ $slug ] ) ) {
			return '';
		}

		return $nice_names[ $key ][ $slug ];
	}

	/**
	 * Return Sources, Events, Integrations, Actions nice names.
	 * Useful when respective entity not exist but data as task or automation available.
	 *
	 * @return array
	 */
	public static function get_default_event_action_names() {
		return array(
			'source'      => array(
				'wc'             => __( 'WooCommerce', 'wp-marketing-automations' ),
				'wp'             => __( 'WordPress', 'wp-marketing-automations' ),
				'wcs'            => __( 'WooCommerce Subscription', 'wp-marketing-automations' ),
				'upstroke'       => __( 'UpStroke', 'wp-marketing-automations' ),
				'activecampaign' => __( 'Active Campaign', 'wp-marketing-automations' ),
				'drip'           => __( 'Drip', 'wp-marketing-automations' ),
				'affwp'          => __( 'AffiliateWp', 'wp-marketing-automations' ),
				'gf'             => __( 'Gravity Forms', 'wp-marketing-automations' ),
			),
			'event'       => array(
				'wc_comment_post'          => __( 'New Review', 'wp-marketing-automations' ),
				'wc_new_order'             => __( 'Order Created', 'wp-marketing-automations' ),
				'wc_order_note_added'      => __( 'Order Note Added', 'wp-marketing-automations' ),
				'wc_order_status_change'   => __( 'Order Status Changed', 'wp-marketing-automations' ),
				'wc_product_purchased'     => __( 'Order Created - Per Item', 'wp-marketing-automations' ),
				'wc_product_refunded'      => __( 'Order Item Refunded', 'wp-marketing-automations' ),
				'wc_product_stock_reduced' => __( 'Order Item Stock Reduced', 'wp-marketing-automations' ),
				'wc_customer_win_back'     => __( 'Customer Win Back', 'wp-marketing-automations' ),
				'ab_cart_abandoned'        => __( 'Cart Abandoned', 'wp-marketing-automations' ),
				'ab_cart_recovered'        => __( 'Cart Recovered', 'wp-marketing-automations' ),
				'wp_user_creation'         => __( 'User Creation', 'wp-marketing-automations' ),
				'wp_user_login'            => __( 'User Login', 'wp-marketing-automations' ),

				'ac_webhook_received'   => __( 'Webhook Received', 'wp-marketing-automations' ),
				'drip_webhook_received' => __( 'Webhook Received', 'wp-marketing-automations' ),

				'upstroke_funnel_ended'         => __( 'Funnel Ended', 'wp-marketing-automations' ),
				'upstroke_offer_viewed'         => __( 'Offer Viewed', 'wp-marketing-automations' ),
				'upstroke_product_accepted'     => __( 'Offer Accepted', 'wp-marketing-automations' ),
				'upstroke_offer_rejected'       => __( 'Offer Rejected', 'wp-marketing-automations' ),
				'upstroke_offer_payment_failed' => __( 'Offer Payment Failed', 'wp-marketing-automations' ),

				'wcs_created'                  => __( 'Subscriptions Created', 'wp-marketing-automations' ),
				'wcs_status_changed'           => __( 'Subscriptions Status Changed', 'wp-marketing-automations' ),
				'wcs_trial_end'                => __( 'Subscriptions Trial End', 'wp-marketing-automations' ),
				'wcs_before_renewal'           => __( 'Subscriptions Before Renewal', 'wp-marketing-automations' ),
				'wcs_before_end'               => __( 'Subscriptions Before End', 'wp-marketing-automations' ),
				'wcs_renewal_payment_complete' => __( 'Subscriptions Renewal Payment Complete', 'wp-marketing-automations' ),
				'wcs_renewal_payment_failed'   => __( 'Subscriptions Renewal Payment Failed', 'wp-marketing-automations' ),
				'wcs_card_expiry'              => __( 'Customer Before Card Expiry', 'wp-marketing-automations' ),

				'affwp_affiliate_report'     => __( 'Affiliate Digests', 'wp-marketing-automations' ),
				'affwp_application_approved' => __( 'Application Approved', 'wp-marketing-automations' ),
				'affwp_application_rejected' => __( 'Application Rejected', 'wp-marketing-automations' ),
				'affwp_makes_sale'           => __( 'Affiliate Makes A Sale', 'wp-marketing-automations' ),
				'affwp_referral_rejected'    => __( 'Referral Rejected', 'wp-marketing-automations' ),
				'affwp_signup'               => __( 'Application Sign Up', 'wp-marketing-automations' ),

				'gf_form_submit' => __( 'Form Submits', 'wp-marketing-automations' ),

			),
			'integration' => array(
				'wc'             => __( 'WooCommerce', 'wp-marketing-automations' ),
				'wp'             => __( 'WordPress', 'wp-marketing-automations' ),
				'wp_adv'         => __( 'WordPress Advanced', 'wp-marketing-automations' ),
				'zapier'         => __( 'Zapier', 'wp-marketing-automations' ),
				'activecampaign' => __( 'ActiveCampaign', 'wp-marketing-automations' ),
				'convertkit'     => __( 'Kit ( Formerly ConvertKit )', 'wp-marketing-automations' ),
				'drip'           => __( 'Drip', 'wp-marketing-automations' ),
				'slack'          => __( 'Slack', 'wp-marketing-automations' ),
				'twilio'         => __( 'Twilio', 'wp-marketing-automations' ),
				'whatsapp'       => __( 'Whatsapp', 'wp-marketing-automations' ),
				'google_sheets'  => __( 'Google Sheets', 'wp-marketing-automations' ),
			),
			'action'      => array(
				'wc_create_coupon'       => __( 'Create Coupon', 'wp-marketing-automations' ),
				'wc_add_order_note'      => __( 'Add Order Note', 'wp-marketing-automations' ),
				'wc_change_order_status' => __( 'Change Order Status', 'wp-marketing-automations' ),
				'wc_remove_coupon'       => __( 'Delete Coupon', 'wp-marketing-automations' ),

				'wp_sendemail' => __( 'Send Email', 'wp-marketing-automations' ),

				'za_send_data' => __( 'Send Data To Zapier', 'wp-marketing-automations' ),

				'wp_custom_callback'  => __( 'Custom Callback', 'wp-marketing-automations' ),
				'wp_debug'            => __( 'Debug', 'wp-marketing-automations' ),
				'wp_http_post'        => __( 'HTTP Post', 'wp-marketing-automations' ),
				'wp_createuser'       => __( 'Create User', 'wp-marketing-automations' ),
				'wp_update_user_meta' => __( 'Update User Meta', 'wp-marketing-automations' ),

				'ac_add_tag'               => __( 'Add Tags', 'wp-marketing-automations' ),
				'ac_add_to_automation'     => __( 'Add Contact To Automation', 'wp-marketing-automations' ),
				'ac_add_to_list'           => __( 'Add Contact To List', 'wp-marketing-automations' ),
				'ac_create_abandoned_cart' => __( 'Create Abandoned Cart', 'wp-marketing-automations' ),
				'ac_create_deal_note'      => __( 'Create Deal Note', 'wp-marketing-automations' ),
				'ac_create_deal'           => __( 'Create Deal', 'wp-marketing-automations' ),
				'ac_create_order'          => __( 'Create Order', 'wp-marketing-automations' ),
				'ac_rmv_from_automation'   => __( 'End Automation', 'wp-marketing-automations' ),
				'ac_rmv_from_list'         => __( 'Remove Contact From List', 'wp-marketing-automations' ),
				'ac_rmv_tag'               => __( 'Remove Tags', 'wp-marketing-automations' ),
				'ac_update_customfields'   => __( 'Update Fields', 'wp-marketing-automations' ),
				'ac_update_deal'           => __( 'Update Deal', 'wp-marketing-automations' ),

				'ck_add_customfields'  => __( 'Update Custom Fields', 'wp-marketing-automations' ),
				'ck_add_order'         => __( 'Create A New Purchase', 'wp-marketing-automations' ),
				'ck_add_tags'          => __( 'Add Tags', 'wp-marketing-automations' ),
				'ck_add_to_sequence'   => __( 'Add Subscriber To Sequence', 'wp-marketing-automations' ),
				'ck_rmv_from_sequence' => __( 'Remove Subscriber from Sequence', 'wp-marketing-automations' ),
				'ck_rmv_tags'          => __( 'Remove Tags', 'wp-marketing-automations' ),

				'dr_add_cart'          => __( 'Cart Activity', 'wp-marketing-automations' ),
				'dr_add_customfields'  => __( 'Update Custom fields of Subscriber', 'wp-marketing-automations' ),
				'dr_add_order'         => __( 'Add A New Order', 'wp-marketing-automations' ),
				'dr_add_tags'          => __( 'Add Tags', 'wp-marketing-automations' ),
				'dr_add_to_campaign'   => __( 'Add Subscriber to Campaign', 'wp-marketing-automations' ),
				'dr_add_to_workflow'   => __( 'Add Subscriber to Workflow', 'wp-marketing-automations' ),
				'dr_rmv_from_campaign' => __( 'Remove Subscriber from Campaign', 'wp-marketing-automations' ),
				'dr_rmv_from_workflow' => __( 'Remove Subscriber from Workflow', 'wp-marketing-automations' ),
				'dr_rmv_tags'          => __( 'Remove Tags', 'wp-marketing-automations' ),

				'sl_message_user' => __( 'Sends a message to a user', 'wp-marketing-automations' ),
				'sl_message'      => __( 'Sends a message to a channel', 'wp-marketing-automations' ),

				'twilio_send_sms' => __( 'Send SMS', 'wp-marketing-automations' ),

				'gs_insert_data' => __( 'Insert Row', 'wp-marketing-automations' ),
				'gs_update_data' => __( 'Update Row', 'wp-marketing-automations' ),

				'wcs_change_subscription_status' => __( 'Change Subscription Status', 'wp-marketing-automations' ),
				'wcs_send_subscription_invoice'  => __( 'Send Subscription Invoice', 'wp-marketing-automations' ),

				'affwp_change_affiliate_rate'  => __( 'Change Affiliate Rate', 'wp-marketing-automations' ),
				'affwp_change_referral_status' => __( 'Change Referral Status', 'wp-marketing-automations' ),
			),
		);
	}

	/**
	 * Get automation source name using source_slug
	 *
	 * @param $source
	 *
	 * @return mixed|string
	 */
	public static function get_automation_source_name( $source ) {
		if ( empty( $source ) ) {
			return '';
		}

		$single_source = BWFAN_Core()->sources->get_source( $source );
		if ( $single_source instanceof BWFAN_Source ) {
			return $single_source->get_name();
		}

		return self::get_entity_nice_name( 'source', $source );
	}

	/**
	 * Get automation event name using event_slug
	 *
	 * @param $event
	 *
	 * @return mixed|string
	 */
	public static function get_automation_event_name( $event ) {
		if ( empty( $event ) ) {
			return '';
		}

		$single_event = BWFAN_Core()->sources->get_event( $event );
		if ( $single_event instanceof BWFAN_Event ) {
			return $single_event->get_name();
		}

		return self::get_entity_nice_name( 'event', $event );
	}

	public static function automation_status_count( $aid, $status_aids, $counts ) {
		$aid_index = array_search( $aid, $status_aids, true );
		if ( false === $aid_index ) {
			return 0;
		}

		return isset( $counts[ $aid_index ] ) ? $counts[ $aid_index ] : 0;
	}

	/**
	 * @param $automation_id
	 * @param $automation_tasks
	 *
	 * @return array
	 */
	public static function get_automation_task_details( $automation_id, $automation_tasks ) {
		$output = array();
		foreach ( $automation_tasks as $key => $count ) {
			$output[] = array(
				'count' => absint( $count ),
				'name'  => ucfirst( $key ),
			);
		}

		return $output;
	}

	/**
	 * Skip child order
	 *
	 * @param $id int order id
	 *
	 * @return bool
	 */
	public static function bwf_check_to_skip_child_order( $id ) {
		return ( apply_filters( 'bwf_skip_sub_order', false ) && wp_get_post_parent_id( $id ) );
	}

	/**
	 * Remove action for without instance method  class found and return object of class
	 *
	 * @param $hook
	 * @param $cls
	 * @param $function
	 *
	 * @return mixed|string|null
	 */
	public static function remove_actions( $hook, $cls, $function = '' ) {
		global $wp_filter;
		$object = null;
		if ( class_exists( $cls ) && isset( $wp_filter[ $hook ] ) && ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
			$hooks = $wp_filter[ $hook ]->callbacks;
			foreach ( $hooks as $priority => $reference ) {
				if ( is_array( $reference ) && count( $reference ) > 0 ) {
					foreach ( $reference as $index => $calls ) {
						if ( isset( $calls['function'] ) && is_array( $calls['function'] ) && count( $calls['function'] ) > 0 ) {
							if ( is_object( $calls['function'][0] ) ) {
								$cls_name = get_class( $calls['function'][0] );
								if ( $cls_name === $cls && $calls['function'][1] === $function ) {
									$object = $calls['function'][0];
									unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $index ] );
								}
							} elseif ( $index === $cls . '::' . $function ) {
								// For Static Classess
								$object = $cls;
								unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $cls . '::' . $function ] );
							}
						}
					}
				}
			}
		} elseif ( function_exists( $cls ) && isset( $wp_filter[ $hook ] ) && ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
			$hooks = $wp_filter[ $hook ]->callbacks;
			foreach ( $hooks as $priority => $reference ) {
				if ( is_array( $reference ) && count( $reference ) > 0 ) {
					foreach ( $reference as $index => $calls ) {
						$remove = false;
						if ( $index === $cls ) {
							$remove = true;
						} elseif ( isset( $calls['function'] ) && $cls === $calls['function'] ) {
							$remove = true;
						}
						if ( true === $remove ) {
							unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $cls ] );
						}
					}
				}
			}
		}

		return $object;
	}

	/**
	 * Get all possible merge tags from the given string
	 *
	 * @param $str
	 *
	 * @return array
	 */
	public static function fetch_merge_tags( $str ) {
		/** Remove all style tags and their content */
		$pattern = '/<style\b[^>]*>(.*?)<\/style>/is';
		$str     = preg_replace( $pattern, '', $str );

		$count              = substr_count( $str, '{{' );
		$merge_tag          = [];
		$closing_merge_tags = [];

		/** Decode block shortcodes first */
		$pattern = '/\\[bwfbe_.*?\\](.*?)\\[\\/bwfbe_.*?\\]/s';
		preg_match_all( $pattern, $str, $closing_merge_tags );
		$close_tags = $closing_merge_tags[0] ?? [];

		foreach ( $close_tags as $s ) {
			$str = str_ireplace( $s, '', $str );
		}

		/** Decode normal merge tags */
		for ( $i = 0; $i < $count; $i ++ ) {
			$tag = self::fetch_inner_most_tag( $str );
			if ( empty( $tag ) ) {
				continue;
			}
			$new_tag = str_replace( '{{', '${$', $tag );
			$new_tag = str_replace( '}}', '$}$', $new_tag );
			$str     = str_replace( $tag, $new_tag, $str );

			$tag = str_replace( '${$', '{{', $tag );
			$tag = str_replace( '$}$', '}}', $tag );

			$merge_tag[] = $tag;
		}

		/** also check if the body contain shortcode other than autonami */
		$str = str_replace( '<!--[', 'bwf$__$', $str );
		$str = str_replace( '<![', 'bwf$$_1', $str );

		preg_match_all( '/\[(.*?)\]/', $str, $more_merge_tags );

		$external_merge_tag = [];
		if ( is_array( $more_merge_tags[0] ) && count( $more_merge_tags[0] ) > 0 ) {
			$external_merge_tag = array_filter( array_values( $more_merge_tags[0] ) );
		}

		return array_merge( $merge_tag, $external_merge_tag, $close_tags );
	}

	/**
	 * Get inner most merge tag from the string
	 *
	 * @param $str
	 *
	 * @return string
	 */
	public static function fetch_inner_most_tag( $str ) {
		$exp       = explode( '}}', $str );
		$start_exp = explode( '{{', $exp[0] );
		if ( 1 === count( $start_exp ) ) {
			return '';
		}
		$start_exp = end( $start_exp );

		return '{{' . $start_exp . '}}';
	}

	/**
	 * Replace merge tags with their values in highest to lowest tags in a single string
	 *
	 * @param $body
	 * @param $tags
	 *
	 * @return array|mixed|string|string[]
	 */
	public static function replace_merge_tags( $body, $tags, $cid = 0 ) {
		if ( empty( $tags ) || ! is_array( $tags ) || 0 === count( $tags ) ) {
			return $body;
		}

		/** Sort the array from highest number of merge tags to lowest */
		$keys = array_keys( $tags );
		usort( $keys, function ( $l1, $l2 ) {
			return strcmp( substr_count( $l1, '{{' ), substr_count( $l2, '{{' ) );
		} );
		$keys = array_reverse( $keys );

		/** Replace merge tags with actual data in body */
		foreach ( $keys as $tag ) {
			/** Checking for block visibility shortcode */
			if ( intval( $cid ) > 0 && false !== strpos( $tag, '[bwfan_email_block_visibility' ) ) {
				if ( ! isset( $tags[ $tag ] ) ) {
					continue;
				}
				/** Set data for validate generic rules */
				BWFAN_Merge_Tag_Loader::set_data( [ 'contact_id' => $cid ] );

				$tag     = str_replace( 'bwf$__$', '<!--[', $tag );
				$tag     = str_replace( 'bwf$$_1', '<![', $tag );
				$content = do_shortcode( $tag );

				$body = str_replace( $tag, $content, $body );
				continue;
			}

			$body = str_replace( $tag, is_string( $tags[ $tag ] ) ? $tags[ $tag ] : '', $body );
		}

		return $body;
	}

	/**
	 * Get global data available for automation contact
	 *
	 * @param $data
	 *
	 * @return mixed
	 */
	public static function get_global_data( $data ) {
		if ( ! isset( $data['global'] ) ) {
			$data['global'] = [];
		}

		/** Set user id if available */
		if ( ! isset( $data['global']['user_id'] ) && isset( BWFAN_Common::$events_async_data['user_id'] ) ) {
			$data['global']['user_id'] = absint( BWFAN_Common::$events_async_data['user_id'] );
		}

		/** Set language if available */
		if ( ! isset( $data['global']['language'] ) && isset( BWFAN_Common::$events_async_data['language'] ) ) {
			$data['global']['language'] = BWFAN_Common::$events_async_data['language'];
		}

		/** Set user_id, if email is available */
		if ( ( ! isset( $data['global']['user_id'] ) || empty( $data['global']['user_id'] ) ) && is_email( $data['global']['email'] ) ) {
			$user = get_user_by( 'email', $data['global']['email'] );
			if ( $user instanceof WP_User ) {
				$data['global']['user_id'] = $user->ID;
			}

			/** Get contact ID for all event if there email id available */
			$user_id = isset( $data['global']['user_id'] ) ? $data['global']['user_id'] : null;
			$contact = bwf_get_contact( $user_id, $data['global']['email'] );
			if ( $contact instanceof WooFunnels_Contact && absint( $contact->get_id() ) === 0 ) {
				/** Create contact if not exists */
				$user_id = empty( $user_id ) ? 0 : $user_id;
				$email   = $data['global']['email'];
				$contact = self::create_contact( $contact, $user_id, $email );
			}
			$contact_id                   = $contact instanceof WooFunnels_Contact ? absint( $contact->get_id() ) : 0;
			$data['global']['contact_id'] = $contact_id;
			$data['global']['cid']        = $contact_id;
		}

		/** Set Phone if User ID is available */
		if ( isset( $data['global']['user_id'] ) && ! empty( $data['global']['user_id'] ) && ( ! isset( $data['global']['phone'] ) || empty( $data['global']['phone'] ) ) ) {
			$phone = get_user_meta( $data['global']['user_id'], 'billing_phone', true );
			if ( ! empty( $phone ) ) {
				$country = get_user_meta( $data['global']['user_id'], 'billing_country', true );
				if ( ! empty( $country ) ) {
					$phone = BWFAN_Phone_Numbers::add_country_code( $phone, $country );
				}
				$data['global']['phone'] = $phone;
			}
		}

		/** Set Phone if no User ID is set, but email is set */
		if ( isset( $data['global']['email'] ) && is_email( $data['global']['email'] ) && ( ! isset( $data['global']['phone'] ) || empty( $data['global']['phone'] ) ) ) {
			$order = BWFAN_Common::get_latest_order_by_email( $data['global']['email'] );
			if ( $order instanceof WC_Order ) {
				$phone = $order->get_billing_phone();
				if ( ! empty( $phone ) ) {
					$country = $order->get_billing_country();
					if ( ! empty( $country ) ) {
						$phone = BWFAN_Phone_Numbers::add_country_code( $phone, $country );
					}
					$data['global']['phone'] = $phone;
				}
			}
		}

		if ( isset( $data['global']['contact_id'] ) && ! empty( $data['global']['contact_id'] ) ) {
			$data['global']['cid'] = $data['global']['contact_id'];
		}

		/** Set Contact ID */
		if ( ! isset( $data['global']['cid'] ) ) {
			$email   = isset( $data['global']['email'] ) ? $data['global']['email'] : '';
			$phone   = isset( $data['global']['phone'] ) ? $data['global']['phone'] : '';
			$user_id = isset( $data['global']['user_id'] ) ? $data['global']['user_id'] : '';
			if ( ! empty( $email ) || ! empty( $phone ) || ! empty( $user_id ) ) {
				$contact = new WooFunnels_Contact( $user_id, $email, $phone );
				if ( absint( $contact->get_id() ) === 0 ) {
					$contact = self::create_contact( $contact, $user_id, $email, $phone );
				}
				$data['global']['cid'] = $contact->get_id();
			}
		}

		if ( empty( $data['global']['contact_id'] ) && ! empty( $data['global']['cid'] ) ) {
			$data['global']['contact_id'] = $data['global']['cid'];
		}

		return $data;
	}

	/**
	 * Create contact
	 *
	 * @param $contact
	 * @param $user_id
	 * @param $email
	 * @param $phone
	 *
	 * @return mixed|WooFunnels_Contact
	 */
	public static function create_contact( $contact, $user_id, $email, $phone = '' ) {
		if ( empty( $email ) || ! is_email( $email ) ) {
			return $contact;
		}
		if ( ! $contact instanceof WooFunnels_Contact ) {
			return $contact;
		}

		! empty( $email ) && $contact->set_email( $email );
		! empty( $phone ) && $contact->set_contact_no( $phone );
		! empty( $user_id ) && $contact->set_wpid( absint( $user_id ) );
		$contact->save();

		return $contact;
	}

	public static function get_latest_order_by_email( $email ) {
		if ( ! is_email( $email ) ) {
			return false;
		}
		if ( ! bwfan_is_woocommerce_active() ) {
			return false;
		}

		global $wpdb;

		$email          = sanitize_email( $email );
		$order_statuses = implode( "','", array_map( 'esc_sql', array_keys( wc_get_order_statuses() ) ) );
		if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
			$last_order = $wpdb->get_var( $wpdb->prepare( "SELECT `id` FROM {$wpdb->prefix}wc_orders WHERE `billing_email` = %s AND `type` = 'shop_order' AND `status` IN ( '" . $order_statuses . "' ) ORDER BY `id` DESC LIMIT 0,1", $email ) );// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $last_order && $last_order > 0 ) {
				return wc_get_order( intval( $last_order ) );
			}

			return false;
		}

		$last_order = $wpdb->get_var( $wpdb->prepare( "SELECT posts.ID FROM $wpdb->posts AS posts LEFT JOIN {$wpdb->postmeta} AS meta on posts.ID = meta.post_id WHERE meta.meta_key = '_billing_email' AND meta.meta_value = %s AND posts.post_type = 'shop_order' AND posts.post_status IN ( '" . $order_statuses . "' ) ORDER BY posts.ID DESC LIMIT 0,1", $email ) );// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $last_order ) {
			return false;
		}

		return wc_get_order( intval( $last_order ) );
	}

	/**
	 * v2 Method: Prepared options for field schema
	 *
	 * @param $options
	 *
	 * @return array
	 */
	public static function prepared_field_options( $options ) {
		if ( ! is_array( $options ) || empty( $options ) ) {
			return [];
		}
		$prepared_options = array_map( function ( $label, $value ) {
			return array(
				'label' => $label,
				'value' => $value,
			);
		}, $options, array_keys( $options ) );

		return $prepared_options;
	}

	/**
	 * API access capabilities for rest endpoints
	 * @return array
	 */
	public static function access_capabilities() {
		$default_capabilities = array( 'manage_options' );
		$capabilities         = apply_filters( 'bwfan_crm_api_access_caps', [] );

		return array_merge( $default_capabilities, is_array( $capabilities ) ? $capabilities : [] );
	}

	public static function bwf_has_action_scheduled( $hook = '' ) {
		/** If AJAX call then no need to check */
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return true;
		}

		/** If empty just return */
		if ( empty( $hook ) ) {
			return false;
		}

		/** Hard recurring actions list to avoid duplicate calls */
		$list = [
			'bwfan_delete_expired_autonami_coupons',
			'bwfan_mark_abandoned_lost_cart',
			'bwfan_run_midnight_cron',
			'bwfan_run_midnight_connectors_sync',
			'bwfcrm_broadcast_run_queue',
			'bwfan_check_abandoned_carts',
			'bwfan_run_queue',
			'bwfan_run_queue_v2',
			'bwfan_5_minute_worker',
			'bwfan_delete_old_abandoned_carts',
			'bwfan_delete_logs',
			'bwfan_run_event_queue'
		];

		/**
		 * Recurring action from above list and DB called made already
		 */
		if ( in_array( $hook, $list, true ) && is_array( self::$recurring_actions_db ) && isset( self::$recurring_actions_db[ $hook ] ) && true === self::$recurring_actions_db[ $hook ] ) {
			return true;
		}

		/**
		 * Call all recurring actions status - DB call
		 */
		if ( in_array( $hook, $list, true ) ) {
			global $wpdb;

			$table = $wpdb->prefix . 'bwf_actions';
			$p_key = 'id';

			$placeholders = array_fill( 0, count( $list ), '%s' );
			$placeholders = implode( ', ', $placeholders );

			$query  = $wpdb->prepare( "SELECT `{$p_key}`, `hook` FROM $table WHERE `hook` IN ($placeholders) AND `status` IN (0,1)", $list );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders
			$result = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders generated via array_fill; values bound in prepare()
			if ( is_array( $result ) && count( $result ) > 0 ) {
				foreach ( $result as $val ) {
					self::$recurring_actions_db[ $val['hook'] ] = true;
				}
			}
			foreach ( $list as $val ) {
				if ( ! isset( self::$recurring_actions_db[ $val ] ) ) {
					self::$recurring_actions_db[ $val ] = false;
				}
			}

			return self::$recurring_actions_db[ $hook ];
		}

		/**
		 * Hook not found in the above list then native DB call
		 */
		return bwf_has_action_scheduled( $hook );
	}

	/**
	 * Build a Reply-To email header.
	 *
	 * Returns the standard RFC 5322 bare `Reply-To: <email>` header, which every
	 * standards-compliant SMTP provider accepts. Default behaviour is unchanged.
	 *
	 * The final header line is passed through the `bwfan_reply_to_header` filter so a
	 * site can override it for SMTP plugins that need a different format — e.g. Authority
	 * Mailer rejects a nameless Reply-To with Code 29 ("Reply-To name or email address is
	 * missing"), and such a site can return `Reply-To: Name <email>` from the filter. The
	 * filter receives the email, the reply-to name (if any) and a fallback name (typically
	 * the From name) so the callback can build whatever it needs.
	 *
	 * @param string $reply_to_email Reply-To email address.
	 * @param string $reply_to_name  Reply-To display name (optional).
	 * @param string $fallback_name  Fallback name, typically the From name.
	 *
	 * @return string Reply-To header line, or empty string when no email is provided.
	 */
	public static function build_reply_to_header( $reply_to_email, $reply_to_name = '', $fallback_name = '' ) {
		$reply_to_email = trim( (string) $reply_to_email );
		if ( empty( $reply_to_email ) ) {
			return '';
		}

		/**
		 * Filter the Reply-To header line before it is added to the email headers.
		 *
		 * Default is the standard bare `Reply-To: <email>`. Return a custom value such as
		 * `Reply-To: Name <email>` for SMTP plugins that require a display name on Reply-To
		 * (e.g. Authority Mailer, Code 29). When attaching a name that may contain specials
		 * (comma, <, @, ...), wrap it in double quotes in the callback to keep the header valid.
		 *
		 * @param string $header         The Reply-To header line. Default 'Reply-To: <email>'.
		 * @param string $reply_to_email Reply-To email address.
		 * @param string $reply_to_name  Reply-To display name, if any (may be empty).
		 * @param string $fallback_name  Fallback name, typically the From name.
		 */
		return apply_filters( 'bwfan_reply_to_header', 'Reply-To: ' . $reply_to_email, $reply_to_email, $reply_to_name, $fallback_name );
	}

	/** send test email from autonami screen */
	public static function send_test_email( $args, $is_crm = false ) {
		if ( empty( $args ) || empty( $args['body'] ) ) {
			return false;
		}
		$type = $args['type'] ?? 'rich';
		BWFAN_Common::bwfan_before_send_mail( $type );
		$res    = false;
		$emails = explode( ',', $args['email'] );
		/** Get send email object */
		$send_email_ins = BWFAN_Core()->integration->get_action( 'wp_sendemail' );
		if ( null === $send_email_ins && class_exists( 'BWFAN_Wp_Sendemail' ) ) {
			/** Try to get the instance directly */
			$send_email_ins = BWFAN_Wp_Sendemail::get_instance();
		}
		if ( null === $send_email_ins ) {
			return false;
		}
		foreach ( $emails as $email ) {
			if ( ! is_email( $email ) ) {
				continue;
			}
			$global_email_settings = self::get_global_settings();

			BWFAN_Merge_Tag_Loader::set_data( array(
				'is_preview'     => true,
				'send_test_mail' => true,
			) );

			/** Look for contact if it exists */
			$contact = new WooFunnels_Contact( '', $email );
			if ( $contact instanceof WooFunnels_Contact && intval( $contact->get_id() ) > 0 ) {
				BWFAN_Merge_Tag_Loader::set_data( array(
					'contact_id' => $contact->get_id()
				) );
			}

			$subject = ! empty( $args['subject'] ) ? $args['subject'] : __( 'FunnelKit Automations - Test email subject', 'wp-marketing-automations' );

			$subject = self::decode_merge_tags( $subject, $is_crm );

			/** Email Body */
			$body = self::correct_shortcode_string( $args['body'], $type );
			$body = self::decode_merge_tags( $body, $is_crm );
			$body = self::bwfan_correct_protocol_url( $body );
			$data = [];

			/** Getting the template type in id */
			switch ( $type ) {
				case 'rich':
					$data['template'] = 1;
					break;
				case 'wc':
					if ( class_exists( 'WooCommerce' ) ) {
						$data['template'] = 2;
					}
					break;
				case 'html':
					$data['template'] = 3;
					break;
				case 'editor':
					if ( bwfan_is_autonami_pro_active() ) {
						$data['template'] = 4;
					}
					break;
				case 'block':
					if ( bwfan_is_autonami_pro_active() ) {
						$data['template'] = 5;
					}
			}

			$data['body'] = $body;
			$body         = $send_email_ins->email_content_v2( $data );

			if ( bwfan_is_autonami_pro_active() ) {
				$body = self::add_test_utm_params( $body, $args );
			}

			/** Email Pre-header */
			$pre_header = $args['preheader'] ?? '';
			$body       = ! empty( $pre_header ) ? $send_email_ins->append_to_email_body( $body, $pre_header, '' ) : $body;

			/** Append contact uid in all a href link trigger links  */
			$body = self::append_uid_to_links( $body );

			/** Email Headers */
			$from_name      = $global_email_settings['bwfan_email_from_name'] ?? '';
			$from_email     = $global_email_settings['bwfan_email_from'] ?? '';
			$reply_to_email = $global_email_settings['bwfan_email_reply_to'] ?? '';

			/** Override from broadcast or forms */
			$from_name      = $args['senders_name'] ?? $from_name;
			$from_email     = $args['senders_email'] ?? $from_email;
			$reply_to_email = $args['reply_to'] ?? $reply_to_email;

			if ( isset( $args['overRideSenderInfo'] ) && 1 === absint( $args['overRideSenderInfo'] ) ) {
				$from_name      = isset( $args['overRideInfo']['from_name'] ) && ! empty( $args['overRideInfo']['from_name'] ) ? $args['overRideInfo']['from_name'] : $from_name;
				$from_email     = isset( $args['overRideInfo']['from_email'] ) && ! empty( $args['overRideInfo']['from_email'] ) ? $args['overRideInfo']['from_email'] : $from_email;
				$reply_to_email = isset( $args['overRideInfo']['reply_to_email'] ) && ! empty( $args['overRideInfo']['reply_to_email'] ) ? $args['overRideInfo']['reply_to_email'] : $reply_to_email;
			}

			$override_data = [
				'from_name'      => $from_name,
				'from_email'     => $from_email,
				'reply_to_email' => $reply_to_email,
			];
			do_action( 'bwfan_before_send_email', $override_data, $body );

			/** Setup Headers */
			$header   = array();
			$header[] = 'MIME-Version: 1.0';
			if ( ! empty( $from_email ) && ! empty( $from_name ) ) {
				$header[] = 'From: ' . $from_name . ' <' . $from_email . '>';
			}
			if ( ! empty( $reply_to_email ) ) {
				$header[] = self::build_reply_to_header( $reply_to_email, '', $from_name );
			}
			$header[] = 'Content-type:text/html;charset=UTF-8';

			/** Set unsubscribe link in header */
			$unsubscribe_link = BWFAN_Common::get_unsubscribe_link( [ 'uid' => $contact->get_uid() ] );
			if ( ! empty( $unsubscribe_link ) ) {
				$header[] = "List-Unsubscribe: <$unsubscribe_link>";
				$header[] = "List-Unsubscribe-Post: List-Unsubscribe=One-Click";
			}

			$header       = apply_filters( 'bwfan_email_headers', $header );
			$default_text = apply_filters( 'bwfan_email_default_test_text', true );
			$subject      = ( true === $default_text ? __( 'TEST: ', 'wp-marketing-automations' ) : '' ) . $subject;

			$res = wp_mail( $email, $subject, $body, $header );
		}

		return $res;
	}

	/**
	 * Auto append logged-in user contact UID in a links
	 *
	 * @param $body
	 *
	 * @return array|mixed|string|string[]|null
	 */
	public static function append_uid_to_links( $body ) {
		if ( empty( $body ) ) {
			return $body;
		}

		/** Check if 'bwfan-link-trigger=' exists in the body */
		if ( strpos( $body, 'bwfan-link-trigger=' ) === false ) {
			return $body;
		}

		$current_user_id = get_current_user_id();
		if ( empty( $current_user_id ) ) {
			return $body;
		}

		$contact = new WooFunnels_Contact( $current_user_id );
		if ( empty( $contact->get_id() ) || empty( $contact->get_uid() ) ) {
			return $body;
		}

		$uid = $contact->get_uid();

		/** Regex pattern to match href attributes only within <a> tags (handling both single and double quotes) */
		$pattern = '/<a[^>]+href=("|\')(.*?)\1/i';

		return preg_replace_callback( $pattern, function ( $matches ) use ( $uid ) {
			$quote = $matches[1]; // Capture quote type
			$tag   = $matches[0];

			/** Extract href URL */
			preg_match( '/href=' . $quote . '(.*?)' . $quote . '/', $tag, $hrefMatch );
			$url = $hrefMatch[1] ?? '';

			/** Check if 'bwfan-link-trigger=' exists in the url */
			if ( strpos( $url, 'bwfan-link-trigger=' ) === false ) {
				return $tag;
			}
			/** Append uid parameter correctly */
			$separator = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
			$new_url   = $url . $separator . 'bwfan-uid=' . $uid;

			return str_replace( $url, $new_url, $tag );
		}, $body );
	}

	/**
	 * Add UTM parameters in test email
	 *
	 * @param $body
	 * @param $args
	 * @param $mode
	 *
	 * @return array|mixed|string|string[]|null
	 */
	public static function add_test_utm_params( $body, $args, $mode = 'email' ) {
		$utm_data = array();

		/** Normalise Data */
		if ( isset( $args['utmDetails']['content'] ) ) {
			$utm_data['utm_content'] = $args['utmDetails']['content'];
		}

		if ( isset( $args['utmDetails']['medium'] ) ) {
			$utm_data['utm_medium'] = $args['utmDetails']['medium'];
		}

		if ( isset( $args['utmDetails']['name'] ) ) {
			$utm_data['utm_name'] = $args['utmDetails']['name'];
		}

		if ( isset( $args['utmDetails']['source'] ) ) {
			$utm_data['utm_source'] = $args['utmDetails']['source'];
		}

		if ( isset( $args['utmDetails']['term'] ) ) {
			$utm_data['utm_term'] = $args['utmDetails']['term'];
		}

		if ( empty( $utm_data ) ) {
			return $body;
		}
		/** Add flag to append UTM Params */
		$utm_data['append_utm'] = isset( $args['utmEnabled'] ) ? $args['utmEnabled'] : 0;

		$ins = BWFAN_UTM_Tracking::get_instance();

		return $ins->maybe_add_utm_parameters( $body, $utm_data, $mode );
	}

	public static function decode_merge_tags( $string, $is_crm = false ) {
		if ( ! is_string( $string ) || trim( $string ) === '' ) {
			return '';
		}
		// Check if the body is excluded from decoding the merge tags
		$mergeTagData = BWFAN_Merge_Tag_Loader::get_data();
		if ( isset( $mergeTagData['body_pre_decoding'] ) && boolval( $mergeTagData['body_pre_decoding'] ) ) {
			return $string;
		}

		$string = do_shortcode( $string );
		$string = self::strip_merge_tags( $string, $is_crm );
		$string = apply_filters( 'bwfan_pre_decode_merge_tags', $string );

		$string = str_replace( '[if', '[bwfno_if', $string );
		$string = str_replace( '[endif', '[bwfno_endif', $string );

		do_action( 'bwfan_before_decode_merge_tags', $string );
		$string = BWFAN_Merge_Tag::maybe_parse_nested_merge_tags( $string );

		do_action( 'bwfan_after_decode_merge_tags', $string );

		$string = str_replace( '[bwfno_if', '[if', $string );
		$string = str_replace( '[bwfno_endif', '[endif', $string );

		$string = apply_filters( 'bwfan_post_decode_merge_tags', $string );

		return $string;
	}

	public static function strip_merge_tags( $string, $is_crm = false ) {
		/** Don't strip from the style tag */
		$elements = explode( '</style>', $string );

		$shortcode_head = '[bwfan_';

		$stripped_merge_tags = array();
		foreach ( $elements as $element ) {
			$strings               = explode( '<style', $element );
			$strings[0]            = str_replace( '{{', $shortcode_head, $strings[0] );
			$strings[0]            = str_replace( '}}', ']', $strings[0] );
			$stripped_merge_tags[] = implode( '<style', $strings );
		}

		return implode( '</style>', $stripped_merge_tags );
	}

	/**
	 * Replace the duplicate http from a string.
	 *
	 * @param $string
	 *
	 * @return mixed
	 */
	public static function bwfan_correct_protocol_url( $string ) {
		$string = trim( $string );
		$string = str_replace( 'http://https://', 'https://', $string );
		$string = str_replace( 'https://http://', 'http://', $string );
		$string = str_replace( 'https://https://', 'https://', $string );
		$string = str_replace( 'http://http://', 'http://', $string );

		return $string;
	}

	/**
	 * Validate that a link is a well-formed http(s) URL.
	 *
	 * Use this for links that are stored for tracking or used as redirect targets
	 * (not fetched server-side). Unlike wp_http_validate_url() — which is an SSRF
	 * guard for outbound wp_remote_*() requests — this does NOT resolve DNS or
	 * reject private/loopback IPs. That matters because an admin's own-domain link
	 * legitimately resolves to 127.0.0.1 on hosts that loop their domain back to
	 * themselves, which makes wp_http_validate_url() wrongly reject valid links.
	 *
	 * It keeps the only protection relevant here: a strict http/https protocol
	 * whitelist (via esc_url_raw / wp_kses_bad_protocol) that blocks javascript:,
	 * data:, etc., plus a required host.
	 *
	 * @param string $url
	 *
	 * @return bool
	 */
	public static function bwfan_is_valid_link( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return false;
		}

		/** Reject disallowed protocols (javascript:, data:, …) without DNS/SSRF checks. */
		if ( '' === esc_url_raw( $url ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return false;
		}

		return in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true );
	}

	public static function get_completed_contacts( $aid, $sid, $type = '', $offset = 0, $limit = 25, $path = '', $data_key = 'path' ) {

		/**Get active automation contacts*/
		if ( 'active' === $type ) {
			return BWFAN_Model_Automation_Contact::get_contacts_journey( $aid, '', $limit, $offset, true, false, $type );
		}

		/**Get automation completed contacts*/
		if ( 0 === absint( $sid ) ) {
			return BWFAN_Model_Automation_Complete_Contact::get_automation_completed_contacts( $aid, $offset, $limit );
		}

		/**Get step completed contacts*/
		return BWFAN_Model_Automation_Contact_Trail::get_step_completed_contacts( $aid, $sid, $type, $offset, $limit, $path, $data_key );
	}

	public static function get_automation_contacts_journey( $aid, $search = '' ) {
		$automation_contacts = BWFAN_Model_Automation_Contact::get_contacts_journey( $aid, $search );

		$limit = 10;
		if ( is_array( $automation_contacts ) && count( $automation_contacts ) > 0 ) {
			$limit -= count( $automation_contacts );
		}

		/** if searchable limit, then search in automation contact table */
		$automation_complete_contacts = [];
		if ( $limit > 0 ) {
			$automation_complete_contacts = BWFAN_Model_Automation_Complete_Contact::get_contacts_journey( $aid, $search, $limit );
		}

		return array_merge( $automation_contacts, $automation_complete_contacts );
	}

	public static function get_automation_contacts( $aid, $cid, $search, $limit = 25, $offset = 0, $status = 'all' ) {

		/** Get completed automation contacts */
		if ( 'completed' === $status ) {
			$automation_complete_contacts = BWFAN_Model_Automation_Complete_Contact::get_contacts_journey( $aid, $search, $limit, $offset, true, true, $status, $cid );

			return [
				'contacts' => ! empty( $automation_complete_contacts['contacts'] ) ? $automation_complete_contacts['contacts'] : [],
				'total'    => isset( $automation_complete_contacts['total'] ) ? absint( $automation_complete_contacts['total'] ) : 0
			];
		}

		/** Get delayed automation contacts */
		if ( 'delayed' === $status ) {
			$where                       = 'AND cc.e_time < ' . current_time( 'timestamp', 1 );
			$automation_delayed_contacts = BWFAN_Model_Automation_Contact::get_contacts_journey( $aid, $search, $limit, $offset, true, true, $status, $where, $cid );

			return [
				'contacts' => ! empty( $automation_delayed_contacts['contacts'] ) ? $automation_delayed_contacts['contacts'] : [],
				'total'    => isset( $automation_delayed_contacts['total'] ) ? intval( $automation_delayed_contacts['total'] ) : 0
			];
		}

		/** Get active, failed, paused automation contacts */
		$automation_contacts = BWFAN_Model_Automation_Contact::get_contacts_journey( $aid, $search, $limit, $offset, true, true, $status, '', $cid );

		return [
			'contacts' => ! empty( $automation_contacts['contacts'] ) ? $automation_contacts['contacts'] : [],
			'total'    => isset( $automation_contacts['total'] ) ? absint( $automation_contacts['total'] ) : 0
		];
	}

	public static function get_step_by_trail( $trail ) {
		$results = BWFAN_Model_Automation_Step::get_step_by_trail( $trail );
		if ( ! empty( $results ) ) {
			$results = array_map( function ( $data ) {
				$data['run_time'] = date( 'Y-m-d H:i:s', $data['run_time'] ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
				$action           = ! empty( $data['action'] ) ? json_decode( $data['action'], true ) : [];
				$nice_name        = '';
				if ( 2 === absint( $data['type'] ) ) {
					$nice_name = isset( $action['action'] ) && BWFAN_Core()->integration->get_action( $action['action'] ) instanceof BWFAN_Action ? BWFAN_Core()->integration->get_action( $action['action'] )->get_name() : '';
				} else if ( 3 === absint( $data['type'] ) ) {
					$nice_name = isset( $action['benchmark'] ) && BWFAN_Core()->sources->get_event( $action['benchmark'] ) instanceof BWFAN_Event ? BWFAN_Core()->sources->get_event( $action['benchmark'] )->get_name() : '';
				}
				$data['action'] = $nice_name;

				return $data;
			}, $results );
		}

		return isset( $results[0] ) ? $results[0] : $results;
	}

	public static function get_automations_for_contact( $cid, $limit, $offset ) {
		$automation_contacts = BWFAN_Model_Automation_Contact::get_automation_contacts( $cid, '', $limit, $offset, true );
		$active_total        = BWFAN_Model_Automation_Contact::get_automation_contacts( $cid, '', '', '', false, true );
		if ( is_array( $automation_contacts ) && count( $automation_contacts ) > 0 ) {
			$limit -= count( $automation_contacts );
		}

		/** if active contact found on page( for pagination ) then subtract total active count from offset */
		if ( $offset > 0 && intval( $active_total ) > 0 ) {
			$offset = $offset - $active_total + count( $automation_contacts );
		}

		$automation_complete_contacts = [];

		/** if searchable limit, then search in automation contact table */
		if ( $limit > 0 ) {
			$automation_complete_contacts = BWFAN_Model_Automation_Complete_Contact::get_automation_contacts( $cid, '', $limit, $offset, true );
		}

		$completed_count = BWFAN_Model_Automation_Complete_Contact::get_automation_contacts( $cid, '', '', '', false, '', true );

		$contacts = array_merge( $automation_contacts, $automation_complete_contacts );

		return [
			'contacts' => $contacts,
			'total'    => intval( $active_total ) + intval( $completed_count )
		];
	}

	public static function get_dynamic_string( $count = 8 ) {
		$chars = apply_filters( 'bwfan_dynamic_string_chars', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' );

		return substr( str_shuffle( $chars ), 0, $count );
	}

	/**
	 * Update the execution time of automation contacts if delay time changed
	 *
	 * @param $step_id
	 *
	 */
	public static function bwfan_delay_step_updated( $step_id ) {
		$option_key  = 'bwf_delay_automations_' . $step_id;
		$automations = get_option( $option_key, [] );
		if ( empty( $automations ) ) {
			delete_option( $option_key );
			bwf_unschedule_actions( 'bwfan_delay_step_updated', [ 'sid' => $step_id ] );

			return;
		}

		$updated_automations = $automations;
		$start_time          = time();
		foreach ( $automations as $key => $automation_contact_id ) {
			/** Checking 10 seconds of processing */
			if ( ( time() - $start_time ) > 10 ) {
				return;
			}

			$data = BWFAN_Model_Automation_Contact::get_data( $automation_contact_id );

			/** If automation contact is failed or last step was not the current step id */
			if ( empty( $data ) || ( 2 === intval( $data['status'] ) ) || intval( $step_id ) !== intval( $data['last'] ) ) {
				unset( $updated_automations[ $key ] );
				update_option( $option_key, $updated_automations, false );
				continue;
			}

			$ins                = new BWFAN_Delay_Controller();
			$ins->contact_id    = $data['cid'];
			$ins->automation_id = $data['aid'];
			$ins->step_id       = $step_id;
			$ins->populate_automation_contact_data( $data );
			$ins->populate_step_data();

			$new_e_time = $ins->get_time( $data['last_time'] );
			$data       = [ 'e_time' => $new_e_time ];
			BWFAN_Model_Automation_Contact::update( $data, array(
				'ID' => absint( $automation_contact_id ),
			) );

			unset( $updated_automations[ $key ] );
			update_option( $option_key, $updated_automations, false );
		}
	}

	public static function bwfan_goal_step_updated( $step_id, $goal_run ) {
		$option_key = 'bwf_goal_automations_' . $step_id;

		$automations = get_option( $option_key, [] );
		if ( empty( $automations ) ) {
			delete_option( $option_key );
			bwf_unschedule_actions( 'bwfan_goal_step_updated', [ 'sid' => $step_id, 'goal_run' => $goal_run ] );

			return;
		}

		$updated_automations = $automations;
		$start_time          = time();
		foreach ( $automations as $key => $automation_contact_id ) {
			/** Checking 10 seconds of processing */
			if ( ( time() - $start_time ) > 10 ) {
				return;
			}

			$data = BWFAN_Model_Automation_Contact::get_data( $automation_contact_id );
			/** If automation contact is not active */
			if ( empty( $data ) || intval( $step_id ) !== intval( $data['last'] ) ) {
				unset( $updated_automations[ $key ] );
				update_option( $option_key, $updated_automations, false );
				continue;
			}
			$status = '';
			if ( 'wait' === $goal_run && 1 === absint( $data['status'] ) ) {
				$status = 4;
			}

			if ( 'continue' === $goal_run && 4 === absint( $data['status'] ) ) {
				$status = 1;
			}

			if ( ! empty( $status ) ) {
				$data = [ 'status' => $status ];
				BWFAN_Model_Automation_Contact::update( $data, array(
					'ID' => absint( $automation_contact_id ),
				) );
			} else {

				/** Add automation ended from */
				$reason = [
					'type' => BWFAN_Automation_Controller::$GOAL_UPDATE_WITH_END,
					'data' => [
						'sid' => $step_id
					]
				];
				$data   = BWFAN_Common::set_automation_ended_reason( $reason, $data );

				/** End Automation */
				self::end_v2_automation( 0, $data );
			}

			unset( $updated_automations[ $key ] );
			update_option( $option_key, $updated_automations, false );
		}

	}

	/**
	 * End v2 automation
	 *
	 * @param $a_cid
	 * @param $automation_contact
	 * @param $from
	 *
	 * @return bool
	 */
	public static function end_v2_automation( $a_cid, $automation_contact = [], $from = '' ) {
		if ( empty( $automation_contact ) && ! empty( $a_cid ) ) {
			$automation_contact = BWFAN_Model_Automation_Contact::get( $a_cid );
		}

		if ( ! is_array( $automation_contact ) || 0 === count( $automation_contact ) ) {
			return false;
		}

		if ( 'manually' === $from ) {
			$reason             = [
				'type' => BWFAN_Automation_Controller::$MANUAL_END,
				'data' => [
					'user_id' => get_current_user_id()
				]
			];
			$automation_contact = BWFAN_Common::set_automation_ended_reason( $reason, $automation_contact );
		}

		if ( absint( $automation_contact['last'] ) > 0 ) {
			/** set data for insert complete contact automation */
			$data = [
				'cid'    => $automation_contact['cid'],
				'aid'    => $automation_contact['aid'],
				'event'  => $automation_contact['event'],
				's_date' => $automation_contact['c_date'],
				'c_date' => current_time( 'mysql', 1 ),
				'data'   => $automation_contact['data'],
				'trail'  => $automation_contact['trail'],
			];

			BWFAN_Model_Automation_Complete_Contact::insert_ignore( $data, [
				'%d', // cid
				'%d', // aid
				'%s', // event
				'%s', // s_date
				'%s', // c_date
				'%s', // data
				'%s', // trail
			] );

			/** Update status as success for any step trail where status was waiting */
			BWFAN_Model_Automation_Contact_Trail::update_all_step_trail_status_complete( $automation_contact['trail'] );
		}

		/** Delete the automation contact entry */
		BWFAN_Model_Automation_Contact::delete( $automation_contact['ID'] );

		/** Update automation contact fields */
		self::update_automation_contact_fields( $automation_contact['cid'], $automation_contact['aid'] );

		return true;
	}

	/**
	 * Update automation contact fields
	 *
	 */
	public static function update_automation_contact_fields( $contact_id, $automation_id ) {
		if ( ! class_exists( 'BWFCRM_Contact' ) ) {
			return;
		}

		/** CRM contact object */
		$contact = new BWFCRM_Contact( $contact_id );

		if ( ! $contact->is_contact_exists() ) {
			return;
		}
		$automation_id = (string) $automation_id;

		/** Set automation id in active automation */
		$active_automations    = $contact->get_field_by_slug( 'automation-active' );
		$active_automation_ids = json_decode( $active_automations, true );
		$active_automation_ids = ( ! empty( $active_automation_ids ) && is_array( $active_automation_ids ) ) ? $active_automation_ids : array();

		/** Remove active automation from field */
		$aid_index = array_search( $automation_id, $active_automation_ids, true );
		if ( false !== $aid_index ) {
			unset( $active_automation_ids[ $aid_index ] );
		}

		$active_automation_ids = array_unique( $active_automation_ids );
		sort( $active_automation_ids );
		$active_automation_ids = wp_json_encode( $active_automation_ids );

		/** Set automation id in completed automation */
		$completed_automations    = $contact->get_field_by_slug( 'automation-completed' );
		$completed_automation_ids = json_decode( $completed_automations, true );
		$completed_automation_ids = ( ! empty( $completed_automation_ids ) && is_array( $completed_automation_ids ) ) ? $completed_automation_ids : array();

		$completed_automation_ids[] = $automation_id;

		$completed_automation_ids = array_unique( $completed_automation_ids );
		sort( $completed_automation_ids );
		$completed_automation_ids = wp_json_encode( $completed_automation_ids );

		/** Set updated automation active and completed automation value */
		$contact->set_field_by_slug( 'automation-active', $active_automation_ids );
		$contact->set_field_by_slug( 'automation-completed', $completed_automation_ids );
		$contact->save_fields();
	}


	public static function bwfan_automation_step_deleted( $sid ) {
		$option_key          = 'bwf_queued_automations_' . $sid;
		$automation_contacts = get_option( $option_key, [] );
		if ( empty( $automation_contacts ) ) {
			delete_option( $option_key );
			bwf_unschedule_actions( 'bwfan_automation_step_deleted', [ 'sid' => $sid ] );

			return;
		}
		$automation_contacts = json_decode( $automation_contacts, true );

		$updated_automations = $automation_contacts;
		$start_time          = time();
		$batch_size          = 20;
		while ( ( time() - $start_time ) < 10 ) {

			/** Get first 20 automation contacts */
			$automation_contacts = ( count( $automation_contacts ) > $batch_size ) ? array_slice( $automation_contacts, 0, $batch_size ) : $automation_contacts;

			$automation_contacts = array_filter( $automation_contacts, 'intval' );
			$stringPlaceholders  = array_fill( 0, count( $automation_contacts ), '%d' );
			$stringPlaceholders  = implode( ', ', $stringPlaceholders );

			global $wpdb;
			$query  = $wpdb->prepare( "SELECT `ID`,`trail` FROM {$wpdb->prefix}bwfan_automation_contact WHERE `last` = %d AND `ID` IN ( $stringPlaceholders ) AND (`status` = 1 OR `status` = 4) ", [ // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders generated via array_fill; arg count matches at runtime
				$sid,
				...$automation_contacts
			] );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders
			$result = $wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			if ( empty( $result ) ) {
				$updated_automations = array_diff( $updated_automations, $automation_contacts );
				sort( $updated_automations );
				update_option( $option_key, $updated_automations, false );
				$automation_contacts = $updated_automations;

				continue;
			}
			$ids = array_column( $result, 'ID' );

			/** Remove automation contact id if not in data */
			$remove_ids = array_diff( $automation_contacts, $ids );
			if ( ! empty( $remove_ids ) ) {
				$updated_automations = array_diff( $updated_automations, $remove_ids );
				sort( $updated_automations );
				update_option( $option_key, $updated_automations, false );
			}

			$trails = array_column( $result, 'trail' );
			$e_time = current_time( 'timestamp', 1 );
			BWFAN_Model_Automation_Contact::update_status_by_multiple_ids( $ids, 1, $e_time );
			BWFAN_Model_Automation_Contact_Trail::update_multiple_trail_status( $trails, $sid );

			/** Remove automation contact id from data after updating the status of automation and trail */
			$updated_automations = array_diff( $updated_automations, $ids );
			if ( empty( $updated_automations ) ) {
				delete_option( $option_key );

				return;
			}
			sort( $updated_automations );
			update_option( $option_key, $updated_automations, false );

			/** Assign updated automation contact */
			$automation_contacts = $updated_automations;
		}
	}

	/**
	 * Works with pro version only
	 *
	 * @return void
	 */
	public static function bwfan_store_automation_completed_ids() {
		$max_count = absint( get_option( 'bwfan_max_automation_completed', 0 ) );
		$processed = absint( get_option( 'bwfan_automation_completed_processed', 0 ) );

		global $wpdb;
		$query       = " SELECT `cid`, GROUP_CONCAT(`aid`) AS `aid` FROM `{$wpdb->prefix}bwfan_automation_complete_contact` WHERE `ID` <= {$max_count} GROUP BY `cid` ORDER BY `cid` ASC LIMIT {$processed},20";
		$automations = $wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		if ( empty( $automations ) ) {
			delete_option( 'bwfan_max_automation_completed' );
			delete_option( 'bwfan_automation_completed_processed' );
			bwf_unschedule_actions( 'bwfan_store_automation_completed_ids' );

			return;
		}

		$start_time = time();
		foreach ( $automations as $data ) {
			if ( ( time() - $start_time ) > 10 ) {
				return;
			}
			$aids = explode( ',', $data['aid'] );
			$aids = array_unique( $aids );
			$cid  = $data['cid'];

			/** CRM contact object */
			$contact = new BWFCRM_Contact( $cid );
			if ( ! $contact->is_contact_exists() ) {
				$processed ++;
				update_option( 'bwfan_automation_completed_processed', $processed );
				continue;
			}

			/** Get automation id in completed automation */
			$completed_aids = self::get_automation_contact_field_value( $contact, $aids, 'automation-completed' );

			$entered_aids = self::get_automation_contact_field_value( $contact, $aids, 'automation-entered' );

			$contact->set_field_by_slug( 'automation-completed', $completed_aids );
			$contact->set_field_by_slug( 'automation-entered', $entered_aids );
			$contact->save_fields();
			$processed ++;
			update_option( 'bwfan_automation_completed_processed', $processed );
		}
	}

	/**
	 * @param $contact BWFCRM_Contact
	 * @param $new_aids
	 * @param $field_slug
	 *
	 * @return false|string
	 */
	public static function get_automation_contact_field_value( $contact, $new_aids, $field_slug ) {
		$automation_aids = $contact->get_field_by_slug( $field_slug );
		$automation_aids = json_decode( $automation_aids, true );
		$automation_aids = ( ! empty( $automation_aids ) && is_array( $automation_aids ) ) ? $automation_aids : array();

		$automation_aids = array_merge( $automation_aids, $new_aids );
		$automation_aids = array_unique( $automation_aids );
		sort( $automation_aids );
		$automation_aids = wp_json_encode( $automation_aids );

		return $automation_aids;
	}

	/**
	 * Works with pro version only
	 *
	 * @return void
	 */
	public static function bwfan_store_automation_active_ids() {
		$max_count = absint( get_option( 'bwfan_max_active_automation', 0 ) );
		$processed = absint( get_option( 'bwfan_active_automation_processed', 0 ) );

		global $wpdb;
		$query       = "SELECT `cid`, GROUP_CONCAT(`aid`) AS `aid` FROM `{$wpdb->prefix}bwfan_automation_contact` WHERE `ID` <= {$max_count} GROUP BY `cid` ORDER BY `cid` ASC LIMIT {$processed},20";
		$automations = $wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

		if ( empty( $automations ) ) {
			delete_option( 'bwfan_max_active_automation' );
			delete_option( 'bwfan_active_automation_processed' );
			bwf_unschedule_actions( 'bwfan_store_automation_active_ids' );

			return;
		}

		$start_time = time();
		foreach ( $automations as $data ) {
			if ( ( time() - $start_time ) > 10 ) {
				return;
			}
			$aids = explode( ',', $data['aid'] );
			$aids = array_unique( $aids );
			$cid  = $data['cid'];

			/** CRM contact object */
			$contact = new BWFCRM_Contact( $cid );
			if ( ! $contact->is_contact_exists() ) {
				$processed ++;
				update_option( 'bwfan_active_automation_processed', $processed );
				continue;
			}

			/** Get automation ids in entered automation */
			$entered_aids = self::get_automation_contact_field_value( $contact, $aids, 'automation-entered' );

			/** Get automation ids in active automation */
			$active_aids = self::get_automation_contact_field_value( $contact, $aids, 'automation-active' );

			$contact->set_field_by_slug( 'automation-active', $active_aids );
			$contact->set_field_by_slug( 'automation-entered', $entered_aids );
			$contact->save_fields();
			$processed ++;
			update_option( 'bwfan_active_automation_processed', $processed );
		}
	}

	public static function get_contact_notes_type() {
		return apply_filters( 'bwfcrm_contact_note_types', array(
			array(
				'value' => 'billing',
				'label' => __( 'Billing', 'wp-marketing-automations' ),
			),
			array(
				'value' => 'shipping',
				'label' => __( 'Shipping', 'wp-marketing-automations' ),
			),
			array(
				'value' => 'refund',
				'label' => __( 'Refund', 'wp-marketing-automations' ),
			),
			array(
				'value' => 'subscription',
				'label' => __( 'Subscription', 'wp-marketing-automations' ),
			),
			array(
				'value' => 'feedback',
				'label' => __( 'Feedback', 'wp-marketing-automations' ),
			),
			array(
				'value' => 'log',
				'label' => __( 'Log', 'wp-marketing-automations' ),
			),
			array(
				'value' => 'others',
				'label' => __( 'Others', 'wp-marketing-automations' ),
			),
		) );
	}

	/**
	 * Checks if v1 older automation is active or not for functioning
	 *
	 * @return bool
	 */
	public static function is_automation_v1_active() {
		if ( defined( 'BWFAN_AUTOMATION_V1_DISABLE' ) && true === BWFAN_AUTOMATION_V1_DISABLE ) {
			return false;
		}

		$active = get_option( 'bwfan_automation_v1', 1 );

		return ( 1 === intval( $active ) ) ? true : false;
	}

	public static function bulk_contact_automation_end( $option_key, $args, $scheduler_action ) {
		$data = get_option( $option_key, [] );

		if ( empty( $data ) ) {
			delete_option( $option_key );
			bwf_unschedule_actions( $scheduler_action, $args );

			return;
		}

		$updated_data = $data;
		$start_time   = time();
		foreach ( $data as $key => $id ) {

			if ( ( time() - $start_time ) > 10 ) {
				return;
			}

			$automation_contact = BWFAN_Model_Automation_Contact::get( $id );
			/** End Automation */
			self::end_v2_automation( 0, $automation_contact, 'manually' );
			/** Update status as success for any step trail where status was waiting */
			BWFAN_Model_Automation_Contact_Trail::update_all_step_trail_status_complete( $automation_contact['trail'] );
			self::update_automation_contact_fields( $automation_contact['cid'], $automation_contact['aid'] );

			unset( $updated_data[ $key ] );
			update_option( $option_key, $updated_data, false );
		}

	}

	public static function bwfan_automation_contact_bulk_action( $dynamic_str, $aid, $action, $status ) {
		if ( 'end' === $action ) {
			$option_key       = "bwfan_bulk_automation_contact_end_{$dynamic_str}";
			$args             = array( 'key' => $dynamic_str, 'aid' => $aid, 'action' => $action, 'status' => $status );
			$scheduler_action = 'bwfan_automation_contact_bulk_action';
			self::bulk_contact_automation_end( $option_key, $args, $scheduler_action );

			return;
		}
		$option_key = "bwfan_bulk_automation_contact_{$action}_{$dynamic_str}";
		$a_cids     = get_option( $option_key, [] );
		if ( empty( $a_cids ) ) {
			delete_option( $option_key );
			bwf_unschedule_actions( 'bwfan_automation_contact_bulk_action', [ 'key' => $dynamic_str, 'aid' => $aid, 'action' => $action, 'status' => $status ] );

			return;
		}

		if ( 'completed' === $status && 'rerun' !== $action ) {
			$action = 'delete_complete';
		}
		self::perform_bulk_action( $a_cids, $option_key, $action, $status );
	}

	public static function perform_bulk_action( $a_cids, $option_key, $action = '', $status = '' ) {
		$updated_a_cids = $a_cids;
		$start_time     = time();
		$batch_size     = 20;
		while ( ( time() - $start_time ) < 10 ) {
			/** Get first 20 automation contacts */
			$a_cids = ( count( $a_cids ) > $batch_size ) ? array_slice( $a_cids, 0, $batch_size ) : $a_cids;
			if ( empty( $a_cids ) ) {
				delete_option( $option_key );

				return;
			}

			$a_cids             = array_filter( $a_cids, 'intval' );
			$stringPlaceholders = array_fill( 0, count( $a_cids ), '%d' );
			$stringPlaceholders = implode( ', ', $stringPlaceholders );
			global $wpdb;
			if ( 'delete_complete' === $action || 'completed' === $status ) {
				$query = $wpdb->prepare( " SELECT `ID`,`cid`,`aid`,`trail`,`event`,`data` FROM {$wpdb->prefix}bwfan_automation_complete_contact WHERE `ID` IN ( $stringPlaceholders ) ", $a_cids );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders
			} else {
				$query = $wpdb->prepare( " SELECT `ID`,`cid`,`aid`,`trail` FROM {$wpdb->prefix}bwfan_automation_contact WHERE `ID` IN ( $stringPlaceholders )", $a_cids );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders
			}

			try {
				BWFAN_Common::log_test_data( $option_key . ' - ' . $query, __FUNCTION__ );
				$result = $wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			} catch ( Error $e ) {
				delete_option( $option_key );

				return;
			}

			if ( empty( $result ) ) {
				$updated_a_cids = array_diff( $updated_a_cids, $a_cids );
				sort( $updated_a_cids );
				update_option( $option_key, $updated_a_cids, false );
				$a_cids = $updated_a_cids;

				continue;
			}
			$ids = array_column( $result, 'ID' );

			/** Remove automation contact id if not in data */
			$remove_ids = array_diff( $a_cids, $ids );
			if ( ! empty( $remove_ids ) ) {
				$updated_a_cids = array_diff( $updated_a_cids, $remove_ids );
				sort( $updated_a_cids );
				update_option( $option_key, $updated_a_cids, false );
			}

			$trails = array_column( $result, 'trail' );
			switch ( true ) {
				case ( 'delete' === $action || 'delete_complete' === $action ):
					self::delete_automations( $ids, $trails, $action, $result );
					break;
				case ( 'paused' === $action ) :
					BWFAN_Model_Automation_Contact::update_status_by_multiple_ids( $ids, 3 );
					break;
				case ( 'unpaused' === $action ):
					BWFAN_Model_Automation_Contact::update_status_by_multiple_ids( $ids );
					break;
				case ( 'retry' === $action ):
					BWFAN_Model_Automation_Contact::update_status_by_multiple_ids( $ids, 6 );
					break;
				case ( 'run_now' === $action ):
					BWFAN_Model_Automation_Contact::update_e_time_col_of_ids( $ids );
					break;
				case ( 'rerun' === $action || 're_run' === $action ):
					self::insert_automations( $result );
					break;
				case ( 'startbegin' === $action ):
					$status = 1;
					$e_time = current_time( 'timestamp', 1 );
					self::update_automation_status( $ids, $status, $trails, $e_time, true );
					break;
			}

			/** Remove automation contact id from data after updating the status of automation and trail */
			$updated_a_cids = array_diff( $updated_a_cids, $ids );
			if ( empty( $updated_a_cids ) ) {
				delete_option( $option_key );

				return;
			}
			sort( $updated_a_cids );
			update_option( $option_key, $updated_a_cids, false );

			/** Assign updated automation contact */
			$a_cids = $updated_a_cids;
		}
	}

	public static function update_automation_status( $ids, $status, $trails, $e_time = '', $last = false ) {
		BWFAN_Model_Automation_Contact::update_status_by_multiple_ids( $ids, $status, $e_time, $last );
		if ( 1 === $status ) {
			BWFAN_Model_Automation_Contact_Trail::update_multiple_trail_status( $trails, 0 );
		}
	}

	public static function delete_automations( $ids, $trails, $action = '', $automation_data = [] ) {
		global $wpdb;

		$table_name = "{$wpdb->prefix}bwfan_automation_contact";
		if ( 'delete_complete' === $action ) {
			$table_name = "{$wpdb->prefix}bwfan_automation_complete_contact";
		}
		if ( ! empty( $ids ) ) {
			$ids                = array_filter( $ids, 'intval' );
			$stringPlaceholders = array_fill( 0, count( $ids ), '%d' );
			$stringPlaceholders = implode( ', ', $stringPlaceholders );

			$query = $wpdb->prepare( "DELETE FROM {$table_name} WHERE `ID` IN ( $stringPlaceholders )", $ids );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders
			$wpdb->query( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		}

		if ( ! empty( $trails ) ) {
			$trails      = array_filter( $trails );
			$placeholder = array_fill( 0, count( $trails ), '%s' );
			$placeholder = implode( ', ', $placeholder );

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bwfan_automation_contact_trail WHERE tid IN ( $placeholder )", $trails ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		}


		if ( ! class_exists( 'BWFCRM_Contact' ) ) {
			/** Works with pro version only */
			return;
		}

		foreach ( $automation_data as $data ) {
			self::maybe_remove_aid_from_contact_fields( $data['cid'], $data['aid'] );
		}
	}

	/**
	 * Get automation active and completed count then remove from contact fields
	 */
	public static function maybe_remove_aid_from_contact_fields( $cid, $aid ) {
		if ( ! class_exists( 'BWFCRM_Contact' ) ) {
			/** Works with pro version only */
			return;
		}

		/** Get active automation contact count */
		$active = BWFAN_Model_Automation_Contact::get_automation_count_by_cid( $cid, $aid );

		/** if  active count is 0 then remove automation id from automation completed field */
		if ( 0 === absint( $active ) ) {
			self::remove_aid_from_contact_field( $cid, $aid, 'automation-active' );
		}

		/**Get completed automation contact count */
		$completed = BWFAN_Model_Automation_Complete_Contact::get_automation_count_by_cid( $cid, $aid );

		/** if  active and completed count is 0 then remove automation id from automation entered field */
		$is_entered = absint( $active ) + absint( $completed );
		if ( 0 === $is_entered ) {
			self::remove_aid_from_contact_field( $cid, $aid, 'automation-entered' );
		}

		/** if  completed count is 0 then remove automation id from automation completed field */
		if ( 0 === absint( $completed ) ) {
			self::remove_aid_from_contact_field( $cid, $aid, 'automation-completed' );
		}
	}

	/**
	 * Remove automation id from contact fields
	 */
	public static function remove_aid_from_contact_field( $cid, $removed_aid, $field_slg ) {
		$contact = new BWFCRM_Contact( $cid );

		if ( ! $contact->is_contact_exists() ) {
			return;
		}

		$automations    = $contact->get_field_by_slug( $field_slg );
		$automation_ids = json_decode( $automations, true );
		$automation_ids = ( ! empty( $automation_ids ) && is_array( $automation_ids ) ) ? $automation_ids : array();

		if ( empty( $automation_ids ) ) {
			return;
		}
		$aid = (string) $removed_aid;
		/**remove automation from field */
		$aid_index = array_search( $aid, $automation_ids, true );
		if ( false !== $aid_index ) {
			unset( $automation_ids[ $aid_index ] );
		}
		sort( $automation_ids );
		$automation_ids = wp_json_encode( $automation_ids, true );
		$contact->set_field_by_slug( $field_slg, $automation_ids );
		$contact->save_fields();

	}

	public static function bwfan_bulk_action( $option_key, $type, $cart_type = '' ) {
		$option_key = "bwfan_bulk_action_{$option_key}";

		$ids = get_option( $option_key, [] );

		if ( empty( $ids ) ) {
			delete_option( $option_key );
			bwf_unschedule_actions( "bwfan_bulk_action", [ 'key' => $option_key, 'type' => $type ] );

			return;
		}

		$updated_ids = $ids;
		$start_time  = time();
		$batch_size  = 20;
		while ( ( time() - $start_time ) < 15 ) {

			/** Get first 20 ids */
			$ids          = ( count( $ids ) > $batch_size ) ? array_slice( $ids, 0, $batch_size ) : $ids;
			$ids          = array_filter( $ids, 'intval' );
			$selected_ids = implode( ', ', $ids );

			$stringPlaceholders = array_fill( 0, count( $ids ), '%d' );
			$stringPlaceholders = implode( ', ', $stringPlaceholders );
			global $wpdb;

			switch ( true ) {
				case ( 'tag' === $type || 'list' === $type || 'audience' === $type ):
					$query = "DELETE FROM {$wpdb->prefix}bwfan_terms WHERE `ID` IN ( $stringPlaceholders )";
					$wpdb->query( $wpdb->prepare( $query, $ids ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
					break;
				case ( 'automation_v1' === $type || 'automation_v2' === $type ):
					BWFAN_Core()->automations->delete_automation( $ids );
					BWFAN_Core()->automations->delete_automationmeta( $ids );
					if ( 'automation_v2' === $type ) {
						self::delete_automation_v2_migrations( $ids );
					}
					if ( 'automation_v1' === $type ) {
						self::delete_automation_v1_migrations( $ids );
					}
					break;
				case ( 'broadcast' === $type ):
					// store broadcast data before delete
					if ( class_exists( 'BWFCRM_Category' ) && method_exists( 'BWFCRM_Category', 'handle_category_count_decrease' ) ) {
						if ( isset( BWFCRM_Category::$BROADCAST ) && is_array( $ids ) && ! empty( $ids ) ) {
							BWFCRM_Category::handle_category_count_decrease( $ids, BWFCRM_Category::$BROADCAST );
						}
					}
					$query = "DELETE FROM {$wpdb->prefix}bwfan_broadcast WHERE `id` IN ( $stringPlaceholders )";
					$wpdb->query( $wpdb->prepare( $query, $ids ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

					/** Remove parent from child broadcasts */
					if ( bwfan_is_autonami_pro_active() && method_exists( 'BWFAN_Model_Broadcast', 'remove_parent_from_child' ) ) {
						BWFAN_Model_Broadcast::remove_parent_from_child( $ids );
					}

					break;
				case ( 'template' === $type ):
					BWFAN_Model_Templates::bwf_delete_template( $ids );
					break;
				case ( 'form' === $type ):
					if ( class_exists( 'BWFCRM_Category' ) && method_exists( 'BWFCRM_Category', 'handle_category_count_decrease' ) ) {
						if ( isset( BWFCRM_Category::$FORM ) && is_array( $ids ) && ! empty( $ids ) ) {
							BWFCRM_Category::handle_category_count_decrease( $ids, BWFCRM_Category::$FORM );
						}
					}
					$query = "DELETE FROM {$wpdb->prefix}bwfan_form_feeds WHERE `ID` IN ( $stringPlaceholders )";
					$wpdb->query( $wpdb->prepare( $query, $ids ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
					break;
				case ( 'cart' === $type ):
					if ( 'recovered' === $cart_type ) {
						BWFAN_Recoverable_Carts::delete_recovered_carts( $selected_ids );
					} else {
						$query = "DELETE FROM {$wpdb->prefix}bwfan_abandonedcarts WHERE `ID` IN ($stringPlaceholders )";
						$wpdb->query( $wpdb->prepare( $query, $ids ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
					}
					break;
				case ( 'recovered-cart' === $type ):
					$query = "DELETE FROM {$wpdb->prefix}postmeta WHERE (`meta_key` = '_bwfan_recovered_ab_id' OR `meta_key` = '_bwfan_ab_cart_recovered_a_id') AND `post_id` IN ( $stringPlaceholders )";
					$wpdb->query( $wpdb->prepare( $query, $ids ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
					break;
			}

			/** Remove id from data after deleting */
			$updated_ids = array_diff( $updated_ids, $ids );
			if ( empty( $updated_ids ) ) {
				delete_option( $option_key );

				return;
			}
			sort( $updated_ids );
			update_option( $option_key, $updated_ids, false );

			/** Assign updated ids */
			$ids = $updated_ids;
		}

		return $ids;
	}

	public static function delete_automation_v2_migrations( $aids ) {
		BWFAN_Model_Automation_Step::delete_steps_by_aid( $aids );
		BWFAN_Model_Automation_Contact::delete_automation_contact_by_aid( $aids );
		BWFAN_Model_Automation_Complete_Contact::delete_automation_contact_by_aid( $aids );
		BWFAN_Model_Automation_Contact_Trail::delete_automation_trail_by_id( $aids );
	}

	public static function delete_automation_v1_migrations( $aids ) {
		BWFAN_Core()->tasks->delete_tasks( array(), $aids );
		BWFAN_Core()->logs->delete_logs( array(), $aids );
		BWFAN_Core()->automations->set_automation_id( $aids );
	}

	/**
	 * @return void
	 */
	public static function delete_expired_dynamic_coupons() {
		global $wpdb;
		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$coupons = $wpdb->get_results( $wpdb->prepare( "
                                                SELECT m1.post_id as id
                                                FROM {$wpdb->prefix}postmeta as m1
                                                LEFT JOIN {$wpdb->prefix}postmeta as m2
                                                ON m1.post_id = m2.post_id
                                                WHERE m1.meta_key = %s
                                                AND m1.meta_value = %d
                                                AND m2.meta_key = %s
                                                AND TIMESTAMPDIFF(MINUTE,m2.meta_value,UTC_TIMESTAMP) > %d
                                                LIMIT %d
                                                ", '_is_bwfan_coupon', 1, 'expiry_date', 0, 20 ) );

		if ( empty( $coupons ) ) {
			bwf_unschedule_actions( 'bwfan_delete_expired_coupons' );

			return;
		}

		foreach ( $coupons as $coupon ) {
			wp_delete_post( $coupon->id, true );
		}
	}

	public static function append_extra_url_arguments( $url, $url_utm_args = [] ) {
		$utm_campaign = filter_input( INPUT_GET, 'utm_campaign' );
		if ( ! is_null( $utm_campaign ) && ! empty( $utm_campaign ) ) {
			$url_utm_args['utm_campaign'] = $utm_campaign;
		}

		$utm_term = filter_input( INPUT_GET, 'utm_term' );
		if ( ! is_null( $utm_term ) && ! empty( $utm_term ) ) {
			$url_utm_args['utm_term'] = $utm_term;
		}

		$utm_source = filter_input( INPUT_GET, 'utm_source' );
		if ( ! is_null( $utm_source ) && ! empty( $utm_source ) ) {
			$url_utm_args['utm_source'] = $utm_source;
		}

		$utm_content = filter_input( INPUT_GET, 'utm_content' );
		if ( ! is_null( $utm_content ) && ! empty( $utm_content ) ) {
			$url_utm_args['utm_content'] = $utm_content;
		}

		$utm_medium = filter_input( INPUT_GET, 'utm_medium' );
		if ( ! is_null( $utm_medium ) && ! empty( $utm_medium ) ) {
			$url_utm_args['utm_medium'] = $utm_medium;
		}

		$url = add_query_arg( $url_utm_args, $url );

		return $url;
	}

	public static function get_contact_columns() {
		$columns = get_user_meta( get_current_user_id(), '_bwfan_contact_columns_v2', true );
		if ( is_array( $columns ) ) {
			return $columns;
		}
		$wc_columns      = [];
		$default_columns = [
			[
				'country'    => 'Country/Region',
				'label'      => __( 'Country/Region', 'wp-marketing-automations' ),
				'groupKey'   => 'geography',
				'groupLabel' => __( 'Geography', 'wp-marketing-automations' ),
			],
			[
				'state'      => 'State',
				'label'      => __( 'State', 'wp-marketing-automations' ),
				'groupKey'   => 'geography',
				'groupLabel' => __( 'Geography', 'wp-marketing-automations' ),
			],
			[
				'tags'       => 'Tags',
				'label'      => __( 'Tags', 'wp-marketing-automations' ),
				'groupKey'   => 'segments',
				'groupLabel' => __( 'Segments', 'wp-marketing-automations' ),
			],
			[
				'lists'      => __( 'Lists', 'wp-marketing-automations' ),
				'label'      => 'Lists',
				'groupKey'   => 'segments',
				'groupLabel' => __( 'Segments', 'wp-marketing-automations' ),
			]
		];

		if ( class_exists( 'WooCommerce' ) ) {
			$wc_columns = [
				[
					'total_order_count' => 'Orders Count',
					'label'             => __( 'Orders Count', 'wp-marketing-automations' ),
					'groupKey'          => 'woocommerce',
					'groupLabel'        => __( 'WooCommerce', 'wp-marketing-automations' ),
				],
				[
					'total_order_value' => 'Total Revenue',
					'label'             => __( 'Total Revenue', 'wp-marketing-automations' ),
					'groupKey'          => 'woocommerce',
					'groupLabel'        => __( 'WooCommerce', 'wp-marketing-automations' ),
				],
				[
					'l_order_date' => 'Last Order Date',
					'label'        => __( 'Last Order Date', 'wp-marketing-automations' ),
					'groupKey'     => 'woocommerce',
					'groupLabel'   => __( 'WooCommerce', 'wp-marketing-automations' ),
				],
			];
		}

		return array_merge( $default_columns, $wc_columns );
	}

	/**
	 * Check if contact row found in the unsubscribe table and remove them.
	 *
	 * @param $contact
	 *
	 * @return bool
	 */
	public static function maybe_delete_unsubscribe_rows( $contact ) {
		if ( ! $contact instanceof WooFunnels_Contact ) {
			return false;
		}

		$email      = $contact->get_email();
		$contact_no = $contact->get_contact_no();
		$data       = array(
			'recipient' => array( $email, $contact_no ),
		);

		$rows = BWFAN_Model_Message_Unsubscribe::get_message_unsubscribe_row( $data, false );
		if ( empty( $rows ) ) {
			return false;
		}

		$p_keys = array_column( $rows, 'ID' );
		foreach ( $p_keys as $key ) {
			BWFAN_Model_Message_Unsubscribe::delete( $key );
		}

		return true;
	}

	public static function get_admin_analytics_cache_lifespan() {
		$time = apply_filters( 'bwfan_admin_analytics_cache_lifespan', ( 1 * HOUR_IN_SECONDS ) );

		return ( intval( $time ) > 0 ) ? intval( $time ) : ( 1 * HOUR_IN_SECONDS );
	}

	/**
	 * @param $diff_time
	 *
	 * @return string
	 */
	public static function get_difference_string( $diff_time ) {
		if ( empty( $diff_time ) || ! is_object( $diff_time ) ) {
			return '';
		}
		if ( $diff_time->y > 0 ) {
			return $diff_time->y . __( ' year ago', 'wp-marketing-automations' );
		}
		if ( $diff_time->m > 0 ) {
			return $diff_time->m . __( ' months ago', 'wp-marketing-automations' );
		}
		if ( $diff_time->d > 0 ) {
			return $diff_time->d . __( ' days ago', 'wp-marketing-automations' );
		}
		if ( $diff_time->h > 0 ) {
			return $diff_time->h . __( ' hours ago', 'wp-marketing-automations' );
		}
		if ( $diff_time->i > 0 ) {
			return $diff_time->i . __( ' minutes ago', 'wp-marketing-automations' );
		}
		if ( $diff_time->m > 0 ) {
			return $diff_time->s . __( ' seconds ago', 'wp-marketing-automations' );
		}

		return '';
	}

	public static function ping_woofunnels_worker() {
		/** Allow site owners to suppress the WooFunnels-core worker loopback/self-request. */
		if ( true === apply_filters( 'bwfan_stop_ping_woofunnels_worker', false ) ) {
			return;
		}

		$url  = rest_url( '/woofunnels/v1/worker' ) . '?' . time();
		$args = array(
			'method'    => 'GET',
			'body'      => array(),
			'timeout'   => apply_filters( 'bwfan_as_worker_timeout_v2', 2 ), // Allow time for WordPress to bootstrap and send early response
			'sslverify' => false,
		);

		wp_remote_post( $url, $args );
	}

	public static function get_formatted_price_wc( $price, $raw = true, $currency = '' ) {
		if ( true === $raw ) {
			return $price;
		}

		$decimal_separator  = wc_get_price_decimal_separator();
		$thousand_separator = wc_get_price_thousand_separator();
		$decimals           = wc_get_price_decimals();

		if ( apply_filters( 'woocommerce_price_trim_zeros', false ) && $decimals > 0 ) {
			$price = wc_trim_zeros( $price );
		}

		$output = number_format( $price, $decimals, $decimal_separator, $thousand_separator );
		if ( ! empty( $currency ) ) {
			$position = get_option( 'woocommerce_currency_pos' );
			$prefix   = '';
			$suffix   = '';

			switch ( $position ) {
				case 'left_space':
					$prefix = $currency . ' ';
					break;
				case 'left':
					$prefix = $currency;
					break;
				case 'right_space':
					$suffix = ' ' . $currency;
					break;
				case 'right':
					$suffix = $currency;
					break;
			}

			$output = $prefix . $output . $suffix;
		}

		return $output;
	}

	public static function get_formatting_for_wc_price( $attr, $order ) {
		$formatting      = isset( $attr['format'] ) && ! empty( $attr['format'] ) ? $attr['format'] : 'raw';
		$raw             = ( 'raw' === strval( $formatting ) ) ? true : false;
		$currency_format = '';
		if ( 'formatted-currency' === strval( $formatting ) ) {
			$currency        = ! empty( $order ) && ! is_null( $order->get_currency() ) ? $order->get_currency() : get_option( 'woocommerce_currency' );
			$currency_symbol = get_woocommerce_currency_symbol( $currency );
			$currency_format = html_entity_decode( $currency_symbol, ENT_QUOTES | ENT_HTML401 );
		}

		return array( 'raw' => $raw, 'currency' => $currency_format );
	}

	/**
	 * Advanced logging to check wp-json endpoint for event enabled or not
	 *
	 * @return bool
	 */
	public static function event_cb_advanced_log_enabled() {
		if ( self::is_log_enabled( 'bwfan_end_point_logging' ) ) {
			return true;
		}
		if ( defined( 'BWFAN_ALLOW_EVENT_ENDPOINT_LOGS' ) && true === BWFAN_ALLOW_EVENT_ENDPOINT_LOGS ) {
			return true;
		}
		if ( true === apply_filters( 'bwfan_allow_event_endpoint_logs', false ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Advanced logging for developers to check wp-json endpoint working or not
	 *
	 * @param $log
	 *
	 * @return void
	 */
	public static function event_advanced_logs( $log ) {
		if ( empty( $log ) || false === self::event_cb_advanced_log_enabled() ) {
			return;
		}
		$log = array(
			't' => microtime( true ),
			'm' => $log,
		);
		BWFAN_Common::log_test_data( $log, 'fka-event-endpoint-check', true );
	}

	/**
	 * Update Contact WP User ID to 0 after a WP user is deleted
	 *
	 * @param $user_id
	 *
	 * @return void
	 */
	public static function update_contact_wp_id( $user_id ) {
		$bwf_contact = new WooFunnels_Contact( $user_id );
		if ( 0 === intval( $bwf_contact->get_id() ) ) {
			return;
		}

		$bwf_contact->set_wpid( 0 );
		$bwf_contact->save();
	}

	/**
	 * Get midnight time of tomorrow in store timezone
	 *
	 * @return int
	 */
	public static function get_midnight_store_time() {
		$timezone = new DateTimeZone( wp_timezone_string() );
		$date     = new DateTime();
		$date->modify( '+1 days' );
		$date->setTimezone( $timezone );
		$date->setTime( 0, 0, 0 );

		return $date->getTimestamp();
	}

	/**
	 * Fetch updated data
	 *
	 * @param $meta
	 *
	 * @return mixed
	 */
	public static function fetch_updated_data( $meta ) {
		if ( ! bwfan_is_autonami_pro_active() || ( ! isset( $meta['tags'] ) && ! isset( $meta['list'] ) ) ) {
			return $meta;
		}

		if ( isset( $meta['tags'] ) ) {
			$meta['tags'] = self::get_updated_tags_and_list( $meta['tags'] );
		}
		if ( isset( $meta['list'] ) ) {
			$meta['list'] = self::get_updated_tags_and_list( $meta['list'] );
		}

		return $meta;
	}

	/**
	 * Modify automation steps data, fetch latest names in admin
	 *
	 * @param $step_type
	 * @param $data
	 *
	 * @return mixed
	 */
	public static function modify_step_admin_data( $step_type, $data ) {
		if ( ! bwfan_is_autonami_pro_active() ) {
			return $data;
		}
		if ( defined( 'BWFAN_Disable_Automation_Fetch_Latest_Names' ) && 1 === intval( BWFAN_Disable_Automation_Fetch_Latest_Names ) ) {
			return $data;
		}

		if ( 'conditional' === $step_type ) {
			return BWFAN_Common::get_updated_tags_and_list_conditions( $data );
		}

		if ( 'benchmark' === $step_type ) {
			if ( isset( $data->list ) ) {
				$data->list = BWFAN_Common::get_updated_tags_and_list( (array) $data->list );
			}
			if ( isset( $data->tags ) ) {
				$data->tags = BWFAN_Common::get_updated_tags_and_list( (array) $data->tags );
			}

			return $data;
		}

		if ( 'action' === $step_type ) {
			if ( isset( $data->tags ) ) {
				$data->tags = BWFAN_Common::get_updated_tags_and_list( (array) $data->tags );
			}
			if ( isset( $data->list_id ) ) {
				$data->list_id = BWFAN_Common::get_updated_tags_and_list( (array) $data->list_id );
			}
		}

		return $data;
	}

	/**
	 * Get updated tags & lists names
	 *
	 * @param $terms
	 * @param $id_key
	 * @param $name_key
	 *
	 * @return array|array[]|mixed|object[]
	 */
	public static function get_updated_tags_and_list( $terms, $id_key = 'id', $name_key = 'name' ) {
		if ( ! bwfan_is_autonami_pro_active() ) {
			return $terms;
		}

		return array_map( function ( $term ) use ( $id_key, $name_key ) {
			$obj = false;
			/** If data is in object then convert into array */
			if ( is_object( $term ) ) {
				$term = (array) $term;
				$obj  = true;
			}

			$data = BWFAN_Model_Terms::get( $term[ $id_key ] );

			$term[ $name_key ] = is_array( $data ) && ! empty( $data['name'] ) ? $data['name'] : $term[ $name_key ];

			return ( true === $obj ) ? (object) $term : $term;
		}, $terms );
	}

	/**
	 * Get updated tags & lists names for conditional step
	 *
	 * @param $rules
	 *
	 * @return mixed
	 */
	public static function get_updated_tags_and_list_conditions( $rules ) {
		if ( empty( $rules ) ) {
			return $rules;
		}

		foreach ( $rules as $or_key => $or_rule ) {
			if ( ! is_array( $or_rule ) ) {
				continue;
			}
			/** OR block */
			foreach ( $or_rule as $and_key => $rule ) {
				/** AND block */
				if ( ! isset( $rule->filter ) || ! in_array( $rule->filter, [ 'contact_tags', 'contact_lists' ] ) ) {
					continue;
				}

				$rules[ $or_key ][ $and_key ]->data = self::get_updated_tags_and_list( $rule->data, 'key', 'label' );
			}
		}

		return $rules;
	}

	/**
	 * Get contact marketing status
	 *
	 * @param $contact_status
	 * @param $email
	 * @param $phone
	 *
	 * @return array
	 */
	public static function get_contact_status( $contact_status = 0, $email = '', $phone = '' ) {
		$response = [
			'status'       => $contact_status,
			'email_status' => 1,
			'sms_status'   => 1
		];
		if ( empty( $email ) && empty( $phone ) ) {
			return $response;
		}
		$data = array(
			'recipient' => array( $email, $phone ),
		);

		$unsubscribed_rows = BWFAN_Model_Message_Unsubscribe::get_message_unsubscribe_row( $data, false );
		if ( empty( $unsubscribed_rows ) || 0 === count( $unsubscribed_rows ) ) {
			return $response;
		}

		$unsubscribed_rows        = array_column( $unsubscribed_rows, 'recipient' );
		$response['email_status'] = in_array( $email, $unsubscribed_rows, true ) ? 0 : 1;
		$response['sms_status']   = in_array( $phone, $unsubscribed_rows, true ) ? 0 : 1;

		return $response;
	}

	/**
	 * product_cat term IDs for automation/order "category of product" matching.
	 * Order line items often reference a {@see WC_Product_Variation}; `product_cat` is stored on
	 * the parent, so `WC_Product::get_category_ids()` can be empty. Fall back to the parent.
	 *
	 * @param WC_Product|null $product Line product from `WC_Order_Item_Product::get_product()`.
	 * @return int[] Term IDs (list may be empty).
	 */
	public static function get_wc_product_category_ids_for_order_line( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return array();
		}
		$ids = $product->get_category_ids();
		if ( ! empty( $ids ) && is_array( $ids ) ) {
			return $ids;
		}
		if ( self::is_variation( $product ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$parent = wc_get_product( $parent_id );
				if ( $parent instanceof WC_Product ) {
					$ids = $parent->get_category_ids();
					return ( ! empty( $ids ) && is_array( $ids ) ) ? $ids : array();
				}
			}
		}

		return array();
	}

	/**
	 * product_tag term IDs for a line product. Variations often have no tags; the parent variable product holds `product_tag`.
	 * Same parent merge pattern as {@see get_wc_product_category_ids_for_order_line()}.
	 *
	 * @param WC_Product|null $product Line product from `WC_Order_Item_Product::get_product()`.
	 * @return int[] Term IDs (list may be empty).
	 */
	public static function get_wc_product_tag_ids_for_order_line( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return array();
		}
		$ids = $product->get_tag_ids();
		if ( ! empty( $ids ) && is_array( $ids ) ) {
			return $ids;
		}
		if ( self::is_variation( $product ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$parent = wc_get_product( $parent_id );
				if ( $parent instanceof WC_Product ) {
					$ids = $parent->get_tag_ids();
					return ( ! empty( $ids ) && is_array( $ids ) ) ? $ids : array();
				}
			}
		}

		return array();
	}

	/**
	 * Validate create new order settings
	 *
	 * @param $data
	 *
	 * @return bool
	 */
	public static function validate_create_order_event_setting( $data ) {
		/** Any product case */
		if ( ! isset( $data['event_meta'] ) || ! isset( $data['event_meta']['order-contains'] ) || 'any' === $data['event_meta']['order-contains'] ) {
			return true;
		}

		$order = wc_get_order( intval( $data['order_id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		/** Specific category case */
		if ( 'selected_category' === $data['event_meta']['order-contains'] ) {
			$get_selected_categories = isset( $data['event_meta']['product_categories'] ) ? $data['event_meta']['product_categories'] : [];
			if ( ! is_array( $get_selected_categories ) || empty( $get_selected_categories ) ) {
				return false;
			}

			$category_ids = array_column( $get_selected_categories, 'id' );
			if ( ! is_array( $category_ids ) || empty( $category_ids ) ) {
				return false;
			}

			$order_items = $order->get_items();
			if ( ! is_array( $order_items ) || empty( $order_items ) ) {
				return false;
			}

			// Collect all categories from all products in the order
			$order_product_categories = [];
			foreach ( $order_items as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();
				if ( ! $product instanceof WC_Product ) {
					continue;
				}

				$product_categories = self::get_wc_product_category_ids_for_order_line( $product );
				if ( is_array( $product_categories ) && ! empty( $product_categories ) ) {
					$order_product_categories = array_merge( $order_product_categories, $product_categories );
				}
			}

			// Remove duplicates and check for intersection
			$order_product_categories = array_unique( $order_product_categories );

			return ! empty( array_intersect( $category_ids, $order_product_categories ) );
		}

		/** Specific product case */
		$get_selected_product = isset( $data['event_meta']['products'] ) ? $data['event_meta']['products'] : [];
		$ordered_products     = array();
		foreach ( $order->get_items() as $item ) {
			$ordered_products[] = $item->get_product_id();

			/** In case variation */
			if ( $item->get_variation_id() ) {
				$ordered_products[] = $item->get_variation_id();
			}
		}

		/** Selected product and ordered products */
		$product_selected = array_column( $get_selected_product, 'id' );
		$ordered_products = array_unique( $ordered_products );
		sort( $ordered_products );

		return count( array_intersect( $product_selected, $ordered_products ) ) > 0;
	}

	/**
	 * Update Automation meta data
	 * @return void
	 */
	public static function update_meta_automations_v2() {
		$key       = 'bwfan_automation_v2_meta_normalize';
		$offset_id = get_option( $key );
		if ( intval( $offset_id ) < 1 ) {
			delete_option( $key );
			bwf_unschedule_actions( 'bwfan_update_meta_automations_v2' );

			return;
		}

		global $wpdb;
		$query = "SELECT am.meta_value, a.ID FROM `{$wpdb->prefix}bwfan_automationmeta` AS am JOIN `{$wpdb->prefix}bwfan_automations` AS a ON am.bwfan_automation_id = a.ID WHERE a.ID >= %d AND am.meta_key = %s AND a.v = %d LIMIT 20";
		$rows  = $wpdb->get_results( $wpdb->prepare( $query, $offset_id, 'steps', 2 ), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		if ( empty( $rows ) ) {
			delete_option( $key );
			bwf_unschedule_actions( 'bwfan_update_meta_automations_v2' );

			return;
		}

		$start_time = time();
		foreach ( $rows as $single ) {
			if ( ( time() - $start_time ) > 10 ) {
				return;
			}

			if ( ! isset( $single['meta_value'] ) ) {
				continue;
			}
			$meta_value = maybe_unserialize( $single['meta_value'] );

			if ( ! is_array( $meta_value ) ) {
				continue;
			}

			$meta_value = array_map( function ( $data ) {
				if ( isset( $data['data'] ) && ! in_array( $data['type'], array( 'yesNoNode', 'splitpath', 'branchpath' ), true ) ) {
					$data['data'] = [];
				}

				return $data;
			}, $meta_value );

			BWFAN_Model_Automationmeta::update_automation_meta_values( $single['ID'], [ 'steps' => maybe_serialize( $meta_value ) ] );

			/** updating the option key for next call */
			update_option( 'bwfan_automation_v2_meta_normalize', ( intval( $single['ID'] ) + 1 ) );
		}
	}

	/**
	 * deleting logs older than 30 days
	 * scheduler runs every month
	 * Handles autonami-logs, buildwoofunnels-logs, and fk-temp-logs directories
	 * Processes files in top-level directories only (non-recursive)
	 *
	 * @return void
	 */
	public static function delete_bwfan_logs() {
		$wp_dir   = wp_upload_dir();
		$base_dir = $wp_dir['basedir'] . '/funnelkit/';

		// Directories to clean
		$log_directories = array(
			'autonami-logs',
			'buildwoofunnels-logs',
			'fk-temp-logs',
		);

		$expire_time = strtotime( "-30 days" );

		// Process each log directory
		foreach ( $log_directories as $log_dir ) {
			$log_path = $base_dir . $log_dir;

			if ( is_dir( $log_path ) ) {
				self::delete_old_log_files( $log_path, $expire_time );
			}
		}
	}

	/**
	 * Delete log files older than specified time
	 * IMPORTANT: Only deletes FILES, never directories
	 *
	 * @param string $dir Directory path to process
	 * @param int $expire_time Timestamp threshold for deletion
	 *
	 * @return void
	 */
	private static function delete_old_log_files( $dir, $expire_time ) {
		$files = @scandir( $dir ); // @codingStandardsIgnoreLine.

		if ( empty( $files ) || count( $files ) <= 2 ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$file_path = $dir . '/' . $file;

			// Skip directories
			if ( is_dir( $file_path ) ) {
				continue;
			}

			// Get file modification time
			$file_time = @filemtime( $file_path ); // @codingStandardsIgnoreLine.

			// Only delete if:
			// 1. File time was successfully retrieved
			// 2. File is older than expire_time (30 days)
			if ( false !== $file_time && $file_time <= $expire_time ) {
				@unlink( $file_path ); // @codingStandardsIgnoreLine.
			}
		}
	}

	/**
	 * get desired store time
	 *
	 * @param $hours
	 * @param $mins
	 * @param $sec
	 *
	 * @return int
	 */
	public static function get_store_time( $hours = 0, $mins = 0, $sec = 0 ) {
		$timezone = new DateTimeZone( wp_timezone_string() );
		$date     = new DateTime();

		$date->setTimezone( $timezone );
		$date->setTime( $hours, $mins, $sec );

		if ( current_time( 'timestamp', 1 ) > $date->getTimestamp() ) {
			$date->modify( '+1 days' );
		}

		return $date->getTimestamp();
	}

	/**
	 * Get database query cached result
	 *
	 * @param $query
	 *
	 * @return false|mixed
	 */
	public static function get_db_cache_data( $query = '' ) {
		if ( empty( $query ) ) {
			return false;
		}
		$query = md5( $query );
		if ( ! is_array( self::$cached_db_data ) || ! isset( self::$cached_db_data[ $query ] ) ) {
			return false;
		}

		return self::$cached_db_data[ $query ];
	}

	/**
	 * Set database query cached result
	 *
	 * @param $query
	 * @param $result
	 *
	 * @return bool
	 */
	public static function set_db_cache_data( $query = '', $result = '' ) {
		if ( empty( $query ) ) {
			return false;
		}
		$query = md5( $query );

		self::$cached_db_data[ $query ] = $result;

		return true;
	}

	/**
	 * Unset database query cached result
	 *
	 * @param $query
	 *
	 * @return false|void
	 */
	public static function unset_db_cache_key( $query = '' ) {
		if ( empty( $query ) ) {
			return false;
		}
		$query = md5( $query );

		if ( isset( self::$cached_db_data[ $query ] ) ) {
			unset( self::$cached_db_data[ $query ] );
		}
	}

	/**
	 * get last order of the contact
	 *
	 * @param $contact
	 *
	 * @return array|stdClass|WC_Order[]
	 */
	public static function fetch_last_order_by_contact( $contact ) {
		if ( ! $contact instanceof WooFunnels_Contact ) {
			return [];
		}

		return wc_get_orders( array(
			'meta_key'   => '_woofunnel_cid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-contact order lookup by _woofunnel_cid; admin action, limited
			'meta_value' => $contact->get_id(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- single-contact order lookup by _woofunnel_cid; admin action, limited
			'orderby'    => 'ID',
			'order'      => 'DESC',
			'limit'      => 1,
		) );
	}

	/**
	 * @param $contact
	 *
	 * @return array|object|stdClass|null
	 */
	public static function fetch_last_refund_of_contact( $contact ) {
		if ( ! $contact instanceof WooFunnels_Contact ) {
			return [];
		}

		global $wpdb;
		$orders_ids = wc_get_orders( array(
			'meta_key'   => '_woofunnel_cid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-contact order lookup by _woofunnel_cid; admin action, limited
			'meta_value' => $contact->get_id(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- single-contact order lookup by _woofunnel_cid; admin action, limited
			'limit'      => - 1,
			'return'     => 'ids'
		) );

		if ( empty( $orders_ids ) ) {
			return [];
		}

		$orders_ids = implode( ',', array_map( 'absint', $orders_ids ) );

		if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
			$query  = $wpdb->prepare( "SELECT `id` AS refund_id, `parent_order_id` AS order_id FROM {$wpdb->prefix}wc_orders  WHERE type = %s AND status = %s AND `parent_order_id` IN ($orders_ids) ORDER BY id DESC LIMIT 1", 'shop_order_refund', 'wc-completed' );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders
			$refund = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- order ids absint()ed; other values bound via %s
		} else {
			$query  = $wpdb->prepare( "SELECT `ID` AS refund_id, `post_parent` AS order_id FROM {$wpdb->prefix}posts WHERE `post_type` = %s AND `post_status` = %s AND `post_parent` IN ($orders_ids) ORDER BY `ID` DESC LIMIT 1", 'shop_order', 'publish' );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders
			$refund = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- order ids absint()ed; other values bound via %s
		}

		return $refund;
	}

	/**
	 * Insert multiple contacts in automation contact table
	 *
	 * @param $automation_data
	 *
	 * @return void
	 */
	public static function insert_automations( $automation_data ) {
		foreach ( $automation_data as $auto_data ) {
			$data = ! empty( $auto_data['data'] ) ? json_decode( $auto_data['data'], true ) : [];

			/** Unset end_reason from data */
			if ( ! empty( $data['end_reason'] ) ) {
				unset( $data['end_reason'] );
				$auto_data['data'] = wp_json_encode( $data );
			}

			$data = [
				'cid'       => $auto_data['cid'],
				'aid'       => $auto_data['aid'],
				'event'     => $auto_data['event'],
				'c_date'    => current_time( 'mysql', 1 ),
				'e_time'    => current_time( 'timestamp', 1 ),
				'last_time' => current_time( 'timestamp', 1 ),
				'data'      => $auto_data['data']
			];
			BWFAN_Model_Automation_Contact::insert( $data );
		}
	}


	/**
	 * Bulk action for all the contacts of different automation ids
	 *
	 * @param $dynamic_str
	 * @param $action
	 * @param $status
	 *
	 * @return void
	 */
	public static function bwfan_automation_all_contact_bulk_action( $dynamic_str, $action, $status ) {
		if ( 'end' === $action ) {
			$option_key       = "bwfan_bulk_automation_all_contact_end_{$dynamic_str}";
			$args             = array( 'key' => $dynamic_str, 'action' => $action, 'status' => $status );
			$scheduler_action = 'bwfan_automation_all_contact_bulk_action';
			self::bulk_contact_automation_end( $option_key, $args, $scheduler_action );

			return;
		}

		$option_key = "bwfan_bulk_automation_all_contact_{$action}_{$dynamic_str}";
		$a_cids     = get_option( $option_key, [] );

		if ( empty( $a_cids ) ) {
			delete_option( $option_key );
			bwf_unschedule_actions( 'bwfan_automation_all_contact_bulk_action', [ 'key' => $dynamic_str, 'action' => $action, 'status' => $status ] );

			return;
		}

		if ( 'completed' === $status && 'rerun' !== $action ) {
			$action = 'delete_complete';
		}


		self::perform_bulk_action( $a_cids, $option_key, $action, $status );
	}

	/**
	 * Correct shortcode string for block editor
	 *
	 * @param $body
	 * @param $type
	 *
	 * @return array|mixed|string|string[]
	 */
	public static function correct_shortcode_string( $body, $type ) {
		// Normalize numeric strings to integers for strict comparison
		$type = is_numeric( $type ) ? intval( $type ) : $type;

		// Use strict comparison for better type safety
		if ( ! bwfan_is_autonami_pro_active() || ! in_array( $type, [ 5, '5', 'block', 7, '7' ], true ) ) {
			return $body;
		}
		
		do_action( 'bwfan_block_editor_content_loaded', $body );

		if ( strpos( $body, 'bwfan_email_block_visibility' ) === false ) {
			return $body;
		}


		BWFAN_Core()->rules->load_rules_classes();
		$body = str_replace( "<bwfan_email_block_visibility", "[bwfbe_email_block_visibility", $body );
		$body = str_replace( 'data-bwf-visibility="true">', "]", $body );

		$body = str_replace( "</bwfan_email_block_visibility>", "[/bwfbe_email_block_visibility]", $body );

		return class_exists( 'BWFCRM_Block_Editor' ) ? BWFCRM_Block_Editor::decode_specific_blocks_before( $body, [ 'bwfbe_email_block_visibility' ] ) : $body;
	}

	/**
	 * Get default contact's count
	 *
	 * @return int[]
	 */
	public static function get_contact_data_counts() {
		$bulk_actions_all_count    = 0;
		$bulk_actions_active_count = 0;
		if ( bwfan_is_autonami_pro_active() ) {
			$bulk_actions_count        = BWFAN_Model_Bulk_Action::get_bulk_actions_total_count( false );
			$bulk_actions_all_count    = isset( $bulk_actions_count['all'] ) ? $bulk_actions_count['all'] : 0;
			$bulk_actions_active_count = isset( $bulk_actions_count['1'] ) ? $bulk_actions_count['1'] : 0;
		}

		return [
			'contacts_contacts'            => 0,
			'contacts_manage_audiences'    => 0,
			'contacts_manage_fields'       => 0,
			'contacts_manage_lists'        => 0,
			'contacts_manage_tags'         => 0,
			'contacts_bulk_actions'        => $bulk_actions_all_count,
			'contacts_active_bulk_actions' => $bulk_actions_active_count,
		];
	}

	/**
	 * Get formatted string for mysql query
	 *
	 * @param $value
	 *
	 * @return array|mixed|string|string[]
	 */
	public static function get_formatted_value_for_dbquery( $value ) {
		/**
		 * Retained for backward compatibility — older Pro/add-on builds may still call this.
		 * Hardened to use esc_sql() instead of a naive single-quote str_replace(), which was
		 * backslash-escape bypassable. Returns a value safe to embed inside a quoted SQL
		 * string literal (handles both strings and arrays).
		 */
		return esc_sql( $value );
	}

	/**
	 * Get store average order value
	 *
	 * @return float|int
	 */
	public static function get_store_aov() {
		$store_aov_key = 'bwfan_store_aov';

		/** Get store aov from transient */
		$store_aov = get_transient( $store_aov_key );
		if ( ! empty( $store_aov ) ) {
			return floatval( $store_aov );
		}

		/** Transient expiration time */
		$exp = 12 * HOUR_IN_SECONDS;

		global $wpdb;
		$sql    = "SELECT count(`order_id`) as `orders`, SUM(`total_sales`) as `total` FROM {$wpdb->prefix}wc_order_stats WHERE `status` NOT IN('wc-failed', 'wc-pending', 'wc-cancelled', 'wc-refunded') ";
		$result = $wpdb->get_results( $sql, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		$aov    = 0;
		if ( ! empty( $result[0]['orders'] ) && ! empty( $result[0]['total'] ) ) {
			$aov = ( floatval( $result[0]['total'] ) / absint( $result[0]['orders'] ) );
		}

		set_transient( $store_aov_key, $aov, $exp );

		return $aov;
	}

	/**
	 * @param string $message
	 * @param array $data
	 * @param int $code
	 *
	 * @return WP_Error
	 */
	public static function crm_error( $message = '', $data = array(), $code = 500 ) {
		$wp_error = new WP_Error( $code, $message );
		if ( ! empty( $data ) ) {
			$wp_error->add_data( $data );
		}

		return $wp_error;
	}

	/**
	 * Remove filter before sending mail
	 */
	public static function bwf_remove_filter_before_wp_mail() {
		remove_all_filters( 'wp_mail_from' );
		remove_all_filters( 'wp_mail_from_name' );
		remove_all_filters( 'wp_mail_content_type' );
		remove_all_filters( 'wp_mail_charset' );
	}

	/**
	 * Update automation contact's step trail status
	 *
	 * @return void
	 */
	public static function bwfan_update_contact_trail() {
		global $wpdb;
		$start_time = time();
		do {
			$query = "SELECT ct.ID FROM `{$wpdb->prefix}bwfan_automation_contact_trail` AS ct JOIN `{$wpdb->prefix}bwfan_automation_complete_contact` AS cc ON ct.tid = cc.trail WHERE ct.status = 2 LIMIT 0,20";
			$ids   = $wpdb->get_col( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			if ( empty( $ids ) ) {
				bwf_unschedule_actions( 'bwfan_update_contact_trail' );

				break;
			}
			$ids          = array_map( 'absint', $ids );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$query        = $wpdb->prepare( "UPDATE `{$wpdb->prefix}bwfan_automation_contact_trail` SET `status` = 1, `data` = NULL WHERE ID IN ($placeholders);", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated via array_fill; ids absint()ed
			$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		} while ( ( time() - $start_time ) < 10 );
	}

	/**
	 * Get product price with tax
	 *
	 * @param $product
	 *
	 * @return float|mixed|string|null
	 */
	public static function get_prices_with_tax( $product ) {
		if ( ! wc_tax_enabled() ) {
			return $product instanceof WC_Product && ! empty( $product->get_price() ) ? $product->get_price() : 0;
		}
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}

		$tax_display_cart = get_option( 'woocommerce_tax_display_cart', '' );
		if ( 'incl' === $tax_display_cart ) {
			return wc_get_price_including_tax( $product );
		}
		if ( 'excl' === $tax_display_cart ) {
			return wc_get_price_excluding_tax( $product );
		}

		return 0;
	}

	/**
	 * Set headers to prevent caching
	 *
	 * @return void
	 */
	public static function nocache_headers() {
		do_action( 'litespeed_control_set_nocache', 'fkautomations' );
		if ( headers_sent() ) {
			return;
		}

		header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
		header( 'Last-Modified: false' );
	}

	/**
	 * Check if contact is in automation for a particular order related event
	 *
	 * @param $aid
	 * @param $cid
	 * @param $order_id
	 * @param $item_id
	 * @param $event
	 *
	 * @return true|void
	 */
	public static function is_contact_in_automation( $aid, $cid, $order_id, $item_id = 0, $event = 'wc_new_order' ) {
		$active_contact = BWFAN_Model_Automation_Contact::is_contact_with_same_order( $aid, $cid, $order_id, $item_id, $event );
		if ( ! empty( $active_contact ) ) {
			return true;
		}

		return BWFAN_Model_Automation_Complete_Contact::is_contact_with_same_order( $aid, $cid, $order_id, $item_id, $event );
	}

	/**
	 * Append extra data for order status change
	 *
	 * @param $extra_data
	 * @param $order_id
	 * @param $from
	 * @param $to
	 *
	 * @return mixed
	 */
	public static function order_status_change( $extra_data, $order_id, $from, $to ) {
		$data = [
			'order_id'      => $order_id,
			'from'          => $from,
			'to'            => $to,
			'unique_key'    => get_option( 'bwfan_u_key', false ),
			'function_name' => 'wc_order_status_changed',
		];

		try {
			$a_e_id = BWFAN_Model_Automation_Events::insert_data( $data );
		} catch ( Error $e ) {
			BWFAN_Common::log_test_data( 'Automation Event Insert Row Error: ' . $e->getMessage(), 'db-creation-errors', true );
			$table_instance = new BWFAN_DB_Table_Automation_Events();
			$table_instance->create_table();

			$a_e_id = BWFAN_Model_Automation_Events::insert_data( $data );
		}

		if ( ! empty( $a_e_id ) && intval( $a_e_id ) > 0 ) {
			$extra_data['a_e_id'] = $a_e_id;
		}

		return $extra_data;
	}

	public static function order_status_change_async_capture( $order_data ) {
		/** Delete row from automation events */
		if ( isset( $order_data['a_e_id'] ) ) {
			BWFAN_Model_Automation_Events::delete( $order_data['a_e_id'] );
		}

		$order = wc_get_order( $order_data['order_id'] );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		do_action( 'bwfan_wc_order_status_changed', $order, $order_data['from'], $order_data['to'] );
	}

	/**
	 * Set dynamic string prop
	 *
	 * @return void
	 */
	public static function set_dynamic_string() {
		self::$dynamic_str = self::get_dynamic_string( 10 );
	}

	/**
	 * Get email from order
	 *
	 * @param $order_id
	 * @param $order
	 *
	 * @return string|null
	 */
	public static function get_email_from_order( $order_id = '', ?WC_Order $order = null ) {
		if ( empty( $order_id ) && ! $order instanceof WC_Order ) {
			return '';
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		return $order instanceof WC_Order ? $order->get_billing_email() : '';
	}

	/**
	 * Get phone from order
	 *
	 * @param $order_id
	 * @param $order
	 *
	 * @return mixed|string|null
	 */
	public static function get_phone_from_order( $order_id = '', ?WC_Order $order = null ) {
		if ( empty( $order_id ) && ! $order instanceof WC_Order ) {
			return '';
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$phone = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_billing_phone' );
		if ( empty( $phone ) ) {
			$phone = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_shipping_phone' );
		}

		if ( empty( $phone ) || ! class_exists( 'BWFAN_Phone_Numbers' ) ) {
			return $phone;
		}

		$country = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_billing_country' );
		if ( empty( $country ) ) {
			$country = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_shipping_country' );
		}

		if ( ! empty( $country ) ) {
			$phone = BWFAN_Phone_Numbers::add_country_code( $phone, $country );
		}

		return $phone;
	}

	/**
	 * Get WP user id from order
	 *
	 * @param string $order_id
	 * @param $order
	 *
	 * @return int|string
	 */
	public static function get_wp_user_id_from_order( $order_id = '', ?WC_Order $order = null ) {
		if ( empty( $order_id ) && ! $order instanceof WC_Order ) {
			return '';
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		return $order instanceof WC_Order ? $order->get_customer_id() : '';
	}

	/**
	 * Save meta in order
	 *
	 * @param $order_id
	 * @param $meta_key
	 * @param $meta_value
	 *
	 * @return void
	 */
	public static function save_order_meta( $order_id, $meta_key, $meta_value ) {
		global $wpdb;

		$hpos_enabled = BWF_WC_Compatibility::is_hpos_enabled();
		if ( $hpos_enabled ) {
			$table = "{$wpdb->prefix}wc_orders_meta";
			$query = " SELECT `id`,`meta_value` FROM {$table} WHERE `order_id` = %d AND `meta_key` = %s  ";
		} else {
			$table = "{$wpdb->prefix}postmeta";
			$query = " SELECT `meta_id`,`meta_value` FROM {$table} WHERE `post_id` = %d AND `meta_key` = %s  ";
		}
		$row = $wpdb->get_row( $wpdb->prepare( $query, $order_id, $meta_key ), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

		/** If no data found */
		if ( empty( $row ) ) {
			$data = [
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- writes an order meta row by order ID (HPOS or postmeta table), not a meta lookup query
				'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- writes an order meta row by order ID (HPOS or postmeta table), not a meta lookup query
			];

			if ( $hpos_enabled ) {
				$data['order_id'] = $order_id;
			} else {
				$data['post_id'] = $order_id;
			}
			$wpdb->insert( $table, $data ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			return;
		}

		/** If old value is equal to new value */
		if ( $meta_value === $row['meta_value'] ) {
			return;
		}

		/** update meta value */
		$column = ( $hpos_enabled ) ? 'id' : 'meta_id';

		$wpdb->update( $table, [ 'meta_value' => $meta_value ], [ $column => $row[ $column ] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- writes an order meta row by order ID (HPOS or postmeta table), not a meta lookup query
	}

	/**
	 * Get FK Automation links
	 * 
	 * @param string $type Specific link type to retrieve. If empty, returns all links. Supported types: 'upgrade', 'offer', 'autonami', 'support', 'docs', 'nxtgenbuilder', 'migratefromv1', 'whatsnew', 'publicapi', 'sublium', 'account', 'paidorderstatus'.
	 *
	 * @return array|string 
	 */
	public static function get_fk_site_links( $type = '' ) {
		$default_args = [
			'utm_source'   => 'WordPress',
			'utm_campaign' => 'FKA+Lite+Plugin'
		];

		$links = [
			'upgrade'       => apply_filters( 'bwfan_upgrade_link_append_utm_args', add_query_arg( $default_args, 'https://funnelkit.com/exclusive-offer/' ) ),
			'offer'         => apply_filters( 'bwfan_offer_link_append_utm_args', add_query_arg( $default_args, 'https://funnelkit.com/exclusive-offer/' ) ),
			'autonami'      => apply_filters( 'bwfan_fka_sales_link_append_utm_args', add_query_arg( $default_args, 'https://funnelkit.com/wordpress-marketing-automation-autonami/' ) ),
			'support'       => apply_filters( 'bwfan_support_link_append_utm_args', add_query_arg( $default_args, 'https://funnelkit.com/support/' ) ),
			'docs'          => apply_filters( 'bwfan_docs_link_append_utm_args', add_query_arg( $default_args, 'https://funnelkit.com/docs/autonami-2/' ) ),
			'nxtgenbuilder' => apply_filters( 'bwfan_fka_nextgen_link_append_utm_args', add_query_arg( $default_args, 'https://funnelkit.com/autonami-next-generation-automation-builder/' ) ),
			'migratefromv1' => apply_filters( 'bwfan_fka_migrate_link_append_utm_args', add_query_arg( $default_args, 'https://funnelkit.com/docs/autonami-2/automations/migrate-from-older-version' ) ),
			'whatsnew'      => apply_filters( 'bwfan_fka_whatsnew_link_append_utm_args', add_query_arg( $default_args, 'https://funnelkit.com/whats-new/' ) ),
			'publicapi'     => apply_filters( 'bwfan_fka_public_api_link_append_utm_args', add_query_arg( $default_args, 'https://developers.funnelkit.com/#introduction' ) ),
			'sublium'       => apply_filters( 'bwfan_fka_sublium_link_append_utm_args', add_query_arg( $default_args, 'https://sublium.com/' ) ),
			'account'       => apply_filters( 'bwfan_fka_account_link_append_utm_args', add_query_arg( $default_args, 'https://myaccount.funnelkit.com/?utm_source=WordPress&utm_campaign=FKA+Lite+Plugin&utm_medium=Settings+License+Login' ) ),
			'paidorderstatus' => apply_filters( 'bwfan_fka_paid_order_status_link_append_utm_args', add_query_arg( array_merge( $default_args, [ 'utm_medium' => 'WC+Import' ] ), 'https://funnelkit.com/docs/autonami-2/troubleshooting/set-custom-order-status-as-paid-order-status-for-syncing-orders/' ) ),
		];

		if ( ! empty( $type ) ) {
			return ! empty( $links[ $type ] ) ? $links[ $type ] : '';
		}

		return $links;
	}

	/**
	 * Get unsubscribe link
	 *
	 * @param $data
	 *
	 * @return string
	 */
	public static function get_unsubscribe_link( $data ) {
		if ( empty( self::$unsubscribe_page_link ) ) {
			$global_settings = self::get_global_settings();
			if ( ! isset( $global_settings['bwfan_unsubscribe_page'] ) || empty( $global_settings['bwfan_unsubscribe_page'] ) ) {
				self::$unsubscribe_page_link = esc_url_raw( home_url() );
			} else {
				$page      = absint( $global_settings['bwfan_unsubscribe_page'] );
				$permalink = get_permalink( $page );

				self::$unsubscribe_page_link = ! empty( $permalink ) ? $permalink : esc_url_raw( home_url( '/' ) );
			}
		}

		if ( empty( $data ) || ! is_array( $data ) ) {
			return self::$unsubscribe_page_link;
		}

		if ( empty( $data['uid'] ) && isset( $data['contact_id'] ) ) {
			$contact = new WooFunnels_Contact( '', '', '', $data['contact_id'] );
			$uid     = ( $contact->get_id() > 0 ) ? $contact->get_uid() : '';

			if ( ! empty( $uid ) ) {
				$data['uid'] = $uid;
			}
			unset( $data['contact_id'] );
		}

		$data['bwfan-action'] = 'unsubscribe';

		return add_query_arg( $data, self::$unsubscribe_page_link );
	}

	/**
	 * Checking wc new order goal in automation
	 *
	 * @param $aid
	 *
	 * @return bool
	 */
	public static function is_wc_order_goal( $aid ) {
		global $wpdb;
		$query = $wpdb->prepare( "SELECT `ID` FROM {$wpdb->prefix}bwfan_automations WHERE ID = %d AND `benchmark` LIKE %s", $aid, '%"wc_new_order"%' );

		return ( ! empty( $wpdb->get_var( $query ) ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
	}

	/**
	 * Remove duplicate actions if found
	 *
	 * @return void
	 */
	public static function remove_duplicate_actions() {
		global $wpdb;

		$hooks = [
			'bwfan_delete_logs',
			'bwfan_delete_expired_autonami_coupons',
			'bwfan_mark_abandoned_lost_cart',
			'bwfan_run_midnight_cron',
			'bwfan_run_midnight_connectors_sync',
			'bwfan_run_queue',
			'bwfan_run_queue_v2',
			'bwfcrm_broadcast_run_queue',
			'bwfan_check_abandoned_carts',
			'bwfan_5_minute_worker',
			'bwfan_run_event_queue'
		];

		$placeholders       = array_fill( 0, count( $hooks ), '%s' );
		$placeholders_hooks = implode( ', ', $placeholders );

		$query = "SELECT `hook`, count(`id`) as `count` FROM `{$wpdb->prefix}bwf_actions` WHERE `hook` IN ($placeholders_hooks) AND `status` = 0 GROUP BY `hook`;";
		$query = $wpdb->prepare( $query, $hooks );// phpcs:ignore WordPress.DB.PreparedSQL

		$result = $wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		if ( empty( $result ) ) {
			return;
		}
		$result = array_filter( $result, function ( $row ) {
			return $row['count'] > 1;
		} );
		if ( empty( $result ) ) {
			return;
		}

		$hooks = array_column( $result, 'hook' );
		sort( $hooks );

		$placeholders       = array_fill( 0, count( $hooks ), '%s' );
		$placeholders_hooks = implode( ', ', $placeholders );

		/**
		 * Keep one pending row per hook instead of deleting every row and re-creating one. The previous
		 * approach re-created only bwfcrm_broadcast_run_queue, so every other hook in the list above was left
		 * unscheduled until someone opened wp-admin - and even that single re-insert could silently fail.
		 *
		 * The row kept is the oldest recurring one, falling back to the oldest row of any kind. Keeping a
		 * plain MIN(`id`) could keep a one-off row over a recurring one and end the schedule after it fires.
		 * The derived table is required, MySQL will not read the target table of a DELETE directly.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders generated via array_fill; values bound in prepare()
		$query = $wpdb->prepare( "DELETE FROM `{$wpdb->prefix}bwf_actions`
			WHERE `hook` IN ($placeholders_hooks) AND `status` = 0
			AND `id` NOT IN (
				SELECT `id` FROM (
					SELECT COALESCE( MIN( CASE WHEN `recurring_interval` > 0 THEN `id` END ), MIN( `id` ) ) AS `id`
					FROM `{$wpdb->prefix}bwf_actions`
					WHERE `hook` IN ($placeholders_hooks) AND `status` = 0
					GROUP BY `hook`
				) AS `keep_rows`
			)", array_merge( $hooks, $hooks ) ); // phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
	}

	/**
	 * Save contact uid as cookie
	 *
	 * @param $optin_id
	 * @param $posted_data
	 * @param $bwf_contact WooFunnels_Contact
	 *
	 * @return void
	 */
	public static function set_uid_in_cookies( $optin_id, $posted_data, ?WooFunnels_Contact $bwf_contact = null ) {
		if ( headers_sent() ) {
			return;
		}

		/** If contact object available */
		if ( ! is_null( $bwf_contact ) && $bwf_contact instanceof WooFunnels_Contact ) {
			$uid = $bwf_contact->get_uid();
			if ( ! empty( $uid ) ) {
				BWFAN_Common::set_cookie( '_fk_contact_uid', $uid, time() + ( 10 * YEAR_IN_SECONDS ) );

				return;
			}
		}

		if ( ! isset( $posted_data['cid'] ) || empty( $posted_data['cid'] ) ) {
			return;
		}

		$contact = new WooFunnels_Contact( '', '', '', $posted_data['cid'] );
		if ( ! $contact instanceof WooFunnels_Contact ) {
			return;
		}

		$uid = $contact->get_uid();
		if ( empty( $uid ) ) {
			return;
		}
		BWFAN_Common::set_cookie( '_fk_contact_uid', $uid, time() + ( 10 * YEAR_IN_SECONDS ) );
	}

	/**
	 * Returns block editor default settings
	 *
	 * @return array
	 */
	public static function get_block_editor_default_setting() {
		return [
			'site'    => [
				'logo'     => '',
				'url'      => '',
				'logoAuto' => [
					'desktop' => true,
				],
				'logoSize' => [
					'desktop' => [
						'value' => 100,
					],
				],
			],
			'setting' => [
				'background'        => [
					'desktop' => [
						'color' => '#f6f6f6',
					],
				],
				'contentBackground' => [
					'desktop' => [
						'color' => '#ffffff',
					],
				],
				'width'             => [
					'desktop' => [
						'value' => 640,
						'unit'  => 'px',
					],
				],
				'align'             => [
					'desktop' => 'center',
				],
				'color'             => [
					'desktop' => '#353030',
				],
				'font'              => [
					'desktop' => [
						'family' => 'arial,helvetica,sans-serif',
						'size'   => 14,
					],
				],
				'fontH1'            => [
					'desktop' => [
						'size' => 36,
					],
				],
				'fontH2'            => [
					'desktop' => [
						'size' => 32,
					],
				],
				'fontH3'            => [
					'desktop' => [
						'size' => 28,
					],
				],
				'fontH4'            => [
					'desktop' => [
						'size' => 24,
					],
				],
				'fontH5'            => [
					'desktop' => [
						'size' => 20,
					],
				],
				'fontH6'            => [
					'desktop' => [
						'size' => 16,
					],
				],
				'linkLineType'      => [
					'desktop' => 'underline',
				],
				'linkColor'         => [
					'desktop' => '#236fa1',
				],
				'linkLineColor'     => [],
				'buttonColor'       => [
					'desktop' => '#ffffff',
				],
				'buttonBackground'  => [
					'desktop' => [
						'color'    => '#353030',
						'gradient' => null,
					],
				],
				'buttonSize'        => [
					'desktop' => [
						'value' => 100,
					],
				],
				'buttonAuto'        => [
					'desktop' => true,
				],
				'buttonBorder'      => [
					'desktop' => [
						'top-left'     => '8',
						'top-right'    => '8',
						'bottom-left'  => '8',
						'bottom-right' => '8',
						'radius_unit'  => 'px',
					]
				],
				'buttonPadding'     => [
					'desktop' => [
						'left'   => '24',
						'right'  => '24',
						'top'    => '16',
						'bottom' => '16',
						'unit'   => 'px',
					]
				],
				'buttonFont'        => [
					'desktop' => [
						'size' => 16,
					],
				],
				'backupFont'        => [
					'desktop' => [
						'family' => 'arial,helvetica,sans-serif',
					],
				],
				'social'            => [
					[ 'iconName' => 'facebook', 'link' => '' ],
					[ 'iconName' => 'instagram', 'link' => '' ],
					[ 'iconName' => 'youtube', 'link' => '' ],
					[ 'iconName' => 'pinterest', 'link' => '' ],
				],
				'socialType'        => 'circle',
			],
		];
	}

	/**
	 * Get block editor settings
	 *
	 * @return array|false|mixed|null
	 */
	public static function get_block_editor_settings() {
		if ( ! empty( self::$block_editor_settings ) ) {
			return self::$block_editor_settings;
		}
		$default       = BWFAN_Common::get_block_editor_default_setting();
		$saved_setting = get_option( 'bwf_global_block_editor_setting', $default );
		if ( isset( $saved_setting['setting'] ) && isset( $default['setting'] ) ) {
			$new_settings             = array_merge( $default['setting'], $saved_setting['setting'] );
			$saved_setting['setting'] = $new_settings;
		}
		self::$block_editor_settings = ! empty( $saved_setting ) ? $saved_setting : $default;

		return self::$block_editor_settings;
	}

	/**
	 * Check pro is deactivated or 3.0
	 *
	 * @return bool
	 */
	public static function is_pro_3_0() {
		return ( ( ! bwfan_is_autonami_pro_active() ) || ( defined( 'BWFAN_PRO_VERSION' ) && version_compare( BWFAN_PRO_VERSION, '3.0.beta.27032024', '>=' ) ) );
	}

	/**
	 * Insert default fields
	 *
	 * @return void
	 */
	public static function insert_default_crm_fields() {
		global $wpdb;
		$crm_fields_table = $wpdb->prefix . 'bwfan_fields';
		$default_fields   = self::$crm_default_fields;
		$db_errors        = [];
		foreach ( $default_fields as $field => $field_data ) {
			$already_exist = self::check_field_exist( $field );
			if ( $already_exist ) {
				continue;
			}
			$data = [
				'name'       => $field_data['name'],
				'slug'       => $field,
				'type'       => $field_data['type'],
				'gid'        => 0,
				'meta'       => wp_json_encode( $field_data['meta'] ),
				'mode'       => $field_data['mode'],
				'vmode'      => $field_data['vmode'],
				'view'       => $field_data['view'],
				'search'     => 1,
				'created_at' => current_time( 'mysql', 1 ),
			];
			$wpdb->insert( $crm_fields_table, $data ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$field_id = $wpdb->insert_id;
			if ( ! empty( $wpdb->last_error ) ) {
				$db_errors[] = 'Error in default field creation: ' . $field_data['name'] . ' ' . $wpdb->last_error;
			}
			/** Check field column exist in contact_fields table **/
			$exists = BWF_Model_Contact_Fields::column_already_exists( $field_id );
			if ( empty( $exists ) ) {
				$column_added = BWF_Model_Contact_Fields::add_column_field( $field_id, 1 );
				/** If column not added */
				if ( true !== $column_added ) {
					BWFAN_Model_Fields::delete( $field_id );
					$db_errors[] = 'Error in contact field column creation: ' . $column_added;
				}
			}
		}
		/** Log if any mysql errors */
		if ( ! empty( $db_errors ) ) {
			BWFAN_Common::log_test_data( array_merge( [ __FUNCTION__ ], $db_errors ), 'field-creation-error' );
		}
	}

	/**
	 * Checking if field with slug exists or not
	 *
	 * @param $slug
	 *
	 * @return bool
	 */
	public static function check_field_exist( $slug ) {
		global $wpdb;
		$query  = $wpdb->prepare( "SELECT `ID` FROM `{$wpdb->prefix}bwfan_fields` WHERE `slug` = %s LIMIT 0, 1", $slug );
		$result = $wpdb->get_var( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

		return ! empty( $result );
	}

	/**
	 * Get the complete phone number of a contact with a country code
	 *
	 * @param $contact_obj WooFunnels_Contact
	 *
	 * @return mixed|string|void
	 */
	public static function get_contact_full_number( $contact_obj ) {
		if ( ! $contact_obj instanceof WooFunnels_Contact || 0 === absint( $contact_obj->get_id() ) ) {
			return '';
		}
		$phone = $contact_obj->get_contact_no();
		if ( empty( $phone ) ) {
			return '';
		}
		$country = $contact_obj->get_country();
		if ( ! empty( $country ) ) {
			$phone = BWFAN_Phone_Numbers::add_country_code( $phone, $country );
		}

		return $phone;
	}

	/**
	 * @param $link
	 *
	 * @return void
	 */
	public static function wp_redirect( $link ) {
		remove_all_filters( 'wp_redirect' );

		add_filter( 'allowed_redirect_hosts', function ( $allowed_hosts, $host ) use ( $link ) {
			/** Add link host in allowed hosts, because already checked link is valid or not */
			if ( $host === wp_parse_url( $link, PHP_URL_HOST ) ) {
				$allowed_hosts[] = $host;
			}

			return $allowed_hosts;
		}, 10, 2 );


		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}

		wp_safe_redirect( $link );
		exit;
	}

	/**
	 * Get Contact id from contact object
	 *
	 * @param $email
	 * @param $user_id
	 * @param $phone
	 *
	 * @return int
	 */
	public static function get_cid_from_contact( $email = '', $user_id = 0, $phone = '' ) {
		if ( empty( $email ) && empty( $phone ) ) {
			return 0;
		}
		$contact = new WooFunnels_Contact( $user_id, $email, $phone );

		if ( $contact->get_id() > 0 ) {
			return $contact->get_id();
		}

		return 0;
	}

	/**
	 * Return regex pattern
	 *
	 * @param int $type
	 *
	 * @return string
	 */
	public static function get_regex_pattern( $type = 1 ) {
		$type = (int) $type;

		switch ( $type ) {
			case 1:
				/**
				 * [0] => href='https://google.com/?abcd=ss&utm_content=source'
				 * [1] => https://google.com/?abcd=ss&utm_content=source
				 */ return '/href=["\']?([^"\'>]+)["\']?/';
			case 2:
				/**
				 * [0] => <a href='https://...' class='any'>
				 */ return '/<a[^>]+href=[^>]+>/i';
			case 3:
				/**
				 * [0] => https://...
				 * A trailing "/?" stays in the match (e.g. {{reorder_last_order_url}} fallback), else params get appended before the "?"
				 */ return '#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|/\?|([^,[:punct:]\s]|/))#';
			default:
				return '';
		}
	}

	/**
	 * If url exists in excluded urls
	 *
	 * @param $url
	 *
	 * @return bool
	 */
	public static function is_exclude_url( $url ) {
		if ( empty( $url ) ) {
			return false;
		}

		$excluded_urls = [
			'fonts.googleapis.com',
			'mailto:',
			'tel:',
			'whatsapp:',
			'wa.me:',
			'//wa.me',
			't.me',
			'm.me',
			'x.com',
			'twitter.com',
			'linkedin.com',
			'instagram.com',
			'pinterest.com',
			'youtube.com',
			'snapchat.com',
			'reddit.com',
			'tripadvisor.com',
			'meetup.com',
			'producthunt.com',
			'tinder.com',
			'tumblr.com',
			'music.apple.com',
			'open.spotify.com',
			'soundcloud.com',
			'yelp.com',
			'medium.com',
			'skype.com',
			'flickr.com',
			'github.com',
			'discord.gg',
			'tiktok.com',
			'zoom.us',
			'youtu.be'
		];
		$excluded_urls = apply_filters( 'bwfan_exclude_click_track_urls', $excluded_urls );

		foreach ( $excluded_urls as $excluded_url ) {
			if ( $url === $excluded_url || false !== strpos( $url, $excluded_url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns country iso code
	 *
	 * @param $country
	 *
	 * @return string
	 */
	public static function get_country_iso_code( $country ) {
		$country = strtolower( $country );

		$countries     = self::get_countries_data();
		$countries_iso = array_keys( $countries );
		$countries_iso = array_map( function ( $j ) {
			return strtolower( $j );
		}, $countries_iso );

		if ( in_array( $country, $countries_iso, true ) ) {
			/** Country found */
			return strtoupper( $country );
		}

		/** Country array with 2 digit key and lower names as value */
		$countries_lower = array_map( function ( $j ) {
			return strtolower( $j );
		}, $countries );

		if ( in_array( $country, $countries_lower, true ) ) {
			/** Country found */
			$index = array_search( $country, $countries_lower );

			return strtoupper( $index );
		}

		return '';
	}

	public static function get_country_name( $country_code ) {
		$country_code = strtoupper( $country_code );
		$countries    = self::get_countries_data();

		if ( isset( $countries[ $country_code ] ) ) {
			return $countries[ $country_code ];
		}

		return $country_code;
	}

	/** countries data
	 * @return mixed|null
	 */
	public static function get_countries_data() {
		$countries_json = file_get_contents( BWFAN_PLUGIN_DIR . '/includes/countries.json' );
		$countries      = json_decode( $countries_json, true );

		return $countries;
	}

	/**
	 * Conversions indexing
	 *
	 * @param $table_empty
	 *
	 * @return void
	 */
	public static function bwfan_conversions_index( $table_empty ) {
		$key       = 'bwfan_conversions_index';
		$order_ids = bwf_options_get( $key );
		if ( empty( $order_ids ) || ! is_array( $order_ids ) || 0 === count( $order_ids ) ) {
			delete_option( $key );
			bwf_unschedule_actions( 'bwfan_conversions_index' );

			return;
		}

		$start_time     = time();
		$updated_orders = $order_ids;
		foreach ( $order_ids as $index => $order_id ) {
			if ( ( time() - $start_time ) > 10 ) {
				break;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				unset( $updated_orders[ $index ] );
				continue;
			}

			/** Check order is already exists in table if table is not empty */
			if ( empty( $table_empty ) ) {
				$already_exists = BWFAN_Model_Conversions::get_specific_rows( 'wcid', $order_id );
				if ( ! empty( $already_exists ) ) {
					unset( $updated_orders[ $index ] );
					continue;
				}
			}

			$cid = BWF_WC_Compatibility::get_order_meta( $order, '_woofunnel_cid' );
			$oid = BWF_WC_Compatibility::get_order_meta( $order, '_bwfan_ab_cart_recovered_a_id' );

			$data = array(
				'wcid'    => intval( $order_id ),
				'cid'     => intval( $cid ),
				'oid'     => intval( $oid ),
				'otype'   => 1,
				'wctotal' => $order->get_total(),
				'date'    => $order->get_date_created()->date( 'Y-m-d H:i:s' )
			);
			BWFAN_Model_Conversions::insert( $data );

			unset( $updated_orders[ $index ] );
		}

		sort( $updated_orders );
		if ( empty( $updated_orders ) ) {
			delete_option( $key );
			bwf_unschedule_actions( 'bwfan_conversions_index' );

			return;
		}
		bwf_options_update( $key, $updated_orders );
	}

	/**
	 * @return bool
	 */
	public static function is_conversion_empty() {
		global $wpdb;
		$query = "SELECT MAX(id) FROM {$wpdb->prefix}bwfan_conversions";

		return empty( $wpdb->get_var( $query ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
	}

	public static function get_skip_conversion_automations() {
		global $wpdb;
		$skip_events = apply_filters( 'bwfan_skip_conversion_automations', [ 'wcs_before_renewal' ] );
		/** Create string placeholder */
		$placeholder = array_fill( 0, count( $skip_events ), '%s' );
		$placeholder = implode( ", ", $placeholder );

		$query = $wpdb->prepare( "SELECT `ID` FROM {$wpdb->prefix}bwfan_automations WHERE 1 = 1 AND `event` IN ($placeholder) ", $skip_events );// phpcs:ignore WordPress.DB.PreparedSQL,  WordPress.DB.PreparedSQLPlaceholders

		$query_res = $wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

		if ( empty( $query_res ) ) {
			return [];
		}

		return array_column( $query_res, 'ID' );
	}

	/**
	 * Include block editor shortcode before send mail
	 *
	 * @return void
	 */
	public static function bwfan_before_send_mail( $type = 'block' ) {
		if ( bwfan_is_autonami_pro_active() && version_compare( BWFAN_PRO_VERSION, '2.9.0', '>=' ) && in_array( $type, [ 5, 'block', '5', 7, '7' ] ) ) {
			include_once BWFAN_PRO_PLUGIN_DIR . '/crm/includes/class-bwfcrm-block-editor.php';
		}
	}

	/**
	 * Get product image url
	 *
	 * @param string|WC_Product $product
	 *
	 * @return string
	 */
	public static function get_product_image_url( $product = '', $size = 'thumbnail' ) {
		/** check for woocommerce active **/
		if ( ! function_exists( 'bwfan_is_woocommerce_active' ) || false === bwfan_is_woocommerce_active() ) {
			return '';
		}

		if ( ! empty( $product ) ) {
			if ( ! $product instanceof WC_Product || ( is_int( $product ) && intval( $product ) > 0 ) ) {
				$product = wc_get_product( $product );
			}
			if ( $product ) {
				$product_img_id = $product->get_image_id();
				if ( ! empty( $product_img_id ) ) {
					return wp_get_attachment_image_url( $product_img_id, $size );
				} elseif ( $product->get_parent_id() ) {
					$parent_product = wc_get_product( $product->get_parent_id() );
					if ( $parent_product ) {
						$image_id = $parent_product->get_image_id();
						if ( ! empty( $image_id ) ) {
							return wp_get_attachment_image_url( $image_id, $size );
						}
					}
				}
			}
		}

		return '';
	}

	public static function bwfan_run_event_queue() {
		global $wpdb;
		$query   = $wpdb->prepare( "SELECT `ID`, `args` FROM {$wpdb->prefix}bwfan_automation_events WHERE `execution_time` < %d ORDER BY `execution_time` ASC LIMIT 100", current_time( 'timestamp', 1 ) );
		$results = $wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		if ( empty( $results ) ) {
			return;
		}

		$events = [];
		foreach ( $results as $result ) {
			$events[ $result['ID'] ] = json_decode( $result['args'], true );
		}

		$start_time = time();
		$batch_size = 10;
		while ( count( $events ) > 0 && ( ( time() - $start_time ) < 15 ) ) {
			$selected_ids = ( count( $events ) > $batch_size ) ? array_slice( $events, 0, $batch_size, true ) : $events;
			foreach ( $selected_ids as $id => $args ) {
				unset( $events[ $id ] );

				/** Delete row */
				BWFAN_Model_Automation_Events::delete( $id );

				if ( empty( $args ) ) {
					continue;
				}

				/** Order status change case */
				if ( isset( $args['function_name'] ) && ( 'wc_order_status_changed' === $args['function_name'] ) ) {
					$order = wc_get_order( $args['order_id'] );
					if ( $order instanceof WC_Order ) {
						do_action( 'bwfan_wc_order_status_changed', $order, $args['from'], $args['to'] );
					}
					continue;
				};

				/** Other events case */
				BWFAN_Common::capture_async_helper( $args, false );
			}
		}
	}

	/**
	 * Set automation ended reason in data
	 *
	 * @param $reason
	 * @param $data
	 *
	 * @return mixed
	 */
	public static function set_automation_ended_reason( $reason, $data ) {
		$decoded_data               = ! empty( $data['data'] ) ? json_decode( $data['data'], true ) : [];
		$decoded_data['end_reason'] = $reason;
		$data['data']               = wp_json_encode( $decoded_data );

		return $data;
	}

	/**
	 * Get automaton end reason message
	 *
	 * @param $reason_data
	 *
	 * @return string
	 */
	public static function get_end_reason_message( $reason_data ) {
		if ( ! is_array( $reason_data ) || empty( $reason_data ) ) {
			return '';
		}
		$messages = [
			BWFAN_Automation_Controller::$MANUAL_END                => __( "Manually ended by User #{user_id}", 'wp-marketing-automations' ),
			BWFAN_Automation_Controller::$ACTION_END                => __( "Ended by 'End automation' action (step #{step_id} of Automation #{automation_id})", 'wp-marketing-automations' ),
			BWFAN_Automation_Controller::$BULK_ACTION_END           => __( "Ended by Bulk Action #{id}", 'wp-marketing-automations' ),
			BWFAN_Automation_Controller::$LINK_TRIGGER_END          => __( "Ended by Link Trigger #{id}", 'wp-marketing-automations' ),
			BWFAN_Automation_Controller::$GOAL_UPDATE_WITH_END      => __( "Ended because goal step ( step #{step_id} ) is updated with end automation", 'wp-marketing-automations' ),
			BWFAN_Automation_Controller::$GOAL_END                  => __( "Ended by Goal (Step #{step_id})", 'wp-marketing-automations' ),
			BWFAN_Automation_Controller::$CART_RECOVERED_END        => __( "Ended as Cart is Recovered", 'wp-marketing-automations' ),
			BWFAN_Automation_Controller::$BEFORE_START_VALIDATION   => __( "Ended as Event validation failed", 'wp-marketing-automations' ),
			BWFAN_Automation_Controller::$AUTOMATION_DATA_NOT_FOUND => __( "Automation data not found for event ({event_slug})", 'wp-marketing-automations' ),
		];

		$type = intval( $reason_data['type'] );
		if ( ! isset( $messages[ $type ] ) ) {
			return '';
		}

		$message = '';
		switch ( $type ) {
			case BWFAN_Automation_Controller::$MANUAL_END:
				$message = str_replace( '{user_id}', intval( $reason_data['data']['user_id'] ), $messages[ $type ] );
				break;
			case BWFAN_Automation_Controller::$ACTION_END:
				$message = str_replace( '{step_id}', intval( $reason_data['data']['sid'] ), $messages[ $type ] );
				$aid     = intval( $reason_data['data']['aid'] );
				$link    = "<a href='" . admin_url( '/admin.php?page=autonami&path=/automation/' . $aid ) . "' class='bwf-normal-a-t'>#$aid</a>";
				$message = str_replace( '#{automation_id}', $link, $message );
				break;
			case BWFAN_Automation_Controller::$LINK_TRIGGER_END:
			case BWFAN_Automation_Controller::$BULK_ACTION_END:
				$id      = intval( $reason_data['data']['id'] );
				$action  = ( $type === BWFAN_Automation_Controller::$BULK_ACTION_END ) ? 'bulk-action' : 'link-trigger';
				$link    = "<a href='" . admin_url( 'admin.php?page=autonami&path=/' . $action . '/' . $id ) . "' class='bwf-normal-a-t'>#$id</a>";
				$message = str_replace( '#{id}', $link, $messages[ $type ] );
				break;
			case BWFAN_Automation_Controller::$GOAL_END:
			case BWFAN_Automation_Controller::$GOAL_UPDATE_WITH_END:
				$message = str_replace( '{step_id}', intval( $reason_data['data']['sid'] ), $messages[ $type ] );
				break;
			case BWFAN_Automation_Controller::$BEFORE_START_VALIDATION:
			case BWFAN_Automation_Controller::$CART_RECOVERED_END:
				$message = $messages[ $type ];
				break;
			case BWFAN_Automation_Controller::$AUTOMATION_DATA_NOT_FOUND:
				$message = str_replace( '{event_slug}', $reason_data['data']['event_slug'], $messages[ $type ] );
				break;
		}

		return $message;
	}

	/**
	 * Get or create Tag / list if not exists
	 *
	 * @param $terms
	 * @param $type
	 *
	 * @return array
	 */
	public static function get_or_create_terms( $terms, $type ) {

		$terms = array_map( function ( $term ) {
			if ( isset( $term['name'] ) ) {
				$term['value'] = $term['name'];
			}

			return $term;
		}, $terms );

		$terms = self::check_for_comma_seperated( $terms );
		$terms = BWFCRM_Term::get_or_create_terms( $terms, $type, true );

		/** Preparing Data */
		return array_map( function ( $term ) {
			$term_data         = [];
			$term_data['id']   = $term->get_id();
			$term_data['name'] = $term->get_name();

			return $term_data;
		}, $terms );
	}


	/**
	 * Create or update contact on form submit event
	 *
	 * @param $automation_data
	 */
	public static function maybe_create_update_contact( $automation_data ) {
		if ( empty( $automation_data ) ) {
			return;
		}

		$email = $automation_data['email'];

		if ( ! is_email( trim( $email ) ) ) {
			return;
		}

		$contact = new WooFunnels_Contact( '', $email, '', '', '' );
		$args    = [];
		if ( isset( $automation_data['first_name'] ) && ! empty( $automation_data['first_name'] ) ) {
			$args['f_name'] = $automation_data['first_name'];
		}

		if ( isset( $automation_data['last_name'] ) && ! empty( $automation_data['last_name'] ) ) {
			$args['l_name'] = $automation_data['last_name'];
		}

		if ( isset( $automation_data['contact_phone'] ) && ! empty( $automation_data['contact_phone'] ) ) {
			$args['contact_no'] = $automation_data['contact_phone'];
		}

		if ( isset( $automation_data['mark_contact_subscribed'] ) && 1 === absint( $automation_data['mark_contact_subscribed'] ) ) {
			$args['status'] = 1;
		}

		/** checking if contact not exists than create otherwise update */
		if ( ! $contact instanceof WooFunnels_Contact || 0 === absint( $contact->get_id() ) ) {
			new BWFCRM_Contact( $email, true, $args );
		} else { // update first_name, last_name, phone and status
			$bwfcrm_contact = new BWFCRM_Contact( $contact, false, $args );
			$bwfcrm_contact->update( $args );
			if ( isset( $automation_data['mark_contact_subscribed'] ) && 1 === absint( $automation_data['mark_contact_subscribed'] ) ) {
				$bwfcrm_contact->resubscribe();
			}
		}
	}

	/**
	 * Get WP User object
	 *
	 * @param $user_login
	 * @param $user
	 *
	 * @return false|WP_User|null
	 */
	public static function get_user( $user_login = false, $user = false ) {
		if ( ! empty( $user ) && $user instanceof WP_User ) {
			return $user;
		}

		if ( ! empty( $user_login ) ) {
			$user = get_user_by( 'login', $user_login );
			if ( false === $user ) {
				$user = get_user_by( 'email', $user_login );
			}
			if ( ! empty( $user ) && $user instanceof WP_User ) {
				return $user;
			}
		}

		if ( is_user_logged_in() ) {
			return wp_get_current_user();
		}

		return false;
	}

	/**
	 * Get paid orders count
	 *
	 * @param $renewal_orders
	 *
	 * @return int
	 */
	public static function get_paid_orders_count( $renewal_orders ) {
		if ( empty( $renewal_orders ) ) {
			return 0;
		}
		$paid_statuses = wc_get_is_paid_statuses();
		if ( empty( $paid_statuses ) ) {
			return 0;
		}
		$orders = array_filter( $renewal_orders, function ( $order_id ) use ( $paid_statuses ) {
			$order = wc_get_order( $order_id );

			return ( $order instanceof WC_ORDER && $order->has_status( $paid_statuses ) );
		} );

		return count( $orders );
	}

	/**
	 * Get order language
	 *
	 * @param $order
	 *
	 * @return array|mixed|string
	 */
	public static function get_order_language( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$lang = '';
		if ( function_exists( 'pll_current_language' ) && function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $order->get_id() );
			$lang = $lang && is_string( $lang ) ? $lang : '';
		} else {
			$meta_key = '';
			if ( class_exists( 'woocommerce_wpml' ) || defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$meta_key = 'wpml_language';
			} elseif ( bwfan_is_translatepress_active() ) {
				$meta_key = 'trp_language';
			} elseif ( function_exists( 'bwfan_is_weglot_active' ) && bwfan_is_weglot_active() ) {
				$meta_key = 'weglot_language';
			} elseif ( function_exists( 'bwfan_is_linguise_active' ) && bwfan_is_linguise_active() ) {
				$meta_key = 'linguise_language';
			}
			$lang = $meta_key ? $order->get_meta( $meta_key ) : '';
		}

		if ( empty( $lang ) && defined( 'ICL_SITEPRESS_VERSION' ) ) {
			// Belt-and-suspenders: only resolves when shop_order is registered with WPML's translation system (typically via WCML).
			$language_details = apply_filters( 'wpml_post_language_details', null, $order->get_id() );
			if ( ! empty( $language_details ) && ! is_wp_error( $language_details ) && ! empty( $language_details['language_code'] ) ) {
				$lang = $language_details['language_code'];
			}
		}

		return apply_filters( 'bwfan_order_language', $lang, $order );
	}

	/**
	 * Worker-side self-heal for the plugin's own recurring hooks.
	 *
	 * validate_scheduled_recurring_actions() otherwise only runs from admin/REST paths, so a store where
	 * nobody opens wp-admin stays broken indefinitely once a hook loses its row. Throttled so the worker
	 * does not pay for the lookup on every single run.
	 *
	 * @return void
	 */
	public static function validate_recurring_actions_on_worker() {
		$interval = absint( apply_filters( 'bwfan_validate_recurring_actions_interval', 30 * MINUTE_IN_SECONDS ) );

		/**
		 * 0 means "do not throttle", not "switch the self-heal off". absint() also lands here for a filter
		 * returning false, null or a non-numeric string, and on a cron-only store this is the only path that
		 * repairs a missing hook - failing that silently closed would be worse than running it every request.
		 */
		if ( 0 === $interval ) {
			self::validate_scheduled_recurring_actions();

			return;
		}

		$throttle_key = 'bwfan_recurring_actions_validated';
		if ( ! empty( get_transient( $throttle_key ) ) ) {
			return;
		}

		set_transient( $throttle_key, time(), $interval );

		self::validate_scheduled_recurring_actions();
	}

	/**
	 * Map a multilingual plugin language code (e.g. 'de', 'zh-hans') to a full WordPress locale
	 * (e.g. 'de_DE', 'zh_CN') so it can be used with switch_to_locale(). Best-effort resolution via
	 * the active multilingual plugin's own APIs, with graceful fallback (returns '' when unresolved).
	 *
	 * @param string $lang
	 *
	 * @return string
	 */
	public static function get_locale_from_language_code( $lang ) {
		if ( empty( $lang ) || ! is_string( $lang ) ) {
			return '';
		}

		/**
		 * Resolved once per language code: this runs for every date merge tag, and the WPML branch below
		 * costs a filter call. Only successful resolutions are cached - an empty result may simply mean the
		 * multilingual plugin had not finished booting on the first call, and caching that would keep the
		 * whole request unresolved.
		 */
		static $resolved = array();
		if ( ! empty( $resolved[ $lang ] ) ) {
			return $resolved[ $lang ];
		}

		/** Already a full locale like de_DE */
		if ( false !== strpos( $lang, '_' ) ) {
			$resolved[ $lang ] = apply_filters( 'bwfan_locale_from_language_code', $lang, $lang );

			return $resolved[ $lang ];
		}

		$locale = '';

		/** WPML */
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			/**
			 * SitePress::get_locale() is a plain lookup in WPML's locale map and works in cron/async
			 * requests. 'wpml_active_languages' builds language-switcher data for the current page and
			 * can come back empty outside a normal front-end request, so it is only the fallback.
			 */
			global $sitepress;
			if ( is_object( $sitepress ) && method_exists( $sitepress, 'get_locale' ) ) {
				$wpml_locale = $sitepress->get_locale( $lang );
				$locale      = is_string( $wpml_locale ) ? $wpml_locale : '';
			}

			if ( empty( $locale ) ) {
				$languages = apply_filters( 'wpml_active_languages', null );
				if ( is_array( $languages ) && isset( $languages[ $lang ]['default_locale'] ) && ! empty( $languages[ $lang ]['default_locale'] ) ) {
					$locale = $languages[ $lang ]['default_locale'];
				}
			}
		}

		/** Polylang */
		if ( empty( $locale ) && function_exists( 'PLL' ) ) {
			$pll = PLL();
			if ( is_object( $pll ) && isset( $pll->model ) && method_exists( $pll->model, 'get_language' ) ) {
				$pll_lang = $pll->model->get_language( $lang );
				if ( is_object( $pll_lang ) && ! empty( $pll_lang->locale ) ) {
					$locale = $pll_lang->locale;
				}
			}
		}

		$resolved[ $lang ] = apply_filters( 'bwfan_locale_from_language_code', $locale, $lang );

		return $resolved[ $lang ];
	}

	/**
	 * Validate scheduled recurring actions
	 *
	 * @return void
	 */
	public static function validate_scheduled_recurring_actions() {
		$hooks = [
			[
				'name'       => 'bwfan_run_event_queue',
				'time'       => MINUTE_IN_SECONDS,
				'args'       => [],
				'group_slug' => ''
			],
			[
				'name'       => 'bwfan_run_queue_v2',
				'time'       => MINUTE_IN_SECONDS,
				'args'       => [],
				'group_slug' => ''
			],
		];

		/** V1 queue runner, only when V1 automations are still in use */
		if ( self::is_automation_v1_active() ) {
			$hooks[] = [
				'name'       => 'bwfan_run_queue',
				'time'       => MINUTE_IN_SECONDS,
				'args'       => [],
				'group_slug' => ''
			];
		}

		/**
		 * Daily housekeeping. A missing minute hook is noticed within minutes; these are the ones that die
		 * quietly - logs grow forever, expired coupons are never purged, carts are never marked lost.
		 *
		 * 'start' only sets the first occurrence. Every one of these is registered in init() with a
		 * bwf_recurring_action_{hook}_execution_time filter that pins later runs to store midnight, so the
		 * value here only has to match the original scheduler.
		 */
		$midnight_time = self::get_midnight_store_time();

		$hooks[] = [
			'name'       => 'bwfan_delete_logs',
			'time'       => DAY_IN_SECONDS,
			'args'       => [],
			'group_slug' => '',
			'start'      => self::get_store_time( 4 )
		];

		/**
		 * Ungated: Lite handles this itself (table validation, notifications), so it has to come back even
		 * on a site where Pro was deactivated - Pro's deactivation() unschedules it without checking.
		 */
		$hooks[] = [
			'name'       => 'bwfan_run_midnight_cron',
			'time'       => DAY_IN_SECONDS,
			'args'       => [],
			'group_slug' => '',
			'start'      => $midnight_time
		];

		/** Abandoned cart tracking is unscheduled when WooCommerce is inactive, so only re-arm it alongside WC */
		if ( class_exists( 'WooCommerce' ) ) {
			$hooks[] = [
				'name'       => 'bwfan_check_abandoned_carts',
				'time'       => MINUTE_IN_SECONDS,
				'args'       => [],
				'group_slug' => ''
			];
			$hooks[] = [
				'name'       => 'bwfan_delete_expired_autonami_coupons',
				'time'       => DAY_IN_SECONDS,
				'args'       => [],
				'group_slug' => '',
				'start'      => $midnight_time
			];
			$hooks[] = [
				'name'       => 'bwfan_mark_abandoned_lost_cart',
				'time'       => DAY_IN_SECONDS,
				'args'       => [],
				'group_slug' => '',
				'start'      => $midnight_time
			];
		}

		/** Handled by the connectors add-on, which unschedules this on its own deactivation */
		if ( class_exists( 'BWFAN_Connectors_Common' ) ) {
			$hooks[] = [
				'name'       => 'bwfan_run_midnight_connectors_sync',
				'time'       => DAY_IN_SECONDS,
				'args'       => [],
				'group_slug' => '',
				'start'      => $midnight_time
			];
		}

		/** Pro-only hooks, nothing handles these on Lite */
		if ( bwfan_is_autonami_pro_active() ) {
			$hooks[] = [
				'name'       => 'bwfcrm_broadcast_run_queue',
				'time'       => MINUTE_IN_SECONDS,
				'args'       => [],
				'group_slug' => 'bwfcrm'
			];
			$hooks[] = [
				'name'       => 'bwfan_5_minute_worker',
				'time'       => MINUTE_IN_SECONDS * 5,
				'args'       => [],
				'group_slug' => ''
			];
		}

		self::delete_unclaimable_recurring_rows( array_column( $hooks, 'name' ) );

		foreach ( $hooks as $hook ) {
			/**
			 * Status 0 (pending) only. A row stuck at 1 (running) or 2 (duplicate run detected) will never
			 * execute again, and counting it as scheduled is what leaves the hook dead - the exact state this
			 * method exists to repair. Args and group stay unfiltered, matching how this has always matched.
			 * The 4th argument is ignored by framework copies older than 1.10.12.86, which degrades to the
			 * previous any-status behaviour rather than erroring.
			 */
			if ( bwf_has_action_scheduled( $hook['name'], array(), '', 0 ) ) {
				continue;
			}

			$start = isset( $hook['start'] ) ? $hook['start'] : time();

			bwf_schedule_recurring_action( $start, $hook['time'], $hook['name'], $hook['args'], $hook['group_slug'] );
		}
	}

	/**
	 * Drop rows of our own recurring hooks that can never run again.
	 *
	 * Status 2 is written by BWF_AS_Action_Store when it finds the same recurring hook already running.
	 * The row is normally consumed right after, so a leftover only exists when that request died - and
	 * nothing else removes it: the claim query only takes status 0 and Action Scheduler's cleaner only
	 * looks at running actions. The re-arm above no longer counts these rows as scheduled, so this is
	 * garbage collection rather than the thing standing between a hook and its next run.
	 *
	 * Only safe because every hook handled here is a plugin-owned singleton with no arguments.
	 *
	 * @param array $hooks
	 *
	 * @return void
	 */
	public static function delete_unclaimable_recurring_rows( $hooks ) {
		if ( empty( $hooks ) || ! is_array( $hooks ) ) {
			return;
		}

		global $wpdb;

		$placeholders_hooks = implode( ', ', array_fill( 0, count( $hooks ), '%s' ) );

		$query = $wpdb->prepare( "DELETE FROM `{$wpdb->prefix}bwf_actions` WHERE `hook` IN ($placeholders_hooks) AND `status` = 2", $hooks ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		$wpdb->query( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
	}

	/**
	 * Get contact status list
	 *
	 * @return array
	 */
	public static function get_contact_status_array_list() {
		return [
			[
				'id'            => 0,
				'name'          => __( 'Unverified', 'wp-marketing-automations' ),
				'slug'          => 'unverify',
				'readableText'  => __( 'Unverified', 'wp-marketing-automations' ),
				'displaystatus' => 3,
				'statusAction'  => 'unverify',
				'statusclass'   => 'bwf-tags bwf-tag-gray',
			],
			[
				'id'            => 1,
				'name'          => __( 'Subscribed', 'wp-marketing-automations' ),
				'slug'          => 'resubscribe',
				'readableText'  => __( 'Opted In', 'wp-marketing-automations' ),
				'displaystatus' => 1,
				'statusAction'  => 'resubscribe',
				'statusclass'   => 'bwf-tags bwf-tag-blue',
			],
			[
				'id'            => 2,
				'name'          => __( 'Bounced', 'wp-marketing-automations' ),
				'slug'          => 'bounced',
				'readableText'  => __( 'Bounced', 'wp-marketing-automations' ),
				'displaystatus' => 4,
				'statusAction'  => 'bounced',
				'statusclass'   => 'bwf-tags bwf-tag-red',
			],
			[
				'id'            => 3,
				'name'          => __( 'Unsubscribed', 'wp-marketing-automations' ),
				'slug'          => 'unsubscribe',
				'readableText'  => __( 'Unsubscribed', 'wp-marketing-automations' ),
				'displaystatus' => 2,
				'statusAction'  => 'unsubscribe',
				'statusclass'   => 'bwf-tags bwf-tag-orange',
			],
			[
				'id'            => 4,
				'name'          => __( 'Soft Bounced', 'wp-marketing-automations' ),
				'slug'          => 'softbounced',
				'readableText'  => __( 'Soft Bounced', 'wp-marketing-automations' ),
				'displaystatus' => 5,
				'statusAction'  => 'softbounced',
				'statusclass'   => 'bwf-tags bwf-tag-red',
			],
			[
				'id'            => 5,
				'name'          => __( 'Complaint', 'wp-marketing-automations' ),
				'slug'          => 'complaint',
				'readableText'  => __( 'Complaint', 'wp-marketing-automations' ),
				'displaystatus' => 6,
				'statusAction'  => 'complaint',
				'statusclass'   => 'bwf-tags bwf-tag-red',
			]
		];
	}

	/**
	 * Check if async http call timeout is high and status of the call is not 200
	 *
	 * @param $force
	 * @param $last_run
	 *
	 * @return array|int[]|mixed
	 */
	public static function validate_core_worker( $force = false, $last_run = 0 ) {

		/** Check if the worker has run recently */
		$last_run = empty( $last_run ) ? bwf_options_get( 'fk_core_worker_let' ) : $last_run;
		if ( false === $force && $last_run > 0 ) {
			return ['response_code' => '' ];
		}

		$transient_val = get_transient( 'bwfan_core_worker_async' );
		if ( false === $force && false !== $transient_val ) {
			return $transient_val;
		}

		$start_time = microtime( true );
		$url        = rest_url( '/woofunnels/v1/worker' ) . '?' . time();
		$args       = array(
			'method'    => 'GET',
			'body'      => array(),
			'timeout'   => 0.01,
			'sslverify' => false,
		);

		$request  = wp_remote_post( $url, $args );
		$end_time = microtime( true );
		$data     = [];
		if ( 0.2 < ( $end_time - $start_time ) ) {
			/** Taking more than 200ms */
			$data = [ 'worker_async' => 1 ];
		}

		/** Check for response code in case of firewall */
		$status_code = wp_remote_retrieve_response_code( $request );

		/** Check for rate_limited error */
		if ( 429 === $status_code ) {
			$body        = json_decode( wp_remote_retrieve_body( $request ), true );
			$status_code = ( is_array( $body ) && isset( $body['msg'] ) ) ? $body['msg'] : $status_code;
		}

		if ( ! empty( $status_code ) && $status_code !== 200 && $status_code !== 'rate_limited' ) {
			$data['response_code'] = $status_code;
			set_transient( 'bwfan_core_worker_async', $data, 6 * HOUR_IN_SECONDS );

			return [ 'response_code' => $status_code ];
		}

		set_transient( 'bwfan_core_worker_async', $data, 6 * HOUR_IN_SECONDS );

		return $data;
	}

	public static function check_for_lks() {
		if ( ! is_null( self::$c_lk_p ) ) {
			return self::$c_lk_p;
		}

		$data = self::get_lk_data();
		$s    = isset( $data['s'] ) ? $data['s'] : 0;
		$e    = isset( $data['e'] ) ? $data['e'] : '';
		$ad   = isset( $data['ad'] ) ? $data['ad'] : '';
		$gp   = isset( $data['gp'] ) ? $data['gp'] : [ 1, 1 ];
		$h    = isset( $data['h'] ) ? $data['h'] : '';

		if ( 2 === intval( $s ) ) {
			if ( $e === '' ) {
				self::$c_lk_p = true;

				return self::$c_lk_p;
			}
			$n  = new DateTime();
			$et = new DateTime( $e );
			if ( $n > $et ) {
				$d = $n->diff( $et )->days;

				self::$c_lk_p = ( $d < 7 );

				return self::$c_lk_p;
			}
			self::$c_lk_p = true;

			return self::$c_lk_p;
		}
		if ( 1 === intval( $s ) ) {
			if ( $ad !== '' ) {
				$n   = new DateTime();
				$adt = new DateTime( $ad );
				$d   = $n->diff( $adt )->days;
				if ( $d < 7 ) {
					self::$c_lk_p = true;

					return self::$c_lk_p;
				}
			}
			self::$c_lk_p = false;

			return self::$c_lk_p;
		}

		self::$c_lk_p = false;

		return self::$c_lk_p;
	}

	/**
	 * Get last week date range
	 *
	 * @return array
	 * @throws DateMalformedStringException
	 */
	public static function get_notification_week_range() {
		$dates = [];

		$date = new DateTime( current_time( 'mysql', true ) );
		$date->setTimezone( wp_timezone() );
		if ( 1 !== intval( $date->format( 'N' ) ) ) {
			$date->modify( 'last Monday' );
		}
		$date->setTime( 0, 0, 0 );

		$date = $date->modify( '-7 day' );

		$dates['from_date'] = $date->format( 'Y-m-d' );

		$date = $date->modify( '+6 day' );

		$dates['to_date'] = $date->format( 'Y-m-d' );

		$date = $date->modify( '-7 day' );

		$dates['to_date_previous'] = $date->format( 'Y-m-d' );

		$date = $date->modify( '-6 day' );

		$dates['from_date_previous'] = $date->format( 'Y-m-d' );

		return $dates;
	}

	/**
	 * Get last month date range
	 *
	 * @return array
	 * @throws DateMalformedStringException
	 */
	public static function get_notification_month_range() {
		$dates = [];

		$date = new DateTime( current_time( 'mysql', true ) );
		$date->setTimezone( wp_timezone() );
		$date->modify( 'last Month' );
		$date->setDate( $date->format( 'Y' ), $date->format( 'm' ), 1 );
		$date->setTime( 0, 0, 0 );

		$dates['from_date'] = $date->format( 'Y-m-d' );

		$last_month = clone $date;

		$date->modify( 'last day of this month' );
		$dates['to_date'] = $date->format( 'Y-m-d' );

		$last_month->modify( 'last Month' );
		$last_month->setDate( $last_month->format( 'Y' ), $last_month->format( 'm' ), 1 );

		$dates['from_date_previous'] = $last_month->format( 'Y-m-d' );

		$last_month->modify( 'last day of this month' );
		$dates['to_date_previous'] = $last_month->format( 'Y-m-d' );

		return $dates;
	}

	/**
	 * Get last day date range
	 *
	 * @return array
	 * @throws DateMalformedStringException
	 */
	public static function get_notification_day_range() {
		$dates = [];

		$date = new DateTime( current_time( 'mysql', true ) );
		$date->modify( '-1 day' );
		$date->setTime( 0, 0, 0 );

		$dates['from_date'] = $date->format( 'Y-m-d' );
		$dates['to_date']   = $date->format( 'Y-m-d' );

		$date->modify( '-1 day' );

		$dates['from_date_previous'] = $date->format( 'Y-m-d' );
		$dates['to_date_previous']   = $date->format( 'Y-m-d' );

		return $dates;
	}

	/**
	 * This function deletes engagement meta table's merge tag data from the "Tools" tab.
	 *
	 * @return void
	 */
	public static function delete_engagement_tracking_meta_tool_action() {
		$datetime = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

		/** Fetched the last engagement ID of engagements from one day ago. */
		$datetime->modify( '-1 day' );
		$one_day_old = $datetime->format( 'Y-m-d H:i:s' );

		global $wpdb;
		$query = "SELECT MAX(`ID`) FROM {$wpdb->prefix}bwfan_engagement_tracking WHERE `created_at` < %s";

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		$last_id = $wpdb->get_var( $wpdb->prepare( $query, $one_day_old ) );
		if ( empty( $last_id ) ) {
			bwf_unschedule_actions( 'bwfan_delete_engagement_tracking_meta_tool_action' );

			return;
		}

		$start_time = time();
		do {
			$query = "DELETE FROM {$wpdb->prefix}bwfan_engagement_trackingmeta WHERE `eid` <= %d AND `meta_key` = %s LIMIT 500";
			//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			$rows_affected = $wpdb->query( $wpdb->prepare( $query, $last_id, 'merge_tags' ) );

			/** If no rows deleted, we're done */
			if ( $rows_affected === 0 ) {
				bwf_unschedule_actions( 'bwfan_delete_engagement_tracking_meta_tool_action' );
				break;
			}
		} while ( ( time() - $start_time ) < 15 && ! BWFAN_Common::memory_exceeded() );
	}

	/**
	 * Schedule email notification daily
	 *
	 * @return void
	 */
	public static function schedule_notification() {
		$settings = BWFAN_Common::get_global_settings();
		if ( empty( $settings['bwfan_enable_notification'] ) ) {
			return;
		}

		$ins = BWFAN_Notification_Email::get_instance();

		$old_settings = $settings;

		$old_settings['bwfan_notification_time']['ampm'] = ( 'am' === $settings['bwfan_notification_time']['ampm'] ) ? 'pm' : 'am';

		$ins->set_scheduler( $old_settings, $settings );
	}

	public static function bwfan_get_date_format() {
		return ! empty( get_option( 'date_format' ) ) ? get_option( 'date_format' ) : 'Y-m-d';
	}

	public static function bwfan_get_time_format() {
		return ! empty( get_option( 'time_format' ) ) ? get_option( 'time_format' ) : 'g:i a';
	}

	public static function get_worker_delay_timestamp() {
		return ( 60 * apply_filters( 'bwfan_worker_notification_status_delay_var_in_min', 5 ) );
	}

	/**
	 * Get the UTC date time value from store date time value
	 *
	 * @param $store_date
	 * @param $format
	 *
	 * @return string
	 */
	public static function get_utc_date_from_store_date( $store_date, $format = 'Y-m-d H:i:s' ) {
		if ( empty( $store_date ) ) {
			return '';
		}

		try {
			$utc_date = get_gmt_from_date( $store_date, $format );

			return $utc_date ?: '';
		} catch ( Exception|Error $e ) {
			return '';
		}
	}

	public static function get_user_menu_access() {
		$user_menu_access = apply_filters( 'bwfan_user_menu_access_data', [] );
		$menu_data        = [];

		if ( ! empty( $user_menu_access ) && isset( $user_menu_access[ get_current_user_id() ] ) ) {
			$menu_data = $user_menu_access[ get_current_user_id() ];
		}

		return $menu_data;
	}

	/**
	 * Get timezone lists
	 */
	public static function get_timezone_list() {
		static $regions = array(
			DateTimeZone::AFRICA,
			DateTimeZone::AMERICA,
			DateTimeZone::ANTARCTICA,
			DateTimeZone::ASIA,
			DateTimeZone::ATLANTIC,
			DateTimeZone::AUSTRALIA,
			DateTimeZone::EUROPE,
			DateTimeZone::INDIAN,
			DateTimeZone::PACIFIC,
		);

		$timezones = array();
		foreach ( $regions as $region ) {
			$timezones = array_merge( $timezones, DateTimeZone::listIdentifiers( $region ) );
		}

		$timezone_offsets = array();
		foreach ( $timezones as $timezone ) {
			$tz                            = new DateTimeZone( $timezone );
			$timezone_offsets[ $timezone ] = $tz->getOffset( new DateTime() );
		}

		asort( $timezone_offsets );

		$timezone_list = array();
		foreach ( $timezone_offsets as $timezone => $offset ) {
			$offset_prefix    = $offset < 0 ? '-' : '+';
			$offset_formatted = gmdate( 'H:i', abs( $offset ) );

			$pretty_offset = "UTC{$offset_prefix}{$offset_formatted}";

			$timezone_list[ $timezone ] = "({$pretty_offset}) $timezone";
		}

		return $timezone_list;
	}

	/**
	 * Check advance log enabled
	 *
	 * @param $log
	 *
	 * @return bool
	 */
	public static function is_log_enabled( $log ) {
		$global_settings = BWFAN_Common::get_global_settings();

		return isset( $global_settings['bwfan_advance_logs'] ) && ! empty( $global_settings['bwfan_advance_logs'] ) && isset( $global_settings[ $log ] ) && ! empty( $global_settings[ $log ] );
	}

	/**
	 * Get formatted tag & list data if comma exists in value
	 *
	 * @param $data
	 *
	 * @return array|string[]
	 */
	public static function check_for_comma_seperated( $data ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return [];
		}
		$formatted_data = [];
		foreach ( $data as $value ) {
			if ( ! empty( ( $value['id'] ) ) || false === strpos( $value['value'], ',' ) ) {
				$formatted_data[] = $value;
				continue;
			}
			$comma_separated_values = explode( ',', $value['value'] );
			$comma_separated_values = array_map( function ( $single_value ) {
				return [
					'id'    => 0,
					'value' => trim( $single_value )
				];
			}, $comma_separated_values );
			$formatted_data         = array_merge( $formatted_data, $comma_separated_values );
		}

		return $formatted_data;
	}

	public static function minifyHtmlData( $content ) {
		if ( empty( $content ) ) {
			return '';
		}

		// Remove unnecessary whitespaces and newlines between tags
		$htmlMinified = preg_replace( '/\s+/', ' ', $content );
		$htmlMinified = preg_replace( '/> </', '><', $htmlMinified ); // Remove space between tags

		// Call minifyCSS to handle embedded CSS inside <style> tags
		$htmlMinified = self::minifyCSSInHTML( $htmlMinified );

		// Return the minified HTML content
		return $htmlMinified;
	}

	private static function minifyCSSContent( $css ) {
		if ( empty( $css ) ) {
			return '';
		}

		// Remove unnecessary whitespaces, comments, and newlines
		$cssMinified = preg_replace( '/\s+/', ' ', $css ); // Collapse multiple spaces into one
		$cssMinified = preg_replace( '/\/\*.*?\*\//s', '', $cssMinified ); // Remove comments with /s modifier
		$cssMinified = preg_replace( '/\s*([{:},;])\s*/', '$1', $cssMinified ); // Remove spaces around colons, commas, semicolons
		$cssMinified = preg_replace( '/[^\}]+\{\s*\}/', '', $cssMinified ); // Remove empty CSS selectors

		return $cssMinified;
	}

	// Minify embedded CSS in HTML content (inside <style> tags)
	private static function minifyCSSInHTML( $html ) {
		// Find all <style> tags and minify their content
		preg_match_all( '/<style.*?>(.*?)<\/style>/is', $html, $matches );

		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $cssContent ) {
				$minifiedCSS = self::minifyCSSContent( $cssContent ); // Minify CSS content inside the <style> tag
				$html        = str_replace( $cssContent, $minifiedCSS, $html ); // Replace the original CSS with the minified one
			}
		}

		preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches );
		$mergedCSS   = implode( ' ', $matches[1] );
		$mergedCSS   = self::mergeDuplicateMediaQueries( $mergedCSS );
		$minifiedCSS = self::minifyCSSContent( $mergedCSS ); // Minify CSS content inside the <style> tag
		$html        = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $html );
		$html        = preg_replace( '/<\/head>/', '<style>' . $minifiedCSS . '</style></head>', $html, 1 );

		return $html;
	}

	private static function mergeDuplicateMediaQueries( $style ) {
		// Extract all media queries and their rules
		$mediaQueries = [];

		$change_resolution = apply_filters( 'bwfan_change_media_query_resolution', '' );

		preg_match_all( '/@media[^{]+\{([\s\S]*?)\}}/i', $style, $mediaMatches );
		foreach ( $mediaMatches[0] as $mediaQuery ) {
			// Split the media query and its rules
			$parts = explode( '{', $mediaQuery, 2 );
			$query = trim( $parts[0] );
			$rules = trim( $parts[1] );

			if ( ! empty( $change_resolution ) && intval( $change_resolution ) > 0 ) {
				// Update screen sizes from 768px to 480px
				$query = preg_replace( '/(\(max-width:\s*)768px(\s*\))/i', '${1}' . $change_resolution . 'px${2}', $query );
			}

			// Store rules in the mediaQueries array
			if ( ! isset( $mediaQueries[ $query ] ) ) {
				$mediaQueries[ $query ] = [];
			}

			if ( str_ends_with( $rules, '}' ) ) {
				// Remove the last character (brace) from the string
				$rules = substr( $rules, 0, - 1 );
			}

			$mediaQueries[ $query ][] = $rules;
		}

		// Combine the media query rules into a single string
		$combinedCSS = '';
		foreach ( $mediaQueries as $query => $rules ) {
			$combinedCSS .= "{$query} { " . implode( ' ', $rules ) . " }\n";
		}

		// Remove all media queries from the style
		$style = preg_replace( '/@media[^{]*{([^{}]*{[^{}]*})*[^{}]*}/', '', $style );

		return $style . " " . $combinedCSS;
	}


	/**
	 * Callback function for running v2 automation
	 *
	 * @param $request
	 *
	 * @return void
	 */
	public static function run_v2_worker_tasks( $request = '' ) {
		self::nocache_headers();

		/** Rate-limit the worker endpoint. v2 GET has no unique-key gate, so this is the only re-entry guard. Shares the v2 cooldown with v2 POST. */
		if ( self::is_worker_rate_limited( 2 ) ) {
			wp_send_json( array(
				'msg'  => 'rate_limited',
				'time' => date_i18n( 'Y-m-d H:i:s' ),
			), 429 );
		}

		/** Stamp run start so subsequent calls within the cooldown window get 429. */
		bwf_options_update( 'bwfan_worker_let_v2', time() );

		self::event_advanced_logs( "V2 worker callback received" );

		/** Logs */
		$cron_check = self::is_log_enabled( 'bwfan_cron_check_logging' );
		if ( true === $cron_check || ( defined( 'BWF_CHECK_CRON_SCHEDULE' ) && true === BWF_CHECK_CRON_SCHEDULE ) ) {
			add_filter( 'bwf_logs_allowed', '__return_true', PHP_INT_MAX );
			$logger_obj = BWF_Logger::get_instance();
			$logger_obj->log( date_i18n( 'Y-m-d H:i:s' ) . ' - before worker run', 'fka-cron-check-v2', 'autonami' );
		}

		// Prepare and send response immediately to prevent timeout
		$resp = [
			'msg'       => 'success',
			'time'      => date_i18n( 'Y-m-d H:i:s' ),
			'datastore' => get_class( ActionScheduler_Store::instance() ),
		];
		$connection_closed = self::send_response_and_close_connection( $resp );

		// Now run worker tasks in background (client already received response)
		self::worker_as_run();

		self::event_advanced_logs( "V2 worker callback completed" );
		/** Logs */
		if ( true === $cron_check || ( defined( 'BWF_CHECK_CRON_SCHEDULE' ) && true === BWF_CHECK_CRON_SCHEDULE ) ) {
			if ( isset( $logger_obj ) ) {
				$logger_obj->log( date_i18n( 'Y-m-d H:i:s' ) . ' - after worker run', 'fka-cron-check-v2', 'autonami' );
			}
		}

		/**
		 * When the connection was actually detached, terminate now so neither WP_REST_Server
		 * nor any host cache layer emits headers against the already-committed output.
		 * Gated on REST_REQUEST because this function's signature ($request = '') permits
		 * non-REST callers (cron/CLI/do_action); exiting unconditionally there would kill
		 * the parent process mid-flow and leave Action Scheduler claims unreleased.
		 */
		if ( true === $connection_closed && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			exit;
		}
	}

	/**
	 * Validate redirect link by domain
	 *
	 * @param $link
	 * @param $l_hash
	 * @param $engagement_data
	 *
	 * @return mixed|string|null
	 */
	public static function validate_target_link( $link = '', $l_hash = '', $engagement_data = [] ) {

		/** Validate target link if all links not saved and l_hash empty */
		if ( empty( get_option( 'bwfan_all_link_saved' ) ) && empty( $l_hash ) ) {
			return self::validate_old_target_link( $link, $engagement_data );
		}

		/** Checking by cleaned url */
		$cleaned_url = BWFAN_Core()->conversation->get_cleaned_url( $link );

		/** Checking by l_hash & clean url */
		if ( ! empty( $l_hash ) ) {
			$is_link_exists = BWFAN_Model_Links::is_link_hash_exists( $l_hash, $cleaned_url );

			// if link entry not found return home url
			if ( empty( $is_link_exists ) ) {
				do_action( 'bwfan_invalid_tracking_link', $link );

				return home_url();
			}
			BWFAN_Email_Conversations::$link_id = ! empty( $is_link_exists['ID'] ) ? $is_link_exists['ID'] : 0;

			return $link;
		}

		/** Checking by link exist in links table */
		$is_link_exists = BWFAN_Model_Links::get_link_id_by_tid( $cleaned_url, $engagement_data[0] ?? [] );
		if ( ! empty( $is_link_exists ) ) {
			BWFAN_Email_Conversations::$link_id = $is_link_exists;

			return $link;
		}

		/** If site url and redirect url host are same */
		try {
			$link_host     = wp_parse_url( urldecode( $link ), PHP_URL_HOST );
			$site_url      = home_url();
			$site_url_host = wp_parse_url( $site_url, PHP_URL_HOST );
		} catch ( Error|Exception $e ) {
			return $link;
		}

		if ( $link_host !== $site_url_host ) {
			do_action( 'bwfan_invalid_tracking_link', $link );

			return home_url();
		}

		return $link;
	}

	/**
	 * Validate old target link if link is not saved
	 *
	 * @param $link
	 * @param $e_data
	 *
	 * @return mixed|string|null
	 */
	public static function validate_old_target_link( $link, $e_data = [] ) {
		try {
			$link_host     = wp_parse_url( urldecode( $link ), PHP_URL_HOST );
			$site_url      = home_url();
			$site_url_host = wp_parse_url( $site_url, PHP_URL_HOST );
		} catch ( Error|Exception $e ) {
			return $link;
		}

		/** Checking by cleaned url */
		$cleaned_url                        = BWFAN_Core()->conversation->get_cleaned_url( urldecode( $link ) );
		BWFAN_Email_Conversations::$link_id = BWFAN_Model_Links::get_link_id_by_tid( $cleaned_url, $e_data[0] );

		/** If site url and redirect url host are same */
		if ( $link_host === $site_url_host ) {
			return $link;
		}

		/** Allowed domains */
		$allowed_domains = apply_filters( 'bwfan_allowed_redirect_domains', [] );
		if ( empty( $allowed_domains ) ) {
			return $link;
		}

		/** Filter valid domains */
		$allowed_domains = array_filter( array_map( function ( $domain ) {
			return ! empty( $domain ) && is_string( $domain ) ? wp_parse_url( $domain, PHP_URL_HOST ) : false;
		}, $allowed_domains ) );

		return in_array( $link_host, $allowed_domains, true ) ? $link : $site_url;
	}

	/**
	 * Store links
	 *
	 * @return void
	 */
	public static function bwfan_store_template_links() {
		$last_id = intval( get_option( 'bwfan_template_links' ) );
		BWFAN_Common::log_test_data( 'Template link store process start', 'fk-store-link', true );
		global $wpdb;

		$email_regex        = BWFAN_Common::get_regex_pattern();
		$sms_whatsapp_regex = BWFAN_Common::get_regex_pattern( 3 );

		$start_time = time();
		while ( ( time() - $start_time ) < 15 ) {
			$query     = "SELECT et.`oid`,et.`sid`,et.`type`,et.`mode`,t.`ID`,t.`template` FROM {$wpdb->prefix}bwfan_templates AS t JOIN {$wpdb->prefix}bwfan_engagement_tracking AS et ON t.`ID`=et.`tid` WHERE tid > $last_id GROUP BY tid LIMIT 20";
			$templates = $wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			if ( empty( $templates ) ) {
				bwf_unschedule_actions( 'bwfan_store_template_links' );
				delete_option( 'bwfan_template_links' );
				update_option( 'bwfan_all_link_saved', true, true );
				BWFAN_Common::log_test_data( 'Template link store process complete', 'fk-store-link', true );
				break;
			}
			$template_ids = array_column( $templates, 'ID' );
			BWFAN_Common::log_test_data( 'Template ids :' . implode( ',', $template_ids ), 'fk-store-link', true );
			foreach ( $templates as $template ) {
				$mode = intval( $template['mode'] );
				$mode = $mode === BWFAN_Email_Conversations::$MODE_EMAIL ? 'email' : ( $mode === BWFAN_Email_Conversations::$MODE_SMS ? 'sms' : 'whatsapp' );

				$regex_pattern = ( $mode === 'email' ) ? $email_regex : $sms_whatsapp_regex;
				preg_replace_callback( $regex_pattern, function ( $matches ) use ( $mode, $template ) {
					/** According to Href (1) regex, URL is at 1 index. And for Link (3) Regex, 0 index. */
					$url = 'email' !== $mode ? $matches[0] : $matches[1];
					BWFAN_Common::log_test_data( 'id:' . $template['ID'] . ' URL: ' . $url, 'fk-store-link', true );

					if ( BWFAN_Common::is_exclude_url( $url ) ) {
						BWFAN_Common::log_test_data( 'exclude url', 'fk-store-link', true );

						return $matches[0];
					}

					/** Exclude click tracking for unsubscribe link and view email browser link*/
					if ( false !== strpos( $url, 'bwfan-action=unsubscribe' ) || false !== strpos( $url, 'bwfan-action=view_in_browser' ) ) {
						BWFAN_Common::log_test_data( 'unsubscribe or view url', 'fk-store-link', true );

						return 'email' !== $mode ? $url : str_replace( $matches[1], $url, $matches[0] );
					}
					$data = [
						'type'        => $template['type'],
						'oid'         => $template['oid'],
						'step_id'     => $template['sid'],
						'template_id' => $template['ID'],
					];

					/** Save link in DB */
					BWFAN_Core()->conversation->get_link_hash( $url, $data );

					return 'email' !== $mode ? $url : str_replace( $matches[1], $url, $matches[0] );
				}, $template['template'] );

				$last_id = $template['ID'];
			}
			BWFAN_Common::log_test_data( 'Last process template id :' . $last_id, 'fk-store-link', true );
			update_option( 'bwfan_template_links', $last_id );
		}
		BWFAN_Common::log_test_data( 'Template link store process end :', 'fk-store-link', true );
	}

	/**
	 * Get mail replace string
	 *
	 * @return array
	 */
	public static function get_mail_replace_string() {
		if ( defined( 'BWFAN_PRO_VERSION' ) && version_compare( BWFAN_PRO_VERSION, '3.5.2', '<=' ) ) {
			return [];
		}

		return [
			'http-equiv' => md5( 'http-equiv' ),
		];
	}

	/**
	 * Create contact if not created
	 *
	 * @param $user_id
	 *
	 * @return void
	 */
	public static function bwfan_register_user( $user_id ) {
		if ( did_action( 'woocommerce_before_checkout_process' ) ) {
			return;
		}
		if ( class_exists( 'WooFunnels_DB_Updater' ) ) {
			$db_updater = WooFunnels_DB_Updater::get_instance();
			if ( is_object( $db_updater ) && method_exists( $db_updater, 'do_profile_update_async_call' ) ) {
				$db_updater->do_profile_update_async_call( $user_id );
				define( 'BWF_DISABLE_CONTACT_PROFILE_UPDATE', 1 );
			}
		}
	}

	/**
	 * @return string
	 *
	 */
	public static function get_wc_tax_label_if_displayed() {
		if ( ! wc_tax_enabled() ) {
			return '';
		}

		$tax_display_mode   = get_option( 'woocommerce_tax_display_cart' );
		$prices_include_tax = wc_prices_include_tax();

		if ( $tax_display_mode === 'incl' && ! $prices_include_tax ) {
			return WC()->countries->inc_tax_or_vat();
		}

		if ( $tax_display_mode === 'excl' && $prices_include_tax ) {
			return WC()->countries->ex_tax_or_vat();
		}

		return '';
	}

	/**
	 * If constant define then get contact email by user id
	 *
	 * @param $user_id
	 * @param $contact_id
	 *
	 * @return string
	 */
	public static function get_contact_email( $user_id = 0, $contact_id = 0 ) {
		if ( ! defined( 'BWFAN_GET_CONTACT_EMAIL' ) || true !== BWFAN_GET_CONTACT_EMAIL || ( empty( $user_id ) && empty( $contact_id ) ) ) {
			return '';
		}

		$contact = new WooFunnels_Contact( $user_id, '', '', $contact_id );

		return $contact->get_id() > 0 ? $contact->get_email() : '';
	}

	/**
	 * Check language support
	 *
	 * @return bool
	 */
	public static function should_skip_language_support() {
		if ( ! function_exists( 'icl_get_languages' ) && ! function_exists( 'pll_the_languages' ) && ( ! function_exists( 'bwfan_is_translatepress_active' ) || ! bwfan_is_translatepress_active() ) && ( ! function_exists( 'bwfan_is_weglot_active' ) || ! bwfan_is_weglot_active() ) && ( ! function_exists( 'bwfan_is_gtranslate_active' ) || ! bwfan_is_gtranslate_active() ) && ( ! function_exists( 'bwfan_is_linguise_active' ) || ! bwfan_is_linguise_active() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get wp terms id and name array from ids, static query
	 *
	 * @param $term_ids
	 *
	 * @return array|object|stdClass[]|null
	 */
	public static function get_wp_term( $term_ids ) {
		if ( empty( $term_ids ) ) {
			return [];
		}

		global $wpdb;

		$placeholder = array_fill( 0, count( $term_ids ), '%d' );
		$placeholder = implode( ', ', $placeholder );

		$query = "SELECT `term_id` AS `id`,`name` FROM {$wpdb->prefix}terms WHERE `term_id` IN ($placeholder)";
		//phpcs:ignore WordPress.DB.PreparedSQL
		$query = $wpdb->prepare( $query, $term_ids );

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		return $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Get product names and id from ids, static query
	 *
	 * @param $product_ids
	 *
	 * @return array|object|stdClass[]|null
	 */
	public static function get_products_name( $product_ids ) {
		if ( empty( $product_ids ) ) {
			return [];
		}

		global $wpdb;

		$placeholder = array_fill( 0, count( $product_ids ), '%d' );
		$placeholder = implode( ', ', $placeholder );

		$query = "SELECT `ID` AS `id`, `post_title` AS `name`, `post_parent` FROM {$wpdb->prefix}posts WHERE `ID` IN ($placeholder)";
		//phpcs:ignore WordPress.DB.PreparedSQL
		$query = $wpdb->prepare( $query, $product_ids );

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		return $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Check server memory limit.
	 * Using 75% max
	 *
	 * @return bool
	 */
	public static function memory_exceeded() {
		$memory_limit   = self::get_memory_limit() * 0.9;
		$current_memory = memory_get_usage( true );

		return ( $current_memory >= $memory_limit );
	}

	/**
	 * Get the memory limit in bytes.
	 * If the memory limit is set to -1 or 'unlimited', it will return 32GB.
	 *
	 * @return int|mixed
	 */
	public static function get_memory_limit() {
		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			$memory_limit = '128M'; // Sensible default, and minimum required by WooCommerce
		}

		if ( ! $memory_limit || - 1 === $memory_limit || '-1' === $memory_limit ) {
			// Unlimited, set to 32GB.
			$memory_limit = '32G';
		}

		return self::convert_hr_to_bytes( $memory_limit );
	}

	/**
	 * Converts a shorthand byte value to an integer byte value.
	 *
	 * @param $value
	 *
	 * @return int|mixed
	 */
	public static function convert_hr_to_bytes( $value ) {
		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return wp_convert_hr_to_bytes( $value );
		}

		$value = strtolower( trim( $value ) );
		$bytes = (int) $value;

		if ( false !== strpos( $value, 'g' ) ) {
			$bytes *= GB_IN_BYTES;
		} elseif ( false !== strpos( $value, 'm' ) ) {
			$bytes *= MB_IN_BYTES;
		} elseif ( false !== strpos( $value, 'k' ) ) {
			$bytes *= KB_IN_BYTES;
		}

		// Deal with large (float) values which run into the maximum integer size.
		return min( $bytes, PHP_INT_MAX );
	}

	/**
	 * Get a list of attach actions/filters hook
	 *
	 * @param $hook
	 *
	 * @return array
	 * @throws ReflectionException
	 */
	final public static function get_list_of_attach_actions( $hook ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return [];
		}
		$output = [];
		$hooks  = $wp_filter[ $hook ]->callbacks;
		foreach ( $hooks as $priority => $reference ) {
			if ( ! is_array( $reference ) || 0 === count( $reference ) ) {
				continue;
			}
			foreach ( $reference as $index => $calls ) {
				if ( isset( $calls['function'] ) && is_array( $calls['function'] ) && count( $calls['function'] ) > 0 ) {
					if ( is_object( $calls['function'][0] ) ) {
						$cls_name = get_class( $calls['function'][0] );
						$output[] = [
							'type'       => 'class',
							'class'      => $cls_name,
							'function'   => $calls['function'][1],
							'class_path' => self::get_class_path( $cls_name ),
							'index'      => $index,
							'priority'   => $priority,
						];
					} else {
						$output[] = [
							'type'       => 'static_class',
							'class'      => $calls['function'][0],
							'function'   => $calls['function'][1],
							'class_path' => self::get_class_path( $calls['function'][0] ),
							'index'      => $index,
							'priority'   => $priority,
						];
					}
				} else {
					$output[] = [
						'type'          => 'function',
						'function'      => $calls['function'],
						'function_path' => self::get_function_path( $calls['function'] ),
						'index'         => $index,
						'priority'      => $priority,
					];
				}
			}
		}

		return $output;
	}

	/**
	 * Get a class path
	 *
	 * @param $class
	 *
	 * @return string
	 * @throws ReflectionException
	 */
	public static function get_class_path( $class = 'BWFAN_Core' ) {
		$reflector = new ReflectionClass( $class );
		$file_name = $reflector->getFileName();

		return dirname( $file_name );
	}

	/**
	 * Get a function path
	 *
	 * @param $function
	 *
	 * @return array|string|string[]
	 */
	public static function get_function_path( $function = '' ) {
		if ( empty( $function ) ) {
			return '';
		}
		try {
			$reflector = new ReflectionFunction( $function );
			$file_name = $reflector->getFileName();
			$directory = dirname( $file_name );
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$directory = str_replace( WP_CONTENT_DIR, '', $directory );
			}
		} catch ( ReflectionException $exception ) {
			$directory = $exception->getMessage();
		}

		return $directory;
	}

	/**
	 * Check if the site's main language is RTL (right-to-left)
	 * This checks the site's default locale, NOT the current user's language preference
	 * Uses WordPress native functions only - no hardcoded locale lists
	 *
	 * @return bool True if site's default language is RTL, false otherwise
	 */
	public static function is_site_rtl() {
		static $is_rtl = null;
		static $site_locale = null;

		// Return cached result if already determined
		if ( null !== $is_rtl ) {
			// Allow filtering of the final RTL result for flexibility
			return apply_filters( 'bwfan_is_site_rtl', $is_rtl, $site_locale );
		}

		// Get the site's default locale (bypassing user preferences)
		$site_locale = self::get_site_default_locale();

		// Switch to site's default locale temporarily
		$switched = switch_to_locale( $site_locale );

		if ( $switched ) {
			// Use WordPress's native is_rtl() function which checks $wp_locale
			// Now $wp_locale reflects the SITE's language, not user's preference
			$is_rtl = is_rtl();

			// Restore the original locale (user's preference if in admin)
			restore_previous_locale();
		} else {
			// If switching failed, check current state (fallback)
			// This shouldn't happen in normal WordPress installations
			$is_rtl = is_rtl();
		}

		// Allow filtering of the final RTL result for complete flexibility
		return apply_filters( 'bwfan_is_site_rtl', $is_rtl, $site_locale );
	}

	/**
	 * Get the site's default locale (not affected by user language preference)
	 * Replicates WordPress's get_locale() logic but without user preference override
	 *
	 * @return string Site's default locale
	 */
	private static function get_site_default_locale() {
		// Check if WPLANG is defined in wp-config (highest priority)
		if ( defined( 'WPLANG' ) ) {
			return WPLANG;
		}

		$locale = '';

		// Get from database options (site-wide setting)
		if ( is_multisite() ) {
			$locale = get_option( 'WPLANG' );
			if ( false === $locale ) {
				$locale = get_site_option( 'WPLANG' );
			}
		} else {
			$locale = get_option( 'WPLANG' );
		}

		// if locale is empty, set it to en_US
		if ( empty( $locale ) ) {
			$locale = 'en_US';
		}

		return $locale;
	}

	/**
	 * Returns sale menu data
	 *
	 * @return array
	 */
	public static function sale_notification_menu() {
		$month = gmdate( 'm' );
		if ( ! in_array( intval( $month ), [ 11, 12 ], true ) ) {
			return [];
		}

		$yearKey = gmdate( 'Y' );
		$data    = [];

		if ( self::show_pre_black_friday_header_notification() ) {
			$data = [
				'title'    => __( 'Black Friday', 'wp-marketing-automations' ),
				'campaign' => 'PREBF' . $yearKey,
			];
		} elseif ( self::show_black_friday_header_notification() ) {
			$data = [
				'title'    => __( 'Black Friday', 'wp-marketing-automations' ),
				'campaign' => 'BF' . $yearKey,
			];
		} elseif ( self::show_small_business_saturday_header_notification() ) {
			$data = [
				'title'    => __( 'Black Friday', 'wp-marketing-automations' ),
				'campaign' => 'BFSB' . $yearKey,
			];
		} elseif ( self::show_black_friday_extended_header_notification() ) {
			$data = [
				'title'    => __( 'Black Friday', 'wp-marketing-automations' ),
				'campaign' => 'BFE' . $yearKey,
			];
		} elseif ( self::show_cyber_monday_header_notification() ) {
			$data = [
				'title'    => __( 'Cyber Monday', 'wp-marketing-automations' ),
				'campaign' => 'CM' . $yearKey,
			];
		} elseif ( self::show_extended_cyber_monday_header_notification() ) {
			$data = [
				'title'    => __( 'Cyber Monday', 'wp-marketing-automations' ),
				'campaign' => 'CME' . $yearKey,
			];
		}

		// Show Green Monday notification independently
		if ( empty( $data ) && self::show_green_monday_header_notification() ) {
			$data = [
				'title'    => __( 'Green Monday', 'wp-marketing-automations' ),
				'campaign' => 'GM' . $yearKey,
			];
		}

		return $data;
	}

	/**
	 * Check black friday difference
	 *
	 * @return int
	 */
	public static function get_black_friday_day_diff() {
		// Set the timezone to 'America/New_York'
		$timezone = new DateTimeZone( 'America/New_York' );
		// Create DateTime object for today's date and time in the specified timezone
		$today = new DateTime( 'now', $timezone );

		// Get the current year
		$year = $today->format( 'Y' );
		// Start from November 30 at midnight UTC and calculate Black Friday
		$blackFriday = new DateTime( "{$year}-11-30 00:00:00", new DateTimeZone( 'UTC' ) );

		// Find the last Friday of November
		while ( $blackFriday->format( 'N' ) != 5 ) { //phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			$blackFriday = $blackFriday->modify( '-1 day' );
		}

		// Convert Black Friday date to 'America/New_York' timezone for accurate diff
		$blackFriday = $blackFriday->setTimezone( $timezone );

		// Calculate the difference in minutes between today and Black Friday
		$differenceInMinutes = $today->getTimestamp() - $blackFriday->getTimestamp();
		$differenceInMinutes = round( $differenceInMinutes / 60 );

		return $differenceInMinutes;
	}

	/**
	 * Get green monday difference
	 *
	 * @param $diff
	 *
	 * @return float|string
	 * @throws Exception
	 */
	public static function get_second_dec_monday_day_diff( $diff = true ) {
		// Set the timezone to 'America/New_York'
		$timezone = new DateTimeZone( 'America/New_York' );
		// Get today's date and time in the specified timezone
		$today = new DateTime( 'now', $timezone );

		// Get the current year
		$year = $today->format( 'Y' );

		// Create a DateTime object for Dec 01 at midnight UTC
		$dateObj = new DateTime( "{$year}-12-01 00:00:00", $timezone );

		// Get the day of the week (0 = Sunday, 1 = Monday, etc.)
		$dayOfWeek = intval( $dateObj->format( 'w' ) );
		// Calculate days to add to reach the first Monday of December
		// Monday is 1, so if Dec 1 is Monday (1), add 0 days
		// If Dec 1 is Sunday (0), add 1 day
		// If Dec 1 is any other day, add (8 - dayOfWeek) to reach next Monday
		$daysToAdd = ( 1 === $dayOfWeek ) ? 0 : ( ( 0 === $dayOfWeek ) ? 1 : ( 8 - $dayOfWeek ) );

		// Move to the first Monday of December
		$dateObj->modify( "+{$daysToAdd} days" );

		// Move to the second Monday of December
		$dateObj->modify( '+7 days' );

		if ( $diff ) {
			// Calculate the difference in minutes between today and the second Monday of December
			$differenceInMinutes = round( ( $today->getTimestamp() - $dateObj->getTimestamp() ) / 60 );

			return $differenceInMinutes;
		}

		// Return the formatted date of the second Monday of December
		return $dateObj->format( 'M d' );
	}

	/**
	 * Show preblack friday notification
	 *
	 * @return bool
	 */
	public static function show_pre_black_friday_header_notification() {
		// Get the difference in minutes between today and Black Friday
		$blackFridayDifference = self::get_black_friday_day_diff();
		// Check if the difference falls within the range for showing the notification
		// (-11 days in minutes to -4 days in minutes)
		if ( $blackFridayDifference >= - ( 11 * 1440 ) && $blackFridayDifference < - ( 4 * 1440 ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Show black friday notification
	 *
	 * @return bool
	 */
	public static function show_black_friday_header_notification() {
		// Get the difference in minutes between today and Black Friday
		$blackFridayDifference = self::get_black_friday_day_diff();

		// Check if the difference falls within the range for showing the notification
		// (-4 days in minutes to the day after Black Friday)
		if ( $blackFridayDifference >= - ( 4 * 1440 ) && $blackFridayDifference < 1440 ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Show small business notification
	 *
	 * @return bool
	 */
	public static function show_small_business_saturday_header_notification() {
		// Get the difference in minutes between today and Black Friday
		$blackFridayDifference = self::get_black_friday_day_diff();

		// Check if the difference falls within the range for showing the notification
		// (1 day to 2 days after Black Friday)
		if ( $blackFridayDifference >= 1440 && $blackFridayDifference < 2880 ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * show black friday extended notification
	 *
	 * @return bool
	 */
	public static function show_black_friday_extended_header_notification() {
		// Get the difference in minutes between today and Black Friday
		$blackFridayDifference = self::get_black_friday_day_diff();

		// Check if the difference falls within the range for showing the notification
		// (2 days to 3 days after Black Friday)
		if ( $blackFridayDifference >= 2880 && $blackFridayDifference < 4320 ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * show cyber monday notification
	 *
	 * @return bool
	 */
	public static function show_cyber_monday_header_notification() {
		// Get the difference in minutes between today and Black Friday
		$blackFridayDifference = self::get_black_friday_day_diff();

		// Check if the difference falls within the range for showing the notification
		// (3 days to 4 days after Black Friday)
		if ( $blackFridayDifference >= 4320 && $blackFridayDifference < 5760 ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * show cyber monday extended notification
	 *
	 * @return bool
	 */
	public static function show_extended_cyber_monday_header_notification() {
		// Get the difference in minutes between today and Black Friday
		$blackFridayDifference = self::get_black_friday_day_diff();

		// Check if the difference falls within the range for showing the notification
		// (4 days to 8 days after Black Friday)
		if ( $blackFridayDifference >= 5760 && $blackFridayDifference < 11520 ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * show green monday notification
	 *
	 * @return bool
	 */
	public static function show_green_monday_header_notification() {
		// Get the difference in minutes between today and the second Monday of December
		$secondDecMondayDayDiff = self::get_second_dec_monday_day_diff();

		// Check if the difference falls within the range for showing the notification
		// (0 to 1 day after the second Monday of December)
		if ( $secondDecMondayDayDiff >= 0 && $secondDecMondayDayDiff < 1440 ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Get the real client IP address.
	 * Trusts CF-Connecting-IP only when CF-RAY is also present (Cloudflare always sends both).
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_RAY'] ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}

	/**
	 * Detect which code path is creating the abandoned cart.
	 *
	 * @return string
	 */
	public static function detect_cart_entry_point() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			if ( false !== strpos( $route, 'wc/store' ) ) {
				return 'store_api';
			}

			return 'rest_other';
		}
		if ( wp_doing_ajax() ) {
			return 'wc_ajax';
		}

		return 'unknown';
	}

	/**
	 * Collect visitor fingerprint for forensic analysis.
	 * Field set is gated by the GDPR Consent settings:
	 *   - bwfan_ab_capture_ip_address controls IP / IP-country / forwarded-for / Cloudflare scores
	 *   - bwfan_ab_capture_device controls user-agent, browser hints and language signals
	 * Returns an empty array when both settings are off; callers must skip writing in that case.
	 *
	 * @param string|null $entry_point Optional caller-supplied label (e.g. 'wc_add_to_cart').
	 *                                 Falls back to detect_cart_entry_point() when omitted.
	 *
	 * @return array
	 */
	public static function get_visitor_fingerprint( $entry_point = null ) {
		$settings       = self::get_global_settings();
		$capture_ip     = ! empty( $settings['bwfan_ab_capture_ip_address'] );
		$capture_device = ! empty( $settings['bwfan_ab_capture_device'] );

		if ( ! $capture_ip && ! $capture_device ) {
			return array();
		}

		$data = array(
			'entry_point'    => ! empty( $entry_point ) ? sanitize_key( $entry_point ) : self::detect_cart_entry_point(),
			'tracked_at'     => current_time( 'mysql', 1 ),
			'request_method' => sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ),
		);

		if ( $capture_ip ) {
			$data['ip']              = self::get_client_ip();
			$data['ip_country']      = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '' ) );
			$data['forwarded_for']   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) );
			$data['cf_bot_score']    = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_BOT_SCORE'] ?? '' ) );
			$data['cf_threat_score'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_THREAT_SCORE'] ?? '' ) );
		}

		if ( $capture_device ) {
			$data['user_agent']      = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
			$data['accept_encoding'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '' ) );
			$data['accept_language'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ) );
			$data['ch_ua']           = sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_CH_UA'] ?? '' ) );
			$data['ch_ua_mobile']    = sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '' ) );
			$data['ch_ua_platform']  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? '' ) );
			$data['sec_fetch_mode']  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '' ) );
			$data['sec_fetch_dest']  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '' ) );
			$data['sec_fetch_site']  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) );
			$data['referer']         = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) );
			$data['origin']          = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) );
			$data['content_type']    = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ?? '' ) );
		}

		return $data;
	}

	/**
	 * Parse a User-Agent string into device and browser info.
	 *
	 * @param string $ua Raw User-Agent string.
	 *
	 * @return array { 'device' => string, 'browser' => string }
	 */
	public static function parse_user_agent( $ua ) {
		$result = [ 'device' => '', 'browser' => '' ];
		if ( empty( $ua ) ) {
			return $result;
		}

		/**
		 * Device / OS — extract from the parenthesized section.
		 */
		if ( preg_match( '/\(([^)]+)\)/', $ua, $m ) ) {
			$raw = $m[1];

			if ( false !== strpos( $raw, 'Android' ) ) {
				// "Linux; Android 14; Pixel 8" → "Android 14 - Pixel 8"
				if ( preg_match( '/Android\s*([\d.]+)/', $raw, $v ) ) {
					$device = 'Android ' . $v[1];
					// Device model comes after the version and semicolon
					$parts = explode( ';', $raw );
					if ( count( $parts ) >= 3 ) {
						$model  = trim( end( $parts ) );
						$model  = str_replace( ' Build', '', $model );
						$device .= ' - ' . $model;
					}
					$result['device'] = $device;
				}
			} elseif ( false !== strpos( $raw, 'iPhone' ) ) {
				// "iPhone; CPU iPhone OS 17_0 like Mac OS X" → "iPhone iOS 17.0"
				if ( preg_match( '/iPhone OS ([\d_]+)/', $raw, $v ) ) {
					$result['device'] = 'iPhone iOS ' . str_replace( '_', '.', $v[1] );
				} else {
					$result['device'] = 'iPhone';
				}
			} elseif ( false !== strpos( $raw, 'iPad' ) ) {
				if ( preg_match( '/CPU OS ([\d_]+)/', $raw, $v ) ) {
					$result['device'] = 'iPad iOS ' . str_replace( '_', '.', $v[1] );
				} else {
					$result['device'] = 'iPad';
				}
			} elseif ( false !== strpos( $raw, 'Windows NT' ) ) {
				// "Windows NT 10.0; Win64; x64" → "Windows 10"
				$win_map = [
					'10.0' => '10/11',
					'6.3'  => '8.1',
					'6.2'  => '8',
					'6.1'  => '7',
				];
				if ( preg_match( '/Windows NT ([\d.]+)/', $raw, $v ) ) {
					$ver              = $win_map[ $v[1] ] ?? $v[1];
					$result['device'] = 'Windows ' . $ver;
				}
			} elseif ( false !== strpos( $raw, 'Mac OS X' ) || false !== strpos( $raw, 'Macintosh' ) ) {
				// "Macintosh; Intel Mac OS X 10_15_7" → "Mac OS X 10.15.7"
				if ( preg_match( '/Mac OS X ([\d_]+)/', $raw, $v ) ) {
					$result['device'] = 'Mac OS X ' . str_replace( '_', '.', $v[1] );
				} else {
					$result['device'] = 'Mac';
				}
			} elseif ( false !== strpos( $raw, 'Linux' ) ) {
				// "X11; Linux x86_64" → "Linux x86_64"
				if ( preg_match( '/Linux\s*(\S+)?/', $raw, $v ) ) {
					$result['device'] = 'Linux' . ( ! empty( $v[1] ) ? ' ' . $v[1] : '' );
				}
			} elseif ( false !== strpos( $raw, 'CrOS' ) ) {
				$result['device'] = 'Chrome OS';
			}
		}

		/**
		 * Browser — match tokens in priority order (most specific first).
		 */
		$browsers = [
			'EdgA/'            => 'Edge',
			'Edg/'             => 'Edge',
			'OPR/'             => 'Opera',
			'Opera/'           => 'Opera',
			'SamsungBrowser/'  => 'Samsung Browser',
			'UCBrowser/'       => 'UC Browser',
			'FxiOS/'           => 'Firefox',
			'CriOS/'           => 'Chrome',
			'Firefox/'         => 'Firefox',
		];

		$found_browser = false;
		foreach ( $browsers as $token => $name ) {
			if ( false !== strpos( $ua, $token ) ) {
				$version = '';
				if ( preg_match( '/' . preg_quote( $token, '/' ) . '([\d.]+)/', $ua, $v ) ) {
					// Major version only
					$version = strtok( $v[1], '.' );
				}
				$result['browser'] = $name . ( ! empty( $version ) ? ' ' . $version : '' );
				$found_browser     = true;
				break;
			}
		}

		if ( ! $found_browser ) {
			// Chrome must be checked after Edge/Opera since those UAs also contain "Chrome/"
			if ( false !== strpos( $ua, 'Chrome/' ) ) {
				if ( preg_match( '/Chrome\/([\d.]+)/', $ua, $v ) ) {
					$result['browser'] = 'Chrome ' . strtok( $v[1], '.' );
				}
			} elseif ( false !== strpos( $ua, 'Safari/' ) && false !== strpos( $ua, 'Version/' ) ) {
				// Safari: "Version/17.0 Safari/605.1.15" → "Safari 17"
				if ( preg_match( '/Version\/([\d.]+)/', $ua, $v ) ) {
					$result['browser'] = 'Safari ' . strtok( $v[1], '.' );
				}
			}
		}

		return $result;
	}

	public static function add_visitor_info_to_others( $fingerprint ) {
		if ( empty( $fingerprint ) || ! is_array( $fingerprint ) ) {
			return [];
		}

		/** Parse user_agent into device + browser */
		$parsed = self::parse_user_agent( $fingerprint['user_agent'] ?? '' );

		/** Device — prefer Client Hints when available, fall back to parsed UA */
		$device = '';
		if ( ! empty( $fingerprint['ch_ua_platform'] ) ) {
			$device = trim( $fingerprint['ch_ua_platform'], '\\"' );
		}
		if ( empty( $device ) && ! empty( $parsed['device'] ) ) {
			$device = $parsed['device'];
		}

		$labels = [
			'ip'         => __( 'IP Address', 'wp-marketing-automations' ),
			'ip_country' => __( 'IP Country', 'wp-marketing-automations' ),
		];

		$visitor_info = [];
		foreach ( $labels as $key => $label ) {
			if ( ! empty( $fingerprint[ $key ] ) ) {
				$visitor_info[ $label ] = stripslashes_deep( $fingerprint[ $key ] );
			}
		}

		if ( ! empty( $device ) ) {
			$visitor_info[ __( 'Device', 'wp-marketing-automations' ) ] = $device;
		}

		if ( ! empty( $parsed['browser'] ) ) {
			$visitor_info[ __( 'Browser', 'wp-marketing-automations' ) ] = $parsed['browser'];
		}

		if ( ! empty( $fingerprint['accept_language'] ) ) {
			/** Show just the primary language, e.g. "en-US" from "en-US,en;q=0.9" */
			$lang = strtok( $fingerprint['accept_language'], ',' );
			$visitor_info[ __( 'Language', 'wp-marketing-automations' ) ] = $lang;
		}

		return $visitor_info;
	}
	/** Decode "&amp;" etc. in a term name for display */
	public static function decode_term_name( $name ) {
		return wp_specialchars_decode( (string) $name, ENT_QUOTES );
	}

	/**
	 * Search terms by name, matching both "&" and the stored "&amp;" form
	 *
	 * @param string $taxonomy
	 * @param string $search
	 * @param int    $number max results, 0 = no limit
	 *
	 * @return WP_Term[]
	 */
	public static function search_terms_by_name( $taxonomy, $search, $number = 10 ) {
		$search = wp_specialchars_decode( (string) $search, ENT_QUOTES );
		/** WP stores term names through _wp_specialchars( ENT_NOQUOTES ): & < > encoded, quotes kept */
		$variants = array_unique( array( $search, htmlspecialchars( $search, ENT_NOQUOTES ) ) );
		$found    = array();

		foreach ( $variants as $variant ) {
			$terms = get_terms( array(
				'taxonomy'   => array( $taxonomy ),
				'orderby'    => 'id',
				'order'      => 'ASC',
				'number'     => $number,
				'hide_empty' => false,
				'fields'     => 'all',
				'name__like' => $variant,
			) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$found[ $term->term_id ] = $term;
			}
		}

		return $number > 0 ? array_slice( array_values( $found ), 0, $number ) : array_values( $found );
	}
}
