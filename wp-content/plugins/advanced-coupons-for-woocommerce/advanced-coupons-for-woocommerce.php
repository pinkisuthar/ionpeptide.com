<?php
/**
 * Plugin Name: Advanced Coupons for WooCommerce Premium
 * Plugin URI: https://advancedcouponsplugin.com
 * Description: Advanced Coupons for WooCommerce (Premium Add-on) adds even more advanced features to WooCommerce coupons so store owners can market their stores better.
 * Version: 4.1.0
 * Author: Rymera Web Co
 * Author URI: https://rymera.com.au/
 * Update URI: advancedcouponsplugin.com
 * Requires at least: 5.9
 * Tested up to: 7.1
 * WC requires at least: 4.0
 * WC tested up to: 11.1.0
 *
 * Text Domain: advanced-coupons-for-woocommerce
 * Domain Path: /languages/
 *
 * @package ACFWP
 * @category Core
 * @author Rymera Web Co
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin basename constant for integration with other plugins (e.g., StoreAgent).
if ( ! defined( 'ACFWP_PLUGIN_BASENAME' ) ) {
    define( 'ACFWP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Abilities;
use ACFWP\Models\ACFW_Reports;
use ACFWP\Models\Add_Products;
use ACFWP\Models\Allowed_Customers;
use ACFWP\Models\Apply_Notification;
use ACFWP\Models\Auto_Apply;
use ACFWP\Models\BOGO\Admin as BOGO_Admin;
use ACFWP\Models\Bootstrap;
use ACFWP\Models\Cart_Conditions;
use ACFWP\Models\Edit_Coupon;
use ACFWP\Models\Editor_Blocks;
use ACFWP\Models\Exclude_Coupons;
use ACFWP\Models\Module_Settings;
use ACFWP\Models\Notices;
use ACFWP\Models\Payment_Methods_Restrict;
use ACFWP\Models\Percent_Discount_Cap;
use ACFWP\Models\Product_Attributes;
use ACFWP\Models\Product_Discount_Rules;
use ACFWP\Models\REST_API\API_Coupon_Generator_Storage;
use ACFWP\Models\REST_API\API_Virtual_Coupons;
use ACFWP\Models\REST_API\Template_Field_Enrichment;
use ACFWP\Models\Script_Loader;
use ACFWP\Models\Shipping_Overrides;
use ACFWP\Models\SLMW\License;
use ACFWP\Models\SLMW\Update;
use ACFWP\Models\Tiered_Cart_Discount;
use ACFWP\Models\Usage_Limits;
use ACFWP\Models\Virtual_Coupon\Admin as Virtual_Coupon_Admin;
use ACFWP\Models\Virtual_Coupon\Frontend as Virtual_Coupon_Frontend;
use ACFWP\Models\Virtual_Coupon\Queries as Virtual_Coupon_Queries;

/**
 * Register plugin autoloader.
 *
 * @since 2.0
 *
 * @param string $class_name Name of the class to load.
 */
spl_autoload_register(
    function ( $class_name ) {
        if ( str_starts_with( $class_name, 'ACFWP\\' ) ) { // Only do autoload for our plugin files.
            $class_file = str_replace( array( '\\', 'ACFWP' . DIRECTORY_SEPARATOR ), array( DIRECTORY_SEPARATOR, '' ), $class_name ) . '.php';
            require_once plugin_dir_path( __FILE__ ) . $class_file;
        }
    }
);

/**
 * The main plugin class.
 */
class ACFWP extends Abstract_Main_Plugin_Class { // phpcs:ignore

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
     * Array of missing external plugins/or plugins with invalid version that this plugin is depends on.
     *
     * @since 2.0
     * @access private
     * @var array
     */
    private $failed_dependencies;

    /**
     * Minimum required ACFWF version.
     *
     * The floor is '4.7.6' because of the auto-apply interchange fix (#1198). That fix needs the
     * free plugin's "one BOGO coupon" restriction to read the live applied-coupons list, which
     * ACFWF 4.7.6 adds (free-plugin companion fix ACFWF #1606, premium PR #1590). Against an older
     * ACFWF, the BOGO interchange ends with no BOGO coupon applied. Do not lower this floor below
     * '4.7.6'. Issue #1607 raised it.
     *
     * @since 3.5.5
     * @since 4.1.0 Raised the floor to '4.7.6' for the auto-apply interchange fix (#1198).
     * @access private
     * @var string
     */
    private $_required_acfwf_version = '4.7.6';

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * ACFWP constructor.
     *
     * @since 2.0
     * @access public
     */
    public function __construct() {
        Plugin_Constants::get_instance( $this );
        Helper_Functions::get_instance( $this, $this->Plugin_Constants );
        register_deactivation_hook( __FILE__, array( $this, 'general_deactivation_code' ) );

        if ( $this->_check_plugin_dependencies() !== true ) {

            // Display notice that plugin dependency is not present.
            add_action( 'admin_notices', array( $this, 'missing_plugin_dependencies_notice' ) );
            add_action( 'network_admin_notices', array( $this, 'missing_plugin_dependencies_notice' ) );

        } elseif ( $this->_check_plugin_dependency_version_requirements() !== true ) {

            // Display notice that some dependent plugin did not meet the required version.
            add_action( 'admin_notices', array( $this, 'invalid_plugin_dependency_version_notice' ) );

        } else {

            // Lock 'n Load.
            $this->_initialize_plugin_components();
            $this->_run_plugin();

        }
    }

