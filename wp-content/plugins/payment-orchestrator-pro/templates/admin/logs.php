<?php
/**
 * Logs Template
 * 
 * @package PaymentOrchestrator
 * @var array $logs
 */

defined('ABSPATH') || exit;
?>
<div class="wrap">
    <h1 style="display:none;">Logs</h1>
    
    <div class="po-wrap">
        <div class="po-layout">
            
            <!-- Shared Header + Tab Nav -->
            <?php $currentPage = 'payment-orchestrator-logs'; include PO_PLUGIN_DIR . 'templates/admin/partials/header-nav.php'; ?>
            
            <!-- Action Buttons -->
            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 16px;">
                <button type="button" id="po-export-logs" class="po-btn po-btn-secondary">Export</button>
                <button type="button" id="po-clear-logs" class="po-btn po-btn-danger">Clear Logs</button>
            </div>
        
        <!-- Filters -->
        <div class="po-card">
            <div class="po-card-body po-logs-filters">
                <div class="po-filter-group">
                    <label>Level:</label>
                    <select id="po-log-level" class="po-select">
                        <option value="all">All Levels</option>
                        <option value="debug">Debug</option>
                        <option value="info">Info</option>
                        <option value="warning">Warning</option>
                        <option value="error">Error</option>
                    </select>
                </div>
                <button type="button" id="po-refresh-logs" class="po-btn po-btn-sm">Refresh</button>
            </div>
        </div>
        
        <!-- Logs Table -->
        <div class="po-card">
            <div class="po-card-body po-logs-container">
                <?php if (empty($logs)): ?>
                    <div class="po-empty">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                        <p>No logs yet. Activity will appear here as the plugin runs.</p>
                    </div>
                <?php else: ?>
                    <table class="po-table po-logs-table" id="po-logs-table">
                        <thead>
                            <tr>
                                <th style="width:160px;">Time</th>
                                <th style="width:80px;">Level</th>
                                <th>Message</th>
                                <th style="width:200px;">Context</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): 
                                $levelClass = match($log['level']) {
                                    'error' => 'po-log-error',
                                    'warning' => 'po-log-warning',
                                    'info' => 'po-log-info',
                                    default => 'po-log-debug',
                                };
                            ?>
                            <tr class="<?php echo $levelClass; ?>">
                                <td class="po-log-time"><?php echo esc_html($log['created_at']); ?></td>
                                <td>
                                    <span class="po-log-level po-log-level-<?php echo esc_attr($log['level']); ?>">
                                        <?php echo esc_html(strtoupper($log['level'])); ?>
                                    </span>
                                </td>
                                <td class="po-log-message"><?php echo esc_html($log['message']); ?></td>
                                <td class="po-log-context">
                                    <?php 
                                    $context = json_decode($log['context'], true);
                                    if (!empty($context)) {
                                        echo '<code>' . esc_html(json_encode($context, JSON_PRETTY_PRINT)) . '</code>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        </div>
    </div>
</div>
