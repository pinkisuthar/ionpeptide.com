<?php
/**
 * Activation / deactivation.
 *
 * @package POPRecoveryEmail
 */

declare(strict_types=1);

namespace POPRecoveryEmail;

defined('ABSPATH') || exit;

final class Activator {

    /** Bump whenever the schema in createTables() changes. */
    public const DB_VERSION = '1.3.0';

    /** Funnel columns added in 1.3.0 (1.5.9): name => definition. */
    private const FUNNEL_COLUMNS = [
        'landed_at'        => 'DATETIME NULL',
        'pay_attempts'     => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'last_decline'     => 'VARCHAR(255) NULL',
        'paid_via_link_at' => 'DATETIME NULL',
    ];

    /**
     * Runs on every load (cheap option check). Applies schema changes to sites that
     * updated the plugin in place, where the activation hook never fires.
     */
    public static function maybeUpgrade(): void {
        // Cheap autoloaded-option check on every load. The version is only recorded once
        // the pay_url column is verified, so no schema query runs on normal requests.
        if (get_option('prer_db_version') === self::DB_VERSION) {
            return;
        }
        // Avoid parallel runs on busy sites.
        if (get_transient('prer_db_upgrading')) {
            return;
        }
        set_transient('prer_db_upgrading', 1, 60);

        $from = (string) get_option('prer_db_version', '0');

        self::createTables();
        self::ensurePayUrlColumn();
        self::ensureFunnelColumns();
        self::scheduleCron();
        self::secureLegacyLogs();

        if (self::schemaComplete()) {
            if (version_compare($from, '1.3.0', '<')) {
                self::correctEmailCreditHistory();
            }
            update_option('prer_db_version', self::DB_VERSION);
        } else {
            error_log('[POP Recovery Email] DB upgrade could not add all columns; will retry.');
        }
        delete_transient('prer_db_upgrading');
    }

