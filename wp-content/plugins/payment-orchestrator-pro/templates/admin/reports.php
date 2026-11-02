<?php
/**
 * Reports admin page template
 * 
 * @var array $gateways
 * @var array $reportData
 * @var string $reportType
 * @var string $selectedGateway
 * @var string $dateFrom
 * @var string $dateTo
 */

defined('ABSPATH') || exit;
?>

<div class="po-reports-page">
    <!-- Shared Header + Tab Nav -->
    <?php $currentPage = 'payment-orchestrator-reports'; include PO_PLUGIN_DIR . 'templates/admin/partials/header-nav.php'; ?>
    
    <!-- Report Filters -->
    <div class="po-card">
        <div class="po-card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2>Transaction Reports</h2>
            <!-- Quick Order Lookup -->
            <div style="display: flex; align-items: center; gap: 8px;">
                <input type="text" id="po-order-lookup" placeholder="Order ID..." style="width: 120px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px;">
                <button type="button" id="po-order-lookup-btn" class="po-btn po-btn-sm" style="white-space: nowrap;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 4px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Lookup
                </button>
            </div>
        </div>
        <div class="po-card-body">
            <form method="get" class="po-report-filters">
                <input type="hidden" name="page" value="payment-orchestrator-reports">
                
                <div class="po-filter-row">
                    <div class="po-filter-group">
                        <label class="po-label">Report Type</label>
                        <select name="report_type" class="po-select" id="po-report-type">
                            <option value="today" <?php selected($reportType, 'today'); ?>>Today</option>
                            <option value="yesterday" <?php selected($reportType, 'yesterday'); ?>>Yesterday</option>
                            <option value="this_week" <?php selected($reportType, 'this_week'); ?>>This Week</option>
                            <option value="this_month" <?php selected($reportType, 'this_month'); ?>>This Month</option>
                            <option value="last_month" <?php selected($reportType, 'last_month'); ?>>Last Month</option>
                            <option value="custom" <?php selected($reportType, 'custom'); ?>>Custom Range</option>
                        </select>
                    </div>
                    
                    <div class="po-filter-group po-date-range" style="<?php echo $reportType !== 'custom' ? 'display:none;' : ''; ?>">
                        <label class="po-label">From</label>
                        <input type="date" name="date_from" class="po-input" value="<?php echo esc_attr($dateFrom); ?>">
                    </div>
                    
                    <div class="po-filter-group po-date-range" style="<?php echo $reportType !== 'custom' ? 'display:none;' : ''; ?>">
                        <label class="po-label">To</label>
                        <input type="date" name="date_to" class="po-input" value="<?php echo esc_attr($dateTo); ?>">
                    </div>
                    
                    <div class="po-filter-group">
                        <label class="po-label">Gateway</label>
                        <select name="gateway" class="po-select">
                            <option value="">All Gateways</option>
                            <?php foreach ($gateways as $gateway): ?>
                                <option value="<?php echo esc_attr($gateway->id); ?>" <?php selected($selectedGateway, $gateway->id); ?>>
                                    <?php echo esc_html($gateway->displayName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="po-filter-group po-filter-actions">
                        <button type="submit" class="po-btn po-btn-primary">Generate Report</button>
                        <button type="button" class="po-btn po-btn-secondary" id="po-export-csv">Export CSV</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if (!empty($reportData)): ?>
    <!-- Summary Cards -->
    <div class="po-report-summary">
        <div class="po-summary-card">
            <div class="po-summary-value"><?php echo number_format($reportData['summary']['total_transactions']); ?></div>
            <div class="po-summary-label">Total Transactions</div>
        </div>
        <div class="po-summary-card po-summary-success">
            <div class="po-summary-value"><?php echo number_format($reportData['summary']['successful']); ?></div>
            <div class="po-summary-label">Successful</div>
        </div>
        <div class="po-summary-card po-summary-failed">
            <div class="po-summary-value"><?php echo number_format($reportData['summary']['failed']); ?></div>
            <div class="po-summary-label">Failed</div>
        </div>
        <div class="po-summary-card">
            <div class="po-summary-value">$<?php echo number_format($reportData['summary']['total_volume'], 2); ?></div>
            <div class="po-summary-label">Total Volume</div>
        </div>
        <div class="po-summary-card">
            <div class="po-summary-value"><?php echo $reportData['summary']['success_rate']; ?>%</div>
            <div class="po-summary-label">Success Rate</div>
        </div>
    </div>
    
    <!-- Gateway Breakdown -->
    <div class="po-card">
        <div class="po-card-header">
            <h2>Gateway Breakdown</h2>
        </div>
        <div class="po-card-body">
            <table class="po-table">
                <thead>
                    <tr>
                        <th>Gateway</th>
                        <th>Successful</th>
                        <th>Failed</th>
                        <th>Total</th>
                        <th>Volume</th>
                        <th>Success Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportData['by_gateway'] as $gw): 
                        $isDayView = in_array($reportType, ['today', 'yesterday']);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($gw['display_name']); ?></strong></td>
                        <td>
                            <?php if ($isDayView && $gw['successful'] > 0): ?>
                                <a href="#" class="po-drill-link po-text-success" 
                                   data-gateway="<?php echo esc_attr($gw['gateway_id']); ?>"
                                   data-status="success"
                                   data-label="<?php echo esc_attr($gw['display_name']); ?> &#x2014; Successful">
                                    <?php echo number_format($gw['successful']); ?>
                                </a>
                            <?php else: ?>
                                <span class="po-text-success"><?php echo number_format($gw['successful']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isDayView && $gw['failed'] > 0): ?>
                                <a href="#" class="po-drill-link po-text-danger"
                                   data-gateway="<?php echo esc_attr($gw['gateway_id']); ?>"
                                   data-status="failed"
                                   data-label="<?php echo esc_attr($gw['display_name']); ?> &#x2014; Failed">
                                    <?php echo number_format($gw['failed']); ?>
                                </a>
                            <?php else: ?>
                                <span class="po-text-danger"><?php echo number_format($gw['failed']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isDayView && $gw['total'] > 0): ?>
                                <a href="#" class="po-drill-link"
                                   data-gateway="<?php echo esc_attr($gw['gateway_id']); ?>"
                                   data-status=""
                                   data-label="<?php echo esc_attr($gw['display_name']); ?> &#x2014; All Transactions">
                                    <?php echo number_format($gw['total']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo number_format($gw['total']); ?>
                            <?php endif; ?>
                        </td>
                        <td>$<?php echo number_format($gw['volume'], 2); ?></td>
                        <td>
                            <span class="po-badge <?php echo $gw['success_rate'] >= 95 ? 'po-badge-success' : ($gw['success_rate'] >= 80 ? 'po-badge-warning' : 'po-badge-danger'); ?>">
                                <?php echo $gw['success_rate']; ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Drill-Down Panel (hidden by default) -->
    <div class="po-card" id="po-drill-down-panel" style="display:none;">
        <div class="po-card-header">
            <h2 id="po-drill-down-title">Transaction Details</h2>
            <button type="button" class="po-btn po-btn-sm" id="po-drill-down-close" style="background:#f1f5f9;color:#334155;">&#x2715; Close</button>
        </div>
        <div class="po-card-body">
            <div id="po-drill-down-loading" style="text-align:center;padding:24px;color:#64748b;">
                Loading transactions...
            </div>
            <div id="po-drill-down-content" style="display:none;">
                <p id="po-drill-down-count" style="margin:0 0 12px;font-size:13px;color:#64748b;"></p>
                <table class="po-table po-table-striped">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Gateway</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Details</th>
                            <th>Time</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="po-drill-down-body"></tbody>
                </table>
            </div>
            <div id="po-drill-down-empty" style="display:none;text-align:center;padding:24px;color:#94a3b8;">
                No transactions found.
            </div>
        </div>
    </div>
    
    <script>
    (function($) {
        var drillPanel = $('#po-drill-down-panel');
        var drillBody = $('#po-drill-down-body');
        var drillLoading = $('#po-drill-down-loading');
        var drillContent = $('#po-drill-down-content');
        var drillEmpty = $('#po-drill-down-empty');
        
        function doDrillDown(params, label) {
            drillPanel.show();
            drillLoading.show();
            drillContent.hide();
            drillEmpty.hide();
            $('#po-drill-down-title').text(label);
            
            // Scroll to panel
            $('html, body').animate({scrollTop: drillPanel.offset().top - 40}, 300);
            
            var ajaxData = $.extend({
                action: 'po_drill_down',
                nonce: '<?php echo wp_create_nonce("po_admin_nonce"); ?>',
                date_from: '<?php echo esc_js($dateFrom); ?>',
                date_to: '<?php echo esc_js($dateTo); ?>'
            }, params);
            
            $.post(ajaxurl, ajaxData, function(response) {
                drillLoading.hide();
                if (!response.success || !response.data.rows.length) {
                    drillEmpty.show();
                    return;
                }
                
                var rows = response.data.rows;
                var total = response.data.total;
                drillBody.empty();
                
                rows.forEach(function(row) {
                    var statusClass = row.status === 'success' ? 'po-text-success' : 'po-text-danger';
                    var statusLabel = row.status === 'success' ? '&#x2713; Success' : '&#x2717; Failed';
                    var details = row.failure_reason || (row.status === 'success' ? '&#x2014;' : 'Unknown');
                    var recoveredBadge = '';
                    if (row.recovered === '1' || row.recovered === 1) {
                        recoveredBadge = ' <span style="display:inline-block;padding:1px 6px;background:#dcfce7;color:#16a34a;border-radius:4px;font-size:10px;font-weight:600;">RECOVERED</span>';
                    }
                    var time = row.created_at ? row.created_at.split(' ')[1] || row.created_at : '';
                    
                    drillBody.append(
                        '<tr>' +
                        '<td><a href="' + row.edit_url + '" target="_blank">#' + row.order_id + '</a></td>' +
                        '<td>' + (row.gateway_name || row.gateway_id) + '</td>' +
                        '<td>$' + parseFloat(row.amount || 0).toFixed(2) + '</td>' +
                        '<td><span class="' + statusClass + '">' + statusLabel + '</span>' + recoveredBadge + '</td>' +
                        '<td><span title="' + details.replace(/"/g, '&quot;') + '" style="max-width:250px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;">' + details + '</span></td>' +
                        '<td>' + time + '</td>' +
                        '<td><a href="' + row.edit_url + '" target="_blank" class="po-btn po-btn-sm">View</a></td>' +
                        '</tr>'
                    );
                });
                
                var countText = total > rows.length ? 'Showing ' + rows.length + ' of ' + total : total + ' transaction' + (total !== 1 ? 's' : '');
                $('#po-drill-down-count').text(countText);
                drillContent.show();
            }).fail(function() {
                drillLoading.hide();
                drillEmpty.text('Failed to load transactions.').show();
            });
        }
        
        // Gateway breakdown drill-down links
        $(document).on('click', '.po-drill-link', function(e) {
            e.preventDefault();
            doDrillDown({
                gateway_id: $(this).data('gateway'),
                status: $(this).data('status') || ''
            }, $(this).data('label'));
        });
        
        // Failure code drill-down links (from Recovery page link)
        $(document).on('click', '.po-drill-code-link', function(e) {
            e.preventDefault();
            doDrillDown({
                failure_code: $(this).data('code')
            }, 'Failure Code ' + $(this).data('code') + ' &#x2014; Transactions');
        });
        
        // Close panel
        $('#po-drill-down-close').on('click', function() {
            drillPanel.slideUp(200);
        });
    })(jQuery);
    </script>
    
    <!-- Failed Transactions -->
    <?php if (!empty($reportData['failed_transactions'])): ?>
    <div class="po-card">
        <div class="po-card-header">
            <h2>Failed Transactions</h2>
            <span class="po-badge po-badge-danger"><?php echo count($reportData['failed_transactions']); ?> failures</span>
        </div>
        <div class="po-card-body">
            <table class="po-table po-table-striped">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Gateway</th>
                        <th>Amount</th>
                        <th>Date/Time</th>
                        <th>Failure Reason</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportData['failed_transactions'] as $txn): ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $txn['order_id'] . '&action=edit'); ?>" target="_blank">
                                #<?php echo esc_html($txn['order_id']); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($txn['gateway_name']); ?></td>
                        <td>$<?php echo number_format($txn['amount'], 2); ?></td>
                        <td><?php echo esc_html($txn['created_at']); ?></td>
                        <td>
                            <span class="po-failure-reason" title="<?php echo esc_attr($txn['failure_reason']); ?>">
                                <?php echo esc_html($txn['failure_reason'] ?: 'Unknown'); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $txn['order_id'] . '&action=edit'); ?>" 
                               class="po-btn po-btn-sm" target="_blank">View Order</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($reportData['failed_count_total'] > count($reportData['failed_transactions'])): ?>
            <p class="po-text-muted" style="margin-top: 1rem;">
                Showing <?php echo count($reportData['failed_transactions']); ?> of <?php echo $reportData['failed_count_total']; ?> failed transactions.
                Export to CSV for complete list.
            </p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Recent Transactions -->
    <div class="po-card">
        <div class="po-card-header">
            <h2>Recent Transactions</h2>
        </div>
        <div class="po-card-body">
            <table class="po-table po-table-striped">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Gateway</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date/Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportData['transactions'] as $txn): ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $txn['order_id'] . '&action=edit'); ?>" target="_blank">
                                #<?php echo esc_html($txn['order_id']); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($txn['gateway_name']); ?></td>
                        <td>$<?php echo number_format($txn['amount'], 2); ?></td>
                        <td>
                            <span class="po-badge <?php echo $txn['status'] === 'success' ? 'po-badge-success' : 'po-badge-danger'; ?>">
                                <?php echo ucfirst($txn['status']); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($txn['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (empty($reportData['transactions'])): ?>
            <p class="po-text-muted">No transactions found for the selected period.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php else: ?>
    <div class="po-card">
        <div class="po-card-body">
            <p class="po-text-muted">Select a report type and click "Generate Report" to view transaction data.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // Toggle date range fields
    $('#po-report-type').on('change', function() {
        if ($(this).val() === 'custom') {
            $('.po-date-range').show();
        } else {
            $('.po-date-range').hide();
        }
    });
    
    // Export CSV
    $('#po-export-csv').on('click', function() {
        var params = new URLSearchParams(window.location.search);
        params.set('export', 'csv');
        window.location.href = window.location.pathname + '?' + params.toString();
    });
    
    // Auto-trigger drill-down from URL params (e.g. from Recovery page links)
    var urlParams = new URLSearchParams(window.location.search);
    var drillCode = urlParams.get('drill_code');
    if (drillCode) {
        // Small delay to ensure DOM is ready
        setTimeout(function() {
            var drillPanel = $('#po-drill-down-panel');
            var drillBody = $('#po-drill-down-body');
            var drillLoading = $('#po-drill-down-loading');
            var drillContent = $('#po-drill-down-content');
            var drillEmpty = $('#po-drill-down-empty');
            
            drillPanel.show();
            drillLoading.show();
            drillContent.hide();
            drillEmpty.hide();
            $('#po-drill-down-title').text('Failure Code ' + drillCode + ' &#x2014; Transactions');
            
            $('html, body').animate({scrollTop: drillPanel.offset().top - 40}, 300);
            
            $.post(ajaxurl, {
                action: 'po_drill_down',
                nonce: '<?php echo wp_create_nonce("po_admin_nonce"); ?>',
                failure_code: drillCode,
                date_from: '<?php echo esc_js($dateFrom); ?>',
                date_to: '<?php echo esc_js($dateTo); ?>'
            }, function(response) {
                drillLoading.hide();
                if (!response.success || !response.data.rows.length) {
                    drillEmpty.show();
                    return;
                }
                var rows = response.data.rows;
                var total = response.data.total;
                drillBody.empty();
                rows.forEach(function(row) {
                    var details = row.failure_reason || 'Unknown';
                    var recoveredBadge = '';
                    if (row.recovered === '1' || row.recovered === 1) {
                        recoveredBadge = ' <span style="display:inline-block;padding:1px 6px;background:#dcfce7;color:#16a34a;border-radius:4px;font-size:10px;font-weight:600;">RECOVERED</span>';
                    }
                    var time = row.created_at ? row.created_at.split(' ')[1] || row.created_at : '';
                    drillBody.append(
                        '<tr><td><a href="' + row.edit_url + '" target="_blank">#' + row.order_id + '</a></td>' +
                        '<td>' + (row.gateway_name || row.gateway_id) + '</td>' +
                        '<td>$' + parseFloat(row.amount || 0).toFixed(2) + '</td>' +
                        '<td><span class="po-text-danger">&#x2717; Failed</span>' + recoveredBadge + '</td>' +
                        '<td><span title="' + details.replace(/"/g, '&quot;') + '" style="max-width:250px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;">' + details + '</span></td>' +
                        '<td>' + time + '</td>' +
                        '<td><a href="' + row.edit_url + '" target="_blank" class="po-btn po-btn-sm">View</a></td></tr>'
                    );
                });
                var countText = total > rows.length ? 'Showing ' + rows.length + ' of ' + total : total + ' transaction' + (total !== 1 ? 's' : '');
                $('#po-drill-down-count').text(countText);
                drillContent.show();
            });
        }, 200);
    }
    
    // Order ID quick lookup
    $('#po-order-lookup-btn').on('click', function() {
        var orderId = $('#po-order-lookup').val().replace(/[^0-9]/g, '');
        if (!orderId) { alert('Please enter an Order ID'); return; }
        
        var btn = $(this);
        btn.prop('disabled', true).text('Looking up...');
        
        $.post(ajaxurl, {
            action: 'po_order_lookup',
            nonce: poAdmin.nonce,
            order_id: orderId
        }, function(response) {
            btn.prop('disabled', false).html('<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Lookup');
            if (!response.success) {
                alert(response.data || 'Order not found');
                return;
            }
            var d = response.data;
            var html = '<div class="po-card" style="margin-bottom:20px; border:2px solid #3b82f6;" id="po-lookup-result">' +
                '<div class="po-card-header" style="display:flex;justify-content:space-between;align-items:center;">' +
                '<h2>Order #' + d.order_id + '</h2>' +
                '<div><a href="' + d.edit_url + '" target="_blank" class="po-btn po-btn-sm po-btn-primary">View in WooCommerce</a> ' +
                '<button type="button" class="po-btn po-btn-sm" onclick="document.getElementById(\'po-lookup-result\').remove()">Close</button></div></div>' +
                '<div class="po-card-body"><table class="po-table"><tbody>' +
                '<tr><td><strong>Status</strong></td><td>' + d.status + '</td></tr>' +
                '<tr><td><strong>Amount</strong></td><td>$' + d.amount + '</td></tr>' +
                '<tr><td><strong>Gateway</strong></td><td>' + d.gateway_name + ' <span style="color:#94a3b8;">(' + d.gateway_id + ')</span></td></tr>' +
                '<tr><td><strong>Date</strong></td><td>' + d.date + '</td></tr>';
            
            if (d.failure) {
                html += '<tr><td><strong>Failure Code</strong></td><td>' + (d.failure.code || 'N/A') + '</td></tr>' +
                    '<tr><td><strong>Failure Reason</strong></td><td>' + (d.failure.reason || 'N/A') + '</td></tr>' +
                    '<tr><td><strong>Category</strong></td><td>' + (d.failure.category || 'N/A') + '</td></tr>' +
                    '<tr><td><strong>Recovered</strong></td><td>' + (d.failure.recovered ? '<span style="color:#16a34a;font-weight:600;">Yes</span>' : '<span style="color:#dc2626;">No</span>') + '</td></tr>';
                if (d.failure.retry_gateway) {
                    html += '<tr><td><strong>Recovery Gateway</strong></td><td>' + d.failure.retry_gateway_name + ' <span style="color:#94a3b8;">(' + d.failure.retry_gateway + ')</span></td></tr>';
                }
                if (d.failure.recovery_source) {
                    html += '<tr><td><strong>Recovery Source</strong></td><td>' + d.failure.recovery_source + '</td></tr>';
                }
            }
            
            html += '</tbody></table></div></div>';
            
            // Remove previous result and insert after filters
            $('#po-lookup-result').remove();
            $('.po-report-summary').length ? $('.po-report-summary').before(html) : $('.po-card').first().after(html);
        });
    });
    
    // Allow Enter key in lookup field
    $('#po-order-lookup').on('keypress', function(e) {
        if (e.which === 13) { e.preventDefault(); $('#po-order-lookup-btn').click(); }
    });
});
</script>
