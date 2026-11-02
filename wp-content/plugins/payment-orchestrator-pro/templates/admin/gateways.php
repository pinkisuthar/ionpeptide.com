<?php
/**
 * Gateways Template
 * 
 * @package PaymentOrchestrator
 * @var array $gateways
 * @var array $wcGateways
 * @var Gateway|null $editGateway
 */

defined('ABSPATH') || exit;

use PaymentOrchestrator\Gateway\Gateway;
?>
<div class="wrap">
    <h1 style="display:none;">Gateways</h1>
    
    <div class="po-wrap">
        <div class="po-layout">
            
            <!-- Shared Header + Tab Nav -->
            <?php $currentPage = 'payment-orchestrator-gateways'; include PO_PLUGIN_DIR . 'templates/admin/partials/header-nav.php'; ?>
            
            <!-- Orchestrator Master Toggle -->
            <?php $orchestratorOn = get_option('po_orchestrator_enabled', 'yes') === 'yes'; ?>
            <div class="po-card" style="margin-bottom: 20px; border: 2px solid <?php echo $orchestratorOn ? '#bbf7d0' : '#fecaca'; ?>;">
                <div class="po-card-body" style="padding: 16px 20px; display: flex; align-items: flex-start; justify-content: space-between; gap: 20px;">
                    <div style="display: flex; gap: 12px; align-items: flex-start;">
                        <?php if ($orchestratorOn): ?>
                            <div style="flex-shrink: 0; width: 36px; height: 36px; border-radius: 8px; background: #dcfce7; display: flex; align-items: center; justify-content: center;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            </div>
                        <?php else: ?>
                            <div style="flex-shrink: 0; width: 36px; height: 36px; border-radius: 8px; background: #fef2f2; display: flex; align-items: center; justify-content: center;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </div>
                        <?php endif; ?>
                        <div>
                            <strong style="font-size: 14px; color: #1e293b;">Gateway Orchestration</strong>
                            <?php if ($orchestratorOn): ?>
                                <p style="margin: 4px 0 0; font-size: 13px; color: #16a34a; font-weight: 600;">
                                    Routing is active &#x2014; gateways are being rotated based on health, pacing, and capacity.
                                </p>
                            <?php else: ?>
                                <p style="margin: 4px 0 0; font-size: 13px; color: #dc2626; font-weight: 600;">
                                    Routing is paused &#x2014; WooCommerce default gateway order is being used.
                                </p>
                                <p style="margin: 6px 0 0; font-size: 12px; color: #64748b; line-height: 1.5;">
                                    While paused: transaction tracking, failure classification, recovery, health monitoring, and reporting all <strong>continue running</strong> normally.
                                    Only the checkout gateway selection logic is disabled.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="button" id="po-master-toggle"
                        style="flex-shrink: 0; position: relative; width: 52px; height: 28px; border-radius: 14px; border: none; cursor: pointer; transition: background 0.2s;
                               background: <?php echo $orchestratorOn ? '#22c55e' : '#cbd5e1'; ?>; padding: 0; margin-top: 4px;"
                        data-enabled="<?php echo $orchestratorOn ? 'yes' : 'no'; ?>">
                        <span style="position: absolute; top: 2px; <?php echo $orchestratorOn ? 'left: 26px' : 'left: 2px'; ?>; width: 24px; height: 24px; background: #fff; border-radius: 50%; transition: left 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);"></span>
                    </button>
                </div>
            </div>
            <script>
            jQuery(function($) {
                $('#po-master-toggle').on('click', function() {
                    var btn = $(this);
                    var currentlyOn = btn.data('enabled') === 'yes';
                    var newState = currentlyOn ? 'no' : 'yes';
                    
                    if (currentlyOn && !confirm('&#x26A0;&#xFE0F; PAUSE GATEWAY ORCHESTRATION?\n\nThis will stop all smart gateway routing at checkout. WooCommerce will use its default gateway order instead.\n\nTracking, recovery, health monitoring, and reporting will continue running normally.\n\nAre you sure?')) {
                        return;
                    }
                    
                    $.post(ajaxurl, {
                        action: 'po_toggle_orchestrator',
                        nonce: '<?php echo wp_create_nonce("po_admin_nonce"); ?>',
                        enabled: newState
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        }
                    });
                });
            });
            </script>
            
            <?php settings_errors('po_messages'); ?>
        
        <?php if ($editGateway): ?>
            <!-- Edit Gateway Form -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Edit Gateway: <?php echo esc_html($editGateway->displayName); ?></h2>
                    <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-gateways'); ?>" class="po-btn po-btn-sm">&#x2190; Back</a>
                </div>
                <div class="po-card-body">
                    <form method="post" class="po-form">
                        <?php wp_nonce_field('po_update_gateway'); ?>
                        <input type="hidden" name="gateway_id" value="<?php echo esc_attr($editGateway->id); ?>">
                        
                        <div class="po-form-row">
                            <label class="po-label">Display Name</label>
                            <input type="text" name="display_name" value="<?php echo esc_attr($editGateway->displayName); ?>" class="po-input" required>
                        </div>
                        
                        <div class="po-form-row">
                            <label class="po-label">Monthly Target ($)</label>
                            <input type="number" name="monthly_target" value="<?php echo esc_attr($editGateway->monthlyTarget); ?>" class="po-input" min="0" step="0.01">
                            <p class="po-help">Set to 0 for unlimited. Gateway will be hidden when target is reached.</p>
                        </div>
                        
                        <div class="po-form-row">
                            <label class="po-label">Max Transaction Amount ($)</label>
                            <input type="number" name="max_transaction_amount" value="<?php echo esc_attr($editGateway->maxTransactionAmount); ?>" class="po-input po-input-sm" min="0" step="0.01">
                            <p class="po-help">Set to 0 for no limit. Orders above this amount will route to another gateway.</p>
                        </div>
                        
                        <div class="po-form-row">
                            <label class="po-label">Priority</label>
                            <input type="number" name="priority" value="<?php echo esc_attr($editGateway->priority); ?>" class="po-input po-input-sm" min="1" max="100">
                            <p class="po-help">Lower number = higher priority when scores are equal</p>
                        </div>
                        
                        <div class="po-form-row">
                            <label class="po-label">Gateway Type</label>
                            <select name="gateway_type" class="po-select" id="po-edit-gw-type" style="max-width: 250px;">
                                <option value="card" <?php selected($editGateway->gatewayType, 'card'); ?>>Card Payment</option>
                                <option value="alternative" <?php selected($editGateway->gatewayType, 'alternative'); ?>>Alternative (Zelle, P2P, ACH, etc.)</option>
                            </select>
                            <p class="po-help">Card gateways route by card type. Alternative gateways skip card-type routing.</p>
                        </div>
                        
                        <div class="po-form-row po-card-types-section" id="po-edit-card-types" style="<?php echo $editGateway->gatewayType !== 'card' ? 'display:none;' : ''; ?>">
                            <label class="po-label">Accepted Card Types</label>
                            <div class="po-checkbox-group">
                                <label class="po-checkbox">
                                    <input type="checkbox" name="accepts_visa" value="1" <?php checked($editGateway->acceptsVisa); ?>>
                                    <span>Visa</span>
                                </label>
                                <label class="po-checkbox">
                                    <input type="checkbox" name="accepts_mastercard" value="1" <?php checked($editGateway->acceptsMastercard); ?>>
                                    <span>Mastercard</span>
                                </label>
                                <label class="po-checkbox">
                                    <input type="checkbox" name="accepts_amex" value="1" <?php checked($editGateway->acceptsAmex); ?>>
                                    <span>American Express</span>
                                </label>
                                <label class="po-checkbox">
                                    <input type="checkbox" name="accepts_discover" value="1" <?php checked($editGateway->acceptsDiscover); ?>>
                                    <span>Discover</span>
                                </label>
                            </div>
                        </div>
                        
                        <script>
                        jQuery(function($) {
                            $('#po-edit-gw-type').on('change', function() {
                                $('#po-edit-card-types').toggle($(this).val() === 'card');
                            });
                        });
                        </script>
                        
                        <div class="po-form-row">
                            <label class="po-label">Options</label>
                            <div class="po-checkbox-group">
                                <label class="po-checkbox">
                                    <input type="checkbox" name="is_enabled" value="1" <?php checked($editGateway->isEnabled); ?>>
                                    <span>Enabled</span>
                                </label>
                                <label class="po-checkbox">
                                    <input type="checkbox" name="is_always_on" value="1" <?php checked($editGateway->isAlwaysOn); ?>>
                                    <span>Always Show (exclude from rotation)</span>
                                </label>
                            </div>
                            <p class="po-help">Use "Always Show" for PayPal, ACH, Zelle, etc.</p>
                        </div>
                        
                        <div class="po-form-actions">
                            <button type="submit" name="po_update_gateway" class="po-btn po-btn-primary">Save Changes</button>
                            <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-gateways'); ?>" class="po-btn">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Add Gateway Form -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Add Gateway</h2>
                </div>
                <div class="po-card-body">
                    <?php if (empty($wcGateways)): ?>
                        <div class="po-notice po-notice-warning">
                            No enabled payment gateways found in WooCommerce. Please enable at least one payment gateway first.
                        </div>
                    <?php else: ?>
                        <form method="post" class="po-form po-form-inline">
                            <?php wp_nonce_field('po_add_gateway'); ?>
                            
                            <div class="po-form-row">
                                <label class="po-label">Select Gateway</label>
                                <select name="gateway_id" class="po-select" required>
                                    <option value="">Choose a gateway...</option>
                                    <?php 
                                    $existingIds = array_map(fn($g) => $g->id, $gateways);
                                    foreach ($wcGateways as $id => $title): 
                                        if (!in_array($id, $existingIds)):
                                    ?>
                                        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($title); ?> (<?php echo esc_html($id); ?>)</option>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </select>
                            </div>
                            
                            <div class="po-form-row">
                                <label class="po-label">Display Name</label>
                                <input type="text" name="display_name" class="po-input" placeholder="e.g., Credit Card (Visa, MC)" required>
                            </div>
                            
                            <div class="po-form-row">
                                <label class="po-label">Monthly Target ($)</label>
                                <input type="number" name="monthly_target" class="po-input po-input-sm" value="0" min="0" step="0.01">
                            </div>
                            
                            <div class="po-form-row">
                                <label class="po-label">Max Transaction Amount ($)</label>
                                <input type="number" name="max_transaction_amount" class="po-input po-input-sm" value="0" min="0" step="0.01">
                                <p class="po-help">Set to 0 for no limit.</p>
                            </div>
                            
                            <div class="po-form-row">
                                <div class="po-checkbox-group po-checkbox-inline">
                                    <label class="po-checkbox">
                                        <input type="checkbox" name="accepts_visa" value="1" checked>
                                        <span>Visa</span>
                                    </label>
                                    <label class="po-checkbox">
                                        <input type="checkbox" name="accepts_mastercard" value="1" checked>
                                        <span>MC</span>
                                    </label>
                                    <label class="po-checkbox">
                                        <input type="checkbox" name="accepts_amex" value="1" checked>
                                        <span>Amex</span>
                                    </label>
                                    <label class="po-checkbox">
                                        <input type="checkbox" name="accepts_discover" value="1" checked>
                                        <span>Discover</span>
                                    </label>
                                    <label class="po-checkbox">
                                        <input type="checkbox" name="is_always_on" value="1">
                                        <span>Always On</span>
                                    </label>
                                </div>
                                <input type="hidden" name="gateway_type" value="card">
                            </div>
                            
                            <button type="submit" name="po_add_gateway" class="po-btn po-btn-primary">Add Gateway</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Gateways List -->
            <div class="po-card">
                <div class="po-card-header">
                    <h2 class="po-card-title">Configured Gateways</h2>
                </div>
                <div class="po-card-body">
                    <?php if (empty($gateways)): ?>
                        <div class="po-empty">
                            <p>No gateways configured yet. Add one above to get started.</p>
                        </div>
                    <?php else: ?>
                        <table class="po-table">
                            <thead>
                                <tr>
                                    <th>Gateway</th>
                                    <th>Monthly Target</th>
                                    <th>Cards</th>
                                    <th>Type</th>
                                    <th>Rotation</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gateways as $gateway): 
                                    $gStat = $gatewayStats[$gateway->id] ?? null;
                                    $monthVol = $gStat ? $gStat['monthly_volume'] : 0;
                                    $monthPct = ($gateway->monthlyTarget > 0) ? round(($monthVol / $gateway->monthlyTarget) * 100, 1) : 0;
                                    $paceStatus = $gStat['pace_status'] ?? 'on_track';
                                    $paceColor = match($paceStatus) {
                                        'behind' => '#dc2626',
                                        'ahead' => '#2563eb',
                                        'complete' => '#16a34a',
                                        default => '#64748b',
                                    };
                                ?>
                                <tr style="<?php echo !$gateway->isEnabled ? 'opacity: 0.55;' : ''; ?>">
                                    <td>
                                        <div class="po-gateway-name"><?php echo esc_html($gateway->displayName); ?></div>
                                        <div class="po-gateway-id"><?php echo esc_html($gateway->id); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($gateway->monthlyTarget > 0): ?>
                                            <div style="font-weight: 600;">$<?php echo number_format($gateway->monthlyTarget, 0); ?></div>
                                            <div style="font-size: 12px; color: <?php echo $paceColor; ?>; margin-top: 2px;">
                                                $<?php echo number_format($monthVol, 0); ?> done (<?php echo $monthPct; ?>%)
                                            </div>
                                            <div style="background: #e2e8f0; border-radius: 3px; height: 4px; width: 80px; margin-top: 4px; overflow: hidden;">
                                                <div style="background: <?php echo $paceColor; ?>; height: 100%; width: <?php echo min($monthPct, 100); ?>%; border-radius: 3px;"></div>
                                            </div>
                                        <?php else: ?>
                                            <div><span class="po-muted">Unlimited</span></div>
                                            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">$<?php echo number_format($monthVol, 0); ?> this month</div>
                                        <?php endif; ?>
                                        <?php if ($gateway->maxTransactionAmount > 0): ?>
                                            <div style="font-size: 11px; color: #94a3b8; margin-top: 3px;">Max txn: $<?php echo number_format($gateway->maxTransactionAmount, 0); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($gateway->isCardGateway()): ?>
                                            <?php if ($gateway->acceptsVisa): ?>
                                                <span class="po-badge po-badge-gray">Visa</span>
                                            <?php endif; ?>
                                            <?php if ($gateway->acceptsMastercard): ?>
                                                <span class="po-badge po-badge-gray">MC</span>
                                            <?php endif; ?>
                                            <?php if ($gateway->acceptsAmex): ?>
                                                <span class="po-badge po-badge-blue">Amex</span>
                                            <?php endif; ?>
                                            <?php if ($gateway->acceptsDiscover): ?>
                                                <span class="po-badge po-badge-orange">Disc</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="po-badge po-badge-gray">Alternative</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($gateway->isAlwaysOn): ?>
                                            <span class="po-badge po-badge-purple">Always On</span>
                                        <?php else: ?>
                                            <span class="po-badge po-badge-green">Managed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="po-gw-toggle"
                                            data-gateway="<?php echo esc_attr($gateway->id); ?>"
                                            data-enabled="<?php echo $gateway->isEnabled ? '1' : '0'; ?>"
                                            style="position: relative; width: 40px; height: 22px; border-radius: 11px; border: none; cursor: pointer; transition: background 0.2s; padding: 0;
                                                   background: <?php echo $gateway->isEnabled ? '#22c55e' : '#cbd5e1'; ?>;">
                                            <span style="position: absolute; top: 2px; <?php echo $gateway->isEnabled ? 'left: 20px' : 'left: 2px'; ?>; width: 18px; height: 18px; background: #fff; border-radius: 50%; transition: left 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.2);"></span>
                                        </button>
                                    </td>
                                    <td>
                                        <div class="po-actions-inline">
                                            <a href="<?php echo admin_url('admin.php?page=payment-orchestrator-gateways&edit=' . urlencode($gateway->id)); ?>" class="po-btn po-btn-sm">Edit</a>
                                            <div class="po-backfill-group">
                                                <button type="button" class="po-btn po-btn-sm po-btn-secondary po-backfill-btn" data-gateway="<?php echo esc_attr($gateway->id); ?>">Backfill</button>
                                                <button type="button" class="po-btn po-btn-sm po-backfill-menu-btn" data-gateway="<?php echo esc_attr($gateway->id); ?>" title="More options">&#x25BE;</button>
                                            </div>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Remove this gateway from management?');">
                                                <?php wp_nonce_field('po_delete_gateway'); ?>
                                                <input type="hidden" name="gateway_id" value="<?php echo esc_attr($gateway->id); ?>">
                                                <button type="submit" name="po_delete_gateway" class="po-btn po-btn-sm po-btn-danger">Remove</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <script>
                        jQuery(function($) {
                            $('.po-gw-toggle').on('click', function() {
                                var btn = $(this);
                                var gwId = btn.data('gateway');
                                var currentlyOn = btn.data('enabled') === 1 || btn.data('enabled') === '1';
                                var newState = currentlyOn ? '0' : '1';
                                
                                $.post(ajaxurl, {
                                    action: 'po_toggle_gateway',
                                    nonce: '<?php echo wp_create_nonce("po_admin_nonce"); ?>',
                                    gateway_id: gwId,
                                    enabled: newState
                                }, function(response) {
                                    if (response.success) {
                                        // Update toggle visually
                                        btn.data('enabled', newState);
                                        var knob = btn.find('span');
                                        var row = btn.closest('tr');
                                        if (newState === '1') {
                                            btn.css('background', '#22c55e');
                                            knob.css('left', '20px');
                                            row.css('opacity', '1');
                                        } else {
                                            btn.css('background', '#cbd5e1');
                                            knob.css('left', '2px');
                                            row.css('opacity', '0.55');
                                        }
                                    }
                                });
                            });
                        });
                        </script>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Backfill Modal -->
        <div id="po-backfill-modal" class="po-modal" style="display:none;">
            <div class="po-modal-content">
                <div class="po-modal-header">
                    <h3>Backfill Options</h3>
                    <button type="button" class="po-modal-close">&times;</button>
                </div>
                <div class="po-modal-body">
                    <input type="hidden" id="po-backfill-gateway" value="">
                    
                    <div class="po-form-row">
                        <label class="po-label">Month to Backfill</label>
                        <select id="po-backfill-month" class="po-select">
                            <?php 
                            for ($i = 0; $i < 3; $i++):
                                $date = new DateTime('first day of this month');
                                $date->modify("-{$i} months");
                                $value = $date->format('Y-m');
                                $label = $date->format('F Y');
                            ?>
                            <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="po-form-row">
                        <label class="po-checkbox">
                            <input type="checkbox" id="po-backfill-rebackfill">
                            <span>Re-backfill (clear existing data for this month first)</span>
                        </label>
                        <p class="po-help">Use this if you need to refresh the data completely.</p>
                    </div>
                </div>
                <div class="po-modal-footer">
                    <button type="button" class="po-btn po-btn-secondary po-modal-cancel">Cancel</button>
                    <button type="button" class="po-btn po-btn-primary" id="po-backfill-confirm">Run Backfill</button>
                </div>
            </div>
        </div>
        
        </div>
    </div>
</div>
