/**
 * Payment Orchestrator Admin Scripts
 */
(function($) {
    'use strict';

    // Send test report
    $(document).on('click', '#po-test-report', function() {
        var $btn = $(this);
        var email = $('input[name="daily_report_email"]').val();
        
        if (!email) {
            alert('Please enter an email address first.');
            return;
        }
        
        $btn.prop('disabled', true).text('Sending...');
        
        $.post(poAdmin.ajaxUrl, {
            action: 'po_send_test_report',
            nonce: poAdmin.nonce,
            email: email
        }, function(response) {
            if (response.success) {
                alert('Test report sent to ' + email);
            } else {
                alert('Failed to send report: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            alert('Request failed. Please try again.');
        }).always(function() {
            $btn.prop('disabled', false).text('Send Test Report');
        });
    });

    // Refresh logs
    $(document).on('click', '#po-refresh-logs', function() {
        var level = $('#po-log-level').val();
        
        $.post(poAdmin.ajaxUrl, {
            action: 'po_get_logs',
            nonce: poAdmin.nonce,
            level: level
        }, function(response) {
            if (response.success) {
                updateLogsTable(response.data);
            }
        });
    });

    // Filter logs by level
    $(document).on('change', '#po-log-level', function() {
        $('#po-refresh-logs').click();
    });

    // Clear logs
    $(document).on('click', '#po-clear-logs', function() {
        if (!confirm('Are you sure you want to clear all logs? This cannot be undone.')) {
            return;
        }
        
        $.post(poAdmin.ajaxUrl, {
            action: 'po_clear_logs',
            nonce: poAdmin.nonce
        }, function(response) {
            if (response.success) {
                $('#po-logs-table tbody').html(
                    '<tr><td colspan="4" style="text-align:center;padding:40px;color:#6b7280;">Logs cleared.</td></tr>'
                );
            }
        });
    });

    // Export logs
    $(document).on('click', '#po-export-logs', function() {
        $.post(poAdmin.ajaxUrl, {
            action: 'po_export_logs',
            nonce: poAdmin.nonce
        }, function(response) {
            if (response.success && response.data.content) {
                var blob = new Blob([response.data.content], { type: 'text/plain' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'payment-orchestrator-logs-' + new Date().toISOString().slice(0, 10) + '.txt';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }
        });
    });

    // Update logs table
    function updateLogsTable(logs) {
        var $tbody = $('#po-logs-table tbody');
        
        if (!logs || logs.length === 0) {
            $tbody.html('<tr><td colspan="4" style="text-align:center;padding:40px;color:#6b7280;">No logs found.</td></tr>');
            return;
        }
        
        var html = '';
        logs.forEach(function(log) {
            var levelClass = 'po-log-level-' + log.level;
            var context = '';
            
            try {
                var ctx = JSON.parse(log.context);
                if (ctx && Object.keys(ctx).length > 0) {
                    context = '<code>' + escapeHtml(JSON.stringify(ctx, null, 2)) + '</code>';
                }
            } catch (e) {}
            
            html += '<tr class="po-log-' + log.level + '">' +
                '<td class="po-log-time">' + escapeHtml(log.created_at) + '</td>' +
                '<td><span class="po-log-level ' + levelClass + '">' + log.level.toUpperCase() + '</span></td>' +
                '<td class="po-log-message">' + escapeHtml(log.message) + '</td>' +
                '<td class="po-log-context">' + context + '</td>' +
                '</tr>';
        });
        
        $tbody.html(html);
    }

    // Escape HTML helper
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Auto-fill display name from gateway selection
    $(document).on('change', 'select[name="gateway_id"]', function() {
        var $option = $(this).find('option:selected');
        var text = $option.text();
        var name = text.split('(')[0].trim();
        
        var $displayName = $('input[name="display_name"]');
        if ($displayName.length && !$displayName.val()) {
            $displayName.val(name);
        }
    });

    // Quick backfill (current month, no rebackfill)
    $(document).on('click', '.po-backfill-btn', function() {
        var $btn = $(this);
        var gatewayId = $btn.data('gateway');
        
        if (!confirm('Backfill current month data for ' + gatewayId + '? This will add any missing orders.')) {
            return;
        }
        
        runBackfill(gatewayId, null, false, $btn);
    });
    
    // Open backfill modal for more options
    $(document).on('click', '.po-backfill-menu-btn', function() {
        var gatewayId = $(this).data('gateway');
        $('#po-backfill-gateway').val(gatewayId);
        $('#po-backfill-month').val($('#po-backfill-month option:first').val());
        $('#po-backfill-rebackfill').prop('checked', false);
        $('#po-backfill-modal').show();
    });
    
    // Close modal
    $(document).on('click', '.po-modal-close, .po-modal-cancel', function() {
        $('#po-backfill-modal').hide();
    });
    
    // Close modal on background click
    $(document).on('click', '.po-modal', function(e) {
        if ($(e.target).hasClass('po-modal')) {
            $('#po-backfill-modal').hide();
        }
    });
    
    // Run backfill from modal
    $(document).on('click', '#po-backfill-confirm', function() {
        var $btn = $(this);
        var gatewayId = $('#po-backfill-gateway').val();
        var month = $('#po-backfill-month').val();
        var rebackfill = $('#po-backfill-rebackfill').is(':checked');
        
        runBackfill(gatewayId, month, rebackfill, $btn);
    });
    
    // Backfill function
    function runBackfill(gatewayId, month, rebackfill, $btn) {
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Processing...');
        
        $.post(poAdmin.ajaxUrl, {
            action: 'po_backfill_volume',
            nonce: poAdmin.nonce,
            gateway_id: gatewayId,
            month: month,
            rebackfill: rebackfill ? 'true' : 'false'
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                $('#po-backfill-modal').hide();
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            alert('Request failed. Please try again.');
        }).always(function() {
            $btn.prop('disabled', false).text(originalText);
        });
    }

    // Rotation Simulator
    $(document).on('click', '#po-run-simulation', function() {
        var $btn = $(this);
        var count = $('#po-sim-count').val();
        var $results = $('#po-sim-results');
        var $inner = $results.find('.po-sim-results-inner');
        
        $btn.prop('disabled', true).text('Simulating...');
        $results.hide();
        
        $.post(poAdmin.ajaxUrl, {
            action: 'po_simulate_rotation',
            nonce: poAdmin.nonce,
            count: count
        }, function(response) {
            if (response.success) {
                var data = response.data;
                var html = '';
                
                // Sort by count descending
                var sorted = Object.values(data.distribution).sort(function(a, b) {
                    return b.count - a.count;
                });
                
                sorted.forEach(function(item) {
                    html += '<div class="po-sim-bar">' +
                        '<span class="po-sim-bar-label">' + item.gateway.displayName + '</span>' +
                        '<div class="po-sim-bar-track">' +
                        '<div class="po-sim-bar-fill" style="width:' + item.percentage + '%"></div>' +
                        '</div>' +
                        '<span class="po-sim-bar-value">' + item.count + ' (' + item.percentage + '%)</span>' +
                        '</div>';
                });
                
                $inner.html(html);
                $results.show();
            } else {
                alert('Error: ' + (response.data || 'Simulation failed'));
            }
        }).fail(function() {
            alert('Request failed. Please try again.');
        }).always(function() {
            $btn.prop('disabled', false).text('Run Simulation');
        });
    });

    // Reset & Sync All
    $(document).on('click', '#po-reset-sync-all', function() {
        if (!confirm('This will DELETE all transaction history and daily stats, then re-sync all managed gateways from WooCommerce orders for the current month.\n\nThis may take several minutes for large stores.\n\nAre you sure you want to continue?')) {
            return;
        }
        
        var $btn = $(this);
        var $status = $('#po-sync-status');
        var $progress = $status.find('.po-sync-progress-bar');
        var $message = $status.find('.po-sync-message');
        
        $btn.prop('disabled', true);
        $status.show();
        $progress.css('width', '20%');
        $message.text('Clearing existing data and syncing... This may take a few minutes.');
        
        $.ajax({
            url: poAdmin.ajaxUrl,
            type: 'POST',
            timeout: 600000, // 10 minute timeout
            data: {
                action: 'po_reset_sync_all',
                nonce: poAdmin.nonce
            },
            success: function(response) {
                $progress.css('width', '100%');
                
                if (response.success) {
                    $message.html('<span style="color:#16a34a;">\u2713 ' + response.data.message + '</span>');
                    
                    // Show details if available
                    if (response.data.details) {
                        var details = '';
                        for (var gw in response.data.details) {
                            var d = response.data.details[gw];
                            details += d.name + ': ' + d.orders + ' orders, $' + parseFloat(d.volume).toFixed(2) + '<br>';
                        }
                        if (details) {
                            $message.append('<div style="margin-top:10px;font-size:12px;color:#6b7280;">' + details + '</div>');
                        }
                    }
                    
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    $message.html('<span style="color:#dc2626;">\u2717 ' + (response.data.message || 'Sync failed') + '</span>');
                    $btn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $progress.css('width', '100%');
                var errorMsg = 'Request failed';
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. For large stores, try using individual gateway backfills instead.';
                } else if (error) {
                    errorMsg = error;
                }
                $message.html('<span style="color:#dc2626;">\u2717 ' + errorMsg + '</span>');
                $btn.prop('disabled', false);
            }
        });
    });

    // Clear Health Data
    $(document).on('click', '#po-clear-health', function() {
        if (!confirm('This will reset health scores for all gateways. They will all show as "healthy" until new transaction data is collected.\n\nContinue?')) {
            return;
        }
        
        var $btn = $(this);
        $btn.prop('disabled', true).text('Clearing...');
        
        $.post(poAdmin.ajaxUrl, {
            action: 'po_clear_health',
            nonce: poAdmin.nonce
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert('Error: ' + (response.data.message || 'Failed to clear health data'));
            }
        }).fail(function() {
            alert('Request failed. Please try again.');
        }).always(function() {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-heart" style="margin-right:5px;"></span>Clear All Health Data');
        });
    });

})(jQuery);
