<?php

if ( ! class_exists( 'WC_Abstract_Privacy' ) ) {
    return;
}

class WC_Authnet_Privacy extends WC_Abstract_Privacy {

    /**
     * Constructor
     */
	public function __construct() {
		parent::__construct();

		// Initialize data exporters and erasers.
		add_action( 'init', array( $this, 'register_erasers_exporters' ) );
		add_filter( 'woocommerce_get_settings_account', array( $this, 'account_settings' ) );
	}

	/**
	 * Initial registration of privacy erasers and exporters.
	 */
	public function register_erasers_exporters() {
		$this->name = __( 'Authorize.net', 'wc-authnet' );

		$this->add_exporter( 'wc-authnet-order-data', __( 'Secure Authorize.net Order Data', 'wc-authnet' ), array( $this, 'order_data_exporter' ) );
		$this->add_eraser( 'wc-authnet-order-data', __( 'Secure Authorize.net Data', 'wc-authnet' ), array( $this, 'order_data_eraser' ) );
	}

    /**
     * Add retention settings to account tab.
     *
     * @param array $settings
     * @return array $settings Updated
     */
    public function account_settings( $settings ) {
        $insert_setting = array( array(
            'title'       => __( 'Retain Authorize.net Data', 'wc-authnet' ),
            'desc_tip'    => __( 'Retains any Authorize.net data such as Authorize.net customer ID, charge ID.', 'wc-authnet' ),
            'id'          => 'woocommerce_gateway_authnet_retention',
            'type'        => 'relative_date_selector',
            'placeholder' => __( 'N/A', 'wc-authnet' ),
            'default'     => '',
            'autoload'    => false,
        ) );

        array_splice( $settings, ( count( $settings ) - 1 ), 0, $insert_setting );

		return $settings;
    }

    /**
     * Returns a list of orders that are using one of AuthNet's payment methods.
     *
     * @param string  $email_address
     * @param int     $page
     *
     * @return array WP_Post
     */
    protected function get_authnet_orders( $email_address, $page ) {
        $user = get_user_by( 'email', $email_address );

        $payment_method = array( 'authnet' );

        $order_query = array(
            'payment_method' => $payment_method,
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
     *
     */
    public function get_privacy_message() {
        return wpautop( sprintf( __( 'By using this extension, you may be storing personal data or sharing data with an external service. <a href="%s" target="_blank">Learn more about how this works, including what you may want to include in your privacy policy.</a>', 'wc-authnet' ), 'https://woocommerce.com/document/privacy-payments/' ) );
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
        $done 			= false;
        $data_to_export = array();

        $orders = $this->get_authnet_orders( $email_address, (int) $page );

        $done = true;

        if ( 0 < count( $orders ) ) {
            foreach ( $orders as $order ) {
                $order_data = array(
                    'group_id'    => 'woocommerce_orders',
                    'group_label' => __( 'Orders', 'wc-authnet' ),
                    'item_id'     => 'order-' . $order->get_id(),
                    'data'        => array( array(
                    'name'  => __( 'Authorize.net payment id', 'wc-authnet' ),
                    'value' => $order->get_meta( '_authnet_charge_id' ),
                ) ),
                );
                $data_to_export[] = $order_data;
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
        $orders = $this->get_authnet_orders( $email_address, (int) $page );

        $items_removed 	= false;
        $items_retained = false;
        $messages 		= array();

        foreach ( (array) $orders as $order ) {
            $order = wc_get_order( $order->get_id() );

            list( $removed, $retained, $msgs ) = $this->maybe_handle_order( $order );
            $items_removed |= $removed;
            $items_retained |= $retained;
            $messages = array_merge( $messages, $msgs );
        }

        // Tell core if we have more orders to work on still
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
        $order_id 			= $order->get_id();
        $authnet_charge_id 	= $order->get_meta( '_authnet_charge_id' );

        if ( ! $this->is_retention_expired( $order->get_date_created()->getTimestamp() ) ) {
            return array( false, true, array( sprintf( __( 'Order ID %d is less than set retention days. Personal data retained. (Authorize.net)', 'wc-authnet' ), $order->get_id() ) ) );
        }

        if ( empty( $authnet_charge_id ) ) {
            return array( false, false, array() );
        }

        $order->delete_meta_data( '_authnet_charge_id' );
        $order->save();

        return array( true, false, array( __( 'Authorize.net personal data erased.', 'wc-authnet' ) ) );
    }

    /**
     * Checks if create date is passed retention duration.
     *
     */
    public function is_retention_expired( $created_date ) {

        $retention 	= wc_parse_relative_date_option( get_option( 'woocommerce_gateway_authnet_retention' ) );
        $is_expired = false;
        $time_span 	= time() - strtotime( $created_date );

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

new WC_Authnet_Privacy();
