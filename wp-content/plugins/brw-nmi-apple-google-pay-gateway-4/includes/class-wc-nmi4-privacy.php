<?php

if ( ! class_exists( 'WC_Abstract_Privacy' ) ) {
	return;
}

class WC_NMI4_Privacy extends WC_Abstract_Privacy {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		add_action( 'init', array( $this, 'register_erasers_exporters' ) );
		add_filter( 'woocommerce_get_settings_account', array( $this, 'account_settings' ) );
	}

	/**
	 * Initial registration of privacy erasers and exporters.
	 */
	public function register_erasers_exporters() {
		$this->name = __( 'BRW NMI Apple / Google Pay Gateway', 'wc-nmi4' );

		$this->add_exporter( 'wc-nmi4-order-data', __( 'BRW NMI Apple / Google Pay Order Data', 'wc-nmi4' ), array( $this, 'order_data_exporter' ) );
		$this->add_eraser( 'wc-nmi4-order-data', __( 'BRW NMI Apple / Google Pay Data', 'wc-nmi4' ), array( $this, 'order_data_eraser' ) );
	}

	/**
	 * Add retention settings to account tab.
	 *
	 * @param array $settings
	 * @return array $settings Updated
	 */
	public function account_settings( $settings ) {
		$insert_setting = array(
			array(
				'title'       => __( 'Retain BRW NMI Apple / Google Pay Data', 'wc-nmi4' ),
				'desc_tip'    => __( 'Retains NMI transaction data such as gateway customer ID and charge ID.', 'wc-nmi4' ),
				'id'          => 'woocommerce_gateway_nmi4_retention',
				'type'        => 'relative_date_selector',
				'placeholder' => __( 'N/A', 'wc-nmi4' ),
				'default'     => '',
				'autoload'    => false,
			),
		);

		array_splice( $settings, ( count( $settings ) - 1 ), 0, $insert_setting );

		return $settings;
	}

	/**
	 * Returns a list of orders that are using NMI4 payment method.
	 *
	 * @param string  $email_address
	 * @param int     $page
	 *
	 * @return array WP_Post
	 */
	protected function get_nmi4_orders( $email_address, $page ) {
		$user = get_user_by( 'email', $email_address );

		$order_query    = array(
			'payment_method' => array( 'nmi4' ),
			'limit'          => 10,
			'page'           => $page,
		);

		if ( $user instanceof WP_User ) {
			$order_query['customer_id'] = (int) $user->ID;
		} else {
			$order_query['billing_email'] = $email_address;
		}

		return wc_get_orders( $order_query );
	}

	/**
	 * Gets the message of the privacy to display.
	 */
	public function get_privacy_message() {
		return wpautop( sprintf( __( 'By using this extension, you may be storing personal data or sharing data with an external service. <a href="%s" target="_blank">Learn more about how this works, including what you may want to include in your privacy policy.</a>', 'wc-nmi4' ), 'https://docs.woocommerce.com/document/privacy-payments/' ) );
	}

	/**
	 * Handle exporting data for Orders.
	 *
	 * @param string $email_address E-mail address to export.
	 * @param int    $page          Pagination of data.
	 *
	 * @return array
	 */
	public function order_data_exporter( $email_address, $page = 1 ) {
		$done           = false;
		$data_to_export = array();

		$orders = $this->get_nmi4_orders( $email_address, (int) $page );

		$done = true;

		if ( 0 < count( $orders ) ) {
			foreach ( $orders as $order ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_orders',
					'group_label' => __( 'Orders', 'wc-nmi4' ),
					'item_id'     => 'order-' . $order->get_id(),
					'data'        => array(
						array(
							'name'  => __( 'NMI payment ID', 'wc-nmi4' ),
							'value' => $order->get_meta( '_nmi4_charge_id' ),
						),
					),
				);
			}

			$done = 10 > count( $orders );
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Finds and erases order data by email address.
	 *
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of personal data in name value pairs
	 */
	public function order_data_eraser( $email_address, $page ) {
		$orders = $this->get_nmi4_orders( $email_address, (int) $page );

		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		foreach ( (array) $orders as $order ) {
			$order = wc_get_order( $order->get_id() );

			list( $removed, $retained, $msgs ) = $this->maybe_handle_order( $order );
			$items_removed  |= $removed;
			$items_retained |= $retained;
			$messages        = array_merge( $messages, $msgs );
		}

		$done = count( $orders ) < 10;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Handle eraser of data tied to Orders
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	protected function maybe_handle_order( $order ) {
		$order_id = $order->get_id();
		$nmi4_charge_id = $order->get_meta( '_nmi4_charge_id' );

		if ( ! $this->is_retention_expired( $order->get_date_created()->getTimestamp() ) ) {
			return array( false, true, array( sprintf( __( 'Order ID %d is less than set retention days. Personal data retained. (BRW NMI Apple / Google Pay)', 'wc-nmi4' ), $order->get_id() ) ) );
		}

		if ( empty( $nmi4_charge_id ) ) {
			return array( false, false, array() );
		}

		$order->delete_meta_data( '_nmi4_charge_id' );
		$order->save();

		return array( true, false, array( __( 'BRW NMI Apple / Google Pay personal data erased.', 'wc-nmi4' ) ) );
	}

	/**
	 * Checks if create date is passed retention duration.
	 */
	public function is_retention_expired( $created_date ) {
		$retention  = wc_parse_relative_date_option( get_option( 'woocommerce_gateway_nmi4_retention' ) );
		$is_expired = false;
		$time_span  = time() - strtotime( $created_date );

		if ( empty( $retention['number'] ) || empty( $created_date ) ) {
			return false;
		}

		switch ( $retention['unit'] ) {
			case 'days':
				$retention = (int) $retention['number'] * DAY_IN_SECONDS;
				if ( $time_span > $retention ) {
					$is_expired = true;
				}
				break;
			case 'weeks':
				$retention = (int) $retention['number'] * WEEK_IN_SECONDS;
				if ( $time_span > $retention ) {
					$is_expired = true;
				}
				break;
			case 'months':
				$retention = (int) $retention['number'] * MONTH_IN_SECONDS;
				if ( $time_span > $retention ) {
					$is_expired = true;
				}
				break;
			case 'years':
				$retention = (int) $retention['number'] * YEAR_IN_SECONDS;
				if ( $time_span > $retention ) {
					$is_expired = true;
				}
				break;
		}
		return $is_expired;
	}
}
new WC_NMI4_Privacy();