    /**
     * Add notice to notify users that some plugin dependencies of this plugin are missing.
     *
     * @since 2.0
     * @access public
     */
    public function missing_plugin_dependencies_notice() {
        if ( ! empty( $this->failed_dependencies ) ) {

            $admin_notice_msg = '';

            foreach ( $this->failed_dependencies as $failed_dependency ) {

                $failed_dep_plugin_file = trailingslashit( WP_PLUGIN_DIR ) . plugin_basename( $failed_dependency['plugin-base-name'] );

                if ( file_exists( $failed_dep_plugin_file ) ) {
                    $failed_dep_install_text = sprintf(
                        /* translators: %1$s: Plugin activation URL */
                        __( '<a class="action-button" href="%1$s" title="Activate this plugin">Click here to activate &rarr;</a><span><em>Plugin Detected</em></span>', 'advanced-coupons-for-woocommerce' ),
                        $this->Helper_Functions->get_plugin_dependency_activation_url( $failed_dependency['plugin-base-name'] ) // %1$s
                    );
                } else {
                    $failed_dep_install_text = sprintf(
                        /* translators: %1$s: Install plugin URL */
                        __( '<a class="action-button" href="%1$s" title="Install this plugin">Click here to install from WordPress.org repo &rarr;</a>', 'advanced-coupons-for-woocommerce' ),
                        $this->Helper_Functions->get_plugin_dependency_install_url( $failed_dependency['plugin-key'] ) // %1$s
                    );
                }

                $admin_notice_msg .= sprintf(
                    /* translators: %1$s is Plugin URL, %2$s is Plugin name */
                    '<p>' . __( 'Please ensure you have the <a href="%1$s" target="_blank">%2$s</a> plugin installed and activated.<br/>', 'advanced-coupons-for-woocommerce' ) . '</p>',
                    'http://wordpress.org/plugins/' . $failed_dependency['plugin-key'] . '/', // %1$s
                    $failed_dependency['plugin-name']// %2$s
                );
                $admin_notice_msg .= sprintf( '<p class="action-wrap">%s</p>', $failed_dep_install_text );

            }

            $acfw_logo = $this->Plugin_Constants->IMAGES_ROOT_URL . 'acfw-logo.png';

            include $this->Plugin_Constants->VIEWS_ROOT_PATH . 'notices/view-acfwp-failed-dependancy-notice.php';
        }
    }

    /**
     * Add notice to notify user that some plugin dependencies did not meet the required version for the current version of this plugin.
     *
     * @since 2.0
     * @access public
     */
    public function invalid_plugin_dependency_version_notice() {
        if ( ! empty( $this->failed_dependencies ) ) {

            $admin_notice_msg = '';
            $acfwf_dependency = false;

            foreach ( $this->failed_dependencies as $failed_dependency ) {

                if ( 'advanced-coupons-for-woocommerce-free' === $failed_dependency['plugin-key'] ) {
                    $acfwf_dependency = $failed_dependency;
                    continue;
                }

                $update_text = sprintf(
                    /* translators: %1$s is the update plugin URL, %2$s is the plugin name. */
                    __( '<a href="%1$s">Click here to update %2$s &rarr;</a>', 'advanced-coupons-for-woocommerce' ),
                    $this->Helper_Functions->get_plugin_dependency_install_url( $failed_dependency['plugin-base-name'], true ), // %1$s
                    $failed_dependency['plugin-name']// %2$s
                );

                $admin_notice_msg .= sprintf(
                    /* translators: %1$s is the update plugin URL, %2$s is the plugin name. */
                    __( 'Please ensure you have the latest version of <a href="%1$s" target="_blank">%2$s</a> plugin installed and activated.<br/>', 'advanced-coupons-for-woocommerce' ),
                    'http://wordpress.org/plugins/' . $failed_dependency['plugin-key'] . '/', // %1$s
                    $failed_dependency['plugin-name']// %2$s
                );
                $admin_notice_msg .= $update_text . '<br/><br/>';

            }

            $acfw_logo     = $this->Plugin_Constants->IMAGES_ROOT_URL . 'acfw-logo.png';
            $acfwf_version = $this->_required_acfwf_version; // Minimum required version of ACFWF.
            $acfwp_version = $this->Plugin_Constants->VERSION; // Current version of ACFWP.

            include $this->Plugin_Constants->VIEWS_ROOT_PATH . 'notices/view-plugin-dependency-version-notice.php';

        }
    }

