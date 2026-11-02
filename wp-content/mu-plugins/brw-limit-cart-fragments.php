<?php
/**
 * Plugin Name: BRW Limit Woo Cart Fragments
 * Description: Reduces wc-ajax=get_refreshed_fragments load without touching checkout/cart behavior.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('woocommerce_get_script_data', function ($script_data, $handle) {
    if ($handle !== 'wc-cart-fragments') {
        return $script_data;
    }

    if (is_admin()) {
        return $script_data;
    }

    if (function_exists('is_cart') && is_cart()) {
        return $script_data;
    }

    if (function_exists('is_checkout') && is_checkout()) {
        return $script_data;
    }

    if (function_exists('is_account_page') && is_account_page()) {
        return $script_data;
    }

    if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
        return $script_data;
    }

    return null;
}, 10, 2);