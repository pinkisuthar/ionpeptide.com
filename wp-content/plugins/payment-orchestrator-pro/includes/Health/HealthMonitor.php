<?php
/**
 * HealthMonitor - Tracks gateway health based on success/failure rates
 *
 * ARCHITECTURE:
 * Health scores are now computed from the po_transactions table, which VolumeTracker
 * already writes to on every order status change. This replaces the previous transient-based
 * approach where health data was lost on cache clears.
 *
 * The po_transactions table is the single source of truth:
 *   - VolumeTracker::recordTransaction() writes success records
 *   - VolumeTracker::recordFailure() writes failure records
 *   - HealthMonitor reads from these records to compute health scores
 *
 * Per-request caching prevents repeated DB queries within a single request.
 *
 * Failure alerting still uses transients - alerts are ephemeral by nature and
 * it's acceptable (even desirable) to re-alert after a cache clear.
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Health;

use PaymentOrchestrator\Gateway\GatewayRepository;
use PaymentOrchestrator\Core\Logger;

final class HealthMonitor {
    private GatewayRepository $gateways;
    
    // Health thresholds
    public const HEALTH_EXCELLENT = 95;  // Green
    public const HEALTH_GOOD = 85;       // Yellow
    public const HEALTH_DEGRADED = 70;   // Orange - 50% traffic reduction
    public const HEALTH_CRITICAL = 70;   // Below this = Red, no traffic
    
    // Traffic multipliers based on health
    public const MULTIPLIER_EXCELLENT = 1.0;
    public const MULTIPLIER_GOOD = 0.9;
    public const MULTIPLIER_DEGRADED = 0.5;
    public const MULTIPLIER_CRITICAL = 0.0;
    
    // Minimum sample sizes before penalizing a gateway
    private const MIN_SAMPLE_24H = 10;
    private const MIN_SAMPLE_1H = 5;
    
    /**
     * Per-request cache of health stats keyed by gateway ID.
     * Populated lazily on first access, shared across all calls in the same request.
     *
     * @var array<string, array{stats_1h: array, stats_24h: array}>|null
     */
    private ?array $statsCache = null;
    
    public function __construct(GatewayRepository $gateways) {
        $this->gateways = $gateways;
    }
    
    /**
     * Record a successful transaction for health tracking.
     * 
     * NOTE: The actual DB write is handled by VolumeTracker::recordTransaction().
     * HealthMonitor reads from the same table. We just invalidate our per-request cache
     * so subsequent health checks within this request see the latest data.
     */
    public function recordSuccess(string $gatewayId): void {
        $this->invalidateCache();
    }
    
    /**
     * Record a failed transaction for health tracking.
     * 
     * NOTE: The actual DB write is handled by VolumeTracker::recordFailure().
     * We invalidate cache and check failure alerting.
     *
     * IMPORTANT (v2.4.2): Only gateway-side failures count against health.
     * Customer-caused failures (bad CVV, insufficient funds, etc.) do NOT
     * penalize the gateway. This is determined by the $countsAgainstHealth parameter.
     *
     * @param string $gatewayId The gateway that processed the failed transaction
     * @param bool $countsAgainstHealth Whether this failure should count against health scoring.
     *             true = gateway problem (timeout, merchant ID error, etc.)
     *             false = customer problem (bad CVV, expired card, etc.)
     *             Default: false (safe default - unknown failures don't penalize gateway)
     */
    public function recordFailure(string $gatewayId, bool $countsAgainstHealth = false): void {
        $this->invalidateCache();
        
        // Check if we need to send a failure alert (alerts fire for ALL failures regardless)
        $this->checkFailureAlert($gatewayId);
    }
    
    /**
     * Invalidate per-request cache.
     * Called after any health-affecting event within the same request.
     */
    private function invalidateCache(): void {
        $this->statsCache = null;
    }
    
    /**
     * Load health stats for ALL gateways in a single efficient query.
     * Returns 1h and 24h success/failure counts per gateway.
     *
     * Uses two queries total (1h window + 24h window) regardless of gateway count.
     */
    private function loadAllStats(): void {
        if ($this->statsCache !== null) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'po_transactions';
        $now = current_time('mysql');
        
        // Initialize cache for all managed gateways
        $this->statsCache = [];
        $allGateways = $this->gateways->getAll();
        foreach ($allGateways as $gateway) {
            $this->statsCache[$gateway->id] = [
                'stats_1h' => ['total' => 0, 'success' => 0, 'failure' => 0],
                'stats_24h' => ['total' => 0, 'success' => 0, 'failure' => 0],
            ];
        }
        
        // Single query for 24h stats (which includes 1h data)
        // We compute both windows from one result set.
        // Uses NOW() to match the UTC timestamps stored by CURRENT_TIMESTAMP default.
        // Health uses rolling windows (1h, 24h) so UTC vs local offset doesn't
        // affect the window size - it just shifts it, which is fine for health scoring.
        //
        // IMPORTANT (v2.4.2): Only gateway-side failures count against health.
        // Customer-caused failures (bad CVV, insufficient funds, etc.) are excluded.
        //
        // Two paths for failure detection:
        //   1. NMI: hardcoded error codes (400, 410, 420, etc.) from po_transactions
        //   2. GP + all gateways: failure_category from po_failed_transactions
        //      (retryable_gateway = processor's fault, counts against health)
        //
        // A failure counts if EITHER path matches. This ensures GP failures
        // are visible to health scoring even though they use string codes
        // that the NMI error code list doesn't recognize.
        $failTable = $wpdb->prefix . 'po_failed_transactions';
        $rows = $wpdb->get_results(
            "SELECT 
                t.gateway_id,
                CASE 
                    WHEN t.status = 'success' THEN 'success'
                    WHEN t.status = 'failed' AND (
                        t.error_code IN ('400', '410', '420')
                        OR (t.error_code = '440' AND (t.error_message LIKE '%Re-enter%'))  /* 2.7.25: 440 Invalid amount = card amount cap, not a processor fault */
                        OR (t.error_code = '300' AND (
                            t.error_message LIKE '%Payment Token%'
                            OR t.error_message LIKE '%Duplicate transaction%'
                            OR t.error_message LIKE '%Error Processing%'
                            OR t.error_message LIKE '%Authentication Failed%'
                        ))
                        OR t.error_message LIKE '%cURL error%'
                        OR t.error_message LIKE '%timed out%'
                        OR t.error_message LIKE '%Unrecognized response%'
                        OR t.error_message LIKE '%error with the gateway%'
                        OR EXISTS (
                            SELECT 1 FROM {$failTable} f
                            WHERE f.order_id = t.order_id
                            AND f.gateway_id = t.gateway_id
                            AND " . \PaymentOrchestrator\Recovery\FailureClassifier::healthFaultSql('f.') . "
                        )
                    ) THEN 'failed'
                    ELSE 'ignored'
                END as health_status,
                CASE WHEN t.created_at >= NOW() - INTERVAL 1 HOUR THEN 1 ELSE 0 END as is_last_1h,
                COUNT(*) as cnt
            FROM {$table} t
            WHERE t.created_at >= NOW() - INTERVAL 24 HOUR
            AND t.status IN ('success', 'failed')
            GROUP BY t.gateway_id, health_status, is_last_1h
            HAVING health_status != 'ignored'",
            ARRAY_A
        );
        
        if (!$rows) {
            return;
        }
        
        foreach ($rows as $row) {
            $gid = $row['gateway_id'];
            $count = (int) $row['cnt'];
            $isSuccess = $row['health_status'] === 'success';
            $isLast1h = (int) $row['is_last_1h'] === 1;
            
            if (!isset($this->statsCache[$gid])) {
                // Gateway in transactions but not in our config - skip
                continue;
            }
            
            // All rows contribute to 24h stats
            $this->statsCache[$gid]['stats_24h']['total'] += $count;
            if ($isSuccess) {
                $this->statsCache[$gid]['stats_24h']['success'] += $count;
            } else {
                $this->statsCache[$gid]['stats_24h']['failure'] += $count;
            }
            
            // Only rows flagged as last 1h contribute to 1h stats
            if ($isLast1h) {
                $this->statsCache[$gid]['stats_1h']['total'] += $count;
                if ($isSuccess) {
                    $this->statsCache[$gid]['stats_1h']['success'] += $count;
                } else {
                    $this->statsCache[$gid]['stats_1h']['failure'] += $count;
                }
            }
        }
    }
    
    /**
     * Get stats for a specific time window for a gateway.
     */
    private function getWindowStats(string $gatewayId, string $window): array {
        $this->loadAllStats();
        
        $key = $window === '1h' ? 'stats_1h' : 'stats_24h';
        
        return $this->statsCache[$gatewayId][$key] ?? ['total' => 0, 'success' => 0, 'failure' => 0];
    }
    
    /**
     * Get health score for a gateway (0-100)
     * 
     * New gateways with limited data are treated as healthy (100%)
     * to ensure they can receive traffic and build up a track record.
     * We require a meaningful sample size before penalizing a gateway.
     */
    public function getHealthScore(string $gatewayId): float {
        $stats1h = $this->getWindowStats($gatewayId, '1h');
        $stats24h = $this->getWindowStats($gatewayId, '24h');
        
        // If no data, assume healthy - new gateways should get full traffic
        if ($stats1h['total'] === 0 && $stats24h['total'] === 0) {
            return 100.0;
        }
        
        // Require minimum sample size before penalizing
        // A gateway needs at least 10 transactions in 24h before we judge its health
        // This prevents a few early failures from killing a new gateway
        if ($stats24h['total'] < self::MIN_SAMPLE_24H) {
            return 100.0; // Not enough data to judge - treat as healthy
        }
        
        // Calculate success rates
        $rate1h = $stats1h['total'] > 0 ? ($stats1h['success'] / $stats1h['total']) * 100 : 100;
        $rate24h = $stats24h['total'] > 0 ? ($stats24h['success'] / $stats24h['total']) * 100 : 100;
        
        // If we have meaningful recent data (1h with 5+ transactions), weight it heavily
        if ($stats1h['total'] >= self::MIN_SAMPLE_1H) {
            return ($rate1h * 0.7) + ($rate24h * 0.3);
        }
        
        // Otherwise use 24h data
        return $rate24h;
    }
    
    /**
     * Get health status string
     */
    public function getHealthStatus(string $gatewayId): string {
        $score = $this->getHealthScore($gatewayId);
        
        if ($score >= self::HEALTH_EXCELLENT) {
            return 'excellent';
        } elseif ($score >= self::HEALTH_GOOD) {
            return 'good';
        } elseif ($score >= self::HEALTH_DEGRADED) {
            return 'degraded';
        } else {
            return 'critical';
        }
    }
    
    /**
     * Get traffic multiplier based on health
     */
    public function getTrafficMultiplier(string $gatewayId): float {
        $score = $this->getHealthScore($gatewayId);
        
        if ($score >= self::HEALTH_EXCELLENT) {
            return self::MULTIPLIER_EXCELLENT;
        } elseif ($score >= self::HEALTH_GOOD) {
            return self::MULTIPLIER_GOOD;
        } elseif ($score >= self::HEALTH_DEGRADED) {
            return self::MULTIPLIER_DEGRADED;
        } else {
            return self::MULTIPLIER_CRITICAL;
        }
    }
    
    /**
     * Check if gateway is healthy enough to receive traffic
     */
    public function isHealthy(string $gatewayId): bool {
        return $this->getHealthScore($gatewayId) >= self::HEALTH_CRITICAL;
    }
    
    /**
     * Alias for isHealthy - used by RotationManager
     */
    public function canReceiveTraffic(string $gatewayId): bool {
        return $this->isHealthy($gatewayId);
    }
    
    /**
     * Get health multiplier - alias for getTrafficMultiplier
     */
    public function getHealthMultiplier(string $gatewayId): float {
        return $this->getTrafficMultiplier($gatewayId);
    }
    
    /**
     * Get detailed health info for a gateway
     */
    public function getHealthDetails(string $gatewayId): array {
        $stats1h = $this->getWindowStats($gatewayId, '1h');
        $stats24h = $this->getWindowStats($gatewayId, '24h');
        $score = $this->getHealthScore($gatewayId);
        $status = $this->getHealthStatus($gatewayId);
        
        return [
            'score' => round($score, 1),
            'status' => $status,
            'multiplier' => $this->getTrafficMultiplier($gatewayId),
            'last_1h' => $stats1h,
            'last_24h' => $stats24h,
            'rate_1h' => $stats1h['total'] > 0 
                ? round(($stats1h['success'] / $stats1h['total']) * 100, 1) 
                : null,
            'rate_24h' => $stats24h['total'] > 0 
                ? round(($stats24h['success'] / $stats24h['total']) * 100, 1) 
                : null,
        ];
    }
    
    /**
     * Get health for all gateways
     */
    public function getAllHealth(): array {
        $gateways = $this->gateways->getAll();
        $health = [];
        
        foreach ($gateways as $gateway) {
            $health[$gateway->id] = $this->getHealthDetails($gateway->id);
        }
        
        return $health;
    }
    
    /**
     * Clear health data for a gateway.
     * With DB-backed health, this clears the per-request cache so next
     * call re-queries. Does NOT delete transaction records.
     * Also clears any legacy transient data.
     */
    public function clearHealth(string $gatewayId): void {
        $this->invalidateCache();
        // Clean up any legacy transients from pre-v2.4.1
        delete_transient('po_health_1h_' . $gatewayId);
        delete_transient('po_health_24h_' . $gatewayId);
    }
    
    /**
     * Clear all health data (for testing)
     */
    public function clearAllHealth(): void {
        $this->invalidateCache();
        $gateways = $this->gateways->getAll();
        foreach ($gateways as $gateway) {
            delete_transient('po_health_1h_' . $gateway->id);
            delete_transient('po_health_24h_' . $gateway->id);
        }
    }
    
    /**
     * Check if failure threshold is exceeded and send alert.
     * 
     * Alerting still uses transients - this is intentional. Alert tracking is
     * ephemeral by nature: if a cache clear causes a re-alert, that's actually
     * desirable (better to alert twice than miss an ongoing problem).
     */
    private function checkFailureAlert(string $gatewayId): void {
        if (get_option('po_failure_alert_enabled', 'yes') !== 'yes') {
            return;
        }
        
        $threshold = (int) get_option('po_failure_alert_threshold', 3);
        $windowMinutes = (int) get_option('po_failure_alert_window', 10);
        $email = get_option('po_failure_alert_email', get_option('admin_email'));
        
        if (!$email || $threshold < 1) {
            return;
        }
        
        // Build a unique customer identifier from available context.
        // WC()->customer may not be available in all contexts (webhooks, AJAX),
        // so we use multiple signals: customer_id, billing email, and IP.
        $customerIdent = '';
        if (function_exists('WC') && WC()->customer) {
            $custId = WC()->customer->get_id();
            $custEmail = WC()->customer->get_billing_email();
            if ($custId > 0) {
                $customerIdent = 'uid:' . $custId;
            } elseif (!empty($custEmail)) {
                $customerIdent = 'email:' . $custEmail;
            }
        }
        // Fallback to IP if no customer identity available
        if (empty($customerIdent)) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (!empty($ip)) {
                $customerIdent = 'ip:' . $ip;
            }
        }
        
        // Get recent failures from transient (stored as [time, ident] pairs)
        $cacheKey = 'po_failure_alert_' . $gatewayId;
        $failures = get_transient($cacheKey);
        
        if (!is_array($failures)) {
            $failures = [];
        }
        
        // Add current failure
        $now = time();
        $failures[] = ['time' => $now, 'ident' => $customerIdent];
        
        // Remove failures outside the window
        $cutoff = $now - ($windowMinutes * 60);
        $failures = array_filter($failures, fn($f) => (is_array($f) ? $f['time'] : $f) >= $cutoff);
        $failures = array_values($failures);
        
        // Save updated failures
        set_transient($cacheKey, $failures, $windowMinutes * 60);
        
        // Check if we've exceeded threshold
        if (count($failures) >= $threshold) {
            // Require failures from at least 3 unique customers.
            // This prevents a single customer with repeated bad CVV/card
            // from triggering false alarms.
            $uniqueCustomers = [];
            foreach ($failures as $f) {
                $ident = is_array($f) ? ($f['ident'] ?? $f['email'] ?? '') : '';
                if ($ident !== '') {
                    $uniqueCustomers[$ident] = true;
                }
            }
            
            $minUniqueCustomers = 3;
            if (count($uniqueCustomers) < $minUniqueCustomers) {
                // Not enough unique customers - likely one person retrying.
                // This covers both the case where we have 1-2 identities
                // AND the case where we have zero (can't determine uniqueness
                // = can't confirm it's a real multi-customer problem).
                return;
            }
            
            // Check if we've already sent an alert recently (within the window)
            $lastAlertKey = 'po_failure_alert_sent_' . $gatewayId;
            $lastAlert = get_transient($lastAlertKey);
            
            if (!$lastAlert) {
                $this->sendFailureAlert($gatewayId, count($failures), $windowMinutes, $email);
                set_transient($lastAlertKey, time(), $windowMinutes * 60);
                delete_transient($cacheKey);
            }
        }
    }
    
    /**
     * Send failure alert email
     */
    private function sendFailureAlert(string $gatewayId, int $failureCount, int $windowMinutes, string $email): void {
        $gateway = $this->gateways->getById($gatewayId);
        $gatewayName = $gateway ? $gateway->displayName : $gatewayId;
        
        $subject = sprintf(
            '[Payment Alert] %d failed transactions on %s in %d minutes',
            $failureCount,
            $gatewayName,
            $windowMinutes
        );
        
        $message = sprintf(
            "\u{26A0}\u{FE0F} Payment Failure Alert\n\n" .
            "Gateway: %s (%s)\n" .
            "Failed Transactions: %d\n" .
            "Time Window: %d minutes\n" .
            "Time: %s\n\n" .
            "This alert indicates that %d transactions have failed on this gateway within the last %d minutes.\n\n" .
            "Possible causes:\n" .
            "- Gateway API issues\n" .
            "- Invalid credentials\n" .
            "- Gateway disabled in WooCommerce\n" .
            "- Network connectivity problems\n\n" .
            "Please check your Payment Orchestrator dashboard and gateway settings.\n\n" .
            "---\n" .
            "This alert was sent by Payment Orchestrator Pro.\n" .
            "You can adjust alert settings in Pay Orchestrator \u{2192} Settings.",
            $gatewayName,
            $gatewayId,
            $failureCount,
            $windowMinutes,
            current_time('Y-m-d H:i:s'),
            $failureCount,
            $windowMinutes
        );
        
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        
        $sent = wp_mail($email, $subject, $message, $headers);
        
        Logger::warning('Failure alert sent', [
            'gateway' => $gatewayId,
            'failures' => $failureCount,
            'window_minutes' => $windowMinutes,
            'email' => $email,
            'sent' => $sent,
        ]);
    }
}
