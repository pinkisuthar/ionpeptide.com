<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Carts_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $total_count = 0;
	public $count_data = [];
	public $task_localized = [];

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		$this->pagination         = new stdClass();
		$this->pagination->offset = 0;
		$this->pagination->limit  = 10;
		parent::__construct();
	}

	public function default_args_values() {
		return array(
			'search_by'         => 'email',
			'search'            => '',
			'offset'            => 0,
			'limit'             => 10,
			'abandoned_ids'     => [],
			'abandoned_id'      => '',
			'type'              => '',
			'no_of_days'        => '',
			'date_range_first'  => '',
			'date_range_second' => '',
		);
	}

	protected function register_routes() {
		// GET /carts/recoverable/
		$this->add_route( '/carts/recoverable/', array(
			array( 'GET', 'get_recoverable_carts', array(
				'search' => array(
					'description' => __( 'Search from email or checkout page id', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'offset' => array(
					'description' => __( 'Recoverable carts list Offset', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'limit'  => array(
					'description' => __( 'Per page limit', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
		) );

		// GET /carts/recovered/
		$this->add_route( '/carts/recovered/', array(
			array( 'GET', 'get_recovered_carts', array(
				'search' => array(
					'description' => '',
					'type'        => 'string',
				),
				'offset' => array(
					'description' => __( 'Recovered carts list Offset', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'limit'  => array(
					'description' => __( 'Per page limit', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
		) );

		// GET /carts/lost/
		$this->add_route( '/carts/lost/', array(
			array( 'GET', 'get_lost_carts', array(
				'search' => array(
					'description' => __( 'Search from email or checkout page id', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'offset' => array(
					'description' => __( 'Lost carts list Offset', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
				'limit'  => array(
					'description' => __( 'Per page limit', 'wp-marketing-automations' ),
					'type'        => 'integer',
				),
			) ),
		) );

		// DELETE /carts/
		$this->add_route( '/carts/', array(
			array( 'DELETE', 'delete_abandoned_carts' ),
		) );

		// PUT /carts/abandoned/retry/
		$this->add_route( '/carts/abandoned/retry/', array(
			array( 'PUT', 'retry_abandoned_carts' ),
		) );

		// GET /carts/{abandoned_id}/tasks/
		$this->add_route( '/carts/(?P<abandoned_id>[\\d]+)/tasks/', array(
			array( 'GET', 'get_cart_view_tasks' ),
		) );

		// GET /analytics/carts/
		$this->add_route( '/analytics/carts/', array(
			array( 'GET', 'get_cart_analytics' ),
		) );
	}

	/**
	 * GET /carts/recoverable/
	 */
	public function get_recoverable_carts() {
		$search_by   = $this->get_sanitized_arg( 'search_by', 'text_field' );
		$search_term = $this->get_sanitized_arg( 'search', 'text_field' );
		$offset      = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? $this->get_sanitized_arg( 'offset', 'absint' ) : 0;
		$limit       = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;

		$recoverable_carts = BWFAN_Recoverable_Carts::get_abandoned_carts( $search_by, $search_term, $offset, $limit );
		if ( isset( $recoverable_carts['total_count'] ) ) {
			$this->total_count = $recoverable_carts['total_count'];
			unset( $recoverable_carts['total_count'] );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->success_response( [], __( 'No recoverable carts found', 'wp-marketing-automations' ) );
		}

		$result  = [];
		$nowDate = new DateTime( 'now', new DateTimeZone( "UTC" ) );
		foreach ( $recoverable_carts as $item ) {
			$cartDate      = new DateTime( $item->last_modified );
			$diff          = date_diff( $nowDate, $cartDate, true );
			$diff          = BWFAN_Common::get_difference_string( $diff );
			$currency_data = BWFAN_Recoverable_Carts::get_currency( $item );
			$total         = ! is_null( $item->total ) ? $item->total : 0;
			$fee_data      = $this->get_formatted_fee_data( $item->fees );
			$result[]      = [
				'id'            => ! is_null( $item->ID ) ? $item->ID : 0,
				'email'         => ! is_null( $item->email ) ? $item->email : '',
				'phone'         => $this->get_phone_recoverable( $item ),
				'preview'       => $this->get_preview_data_recoverable( $item, $fee_data['total'] ),
				'date'          => get_date_from_gmt( $item->last_modified ),
				'created_on'    => get_date_from_gmt( $item->created_time ),
				'diffstring'    => $diff,
				'status'        => ! is_null( $item->status ) ? $item->status : '',
				'items'         => $this->get_items_recoverable( $item ),
				'fees'          => ! empty( $fee_data['data'] ) ? $fee_data['data'] : [],
				'total'         => $total,
				'currency'      => $currency_data,
				'buyer_name'    => $this->get_order_name_recoverable( $item ),
				'order_id'      => $this->get_order_id_recoverable( $item ),
				'user_id'       => ! empty( $item->user_id ) ? $item->user_id : 0,
				'checkout_data' => ! is_null( $item->checkout_data ) ? $item->checkout_data : '',
				'recovery_link' => $this->get_recovery_link( $item ),
			];
		}

		$result           = BWFAN_Recoverable_Carts::populate_contact_info( $result );
		$this->count_data = [];

		return $this->success_response( $result, __( 'Recoverable carts found', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /carts/recovered/
	 */
	public function get_recovered_carts() {
		$search = $this->get_sanitized_arg( 'search', 'text_field' );
		$offset = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? $this->get_sanitized_arg( 'offset', 'absint' ) : 0;
		$limit  = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;

		$recovered_carts  = BWFAN_Recoverable_Carts::get_recovered_carts( $search, $offset, $limit );
		$result           = [];
		$this->count_data = [];

		if ( ! isset( $recovered_carts['items'] ) ) {
			return $this->success_response( [], __( 'No recovered carts found', 'wp-marketing-automations' ) );
		}
		$orders  = $recovered_carts['items'];
		$nowDate = new DateTime( 'now', new DateTimeZone( "UTC" ) );
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$cartDate      = new DateTime( $order->get_date_created()->date( 'Y-m-d H:i:s' ) );
			$diff          = date_diff( $nowDate, $cartDate, true );
			$diff          = BWFAN_Common::get_difference_string( $diff );
			$currency_data = BWFAN_Recoverable_Carts::get_currency( $order );
			$preview_data  = $this->get_preview_recovered( $order );

			/** Attach visitor fingerprint from order meta (copied before abandoned cart row was deleted) */
			$fingerprint_json = $order->get_meta( '_bwfan_visitor_fingerprint' );
			if ( ! empty( $fingerprint_json ) ) {
				$fingerprint = json_decode( $fingerprint_json, true );
				if ( is_array( $fingerprint ) ) {
					$preview_data['user_info']    = BWFAN_Common::add_visitor_info_to_others( $fingerprint );
					$preview_data['visitor_info'] = $fingerprint;
				}
			}

			$result[] = [
				'id'            => $order->get_meta( '_bwfan_recovered_ab_id' ),
				'order_id'      => $order->get_id(),
				'email'         => $order->get_billing_email(),
				'phone'         => $order->get_billing_phone(),
				'f_name'        => $order->get_billing_first_name(),
				'l_name'        => $order->get_billing_last_name(),
				'preview'       => $preview_data,
				'diffstring'    => $diff,
				'date'          => $order->get_date_created()->date( 'Y-m-d H:i:s' ),
				'items'         => $this->get_items_recovered( $order ),
				'total'         => $order->get_total(),
				'currency'      => $currency_data,
				'buyer_name'    => $this->get_order_name_recovered( $order ),
				'user_id'       => ! empty( $order->get_customer_id() ) ? $order->get_customer_id() : 0,
				'checkout_data' => ! is_null( $order->get_meta() ) ? $order->get_meta() : '',
			];
		}

		$result = BWFAN_Recoverable_Carts::populate_contact_info( $result );
		if ( isset( $recovered_carts['total_count'] ) ) {
			$this->total_count = $recovered_carts['total_count'];
			unset( $result['total_record'] );
		}

		return $this->success_response( $result, __( 'Recovered carts found', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /carts/lost/
	 */
	public function get_lost_carts() {
		$search_by   = $this->get_sanitized_arg( 'search_by', 'text_field' );
		$search_term = $this->get_sanitized_arg( 'search', 'text_field' );
		$offset      = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? $this->get_sanitized_arg( 'offset', 'absint' ) : 0;
		$limit       = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;

		$lost_carts = BWFAN_Recoverable_Carts::get_abandoned_carts( $search_by, $search_term, $offset, $limit, 2 );
		if ( isset( $lost_carts['total_count'] ) ) {
			$this->total_count = $lost_carts['total_count'];
			unset( $lost_carts['total_count'] );
		}

		$result  = [];
		$nowDate = new DateTime( 'now', new DateTimeZone( "UTC" ) );
		foreach ( $lost_carts as $item ) {
			$cartDate      = new DateTime( $item->last_modified );
			$diff          = date_diff( $nowDate, $cartDate, true );
			$diff          = BWFAN_Common::get_difference_string( $diff );
			$currency_data = BWFAN_Recoverable_Carts::get_currency( $item );
			$total         = ! is_null( $item->total ) ? $item->total : 0;
			$fee_data      = $this->get_formatted_fee_data( $item->fees );
			$result[]      = [
				'id'            => ! is_null( $item->ID ) ? $item->ID : 0,
				'email'         => ! is_null( $item->email ) ? $item->email : '',
				'phone'         => $this->get_phone_lost( $item ),
				'preview'       => $this->get_preview_data_lost( $item, $fee_data['total'] ),
				'date'          => get_date_from_gmt( $item->last_modified ),
				'created_on'    => get_date_from_gmt( $item->created_time ),
				'status'        => ! is_null( $item->status ) ? $item->status : '',
				'diffstring'    => $diff,
				'items'         => $this->get_items_lost( $item ),
				'fees'          => ! empty( $fee_data['data'] ) ? $fee_data['data'] : [],
				'total'         => $total,
				'currency'      => $currency_data,
				'buyer_name'    => $this->get_order_name_lost( $item ),
				'order_id'      => $this->get_order_id_lost( $item ),
				'user_id'       => ! empty( $item->user_id ) ? $item->user_id : 0,
				'checkout_data' => ! is_null( $item->checkout_data ) ? $item->checkout_data : '',
			];
		}

		$result           = BWFAN_Recoverable_Carts::populate_contact_info( $result );
		$this->count_data = [];

		return $this->success_response( $result, __( 'Lost carts found', 'wp-marketing-automations' ) );
	}

	/**
	 * DELETE /carts/
	 */
	public function delete_abandoned_carts() {
		$abandoned_ids = $this->args['abandoned_ids'];
		$type          = isset( $this->args['type'] ) ? $this->args['type'] : 'cart';
		if ( empty( $abandoned_ids ) || ! is_array( $abandoned_ids ) ) {
			return $this->error_response( __( 'Cart IDs are missing', 'wp-marketing-automations' ) );
		}

		/** Delete recovered cart */
		if ( 'order' === $type ) {
			BWFAN_Recoverable_Carts::delete_recovered_carts( $abandoned_ids );

			return $this->success_response( [], __( 'Cart deleted', 'wp-marketing-automations' ) );
		}
		$result = BWFAN_Recoverable_Carts::delete_abandoned_cart( $abandoned_ids );
		if ( true !== $result && is_array( $result ) ) {
			$message = __( 'Unable to delete cart with ID: ', 'wp-marketing-automations' ) . implode( ',', $result );

			return $this->success_response( [], $message );
		}

		return $this->success_response( [], __( 'Cart deleted', 'wp-marketing-automations' ) );
	}

	/**
	 * PUT /carts/abandoned/retry/
	 */
	public function retry_abandoned_carts() {
		$abandoned_ids = $this->args['abandoned_ids'];
		if ( empty( $abandoned_ids ) || ! is_array( $abandoned_ids ) ) {
			return $this->error_response( __( 'Abandoned carts missing', 'wp-marketing-automations' ) );
		}

		BWFAN_Recoverable_Carts::retry_abandoned_cart( $abandoned_ids );

		return $this->success_response( [], __( 'Cart retry done', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /carts/{abandoned_id}/tasks/
	 */
	public function get_cart_view_tasks() {
		$abandoned_id = $this->args['abandoned_id'];
		if ( empty( $abandoned_id ) ) {
			return $this->error_response( __( 'Abandoned cart is missing', 'wp-marketing-automations' ) );
		}
		$type = $this->args['type'];

		global $wpdb;
		$cart_details  = [];
		$basic_details = [];
		if ( 'recovered' === $type ) {
			if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
				$query    = $wpdb->prepare( "SELECT `order_id` FROM {$wpdb->prefix}wc_orders_meta WHERE `meta_key` = %s AND `meta_value`= %d", '_bwfan_recovered_ab_id', $abandoned_id );
				$order_id = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above; table from $wpdb->prefix
			} else {
				$query    = $wpdb->prepare( "SELECT `post_id` FROM {$wpdb->prefix}postmeta WHERE `meta_key` = %s AND `meta_value`= %d", '_bwfan_recovered_ab_id', $abandoned_id );
				$order_id = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above; table from $wpdb->prefix
			}
			if ( empty( $order_id ) ) {
				return $this->error_response( __( 'Abandoned cart data is missing', 'wp-marketing-automations' ) );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return $this->error_response( __( 'Order is missing', 'wp-marketing-automations' ) );
			}

			$cart_details['email'] = $order->get_meta( '_billing_email' );

			$basic_details['email']  = $cart_details['email'];
			$basic_details['f_name'] = $order->get_meta( '_billing_first_name' );
			$basic_details['l_name'] = $order->get_meta( '_billing_last_name' );
		} else {
			$cart_details = BWFAN_Model_Abandonedcarts::get( $abandoned_id );
			if ( empty( $cart_details ) ) {
				return $this->error_response( __( 'Abandoned cart data is missing', 'wp-marketing-automations' ) );
			}
			$cart_data = json_decode( $cart_details['checkout_data'], true );

			$basic_details['email']  = $cart_details['email'];
			$basic_details['f_name'] = isset( $cart_data['fields'] ) && isset( $cart_data['fields']['billing_first_name'] ) ? $cart_data['fields']['billing_first_name'] : '';
			$basic_details['l_name'] = isset( $cart_data['fields'] ) && isset( $cart_data['fields']['billing_last_name'] ) ? $cart_data['fields']['billing_last_name'] : '';
		}

		$arr = [ 'v1' => [], 'v2' => [] ];

		/** Automation v1 */
		$cart_tasks = [];

		/** Automation v2 */
		$cart_automations = [];

		if ( BWFAN_Common::is_automation_v1_active() ) {
			$cart_tasks = BWFAN_Recoverable_Carts::get_cart_tasks( $abandoned_id );
			$arr['v1']  = $cart_tasks;
		}

		$cart_email = $cart_details['email'];

		$table1 = $wpdb->prefix . 'bwfan_automation_contact';
		$table2 = $wpdb->prefix . 'bwfan_automation_complete_contact';

		$like_aid   = '%cart_abandoned_id":"' . $wpdb->esc_like( $abandoned_id ) . '"%';
		$like_email = '%email":"' . $wpdb->esc_like( $cart_email ) . '"%';

		$query1  = $wpdb->prepare( "SELECT * FROM $table1 WHERE `event` LIKE %s AND `data` LIKE %s AND data LIKE %s", 'ab_cart_abandoned', $like_aid, $like_email ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result1 = $wpdb->get_results( $query1, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prepared above; tables from $wpdb->prefix; LIKE values esc_like()d + %s

		$query2  = $wpdb->prepare( "SELECT * FROM $table2 WHERE `event` LIKE %s AND `data` LIKE %s AND data LIKE %s", 'ab_cart_abandoned', $like_aid, $like_email ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result2 = $wpdb->get_results( $query2, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prepared above; tables from $wpdb->prefix; LIKE values esc_like()d + %s

		if ( ! empty( $result1 ) ) {
			foreach ( $result1 as $a_contact ) {
				$event_slug = $a_contact['event'];
				$event_obj  = BWFAN_Core()->sources->get_event( $event_slug );
				$event_name = ! empty( $event_obj ) ? $event_obj->get_name() : '';

				$automation_data = BWFAN_Model_Automations_V2::get_automation( $a_contact['aid'] );
				unset( $a_contact['data'] );
				$a_contact['event']            = $event_name;
				$a_contact['automation_title'] = $automation_data['title'];
				$a_contact['e_time']           = isset( $a_contact['e_time'] ) ? gmdate( 'Y-m-d H:i:s', $a_contact['e_time'] ) : '';
				$a_contact['last_time']        = isset( $a_contact['last_time'] ) ? gmdate( 'Y-m-d H:i:s', $a_contact['last_time'] ) : '';
				$cart_automations[]            = array_merge( $a_contact, $basic_details );
			}
		}

		if ( ! empty( $result2 ) ) {
			foreach ( $result2 as $a_contact ) {
				$event_slug = $a_contact['event'];
				$event_obj  = BWFAN_Core()->sources->get_event( $event_slug );
				$event_name = ! empty( $event_obj ) ? $event_obj->get_name() : '';

				$automation_data = BWFAN_Model_Automations_V2::get_automation( $a_contact['aid'] );
				unset( $a_contact['data'] );
				$a_contact['last_time']        = isset( $a_contact['c_date'] ) ? $a_contact['c_date'] : '';
				$a_contact['event']            = $event_name;
				$a_contact['automation_title'] = $automation_data['title'];

				$cart_automations[] = array_merge( $a_contact, $basic_details );
			}
		}
		$msg = __( 'Automation tasks found', 'wp-marketing-automations' );
		if ( empty( $cart_tasks ) && empty( $cart_automations ) ) {
			$msg = __( 'No automation found for cart id: ', 'wp-marketing-automations' ) . $abandoned_id;
		}

		if ( ! empty( $cart_automations ) ) {
			$arr['v2'] = $cart_automations;
		}

		return $this->success_response( $arr, $msg );
	}

	/**
	 * GET /analytics/carts/
	 */
	public function get_cart_analytics() {
		$response['totals']    = $this->prepare_data_for_analytics();
		$response['intervals'] = $this->prepare_data_for_analytics( 'interval' );

		$this->response_code = 200;

		return $this->success_response( $response );
	}

	public function prepare_data_for_analytics( $is_interval = '' ) {
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-cart-analytics.php';

		$start_date        = ( isset( $this->args['after'] ) && '' !== $this->args['after'] ) ? $this->args['after'] : BWFAN_Cart_Analytics::default_date( WEEK_IN_SECONDS )->format( BWFAN_Cart_Analytics::$sql_datetime_format );
		$end_date          = ( isset( $this->args['before'] ) && '' !== $this->args['before'] ) ? $this->args['before'] : BWFAN_Cart_Analytics::default_date()->format( BWFAN_Cart_Analytics::$sql_datetime_format );
		$intervals_request = ( isset( $this->args['interval'] ) && '' !== $this->args['interval'] ) ? $this->args['interval'] : 'week';

		$captured_carts      = BWFAN_Cart_Analytics::get_captured_cart( $start_date, $end_date, $intervals_request, $is_interval );
		$recovered_carts     = BWFAN_Cart_Analytics::get_recovered_cart( $start_date, $end_date, $intervals_request, $is_interval );
		$lost_carts          = BWFAN_Cart_Analytics::get_lost_cart( $start_date, $end_date, $intervals_request, $is_interval );
		$captured_cart_count = isset( $captured_carts[0]['count'] ) ? $captured_carts[0]['count'] : 0;
		$captured_cart_count += isset( $lost_carts[0]['count'] ) ? $lost_carts[0]['count'] : 0;
		$recovery_percentage = BWFAN_Cart_Analytics::get_recovery_rate( $captured_cart_count, isset( $recovered_carts[0]['count'] ) ? $recovered_carts[0]['count'] : 0 );

		$result    = [];
		$intervals = [];
		/** when interval present */
		if ( ! empty( $is_interval ) ) {
			$intervals_all = BWFAN_Cart_Analytics::intervals_between( $start_date, $end_date, $intervals_request );
			foreach ( $intervals_all as $all_interval ) {
				$interval   = $all_interval['time_interval'];
				$start_date = $all_interval['start_date'];
				$end_date   = $all_interval['end_date'];

				$captured_cart       = BWFAN_Cart_Analytics::maybe_interval_exists( $captured_carts, 'time_interval', $interval );
				$recovered_cart      = BWFAN_Cart_Analytics::maybe_interval_exists( $recovered_carts, 'time_interval', $interval );
				$lost_cart           = BWFAN_Cart_Analytics::maybe_interval_exists( $lost_carts, 'time_interval', $interval );
				$recovery_percentage = BWFAN_Cart_Analytics::get_recovery_rate( isset( $captured_cart[0]['count'] ) ? $captured_cart[0]['count'] : 0, isset( $recovered_cart[0]['count'] ) ? $recovered_cart[0]['count'] : 0 );

				$intervals['interval']       = $interval;
				$intervals['start_date']     = $start_date;
				$intervals['date_start_gmt'] = BWFAN_Cart_Analytics::convert_local_datetime_to_gmt( $start_date )->format( BWFAN_Dashboards::$sql_datetime_format );
				$intervals['end_date']       = $end_date;
				$intervals['date_end_gmt']   = BWFAN_Cart_Analytics::convert_local_datetime_to_gmt( $end_date )->format( BWFAN_Dashboards::$sql_datetime_format );

				$intervals['subtotals'] = array(
					'recoverable_carts' => isset( $captured_cart[0]['count'] ) ? $captured_cart[0]['count'] : 0,
					'potential_revenue' => isset( $captured_cart[0]['sum'] ) ? $captured_cart[0]['sum'] : 0,
					'recovered_cart'    => isset( $recovered_cart[0]['count'] ) ? $recovered_cart[0]['count'] : 0,
					'recovered_revenue' => isset( $recovered_cart[0]['sum'] ) ? $recovered_cart[0]['sum'] : 0,
					'recovery_rate'     => $recovery_percentage,
					'lost_cart'         => isset( $lost_cart[0]['count'] ) ? $lost_cart[0]['count'] : 0,
				);
				$result[]               = $intervals;
			}

			return $result;
		}

		return [
			'recoverable_carts' => isset( $captured_carts[0]['count'] ) ? $captured_carts[0]['count'] : 0,
			'potential_revenue' => isset( $captured_carts[0]['sum'] ) ? $captured_carts[0]['sum'] : 0,
			'recovered_cart'    => isset( $recovered_carts[0]['count'] ) ? $recovered_carts[0]['count'] : 0,
			'recovered_revenue' => isset( $recovered_carts[0]['sum'] ) ? $recovered_carts[0]['sum'] : 0,
			'recovery_rate'     => $recovery_percentage,
			'lost_cart'         => isset( $lost_carts[0]['count'] ) ? $lost_carts[0]['count'] : 0,
		];
	}

	// ---- Recoverable cart helpers ----

	/**
	 * Get formatted fee data
	 *
	 * @param $data
	 *
	 * @return array|string
	 */
	public function get_formatted_fee_data( $data ) {
		$fee_data = [
			'data'  => [],
			'total' => 0,
		];
		$data     = maybe_unserialize( $data );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return $fee_data;
		}
		foreach ( $data as $fee ) {
			if ( ! isset( $fee->name ) || empty( $fee->total ) ) {
				continue;
			}

			$amount = floatval( $fee->total );
			if ( ! empty( $fee->tax ) ) {
				$amount += floatval( $fee->tax );
			}

			$fee_data['data'][ ! empty( $fee->name ) ? $fee->name : __( 'Fee', 'wp-marketing-automations' ) ] = $amount;
			$fee_data['total']                                                                                += $amount;
		}

		return $fee_data;
	}

	public function get_recovery_link( $item ) {
		$checkout_data = json_decode( $item->checkout_data, true );
		if ( empty( $checkout_data ) || ! is_array( $checkout_data ) || empty( $item->token ) ) {
			return '';
		}
		$lang     = isset( $checkout_data['lang'] ) ? $checkout_data['lang'] : '';
		$cart_url = BWFAN_Common::wc_get_cart_recovery_url( $item->token, '', $lang, $checkout_data );

		return $cart_url;
	}

	public function get_phone_recoverable( $item ) {
		$checkout_data = json_decode( $item->checkout_data, true );

		if ( is_array( $checkout_data ) && isset( $checkout_data['fields'] ) && is_array( $checkout_data['fields'] ) ) {
			if ( isset( $checkout_data['fields']['billing_phone'] ) && ! empty( $checkout_data['fields']['billing_phone'] ) ) {
				return $checkout_data['fields']['billing_phone'];
			}

			if ( isset( $checkout_data['fields']['shipping_phone'] ) && ! empty( $checkout_data['fields']['shipping_phone'] ) ) {
				return $checkout_data['fields']['shipping_phone'];
			}
		}

		return '';
	}

	public function get_preview_data_recoverable( $item, $fee_total = 0 ) {
		$data          = array();
		$billing       = array();
		$shipping      = array();
		$others        = array();
		$products      = array();
		$products_data = maybe_unserialize( $item->items );
		$nice_names    = BWFAN_Abandoned_Cart::get_woocommerce_default_checkout_nice_names();
		$checkout_data = json_decode( $item->checkout_data, true );

		if ( is_array( $checkout_data ) && count( $checkout_data ) > 0 ) {
			$fields = ( isset( $checkout_data['fields'] ) ) ? $checkout_data['fields'] : [];
			if ( class_exists( 'WooCommerce' ) ) {
				$available_gateways = WC()->payment_gateways->payment_gateways();
			}

			if ( ! empty( $fields ) ) {
				foreach ( $fields as $key => $value ) {
					if ( 'billing_phone' === $key ) {
						$others[ $nice_names[ $key ] ] = $value;
						continue;
					}
					if ( 'shipping_phone' === $key && isset( $fields['shipping_phone'] ) && ! empty( $fields['shipping_phone'] ) && empty( $fields['billing_phone'] ) ) {
						$others[ $nice_names[ $key ] ] = $value;
						continue;
					}
					if ( false !== strpos( $key, 'billing' ) && isset( $nice_names[ $key ] ) ) {
						$key             = str_replace( 'billing_', '', $key );
						$billing[ $key ] = $value;
						continue;
					}
					if ( false !== strpos( $key, 'shipping' ) && isset( $nice_names[ $key ] ) ) {
						$key              = str_replace( 'shipping_', '', $key );
						$shipping[ $key ] = $value;
						continue;
					}
					if ( 'bwfan_user_consent' === $key && isset( $nice_names[ $key ] ) ) {
						$others[ $nice_names[ $key ] ] = ( 1 === absint( $value ) ) ? __( 'Yes', 'wp-marketing-automations' ) : __( 'No', 'wp-marketing-automations' );
						continue;
					}
					if ( 'payment_method' === $key ) {
						if ( isset( $available_gateways[ $value ] ) && 'yes' === $available_gateways[ $value ]->enabled ) {
							$value = $available_gateways[ $value ]->method_title;
						}
						if ( isset( $nice_names[ $key ] ) ) {
							$others[ $nice_names[ $key ] ] = $value;
						}
						continue;
					}
					if ( isset( $nice_names[ $key ] ) ) {
						$others[ $nice_names[ $key ] ] = $value;
					}
				}
			}

			/** Remove WordPress page id in abandoned preview if WordPress page id is same as Aero page id. */
			if ( isset( $checkout_data['current_page_id'] ) && isset( $checkout_data['aerocheckout_page_id'] ) && $checkout_data['current_page_id'] === $checkout_data['aerocheckout_page_id'] ) {
				unset( $checkout_data['current_page_id'] );
			}

			foreach ( $checkout_data as $key => $value ) {
				if ( isset( $nice_names[ $key ] ) ) {
					$others[ $nice_names[ $key ] ] = $value;
				}
			}
		}
		$product_total = floatval( $fee_total );
		if ( is_array( $products_data ) ) {
			$hide_free_products = BWFAN_Common::hide_free_products_cart_order_items();
			foreach ( $products_data as $product ) {
				if (
					! is_array( $product ) ||
					( true === $hide_free_products && empty( $product['line_total'] ) ) ||
					( ! isset( $product['data'] ) || ! ( $product['data'] instanceof WC_Product ) ) ||
					empty( $product['quantity'] )
				) {
					continue;
				}

				$product_line_total = isset( $product['line_total'] ) ? floatval( $product['line_total'] ) : 0;
				$product_line_tax   = isset( $product['line_tax'] ) ? floatval( $product['line_tax'] ) : 0;
				$product_price      = $product_line_total + $product_line_tax;
				$product_price      = ! empty( $product_price ) ? number_format( ( $product_price ), 2, '.', '' ) : 0;
				$product_sub_total  = isset( $product['line_subtotal'] ) ? floatval( $product['line_subtotal'] ) : 0;
				$line_subtotal_tax  = isset( $product['line_subtotal_tax'] ) ? floatval( $product['line_subtotal_tax'] ) : 0;
				$product_sub_total  = $product_sub_total + $line_subtotal_tax;
				$product_sub_total  = ! empty( $product_sub_total ) ? number_format( ( $product_sub_total ), 2, '.', '' ) : 0;
				$product_discount   = $product_sub_total - $product_price;
				$product_price      = $product_price + $product_discount;
				$products[]         = array(
					'name'  => apply_filters( 'bwfan_get_formatted_cart_item_name', $product['data']->get_formatted_name(), $product ),
					'qty'   => $product['quantity'],
					'price' => $product_price,
				);

				$product_total = $product_total + $product_price;
			}
		}

		if ( isset( $billing['country'] ) && isset( $billing['state'] ) ) {
			$country_states = WC()->countries->get_states( $billing['country'] );
			if ( is_array( $country_states ) && isset( $country_states[ $billing['state'] ] ) ) {
				$billing['state'] = $country_states[ $billing['state'] ];
			}
		}

		add_filter( 'woocommerce_formatted_address_force_country_display', '__return_true' );

		$data['billing'] = WC()->countries->get_formatted_address( $billing );
		if ( isset( $shipping['country'] ) && isset( $shipping['state'] ) ) {
			$country_states = WC()->countries->get_states( $shipping['country'] );
			if ( is_array( $country_states ) && isset( $country_states[ $shipping['state'] ] ) ) {
				$shipping['state'] = $country_states[ $shipping['state'] ];
			}
		}

		$data['shipping'] = WC()->countries->get_formatted_address( $shipping );

		add_filter( 'woocommerce_formatted_address_force_country_display', '__return_false' );

		/** Visitor fingerprint — each field as its own row */
		if ( is_array( $checkout_data ) && isset( $checkout_data['_visitor_fingerprint'] ) && is_array( $checkout_data['_visitor_fingerprint'] ) ) {
			$data['user_info'] = BWFAN_Common::add_visitor_info_to_others( $checkout_data['_visitor_fingerprint'] );
		}

		$data['others']   = $others;
		$data['products'] = $products;
		$coupon_data      = maybe_unserialize( $item->coupons );
		$data['currency'] = get_woocommerce_currency_symbol( $item->currency );
		$data['discount'] = 0;

		if ( is_array( $coupon_data ) && 0 !== count( $coupon_data ) ) {
			foreach ( $coupon_data as $key => $coupon ) {
				if ( ! is_array( $coupon ) ) {
					continue;
				}
				$data['discount'] += isset( $coupon['discount_incl_tax'] ) ? floatval( $coupon['discount_incl_tax'] ) : 0;
			}
		}

		$product_total              = $product_total - floatval( $data['discount'] );
		$data['total']              = round( $product_total, 2 );
		$shipping_total_value       = ! empty( $item->shipping_total ) ? floatval( $item->shipping_total ) : 0;
		$shipping_tax_value         = ! empty( $item->shipping_tax_total ) ? floatval( $item->shipping_tax_total ) : 0;
		$shipping_total             = $shipping_total_value + $shipping_tax_value;
		$data['total']              = $data['total'] + $shipping_total;
		$data['total']              = ! empty( $data['total'] ) ? number_format( $data['total'], 2, '.', '' ) : 0;
		$data['shipping_total']     = ! empty( $shipping_total ) ? number_format( $shipping_total, 2, '.', '' ) : 0;
		$data['shipping_tax_total'] = ! empty( $shipping_tax_value ) ? number_format( $shipping_tax_value, 2, '.', '' ) : 0;

		/** Visitor fingerprint for analysis */
		if ( is_array( $checkout_data ) && isset( $checkout_data['_visitor_fingerprint'] ) && is_array( $checkout_data['_visitor_fingerprint'] ) ) {
			$data['visitor_info'] = $checkout_data['_visitor_fingerprint'];
		}

		return $data;
	}

	public function get_items_recoverable( $item ) {
		return BWFAN_Common::cart_item_names( $item->items, BWFAN_Common::hide_free_products_cart_order_items() );
	}

	function get_order_name_recoverable( $item ) {
		if ( 0 === intval( $item->order_id ) ) {
			return '';
		}

		$obj    = wc_get_order( $item->order_id );
		$buyer  = '';
		$output = '';

		if ( ! $obj instanceof WC_Order ) {
			return $output;
		}

		if ( $obj->get_billing_first_name() || $obj->get_billing_last_name() ) {
			/* translators: 1: first name 2: last name */
			$buyer = trim( sprintf( '%1$s %2$s', $obj->get_billing_first_name(), $obj->get_billing_last_name() ) );
		} elseif ( $obj->get_billing_company() ) {
			$buyer = trim( $obj->get_billing_company() );
		} elseif ( $obj->get_customer_id() ) {
			$user  = get_user_by( 'id', $obj->get_customer_id() );
			$buyer = ucwords( $user->display_name );
		}

		return apply_filters( 'woocommerce_admin_order_buyer_name', $buyer, $obj );
	}

	function get_order_id_recoverable( $item ) {
		if ( 0 === intval( $item->order_id ) ) {
			return '';
		}

		$obj = wc_get_order( $item->order_id );
		if ( $obj instanceof WC_Order ) {
			return $item->order_id;
		}

		return '';
	}

	public function get_user_display_name_recoverable( $item ) {
		if ( empty( $item->user_id ) ) {
			return '';
		}
		$user = get_user_by( 'id', absint( $item->user_id ) );

		return $user instanceof WP_User ? $user->display_name : '';
	}

	// ---- Recovered cart helpers ----

	/**
	 * @param $order WC_Order
	 *
	 * @return array
	 */
	public function get_preview_recovered( $order ) {
		$data        = array();
		$products    = array();
		$order_items = $order->get_items();
		foreach ( $order_items as $product ) {
			$product_data = $product->get_data();
			$products[] = array(
				'name'  => apply_filters( 'bwfan_get_formatted_cart_item_name', $product_data['name'], $product_data ),
				'qty'   => $product->get_quantity(),
				'price' => number_format( $order->get_line_subtotal( $product ), 2, '.', '' ),
			);
		}
		$data['order_id'] = $order->get_id();
		$data['products'] = $products;
		$data['billing']  = $order->get_formatted_billing_address();
		$data['shipping'] = $order->get_formatted_shipping_address();
		$data['discount'] = $order->get_total_discount();
		$data['total']    = $order->get_total();

		return $data;
	}

	/**
	 * @param $order WC_Order
	 *
	 * @return array
	 */
	public function get_items_recovered( $order ) {
		$names = [];
		foreach ( $order->get_items() as $value ) {
			if ( ! $value instanceof WC_Order_Item ) {
				continue;
			}

			$product_name = $value->get_name();
			$product_id   = $value->get_product_id();
			if ( $value->is_type( 'variable' ) ) {
				$product_id = $value->get_variation_id();
			}

			$names[ $product_id ] = wp_strip_all_tags( $product_name );
		}

		return $names;
	}

	/**
	 * @param $contact_id
	 * @param $order_id
	 *
	 * @return string[]
	 */
	public function get_name( $contact_id, $order_id ) {
		$data = array( 'f_name' => '', 'l_name' => '' );
		if ( ! empty( $contact_id ) ) {
			$contact_array  = new WooFunnels_Contact( '', '', '', $contact_id );
			$data['f_name'] = $contact_array->get_f_name();
			$data['l_name'] = $contact_array->get_l_name();

			return $data;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return $data;
		}

		$data['f_name'] = $order->get_billing_first_name();
		$data['l_name'] = $order->get_billing_last_name();

		return $data;
	}

	/**
	 * @param $order WC_Order
	 *
	 * @return mixed|string|void
	 */
	function get_order_name_recovered( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$buyer = '';
		if ( $order->get_billing_first_name() || $order->get_billing_last_name() ) {
			/* translators: 1: first name 2: last name */
			$buyer = trim( sprintf( '%1$s %2$s', $order->get_billing_first_name(), $order->get_billing_last_name() ) );
		} elseif ( $order->get_billing_company() ) {
			$buyer = trim( $order->get_billing_company() );
		} elseif ( $order->get_customer_id() ) {
			$user  = get_user_by( 'id', $order->get_customer_id() );
			$buyer = ucwords( $user->display_name );
		}

		return apply_filters( 'woocommerce_admin_order_buyer_name', $buyer, $order );
	}

	/**
	 * @param $order WC_Order
	 *
	 * @return mixed|string|void
	 */
	public function get_full_name_recovered( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$buyer = '';
		if ( $order->get_billing_first_name() || $order->get_billing_last_name() ) {
			/* translators: 1: first name 2: last name */
			$buyer = trim( sprintf( '%1$s %2$s', $order->get_billing_first_name(), $order->get_billing_last_name() ) );
		} elseif ( $order->get_billing_company() ) {
			$buyer = trim( $order->get_billing_company() );
		} elseif ( $order->get_customer_id() ) {
			$user  = get_user_by( 'id', $order->get_customer_id() );
			$buyer = ucwords( $user->display_name );
		}

		return apply_filters( 'woocommerce_admin_order_buyer_name', $buyer, $order );
	}

	/**
	 * @param $order WC_Order
	 *
	 * @return mixed
	 */
	public function get_email_recovered( $order ) {
		return $order->get_billing_email();
	}

	/**
	 * @param $order WC_Order
	 *
	 * @return string
	 */
	public function get_user_display_name_recovered( $order ) {
		if ( empty( $order->get_customer_id() ) ) {
			return '';
		}

		$user = get_user_by( 'id', absint( $order->get_customer_id() ) );

		return $user instanceof WP_User ? $user->display_name : '';
	}

	// ---- Lost cart helpers ----

	public function get_phone_lost( $item ) {
		$checkout_data = json_decode( $item->checkout_data, true );

		return ( is_array( $checkout_data ) && isset( $checkout_data['fields'] ) && is_array( $checkout_data['fields'] ) && isset( $checkout_data['fields']['billing_phone'] ) && ! empty( $checkout_data['fields']['billing_phone'] ) ) ? $checkout_data['fields']['billing_phone'] : '';
	}

	public function get_preview_data_lost( $item, $fee_total = 0 ) {
		$data          = array();
		$billing       = array();
		$shipping      = array();
		$others        = array();
		$products      = array();
		$products_data = maybe_unserialize( $item->items );
		$nice_names    = BWFAN_Abandoned_Cart::get_woocommerce_default_checkout_nice_names();
		$checkout_data = json_decode( $item->checkout_data, true );

		if ( is_array( $checkout_data ) && count( $checkout_data ) > 0 ) {
			$fields             = ( isset( $checkout_data['fields'] ) ) ? $checkout_data['fields'] : [];
			$available_gateways = WC()->payment_gateways->payment_gateways();

			if ( ! empty( $fields ) ) {
				foreach ( $fields as $key => $value ) {
					if ( 'billing_phone' === $key ) {
						$others[ $nice_names[ $key ] ] = $value;
						continue;
					}
					if ( false !== strpos( $key, 'billing' ) && isset( $nice_names[ $key ] ) ) {
						$key             = str_replace( 'billing_', '', $key );
						$billing[ $key ] = $value;
						continue;
					}
					if ( false !== strpos( $key, 'shipping' ) && isset( $nice_names[ $key ] ) ) {
						$key              = str_replace( 'shipping_', '', $key );
						$shipping[ $key ] = $value;
						continue;
					}
					if ( 'bwfan_user_consent' === $key && isset( $nice_names[ $key ] ) ) {
						$others[ $nice_names[ $key ] ] = ( 1 === absint( $value ) ) ? __( 'Yes', 'wp-marketing-automations' ) : __( 'No', 'wp-marketing-automations' );
						continue;
					}
					if ( 'payment_method' === $key ) {
						if ( isset( $available_gateways[ $value ] ) && 'yes' === $available_gateways[ $value ]->enabled ) {
							$value = $available_gateways[ $value ]->method_title;
						}
						if ( isset( $nice_names[ $key ] ) ) {
							$others[ $nice_names[ $key ] ] = $value;
						}
						continue;
					}
					if ( isset( $nice_names[ $key ] ) ) {
						$others[ $nice_names[ $key ] ] = $value;
					}
				}
			}

			/** Remove WordPress page id in abandoned preview if WordPress page id is same as Aero page id. */
			if ( isset( $checkout_data['current_page_id'] ) && isset( $checkout_data['aerocheckout_page_id'] ) && $checkout_data['current_page_id'] === $checkout_data['aerocheckout_page_id'] ) {
				unset( $checkout_data['current_page_id'] );
			}

			foreach ( $checkout_data as $key => $value ) {
				if ( isset( $nice_names[ $key ] ) ) {
					$others[ $nice_names[ $key ] ] = $value;
				}
			}
		}
		$product_total = floatval( $fee_total );
		if ( is_array( $products_data ) ) {
			$hide_free_products = BWFAN_Common::hide_free_products_cart_order_items();
			foreach ( $products_data as $product ) {
				if ( true === $hide_free_products && empty( $product['line_total'] ) ) {
					continue;
				}
				if ( ! $product['data'] instanceof WC_Product ) {
					continue;
				}
				$products[]    = array(
					'name'  => $product['data']->get_formatted_name(),
					'qty'   => $product['quantity'],
					'price' => number_format( floatval( $product['line_subtotal'] ), 2, '.', '' ),
				);
				$product_total = $product_total + floatval( $product['line_subtotal'] );
			}
		}

		if ( isset( $billing['country'] ) && isset( $billing['state'] ) ) {
			$country_states = WC()->countries->get_states( $billing['country'] );
			if ( is_array( $country_states ) && isset( $country_states[ $billing['state'] ] ) ) {
				$billing['state'] = $country_states[ $billing['state'] ];
			}
		}

		add_filter( 'woocommerce_formatted_address_force_country_display', '__return_true' );

		$data['billing'] = WC()->countries->get_formatted_address( $billing );
		if ( isset( $shipping['country'] ) && isset( $shipping['state'] ) ) {
			$country_states = WC()->countries->get_states( $shipping['country'] );
			if ( is_array( $country_states ) && isset( $country_states[ $shipping['state'] ] ) ) {
				$shipping['state'] = $country_states[ $shipping['state'] ];
			}
		}

		$data['shipping'] = WC()->countries->get_formatted_address( $shipping );

		add_filter( 'woocommerce_formatted_address_force_country_display', '__return_false' );

		/** Visitor fingerprint — each field as its own row */
		if ( is_array( $checkout_data ) && isset( $checkout_data['_visitor_fingerprint'] ) && is_array( $checkout_data['_visitor_fingerprint'] ) ) {
			$data['user_info'] = BWFAN_Common::add_visitor_info_to_others( $checkout_data['_visitor_fingerprint'] );
		}

		$data['others']   = $others;
		$data['products'] = $products;
		$coupon_data      = maybe_unserialize( $item->coupons );
		$data['currency'] = get_woocommerce_currency_symbol( $item->currency );
		$data['discount'] = 0;

		if ( is_array( $coupon_data ) && 0 !== count( $coupon_data ) ) {
			foreach ( $coupon_data as $key => $coupon ) {
				$data['discount'] += isset( $coupon['discount_incl_tax'] ) ? floatval( $coupon['discount_incl_tax'] ) : 0;
			}
		}
		$data['total']          = $product_total - floatval( $data['discount'] );
		$shipping_total_value   = ! empty( $item->shipping_total ) ? floatval( $item->shipping_total ) : 0;
		$data['total']          = $data['total'] + $shipping_total_value;
		$data['total']          = ! empty( $data['total'] ) ? number_format( $data['total'], 2, '.', '' ) : 0;
		$data['shipping_total'] = ! empty( $item->shipping_total ) ? number_format( floatval( $item->shipping_total ), 2, '.', '' ) : 0;

		/** Visitor fingerprint for analysis */
		if ( is_array( $checkout_data ) && isset( $checkout_data['_visitor_fingerprint'] ) && is_array( $checkout_data['_visitor_fingerprint'] ) ) {
			$data['visitor_info'] = $checkout_data['_visitor_fingerprint'];
		}

		return $data;
	}

	public function get_items_lost( $item ) {
		$items = maybe_unserialize( $item->items );
		if ( empty( $items ) ) {
			return '';
		}

		$hide_free_products = BWFAN_Common::hide_free_products_cart_order_items();
		$names              = [];
		foreach ( $items as $value ) {
			if ( true === $hide_free_products && empty( $value['line_total'] ) ) {
				continue;
			}
			if ( ! $value['data'] instanceof WC_Product ) {
				continue;
			}
			$names[ $value['data']->get_id() ] = wp_strip_all_tags( $value['data']->get_name() );
		}

		return $names;
	}

	function get_order_name_lost( $item ) {
		if ( 0 === intval( $item->order_id ) ) {
			return '';
		}

		$obj    = wc_get_order( $item->order_id );
		$buyer  = '';
		$output = '';

		if ( ! $obj instanceof WC_Order ) {
			return $output;
		}

		if ( $obj->get_billing_first_name() || $obj->get_billing_last_name() ) {
			/* translators: 1: first name 2: last name */
			$buyer = trim( sprintf( _x( '%1$s %2$s', 'full name', 'woocommerce' ), $obj->get_billing_first_name(), $obj->get_billing_last_name() ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		} elseif ( $obj->get_billing_company() ) {
			$buyer = trim( $obj->get_billing_company() );
		} elseif ( $obj->get_customer_id() ) {
			$user  = get_user_by( 'id', $obj->get_customer_id() );
			$buyer = ucwords( $user->display_name );
		}

		return apply_filters( 'woocommerce_admin_order_buyer_name', $buyer, $obj );
	}

	function get_order_id_lost( $item ) {
		if ( 0 === intval( $item->order_id ) ) {
			return '';
		}

		$obj = wc_get_order( $item->order_id );
		if ( $obj instanceof WC_Order ) {
			return $item->order_id;
		}

		return '';
	}

	public function get_user_display_name_lost( $item ) {
		if ( empty( $item->user_id ) ) {
			return '';
		}
		$user = get_user_by( 'id', absint( $item->user_id ) );

		return $user instanceof WP_User ? $user->display_name : '';
	}

	public function get_result_total_count() {
		return $this->total_count;
	}

	public function get_result_count_data() {
		return $this->count_data;
	}
}

BWFAN_API_Carts_Controller::get_instance();
