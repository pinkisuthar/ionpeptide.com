<?php
/**
 * Rotating file logger - 3-day cycle.
 *
 * Writes to wp-content/prer-logs-{random}/prer-YYYY-MM-DD.log
 * Auto-purges files older than 3 days on every write.
 *
 * 1.5.8: the directory name now includes a random per-site key. The old fixed path
 * (wp-content/prer-logs/) was protected only by .htaccess, which Nginx ignores, so
 * date-named log files containing customer emails and pay links could be fetched.
 *
 * @package POPRecoveryEmail\Core
 */

declare(strict_types=1);

namespace POPRecoveryEmail\Core;

defined('ABSPATH') || exit;

final class Logger {

    private static ?string $logDir = null;
    private static bool $enabled = true;
    private static bool $checkedOption = false;

    private static function init(): void {
        if (self::$logDir !== null) {
            return;
        }
        self::$logDir = WP_CONTENT_DIR . '/prer-logs-' . self::dirKey();

        if (!is_dir(self::$logDir)) {
            wp_mkdir_p(self::$logDir);
            // Protect from direct access (Apache / IIS). Nginx relies on the random dir name.
            @file_put_contents(self::$logDir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents(self::$logDir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
            @file_put_contents(self::$logDir . '/index.php', '<?php // silence');
        }
    }

    /**
     * Random, persistent, per-site directory key.
     */
    private static function dirKey(): string {
        $key = (string) get_option('prer_log_dir_key', '');
        if (!preg_match('/^[a-z0-9]{24}$/', $key)) {
            $key = strtolower(wp_generate_password(24, false, false));
            update_option('prer_log_dir_key', $key, false);
        }
        return $key;
    }

    private static function isEnabled(): bool {
        if (!self::$checkedOption) {
            self::$enabled = get_option('prer_debug_log', 'yes') === 'yes';
            self::$checkedOption = true;
        }
        return self::$enabled;
    }

    /**
     * Log an info-level message.
     */
    public static function info(string $message, array $context = []): void {
        self::write('INFO', $message, $context);
    }

    /**
     * Log an error-level message.
     */
    public static function error(string $message, array $context = []): void {
        self::write('ERROR', $message, $context);
    }

    /**
     * Log a debug-level message (more verbose).
     */
    public static function debug(string $message, array $context = []): void {
        self::write('DEBUG', $message, $context);
    }

    /**
     * Write a log entry.
     */
    private static function write(string $level, string $message, array $context): void {
        if (!self::isEnabled()) {
            return;
        }

        self::init();

        $file = self::$logDir . '/prer-' . gmdate('Y-m-d') . '.log';
        $timestamp = gmdate('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES) : '';

        $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        // Purge old files (check once per request via static flag)
        static $purged = false;
        if (!$purged) {
            $purged = true;
            self::purgeOldLogs();
        }
    }

    /**
     * Delete log files older than 3 days.
     */
    private static function purgeOldLogs(): void {
        $cutoff = strtotime('-3 days');
        $files = glob(self::$logDir . '/prer-*.log');
        if (!$files) {
            return;
        }
        foreach ($files as $file) {
            // Extract date from filename: prer-YYYY-MM-DD.log
            if (preg_match('/prer-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                $fileDate = strtotime($m[1]);
                if ($fileDate && $fileDate < $cutoff) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Get path to today's log file (for admin display).
     */
    public static function getTodayLogPath(): string {
        self::init();
        return self::$logDir . '/prer-' . gmdate('Y-m-d') . '.log';
    }

    /**
     * Get all available log files, newest first.
     *
     * @return array<string, string> filename => full path
     */
    public static function getLogFiles(): array {
        self::init();
        $files = glob(self::$logDir . '/prer-*.log') ?: [];
        rsort($files);
        $result = [];
        foreach ($files as $f) {
            $result[basename($f)] = $f;
        }
        return $result;
    }

    /**
     * Read tail of a log file.
     */
    public static function readLog(string $file, int $lines = 200): string {
        if (!file_exists($file) || !is_readable($file)) {
            return '';
        }
        $all = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail = array_slice($all, -$lines);
        return implode(PHP_EOL, $tail);
    }
}
