<?php
/**
 * Recovery admin page template
 *
 * @var array $gateways
 * @var array $recoveryStats
 * @var array $todayRecoveryStats
 * @var array $topFailureCodes
 * @var array $todayTopFailureCodes
 * @var array $gatewayPairs
 * @var array $todayGatewayPairs
 * @var array $classifierRules
 * @var string $selectedMonth
 * @var bool $recoveryEnabled
 */

defined('ABSPATH') || exit;

$months = [];
for ($i = 0; $i < 6; $i++) {
    $dt = new DateTime('now', wp_timezone());
    $dt->modify("-{$i} months");
    $months[$dt->format('Y-m')] = $dt->format('F Y');
}
?>

<div class="wrap po-wrap">
    <h1 style="display:none;">Payment Recovery</h1>
    
    <!-- Shared Header + Tab Nav -->
    <?php $currentPage = 'payment-orchestrator-recovery'; include PO_PLUGIN_DIR . 'templates/admin/partials/header-nav.php'; ?>

    <?php if (!$recoveryEnabled): ?>
        <div class="notice notice-warning" style="margin: 15px 0;">
            <p><strong>Recovery is currently disabled.</strong> Enable it in <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-settings'); ?>">Settings</a> to start capturing failures and auto-retrying.</p>
        </div>
    <?php endif; ?>

    <!-- Month Selector -->
    <div class="po-card" style="margin-bottom: 20px;">
        <div class="po-card-body" style="padding: 12px 16px;">
            <form method="get" style="display: flex; align-items: center; gap: 12px;">
                <input type="hidden" name="page" value="payment-orchestrator-recovery">
                <label class="po-label" style="margin: 0; font-weight: 600;">Period:</label>
                <select name="month" class="po-select" style="width: auto;" onchange="this.form.submit()">
                    <?php foreach ($months as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($selectedMonth, $val); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- Today's Recovery Summary -->
    <div style="margin-bottom: 8px;">
        <h3 style="margin: 0 0 12px; font-size: 15px; font-weight: 600; color: #334155;">Today</h3>
    </div>
    <div class="po-report-summary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 24px;">
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #1e293b;">
                <?php echo number_format($todayRecoveryStats['total_failures']); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Failures</div>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #2563eb;">
                <?php echo number_format($todayRecoveryStats['total_retryable']); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Retryable</div>
            <?php if (($todayRecoveryStats['retryable_revenue'] ?? 0) > 0): ?>
            <div style="font-size: 10px; color: #94a3b8; margin-top: 2px;">$<?php echo number_format($todayRecoveryStats['retryable_revenue'], 0); ?></div>
            <?php endif; ?>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #dc2626;">
                <?php echo number_format($todayRecoveryStats['non_retryable'] ?? 0); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Non-Retryable</div>
            <?php if (($todayRecoveryStats['non_retryable_revenue'] ?? 0) > 0): ?>
            <div style="font-size: 10px; color: #94a3b8; margin-top: 2px;">$<?php echo number_format($todayRecoveryStats['non_retryable_revenue'], 0); ?></div>
            <?php endif; ?>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center; border-left: 4px solid #22c55e;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #22c55e;">
                <?php echo number_format($todayRecoveryStats['recovered_count']); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Recovered</div>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center; border-left: 4px solid #22c55e;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #22c55e;">
                $<?php echo number_format($todayRecoveryStats['recovered_revenue'], 2); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Revenue Recovered</div>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #f59e0b;">
                <?php echo $todayRecoveryStats['recovery_rate']; ?>%
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Recovery Rate</div>
        </div>
    </div>

    <!-- Monthly Recovery Summary -->
    <div style="margin-bottom: 8px;">
        <h3 style="margin: 0 0 12px; font-size: 15px; font-weight: 600; color: #334155;"><?php echo esc_html($months[$selectedMonth] ?? $selectedMonth); ?></h3>
    </div>
    <div class="po-report-summary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 24px;">
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #1e293b;">
                <?php echo number_format($recoveryStats['total_failures']); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Failures</div>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #2563eb;">
                <?php echo number_format($recoveryStats['total_retryable']); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Retryable</div>
            <?php if (($recoveryStats['lost_retryable_revenue'] ?? 0) > 0): ?>
            <div style="font-size: 10px; color: #94a3b8; margin-top: 2px;">$<?php echo number_format($recoveryStats['lost_retryable_revenue'], 0); ?> not recovered by POP</div>
            <?php endif; ?>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;" title="Failed orders paid afterwards by any means (customer retry, another card, recovery email, auto-retry)">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #16a34a;">
                <?php echo number_format((int) ($recoveryStats['paid_after_failure'] ?? 0)); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Paid after failure</div>
            <div style="font-size: 10px; color: #94a3b8; margin-top: 2px;"><?php echo esc_html((string) ($recoveryStats['paid_after_failure_rate'] ?? 0)); ?>% of failed orders</div>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;" title="All failure types; excludes orders paid later and customers who placed a paid order within 7 days">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #dc2626;">
                $<?php echo number_format((float) ($recoveryStats['unrecovered_net_revenue'] ?? 0), 0); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Unrecovered (net)</div>
            <div style="font-size: 10px; color: #94a3b8; margin-top: 2px;">after reorders within 7 days</div>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #dc2626;">
                <?php echo number_format($recoveryStats['non_retryable'] ?? 0); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Non-Retryable</div>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center; border-left: 4px solid #22c55e;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #22c55e;">
                <?php echo number_format($recoveryStats['recovered_count']); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Recovered</div>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center; border-left: 4px solid #22c55e;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #22c55e;">
                $<?php echo number_format($recoveryStats['recovered_revenue'], 2); ?>
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Revenue Recovered</div>
        </div>
        <div class="po-summary-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center;">
            <div class="po-summary-value" style="font-size: 24px; font-weight: 700; color: #f59e0b;">
                <?php echo $recoveryStats['recovery_rate']; ?>%
            </div>
            <div class="po-summary-label" style="font-size: 12px; color: #64748b; margin-top: 4px;">Recovery Rate</div>
        </div>
    </div>

    <!-- Two Column Layout: Top Failure Codes + Recovery by Gateway -->
    <div class="po-two-col">

        <!-- Top Failure Codes -->
        <div class="po-card">
            <div class="po-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0;">Top Failure Codes</h2>
                <div style="display:flex; gap:4px; align-items:center;">
                    <button type="button" id="po-fc-tab-today" onclick="poToggleFailCodes('today')" 
                            style="padding:4px 12px; font-size:12px; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; background:#1e293b; color:#fff; font-weight:600;">Today</button>
                    <button type="button" id="po-fc-tab-month" onclick="poToggleFailCodes('month')" 
                            style="padding:4px 12px; font-size:12px; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; background:#f1f5f9; color:#334155;">
                        <?php echo esc_html($months[$selectedMonth] ?? $selectedMonth); ?>
                    </button>
                </div>
            </div>
            <div class="po-card-body" style="padding: 0;">
                <!-- Today's failure codes (shown by default) -->
                <div id="po-fc-today">
                <?php if (empty($todayTopFailureCodes)): ?>
                    <p style="padding: 20px; color: #94a3b8; text-align: center;">No failures recorded today.</p>
                <?php else: ?>
                    <table class="po-table" style="margin: 0;">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Reason</th>
                                <th>Category</th>
                                <th style="text-align: right;">Count</th>
                                <th style="text-align: right;">Value</th>
                                <th style="text-align: right;">Recovered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todayTopFailureCodes as $fc): ?>
                                <?php
                                $catColor = match($fc['failure_category']) {
                                    'retryable_gateway' => '#2563eb',
                                    'retryable_card_type' => '#334155',
                                    'non_retryable' => '#dc2626',
                                    default => '#94a3b8',
                                };
                                $catLabel = match($fc['failure_category']) {
                                    'retryable_gateway' => '' . "\u{1F504}" . ' Gateway',
                                    'retryable_card_type' => '' . "\u{1F504}" . ' Card Type',
                                    'non_retryable' => '' . "\u{274C}" . ' Customer',
                                    default => '' . "\u{26AA}" . ' Other',
                                };
                                ?>
                                <tr>
                                    <td><code style="background: #f1f5f9; padding: 2px 6px; border-radius: 3px; font-size: 12px;"><?php echo esc_html($fc['failure_code'] ?: '' . "\u{2014}" . ''); ?></code></td>
                                    <td style="font-size: 13px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr($fc['failure_reason']); ?>">
                                        <?php echo esc_html(mb_substr($fc['failure_reason'], 0, 40)); ?>
                                    </td>
                                    <td><span style="color: <?php echo $catColor; ?>; font-size: 12px; font-weight: 600;"><?php echo $catLabel; ?></span></td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo number_format((int)$fc['occurrences']); ?></td>
                                    <td style="text-align: right;">$<?php echo number_format((float)$fc['total_value'], 0); ?></td>
                                    <td style="text-align: right;">
                                        <?php if ((int)$fc['recovered'] > 0): ?>
                                            <span style="color: #22c55e; font-weight: 600;"><?php echo number_format((int)$fc['recovered']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #cbd5e1;">0</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                </div>
                
                <!-- Monthly failure codes (hidden by default) -->
                <div id="po-fc-month" style="display:none;">
                <?php if (empty($topFailureCodes)): ?>
                    <p style="padding: 20px; color: #94a3b8; text-align: center;">No failure data yet for this period.</p>
                <?php else: ?>
                    <table class="po-table" style="margin: 0;">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Reason</th>
                                <th>Category</th>
                                <th style="text-align: right;">Count</th>
                                <th style="text-align: right;">Value</th>
                                <th style="text-align: right;">Recovered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topFailureCodes as $fc): ?>
                                <?php
                                $catColor = match($fc['failure_category']) {
                                    'retryable_gateway' => '#2563eb',
                                    'retryable_card_type' => '#334155',
                                    'non_retryable' => '#dc2626',
                                    default => '#94a3b8',
                                };
                                $catLabel = match($fc['failure_category']) {
                                    'retryable_gateway' => '' . "\u{1F504}" . ' Gateway',
                                    'retryable_card_type' => '' . "\u{1F504}" . ' Card Type',
                                    'non_retryable' => '' . "\u{274C}" . ' Customer',
                                    default => '' . "\u{26AA}" . ' Other',
                                };
                                ?>
                                <tr>
                                    <td><code style="background: #f1f5f9; padding: 2px 6px; border-radius: 3px; font-size: 12px;"><?php echo esc_html($fc['failure_code'] ?: '' . "\u{2014}" . ''); ?></code></td>
                                    <td style="font-size: 13px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr($fc['failure_reason']); ?>">
                                        <?php echo esc_html(mb_substr($fc['failure_reason'], 0, 40)); ?>
                                    </td>
                                    <td><span style="color: <?php echo $catColor; ?>; font-size: 12px; font-weight: 600;"><?php echo $catLabel; ?></span></td>
                                    <td style="text-align: right; font-weight: 600;">
                                        <?php if ($fc['failure_code']): ?>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=payment-orchestrator-reports&report_type=this_month&drill_code=' . urlencode($fc['failure_code']))); ?>" 
                                               style="color:#1e293b; text-decoration:none; border-bottom:1px dashed #94a3b8; padding-bottom:1px;"
                                               title="View transactions with code <?php echo esc_attr($fc['failure_code']); ?>">
                                                <?php echo number_format((int)$fc['occurrences']); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo number_format((int)$fc['occurrences']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">$<?php echo number_format((float)$fc['total_value'], 0); ?></td>
                                    <td style="text-align: right;">
                                        <?php if ((int)$fc['recovered'] > 0): ?>
                                            <span style="color: #22c55e; font-weight: 600;"><?php echo number_format((int)$fc['recovered']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #cbd5e1;">0</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                </div><!-- end #po-fc-month -->
            </div>
        </div>
        
        <script>
        function poToggleFailCodes(view) {
            var todayEl = document.getElementById('po-fc-today');
            var monthEl = document.getElementById('po-fc-month');
            var todayBtn = document.getElementById('po-fc-tab-today');
            var monthBtn = document.getElementById('po-fc-tab-month');
            if (view === 'today') {
                todayEl.style.display = '';
                monthEl.style.display = 'none';
                todayBtn.style.background = '#1e293b';
                todayBtn.style.color = '#fff';
                monthBtn.style.background = '#f1f5f9';
                monthBtn.style.color = '#334155';
            } else {
                todayEl.style.display = 'none';
                monthEl.style.display = '';
                monthBtn.style.background = '#1e293b';
                monthBtn.style.color = '#fff';
                todayBtn.style.background = '#f1f5f9';
                todayBtn.style.color = '#334155';
            }
        }
        </script>

        <!-- Recovery by Gateway &#x2014; Today + Month -->
        <div class="po-card">
            <div class="po-card-header">
                <h2 style="margin: 0;">Recovery by Gateway</h2>
            </div>
            <div class="po-card-body" style="padding: 0;">
                <!-- Today -->
                <?php if (!empty($todayGatewayPairs)): ?>
                <div style="padding: 12px 20px 4px; font-size: 12px; font-weight: 600; color: #334155; text-transform: uppercase; letter-spacing: 0.5px;">Today</div>
                <table class="po-table" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Failed On</th>
                            <th></th>
                            <th>Recovered On</th>
                            <th style="text-align: right;">Orders</th>
                            <th style="text-align: right;">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todayGatewayPairs as $pair): ?>
                            <?php
                            $failedName = $pair['failed_gateway'];
                            $retryName = $pair['retry_gateway'];
                            foreach ($gateways as $g) {
                                if ($g->id === $pair['failed_gateway']) $failedName = $g->displayName;
                                if ($g->id === $pair['retry_gateway']) $retryName = $g->displayName;
                            }
                            ?>
                            <tr>
                                <td><span style="color: #dc2626; font-weight: 500;"><?php echo esc_html($failedName); ?></span></td>
                                <td style="text-align: center; color: #22c55e;">&#x2192;</td>
                                <td><span style="color: #22c55e; font-weight: 500;"><?php echo esc_html($retryName); ?></span></td>
                                <td style="text-align: right; font-weight: 600;"><?php echo number_format((int)$pair['recovered_count']); ?></td>
                                <td style="text-align: right; font-weight: 600; color: #22c55e;">$<?php echo number_format((float)$pair['recovered_revenue'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <!-- Month -->
                <div style="padding: 12px 20px 4px; font-size: 12px; font-weight: 600; color: #334155; text-transform: uppercase; letter-spacing: 0.5px; <?php echo !empty($todayGatewayPairs) ? 'border-top: 2px solid #e2e8f0;' : ''; ?>">
                    <?php echo esc_html($months[$selectedMonth] ?? $selectedMonth); ?>
                </div>
                <?php if (empty($gatewayPairs)): ?>
                    <p style="padding: 12px 20px 20px; color: #94a3b8; text-align: center;">No recoveries yet for this period.</p>
                <?php else: ?>
                    <table class="po-table" style="margin: 0;">
                        <?php if (empty($todayGatewayPairs)): ?>
                        <thead>
                            <tr>
                                <th>Failed On</th>
                                <th></th>
                                <th>Recovered On</th>
                                <th style="text-align: right;">Orders</th>
                                <th style="text-align: right;">Revenue</th>
                            </tr>
                        </thead>
                        <?php endif; ?>
                        <tbody>
                            <?php foreach ($gatewayPairs as $pair): ?>
                                <?php
                                $failedName = $pair['failed_gateway'];
                                $retryName = $pair['retry_gateway'];
                                foreach ($gateways as $g) {
                                    if ($g->id === $pair['failed_gateway']) $failedName = $g->displayName;
                                    if ($g->id === $pair['retry_gateway']) $retryName = $g->displayName;
                                }
                                ?>
                                <tr>
                                    <td><span style="color: #dc2626; font-weight: 500;"><?php echo esc_html($failedName); ?></span></td>
                                    <td style="text-align: center; color: #22c55e;">&#x2192;</td>
                                    <td><span style="color: #22c55e; font-weight: 500;"><?php echo esc_html($retryName); ?></span></td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo number_format((int)$pair['recovered_count']); ?></td>
                                    <td style="text-align: right; font-weight: 600; color: #22c55e;">$<?php echo number_format((float)$pair['recovered_revenue'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Failures -->
    <div class="po-card" style="margin-bottom: 20px;">
        <div class="po-card-header">
            <h2 style="margin: 0;">Recent Failures</h2>
        </div>
        <div class="po-card-body" style="padding: 0;">
            <?php
            global $wpdb;
            $recentFailures = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}po_failed_transactions 
                WHERE month_year = %s 
                ORDER BY id DESC LIMIT 25",
                $selectedMonth
            ), ARRAY_A);
            ?>
            <?php if (empty($recentFailures)): ?>
                <p style="padding: 20px; color: #94a3b8; text-align: center;">No failures captured yet. Data will appear here after the next failed transaction.</p>
            <?php else: ?>
                <table class="po-table" style="margin: 0; font-size: 13px;">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Gateway</th>
                            <th>Code</th>
                            <th>Reason</th>
                            <th>Category</th>
                            <th style="text-align: right;">Amount</th>
                            <th>Status</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentFailures as $f): ?>
                            <?php
                            $catColor = match($f['failure_category']) {
                                'retryable_gateway' => '#2563eb',
                                'retryable_card_type' => '#334155',
                                'non_retryable' => '#dc2626',
                                default => '#94a3b8',
                            };
                            $catLabel = match($f['failure_category']) {
                                'retryable_gateway' => '' . "\u{1F504}" . ' Gateway',
                                'retryable_card_type' => '' . "\u{1F504}" . ' Card Type',
                                'non_retryable' => '' . "\u{274C}" . ' Customer',
                                default => '' . "\u{26AA}" . ' Other',
                            };
                            $gwName = $f['gateway_id'];
                            foreach ($gateways as $g) {
                                if ($g->id === $f['gateway_id']) { $gwName = $g->displayName; break; }
                            }
                            
                            $liveOrder = wc_get_order((int) $f['order_id']);
                            $isRecovered = (int) $f['recovered'];
                            $liveStatus = $liveOrder ? $liveOrder->get_status() : 'unknown';
                            $isPaid = $liveOrder && $liveOrder->is_paid();
                            
                            if (!$isRecovered && $isPaid) {
                                $isRecovered = 2;
                            }
                            
                            $utcTimestamp = strtotime($f['created_at'] . ' UTC');
                            $localTime = wp_date('M j g:ia', $utcTimestamp);
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url($liveOrder ? $liveOrder->get_edit_order_url() : admin_url('post.php?post=' . $f['order_id'] . '&action=edit')); ?>" target="_blank" style="font-weight: 600;">
                                        #<?php echo esc_html($f['order_id']); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($gwName); ?></td>
                                <td><code style="background: #f1f5f9; padding: 2px 6px; border-radius: 3px; font-size: 11px;"><?php echo esc_html($f['failure_code'] ?: '' . "\u{2014}" . ''); ?></code></td>
                                <td style="max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr($f['failure_reason']); ?>">
                                    <?php echo esc_html(mb_substr($f['failure_reason'], 0, 35)); ?>
                                </td>
                                <td><span style="color: <?php echo $catColor; ?>; font-size: 12px; font-weight: 600;"><?php echo $catLabel; ?></span></td>
                                <td style="text-align: right;">$<?php echo number_format((float)$f['order_total'], 2); ?></td>
                                <td>
                                    <?php if ($isRecovered === 1): ?>
                                        <span style="color: #22c55e; font-weight: 600;">&#x2705; Recovered</span>
                                        <?php if ($f['retry_gateway']): ?>
                                            <?php
                                            $retryName = $f['retry_gateway'];
                                            foreach ($gateways as $g) {
                                                if ($g->id === $f['retry_gateway']) { $retryName = $g->displayName; break; }
                                            }
                                            ?>
                                            <br><span style="font-size: 11px; color: #64748b;">via <?php echo esc_html($retryName); ?></span>
                                        <?php endif; ?>
                                    <?php elseif ($isRecovered === 2): ?>
                                        <span style="color: #22c55e; font-weight: 600;">&#x2705; Paid</span>
                                        <?php
                                        // Use POP display name if this gateway is managed, otherwise fall back to WC title
                                        $paidGwId = $liveOrder->get_payment_method();
                                        $paidGwName = $liveOrder->get_payment_method_title() ?: $paidGwId;
                                        foreach ($gateways as $g) {
                                            if ($g->id === $paidGwId) { $paidGwName = $g->displayName; break; }
                                        }
                                        ?>
                                        <br><span style="font-size: 11px; color: #64748b;">via <?php echo esc_html($paidGwName); ?></span>
                                    <?php elseif ($liveStatus === 'cancelled'): ?>
                                        <span style="color: #94a3b8;">&#x1F6AB; Cancelled</span>
                                    <?php elseif ($liveStatus === 'failed' && ($f['failure_category'] === 'retryable_gateway' || $f['failure_category'] === 'retryable_card_type')): ?>
                                        <span style="color: #f59e0b;">&#x23F3; Awaiting Retry</span>
                                    <?php elseif ($liveStatus === 'pending'): ?>
                                        <span style="color: #f59e0b;">&#x23F3; Pending</span>
                                    <?php elseif ($liveStatus === 'failed'): ?>
                                        <span style="color: #dc2626;">&#x2717; Failed</span>
                                    <?php else: ?>
                                        <span style="color: #64748b;"><?php echo esc_html(ucfirst($liveStatus)); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px; color: #64748b;"><?php echo esc_html($localTime); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Failure Classification Rules (bottom) -->
    <div class="po-card">
        <div class="po-card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0;">Failure Classification Rules</h2>
            <span style="font-size: 12px; color: #94a3b8;">How each failure code is classified across all gateways</span>
        </div>
        <div class="po-card-body" style="padding: 0;">
            <?php foreach ($classifierRules as $catKey => $cat): ?>
                <div style="border-bottom: 1px solid #e2e8f0; padding: 16px 20px;">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                        <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: <?php echo $cat['color']; ?>;"></span>
                        <strong style="font-size: 14px;"><?php echo esc_html($cat['label']); ?></strong>
                        <span style="font-size: 12px; padding: 2px 8px; border-radius: 10px; background: <?php echo $catKey === 'non_retryable' ? '#fef2f2' : ($catKey === 'retryable_gateway' ? '#eff6ff' : '#f5f3ff'); ?>; color: <?php echo $cat['color']; ?>;">
                            <?php echo esc_html($cat['health_impact']); ?>
                        </span>
                    </div>
                    <p style="color: #64748b; font-size: 13px; margin: 0 0 12px 24px;"><?php echo esc_html($cat['description']); ?></p>
                    <div style="margin-left: 24px; display: flex; flex-wrap: wrap; gap: 6px;">
                        <?php foreach ($cat['codes'] as $code => $desc): ?>
                            <span title="<?php echo esc_attr($desc); ?>" style="display: inline-block; font-size: 12px; padding: 3px 10px; border-radius: 4px; background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; cursor: help;">
                                <code style="font-size: 11px; color: <?php echo $cat['color']; ?>; font-weight: 600;"><?php echo esc_html($code); ?></code>
                                <span style="color: #94a3b8; margin-left: 4px;"><?php echo esc_html($desc); ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
