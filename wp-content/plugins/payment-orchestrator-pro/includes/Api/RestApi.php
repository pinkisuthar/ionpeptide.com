<?php
/**
 * REST API for Payment Orchestrator Pro
 * 
 * Provides read-only endpoints for external dashboard consumption (e.g., Loveable).
 * 
 * Security layers:
 *   1. API key authentication (SHA-256 hashed, compared via hash_equals)
 *   2. Optional IP allowlist
 *   3. Rate limiting (60 requests/minute per key)
 *   4. HTTPS enforcement (production only)
 *   5. Read-only - all endpoints are GET, no mutations possible
 *   6. No customer PII in responses (emails, names, addresses stripped)
 * 
 * Base URL: /wp-json/po/v1/
 */

namespace PaymentOrchestrator\Api;

use PaymentOrchestrator\Core\Logger;

class RestApi {
    
    private const NAMESPACE = 'po/v1';
    private const RATE_LIMIT = 60;       // requests per window
    private const RATE_WINDOW = 60;      // seconds
    private const MAX_PER_PAGE = 500;
    private const DEFAULT_PER_PAGE = 100;
    
    /**
     * Container instance for accessing POP services.
     */
    private $container;
    
    public function __construct($container) {
        $this->container = $container;
    }
    
    /**
     * Register all REST routes. Hooked to rest_api_init.
     */
    public function registerRoutes(): void {
        // -- Diagnostic endpoint (no auth required) --
        // Returns connection status so external apps can debug issues.
        // Never exposes data - only reports which auth checks would pass/fail.
        register_rest_route(self::NAMESPACE, '/ping', [
            'methods'  => 'GET',
            'callback' => [$this, 'ping'],
            'permission_callback' => '__return_true',
        ]);
        
        // Gateway configuration + live status
        register_rest_route(self::NAMESPACE, '/gateways', [
            'methods'  => 'GET',
            'callback' => [$this, 'getGateways'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
        
        // Transaction records (paginated)
        register_rest_route(self::NAMESPACE, '/transactions', [
            'methods'  => 'GET',
            'callback' => [$this, 'getTransactions'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
        
        // Failure records with recovery info (paginated)
        register_rest_route(self::NAMESPACE, '/failures', [
            'methods'  => 'GET',
            'callback' => [$this, 'getFailures'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
        
        // Today's dashboard snapshot
        register_rest_route(self::NAMESPACE, '/stats/today', [
            'methods'  => 'GET',
            'callback' => [$this, 'getStatsToday'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
        
        // Monthly aggregated stats
        register_rest_route(self::NAMESPACE, '/stats/monthly', [
            'methods'  => 'GET',
            'callback' => [$this, 'getStatsMonthly'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
        
        // Recovery stats (today + selected month)
        register_rest_route(self::NAMESPACE, '/stats/recovery', [
            'methods'  => 'GET',
            'callback' => [$this, 'getStatsRecovery'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
        
        // Real-time gateway health scores
        register_rest_route(self::NAMESPACE, '/stats/health', [
            'methods'  => 'GET',
            'callback' => [$this, 'getStatsHealth'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
        
        // Current rotation weights and scores
        register_rest_route(self::NAMESPACE, '/stats/weights', [
            'methods'  => 'GET',
            'callback' => [$this, 'getStatsWeights'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
    }
    
    // ---------------------------------------------------------
    // AUTHENTICATION & SECURITY
    // ---------------------------------------------------------
    
    /**
     * Authenticate the request. Used as permission_callback on all routes.
     * 
     * Returns true on success, WP_Error with specific code on failure.
     * This lets external apps see WHY authentication failed instead of
     * a generic "rest_forbidden".
     * 
     * @return true|\WP_Error
     */
    public function authenticate(\WP_REST_Request $request) {
        // 1. HTTPS enforcement (proxy-aware)
        // Many hosts (Convesio, Cloudflare, load balancers) terminate SSL at the
        // proxy and forward to PHP over HTTP. is_ssl() only checks $_SERVER['HTTPS']
        // which is empty behind a proxy. Also check X-Forwarded-Proto header.
        $isSsl = is_ssl() 
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
        $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        
        if (!$isSsl && !$isLocalhost) {
            if (get_option('po_api_require_ssl', 'yes') === 'yes') {
                Logger::warning('API rejected: non-SSL request', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
                return new \WP_Error(
                    'po_ssl_required',
                    'HTTPS is required for API access.',
                    ['status' => 403]
                );
            }
        }
        
        // 2. Check API is enabled
        if (get_option('po_api_enabled', 'no') !== 'yes') {
            Logger::debug('API rejected: API is disabled');
            return new \WP_Error(
                'po_api_disabled',
                'The POP REST API is not enabled. Enable it in Settings ' . "\u{2192}" . ' REST API.',
                ['status' => 403]
            );
        }
        
        // 3. API key extraction - try multiple header formats
        // Different proxies, CDNs, and hosting platforms may transform or strip
        // custom headers. We accept the key from several sources:
        //   - X-PO-API-Key header (primary)
        //   - Authorization: Bearer <key> (standard REST pattern)
        //   - api_key query parameter (fallback for testing)
        $providedKey = $request->get_header('X_PO_API_Key');
        
        if (empty($providedKey)) {
            $providedKey = $request->get_header('X-PO-API-Key');
        }
        
        if (empty($providedKey)) {
            // Try Authorization: Bearer header
            $authHeader = $request->get_header('Authorization');
            if (!empty($authHeader) && stripos($authHeader, 'Bearer ') === 0) {
                $providedKey = trim(substr($authHeader, 7));
            }
        }
        
        if (empty($providedKey)) {
            // Try raw $_SERVER in case WP_REST_Request doesn't find it
            $providedKey = $_SERVER['HTTP_X_PO_API_KEY'] ?? '';
        }
        
        if (empty($providedKey)) {
            // Query param fallback
            $providedKey = $request->get_param('api_key');
        }
        
        if (empty($providedKey)) {
            Logger::warning('API rejected: no API key provided', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'headers_present' => implode(', ', array_filter([
                    $request->get_header('X_PO_API_Key') ? 'X_PO_API_Key' : '',
                    $request->get_header('X-PO-API-Key') ? 'X-PO-API-Key' : '',
                    $request->get_header('Authorization') ? 'Authorization' : '',
                    isset($_SERVER['HTTP_X_PO_API_KEY']) ? 'HTTP_X_PO_API_KEY' : '',
                ])),
            ]);
            return new \WP_Error(
                'po_missing_key',
                'API key is required. Send via X-PO-API-Key header, Authorization: Bearer header, or api_key query parameter.',
                ['status' => 401]
            );
        }
        
        // 4. API key validation
        $storedHash = get_option('po_api_key_hash', '');
        if (empty($storedHash)) {
            Logger::warning('API rejected: no API key has been generated');
            return new \WP_Error(
                'po_no_key_configured',
                'No API key has been generated. Generate one in Settings ' . "\u{2192}" . ' REST API.',
                ['status' => 403]
            );
        }
        
        // Constant-time comparison to prevent timing attacks
        $providedHash = hash('sha256', $providedKey);
        if (!hash_equals($storedHash, $providedHash)) {
            Logger::warning('API authentication failed: key mismatch', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'provided_prefix' => substr($providedKey, 0, 7) . '...',
            ]);
            return new \WP_Error(
                'po_invalid_key',
                'Invalid API key.',
                ['status' => 401]
            );
        }
        
        // 5. IP allowlist (if configured)
        $allowlist = get_option('po_api_ip_allowlist', '');
        if (!empty($allowlist)) {
            $allowedIps = array_map('trim', explode(',', $allowlist));
            $allowedIps = array_filter($allowedIps);
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            
            if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
                Logger::warning('API request from non-allowlisted IP', [
                    'ip' => $clientIp,
                    'allowlist' => $allowlist,
                ]);
                return new \WP_Error(
                    'po_ip_blocked',
                    'Your IP address is not in the allowlist.',
                    ['status' => 403]
                );
            }
        }
        
        // 6. Rate limiting
        $rateKey = 'po_api_rate_' . substr($providedHash, 0, 16);
        $requests = (int) get_transient($rateKey);
        
        if ($requests >= self::RATE_LIMIT) {
            Logger::warning('API rate limit exceeded', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'requests' => $requests,
            ]);
            return new \WP_Error(
                'po_rate_limited',
                'Rate limit exceeded. Maximum ' . self::RATE_LIMIT . ' requests per minute.',
                ['status' => 429]
            );
        }
        
        set_transient($rateKey, $requests + 1, self::RATE_WINDOW);
        
        return true;
    }
    
    /**
     * GET /po/v1/ping
     * Unauthenticated diagnostic endpoint. Reports which auth checks
     * would pass/fail without exposing any data.
     */
    public function ping(\WP_REST_Request $request): \WP_REST_Response {
        $apiEnabled = get_option('po_api_enabled', 'no') === 'yes';
        $hasKey = !empty(get_option('po_api_key_hash', ''));
        $allowlist = get_option('po_api_ip_allowlist', '');
        $requireSsl = get_option('po_api_require_ssl', 'yes') === 'yes';
        
        $isSsl = is_ssl()
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
        
        // Check which headers the request sent (without reading values)
        $headersReceived = array_filter([
            'X_PO_API_Key' => !empty($request->get_header('X_PO_API_Key')),
            'X-PO-API-Key' => !empty($request->get_header('X-PO-API-Key')),
            'Authorization' => !empty($request->get_header('Authorization')),
            'HTTP_X_PO_API_KEY_raw' => isset($_SERVER['HTTP_X_PO_API_KEY']),
            'api_key_param' => !empty($request->get_param('api_key')),
        ]);
        
        return new \WP_REST_Response([
            'status' => 'reachable',
            'plugin_version' => defined('PO_VERSION') ? PO_VERSION : 'unknown',
            'api_enabled' => $apiEnabled,
            'api_key_configured' => $hasKey,
            'ssl_detected' => $isSsl,
            'ssl_required' => $requireSsl,
            'ssl_would_pass' => $isSsl || !$requireSsl,
            'ip_allowlist_active' => !empty($allowlist),
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'ip_would_pass' => empty($allowlist) || in_array($_SERVER['REMOTE_ADDR'] ?? '', array_map('trim', explode(',', $allowlist)), true),
            'auth_headers_received' => $headersReceived,
            'generated_at' => current_time('c'),
        ], 200);
    }
    
    // ---------------------------------------------------------
    // ENDPOINT HANDLERS
    // ---------------------------------------------------------
    
    /**
     * GET /po/v1/gateways
     * Returns all gateway configurations with live health and pacing data.
     */
    public function getGateways(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $repo = $this->container->get(\PaymentOrchestrator\Gateway\GatewayRepository::class);
            $tracker = $this->container->get(\PaymentOrchestrator\Volume\VolumeTracker::class);
            $health = $this->container->get(\PaymentOrchestrator\Health\HealthMonitor::class);
            
            $gateways = $repo->getAll();
            $allStats = $tracker->getAllStats();
            $healthData = $health->getAllHealth();
            
            $statsMap = [];
            foreach ($allStats as $s) {
                $statsMap[$s['gateway_id']] = $s;
            }
            
            $result = [];
            foreach ($gateways as $gw) {
                $stat = $statsMap[$gw->id] ?? [];
                $h = $healthData[$gw->id] ?? [];
                
                $result[] = [
                    'id'              => $gw->id,
                    'display_name'    => $gw->displayName,
                    'gateway_type'    => $gw->gatewayType ?? 'card',
                    'is_enabled'      => $gw->isEnabled,
                    'is_always_on'    => $gw->isAlwaysOn ?? false,
                    'monthly_target'  => $gw->monthlyTarget,
                    'priority'        => $gw->priority,
                    'accepts_visa'    => $gw->acceptsVisa ?? true,
                    'accepts_mastercard' => $gw->acceptsMastercard ?? true,
                    'accepts_amex'    => $gw->acceptsAmex ?? true,
                    'accepts_discover'=> $gw->acceptsDiscover ?? true,
                    // Live stats
                    'month_volume'    => (float) ($stat['volume'] ?? 0),
                    'month_success'   => (int) ($stat['success'] ?? 0),
                    'month_failed'    => (int) ($stat['failed'] ?? 0),
                    'today_volume'    => (float) ($stat['today_volume'] ?? 0),
                    'pace_status'     => $stat['pace_status'] ?? 'unknown',
                    'pace_percentage' => (float) ($stat['pace_percentage'] ?? 0),
                    // Health
                    'health_score'    => (float) ($h['score'] ?? 100),
                    'health_status'   => $h['status'] ?? 'excellent',
                ];
            }
            
            return new \WP_REST_Response([
                'gateways' => $result,
                'generated_at' => current_time('c'),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to load gateways', $e);
        }
    }
    
    /**
     * GET /po/v1/transactions
     * Paginated transaction records. Filterable by gateway, status, date range.
     * 
     * Params: gateway, status (success|failed), date_from, date_to, page, per_page
     */
    public function getTransactions(\WP_REST_Request $request): \WP_REST_Response {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'po_transactions';
            
            $page = max(1, (int) $request->get_param('page') ?: 1);
            $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page') ?: self::DEFAULT_PER_PAGE));
            $offset = ($page - 1) * $perPage;
            
            $where = ['1=1'];
            $params = [];
            
            if ($gateway = $request->get_param('gateway')) {
                $where[] = 'gateway_id = %s';
                $params[] = sanitize_text_field($gateway);
            }
            if ($status = $request->get_param('status')) {
                $where[] = 'status = %s';
                $params[] = sanitize_text_field($status);
            }
            if ($dateFrom = $request->get_param('date_from')) {
                $where[] = 'created_at >= %s';
                $params[] = sanitize_text_field($dateFrom) . ' 00:00:00';
            }
            if ($dateTo = $request->get_param('date_to')) {
                $where[] = 'created_at <= %s';
                $params[] = sanitize_text_field($dateTo) . ' 23:59:59';
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Count total
            $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$whereClause}";
            $total = (int) (empty($params) 
                ? $wpdb->get_var($countSql) 
                : $wpdb->get_var($wpdb->prepare($countSql, ...$params)));
            
            // Fetch page
            $dataSql = "SELECT id, gateway_id, order_id, amount, status, created_at 
                        FROM {$table} WHERE {$whereClause} 
                        ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $dataParams = array_merge($params, [$perPage, $offset]);
            $rows = $wpdb->get_results($wpdb->prepare($dataSql, ...$dataParams), ARRAY_A) ?: [];
            
            // Cast types
            foreach ($rows as &$row) {
                $row['id'] = (int) $row['id'];
                $row['order_id'] = (int) $row['order_id'];
                $row['amount'] = (float) $row['amount'];
            }
            
            return new \WP_REST_Response([
                'transactions' => $rows,
                'pagination' => [
                    'page'      => $page,
                    'per_page'  => $perPage,
                    'total'     => $total,
                    'pages'     => (int) ceil($total / $perPage),
                ],
                'generated_at' => current_time('c'),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to load transactions', $e);
        }
    }
    
    /**
     * GET /po/v1/failures
     * Paginated failure records with recovery details. No PII.
     * 
     * Params: gateway, category, code, recovered (0|1), date_from, date_to, page, per_page
     */
    public function getFailures(\WP_REST_Request $request): \WP_REST_Response {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'po_failed_transactions';
            
            $page = max(1, (int) $request->get_param('page') ?: 1);
            $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page') ?: self::DEFAULT_PER_PAGE));
            $offset = ($page - 1) * $perPage;
            
            $where = ['1=1'];
            $params = [];
            
            if ($gateway = $request->get_param('gateway')) {
                $where[] = 'gateway_id = %s';
                $params[] = sanitize_text_field($gateway);
            }
            if ($category = $request->get_param('category')) {
                $where[] = 'failure_category = %s';
                $params[] = sanitize_text_field($category);
            }
            if ($code = $request->get_param('code')) {
                $where[] = 'failure_code = %s';
                $params[] = sanitize_text_field($code);
            }
            if ($request->get_param('recovered') !== null) {
                $where[] = 'recovered = %d';
                $params[] = (int) $request->get_param('recovered');
            }
            if ($dateFrom = $request->get_param('date_from')) {
                $where[] = 'created_at >= %s';
                $params[] = sanitize_text_field($dateFrom) . ' 00:00:00';
            }
            if ($dateTo = $request->get_param('date_to')) {
                $where[] = 'created_at <= %s';
                $params[] = sanitize_text_field($dateTo) . ' 23:59:59';
            }
            
            $whereClause = implode(' AND ', $where);
            
            $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$whereClause}";
            $total = (int) (empty($params) 
                ? $wpdb->get_var($countSql) 
                : $wpdb->get_var($wpdb->prepare($countSql, ...$params)));
            
            // Explicitly select columns - NO customer_email, NO customer PII
            $dataSql = "SELECT id, order_id, gateway_id, failure_code, failure_reason, 
                               failure_category, card_type, order_total, retry_count,
                               retry_gateway, recovered, recovered_at, recovery_source,
                               month_year, created_at
                        FROM {$table} WHERE {$whereClause} 
                        ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $dataParams = array_merge($params, [$perPage, $offset]);
            $rows = $wpdb->get_results($wpdb->prepare($dataSql, ...$dataParams), ARRAY_A) ?: [];
            
            // Cast types
            foreach ($rows as &$row) {
                $row['id'] = (int) $row['id'];
                $row['order_id'] = (int) $row['order_id'];
                $row['order_total'] = (float) $row['order_total'];
                $row['retry_count'] = (int) $row['retry_count'];
                $row['recovered'] = (bool) $row['recovered'];
            }
            
            return new \WP_REST_Response([
                'failures' => $rows,
                'pagination' => [
                    'page'      => $page,
                    'per_page'  => $perPage,
                    'total'     => $total,
                    'pages'     => (int) ceil($total / $perPage),
                ],
                'generated_at' => current_time('c'),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to load failures', $e);
        }
    }
    
    /**
     * GET /po/v1/stats/today
     * Today's complete dashboard snapshot in a single call.
     */
    public function getStatsToday(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $tracker = $this->container->get(\PaymentOrchestrator\Volume\VolumeTracker::class);
            $health = $this->container->get(\PaymentOrchestrator\Health\HealthMonitor::class);
            
            $todayStats = $tracker->getTodayStatsByGateway();
            $healthData = $health->getAllHealth();
            
            global $wpdb;
            $failTable = $wpdb->prefix . 'po_failed_transactions';
            
            // Aggregate today's fail/recovery from po_failed_transactions
            $failAgg = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                    COUNT(*) AS total_failures,
                    COALESCE(SUM(order_total), 0) AS failed_revenue,
                    SUM(CASE WHEN recovered = 1 THEN 1 ELSE 0 END) AS recovered_count,
                    COALESCE(SUM(CASE WHEN recovered = 1 THEN order_total ELSE 0 END), 0) AS recovered_revenue,
                    SUM(CASE WHEN " . \PaymentOrchestrator\Recovery\FailureClassifier::healthFaultSql() . " THEN 1 ELSE 0 END) AS gateway_fails,
                    SUM(CASE WHEN failure_category = 'non_retryable' THEN 1 ELSE 0 END) AS customer_fails
                FROM {$failTable} WHERE created_at >= %s AND created_at < %s",
                ...\PaymentOrchestrator\Core\SiteTime::dayUtcRange()
            ));
            
            // Per-gateway breakdown
            $gateways = [];
            foreach ($todayStats as $gw) {
                $total = $gw['success'] + $gw['fail'];
                $h = $healthData[$gw['gateway_id']] ?? [];
                
                // Gateway-attributable failures for success rate calculation
                $gwFails = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT order_id) FROM {$failTable}
                    WHERE gateway_id = %s AND created_at >= %s AND created_at < %s
                    AND " . \PaymentOrchestrator\Recovery\FailureClassifier::healthFaultSql(),
                    $gw['gateway_id'], ...\PaymentOrchestrator\Core\SiteTime::dayUtcRange()
                ));
                $rateBase = $gw['success'] + $gwFails;
                
                $gateways[] = [
                    'gateway_id'   => $gw['gateway_id'],
                    'gateway_name' => $gw['gateway_name'],
                    'volume'       => (float) $gw['volume'],
                    'success'      => (int) $gw['success'],
                    'fail'         => (int) $gw['fail'],
                    'total'        => $total,
                    'success_rate' => $rateBase > 0 ? round(($gw['success'] / $rateBase) * 100, 1) : 100,
                    'health_score' => (float) ($h['score'] ?? 100),
                    'health_status'=> $h['status'] ?? 'excellent',
                ];
            }
            
            $totalProcessed = array_sum(array_column($gateways, 'volume'));
            $totalTransactions = array_sum(array_column($gateways, 'total'));
            $totalSuccess = array_sum(array_column($gateways, 'success'));
            $totalGatewayFails = (int) ($failAgg->gateway_fails ?? 0);
            $summaryRateBase = $totalSuccess + $totalGatewayFails;
            
            return new \WP_REST_Response([
                'summary' => [
                    'total_processed'      => $totalProcessed,
                    'total_transactions'   => $totalTransactions,
                    'success_rate'         => $summaryRateBase > 0 ? round(($totalSuccess / $summaryRateBase) * 100, 1) : 100,
                    'failed_revenue'       => (float) ($failAgg->failed_revenue ?? 0),
                    'recovered_revenue'    => (float) ($failAgg->recovered_revenue ?? 0),
                    'recovered_count'      => (int) ($failAgg->recovered_count ?? 0),
                    'total_failures'       => (int) ($failAgg->total_failures ?? 0),
                    'gateway_fails'        => (int) ($failAgg->gateway_fails ?? 0),
                    'customer_fails'       => (int) ($failAgg->customer_fails ?? 0),
                    'recovery_rate'        => ($failAgg->total_failures ?? 0) > 0 
                        ? round(((int)$failAgg->recovered_count / (int)$failAgg->total_failures) * 100, 1) : 0,
                ],
                'gateways' => $gateways,
                'generated_at' => current_time('c'),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to load today stats', $e);
        }
    }
    
    /**
     * GET /po/v1/stats/monthly
     * Monthly aggregated data. Param: month (YYYY-MM, default current).
     */
    public function getStatsMonthly(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $month = sanitize_text_field($request->get_param('month') ?: current_time('Y-m'));
            
            // Validate format
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                return new \WP_REST_Response(['error' => 'Invalid month format. Use YYYY-MM.'], 400);
            }
            
            global $wpdb;
            $txnTable = $wpdb->prefix . 'po_transactions';
            $failTable = $wpdb->prefix . 'po_failed_transactions';
            
            // Transaction aggregates by gateway
            $txnRows = $wpdb->get_results($wpdb->prepare(
                "SELECT gateway_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS successful,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
                    COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END), 0) AS volume
                FROM {$txnTable}
                WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s
                GROUP BY gateway_id",
                $month
            ), ARRAY_A) ?: [];
            
            // Failure/recovery aggregates by gateway
            $failRows = $wpdb->get_results($wpdb->prepare(
                "SELECT gateway_id,
                    COUNT(*) AS fail_count,
                    COALESCE(SUM(order_total), 0) AS lost_revenue,
                    SUM(CASE WHEN " . \PaymentOrchestrator\Recovery\FailureClassifier::healthFaultSql() . " THEN 1 ELSE 0 END) AS gw_fails,
                    SUM(CASE WHEN failure_category = 'non_retryable' THEN 1 ELSE 0 END) AS cust_fails
                FROM {$failTable}
                WHERE month_year = %s
                GROUP BY gateway_id",
                $month
            ), ARRAY_A) ?: [];
            
            // Recovery by receiving gateway
            $recoveryRows = $wpdb->get_results($wpdb->prepare(
                "SELECT retry_gateway,
                    COUNT(*) AS recovered_count,
                    COALESCE(SUM(order_total), 0) AS recovered_revenue
                FROM {$failTable}
                WHERE month_year = %s AND recovered = 1
                GROUP BY retry_gateway",
                $month
            ), ARRAY_A) ?: [];
            
            // Daily breakdown for charting
            $dailyRows = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(created_at) AS date,
                    gateway_id,
                    SUM(CASE WHEN status='success' THEN amount ELSE 0 END) AS volume,
                    SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS success,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed
                FROM {$txnTable}
                WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s
                GROUP BY DATE(created_at), gateway_id
                ORDER BY date",
                $month
            ), ARRAY_A) ?: [];
            
            return new \WP_REST_Response([
                'month' => $month,
                'by_gateway' => [
                    'transactions' => $txnRows,
                    'failures' => $failRows,
                    'recoveries' => $recoveryRows,
                ],
                'daily' => $dailyRows,
                'generated_at' => current_time('c'),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to load monthly stats', $e);
        }
    }
    
    /**
     * GET /po/v1/stats/recovery
     * Recovery stats for today + selected month, top failure codes, gateway pairs.
     */
    public function getStatsRecovery(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $month = sanitize_text_field($request->get_param('month') ?: current_time('Y-m'));
            
            $FailureHandler = \PaymentOrchestrator\Recovery\FailureHandler::class;
            
            return new \WP_REST_Response([
                'today' => [
                    'stats'          => $FailureHandler::getRecoveryStatsToday(),
                    'top_codes'      => $FailureHandler::getTopFailureCodesToday(15),
                    'gateway_pairs'  => $FailureHandler::getRecoveryByGatewayPairToday(),
                ],
                'month' => [
                    'month_year'     => $month,
                    'stats'          => $FailureHandler::getRecoveryStats($month),
                    'top_codes'      => $FailureHandler::getTopFailureCodes($month, 15),
                    'gateway_pairs'  => $FailureHandler::getRecoveryByGatewayPair($month),
                ],
                'generated_at' => current_time('c'),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to load recovery stats', $e);
        }
    }
    
    /**
     * GET /po/v1/stats/health
     * Real-time gateway health scores.
     */
    public function getStatsHealth(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $health = $this->container->get(\PaymentOrchestrator\Health\HealthMonitor::class);
            $allHealth = $health->getAllHealth();
            
            $result = [];
            foreach ($allHealth as $gwId => $h) {
                $result[] = [
                    'gateway_id'    => $gwId,
                    'score'         => (float) ($h['score'] ?? 100),
                    'status'        => $h['status'] ?? 'excellent',
                    'recent_success'=> (int) ($h['recent_success'] ?? 0),
                    'recent_failed' => (int) ($h['recent_failed'] ?? 0),
                    'window_size'   => (int) ($h['window_size'] ?? 0),
                ];
            }
            
            return new \WP_REST_Response([
                'health' => $result,
                'generated_at' => current_time('c'),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to load health data', $e);
        }
    }
    
    /**
     * GET /po/v1/stats/weights
     * Current rotation weights and scoring breakdown.
     */
    public function getStatsWeights(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $rotation = $this->container->get(\PaymentOrchestrator\Routing\RotationManager::class);
            $weights = $rotation->getCurrentWeights();
            
            return new \WP_REST_Response([
                'weights' => $weights,
                'generated_at' => current_time('c'),
            ], 200);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to load weights', $e);
        }
    }
    
    // ---------------------------------------------------------
    // API KEY MANAGEMENT (called from AdminController)
    // ---------------------------------------------------------
    
    /**
     * Generate a new API key. Returns the plain-text key (shown once).
     * Stores only the SHA-256 hash.
     */
    public static function generateApiKey(): string {
        $plainKey = 'po_' . wp_generate_password(48, false, false);
        $hash = hash('sha256', $plainKey);
        
        update_option('po_api_key_hash', $hash);
        update_option('po_api_key_created', current_time('mysql'));
        update_option('po_api_key_prefix', substr($plainKey, 0, 7) . '...');
        
        Logger::info('API key generated', [
            'prefix' => substr($plainKey, 0, 7) . '...',
        ]);
        
        return $plainKey;
    }
    
    /**
     * Revoke the current API key.
     */
    public static function revokeApiKey(): void {
        delete_option('po_api_key_hash');
        delete_option('po_api_key_created');
        delete_option('po_api_key_prefix');
        
        Logger::info('API key revoked');
    }
    
    // ---------------------------------------------------------
    // HELPERS
    // ---------------------------------------------------------
    
    private function errorResponse(string $message, \Throwable $e): \WP_REST_Response {
        Logger::error('REST API error: ' . $message, ['error' => $e->getMessage()]);
        return new \WP_REST_Response([
            'error' => $message,
            'generated_at' => current_time('c'),
        ], 500);
    }
}
