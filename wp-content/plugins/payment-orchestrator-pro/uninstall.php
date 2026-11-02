<?php
/**
 * Uninstall handler - removes all plugin data
 *
 * @package PaymentOrchestrator
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// ALWAYS clear scheduled events (even if not deleting data)
wp_clear_scheduled_hook('po_daily_report');
wp_clear_scheduled_hook('po_hourly_cleanup');
wp_clear_scheduled_hook('po_monthly_check');

// ALWAYS clear transients
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_po_%' OR option_name LIKE '_transient_timeout_po_%'"
);

// Only delete tables and options if user explicitly chose to
$deleteData = get_option('po_delete_data_on_uninstall', 'no');

if ($deleteData === 'yes') {
    // Drop custom tables (ONLY our po_ tables)
    $tables = [
        $wpdb->prefix . 'po_gateways',
        $wpdb->prefix . 'po_transactions',
        $wpdb->prefix . 'po_daily_stats',
        $wpdb->prefix . 'po_logs',
    ];
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
    
    // Delete our options (ONLY po_ prefixed)
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'po_%'"
    );
    
    // Clean up any legacy _po_recorded meta from older versions
    // This uses direct SQL to avoid loading WooCommerce
    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->postmeta}'") === $wpdb->postmeta) {
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_po_recorded'"
        );
    }
    
    // Also clean from HPOS meta table if it exists
    $hposMetaTable = $wpdb->prefix . 'wc_orders_meta';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$hposMetaTable}'") === $hposMetaTable) {
        $wpdb->query(
            "DELETE FROM {$hposMetaTable} WHERE meta_key = '_po_recorded'"
        );
    }
}
