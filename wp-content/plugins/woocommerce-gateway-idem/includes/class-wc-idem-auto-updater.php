<?php
/**
 * IDEM Payment Gateway Auto-Updater
 *
 * Handles automatic plugin updates from EasyProcess servers
 *
 * @package WooCommerce_Gateway_IDEM
 * @since 4.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_IDEM_Auto_Updater {
    
    /**
     * Plugin base directory
     * @var string
     */
    private $plugin_dir;
    
    /**
     * Plugin main file
     * @var string
     */
    private $plugin_file;
    
    /**
     * Current plugin version
     * @var string
     */
    private $current_version;
    
    /**
     * Backup directory path
     * @var string
     */
    private $backup_dir;
    
    /**
     * Logger instance
     * @var WC_Logger
     */
    private $logger;
    
    /**
     * Gateway instance
     * @var WC_Gateway_Idem
     */
    private $gateway;
    
    /**
     * Constructor
     */
    public function __construct($gateway = null) {
        $this->plugin_dir = dirname(dirname(__FILE__));
        $this->plugin_file = $this->plugin_dir . '/woocommerce-gateway-idem.php';
        $this->gateway = $gateway;
        
        // Get current version from plugin header
        $plugin_data = get_file_data($this->plugin_file, array('Version' => 'Version'));
        $this->current_version = $plugin_data['Version'];
        
        // Setup backup directory
        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/idem-backups';
        
        // Initialize logger
        $this->logger = wc_get_logger();
        
        // Ensure backup directory exists
        $this->ensure_backup_directory();
    }
    
    /**
     * Initialize auto-updater hooks
     */
    public function init() {
        // Register cron hook
        add_action('wc_idem_auto_update_check', array($this, 'check_and_update'));
        
        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('wc_idem_auto_update_check')) {
            wp_schedule_event(time(), 'sixhours', 'wc_idem_auto_update_check');
        }
        
        // Cleanup on deactivation
        register_deactivation_hook($this->plugin_file, array($this, 'deactivate'));
    }
    
    /**
     * Add six hours cron schedule
     */
    public function add_cron_schedule($schedules) {
        if (!isset($schedules['sixhours'])) {
            $schedules['sixhours'] = array(
                'interval' => 21600, // 6 hours in seconds
                'display' => __('Every Six Hours', 'woocommerce-gateway-idem')
            );
        }
        return $schedules;
    }
    
    /**
     * Main update check and installation method
     */
    public function check_and_update($force = false) {
        $from = $this->current_version;

        // Check if auto-update is enabled. An explicit force (API force_update / manual) overrides
        // the toggle — the toggle only governs the automatic cron path.
        if (!$force && !$this->is_auto_update_enabled()) {
            $this->log('Auto-update is disabled in settings');
            return array('status' => 'up_to_date', 'from' => $from, 'to' => $from, 'message' => 'Auto-update is disabled in settings (pass force=1 to override)');
        }

        try {
            // Check for new version
            $latest_version = $this->get_latest_version();

            if (is_wp_error($latest_version)) {
                $this->log('Error checking latest version: ' . $latest_version->get_error_message(), 'error');
                return array('status' => 'failed', 'from' => $from, 'to' => $from, 'message' => 'Error checking latest version: ' . $latest_version->get_error_message());
            }

            // Compare versions — up to date unless forced (a force re-installs the same version, e.g. a
            // hotfix republished under the same version number).
            if (!$force && version_compare($this->current_version, $latest_version, '>=')) {
                $this->log('Plugin is up to date (current: ' . $this->current_version . ', latest: ' . $latest_version . ')');
                return array('status' => 'up_to_date', 'from' => $from, 'to' => $latest_version, 'message' => 'Plugin is up to date (current: ' . $from . ', latest: ' . $latest_version . ')');
            }

            $this->log(($force ? 'Forced (re)install of version ' : 'New version available: ') . $latest_version . ' (current: ' . $this->current_version . ')');

            // Perform pre-update checks
            $pre_check = $this->pre_update_checks();
            if (is_wp_error($pre_check)) {
                $this->log('Pre-update checks failed: ' . $pre_check->get_error_message(), 'error');
                $this->send_update_notification('failed', $pre_check->get_error_message());
                return array('status' => 'failed', 'from' => $from, 'to' => $latest_version, 'message' => 'Pre-update checks failed: ' . $pre_check->get_error_message());
            }

            // Download new version
            $download_path = $this->download_update($latest_version);
            if (is_wp_error($download_path)) {
                $this->log('Download failed: ' . $download_path->get_error_message(), 'error');
                $this->send_update_notification('failed', $download_path->get_error_message());
                return array('status' => 'failed', 'from' => $from, 'to' => $latest_version, 'message' => 'Download failed: ' . $download_path->get_error_message());
            }

            // Backup current version
            $backup_path = $this->backup_current_version();
            if (is_wp_error($backup_path)) {
                $this->log('Backup failed: ' . $backup_path->get_error_message(), 'error');
                $this->send_update_notification('failed', $backup_path->get_error_message());
                // Clean up download
                @unlink($download_path);
                return array('status' => 'failed', 'from' => $from, 'to' => $latest_version, 'message' => 'Backup failed: ' . $backup_path->get_error_message());
            }

            // Install update
            $install_result = $this->install_update($download_path, $latest_version);
            if (is_wp_error($install_result)) {
                $this->log('Installation failed: ' . $install_result->get_error_message(), 'error');

                // Attempt rollback
                $rollback_result = $this->rollback($backup_path);
                $rolled_back = !is_wp_error($rollback_result);
                if (!$rolled_back) {
                    $this->log('CRITICAL: Rollback failed: ' . $rollback_result->get_error_message(), 'error');
                    $this->send_update_notification('critical', 'Update failed and rollback failed');
                } else {
                    $this->log('Successfully rolled back to previous version');
                    $this->send_update_notification('failed', 'Update failed but successfully rolled back');
                }

                // Clean up download
                @unlink($download_path);
                return array('status' => 'failed', 'from' => $from, 'to' => $latest_version, 'message' => 'Installation failed: ' . $install_result->get_error_message() . ($rolled_back ? ' (rolled back)' : ' (ROLLBACK ALSO FAILED)'));
            }

            // Verify update
            $verify_result = $this->verify_update($latest_version);
            if (is_wp_error($verify_result)) {
                $this->log('Verification failed: ' . $verify_result->get_error_message(), 'error');

                // Attempt rollback
                $rollback_result = $this->rollback($backup_path);
                $rolled_back = !is_wp_error($rollback_result);
                if (!$rolled_back) {
                    $this->log('CRITICAL: Rollback failed: ' . $rollback_result->get_error_message(), 'error');
                    $this->send_update_notification('critical', 'Verification failed and rollback failed');
                } else {
                    $this->log('Successfully rolled back to previous version');
                    $this->send_update_notification('failed', 'Verification failed but successfully rolled back');
                }

                // Clean up download
                @unlink($download_path);
                return array('status' => 'failed', 'from' => $from, 'to' => $latest_version, 'message' => 'Verification failed: ' . $verify_result->get_error_message() . ($rolled_back ? ' (rolled back)' : ' (ROLLBACK ALSO FAILED)'));
            }

            // Clean up download file
            @unlink($download_path);

            // Clean up old backups (keep last 3)
            $this->cleanup_old_backups();

            // Clear caches
            $this->clear_caches();

            // Log success
            $this->log('Successfully updated to version ' . $latest_version);

            // Send success notification
            $this->send_update_notification('success', 'Updated from ' . $this->current_version . ' to ' . $latest_version);

            // Update current version
            $this->current_version = $latest_version;

            return array('status' => 'updated', 'from' => $from, 'to' => $latest_version, 'message' => 'Updated from ' . $from . ' to ' . $latest_version);

        } catch (Exception $e) {
            $this->log('Unexpected error during update: ' . $e->getMessage(), 'error');
            $this->send_update_notification('failed', $e->getMessage());
            return array('status' => 'failed', 'from' => $from, 'to' => $from, 'message' => 'Unexpected error during update: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if auto-update is enabled
     */
    private function is_auto_update_enabled() {
        if ($this->gateway) {
            return 'yes' === $this->gateway->get_option('auto_update_enabled', 'yes');
        }
        
        // Fallback: check option directly
        $settings = get_option('woocommerce_idem_settings', array());
        return !isset($settings['auto_update_enabled']) || 'yes' === $settings['auto_update_enabled'];
    }
    
    /**
     * Get latest version from API
     */
    private function get_latest_version() {
        $response = wp_remote_get('https://www.easyprocess.ai/api/idem/woo-plugin-version', array(
            'timeout' => 30,
            'user-agent' => 'IDEM-WooCommerce-Plugin/' . $this->current_version
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data) || !isset($data['data']) || !is_string($data['data'])) {
            return new WP_Error(
                'idem_invalid_version_response',
                __('Invalid response from IDEM version endpoint', 'woocommerce-gateway-idem')
            );
        }
        
        return $data['data'];
    }
    
    /**
     * Perform pre-update checks
     */
    private function pre_update_checks() {
        // Check if plugin directory is writable
        if (!is_writable($this->plugin_dir)) {
            return new WP_Error('directory_not_writable', 'Plugin directory is not writable');
        }
        
        // Check if backup directory is writable
        if (!is_writable($this->backup_dir)) {
            return new WP_Error('backup_dir_not_writable', 'Backup directory is not writable');
        }
        
        // Check available disk space (require at least 50MB)
        $free_space = disk_free_space($this->plugin_dir);
        if ($free_space !== false && $free_space < 52428800) { // 50MB in bytes
            return new WP_Error('insufficient_space', 'Insufficient disk space for update');
        }
        
        // Check if we're not in the middle of another update
        $lock = get_transient('idem_update_lock');
        if ($lock) {
            return new WP_Error('update_in_progress', 'Another update is already in progress');
        }
        
        // Set update lock (expires in 10 minutes)
        set_transient('idem_update_lock', true, 600);
        
        return true;
    }
    
    /**
     * Download update package
     */
    private function download_update($version) {
        $download_url = 'http://www.easyprocess.ai/files/documents/gateway-easyprocess-idem-' . $version . '.zip';
        
        // Use HTTPS if available
        if (is_ssl()) {
            $download_url = str_replace('http://', 'https://', $download_url);
        }
        
        $temp_file = download_url($download_url, 300); // 5 minutes timeout
        
        if (is_wp_error($temp_file)) {
            return $temp_file;
        }
        
        // Verify it's a valid zip file
        $zip = new ZipArchive();
        if ($zip->open($temp_file) !== true) {
            @unlink($temp_file);
            return new WP_Error('invalid_zip', 'Downloaded file is not a valid ZIP archive');
        }
        $zip->close();
        
        return $temp_file;
    }
    
    /**
     * Backup current plugin version
     */
    private function backup_current_version() {
        $backup_filename = 'idem-backup-' . $this->current_version . '-' . date('YmdHis') . '.zip';
        $backup_path = $this->backup_dir . '/' . $backup_filename;
        
        // Create zip archive
        $zip = new ZipArchive();
        if ($zip->open($backup_path, ZipArchive::CREATE) !== true) {
            return new WP_Error('backup_create_failed', 'Could not create backup archive');
        }
        
        // Add all plugin files to backup
        $this->add_directory_to_zip($zip, $this->plugin_dir, basename($this->plugin_dir));
        
        $zip->close();
        
        // Verify backup was created
        if (!file_exists($backup_path) || filesize($backup_path) < 1000) {
            return new WP_Error('backup_verify_failed', 'Backup file was not created properly');
        }
        
        // Also backup settings
        $settings_backup = array(
            'version' => $this->current_version,
            'settings' => get_option('woocommerce_idem_settings', array()),
            'timestamp' => time()
        );
        
        update_option('idem_settings_backup_' . $this->current_version, $settings_backup);
        
        $this->log('Created backup: ' . $backup_filename);
        
        return $backup_path;
    }
    
    /**
     * Install update from downloaded package
     */
    private function install_update($package_path, $new_version) {
        // Enable maintenance mode
        $this->enable_maintenance_mode();
        
        try {
            // Extract to temporary directory
            $temp_dir = $this->plugin_dir . '_update_temp';
            
            // Remove temp directory if it exists
            if (is_dir($temp_dir)) {
                $this->recursive_remove_directory($temp_dir);
            }
            
            // Extract update package
            $unzip_result = unzip_file($package_path, $temp_dir);
            
            if (is_wp_error($unzip_result)) {
                $this->disable_maintenance_mode();
                return $unzip_result;
            }
            
            // Find the actual plugin directory in the extracted files
            $extracted_plugin_dir = $this->find_plugin_directory($temp_dir);
            if (is_wp_error($extracted_plugin_dir)) {
                $this->recursive_remove_directory($temp_dir);
                $this->disable_maintenance_mode();
                return $extracted_plugin_dir;
            }
            
            // Move current plugin to temporary location
            $old_plugin_temp = $this->plugin_dir . '_old';
            if (is_dir($old_plugin_temp)) {
                $this->recursive_remove_directory($old_plugin_temp);
            }
            
            if (!rename($this->plugin_dir, $old_plugin_temp)) {
                $this->recursive_remove_directory($temp_dir);
                $this->disable_maintenance_mode();
                return new WP_Error('move_failed', 'Could not move current plugin directory');
            }
            
            // Move new version to plugin location
            if (!rename($extracted_plugin_dir, $this->plugin_dir)) {
                // Try to restore old version
                rename($old_plugin_temp, $this->plugin_dir);
                $this->recursive_remove_directory($temp_dir);
                $this->disable_maintenance_mode();
                return new WP_Error('install_failed', 'Could not install new plugin version');
            }
            
            // Clean up
            $this->recursive_remove_directory($old_plugin_temp);
            $this->recursive_remove_directory($temp_dir);
            
            // Disable maintenance mode
            $this->disable_maintenance_mode();
            
            // Clear update lock
            delete_transient('idem_update_lock');
            
            return true;
            
        } catch (Exception $e) {
            $this->disable_maintenance_mode();
            delete_transient('idem_update_lock');
            return new WP_Error('install_exception', $e->getMessage());
        }
    }
    
    /**
     * Verify the update was successful
     */
    private function verify_update($expected_version) {
        // Check if main plugin file exists
        if (!file_exists($this->plugin_file)) {
            return new WP_Error('plugin_file_missing', 'Main plugin file is missing after update');
        }
        
        // Check version in plugin header
        $plugin_data = get_file_data($this->plugin_file, array('Version' => 'Version'));
        if ($plugin_data['Version'] !== $expected_version) {
            return new WP_Error('version_mismatch', 'Updated version does not match expected version');
        }
        
        // Check critical files exist
        $critical_files = array(
            '/includes/class-wc-gateway-idem.php',
            '/includes/class-wc-idem-api-client.php'
        );
        
        foreach ($critical_files as $file) {
            if (!file_exists($this->plugin_dir . $file)) {
                return new WP_Error('critical_file_missing', 'Critical file missing: ' . $file);
            }
        }
        
        // Try to load the main class to ensure no syntax errors
        if (!class_exists('WC_Gateway_Idem')) {
            include_once($this->plugin_dir . '/includes/class-wc-gateway-idem.php');
            if (!class_exists('WC_Gateway_Idem')) {
                return new WP_Error('class_load_failed', 'Could not load main gateway class');
            }
        }
        
        return true;
    }
    
    /**
     * Rollback to previous version
     */
    public function rollback($backup_path) {
        if (!file_exists($backup_path)) {
            return new WP_Error('backup_not_found', 'Backup file not found');
        }
        
        $this->log('Starting rollback from: ' . $backup_path);
        
        // Enable maintenance mode
        $this->enable_maintenance_mode();
        
        try {
            // Remove current (failed) version
            if (is_dir($this->plugin_dir)) {
                $this->recursive_remove_directory($this->plugin_dir);
            }
            
            // Extract backup
            $unzip_result = unzip_file($backup_path, dirname($this->plugin_dir));
            
            if (is_wp_error($unzip_result)) {
                $this->disable_maintenance_mode();
                return $unzip_result;
            }
            
            // Disable maintenance mode
            $this->disable_maintenance_mode();
            
            // Clear update lock
            delete_transient('idem_update_lock');
            
            $this->log('Rollback completed successfully');
            
            return true;
            
        } catch (Exception $e) {
            $this->disable_maintenance_mode();
            delete_transient('idem_update_lock');
            return new WP_Error('rollback_exception', $e->getMessage());
        }
    }
    
    /**
     * Clean up old backups (keep last 3)
     */
    private function cleanup_old_backups() {
        $backups = glob($this->backup_dir . '/idem-backup-*.zip');
        
        if (count($backups) > 3) {
            // Sort by modification time
            usort($backups, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest backups
            $to_remove = count($backups) - 3;
            for ($i = 0; $i < $to_remove; $i++) {
                @unlink($backups[$i]);
                $this->log('Removed old backup: ' . basename($backups[$i]));
            }
        }
    }
    
    /**
     * Clear various caches
     */
    private function clear_caches() {
        // Clear object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Clear opcache if available
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        // Clear WooCommerce caches
        if (class_exists('WC_Cache_Helper')) {
            WC_Cache_Helper::get_transient_version('shipping', true);
            WC_Cache_Helper::get_transient_version('product', true);
        }
    }
    
    /**
     * Send update notification email
     */
    private function send_update_notification($status, $message) {
        $to = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        switch ($status) {
            case 'success':
                $subject = sprintf('[%s] IDEM Plugin Updated Successfully', $site_name);
                $body = "The IDEM Payment Gateway plugin has been successfully updated.\n\n";
                break;
            
            case 'failed':
                $subject = sprintf('[%s] IDEM Plugin Update Failed', $site_name);
                $body = "The IDEM Payment Gateway plugin update failed.\n\n";
                break;
            
            case 'critical':
                $subject = sprintf('[%s] CRITICAL: IDEM Plugin Update Failed', $site_name);
                $body = "CRITICAL: The IDEM Payment Gateway plugin update failed and rollback also failed.\n\n";
                $body .= "IMMEDIATE ACTION REQUIRED: Please manually restore the plugin.\n\n";
                break;
            
            default:
                return;
        }
        
        $body .= "Details: " . $message . "\n\n";
        $body .= "Time: " . current_time('mysql') . "\n";
        $body .= "Site URL: " . home_url() . "\n";
        
        wp_mail($to, $subject, $body);
    }
    
    /**
     * Enable maintenance mode
     */
    private function enable_maintenance_mode() {
        $maintenance_file = ABSPATH . '.maintenance';
        $maintenance_content = '<?php $upgrading = ' . time() . '; ?>';
        file_put_contents($maintenance_file, $maintenance_content);
    }
    
    /**
     * Disable maintenance mode
     */
    private function disable_maintenance_mode() {
        $maintenance_file = ABSPATH . '.maintenance';
        if (file_exists($maintenance_file)) {
            @unlink($maintenance_file);
        }
    }
    
    /**
     * Ensure backup directory exists
     */
    private function ensure_backup_directory() {
        if (!is_dir($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            
            // Add .htaccess to prevent direct access
            $htaccess_content = "Deny from all\n";
            file_put_contents($this->backup_dir . '/.htaccess', $htaccess_content);
            
            // Add index.php for extra security
            $index_content = "<?php // Silence is golden\n";
            file_put_contents($this->backup_dir . '/index.php', $index_content);
        }
    }
    
    /**
     * Recursively add directory to zip
     */
    private function add_directory_to_zip($zip, $dir, $base_path = '') {
        $dir = rtrim($dir, '/');
        
        if (is_dir($dir)) {
            $files = scandir($dir);
            
            foreach ($files as $file) {
                if ($file == '.' || $file == '..') {
                    continue;
                }
                
                $file_path = $dir . '/' . $file;
                $zip_path = $base_path ? $base_path . '/' . $file : $file;
                
                if (is_dir($file_path)) {
                    $zip->addEmptyDir($zip_path);
                    $this->add_directory_to_zip($zip, $file_path, $zip_path);
                } else {
                    $zip->addFile($file_path, $zip_path);
                }
            }
        }
    }
    
    /**
     * Recursively remove directory
     */
    private function recursive_remove_directory($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->recursive_remove_directory($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
    
    /**
     * Find plugin directory in extracted files
     */
    private function find_plugin_directory($extract_dir) {
        // Check if the extracted content is directly the plugin
        if (file_exists($extract_dir . '/woocommerce-gateway-idem.php')) {
            return $extract_dir;
        }
        
        // Check one level deep (common in zip files)
        $dirs = glob($extract_dir . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            if (file_exists($dir . '/woocommerce-gateway-idem.php')) {
                return $dir;
            }
        }
        
        return new WP_Error('plugin_not_found', 'Could not find plugin files in extracted archive');
    }
    
    /**
     * Log message
     */
    private function log($message, $level = 'info') {
        if ($this->logger) {
            $context = array('source' => 'idem-auto-update');
            $this->logger->log($level, $message, $context);
        }
        
        // Also log to custom file for easier debugging
        $log_file = $this->backup_dir . '/auto-update.log';
        $timestamp = current_time('mysql');
        $log_entry = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($level), $message);
        
        @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Deactivation cleanup
     */
    public function deactivate() {
        // Clear scheduled cron
        $timestamp = wp_next_scheduled('wc_idem_auto_update_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wc_idem_auto_update_check');
        }
        
        // Clear any update locks
        delete_transient('idem_update_lock');
    }
    
    /**
     * Manual update trigger (for admin interface)
     */
    public function manual_update() {
        // Set a flag to bypass the auto-update check
        set_transient('idem_manual_update', true, 60);
        
        // Run the update check
        $this->check_and_update();
        
        // Clear the flag
        delete_transient('idem_manual_update');
    }
    
    /**
     * Get update status for admin display
     */
    public function get_update_status() {
        $status = array(
            'current_version' => $this->current_version,
            'latest_version' => null,
            'update_available' => false,
            'last_check' => get_option('idem_last_update_check', 0),
            'auto_update_enabled' => $this->is_auto_update_enabled(),
            'backups' => array()
        );
        
        // Get latest version
        $latest = $this->get_latest_version();
        if (!is_wp_error($latest)) {
            $status['latest_version'] = $latest;
            $status['update_available'] = version_compare($this->current_version, $latest, '<');
        }
        
        // Get backup list
        $backups = glob($this->backup_dir . '/idem-backup-*.zip');
        if ($backups) {
            foreach ($backups as $backup) {
                $status['backups'][] = array(
                    'file' => basename($backup),
                    'size' => size_format(filesize($backup)),
                    'date' => date('Y-m-d H:i:s', filemtime($backup))
                );
            }
        }
        
        // Update last check time
        update_option('idem_last_update_check', time());
        
        return $status;
    }
}