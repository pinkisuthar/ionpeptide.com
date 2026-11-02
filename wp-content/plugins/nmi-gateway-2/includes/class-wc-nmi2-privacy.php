<?php

if ( ! class_exists( 'WC_Abstract_Privacy' ) ) {
	return;
}

class WC_NMI2_Privacy extends WC_Abstract_Privacy {

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
		$this->name = __( 'NMI Gateway 2', 'wc-nmi2' );

		$this->add_exporter( 'wc-nmi2-order-data', __( 'WooCommerce NMI Gateway 2 Order Data', 'wc-nmi2' ), array( $this, 'order_data_exporter' ) );
		$this->add_eraser( 'wc-nmi2-order-data', __( 'WooCommerce NMI Gateway 2 Data', 'wc-nmi2' ), array( $this, 'order_data_eraser' ) );
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
				'title'       => __( 'Retain NMI Gateway 2 Data', 'wc-nmi2' ),
				'desc_tip'    => __( 'Retains NMI Gateway 2 data such as NMI customer ID, charge ID.', 'wc-nmi2' ),
				'id'          => 'woocommerce_gateway_nmi2_retention',
				'type'        => 'relative_date_selector',
				'placeholder' => __( 'N/A', 'wc-nmi2' ),
				'default'     => '',
				'autoload'    => false,
			),
		);

		array_splice( $settings, ( count( $settings ) - 1 ), 0, $insert_setting );

		return $settings;
	}

	/**
	 * Returns a list of orders that are using NMI2 payment method.
	 *
	 * @param string  $email_address
	 * @param int     $page
	 *
	 * @return array WP_Post
	 */
	protected function get_nmi2_orders( $email_address, $page ) {
		$user = get_user_by( 'email', $email_address );

		$order_query    = array(
			'payment_method' => array( 'nmi2' ),
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
		return wpautop( sprintf( __( 'By using this extension, you may be storing personal data or sharing data with an external service. <a href="%s" target="_blank">Learn more about how this works, including what you may want to include in your privacy policy.</a>', 'wc-nmi2' ), 'https://docs.woocommerce.com/document/privacy-payments/' ) );
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

		$orders = $this->get_nmi2_orders( $email_address, (int) $page );

		$done = true;

		if ( 0 < count( $orders ) ) {
			foreach ( $orders as $order ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_orders',
					'group_label' => __( 'Orders', 'wc-nmi2' ),
					'item_id'     => 'order-' . $order->get_id(),
					'data'        => array(
						array(
							'name'  => __( 'NMI Gateway 2 payment id', 'wc-nmi2' ),
							'value' => $order->get_meta( '_nmi2_charge_id' ),
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
		$orders = $this->get_nmi2_orders( $email_address, (int) $page );

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
		$nmi2_charge_id = $order->get_meta( '_nmi2_charge_id' );

		if ( ! $this->is_retention_expired( $order->get_date_created()->getTimestamp() ) ) {
			return array( false, true, array( sprintf( __( 'Order ID %d is less than set retention days. Personal data retained. (NMI Gateway 2)', 'wc-nmi2' ), $order->get_id() ) ) );
		}

		if ( empty( $nmi2_charge_id ) ) {
			return array( false, false, array() );
		}

		$order->delete_meta_data( '_nmi2_charge_id' );
		$order->save();

		return array( true, false, array( __( 'NMI Gateway 2 personal data erased.', 'wc-nmi2' ) ) );
	}

	/**
	 * Checks if create date is passed retention duration.
	 */
	public function is_retention_expired( $created_date ) {
		$retention  = wc_parse_relative_date_option( get_option( 'woocommerce_gateway_nmi2_retention' ) );
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
new WC_NMI2_Privacy();
