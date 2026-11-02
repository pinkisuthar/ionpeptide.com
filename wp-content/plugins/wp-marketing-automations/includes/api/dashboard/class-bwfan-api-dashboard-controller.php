<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Dashboard_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $total_count = 0;
	public $contact;

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
		return array();
	}

	protected function register_routes() {
		// GET /dashboard
		$this->add_route( '/dashboard', array(
			array( 'GET', 'get_dashboard_data' ),
		) );

		// GET /dashboard/autonami-analytics
		$this->add_route( '/dashboard/autonami-analytics', array(
			array( 'GET', 'get_autonami_analytics' ),
		) );

		// GET /dashboard/recent-contacts-abandoned
		$this->add_route( '/dashboard/recent-contacts-abandoned', array(
			array( 'GET', 'get_recent_abandoned' ),
		) );

		// GET /dashboard/recent-contacts-unsubscribe
		$this->add_route( '/dashboard/recent-contacts-unsubscribe', array(
			array( 'GET', 'get_recent_contacts_unsubscribe' ),
		) );

		// GET /dashboard/recent-recovered
		$this->add_route( '/dashboard/recent-recovered', array(
			array( 'GET', 'get_recent_recovered' ),
		) );
	}

	/**
	 * GET /dashboard
	 */
	public function get_dashboard_data() {
		$response            = $this->prepare_dashboard_response();
		$this->response_code = 200;

		return $this->success_response( $response );
	}

	/**
	 * @return array
	 */
	public function prepare_dashboard_response() {
		$force    = filter_input( INPUT_GET, 'force' );
		$force    = ( 'false' === $force ) ? false : true;
		$new_data = [];

		/** Check if worker call is late */
		$last_run = bwf_options_get( 'fk_core_worker_let' );
		if ( '' !== $last_run && ( ( time() - $last_run ) > BWFAN_Common::get_worker_delay_timestamp() ) ) {
			/** Worker is running late */
			$new_data['worker_delayed'] = time() - $last_run;
		}

		/** Check basic worker last run time and status code check */
		$resp = BWFAN_Common::validate_core_worker( $force, $last_run );
		if ( isset( $resp['response_code'] ) ) {
			$new_data['response_code'] = $resp['response_code'];
		}

		/** Dashboard call */
		$lite_key = 'bwfan_dashboard_report_lite';
		$pro_key  = 'bwfan_dashboard_report_pro';
		$exp      = BWFAN_Common::get_admin_analytics_cache_lifespan();

		if ( 'false' === $force ) {
			/** Check for cached data */
			if ( ! bwfan_is_autonami_pro_active() ) {
				/** Lite version active */
				$data = get_transient( $lite_key );
				if ( ! empty( $data ) ) {
					return array_merge( $data, $new_data );
				}
			} else {
				/** Pro version active */
				$data = get_transient( $pro_key );
				if ( ! empty( $data ) ) {
					return array_merge( $data, $new_data );
				}
			}
		}

		$recovered_carts  = [];
		$recent_abandoned = [];
		$lost_carts       = [];

		if ( function_exists( 'bwfan_is_woocommerce_active' ) && bwfan_is_woocommerce_active() ) {
			$recovered_carts  = BWFAN_Recoverable_Carts::get_recovered_carts( '', 0, 5 );
			$recovered_carts  = isset( $recovered_carts['items'] ) ? $this->get_recovered( $recovered_carts['items'] ) : [];
			$recent_abandoned = BWFAN_Automations::get_recent_abandoned();
			$lost_carts       = BWFAN_Automations::get_recent_abandoned( 2 );
		}

		$unsubsribers      = BWFAN_Dashboards::get_recent_unsubsribers();
		$unsubsribers      = array_map( function ( $unsubscribe ) {
			$unsubscribe['type'] = 'unsubscribe';

			return $unsubscribe;
		}, $unsubsribers );
		$new_contacts      = BWFAN_Dashboards::get_recent_contacts();
		$new_contacts      = array_map( function ( $contact ) {
			$contact['type'] = 'contact';

			return $contact;
		}, $new_contacts );
		$recent_activities = array_merge( $new_contacts, $unsubsribers );
		$recovered_carts   = array_map( function ( $data ) {
			$data['type'] = 1;

			return $data;
		}, ( array ) $recovered_carts );
		$recent_abandoned  = array_map( function ( $data ) {
			$data['type'] = 2;

			return $data;
		}, ( array ) $recent_abandoned );
		$lost_carts        = array_map( function ( $data ) {
			$data['type'] = 3;

			return $data;
		}, ( array ) $lost_carts );

		$carts = array_merge( $recovered_carts, $recent_abandoned, $lost_carts );
		uasort( $carts, function ( $a, $b ) {
			return $a['created_on'] >= $b['created_on'] ? - 1 : 1;
		} );
		$carts = array_values( $carts );
		$carts = count( $carts ) > 5 ? array_slice( $carts, 0, 5 ) : $carts;

		$data = [
			'carts' => $carts,
		];
		$data = array_merge( $data, $new_data );

		$additional_info = [
			'grab_totals' => true,
			'only_count'  => true
		];

		$contacts_count   = BWFCRM_Contact::get_contacts( '', 0, 0, [], $additional_info );
		$get_total_sents  = BWFAN_Dashboards::get_total_engagement_sents( '', '', '', '' );
		$get_total_orders = BWFAN_Dashboards::get_total_orders( '', '', '', '' );

		$analytics_data = [
			'total_contact' => ! isset( $contacts_count['total_count'] ) ? 0 : $contacts_count['total_count'],
			'email_sents'   => ! isset( $get_total_sents[0]['email_sents'] ) ? 0 : $get_total_sents[0]['email_sents'],
			'sms_sent'      => ! isset( $get_total_sents[0]['sms_sent'] ) ? 0 : $get_total_sents[0]['sms_sent'],
			'total_orders'  => ! isset( $get_total_orders[0]['total_orders'] ) ? 0 : $get_total_orders[0]['total_orders'],
			'total_revenue' => ! isset( $get_total_orders[0]['total_revenue'] ) ? 0 : $get_total_orders[0]['total_revenue'],
		];

		$top_automations = BWFCRM_Automations::get_top_automations();

		if ( ! bwfan_is_autonami_pro_active() ) {
			uasort( $recent_activities, function ( $a, $b ) {
				return $a['creation_date'] >= $b['creation_date'] ? - 1 : 1;
			} );
			$recent_activities = array_values( $recent_activities );
			$recent_activities = count( $recent_activities ) > 10 ? array_slice( $recent_activities, 0, 9 ) : $recent_activities;
			$data              = array_merge( $data, [
				'pro_active'        => false,
				'analytics_data'    => $analytics_data,
				'top_automations'   => $top_automations['top_automations'],
				'top_broadcast'     => [],
				'recent_activities' => $recent_activities,
			] );
			set_transient( $lite_key, $data, $exp );
			BWFAN_Common::validate_scheduled_recurring_actions();

			return $data;
		}

		$top_broadcast     = BWFCRM_Campaigns::get_top_broadcast();
		$top_broadcast_sms = BWFCRM_Campaigns::get_top_broadcast( 2 );

		$recent_conversions = BWFAN_Dashboards::get_recent_conversions();
		$recent_conversions = array_map( function ( $conversion ) {
			$conversion['type'] = 'conversion';

			return $conversion;
		}, $recent_conversions );

		$recent_activities = array_merge( $recent_activities, $recent_conversions );
		uasort( $recent_activities, function ( $a, $b ) {
			return $a['creation_date'] >= $b['creation_date'] ? - 1 : 1;
		} );
		$recent_activities = array_values( $recent_activities );
		$recent_activities = count( $recent_activities ) > 10 ? array_slice( $recent_activities, 0, 9 ) : $recent_activities;

		$data = array_merge( $data, [
			'pro_active'        => true,
			'analytics_data'    => $analytics_data,
			'top_automations'   => $top_automations['top_automations'],
			'top_broadcast'     => $top_broadcast['top_broadcast'],
			'top_broadcast_sms' => $top_broadcast_sms['top_broadcast'],
			'recent_activities' => $recent_activities,
		] );
		set_transient( $pro_key, $data, $exp );
		BWFAN_Common::validate_scheduled_recurring_actions();

		return $data;
	}

	public function get_recovered( $recovered_carts ) {
		if ( empty( $recovered_carts ) ) {
			return [];
		}
		$result = [];
		foreach ( $recovered_carts as $item ) {
			if ( ! $item instanceof WC_Order ) {
				continue;
			}
			$order_date = $item->get_date_created();
			$result[]   = [
				'order_id'   => $item->get_id(),
				'f_name'     => $item->get_billing_first_name(),
				'l_name'     => $item->get_billing_last_name(),
				'email'      => $item->get_billing_email(),
				'created_on' => ( $order_date instanceof WC_DateTime ) ? ( $order_date->date( 'Y-m-d H:i:s' ) ) : '',
				'revenue'    => $item->get_total(),
				'currency'   => BWFAN_Automations::get_currency( $item->get_currency() ),
				'id'         => $item->get_meta( '_woofunnel_cid' ),
			];
		}

		return $result;
	}

	public function get_full_name_dashboard( $item ) {
		if ( ! $item instanceof WC_Order ) {
			return '';
		}
		$buyer = '';
		if ( $item->get_billing_first_name() || $item->get_billing_last_name() ) {
			/* translators: 1: first name 2: last name */
			$buyer = trim( sprintf( _x( '%1$s %2$s', 'full name', 'woocommerce' ), $item->get_billing_first_name(), $item->get_billing_last_name() ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		} elseif ( $item->get_billing_company() ) {
			$buyer = trim( $item->get_billing_company() );
		} elseif ( $item->get_customer_id() ) {
			$user  = get_user_by( 'id', $item->get_customer_id() );
			$buyer = ucwords( $user->display_name );
		}

		return apply_filters( 'woocommerce_admin_order_buyer_name', $buyer, $item );
	}

	public function get_items_dashboard( $item ) {
		$names = [];
		foreach ( $item->get_items() as $value ) {
			$names[] = $value->get_name();
		}

		return $names;
	}

	/**
	 * GET /dashboard/autonami-analytics
	 */
	public function get_autonami_analytics() {
		$response['totals'] = $this->prepare_analytics_response();

		$this->response_code = 200;

		return $this->success_response( $response );
	}

	/**
	 * @return array
	 */
	public function prepare_analytics_response() {
		$get_total_contacts = BWFAN_Dashboards::get_total_contacts();

		$get_total_sents = BWFAN_Dashboards::get_total_engagement_sents( '', '', '', '' );

		$get_total_orders = BWFAN_Dashboards::get_total_orders( '', '', '', '' );

		$result = [
			'total_contact' => intval( $get_total_contacts ),
			'email_sents'   => ! isset( $get_total_sents[0]['email_sents'] ) ? 0 : $get_total_sents[0]['email_sents'],
			'sms_sent'      => ! isset( $get_total_sents[0]['sms_sent'] ) ? 0 : $get_total_sents[0]['sms_sent'],
			'total_orders'  => ! isset( $get_total_orders[0]['total_orders'] ) ? 0 : $get_total_orders[0]['total_orders'],
			'total_revenue' => ! isset( $get_total_orders[0]['total_revenue'] ) ? 0 : $get_total_orders[0]['total_revenue'],
		];

		return $result;
	}

	/**
	 * GET /dashboard/recent-contacts-abandoned
	 */
	public function get_recent_abandoned() {
		global $wpdb;
		$abandoned_table = $wpdb->prefix . 'bwfan_abandonedcarts';
		$contact_table   = $wpdb->prefix . 'bwf_contact';

		$query = "SELECT abandon.email,abandon.checkout_data, abandon.total as revenue, COALESCE(con.id, 0) as id, COALESCE(con.f_name, '') as f_name, COALESCE(con.l_name, '') as l_name from $abandoned_table as abandon LEFT JOIN $contact_table as con ON abandon.email = con.email ORDER BY abandon.ID DESC LIMIT 5 OFFSET 0";

		$abandoned = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL

		if ( ! is_array( $abandoned ) ) {
			$this->response_code = 500;

			return $this->error_response( is_string( $abandoned ) ? $abandoned : __( 'Unknown error', 'wp-marketing-automations' ) );
		}

		$this->response_code = 200;
		$this->total_count   = count( $abandoned );

		return $this->success_response( $abandoned );
	}

	/**
	 * GET /dashboard/recent-contacts-unsubscribe
	 */
	public function get_recent_contacts_unsubscribe() {
		global $wpdb;

		$contact_table     = $wpdb->prefix . 'bwf_contact';
		$unsubscribe_table = $wpdb->prefix . 'bwfan_message_unsubscribe';

		$query = "SELECT sub.recipient as email, COALESCE(con.id, 0) as id, COALESCE(con.f_name, '') as f_name, COALESCE(con.l_name, '') as l_name, sub.c_date from $unsubscribe_table as sub LEFT JOIN $contact_table as con ON sub.recipient = con.email ORDER BY sub.ID DESC LIMIT 5 OFFSET 0";

		$unsubscribes = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; fully hardcoded SQL

		$contacts['contacts'] = $unsubscribes;

		if ( ! is_array( $contacts ) ) {
			$this->response_code = 500;

			return $this->error_response( is_string( $contacts ) ? $contacts : __( 'Unknown Error', 'wp-marketing-automations' ) );
		}

		if ( ! isset( $contacts['contacts'] ) || empty( $contacts['contacts'] ) ) {
			return $this->success_response( [] );
		}

		$this->response_code = 200;
		$this->total_count   = count( $contacts['contacts'] );

		return $this->success_response( $contacts['contacts'] );
	}

	/**
	 * GET /dashboard/recent-recovered
	 */
	public function get_recent_recovered() {
		$offset = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? $this->get_sanitized_arg( 'offset', 'absint' ) : 0;
		$limit  = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 5;

		$recovered_carts = BWFAN_Automations::get_recovered_carts( $offset, $limit );

		if ( empty( $recovered_carts ) ) {
			$this->response_code = 200;
			$this->total_count   = 0;

			return $this->success_response( [], __( 'Recovered carts found', 'wp-marketing-automations' ) );
		}

		$result = [];
		foreach ( $recovered_carts['items'] as $item ) {

			if ( ! $item instanceof WC_Order ) {
				continue;
			}
			$order_date = $item->get_date_created();
			$result[]   = [
				'order_id'          => $item->get_id(),
				'billing_full_name' => $this->get_full_name_recent_recovered( $item ),
				'email'             => $item->get_billing_email(),
				'phone'             => $item->get_billing_phone(),
				'date_created'      => ( $order_date instanceof WC_DateTime ) ? ( $order_date->date( 'Y-m-d H:i:s' ) ) : '',
				'items'             => $this->get_items_recent_recovered( $item ),
				'total'             => $item->get_total(),
				'actions'           => ''
			];
		}
		$this->response_code = 200;
		$this->total_count   = $recovered_carts['total_record'];


		return $this->success_response( $result, __( 'Recovered carts found', 'wp-marketing-automations' ) );
	}

	public function get_full_name_recent_recovered( $item ) {
		$buyer = '';

		if ( ! $item instanceof WC_Order ) {
			return '';
		}

		if ( $item->get_billing_first_name() || $item->get_billing_last_name() ) {
			/* translators: 1: first name 2: last name */
			$buyer = trim( sprintf( _x( '%1$s %2$s', 'full name', 'woocommerce' ), $item->get_billing_first_name(), $item->get_billing_last_name() ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		} elseif ( $item->get_billing_company() ) {
			$buyer = trim( $item->get_billing_company() );
		} elseif ( $item->get_customer_id() ) {
			$user  = get_user_by( 'id', $item->get_customer_id() );
			$buyer = ucwords( $user->display_name );
		}

		return apply_filters( 'woocommerce_admin_order_buyer_name', $buyer, $item );
	}

	public function get_email_recent_recovered( $item ) {
		return $item->get_billing_email();
	}

	public function get_items_recent_recovered( $item ) {
		$names = [];
		foreach ( $item->get_items() as $value ) {
			$names[] = $value->get_name();
		}

		return $names;
	}

	public function get_result_total_count() {
		return $this->total_count;
	}
}

BWFAN_API_Dashboard_Controller::get_instance();
