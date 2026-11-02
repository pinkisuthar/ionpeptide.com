<?php
/**
 * Settings Template
 * 
 * @package PaymentOrchestrator
 * @var array $settings
 */

defined('ABSPATH') || exit;

$timezones = [
    'America/New_York' => 'Eastern Time (ET)',
    'America/Chicago' => 'Central Time (CT)',
    'America/Denver' => 'Mountain Time (MT)',
    'America/Los_Angeles' => 'Pacific Time (PT)',
    'America/Phoenix' => 'Arizona (no DST)',
    'UTC' => 'UTC',
];
?>
<div class="wrap">
    <h1 style="display:none;">Settings</h1>
    
    <div class="po-wrap">
        <div class="po-layout">
            
            <!-- Shared Header + Tab Nav -->
            <?php $currentPage = 'payment-orchestrator-settings'; include PO_PLUGIN_DIR . 'templates/admin/partials/header-nav.php'; ?>
            
            <?php settings_errors('po_messages'); ?>
        
        <form method="post" class="po-settings-form">
            <?php wp_nonce_field('po_settings'); ?>
            
            <!-- Load Balancing -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Load Balancing</h2>
                </div>
                <div class="po-card-body">
                    <div class="po-form-row">
                        <label class="po-label">Maximum Cold Gap</label>
                        <div class="po-input-group">
                            <input type="number" name="max_cold_transactions" value="<?php echo esc_attr($settings['max_cold_transactions']); ?>" class="po-input po-input-sm" min="1" max="500">
                            <span class="po-input-suffix">transactions</span>
                        </div>
                        <p class="po-help">A gateway will be prioritized if it hasn't been used in this many transactions. Under the two-pool routing model this is an edge-case backstop &#x2014; recommended value is 100.</p>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Test Mode</label>
                        <label class="po-toggle">
                            <input type="hidden" name="test_mode" value="no">
                            <input type="checkbox" name="test_mode" value="yes" <?php checked($settings['test_mode'], 'yes'); ?>>
                            <span class="po-toggle-slider"></span>
                            <span class="po-toggle-label">Enable test mode</span>
                        </label>
                        <p class="po-help">When enabled, always shows the highest-priority gateway (disables random distribution). Useful for testing.</p>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Rotation Debug Logging</label>
                        <label class="po-toggle">
                            <input type="hidden" name="rotation_debug" value="no">
                            <input type="checkbox" name="rotation_debug" value="yes" <?php checked($settings['rotation_debug'] ?? 'no', 'yes'); ?>>
                            <span class="po-toggle-slider"></span>
                            <span class="po-toggle-label">Log rotation decisions</span>
                        </label>
                        <p class="po-help">When enabled, logs detailed information about each gateway selection decision. Check the Logs tab to see why each gateway was chosen.</p>
                    </div>
                </div>
            </div>
            
            <!-- Order Tracking -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Order Tracking</h2>
                </div>
                <div class="po-card-body">
                    <div class="po-form-row">
                        <label class="po-label">Successful Order Statuses</label>
                        <p class="po-help" style="margin-bottom:10px;">Select which order statuses count as successful transactions for volume tracking and backfill.</p>
                        <?php 
                        $allStatuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
                        $selectedStatuses = $settings['success_statuses'] ?? ['wc-processing', 'wc-completed'];
                        ?>
                        <div class="po-checkbox-group">
                            <?php foreach ($allStatuses as $status => $label): ?>
                            <label class="po-checkbox">
                                <input type="checkbox" name="success_statuses[]" value="<?php echo esc_attr($status); ?>" <?php checked(in_array($status, $selectedStatuses)); ?>>
                                <span><?php echo esc_html($label); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Failure Alerts -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Failure Alerts</h2>
                </div>
                <div class="po-card-body">
                    <div class="po-form-row">
                        <label class="po-label">Enable Failure Alerts</label>
                        <label class="po-toggle">
                            <input type="hidden" name="failure_alert_enabled" value="no">
                            <input type="checkbox" name="failure_alert_enabled" value="yes" <?php checked($settings['failure_alert_enabled'] ?? 'yes', 'yes'); ?>>
                            <span class="po-toggle-slider"></span>
                            <span class="po-toggle-label">Send email alerts when failures exceed threshold</span>
                        </label>
                        <p class="po-help">Get notified immediately when a gateway experiences multiple consecutive failures.</p>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Failure Threshold</label>
                        <div class="po-input-group">
                            <input type="number" name="failure_alert_threshold" value="<?php echo esc_attr($settings['failure_alert_threshold'] ?? 3); ?>" class="po-input po-input-sm" min="1" max="20">
                            <span class="po-input-suffix">failures</span>
                        </div>
                        <p class="po-help">Send an alert when this many failures occur within the time window.</p>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Time Window</label>
                        <div class="po-input-group">
                            <input type="number" name="failure_alert_window" value="<?php echo esc_attr($settings['failure_alert_window'] ?? 10); ?>" class="po-input po-input-sm" min="1" max="60">
                            <span class="po-input-suffix">minutes</span>
                        </div>
                        <p class="po-help">The time period to count failures. Alert triggers if threshold is reached within this window.</p>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Alert Email</label>
                        <input type="email" name="failure_alert_email" value="<?php echo esc_attr($settings['failure_alert_email'] ?? get_option('admin_email')); ?>" class="po-input">
                        <p class="po-help">Email address to receive failure alerts.</p>
                    </div>
                </div>
            </div>
            
            <!-- Daily Reports -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">High-Risk Retry</h2>
                </div>
                <div class="po-card-body">
                    <div class="po-form-row">
                        <label class="po-label">Bank Decline Retry Mode (201, 253)</label>
                        
                        <div style="display:flex; flex-direction:column; gap:12px; margin-top:8px;">
                            <label style="display:flex; align-items:flex-start; gap:10px; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer;">
                                <input type="radio" name="high_risk_retry_mode" value="off" <?php checked($settings['high_risk_retry_mode'] ?? 'off', 'off'); ?> style="margin-top:3px;">
                                <div>
                                    <strong style="color:#1e293b;">Off</strong>
                                    <p style="margin:4px 0 0; font-size:13px; color:#64748b;">201 and 253 declines are treated as non-retryable (standard behavior).</p>
                                </div>
                            </label>
                            
                            <label style="display:flex; align-items:flex-start; gap:10px; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer;">
                                <input type="radio" name="high_risk_retry_mode" value="auto" <?php checked($settings['high_risk_retry_mode'] ?? 'off', 'auto'); ?> style="margin-top:3px;">
                                <div>
                                    <strong style="color:#1e293b;">Retry on any available gateway</strong>
                                    <p style="margin:4px 0 0; font-size:13px; color:#64748b;">Bank declines are retried on the next healthy gateway using standard rotation logic.</p>
                                </div>
                            </label>
                            
                            <label style="display:flex; align-items:flex-start; gap:10px; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer;">
                                <input type="radio" name="high_risk_retry_mode" value="specific" <?php checked($settings['high_risk_retry_mode'] ?? 'off', 'specific'); ?> style="margin-top:3px;">
                                <div>
                                    <strong style="color:#1e293b;">Retry on a specific gateway</strong>
                                    <p style="margin:4px 0 0; font-size:13px; color:#64748b;">Bank declines are always retried on one chosen gateway (your highest performer for these codes).</p>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <div class="po-form-row" id="po-high-risk-gateway-row" style="margin-top:16px; <?php echo ($settings['high_risk_retry_mode'] ?? 'off') !== 'specific' ? 'display:none;' : ''; ?>">
                        <label class="po-label">Retry Gateway</label>
                        <select name="high_risk_retry_gateway" class="po-input" style="max-width:400px;">
                            <option value="">&#x2014; Select a gateway &#x2014;</option>
                            <?php 
                            $allGateways = $this->container->get(\PaymentOrchestrator\Gateway\GatewayRepository::class)->getAll();
                            foreach ($allGateways as $gw): 
                                if ($gw->isAlwaysOn) continue;
                            ?>
                                <option value="<?php echo esc_attr($gw->id); ?>" <?php selected($settings['high_risk_retry_gateway'] ?? '', $gw->id); ?>>
                                    <?php echo esc_html($gw->displayName); ?> (<?php echo esc_html($gw->id); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="po-help">All 201 and 253 bank declines will be retried on this gateway. Choose your most reliable gateway for these transaction types.</p>
                    </div>
                    
                    <p class="po-help" style="margin-top:12px;">
                        For high-risk merchants. "Do Not Honor" (201) and "Pick Up Card - Suspected Fraud" (253) 
                        are often bank-side decisions based on the gateway's MID risk profile. The customer sees: 
                        <em>"Payment failed to process. You can try this card again or use a different card to complete your order."</em>
                    </p>
                </div>
            </div>
            
            <script>
            (function() {
                var radios = document.querySelectorAll('input[name="high_risk_retry_mode"]');
                var gatewayRow = document.getElementById('po-high-risk-gateway-row');
                radios.forEach(function(radio) {
                    radio.addEventListener('change', function() {
                        gatewayRow.style.display = this.value === 'specific' ? '' : 'none';
                    });
                });
            })();
            </script>
            
            <!-- Daily Email Reports -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Daily Reports</h2>
                </div>
                <div class="po-card-body">
                    <div class="po-form-row">
                        <label class="po-label">Enable Daily Report</label>
                        <label class="po-toggle">
                            <input type="hidden" name="daily_report_enabled" value="no">
                            <input type="checkbox" name="daily_report_enabled" value="yes" <?php checked($settings['daily_report_enabled'], 'yes'); ?>>
                            <span class="po-toggle-slider"></span>
                            <span class="po-toggle-label">Send daily performance report</span>
                        </label>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Report Time</label>
                        <div class="po-input-group">
                            <select name="daily_report_time" class="po-select">
                                <?php 
                                for ($h = 0; $h < 24; $h++) {
                                    $time = sprintf('%02d:00', $h);
                                    $display = date('g:i A', strtotime($time));
                                    printf(
                                        '<option value="%s" %s>%s</option>',
                                        esc_attr($time),
                                        selected($settings['daily_report_time'], $time, false),
                                        esc_html($display)
                                    );
                                }
                                ?>
                            </select>
                            <select name="daily_report_timezone" class="po-select">
                                <?php foreach ($timezones as $tz => $label): ?>
                                    <option value="<?php echo esc_attr($tz); ?>" <?php selected($settings['daily_report_timezone'], $tz); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Email Recipients</label>
                        <input type="email" name="daily_report_email" value="<?php echo esc_attr($settings['daily_report_email']); ?>" class="po-input">
                        <p class="po-help">Email address to receive daily reports.</p>
                    </div>
                    
                    <div class="po-form-row">
                        <button type="button" id="po-test-report" class="po-btn po-btn-secondary">Send Test Report</button>
                    </div>
                </div>
            </div>
            
            <!-- Logging -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Logging</h2>
                </div>
                <div class="po-card-body">
                    <div class="po-form-row">
                        <label class="po-label">Enable Logging</label>
                        <label class="po-toggle">
                            <input type="hidden" name="enable_logging" value="no">
                            <input type="checkbox" name="enable_logging" value="yes" <?php checked($settings['enable_logging'], 'yes'); ?>>
                            <span class="po-toggle-slider"></span>
                            <span class="po-toggle-label">Log plugin activity</span>
                        </label>
                        <p class="po-help">Logs are stored securely in the database and can be viewed in the Logs tab.</p>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Minimum Log Level</label>
                        <select name="log_level" class="po-select">
                            <option value="debug" <?php selected($settings['log_level'], 'debug'); ?>>Debug (verbose)</option>
                            <option value="info" <?php selected($settings['log_level'], 'info'); ?>>Info (recommended)</option>
                            <option value="warning" <?php selected($settings['log_level'], 'warning'); ?>>Warning</option>
                            <option value="error" <?php selected($settings['log_level'], 'error'); ?>>Error only</option>
                        </select>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Log Retention (days)</label>
                        <input type="number" name="log_retention_days" class="po-input" style="width:80px;" 
                            value="<?php echo esc_attr(get_option('po_log_retention_days', '14')); ?>" min="1" max="90">
                        <p class="po-help">DEBUG logs are kept for this many days. INFO/WARNING/ERROR logs are kept for double this period.</p>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Max Log Entries</label>
                        <select name="max_log_entries" class="po-select" style="width:140px;">
                            <?php $maxEntries = (int) get_option('po_max_log_entries', '5000'); ?>
                            <option value="2000" <?php selected($maxEntries, 2000); ?>>2,000</option>
                            <option value="5000" <?php selected($maxEntries, 5000); ?>>5,000 (default)</option>
                            <option value="10000" <?php selected($maxEntries, 10000); ?>>10,000</option>
                            <option value="25000" <?php selected($maxEntries, 25000); ?>>25,000</option>
                        </select>
                        <p class="po-help">When the limit is reached, oldest DEBUG entries are trimmed first. WARNING and ERROR logs are never deleted to make room.</p>
                    </div>
                </div>
            </div>
            
            <!-- Data -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Data</h2>
                </div>
                <div class="po-card-body">
                    <div class="po-form-row">
                        <label class="po-label">Uninstall Behavior</label>
                        <label class="po-toggle">
                            <input type="hidden" name="delete_data_on_uninstall" value="no">
                            <input type="checkbox" name="delete_data_on_uninstall" value="yes" <?php checked($settings['delete_data_on_uninstall'], 'yes'); ?>>
                            <span class="po-toggle-slider"></span>
                            <span class="po-toggle-label">Delete all data when plugin is uninstalled</span>
                        </label>
                        <p class="po-help po-help-warning">&#x26A0;&#xFE0F; If enabled, all gateway configurations, transaction history, and settings will be permanently deleted when you uninstall the plugin.</p>
                    </div>
                </div>
            </div>
            
            <!-- Data Sync -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Data Synchronization</h2>
                </div>
                <div class="po-card-body">
                    <div class="po-form-row">
                        <label class="po-label">Reset & Sync All Gateways</label>
                        <p class="po-help" style="margin-bottom:15px;">
                            If your dashboard numbers don't match WooCommerce orders, use this to clear all tracking data and re-sync from WooCommerce.
                            This will clear transaction history and daily stats, then backfill all managed gateways for the current month.
                        </p>
                        <button type="button" id="po-reset-sync-all" class="po-btn po-btn-warning">
                            <span class="dashicons dashicons-update" style="margin-right:5px;"></span>
                            Reset & Sync All Gateways
                        </button>
                        <div id="po-sync-status" style="margin-top:15px; display:none;">
                            <div class="po-sync-progress">
                                <div class="po-sync-progress-bar" style="width:0%;"></div>
                            </div>
                            <p class="po-sync-message">Initializing...</p>
                        </div>
                    </div>
                    
                    <div class="po-form-row" style="margin-top:20px; padding-top:20px; border-top:1px solid #e5e7eb;">
                        <label class="po-label">Clear Health Data</label>
                        <p class="po-help" style="margin-bottom:15px;">
                            Reset health scores for all gateways. Use this if a gateway is stuck in "critical" status due to old failure data.
                        </p>
                        <button type="button" id="po-clear-health" class="po-btn po-btn-secondary">
                            <span class="dashicons dashicons-heart" style="margin-right:5px;"></span>
                            Clear All Health Data
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Emergency: Bypass Mode -->
            <div class="po-card" style="border: 2px solid <?php echo ($settings['bypass_enabled'] ?? 'no') === 'yes' ? '#ef4444' : '#e2e8f0'; ?>;">
                <div class="po-card-header" style="background:<?php echo ($settings['bypass_enabled'] ?? 'no') === 'yes' ? '#fef2f2' : '#fff'; ?>;">
                    <h2 class="po-card-title" style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:20px;">&#x1F6A8;</span>
                        Emergency Bypass
                        <?php if (($settings['bypass_enabled'] ?? 'no') === 'yes'): ?>
                            <span style="display:inline-block;padding:2px 10px;background:#fee2e2;color:#dc2626;border-radius:10px;font-size:11px;font-weight:700;margin-left:8px;">ACTIVE</span>
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="po-card-body">
                    <div class="po-form-row">
                        <label class="po-label">Manual Bypass</label>
                        <label class="po-toggle">
                            <input type="hidden" name="bypass_enabled" value="no">
                            <input type="checkbox" name="bypass_enabled" value="yes" <?php checked($settings['bypass_enabled'] ?? 'no', 'yes'); ?>>
                            <span class="po-toggle-slider"></span>
                            <span class="po-toggle-label">Disable ALL rotation logic and expose all gateways</span>
                        </label>
                        <p class="po-help">
                            Emergency switch. When enabled, Payment Orchestrator stops filtering gateways entirely &mdash; 
                            all WooCommerce payment methods are shown to every customer as-is. Use this if gateways are 
                            failing and you need to restore normal checkout immediately. Volume tracking and failure 
                            recording continue to work. <strong>Turn this off once the issue is resolved.</strong>
                        </p>
                    </div>
                    <div class="po-form-row" style="margin-top:16px; padding-top:16px; border-top:1px solid #e2e8f0;">
                        <label class="po-label">Auto-Bypass</label>
                        <label class="po-toggle">
                            <input type="hidden" name="auto_bypass_enabled" value="no">
                            <input type="checkbox" name="auto_bypass_enabled" value="yes" <?php checked($settings['auto_bypass_enabled'] ?? 'no', 'yes'); ?>>
                            <span class="po-toggle-slider"></span>
                            <span class="po-toggle-label">Automatically bypass when all gateways are failing</span>
                        </label>
                        <p class="po-help" style="margin-top:8px;">
                            When enabled, the system monitors all managed gateways during checkout. If every gateway exceeds a 90% 
                            failure rate within a 15-minute window, rotation is automatically bypassed and all WooCommerce gateways 
                            are exposed. An <strong>urgent email alert</strong> is sent to your failure alert email address 
                            (rate-limited to once per 30 minutes). Normal rotation resumes automatically once gateways start succeeding again.
                        </p>
                        <p class="po-help">
                            <strong>Built-in safeguards:</strong> Auto-bypass only triggers when at least 3 transactions per gateway 
                            have been recorded in the window <em>and</em> the failures come from at least 3 unique customer accounts. 
                            This means a single customer retrying a bad card cannot take down the orchestration logic.
                        </p>
                        <p class="po-help">
                            <em>Also triggers if all managed gateways become disabled simultaneously.</em>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- REST API -->
            <div class="po-card" style="margin-bottom:24px;">
                <div class="po-card-header">
                    <h2 class="po-card-title" style="display:flex; align-items:center; gap:8px;">
                        <span class="dashicons dashicons-rest-api"></span>
                        REST API
                    </h2>
                </div>
                <div class="po-card-body">
                    <div class="po-form-row" style="margin-bottom:16px;">
                        <label class="po-label">Enable API</label>
                        <label class="po-toggle">
                            <input type="hidden" name="api_enabled" value="no">
                            <input type="checkbox" name="api_enabled" value="yes" <?php checked($settings['api_enabled'] ?? 'no', 'yes'); ?>>
                            <span class="po-toggle-slider"></span>
                            <span class="po-toggle-label">Allow external applications to read POP data via REST API</span>
                        </label>
                    </div>
                    
                    <div class="po-form-row" style="margin-bottom:16px;">
                        <label class="po-label">API Key</label>
                        <?php if (!empty($settings['api_key_prefix'])): ?>
                            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                <code style="background:#f1f5f9; padding:6px 12px; border-radius:6px; font-size:13px; color:#334155;">
                                    <?php echo esc_html($settings['api_key_prefix']); ?>
                                </code>
                                <span style="font-size:12px; color:#64748b;">Generated: <?php echo esc_html($settings['api_key_created']); ?></span>
                                <button type="button" id="po-revoke-key-btn" class="po-btn" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; font-size:12px; padding:4px 12px;">
                                    Revoke Key
                                </button>
                            </div>
                        <?php else: ?>
                            <div style="display:flex; align-items:center; gap:12px;">
                                <span style="font-size:13px; color:#94a3b8;">No API key configured.</span>
                            </div>
                        <?php endif; ?>
                        <div style="margin-top:8px;">
                            <button type="button" id="po-generate-key-btn" class="po-btn po-btn-sm" style="background:#1e293b; color:#fff;">
                                <?php echo !empty($settings['api_key_prefix']) ? 'Regenerate Key' : 'Generate Key'; ?>
                            </button>
                        </div>
                        <div id="po-api-key-display" style="display:none; margin-top:12px; padding:16px; background:#fef3c7; border:1px solid #fbbf24; border-radius:8px;">
                            <div style="font-weight:700; color:#92400e; margin-bottom:8px;">&#x26A0;&#xFE0F; Copy this key now &#x2014; it will NOT be shown again!</div>
                            <code id="po-api-key-value" style="display:block; background:#fff; padding:10px 14px; border-radius:6px; font-size:14px; word-break:break-all; color:#1e293b; border:1px solid #e2e8f0;"></code>
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('po-api-key-value').textContent).then(function(){alert('Copied!')})" 
                                    class="po-btn po-btn-sm" style="margin-top:8px; background:#f1f5f9; color:#334155;">
                                Copy to Clipboard
                            </button>
                        </div>
                    </div>
                    
                    <div class="po-form-row" style="margin-bottom:16px;">
                        <label class="po-label">IP Allowlist <span style="font-size:12px; color:#94a3b8; font-weight:400;">(optional)</span></label>
                        <input type="text" name="api_ip_allowlist" value="<?php echo esc_attr($settings['api_ip_allowlist'] ?? ''); ?>" 
                               class="po-input" style="max-width:500px;" placeholder="e.g. 203.0.113.50, 198.51.100.25">
                        <p class="po-help">Comma-separated IP addresses. If empty, any IP with a valid key can access the API. Recommended: add your Loveable app server IP.</p>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-label">Require HTTPS</label>
                        <label class="po-toggle">
                            <input type="hidden" name="api_require_ssl" value="no">
                            <input type="checkbox" name="api_require_ssl" value="yes" <?php checked($settings['api_require_ssl'] ?? 'yes', 'yes'); ?>>
                            <span class="po-toggle-slider"></span>
                            <span class="po-toggle-label">Reject API requests over non-encrypted connections</span>
                        </label>
                        <p class="po-help">Strongly recommended. Only disable for local development.</p>
                    </div>
                    
                    <div style="margin-top:16px; padding:12px 16px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                        <div style="font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px;">Base URL</div>
                        <code style="font-size:13px; color:#334155;"><?php echo esc_html(rest_url('po/v1/')); ?></code>
                        <div style="font-size:11px; color:#94a3b8; margin-top:4px;">See the <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-help#api-docs'); ?>">Help &#x2192; API Documentation</a> for endpoints and usage.</div>
                    </div>
                </div>
            </div>
            
            <script>
            (function(){
                var nonce = '<?php echo wp_create_nonce('po_admin_nonce'); ?>';
                var genBtn = document.getElementById('po-generate-key-btn');
                var revokeBtn = document.getElementById('po-revoke-key-btn');
                var keyDisplay = document.getElementById('po-api-key-display');
                var keyValue = document.getElementById('po-api-key-value');
                
                if (genBtn) {
                    genBtn.addEventListener('click', function() {
                        if (!confirm('This will generate a new API key and invalidate the existing one. Continue?')) return;
                        genBtn.disabled = true;
                        genBtn.textContent = 'Generating...';
                        
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'action=po_generate_api_key&nonce=' + nonce
                        })
                        .then(function(r){ return r.json(); })
                        .then(function(resp) {
                            if (resp.success) {
                                keyValue.textContent = resp.data.key;
                                keyDisplay.style.display = '';
                                genBtn.textContent = 'Regenerate Key';
                                genBtn.disabled = false;
                            } else {
                                alert('Error: ' + (resp.data || 'Unknown error'));
                                genBtn.textContent = 'Generate Key';
                                genBtn.disabled = false;
                            }
                        })
                        .catch(function() {
                            alert('Network error');
                            genBtn.disabled = false;
                        });
                    });
                }
                
                if (revokeBtn) {
                    revokeBtn.addEventListener('click', function() {
                        if (!confirm('Revoke the API key? External apps will immediately lose access.')) return;
                        revokeBtn.disabled = true;
                        
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'action=po_revoke_api_key&nonce=' + nonce
                        })
                        .then(function(r){ return r.json(); })
                        .then(function(resp) {
                            if (resp.success) {
                                location.reload();
                            } else {
                                alert('Error: ' + (resp.data || 'Unknown error'));
                                revokeBtn.disabled = false;
                            }
                        });
                    });
                }
            })();
            </script>
            
            <!-- Appearance (2.7.27) -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Appearance</h2>
                </div>
                <div class="po-card-body">
                    <p class="po-help" style="margin-top: 0;">Choose how Payment Orchestrator looks for you. This only changes colours and fonts on POP's screens &#x2014; layouts, numbers and other users are not affected. Default is POP's standard look.</p>
                    <?php $poCurrentTheme = \PaymentOrchestrator\Admin\Theme::current(); ?>
                    <fieldset style="border: 0; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 12px;">
                        <legend class="screen-reader-text">Theme</legend>
                        <?php foreach (\PaymentOrchestrator\Admin\Theme::THEMES as $poThemeId => $poThemeLabel): ?>
                            <label style="display: inline-flex; align-items: center; gap: 10px; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; min-width: 180px;">
                                <input type="radio" name="po_admin_theme" value="<?php echo esc_attr($poThemeId); ?>" <?php checked($poCurrentTheme, $poThemeId); ?>>
                                <span style="display: inline-flex; gap: 4px;" aria-hidden="true">
                                    <?php foreach (array_slice(\PaymentOrchestrator\Admin\Theme::swatches($poThemeId), 0, 3) as $poSwatch): ?>
                                        <span style="width: 14px; height: 14px; border-radius: 4px; border: 1px solid rgba(0,0,0,0.15); display: inline-block; --po-swatch: <?php echo esc_attr($poSwatch); ?>; background-color: var(--po-swatch);"></span>
                                    <?php endforeach; ?>
                                </span>
                                <span style="font-weight: 600;"><?php echo esc_html($poThemeLabel); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                </div>
            </div>
            
            <!-- Save Button -->
            <div class="po-form-actions">
                <button type="submit" name="po_save_settings" class="po-btn po-btn-primary po-btn-lg">Save Settings</button>
            </div>
        </form>
        
        </div>
    </div>
</div>
