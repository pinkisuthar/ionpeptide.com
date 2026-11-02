<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

// Only remove data if user explicitly opted in via Settings
if (get_option('prer_delete_data_on_uninstall', 'no') !== 'yes') {
    // Keep all data - this is likely a plugin update, not a permanent removal
    return;
}

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}po_email_templates");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}po_email_sequences");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}po_email_log");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}po_email_unsubscribes");
foreach (['prer_enabled','prer_email_delay','prer_non_retryable_delay','prer_pending_enabled','prer_pending_delay','prer_onhold_gateways','prer_onhold_gateways_text','prer_onhold_cancel_after','prer_min_order_value','prer_from_name','prer_from_email','prer_cc_admin','prer_debug_log','prer_categories','prer_sequence_expiry','prer_unsub_expiry_days','prer_max_failures_24h','prer_inline_cooldown','prer_active_template_id','prer_db_version','prer_delete_data_on_uninstall','prer_log_dir_key'] as $opt) {
    delete_option($opt);
}
wp_clear_scheduled_hook('prer_process_email_queue');
wp_clear_scheduled_hook('prer_purge_old_data');
// Clean up log files (legacy wp-content/prer-logs and 1.5.8+ wp-content/prer-logs-{key}), including dotfiles
foreach ((array) glob(WP_CONTENT_DIR . '/prer-logs*', GLOB_ONLYDIR) as $logDir) {
    foreach ((array) @scandir($logDir) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (is_file($logDir . '/' . $name)) {
            @unlink($logDir . '/' . $name);
        }
    }
    @rmdir($logDir);
}
