<?php

// We need to manually require here coz our autoloader is not executed
// Remember when uninstalling a plugin means the plugin is inactive, meaning our autoloader is not active.
require_once 'Traits/Singleton.php';
require_once 'Helpers/Plugin_Constants.php';

use ACFWP\Helpers\Plugin_Constants;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit();
}

/**
 * Function that houses the code that cleans up the plugin on un-installation.
 *
 * @since 2.0
 */
function acfwp_plugin_cleanup() {

    global $wpdb;

    $constants = Plugin_Constants::get_instance( null );

    // skip if the clean up setting is not enabled.
    if ( get_option( $constants->OPTION_CLEAN_UP_PLUGIN_OPTIONS ) !== 'yes' ) {
        return;
    }

    // Delete options.
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE 'acfwp\_%'
            OR option_name LIKE '%\_acfwp\_%';"
    );

    // Drop virtual coupons table.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}acfw_virtual_coupons" );
    delete_option( $constants->VIRTUAL_COUPONS_DB_CREATED );

    // Delete user, product, coupon, categories and order metas.
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '%\_acfwp\_%' OR meta_key LIKE 'acfwp\_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '%\_acfwp\_%' OR meta_key LIKE 'acfwp\_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '%\_acfwp\_%' OR meta_key LIKE 'acfwp\_%'" );

    $wc_tables       = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}woocommerce_%'" );
    $order_item_meta = $wpdb->prefix . 'woocommerce_order_itemmeta';

    // Delete meta data under the WC order items meta table.
    if ( isset( $wc_tables[ $order_item_meta ] ) ) {
        $wpdb->query( "DELETE FROM {$order_item_meta} WHERE meta_key LIKE '%\_acfw\_coupon\_%' OR meta_key LIKE '%acfwp\_%' OR meta_key LIKE 'acfw\_virtual%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    // Coupon premium metas.
    $coupon_metas = array(
        'bogo_auto_add_products',
        'add_before_conditions',
        'coupon_label',
        'enable_day_time_schedules',
        'day_time_schedules',
        'day_time_schedule_error_msg',
        'add_products_data',
        'excluded_coupons',
        'shipping_overrides',
        'apply_notification_message',
        'apply_notification_btn_text',
        'apply_notification_type',
        'reset_usage_limit_period',
        'coupon_sort_priority',
        'cart_condition_display_notice_auto_apply',
        'enable_payment_methods_restrict',
        'payment_methods_restrict_type',
        'payment_methods_restrict_selection',
        'enable_virtual_coupons',
        'virtual_coupon_for_display',
        'percentage_discount_cap',
        'defer_apply_url_coupon',
        'cashback_waiting_period',
        'reset_usage_limit_period',
        'usage_limit_reset_time',
        'allowed_customers',
        'disallowed_customers',
        'disallowed_emails',
        'virtual_coupons_bulk_create_date',
    );
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_acfw_" . implode( "','_acfw_", $coupon_metas ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

    // Modules.
    foreach ( $constants->PREMIUM_MODULES() as $module_option ) {
        delete_option( $module_option );
    }

    // Settings.
    delete_option( $constants->AUTO_APPLY_COUPONS );
    delete_option( $constants->APPLY_NOTIFICATION_CACHE );
    delete_option( $constants->DEFER_URL_COUPON_SESSION );
    delete_option( $constants->ADD_PRODUCTS_DISCOUNT_APPLICATION_MODE );
    delete_option( $constants->SHIPPING_OVERRIDES_DISCOUNT_APPLICATION_MODE );

    // SLMW options.
    delete_option( $constants->OPTION_ACTIVATION_EMAIL );
    delete_option( $constants->OPTION_LICENSE_KEY );
    delete_option( $constants->OPTION_LICENSE_ACTIVATED );
    delete_option( $constants->OPTION_UPDATE_DATA );
    delete_option( $constants->OPTION_RETRIEVING_UPDATE_DATA );

    // Help settings section options.
    delete_option( $constants->OPTION_CLEAN_UP_PLUGIN_OPTIONS );
}

if ( function_exists( 'is_multisite' ) && is_multisite() ) {

    global $wpdb;

    $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

    foreach ( $blog_ids as $blogid ) {

        switch_to_blog( $blogid );
        acfwp_plugin_cleanup();

    }

    restore_current_blog();

    return;

} else {
    acfwp_plugin_cleanup();
}
