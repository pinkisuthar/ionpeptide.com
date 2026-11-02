<?php
/**
 * IDEM API Client
 *
 * Handles all API interactions with the IDEM payment gateway.
 *
 * @package WC_Idem_Payments
 */

defined('ABSPATH') || exit;

/**
 * WC_Idem_API_Client class.
 */
class WC_Idem_API_Client {
    /**
     * API Endpoints
     */
    const DEV_API_URL = 'https://gateway.idem-club.ovh/api/idem';
    const SANDBOX_API_URL = 'https://gateway.idem-club.info/api/idem';
    const PRODUCTION_API_URL = 'https://gateway.idem-club.com/api/idem';

    /**
     * API Authentication token
     *
     * @var string
     */
    private $api_token;

    /**
     * Whether to use sandbox mode
     *
     * @var bool
     */
    private $is_sandbox;

    private $logger;
    private $test_url;
    private $prod_url;

    /**
     * Request timeout in seconds
     *
     * @var int
     */
    private $timeout;

    /**
     * Constructor
     *
     * @param string $api_token API Token
     * @param bool   $is_sandbox Whether to use sandbox mode
     * @param WC_Logger_Interface|null $logger WC Logger instance
     * @param string|null $test_url The test URL from settings
     * @param int $timeout Request timeout in seconds
     */
    public function __construct($api_token, $is_sandbox = false, $logger = null, $test_url = null, $timeout = 30) {
        $this->api_token = $api_token;
        $this->is_sandbox = $is_sandbox;
        $this->logger = $logger;
        $this->test_url = $test_url;
        $this->timeout = $timeout;
    }

    /**
     * Set a custom PRODUCTION base URL override (mirrors $test_url for sandbox). Chainable so callers
     * that know the merchant's gateway_url can honor it without widening the constructor signature.
     *
     * @param string|null $url
     * @return $this
     */
    public function set_prod_url($url) {
        $this->prod_url = $url;
        return $this;
    }

    /**
     * Get base API URL based on environment
     *
     * @return string
     */
    private function get_api_url() {
        if ($this->is_sandbox) {
            if (!empty($this->test_url)) {
                return rtrim($this->test_url, '/') . '/api/idem';
            }
            return self::SANDBOX_API_URL;
        }
        // Honor a custom production base URL (matches the Connect flow, which builds from gateway_url).
        if (!empty($this->prod_url)) {
            return rtrim($this->prod_url, '/') . '/api/idem';
        }
        return self::PRODUCTION_API_URL;
    }

    /**
     * Make an API request
     *
     * @param string $endpoint API endpoint
     * @param array  $args Request arguments
     * @param string $method HTTP method
     * @return array|WP_Error
     */
    private function request($endpoint, $args = [], $method = 'GET') {
        $url = trailingslashit($this->get_api_url()) . ltrim($endpoint, '/');

        // if ($this->logger) {
        //     $this->logger->info('IDEM API Request URL: ' . $url, ['source' => 'idem-api-client']);
        //     if (!empty($args)) {
        //         $this->logger->info('IDEM API Request Args: ' . wp_json_encode($args), ['source' => 'idem-api-client']);
        //     }
        // }

        $headers = [
            'Authorization' => 'Bearer ' . $this->api_token,
            'Content-Type' => 'application/json',
        ];

        $request_args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => $this->timeout,
        ];

        if (!empty($args) && in_array($method, ['POST', 'PUT'])) {
            $request_args['body'] = wp_json_encode($args);
        }

        if (!empty($args) && $method === 'GET') {
            $url = add_query_arg($args, $url);
        }

        $response = wp_remote_request($url, $request_args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 400) {
            // A Cloudflare / WAF block on OUR side answers with an HTML challenge page instead of
            // our JSON. Surface a clean, actionable message (Ray ID + the store's public IP) rather
            // than dumping the raw HTML into an admin notice — see the "you have been blocked" case.
            $block = self::detect_upstream_block($response, $body);
            if ($block) {
                return new WP_Error('idem_api_blocked', $block['message'], [
                    'status'    => $code,
                    'blocked'   => true,
                    'ray'       => $block['ray'],
                    'server_ip' => $block['ip'],
                ]);
            }
            return new WP_Error(
                'idem_api_error',
                sprintf(__('IDEM API Error: %s', 'woocommerce-gateway-idem'), self::summarize_error_body($body)),
                ['status' => $code]
            );
        }

