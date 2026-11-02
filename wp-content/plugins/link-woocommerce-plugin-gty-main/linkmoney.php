<?php
/**
 * Plugin Name: WooCommerce - Secure Bank Payments via Link Money (US Only)
 * Description: Allow your customers to pay via secure bank transfer, powered by Link Money.
 * Version: 1.3.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.1
 * Author: LinkMoney
 * Author URI: https://link.money
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: linkmoney
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 *
 * Use prefix "linkmoney_" everywhere in the plugin for security and compatibility
 * Current plugin version.
 *
 */
define( 'LINKMONEY_VERSION', '1.3.1' );
define( 'LINKMONEY_PLUGIN_FILE', __FILE__ );
define( 'LINKMONEY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINKMONEY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * 1. FIX: Load Translations (Prevents the Log Error)
 */
add_action( 'init', 'linkmoney_load_translations' );
function linkmoney_load_translations() {
    load_plugin_textdomain( 'linkmoney', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}


/**
 * Register Custom Post Status
 */
add_action( 'init', function() {
    register_post_status( 'wc-awaiting-payment', [
        'label'                     => _x( 'Awaiting Payment Confirmation', 'Order status', 'linkmoney' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop(
            'Awaiting Payment Confirmation <span class="count">(%s)</span>',
            'Awaiting Payment Confirmation <span class="count">(%s)</span>',
            'linkmoney'
        ),
    ] );
}, 0 );


add_filter( 'wc_order_statuses', function( $order_statuses ) {
    $new = [];
    foreach ( $order_statuses as $key => $label ) {
        $new[ $key ] = $label;
        if ( 'wc-pending' === $key ) {
            $new['wc-awaiting-payment'] = __( 'Awaiting Payment Confirmation', 'linkmoney' );
        }
    }
    return $new;
} );


add_filter( 'views_edit-shop_order', function( $views ) {
    global $wpdb;

    // 1) Get the live count of orders in our custom status
    $awaiting = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type = %s
           AND post_status = %s",
        'shop_order',
        'wc-awaiting-payment'
    ) );


    $current = ( isset( $_GET['post_status'] ) && $_GET['post_status'] === 'wc-awaiting-payment' )
        ? 'current'
        : '';


    $url   = admin_url( 'edit.php?post_type=shop_order&post_status=wc-awaiting-payment' );
    $label = sprintf(
        '<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
        esc_url( $url ),
        esc_attr( $current ),
        __( 'Awaiting Payment Confirmation', 'linkmoney' ),
        $awaiting
    );


    $new = [];
    foreach ( $views as $key => $html ) {
        $new[ $key ] = $html;
        if ( 'wc-pending' === $key ) {
            $new['wc-awaiting-payment'] = $label;
        }
    }

    return $new;
}, 20 );

add_filter( 'woocommerce_shop_order_query', function( $args ) {
    if (
        isset( $_GET['post_status'] ) &&
        $_GET['post_status'] === 'wc-awaiting-payment'
    ) {
        // Must be an array, not a plain string
        $args['post_status'] = [ 'wc-awaiting-payment' ];
    }
    return $args;
}, 20 );


/**
* Run logic earlier (Priority 0 instead of 11) to beat conflicting plugin
*/
add_action( 'plugins_loaded', 'linkmoney_init_gateway', 0 );
function linkmoney_init_gateway() {

    // WooCommerce not active? Bail out.
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', 'linkmoney_wc_missing_notice' );
        return;
    }

    require_once LINKMONEY_PLUGIN_DIR . 'includes/class-linkmoney-payment-lock.php';
    require_once LINKMONEY_PLUGIN_DIR . 'includes/class-wc-gateway-linkmoney.php';
    require_once LINKMONEY_PLUGIN_DIR . 'includes/class-wc-gateway-linkmoney-blocks.php';
    add_action( 'rest_api_init', [ 'WC_Gateway_LinkMoney', 'register_webhook_endpoint' ] );

    // Register the deferred abandon-check handler at module load so Action
    // Scheduler can dispatch it from cron contexts where the gateway hasn't
    // been instantiated.
    add_action( 'linkmoney_delayed_abandon_check', [ 'WC_Gateway_LinkMoney', 'handle_delayed_abandon_check' ] );

    // Register cart-restore handler early — the gateway constructor may not
    // run before template_redirect, so we hook it here explicitly.
    add_action( 'template_redirect', function() {
        if ( ! class_exists( 'WC_Gateway_LinkMoney' ) || ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        // Explicit restore-by-URL (Link Money redirect back on cancel).
        if ( ! empty( $_GET['lm_restore_order'] ) && ! empty( $_GET['lm_restore_key'] ) ) {
            ( new WC_Gateway_LinkMoney() )->maybe_restore_cart_from_order();
            return;
        }

        // Browser Back from the Link Money hosted page lands on the bare checkout
        // URL with no restore param. Recover the cart from the stashed draft order
        // on the checkout/cart pages only (skip the order-received page).
        if (
            empty( WC()->session->get( WC_Gateway_LinkMoney::SESSION_PENDING_RESTORE_ORDER ) )
            || ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
        ) {
            return;
        }

        if ( ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() ) ) {
            ( new WC_Gateway_LinkMoney() )->maybe_recover_pending_checkout();
        }
    }, 5 );

    //Priority 999 ensures we add ourselves LAST, overriding conflicts
    add_filter( 'woocommerce_payment_gateways', 'linkmoney_add_gateway', 999 );

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( $registry ) {
            $registry->register( new WC_Gateway_LinkMoney_Blocks() );
        }
    );
}

