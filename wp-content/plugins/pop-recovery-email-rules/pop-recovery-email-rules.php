<?php
/**
 * Plugin Name: POP Recovery Email Rules
 * Description: Automated single-email recovery for failed payments. Integrates with Payment Orchestrator Pro to drive customers back to complete checkout.
 * Version: 1.5.10
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Crafters
 * License: GPL v2 or later
 * Text Domain: pop-recovery-email
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 *
 * @package POPRecoveryEmail
 */

declare(strict_types=1);

namespace POPRecoveryEmail;

defined('ABSPATH') || exit;

define('PRER_VERSION', '1.5.10');
define('PRER_PLUGIN_FILE', __FILE__);
define('PRER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PRER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PRER_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4 autoloader
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'POPRecoveryEmail\\';
    $baseDir = PRER_PLUGIN_DIR . 'includes/';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

function prer_init(): void {
    if (!class_exists(\PaymentOrchestrator\Recovery\FailureHandler::class)) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p><strong>POP Recovery Email Rules</strong> requires Payment Orchestrator Pro v2.5.7 or later.</p></div>';
        });
        return;
    }
    if (!class_exists('WooCommerce')) {
        return;
    }
    try {
        $plugin = new Core\Plugin();
        $plugin->boot();
    } catch (\Throwable $e) {
        error_log('[POP Recovery Email] Init failed: ' . $e->getMessage());
    }
}
add_action('plugins_loaded', 'POPRecoveryEmail\\prer_init', 25);

/**
 * Apply database upgrades for in-place plugin updates (the activation hook does not
 * run when a plugin is updated, which is how the pay_url column went missing).
 */
add_action('plugins_loaded', static function (): void {
    try {
        Activator::maybeUpgrade();
    } catch (\Throwable $e) {
        error_log('[POP Recovery Email] Upgrade failed: ' . $e->getMessage());
    }
}, 20);

register_activation_hook(__FILE__, static function (): void {
    if (!class_exists(\PaymentOrchestrator\Recovery\FailureHandler::class)) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('POP Recovery Email Rules requires Payment Orchestrator Pro v2.5.7 or later.');
    }
    require_once PRER_PLUGIN_DIR . 'includes/Activator.php';
    Activator::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    require_once PRER_PLUGIN_DIR . 'includes/Activator.php';
    Activator::deactivate();
});

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
