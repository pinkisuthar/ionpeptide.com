<?php
/**
 * SSTG Payments Gateway Class
 *
 * @package SSTG_Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_SSTG_Payments extends WC_Payment_Gateway {

    /**
     * @var bool
     */
    public bool $test_mode;

    /**
     * @var string
     */
    private string $api_secret;

    /**
     * @var string
     */
    private string $base_url;

    /**
     * @var bool
     */
    public bool $debug;

    /**
     * @var string
     */
    public string $callback_url;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'sstg_payments';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'SSTG Payments', 'sstg-payments' );
        $this->method_description = __( 'Accept payments via SSTG Payments for WooCommerce.', 'sstg-payments' );
        $this->supports           = array(
            'products',
            'refunds',
        );

        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->enabled      = $this->get_option( 'enabled' );
        $this->test_mode    = 'yes' === $this->get_option( 'test_mode' );
        $this->api_secret   = $this->get_option( 'api_secret' );
        $this->base_url     = 'https://request.sstgusa.com';
        $this->debug        = 'yes' === $this->get_option( 'debug' );
        $this->callback_url = add_query_arg( 'wc-api', 'sstg_payments_callback', home_url() );

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_sstg_payments_callback', array( $this, 'process_callback' ) );
    }

    /**
     * Initialize Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'      => array(
                'title'   => __( 'Enable/Disable', 'sstg-payments' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable SSTG Payments', 'sstg-payments' ),
                'default' => 'yes',
            ),
            'title'        => array(
                'title'       => __( 'Title', 'sstg-payments' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'sstg-payments' ),
                'default'     => __( 'Pay with Card', 'sstg-payments' ),
                'desc_tip'    => true,
            ),
            'description'  => array(
                'title'       => __( 'Customer Message', 'sstg-payments' ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'sstg-payments' ),
                'default'     => __( 'Pay securely using your credit or debit card.', 'sstg-payments' ),
                'desc_tip'    => true,
            ),
            'api_secret'   => array(
                'title'       => __( 'API Secret', 'sstg-payments' ),
                'type'        => 'password',
                'description' => __( 'Your SSTG Payments API Secret for Bearer authentication. Add Live / Test key as required.', 'sstg-payments' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'test_mode'    => array(
                'title'   => __( 'Test Mode', 'sstg-payments' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enables Test Mode even if you\'re using live secret key.', 'sstg-payments' ),
                'default' => 'yes',
            ),
            'debug'        => array(
                'title'       => __( 'Debug Log', 'sstg-payments' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging', 'sstg-payments' ),
                'default'     => 'no',
                'description' => __( 'Log SSTG Payments events inside <code>wp-content/wc-logs/</code>', 'sstg-payments' ),
            ),
        );
    }

    /**
     * Custom Payment Fields - Shows HPP iframe widget.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }
        wp_enqueue_script( 'sstg-payments-blocks-integration' );
        echo '<div id="sstg-payments-iframe-container"></div>';
        echo '<input type="hidden" name="sstg_payment_token" id="sstg_payment_token" value="" />';
        echo '<input type="hidden" name="sstg_hpp_token" id="sstg_hpp_token" value="" />';
        echo '<input type="hidden" name="sstg_transaction_id" id="sstg_transaction_id" value="" />';
        echo '<input type="hidden" name="sstg_order_first" id="sstg_order_first" value="0" />';
    }

    /**
     * Process the payment.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'sstg-payments' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Check if order is already paid
        if ( $order->is_paid() ) {
            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_order_received_url(),
            );
        }

        // Get payment data from POST
        $payment_token  = isset( $_POST['sstg_payment_token'] ) ? sanitize_text_field( $_POST['sstg_payment_token'] ) : '';
        $hpp_token      = isset( $_POST['sstg_hpp_token'] ) ? sanitize_text_field( $_POST['sstg_hpp_token'] ) : '';
        $transaction_id = isset( $_POST['sstg_transaction_id'] ) ? sanitize_text_field( $_POST['sstg_transaction_id'] ) : '';

        // ORDER-FIRST FLOW: No tokens yet = first pass from classic checkout.
        // Create invoice using the order (not cart data) and return widget URL.
        if ( empty( $hpp_token ) && empty( $payment_token ) && empty( $transaction_id ) ) {
            $is_order_first = isset( $_POST['sstg_order_first'] ) && $_POST['sstg_order_first'] === '1';

            if ( $is_order_first ) {
                $this->log( 'Order-first flow: creating invoice for order ' . $order_id );

                $invoice_result = $this->create_payment_invoice( $order );

                if ( is_wp_error( $invoice_result ) ) {
                    wc_add_notice( $invoice_result->get_error_message(), 'error' );
                    return array( 'result' => 'failure' );
                }

                // Extract hpp_token and build widget URL
                $hpp_url       = $invoice_result['attributes']['hpp_url'] ?? '';
                $inv_hpp_token = '';
                if ( preg_match( '/\/hpp\/([^\/\?]+)/', $hpp_url, $matches ) ) {
                    $inv_hpp_token = $matches[1];
                }
                $widget_url = $hpp_url ? $hpp_url . '?type=widget' : '';

                // Store hpp_token on order for complete-order endpoint validation
                if ( $inv_hpp_token ) {
                    $order->update_meta_data( '_sstg_hpp_token', $inv_hpp_token );
                    $order->save();
                    set_transient( 'sstg_hpp_order_' . md5( $inv_hpp_token ), $order_id, MONTH_IN_SECONDS );
                }

                return array(
                    'result'                  => 'success',
                    'redirect'                => '#sstg-pay',
                    'sstg_widget_url'         => $widget_url,
                    'sstg_hpp_token'          => $inv_hpp_token,
                    'sstg_order_id'           => $order_id,
                    'sstg_order_received_url' => $order->get_checkout_order_received_url(),
                );
            }
        }

        // If transaction_id is empty, use payment_token (they might be the same)
        if ( empty( $transaction_id ) ) {
            $transaction_id = $payment_token;
        }

        $this->log( sprintf(
            'Processing payment for order %s: hpp_token=%s, transaction_id=%s',
            $order_id,
            $hpp_token,
            $transaction_id
        ) );

        // Store hpp_token -> order_id mapping for fallback webhook lookup
        if ( ! empty( $hpp_token ) ) {
            set_transient( 'sstg_hpp_order_' . md5( $hpp_token ), $order_id, MONTH_IN_SECONDS );
        }

        // If we have hpp_token, verify payment status before completing
        if ( ! empty( $hpp_token ) ) {
            $status_result = $this->verify_hpp_status( $hpp_token );

            if ( is_wp_error( $status_result ) ) {
                wc_add_notice( $status_result->get_error_message(), 'error' );
                return array( 'result' => 'failure' );
            }

            // Check if payment is completed
            if ( $status_result['status'] === 'completed' ) {
                $verified_transaction_id = $status_result['transaction_id'] ?? $transaction_id;

                // Verify the charged amount still matches the final order total before completing.
                if ( ! $this->amount_matches_order_total( $order ) ) {
                    $order->update_meta_data( '_sstg_transaction_id', $verified_transaction_id );
                    $order->update_meta_data( '_sstg_hpp_token', $hpp_token );
                    $this->hold_for_amount_mismatch( $order, (int) $order->get_meta( '_sstg_invoice_amount' ) );

                    return array(
                        'result'   => 'success',
                        'redirect' => $order->get_checkout_order_received_url(),
                    );
                }

                $order->payment_complete( $verified_transaction_id );
                $order->add_order_note( sprintf(
                    __( 'Payment completed via SSTG Payments (verified). Transaction ID: %s', 'sstg-payments' ),
                    $verified_transaction_id
                ) );
                $order->update_meta_data( '_sstg_transaction_id', $verified_transaction_id );
                $order->update_meta_data( '_sstg_hpp_token', $hpp_token );
                $order->save();

                return array(
                    'result'   => 'success',
                    'redirect' => $order->get_checkout_order_received_url(),
                );
            }

            // If status is pending, we might need to process it
            if ( $status_result['status'] === 'pending' && ! empty( $payment_token ) ) {
                $result = $this->process_hpp_payment( $hpp_token, $payment_token, $order );

                if ( is_wp_error( $result ) ) {
                    wc_add_notice( $result->get_error_message(), 'error' );
                    return array( 'result' => 'failure' );
                }

                return array(
                    'result'   => 'success',
                    'redirect' => $order->get_checkout_order_received_url(),
                );
            }

            // Payment failed or other status
            if ( in_array( $status_result['status'], array( 'failed', 'expired', 'cancelled' ), true ) ) {
                wc_add_notice( __( 'Payment was not successful. Please try again.', 'sstg-payments' ), 'error' );
                return array( 'result' => 'failure' );
            }
        }

        // If we have hpp_token but no UUID transaction_id, need to process via HPP API
        if ( ! empty( $hpp_token ) && ! empty( $payment_token ) ) {
            $result = $this->process_hpp_payment( $hpp_token, $payment_token, $order );

            if ( is_wp_error( $result ) ) {
                wc_add_notice( $result->get_error_message(), 'error' );
                return array( 'result' => 'failure' );
            }

            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_order_received_url(),
            );
        }

        // Payment not yet completed - return failure to prevent order completion
        wc_add_notice( __( 'Please complete the payment form before placing your order.', 'sstg-payments' ), 'error' );
        return array( 'result' => 'failure' );
    }

    /**
     * Verify HPP payment status.
     *
     * @param string $hpp_token HPP token.
     * @return array|WP_Error Status data or error.
     */
    public function verify_hpp_status( $hpp_token ) {
        $url = rtrim( $this->base_url, '/' ) . '/hpp/' . $hpp_token . '/status';

        $args = array(
            'headers' => array(
                'Accept' => 'application/json',
            ),
            'method'  => 'GET',
            'timeout' => 30,
        );

        $this->log( 'HPP Status Check: ' . $url );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log( 'HPP Status Error: ' . $response->get_error_message() );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        $this->log( 'HPP Status Response (' . $http_code . '): ' . $body );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $error_data = json_decode( $body, true );
            $message    = $error_data['error'] ?? $error_data['message'] ?? sprintf( __( 'Status check failed (HTTP %s)', 'sstg-payments' ), $http_code );
            return new WP_Error( 'hpp_status_error', $message );
        }

        $result = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_response', __( 'Invalid response from payment gateway.', 'sstg-payments' ) );
        }

        return array(
            'status'         => $result['status'] ?? 'unknown',
            'transaction_id' => $result['transaction_id'] ?? '',
            'redirect_url'   => $result['redirect_url'] ?? '',
        );
    }

    /**
     * Process payment via HPP.
     *
     * @param string   $hpp_token     HPP token.
     * @param string   $payment_token Payment token from card tokenization.
     * @param WC_Order $order         Order object.
     * @return bool|WP_Error
     */
    public function process_hpp_payment( $hpp_token, $payment_token, $order ) {
        $url = rtrim( $this->base_url, '/' ) . '/hpp/' . $hpp_token . '/process';

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_secret,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'payment_token' => $payment_token ) ),
            'method'  => 'POST',
            'timeout' => 45,
        );

        $this->log( 'HPP Process Request: ' . $url );
        $this->log( 'Request data: payment_token=' . substr( $payment_token, 0, 10 ) . '...' );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log( 'HPP Process Error: ' . $response->get_error_message() );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        $this->log( 'HPP Process Response (' . $http_code . '): ' . $body );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $error_data = json_decode( $body, true );
            $message    = $error_data['error'] ?? sprintf( __( 'Payment processing failed (HTTP %s)', 'sstg-payments' ), $http_code );
            return new WP_Error( 'hpp_process_error', $message );
        }

        $result = json_decode( $body, true );

        if ( isset( $result['success'] ) && $result['success'] ) {
            // Get transaction ID - try multiple possible field names
            $transaction_id = $result['transactionId']
                ?? $result['transaction_id']
                ?? $result['id']
                ?? null;

            // The amount actually charged is returned by the gateway when available;
            // fall back to the snapshotted invoice amount. Verify it matches the final total.
            $charged_cents = isset( $result['amount'] ) ? (int) $result['amount'] : (int) $order->get_meta( '_sstg_invoice_amount' );
            if ( $order instanceof WC_Order && ! $this->amount_matches_order_total( $order, $charged_cents ?: null ) ) {
                $order->update_meta_data( '_sstg_hpp_token', $hpp_token );
                if ( $transaction_id && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $transaction_id ) ) {
                    $order->update_meta_data( '_sstg_transaction_id', $transaction_id );
                }
                $this->hold_for_amount_mismatch( $order, $charged_cents );
                return true;
            }

            $order->payment_complete( $transaction_id ?: $hpp_token );
            $order->add_order_note( sprintf(
                __( 'Payment completed via SSTG Payments. Transaction ID: %s', 'sstg-payments' ),
                $transaction_id ?: $hpp_token
            ) );
            $order->update_meta_data( '_sstg_hpp_token', $hpp_token );

            // Only store transaction_id if it's a valid UUID
            if ( $transaction_id && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $transaction_id ) ) {
                $order->update_meta_data( '_sstg_transaction_id', $transaction_id );
            }
            $order->save();
            return true;
        }

        $error_message = $result['error'] ?? __( 'Payment was not successful.', 'sstg-payments' );
        return new WP_Error( 'payment_failed', $error_message );
    }

    /**
     * Create payment invoice via API.
     *
     * @param WC_Order $order Order object.
     * @return array|WP_Error
     */
    public function create_payment_invoice( WC_Order $order ) {
        $reference_id = 'order-' . $order->get_id() . '-' . time();

        // Snapshot the amount from the FINAL order total at the moment the invoice
        // is created. This same value is stored on the order so completion paths can
        // verify the charged amount still matches the order total before completing.
        $amount_cents = (int) round( floatval( $order->get_total() ) * 100 );

        $invoice_data = array(
            'data' => array(
                'type'       => 'payment-invoices',
                'attributes' => array(
                    'reference_id'    => $reference_id,
                    'amount'          => $amount_cents, // Amount in cents
                    'currency'        => $order->get_currency(),
                    'flow'            => 'charge',
                    'test_mode'       => $this->test_mode,
                    'description'     => sprintf( __( 'Order #%s', 'sstg-payments' ), $order->get_order_number() ),
                    'gateway_options' => array(
                        'cardgate' => array(
                            'tokenize' => false,
                        ),
                    ),
                    'customer'        => array(
                        'reference_id'  => (string) $order->get_customer_id() ?: md5( $order->get_billing_email() ),
                        'name'          => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                        'email'         => $order->get_billing_email(),
                        'phone'         => $order->get_billing_phone(),
                        'address'       => array(
                            'full_address' => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
                            'country'      => $order->get_billing_country(),
                            'region'       => $order->get_billing_state(),
                            'city'         => $order->get_billing_city(),
                            'street'       => $order->get_billing_address_1(),
                            'post_code'    => $order->get_billing_postcode(),
                        ),
                        'metadata'      => new stdClass(),
                    ),
                    'metadata'        => array(
                        'order_id'    => $order->get_id(),
                        'order_key'   => $order->get_order_key(),
                        'wp_user_id'  => $order->get_user_id(),
                    ),
                    'return_url'      => $order->get_checkout_order_received_url(),
                    'return_urls'     => array(
                        'success' => add_query_arg( array(
                            'sstg_status' => 'success',
                            'order_id'    => $order->get_id(),
                        ), $order->get_checkout_order_received_url() ),
                        'pending' => add_query_arg( array(
                            'sstg_status' => 'pending',
                            'order_id'    => $order->get_id(),
                        ), $order->get_checkout_order_received_url() ),
                        'fail'    => add_query_arg( array(
                            'sstg_status' => 'fail',
                            'order_id'    => $order->get_id(),
                        ), wc_get_checkout_url() ),
                    ),
                    'callback_url'    => $this->callback_url,
                ),
            ),
        );

        $response = $this->call_api( '/payment-invoices', $invoice_data, 'POST' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! isset( $body['data']['id'] ) ) {
            $this->log( 'Invalid API response: ' . print_r( $body, true ) );
            return new WP_Error( 'invalid_response', __( 'Invalid response from payment gateway.', 'sstg-payments' ) );
        }

        // Store reference_id and invoice_id in order meta
        $order->update_meta_data( '_sstg_reference_id', $reference_id );
        $order->update_meta_data( '_sstg_invoice_id', $body['data']['id'] );
        // Persist the amount the invoice was created for, so completion paths can
        // detect if the order total changed afterwards (e.g. ATUM / coupons / shipping).
        $order->update_meta_data( '_sstg_invoice_amount', $amount_cents );
        $order->update_meta_data( '_sstg_invoice_currency', $order->get_currency() );
        $order->save();

        return $body['data'];
    }

    /**
     * Verify the amount that was/will be charged still matches the final order total.
     *
     * Other plugins (e.g. ATUM Multi-Inventory, coupons, recalculated shipping/tax)
     * can mutate the order total AFTER the invoice was created. The invoice amount is
     * snapshotted at creation time, so we must re-check it before marking the order paid.
     *
     * @param WC_Order $order         Order object.
     * @param int|null $charged_cents Amount actually charged at the gateway, in cents.
     *                                When null, the amount snapshotted at invoice creation
     *                                ( _sstg_invoice_amount ) is used.
     * @return bool True when the amounts match (within a 1 cent rounding tolerance).
     */
    private function amount_matches_order_total( WC_Order $order, $charged_cents = null ) {
        $order_total_cents = (int) round( floatval( $order->get_total() ) * 100 );

        if ( null === $charged_cents ) {
            $charged_cents = $order->get_meta( '_sstg_invoice_amount' );

            // No snapshot stored (legacy order, or order-pay invoice without meta) —
            // we cannot validate, so don't block completion on a value we never recorded.
            if ( '' === $charged_cents || null === $charged_cents ) {
                return true;
            }
        }

        $charged_cents = (int) $charged_cents;

        return abs( $charged_cents - $order_total_cents ) <= 1;
    }

    /**
     * Put an order on hold for manual review when the charged amount does not match
     * the final order total, instead of silently completing it.
     *
     * @param WC_Order $order         Order object.
     * @param int      $charged_cents Amount charged at the gateway, in cents.
     * @return void
     */
    private function hold_for_amount_mismatch( WC_Order $order, $charged_cents ) {
        $charged_cents     = (int) $charged_cents;
        $order_total_cents = (int) round( floatval( $order->get_total() ) * 100 );

        $this->log( sprintf(
            'AMOUNT MISMATCH for order %d: charged=%d cents, order_total=%d cents. Holding for review.',
            $order->get_id(),
            $charged_cents,
            $order_total_cents
        ) );

        $note = sprintf(
            __( 'SSTG Payments: the charged amount (%1$s) does not match the final order total (%2$s). The order has been placed on hold for manual review instead of being completed. This usually means the order total changed (e.g. inventory pricing, coupons or shipping) after the payment invoice was created.', 'sstg-payments' ),
            wc_price( $charged_cents / 100 ),
            wc_price( $order_total_cents / 100 )
        );

        $order->update_meta_data( '_sstg_amount_mismatch', 'yes' );
        $order->update_meta_data( '_sstg_charged_amount', $charged_cents );

        if ( ! $order->has_status( array( 'on-hold', 'cancelled', 'refunded' ) ) ) {
            $order->update_status( 'on-hold', $note );
        } else {
            $order->add_order_note( $note );
        }

        $order->save();
    }

    /**
     * REST API endpoint to create invoice and get HPP URL.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function rest_create_invoice( WP_REST_Request $request ) {
        $order_id = $request->get_param( 'order_id' );

        if ( ! $order_id ) {
            return new WP_REST_Response( array( 'error' => 'Order ID is required.' ), 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_REST_Response( array( 'error' => 'Order not found.' ), 404 );
        }

        $result = $this->create_payment_invoice( $order );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
        }

        $hpp_url   = $result['attributes']['hpp_url'] ?? '';
        $hpp_token = '';

        // Extract hpp_token from hpp_url (format: .../hpp/{token})
        if ( preg_match( '/\/hpp\/([^\/\?]+)/', $hpp_url, $matches ) ) {
            $hpp_token = $matches[1];
        }

        // Store hpp_token on order for complete-order endpoint validation
        if ( $hpp_token ) {
            $order->update_meta_data( '_sstg_hpp_token', $hpp_token );
            $order->save();
            set_transient( 'sstg_hpp_order_' . md5( $hpp_token ), $order_id, MONTH_IN_SECONDS );
        }

        // Build widget URL
        $widget_url = $hpp_url ? $hpp_url . '?type=widget' : '';

        return new WP_REST_Response( array(
            'success'      => true,
            'invoice_id'   => $result['id'],
            'hpp_url'      => $hpp_url,
            'widget_url'   => $widget_url,
            'hpp_token'    => $hpp_token,
            // Amount (in cents) the invoice was created for, so the client can detect
            // if the cart/order total changes before the order is placed.
            'amount'       => (int) $order->get_meta( '_sstg_invoice_amount' ),
        ) );
    }

    /**
     * REST API endpoint to process payment after token received.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function rest_process_payment( WP_REST_Request $request ) {
        $order_id      = $request->get_param( 'order_id' );
        $payment_token = $request->get_param( 'payment_token' );
        $hpp_token     = $request->get_param( 'hpp_token' );

        if ( ! $payment_token || ! $hpp_token ) {
            return new WP_REST_Response( array( 'error' => 'Payment token and HPP token are required.' ), 400 );
        }

        $order = null;
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return new WP_REST_Response( array( 'error' => 'Order not found.' ), 404 );
            }

            if ( $order->is_paid() ) {
                return new WP_REST_Response( array(
                    'success'      => true,
                    'redirect_url' => $order->get_checkout_order_received_url(),
                ) );
            }
        }

        // Process payment via HPP
        $result = $this->process_hpp_payment( $hpp_token, $payment_token, $order );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
        }

        $redirect_url = $order ? $order->get_checkout_order_received_url() : wc_get_checkout_url();

        return new WP_REST_Response( array(
            'success'      => true,
            'redirect_url' => $redirect_url,
        ) );
    }

    /**
     * REST API endpoint to complete order after payment.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function rest_complete_order( WP_REST_Request $request ) {
        $order_id       = $request->get_param( 'order_id' );
        $transaction_id = $request->get_param( 'transaction_id' );
        $hpp_token      = $request->get_param( 'hpp_token' );

        if ( ! $order_id || ! $hpp_token ) {
            return new WP_REST_Response( array( 'error' => 'Order ID and HPP token are required.' ), 400 );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_REST_Response( array( 'error' => 'Order not found.' ), 404 );
        }

        if ( $order->is_paid() ) {
            return new WP_REST_Response( array(
                'success'      => true,
                'redirect_url' => $order->get_checkout_order_received_url(),
            ) );
        }

        // Verify hpp_token matches what's stored on the order
        $stored_hpp_token = $order->get_meta( '_sstg_hpp_token' );
        if ( $stored_hpp_token && $stored_hpp_token !== $hpp_token ) {
            return new WP_REST_Response( array( 'error' => 'HPP token mismatch.' ), 403 );
        }

        // Verify payment status at the gateway before completing
        $status_result = $this->verify_hpp_status( $hpp_token );
        if ( is_wp_error( $status_result ) ) {
            return new WP_REST_Response( array( 'error' => $status_result->get_error_message() ), 500 );
        }

        if ( $status_result['status'] !== 'completed' ) {
            return new WP_REST_Response( array( 'error' => 'Payment has not been completed at the gateway.' ), 400 );
        }

        $verified_transaction_id = $status_result['transaction_id'] ?? $transaction_id;

        // Verify the charged amount still matches the final order total before completing.
        if ( ! $this->amount_matches_order_total( $order ) ) {
            $order->update_meta_data( '_sstg_hpp_token', $hpp_token );
            if ( $verified_transaction_id ) {
                $order->update_meta_data( '_sstg_transaction_id', $verified_transaction_id );
            }
            $this->hold_for_amount_mismatch( $order, (int) $order->get_meta( '_sstg_invoice_amount' ) );

            return new WP_REST_Response( array(
                'success'      => true,
                'redirect_url' => $order->get_checkout_order_received_url(),
            ) );
        }

        // Complete the order
        $order->payment_complete( $verified_transaction_id ?: $hpp_token );
        $order->add_order_note( sprintf(
            __( 'Payment completed via SSTG Payments (verified). Transaction ID: %s', 'sstg-payments' ),
            $verified_transaction_id ?: $hpp_token
        ) );

        $order->update_meta_data( '_sstg_hpp_token', $hpp_token );
        if ( $verified_transaction_id ) {
            $order->update_meta_data( '_sstg_transaction_id', $verified_transaction_id );
        }
        $order->save();

        return new WP_REST_Response( array(
            'success'      => true,
            'redirect_url' => $order->get_checkout_order_received_url(),
        ) );
    }

    /**
     * Process webhook callback.
     */
    public function process_callback() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            status_header( 400 );
            wp_send_json_error( array( 'message' => 'Invalid request method.' ) );
            exit;
        }

        $raw_data = file_get_contents( 'php://input' );

        if ( empty( $raw_data ) ) {
            status_header( 400 );
            wp_send_json_error( array( 'message' => 'Empty callback data.' ) );
            exit;
        }

        $callback_data = json_decode( $raw_data, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            status_header( 400 );
            wp_send_json_error( array( 'message' => 'Invalid JSON data.' ) );
            exit;
        }

        $this->log( 'Callback received: ' . print_r( $callback_data, true ) );

        // Our webhook format:
        // {
        //   "id": "event-uuid",
        //   "type": "payment.completed|payment.failed|refund.completed|refund.failed",
        //   "created": 1234567890,
        //   "data": { ... event data ... },
        //   "object": { "type": "payment_request|transaction|refund", "id": "uuid" }
        // }

        $event_type     = $callback_data['type'] ?? '';
        $event_data     = $callback_data['data'] ?? array();
        $object_info    = $callback_data['object'] ?? array();

        // Extract key fields from event data
        $reference_id   = $event_data['reference_id'] ?? '';
        $status         = strtolower( $event_data['status'] ?? '' );
        $transaction_id = $event_data['transaction_id'] ?? '';
        $amount         = $event_data['amount'] ?? 0;
        $currency       = $event_data['currency'] ?? 'USD';

        // For refunds
        $refund_id              = $event_data['refund_id'] ?? '';
        $original_transaction_id = $event_data['original_transaction_id'] ?? '';

        $this->log( sprintf(
            'Parsed webhook: type=%s, reference_id=%s, status=%s, transaction_id=%s',
            $event_type, $reference_id, $status, $transaction_id
        ) );

        if ( empty( $reference_id ) ) {
            status_header( 400 );
            wp_send_json_error( array( 'message' => 'Missing reference_id in event data.' ) );
            exit;
        }

        // Find order by reference_id
        $order_id = 0;
        $order    = null;

        // Method 1: Check if metadata contains order_id directly
        $metadata = $event_data['metadata'] ?? array();
        if ( ! empty( $metadata['order_id'] ) ) {
            $order_id = absint( $metadata['order_id'] );
            $order    = wc_get_order( $order_id );
            if ( $order ) {
                $this->log( 'Found order via metadata order_id: ' . $order_id );
            }
        }

        // Method 2: Try order reference pattern (format: order-{id}-{timestamp} or refund-order-{id}-{timestamp})
        if ( ! $order ) {
            preg_match( '/(?:refund-)?order-(\d+)-/', $reference_id, $matches );
            if ( ! empty( $matches[1] ) ) {
                $order_id = absint( $matches[1] );
                $order    = wc_get_order( $order_id );
                if ( $order ) {
                    $this->log( 'Found order via reference pattern: ' . $order_id );
                }
            }
        }

        // Order not found — log and acknowledge (return 200 to prevent retries)
        if ( ! $order ) {
            $this->log( 'WARNING: Order not found for webhook reference: ' . $reference_id );
            status_header( 200 );
            wp_send_json( array(
                'message'      => 'Order not found for reference.',
                'reference_id' => $reference_id,
            ) );
            exit;
        }

        $event_known = true;
        // Process based on event type
        switch ( $event_type ) {
            case 'payment.completed':
                if ( $status === 'completed' || $status === 'approved' ) {
                    if ( ! $order->is_paid() ) {
                        // The webhook reports the amount actually charged (in cents).
                        // Verify it matches the final order total before completing; if it
                        // diverged (ATUM / coupons / shipping changed the total after the
                        // invoice was created), hold for manual review instead.
                        $charged_cents = (int) $amount;
                        if ( ! $this->amount_matches_order_total( $order, $charged_cents ?: null ) ) {
                            $order->update_meta_data( '_sstg_transaction_id', $transaction_id );
                            $this->hold_for_amount_mismatch( $order, $charged_cents ?: (int) $order->get_meta( '_sstg_invoice_amount' ) );
                        } else {
                            $order->update_meta_data( '_sstg_transaction_id', $transaction_id );
                            $order->payment_complete( $transaction_id );
                            $order->add_order_note( sprintf(
                                __( 'Payment completed via webhook. Transaction ID: %s', 'sstg-payments' ),
                                $transaction_id
                            ) );
                        }
                    }else{
                        $this->log('Order already marked paid for: ' . $order->get_id() );
                    }
                }
                break;

            case 'payment.failed':
                if ( ! $order->has_status( array( 'failed', 'cancelled', 'completed', 'processing' ) ) ) {
                    $order->update_status( 'failed', sprintf(
                        __( 'Payment failed via webhook. Status: %s', 'sstg-payments' ),
                        $status
                    ) );
                }
                break;

            case 'refund.completed':
                if ( $status === 'approved' ) {
                    // Check if refund already exists to avoid duplicates
                    $existing_refunds = $order->get_refunds();
                    $refund_exists = false;

                    foreach ( $existing_refunds as $existing_refund ) {
                        if ( $existing_refund->get_meta( '_sstg_refund_id' ) === $refund_id ) {
                            $refund_exists = true;
                            break;
                        }
                    }

                    if ( ! $refund_exists && $amount > 0 ) {
                        // Amount is in cents, convert to decimal
                        $refund_amount = $amount / 100.0;

                        $refund = wc_create_refund( array(
                            'order_id'   => $order->get_id(),
                            'amount'     => $refund_amount,
                            'reason'     => __( 'Refund processed via payment gateway', 'sstg-payments' ),
                            'refund_payment' => false, // Already refunded at gateway
                        ) );

                        if ( ! is_wp_error( $refund ) ) {
                            $refund->update_meta_data( '_sstg_refund_id', $refund_id );
                            $refund->update_meta_data( '_sstg_original_transaction_id', $original_transaction_id );
                            $refund->save();

                            $order->add_order_note( sprintf(
                                __( 'Refund of %s %s completed via webhook. Refund ID: %s', 'sstg-payments' ),
                                wc_price( $refund_amount ),
                                $currency,
                                $refund_id
                            ) );
                        } else {
                            $order->add_order_note( sprintf(
                                __( 'Failed to create WooCommerce refund: %s. Gateway refund ID: %s', 'sstg-payments' ),
                                $refund->get_error_message(),
                                $refund_id
                            ) );
                        }
                    }
                }
                break;

            case 'refund.failed':
                $order->add_order_note( sprintf(
                    __( 'Refund failed via webhook. Status: %s, Reference: %s', 'sstg-payments' ),
                    $status,
                    $reference_id
                ) );
                break;

            default:
                $this->log( 'Unknown event type: ' . $event_type );
                $event_known = false;
                // Still return success to acknowledge receipt
                break;
        }

        $order->save();

        wp_send_json_success( array(
            'message'    => 'Callback received. '.( $event_known ? '' : 'Unknown event type: ' . $event_type ),
            'event_type' => $event_type,
            'order_id'   => $order_id,
        ) );
        exit;
    }

    /**
     * Process refund.
     *
     * @param int    $order_id Order ID.
     * @param float  $amount   Amount to refund.
     * @param string $reason   Refund reason.
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Invalid order.', 'sstg-payments' ) );
        }

        // Get transaction ID from order meta
        $transaction_id = $order->get_meta( '_sstg_transaction_id' );
        if ( ! $transaction_id ) {
            $transaction_id = $order->get_transaction_id();
        }

        if ( ! $transaction_id ) {
            return new WP_Error( 'missing_transaction', __( 'Transaction ID not found for refund. This order may not have been processed through SSTG Payments.', 'sstg-payments' ) );
        }

        // Validate UUID format
        $uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if ( ! preg_match( $uuid_pattern, $transaction_id ) ) {
            $this->log( 'Invalid transaction ID format for refund: ' . $transaction_id );
            return new WP_Error(
                'invalid_transaction_id',
                __( 'Refund not available. The transaction ID was not properly recorded for this order. This may happen with older orders or if the payment callback was not received. Please process this refund manually through the SSTG Payments dashboard.', 'sstg-payments' )
            );
        }

        $refund_reference = 'refund-order-' . $order_id . '-' . time();
        $idempotency_key  = 'ref_' . md5( $order_id . '_' . $amount . '_' . time() );
        $amount_cents     = (int) round( floatval( $amount ) * 100 );

        if ( $amount_cents < 1 ) {
            return new WP_Error( 'invalid_amount', __( 'Refund amount must be greater than zero.', 'sstg-payments' ) );
        }

        $refund_data = array(
            'transaction_id'  => $transaction_id,
            'amount'          => $amount_cents,
            'reason'          => 'requested_by_customer',
            'reference_id'    => $refund_reference,
            'idempotency_key' => $idempotency_key,
            'callback_url'    => $this->callback_url,
            'metadata'        => array(
                'order_id'      => (int) $order_id,
                'reason'        => $reason ?: 'Customer requested refund',
                'internal_note' => $reason ?: __( 'Refund for order', 'sstg-payments' ) . ' #' . $order->get_order_number(),
            ),
        );

        $this->log( 'Processing refund for order ' . $order_id . ': ' . print_r( $refund_data, true ) );

        $response = $this->call_api( '/payment/refund', $refund_data, 'POST' );

        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            // Handle nested array error messages
            if ( is_array( $error_message ) ) {
                $error_message = implode( ', ', array_map( function( $m ) {
                    return is_array( $m ) ? implode( ', ', $m ) : $m;
                }, $error_message ) );
            }
            $this->log( 'Refund API error: ' . $error_message );
            return new WP_Error( 'refund_api_error', $error_message );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        $this->log( 'Refund response: ' . print_r( $body, true ) );

        // Extract data from JSON:API format response
        $attributes = $body['data']['attributes'] ?? array();
        $status     = $attributes['status'] ?? '';
        $refund_id  = $attributes['refund_id'] ?? $body['data']['id'] ?? '';

        // Check for approved/processed status
        if ( in_array( $status, array( 'approved', 'processed', 'success' ), true ) ) {
            $order->add_order_note( sprintf(
                __( 'Refund processed successfully via SSTG Payments. Amount: %s, Refund ID: %s', 'sstg-payments' ),
                wc_price( $amount ),
                $refund_id
            ) );
            $order->update_meta_data( '_sstg_refund_id', $refund_id );
            $order->save();
            return true;
        }

        // Check for pending status
        if ( $status === 'pending' ) {
            $message = sprintf(
                __( 'Refund submitted and pending approval. Amount: %s, Refund ID: %s', 'sstg-payments' ),
                wc_price( $amount ),
                $refund_id
            );
            $order->add_order_note($message);
            $order->update_meta_data( '_sstg_refund_id', $refund_id );
            $order->save();
            return $message;
        }

        // Check for declined/failed status
        if ( in_array( $status, array( 'declined', 'failed', 'rejected' ), true ) ) {
            $error_message = $attributes['error'] ?? $attributes['message'] ?? __( 'Refund was declined.', 'sstg-payments' );
            $order->add_order_note( sprintf(
                __( 'Refund declined via SSTG Payments. Amount: %s, Reason: %s', 'sstg-payments' ),
                wc_price( $amount ),
                $error_message
            ) );
            return new WP_Error( 'refund_declined', $error_message );
        }

        // Fallback error handling
        $error_message = $body['error'] ?? $body['message'] ?? $attributes['error'] ?? __( 'Refund could not be processed.', 'sstg-payments' );
        return new WP_Error( 'refund_failed', $error_message );
    }

    /**
     * Call the SSTG Payments API.
     *
     * @param string $endpoint API endpoint.
     * @param array  $data     Request data.
     * @param string $method   HTTP method.
     * @return array|WP_Error
     */
    private function call_api( $endpoint, $data = array(), $method = 'POST' ) {
        $url = rtrim( $this->base_url, '/' ) . $endpoint;

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_secret,
                'Content-Type'  => 'application/json',
            ),
            'method'  => $method,
            'timeout' => 45,
        );

        if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && ! empty( $data ) ) {
            $args['body'] = wp_json_encode( $data );
        }

        $this->log( 'API Request: ' . $method . ' ' . $url );
        $this->log( 'Request data: ' . print_r( $data, true ) );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log( 'API Error: ' . $response->get_error_message() );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        $this->log( 'API Response (' . $http_code . '): ' . $body );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $error_data = json_decode( $body, true );
            $message    = $error_data['message'] ?? sprintf( __( 'API Error (HTTP %s)', 'sstg-payments' ), $http_code );
            return new WP_Error( 'api_error', $message );
        }

        return $response;
    }

    /**
     * Log message if debug is enabled.
     *
     * @param string $message Message to log.
     */
    private function log( $message ) {
        if ( $this->debug ) {
            wc_get_logger()->debug( $message, array( 'source' => 'sstg-payments' ) );
        }
    }
}