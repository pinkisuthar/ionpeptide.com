<?php
namespace ACFWP\Helpers;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses all the plugin constants.
 *
 * @since 2.0
 */
class Plugin_Constants {
    /*
    |--------------------------------------------------------------------------
    | Traits
    |--------------------------------------------------------------------------
     */
    use \ACFWP\Traits\Singleton;

    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Class property that houses all the actual constants data.
     *
     * @since 2.0
     * @access private
     * @var array
     */
    private $_data = array();

    /**
     * Modules constants.
     *
     * @since 2.0
     */
    const URL_COUPONS_MODULE        = 'acfw_url_coupons_module';
    const SCHEDULER_MODULE          = 'acfw_scheduler_module';
    const ADD_PRODUCTS_MODULE       = 'acfw_add_free_products_module'; // we don't change the actual meta name for backwards compatibility.
    const AUTO_APPLY_MODULE         = 'acfw_auto_apply_module';
    const APPLY_NOTIFICATION_MODULE = 'acfw_apply_notification_module';
    const SHIPPING_OVERRIDES_MODULE = 'acfw_shipping_overrides_module';
    const USAGE_LIMITS_MODULE       = 'acfw_advanced_usage_limits_module';
    const CART_CONDITIONS_MODULE    = 'acfw_cart_conditions_module';
    const BOGO_DEALS_MODULE         = 'acfw_bogo_deals_module';
    const SORT_COUPONS_MODULE       = 'acfw_sort_coupons_module';
    const PAYMENT_METHODS_RESTRICT  = 'acfw_payment_methods_restrict_module';
    const STORE_CREDITS_MODULE      = 'acfw_store_credits_module';
    const VIRTUAL_COUPONS_MODULE    = 'acfw_virtual_coupons_module';
    const CASHBACK_COUPON_MODULE    = 'acfw_cashback_coupon_module';
    const CACHED_CART_TOTAL         = 'acfw_cached_cart_total';


    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 2.0
     * @access public
     *
     * @param Abstract_Main_Plugin_Class|null $main_plugin Main plugin object.
     */
    public function __construct( ?Abstract_Main_Plugin_Class $main_plugin = null ) {
        $main_plugin_file_path = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'advanced-coupons-for-woocommerce' . DIRECTORY_SEPARATOR . 'advanced-coupons-for-woocommerce.php';
        $plugin_dir_path       = plugin_dir_path( $main_plugin_file_path );
        $plugin_dir_url        = plugin_dir_url( $main_plugin_file_path );
        $plugin_basename       = plugin_basename( $main_plugin_file_path );
        $plugin_dirname        = plugin_basename( dirname( $main_plugin_file_path ) );
        $slmw_url              = 'https://advancedcouponsplugin.com';

        $this->_data = array(

            // Configuration Constants.
            'TOKEN'                                        => 'acfwp',
            'INSTALLED_VERSION'                            => 'acfwp_installed_version',
            'VERSION'                                      => '4.1.0',
            'TEXT_DOMAIN'                                  => 'advanced-coupons-for-woocommerce',
            'THEME_TEMPLATE_PATH'                          => 'advanced-coupons-for-woocommerce',
            'META_PREFIX'                                  => '_acfw_',
            'FREE_PLUGIN'                                  => 'advanced-coupons-for-woocommerce-free/advanced-coupons-for-woocommerce-free.php',

            // SLMW URLs.
            'PLUGIN_SITE_URL'                              => $slmw_url,
            'LICENSE_ACTIVATION_URL'                       => $slmw_url . '/wp-admin/admin-ajax.php?action=slmw_activate_license',
            'UPDATE_DATA_URL'                              => $slmw_url . '/wp-admin/admin-ajax.php?action=slmw_get_update_data',
            'STATIC_PING_FILE'                             => $slmw_url . '/ACFW.json',
            'LICENSE_ACTIVATION_ENDPOINT'                  => $slmw_url . '/wp-json/slmw/v1/license/activate',
            'LICENSE_CHECK_ENDPOINT'                       => $slmw_url . '/wp-json/slmw/v1/license/check',
            'UPDATE_DATA_ENDPOINT'                         => $slmw_url . '/wp-json/slmw/v1/license/update',

            // SLMW Options.
            'OPTION_ACTIVATION_EMAIL'                      => 'acfw_slmw_activation_email',
            'OPTION_LICENSE_KEY'                           => 'acfw_slmw_license_key',
            'OPTION_LICENSE_ACTIVATED'                     => 'acfw_license_activated',
            'OPTION_LICENSE_EXPIRED'                       => 'acfw_license_expired',
            'OPTION_UPDATE_DATA'                           => 'acfw_option_update_data',
            'OPTION_RETRIEVING_UPDATE_DATA'                => 'acfw_option_retrieving_update_data',
            'OPTION_LICENSE_DATA'                          => 'acfw_plugins_license_data', // Holds the license data for all premium plugins.
            'OPTION_LAST_LICENSE_CHECK'                    => 'acfw_last_license_check',
            'SOFTWARE_KEY'                                 => 'ACFW',

            // Notices.
            'SHOW_GETTING_STARTED_NOTICE'                  => 'acfwf_show_getting_started_notice',
            'GETTING_STARTED_PREMIUM_SHOWN'                => 'acfwf_getting_started_notice_shown_premium',
            'SHOW_NEW_UPDATE_NOTICE'                       => 'acfwp_show_new_update_notice',
            'NEW_UPDATE_NOTICE_VERSION'                    => '3.4', // the version of which the new update notice should show up.
            'NO_LICENSE_NOTICE_DISMISSED'                  => 'acfw_slmw_no_license_notice_dismissed',
            'LICENSE_DISABLED_NOTICE_DISMISSED'            => 'acfw_license_disabled_notice_dismissed',
            'LICENSE_EXPIRE_NOTICE_DISMISSED'              => 'acfw_license_expire_notice_dismissed',
            'LICENSE_PRE_EXPIRE_NOTICE_DISMISSED'          => 'acfw_license_pre_expire_notice_dismissed',
            'NO_LICENSE_REMINDER_DISMISSED'                => 'acfw_no_license_reminder_dismissed',

            // Paths.
            'MAIN_PLUGIN_FILE_PATH'                        => $main_plugin_file_path,
            'PLUGIN_DIR_PATH'                              => $plugin_dir_path,
            'PLUGIN_DIR_URL'                               => $plugin_dir_url,
            'PLUGIN_BASENAME'                              => $plugin_basename,
            'PLUGIN_DIRNAME'                               => $plugin_dirname,
            'JS_ROOT_PATH'                                 => $plugin_dir_path . 'js/',
            'VIEWS_ROOT_PATH'                              => $plugin_dir_path . 'views/',
            'TEMPLATES_ROOT_PATH'                          => $plugin_dir_path . 'templates/',
            'LOGS_ROOT_PATH'                               => $plugin_dir_path . 'logs/',
            'THIRD_PARTY_PATH'                             => $plugin_dir_path . 'Models/Third_Party_Integrations/',
            'DIST_ROOT_PATH'                               => $plugin_dir_path . 'dist/',

            // URLs.
            'CSS_ROOT_URL'                                 => $plugin_dir_url . 'css/',
            'IMAGES_ROOT_URL'                              => $plugin_dir_url . 'images/',
            'JS_ROOT_URL'                                  => $plugin_dir_url . 'js/',
            'THIRD_PARTY_URL'                              => $plugin_dir_url . 'Models/Third_Party_Integrations/',
            'DIST_ROOT_URL'                                => $plugin_dir_url . 'dist/',

            // Endpoints.
            'MY_COUPONS_ENDPOINT'                          => 'my-coupons',

            // Coupon Categories Constants.
            'COUPON_CAT_TAXONOMY'                          => 'shop_coupon_cat',
            'DEFAULT_REDEEM_COUPON_CAT'                    => 'acfw_default_redeemed_coupon_category',

            // Scheduler section.
            'SCHEDULER_START_ERROR_MESSAGE'                => 'acfw_scheduler_start_error_message',
            'SCHEDULER_EXPIRE_ERROR_MESSAGE'               => 'acfw_scheduler_expire_error_message',
            'DAYTIME_SCHEDULES_ERROR_MESSAGE'              => 'acfw_daytime_schedule_error_message',

            // Advance Usage Limits.
            'USAGE_LIMITS_CRON'                            => 'acfw_advanced_usage_limits_cron',

            // Virtual Codes.
            'VIRTUAL_COUPONS_DB_CREATED'                   => 'acfw_virtual_coupons_db_created',
            'VIRTUAL_COUPONS_DB_NAME'                      => 'acfw_virtual_coupons',
            'VIRTUAL_COUPONS_BULK_CREATE_DATE'             => '_acfw_virtual_coupons_bulk_create_date',
            'VIRTUAL_COUPONS_META_PREFIX'                  => 'acfw_virtual_coupon_',

            // Defer apply url coupons.
            'DEFER_URL_COUPON_SESSION'                     => 'acfw_defer_url_coupon',

            // Reports.
            'ACFW_REPORTS_TAB'                             => 'acfw_reports',

            // Cache options.
            'AUTO_APPLY_COUPONS'                           => 'acfw_auto_apply_coupons',
            'APPLY_NOTIFICATION_CACHE'                     => 'acfw_apply_notifcation_cache',

            // REST API.
            'REST_API_NAMESPACE'                           => 'coupons/v1',
            'WC_REST_API_NAMESPACE'                        => 'wc-coupons/v1',

            // Options.
            'OPTION_ACFWP_ACTIVATION_CODE_TRIGGERED'       => 'option_acfwp_activation_code_triggered',
            'BOGO_PRODUCT_CAT_MIGRATION_STATUS'            => 'acfwp_bogo_product_cat_migration_status',
            'BOGO_PRODUCT_CAT_DATA_MIGRATED'               => '_acfwp_bogo_product_cat_data_migrated',

            // Settings ( Help ).
            'OPTION_CLEAN_UP_PLUGIN_OPTIONS'               => 'acfw_clean_up_plugin_options',
            'OPTION_HIDE_ZERO_DOLLAR_COUPON'               => 'acfw_general_hide_zero_dollar_coupon',
            'OPTION_HIDE_MY_COUPONS_TAB'                   => 'acfw_general_hide_my_coupons_tab',

            // Discount application mode ('price' modifies item/rate prices, 'coupon' attributes the discount to the coupon amount).
            'ADD_PRODUCTS_DISCOUNT_APPLICATION_MODE'       => 'acfw_add_products_discount_application_mode',
            'SHIPPING_OVERRIDES_DISCOUNT_APPLICATION_MODE' => 'acfw_shipping_overrides_discount_application_mode',

            // Order Meta.
            'CASHBACK_ACTION_SCHEDULE'                     => 'acfwp_cashback_action_schedule',
            'ORDER_COUPON_ADD_PRODUCTS_DISCOUNT'           => '_acfw_coupon_add_products_discount',
            'ORDER_COUPON_CASHBACK_AMOUNT'                 => '_acfw_coupon_cashback_amount',
            'ORDER_COUPON_CASHBACK_WAITING_PERIOD'         => '_acfw_coupon_cashback_waiting_period',
            'ORDER_COUPON_CASHBACK_STORE_CREDIT_ENTRY'     => '_acfw_coupon_cashback_store_credit_entry',
            'ORDER_COUPON_SHIPPING_OVERRIDES_DISCOUNT'     => '_acfw_coupon_shipping_overrides_discount',

            // Coupon Meta.
            'SHOW_ON_MY_COUPONS_PAGE'                      => '_acfw_show_on_my_coupons_page',
            'SHOW_ONLY_ELIGIBILE_COUPON_MY_COUPONS_PAGE'   => '_acfw_show_only_eligible_coupon_my_coupons_page',
            'ALLOWED_CUSTOMER'                             => '_acfw_allowed_customers',
            'DISALLOWED_CUSTOMER'                          => '_acfw_disallowed_customers',
            'DISALLOWED_EMAIL'                             => '_acfw_disallowed_emails',

            // Coupon Category Meta.
            'MUTUALLY_EXCLUSIVE'                           => '_acfw_coupon_category_mutually_exclusive',

            // Others.
            'DISPLAY_DATE_FORMAT'                          => 'F j, Y g:i a',
            'DB_DATE_FORMAT'                               => 'Y-m-d H:i:s',

            // Permissions.
            'ALLOW_FETCH_CONTENT_REMOTE'                   => 'acfw_allow_fetch_content_remote_server',

            // Cashback.
            'CASHBACK_COUPON_NOTICE_MESSAGE'               => 'acfw_cashback_coupon_notice_message',
            'CASHBACK_COUPON_NOTICE_BTN_TEXT'              => 'acfw_cashback_coupon_notice_button_text',
            'CASHBACK_COUPON_NOTICE_BTN_URL'               => 'acfw_cashback_coupon_notice_button_url',
            'CASHBACK_POINTS_ORDER_STATUS'                 => 'acfw_cashback_points_order_status',

            // Store Credits.
            'MAX_STORE_CREDITS_AMOUNT_REDEEM'              => 'acfw_max_store_credits_amount_redeem',
            'MIN_STORE_CREDITS_AMOUNT_REDEEM'              => 'acfw_min_store_credits_amount_redeem',
            'AUTO_REVOKE_CASHBACK_COUPON_STORE_CREDITS'    => 'acfw_auto_revoke_cashback_coupon_store_credits',
            'STORE_CREDITS_AUTO_APPLY_AVAILABLE_AMOUNT'    => 'acfw_store_credits_auto_apply_available_amount',
            'STORE_CREDITS_MANUALLY_REMOVED'               => 'acfw_store_credits_manually_removed',
            'HIDE_STORE_CREDITS_WHEN_COUPON_APPLIED'       => 'acfw_hide_store_credits_when_coupon_applied',

            // Bulk Store Credit Adjustment.
            'BULK_ADJUST_ACTION'                           => 'bulk_adjustment',
            'BULK_RESET_ACTION'                            => 'bulk_reset',
            'BULK_DELETE_ACTION'                           => 'bulk_delete',
            'BULK_OPERATION_TYPE_ADJUST'                   => 'adjust',
            'BULK_OPERATION_TYPE_RESET'                    => 'reset',
            'BULK_OPERATION_TYPE_DELETE'                   => 'delete',
            'BULK_ADJUST_SCHEDULE_HOOK'                    => 'acfwp_bulk_adjust_store_credits',
            'BULK_ADJUST_IN_PROGRESS'                      => 'acfwp_bulk_adjust_in_progress',
            'BULK_ADJUST_LAST_COMPLETED'                   => 'acfwp_bulk_adjust_last_completed',
        );

        if ( $main_plugin ) {
            $main_plugin->add_to_public_helpers( $this );
        }
    }

