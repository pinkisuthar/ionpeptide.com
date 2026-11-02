<?php
/**
 * VolumeTracker - Tracks transaction volume and stats
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Volume;

use PaymentOrchestrator\Gateway\GatewayRepository;
use PaymentOrchestrator\Gateway\Gateway;
use PaymentOrchestrator\Core\Logger;

final class VolumeTracker {
    private GatewayRepository $gateways;
    
    public function __construct(GatewayRepository $gateways) {
        $this->gateways = $gateways;
    }
    
    /**
     * Record a successful transaction
     * 
     * IMPORTANT: This function ONLY READS from WooCommerce orders.
     * It does NOT modify any WooCommerce data. Duplicate tracking uses our own table.
     */
    public function recordTransaction(int $orderId, ?\WC_Order $order = null): void {
        if (!$order) {
            $order = wc_get_order($orderId);
        }
        
        if (!$order) {
            return;
        }
        
        $gatewayId = $order->get_payment_method();
        if (!$gatewayId || !$this->gateways->isManaged($gatewayId)) {
            return;
        }
        
        global $wpdb;
        
        // Prevent duplicate recording - check our OWN table, not WooCommerce
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}po_transactions WHERE order_id = %d AND status = 'success' LIMIT 1",
            $orderId
        ));
        
        if ($existing) {
            return; // Already recorded
        }
        
        $amount = (float) $order->get_total();
        $monthYear = $this->getMonthYear();
        
        // Record transaction in OUR table only
        $wpdb->insert($wpdb->prefix . 'po_transactions', [
            'gateway_id' => $gatewayId,
            'order_id' => $orderId,
            'amount' => $amount,
            'status' => 'success',
            'month_year' => $monthYear,
            'created_at' => current_time('mysql'),
        ]);
        
        // Update daily stats in OUR table only
        $this->updateDailyStats($gatewayId, $amount, true);
        
        Logger::info('Transaction recorded', [
            'gateway' => $gatewayId,
            'order' => $orderId,
            'amount' => $amount,
        ]);
    }
    
    /**
     * Record a failed transaction
     */
    public function recordFailure(int $orderId, ?\WC_Order $order = null, ?string $gatewayOverride = null): void {
        if (!$order) {
            $order = wc_get_order($orderId);
        }
        
        if (!$order) {
            return;
        }

        // Use the gateway that actually failed, not get_payment_method().
        // FailureHandler runs at priority 5 and stores _po_failed_gateway on the order
        // before VolumeTracker runs at priority 10. By priority 10, WooCommerce may have
        // already updated payment_method to the retry gateway - using get_payment_method()
        // here would record the failure against the recovery gateway, not the one that failed.
        $failedGateway = $order->get_meta('_po_failed_gateway');
        // 2.7.28: a decline on the pay page happened on the order's CURRENT gateway
        // (e.g. the retry gateway), so the caller passes it explicitly.
        $gatewayId = $gatewayOverride ?: ($failedGateway ?: $order->get_payment_method());

        if (!$gatewayId || !$this->gateways->isManaged($gatewayId)) {
            return;
        }
        
        // Get error from order notes
        $errorMessage = '';
        $errorCode = '';
        $notes = wc_get_order_notes(['order_id' => $orderId, 'limit' => 5]);
        
        foreach ($notes as $note) {
            if (stripos($note->content, 'fail') !== false || stripos($note->content, 'decline') !== false || stripos($note->content, 'error') !== false) {
                $errorMessage = $note->content;
                if (preg_match('/(\d{3})\s*[-:]/', $note->content, $matches)) {
                    $errorCode = $matches[1];
                }
                break;
            }
        }
        
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . 'po_transactions', [
            'gateway_id' => $gatewayId,
            'order_id' => $orderId,
            'amount' => (float) $order->get_total(),
            'status' => 'failed',
            'error_code' => $errorCode ?: null,
            'error_message' => mb_substr($errorMessage, 0, 500) ?: null,
            'month_year' => $this->getMonthYear(),
            'created_at' => current_time('mysql'),
        ]);
        
        // Update daily stats
        $this->updateDailyStats($gatewayId, 0, false);
        
        Logger::warning('Transaction failed', [
            'gateway' => $gatewayId,
            'order' => $orderId,
            'error_code' => $errorCode,
        ]);
    }
    
    /**
     * Record a refund
     */
    public function recordRefund(int $orderId, int $refundId): void {
        $order = wc_get_order($orderId);
        $refund = wc_get_order($refundId);
        
        if (!$order || !$refund) {
            return;
        }
        
        $gatewayId = $order->get_payment_method();
        if (!$gatewayId || !$this->gateways->isManaged($gatewayId)) {
            return;
        }
        
        $amount = abs((float) $refund->get_total());
        
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . 'po_transactions', [
            'gateway_id' => $gatewayId,
            'order_id' => $orderId,
            'amount' => -$amount,
            'status' => 'refund',
            'month_year' => $this->getMonthYear(),
            'created_at' => current_time('mysql'),
        ]);
        
        Logger::info('Refund recorded', [
            'gateway' => $gatewayId,
            'order' => $orderId,
            'amount' => $amount,
        ]);
    }
    
    /**
     * Update daily statistics
     */
    private function updateDailyStats(string $gatewayId, float $amount, bool $success): void {
        global $wpdb;
        $table = $wpdb->prefix . 'po_daily_stats';
        $today = current_time('Y-m-d');
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE gateway_id = %s AND stat_date = %s",
            $gatewayId,
            $today
        ));
        
        if ($existing) {
            if ($success) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET 
                        total_volume = total_volume + %f,
                        transaction_count = transaction_count + 1,
                        success_count = success_count + 1,
                        last_transaction_at = NOW()
                    WHERE gateway_id = %s AND stat_date = %s",
                    $amount,
                    $gatewayId,
                    $today
                ));
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET 
                        failure_count = failure_count + 1,
                        last_transaction_at = NOW()
                    WHERE gateway_id = %s AND stat_date = %s",
                    $gatewayId,
                    $today
                ));
            }
        } else {
            $wpdb->insert($table, [
                'gateway_id' => $gatewayId,
                'stat_date' => $today,
                'total_volume' => $success ? $amount : 0,
                'transaction_count' => $success ? 1 : 0,
                'success_count' => $success ? 1 : 0,
                'failure_count' => $success ? 0 : 1,
                'last_transaction_at' => current_time('mysql'),
            ]);
        }
    }
    
    /**
     * Get monthly volume for a gateway
     */
    public function getMonthlyVolume(string $gatewayId): float {
        global $wpdb;
        $monthYear = $this->getMonthYear();
        
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}po_transactions 
            WHERE gateway_id = %s AND month_year = %s AND status = 'success'",
            $gatewayId,
            $monthYear
        ));
    }
    
    /**
     * Get monthly transaction count for a gateway
     */
    public function getMonthlyTransactionCount(string $gatewayId): int {
        global $wpdb;
        $monthYear = $this->getMonthYear();
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}po_transactions 
            WHERE gateway_id = %s AND month_year = %s AND status = 'success'",
            $gatewayId,
            $monthYear
        ));
    }
    
    /**
     * Get today's volume for a gateway
     */
    public function getTodayVolume(string $gatewayId): float {
        global $wpdb;
        $today = current_time('Y-m-d');
        
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(total_volume, 0) FROM {$wpdb->prefix}po_daily_stats 
            WHERE gateway_id = %s AND stat_date = %s",
            $gatewayId,
            $today
        ));
    }
    
    /**
     * Get today's transaction count for a gateway
     */
    public function getTodayTransactionCount(string $gatewayId): int {
        global $wpdb;
        $today = current_time('Y-m-d');
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(transaction_count, 0) FROM {$wpdb->prefix}po_daily_stats 
            WHERE gateway_id = %s AND stat_date = %s",
            $gatewayId,
            $today
        ));
    }
    
    /**
     * Get last transaction time for a gateway
     */
    public function getLastTransactionTime(string $gatewayId): ?string {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT last_transaction_at FROM {$wpdb->prefix}po_daily_stats 
            WHERE gateway_id = %s ORDER BY stat_date DESC LIMIT 1",
            $gatewayId
        ));
    }
    
    /**
     * Get transactions since a specific gateway was last used
     */
    public function getTransactionsSinceGateway(string $gatewayId): int {
        global $wpdb;
        $today = current_time('Y-m-d');
        
        // Get the last transaction ID for this gateway
        $lastId = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(id) FROM {$wpdb->prefix}po_transactions WHERE gateway_id = %s",
            $gatewayId
        ));
        
        if (!$lastId) {
            return 999; // Never used
        }
        
        // Count transactions since then
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}po_transactions WHERE id > %d AND status = 'success'",
            $lastId
        ));
    }
    
    /**
     * Increment rotation counter for a gateway
     */
    public function recordRotation(string $gatewayId): void {
        // We track this via transactions, so this is just for logging
        Logger::debug('Gateway rotated to', ['gateway' => $gatewayId]);
    }
    
    /**
     * Get volume statistics for all gateways
     */
    public function getAllStats(): array {
        global $wpdb;
        $gateways = $this->gateways->getAll();
        $stats = [];
        $monthYear = $this->getMonthYear();
        
        foreach ($gateways as $gateway) {
            $monthlyVolume = $this->getMonthlyVolume($gateway->id);
            $todayVolume = $this->getTodayVolume($gateway->id);
            $todayCount = $this->getTodayTransactionCount($gateway->id);
            $lastTxn = $this->getLastTransactionTime($gateway->id);
            
            // Get monthly success/fail counts
            $successCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}po_transactions 
                WHERE gateway_id = %s AND month_year = %s AND status = 'success'",
                $gateway->id, $monthYear
            ));
            
            $failCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}po_transactions 
                WHERE gateway_id = %s AND month_year = %s AND status = 'failed'",
                $gateway->id, $monthYear
            ));
            
            // Gateway-attributable failures only (for success rate calculation)
            // Customer-caused failures don't reflect gateway performance
            $gatewayFailCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}po_failed_transactions 
                WHERE gateway_id = %s AND month_year = %s 
                AND " . \PaymentOrchestrator\Recovery\FailureClassifier::healthFaultSql(),
                $gateway->id, $monthYear
            ));
            
            // Calculate pace status based on the two-pool drip model.
            // "On track" = today's volume is within 20% of today's expected hourly drip.
            // This matches the routing logic: remaining / daysLeft = dailyDrip,
            // dailyDrip / 24 hours x hoursElapsed = expectedByNow (24/7 store).
            $daysInMonth   = (int) current_time('t');
            $dayOfMonth    = (int) current_time('j');
            $daysRemaining = max(1, $daysInMonth - $dayOfMonth + 1);
            // 2.7.25: today's drip comes from the balance at the START of today.
            // Using the balance after today's sales made today's target shrink as
            // sales grew, biasing pace towards "ahead".
            $remaining     = max(0.0, $gateway->monthlyTarget - ($monthlyVolume - $todayVolume));
            $dailyDrip     = $remaining / $daysRemaining;
            $activeHoursStart = 0;
            $activeHours      = 24; // 24/7 eCommerce - full day window
            $currentHour      = (int) current_time('G');
            $hoursElapsed     = max(0, $currentHour - $activeHoursStart);
            $expectedByNow    = ($dailyDrip / $activeHours) * $hoursElapsed;
            // expectedPace kept for API compatibility - set to expectedByNow
            $expectedPace  = $expectedByNow;

            $paceStatus = 'on_track';
            if ($gateway->monthlyTarget > 0) {
                if ($monthlyVolume >= $gateway->monthlyTarget) {
                    $paceStatus = 'complete';
                } elseif ($dailyDrip <= 0) {
                    // No remaining drip - target effectively met
                    $paceStatus = 'complete';
                } elseif ($expectedByNow <= 0) {
                    // Before active window starts - treat as on track
                    $paceStatus = 'on_track';
                } else {
                    $intradayRatio = $todayVolume / $expectedByNow;
                    if ($intradayRatio < 0.5) {
                        $paceStatus = 'behind';
                    } elseif ($intradayRatio > 1.5) {
                        $paceStatus = 'ahead';
                    }
                    // 0.5-1.5 = on track - wide band to avoid flapping
                }
            }
            
            $stats[$gateway->id] = [
                'gateway' => $gateway,
                'monthly_volume' => $monthlyVolume,
                'monthly_target' => $gateway->monthlyTarget,
                'monthly_percent' => $gateway->monthlyTarget > 0 ? round(($monthlyVolume / $gateway->monthlyTarget) * 100, 1) : 0,
                'today_volume' => $todayVolume,
                'today_count' => $todayCount,
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'gateway_fail_count' => $gatewayFailCount,
                'last_transaction' => $lastTxn,
                'pace_status' => $paceStatus,
                'expected_pace' => $expectedPace,
            ];
        }
        
        return $stats;
    }
    
    /**
     * Get current month-year string
     */
    private function getMonthYear(): string {
        return current_time('Y-m');
    }
    
    /**
     * Backfill volume from WooCommerce orders
     * 
     * @param string $gatewayId Gateway ID
     * @param string|null $month Month to backfill (YYYY-MM format), null for current month
     * @param bool $rebackfill If true, clear existing data first
     */
    public function backfillFromOrders(string $gatewayId, ?string $month = null, bool $rebackfill = false): array {
        global $wpdb;
        
        Logger::info('Backfill started', [
            'gateway' => $gatewayId,
            'month' => $month,
            'rebackfill' => $rebackfill,
        ]);
        
        // Determine which month to backfill
        // Determine date range - use GMT for HPOS compatibility
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $monthYear = $month;
            $startDate = $month . '-01 00:00:00';
            $endDate = date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59';
        } else {
            // Current month - use WordPress timezone then we'll query appropriately
            $monthYear = current_time('Y-m');
            $startDate = current_time('Y-m-01') . ' 00:00:00';
            $endDate = current_time('Y-m-t') . ' 23:59:59';
        }
        
        Logger::info('Backfill date range', [
            'monthYear' => $monthYear,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'wp_timezone' => wp_timezone_string(),
            'current_time' => current_time('Y-m-d H:i:s'),
        ]);
        
        // Check if gateway exists in our system
        if (!$this->gateways->isManaged($gatewayId)) {
            Logger::warning('Backfill failed - gateway not managed', ['gateway' => $gatewayId]);
            return ['success' => false, 'message' => 'Gateway not managed by Payment Orchestrator'];
        }
        
        // Get configured success statuses
        $defaultStatuses = ['wc-processing', 'wc-completed', 'wc-shipped', 'wc-delivered'];
        $successStatuses = get_option('po_success_statuses', $defaultStatuses);
        
        // Also track failed status
        $failedStatuses = ['wc-failed'];
        
        if (empty($successStatuses)) {
            return ['success' => false, 'message' => 'No success statuses configured. Go to Settings > Order Tracking.'];
        }
        
        Logger::info('Backfill statuses', [
            'success_statuses' => $successStatuses,
            'failed_statuses' => $failedStatuses,
        ]);
        
        // If rebackfill, clear existing data for this gateway/month
        if ($rebackfill) {
            $wpdb->delete($wpdb->prefix . 'po_transactions', [
                'gateway_id' => $gatewayId,
                'month_year' => $monthYear,
            ]);
            
            // Clear daily stats for this month
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}po_daily_stats 
                WHERE gateway_id = %s AND stat_date >= %s AND stat_date <= %s",
                $gatewayId, substr($startDate, 0, 10), substr($endDate, 0, 10)
            ));
            
            $existingOrderIds = [];
            Logger::debug('Rebackfill - cleared existing data');
        } else {
            // Get already recorded order IDs to prevent duplicates
            $existingOrderIds = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT order_id FROM {$wpdb->prefix}po_transactions WHERE gateway_id = %s AND month_year = %s",
                $gatewayId,
                $monthYear
            ));
            Logger::debug('Existing orders to skip', ['count' => count($existingOrderIds)]);
        }
        
        // Combine all statuses we want to query
        $allStatuses = array_merge($successStatuses, $failedStatuses);
        
        // Check if HPOS is enabled
        $isHpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') 
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        
        Logger::debug('HPOS check', ['is_hpos' => $isHpos]);
        
        if ($isHpos) {
            // HPOS - statuses are stored WITH 'wc-' prefix (same as legacy)
            $placeholders = implode(',', array_fill(0, count($allStatuses), '%s'));
            
            // First, check what payment methods exist in the database
            $existingMethods = $wpdb->get_col("SELECT DISTINCT payment_method FROM {$wpdb->prefix}wc_orders LIMIT 20");
            Logger::debug('Available payment methods in DB', ['methods' => $existingMethods]);
            
            // Check what statuses exist for this gateway
            $existingStatuses = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT status FROM {$wpdb->prefix}wc_orders WHERE payment_method = %s LIMIT 20",
                $gatewayId
            ));
            Logger::debug('Available statuses for gateway in DB', ['gateway' => $gatewayId, 'statuses' => $existingStatuses]);
            
            // Check total orders for this gateway (ignoring date/status)
            $totalForGateway = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE payment_method = %s",
                $gatewayId
            ));
            Logger::debug('Total orders for gateway (all time)', ['gateway' => $gatewayId, 'count' => $totalForGateway]);
            
            // Check orders in date range (ignoring status)
            $ordersInRange = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE payment_method = %s AND date_created_gmt >= %s AND date_created_gmt <= %s",
                $gatewayId, $startDate, $endDate
            ));
            Logger::info('Orders in date range (any status)', ['gateway' => $gatewayId, 'count' => $ordersInRange, 'start' => $startDate, 'end' => $endDate]);
            
            $query = "SELECT id, total_amount, status, date_created_gmt 
                FROM {$wpdb->prefix}wc_orders 
                WHERE payment_method = %s 
                AND status IN ({$placeholders})
                AND date_created_gmt >= %s
                AND date_created_gmt <= %s";
            
            $params = array_merge([$gatewayId], $allStatuses, [$startDate, $endDate]);
            
            Logger::debug('HPOS query', [
                'gateway_id' => $gatewayId,
                'statuses' => $allStatuses,
                'start' => $startDate,
                'end' => $endDate,
            ]);
            
            $orders = $wpdb->get_results($wpdb->prepare($query, $params));
            
            Logger::debug('HPOS query result', [
                'orders_found' => count($orders),
                'last_error' => $wpdb->last_error,
            ]);
            
            // Determine success/fail based on status (with wc- prefix)
            $isSuccess = fn($status) => in_array($status, $successStatuses);
        } else {
            // Legacy posts table - statuses have 'wc-' prefix
            $placeholders = implode(',', array_fill(0, count($allStatuses), '%s'));
            
            $query = "SELECT p.ID as id, pm_total.meta_value as total_amount, p.post_status as status, p.post_date as date_created_gmt
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_payment_method'
                INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
                WHERE p.post_type = 'shop_order'
                AND pm.meta_value = %s
                AND p.post_status IN ({$placeholders})
                AND p.post_date >= %s
                AND p.post_date <= %s";
            
            $params = array_merge([$gatewayId], $allStatuses, [$startDate, $endDate]);
            
            Logger::debug('Legacy query', [
                'statuses' => $allStatuses,
                'params_count' => count($params),
            ]);
            
            $orders = $wpdb->get_results($wpdb->prepare($query, $params));
            
            Logger::debug('Legacy query result', [
                'orders_found' => count($orders),
                'last_error' => $wpdb->last_error,
            ]);
            
            // Determine success/fail based on legacy status (with wc- prefix)
            $isSuccess = fn($status) => in_array($status, $successStatuses);
        }
        
        if (empty($orders)) {
            Logger::info('Backfill complete - no orders found', [
                'gateway' => $gatewayId,
                'month' => $monthYear,
            ]);
            return ['success' => true, 'count' => 0, 'volume' => 0, 'message' => 'No orders found for the selected period'];
        }
        
        // Filter out already recorded orders
        $newOrders = array_filter($orders, fn($order) => !in_array($order->id, $existingOrderIds));
        
        Logger::info('Orders after filtering', [
            'total_found' => count($orders),
            'new_orders' => count($newOrders),
            'already_recorded' => count($orders) - count($newOrders),
        ]);
        
        if (empty($newOrders)) {
            return ['success' => true, 'count' => 0, 'volume' => 0, 'message' => 'All orders already recorded'];
        }
        
        $totalVolume = 0;
        $totalCount = 0;
        $successCount = 0;
        $failCount = 0;
        $dailyStats = [];
        
        // Get WordPress timezone for proper date conversion
        $wpTimezone = wp_timezone();
        $utcTimezone = new \DateTimeZone('UTC');
        
        foreach ($newOrders as $order) {
            $amount = (float) $order->total_amount;
            
            // Convert GMT date to local timezone for daily stats
            try {
                $orderDate = new \DateTime($order->date_created_gmt, $utcTimezone);
                $orderDate->setTimezone($wpTimezone);
                $date = $orderDate->format('Y-m-d');
                $localDateTime = $orderDate->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                // Fallback to GMT date if conversion fails
                $date = date('Y-m-d', strtotime($order->date_created_gmt));
                $localDateTime = $order->date_created_gmt;
            }
            
            $orderIsSuccess = $isSuccess($order->status);
            $status = $orderIsSuccess ? 'success' : 'failed';
            
            // Record transaction - store LOCAL time in created_at for proper date filtering
            $wpdb->insert($wpdb->prefix . 'po_transactions', [
                'gateway_id' => $gatewayId,
                'order_id' => $order->id,
                'amount' => $orderIsSuccess ? $amount : 0,
                'status' => $status,
                'month_year' => $monthYear,
                'created_at' => $localDateTime,
            ]);
            
            // Aggregate daily stats for upsert
            if (!isset($dailyStats[$date])) {
                $dailyStats[$date] = ['volume' => 0, 'count' => 0, 'success' => 0, 'fail' => 0, 'last' => null];
            }
            $dailyStats[$date]['count']++;
            if ($orderIsSuccess) {
                $dailyStats[$date]['volume'] += $amount;
                $dailyStats[$date]['success']++;
                $totalVolume += $amount;
                $successCount++;
            } else {
                $dailyStats[$date]['fail']++;
                $failCount++;
            }
            $dailyStats[$date]['last'] = $order->date_created_gmt;
            
            $totalCount++;
        }
        
        // Upsert daily stats - REPLACE instead of ADD to prevent duplication
        foreach ($dailyStats as $date => $data) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}po_daily_stats WHERE gateway_id = %s AND stat_date = %s",
                $gatewayId, $date
            ));
            
            if ($existing) {
                // REPLACE the values, don't add to them (backfill should set exact values)
                $wpdb->update(
                    $wpdb->prefix . 'po_daily_stats',
                    [
                        'total_volume' => $data['volume'],
                        'transaction_count' => $data['count'],
                        'success_count' => $data['success'],
                        'failure_count' => $data['fail'],
                        'last_transaction_at' => $data['last'],
                    ],
                    ['gateway_id' => $gatewayId, 'stat_date' => $date]
                );
            } else {
                $wpdb->insert($wpdb->prefix . 'po_daily_stats', [
                    'gateway_id' => $gatewayId,
                    'stat_date' => $date,
                    'total_volume' => $data['volume'],
                    'transaction_count' => $data['count'],
                    'success_count' => $data['success'],
                    'failure_count' => $data['fail'],
                    'last_transaction_at' => $data['last'],
                ]);
            }
        }
        
        Logger::info('Volume backfilled', [
            'gateway' => $gatewayId,
            'orders' => $totalCount,
            'success' => $successCount,
            'failed' => $failCount,
            'volume' => $totalVolume,
        ]);
        
        return [
            'success' => true,
            'count' => $totalCount,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'volume' => $totalVolume,
            'message' => sprintf('Imported %d orders (%d successful, %d failed) totaling $%s', 
                $totalCount, $successCount, $failCount, number_format($totalVolume, 2)),
        ];
    }
    
    /**
     * Get 3-month history data for all gateways
     */
    public function getThreeMonthHistory(): array {
        global $wpdb;
        
        $gateways = $this->gateways->getAll();
        $history = [];
        
        // Get last 3 months including current
        $months = [];
        for ($i = 2; $i >= 0; $i--) {
            // 2.7.25: month boundaries in the site timezone (month_year is site time)
            $date = new \DateTime('first day of this month', wp_timezone());
            $date->modify("-{$i} months");
            $months[] = $date->format('Y-m');
        }
        
        // Gateway colors - 8 maximally distinct hues, no two from the same family.
        // Order: dark navy, orange, teal, red, purple, lime, pink, sky blue.
        $colors = ['#1e293b', '#ea580c', '#0f766e', '#dc2626', '#7c3aed', '#65a30d', '#db2777', '#0284c7'];
        $colorIndex = 0;
        
        foreach ($gateways as $gateway) {
            $monthlyData = [];
            
            foreach ($months as $month) {
                // Get volume for this gateway/month
                $volume = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}po_transactions 
                    WHERE gateway_id = %s AND month_year = %s AND status = 'success'",
                    $gateway->id, $month
                ));
                
                $txnCount = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}po_transactions 
                    WHERE gateway_id = %s AND month_year = %s AND status = 'success'",
                    $gateway->id, $month
                ));
                
                $failCount = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}po_transactions 
                    WHERE gateway_id = %s AND month_year = %s AND status = 'failed'",
                    $gateway->id, $month
                ));
                
                $monthlyData[] = [
                    'month' => $month,
                    'month_label' => date('M Y', strtotime($month . '-01')),
                    'volume' => $volume,
                    'transactions' => $txnCount,
                    'failures' => $failCount,
                    'success_rate' => ($txnCount + $failCount) > 0 
                        ? round(($txnCount / ($txnCount + $failCount)) * 100, 1) 
                        : 100,
                ];
            }
            
            $history[] = [
                'gateway_id'   => $gateway->id,
                'gateway_name' => $gateway->displayName,
                'is_unlimited' => $gateway->hasUnlimitedCapacity(),
                'color'        => $colors[$colorIndex % count($colors)],
                'data'         => $monthlyData,
            ];
            
            $colorIndex++;
        }
        
        return [
            'months' => array_map(fn($m) => date('M Y', strtotime($m . '-01')), $months),
            'gateways' => $history,
        ];
    }
    
    /**
     * Get today's stats per gateway for dashboard tiles
     */
    public function getTodayStatsByGateway(): array {
        global $wpdb;
        $today = current_time('Y-m-d');
        $gateways = $this->gateways->getAll();
        $stats = [];
        
        foreach ($gateways as $gateway) {
            $volume = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(total_volume, 0) FROM {$wpdb->prefix}po_daily_stats 
                WHERE gateway_id = %s AND stat_date = %s",
                $gateway->id, $today
            ));
            
            $successCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(success_count, 0) FROM {$wpdb->prefix}po_daily_stats 
                WHERE gateway_id = %s AND stat_date = %s",
                $gateway->id, $today
            ));
            
            $failCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(failure_count, 0) FROM {$wpdb->prefix}po_daily_stats 
                WHERE gateway_id = %s AND stat_date = %s",
                $gateway->id, $today
            ));
            
            $stats[] = [
                'gateway_id' => $gateway->id,
                'gateway_name' => $gateway->displayName,
                'volume' => $volume,
                'success' => $successCount,
                'fail' => $failCount,
                'success_rate' => ($successCount + $failCount) > 0 
                    ? round(($successCount / ($successCount + $failCount)) * 100, 1) 
                    : 100,
            ];
        }
        
        return $stats;
    }
}