        return json_decode($body, true);
    }

    /**
     * Detect a Cloudflare / WAF "Sorry, you have been blocked" page and extract the Ray ID and the
     * store's public IP, so the merchant can hand our support exactly what they need to allow the
     * server. Returns ['ray'=>..,'ip'=>..,'message'=>..] when it looks like a block, false otherwise.
     *
     * @param array  $response wp_remote_request response array
     * @param string $body     Response body
     * @return array|false
     */
    private static function detect_upstream_block($response, $body) {
        $body = (string) $body;

        // If the payload is valid JSON, it's our API talking (even an error such as
        // "Invalid merchant credentials") — never a Cloudflare block page.
        if (is_array(json_decode($body, true))) {
            return false;
        }

        $ray_hdr = (string) wp_remote_retrieve_header($response, 'cf-ray');
        $server  = strtolower((string) wp_remote_retrieve_header($response, 'server'));
        $mitig   = (string) wp_remote_retrieve_header($response, 'cf-mitigated');

        $looks_cf = ($ray_hdr !== '')
            || strpos($server, 'cloudflare') !== false
            || $mitig !== ''
            || stripos($body, 'Cloudflare Ray ID') !== false
            || stripos($body, 'Attention Required!') !== false
            || stripos($body, 'Sorry, you have been blocked') !== false
            || stripos($body, 'cf-error-details') !== false;

        if (!$looks_cf) {
            return false;
        }

        // Ray ID: prefer the cf-ray header (strip the trailing "-DATACENTER" tag); fall back to body.
        $ray_id = ($ray_hdr !== '') ? strtok($ray_hdr, '-') : '';
        if (($ray_id === '' || $ray_id === false)
            && preg_match('/Ray ID:\s*(?:<strong[^>]*>\s*)?([0-9a-f]{10,})/i', $body, $m)) {
            $ray_id = $m[1];
        }
        $ray_id = (string) $ray_id;

        // The store's own public IP, as shown on the Cloudflare block page.
        $ip = '';
        if (preg_match('/id="cf-footer-ip"[^>]*>\s*([0-9a-fA-F:.]+)/', $body, $m)) {
            $ip = $m[1];
        } elseif (preg_match('/Your IP(?:\s*address)?:\s*(?:<[^>]*>\s*)*([0-9]{1,3}(?:\.[0-9]{1,3}){3}|[0-9a-fA-F:]{3,})/i', $body, $m)) {
            $ip = $m[1];
        }

        $ref = [];
        if ($ray_id !== '') { $ref[] = sprintf(__('Ray ID: %s', 'woocommerce-gateway-idem'), $ray_id); }
        if ($ip !== '')     { $ref[] = sprintf(__('your server IP: %s', 'woocommerce-gateway-idem'), $ip); }
        $ref = $ref ? ' (' . implode(', ', $ref) . ')' : '';

        $message = sprintf(
            /* translators: %s: " (Ray ID: x, your server IP: y)" reference */
            __('Your server could not reach the IDEM servers — the request was blocked by Cloudflare on our side. Please contact IDEM support with this reference so we can allow your server%s.', 'woocommerce-gateway-idem'),
            $ref
        );

        return ['ray' => $ray_id, 'ip' => $ip, 'message' => $message];
    }

    /**
     * Turn an error response body into a short, safe message: the JSON error message when our API
     * answered with JSON, otherwise a stripped/truncated snippet (never a full HTML page).
     *
     * @param string $body
     * @return string
     */
    private static function summarize_error_body($body) {
        $body = trim((string) $body);
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if (!empty($decoded['message'])) return (string) $decoded['message'];
            if (!empty($decoded['msg']))     return (string) $decoded['msg'];
            return wp_json_encode($decoded);
        }
        if (stripos($body, '<html') !== false || strlen($body) > 300) {
            $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($body)));
            if (strlen($text) > 200) { $text = substr($text, 0, 200) . '…'; }
            return $text !== '' ? $text : __('Unexpected non-JSON response', 'woocommerce-gateway-idem');
        }
        return $body;
    }

    /**
     * Ping the API
     *
     * @return array|WP_Error
     */
    public function ping() {
        return $this->request("ping", [], "GET");
    }

    /**
     * Check the API authentication
     *
     * @return array|WP_Error
     */
    public function check_auth() {
        return $this->request("auth", [], "GET");
    }

    /**
     * Get the list of our notification-server IP addresses the merchant should whitelist.
     *
     * @return array|WP_Error { status, data: [ "1.2.3.4", ... ] }
     */
    public function get_ips() {
        return $this->request("ip", [], "GET");
    }

    /**
     * Ask the platform to probe whether OUR servers can reach this store's IPN (notification) URL —
     * detects a merchant-side firewall/WAF silently blocking our inbound IPNs.
     *
     * @param int|null $sub_id Optionally test a sub-account's own webhook.
     * @return array|WP_Error { status, data: { reachable, blocked, http_code, blocked_by, ray, url } }
     */
    public function verify_notification($sub_id = null) {
        $args = ($sub_id) ? ['sub_id' => (int) $sub_id] : [];
        return $this->request('verify-notification', $args, 'POST');
    }

    /**
     * List the merchant's sub-accounts for this environment (Bearer = the env's API token).
     *
     * @return array|WP_Error { status, data: { subaccounts_enabled, subaccounts:[...] } }
     */
    public function get_subaccounts() {
        return $this->request('subaccounts', [], 'GET');
    }

    /**
     * Create a sub-account for the calling store, seeded from the store payload.
     *
     * @param array $payload { store_url, store_name, site_url?, cs_email?, cs_number?, cs_message?, logo_url?, reference? }
     * @return array|WP_Error { status, data: { subaccount, created, warnings } }
     */
    public function create_subaccount($payload) {
        return $this->request('subaccounts', $payload, 'POST');
    }

    /**
     * Get the merchant's customer-support info (phone / email / message) for this environment.
     *
     * @return array|WP_Error { status, data: { cs_number, cs_email, cs_message } }
     */
    public function get_customer_support($sub_id = null) {
        $args = ($sub_id) ? ['sub_id' => (int) $sub_id] : [];
        return $this->request('customer-support', $args, 'GET');
    }

    /**
     * Update the merchant's customer-support info. Email and message are required on the platform.
     *
     * @param array $payload { cs_email, cs_message, cs_number? }
     * @return array|WP_Error { status, data: { cs_number, cs_email, cs_message, warnings } }
     */
    public function update_customer_support($payload) {
        return $this->request('customer-support', $payload, 'POST');
    }

    /**
     * Create a payment request
     *
     * @param array $payment_request Payment request data
     * @param array $customer Customer data
     * @return array|WP_Error
     */
    public function create_payment_request($payment_request, $customer) {
        return $this->request('payment-request/create', [
            'payment_request' => $payment_request,
            'customer' => $customer,
        ], 'POST');
    }

    /**
     * Get a payment request
     *
     * @param int $id Payment request ID
     * @return array|WP_Error
     */
    public function get_payment_request($id) {
        return $this->request("payment-request/get/{$id}");
    }

    /**
     * Cancel a payment request
     *
     * @param int $id Payment request ID
     * @param string $reason Cancellation reason
     * @return array|WP_Error
     */
    public function cancel_payment_request($id, $reason = '') {
        return $this->request("payment-request/cancel/{$id}", [
            'reason' => $reason,
        ]);
    }

    /**
     * Get a request/transaction
     *
     * @param int $id Request ID
     * @return array|WP_Error
     */
    public function get_request($id) {
        return $this->request("request/get/{$id}");
    }

    /**
     * Search requests/transactions by order_id
     *
     * @param int $order_id Order ID
     * @return array|WP_Error
     */
    public function search_requests($criterias = []) {
        // Example of criterias:
        // [
        //     'reference' => '1234',
        //     'status' => 'completed'
        // ]
        return $this->request("requests/search", $criterias, "POST");
    }

    /**
     * Refund a request/transaction
     *
     * @param int    $id Request ID
     * @param string $secret_key Secret key for refunds
     * @param string $reason Refund reason
     * @param float  $amount Refund amount (optional)
     * @param string $currency Currency code (required if amount is specified)
     * @return array|WP_Error
     */
    public function refund_request($id, $secret_key, $reason, $amount = null, $currency = null) {
        $args = [
            'id' => $id,
            'secret_key' => $secret_key,
            'reason' => $reason,
        ];

        if ($amount !== null) {
            $args['amount'] = $amount;
            $args['currency'] = $currency;
        }

        return $this->request('request/refund', $args, 'POST');
    }

    /**
     * Get customer details
     *
     * @param int|string $id_or_email Customer ID or email
     * @return array|WP_Error
     */
    public function get_customer($id_or_email) {
        $args = is_email($id_or_email) ? ['email' => $id_or_email] : ['id' => $id_or_email];
        return $this->request('customer/get', $args);
    }

    /**
     * Stop/Cancel a recurring payment
     *
     * @param string $request_id The recurring payment request ID
     * @return array|WP_Error
     */
    public function stop_recurring_payment($request_id) {
        return $this->request('recurring/' . $request_id . '/stop', [], 'POST');
    }

    /**
     * Suspend a recurring payment
     *
     * @param string $request_id The recurring payment request ID
     * @param string $reason Optional reason for suspension
     * @return array|WP_Error
     */
    public function suspend_recurring_payment($request_id, $reason = '') {
        $data = [];
        if (!empty($reason)) {
            $data['reason'] = $reason;
        }
        return $this->request('recurring/' . $request_id . '/suspend', $data, 'POST');
    }

    /**
     * Reactivate a suspended recurring payment
     *
     * @param string $request_id The recurring payment request ID
     * @return array|WP_Error
     */
    public function reactivate_recurring_payment($request_id) {
        return $this->request('recurring/' . $request_id . '/reactivate', [], 'POST');
    }

    /**
     * Update the amount of a recurring payment
     *
     * @param string $request_id The recurring payment request ID
     * @param float  $amount The new amount
     * @param string $scope The scope of the update ('single' or 'all')
     * @return array|WP_Error
     */
    public function update_recurring_amount($request_id, $amount, $scope = 'all') {
        $data = [
            'amount' => $amount,
            'scope' => $scope,
        ];
        return $this->request('recurring/' . $request_id . '/amount', $data, 'POST');
    }

    /**
     * Update the number of cycles of a recurring payment
     *
     * @param string $request_id The recurring payment request ID
     * @param int    $cycles The new number of cycles
     * @return array|WP_Error
     */
    public function update_recurring_cycles($request_id, $cycles) {
        $data = [
            'cycles' => $cycles,
        ];
        return $this->request('recurring/' . $request_id . '/cycles', $data, 'POST');
    }

    /**
     * Skip the next payment of a recurring subscription
     *
     * @param string $request_id The recurring payment request ID
     * @param bool   $extend Whether to extend the subscription by one cycle
     * @return array|WP_Error
     */
    public function skip_recurring_payment($request_id, $extend = false) {
        $data = [
            'extend' => $extend,
        ];
        return $this->request('recurring/' . $request_id . '/skip', $data, 'POST');
    }

    public function update_recurring_end_date($request_id, $end_date) {
        return $this->request("recurring/{$request_id}/enddate", [
            'end_date' => $end_date,
        ], 'POST');
    }

    /**
     * Update the interval and periodicity for a recurring payment
     *
     * @param string $request_id The recurring payment request ID
     * @param int    $interval The interval (e.g., 1, 3, 6)
     * @param string $periodicity The periodicity (e.g., 'months', 'weeks')
     * @param string $next_date Optional next payment date to sync schedule exactly
     * @return array|WP_Error
     */
    public function update_recurring_interval($request_id, $interval, $periodicity, $next_date = null) {
        $data = [
            'interval' => $interval,
            'periodicity' => $periodicity,
        ];

        if ($next_date !== null) {
            $data['next_date'] = $next_date;
        }

        return $this->request("recurring/{$request_id}/interval", $data, 'POST');
    }
} 