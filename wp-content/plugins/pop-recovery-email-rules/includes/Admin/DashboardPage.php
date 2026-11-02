<?php
declare(strict_types=1);
namespace POPRecoveryEmail\Admin;
defined('ABSPATH') || exit;

final class DashboardPage {

    /**
     * Convert a UTC datetime string from DB to local time for display.
     */
    private static function local(string $utc, string $format): string {
        return get_date_from_gmt($utc, $format);
    }

    /**
     * HPOS-aware order edit link (post.php does not open orders stored in wc_orders).
     */
    public static function orderEditUrl(int $orderId): string {
        if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId);
        }
        return admin_url('post.php?post=' . $orderId . '&action=edit');
    }

    public function render(): void {
        global $wpdb;
        $seq = $wpdb->prefix . 'po_email_sequences';
        $po  = $wpdb->prefix . 'po_failed_transactions';
        $today = gmdate('Y-m-d');
        $month = gmdate('Y-m-d', strtotime('-30 days'));

        $ts = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) as q, SUM(status IN ('sent','recovered','paid_direct')) as s, SUM(opened_at IS NOT NULL) as o, SUM(clicked_at IS NOT NULL) as c, SUM(status='recovered') as r, SUM(IF(status='recovered',order_total,0)) as rv FROM {$seq} WHERE DATE(created_at)=%s",$today));
        $ms = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) as q, SUM(status='recovered') as r, SUM(IF(status='recovered',order_total,0)) as rv FROM {$seq} WHERE created_at>=%s",$month.' 00:00:00'));
        $atId = (int)get_option('prer_active_template_id',0);
        $at = $atId ? $wpdb->get_row($wpdb->prepare("SELECT name FROM {$wpdb->prefix}po_email_templates WHERE id=%d",$atId)) : null;
        $rr = ((int)($ms->q??0))>0 ? round((int)($ms->r??0)/(int)$ms->q*100,1) : 0;

        // Revenue split - from POP's failed_transactions
        $popRevToday = 0; $emailRevToday = 0; $totalRevToday = 0;
        $popRev30 = 0; $emailRev30 = 0; $totalRev30 = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$po}'")) {
            $revToday = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(recovery_source,'auto_redirect') as src, SUM(order_total) as rv FROM {$po} WHERE recovered=1 AND DATE(created_at)=%s GROUP BY src", $today));
            foreach ($revToday as $r) {
                $totalRevToday += (float)$r->rv;
                if ($r->src === 'email') $emailRevToday = (float)$r->rv;
                else $popRevToday += (float)$r->rv;
            }
            $rev30 = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(recovery_source,'auto_redirect') as src, SUM(order_total) as rv FROM {$po} WHERE recovered=1 AND created_at>=%s GROUP BY src", $month.' 00:00:00'));
            foreach ($rev30 as $r) {
                $totalRev30 += (float)$r->rv;
                if ($r->src === 'email') $emailRev30 = (float)$r->rv;
                else $popRev30 += (float)$r->rv;
            }
        }

        $queue = $wpdb->get_results("SELECT order_id, customer_email, order_total, failure_code, failure_category, template_id, send_at, created_at FROM {$seq} WHERE status='pending' ORDER BY send_at ASC LIMIT 50");
        $recent = $wpdb->get_results("SELECT order_id, customer_email, order_total, failure_code, failure_category, status, cancelled_reason, sent_at, opened_at, clicked_at, recovered_at, created_at FROM {$seq} WHERE status != 'pending' ORDER BY COALESCE(sent_at, created_at) DESC LIMIT 15");
        ?>

        <div class="prer-stats">
            <?php
            $cards = [
                ['Sent Today', (int)($ts->s??0), '#e0f2fe','#0284c7','<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>'],
                ['Opened', (int)($ts->o??0), '#fef3c7','#d97706','<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>'],
                ['Clicked', (int)($ts->c??0), '#dbeafe','#2563eb','<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>'],
                ['Paid via link', (int)($ts->r??0), '#dcfce7','#16a34a','<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'],
            ];
            foreach ($cards as [$l,$v,$bg,$fg,$ic]): ?>
            <div class="prer-stat-card"><div class="prer-stat-icon" style="background:<?php echo $bg;?>;color:<?php echo $fg;?>;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?php echo $ic;?></svg></div><div><div class="prer-stat-value"><?php echo $v;?></div><div class="prer-stat-label"><?php echo $l;?></div></div></div>
            <?php endforeach; ?>
        </div>

        <!-- Revenue Cards -->
        <div class="prer-revenue-cards" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:20px 0">
            <div class="prer-stat-card prer-stat-highlight"><div class="prer-stat-icon" style="background:rgba(255,255,255,.15);color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div><div><div class="prer-stat-value"><?php echo wc_price($totalRevToday);?></div><div class="prer-stat-label">Total Recovered Today</div></div></div>
            <div class="prer-stat-card" style="border-left:3px solid #7c3aed"><div class="prer-stat-icon" style="background:#ede9fe;color:#7c3aed"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div><div><div class="prer-stat-value"><?php echo wc_price($popRevToday);?></div><div class="prer-stat-label">Payment Orchestrator</div></div></div>
            <div class="prer-stat-card" style="border-left:3px solid #0284c7"><div class="prer-stat-icon" style="background:#e0f2fe;color:#0284c7"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></div><div><div class="prer-stat-value"><?php echo wc_price($emailRevToday);?></div><div class="prer-stat-label">Email Recovery</div></div></div>
        </div>

        <div class="prer-two-col">
            <div class="prer-card">
                <h2 class="prer-card-title">30-Day Summary</h2>
                <div class="prer-summary-grid">
                    <div class="prer-summary-item"><span class="prer-summary-val"><?php echo number_format((int)($ms->q??0));?></span><span class="prer-summary-lbl">Queued</span></div>
                    <div class="prer-summary-item"><span class="prer-summary-val"><?php echo number_format((int)($ms->r??0));?></span><span class="prer-summary-lbl">Recovered</span></div>
                    <div class="prer-summary-item"><span class="prer-summary-val"><?php echo $rr;?>%</span><span class="prer-summary-lbl">Recovery Rate</span></div>
                    <div class="prer-summary-item prer-summary-highlight"><span class="prer-summary-val"><?php echo wc_price($totalRev30);?></span><span class="prer-summary-lbl">Total Revenue</span></div>
                </div>
                <div style="display:flex;gap:20px;margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0;font-size:13px;color:#64748b">
                    <span>POP: <strong style="color:#7c3aed"><?php echo wc_price($popRev30);?></strong></span>
                    <span>Email: <strong style="color:#0284c7"><?php echo wc_price($emailRev30);?></strong></span>
                </div>
            </div>
            <div class="prer-card">
                <h2 class="prer-card-title">Quick Info</h2>
                <div class="prer-info-list">
                    <div class="prer-info-row"><span class="prer-info-label">Active Template</span><span class="prer-info-value"><?php echo $at ? esc_html($at->name) : '<em style="color:#94a3b8">None</em>';?> <a href="<?php echo admin_url('admin.php?page=pop-email-recovery&tab=templates');?>" class="prer-link-sm">Manage</a></span></div>
                    <div class="prer-info-row"><span class="prer-info-label">Retryable Delay</span><span class="prer-info-value"><?php echo (int)get_option('prer_email_delay',3);?> min</span></div>
                    <div class="prer-info-row"><span class="prer-info-label">Non-Retryable</span><span class="prer-info-value"><?php $nrd=(int)get_option('prer_non_retryable_delay',2); echo $nrd>0 ? esc_html($nrd.' min') : '' . "\u{26A1}" . ' Instant';?></span></div>
                    <div class="prer-info-row"><span class="prer-info-label">On-Hold Recovery</span><span class="prer-info-value"><?php echo get_option('prer_pending_enabled','no')==='yes' ? 'Enabled ('.get_option('prer_pending_delay',120).' min)' : 'Disabled';?></span></div>
                    <div class="prer-info-row"><span class="prer-info-label">Min Order Value</span><span class="prer-info-value"><?php echo wc_price((float)get_option('prer_min_order_value',0));?></span></div>
                    <div class="prer-info-row"><span class="prer-info-label">From</span><span class="prer-info-value"><?php echo esc_html(get_option('prer_from_name',''));?> &lt;<?php echo esc_html(get_option('prer_from_email',''));?>&gt;</span></div>
                </div>
            </div>
        </div>

        <!-- Live Queue -->
        <div class="prer-card" style="margin-top:24px" id="prer-queue-card">
            <div class="prer-card-header-row">
                <div class="prer-card-header-left">
                    <h2 class="prer-card-title" style="margin:0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:6px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Queue
                        <span class="prer-queue-count" id="prer-queue-count"><?php echo count($queue); ?></span>
                    </h2>
                </div>
                <div class="prer-card-header-right">
                    <span class="prer-auto-refresh-label" id="prer-refresh-status">
                        <span class="prer-pulse-dot"></span> Auto-refreshes every 30s
                    </span>
                    <button type="button" class="prer-btn prer-btn-ghost prer-btn-sm" id="prer-refresh-queue">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                        Refresh
                    </button>
                    <button type="button" class="prer-btn prer-btn-danger prer-btn-sm" id="prer-clear-queue" style="<?php echo count($queue) ? '' : 'display:none'; ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                        Clear Queue
                    </button>
                </div>
            </div>
            <div id="prer-queue-body" class="prer-table-scroll">
                <?php self::renderQueueTable($queue); ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="prer-card" style="margin-top:24px" id="prer-recent-card">
            <div class="prer-card-header-row">
                <h2 class="prer-card-title" style="margin:0">Recent Activity</h2>
                <a href="<?php echo admin_url('admin.php?page=pop-email-recovery&tab=reports');?>" class="prer-link-sm">Full Reports &#x2192;</a>
            </div>
            <div id="prer-recent-body" class="prer-table-scroll">
                <?php self::renderRecentTable($recent); ?>
            </div>
        </div>

        <!-- Debug Log Viewer -->
        <?php if (get_option('prer_debug_log', 'yes') === 'yes'): ?>
        <div class="prer-card" style="margin-top:24px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                <h2 class="prer-card-title" style="margin:0">Debug Log</h2>
                <div style="display:flex;gap:8px;align-items:center">
                    <select id="prer-log-file" class="prer-log-select"></select>
                    <button type="button" class="button button-small" id="prer-refresh-log">Refresh</button>
                    <button type="button" class="button button-small" id="prer-download-log">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;margin-right:3px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download
                    </button>
                </div>
            </div>
            <pre class="prer-log-viewer" id="prer-log-content">Loading...</pre>
        </div>
        <?php endif; ?>
        <?php
    }

    public static function renderQueueTable(array $queue): void {
        if (empty($queue)): ?>
            <div class="prer-empty-queue">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <p>No emails in queue right now.</p>
            </div>
        <?php return; endif;
        $now = current_time('timestamp', true); // UTC timestamp
        ?>
        <table class="prer-table">
            <thead><tr><th>Order</th><th>Customer</th><th>Amount</th><th>Category</th><th>Code</th><th>Queued</th><th>Sends In</th></tr></thead>
            <tbody>
            <?php foreach ($queue as $q):
                $sendTs = strtotime($q->send_at . ' UTC');
                $diff = $sendTs - time();
                if ($diff <= 0) {
                    $countdown = '<span class="prer-badge-sending">Sending' . "\u{2026}" . '</span>';
                } elseif ($diff < 60) {
                    $countdown = '<span class="prer-countdown-hot">' . $diff . 's</span>';
                } elseif ($diff < 3600) {
                    $countdown = ceil($diff / 60) . ' min';
                } else {
                    $h = round($diff / 3600, 1);
                    $countdown = (floor($h) == $h ? (int)$h : $h) . ' hr';
                }
            ?>
            <tr>
                <td><a href="<?php echo esc_url(self::orderEditUrl((int)$q->order_id));?>" class="prer-order-link">#<?php echo esc_html($q->order_id);?></a></td>
                <td class="prer-email-cell"><?php echo esc_html($q->customer_email);?></td>
                <td><?php echo wc_price((float)$q->order_total);?></td>
                <td><span class="prer-cat-badge"><?php echo esc_html(str_replace('_',' ',$q->failure_category));?></span></td>
                <td><span class="prer-code"><?php echo esc_html($q->failure_code);?></span></td>
                <td class="prer-nowrap"><?php echo esc_html(self::local($q->created_at, 'g:ia'));?></td>
                <td class="prer-nowrap"><?php echo $countdown;?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function renderRecentTable(array $recent): void {
        global $wpdb;
        ?>
        <table class="prer-table"><thead><tr><th>Order</th><th>Customer</th><th>Amount</th><th>Category</th><th>Code</th><th>Sent</th><th>Opened</th><th>Clicked</th><th>Outcome</th></tr></thead><tbody>
        <?php foreach ($recent as $r):
            // Check live order status for accurate outcome
            $order = wc_get_order((int)$r->order_id);
            $orderPaid = $order && in_array($order->get_status(), ['processing','completed'], true);

            if ($r->status === 'recovered' || $r->status === 'paid_direct' || ($r->status === 'sent' && $orderPaid) || ($r->status === 'cancelled' && $orderPaid)) {
                // Determine conversion source
                $recSource = $wpdb->get_var($wpdb->prepare(
                    "SELECT recovery_source FROM {$wpdb->prefix}po_failed_transactions WHERE order_id=%d AND recovered=1 ORDER BY id DESC LIMIT 1",
                    (int)$r->order_id
                ));
                // 1.5.9: email gets credit only for "recovered" (clicked + paid via the
                // email's pay link); POP only when POP recorded the recovery.
                if ($r->status === 'recovered' || $recSource === 'email') {
                    $outcomeLabel = '<span class="prer-badge-converted" title="Clicked the email and paid via its pay link">' . "\u{2709}" . ' Email</span>';
                } elseif ($recSource === 'auto_redirect') {
                    $outcomeLabel = '<span class="prer-badge-pop-converted" title="Recovered by Payment Orchestrator auto-retry">' . "\u{26A1}" . ' POP</span>';
                } elseif ($recSource) {
                    $outcomeLabel = '<span class="prer-badge-converted">' . esc_html(ucfirst($recSource)) . '</span>';
                } else {
                    $outcomeLabel = '<span class="prer-status status-sent" title="Paid without using the email link">Paid directly</span>';
                }
            } elseif ($r->status === 'cancelled') {
                $reason = $r->cancelled_reason ?? '';
                $outcomeLabel = '<span class="prer-muted" title="'.esc_attr($reason).'">Cancelled</span>';
            } elseif ($r->status === 'sent') {
                if ($r->clicked_at) {
                    $outcomeLabel = '<span class="prer-badge-pending-conv">Clicked</span>';
                } elseif ($r->opened_at) {
                    $outcomeLabel = '<span class="prer-badge-opened">Opened</span>';
                } else {
                    $outcomeLabel = '<span class="prer-status status-sent">Delivered</span>';
                }
            } else {
                $outcomeLabel = '<span class="prer-muted">' . "\u{2014}" . '</span>';
            }
        ?>
        <tr>
            <td><a href="<?php echo esc_url(self::orderEditUrl((int)$r->order_id));?>" class="prer-order-link">#<?php echo esc_html($r->order_id);?></a></td>
            <td class="prer-email-cell"><?php echo esc_html($r->customer_email);?></td>
            <td><?php echo wc_price((float)$r->order_total);?></td>
            <td><span class="prer-cat-badge"><?php echo esc_html(str_replace('_',' ',$r->failure_category));?></span></td>
            <td><span class="prer-code"><?php echo esc_html($r->failure_code);?></span></td>
            <td class="prer-nowrap"><?php echo $r->sent_at ? esc_html(self::local($r->sent_at, 'M j, g:ia')) : '<span class="prer-muted">' . "\u{2014}" . '</span>';?></td>
            <td><?php echo $r->opened_at ? '<span class="prer-badge-yes">'.esc_html(self::local($r->opened_at, 'g:ia')).'</span>' : '<span class="prer-muted">' . "\u{2014}" . '</span>';?></td>
            <td><?php echo $r->clicked_at ? '<span class="prer-badge-yes">'.esc_html(self::local($r->clicked_at, 'g:ia')).'</span>' : '<span class="prer-muted">' . "\u{2014}" . '</span>';?></td>
            <td><?php echo $outcomeLabel;?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?><tr><td colspan="9" class="prer-empty">No emails sent yet.</td></tr><?php endif; ?>
        </tbody></table>
        <?php
    }
}
