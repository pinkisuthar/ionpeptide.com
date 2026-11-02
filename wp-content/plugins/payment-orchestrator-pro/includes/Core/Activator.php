<?php
/**
 * Activator - Handles plugin activation and database setup
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Core;

final class Activator {
    /**
     * Run on plugin activation
     */
    public static function activate(): void {
        try {
            self::createTables();
            self::ensureFailureHealthColumn();
            self::setDefaultOptions();
            self::scheduleEvents();
            
            // Store activation time for reference
            update_option('po_activated_at', current_time('mysql'));
            update_option('po_version', PO_VERSION);
            
            // Flush rewrite rules
            flush_rewrite_rules();
        } catch (\Exception $e) {
            // Log error to WordPress debug log if enabled
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Payment Orchestrator activation error: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Run on plugin deactivation
     */
    public static function deactivate(): void {
        // Clear scheduled events
        wp_clear_scheduled_hook('po_daily_report');
        wp_clear_scheduled_hook('po_hourly_cleanup');
        wp_clear_scheduled_hook('po_monthly_check');
        
        // Clear transients
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_po_%' OR option_name LIKE '_transient_timeout_po_%'"
        );
    }
    
    /**
     * Create database tables
     */
    private static function createTables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        // Ensure wp-admin/includes/upgrade.php is available
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        // Gateways table
        $sql = "CREATE TABLE {$wpdb->prefix}po_gateways (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            gateway_id VARCHAR(100) NOT NULL UNIQUE,
            display_name VARCHAR(255) NOT NULL,
            monthly_target DECIMAL(12,2) DEFAULT 0,
            max_transaction_amount DECIMAL(12,2) DEFAULT 0,
            gateway_type VARCHAR(20) DEFAULT 'card',
            accepts_visa TINYINT(1) DEFAULT 1,
            accepts_mastercard TINYINT(1) DEFAULT 1,
            accepts_amex TINYINT(1) DEFAULT 1,
            accepts_discover TINYINT(1) DEFAULT 1,
            is_enabled TINYINT(1) DEFAULT 1,
            is_always_on TINYINT(1) DEFAULT 0,
            priority INT DEFAULT 10,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_enabled (is_enabled),
            INDEX idx_gateway (gateway_id)
        ) {$charset};";
        dbDelta($sql);
        
        // Transactions table (for rotation tracking)
        $sql = "CREATE TABLE {$wpdb->prefix}po_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            gateway_id VARCHAR(100) NOT NULL,
            order_id BIGINT UNSIGNED,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error_code VARCHAR(50),
            error_message TEXT,
            month_year VARCHAR(7) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gateway (gateway_id),
            INDEX idx_month (month_year),
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) {$charset};";
        dbDelta($sql);
        
        // Daily stats table
        $sql = "CREATE TABLE {$wpdb->prefix}po_daily_stats (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            gateway_id VARCHAR(100) NOT NULL,
            stat_date DATE NOT NULL,
            total_volume DECIMAL(12,2) DEFAULT 0,
            transaction_count INT DEFAULT 0,
            success_count INT DEFAULT 0,
            failure_count INT DEFAULT 0,
            last_transaction_at DATETIME,
            UNIQUE KEY unique_gateway_date (gateway_id, stat_date),
            INDEX idx_date (stat_date)
        ) {$charset};";
        dbDelta($sql);
        
        // Logs table
        $sql = "CREATE TABLE {$wpdb->prefix}po_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            level VARCHAR(20) NOT NULL,
            message VARCHAR(500) NOT NULL,
            context TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_level (level),
            INDEX idx_created (created_at)
        ) {$charset};";
        dbDelta($sql);
        
        // Failed transactions table (v2.4.2 - for retry/recovery tracking)
        $sql = "CREATE TABLE {$wpdb->prefix}po_failed_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            gateway_id VARCHAR(100) NOT NULL,
            failure_code VARCHAR(50),
            failure_reason VARCHAR(500),
            failure_category VARCHAR(50) NOT NULL DEFAULT 'unknown',
            counts_against_health TINYINT(1) DEFAULT NULL,
            card_type VARCHAR(50),
            customer_email VARCHAR(255),
            order_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            retry_count INT NOT NULL DEFAULT 0,
            retry_gateway VARCHAR(100),
            recovered TINYINT(1) NOT NULL DEFAULT 0,
            recovered_at DATETIME,
            recovery_source VARCHAR(50) DEFAULT NULL,
            customer_message VARCHAR(500),
            month_year VARCHAR(7) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order (order_id),
            INDEX idx_gateway (gateway_id),
            INDEX idx_category (failure_category),
            INDEX idx_recovered (recovered),
            INDEX idx_month (month_year),
            INDEX idx_email (customer_email(100))
        ) {$charset};";
        dbDelta($sql);
    }
    
    /**
     * Set default plugin options
     */
    private static function setDefaultOptions(): void {
        $defaults = [
            'po_enable_logging' => 'yes',
            'po_log_level' => 'info',
            'po_max_cold_transactions' => 5,
            'po_daily_report_enabled' => 'no',
            'po_daily_report_time' => '20:00',
            'po_daily_report_timezone' => 'America/Chicago',
            'po_daily_report_email' => get_option('admin_email'),
            'po_test_mode' => 'no',
            'po_rotation_debug' => 'no',
            'po_delete_data_on_uninstall' => 'no',
            // Failure alerting
            'po_failure_alert_enabled' => 'yes',
            'po_failure_alert_threshold' => 3,
            'po_failure_alert_window' => 10, // minutes
            'po_failure_alert_email' => get_option('admin_email'),
            // Recovery (v2.4.2)
            'po_recovery_enabled' => 'yes',
        ];
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                update_option($key, $value);
            }
        }
    }
    
    /**
     * Schedule cron events
     */
    private static function scheduleEvents(): void {
        // Daily report
        if (!wp_next_scheduled('po_daily_report')) {
            $timezone = get_option('po_daily_report_timezone', 'America/Chicago');
            $time = get_option('po_daily_report_time', '20:00');
            
            try {
                $tz = new \DateTimeZone($timezone);
                $now = new \DateTime('now', $tz);
                $scheduled = new \DateTime($time, $tz);
                
                if ($scheduled <= $now) {
                    $scheduled->modify('+1 day');
                }
                
                wp_schedule_event($scheduled->getTimestamp(), 'daily', 'po_daily_report');
            } catch (\Exception $e) {
                // Fallback to simple scheduling
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'po_daily_report');
            }
        }
        
        // Hourly cleanup
        if (!wp_next_scheduled('po_hourly_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'po_hourly_cleanup');
        }
        
        // Monthly reset check (runs daily, checks if it's the 1st)
        if (!wp_next_scheduled('po_monthly_check')) {
            wp_schedule_event(time(), 'daily', 'po_monthly_check');
        }
    }
    
    /**
     * Check and run database migrations if needed
     */
    public static function maybeUpgrade(): void {
        $currentVersion = get_option('po_version', '0.0.0');
        
        if (version_compare($currentVersion, PO_VERSION, '<')) {
            // Throttle retries if a previous attempt could not finish the schema
            // change, so a failing upgrade never runs dbDelta on every request.
            if (get_transient('po_upgrade_retry_wait')) {
                return;
            }
            // 2.7.25: run once. Without this, concurrent requests right after an
            // update each ran the upgrade (production: 13 runs for 2.7.22, 4 for 2.7.24).
            // 2.7.33: atomic lock - add_option() is a single INSERT on a unique key,
            // so only one concurrent request can win (the transient check-then-set
            // let 3 requests in at once on production). Stale locks expire after 2 min.
            $lockAt = (int) get_option('po_upgrade_lock', 0);
            if ($lockAt && (time() - $lockAt) > 120) {
                delete_option('po_upgrade_lock');
            }
            if (!add_option('po_upgrade_lock', time(), '', 'no')) {
                return;
            }
            
            self::createTables(); // dbDelta handles upgrades
            
            // 2.7.22: verify the new column really exists. dbDelta can fail silently;
            // if it did, add the column directly. Only record the new version once the
            // schema is confirmed, otherwise retry later (throttled).
            if (!self::ensureFailureHealthColumn()) {
                set_transient('po_upgrade_retry_wait', 1, 10 * MINUTE_IN_SECONDS);
                delete_option('po_upgrade_lock');
                Logger::error('Upgrade incomplete: counts_against_health column missing; will retry', [
                    'from' => $currentVersion,
                    'to' => PO_VERSION,
                ]);
                return;
            }
            
            update_option('po_version', PO_VERSION);
            delete_option('po_upgrade_lock');
            
            Logger::info('Plugin upgraded', [
                'from' => $currentVersion,
                'to' => PO_VERSION,
            ]);
        }
    }
    
    /**
     * Ensure po_failed_transactions.counts_against_health exists.
     * Sets the po_fail_health_col option to '1' only when the column is confirmed,
     * which is what every reader checks before referencing the column.
     */
    public static function ensureFailureHealthColumn(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'po_failed_transactions';
        $has = static function () use ($wpdb, $table): bool {
            return (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'counts_against_health'",
                $table
            ));
        };
        if (!$has()) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN counts_against_health TINYINT(1) DEFAULT NULL AFTER failure_category");
        }
        if ($has()) {
            update_option('po_fail_health_col', '1', true);
            return true;
        }
        update_option('po_fail_health_col', '0', true);
        return false;
    }
}