    /**
     * The purpose of this function is to have a "general/global" deactivation function callback that is
     * guaranteed to execute when a plugin is deactivated.
     *
     * We have experienced in the past that WordPress does not require "activation" and "deactivation" callbacks,
     * regardless if its present or not, it just activates/deactivates the plugin.
     *
     * In our past experience, a plugin can be activated/deactivated without triggering its "activation" and/or
     * "deactivation" callback on cases where plugin dependency requirements failed or plugin dependency version
     * requirement failed.
     *
     * By registering this "deactivation" callback on constructor, we ensure this "deactivation" callback
     * is always triggered on plugin deactivation.
     *
     * We put inside the function body just the "general" deactivation codebase.
     * Model specific activation/deactivation code base should still reside inside its individual models.
     *
     * We do not need to register a general/global "activation" callback coz we do need all plugin requirements
     * passed before activating the plugin.
     *
     * @since 2.0
     * @access public
     *
     * @global object $wpdb Object that contains a set of functions used to interact with a database.
     *
     * @param boolean $network_wide Flag that determines whether the plugin has been activated network wid ( on multi site environment ) or not.
     */
    public function general_deactivation_code( $network_wide ) {
        // Delete the flag that determines if plugin activation code is triggered.
        global $wpdb;

        // check if it is a multisite network.
        if ( is_multisite() ) {

            // check if the plugin has been activated on the network or on a single site.
            if ( $network_wide ) {

                // get ids of all sites.
                $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

                foreach ( $blog_ids as $blog_id ) {

                    switch_to_blog( $blog_id );
                    delete_option( $this->Plugin_Constants->OPTION_ACFWP_ACTIVATION_CODE_TRIGGERED );
                    delete_option( $this->Plugin_Constants->INSTALLED_VERSION );

                }

                restore_current_blog();

            } else {
                // activated on a single site, in a multi-site.

                delete_option( $this->Plugin_Constants->OPTION_ACFWP_ACTIVATION_CODE_TRIGGERED );
                delete_option( $this->Plugin_Constants->INSTALLED_VERSION );

            }
        } else {
            // activated on a single site.

            delete_option( $this->Plugin_Constants->OPTION_ACFWP_ACTIVATION_CODE_TRIGGERED );
            delete_option( $this->Plugin_Constants->INSTALLED_VERSION );

        }
    }

    /**
     * Check for external plugin dependencies.
     *
     * @since 2.0
     * @access private
     *
     * @return mixed Array if there are missing plugin dependencies, True if all plugin dependencies are present.
     */
    private function _check_plugin_dependencies() {
        // Makes sure the function is defined before trying to use it.
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $this->failed_dependencies = array();

        // WooCommerce.
        if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {

            $this->failed_dependencies[] = array(
                'plugin-key'       => 'woocommerce',
                'plugin-name'      => 'WooCommerce', // We don't translate this coz this is the plugin name.
                'plugin-base-name' => 'woocommerce/woocommerce.php',
            );

        }

        // ACFWF.
        if ( ! is_plugin_active( 'advanced-coupons-for-woocommerce-free/advanced-coupons-for-woocommerce-free.php' ) || ! function_exists( 'ACFWF' ) ) {

            $this->failed_dependencies[] = array(
                'plugin-key'       => 'advanced-coupons-for-woocommerce-free',
                'plugin-name'      => 'Advanced Coupons for WooCommerce Free', // We don't translate this coz this is the plugin name.
                'plugin-base-name' => 'advanced-coupons-for-woocommerce-free/advanced-coupons-for-woocommerce-free.php',
            );

        }

        return ! empty( $this->failed_dependencies ) ? $this->failed_dependencies : true;
    }