    /**
     * Get constant property.
     * We use this magic method to automatically access data from the _data property so
     * we do not need to create individual methods to expose each of the constant properties.
     *
     * @since 2.0
     * @access public
     *
     * @param string $prop The name of the data property to access.
     * @return mixed Data property value.
     * @throws \Exception Error message.
     */
    public function __get( $prop ) {
        if ( array_key_exists( $prop, $this->_data ) ) {
            return $this->_data[ $prop ];
        } else {
            throw new \Exception( 'Trying to access unknown property' );
        }
    }

    public function CACHE_OPTIONS() { // phpcs:ignore
        return array(
            $this->AUTO_APPLY_COUPONS,
            $this->APPLY_NOTIFICATION_CACHE,
        );
    }

    public static function PREMIUM_MODULES() { // phpcs:ignore
        return array(
            self::SCHEDULER_MODULE,
            self::ADD_PRODUCTS_MODULE,
            self::AUTO_APPLY_MODULE,
            self::APPLY_NOTIFICATION_MODULE,
            self::SHIPPING_OVERRIDES_MODULE,
            self::USAGE_LIMITS_MODULE,
            self::SORT_COUPONS_MODULE,
            self::PAYMENT_METHODS_RESTRICT,
            self::VIRTUAL_COUPONS_MODULE,
            self::CASHBACK_COUPON_MODULE,
        );
    }

