<?php
/**
 * PHPUnit bootstrap for Link Money integration tests.
 *
 * Loads the WordPress test suite, activates WooCommerce and the Link Money
 * plugin, and requires the test helpers.
 */

// Composer autoloader (for PHPUnit polyfills and test classes).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Locate the WordPress test suite. Allow override via env for CI.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find WordPress test suite at '{$_tests_dir}'." . PHP_EOL;
    echo "Set WP_TESTS_DIR env or run bin/install-wp-tests.sh first." . PHP_EOL;
    exit( 1 );
}

// Load the WP test suite functions (tests_add_filter, etc.) before using them.
require_once $_tests_dir . '/includes/functions.php';

// Give tests access to the plugin directory constant (needed by linkmoney.php).
define( 'LINKMONEY_TEST_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

/**
 * Load WooCommerce and our plugin during muplugins_loaded.
 *
 * This fires BEFORE plugins_loaded, so linkmoney_init_gateway() (which hooks
 * into plugins_loaded at priority 0) will still fire and load the gateway class.
 */
tests_add_filter( 'muplugins_loaded', function() {
    // Load WooCommerce.
    $wc_plugin = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
    if ( ! file_exists( $wc_plugin ) ) {
        echo "WooCommerce plugin not found at '{$wc_plugin}'." . PHP_EOL;
        exit( 1 );
    }
    require_once $wc_plugin;

    // Load Link Money plugin. Its plugins_loaded hook will fire normally.
    require_once LINKMONEY_TEST_PLUGIN_DIR . 'linkmoney.php';
} );

/**
 * After WP is fully loaded, run the WooCommerce install routine to create
 * database tables (wc_webhooks, wc_orders, etc.) that tests depend on.
 */
tests_add_filter( 'setup_theme', function() {
    WC_Install::install();
} );

// Load the WordPress test suite bootstrap. This sets up the DB and WP.
require $_tests_dir . '/includes/bootstrap.php';

// Load test helpers.
require_once __DIR__ . '/Helpers/MockHttpTrait.php';
require_once __DIR__ . '/Helpers/OrderHelper.php';
