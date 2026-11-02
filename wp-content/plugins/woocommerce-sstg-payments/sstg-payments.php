<?php
/**
 * Plugin Name: SSTG Payments
 * Plugin URI:  https://sstgateway.com/
 * Description: Accept payments via SSTG Payments for WooCommerce.
 * Version:     1.0.9
 * Author:      SSTG Payments
 * Author URI:  https://sstgateway.com
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

/*
 * CHANGE LOG
 * v1.0.0 Initial build
 * v1.0.1 Removed URL Field
 * v1.0.2 Fixed refund processing to display error for pending.
 * v1.0.3 3DS Handling in a new window to avoid x-frame restrictions.
 * v1.0.4 Fixed API URL
 * v1.0.5 Enhanced cart-reference-id management to prevent transactions without orders.
 * v1.0.6 Removed duplicate orders and auto cancellations on legacy checkouts.
 * v1.0.7 Added Pending Refund handling and transaction ID recording after refund bugfix.
 * v1.0.8 Fixed stale amount invoice creation and added dynamic checks to update for any discounts/changes etc.
 * v1.0.9 Fixed a clickjacking bug which was making this plugin incompatible with other pay methods that existed.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define( 'SSTG_PAYMENTS_VERSION', '3.0.0' );
define( 'SSTG_PAYMENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSTG_PAYMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

use YahnisElsts\PluginUpdateChecker\v5\PucFactory as PucFactoryAlias;

/**
 * Declare compatibility with WooCommerce Blocks.
 */
add_action( 'before_woocommerce_init', 'sstg_payments_declare_block_compatibility' );
function sstg_payments_declare_block_compatibility() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__
        );
    }
}

// Ensure WooCommerce is active
add_action( 'plugins_loaded', 'sstg_payments_init', 0 );
function sstg_payments_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', 'sstg_payments_admin_notice' );
        function sstg_payments_admin_notice() {
            echo '<div class="notice notice-warning"><p>' . __( 'WooCommerce is not active. Please activate it to use SSTG Payments.', 'sstg-payments' ) . '</p></div>';
        }
        return;
    }

    require_once plugin_dir_path( __FILE__ ) . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';

    $myUpdateChecker = PucFactoryAlias::buildUpdateChecker(
        'https://updates.sstgusa.com/wp/woocommerce-sstg-payments/woocommerce-sstg-payments.json',
        __FILE__,
        'woocommerce-sstg-payments'
    );

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', 'sstgateway_core_payments_admin_notice' );
        function sstgateway_core_payments_admin_notice() {
            echo '<div class="notice notice-warning"><p>' . __( 'WooCommerce is not active. Please activate it to use SSTGateway Core Payments.', 'sstgateway-core-payments' ) . '</p></div>';
        }
        return;
    }

    require_once SSTG_PAYMENTS_PLUGIN_DIR . 'includes/class-wc-gateway-sstg-payments.php';
    require_once SSTG_PAYMENTS_PLUGIN_DIR . 'includes/class-wc-sstg-payments-blocks-support.php';

    /**
     * Add the gateway to WooCommerce
     */
    add_filter( 'woocommerce_payment_gateways', 'add_sstg_payments_gateway' );
    function add_sstg_payments_gateway( $methods ) {
        $methods[] = 'WC_Gateway_SSTG_Payments';
        return $methods;
    }

    add_action( 'wp_enqueue_scripts', 'sstg_payments_load_scripts' );
    function sstg_payments_load_scripts() {
        $asset_filepath = SSTG_PAYMENTS_PLUGIN_DIR . 'build/index.asset.php';
        $asset_file = file_exists( $asset_filepath ) ? require $asset_filepath : array(
            'dependencies' => array(),
            'version'      => SSTG_PAYMENTS_VERSION,
        );

        $deps = array_merge(
            $asset_file['dependencies'],
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element' )
        );

        wp_register_script(
            'sstg-payments-blocks-integration',
            SSTG_PAYMENTS_PLUGIN_URL . 'build/index.js',
            $deps,
            filemtime( SSTG_PAYMENTS_PLUGIN_DIR . 'build/index.js' ),
            true
        );

        $gateway = new WC_Gateway_SSTG_Payments();
        wp_localize_script( 'sstg-payments-blocks-integration', 'sstgPaymentsData', array(
            'baseUrl' => esc_url( home_url() ),
            'restUrl' => esc_url_raw( rest_url( 'sstg-payments/v1/' ) ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    /**
     * Register the blocks' integration.
     */
    add_action( 'woocommerce_blocks_payment_method_type_registration', 'register_sstg_payments_block_support' );
    function register_sstg_payments_block_support( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
        $payment_method_registry->register( new WC_SSTG_Payments_Blocks_Support() );
    }

    // REST API routes
    add_action( 'rest_api_init', function () {
        register_rest_route( 'sstg-payments/v1', '/create-invoice', array(
            'methods'  => 'POST',
            'callback' => function ( WP_REST_Request $request ) {
                $gateway = new WC_Gateway_SSTG_Payments();
                return $gateway->rest_create_invoice( $request );
            },
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'sstg-payments/v1', '/process-payment', array(
            'methods'  => 'POST',
            'callback' => function ( WP_REST_Request $request ) {
                $gateway = new WC_Gateway_SSTG_Payments();
                return $gateway->rest_process_payment( $request );
            },
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'sstg-payments/v1', '/complete-order', array(
            'methods'  => 'POST',
            'callback' => function ( WP_REST_Request $request ) {
                $gateway = new WC_Gateway_SSTG_Payments();
                return $gateway->rest_complete_order( $request );
            },
            'permission_callback' => '__return_true',
        ) );
    } );
}