    public static function ALL_MODULES() { // phpcs:ignore
        return array_merge( \ACFWF\Helpers\Plugin_Constants::ALL_MODULES(), self::PREMIUM_MODULES() );
    }

    public static function DEFAULT_MODULES() { // phpcs:ignore
        $premium = self::PREMIUM_MODULES();
        $premium = array_diff( $premium, array( self::SORT_COUPONS_MODULE ) );

        return array_merge( \ACFWF\Helpers\Plugin_Constants::DEFAULT_MODULES(), $premium );
    }

    /**
     * Get the virtual coupon code separator.
     *
     * @since 3.5.9
     * @return string
     */
    public function get_virtual_coupon_code_separator() {
        return apply_filters( 'acfw_virtual_coupon_code_separator', '-' );
    }

    /**
     * Get the license page URL.
     *
     * @since 3.6.0
     * @access public
     *
     * @return string License page URL.
     */
    public function get_license_page_url() {
        if ( is_multisite() ) {
            $license_page_link = current_user_can( 'manage_network_plugins' ) ? network_admin_url( 'admin.php?page=advanced-coupons&tab=acfwp_license' ) : '';
        } else {
            $license_page_link = admin_url( 'admin.php?page=acfw-license' );
        }

        return $license_page_link;
    }
}
