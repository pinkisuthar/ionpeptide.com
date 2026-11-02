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
	const COOKIE_DAYS       = 60;
	const JS_HANDLE         = 'brw-payment-rotator-sticky-blocks';
	const DAILY_TOTALS_KEY  = 'brw_pr_daily_totals_v1';
	const DAILY_STATS_KEY   = 'brw_pr_daily_stats_v1';

	public static function init() {
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways_classic' ), 9999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_scripts' ), 20 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_posted_gateway' ) );
		add_action( 'wp_ajax_brw_pr_select_gateway', array( __CLASS__, 'ajax_store_selected_gateway' ) );
		add_action( 'wp_ajax_nopriv_brw_pr_select_gateway', array( __CLASS__, 'ajax_store_selected_gateway' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_gateway_adjustments' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'add_order_meta' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'track_order_total_for_daily_caps' ), 20, 3 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'track_gateway_status_stats' ), 20, 4 );
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

		return self::decorate_available_gateways( $sorted );
	}

	public static function enqueue_checkout_scripts() {
		if ( is_admin() ) {
			return;
		}

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

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
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'brw_pr_select_gateway' ),
		);

		if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
			$available = WC()->payment_gateways()->get_available_payment_gateways();

			if ( is_array( $available ) && ! empty( $available ) && self::is_blocks_checkout_context() ) {
				$chosen_rotating = self::get_or_assign_rotating_gateways( $available );
				$fixed_ids       = self::get_fixed_gateway_ids( $available );
				$secondary_ids   = self::get_secondary_gateway_ids( $available );
				$all_ids         = array_values( array_keys( $available ) );
				$display_ids     = array_values( array_unique( array_merge( $fixed_ids, $chosen_rotating, $secondary_ids ) ) );

				$payload = array(
					'all_ids'       => $all_ids,
					'fixed_ids'     => $fixed_ids,
					'rotator_ids'   => $chosen_rotating,
					'secondary_ids' => $secondary_ids,
					'allowed_ids'   => $display_ids,
					'display_order' => $display_ids,
					'adjustments'   => self::get_gateway_adjustment_payload(),
					'ajax_url'      => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( 'brw_pr_select_gateway' ),
				);
			}
		}

		wp_localize_script( self::JS_HANDLE, 'BRW_PAYMENT_ROTATOR_STICKY', $payload );
		wp_enqueue_script( self::JS_HANDLE );
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
		$gateway_id = '';

		if ( is_string( $posted_data ) ) {
			parse_str( $posted_data, $parsed );
			if ( ! empty( $parsed['payment_method'] ) ) {
				$gateway_id = sanitize_text_field( wp_unslash( $parsed['payment_method'] ) );
			}
		}

		if ( '' !== $gateway_id ) {
			self::store_selected_payment_method( $gateway_id );
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
			self::increment_gateway_stat( $order, $gateway_id, $date_key, 'failed_count', 1 );
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
				'approved_total' => 0,
				'approved_count' => 0,
				'failed_count'   => 0,
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

	private static function get_fixed_gateway_ids( $available_gateways ) {
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
				'fixed_order' => isset( $row['fixed_order'] ) ? (int) $row['fixed_order'] : 999,
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

		return array_values( wp_list_pluck( $fixed, 'gateway_id' ) );
	}

	private static function get_rotating_pool( $available_gateways ) {
		$rules = self::get_gateway_rules();
		$pool  = array();

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

	private static function filter_and_sort_gateways_for_display( $gateways, $chosen_rotating_gateways ) {
		$fixed_ids     = self::get_fixed_gateway_ids( $gateways );
		$secondary_ids = self::get_secondary_gateway_ids( $gateways );
		$sorted        = array();

		foreach ( $fixed_ids as $gateway_id ) {
			if ( isset( $gateways[ $gateway_id ] ) ) {
				$sorted[ $gateway_id ] = $gateways[ $gateway_id ];
			}
		}

		foreach ( (array) $chosen_rotating_gateways as $gateway_id ) {
			if ( isset( $gateways[ $gateway_id ] ) ) {
				$sorted[ $gateway_id ] = $gateways[ $gateway_id ];
			}
		}

		foreach ( $secondary_ids as $gateway_id ) {
			if ( isset( $gateways[ $gateway_id ] ) ) {
				$sorted[ $gateway_id ] = $gateways[ $gateway_id ];
			}
		}

		return ! empty( $sorted ) ? $sorted : $gateways;
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

		add_action(
			'wp_print_footer_scripts',
			static function() {
				echo '<style>.brw-pr-discount-badge,.brw-pr-fee-badge{display:inline-block;margin-left:8px;padding:2px 8px;border-radius:999px;color:#fff;font-size:12px;font-weight:700;line-height:1.5;vertical-align:middle;}.brw-pr-discount-badge{background:#155e75;}.brw-pr-fee-badge{background:#b42318;}.brw-pr-discount-announcement{margin-top:6px;color:#155e75;font-size:12px;font-weight:600;}</style>';
			},
			99
		);

		return $gateways;
	}

	private static function get_selected_payment_method() {
		foreach ( self::get_payment_method_candidates() as $gateway_id ) {
			if ( '' === $gateway_id ) {
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

		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			WC()->session->set( self::SELECTED_METHOD_KEY, $gateway_id );
			WC()->session->set( 'chosen_payment_method', $gateway_id );
		}
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