    private static function hasColumn(string $column): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'po_email_sequences';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table, $column
        ));
    }

    /** Belt and braces for the 1.3.0 funnel columns (dbDelta can fail silently). */
    private static function ensureFunnelColumns(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'po_email_sequences';
        foreach (self::FUNNEL_COLUMNS as $col => $def) {
            if (!self::hasColumn($col)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
            }
        }
    }

    /**
     * One-time (1.3.0): before 1.5.8 every paid order was marked "recovered by
     * email", clicked or not. Relabel those unclicked records as paid without
     * email, so past months stop overstating email recoveries.
     */
    private static function correctEmailCreditHistory(): void {
        global $wpdb;
        $seq = $wpdb->prefix . 'po_email_sequences';
        $log = $wpdb->prefix . 'po_email_log';
        $seqFixed = (int) $wpdb->query("UPDATE {$seq} SET status = 'paid_direct' WHERE status = 'recovered' AND clicked_at IS NULL");
        $logFixed = (int) $wpdb->query("UPDATE {$log} SET recovered = 0 WHERE recovered = 1 AND clicked_at IS NULL");
        update_option('prer_history_corrected', [
            'at'        => gmdate('Y-m-d H:i:s'),
            'sequences' => $seqFixed,
            'log_rows'  => $logFixed,
        ], false);
    }

    private static function hasPayUrlColumn(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'po_email_sequences';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'pay_url'",
            $table
        ));
    }

    /**
     * Belt and braces: if dbDelta did not add the column, add it directly.
     */
    private static function ensurePayUrlColumn(): void {
        if (self::hasPayUrlColumn()) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'po_email_sequences';
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN pay_url TEXT NULL AFTER tracking_token");
    }

    /**
     * Logs moved to an unguessable directory in 1.5.8. The old wp-content/prer-logs
     * directory relied on .htaccess, which Nginx ignores, so remove its contents.
     */
    private static function secureLegacyLogs(): void {
        $legacy = WP_CONTENT_DIR . '/prer-logs';
        if (!is_dir($legacy)) {
            return;
        }
        foreach ((array) @scandir($legacy) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $file = $legacy . '/' . $name;
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($legacy);
    }

    public static function activate(): void {
        self::createTables();
        self::ensurePayUrlColumn();
        self::ensureFunnelColumns();
        if (self::schemaComplete()) {
            update_option('prer_db_version', self::DB_VERSION);
        }
        self::setDefaults();
        self::seedDefaultTemplate();
        self::scheduleCron();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('prer_process_email_queue');
        wp_clear_scheduled_hook('prer_purge_old_data');
    }

    private static function createTables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $templates = $wpdb->prefix . 'po_email_templates';
        $sequences = $wpdb->prefix . 'po_email_sequences';
        $log       = $wpdb->prefix . 'po_email_log';
        $unsubs    = $wpdb->prefix . 'po_email_unsubscribes';

        $sql = "
            CREATE TABLE {$templates} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL DEFAULT '',
                subject VARCHAR(255) NOT NULL DEFAULT '',
                html_body LONGTEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_status (status)
            ) {$charset};

            CREATE TABLE {$sequences} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                customer_email VARCHAR(255) NOT NULL DEFAULT '',
                failure_code VARCHAR(50) NOT NULL DEFAULT '',
                failure_reason VARCHAR(255) NOT NULL DEFAULT '',
                failure_category VARCHAR(50) NOT NULL DEFAULT '',
                order_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                send_at DATETIME NULL,
                sent_at DATETIME NULL,
                opened_at DATETIME NULL,
                clicked_at DATETIME NULL,
                recovered_at DATETIME NULL,
                cancelled_reason VARCHAR(100) NOT NULL DEFAULT '',
                tracking_token VARCHAR(64) NOT NULL DEFAULT '',
                pay_url TEXT NULL,
                landed_at DATETIME NULL,
                pay_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                last_decline VARCHAR(255) NULL,
                paid_via_link_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_order (order_id),
                KEY idx_email (customer_email),
                KEY idx_status_send (status, send_at),
                KEY idx_token (tracking_token),
                KEY idx_created (created_at)
            ) {$charset};

            CREATE TABLE {$log} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sequence_id BIGINT UNSIGNED NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                template_name VARCHAR(100) NOT NULL DEFAULT '',
                subject VARCHAR(255) NOT NULL DEFAULT '',
                customer_email VARCHAR(255) NOT NULL DEFAULT '',
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                opened_at DATETIME NULL,
                clicked_at DATETIME NULL,
                recovered TINYINT(1) NOT NULL DEFAULT 0,
                recovered_at DATETIME NULL,
                order_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                failure_code VARCHAR(50) NOT NULL DEFAULT '',
                tracking_token VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                KEY idx_sequence (sequence_id),
                KEY idx_order (order_id),
                KEY idx_token (tracking_token),
                KEY idx_sent (sent_at)
            ) {$charset};

            CREATE TABLE {$unsubs} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_email VARCHAR(255) NOT NULL DEFAULT '',
                order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY idx_email (customer_email)
            ) {$charset};
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        // 1.5.9: the schema version is recorded only by schemaComplete() callers,
        // after EVERY required column is verified (previously recorded here as soon
        // as pay_url existed, which skipped the retry when later columns failed).
    }

    /** All required columns present? */
    private static function schemaComplete(): bool {
        if (!self::hasPayUrlColumn()) {
            return false;
        }
        foreach (array_keys(self::FUNNEL_COLUMNS) as $col) {
            if (!self::hasColumn($col)) {
                return false;
            }
        }
        return true;
    }

    private static function setDefaults(): void {
        $defaults = [
            'prer_enabled'            => 'yes',
            'prer_email_delay'        => 3,
            'prer_non_retryable_delay'=> 2,
            'prer_pending_enabled'    => 'no',
            'prer_pending_delay'      => 120,
            'prer_onhold_gateways'    => ['adyen'],
            'prer_onhold_cancel_after'=> 90,
            'prer_min_order_value'    => 0,
            'prer_from_name'          => get_bloginfo('name'),
            'prer_from_email'         => get_option('admin_email'),
            'prer_cc_admin'           => 'no',
            'prer_debug_log'          => 'yes',
            'prer_categories'         => ['retryable_gateway', 'retryable_card_type'],
            'prer_sequence_expiry'    => 72,
            'prer_unsub_expiry_days' => 30,
            'prer_max_failures_24h'   => 2,
            'prer_inline_cooldown'    => 15,
            'prer_active_template_id' => 0,
        ];
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                update_option($key, $value);
            }
        }
    }

    private static function seedDefaultTemplate(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'po_email_templates';

        // Check if table has any templates at all
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        if ($count === 0) {
            // Fresh install or reinstall after uninstall - insert default template
            $templateFile = PRER_PLUGIN_DIR . 'templates/emails/default-recovery.html';
            $html = file_exists($templateFile) ? file_get_contents($templateFile) : self::getInlineDefaultTemplate();

            $wpdb->insert($table, [
                'name'      => 'Default Recovery',
                'subject'   => 'Your order #{{order_number}} is waiting ' . "\u{2014}" . ' complete checkout',
                'html_body' => $html,
                'status'    => 'active',
                'created_at'=> gmdate('Y-m-d H:i:s'),
                'updated_at'=> gmdate('Y-m-d H:i:s'),
            ], ['%s','%s','%s','%s','%s','%s']);

            $id = $wpdb->insert_id;
            if ($id) {
                update_option('prer_active_template_id', $id);
            }
            return;
        }

        // Table has templates - ensure active_template_id points to a valid one
        $activeId = (int) get_option('prer_active_template_id', 0);
        if ($activeId > 0) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE id = %d", $activeId
            ));
            if ($exists > 0) {
                return; // Active template exists, all good
            }
        }

        // Active template ID is missing or invalid - try to find any active template
        $fallbackId = (int) $wpdb->get_var("SELECT id FROM {$table} WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        if (!$fallbackId) {
            // No active templates - pick the first one and activate it
            $fallbackId = (int) $wpdb->get_var("SELECT id FROM {$table} ORDER BY id ASC LIMIT 1");
            if ($fallbackId) {
                $wpdb->update($table, ['status' => 'active'], ['id' => $fallbackId]);
            }
        }

        if ($fallbackId) {
            update_option('prer_active_template_id', $fallbackId);
        }
    }

    private static function scheduleCron(): void {
        // Ensure the custom interval exists even when called before Core\Plugin::boot()
        // registers it (maybeUpgrade runs at plugins_loaded:20, boot at :25).
        add_filter('cron_schedules', static function (array $schedules): array {
            if (!isset($schedules['every_five_minutes'])) {
                $schedules['every_five_minutes'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
            }
            return $schedules;
        });
        if (!wp_next_scheduled('prer_process_email_queue')) {
            wp_schedule_event(time(), 'every_five_minutes', 'prer_process_email_queue');
        }
        if (!wp_next_scheduled('prer_purge_old_data')) {
            wp_schedule_event(time(), 'daily', 'prer_purge_old_data');
        }
    }

    private static function getInlineDefaultTemplate(): string {
        return '<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Complete Your Order</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;"><tr><td align="center" style="padding:40px 16px;">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">
<tr><td style="background:linear-gradient(135deg,#1e293b,#334155);padding:32px 40px;text-align:center;"><h1 style="margin:0;font-size:22px;font-weight:700;color:#fff;">{{site_name}}</h1></td></tr>
<tr><td style="padding:40px;">
<p style="margin:0 0 20px;font-size:18px;font-weight:600;color:#0f172a;">Hi {{customer_name}},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#475569;">We noticed your recent order didn\'t go through. No worries ' . "\u{2014}" . ' these things happen!</p>
<p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#475569;">{{failure_message}}</p>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:12px;margin-bottom:32px;"><tr><td style="padding:24px;">
<p style="margin:0 0 8px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Order #{{order_number}}</p>
<p style="margin:0 0 4px;font-size:15px;color:#334155;">{{order_items}}</p>
<p style="margin:0;font-size:20px;font-weight:700;color:#0f172a;">{{order_total}}</p>
</td></tr></table>
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<a href="{{pay_url}}" style="display:inline-block;padding:16px 48px;background:#1e293b;color:#fff;font-size:16px;font-weight:600;text-decoration:none;border-radius:10px;">Complete Your Order</a>
</td></tr></table>
<p style="margin:32px 0 0;font-size:14px;line-height:1.6;color:#94a3b8;text-align:center;">Your items are reserved and ready. Just click above to finish checkout.</p>
</td></tr>
<tr><td style="padding:24px 40px;background:#f8fafc;border-top:1px solid #e2e8f0;">
<p style="margin:0 0 8px;font-size:13px;color:#94a3b8;text-align:center;">Need help? Contact <a href="mailto:{{support_email}}" style="color:#475569;text-decoration:underline;">{{support_email}}</a></p>
<p style="margin:0;font-size:12px;color:#cbd5e1;text-align:center;">&copy; {{current_year}} {{site_name}} &middot; <a href="{{unsubscribe_url}}" style="color:#cbd5e1;text-decoration:underline;">Unsubscribe</a></p>
</td></tr>
</table></td></tr></table>
<img src="{{open_track_url}}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">
</body></html>';
    }
}