function linkmoney_add_gateway( $methods ) {
    $methods[] = 'WC_Gateway_LinkMoney';
    return $methods;
}

function linkmoney_wc_missing_notice() {
    echo '<div class="error"><p><strong>Link Money</strong> requires WooCommerce to be active.</p></div>';
}

function declare_plugin_compatibility() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
}
add_action( 'before_woocommerce_init', 'declare_plugin_compatibility' );


register_activation_hook( __FILE__, 'linkmoney_activate' );
function linkmoney_activate() { flush_rewrite_rules(); }

register_deactivation_hook( __FILE__, 'linkmoney_deactivate' );
function linkmoney_deactivate() { flush_rewrite_rules(); }


/**
 * Show Link Money IDs on the Order Edit screen.
 */

add_action( 'woocommerce_admin_order_data_after_order_details', function( $order ) {
    if ( $order->get_payment_method() !== 'linkmoney' ) {
        return;
    }

    $payment_id  = $order->get_meta( '_linkmoney_payment_id' );
    $customer_id = get_user_meta($order->get_user_id(),'_linkmoney_customer_id',  true);
    if ( ! $payment_id && ! $customer_id ) {
        return;
    }
    echo '<div class="order_data_column" style="width:100%">';
    echo '<h4>' . esc_html__( 'Link Money Details', 'linkmoney' ) . '</h4>';
    if ( $payment_id ) {
        echo '<p><strong>' . esc_html__( 'Payment ID:', 'linkmoney' ) . '</strong> <input type="text" readonly style="width:100%;" value="'. esc_html( $payment_id ) .'" id="_linkmoney_payment_id"></p>';
    }
    if ( $customer_id ) {
        echo '<p><strong>' . esc_html__( 'Customer ID:', 'linkmoney' ) . '</strong> <input type="text" readonly style="width:100%;" value="'
            . esc_html( $customer_id ) . '" id="_linkmoney_customer_id"</p>';
    }
    echo '</div>';
} );


add_action( 'init', function() {

    add_filter( 'gettext', function( $translated, $original, $domain ) {
        if (
            $domain === 'woocommerce'
            && $original === 'Your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.'
        ) {
            return 'Your order was not processed as the originating bank/merchant declined the transaction.';
        }
        return $translated;
    }, 10, 3 );
});
