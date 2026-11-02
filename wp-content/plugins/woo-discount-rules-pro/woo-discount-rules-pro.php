<?php
/**
 * Plugin name: Discount Rules PRO 2.0
 * Plugin URI: https://www.flycart.org
 * Description: PRO package for Discount Rules. You need both the Core and PRO packages to get the PRO features running.
 * Author: Flycart
 * Author URI: https://www.flycart.org
 * Version: 2.6.18
 * Slug: woo-discount-rules-pro
 * Text Domain: woo-discount-rules-pro
 * Domain Path: /i18n/languages/
 * Requires at least: 6.2
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 * License: GPLv2 or later
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Current version of our app
 */
if (!defined('WDR_PRO_VERSION')) {
    define('WDR_PRO_VERSION', '2.6.18');
}

/**
 * The plugin Text Domain
 */
if (!defined('WDR_PRO_TEXT_DOMAIN')) {
    define('WDR_PRO_TEXT_DOMAIN', 'woo-discount-rules-pro');
}
/**
 * The plugin path
 */
if (!defined('WDR_PRO_PLUGIN_PATH')) {
    define('WDR_PRO_PLUGIN_PATH', plugin_dir_path(__FILE__));
}
/**
 * The plugin url
 */
if (!defined('WDR_PRO_PLUGIN_URL')) {
    define('WDR_PRO_PLUGIN_URL', plugin_dir_url(__FILE__));
}

/**
 * Core version
 */
if (!defined('WDR_PRO')) {
    define('WDR_PRO', true);
}

if(!function_exists('wdrpro_init')){
    function wdrpro_init(){
        require_once __DIR__ . "/vendor/autoload.php";
        if(!did_action('advanced_woo_discount_rules_pro_loaded')){
            do_action('advanced_woo_discount_rules_pro_loaded');
        }
    }
}
add_action('advanced_woo_discount_rules_before_loaded', function (){
    wdrpro_init();
}, 1);
/**
 * Check the woo discount rules is active or not
 * @return bool
 */
if(!function_exists('wdrpro_is_core_plugin_active')){
    function wdrpro_is_core_plugin_active()
    {
        $active_plugins = apply_filters('active_plugins', get_option('active_plugins', array()));//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying WordPress core filter
        if (is_multisite()) {
            $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
        }
        return in_array('woo-discount-rules/woo-discount-rules.php', $active_plugins, false) || array_key_exists('woo-discount-rules/woo-discount-rules.php', $active_plugins);
    }
}
if(!function_exists('wdrpro_load_core_missing_html')){
    function wdrpro_load_core_missing_html(){
        echo '<div class="woo_discount_loader_outer">
            <div class="wdr-main">
                <h2 style="font-size: 18px;">'.esc_html__('Woo Discount Rules 2.0', 'woo-discount-rules-pro').'</h2>';
                ?>
                <p class="wdr-core-missing" style="font-size: 16px;">
                    <?php esc_html_e('Since 2.0, you need both the core and pro packages installed and activated.', 'woo-discount-rules-pro'); ?>
                </p>
                <p class="wdr-core-missing" style="font-size: 16px;">
                    <?php esc_html_e('Why we made this change?', 'woo-discount-rules-pro'); ?>
                </p>
                <p class="wdr-core-missing" style="font-size: 16px;">
                    <?php esc_html_e('This arrangement is to avoid the confusion in the installation and upgrade process. Many users first install the core free version. Then purchase the PRO version and try to install it over the free version. Since both free and pro packages have same names, wordpress asks them to uninstall free and then install pro. As you can see, this is quite confusing for the end users.', 'woo-discount-rules-pro'); ?>
                </p>
                <p class="wdr-core-missing" style="font-size: 16px;">
                    <?php esc_html_e('As a result, starting from 2.0, we now have two packs: 1. Core 2. PRO.', 'woo-discount-rules-pro'); ?>
                </p>
                <p class="wdr-core-missing" style="font-size: 16px;">
                    <?php esc_html_e('What do I need to do?', 'woo-discount-rules-pro'); ?>
                </p>
                <p class="wdr-core-missing" style="font-size: 14px;">
                    <?php esc_html_e(' - Just install both and activate both Core and Pro packs.', 'woo-discount-rules-pro');
                    $url = admin_url()."plugin-install.php?tab=plugin-information&plugin=woo-discount-rules&TB_iframe=true&width=600&height=550";
                    ?>
                    <a href="<?php echo esc_url($url); ?>" target="_blank"><?php esc_html_e('Install core version', 'woo-discount-rules-pro'); ?></a>
                </p>
                <p class="wdr-core-missing" style="font-size: 16px;">
                    <?php esc_html_e('Simple and straight-forward (no uninstalls and re-installs).', 'woo-discount-rules-pro'); ?>
                </p>
                <?php
        echo '</div>
        </div>';
    }
}
if (defined('WDR_CORE')) {
    wdrpro_init();
}else{
    if(!wdrpro_is_core_plugin_active()){
        add_action('admin_menu', function (){
            if (!is_admin()) return;
            global $submenu;
            if (isset($submenu['woocommerce'])) {
                add_submenu_page(
                    'woocommerce',
                    __('Discount Rules', 'woo-discount-rules-pro'),
                    __('Discount Rules', 'woo-discount-rules-pro'),
                    'manage_woocommerce', 'woo_discount_rules',
                    'wdrpro_load_core_missing_html'
                );
            }
        });
    }
}

/**
 * For plugin translation
 * */
/*add_action( 'plugins_loaded', function (){
    if(function_exists('load_plugin_textdomain')){
        load_plugin_textdomain( 'woo-discount-rules-pro', FALSE, basename( dirname( __FILE__ ) ) . '/i18n/languages/' );
    }
});*/

/**
 * To set plugin is compatible for WC Custom Order Table (HPOS) feature.
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * To remove 'View details' link from plugin row on plugins page.
 */
add_filter('plugin_row_meta', function ($plugin_meta, $plugin_file) {
    if ($plugin_file == 'woo-discount-rules-pro/woo-discount-rules-pro.php') {
        foreach ($plugin_meta as $key => $meta) {
            if (strpos($meta, 'open-plugin-details-modal') !== false) {
                unset($plugin_meta[$key]);
                break;
            }
        }
    }
    return $plugin_meta;
}, 1000, 2);
