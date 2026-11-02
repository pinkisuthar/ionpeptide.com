<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BRW_PR_Router {

	const COOKIE_KEY          = 'brw_pr_gateway_set';
	const COOKIE_DATE_KEY     = 'brw_pr_gateway_set_date';
	const USERMETA_KEY        = 'brw_pr_gateway_set';
	const USERMETA_DATE_KEY   = 'brw_pr_gateway_set_date';
	const COUNTER_OPTION    = 'brw_pr_counter_v8';
	const SESSION_KEY         = 'brw_pr_chosen_gateway_set_v7';
	const SESSION_DATE_KEY    = 'brw_pr_chosen_gateway_set_date_v1';
	const SELECTED_METHOD_KEY = 'brw_pr_selected_payment_method_v1';
	const SELECTED_PAYMENT_TYPE_KEY = 'brw_pr_selected_payment_type_v1';
	const COOKIE_DAYS       = 60;
	const JS_HANDLE         = 'brw-payment-rotator-sticky-blocks';
	const DAILY_TOTALS_KEY  = 'brw_pr_daily_totals_v1';
	const DAILY_STATS_KEY   = 'brw_pr_daily_stats_v1';

	private static $last_payment_type_selector_ids = array();

	public static function init() {
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways_classic' ), 9999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_scripts' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'ensure_frontend_assets_before_footer' ), 5 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_posted_gateway' ) );
		add_action( 'wp_ajax_brw_pr_select_gateway', array( __CLASS__, 'ajax_store_selected_gateway' ) );
		add_action( 'wp_ajax_nopriv_brw_pr_select_gateway', array( __CLASS__, 'ajax_store_selected_gateway' ) );
		add_action( 'wp_ajax_brw_pr_select_payment_type', array( __CLASS__, 'ajax_store_selected_payment_type' ) );
		add_action( 'wp_ajax_nopriv_brw_pr_select_payment_type', array( __CLASS__, 'ajax_store_selected_payment_type' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_gateway_adjustments' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'add_order_meta' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'track_order_total_for_daily_caps' ), 20, 3 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'track_gateway_status_stats' ), 20, 4 );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_payment_type_selector' ), 8 );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_checkout_hint' ), 20 );
	}

	public static function filter_gateways_classic( $gateways ) {
		if ( is_admin() ) {
			return $gateways;
		}

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return $gateways;
		}

		if ( ! is_array( $gateways ) || empty( $gateways ) ) {
			return $gateways;
		}

		$chosen_rotating = self::get_or_assign_rotating_gateways( $gateways );
		$sorted          = self::filter_and_sort_gateways_for_display( $gateways, $chosen_rotating );

		self::$last_payment_type_selector_ids = array_values( array_keys( $sorted ) );
		$sorted = self::maybe_filter_gateways_by_payment_type( $sorted );

		return self::decorate_available_gateways( $sorted );
	}

	public static function enqueue_checkout_scripts() {
		if ( is_admin() ) {
			return;
		}

		if ( ! self::should_load_frontend_assets() ) {
			return;
		}

		$is_checkout_page = function_exists( 'is_checkout' ) && is_checkout();

		wp_register_script(
			self::JS_HANDLE,
			BRW_PR_URL . 'assets/brw-payment-rotator-sticky-blocks.js',
			array( 'jquery' ),
			BRW_PR_VERSION,
			true
		);

		$payload = array(
			'all_ids'       => array(),
			'fixed_ids'     => array(),
			'rotator_ids'   => array(),
			'secondary_ids' => array(),
			'allowed_ids'   => array(),
			'display_order' => array(),
			'adjustments'   => self::get_gateway_adjustment_payload(),
			'payment_type_routing' => self::get_payment_type_routing_payload(),
			'payment_type_base_ids' => array(),
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'checkout_url'  => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ),
			'is_checkout'   => $is_checkout_page,
			'intercept_checkout_links' => ! $is_checkout_page && self::is_payment_type_routing_enabled(),
			'nonce'         => wp_create_nonce( 'brw_pr_select_gateway' ),
			'payment_type_nonce' => wp_create_nonce( 'brw_pr_select_payment_type' ),
		);

		self::clear_stale_selected_payment_method();

		if ( $is_checkout_page && function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
			$available = WC()->payment_gateways()->get_available_payment_gateways();

			if ( is_array( $available ) && ! empty( $available ) && self::is_blocks_checkout_context() ) {
				$chosen_rotating = self::get_or_assign_rotating_gateways( $available );
				$fixed_ids       = self::get_fixed_gateway_ids( $available );
				$secondary_ids   = self::get_secondary_gateway_ids( $available );
				$all_ids         = array_values( array_keys( $available ) );
				$display_ids_before_type = self::get_gateway_display_order_ids( $available, $chosen_rotating );
				$display_ids     = self::filter_gateway_ids_by_payment_type( $display_ids_before_type );

				$payload = array(
					'all_ids'       => $all_ids,
					'fixed_ids'     => $fixed_ids,
					'rotator_ids'   => $chosen_rotating,
					'secondary_ids' => $secondary_ids,
					'allowed_ids'   => $display_ids,
					'display_order' => $display_ids,
					'adjustments'   => self::get_gateway_adjustment_payload(),
					'payment_type_routing' => self::get_payment_type_routing_payload( $display_ids_before_type ),
					'payment_type_base_ids' => $display_ids_before_type,
					'ajax_url'      => admin_url( 'admin-ajax.php' ),
					'checkout_url'  => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ),
					'is_checkout'   => $is_checkout_page,
					'intercept_checkout_links' => ! $is_checkout_page && self::is_payment_type_routing_enabled(),
					'nonce'         => wp_create_nonce( 'brw_pr_select_gateway' ),
					'payment_type_nonce' => wp_create_nonce( 'brw_pr_select_payment_type' ),
				);
			}
		}

		wp_localize_script( self::JS_HANDLE, 'BRW_PAYMENT_ROTATOR_STICKY', $payload );
		wp_enqueue_script( self::JS_HANDLE );
	}


	public static function ensure_frontend_assets_before_footer() {
		if ( is_admin() ) {
			return;
		}

		if ( wp_script_is( self::JS_HANDLE, 'enqueued' ) || wp_script_is( self::JS_HANDLE, 'done' ) ) {
			return;
		}

		if ( ! self::should_load_frontend_assets() ) {
			return;
		}

		self::enqueue_checkout_scripts();
	}

	private static function should_load_frontend_assets() {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) {
			return false;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		if ( self::is_probable_checkout_request() ) {
			return true;
		}

		/* Load globally when payment-type routing is enabled so cart, mini-cart,
		 * and custom checkout buttons can be gated before reaching checkout.
		 */
		return self::is_payment_type_routing_enabled();
	}

	private static function is_probable_checkout_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$path = '/' . trim( strtolower( $path ), '/' ) . '/';

		return ( false !== strpos( $path, '/checkout/' ) );
	}

	private static function is_blocks_checkout_context() {
		$post = get_queried_object();

		if ( $post && isset( $post->post_content ) && is_string( $post->post_content ) ) {
			if ( has_shortcode( $post->post_content, 'woocommerce_checkout' ) ) {
				return false;
			}

			if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $post ) ) {
				return true;
			}
		}

		if ( wp_script_is( 'wc-blocks-registry', 'enqueued' ) || wp_script_is( 'wc-blocks-checkout', 'enqueued' ) ) {
			return true;
		}

		return false;
	}

	public static function capture_posted_gateway( $posted_data ) {
		$gateway_id                 = '';
		$payment_type_field_present = false;
		$posted_payment_type        = '';

		if ( is_string( $posted_data ) ) {
			parse_str( $posted_data, $parsed );
			if ( ! empty( $parsed['payment_method'] ) ) {
				$gateway_id = sanitize_text_field( wp_unslash( $parsed['payment_method'] ) );
			}

			if ( array_key_exists( 'brw_pr_payment_type', $parsed ) ) {
				$payment_type_field_present = true;
				$posted_payment_type        = self::sanitize_payment_type( sanitize_text_field( wp_unslash( $parsed['brw_pr_payment_type'] ) ) );
			}
		}

		if ( '' !== $gateway_id ) {
			self::store_selected_payment_method( $gateway_id );
		}

		if ( $payment_type_field_present ) {
			if ( '' !== $posted_payment_type ) {
				self::store_selected_payment_type( $posted_payment_type );
			} else {
				self::clear_selected_payment_type();
			}
			return;
		}

		foreach ( self::get_payment_type_candidates() as $payment_type ) {
			if ( '' !== $payment_type ) {
				self::store_selected_payment_type( $payment_type );
				break;
			}
		}
	}

	public static function ajax_store_selected_gateway() {
		check_ajax_referer( 'brw_pr_select_gateway', 'nonce' );

		$gateway_id = isset( $_POST['gateway_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_id'] ) ) : '';
		if ( '' !== $gateway_id ) {
			self::store_selected_payment_method( $gateway_id );
		}

		wp_send_json_success( array( 'gateway_id' => $gateway_id ) );
	}

	public static function ajax_store_selected_payment_type() {
		check_ajax_referer( 'brw_pr_select_payment_type', 'nonce' );

		$payment_type = isset( $_POST['payment_type'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_type'] ) ) : '';
		$payment_type = self::sanitize_payment_type( $payment_type );

		if ( '' === $payment_type ) {
			self::clear_selected_payment_type();
		} else {
			self::store_selected_payment_type( $payment_type );
		}

		wp_send_json_success(
			array(
				'payment_type' => $payment_type,
				'payload'      => self::get_payment_type_routing_payload(),
			)
		);
	}

	public static function apply_gateway_adjustments( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		if ( $cart->is_empty() ) {
			return;
		}

		$gateway_id = self::get_selected_payment_method();
		if ( '' === $gateway_id ) {
			return;
		}

		$rules = self::get_gateway_rules();
		if ( empty( $rules[ $gateway_id ] ) ) {
			return;
		}

		$row      = $rules[ $gateway_id ];
		$discount = isset( $row['discount'] ) ? (float) $row['discount'] : 0;
		$fee      = isset( $row['fee'] ) ? (float) $row['fee'] : 0;

		if ( $discount <= 0 && $fee <= 0 ) {
			return;
		}

		$base = max( 0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total() );
		if ( $base <= 0 ) {
			return;
		}

		$settings       = BRW_PR_Admin::get_settings();
		$discount_label = ! empty( $settings['fee_label'] ) ? $settings['fee_label'] : 'Payment method discount';

		if ( $discount > 0 ) {
			$discount_amount = round( $base * ( $discount / 100 ), wc_get_price_decimals() );
			if ( $discount_amount > 0 ) {
				$cart->add_fee( $discount_label . ' (' . self::format_percent( $discount ) . ')', -1 * $discount_amount, false );
			}
		}

		if ( $fee > 0 ) {
			$fee_amount = round( $base * ( $fee / 100 ), wc_get_price_decimals() );
			if ( $fee_amount > 0 ) {
				$cart->add_fee( 'Payment method fee (' . self::format_percent( $fee ) . ')', $fee_amount, false );
			}
		}
	}

	public static function render_payment_type_selector() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! self::is_payment_type_routing_enabled() ) {
			return;
		}

		$gateway_ids = ! empty( self::$last_payment_type_selector_ids ) ? self::$last_payment_type_selector_ids : array();
		if ( empty( $gateway_ids ) ) {
			$available = self::get_checkout_available_gateways();
			$gateway_ids = is_array( $available ) ? array_values( array_keys( $available ) ) : array();
		}

		$payload = self::get_payment_type_routing_payload( $gateway_ids );
		if ( empty( $payload['enabled'] ) || empty( $payload['types'] ) || ! is_array( $payload['types'] ) ) {
			return;
		}

		$selected = isset( $payload['selected'] ) ? (string) $payload['selected'] : '';
		$label    = ! empty( $payload['label'] ) ? (string) $payload['label'] : 'Select your payment type';

		echo '<div class="brw-pr-payment-type-selector" data-brw-pr-payment-types="1" style="margin:0 0 14px;padding:12px 14px;border:1px solid rgba(21,42,77,.12);border-radius:12px;background:#f8fafc;font-size:14px;line-height:1.45;">';
		echo '<div style="font-weight:700;margin-bottom:8px;">' . esc_html( $label ) . '</div>';
		echo '<div class="brw-pr-payment-type-options" style="display:flex;flex-wrap:wrap;gap:8px;">';

		foreach ( $payload['types'] as $type_key => $type_label ) {
			$is_selected = ( $selected === (string) $type_key );
			echo '<button type="button" class="brw-pr-payment-type-option" data-payment-type="' . esc_attr( (string) $type_key ) . '" aria-pressed="' . ( $is_selected ? 'true' : 'false' ) . '" style="border:1px solid ' . ( $is_selected ? '#155e75' : 'rgba(21,42,77,.18)' ) . ';background:' . ( $is_selected ? '#155e75' : '#fff' ) . ';color:' . ( $is_selected ? '#fff' : '#0f172a' ) . ';border-radius:999px;padding:7px 12px;font-weight:700;cursor:pointer;">' . esc_html( (string) $type_label ) . '</button>';
		}

		if ( '' !== $selected ) {
			echo '<button type="button" class="brw-pr-payment-type-clear" style="border:1px solid rgba(21,42,77,.18);background:#fff;color:#334155;border-radius:999px;padding:7px 12px;font-weight:700;cursor:pointer;">Show all</button>';
		}

		echo '</div>';
		echo '<input type="hidden" name="brw_pr_payment_type" value="' . esc_attr( $selected ) . '" />';
		echo '</div>';
	}

	public static function render_checkout_hint() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$available = self::get_checkout_available_gateways();
		if ( empty( $available ) ) {
			return;
		}

		$rules = self::get_gateway_rules();
		$parts = array();

		foreach ( $available as $gateway_id => $gateway ) {
			if ( empty( $rules[ $gateway_id ]['discount'] ) ) {
				continue;
			}

			$discount = self::format_percent( $rules[ $gateway_id ]['discount'] );
			$title    = is_object( $gateway ) && ! empty( $gateway->title )
				? trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $gateway->title ) ) )
				: $gateway_id;

			$title_has_discount = ( false !== stripos( $title, $discount ) ) || ( false !== stripos( $title, $discount . ' off' ) );

			$parts[] = esc_html( $title_has_discount ? $title : $title . ' — ' . $discount . ' off' );
		}

		if ( empty( $parts ) ) {
			return;
		}

		echo '<div class="brw-pr-checkout-hint" style="margin:0 0 14px;padding:12px 14px;border:1px solid rgba(21,42,77,.12);border-radius:12px;background:#f7fbfd;font-size:14px;line-height:1.5;">';
		echo '<strong>Payment discounts available:</strong> ';
		echo implode( ' • ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	public static function add_order_meta( $order, $data ) {
		$gateway_id = self::get_selected_payment_method();
		if ( '' === $gateway_id ) {
			$gateway_id = $order->get_payment_method();
		}

		if ( '' === $gateway_id ) {
			return;
		}

		$rules = self::get_gateway_rules();
		if ( empty( $rules[ $gateway_id ] ) ) {
			return;
		}

		$order->update_meta_data( '_brw_pr_gateway_id', $gateway_id );
		$order->update_meta_data( '_brw_pr_assigned_rotating_set', implode( '|', self::get_or_assign_rotating_gateways( self::get_checkout_available_gateways() ) ) );

		if ( ! empty( $rules[ $gateway_id ]['discount'] ) ) {
			$order->update_meta_data( '_brw_pr_gateway_discount_percent', self::format_percent( $rules[ $gateway_id ]['discount'] ) );
		}

		if ( ! empty( $rules[ $gateway_id ]['fee'] ) ) {
			$order->update_meta_data( '_brw_pr_gateway_fee_percent', self::format_percent( $rules[ $gateway_id ]['fee'] ) );
		}

		if ( ! empty( $rules[ $gateway_id ]['daily_cap'] ) ) {
			$order->update_meta_data( '_brw_pr_gateway_daily_cap', (float) $rules[ $gateway_id ]['daily_cap'] );
		}
	}

	public static function track_order_total_for_daily_caps( $order_id, $posted_data, $order ) {
		if ( ! $order_id || ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$already_counted = $order->get_meta( '_brw_pr_daily_cap_counted', true );
		if ( $already_counted ) {
			return;
		}

		$gateway_id = (string) $order->get_payment_method();
		if ( '' === $gateway_id ) {
			return;
		}

		$rules = self::get_gateway_rules();
		if ( empty( $rules[ $gateway_id ] ) ) {
			return;
		}

		$cap = isset( $rules[ $gateway_id ]['daily_cap'] ) ? (float) $rules[ $gateway_id ]['daily_cap'] : 0;
		if ( $cap <= 0 ) {
			return;
		}

		$total = (float) $order->get_total();
		if ( $total <= 0 ) {
			return;
		}

		$date_key = self::get_today_key();
		$totals   = get_option( self::DAILY_TOTALS_KEY, array() );

		if ( ! is_array( $totals ) ) {
			$totals = array();
		}

		if ( empty( $totals[ $date_key ] ) || ! is_array( $totals[ $date_key ] ) ) {
			$totals[ $date_key ] = array();
		}

		if ( empty( $totals[ $date_key ][ $gateway_id ] ) ) {
			$totals[ $date_key ][ $gateway_id ] = 0;
		}

		$totals[ $date_key ][ $gateway_id ] = (float) $totals[ $date_key ][ $gateway_id ] + $total;

		update_option( self::DAILY_TOTALS_KEY, $totals, false );

		$order->update_meta_data( '_brw_pr_daily_cap_counted', 1 );
		$order->update_meta_data( '_brw_pr_daily_cap_date', $date_key );
		$order->save_meta_data();
	}

	public static function track_gateway_status_stats( $order_id, $old_status, $new_status, $order ) {
		if ( ! $order_id || ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$gateway_id = (string) $order->get_payment_method();
		if ( '' === $gateway_id ) {
			return;
		}

		$rules = self::get_gateway_rules();
		if ( empty( $rules[ $gateway_id ] ) ) {
			return;
		}

		$date_key = self::get_today_key();

		if ( in_array( $new_status, self::get_paid_tracking_statuses(), true ) ) {
			self::increment_gateway_stat( $order, $gateway_id, $date_key, 'approved_count', 1 );
			self::increment_gateway_stat( $order, $gateway_id, $date_key, 'approved_total', (float) $order->get_total() );
		}

		if ( 'failed' === $new_status ) {
			if ( self::should_count_order_as_processor_failure( $order ) ) {
				self::increment_gateway_stat( $order, $gateway_id, $date_key, 'failed_count', 1 );
			} else {
				self::increment_gateway_stat( $order, $gateway_id, $date_key, 'ignored_failed_count', 1 );
			}
		}
	}

	private static function increment_gateway_stat( $order, $gateway_id, $date_key, $field, $amount ) {
		$meta_key = '_brw_pr_stat_' . $field . '_' . $date_key;

		if ( $order->get_meta( $meta_key, true ) ) {
			return;
		}

		$stats = get_option( self::DAILY_STATS_KEY, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		if ( empty( $stats[ $date_key ] ) || ! is_array( $stats[ $date_key ] ) ) {
			$stats[ $date_key ] = array();
		}

		if ( empty( $stats[ $date_key ][ $gateway_id ] ) || ! is_array( $stats[ $date_key ][ $gateway_id ] ) ) {
			$stats[ $date_key ][ $gateway_id ] = array(
				'approved_total'       => 0,
				'approved_count'       => 0,
				'failed_count'         => 0,
				'ignored_failed_count' => 0,
			);
		}

		if ( ! isset( $stats[ $date_key ][ $gateway_id ][ $field ] ) ) {
			$stats[ $date_key ][ $gateway_id ][ $field ] = 0;
		}

		$stats[ $date_key ][ $gateway_id ][ $field ] += $amount;

		update_option( self::DAILY_STATS_KEY, $stats, false );

		$order->update_meta_data( $meta_key, 1 );
		$order->save_meta_data();
	}


	public static function reset_today_stats() {
		$date_key = self::get_today_key();

		foreach ( array( self::DAILY_TOTALS_KEY, self::DAILY_STATS_KEY ) as $option_key ) {
			$data = get_option( $option_key, array() );

			if ( ! is_array( $data ) ) {
				$data = array();
			}

			if ( isset( $data[ $date_key ] ) ) {
				unset( $data[ $date_key ] );
			}

			update_option( $option_key, $data, false );
		}

		return $date_key;
	}


	public static function reset_today_cash_values() {
		$date_key = self::get_today_key();

		$totals = get_option( self::DAILY_TOTALS_KEY, array() );
		if ( ! is_array( $totals ) ) {
			$totals = array();
		}

		if ( isset( $totals[ $date_key ] ) ) {
			unset( $totals[ $date_key ] );
		}
		update_option( self::DAILY_TOTALS_KEY, $totals, false );

		$stats = get_option( self::DAILY_STATS_KEY, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		if ( ! empty( $stats[ $date_key ] ) && is_array( $stats[ $date_key ] ) ) {
			foreach ( $stats[ $date_key ] as $gateway_id => $gateway_stats ) {
				if ( is_array( $gateway_stats ) ) {
					$stats[ $date_key ][ $gateway_id ]['approved_total'] = 0;
				}
			}
		}

		update_option( self::DAILY_STATS_KEY, $stats, false );

		return $date_key;
	}

	private static function has_configured_display_gateways( $available_gateways, $rules = null ) {
		$rules = is_array( $rules ) ? $rules : self::get_gateway_rules();

		if ( empty( $available_gateways ) || empty( $rules ) || ! is_array( $available_gateways ) || ! is_array( $rules ) ) {
			return false;
		}

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			if ( empty( $rules[ $gateway_id ] ) || ! is_array( $rules[ $gateway_id ] ) ) {
				continue;
			}

			$role = isset( $rules[ $gateway_id ]['role'] ) ? sanitize_key( (string) $rules[ $gateway_id ]['role'] ) : 'secondary';
			if ( 'hidden' !== $role ) {
				return true;
			}
		}

		return false;
	}

	private static function get_paid_tracking_statuses() {
		if ( class_exists( 'BRW_PR_Admin' ) ) {
			$settings = BRW_PR_Admin::get_settings();

			if ( ! empty( $settings['paid_statuses'] ) && is_array( $settings['paid_statuses'] ) ) {
				return array_values( array_unique( array_map( 'sanitize_key', $settings['paid_statuses'] ) ) );
			}
		}

		return array( 'processing', 'completed' );
	}

	public static function get_gateway_today_processing_total( $gateway_id ) {
		$stats = self::get_today_stats_for_gateway( $gateway_id );
		return isset( $stats['approved_total'] ) ? (float) $stats['approved_total'] : 0.0;
	}

	public static function get_gateway_today_processing_count( $gateway_id ) {
		$stats = self::get_today_stats_for_gateway( $gateway_id );
		return isset( $stats['approved_count'] ) ? (int) $stats['approved_count'] : 0;
	}

	public static function get_gateway_today_failed_count( $gateway_id ) {
		$stats = self::get_today_stats_for_gateway( $gateway_id );
		return isset( $stats['failed_count'] ) ? (int) $stats['failed_count'] : 0;
	}

	public static function get_gateway_today_ignored_failed_count( $gateway_id ) {
		$stats = self::get_today_stats_for_gateway( $gateway_id );
		return isset( $stats['ignored_failed_count'] ) ? (int) $stats['ignored_failed_count'] : 0;
	}

	public static function get_gateway_today_fail_percent( $gateway_id ) {
		$approved = self::get_gateway_today_processing_count( $gateway_id );
		$failed   = self::get_gateway_today_failed_count( $gateway_id );
		$total    = $approved + $failed;

		if ( $total <= 0 ) {
			return 0.0;
		}

		return round( ( $failed / $total ) * 100, 2 );
	}

	public static function is_gateway_auto_paused_public( $gateway_id ) {
		return self::is_gateway_auto_paused( $gateway_id );
	}

	private static function get_today_stats_for_gateway( $gateway_id ) {
		$date_key = self::get_today_key();
		$stats    = get_option( self::DAILY_STATS_KEY, array() );

		if ( ! is_array( $stats ) ) {
			return array();
		}

		if ( empty( $stats[ $date_key ] ) || ! is_array( $stats[ $date_key ] ) ) {
			return array();
		}

		if ( empty( $stats[ $date_key ][ $gateway_id ] ) || ! is_array( $stats[ $date_key ][ $gateway_id ] ) ) {
			return array();
		}

		return $stats[ $date_key ][ $gateway_id ];
	}

	private static function get_checkout_available_gateways() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
			return array();
		}

		$available = WC()->payment_gateways()->get_available_payment_gateways();

		return is_array( $available ) ? $available : array();
	}

	private static function get_gateway_rules() {
		$settings = BRW_PR_Admin::get_settings();
		return ! empty( $settings['gateways'] ) && is_array( $settings['gateways'] )
			? $settings['gateways']
			: array();
	}

	private static function get_router_settings() {
		return BRW_PR_Admin::get_settings();
	}


	private static function get_payment_type_options() {
		if ( class_exists( 'BRW_PR_Admin' ) && method_exists( 'BRW_PR_Admin', 'get_payment_type_options' ) ) {
			return BRW_PR_Admin::get_payment_type_options();
		}

		return array(
			'visa'       => 'Visa',
			'mastercard' => 'MasterCard',
			'amex'       => 'American Express',
			'discover'   => 'Discover',
			'apple_pay'  => 'Apple Pay',
			'google_pay' => 'Google Pay',
		);
	}

	private static function sanitize_payment_type( $type ) {
		$type = sanitize_key( (string) $type );
		$options = self::get_payment_type_options();

		return isset( $options[ $type ] ) ? $type : '';
	}

	private static function is_payment_type_routing_enabled() {
		$settings = self::get_router_settings();
		if ( empty( $settings['payment_type_routing_enabled'] ) ) {
			return false;
		}

		foreach ( self::get_gateway_rules() as $row ) {
			if ( ! empty( $row['payment_types'] ) || ! empty( $row['payment_type_always'] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function get_payment_type_routing_payload( $gateway_ids = array() ) {
		$options = self::get_payment_type_options();
		$enabled = self::is_payment_type_routing_enabled();
		$gateway_ids = array_values( array_filter( array_map( 'strval', (array) $gateway_ids ) ) );

		/*
		 * Payment-type routing must present the customer's real choices before the normal
		 * visible rotator set is narrowed. If we only read the currently assigned display
		 * IDs here, a customer could miss MasterCard/Amex/etc. simply because that gateway
		 * was not in the pre-selection rotating slot. Keep the modal based on all configured
		 * payment-type mappings, then let the router do a fresh weighted pick inside the
		 * selected type.
		 */
		$available_types = $enabled ? self::get_available_payment_types_for_gateway_ids( array() ) : array();
		$selected        = $enabled ? self::get_selected_payment_type() : '';

		if ( '' !== $selected && ! isset( $available_types[ $selected ] ) ) {
			self::clear_selected_payment_type();
			$selected = '';
		}

		$settings = self::get_router_settings();

		return array(
			'enabled'       => $enabled,
			'label'         => ! empty( $settings['payment_type_routing_label'] ) ? (string) $settings['payment_type_routing_label'] : 'Select your payment type',
			'selected'      => $selected,
			'types'         => $available_types,
			'all_types'     => $options,
			'gateway_types' => self::get_gateway_payment_type_map(),
			'always_ids'    => self::get_payment_type_always_gateway_ids(),
		);
	}

	private static function get_gateway_payment_type_map() {
		$map = array();

		foreach ( self::get_gateway_rules() as $gateway_id => $row ) {
			$types = isset( $row['payment_types'] ) && is_array( $row['payment_types'] ) ? $row['payment_types'] : array();
			$clean = array();

			foreach ( $types as $type ) {
				$type = self::sanitize_payment_type( $type );
				if ( '' !== $type ) {
					$clean[] = $type;
				}
			}

			$map[ (string) $gateway_id ] = array_values( array_unique( $clean ) );
		}

		return $map;
	}

	private static function get_payment_type_always_gateway_ids() {
		$ids = array();

		foreach ( self::get_gateway_rules() as $gateway_id => $row ) {
			if ( ! empty( $row['payment_type_always'] ) ) {
				$ids[] = (string) $gateway_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private static function get_available_payment_types_for_gateway_ids( $gateway_ids = array() ) {
		$options = self::get_payment_type_options();
		$rules   = self::get_gateway_rules();
		$allowed = array();
		$limit_to_ids = ! empty( $gateway_ids );
		$id_lookup = array();

		foreach ( (array) $gateway_ids as $gateway_id ) {
			$id_lookup[ (string) $gateway_id ] = true;
		}

		foreach ( $rules as $gateway_id => $row ) {
			if ( $limit_to_ids && empty( $id_lookup[ (string) $gateway_id ] ) ) {
				continue;
			}

			$types = isset( $row['payment_types'] ) && is_array( $row['payment_types'] ) ? $row['payment_types'] : array();
			foreach ( $types as $type ) {
				$type = self::sanitize_payment_type( $type );
				if ( '' !== $type && isset( $options[ $type ] ) ) {
					$allowed[ $type ] = $options[ $type ];
				}
			}
		}

		return $allowed;
	}

	private static function gateway_matches_payment_type( $gateway_id, $payment_type ) {
		$payment_type = self::sanitize_payment_type( $payment_type );
		if ( '' === $payment_type ) {
			return true;
		}

		$gateway_id = (string) $gateway_id;
		if ( in_array( $gateway_id, self::get_payment_type_always_gateway_ids(), true ) ) {
			return true;
		}

		$map = self::get_gateway_payment_type_map();
		return ! empty( $map[ $gateway_id ] ) && in_array( $payment_type, $map[ $gateway_id ], true );
	}

	private static function maybe_filter_gateways_by_payment_type( $gateways ) {
		if ( ! self::is_payment_type_routing_enabled() || ! is_array( $gateways ) || empty( $gateways ) ) {
			return $gateways;
		}

		$payment_type = self::get_selected_payment_type();
		if ( '' === $payment_type ) {
			return $gateways;
		}

		$filtered = array();
		foreach ( $gateways as $gateway_id => $gateway ) {
			if ( self::gateway_matches_payment_type( $gateway_id, $payment_type ) ) {
				$filtered[ $gateway_id ] = $gateway;
			}
		}

		if ( empty( $filtered ) ) {
			self::clear_selected_payment_type();
			return $gateways;
		}

		return $filtered;
	}

	private static function filter_gateway_ids_by_payment_type( $gateway_ids ) {
		$gateway_ids = array_values( array_filter( array_map( 'strval', (array) $gateway_ids ) ) );
		if ( ! self::is_payment_type_routing_enabled() || empty( $gateway_ids ) ) {
			return $gateway_ids;
		}

		$payment_type = self::get_selected_payment_type();
		if ( '' === $payment_type ) {
			return $gateway_ids;
		}

		$filtered = array_values(
			array_filter(
				$gateway_ids,
				static function( $gateway_id ) use ( $payment_type ) {
					return self::gateway_matches_payment_type( $gateway_id, $payment_type );
				}
			)
		);

		if ( empty( $filtered ) ) {
			self::clear_selected_payment_type();
			return $gateway_ids;
		}

		return $filtered;
	}

	private static function should_count_order_as_processor_failure( $order ) {
		$settings = self::get_router_settings();
		if ( empty( $settings['processor_only_fail_tracking'] ) ) {
			return true;
		}

		$text = self::get_order_failure_text( $order );
		if ( '' === $text ) {
			return false;
		}

		$customer_keywords = apply_filters(
			'brw_pr_customer_failure_keywords',
			array(
				'insufficient funds',
				'not sufficient funds',
				'nsf',
				'card declined',
				'declined',
				'do not honor',
				'expired card',
				'invalid card',
				'invalid account',
				'invalid number',
				'cvv',
				'cvc',
				'security code',
				'avs',
				'address verification',
				'billing address',
				'zip code',
				'postal code',
				'3d secure',
				'3ds',
				'authentication required',
				'customer authentication',
				'payer authentication',
				'verification failed',
				'fraud',
				'risk',
				'pickup card',
				'lost card',
				'stolen card',
				'restricted card',
				'transaction not permitted',
			)
		);

		$processor_keywords = apply_filters(
			'brw_pr_processor_failure_keywords',
			array(
				'processor error',
				'processing error',
				'gateway error',
				'gateway unavailable',
				'timeout',
				'timed out',
				'connection error',
				'network error',
				'api error',
				'server error',
				'internal error',
				'service unavailable',
				'invalid merchant',
				'merchant account',
				'terminal error',
				'configuration error',
				'credentials',
				'unable to reach',
				'could not connect',
				'http 500',
				'http 502',
				'http 503',
				'http 504',
			)
		);

		foreach ( (array) $customer_keywords as $keyword ) {
			$keyword = strtolower( trim( (string) $keyword ) );
			if ( '' !== $keyword && false !== strpos( $text, $keyword ) ) {
				return false;
			}
		}

		foreach ( (array) $processor_keywords as $keyword ) {
			$keyword = strtolower( trim( (string) $keyword ) );
			if ( '' !== $keyword && false !== strpos( $text, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	private static function get_order_failure_text( $order ) {
		$chunks = array();

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '';
		}

		$meta_keys = array(
			'_payment_failure_reason',
			'_payment_error',
			'_transaction_error',
			'_stripe_error',
			'_stripe_error_message',
			'_nmi_response_text',
			'_nmi_response_code',
			'_pgr_failure_reason',
		);

		foreach ( $meta_keys as $meta_key ) {
			$value = $order->get_meta( $meta_key, true );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$chunks[] = (string) $value;
			}
		}

		if ( function_exists( 'wc_get_order_notes' ) ) {
			$notes = wc_get_order_notes(
				array(
					'order_id' => $order->get_id(),
					'limit'    => 12,
					'orderby'  => 'date_created',
					'order'    => 'DESC',
				)
			);

			foreach ( (array) $notes as $note ) {
				if ( ! empty( $note->content ) ) {
					$chunks[] = (string) $note->content;
				}
			}
		}

		$text = strtolower( wp_strip_all_tags( implode( ' ', $chunks ) ) );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( (string) $text );
	}

	private static function get_gateway_adjustment_payload() {
		$rules    = self::get_gateway_rules();
		$settings = self::get_router_settings();
		$payload  = array();

		foreach ( $rules as $gateway_id => $row ) {
			$discount = isset( $row['discount'] ) ? (float) $row['discount'] : 0;
			$fee      = isset( $row['fee'] ) ? (float) $row['fee'] : 0;

			if ( $discount <= 0 && $fee <= 0 ) {
				continue;
			}

			$payload[ $gateway_id ] = array(
				'discount'       => $discount,
				'fee'            => $fee,
				'discount_badge' => $discount > 0 ? trim( $settings['label_prefix'] . ' ' . self::format_percent( $discount ) ) : '',
				'fee_badge'      => $fee > 0 ? self::format_percent( $fee ) . ' Fee' : '',
			);
		}

		return $payload;
	}

	private static function get_today_key() {
		$settings = BRW_PR_Admin::get_settings();
		$timezone = BRW_PR_Admin::get_effective_daily_reset_timezone( $settings );
		$time     = isset( $settings['daily_reset_time'] ) ? BRW_PR_Admin::sanitize_daily_reset_time( $settings['daily_reset_time'] ) : '00:00';

		try {
			$now          = new DateTimeImmutable( 'now', new DateTimeZone( $timezone ) );
			$reset_today  = $now->setTime( (int) substr( $time, 0, 2 ), (int) substr( $time, 3, 2 ), 0 );
			$business_day = $now < $reset_today ? $now->modify( '-1 day' ) : $now;

			return $business_day->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			return gmdate( 'Y-m-d' );
		}
	}

	private static function get_current_order_amount_for_limits() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return 0.0;
		}

		$cart = WC()->cart;
		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) || $cart->is_empty() ) {
			return 0.0;
		}

		$amount  = 0.0;
		$amount += max( 0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total() );
		$amount += max( 0, (float) $cart->get_shipping_total() );
		$amount += max( 0, (float) $cart->get_shipping_tax() );
		$amount += max( 0, (float) $cart->get_cart_contents_tax() );

		return max( 0.0, round( $amount, wc_get_price_decimals() ) );
	}

	private static function is_gateway_below_minimum_order_amount( $gateway_id, $rules = null ) {
		$rules = is_array( $rules ) ? $rules : self::get_gateway_rules();

		if ( empty( $rules[ $gateway_id ] ) ) {
			return false;
		}

		$minimum = isset( $rules[ $gateway_id ]['min_order_amount'] ) ? (float) $rules[ $gateway_id ]['min_order_amount'] : 0;
		if ( $minimum <= 0 ) {
			return false;
		}

		return self::get_current_order_amount_for_limits() < $minimum;
	}

	private static function get_today_gateway_total( $gateway_id ) {
		$date_key = self::get_today_key();
		$totals   = get_option( self::DAILY_TOTALS_KEY, array() );

		if ( ! is_array( $totals ) ) {
			return 0.0;
		}

		if ( empty( $totals[ $date_key ] ) || ! is_array( $totals[ $date_key ] ) ) {
			return 0.0;
		}

		return isset( $totals[ $date_key ][ $gateway_id ] ) ? (float) $totals[ $date_key ][ $gateway_id ] : 0.0;
	}

	private static function is_gateway_capped( $gateway_id, $rules = null ) {
		$rules = is_array( $rules ) ? $rules : self::get_gateway_rules();

		if ( empty( $rules[ $gateway_id ] ) ) {
			return false;
		}

		$cap = isset( $rules[ $gateway_id ]['daily_cap'] ) ? (float) $rules[ $gateway_id ]['daily_cap'] : 0;
		if ( $cap <= 0 ) {
			return false;
		}

		$today_total = self::get_today_gateway_total( $gateway_id );

		return $today_total >= $cap;
	}

	private static function is_gateway_auto_paused( $gateway_id, $rules = null ) {
		$rules = is_array( $rules ) ? $rules : self::get_gateway_rules();

		if ( empty( $rules[ $gateway_id ] ) ) {
			return false;
		}

		$row = $rules[ $gateway_id ];

		if ( empty( $row['auto_pause'] ) ) {
			return false;
		}

		$threshold   = isset( $row['pause_threshold'] ) ? (float) $row['pause_threshold'] : 50.0;
		$min_attempt = isset( $row['pause_min_count'] ) ? (int) $row['pause_min_count'] : 4;

		$approved = self::get_gateway_today_processing_count( $gateway_id );
		$failed   = self::get_gateway_today_failed_count( $gateway_id );
		$attempts = $approved + $failed;

		if ( $attempts < max( 1, $min_attempt ) ) {
			return false;
		}

		$fail_percent = self::get_gateway_today_fail_percent( $gateway_id );

		return $fail_percent >= $threshold;
	}

	private static function is_gateway_excluded( $gateway_id, $rules = null ) {
		return self::is_gateway_below_minimum_order_amount( $gateway_id, $rules ) || self::is_gateway_capped( $gateway_id, $rules ) || self::is_gateway_auto_paused( $gateway_id, $rules );
	}

	private static function get_fixed_gateway_rows( $available_gateways ) {
		$rules = self::get_gateway_rules();
		$fixed = array();

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			if ( empty( $rules[ $gateway_id ] ) ) {
				continue;
			}

			$row = $rules[ $gateway_id ];

			if ( 'fixed' !== $row['role'] ) {
				continue;
			}

			if ( self::is_gateway_excluded( $gateway_id, $rules ) ) {
				continue;
			}

			$fixed[] = array(
				'gateway_id'  => (string) $gateway_id,
				'fixed_order' => isset( $row['fixed_order'] ) ? max( 1, (int) $row['fixed_order'] ) : 999,
			);
		}

		usort(
			$fixed,
			static function( $a, $b ) {
				if ( $a['fixed_order'] === $b['fixed_order'] ) {
					return strcmp( $a['gateway_id'], $b['gateway_id'] );
				}

				return $a['fixed_order'] <=> $b['fixed_order'];
			}
		);

		return $fixed;
	}

	private static function get_fixed_gateway_ids( $available_gateways ) {
		return array_values( wp_list_pluck( self::get_fixed_gateway_rows( $available_gateways ), 'gateway_id' ) );
	}

	private static function get_rotating_pool( $available_gateways, $payment_type = null ) {
		$rules = self::get_gateway_rules();
		$pool  = array();

		if ( null === $payment_type ) {
			$payment_type = self::is_payment_type_routing_enabled() ? self::get_selected_payment_type() : '';
		}

		$payment_type = self::sanitize_payment_type( $payment_type );

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			if ( empty( $rules[ $gateway_id ] ) ) {
				continue;
			}

			$row = $rules[ $gateway_id ];

			if ( 'rotating' !== $row['role'] ) {
				continue;
			}

			if ( self::is_gateway_excluded( $gateway_id, $rules ) ) {
				continue;
			}

			if ( '' !== $payment_type && ! self::gateway_matches_payment_type( $gateway_id, $payment_type ) ) {
				continue;
			}

			$weight = isset( $row['weight'] ) ? (float) $row['weight'] : 0;
			if ( $weight <= 0 ) {
				continue;
			}

			$pool[] = array(
				'gateway_id' => (string) $gateway_id,
				'weight'     => $weight,
			);
		}

		return $pool;
	}

	private static function get_secondary_gateway_ids( $available_gateways ) {
		$settings = self::get_router_settings();
		if ( empty( $settings['show_secondary'] ) ) {
			return array();
		}

		$rules     = self::get_gateway_rules();
		$secondary = array();

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			if ( empty( $rules[ $gateway_id ] ) ) {
				continue;
			}

			if ( 'secondary' !== $rules[ $gateway_id ]['role'] ) {
				continue;
			}

			if ( self::is_gateway_excluded( $gateway_id, $rules ) ) {
				continue;
			}

			$secondary[] = (string) $gateway_id;
		}

		return array_values( $secondary );
	}

	private static function get_rotating_visible_count() {
		$settings = self::get_router_settings();
		return max( 1, (int) $settings['rotating_visible_count'] );
	}

	private static function get_or_assign_rotating_gateways( $available_gateways ) {
		/*
		 * When a payment type is selected, build the weighted rotating pool from only
		 * gateways that support that type. This preserves the normal weight percentage
		 * system while smart-routing Visa/MasterCard/Amex/wallet customers to eligible
		 * processors.
		 */
		$pool = self::get_rotating_pool( $available_gateways );

		if ( empty( $pool ) ) {
			return array();
		}

		$wanted_count = min( self::get_rotating_visible_count(), count( $pool ) );
		$pool_ids     = array_map(
			static function( $row ) {
				return (string) $row['gateway_id'];
			},
			$pool
		);

		$current = self::read_persisted_set();

		$valid = array_values(
			array_unique(
				array_filter(
					(array) $current,
					static function( $gateway_id ) use ( $pool_ids ) {
						return is_string( $gateway_id ) && in_array( $gateway_id, $pool_ids, true );
					}
				)
			)
		);

		if ( count( $valid ) > $wanted_count ) {
			$valid = array_slice( $valid, 0, $wanted_count );
		}

		if ( count( $valid ) < $wanted_count ) {
			$remaining_pool = array_values(
				array_filter(
					$pool,
					static function( $row ) use ( $valid ) {
						return ! in_array( (string) $row['gateway_id'], $valid, true );
					}
				)
			);

			$needed = $wanted_count - count( $valid );
			$added  = self::pick_multiple_weighted_gateways( $remaining_pool, $needed );

			$valid = array_values( array_unique( array_merge( $valid, $added ) ) );
		}

		self::persist_choice_set( $valid );

		return $valid;
	}

	private static function pick_multiple_weighted_gateways( $pool, $count ) {
		$chosen = array();

		if ( empty( $pool ) || $count <= 0 ) {
			return $chosen;
		}

		$working = array_values( $pool );
		$salt    = self::atomic_bump_counter();

		while ( ! empty( $working ) && count( $chosen ) < $count ) {
			$picked_id = self::pick_one_weighted_gateway( $working, $salt );
			if ( '' === $picked_id ) {
				break;
			}

			$chosen[] = $picked_id;

			$working = array_values(
				array_filter(
					$working,
					static function( $row ) use ( $picked_id ) {
						return (string) $row['gateway_id'] !== $picked_id;
					}
				)
			);

			$salt++;
		}

		return array_values( array_unique( $chosen ) );
	}

	private static function pick_one_weighted_gateway( $pool, $salt ) {
		if ( empty( $pool ) ) {
			return '';
		}

		if ( 1 === count( $pool ) ) {
			return (string) $pool[0]['gateway_id'];
		}

		$total = 0.0;
		foreach ( $pool as $row ) {
			$total += max( 0.0, (float) $row['weight'] );
		}

		if ( $total <= 0 ) {
			return (string) $pool[0]['gateway_id'];
		}

		$precision = 10000;
		$max_int   = max( 1, (int) round( $total * $precision ) );

		try {
			$ticket = random_int( 1, $max_int );
		} catch ( Exception $e ) {
			$ticket = wp_rand( 1, $max_int );
		}

		$cursor = 0;

		foreach ( $pool as $row ) {
			$cursor += max( 0, (int) round( (float) $row['weight'] * $precision ) );

			if ( $ticket <= $cursor ) {
				return (string) $row['gateway_id'];
			}
		}

		return (string) end( $pool )['gateway_id'];
	}

	private static function get_gateway_display_order_ids( $available_gateways, $chosen_rotating_gateways ) {
		$rows                = array();
		$seen                = array();
		$rotating_position   = 1;
		$rotating_slot_count = 0;

		foreach ( (array) $chosen_rotating_gateways as $gateway_id ) {
			$gateway_id = (string) $gateway_id;

			if ( '' === $gateway_id || isset( $seen[ $gateway_id ] ) || ! isset( $available_gateways[ $gateway_id ] ) ) {
				continue;
			}

			$rows[] = array(
				'gateway_id' => $gateway_id,
				'position'   => $rotating_position,
				'priority'   => 10,
				'index'      => $rotating_position,
			);

			$seen[ $gateway_id ] = true;
			$rotating_position++;
			$rotating_slot_count++;
		}

		$first_fixed_position = $rotating_slot_count + 1;
		$fixed_index          = 1;

		foreach ( self::get_fixed_gateway_rows( $available_gateways ) as $fixed_row ) {
			$gateway_id = isset( $fixed_row['gateway_id'] ) ? (string) $fixed_row['gateway_id'] : '';

			if ( '' === $gateway_id || isset( $seen[ $gateway_id ] ) || ! isset( $available_gateways[ $gateway_id ] ) ) {
				continue;
			}

			$fixed_order        = isset( $fixed_row['fixed_order'] ) ? max( 1, (int) $fixed_row['fixed_order'] ) : 999;
			$effective_position = max( $fixed_order, $first_fixed_position );

			$rows[] = array(
				'gateway_id' => $gateway_id,
				'position'   => $effective_position,
				'priority'   => 20,
				'index'      => $fixed_index,
			);

			$seen[ $gateway_id ] = true;
			$fixed_index++;
		}

		usort(
			$rows,
			static function( $a, $b ) {
				if ( $a['position'] !== $b['position'] ) {
					return $a['position'] <=> $b['position'];
				}

				if ( $a['priority'] !== $b['priority'] ) {
					return $a['priority'] <=> $b['priority'];
				}

				if ( $a['index'] !== $b['index'] ) {
					return $a['index'] <=> $b['index'];
				}

				return strcmp( $a['gateway_id'], $b['gateway_id'] );
			}
		);

		$display_ids = array_values( wp_list_pluck( $rows, 'gateway_id' ) );

		foreach ( self::get_secondary_gateway_ids( $available_gateways ) as $gateway_id ) {
			$gateway_id = (string) $gateway_id;

			if ( '' !== $gateway_id && ! isset( $seen[ $gateway_id ] ) && isset( $available_gateways[ $gateway_id ] ) ) {
				$display_ids[]       = $gateway_id;
				$seen[ $gateway_id ] = true;
			}
		}

		return array_values( $display_ids );
	}

	private static function filter_and_sort_gateways_for_display( $gateways, $chosen_rotating_gateways ) {
		$sorted = array();

		foreach ( self::get_gateway_display_order_ids( $gateways, $chosen_rotating_gateways ) as $gateway_id ) {
			if ( isset( $gateways[ $gateway_id ] ) ) {
				$sorted[ $gateway_id ] = $gateways[ $gateway_id ];
			}
		}

		if ( ! empty( $sorted ) ) {
			return $sorted;
		}

		return self::has_configured_display_gateways( $gateways, self::get_gateway_rules() ) ? array() : $gateways;
	}

	private static function decorate_available_gateways( $gateways ) {
		if ( is_admin() || ! is_array( $gateways ) || empty( $gateways ) ) {
			return $gateways;
		}

		$rules    = self::get_gateway_rules();
		$settings = self::get_router_settings();

		foreach ( $gateways as $gateway_id => $gateway ) {
			$discount = isset( $rules[ $gateway_id ]['discount'] ) ? (float) $rules[ $gateway_id ]['discount'] : 0;
			$fee      = isset( $rules[ $gateway_id ]['fee'] ) ? (float) $rules[ $gateway_id ]['fee'] : 0;

			if ( $discount <= 0 && $fee <= 0 ) {
				continue;
			}

			if ( $discount > 0 ) {
				$title_text = wp_strip_all_tags( (string) $gateway->title );
				$badge      = trim( $settings['label_prefix'] . ' ' . self::format_percent( $discount ) );

				if ( false === strpos( wp_strip_all_tags( (string) $gateway->title ), wp_strip_all_tags( $badge ) ) ) {
					$gateway->title .= ' <span class="brw-pr-discount-badge">' . esc_html( $badge ) . '</span>';
				}

				$message = ! empty( $rules[ $gateway_id ]['message'] )
					? (string) $rules[ $gateway_id ]['message']
					: sprintf(
						'Use %s and receive %s off your order subtotal.',
						$title_text,
						self::format_percent( $discount )
					);

				$existing_description = isset( $gateway->description ) ? (string) $gateway->description : '';
				if ( false === strpos( wp_strip_all_tags( $existing_description ), wp_strip_all_tags( $message ) ) ) {
					$gateway->description = trim( $existing_description . ' <div class="brw-pr-discount-announcement">' . esc_html( $message ) . '</div>' );
				}
			}

			if ( $fee > 0 ) {
				$fee_badge = self::format_percent( $fee ) . ' Fee';
				if ( false === strpos( wp_strip_all_tags( (string) $gateway->title ), wp_strip_all_tags( $fee_badge ) ) ) {
					$gateway->title .= ' <span class="brw-pr-fee-badge">' . esc_html( $fee_badge ) . '</span>';
				}
			}
		}

		$discount_badge_color      = isset( $settings['discount_badge_color'] ) ? BRW_PR_Admin::sanitize_badge_color( $settings['discount_badge_color'], '#155e75' ) : '#155e75';
		$discount_badge_text_color = isset( $settings['discount_badge_text_color'] ) ? BRW_PR_Admin::sanitize_badge_color( $settings['discount_badge_text_color'], '#ffffff' ) : '#ffffff';
		$fee_badge_color           = isset( $settings['fee_badge_color'] ) ? BRW_PR_Admin::sanitize_badge_color( $settings['fee_badge_color'], '#b42318' ) : '#b42318';
		$fee_badge_text_color      = isset( $settings['fee_badge_text_color'] ) ? BRW_PR_Admin::sanitize_badge_color( $settings['fee_badge_text_color'], '#ffffff' ) : '#ffffff';

		add_action(
			'wp_print_footer_scripts',
			static function() use ( $discount_badge_color, $discount_badge_text_color, $fee_badge_color, $fee_badge_text_color ) {
				echo '<style>.brw-pr-discount-badge,.brw-pr-fee-badge{display:inline-block;margin-left:8px;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700;line-height:1.5;vertical-align:middle;}.brw-pr-discount-badge{background:' . esc_attr( $discount_badge_color ) . ';color:' . esc_attr( $discount_badge_text_color ) . ';}.brw-pr-fee-badge{background:' . esc_attr( $fee_badge_color ) . ';color:' . esc_attr( $fee_badge_text_color ) . ';}.brw-pr-discount-announcement{margin-top:6px;color:' . esc_attr( $discount_badge_color ) . ';font-size:12px;font-weight:600;}</style>';
			},
			99
		);

		return $gateways;
	}

	private static function get_selected_payment_type() {
		foreach ( self::get_payment_type_candidates() as $payment_type ) {
			$payment_type = self::sanitize_payment_type( $payment_type );
			if ( '' !== $payment_type ) {
				self::store_selected_payment_type( $payment_type );
				return $payment_type;
			}
		}

		return '';
	}

	private static function get_payment_type_candidates() {
		$candidates = array();
		$keys = array( 'brw_pr_payment_type', 'payment_type', 'card_brand', 'brand', 'wallet_intent' );

		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$candidates[] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
			if ( isset( $_REQUEST[ $key ] ) ) {
				$candidates[] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
			}
		}

		foreach ( array( 'post_data' ) as $post_data_key ) {
			if ( isset( $_POST[ $post_data_key ] ) && is_string( $_POST[ $post_data_key ] ) ) {
				parse_str( wp_unslash( $_POST[ $post_data_key ] ), $parsed_post_data );
				foreach ( $keys as $key ) {
					if ( ! empty( $parsed_post_data[ $key ] ) ) {
						$candidates[] = sanitize_text_field( (string) $parsed_post_data[ $key ] );
					}
				}
			}

			if ( isset( $_REQUEST[ $post_data_key ] ) && is_string( $_REQUEST[ $post_data_key ] ) ) {
				parse_str( wp_unslash( $_REQUEST[ $post_data_key ] ), $parsed_request_post_data );
				foreach ( $keys as $key ) {
					if ( ! empty( $parsed_request_post_data[ $key ] ) ) {
						$candidates[] = sanitize_text_field( (string) $parsed_request_post_data[ $key ] );
					}
				}
			}
		}

		$raw_input = file_get_contents( 'php://input' );
		if ( is_string( $raw_input ) && '' !== $raw_input ) {
			$decoded = json_decode( $raw_input, true );
			if ( is_array( $decoded ) ) {
				foreach ( $keys as $key ) {
					if ( ! empty( $decoded[ $key ] ) ) {
						$candidates[] = sanitize_text_field( (string) $decoded[ $key ] );
					}
				}
				if ( ! empty( $decoded['payment_data']['card_brand'] ) ) {
					$candidates[] = sanitize_text_field( (string) $decoded['payment_data']['card_brand'] );
				}
				if ( ! empty( $decoded['paymentData']['cardBrand'] ) ) {
					$candidates[] = sanitize_text_field( (string) $decoded['paymentData']['cardBrand'] );
				}
			}
		}

		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			$candidates[] = WC()->session->get( self::SELECTED_PAYMENT_TYPE_KEY );
			$candidates[] = WC()->session->get( 'pgr_wallet_intent' );
		}

		return array_values( array_filter( array_map( static function( $value ) {
			return is_string( $value ) ? sanitize_key( $value ) : '';
		}, $candidates ) ) );
	}

	private static function store_selected_payment_type( $payment_type ) {
		$payment_type = self::sanitize_payment_type( $payment_type );

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return;
		}

		if ( '' === $payment_type ) {
			self::clear_selected_payment_type();
			return;
		}

		WC()->session->set( self::SELECTED_PAYMENT_TYPE_KEY, $payment_type );

		if ( in_array( $payment_type, array( 'apple_pay', 'google_pay' ), true ) ) {
			WC()->session->set( 'pgr_wallet_intent', $payment_type );
		} else {
			WC()->session->set( 'pgr_wallet_intent', '' );
		}
	}

	private static function clear_selected_payment_type() {
		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			WC()->session->set( self::SELECTED_PAYMENT_TYPE_KEY, '' );
			WC()->session->set( 'pgr_wallet_intent', '' );
		}
	}

	private static function get_selected_payment_method() {
		foreach ( self::get_payment_method_candidates() as $gateway_id ) {
			if ( '' === $gateway_id ) {
				continue;
			}

			if ( ! self::is_gateway_currently_available( $gateway_id ) ) {
				self::clear_selected_payment_method();
				continue;
			}

			self::store_selected_payment_method( $gateway_id );
			return $gateway_id;
		}

		return '';
	}

	private static function get_payment_method_candidates() {
		$candidates = array();

		if ( isset( $_POST['payment_method'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
		}

		if ( isset( $_REQUEST['payment_method'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_REQUEST['payment_method'] ) );
		}


		if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $parsed_post_data );
			if ( ! empty( $parsed_post_data['payment_method'] ) ) {
				$candidates[] = sanitize_text_field( (string) $parsed_post_data['payment_method'] );
			}
		}

		if ( isset( $_REQUEST['post_data'] ) && is_string( $_REQUEST['post_data'] ) ) {
			parse_str( wp_unslash( $_REQUEST['post_data'] ), $parsed_request_post_data );
			if ( ! empty( $parsed_request_post_data['payment_method'] ) ) {
				$candidates[] = sanitize_text_field( (string) $parsed_request_post_data['payment_method'] );
			}
		}

		$raw_input = file_get_contents( 'php://input' );
		if ( is_string( $raw_input ) && '' !== $raw_input ) {
			$decoded = json_decode( $raw_input, true );

			if ( is_array( $decoded ) ) {
				if ( ! empty( $decoded['payment_method'] ) ) {
					$candidates[] = sanitize_text_field( (string) $decoded['payment_method'] );
				}

				if ( ! empty( $decoded['paymentMethod'] ) ) {
					$candidates[] = sanitize_text_field( (string) $decoded['paymentMethod'] );
				}

				if ( ! empty( $decoded['payment_data']['payment_method'] ) ) {
					$candidates[] = sanitize_text_field( (string) $decoded['payment_data']['payment_method'] );
				}
			}

			if ( false !== strpos( $raw_input, 'payment_method=' ) ) {
				parse_str( $raw_input, $parsed_raw );
				if ( ! empty( $parsed_raw['payment_method'] ) ) {
					$candidates[] = sanitize_text_field( wp_unslash( $parsed_raw['payment_method'] ) );
				}
			}
		}

		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			$candidates[] = WC()->session->get( self::SELECTED_METHOD_KEY );
			$candidates[] = WC()->session->get( 'chosen_payment_method' );
		}

		return array_values( array_filter( array_map( static function( $value ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}, $candidates ) ) );
	}

	private static function store_selected_payment_method( $gateway_id ) {
		$gateway_id = sanitize_text_field( (string) $gateway_id );

		if ( '' === $gateway_id ) {
			return;
		}

		if ( ! self::is_gateway_currently_available( $gateway_id ) ) {
			self::clear_selected_payment_method();
			return;
		}

		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			WC()->session->set( self::SELECTED_METHOD_KEY, $gateway_id );
			WC()->session->set( 'chosen_payment_method', $gateway_id );
		}
	}

	private static function clear_selected_payment_method() {
		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			WC()->session->set( self::SELECTED_METHOD_KEY, '' );
			WC()->session->set( 'chosen_payment_method', '' );
		}
	}

	private static function clear_stale_selected_payment_method() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return;
		}

		$current = WC()->session->get( self::SELECTED_METHOD_KEY );
		if ( ! is_string( $current ) || '' === $current ) {
			$current = WC()->session->get( 'chosen_payment_method' );
		}

		$current = is_string( $current ) ? sanitize_text_field( $current ) : '';
		if ( '' !== $current && ! self::is_gateway_currently_available( $current ) ) {
			self::clear_selected_payment_method();
		}
	}

	private static function is_gateway_currently_available( $gateway_id ) {
		$gateway_id = sanitize_text_field( (string) $gateway_id );
		if ( '' === $gateway_id ) {
			return false;
		}

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
			return true;
		}

		$all_gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! is_array( $all_gateways ) || ! isset( $all_gateways[ $gateway_id ] ) || ! is_object( $all_gateways[ $gateway_id ] ) ) {
			return false;
		}

		$gateway = $all_gateways[ $gateway_id ];

		if ( isset( $gateway->enabled ) && 'yes' !== (string) $gateway->enabled ) {
			return false;
		}

		if ( method_exists( $gateway, 'is_available' ) ) {
			try {
				return (bool) $gateway->is_available();
			} catch ( Exception $e ) {
				return false;
			}
		}

		return true;
	}

	private static function read_persisted_set() {
		$current_day = self::get_today_key();

		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			$session_date = (string) WC()->session->get( self::SESSION_DATE_KEY );
			$session_val  = WC()->session->get( self::SESSION_KEY );
			$current      = self::normalize_gateway_set( $session_val );

			if ( ! empty( $current ) && $session_date === $current_day ) {
				return $current;
			}

			if ( ! empty( $current ) && $session_date !== $current_day ) {
				WC()->session->set( self::SESSION_KEY, array() );
				WC()->session->set( self::SESSION_DATE_KEY, '' );
			}
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			$meta_date = (string) get_user_meta( $user_id, self::USERMETA_DATE_KEY, true );
			$meta_val  = get_user_meta( $user_id, self::USERMETA_KEY, true );
			$current   = self::normalize_gateway_set( $meta_val );

			if ( ! empty( $current ) && $meta_date === $current_day ) {
				return $current;
			}

			if ( ! empty( $current ) && $meta_date !== $current_day ) {
				delete_user_meta( $user_id, self::USERMETA_KEY );
				delete_user_meta( $user_id, self::USERMETA_DATE_KEY );
			}
		}

		if ( isset( $_COOKIE[ self::COOKIE_KEY ] ) ) {
			$cookie_date = isset( $_COOKIE[ self::COOKIE_DATE_KEY ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_DATE_KEY ] ) ) : '';
			$current     = self::normalize_gateway_set( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_KEY ] ) ) );

			if ( ! empty( $current ) && $cookie_date === $current_day ) {
				return $current;
			}

			if ( ! empty( $current ) && $cookie_date !== $current_day ) {
				self::clear_choice_cookies();
			}
		}

		return array();
	}

	private static function normalize_gateway_set( $value ) {
		if ( is_array( $value ) ) {
			$items = $value;
		} elseif ( is_string( $value ) && '' !== $value ) {
			if ( false !== strpos( $value, '|' ) ) {
				$items = explode( '|', $value );
			} else {
				$items = array( $value );
			}
		} else {
			$items = array();
		}

		$items = array_map(
			static function( $item ) {
				return sanitize_text_field( (string) $item );
			},
			$items
		);

		$items = array_values(
			array_filter(
				array_unique( $items ),
				static function( $item ) {
					return '' !== $item;
				}
			)
		);

		return $items;
	}

	private static function persist_choice_set( $gateway_ids ) {
		$gateway_ids = self::normalize_gateway_set( (array) $gateway_ids );
		$packed      = implode( '|', $gateway_ids );
		$today       = self::get_today_key();

		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $gateway_ids );
			WC()->session->set( self::SESSION_DATE_KEY, $today );
		}

		$expire = time() + (int) self::COOKIE_DAYS * DAY_IN_SECONDS;
		setcookie( self::COOKIE_KEY, $packed, $expire, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		setcookie( self::COOKIE_DATE_KEY, $today, $expire, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE[ self::COOKIE_KEY ]      = $packed;
		$_COOKIE[ self::COOKIE_DATE_KEY ] = $today;

		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::USERMETA_KEY, $packed );
			update_user_meta( $user_id, self::USERMETA_DATE_KEY, $today );
		}
	}

	private static function clear_choice_cookies() {
		setcookie( self::COOKIE_KEY, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		setcookie( self::COOKIE_DATE_KEY, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		unset( $_COOKIE[ self::COOKIE_KEY ], $_COOKIE[ self::COOKIE_DATE_KEY ] );
	}

	private static function atomic_bump_counter() {
		global $wpdb;

		$name  = self::COUNTER_OPTION;
		$table = $wpdb->options;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (option_name, option_value, autoload)
				 VALUES (%s, %d, 'no')
				 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$name,
				0
			)
		);

		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1",
				$name
			)
		);

		return max( 1, (int) $val );
	}

	private static function format_percent( $value ) {
		$value = (float) $value;

		if ( floor( $value ) == $value ) {
			return (string) (int) $value . '%';
		}

		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' ) . '%';
	}
}