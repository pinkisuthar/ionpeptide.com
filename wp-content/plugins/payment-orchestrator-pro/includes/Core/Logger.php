<?php
/**
 * Logger - Secure debug logging system
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Core;

final class Logger {
    private const TABLE_NAME = 'po_logs';
    private const DEFAULT_MAX_LOGS = 5000;
    private const DEFAULT_RETENTION_DAYS = 14;
    private const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];
    
    /**
     * Log a debug message
     */
    public static function debug(string $message, array $context = []): void {
        self::log('debug', $message, $context);
    }
    
    /**
     * Log an info message
     */
    public static function info(string $message, array $context = []): void {
        self::log('info', $message, $context);
    }
    
    /**
     * Log a warning message
     */
    public static function warning(string $message, array $context = []): void {
        self::log('warning', $message, $context);
    }
    
    /**
     * Log an error message
     */
    public static function error(string $message, array $context = []): void {
        self::log('error', $message, $context);
    }
    
    /**
     * Write log entry to database
     */
    private static function log(string $level, string $message, array $context): void {
        // Check if logging is enabled
        if (get_option('po_enable_logging', 'yes') !== 'yes') {
            return;
        }
        
        // Check minimum log level
        $minLevel = get_option('po_log_level', 'info');
        if (array_search($level, self::LOG_LEVELS) < array_search($minLevel, self::LOG_LEVELS)) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        // Ensure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }
        
        // Sanitize context - remove sensitive data
        $context = self::sanitizeContext($context);
        
        $wpdb->insert($table, [
            'level' => $level,
            'message' => mb_substr($message, 0, 500),
            'context' => wp_json_encode($context),
            'created_at' => current_time('mysql'),
        ], ['%s', '%s', '%s', '%s']);
        
        // Cleanup old logs periodically
        if (wp_rand(1, 100) === 1) {
            self::cleanup();
        }
    }
    
    /**
     * Remove sensitive data from context
     */
    private static function sanitizeContext(array $context): array {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'card', 'cvv', 'cvc', 'pin'];
        
        foreach ($context as $key => $value) {
            foreach ($sensitiveKeys as $sensitive) {
                if (stripos((string)$key, $sensitive) !== false) {
                    $context[$key] = '[REDACTED]';
                    break;
                }
            }
            
            if (is_array($value)) {
                $context[$key] = self::sanitizeContext($value);
            }
        }
        
        return $context;
    }
    
    /**
     * Get recent logs
     */
    public static function getLogs(int $limit = 100, ?string $level = null): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }
        
        $where = $level ? $wpdb->prepare('WHERE level = %s', $level) : '';
        
        return $wpdb->get_results(
            "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT {$limit}",
            ARRAY_A
        ) ?: [];
    }
    
    /**
     * Clear all logs
     */
    public static function clear(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
    
    /**
     * Cleanup old logs - DEBUG entries are trimmed first, WARNING/ERROR kept longer
     */
    private static function cleanup(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        $maxLogs = (int) get_option('po_max_log_entries', self::DEFAULT_MAX_LOGS);
        $retentionDays = (int) get_option('po_log_retention_days', self::DEFAULT_RETENTION_DAYS);
        
        // Delete DEBUG logs older than retention period
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE level = 'debug' AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retentionDays
        ));
        
        // Keep INFO/WARNING/ERROR logs twice as long
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE level IN ('info', 'warning', 'error') AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retentionDays * 2
        ));
        
        // If still over max, trim oldest DEBUG entries first (never delete WARNING/ERROR to hit cap)
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > $maxLogs) {
            $debugCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE level = 'debug'");
            $deleteCount = min($count - $maxLogs, $debugCount);
            if ($deleteCount > 0) {
                $wpdb->query(
                    "DELETE FROM {$table} WHERE level = 'debug' ORDER BY id ASC LIMIT {$deleteCount}"
                );
            }
        }
    }
    
    /**
     * Export logs for support
     */
    public static function export(): string {
        $logs = self::getLogs(2000);
        $output = "Payment Orchestrator Debug Log\n";
        $output .= "Generated: " . current_time('mysql') . "\n";
        $output .= "Version: " . PO_VERSION . "\n";
        $output .= "PHP: " . PHP_VERSION . "\n";
        $output .= "WordPress: " . get_bloginfo('version') . "\n";
        $output .= "WooCommerce: " . (defined('WC_VERSION') ? WC_VERSION : 'N/A') . "\n";
        $output .= str_repeat('-', 80) . "\n\n";
        
        foreach ($logs as $log) {
            $output .= sprintf(
                "[%s] [%s] %s %s\n",
                $log['created_at'],
                strtoupper($log['level']),
                $log['message'],
                $log['context'] !== '[]' ? $log['context'] : ''
            );
        }
        
        return $output;
    }
}