    /**
     * Check plugin dependency version requirements.
     *
     * @since 2.0
     * @access private
     *
     * @return mixed Array if there are invalid versioned plugin dependencies, True if all plugin dependencies have valid version.
     */
    private function _check_plugin_dependency_version_requirements() {
        $this->failed_dependencies = array();

        $acfw_plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/advanced-coupons-for-woocommerce-free/advanced-coupons-for-woocommerce-free.php', false, false );

        // Compares the version of ACFWF installed with the required version.
        if ( ! version_compare( $acfw_plugin_data['Version'], $this->_required_acfwf_version, '>=' ) ) {

            $this->failed_dependencies[] = array(
                'plugin-key'       => 'advanced-coupons-for-woocommerce-free',
                'plugin-name'      => 'advanced-coupons-for-woocommerce-free', // We don't translate this coz this is the plugin name.
                'plugin-base-name' => 'advanced-coupons-for-woocommerce-free/advanced-coupons-for-woocommerce-free.php',
            );

        }

        return ! empty( $this->failed_dependencies ) ? $this->failed_dependencies : true;
    }

    /**
     * Initialize plugin components.
     *
     * @since 2.0
     * @access private
     */
    private function _initialize_plugin_components() {
        \ACFWP\Models\Force_Apply::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Third_Party_Integrations\WC\WC_Memberships::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ); // 3rd party
        \ACFWP\Models\Third_Party_Integrations\WC\WC_Subscriptions::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ); // 3rd party
        \ACFWP\Models\Third_Party_Integrations\Aelia\Currency_Switcher::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ); // 3rd party
        \ACFWP\Models\Third_Party_Integrations\WPML_Support::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ); // 3rd party
        \ACFWP\Models\Third_Party_Integrations\Woocs::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ); // 3rd party
        \ACFWP\Models\Third_Party_Integrations\FunnelKit::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ); // 3rd party
        \ACFWP\Models\SLMW\Settings::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Sort_Coupons::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\BOGO\Frontend::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Defer_URL_Coupon::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Scheduler::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Cashback_Coupon::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Coupon_Label::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\REST_API\Store_API_Hooks::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Coupon_Card::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Mutually_Exclusive::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\WC_Blocks::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Allowed_Coupons::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Store_Credits::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Store_Credits\Bulk_Adjust::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\Store_Credits\CLI\Bulk_Adjust_Command::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\BOGO\Types\Same_Products::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        Abilities::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );

        $bogo_admin              = BOGO_Admin::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        $usage_limits            = Usage_Limits::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        $admin_notices           = Notices::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        $slmw_license            = License::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        $slmw_update             = Update::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        $virtual_coupon_queries  = Virtual_Coupon_Queries::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        $feature_custom_taxonomy = \ACFWP\Models\Feature_Custom_Taxonomy::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        $store_credits_order_pay = \ACFWP\Models\Order_Pay::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        Virtual_Coupon_Admin::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        Virtual_Coupon_Frontend::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        Payment_Methods_Restrict::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        API_Virtual_Coupons::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        API_Coupon_Generator_Storage::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        \ACFWP\Models\REST_API\API_Bulk_Adjust::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
        Template_Field_Enrichment::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );

        $activatables   = array( $bogo_admin, $usage_limits, $admin_notices, $virtual_coupon_queries, $slmw_license, $feature_custom_taxonomy );
        $initiables     = array(
            $bogo_admin,
            $usage_limits,
            $store_credits_order_pay,
            Cart_Conditions::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Apply_Notification::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Exclude_Coupons::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Allowed_Customers::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            \ACFWP\Models\Disallowed_Customers::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            \ACFWP\Models\Disallowed_Emails::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Percent_Discount_Cap::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Editor_Blocks::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Add_Products::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Auto_Apply::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Shipping_Overrides::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Edit_Coupon::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Module_Settings::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            ACFW_Reports::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            License::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Product_Attributes::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Product_Discount_Rules::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            Tiered_Cart_Discount::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            $slmw_license,
            $slmw_update,
            \ACFWP\Models\Coupon_Tab::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
            \ACFWP\Models\Emails\Emails::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions ),
        );
        $deactivatables = array(
            $usage_limits,
            $slmw_update,
        );

        Bootstrap::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions, $activatables, $initiables, $deactivatables );
        Script_Loader::get_instance( $this, $this->Plugin_Constants, $this->Helper_Functions );
    }

    /**
     * Run the plugin. ( Runs the various plugin components ).
     *
     * @since 2.0
     * @access private
     */
    private function _run_plugin() {
        if ( ! function_exists( 'ACFWF' ) ) {
            return;
        }

        foreach ( $this->_all_models as $model ) {
            if ( $model instanceof Model_Interface ) {
                $model->run();
            }
        }
    }
}

/**
 * Returns the main instance of ACFWP to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return ACFWP Main instance of the plugin.
 */
function ACFWP() { // phpcs:ignore
    return ACFWP::get_instance();
}

// Autobots! Let's Roll!
$GLOBALS['ACFWP'] = ACFWP();
