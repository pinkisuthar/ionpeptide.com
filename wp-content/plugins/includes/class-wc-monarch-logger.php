<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Monarch_Logger {
    
    private static $instance = null;
    private $logger = null;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->logger = wc_get_logger();
    }
    
    /**
     * Log info message
     */
    public function info($message, $context = array()) {
        $this->log('info', $message, $context);
    }
    
    /**
     * Log error message
     */
    public function error($message, $context = array()) {
        $this->log('error', $message, $context);
    }
    
    /**
     * Log debug message
     * Always logs to help diagnose issues with Monarch API integration
     */
    public function debug($message, $context = array()) {
        // Always log debug messages for Monarch ACH to help diagnose API issues
        $this->log('debug', $message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning($message, $context = array()) {
        $this->log('warning', $message, $context);
    }
    
    /**
     * Log API request
     */
    public function log_api_request($method, $url, $data = array(), $headers = array()) {
        $sanitized_data = $this->sanitize_data($data);
        $sanitized_headers = $this->sanitize_headers($headers);

        // Default: keep curl sanitized. For local debugging only, you can opt-in to an unredacted curl.
        // Enable via: define('MONARCH_ACH_LOG_UNREDACTED', true); (e.g. in wp-config.php)
        // Or via filter: add_filter('monarch_ach_log_unredacted', '__return_true');
        $log_unredacted = $this->should_log_unredacted($url);
        
        $this->info('API Request', array(
            'method' => $method,
            'url' => $url,
            'data' => $sanitized_data,
            'headers' => $sanitized_headers,
            // Helpful for reproducing issues like: "The `pay_token` instance is not valid"
            // NOTE: this is intentionally sanitized/masked.
            'curl' => $log_unredacted
                ? $this->build_curl_command($method, $url, $headers, $data)
                : $this->build_curl_command($method, $url, $sanitized_headers, $sanitized_data),
            'curl_is_unredacted' => (bool) $log_unredacted
        ));
    }
    
    /**
     * Log API response
     */
    public function log_api_response($url, $response, $status_code = null) {
        $this->info('API Response', array(
            'url' => $url,
            'status_code' => $status_code,
            'response' => $this->sanitize_response($response)
        ));
    }
    
    /**
     * Log API error
     */
    public function log_api_error($url, $error, $status_code = null) {
        $this->error('API Error', array(
            'url' => $url,
            'status_code' => $status_code,
            'error' => $error
        ));
    }
    
    /**
     * Log transaction event
     */
    public function log_transaction($event, $order_id, $transaction_data = array()) {
        $sanitized_data = $this->sanitize_transaction_data($transaction_data);
        
        $this->info('Transaction Event', array(
            'event' => $event,
            'order_id' => $order_id,
            'transaction_data' => $sanitized_data,
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * Log customer event
     */
    public function log_customer_event($event, $customer_id, $data = array()) {
        $this->info('Customer Event', array(
            'event' => $event,
            'customer_id' => $customer_id,
            'data' => $this->sanitize_customer_data($data),
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * Core logging method
     */
    private function log($level, $message, $context = array()) {
        $formatted_context = '';
        if (!empty($context)) {
            $formatted_context = ' Context: ' . wp_json_encode($context);
        }
        
        $this->logger->log($level, $message . $formatted_context, array('source' => 'monarch-ach'));
    }
    
    /**
     * Sanitize sensitive data for logging
     */
    private function sanitize_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $sensitive_keys = array(
            'password',
            'dda',
            'account_number',
            'accountId',
            'providerAccountId',
            'routing',
            'routing_number',
            'cvv',
            'cc_card_number',
            'cc_account_number'
        );
        
        $sanitized = array();
        foreach ($data as $key => $value) {
            if (in_array($key, $sensitive_keys)) {
                $sanitized[$key] = $this->mask_sensitive_value($value);
            } else if (is_array($value)) {
                $sanitized[$key] = $this->sanitize_data($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    /**
     * Whether to log secrets in curl (DANGEROUS). Intended for local debugging only.
     */
    private function should_log_unredacted($url) {
        $enabled = (defined('MONARCH_ACH_LOG_UNREDACTED') && MONARCH_ACH_LOG_UNREDACTED);
        /**
         * Filter: allow enabling/disabling unredacted curl logging.
         *
         * @param bool   $enabled Current enabled state.
         * @param string $url     Request URL.
         */
        return (bool) apply_filters('monarch_ach_log_unredacted', $enabled, $url);
    }

    /**
     * Build a copy/paste curl command for debugging.
     * This should be passed already-sanitized headers/body (no secrets).
     */
    private function build_curl_command($method, $url, $headers = array(), $data = array()) {
        $method = strtoupper((string) $method);

        $parts = array();
        $parts[] = 'curl';
        $parts[] = '-X';
        $parts[] = escapeshellarg($method);
        $parts[] = escapeshellarg($url);

        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                // keep exact header name as Monarch expects (e.g. X-API-KEY)
                $parts[] = '-H';
                $parts[] = escapeshellarg($k . ': ' . $v);
            }
        }

        // Only send a body for methods that typically carry one.
        if (!empty($data) && in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'), true)) {
            $json = wp_json_encode($data);
            $parts[] = '--data-raw';
            $parts[] = escapeshellarg($json);
        }

        return implode(' ', $parts);
    }
    
    /**
     * Sanitize headers for logging
     */
    private function sanitize_headers($headers) {
        if (!is_array($headers)) {
            return $headers;
        }
        
        $sensitive_headers = array(
            'X-API-KEY',
            'Authorization',
            'x-access-token'
        );
        
        $sanitized = array();
        foreach ($headers as $key => $value) {
            if (in_array($key, $sensitive_headers)) {
                $sanitized[$key] = $this->mask_sensitive_value($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize API response
     */
    private function sanitize_response($response) {
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->sanitize_data($decoded);
            }
            return $response;
        }
        
        return $this->sanitize_data($response);
    }
    
    /**
     * Sanitize transaction data
     */
    private function sanitize_transaction_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $sensitive_keys = array('payTokenId', 'paytoken_id');
        
        $sanitized = array();
        foreach ($data as $key => $value) {
            if (in_array($key, $sensitive_keys)) {
                $sanitized[$key] = $this->mask_sensitive_value($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize customer data
     */
    private function sanitize_customer_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $sensitive_keys = array('password', 'dob', 'phone');
        
        $sanitized = array();
        foreach ($data as $key => $value) {
            if (in_array($key, $sensitive_keys)) {
                $sanitized[$key] = $this->mask_sensitive_value($value);
            } else if (is_array($value)) {
                $sanitized[$key] = $this->sanitize_customer_data($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Mask sensitive values for logging
     */
    private function mask_sensitive_value($value) {
        if (empty($value)) {
            return $value;
        }
        
        $length = strlen($value);
        
        if ($length <= 4) {
            return str_repeat('*', $length);
        }
        
        return str_repeat('*', $length - 4) . substr($value, -4);
    }
    
    /**
     * Get recent logs for admin display
     */
    public static function get_recent_logs($limit = 50) {
        $log_file = WC_Log_Handler_File::get_log_file_path('monarch-ach');
        
        if (!file_exists($log_file)) {
            return array();
        }
        
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if (!$lines) {
            return array();
        }
        
        // Get the last N lines
        $recent_lines = array_slice($lines, -$limit);
        
        $logs = array();
        foreach ($recent_lines as $line) {
            $logs[] = array(
                'timestamp' => '',
                'level' => '',
                'message' => $line
            );
        }
        
        return array_reverse($logs);
    }
    
    /**
     * Clear logs
     */
    public static function clear_logs() {
        $log_file = WC_Log_Handler_File::get_log_file_path('monarch-ach');
        
        if (file_exists($log_file)) {
            return unlink($log_file);
        }
        
        return true;
    }
}