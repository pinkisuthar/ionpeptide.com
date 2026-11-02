<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Interfaces\Activatable_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Feature Custom Taxonomy module.
 * This creates a private custom taxonomy for Advanced Coupons features.
 *
 * @since 4.0.5
 */
class Feature_Custom_Taxonomy extends Base_Model implements Model_Interface, Activatable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Class constructor.
     *
     * @since 4.0.5
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $main_plugin->add_to_all_plugin_models( $this );
        $main_plugin->add_to_public_models( $this );
    }

    /**
     * Add premium feature custom taxonomy modules.
     *
     * @since 4.0.5
     * @access public
     *
     * @param array $modules Modules.
     * @return array Modules.
     */
    public function add_premium_feature_custom_taxonomy_modules( $modules ) {
        $modules         = is_array( $modules ) ? $modules : array();
        $premium_modules = $this->_constants->PREMIUM_MODULES();
        $premium_modules = is_array( $premium_modules ) ? $premium_modules : array();
        return array_merge( $modules, $premium_modules );
    }

    /**
     * Add premium coupon feature checks.
     *
     * @since 4.0.5
     * @access public
     *
     * @param array $feature_checks Feature checks.
     * @param int   $coupon_id      Coupon ID.
     * @param mixed $coupon         Coupon object.
     * @return array Feature checks.
     */
    public function add_premium_coupon_feature_checks( $feature_checks, $coupon_id, $coupon ) {
        $premium_feature_checks = array(
            'acfw-add-free-products-module'        => array( // Add Products.
                array( 'add_products_data' ),
            ),
            'acfw-auto-apply-module'               => array( // Auto Apply.
                array( 'auto_apply_coupon', true ),
            ),
            'acfw-apply-notification-module'       => array( // Apply Notification.
                array( 'enable_apply_notification', true ),
                array( 'apply_notification_message', '', '!=' ),
                array( 'apply_notification_btn_text', '', '!=' ),
            ),
            'acfw-shipping-overrides-module'       => array( // Shipping Overrides.
                array( 'shipping_overrides' ),
            ),
            'acfw-advanced-usage-limits-module'    => array( // Usage Limits Reset.
                array( 'reset_usage_limit_period', 'none', '!=' ),
            ),
            'acfw-sort-coupons-module'             => array( // Sort Coupons.
                array( 'coupon_sort_priority', 30, '!=' ),
            ),
            'acfw-payment-methods-restrict-module' => array( // Payment Methods Restriction.
                array( 'enable_payment_methods_restrict', 'yes' ),
                array( 'payment_methods_restrict_selection' ),
            ),
            'acfw-virtual-coupons-module'          => array( // Virtual Coupons.
                array( 'enable_virtual_coupons', true ),
            ),
            'acfw-cashback-coupon-module'          => array( // Cashback.
                array( 'cashback_waiting_period', 0, '!=' ),
            ),
        );

        return array_merge( $feature_checks, $premium_feature_checks );
    }

    /**
     * Add premium coupon feature checks for taxonomy.
     *
     * @since 4.0.5
     * @access public
     *
     * @param object $coupon Coupon object.
     * @return object|false Coupon object or false if invalid.
     */
    public function add_premium_coupon_feature_checks_for_taxonomy( $coupon ) {
        if ( ! is_object( $coupon ) || ! method_exists( $coupon, 'get_id' ) ) {
            return false;
        }

        $coupon_id = $coupon->get_id();
        if ( empty( $coupon_id ) ) {
            return false;
        }

        return new Advanced_Coupon( $coupon_id );
    }

    /**
     * Handle property value retrieval for wp_option based properties.
     *
     * @since 4.0.5
     * @access public
     *
     * @param mixed  $prop_value The current property value.
     * @param string $prop_key   The property key being retrieved.
     * @param object $coupon     The Advanced Coupon object.
     * @return mixed The filtered property value.
     */
    public function handle_wp_option_property_values( $prop_value, $prop_key, $coupon ) {
        switch ( $prop_key ) {
            case 'auto_apply_coupon':
                // Get the auto apply coupons from wp_options.
                $auto_apply_coupons = \ACFWF()->Helper_Functions->get_option(
                    $this->_constants->AUTO_APPLY_COUPONS,
                    array()
                );
                return in_array( $coupon->get_id(), $auto_apply_coupons, true );

            case 'enable_apply_notification':
                // Get the apply notification coupons from wp_options.
                $notification_coupons = \ACFWF()->Helper_Functions->get_option(
                    $this->_constants->APPLY_NOTIFICATION_CACHE,
                    array()
                );
                return in_array( $coupon->get_id(), $notification_coupons, true );
        }

        return $prop_value;
    }

    /**
     * Refresh feature taxonomy terms for the given coupon IDs.
     * Triggered after the auto apply / apply notification caches are cleared or rebuilt
     * so the Features column on the All Coupons admin table reflects the current state
     * (i.e. no longer shows a stale "Auto Apply" or "Apply Notification" badge).
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $coupon_ids Coupon IDs whose cache-backed feature state may have changed.
     */
    public function refresh_features_for_coupons( $coupon_ids ) {
        if ( empty( $coupon_ids ) || ! is_array( $coupon_ids ) ) {
            return;
        }

        if ( ! file_exists( WP_PLUGIN_DIR . '/advanced-coupons-for-woocommerce-free/Models/Feature_Custom_Taxonomy.php' ) ) {
            return;
        }

        if ( ! function_exists( 'ACFWF' ) ) {
            return;
        }

        $feature_taxonomy = \ACFWF()->Feature_Custom_Taxonomy;

        if ( ! is_callable( array( $feature_taxonomy, 'update_coupon_features' ) ) ) {
            return;
        }

        foreach ( $coupon_ids as $coupon_id ) {
            $coupon_id = absint( $coupon_id );
            if ( ! $coupon_id ) {
                continue;
            }

            $feature_taxonomy->update_coupon_features( $coupon_id );
        }
    }

    /**
     * Filter feature count option prefix for premium plugin.
     *
     * @since 4.0.5
     * @access public
     *
     * @param string $prefix       Current prefix.
     * @param string $feature_slug Feature slug.
     * @return string Modified prefix.
     */
    public function filter_feature_count_option_prefix( $prefix, $feature_slug ) {
        // Get premium feature modules.
        $premium_modules = $this->_constants->PREMIUM_MODULES();

        // Convert underscores to dashes for comparison.
        $premium_slugs = array_map(
            function ( $slug ) {
                return str_replace( '_', '-', $slug );
            },
            $premium_modules
        );

        // Only use 'acfwp' prefix for premium features.
        if ( in_array( $feature_slug, $premium_slugs, true ) ) {
            return 'acfwp';
        }

        // Return original prefix for non-premium features.
        return $prefix;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
    */

    /**
     * Activate the taxonomy registration.
     *
     * @since 4.0.5
     * @access public
     * @inherit ACFWF\Interfaces\Activatable_Interface
     */
    public function activate() {
        if ( file_exists( WP_PLUGIN_DIR . '/advanced-coupons-for-woocommerce-free/Models/Feature_Custom_Taxonomy.php' ) ) {
            \ACFWF()->Feature_Custom_Taxonomy->create_feature_terms();
            \ACFWF()->Feature_Custom_Taxonomy->schedule_bulk_update_coupon_features();
            \ACFWF()->Feature_Custom_Taxonomy->calculate_and_update_feature_counts( true );
        }
    }

    /**
     * Execute Feature_Custom_Taxonomy class.
     *
     * @since 4.0.5
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        add_filter( 'acfw_feature_custom_taxonomy_modules', array( $this, 'add_premium_feature_custom_taxonomy_modules' ), 10, 1 );
        add_filter( 'acfw_coupon_feature_checks', array( $this, 'add_premium_coupon_feature_checks' ), 10, 3 );
        add_filter( 'acfw_feature_custom_taxonomy_coupon', array( $this, 'add_premium_coupon_feature_checks_for_taxonomy' ), 10, 1 );
        add_filter( 'acfw_feature_taxonomy_get_property_value', array( $this, 'handle_wp_option_property_values' ), 10, 3 );
        add_filter( 'acfw_feature_count_option_prefix', array( $this, 'filter_feature_count_option_prefix' ), 10, 2 );

        // Refresh feature terms when option-backed caches change (e.g. cleared/rebuilt via Help page).
        add_action( 'acfw_after_auto_apply_cache_changed', array( $this, 'refresh_features_for_coupons' ), 10, 1 );
        add_action( 'acfw_after_apply_notification_cache_changed', array( $this, 'refresh_features_for_coupons' ), 10, 1 );
    }
}
