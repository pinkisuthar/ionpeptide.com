<?php
/**
 * Plugin Name: Ion Prior-Order Access Manager
 * Description: Configurable WooCommerce access control for selected product categories and products, with prior-order qualification, customer overrides, recurring weekend access, and scheduled open windows.
 * Version: 2.2.0
 * Author: BuildRightWeb
 */

defined( 'ABSPATH' ) || exit;

final class ION_Prior_Order_Category_Access {
	const VERSION     = '2.2.0';
	const OPTION_NAME = 'ion_prior_order_access_settings';
	const PAGE_SLUG   = 'ion-prior-order-access';

	/** @var array<int,bool> */
	private static $eligibility_cache = array();

	/** @var array<string,mixed>|null */
	private static $settings = null;

	/** @var bool|null */
	private static $settings_were_saved = null;

	/** @var int[]|null */
	private static $restricted_term_ids = null;

	/** @var int[]|null */
	private static $restricted_product_ids = null;

	/** @var string[]|null */
	private static $restricted_slugs = null;

	/** @var string[]|null */
	private static $restricted_product_urls = null;

	/** @var bool */
	private static $resolving_restricted_terms = false;

	public static function init() {
		/* Front-end protection. */
		add_filter( 'body_class', array( __CLASS__, 'add_body_class' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_frontend_access_state' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'protect_direct_requests' ), 1 );

		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_restricted_add_to_cart' ), 20, 6 );
		add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'filter_product_visibility' ), 20, 2 );
		add_filter( 'woocommerce_related_products', array( __CLASS__, 'filter_product_id_list' ), 20, 3 );
		add_filter( 'woocommerce_upsell_display_args', array( __CLASS__, 'filter_upsell_display_args' ), 20 );

		add_action( 'pre_get_posts', array( __CLASS__, 'filter_frontend_product_queries' ), 20 );
		add_filter( 'woocommerce_product_query_tax_query', array( __CLASS__, 'filter_wc_product_tax_query' ), 20, 2 );
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'filter_shortcode_product_query' ), 20, 3 );
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'filter_wc_get_products_query' ), 20, 2 );
		add_filter( 'rest_product_query', array( __CLASS__, 'filter_wp_product_rest_query' ), 20, 2 );
		add_filter( 'get_terms_args', array( __CLASS__, 'hide_restricted_category_terms' ), 20, 2 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'hide_restricted_menu_items' ), 20, 2 );

		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'block_restricted_rest_product' ), 20, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'filter_rest_product_collections' ), 20, 3 );

		/* Administration. */
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 99 );
		add_action( 'admin_post_ion_po_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_ion_po_add_customer', array( __CLASS__, 'handle_add_customer' ) );
		add_action( 'admin_post_ion_po_remove_customer', array( __CLASS__, 'handle_remove_customer' ) );
	}

	/**
	 * Load and normalize persisted settings.
	 *
	 * The old MU plugin had no option record. Until this page is saved for the
	 * first time, the legacy creams + drops defaults remain active automatically.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_settings() {
		if ( null !== self::$settings ) {
			return self::$settings;
		}

		$sentinel = '__ion_prior_order_settings_missing__';
		$stored   = get_option( self::OPTION_NAME, $sentinel );

		self::$settings_were_saved = ( $sentinel !== $stored && is_array( $stored ) );
		$stored = is_array( $stored ) ? $stored : array();

		/* Enable the restored recurring weekend rule once when upgrading from 2.1.x. */
		if ( self::$settings_were_saved && empty( $stored['weekend_rule_version'] ) ) {
			$stored['weekend_open']         = 1;
			$stored['weekend_rule_version'] = 1;
			update_option( self::OPTION_NAME, $stored, false );
		}

		$settings = wp_parse_args(
			$stored,
			array(
				'enabled'                 => 1,
				'weekend_open'            => 1,
				'weekend_rule_version'    => 1,
				'scheduled_open'          => 1,
				'scheduled_start'         => '2026-09-04T00:00',
				'scheduled_end'           => '2026-09-08T03:00',
				'restricted_category_ids' => array(),
				'restricted_product_ids'  => array(),
				'allowed_customers'       => array(),
			)
		);

		$settings['enabled']                 = empty( $settings['enabled'] ) ? 0 : 1;
		$settings['weekend_open']            = empty( $settings['weekend_open'] ) ? 0 : 1;
		$settings['scheduled_open']          = empty( $settings['scheduled_open'] ) ? 0 : 1;
		$settings['scheduled_start']         = self::sanitize_schedule_datetime( $settings['scheduled_start'] );
		$settings['scheduled_end']           = self::sanitize_schedule_datetime( $settings['scheduled_end'] );
		$settings['restricted_category_ids'] = self::sanitize_category_ids( $settings['restricted_category_ids'] );
		$settings['restricted_product_ids']  = self::sanitize_product_ids( $settings['restricted_product_ids'] );
		$settings['allowed_customers']       = self::sanitize_allowed_customers( $settings['allowed_customers'] );

		/* Compatibility with any early/internal build that stored plain emails. */
		if ( ! empty( $stored['allowed_emails'] ) && is_array( $stored['allowed_emails'] ) ) {
			foreach ( $stored['allowed_emails'] as $email ) {
				$email = sanitize_email( $email );
				if ( ! is_email( $email ) ) {
					continue;
				}

				$settings['allowed_customers'][] = array(
					'email'    => strtolower( $email ),
					'user_id'  => 0,
					'added_at' => '',
				);
			}
			$settings['allowed_customers'] = self::sanitize_allowed_customers( $settings['allowed_customers'] );
		}

		self::$settings = $settings;
		return self::$settings;
	}

	private static function persist_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$settings = wp_parse_args( $settings, self::get_settings() );

		$settings['enabled']                 = empty( $settings['enabled'] ) ? 0 : 1;
		$settings['weekend_open']            = empty( $settings['weekend_open'] ) ? 0 : 1;
		$settings['scheduled_open']          = empty( $settings['scheduled_open'] ) ? 0 : 1;
		$settings['scheduled_start']         = self::sanitize_schedule_datetime( $settings['scheduled_start'] );
		$settings['scheduled_end']           = self::sanitize_schedule_datetime( $settings['scheduled_end'] );
		$settings['restricted_category_ids'] = self::sanitize_category_ids( $settings['restricted_category_ids'] );
		$settings['restricted_product_ids']  = self::sanitize_product_ids( $settings['restricted_product_ids'] );
		$settings['allowed_customers']       = self::sanitize_allowed_customers( $settings['allowed_customers'] );

		unset( $settings['allowed_emails'] );

		update_option( self::OPTION_NAME, $settings, false );

		self::$settings            = $settings;
		self::$settings_were_saved = true;
		self::reset_runtime_caches();
	}

	private static function reset_runtime_caches() {
		self::$eligibility_cache       = array();
		self::$restricted_term_ids     = null;
		self::$restricted_product_ids  = null;
		self::$restricted_slugs        = null;
		self::$restricted_product_urls = null;
	}

	private static function access_control_enabled() {
		$settings = self::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		if ( ! empty( $settings['weekend_open'] ) && self::is_weekend_open_window() ) {
			return false;
		}

		if ( ! empty( $settings['scheduled_open'] ) && self::is_scheduled_open_window() ) {
			return false;
		}

		return true;
	}

	private static function eastern_timezone() {
		try {
			return new DateTimeZone( 'America/New_York' );
		} catch ( Exception $exception ) {
			return null;
		}
	}

	private static function is_weekend_open_window() {
		$timezone = self::eastern_timezone();
		if ( ! $timezone ) {
			return false;
		}

		$now     = new DateTimeImmutable( 'now', $timezone );
		$weekday = (int) $now->format( 'N' );
		$time    = $now->format( 'H:i' );

		if ( 5 === $weekday ) {
			return $time >= '20:00';
		}

		if ( 6 === $weekday || 7 === $weekday ) {
			return true;
		}

		return 1 === $weekday && $time < '03:00';
	}

	private static function weekend_open_status() {
		$settings = self::get_settings();
		if ( empty( $settings['weekend_open'] ) ) {
			return array( 'disabled', 'Recurring weekend access is disabled.' );
		}

		if ( self::is_weekend_open_window() ) {
			return array( 'active', 'Open access is active. Prior-order restrictions return Monday at 3:00 AM Eastern Time.' );
		}

		return array( 'scheduled', 'Repeats every Friday at 8:00 PM through Monday at 3:00 AM Eastern Time.' );
	}

	private static function sanitize_schedule_datetime( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ) {
			return '';
		}

		$timezone = self::eastern_timezone();
		if ( ! $timezone ) {
			return '';
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) ) {
			return '';
		}

		return $date->format( 'Y-m-d\TH:i' );
	}

	private static function get_scheduled_open_window() {
		$settings = self::get_settings();
		$timezone = self::eastern_timezone();

		if ( ! $timezone || empty( $settings['scheduled_start'] ) || empty( $settings['scheduled_end'] ) ) {
			return array( null, null );
		}

		$start = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $settings['scheduled_start'], $timezone );
		$end   = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $settings['scheduled_end'], $timezone );

		if ( ! $start || ! $end || $end <= $start ) {
			return array( null, null );
		}

		return array( $start, $end );
	}

	private static function is_scheduled_open_window() {
		list( $start, $end ) = self::get_scheduled_open_window();
		$timezone = self::eastern_timezone();

		if ( ! $start || ! $end || ! $timezone ) {
			return false;
		}

		$now = new DateTimeImmutable( 'now', $timezone );
		return $now >= $start && $now < $end;
	}

	private static function scheduled_open_status() {
		$settings = self::get_settings();
		if ( empty( $settings['scheduled_open'] ) ) {
			return array( 'disabled', 'Schedule disabled.' );
		}

		list( $start, $end ) = self::get_scheduled_open_window();
		$timezone = self::eastern_timezone();
		if ( ! $start || ! $end || ! $timezone ) {
			return array( 'invalid', 'Choose a valid start and end time.' );
		}

		$now = new DateTimeImmutable( 'now', $timezone );
		if ( $now < $start ) {
			return array( 'scheduled', 'Scheduled to open ' . $start->format( 'M j, Y \a\t g:i a T' ) . '.' );
		}

		if ( $now >= $end ) {
			return array( 'expired', 'Schedule ended ' . $end->format( 'M j, Y \a\t g:i a T' ) . '.' );
		}

		return array( 'active', 'Open access is active until ' . $end->format( 'M j, Y \a\t g:i a T' ) . '.' );
	}

	/**
	 * Legacy defaults preserved until the admin saves the new settings page.
	 *
	 * @return int[]
	 */
	private static function legacy_category_ids() {
		$ids = array();

		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return $ids;
		}

		foreach ( array( 'creams', 'drops', 'sprays' ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/** @return int[] */
	private static function selected_category_ids() {
		$settings = self::get_settings();

		if ( false === self::$settings_were_saved ) {
			return self::legacy_category_ids();
		}

		return self::sanitize_category_ids( $settings['restricted_category_ids'] );
	}

	/** @return int[] */
	private static function selected_product_ids() {
		$settings = self::get_settings();
		return self::sanitize_product_ids( $settings['restricted_product_ids'] );
	}

	private static function sanitize_category_ids( $ids ) {
		$ids = wp_parse_id_list( is_array( $ids ) ? $ids : array() );

		/*
		 * Keep persisted IDs intact when WooCommerce has not registered its
		 * taxonomy yet. Validating them during that temporary state would turn a
		 * real saved selection into an empty in-memory list for the whole request.
		 */
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		}

		return array_values(
			array_filter(
				$ids,
				function ( $term_id ) {
					$term = get_term( $term_id, 'product_cat' );
					return $term && ! is_wp_error( $term );
				}
			)
		);
	}

	private static function sanitize_product_ids( $ids ) {
		$clean = array();

		foreach ( wp_parse_id_list( is_array( $ids ) ? $ids : array() ) as $product_id ) {
			$post_type = get_post_type( $product_id );

			if ( 'product_variation' === $post_type ) {
				$product_id = (int) wp_get_post_parent_id( $product_id );
				$post_type  = get_post_type( $product_id );
			}

			if ( 'product' === $post_type && $product_id ) {
				$clean[] = absint( $product_id );
			}
		}

		return array_values( array_unique( array_filter( $clean ) ) );
	}

	private static function sanitize_allowed_customers( $entries ) {
		$clean = array();

		foreach ( (array) $entries as $entry ) {
			if ( is_string( $entry ) ) {
				$entry = array( 'email' => $entry );
			}

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$email = isset( $entry['email'] ) ? sanitize_email( $entry['email'] ) : '';
			if ( ! is_email( $email ) ) {
				continue;
			}

			$email = strtolower( $email );
			$clean[ $email ] = array(
				'email'    => $email,
				'user_id'  => isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : 0,
				'added_at' => isset( $entry['added_at'] ) ? sanitize_text_field( $entry['added_at'] ) : '',
			);
		}

		ksort( $clean, SORT_NATURAL | SORT_FLAG_CASE );
		return array_values( $clean );
	}

	/**
	 * Paid statuses that unlock access.
	 *
	 * @return string[]
	 */
	private static function qualifying_statuses() {
		if ( function_exists( 'wc_get_is_paid_statuses' ) ) {
			$statuses = wc_get_is_paid_statuses();
		} else {
			$statuses = array( 'processing', 'completed' );
		}

		$statuses = apply_filters( 'ion_prior_order_qualifying_statuses', $statuses );
		$statuses = array_map( 'sanitize_key', (array) $statuses );
		return array_values( array_unique( array_filter( $statuses ) ) );
	}

	/** Shop managers and administrators always retain access. */
	private static function current_user_is_manager() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' );
	}

	private static function user_has_manual_override( $user_id, $user = null ) {
		$user_id = absint( $user_id );
		$user    = $user instanceof WP_User ? $user : get_userdata( $user_id );

		if ( ! $user_id || ! $user ) {
			return false;
		}

		$account_email = is_email( $user->user_email ) ? strtolower( $user->user_email ) : '';
		$billing_email = sanitize_email( get_user_meta( $user_id, 'billing_email', true ) );
		$billing_email = is_email( $billing_email ) ? strtolower( $billing_email ) : '';

		foreach ( self::get_settings()['allowed_customers'] as $entry ) {
			$allowed_user_id = isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : 0;
			$allowed_email   = isset( $entry['email'] ) ? strtolower( sanitize_email( $entry['email'] ) ) : '';

			if ( $allowed_user_id && $allowed_user_id === $user_id ) {
				return true;
			}

			if ( $allowed_email && ( $allowed_email === $account_email || $allowed_email === $billing_email ) ) {
				return true;
			}
		}

		return false;
	}

	/** Determine whether the current/customer user may access restricted items. */
	public static function user_is_eligible( $user_id = 0 ) {
		/* Global open mode: every visitor is eligible. */
		if ( ! self::access_control_enabled() ) {
			return true;
		}

		if ( self::current_user_is_manager() ) {
			return true;
		}

		$user_id = absint( $user_id ? $user_id : get_current_user_id() );

		if ( ! $user_id ) {
			return false;
		}

		if ( array_key_exists( $user_id, self::$eligibility_cache ) ) {
			return self::$eligibility_cache[ $user_id ];
		}

		$user = get_userdata( $user_id );

		if ( self::user_has_manual_override( $user_id, $user ) ) {
			self::$eligibility_cache[ $user_id ] = true;
			return true;
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			self::$eligibility_cache[ $user_id ] = false;
			return false;
		}

		$statuses = self::qualifying_statuses();
		if ( empty( $statuses ) ) {
			self::$eligibility_cache[ $user_id ] = false;
			return false;
		}

		$base_args = array(
			'limit'   => 1,
			'return'  => 'ids',
			'status'  => $statuses,
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		$orders = wc_get_orders(
			array_merge(
				$base_args,
				array( 'customer_id' => $user_id )
			)
		);

		if ( ! empty( $orders ) ) {
			self::$eligibility_cache[ $user_id ] = true;
			return true;
		}

		/* Also recognize a paid guest order made with this account email. */
		if ( $user && is_email( $user->user_email ) ) {
			$orders = wc_get_orders(
				array_merge(
					$base_args,
					array( 'customer' => $user->user_email )
				)
			);

			if ( ! empty( $orders ) ) {
				self::$eligibility_cache[ $user_id ] = true;
				return true;
			}
		}

		self::$eligibility_cache[ $user_id ] = false;
		return false;
	}

	/**
	 * Resolve selected categories and every child category below them.
	 *
	 * @return int[]
	 */
	private static function restricted_term_ids() {
		if ( null !== self::$restricted_term_ids ) {
			return self::$restricted_term_ids;
		}

		if ( ! taxonomy_exists( 'product_cat' ) ) {
			/*
			 * WooCommerce can request term filtering before product_cat has been
			 * registered. Do not cache that temporary empty state: the admin
			 * coverage summary and later enforcement calls must be allowed to
			 * resolve the saved categories once the taxonomy becomes available.
			 */
			return array();
		}

		self::$restricted_term_ids = array();

		self::$resolving_restricted_terms = true;

		$root_ids   = self::selected_category_ids();
		$root_slugs = array();

		foreach ( $root_ids as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$root_slugs[] = sanitize_title( $term->slug );
			}
		}

		/* Preserve the original plugin's public slug filter compatibility. */
		$filtered_slugs = apply_filters( 'ion_prior_order_restricted_category_slugs', $root_slugs );
		foreach ( array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) $filtered_slugs ) ) ) ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$root_ids[] = (int) $term->term_id;
			}
		}

		$root_ids = array_values( array_unique( array_filter( array_map( 'absint', $root_ids ) ) ) );

		foreach ( $root_ids as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			self::$restricted_term_ids[] = (int) $term->term_id;
			$children = get_term_children( (int) $term->term_id, 'product_cat' );

			if ( ! is_wp_error( $children ) ) {
				self::$restricted_term_ids = array_merge(
					self::$restricted_term_ids,
					array_map( 'absint', $children )
				);
			}
		}

		self::$resolving_restricted_terms = false;
		self::$restricted_term_ids = array_values(
			array_unique(
				array_filter( array_map( 'absint', self::$restricted_term_ids ) )
			)
		);

		return self::$restricted_term_ids;
	}

	/** @return int[] */
	private static function restricted_product_ids() {
		if ( null === self::$restricted_product_ids ) {
			self::$restricted_product_ids = self::selected_product_ids();
		}

		return self::$restricted_product_ids;
	}

	/** @return string[] */
	private static function restricted_slugs() {
		if ( null !== self::$restricted_slugs ) {
			return self::$restricted_slugs;
		}

		$slugs = array();
		foreach ( self::restricted_term_ids() as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$slugs[] = sanitize_title( $term->slug );
			}
		}

		self::$restricted_slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) $slugs ) ) ) );
		return self::$restricted_slugs;
	}

	private static function product_is_restricted( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return false;
		}

		if ( 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = (int) wp_get_post_parent_id( $product_id );
		}

		if ( in_array( $product_id, self::restricted_product_ids(), true ) ) {
			return true;
		}

		$term_ids = self::restricted_term_ids();
		return ! empty( $term_ids ) && has_term( $term_ids, 'product_cat', $product_id );
	}

	private static function request_asks_for_restricted_category() {
		$restricted_slugs = self::restricted_slugs();

		foreach ( array( 'category', 'product_cat' ) as $key ) {
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			$parts = preg_split( '/[\s,|+]+/', strtolower( $value ) );

			foreach ( (array) $parts as $part ) {
				$part = sanitize_title( $part );
				if ( in_array( $part, $restricted_slugs, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function current_request_is_restricted() {
		if ( self::request_asks_for_restricted_category() ) {
			return true;
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			if ( $term && isset( $term->term_id ) ) {
				return in_array( (int) $term->term_id, self::restricted_term_ids(), true );
			}
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return self::product_is_restricted( get_queried_object_id() );
		}

		return false;
	}

	private static function restriction_notice( $login_required = false ) {
		if ( $login_required ) {
			return 'Please log in. Selected products are available to returning customers after their first paid order.';
		}

		return 'Selected products unlock after your first paid order.';
	}

	public static function add_body_class( $classes ) {
		if ( ! self::access_control_enabled() ) {
			$classes[] = 'ion-prior-order-open-mode';
		}

		/*
		 * Keep the header's visibility signal aligned with the actual access
		 * decision. Global open mode (including the configured weekend window)
		 * makes every visitor eligible, so it must expose the same body class as
		 * a customer who qualifies through a prior paid order.
		 */
		if ( self::user_is_eligible() ) {
			$classes[] = 'ion-prior-order-eligible';
		} else {
			$classes[] = 'ion-prior-order-locked';
		}

		return array_values( array_unique( $classes ) );
	}

	public static function print_frontend_access_state() {
		if ( is_admin() ) {
			return;
		}

		$state = array(
			'enabled'   => self::access_control_enabled(),
			'eligible'  => self::user_is_eligible(),
			'restNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		);

		echo '<script data-cfasync="false">window.IONPriorOrderAccess=' . wp_json_encode( $state ) . ';</script>' . "\n";
	}

	public static function protect_direct_requests() {
		if ( is_admin() || wp_doing_ajax() || self::user_is_eligible() ) {
			return;
		}

		if ( ! self::current_request_is_restricted() ) {
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::restriction_notice( ! is_user_logged_in() ), 'notice' );
		}

		if ( ! is_user_logged_in() ) {
			$destination = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
			$destination = add_query_arg(
				array(
					'ion_access'  => 'login',
					'redirect_to' => self::current_url(),
				),
				$destination
			);
		} else {
			$destination = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
			$destination = add_query_arg( 'ion_access', 'prior-order-required', $destination );
		}

		wp_safe_redirect( $destination, 302 );
		exit;
	}

	private static function current_url() {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		return esc_url_raw( $scheme . $host . $uri );
	}

	public static function block_restricted_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		$check_id = $variation_id ? $variation_id : $product_id;

		if ( ! self::user_is_eligible() && self::product_is_restricted( $check_id ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( self::restriction_notice(), 'error' );
			}
			return false;
		}

		return $passed;
	}

	public static function filter_product_visibility( $visible, $product_id ) {
		if ( self::user_is_eligible() ) {
			return $visible;
		}

		return self::product_is_restricted( $product_id ) ? false : $visible;
	}

	public static function filter_product_id_list( $product_ids ) {
		if ( self::user_is_eligible() || empty( $product_ids ) ) {
			return $product_ids;
		}

		return array_values(
			array_filter(
				(array) $product_ids,
				function ( $product_id ) {
					return ! self::product_is_restricted( $product_id );
				}
			)
		);
	}

	public static function filter_upsell_display_args( $args ) {
		/* IDs are filtered later by standard product visibility/query hooks. */
		return $args;
	}

	private static function add_restricted_tax_exclusion( $tax_query ) {
		$term_ids = self::restricted_term_ids();
		$tax_query = is_array( $tax_query ) ? $tax_query : array();

		if ( empty( $term_ids ) ) {
			return $tax_query;
		}

		$tax_query[] = array(
			'taxonomy'         => 'product_cat',
			'field'            => 'term_id',
			'terms'            => $term_ids,
			'operator'         => 'NOT IN',
			'include_children' => true,
		);

		return $tax_query;
	}

	private static function add_restricted_product_exclusion( $query_args ) {
		$query_args = is_array( $query_args ) ? $query_args : array();
		$product_ids = self::restricted_product_ids();

		if ( empty( $product_ids ) ) {
			return $query_args;
		}

		$existing = isset( $query_args['post__not_in'] ) ? wp_parse_id_list( $query_args['post__not_in'] ) : array();
		$query_args['post__not_in'] = array_values( array_unique( array_merge( $existing, $product_ids ) ) );
		return $query_args;
	}

	private static function query_targets_products( $query ) {
		$post_type = $query->get( 'post_type' );

		if ( 'product' === $post_type ) {
			return true;
		}

		if ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) {
			return true;
		}

		return 'product_query' === $query->get( 'wc_query' );
	}

	public static function filter_frontend_product_queries( $query ) {
		if ( self::user_is_eligible() ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! self::query_targets_products( $query ) ) {
			return;
		}

		$query->set( 'tax_query', self::add_restricted_tax_exclusion( $query->get( 'tax_query' ) ) );

		$product_args = self::add_restricted_product_exclusion(
			array( 'post__not_in' => $query->get( 'post__not_in' ) )
		);
		$query->set( 'post__not_in', $product_args['post__not_in'] );
	}

	public static function filter_wc_product_tax_query( $tax_query, $query ) {
		if ( self::user_is_eligible() ) {
			return $tax_query;
		}

		return self::add_restricted_tax_exclusion( $tax_query );
	}

	public static function filter_shortcode_product_query( $query_args, $attributes, $type ) {
		if ( self::user_is_eligible() ) {
			return $query_args;
		}

		$query_args['tax_query'] = self::add_restricted_tax_exclusion( isset( $query_args['tax_query'] ) ? $query_args['tax_query'] : array() );
		return self::add_restricted_product_exclusion( $query_args );
	}

	public static function filter_wc_get_products_query( $wp_query_args, $query_vars ) {
		if ( self::user_is_eligible() ) {
			return $wp_query_args;
		}

		$wp_query_args['tax_query'] = self::add_restricted_tax_exclusion( isset( $wp_query_args['tax_query'] ) ? $wp_query_args['tax_query'] : array() );
		return self::add_restricted_product_exclusion( $wp_query_args );
	}

	public static function filter_wp_product_rest_query( $args, $request ) {
		if ( self::user_is_eligible() ) {
			return $args;
		}

		$args['tax_query'] = self::add_restricted_tax_exclusion( isset( $args['tax_query'] ) ? $args['tax_query'] : array() );
		return self::add_restricted_product_exclusion( $args );
	}

	public static function hide_restricted_category_terms( $args, $taxonomies ) {
		if ( self::$resolving_restricted_terms || self::user_is_eligible() ) {
			return $args;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $args;
		}

		if ( ! in_array( 'product_cat', (array) $taxonomies, true ) ) {
			return $args;
		}

		$existing = isset( $args['exclude'] ) ? wp_parse_id_list( $args['exclude'] ) : array();
		$args['exclude'] = array_values( array_unique( array_merge( $existing, self::restricted_term_ids() ) ) );
		return $args;
	}

	private static function normalized_url_path( $url ) {
		$path = wp_parse_url( html_entity_decode( (string) $url ), PHP_URL_PATH );
		return untrailingslashit( strtolower( rawurldecode( (string) $path ) ) );
	}

	/** @return string[] */
	private static function restricted_product_urls() {
		if ( null !== self::$restricted_product_urls ) {
			return self::$restricted_product_urls;
		}

		$paths = array();
		foreach ( self::restricted_product_ids() as $product_id ) {
			$permalink = get_permalink( $product_id );
			if ( $permalink ) {
				$paths[] = self::normalized_url_path( $permalink );
			}
		}

		self::$restricted_product_urls = array_values( array_unique( array_filter( $paths ) ) );
		return self::$restricted_product_urls;
	}

	public static function hide_restricted_menu_items( $items, $args ) {
		if ( self::user_is_eligible() ) {
			return $items;
		}

		$restricted_slugs = self::restricted_slugs();
		$restricted_ids   = self::restricted_term_ids();
		$product_paths    = self::restricted_product_urls();

		foreach ( $items as $key => $item ) {
			$remove = false;

			if ( isset( $item->object, $item->object_id ) && 'product_cat' === $item->object ) {
				$remove = in_array( (int) $item->object_id, $restricted_ids, true );
			}

			if ( ! $remove && isset( $item->object, $item->object_id ) && 'product' === $item->object ) {
				$remove = self::product_is_restricted( (int) $item->object_id );
			}

			if ( ! $remove && ! empty( $item->url ) ) {
				$url      = strtolower( html_entity_decode( $item->url ) );
				$url_path = self::normalized_url_path( $item->url );

				if ( in_array( $url_path, $product_paths, true ) ) {
					$remove = true;
				}

				if ( ! $remove ) {
					foreach ( $restricted_slugs as $slug ) {
						if ( false !== strpos( $url, 'category=' . $slug ) || false !== strpos( $url, 'product_cat=' . $slug ) || false !== strpos( $url, '/product-category/' . $slug ) ) {
							$remove = true;
							break;
						}
					}
				}
			}

			if ( $remove ) {
				unset( $items[ $key ] );
			}
		}

		return array_values( $items );
	}

	public static function block_restricted_rest_product( $result, $server, $request ) {
		if ( self::user_is_eligible() ) {
			return $result;
		}

		$route      = $request->get_route();
		$product_id = 0;

		if ( preg_match( '#^/wc/store/v1/products/(\d+)(?:/)?$#', $route, $matches ) ) {
			$product_id = absint( $matches[1] );
		} elseif ( preg_match( '#^/wp/v2/product/(\d+)(?:/)?$#', $route, $matches ) ) {
			$product_id = absint( $matches[1] );
		}

		if ( $product_id && self::product_is_restricted( $product_id ) ) {
			return new WP_Error(
				'ion_restricted_product',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		return $result;
	}

	public static function filter_rest_product_collections( $response, $server, $request ) {
		if ( self::user_is_eligible() || is_wp_error( $response ) ) {
			return $response;
		}

		$route = $request->get_route();
		if ( ! preg_match( '#^/(?:wc/store/v1/products|wp/v2/product)/?$#', $route ) ) {
			return $response;
		}

		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$filtered = array_values(
			array_filter(
				$data,
				function ( $item ) {
					$product_id = is_array( $item ) && isset( $item['id'] ) ? absint( $item['id'] ) : 0;
					return ! $product_id || ! self::product_is_restricted( $product_id );
				}
			)
		);

		$response->set_data( $filtered );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	/* -------------------------------------------------------------------------
	 * Admin panel.
	 * ---------------------------------------------------------------------- */

	public static function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			'Prior-Order Access',
			'Prior-Order Access',
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
		}

		if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	private static function admin_page_url( $args = array() ) {
		$url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	private static function assert_admin_access() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage prior-order access.', 'ion-prior-order-access' ) );
		}
	}

	public static function handle_save_settings() {
		self::assert_admin_access();
		check_admin_referer( 'ion_po_save_settings' );

		$settings = self::get_settings();
		$settings['enabled'] = isset( $_POST['enabled'] ) ? absint( wp_unslash( $_POST['enabled'] ) ) : 0;
		$settings['weekend_open'] = isset( $_POST['weekend_open'] ) ? absint( wp_unslash( $_POST['weekend_open'] ) ) : 0;
		$settings['scheduled_open'] = isset( $_POST['scheduled_open'] ) ? absint( wp_unslash( $_POST['scheduled_open'] ) ) : 0;
		$settings['scheduled_start'] = isset( $_POST['scheduled_start'] ) ? self::sanitize_schedule_datetime( wp_unslash( $_POST['scheduled_start'] ) ) : '';
		$settings['scheduled_end'] = isset( $_POST['scheduled_end'] ) ? self::sanitize_schedule_datetime( wp_unslash( $_POST['scheduled_end'] ) ) : '';

		if ( ! empty( $settings['scheduled_open'] ) ) {
			$timezone = self::eastern_timezone();
			$start = $timezone && $settings['scheduled_start'] ? DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $settings['scheduled_start'], $timezone ) : false;
			$end   = $timezone && $settings['scheduled_end'] ? DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $settings['scheduled_end'], $timezone ) : false;

			if ( ! $start || ! $end || $end <= $start ) {
				wp_safe_redirect( self::admin_page_url( array( 'ion_po_notice' => 'invalid-schedule' ) ) );
				exit;
			}
		}

		$settings['restricted_category_ids'] = isset( $_POST['restricted_category_ids'] )
			? self::sanitize_category_ids( wp_unslash( $_POST['restricted_category_ids'] ) )
			: array();
		$settings['restricted_product_ids'] = isset( $_POST['restricted_product_ids'] )
			? self::sanitize_product_ids( wp_unslash( $_POST['restricted_product_ids'] ) )
			: array();

		self::persist_settings( $settings );
		wp_safe_redirect( self::admin_page_url( array( 'ion_po_notice' => 'saved' ) ) );
		exit;
	}

	public static function handle_add_customer() {
		self::assert_admin_access();
		check_admin_referer( 'ion_po_add_customer' );

		$email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_safe_redirect( self::admin_page_url( array( 'ion_po_notice' => 'invalid-email' ) ) );
			exit;
		}

		$email    = strtolower( $email );
		$user_id  = self::locate_customer_user_id( $email );
		$settings = self::get_settings();
		$entries  = self::sanitize_allowed_customers( $settings['allowed_customers'] );
		$found    = false;

		foreach ( $entries as &$entry ) {
			if ( $entry['email'] === $email ) {
				$entry['user_id'] = $user_id ? $user_id : $entry['user_id'];
				$found = true;
				break;
			}
		}
		unset( $entry );

		if ( ! $found ) {
			$entries[] = array(
				'email'    => $email,
				'user_id'  => $user_id,
				'added_at' => current_time( 'mysql', true ),
			);
		}

		/* Adding the first override must not erase the legacy creams/drops defaults. */
		if ( false === self::$settings_were_saved ) {
			$settings['restricted_category_ids'] = self::selected_category_ids();
		}

		$settings['allowed_customers'] = $entries;
		self::persist_settings( $settings );

		$notice = $found ? 'customer-exists' : ( $user_id ? 'customer-added' : 'email-added' );
		wp_safe_redirect( self::admin_page_url( array( 'ion_po_notice' => $notice ) ) );
		exit;
	}

	public static function handle_remove_customer() {
		self::assert_admin_access();

		$email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
		check_admin_referer( 'ion_po_remove_customer_' . md5( strtolower( $email ) ) );

		$settings = self::get_settings();
		$settings['allowed_customers'] = array_values(
			array_filter(
				self::sanitize_allowed_customers( $settings['allowed_customers'] ),
				function ( $entry ) use ( $email ) {
					return strtolower( $entry['email'] ) !== strtolower( $email );
				}
			)
		);

		self::persist_settings( $settings );
		wp_safe_redirect( self::admin_page_url( array( 'ion_po_notice' => 'customer-removed' ) ) );
		exit;
	}

	private static function locate_customer_user_id( $email ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );
		if ( $user instanceof WP_User ) {
			return (int) $user->ID;
		}

		$users = get_users(
			array(
				'number'     => 1,
				'fields'     => 'ids',
				'meta_key'   => 'billing_email',
				'meta_value' => $email,
				'orderby'    => 'ID',
				'order'      => 'DESC',
			)
		);

		if ( ! empty( $users ) ) {
			return absint( $users[0] );
		}

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'customer' => $email,
					'limit'    => 10,
					'return'   => 'objects',
					'orderby'  => 'date',
					'order'    => 'DESC',
				)
			);

			foreach ( $orders as $order ) {
				if ( is_object( $order ) && is_callable( array( $order, 'get_customer_id' ) ) ) {
					$user_id = absint( $order->get_customer_id() );
					if ( $user_id ) {
						return $user_id;
					}
				}
			}
		}

		return 0;
	}

	private static function category_choices() {
		self::$resolving_restricted_terms = true;
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		self::$resolving_restricted_terms = false;

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$children = array();
		$by_id    = array();

		foreach ( $terms as $term ) {
			$by_id[ (int) $term->term_id ] = $term;
			$parent = (int) $term->parent;
			if ( ! isset( $children[ $parent ] ) ) {
				$children[ $parent ] = array();
			}
			$children[ $parent ][] = (int) $term->term_id;
		}

		$choices = array();
		$visited = array();

		$walk = function ( $parent_id, $depth ) use ( &$walk, &$choices, &$visited, $children, $by_id ) {
			if ( empty( $children[ $parent_id ] ) ) {
				return;
			}

			foreach ( $children[ $parent_id ] as $term_id ) {
				if ( isset( $visited[ $term_id ] ) || ! isset( $by_id[ $term_id ] ) ) {
					continue;
				}

				$visited[ $term_id ] = true;
				$choices[ $term_id ] = str_repeat( '— ', $depth ) . $by_id[ $term_id ]->name;
				$walk( $term_id, $depth + 1 );
			}
		};

		$walk( 0, 0 );

		/* Include orphaned terms safely. */
		foreach ( $by_id as $term_id => $term ) {
			if ( ! isset( $visited[ $term_id ] ) ) {
				$choices[ $term_id ] = $term->name;
			}
		}

		return $choices;
	}

	private static function render_admin_notice() {
		$code = isset( $_GET['ion_po_notice'] ) ? sanitize_key( wp_unslash( $_GET['ion_po_notice'] ) ) : '';
		$messages = array(
			'saved'            => array( 'success', 'Access settings saved.' ),
			'customer-added'   => array( 'success', 'Customer located and granted access.' ),
			'email-added'      => array( 'warning', 'Email override saved. No linked WordPress customer account was found yet; access will apply when an account uses that email.' ),
			'customer-exists'  => array( 'info', 'That customer email already has an access override.' ),
			'customer-removed' => array( 'success', 'Customer access override removed.' ),
			'invalid-email'    => array( 'error', 'Enter a valid customer email address.' ),
			'invalid-schedule' => array( 'error', 'Scheduled open access needs a valid start and end time, and the end must be after the start.' ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		list( $type, $message ) = $messages[ $code ];
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	public static function render_admin_page() {
		self::assert_admin_access();

		$settings          = self::get_settings();
		$restrictions_active = self::access_control_enabled();
		$configured_enabled  = ! empty( $settings['enabled'] );
		$weekend_open        = ! empty( $settings['weekend_open'] );
		$weekend_status      = self::weekend_open_status();
		$scheduled_open      = ! empty( $settings['scheduled_open'] );
		$scheduled_start     = $settings['scheduled_start'];
		$scheduled_end       = $settings['scheduled_end'];
		$schedule_status     = self::scheduled_open_status();
		$selected_cats     = self::selected_category_ids();
		$selected_products = self::selected_product_ids();
		$category_choices  = self::category_choices();
		$allowed_customers = self::sanitize_allowed_customers( $settings['allowed_customers'] );
		?>
		<div class="wrap ion-po-wrap">
			<h1>Prior-Order Access</h1>
			<?php self::render_admin_notice(); ?>

			<div class="ion-po-status <?php echo $restrictions_active ? 'is-locked' : 'is-open'; ?>">
				<div>
					<strong><?php echo $restrictions_active ? 'LOCKED MODE ACTIVE' : 'OPEN MODE ACTIVE'; ?></strong>
					<span><?php echo $restrictions_active ? 'Selected categories and products require a paid prior order or a manual customer override.' : 'All visitors can currently see and buy the selected categories and products.'; ?></span>
				</div>
				<div class="ion-po-status-pill"><?php echo $restrictions_active ? 'RESTRICTIONS ON' : 'RESTRICTIONS OFF'; ?></div>
			</div>

			<div class="ion-po-grid">
				<div class="ion-po-card ion-po-main-card">
					<h2>Access Rules</h2>
					<p class="description">Choose exactly what should stay hidden until a customer has a paid order. Administrators and Shop Managers always retain access.</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ion_po_save_settings">
						<?php wp_nonce_field( 'ion_po_save_settings' ); ?>

						<div class="ion-po-field ion-po-toggle-field">
							<div>
								<label for="ion-po-enabled"><strong>Require prior-order access</strong></label>
								<p class="description">Turn this off to open every selected category and product to all customers site-wide.</p>
							</div>
							<label class="ion-po-switch">
								<input type="hidden" name="enabled" value="0">
								<input id="ion-po-enabled" type="checkbox" name="enabled" value="1" <?php checked( $configured_enabled ); ?>>
								<span class="ion-po-slider"></span>
							</label>
						</div>

						<div class="ion-po-field ion-po-weekend-field">
							<div class="ion-po-toggle-field">
								<div>
									<label for="ion-po-weekend-open"><strong>Recurring weekend open access</strong></label>
									<p class="description">Automatically open the selected categories and products every Friday at 8:00 PM through Monday at 3:00 AM Eastern Time.</p>
								</div>
								<label class="ion-po-switch">
									<input type="hidden" name="weekend_open" value="0">
									<input id="ion-po-weekend-open" type="checkbox" name="weekend_open" value="1" <?php checked( $weekend_open ); ?>>
									<span class="ion-po-slider"></span>
								</label>
							</div>
							<div class="ion-po-schedule-status is-<?php echo esc_attr( $weekend_status[0] ); ?>">
								<strong><?php echo 'active' === $weekend_status[0] ? 'OPEN NOW' : ( 'scheduled' === $weekend_status[0] ? 'RECURRING' : 'DISABLED' ); ?></strong>
								<span><?php echo esc_html( $weekend_status[1] ); ?></span>
							</div>
						</div>

						<div class="ion-po-field ion-po-schedule-field">
							<div class="ion-po-toggle-field ion-po-schedule-toggle">
								<div>
									<label for="ion-po-scheduled-open"><strong>Scheduled open access</strong></label>
									<p class="description">Temporarily open the selected categories and products for a one-time date range, then automatically restore prior-order restrictions.</p>
								</div>
								<label class="ion-po-switch">
									<input type="hidden" name="scheduled_open" value="0">
									<input id="ion-po-scheduled-open" type="checkbox" name="scheduled_open" value="1" <?php checked( $scheduled_open ); ?>>
									<span class="ion-po-slider"></span>
								</label>
							</div>

							<div class="ion-po-schedule-controls" id="ion-po-schedule-controls">
								<div class="ion-po-schedule-grid">
									<label>
										<span>Starts</span>
										<input type="datetime-local" name="scheduled_start" id="ion-po-scheduled-start" value="<?php echo esc_attr( $scheduled_start ); ?>">
									</label>
									<label>
										<span>Ends</span>
										<input type="datetime-local" name="scheduled_end" id="ion-po-scheduled-end" value="<?php echo esc_attr( $scheduled_end ); ?>">
									</label>
								</div>
								<p class="description ion-po-timezone-note">Times use Eastern Time (America/New_York) and automatically follow EST/EDT.</p>
								<div class="ion-po-schedule-status is-<?php echo esc_attr( $schedule_status[0] ); ?>">
									<strong><?php echo 'active' === $schedule_status[0] ? 'OPEN NOW' : ( 'scheduled' === $schedule_status[0] ? 'UPCOMING' : ( 'expired' === $schedule_status[0] ? 'ENDED' : ( 'invalid' === $schedule_status[0] ? 'CHECK SCHEDULE' : 'DISABLED' ) ) ); ?></strong>
									<span><?php echo esc_html( $schedule_status[1] ); ?></span>
								</div>
							</div>
						</div>

						<div class="ion-po-field">
							<label for="ion-po-categories"><strong>Restricted categories</strong></label>
							<select id="ion-po-categories" class="wc-enhanced-select" multiple="multiple" name="restricted_category_ids[]" data-placeholder="Select categories…" style="width:100%;">
								<?php foreach ( $category_choices as $term_id => $label ) : ?>
									<option value="<?php echo esc_attr( $term_id ); ?>" <?php selected( in_array( (int) $term_id, $selected_cats, true ) ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Every child category beneath a selected category is protected automatically.</p>
						</div>

						<div class="ion-po-field">
							<label for="ion-po-products"><strong>Restricted individual products</strong></label>
							<select id="ion-po-products" class="wc-product-search" multiple="multiple" name="restricted_product_ids[]" data-placeholder="Search for products…" data-action="woocommerce_json_search_products_and_variations" style="width:100%;">
								<?php foreach ( $selected_products as $product_id ) : ?>
									<?php $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false; ?>
									<?php if ( $product ) : ?>
										<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
							<p class="description">Use this for isolated products outside the restricted categories. Selecting a variation protects its entire parent product.</p>
						</div>

						<?php submit_button( 'Save Access Rules', 'primary', 'submit', false ); ?>
					</form>
				</div>

				<div class="ion-po-card">
					<h2>Customer Overrides</h2>
					<p class="description">Grant immediate access by customer email, even when the customer has never placed a paid order.</p>

					<form class="ion-po-email-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ion_po_add_customer">
						<?php wp_nonce_field( 'ion_po_add_customer' ); ?>
						<input type="email" name="customer_email" placeholder="customer@example.com" required>
						<button type="submit" class="button button-primary">Locate &amp; Grant Access</button>
					</form>

					<div class="ion-po-override-list">
						<?php if ( empty( $allowed_customers ) ) : ?>
							<div class="ion-po-empty">No customer overrides have been added.</div>
						<?php else : ?>
							<table class="widefat striped">
								<thead>
									<tr>
										<th>Email</th>
										<th>Customer</th>
										<th>Status</th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $allowed_customers as $entry ) : ?>
										<?php
										$user = ! empty( $entry['user_id'] ) ? get_userdata( $entry['user_id'] ) : false;
										if ( ! $user ) {
											$resolved_id = self::locate_customer_user_id( $entry['email'] );
											$user = $resolved_id ? get_userdata( $resolved_id ) : false;
										}
										?>
										<tr>
											<td><strong><?php echo esc_html( $entry['email'] ); ?></strong></td>
											<td>
												<?php if ( $user ) : ?>
													<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a>
												<?php else : ?>
													<span class="ion-po-muted">No linked account</span>
												<?php endif; ?>
											</td>
											<td><span class="ion-po-badge <?php echo $user ? 'is-linked' : 'is-email'; ?>"><?php echo $user ? 'ACCOUNT LINKED' : 'EMAIL OVERRIDE'; ?></span></td>
											<td class="ion-po-remove-cell">
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
													<input type="hidden" name="action" value="ion_po_remove_customer">
													<input type="hidden" name="customer_email" value="<?php echo esc_attr( $entry['email'] ); ?>">
													<?php wp_nonce_field( 'ion_po_remove_customer_' . md5( strtolower( $entry['email'] ) ) ); ?>
													<button type="submit" class="button-link-delete" onclick="return confirm('Remove this customer access override?');">Remove</button>
												</form>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="ion-po-card ion-po-summary-card">
				<h2>Protection Coverage</h2>
				<div class="ion-po-summary-grid">
					<div><strong><?php echo esc_html( count( $selected_cats ) ); ?></strong><span>Protected category paths</span></div>
					<div><strong><?php echo esc_html( count( self::restricted_product_ids() ) ); ?></strong><span>Individual products</span></div>
					<div><strong><?php echo esc_html( count( $allowed_customers ) ); ?></strong><span>Customer overrides</span></div>
					<div><strong>9</strong><span>Enforcement layers</span></div>
				</div>
				<p class="description ion-po-coverage-note">Protection applies to shop/archive queries, direct URLs, add-to-cart validation, product visibility, menus, related products, shortcodes, WordPress REST, and the WooCommerce Store API.</p>
			</div>
		</div>

		<style>
			.ion-po-wrap{max-width:1220px}.ion-po-wrap h1{margin-bottom:18px}.ion-po-status{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px 22px;border-radius:10px;margin:0 0 18px;border:1px solid}.ion-po-status strong{display:block;font-size:15px;letter-spacing:.04em}.ion-po-status span{display:block;margin-top:4px}.ion-po-status.is-locked{background:#fff4f1;border-color:#e9a28f}.ion-po-status.is-open{background:#effaf3;border-color:#85c99d}.ion-po-status-pill{flex:0 0 auto;padding:8px 12px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.06em;background:#fff}.ion-po-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(360px,.75fr);gap:18px}.ion-po-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px;box-shadow:0 1px 2px rgba(0,0,0,.03)}.ion-po-card h2{margin:0 0 8px;font-size:18px}.ion-po-card>.description{margin-bottom:20px}.ion-po-field{padding:20px 0;border-top:1px solid #ececec}.ion-po-field:first-of-type{margin-top:18px}.ion-po-field>label{display:block;margin-bottom:8px}.ion-po-toggle-field{display:flex;align-items:center;justify-content:space-between;gap:24px}.ion-po-toggle-field p{margin:5px 0 0}.ion-po-switch{position:relative;display:inline-block;width:54px;height:30px;flex:0 0 auto}.ion-po-switch input[type=checkbox]{opacity:0;width:0;height:0}.ion-po-slider{position:absolute;cursor:pointer;inset:0;background:#a7aaad;border-radius:999px;transition:.2s}.ion-po-slider:before{content:"";position:absolute;height:22px;width:22px;left:4px;top:4px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.25)}.ion-po-switch input:checked+.ion-po-slider{background:#2271b1}.ion-po-switch input:checked+.ion-po-slider:before{transform:translateX(24px)}.ion-po-schedule-toggle{padding-bottom:14px}.ion-po-schedule-controls{padding:16px;border:1px solid #dcdcde;background:#f8f9fa;border-radius:8px;transition:.2s}.ion-po-schedule-controls.is-disabled{opacity:.55}.ion-po-schedule-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ion-po-schedule-grid label>span{display:block;font-weight:600;margin-bottom:6px}.ion-po-schedule-grid input{width:100%;min-height:38px}.ion-po-timezone-note{margin:10px 0 0!important}.ion-po-schedule-status{display:flex;align-items:center;gap:10px;margin-top:14px;padding:10px 12px;border-radius:7px;background:#fff;border:1px solid #dcdcde}.ion-po-schedule-status strong{font-size:10px;letter-spacing:.06em;padding:4px 7px;border-radius:999px;background:#f0f0f1;white-space:nowrap}.ion-po-schedule-status span{color:#50575e}.ion-po-schedule-status.is-active{border-color:#79b98d;background:#f1fbf4}.ion-po-schedule-status.is-active strong{background:#dff4e6;color:#176b39}.ion-po-schedule-status.is-scheduled{border-color:#72aee6;background:#f0f6fc}.ion-po-schedule-status.is-scheduled strong{background:#dceaf7;color:#135e96}.ion-po-schedule-status.is-expired strong,.ion-po-schedule-status.is-disabled strong{color:#50575e}.ion-po-schedule-status.is-invalid{border-color:#d63638;background:#fcf0f1}.ion-po-schedule-status.is-invalid strong{background:#f6d6d8;color:#8a2424}.ion-po-email-form{display:flex;gap:8px;margin:18px 0 20px}.ion-po-email-form input[type=email]{width:100%;min-height:36px}.ion-po-override-list{max-height:420px;overflow:auto}.ion-po-empty{padding:28px;text-align:center;background:#f6f7f7;border-radius:6px;color:#646970}.ion-po-badge{display:inline-block;padding:4px 7px;border-radius:999px;font-size:10px;font-weight:700;letter-spacing:.04em;white-space:nowrap}.ion-po-badge.is-linked{background:#e7f7ed;color:#176b39}.ion-po-badge.is-email{background:#fff3cd;color:#7a5900}.ion-po-muted{color:#777}.ion-po-remove-cell{text-align:right}.ion-po-summary-card{margin-top:18px}.ion-po-summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:16px}.ion-po-summary-grid div{padding:16px;background:#f6f7f7;border-radius:8px}.ion-po-summary-grid strong{display:block;font-size:25px;line-height:1}.ion-po-summary-grid span{display:block;margin-top:7px;color:#50575e}.ion-po-coverage-note{margin:16px 0 0!important}.ion-po-wrap .select2-container{max-width:100%}@media(max-width:960px){.ion-po-grid{grid-template-columns:1fr}.ion-po-summary-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.ion-po-status,.ion-po-toggle-field,.ion-po-email-form{align-items:flex-start;flex-direction:column}.ion-po-schedule-grid{grid-template-columns:1fr}.ion-po-schedule-status{align-items:flex-start;flex-direction:column}.ion-po-summary-grid{grid-template-columns:1fr}.ion-po-email-form .button{width:100%}}
		</style>
		<script>
			(function(){
				var toggle=document.getElementById('ion-po-scheduled-open');
				var controls=document.getElementById('ion-po-schedule-controls');
				var start=document.getElementById('ion-po-scheduled-start');
				var end=document.getElementById('ion-po-scheduled-end');
				if(!toggle||!controls||!start||!end){return;}
				function sync(){
					controls.classList.toggle('is-disabled',!toggle.checked);
					start.required=toggle.checked;
					end.required=toggle.checked;
				}
				toggle.addEventListener('change',sync);
				sync();
			})();
		</script>
		<?php
	}
}

add_action( 'plugins_loaded', array( 'ION_Prior_Order_Category_Access', 'init' ), 20 );
