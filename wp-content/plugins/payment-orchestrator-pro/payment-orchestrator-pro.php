<?php
/**
 * Plugin Name: Payment Orchestrator Pro
 * Description: Smart payment gateway load balancing for WooCommerce. Transaction-based rotation, monthly pacing, and automatic failover.
 * Version: 2.7.35
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Crafters
 * License: GPL v2 or later
 * Text Domain: payment-orchestrator
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator;

defined('ABSPATH') || exit;

// Version and paths
define('PO_VERSION', '2.7.35');
define('PO_PLUGIN_FILE', __FILE__);
define('PO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4 style autoloader
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'PaymentOrchestrator\\';
    $baseDir = PO_PLUGIN_DIR . 'includes/';
    
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Check plugin requirements
 */
function po_check_requirements(): bool {
    $errors = [];
    
    if (!class_exists('WooCommerce')) {
        $errors[] = 'WooCommerce must be installed and active.';
    }
    
    if (version_compare(PHP_VERSION, '8.2', '<')) {
        $errors[] = 'PHP 8.2 or higher is required. Current version: ' . PHP_VERSION;
    }
    
    if (!empty($errors)) {
        add_action('admin_notices', static function () use ($errors): void {
            foreach ($errors as $error) {
                printf(
                    '<div class="notice notice-error"><p><strong>Payment Orchestrator Pro:</strong> %s</p></div>',
                    esc_html($error)
                );
            }
        });
        return false;
    }
    
    return true;
}

/**
 * Initialize plugin
 */
function po_init(): void {
    if (!po_check_requirements()) {
        return;
    }
    
    try {
        $container = new Core\Container();
        $plugin = $container->get(Core\Plugin::class);
        $plugin->boot();
    } catch (\Throwable $e) {
        Core\Logger::error('Plugin initialization failed', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        
        add_action('admin_notices', static function () use ($e): void {
            printf(
                '<div class="notice notice-error"><p><strong>Payment Orchestrator Pro:</strong> Initialization failed. Check the debug log for details.</p></div>'
            );
        });
    }
}
add_action('plugins_loaded', 'PaymentOrchestrator\\po_init', 20);

/**
 * Activation hook
 */
register_activation_hook(__FILE__, static function (): void {
    if (!class_exists('WooCommerce')) {
        wp_die('Payment Orchestrator Pro requires WooCommerce to be installed and active.');
    }
    
    require_once PO_PLUGIN_DIR . 'includes/Core/Activator.php';
    Core\Activator::activate();
});

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, static function (): void {
    require_once PO_PLUGIN_DIR . 'includes/Core/Activator.php';
    Core\Activator::deactivate();
});

/**
 * Uninstall hook - registered via uninstall.php
 */

/**
 * HPOS compatibility declaration
 */
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
