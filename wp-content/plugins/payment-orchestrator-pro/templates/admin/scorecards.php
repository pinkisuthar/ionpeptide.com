<?php
/**
 * Gateway Scorecards admin page template
 * 
 * @var array $scorecards
 * @var array $totals
 * @var string $viewMode
 * @var string $selectedMonth
 * @var array $months
 */

defined('ABSPATH') || exit;
?>

<div class="po-wrap">
    <!-- Shared Header + Tab Nav -->
    <?php $currentPage = 'payment-orchestrator-scorecards'; include PO_PLUGIN_DIR . 'templates/admin/partials/header-nav.php'; ?>

    <!-- View Toggle -->
    <div class="po-card" style="margin-bottom:20px;">
        <div class="po-card-body" style="padding:16px 20px; display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
            <div style="display:flex; gap:4px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=payment-orchestrator-scorecards&view=today')); ?>" 
                   class="po-btn <?php echo $viewMode === 'today' ? 'po-btn-primary' : ''; ?>" 
                   style="<?php echo $viewMode !== 'today' ? 'background:#f1f5f9;color:#334155;' : ''; ?>">
                    Today
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=payment-orchestrator-scorecards&view=month&month=' . $selectedMonth)); ?>" 
                   class="po-btn <?php echo $viewMode === 'month' ? 'po-btn-primary' : ''; ?>"
                   style="<?php echo $viewMode !== 'month' ? 'background:#f1f5f9;color:#334155;' : ''; ?>">
                    Monthly
                </a>
            </div>
            
            <?php if ($viewMode === 'month'): ?>
            <form method="get" style="display:flex; align-items:center; gap:8px; margin:0;">
                <input type="hidden" name="page" value="payment-orchestrator-scorecards">
                <input type="hidden" name="view" value="month">
                <select name="month" class="po-select" style="min-width:160px;" onchange="this.form.submit()">
                    <?php foreach ($months as $m => $label): ?>
                        <option value="<?php echo esc_attr($m); ?>" <?php selected($selectedMonth, $m); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            
            <span style="margin-left:auto; font-size:12px; color:#94a3b8;">
                <?php echo $viewMode === 'today' ? date('l, F j, Y') : esc_html($months[$selectedMonth] ?? $selectedMonth); ?>
            </span>
        </div>
    </div>

    <!-- Totals Summary Bar -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:20px;">
        <div class="po-card" style="margin:0;">
            <div class="po-card-body" style="padding:16px; text-align:center;">
                <div style="font-size:11px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.5px; margin-bottom:4px;">Total Processed</div>
                <div style="font-size:24px; font-weight:700; color:#1e293b;">$<?php echo number_format($totals['volume'], 0); ?></div>
                <div style="font-size:12px; color:#64748b;"><?php echo number_format($totals['successful']); ?> successful</div>
            </div>
        </div>
        <div class="po-card" style="margin:0;">
            <div class="po-card-body" style="padding:16px; text-align:center;">
                <div style="font-size:11px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.5px; margin-bottom:4px;">Success Rate</div>
                <div style="font-size:24px; font-weight:700; color:<?php echo $totals['success_rate'] >= 95 ? '#22c55e' : ($totals['success_rate'] >= 85 ? '#eab308' : '#ef4444'); ?>;">
                    <?php echo $totals['success_rate']; ?>%
                </div>
                <div style="font-size:12px; color:#64748b;"><?php echo number_format($totals['total']); ?> total attempts</div>
            </div>
        </div>
        <div class="po-card" style="margin:0;">
            <div class="po-card-body" style="padding:16px; text-align:center;">
                <div style="font-size:11px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.5px; margin-bottom:4px;">Failed</div>
                <div style="font-size:24px; font-weight:700; color:#ef4444;"><?php echo number_format($totals['failed_count']); ?></div>
                <div style="font-size:12px; color:#64748b;">$<?php echo number_format($totals['lost_revenue'], 0); ?> lost</div>
            </div>
        </div>
        <div class="po-card" style="margin:0;">
            <div class="po-card-body" style="padding:16px; text-align:center;">
                <div style="font-size:11px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.5px; margin-bottom:4px;">Recovered</div>
                <div style="font-size:24px; font-weight:700; color:#22c55e;"><?php echo number_format($totals['recovered_count']); ?></div>
                <div style="font-size:12px; color:#64748b;">$<?php echo number_format($totals['recovered_revenue'], 0); ?></div>
            </div>
        </div>
        <div class="po-card" style="margin:0;">
            <div class="po-card-body" style="padding:16px; text-align:center;">
                <div style="font-size:11px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.5px; margin-bottom:4px;">Recovery Rate</div>
                <div style="font-size:24px; font-weight:700; color:<?php echo $totals['recovery_rate'] > 0 ? '#22c55e' : '#94a3b8'; ?>;">
                    <?php echo $totals['recovery_rate']; ?>%
                </div>
                <div style="font-size:12px; color:#64748b;">Net lost: $<?php echo number_format($totals['net_lost'], 0); ?></div>
            </div>
        </div>
    </div>

    <!-- Gateway Scorecards -->
    <?php if (empty($scorecards)): ?>
        <div class="po-card">
            <div class="po-card-body" style="text-align:center; padding:40px; color:#94a3b8;">
                No gateways configured. Add gateways in <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-gateways'); ?>">Gateway Management</a>.
            </div>
        </div>
    <?php else: ?>
        <div class="po-scorecard-grid">
            <?php foreach ($scorecards as $card): 
                $rateColor = $card['success_rate'] >= 95 ? '#22c55e' : ($card['success_rate'] >= 85 ? '#eab308' : '#ef4444');
                $rateColorBg = $card['success_rate'] >= 95 ? '#f0fdf4' : ($card['success_rate'] >= 85 ? '#fefce8' : '#fef2f2');
                $hasActivity = $card['total'] > 0;
                $statusDot = $card['is_enabled'] ? '#22c55e' : '#ef4444';
            ?>
            <div class="po-card" style="margin:0; <?php echo !$card['is_enabled'] ? 'opacity:0.6;' : ''; ?>">
                <!-- Card Header -->
                <div class="po-card-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="width:10px; height:10px; border-radius:50%; background:<?php echo $statusDot; ?>;"></span>
                        <strong style="font-size:15px;"><?php echo esc_html($card['name']); ?></strong>
                        <?php if ($card['is_always_on'] ?? false): ?>
                            <span style="font-size:10px; padding:2px 6px; background:#f1f5f9; border-radius:4px; color:#64748b;">Always On</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasActivity): ?>
                    <div style="background:<?php echo $rateColorBg; ?>; color:<?php echo $rateColor; ?>; padding:4px 12px; border-radius:12px; font-weight:700; font-size:14px;">
                        <?php echo $card['success_rate']; ?>%
                    </div>
                    <?php else: ?>
                    <div style="background:#f8fafc; color:#94a3b8; padding:4px 12px; border-radius:12px; font-size:12px;">
                        No activity
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Card Body -->
                <div class="po-card-body" style="padding:0 20px 20px;">
                    <?php if ($hasActivity): ?>
                    <!-- Primary metrics -->
                    <div class="po-scorecard-stats">
                        <div style="background:#fff; padding:12px; text-align:center;">
                            <div style="font-size:18px; font-weight:700; color:#22c55e;"><?php echo number_format($card['successful']); ?></div>
                            <div style="font-size:11px; color:#64748b;">Successful</div>
                        </div>
                        <div style="background:#fff; padding:12px; text-align:center;">
                            <div style="font-size:18px; font-weight:700; color:#ef4444;"><?php echo number_format($card['failed']); ?></div>
                            <div style="font-size:11px; color:#64748b;">Failed</div>
                        </div>
                        <div style="background:#fff; padding:12px; text-align:center;">
                            <div style="font-size:18px; font-weight:700; color:#1e293b;"><?php echo number_format($card['total']); ?></div>
                            <div style="font-size:11px; color:#64748b;">Total</div>
                        </div>
                    </div>
                    
                    <!-- Financial metrics -->
                    <div style="display:flex; flex-direction:column; gap:8px; font-size:13px;">
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#64748b;">Volume processed</span>
                            <strong style="color:#1e293b;">$<?php echo number_format($card['volume'], 2); ?></strong>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#64748b;">Avg. transaction</span>
                            <span style="color:#1e293b;">$<?php echo number_format($card['avg_transaction'], 2); ?></span>
                        </div>
                        <?php if (($card['failed_on'] ?? 0) > 0): ?>
                        <div style="display:flex; justify-content:space-between; padding-top:6px; border-top:1px solid #f1f5f9;">
                            <span style="color:#ef4444;">Failed on this gateway</span>
                            <strong style="color:#ef4444;"><?php echo number_format($card['failed_on']); ?> ($<?php echo number_format($card['lost_revenue'], 0); ?>)</strong>
                        </div>
                        <?php endif; ?>
                        <?php if ($card['recovered'] > 0): ?>
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#22c55e;">Recovered by this gateway</span>
                            <strong style="color:#22c55e;"><?php echo $card['recovered']; ?> ($<?php echo number_format($card['recovered_revenue'], 0); ?>)</strong>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Top error codes -->
                    <?php if (!empty($card['top_errors'])): ?>
                    <div style="margin-top:14px; padding-top:14px; border-top:1px solid #f1f5f9;">
                        <div style="font-size:11px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.5px; margin-bottom:6px;">Top Errors</div>
                        <?php foreach ($card['top_errors'] as $err): ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:12px; padding:3px 0;">
                            <span>
                                <code style="background:#f8fafc; padding:1px 5px; border-radius:3px; font-size:11px; color:#475569;"><?php echo esc_html($err['failure_code']); ?></code>
                                <span style="color:#94a3b8; margin-left:4px;"><?php echo esc_html(mb_substr($err['failure_reason'], 0, 30)); ?></span>
                            </span>
                            <span style="font-weight:600; color:#475569;"><?php echo number_format((int)$err['cnt']); ?>&#xD7;</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php else: ?>
                    <div style="text-align:center; padding:20px 0; color:#cbd5e1; font-size:13px;">
                        <?php echo $card['is_enabled'] ? 'No transactions recorded for this period.' : 'Gateway is disabled.'; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
