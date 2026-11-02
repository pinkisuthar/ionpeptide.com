<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Monarch_Cron {

    private static $instance = null;

    const CRON_HOOK = 'monarch_ach_update_transaction_status';
    const CRON_INTERVAL = 'every_two_hours';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Add custom cron schedule
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedule'));

        // Schedule CRON on plugin init
        add_action('init', array($this, 'schedule_cron'));

        // Hook for the CRON event
        add_action(self::CRON_HOOK, array($this, 'update_pending_transactions'));

        // Add manual trigger via AJAX
        add_action('wp_ajax_monarch_manual_status_update', array($this, 'ajax_manual_status_update'));
    }

    /**
     * Add custom cron schedule for every 2 hours
     */
    public function add_custom_cron_schedule($schedules) {
        $schedules['every_two_hours'] = array(
            'interval' => 7200, // 2 hours in seconds
            'display'  => __('Every 2 Hours', 'monarch-ach')
        );
        return $schedules;
    }

    /**
     * Schedule the CRON job if not already scheduled
     */
    public function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    /**
     * Unschedule the CRON job (called on plugin deactivation)
     */
    public static function unschedule_cron() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Update all pending transactions
     */
    public function update_pending_transactions() {
        global $wpdb;

        $logger = WC_Monarch_Logger::instance();
        $logger->info('CRON: Starting transaction status update');

        $table_name = $wpdb->prefix . 'monarch_ach_transactions';

        // Get all pending/processing transactions
        $pending_transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE status IN (%s, %s) ORDER BY created_at ASC LIMIT 100",
            'pending',
            'processing'
        ));

        if (empty($pending_transactions)) {
            $logger->info('CRON: No pending transactions to update');
            $result = array(
                'processed' => 0,
                'updated' => 0,
                'errors' => 0
            );
            // Save last run info even when no transactions to process
            $this->save_last_run_info($result);
            return $result;
        }

        $logger->info('CRON: Found ' . count($pending_transactions) . ' pending transactions to check');

        $processed = 0;
        $updated = 0;
        $errors = 0;

        foreach ($pending_transactions as $transaction) {
            $result = $this->update_single_transaction($transaction);
            $processed++;

            if ($result === true) {
                $updated++;
            } elseif ($result === false) {
                $errors++;
            }
            // null means no change needed

            // Small delay between API calls to avoid rate limiting
            usleep(500000); // 0.5 seconds
        }

        $logger->info("CRON: Completed - Processed: $processed, Updated: $updated, Errors: $errors");

        $result = array(
            'processed' => $processed,
            'updated' => $updated,
            'errors' => $errors
        );

        // Save last run info
        $this->save_last_run_info($result);

        return $result;
    }

    /**
     * Update a single transaction status
     * @param object $transaction Transaction row from database
     * @return bool|null true if updated, false if error, null if no change
     */
    private function update_single_transaction($transaction) {
        $logger = WC_Monarch_Logger::instance();

        if (empty($transaction->transaction_id)) {
            $logger->warning('CRON: Transaction ID missing for order #' . $transaction->order_id);
            return false;
        }

        $order = wc_get_order($transaction->order_id);
        if (!$order) {
            $logger->warning('CRON: Order not found for transaction ' . $transaction->transaction_id);
            return false;
        }

        // Get customer's API credentials
        $customer_id = $order->get_user_id();
        $org_api_key = get_user_meta($customer_id, '_monarch_org_api_key', true);
        $org_app_id = get_user_meta($customer_id, '_monarch_org_app_id', true);

        // Get gateway settings
        $gateway = new WC_Monarch_ACH_Gateway();

        // Use purchaser org credentials if available, otherwise merchant credentials
        $api_key = $org_api_key ?: $gateway->api_key;
        $app_id = $org_app_id ?: $gateway->app_id;

        if (empty($api_key) || empty($app_id)) {
            $logger->error('CRON: Missing API credentials for transaction ' . $transaction->transaction_id);
            return false;
        }

        $monarch_api = new Monarch_API(
            $api_key,
            $app_id,
            $gateway->merchant_org_id,
            $gateway->partner_name,
            $gateway->testmode
        );

        // Call Monarch API to get transaction status
        $result = $monarch_api->get_transaction_status($transaction->transaction_id);

        if (!$result['success']) {
            $error_msg = $result['error'] ?? 'Unknown error';
            $status_code = $result['status_code'] ?? 'N/A';
            $logger->error('CRON: Failed to get status for transaction ' . $transaction->transaction_id . ': ' . $error_msg . ' (HTTP ' . $status_code . ')');

            // Log the full response for debugging
            if (isset($result['response'])) {
                $logger->debug('CRON: Full API response: ' . json_encode($result['response']));
            }

            return false;
        }

        // Try to extract status from various possible response formats
        $api_status = null;
        $response_data = $result['data'] ?? array();

        // Format 1: data.status
        if (isset($response_data['status'])) {
            $api_status = $response_data['status'];
        }
        // Format 2: data.transactionStatus
        elseif (isset($response_data['transactionStatus'])) {
            $api_status = $response_data['transactionStatus'];
        }
        // Format 3: data.transaction.status
        elseif (isset($response_data['transaction']['status'])) {
            $api_status = $response_data['transaction']['status'];
        }
        // Format 4: data.transaction_status
        elseif (isset($response_data['transaction_status'])) {
            $api_status = $response_data['transaction_status'];
        }
        // Format 5: Direct response (no 'data' wrapper) - status
        elseif (isset($result['status'])) {
            $api_status = $result['status'];
        }

        if (empty($api_status)) {
            $logger->warning('CRON: No status field found in response for transaction ' . $transaction->transaction_id);
            $logger->debug('CRON: Full response structure: ' . json_encode($result));
            return null;
        }

        // Map Monarch status to our internal status
        $new_status = $this->map_monarch_status($api_status);

        // Check if status has changed
        if ($new_status === $transaction->status) {
            $logger->debug('CRON: No status change for transaction ' . $transaction->transaction_id . ' (still ' . $new_status . ')');
            return null;
        }

        // Update the transaction in our database
        global $wpdb;
        $table_name = $wpdb->prefix . 'monarch_ach_transactions';

        $wpdb->update(
            $table_name,
            array(
                'status' => $new_status,
                'api_response' => json_encode($result['data']),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $transaction->id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        // Update WooCommerce order status
        $this->update_order_status($order, $new_status, $transaction->transaction_id, $api_status);

        $logger->info('CRON: Updated transaction ' . $transaction->transaction_id . ' from ' . $transaction->status . ' to ' . $new_status);

        return true;
    }

    /**
     * Map Monarch API status to internal status
     * @param string $monarch_status Status from Monarch API
     * @return string Internal status
     */
    private function map_monarch_status($monarch_status) {
        $status_map = array(
            // Successful statuses
            'completed' => 'completed',
            'success' => 'completed',
            'settled' => 'completed',
            'approved' => 'completed',

            // Pending statuses
            'pending' => 'pending',
            'processing' => 'processing',
            'in_progress' => 'processing',
            'submitted' => 'processing',

            // Failed statuses
            'failed' => 'failed',
            'declined' => 'failed',
            'rejected' => 'failed',
            'returned' => 'failed',
            'error' => 'failed',

            // Refund statuses
            'refunded' => 'refunded',
            'reversed' => 'refunded',

            // Void statuses
            'voided' => 'voided',
            'cancelled' => 'voided',
        );

        $monarch_status_lower = strtolower($monarch_status);

        return $status_map[$monarch_status_lower] ?? 'pending';
    }

    /**
     * Update WooCommerce order status based on transaction status
     */
    private function update_order_status($order, $new_status, $transaction_id, $api_status) {
        switch ($new_status) {
            case 'completed':
                if ($order->get_status() !== 'completed' && $order->get_status() !== 'processing') {
                    $order->payment_complete($transaction_id);
                    $order->add_order_note(
                        sprintf('Monarch ACH payment completed. Transaction ID: %s. API Status: %s', $transaction_id, $api_status)
                    );
                }
                break;

            case 'failed':
                if ($order->get_status() !== 'failed') {
                    $order->update_status('failed',
                        sprintf('Monarch ACH payment failed. Transaction ID: %s. API Status: %s', $transaction_id, $api_status)
                    );
                }
                break;

            case 'refunded':
                if ($order->get_status() !== 'refunded') {
                    $order->update_status('refunded',
                        sprintf('Monarch ACH payment refunded. Transaction ID: %s. API Status: %s', $transaction_id, $api_status)
                    );
                }
                break;

            case 'voided':
                if ($order->get_status() !== 'cancelled') {
                    $order->update_status('cancelled',
                        sprintf('Monarch ACH payment voided. Transaction ID: %s. API Status: %s', $transaction_id, $api_status)
                    );
                }
                break;

            default:
                // For pending/processing, just add a note
                $order->add_order_note(
                    sprintf('Monarch ACH transaction status check: %s (API Status: %s)', $new_status, $api_status)
                );
                break;
        }
    }

    /**
     * AJAX handler for manual status update
     */
    public function ajax_manual_status_update() {
        check_ajax_referer('monarch_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        $result = $this->update_pending_transactions();

        wp_send_json_success(array(
            'message' => sprintf(
                'Status update complete. Processed: %d, Updated: %d, Errors: %d',
                $result['processed'],
                $result['updated'],
                $result['errors']
            ),
            'processed' => $result['processed'],
            'updated' => $result['updated'],
            'errors' => $result['errors']
        ));
    }

    /**
     * Get next scheduled run time
     */
    public static function get_next_scheduled() {
        return wp_next_scheduled(self::CRON_HOOK);
    }

    /**
     * Get last run info
     */
    public static function get_last_run_info() {
        return get_option('monarch_cron_last_run', array(
            'time' => null,
            'processed' => 0,
            'updated' => 0,
            'errors' => 0
        ));
    }

    /**
     * Save last run info
     */
    private function save_last_run_info($result) {
        update_option('monarch_cron_last_run', array(
            'time' => current_time('mysql'),
            'processed' => $result['processed'],
            'updated' => $result['updated'],
            'errors' => $result['errors']
        ));
    }
}

// Initialize
WC_Monarch_Cron::instance();
