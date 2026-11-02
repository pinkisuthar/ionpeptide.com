<?php
/**
 * Dashboard Template
 * 
 * @package PaymentOrchestrator
 * @var array $stats
 * @var array $todayStats
 * @var array $history
 * @var array $healthData
 * @var array $rotationWeights
 * @var float $totalToday
 * @var float $totalMonth
 * @var float $totalTarget
 * @var int $todayTransactions
 * @var int $gatewayCount
 */

defined('ABSPATH') || exit;

$daysInMonth = (int) current_time('t');
$dayOfMonth = (int) current_time('j');
$daysRemaining = $daysInMonth - $dayOfMonth;

// Calculate additional stats
$behindCount = 0;
$totalSuccess = 0;
$totalFail = 0;
$unhealthyCount = 0;
foreach ($stats as $gatewayId => $stat) {
    if ($stat['pace_status'] === 'behind') $behindCount++;
    $totalSuccess += $stat['success_count'] ?? 0;
    $totalFail += $stat['fail_count'] ?? 0;
    if (isset($healthData[$gatewayId]) && $healthData[$gatewayId]['status'] === 'critical') {
        $unhealthyCount++;
    }
}
?>
<div class="wrap">
    <h1 style="display:none;">Payment Orchestrator</h1>
    
    <div class="po-wrap">
        <div class="po-layout">
            
            <!-- Shared Header + Tab Nav -->
            <?php $currentPage = 'payment-orchestrator'; include PO_PLUGIN_DIR . 'templates/admin/partials/header-nav.php'; ?>
            
            <!-- Today's Summary Tiles -->
            <?php
            $todayTotalProcessed = 0;
            $todayTotalTransactions = 0;
            $todayTotalFails = 0;
            foreach ($todayStats as $gw) {
                $todayTotalProcessed += $gw['volume'];
                $todayTotalTransactions += ($gw['success'] + $gw['fail']);
                $todayTotalFails += $gw['fail'];
            }
            $todaySuccessRate = $todayTotalTransactions > 0 
                ? round((($todayTotalTransactions - $todayTotalFails) / $todayTotalTransactions) * 100, 1) 
                : 100;

            // Today's unique order count - distinct orders across all managed gateways
            $todayOrderCount = 0;
            $todayAttemptedOrders = 0;
            try {
                global $wpdb;
                $txnTable = $wpdb->prefix . 'po_transactions';
                $todayOrderCount = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT order_id) FROM {$txnTable}
                     WHERE DATE(created_at) = %s AND status = 'success'",
                    current_time('Y-m-d')
                ));
                // 2.7.25: orders attempted today (any outcome), for order conversion
                $todayAttemptedOrders = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT order_id) FROM {$txnTable}
                     WHERE DATE(created_at) = %s AND status IN ('success','failed')",
                    current_time('Y-m-d')
                ));
            } catch (\Throwable $e) {
                // Fail silently - defaults to 0
            }
            
            // Get today's failed/recovered revenue with retryable vs non-retryable splits.
            // All dollar amounts use DISTINCT order_id to prevent double-counting.
            $todayFailedRevenue = 0;
            $todayRetryableRev = 0;
            $todayNonRetryableRev = 0;
            $todayRecoveredRevenue = 0;
            $todayRecoveredCount = 0;
            $todayFailedCount = 0;
            $todayRetryableFailCount = 0;
            $todayRetryableRecoveredCount = 0;
            $todayProcessorRev = 0.0;
            $todayProcessorCount = 0;
            $todayCustomerCount = 0;
            $todayPaidAfterCount = 0;
            $recoveryBySource = []; // keyed by source: ['rev' => float, 'cnt' => int]
            try {
                global $wpdb;
                $failTable = $wpdb->prefix . 'po_failed_transactions';
                $failTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$failTable}'");
                if ($failTableExists) {
                    $today = current_time('Y-m-d');
                    // 2.7.25: failures are stored in UTC; use the site day's UTC range
                    // (DATE(created_at) gave the UTC day: 7 pm-7 pm Chicago).
                    $todayUtc = \PaymentOrchestrator\Core\SiteTime::dayUtcRange($today);
                    $breakdownRow = $wpdb->get_row($wpdb->prepare(
                        "SELECT 
                            COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$failTable} WHERE created_at >= %s AND created_at < %s) d), 0) AS total_failed_rev,
                            COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$failTable} WHERE created_at >= %s AND created_at < %s AND failure_category IN ('retryable_gateway','retryable_card_type')) d), 0) AS retryable_rev,
                            COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$failTable} WHERE created_at >= %s AND created_at < %s AND failure_category = 'non_retryable') d), 0) AS non_retryable_rev,
                            COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$failTable} WHERE created_at >= %s AND created_at < %s AND recovered = 1) d), 0) AS recovered_rev,
                            (SELECT COUNT(DISTINCT order_id) FROM {$failTable} WHERE created_at >= %s AND created_at < %s AND recovered = 1) AS recovered_cnt,
                            (SELECT COUNT(DISTINCT order_id) FROM {$failTable} WHERE created_at >= %s AND created_at < %s) AS failed_cnt,
                            (SELECT COUNT(DISTINCT order_id) FROM {$failTable} WHERE created_at >= %s AND created_at < %s AND failure_category IN ('retryable_gateway','retryable_card_type')) AS retryable_fail_cnt,
                            (SELECT COUNT(DISTINCT order_id) FROM {$failTable} WHERE created_at >= %s AND created_at < %s AND failure_category IN ('retryable_gateway','retryable_card_type') AND recovered = 1) AS retryable_recovered_cnt,
                            COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$failTable} WHERE created_at >= %s AND created_at < %s AND " . \PaymentOrchestrator\Recovery\FailureClassifier::healthFaultSql() . ") d), 0) AS processor_rev,
                            (SELECT COUNT(DISTINCT order_id) FROM {$failTable} WHERE created_at >= %s AND created_at < %s AND " . \PaymentOrchestrator\Recovery\FailureClassifier::healthFaultSql() . ") AS processor_cnt,
                            (SELECT COUNT(DISTINCT order_id) FROM {$failTable} WHERE created_at >= %s AND created_at < %s AND failure_category = 'non_retryable') AS customer_cnt,
                            (SELECT COUNT(DISTINCT f.order_id) FROM {$failTable} f JOIN {$wpdb->prefix}wc_order_operational_data op ON op.order_id = f.order_id WHERE f.created_at >= %s AND f.created_at < %s AND op.date_paid_gmt IS NOT NULL) AS paid_after_cnt",
                        ...array_merge(...array_fill(0, 12, $todayUtc))
                    ));
                    $todayFailedRevenue = (float) ($breakdownRow->total_failed_rev ?? 0);
                    $todayRetryableRev = (float) ($breakdownRow->retryable_rev ?? 0);
                    $todayNonRetryableRev = (float) ($breakdownRow->non_retryable_rev ?? 0);
                    $todayRecoveredRevenue = (float) ($breakdownRow->recovered_rev ?? 0);
                    $todayRecoveredCount = (int) ($breakdownRow->recovered_cnt ?? 0);
                    $todayFailedCount = (int) ($breakdownRow->failed_cnt ?? 0);
                    $todayRetryableFailCount = (int) ($breakdownRow->retryable_fail_cnt ?? 0);
                    $todayRetryableRecoveredCount = (int) ($breakdownRow->retryable_recovered_cnt ?? 0);
                    // 2.7.25: one failure definition everywhere - Processor = counts against
                    // health; Customer = non-retryable; Other = everything else (catch-all, 2.7.29).
                    $todayProcessorRev   = (float) ($breakdownRow->processor_rev ?? 0);
                    $todayProcessorCount = (int) ($breakdownRow->processor_cnt ?? 0);
                    $todayCustomerCount  = (int) ($breakdownRow->customer_cnt ?? 0);
                    $todayPaidAfterCount = (int) ($breakdownRow->paid_after_cnt ?? 0);

                    // Recovery source breakdown - safe: recovery_source may be null or absent
                    try {
                        $sourceRows = $wpdb->get_results($wpdb->prepare(
                            "SELECT
                                COALESCE(recovery_source, 'auto_redirect') AS source,
                                COALESCE(SUM(order_total), 0)              AS rev,
                                COUNT(DISTINCT order_id)                   AS cnt
                             FROM {$failTable}
                             WHERE created_at >= %s AND created_at < %s AND recovered = 1
                             GROUP BY COALESCE(recovery_source, 'auto_redirect')",
                            $todayUtc[0], $todayUtc[1]
                        ));
                        foreach ($sourceRows as $sr) {
                            $recoveryBySource[$sr->source] = [
                                'rev' => (float) $sr->rev,
                                'cnt' => (int)   $sr->cnt,
                            ];
                        }
                    } catch (\Throwable $e) {
                        // Column may not exist on older schema - fail silently
                        $recoveryBySource = [];
                    }
                }
            } catch (\Throwable $e) {
                // Fail silently - display defaults
            }
            $todayNetLost = $todayFailedRevenue - $todayRecoveredRevenue;
            $todayRecoveryRate = $todayFailedCount > 0 
                ? round(($todayRecoveredCount / $todayFailedCount) * 100, 1) 
                : 0;
            // Retryable rate = of processor-side failures, how many were recovered
            $todayRetryableRecoveryRate = $todayRetryableFailCount > 0
                ? round(($todayRetryableRecoveredCount / $todayRetryableFailCount) * 100, 1)
                : 0;
            ?>
            <!-- Option B: 3-card top bar &#x2014; Volume &#xB7; Failures &#xB7; Recovery -->
            <?php
            $hasFailures   = $todayFailedCount > 0;
            $hasRecovery   = $todayRecoveredRevenue > 0;
            $failBorder    = $hasFailures ? '#fecaca' : '#e2e8f0';
            $failHeadColor = $hasFailures ? '#dc2626' : '#1e293b';
            $recBorder     = $hasRecovery ? '#bbf7d0' : '#e2e8f0';
            $recHeadColor  = $hasRecovery ? '#16a34a' : '#1e293b';
            ?>
            <div class="po-top-cards">

                <!-- Card 1: Volume -->
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px 28px;">
                    <div style="font-size:11px; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:0.6px; margin-bottom:10px;">Today's Volume</div>
                    <div style="font-size:34px; font-weight:700; color:#1e293b; line-height:1;">$<?php echo number_format($todayTotalProcessed, 0); ?></div>
                    <div style="margin-top:14px; display:flex; gap:20px;">
                        <div>
                            <div style="font-size:20px; font-weight:700; color:#0f172a;"><?php echo number_format($todayOrderCount); ?></div>
                            <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:2px;">Orders</div>
                        </div>
                        <div style="border-left:1px solid #e2e8f0;"></div>
                        <div>
                            <div style="font-size:20px; font-weight:700; color:#0f172a;"><?php echo number_format($todayTotalTransactions); ?></div>
                            <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:2px;">Transactions</div>
                        </div>
                        <div style="border-left:1px solid #e2e8f0;"></div>
                        <div>
                            <div style="font-size:20px; font-weight:700; color:<?php echo $todaySuccessRate >= 95 ? '#16a34a' : ($todaySuccessRate >= 85 ? '#d97706' : '#dc2626'); ?>;"><?php echo $todaySuccessRate; ?>%</div>
                            <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:2px;" title="Approved payment attempts &#xF7; all payment attempts today">Approval rate</div>
                        </div>
                        <?php $todayOrderConversion = $todayAttemptedOrders > 0 ? round($todayOrderCount / $todayAttemptedOrders * 100, 1) : 100; ?>
                        <div style="border-left:1px solid #e2e8f0;"></div>
                        <div>
                            <div style="font-size:20px; font-weight:700; color:<?php echo $todayOrderConversion >= 95 ? '#16a34a' : ($todayOrderConversion >= 85 ? '#d97706' : '#dc2626'); ?>;"><?php echo $todayOrderConversion; ?>%</div>
                            <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:2px;" title="Orders paid &#xF7; orders that attempted payment today">Order conversion</div>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Failures &#x2014; dollar primary -->
                <?php
                // 2.7.25: total includes every category (previously "other" was left out)
                $todayTotalFailedRev = $todayFailedRevenue;
                $todayOtherCount = max(0, $todayFailedCount - $todayProcessorCount - $todayCustomerCount);
                $todayOtherRev   = max(0.0, $todayFailedRevenue - $todayProcessorRev - $todayNonRetryableRev);
                ?>
                <div style="background:#fff; border:1px solid <?php echo $failBorder; ?>; border-radius:12px; padding:24px 28px;">
                    <div style="font-size:11px; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:0.6px; margin-bottom:10px;">Failures</div>
                    <div style="font-size:34px; font-weight:700; color:<?php echo $hasFailures ? '#dc2626' : '#1e293b'; ?>; line-height:1;">$<?php echo number_format($todayTotalFailedRev, 0); ?></div>
                    <div style="margin-top:14px; display:flex; gap:20px;">
                        <div>
                            <div style="font-size:20px; font-weight:700; color:#dc2626;">$<?php echo number_format($todayProcessorRev, 0); ?></div>
                            <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:2px;" title="Failures that count against gateway health (processor/MCC declines)">Processor &#xB7; <?php echo $todayProcessorCount; ?> fails</div>
                        </div>
                        <div style="border-left:1px solid #e2e8f0;"></div>
                        <div>
                            <div style="font-size:20px; font-weight:700; color:#0f172a;">$<?php echo number_format($todayNonRetryableRev, 0); ?></div>
                            <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:2px;" title="Card or customer problems (CVV, funds, expired, address...)">Customer &#xB7; <?php echo $todayCustomerCount; ?> fails</div>
                        </div>
                        <?php if ($todayOtherCount > 0): ?>
                        <div style="border-left:1px solid #e2e8f0;"></div>
                        <div>
                            <div style="font-size:20px; font-weight:700; color:#0f172a;">$<?php echo number_format($todayOtherRev, 0); ?></div>
                            <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:2px;" title="Everything that is neither a processor problem nor a customer card problem &#x2014; e.g. card amount limits, card brand not accepted, unclassified responses">Other &#xB7; <?php echo $todayOtherCount; ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Card 3: Recovery -->
                <?php
                // Source labels - auto_redirect becomes "Orchestrator", email stays "Email"
                $sourceLabels = [
                    'auto_redirect' => 'POP Retry',
                    'email'         => 'Email',
                ];
                // Only show breakdown when there are 2+ distinct sources with revenue
                $meaningfulSources = array_filter($recoveryBySource, fn($s) => $s['rev'] > 0);
                $showSourceBreakdown = count($meaningfulSources) > 1;
                ?>
                <div style="background:#fff; border:1px solid <?php echo $recBorder; ?>; border-radius:12px; padding:24px 28px;">
                    <div style="font-size:11px; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:0.6px; margin-bottom:10px;">Recovery</div>
                    <div style="font-size:34px; font-weight:700; color:<?php echo $recHeadColor; ?>; line-height:1;">$<?php echo number_format($todayRecoveredRevenue, 0); ?></div>

                    <?php if ($showSourceBreakdown): ?>
                    <div style="margin-top:12px; display:flex; flex-direction:column; gap:6px;">
                        <?php foreach ($meaningfulSources as $source => $data): ?>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:12px; font-weight:600; color:#64748b;">
                                <?php echo esc_html($sourceLabels[$source] ?? ucfirst(str_replace('_', ' ', $source))); ?>
                            </span>
                            <span style="font-size:14px; font-weight:700; color:#16a34a;">
                                $<?php echo number_format($data['rev'], 0); ?>
                                <span style="font-size:11px; font-weight:600; color:#94a3b8; margin-left:3px;"><?php echo $data['cnt']; ?> order<?php echo $data['cnt'] !== 1 ? 's' : ''; ?></span>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="height:0.5px; background:#e2e8f0; margin:12px 0;"></div>
                    <?php else: ?>
                    <div style="margin-top:14px;"></div>
                    <?php endif; ?>

                    <div style="display:flex; gap:20px;">
                        <div>
                            <div style="font-size:20px; font-weight:700; color:<?php echo $todayRetryableRecoveryRate > 0 ? '#16a34a' : '#94a3b8'; ?>;"><?php echo $todayRetryableRecoveryRate; ?>%</div>
                            <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:2px; line-height:1.3;">Processor<br>Recovery</div>
                        </div>
                        <div style="border-left:1px solid #e2e8f0;"></div>
                        <div>
                            <div style="font-size:20px; font-weight:700; color:<?php echo $todayRecoveryRate > 0 ? '#16a34a' : '#94a3b8'; ?>;"><?php echo $todayRecoveryRate; ?>%</div>
                            <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:2px; line-height:1.3;" title="Failed orders recovered by POP &#x2014; POP Retry or a recovery email paid through its link">POP<br>Recovery</div>
                        </div>
                        <div style="border-left:1px solid #e2e8f0;"></div>
                        <div>
                            <?php $todayPaidAfterRate = $todayFailedCount > 0 ? round($todayPaidAfterCount / $todayFailedCount * 100, 1) : 0; ?>
                            <div style="font-size:20px; font-weight:700; color:<?php echo $todayPaidAfterRate > 0 ? '#16a34a' : '#94a3b8'; ?>;"><?php echo $todayPaidAfterRate; ?>%</div>
                            <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:2px; line-height:1.3;" title="Failed orders that were paid afterwards, by any means (customer retry, new card, email, auto-retry)">Paid after<br>failure</div>
                        </div>
                    </div>
                </div>

            </div>
            
            <!-- Today's Stats by Gateway (Enhanced Tiles) -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Today's Activity by Gateway</h2>
                </div>
                <div class="po-card-body">
                    <?php if (empty($todayStats)): ?>
                        <p class="po-muted">No gateways configured.</p>
                    <?php else: ?>
                        <?php
                        // Query per-gateway failure category split for today:
                        // gateway_fails = failures that count against the gateway (healthFaultSql, 2.7.22)
                        // customer_fails = non_retryable (customer-side: CVV, funds, expired, etc.)
                        $perGatewayFailSplit = [];
                        try {
                            if ($failTableExists) {
                                $failSplitRows = $wpdb->get_results($wpdb->prepare(
                                    "SELECT gateway_id,
                                        SUM(CASE WHEN " . \PaymentOrchestrator\Recovery\FailureClassifier::healthFaultSql() . " THEN 1 ELSE 0 END) AS gw_fails,
                                        SUM(CASE WHEN failure_category = 'non_retryable' THEN 1 ELSE 0 END) AS cust_fails,
                                        COALESCE(SUM(order_total), 0) AS fail_revenue
                                     FROM {$failTable}
                                     WHERE created_at >= %s AND created_at < %s
                                     GROUP BY gateway_id",
                                    ...\PaymentOrchestrator\Core\SiteTime::dayUtcRange()
                                ));
                                foreach ($failSplitRows as $row) {
                                    $perGatewayFailSplit[$row->gateway_id] = [
                                        'gw'      => (int) $row->gw_fails,
                                        'cust'    => (int) $row->cust_fails,
                                        'revenue' => (float) $row->fail_revenue,
                                    ];
                                }
                            }
                        } catch (\Throwable $e) {
                            // Fail silently
                        }
                        
                        // Query per-gateway recovery for today:
                        // "Recovered via this gateway" = transactions that failed on another
                        // gateway but were retried and succeeded on THIS gateway today.
                        $perGatewayRecovery = [];
                        try {
                            if ($failTableExists) {
                                $recoveryRows = $wpdb->get_results($wpdb->prepare(
                                    "SELECT retry_gateway, COALESCE(SUM(order_total), 0) AS recovered_amount, COUNT(*) AS recovered_count
                                     FROM {$failTable}
                                     WHERE recovered = 1 AND created_at >= %s AND created_at < %s
                                     GROUP BY retry_gateway",
                                    ...\PaymentOrchestrator\Core\SiteTime::dayUtcRange()
                                ));
                                foreach ($recoveryRows as $row) {
                                    $perGatewayRecovery[$row->retry_gateway] = [
                                        'amount' => (float) $row->recovered_amount,
                                        'count'  => (int) $row->recovered_count,
                                    ];
                                }
                            }
                        } catch (\Throwable $e) {
                            // Fail silently - recovery data is informational
                        }
                        ?>
                        <div class="po-today-tiles">
                            <?php foreach ($todayStats as $gw): 
                                $total = $gw['success'] + $gw['fail'];
                                $checkoutRate = $total > 0 ? round(($gw['success'] / $total) * 100, 1) : 100;
                                $health = $healthData[$gw['gateway_id']] ?? null;
                                $healthStatus = $health['status'] ?? 'excellent';
                                $healthScore = $health['score'] ?? 100;
                                $gwRecovery = $perGatewayRecovery[$gw['gateway_id']] ?? null;
                                $gwFailSplit = $perGatewayFailSplit[$gw['gateway_id']] ?? null;
                                $gwFails = $gwFailSplit ? $gwFailSplit['gw'] : 0;
                                $custFails = $gwFailSplit ? $gwFailSplit['cust'] : 0;
                                $processorBase = $gw['success'] + $gwFails;
                                $processorRate = $processorBase > 0 ? round(($gw['success'] / $processorBase) * 100, 1) : 100;
                                $processorColor = $processorRate >= 97 ? '#16a34a' : ($processorRate >= 93 ? '#d97706' : '#dc2626');
                                $processorBg = $processorRate >= 97 ? '#dcfce7' : ($processorRate >= 93 ? '#fef3c7' : '#fee2e2');
                                // Health dot colour
                                $healthDot = match($healthStatus) {
                                    'excellent' => '#22c55e',
                                    'good'      => '#eab308',
                                    'degraded'  => '#f97316',
                                    default     => '#ef4444', // critical
                                };
                                $rowBg = $healthStatus === 'critical' ? '#fef9f9' : '';
                            ?>
                                <?php
                                $failRevenue = $gwFailSplit ? $gwFailSplit['revenue'] : 0;
                                $rowBg = ($healthStatus === 'critical' || $healthStatus === 'degraded') ? '#fef9f9' : '';
                                ?>
                            <div class="po-gw-row"<?php echo $rowBg ? ' style="background:' . $rowBg . ';"' : ''; ?>>

                                <!-- Name + health dot -->
                                <div class="po-gw-row__name">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <span style="width:9px;height:9px;border-radius:50%;background:<?php echo $healthDot; ?>;display:inline-block;flex-shrink:0;"></span>
                                        <span style="font-size:15px;font-weight:600;color:#1e293b;"><?php echo esc_html($gw['gateway_name']); ?></span>
                                    </div>
                                </div>

                                <!-- Total processed -->
                                <div class="po-gw-row__metric">
                                    <div class="po-gw-row__metric-num">$<?php echo number_format($gw['volume'], 0); ?></div>
                                    <div class="po-gw-row__metric-lbl">Processed</div>
                                </div>

                                <!-- Success orders -->
                                <div class="po-gw-row__metric">
                                    <div class="po-gw-row__metric-num" style="color:#27500a;"><?php echo number_format($gw['success']); ?></div>
                                    <div class="po-gw-row__metric-lbl">Success orders</div>
                                </div>

                                <!-- Failed $ -->
                                <div class="po-gw-row__metric">
                                    <?php if ($failRevenue > 0): ?>
                                        <div class="po-gw-row__metric-num" style="color:#a32d2d;">$<?php echo number_format($failRevenue, 0); ?></div>
                                    <?php else: ?>
                                        <div class="po-gw-row__metric-num" style="color:#cbd5e1;">&#x2014;</div>
                                    <?php endif; ?>
                                    <div class="po-gw-row__metric-lbl">Failed $</div>
                                </div>

                                <!-- Failed orders -->
                                <div class="po-gw-row__metric">
                                    <?php if ($gw['fail'] > 0): ?>
                                        <div class="po-gw-row__metric-num" style="color:#a32d2d;"><?php echo $gw['fail']; ?></div>
                                        <?php if ($gwFails > 0 || $custFails > 0): ?>
                                        <div class="po-gw-row__metric-lbl" style="font-size:10px;color:#94a3b8;"><?php echo $gwFails; ?>gw &#xB7; <?php echo $custFails; ?>cust</div>
                                        <?php else: ?>
                                        <div class="po-gw-row__metric-lbl">Failed orders</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="po-gw-row__metric-num" style="color:#cbd5e1;">&#x2014;</div>
                                        <div class="po-gw-row__metric-lbl">Failed orders</div>
                                    <?php endif; ?>
                                </div>

                                <!-- Recovered $ -->
                                <div class="po-gw-row__metric">
                                    <?php if ($gwRecovery && $gwRecovery['amount'] > 0): ?>
                                        <div class="po-gw-row__metric-num" style="color:#27500a;">$<?php echo number_format($gwRecovery['amount'], 0); ?></div>
                                    <?php else: ?>
                                        <div class="po-gw-row__metric-num" style="color:#cbd5e1;">&#x2014;</div>
                                    <?php endif; ?>
                                    <div class="po-gw-row__metric-lbl">Recovered $</div>
                                </div>

                                <!-- Recovered orders -->
                                <div class="po-gw-row__metric">
                                    <?php if ($gwRecovery && $gwRecovery['count'] > 0): ?>
                                        <div class="po-gw-row__metric-num" style="color:#27500a;"><?php echo $gwRecovery['count']; ?></div>
                                    <?php else: ?>
                                        <div class="po-gw-row__metric-num" style="color:#cbd5e1;">&#x2014;</div>
                                    <?php endif; ?>
                                    <div class="po-gw-row__metric-lbl">Rec. orders</div>
                                </div>

                                <!-- Rate badges -->
                                <div class="po-gw-row__rates">
                                    <span style="text-align:center;padding:6px 14px;border-radius:8px;background:<?php echo $processorBg; ?>;">
                                        <span style="display:block;font-size:16px;font-weight:600;color:<?php echo $processorColor; ?>;"><?php echo $processorRate; ?>%</span>
                                        <span style="display:block;font-size:10px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.4px;margin-top:2px;">Processor</span>
                                    </span>
                                    <span style="text-align:center;padding:6px 14px;border-radius:8px;background:#f1f5f9;">
                                        <span style="display:block;font-size:16px;font-weight:600;color:#334155;"><?php echo $checkoutRate; ?>%</span>
                                        <span style="display:block;font-size:10px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.4px;margin-top:2px;">Checkout</span>
                                    </span>
                                </div>

                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Gateway Performance -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Gateway Performance</h2>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <span class="po-badge po-badge-gray"><?php echo $daysRemaining; ?> days left in month</span>
                        <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-gateways'); ?>" class="po-btn po-btn-sm">Manage Gateways</a>
                    </div>
                </div>
                <div class="po-card-body">
                    <?php if (empty($stats)): ?>
                        <div class="po-empty">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                                <line x1="1" y1="10" x2="23" y2="10"/>
                            </svg>
                            <p>No gateways configured yet.</p>
                            <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-gateways'); ?>" class="po-btn po-btn-primary">Add Gateway</a>
                        </div>
                    <?php else: ?>
                        <table class="po-table">
                            <thead>
                                <tr>
                                    <th>Gateway</th>
                                    <th>Today</th>
                                    <th>Monthly</th>
                                    <th>Success Rate</th>
                                    <th>Progress</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats as $stat): 
                                    $isUnlimited = $stat['gateway']->hasUnlimitedCapacity();
                                    $percent = $stat['monthly_percent'];
                                    $progressColor = match(true) {
                                        $percent >= 100 => '#22c55e',
                                        $percent >= 75  => '#3b82f6',
                                        $percent >= 50  => '#f59e0b',
                                        default         => '#ef4444',
                                    };
                                    $successCount = $stat['success_count'] ?? 0;
                                    $failCount    = $stat['fail_count'] ?? 0;
                                    $totalTxns    = $successCount + $failCount;
                                    $successRate  = $totalTxns > 0 ? round(($successCount / $totalTxns) * 100, 1) : 100;
                                ?>
                                <tr>
                                    <td>
                                        <div class="po-gateway-name"><?php echo esc_html($stat['gateway']->displayName); ?></div>
                                        <div class="po-gateway-id"><?php echo esc_html($stat['gateway']->id); ?></div>
                                        <?php if ($isUnlimited): ?>
                                            <span style="display:inline-block;margin-top:4px;font-size:10px;font-weight:600;background:#e6f1fb;color:#185fa5;padding:1px 7px;border-radius:10px;">Unlimited</span>
                                        <?php else: ?>
                                            <span style="display:inline-block;margin-top:4px;font-size:10px;font-weight:600;background:#f1f5f9;color:#475569;padding:1px 7px;border-radius:10px;">Soft Cap &#xB7; $<?php echo number_format($stat['monthly_target'], 0); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="po-volume">$<?php echo number_format($stat['today_volume'], 0); ?></div>
                                        <div class="po-count"><?php echo $stat['today_count']; ?> txns</div>
                                    </td>
                                    <td>
                                        <div class="po-volume">$<?php echo number_format($stat['monthly_volume'], 0); ?></div>
                                        <?php if (!$isUnlimited): ?>
                                            <div class="po-count">of $<?php echo number_format($stat['monthly_target'], 0); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($totalTxns > 0): ?>
                                            <div class="po-rate <?php echo $successRate >= 95 ? 'po-rate-good' : ($successRate >= 90 ? 'po-rate-warn' : 'po-rate-bad'); ?>">
                                                <?php echo $successRate; ?>%
                                            </div>
                                            <div class="po-count"><?php echo $successCount; ?>&#x2713; / <?php echo $failCount; ?>&#x2717;</div>
                                        <?php else: ?>
                                            <span class="po-muted">&#x2014;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isUnlimited): ?>
                                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                                <div style="flex:1;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                                                    <div style="height:100%;width:<?php echo min(100, $percent); ?>%;background:<?php echo $progressColor; ?>;border-radius:4px;"></div>
                                                </div>
                                                <span style="font-size:12px;font-weight:600;color:#334155;white-space:nowrap;"><?php echo $percent; ?>%</span>
                                            </div>
                                            <div class="po-count">$<?php echo number_format($stat['monthly_volume'], 0); ?> of $<?php echo number_format($stat['monthly_target'], 0); ?></div>
                                        <?php else: ?>
                                            <span style="font-size:12px;color:#94a3b8;">No cap</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($isUnlimited) {
                                            $statusClass = 'po-badge-blue';
                                            $statusLabel = 'PRIMARY';
                                        } else {
                                            $statusClass = match($stat['pace_status']) {
                                                'behind'   => 'po-badge-red',
                                                'ahead'    => 'po-badge-amber',
                                                'complete' => 'po-badge-blue',
                                                default    => 'po-badge-green',
                                            };
                                            $statusLabel = match($stat['pace_status']) {
                                                'behind'   => 'BEHIND',
                                                'ahead'    => 'AHEAD',
                                                'complete' => 'COMPLETE',
                                                default    => 'ON TRACK',
                                            };
                                        }
                                        ?>
                                        <span class="po-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 3 Month History Chart &#x2014; Chart.js stacked bar -->
            <?php if (!empty($history['gateways'])): ?>
            <div class="po-card">
                <div class="po-card-header" style="flex-wrap:wrap;gap:10px;">
                    <h2 class="po-card-title">3-Month Volume by Gateway</h2>
                    <div id="po-chart-legend" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;"></div>
                </div>
                <div class="po-card-body">
                    <div style="position:relative;width:100%;height:320px;">
                        <canvas id="po-history-chart"
                            role="img"
                            aria-label="Stacked bar chart showing monthly volume by gateway for the last 3 months">
                            Monthly gateway volume data.
                        </canvas>
                    </div>
                </div>
            </div>

            <script>
            (function(){
                var historyData = <?php echo json_encode($history); ?>;

                function fmtVal(v) {
                    if (v >= 1000000) return '$' + (v/1000000).toFixed(2).replace(/\.?0+$/,'') + 'M';
                    if (v >= 1000)    return '$' + (v/1000).toFixed(1).replace(/\.?0+$/,'') + 'K';
                    return '$' + Math.round(v);
                }

                function buildLegend(gateways) {
                    var el = document.getElementById('po-chart-legend');
                    if (!el) return;
                    el.innerHTML = gateways.map(function(gw) {
                        var tag = gw.is_unlimited
                            ? '<span style="font-size:10px;font-weight:600;background:#e6f1fb;color:#185fa5;padding:1px 6px;border-radius:10px;margin-left:4px;">Unlimited</span>'
                            : '';
                        return '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--color-text-secondary);">'
                            + '<span style="width:10px;height:10px;border-radius:2px;background:' + gw.color + ';display:inline-block;flex-shrink:0;"></span>'
                            + '<span style="color:var(--color-text-primary);font-weight:500;">' + gw.gateway_name + '</span>'
                            + tag
                            + '</span>';
                    }).join('');
                }

                function initChart() {
                    if (typeof Chart === 'undefined') { setTimeout(initChart, 200); return; }
                    var canvas = document.getElementById('po-history-chart');
                    if (!canvas) return;

                    var gateways = historyData.gateways;
                    var months   = historyData.months;

                    buildLegend(gateways);

                    if (canvas._chartInstance) { canvas._chartInstance.destroy(); }

                    canvas._chartInstance = new Chart(canvas, {
                        type: 'bar',
                        data: {
                            labels: months,
                            datasets: gateways.map(function(gw) {
                                return {
                                    label:           gw.gateway_name,
                                    data:            gw.data.map(function(d){ return d.volume; }),
                                    backgroundColor: gw.color,
                                    borderWidth:     0,
                                    borderRadius:    3,
                                    borderSkipped:   false,
                                };
                            })
                        },
                        options: {
                            responsive:          true,
                            maintainAspectRatio: false,
                            layout: { padding: { top: 28 } },
                            interaction: {
                                mode:      'index',
                                intersect: false,
                            },
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: '#ffffff',
                                    titleColor:      '#1e293b',
                                    bodyColor:       '#475569',
                                    borderColor:     '#e2e8f0',
                                    borderWidth:     1,
                                    padding:         14,
                                    cornerRadius:    8,
                                    boxShadow:       '0 4px 12px rgba(0,0,0,0.08)',
                                    callbacks: {
                                        title: function(items) {
                                            return items[0].label;
                                        },
                                        label: function(ctx) {
                                            var v = ctx.raw;
                                            if (v <= 0) return null;
                                            return '  ' + ctx.dataset.label + ':  ' + fmtVal(v);
                                        },
                                        afterBody: function(items) {
                                            var total = items.reduce(function(s,i){ return s + i.raw; }, 0);
                                            return ['', '  Total:  ' + fmtVal(total)];
                                        },
                                        filter: function(item) {
                                            return item.raw > 0;
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    stacked: true,
                                    grid:    { display: false },
                                    ticks:   { color: '#64748b', font: { size: 12 } },
                                    border:  { display: false },
                                },
                                y: {
                                    stacked: true,
                                    grid:    { color: 'rgba(0,0,0,0.05)', drawTicks: false },
                                    border:  { display: false },
                                    ticks: {
                                        color:    '#64748b',
                                        font:     { size: 11 },
                                        padding:  8,
                                        callback: fmtVal,
                                    }
                                }
                            }
                        },
                        plugins: [{
                            id: 'stackedTotals',
                            afterDatasetsDraw: function(chart) {
                                var ctx2 = chart.ctx;
                                var datasets = chart.data.datasets;
                                var meta0 = chart.getDatasetMeta(0);
                                if (!meta0 || !meta0.data) return;

                                meta0.data.forEach(function(bar, i) {
                                    var total = datasets.reduce(function(sum, ds) {
                                        return sum + (ds.data[i] || 0);
                                    }, 0);
                                    if (total <= 0) return;

                                    var label = fmtVal(total);
                                    ctx2.save();
                                    ctx2.font = '600 12px -apple-system, system-ui, sans-serif';
                                    ctx2.fillStyle = '#1e293b';
                                    ctx2.textAlign = 'center';
                                    ctx2.textBaseline = 'bottom';

                                    var lastMeta = chart.getDatasetMeta(datasets.length - 1);
                                    var topBar = lastMeta.data[i];
                                    if (topBar) {
                                        ctx2.fillText(label, bar.x, topBar.y - 6);
                                    }
                                    ctx2.restore();
                                });
                            }
                        }]
                    });
                }

                if (typeof Chart !== 'undefined') {
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', initChart);
                    } else {
                        setTimeout(initChart, 50);
                    }
                } else {
                    window.addEventListener('load', function(){ setTimeout(initChart, 100); });
                }
                window.addEventListener('resize', function(){
                    if (canvas && canvas._chartInstance) {
                        canvas._chartInstance.resize();
                    }
                });
            })();
            </script>
            <?php endif; ?>
            
            <!-- Rotation Simulator -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Rotation Preview & Simulator</h2>
                </div>
                <div class="po-card-body">
                    <div class="po-simulator-layout">
                        <!-- Current Weights -->
                        <div class="po-simulator-weights">
                            <h4>Current Gateway Weights</h4>
                            <p class="po-help">Based on health, pace, and capacity. Higher weight = more likely to be selected.</p>
                            <?php if (empty($rotationWeights)): ?>
                                <p class="po-muted">No gateways configured.</p>
                            <?php else: ?>
                                <div class="po-weight-bars">
                                    <?php foreach ($rotationWeights as $gid => $w): 
                                        $health = $healthData[$gid] ?? null;
                                        $healthStatus = $health['status'] ?? 'excellent';
                                    ?>
                                    <div class="po-weight-row">
                                        <div class="po-weight-label">
                                            <span class="po-health-dot po-health-<?php echo $healthStatus; ?>"></span>
                                            <?php echo esc_html($w['gateway']->displayName); ?>
                                            <?php if ($w['status'] === 'blocked'): ?>
                                                <span class="po-badge po-badge-red po-badge-sm"><?php echo esc_html($w['reason']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="po-weight-bar-container">
                                            <div class="po-weight-bar" style="width:<?php echo min(100, $w['percentage']); ?>%"></div>
                                            <span class="po-weight-value"><?php echo $w['percentage']; ?>%</span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Simulator -->
                        <div class="po-simulator-run">
                            <h4>Distribution Simulator</h4>
                            <p class="po-help">Simulate how transactions would be distributed right now.</p>
                            <div class="po-simulator-controls">
                                <select id="po-sim-count" class="po-select po-select-sm">
                                    <option value="50">50 transactions</option>
                                    <option value="100" selected>100 transactions</option>
                                    <option value="250">250 transactions</option>
                                    <option value="500">500 transactions</option>
                                </select>
                                <button type="button" id="po-run-simulation" class="po-btn po-btn-primary po-btn-sm">Run Simulation</button>
                            </div>
                            <div id="po-sim-results" class="po-sim-results" style="display:none;">
                                <div class="po-sim-results-inner"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="po-actions">
                <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-gateways'); ?>" class="po-action-card">
                    <div class="po-action-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                            <line x1="1" y1="10" x2="23" y2="10"/>
                        </svg>
                    </div>
                    <span>Manage Gateways</span>
                </a>
                <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-settings'); ?>" class="po-action-card">
                    <div class="po-action-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
                        </svg>
                    </div>
                    <span>Settings</span>
                </a>
                <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-logs'); ?>" class="po-action-card">
                    <div class="po-action-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                        </svg>
                    </div>
                    <span>View Logs</span>
                </a>
            </div>
            
        </div>
    </div>
</div>
