<?php
/**
 * Plugin name: Discount Rules for WooCommerce
 * Plugin URI: https://www.flycart.org
 * Description: Simple to complex discount rules for your WooCommerce store. Core package.
 * Author: Flycart
 * Version: 2.6.18
 * Slug: woo-discount-rules
 * Text Domain: woo-discount-rules
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
 * To set plugin is compatible for WC Custom Order Table (HPOS) feature.
 */
add_action('before_woocommerce_init', function() {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});


/**
 * Current version of our app
 */
if (!defined('WDR_VERSION')) {
    define('WDR_VERSION', '2.6.18');
}

/**
 * Check discount rule v2 installed.
 */
if (class_exists('\WDR\Pro\Route')) {
	return;
}


global $awdr_load_version;

$awdr_load_version = get_option('advanced_woo_discount_rules_load_version', null);

if($awdr_load_version === null || empty($awdr_load_version)){
    $awdr_load_version = 'v1';
    /* Hide this for public beta release */
    if(function_exists('get_posts')){
        $awdr_rules = get_posts(array('post_type' => 'woo_discount', 'numberposts' => '1'));
        if(empty($awdr_rules)){
            $awdr_cart_rules = get_posts(array('post_type' => 'woo_discount_cart', 'numberposts' => '1'));
            if(empty($awdr_cart_rules)) $awdr_load_version = 'v2';
        }
    }
    update_option('advanced_woo_discount_rules_load_version', $awdr_load_version);
}

/**
 * Required PHP Version
 */
if (!defined('WDR_REQUIRED_PHP_VERSION')) {
    define('WDR_REQUIRED_PHP_VERSION', 7.4);
}

/**
 * Required Woocommerce Version
 */
if (!defined('WDR_WC_REQUIRED_VERSION')) {
    define('WDR_WC_REQUIRED_VERSION', '8.0.0');
}

/**
 * The plugin path
 */
if (!defined('WDR_PLUGIN_PATH')) {
    define('WDR_PLUGIN_PATH', plugin_dir_path(__FILE__) . $awdr_load_version . '/');
}

/**
 * The plugin base path
 */
if (!defined('WDR_PLUGIN_BASE_PATH')) {
    define('WDR_PLUGIN_BASE_PATH', plugin_dir_path(__FILE__));
}
/**
 * The plugin url
 */
if (!defined('WDR_PLUGIN_URL')) {
    define('WDR_PLUGIN_URL', plugin_dir_url(__FILE__) . $awdr_load_version . '/');
}
/**
 * Set base file URL
 */
if (!defined('WDR_PLUGIN_BASENAME')) {
    define('WDR_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

/**
 * The plugin Text Domain
 */
if (!defined('WDR_TEXT_DOMAIN')) {
    define('WDR_TEXT_DOMAIN', 'woo-discount-rules');
}
/**
 * The plugin Slug
 */
if (!defined('WDR_SLUG')) {
    define('WDR_SLUG', 'woo_discount_rules');
}
/**
 * The plugin prifix
 */
if (!defined('WDR_PLUGIN_PREFIX')) {
    define('WDR_PLUGIN_PREFIX', 'wdr_');
}

include_once(__DIR__ . "/common.php");
/**
 * Check and load plugin based on version
 */
if ($awdr_load_version == "v2") {
    /**
     * Core version
     */
    if (!defined('WDR_CORE')) {
        define('WDR_CORE', true);
    }

    /**
     *Package autoload
     */
    if (!file_exists(__DIR__ . "/{$awdr_load_version}/vendor/autoload.php")) {
        return false;
    } else {
        require __DIR__ . "/{$awdr_load_version}/vendor/autoload.php";
    }

    /**
     * Create required tables needed by v2
     */
    if (!function_exists('awdr_create_required_tables')) {
        function awdr_create_required_tables()
        {
            $awdr_current_version = get_option('advanced_woo_discount_rules_current_version', null);
            if($awdr_current_version === null || empty($awdr_current_version) || version_compare(WDR_VERSION, $awdr_current_version, '>')){
                $database = new \Wdr\App\Models\DBTable();
                $database->createDBTables();
                $database->updateDBTables();
                \Wdr\App\Helpers\Migration::checkForMigration();
                awdr_add_sample_rules();
                update_option('advanced_woo_discount_rules_current_version', WDR_VERSION);
            }
        }
    }

    /**
     * Add sample rules
     */
    if (!function_exists('awdr_add_sample_rules')) {
        function awdr_add_sample_rules()
        {
            \Wdr\App\Helpers\Migration::checkAndCreateSampleRules();
        }
    }

    /**
     * Check plugin dependency and init scheduler while activate plugin.
     */
    register_activation_hook(__FILE__, function () {
        awdr_check_compatible();
        awdr_create_required_tables();
        \Wdr\App\Helpers\Schedule::mayRunRebuildOnSaleIndex();
    });
    // clear scheduler while deactivate plugin
    register_deactivation_hook(__FILE__, function () {
        \Wdr\App\Helpers\Schedule::stopRebuildOnSaleIndex();
    });
	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['awdr_switch_plugin_to']) && in_array($_GET['awdr_switch_plugin_to'], array('v1', 'v2'))) {
	    //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if(is_admin() && $_GET['awdr_switch_plugin_to'] === "v2"){
            awdr_create_required_tables();
        }
    } else {
        add_action('admin_init', function () {
            awdr_create_required_tables();
        });
    }

    // This is required to load the pro events before core initialize
    add_action('plugins_loaded', function () {
        if ( class_exists( 'WooCommerce' ) ) {
            do_action('advanced_woo_discount_rules_before_loaded');
            new \Wdr\App\Router();
            do_action('advanced_woo_discount_rules_loaded');
        }
    }, 1);

} else {
    /**
     * Set base file URL
     */
    /*if (!defined('WOO_DISCOUNT_PLUGIN_BASENAME')) {
        define('WOO_DISCOUNT_PLUGIN_BASENAME', plugin_basename(__FILE__));
    }*/
    include_once(__DIR__ . "/v1/index.php");
}
