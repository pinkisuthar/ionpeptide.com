<?php
/**
 * Plugin Name: 3thix Payments for WooCommerce
 * Plugin URI: https://3thix.com
 * Description: Accept card payments (or fiat→USDC on-ramp) through 3thix. Charges the exact WooCommerce cart total via an embedded checkout that keeps buyers on your store (or a hosted redirect), marks orders paid via a signed callback, and syncs shipment tracking back to 3thix so escrowed funds release in one business day.
 * Version: 1.8.4
 * Author: 3thix
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 * Text Domain: thix-payments
 *
 * @package Thix_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

define('THIX_PAYMENTS_VERSION', '1.8.4');
define('THIX_PAYMENTS_FILE', __FILE__);
define('THIX_PAYMENTS_DIR', plugin_dir_path(__FILE__));

/**
 * Self-hosted auto-update wiring. Loaded unconditionally (NOT gated on
 * WooCommerce) so a store keeps receiving plugin updates even if WooCommerce is
 * temporarily deactivated. Only registers admin/cron update filters — no
 * front-end side effects.
 */
require_once THIX_PAYMENTS_DIR . 'includes/class-thix-api.php';
require_once THIX_PAYMENTS_DIR . 'includes/class-thix-updater.php';
Thix_Updater::init();

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            THIX_PAYMENTS_FILE,
            true
        );
    }
});

/**
 * Bootstrap: only load the gateway once WooCommerce is present.
 */
add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('3thix Payments requires WooCommerce to be installed and active.', 'thix-payments')
                . '</p></div>';
        });
        return;
    }

    require_once THIX_PAYMENTS_DIR . 'includes/class-thix-api.php';
    require_once THIX_PAYMENTS_DIR . 'includes/class-thix-gateway.php';
    require_once THIX_PAYMENTS_DIR . 'includes/class-thix-callback.php';
    require_once THIX_PAYMENTS_DIR . 'includes/class-thix-auto-tracking.php';
    require_once THIX_PAYMENTS_DIR . 'includes/class-thix-auto-digital.php';
    require_once THIX_PAYMENTS_DIR . 'includes/class-thix-tracking.php';
    require_once THIX_PAYMENTS_DIR . 'includes/class-thix-reconcile.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'Thix_Gateway';
        return $gateways;
    });

    Thix_Callback::init();
    Thix_Auto_Tracking::init();
    Thix_Auto_Digital::init();
    Thix_Tracking::init();
    Thix_Reconcile::init();
});

/**
 * Native WooCommerce Blocks (Gutenberg checkout block) payment integration —
 * gives block-checkout stores the same in-page experience as classic checkout,
 * including the inline secure card form. No-ops when Blocks isn't available.
 */
add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    require_once THIX_PAYMENTS_DIR . 'includes/class-thix-blocks-support.php';
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new Thix_Blocks_Support());
    });
});

/**
 * Clear the background reconcile cron on deactivation so we don't leave an
 * orphaned scheduled event behind.
 */
register_deactivation_hook(__FILE__, function () {
    require_once THIX_PAYMENTS_DIR . 'includes/class-thix-reconcile.php';
    if (class_exists('Thix_Reconcile')) {
        Thix_Reconcile::clear();
    }
});

/**
 * Settings link on the plugins list row.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=thix');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'thix-payments') . '</a>');
    return $links;
});
