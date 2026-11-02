<?php
namespace ACFWF\Models;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Initializable_Interface;
use ACFWF\Interfaces\Deactivatable_Interface;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Vite_App;
use ACFWF\Models\Tools\Plugin_Installer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the Admin_App module logic.
 * Public Model.
 *
 * @since 1.2
 */
class Admin_App implements Model_Interface, Initializable_Interface, Deactivatable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that holds the single main instance of URL_Coupon.
     *
     * @since 1.2
     * @access private
     * @var Cart_Conditions
     */
    private static $_instance;

    /**
     * Model that houses all the plugin constants.
     *
     * @since 1.2
     * @access private
     * @var Plugin_Constants
     */
    private $_constants;

    /**
     * Property that houses all the helper functions of the plugin.
     *
     * @since 1.2
     * @access private
     * @var Helper_Functions
     */
    private $_helper_functions;

    /**
     * Property that holds list of app pages.
     *
     * @since 1.2
     * @access private
     * @var array
     */
    private $_app_pages = array();

    /**
     * Property that holds the plugin installer instance.
     *
     * @since 1.2
     * @access private
     * @var Plugin_Installer
     */
    private $_plugin_installer;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 1.2
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     * @param Plugin_Installer           $plugin_installer Plugin installer object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions, $plugin_installer = null ) {

        $this->_constants        = $constants;
        $this->_helper_functions = $helper_functions;
        $this->_plugin_installer = $plugin_installer;

        $main_plugin->add_to_all_plugin_models( $this );
        $main_plugin->add_to_public_models( $this );
    }

    /**
     * Ensure that only one instance of this class is loaded or can be loaded ( Singleton Pattern ).
     *
     * @since 1.2
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     * @param Plugin_Installer           $plugin_installer Plugin installer object.
     * @return Cart_Conditions
     */
    public static function get_instance( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions, $plugin_installer = null ) {

        if ( ! self::$_instance instanceof self ) {
            self::$_instance = new self( $main_plugin, $constants, $helper_functions, $plugin_installer );
        }

        return self::$_instance;
    }

    /*
    |--------------------------------------------------------------------------
    | Implementation.
    |--------------------------------------------------------------------------
     */

    /**
     * Register settings related submenus.
     *
     * @since 1.2
     * @access public
     *
     * @param string $toplevel_menu Top level menu slug.
     */
    public function register_submenus( $toplevel_menu ) {
        $this->_app_pages = apply_filters(
            'acfw_admin_app_pages',
            array(
                'acfw-store-credits' => array(
                    'slug'  => 'acfw-store-credits',
                    'label' => __( 'Manage Store Credits', 'advanced-coupons-for-woocommerce-free' ),
                    'page'  => 'store_credits_page',
                ),
                'acfw-settings'      => array(
                    'slug'  => 'acfw-settings',
                    'label' => __( 'Settings', 'advanced-coupons-for-woocommerce-free' ),
                    'page'  => 'settings_page',
                ),
                'acfw-license'       => array(
                    'slug'  => 'acfw-license',
                    'label' => __( 'License', 'advanced-coupons-for-woocommerce-free' ),
                    'page'  => 'license_page',
                ),
                'acfw-help'          => array(
                    'slug'  => 'acfw-help',
                    'label' => __( 'Help', 'advanced-coupons-for-woocommerce-free' ),
                    'page'  => 'help_page',
                ),
                'acfw-about'         => array(
                    'slug'  => 'acfw-about',
                    'label' => __( 'About', 'advanced-coupons-for-woocommerce-free' ),
                    'page'  => 'about_page',
                ),
            ),
            true // Deprecated filter parameter.
        );

        if ( ! $this->_helper_functions->is_module( Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            unset( $this->_app_pages['acfw-store-credits'] );
        }

        foreach ( $this->_app_pages as $key => $app_page ) {

            if ( ! $app_page['slug'] ) {
                continue;
            }

            add_submenu_page(
                $toplevel_menu,
                $app_page['label'],
                $app_page['label'],
                'manage_woocommerce',
                $app_page['slug'],
                array( $this, 'display_settings_app' )
            );

            if ( $this->_helper_functions->is_wc_admin_active() && function_exists( 'wc_admin_connect_page' ) ) {

                wc_admin_connect_page(
                    array(
                        'id'        => $key,
                        'title'     => __( 'Advanced Coupons', 'advanced-coupons-for-woocommerce-free' ),
                        'screen_id' => 'coupons_page_' . $key,
                        'path'      => 'admin.php?page=' . $key,
                        'js_page'   => false,
                    )
                );
            }
        }
    }

    /**
     * Display settings app.
     *
     * @since 1.2
     * @access public
     */
    public function display_settings_app() {
        echo '<div class="wrap">';
        echo '<hr class="wp-header-end">';
        echo '<div id="acfw_admin_app"></div>';

        do_action( 'acfw_admin_app' );

        echo '</div>'; // end .wrap class.
    }

    /**
     * Enqueue settings react app styles and scripts.
     *
     * @since 1.2
     * @since 4.3   Load scripts on dashboard page.
     * @since 4.3.3 Add additiona texts for the free guide form. Remove unused JS files on admin app development mode.
     * @access public
     *
     * @param WP_Screen $screen    Current screen object.
     * @param string    $post_type Screen post type.
     */
    public function register_react_scripts( $screen, $post_type ) {
        // get the actual app page from screen id.
        $temp         = explode( '_page_', $screen->id );
        $current_page = isset( $temp[1] ) ? $temp[1] : '';

        $app_page_keys   = array_keys( $this->_app_pages );
        $app_page_keys[] = 'acfw-dashboard';

        if ( ! is_array( $this->_app_pages ) || ! in_array( $current_page, $app_page_keys, true ) ) {
            return;
        }

        // Important: Must enqueue this script in order to use WP REST API via JS.
        wp_enqueue_script( 'wp-api' );

        wp_localize_script(
            'wp-api',
            'acfwAdminApp',
            apply_filters(
                'acfwf_admin_app_localized',
                array(
                    'logo_alt'                => __( 'Advanced Coupons', 'advanced-coupons-for-woocommerce-free' ),
                    'admin_url'               => admin_url(),
                    'title'                   => __( 'Settings', 'advanced-coupons-for-woocommerce-free' ),
                    'desc'                    => __( 'Adjust the global settings options for Advanced Coupons for WooCommerce.', 'advanced-coupons-for-woocommerce-free' ),
                    'logo'                    => $this->_constants->IMAGES_ROOT_URL . 'acfw-logo.png',
                    'link_logo'               => $this->_helper_functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'logo' ),
                    'link_upgrade'            => $this->_helper_functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'upgrade' ),
                    'coupon_nav'              => array(
                        'toplevel'  => __( 'Coupons', 'advanced-coupons-for-woocommerce-free' ),
                        'dashboard' => __( 'Dashboard', 'advanced-coupons-for-woocommerce-free' ),
                        'links'     => array(
                            array(
                                'link' => admin_url( 'edit.php?post_type=shop_coupon' ),
                                'text' => __( 'All Coupons', 'advanced-coupons-for-woocommerce-free' ),
                            ),
                            array(
                                'link' => admin_url( 'post-new.php?post_type=shop_coupon' ),
                                'text' => __( 'Add New', 'advanced-coupons-for-woocommerce-free' ),
                            ),
                            array(
                                'link' => admin_url( 'edit-tags.php?taxonomy=shop_coupon_cat&post_type=shop_coupon' ),
                                'text' => __( 'Coupon Categories', 'advanced-coupons-for-woocommerce-free' ),
                            ),
                        ),
                    ),
                    'validation'              => array(
                        'default' => __( 'Please enter a valid value.', 'advanced-coupons-for-woocommerce-free' ),
                    ),
                    'app_pages'               => array_values( $this->_app_pages ),
                    'action_notices'          => array(
                        'success' => __( 'successfully updated', 'advanced-coupons-for-woocommerce-free' ),
                        'fail'    => __( 'failed to update', 'advanced-coupons-for-woocommerce-free' ),
                    ),
                    'premium_upsell'          => false,
                    'dashboard_page'          => array(
                        'title'                => __( 'Dashboard', 'advanced-coupons-for-woocommerce-free' ),
                        'report_error'         => __( 'The report data could not be loaded. Please reload the page to try again.', 'advanced-coupons-for-woocommerce-free' ),
                        'create_coupon'        => array(
                            'label'      => __( 'Quick Create', 'advanced-coupons-for-woocommerce-free' ),
                            'percentage' => __( 'New % Coupon', 'advanced-coupons-for-woocommerce-free' ),
                            'fixed'      => __( 'New Fixed Coupon', 'advanced-coupons-for-woocommerce-free' ),
                            'bogo'       => __( 'New BOGO Coupon', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                        'resources_links'      => array(
                            array(
                                'key'   => 'getting_started',
                                'slug'  => 'getting_started',
                                'label' => __( 'Getting Started Guides', 'advanced-coupons-for-woocommerce-free' ),
                                'link'  => $this->_helper_functions->get_utm_url( 'kb/getting-started/', 'acfwf', 'dashboard', 'gettingstartedguideslink' ),
                            ),
                            array(
                                'key'   => 'documentation',
                                'slug'  => 'documentation',
                                'label' => __( 'Read Documentation', 'advanced-coupons-for-woocommerce-free' ),
                                'link'  => $this->_helper_functions->get_utm_url( 'knowledge-base/', 'acfwf', 'dashboard', 'readdocslink' ),
                            ),
                            array(
                                'key'   => 'settings',
                                'slug'  => 'settings',
                                'label' => __( 'Settings', 'advanced-coupons-for-woocommerce-free' ),
                                'link'  => 'acfw-settings',
                            ),
                            array(
                                'key'   => 'support',
                                'slug'  => 'support',
                                'label' => __( 'Contact Support', 'advanced-coupons-for-woocommerce-free' ),
                                'link'  => $this->_helper_functions->get_contact_support_link(),
                            ),
                        ),
                        'labels'               => array(
                            'coupon'                    => __( 'Coupon', 'advanced-coupons-for-woocommerce-free' ),
                            'uses'                      => __( 'Uses', 'advanced-coupons-for-woocommerce-free' ),
                            'discounted'                => __( 'Discounted', 'advanced-coupons-for-woocommerce-free' ),
                            'active'                    => __( 'Active', 'advanced-coupons-for-woocommerce-free' ),
                            'inactive'                  => __( 'Inactive', 'advanced-coupons-for-woocommerce-free' ),
                            'expired'                   => __( 'Expired', 'advanced-coupons-for-woocommerce-free' ),
                            'learn_more'                => __( 'Learn more →', 'advanced-coupons-for-woocommerce-free' ),
                            'helpful_resources'         => __( 'Helpful Resources', 'advanced-coupons-for-woocommerce-free' ),
                            'license_activation_status' => __( 'License Activation Status', 'advanced-coupons-for-woocommerce-free' ),
                            'view_licenses'             => __( 'View Licenses →', 'advanced-coupons-for-woocommerce-free' ),
                            'notices'                   => __( 'Notices', 'advanced-coupons-for-woocommerce-free' ),
                            'dismiss'                   => __( 'Dismiss', 'advanced-coupons-for-woocommerce-free' ),
                            'view_all'                  => __( 'View all', 'advanced-coupons-for-woocommerce-free' ),
                            'hide'                      => __( 'Hide', 'advanced-coupons-for-woocommerce-free' ),
                            'free_plugin'               => __( 'Free Plugin', 'advanced-coupons-for-woocommerce-free' ),
                            'recommended'               => __( 'Recommended', 'advanced-coupons-for-woocommerce-free' ),
                            'install_and_activate'      => __( 'Install & Activate', 'advanced-coupons-for-woocommerce-free' ),
                            'installed_successfully'    => __( 'has been installed successfully!', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                        'coupons_list_link'    => admin_url( 'edit.php?post_type=shop_coupon' ),
                        'cross_promo_products' => array(
                            array(
                                'key'         => 'woocommerce-wholesale-prices',
                                'name'        => 'WooCommerce Wholesale Prices',
                                'slug'        => 'woocommerce-wholesale-prices',
                                'description' => __( 'Complete wholesale solution for WooCommerce with pricing, user roles, and order management.', 'advanced-coupons-for-woocommerce-free' ),
                                'logo'        => $this->_constants->IMAGES_ROOT_URL . 'wws-icon.png',
                                'badges'      => array( 'free', 'recommended' ),
                                'is_active'   => $this->_helper_functions->is_plugin_active( Plugin_Constants::WWP_PLUGIN_BASENAME ),
                            ),
                            array(
                                'key'         => 'wc-vendors',
                                'name'        => 'WC Vendors Marketplace',
                                'slug'        => 'wc-vendors',
                                'description' => __( 'Create a multivendor marketplace and earn commission from every sale.', 'advanced-coupons-for-woocommerce-free' ),
                                'logo'        => $this->_helper_functions->get_wp_org_plugin_icon_url( 'wc-vendors' ),
                                'badges'      => array( 'free', 'recommended' ),
                                'is_active'   => $this->_helper_functions->is_plugin_active( Plugin_Constants::WC_VENDORS_PLUGIN ),
                            ),
                            array(
                                'key'         => 'woocommerce-store-toolkit',
                                'name'        => 'Store Toolkit for WooCommerce',
                                'slug'        => 'woocommerce-store-toolkit',
                                'description' => __( 'Essential WooCommerce admin tools for bulk operations and store management.', 'advanced-coupons-for-woocommerce-free' ),
                                'logo'        => $this->_helper_functions->get_wp_org_plugin_icon_url( 'woocommerce-store-toolkit' ),
                                'badges'      => array( 'free', 'recommended' ),
                                'is_active'   => $this->_helper_functions->is_plugin_active( Plugin_Constants::STORE_TOOLKIT_PLUGIN ),
                            ),
                            array(
                                'key'         => 'woo-product-feed-pro',
                                'name'        => 'Product Feed Pro',
                                'slug'        => 'woo-product-feed-pro',
                                'description' => __( 'Generate and manage product feeds for Google Shopping, Facebook, and more.', 'advanced-coupons-for-woocommerce-free' ),
                                'logo'        => $this->_helper_functions->get_wp_org_plugin_icon_url( 'woo-product-feed-pro' ),
                                'badges'      => array( 'free', 'recommended' ),
                                'is_active'   => $this->_helper_functions->is_plugin_active( Plugin_Constants::PRODUCT_FEED_PRO_PLUGIN ),
                            ),
                            array(
                                'key'         => 'storeagent-ai-for-woocommerce',
                                'name'        => 'StoreAgent AI for WooCommerce',
                                'slug'        => 'storeagent-ai-for-woocommerce',
                                'description' => __( 'AI-powered automation for WooCommerce stores with intelligent agents.', 'advanced-coupons-for-woocommerce-free' ),
                                'logo'        => $this->_helper_functions->get_wp_org_plugin_icon_url( 'storeagent-ai-for-woocommerce' ),
                                'badges'      => array( 'free', 'recommended' ),
                                'is_active'   => $this->_helper_functions->is_plugin_active( Plugin_Constants::STOREAGENT_AI_PLUGIN ),
                            ),
                            array(
                                'key'         => 'saveto-wishlist-lite-for-woocommerce',
                                'name'        => 'SaveTo Wishlist Lite for WooCommerce',
                                'slug'        => 'saveto-wishlist-lite-for-woocommerce',
                                'description' => __( 'Adds a wishlist button to product and shop pages so customers can save items, manage their wishlist, and return later to purchase—helping boost engagement and sales.', 'advanced-coupons-for-woocommerce-free' ),
                                'logo'        => $this->_helper_functions->get_wp_org_plugin_icon_url( 'saveto-wishlist-lite-for-woocommerce' ),
                                'badges'      => array( 'free', 'recommended' ),
                                'is_active'   => $this->_helper_functions->is_plugin_active( Plugin_Constants::SAVETO_WISHLIST_LITE_PLUGIN ),
                            ),
                        ),
                    ),
                    'license_page'            => array(
                        'title'              => __( 'Advanced Coupons License Activation', 'advanced-coupons-for-woocommerce-free' ),
                        'desc'               => __( 'Advanced Coupons comes in two versions - the free version (with feature limitations) and the Premium add-on.', 'advanced-coupons-for-woocommerce-free' ),
                        'feature_comparison' => array(
                            'link' => apply_filters( 'acfwp_upsell_link', $this->_helper_functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'licensefeaturecomparison' ) ),
                            'text' => __( 'See feature comparison ', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                        'license_status'     => array(
                            'label' => __( 'Your current license for Advanced Coupons:', 'advanced-coupons-for-woocommerce-free' ),
                            'link'  => apply_filters( 'acfwp_upsell_link', $this->_helper_functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'licenseupgradetopremium' ) ),
                            'text'  => __( 'Upgrade To Premium', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                        'content'            => array(
                            'title' => __( 'Free Version', 'advanced-coupons-for-woocommerce-free' ),
                            'text'  => __( 'You are currently using Advanced Coupons for WooCommerce Free on a GPL license. The free version includes a heap of great extra features for your WooCommerce coupons. The only requirement for the free version is that you have WooCommerce installed.', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                        'specs'              => array(
                            array(
                                'label' => __( 'Plan', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => __( 'Free Version', 'advanced-coupons-for-woocommerce-free' ),
                            ),
                            array(
                                'label' => __( 'Version', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => Plugin_Constants::VERSION,
                            ),
                        ),
                    ),
                    'license_tabs'            => array(
                        array(
                            'key'   => 'ACFW',
                            'label' => __( 'Advanced Coupons', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                    ),
                    'help_page'               => array(
                        'title' => __( 'Getting Help', 'advanced-coupons-for-woocommerce-free' ),
                        'desc'  => __( 'We\'re here to help you get the most out of Advanced Coupons for WooCommerce.', 'advanced-coupons-for-woocommerce-free' ),
                        'cards' => array(
                            array(
                                'title'   => __( 'Knowledge Base', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'Access our self-service help documentation via the Knowledge Base. You\'ll find answers and solutions for a wide range of well known situations. You\'ll also find a Getting Started guide here for the plugin.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => array(
                                    'link' => $this->_helper_functions->get_utm_url( 'knowledge-base/', 'acfwf', 'helppage', 'helpkbbutton' ),
                                    'text' => __( 'Open Knowledge Base', 'advanced-coupons-for-woocommerce-free' ),
                                ),
                            ),
                            array(
                                'title'   => __( 'Free Version WordPress.org Help Forums', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'Our support staff regularly check and help our free users at the official plugin WordPress.org help forums. Submit a post there with your question and we\'ll get back to you as soon as possible.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => array(
                                    'link' => 'https://wordpress.org/support/plugin/advanced-coupons-for-woocommerce-free/',
                                    'text' => __( 'Visit WordPress.org Forums', 'advanced-coupons-for-woocommerce-free' ),
                                ),
                            ),
                        ),
                    ),
                    'free_guide'              => array(
                        'show'                => 'yes' !== get_user_meta( get_current_user_id(), '_acfwf_hide_free_guide_form', true ),
                        'tag'                 => __( 'Recommended', 'advanced-coupons-for-woocommerce-free' ),
                        'title'               => __( 'FREE GUIDE: How To Grow A WooCommerce Store Using Coupons', 'advanced-coupons-for-woocommerce-free' ),
                        'subtitle'            => __( 'The key to growing an online store is promoting it!', 'advanced-coupons-for-woocommerce-free' ),
                        'content'             => sprintf(
                            /* Translators: %1$s: opening bold tag. %2$s: closing bold tag. */
                            __( 'If you\'ve ever wanted to grow a store to 6, 7 or 8-figures and beyond %1$sdownload this guide%2$s now. You\'ll learn how smart store owners are using coupons to grow their WooCommerce stores.', 'advanced-coupons-for-woocommerce-free' ),
                            '<strong>',
                            '</strong>'
                        ),
                        'image'               => $this->_constants->IMAGES_ROOT_URL . 'coupons-free-guide.png',
                        'button'              => array(
                            'link'      => $this->_helper_functions->get_utm_url( 'how-to-grow-your-woocommerce-store-with-coupons/', 'acfwf', 'settings', 'helpfreeguidebutton' ),
                            'text'      => __( 'Get FREE Training Guide', 'advanced-coupons-for-woocommerce-free' ),
                            'help_link' => $this->_helper_functions->get_utm_url( 'how-to-grow-your-woocommerce-store-with-coupons/', 'acfwf', 'helppage', 'helpfreeguidebutton' ),
                        ),
                        'list'                => array(
                            __( 'How "smart store owners" use coupons differently', 'advanced-coupons-for-woocommerce-free' ),
                            __( '3x hot deals that you can implement NOW to increase sales permanently', 'advanced-coupons-for-woocommerce-free' ),
                            __( 'How to get 4-10x the sales on your next once-off coupon campaign', 'advanced-coupons-for-woocommerce-free' ),
                            __( 'The tools you need to run these deals in your WooCommerce store', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                        'field_values'        => array(
                            'name'  => get_user_meta( get_current_user_id(), 'first_name', true ),
                            'email' => wp_get_current_user()->data->user_email,
                        ),
                        'placeholders'        => array(
                            'name'  => __( 'Enter your first name here...', 'advanced-coupons-for-woocommerce-free' ),
                            'email' => __( 'Enter your email here...', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                        'form_nonce'          => wp_create_nonce( 'acfwf_get_free_training_guide' ),
                        'missing_form_fields' => __( 'Please fill out your name and/or email correctly.', 'advanced-coupons-for-woocommerce-free' ),
                        'failed_form_error'   => __( 'There was an error trying to process your request. Please refresh the page and try again.', 'advanced-coupons-for-woocommerce-free' ),
                    ),
                    'about_page'              => array(
                        'title'        => __( 'About Advanced Coupons', 'advanced-coupons-for-woocommerce-free' ),
                        'desc'         => __( 'Hello and welcome to Advanced Coupons, the plugin that makes your WooCommerce coupons better!', 'advanced-coupons-for-woocommerce-free' ),
                        'main_card'    => array(
                            'title'   => __( 'About The Makers - Rymera Web Co', 'advanced-coupons-for-woocommerce-free' ),
                            'content' => array(
                                __( 'Over the years we\'ve worked with thousands of smart store owners that were frustrated with the options for promoting their WooCommerce stores.', 'advanced-coupons-for-woocommerce-free' ),
                                __( 'That\'s why we decided to make Advanced Coupons - a state of the art coupon feature extension plugin that delivers on the promise of "making your store\'s marketing better."', 'advanced-coupons-for-woocommerce-free' ),
                                __( 'Advanced Coupons is brought to you by the same team that\'s behind the largest and most comprehensive wholesale plugin for WooCommerce, Wholesale Suite. We\'ve also been in the WordPress space for over a decade.', 'advanced-coupons-for-woocommerce-free' ),
                                __( 'We\'re thrilled you\'re using our tool and invite you to try our other tools as well.', 'advanced-coupons-for-woocommerce-free' ),
                            ),
                            'image'   => $this->_constants->IMAGES_ROOT_URL . 'rymera-team.jpg',
                        ),
                        'cards'        => array(
                            array(
                                'icon'    => $this->_constants->IMAGES_ROOT_URL . 'acfw-icon.png',
                                'title'   => __( 'Advanced Coupons (Premium Version)', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'Premium adds even more great coupon features, unlocks all of the Cart Conditions, advanced BOGO functionality, lets you add products with a coupon, gives you auto-apply and one-click notifications and loads more.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => $this->_get_plugin_action_link( 'advanced-coupons-for-woocommerce-premium' ),
                            ),
                            array(
                                'icon'    => $this->_constants->IMAGES_ROOT_URL . 'wws-icon.png',
                                'title'   => __( 'WooCommerce Wholesale Prices', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'WooCommerce Wholesale Prices gives WooCommerce store owners the ability to supply specific users with wholesale pricing for their product range. We\'ve made entering wholesale prices as simple as it should be.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => $this->_get_plugin_action_link( 'woocommerce-wholesale-prices' ),
                            ),
                            array(
                                'icon'    => $this->_helper_functions->get_wp_org_plugin_icon_url( 'storeagent-ai-for-woocommerce' ),
                                'title'   => __( 'StoreAgent AI for WooCommerce', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'Get AI Agents for WooCommerce with StoreAgent.ai, the free AI-powered plugin designed to automate tasks, personalize customer interactions, and optimize your eCommerce operations.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => $this->_get_plugin_action_link( 'storeagent-ai-for-woocommerce' ),
                            ),
                            array(
                                'icon'    => $this->_helper_functions->get_wp_org_plugin_icon_url( 'woo-product-feed-pro' ),
                                'title'   => __( 'Product Feed Pro', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'Helps you generate and manage product feeds for various marketing channels, such as Google Shopping, Facebook, and more, to optimize your eCommerce store\'s visibility and sales.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => $this->_get_plugin_action_link( 'woo-product-feed-pro' ),
                            ),
                            array(
                                'icon'    => $this->_helper_functions->get_wp_org_plugin_icon_url( 'wc-vendors' ),
                                'title'   => __( 'WC Vendors Marketplace', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'Easiest way to create your multivendor marketplace and earn commission from every sale. Create a WooCommerce marketplace with multi-seller, product vendor & multi vendor commissions.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => $this->_get_plugin_action_link( 'wc-vendors' ),
                            ),
                            array(
                                'icon'    => $this->_helper_functions->get_wp_org_plugin_icon_url( 'invoice-gateway-for-woocommerce' ),
                                'title'   => __( 'Invoice Gateway for WooCommerce', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'Accept orders via a special invoice payment gateway method which lets your customer enter their order without upfront payment. Then just issue an invoice from your accounting system and paste in the number.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => $this->_get_plugin_action_link( 'invoice-gateway-for-woocommerce' ),
                            ),
                            array(
                                'icon'    => $this->_helper_functions->get_wp_org_plugin_icon_url( 'woocommerce-store-toolkit' ),
                                'title'   => __( 'Store Toolkit for WooCommerce', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'A growing set of commonly-used WooCommerce admin tools such as deleting WooCommerce data in bulk, such as products, orders, coupons, and customers. It also adds extra small features, order filtering, and more.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => $this->_get_plugin_action_link( 'woocommerce-store-toolkit' ),
                            ),
                            array(
                                'icon'    => $this->_helper_functions->get_wp_org_plugin_icon_url( 'woocommerce-exporter' ),
                                'title'   => __( 'Store Exporter for WooCommerce', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'Easily export Orders, Subscriptions, Coupons, Products, Categories, Tags to a variety of formats. The deluxe version also adds scheduled exporting for easy reporting and syncing with other systems.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => $this->_get_plugin_action_link( 'woocommerce-exporter' ),
                            ),
                            array(
                                'icon'    => $this->_helper_functions->get_wp_org_plugin_icon_url( 'saveto-wishlist-lite-for-woocommerce' ),
                                'title'   => __( 'SaveTo Wishlist Lite for WooCommerce', 'advanced-coupons-for-woocommerce-free' ),
                                'content' => __( 'Adds a wishlist button to product and shop pages so customers can save items, manage their wishlist, and return later to purchase, helping boost engagement and sales.', 'advanced-coupons-for-woocommerce-free' ),
                                'action'  => $this->_get_plugin_action_link( 'saveto-wishlist-lite-for-woocommerce' ),
                            ),
                        ),
                        'status'       => __( 'Status', 'advanced-coupons-for-woocommerce-free' ),
                        'status_texts' => array(
                            'not_installed' => __(
                                'Not installed',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'installed'     => __(
                                'Installed',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'active'        => __(
                                'Active',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                        ),
                        'button_texts' => array(
                            'not_installed' => __(
                                'Install Plugin',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'installed'     => __(
                                'Activate Plugin',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                        ),
                    ),
                    'store_credits_page'      => array(
                        'title'              => __(
                            'Store Credits Dashboard',
                            'advanced-coupons-for-woocommerce-free'
                        ),
                        'currency'           => array(
                            'decimal_separator'  => wc_get_price_decimal_separator(),
                            'thousand_separator' => wc_get_price_thousand_separator(),
                            'decimals'           => wc_get_price_decimals(),
                            'symbol'             => html_entity_decode( get_woocommerce_currency_symbol() ),
                        ),
                        'tabs'               => array(
                            array(
                                'label' => __( 'Dashboard', 'advanced-coupons-for-woocommerce-free' ),
                                'key'   => 'dashboard',
                            ),
                            array(
                                'label' => __( 'Customers', 'advanced-coupons-for-woocommerce-free' ),
                                'key'   => 'customers',
                            ),
                            array(
                                'label' => __( 'Automations', 'advanced-coupons-for-woocommerce-free' ),
                                'key'   => 'automations',
                            ),
                            array(
                                'label' => __( 'Bulk Adjust', 'advanced-coupons-for-woocommerce-free' ),
                                'key'   => 'bulk-adjust',
                            ),
                        ),
                        'bulk_adjust_upsell' => array(
                            'title'   => __( 'Bulk Adjust Store Credits', 'advanced-coupons-for-woocommerce-free' ),
                            'desc'    => __( 'Adjust store credit balances in bulk for groups of customers based on filters such as user roles, balance ranges, registration date, and last order date. This feature is available in the Premium version.', 'advanced-coupons-for-woocommerce-free' ),
                            'cta'     => __( 'Upgrade to Premium', 'advanced-coupons-for-woocommerce-free' ),
                            'cta_url' => apply_filters( 'acfwp_upsell_link', $this->_helper_functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'bulkadjustupsell' ) ),
                        ),
                        'period_options'     => array(
                            array(
                                'label' => __( 'Week to Date', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => 'week_to_date',
                            ),
                            array(
                                'label' => __( 'Month to Date', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => 'month_to_date',
                            ),
                            array(
                                'label' => __( 'Quarter to Date', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => 'quarter_to_date',
                            ),
                            array(
                                'label' => __( 'Year to Date', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => 'year_to_date',
                            ),
                            array(
                                'label' => __( 'Last Week', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => 'last_week',
                            ),
                            array(
                                'label' => __( 'Last Month', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => 'last_month',
                            ),
                            array(
                                'label' => __( 'Last Quarter', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => 'last_quarter',
                            ),
                            array(
                                'label' => __( 'Last Year', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => 'last_year',
                            ),
                            array(
                                'label' => __( 'Custom Range', 'advanced-coupons-for-woocommerce-free' ),
                                'value' => 'custom',
                            ),
                        ),
                        'adjust_modal'       => array(
                            'title'                   => __(
                                'Adjust Store Credit',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'description'             => __(
                                'Adjust Store credit for this user. Remember store credits are worth the same as your base currency in the store.',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'current_balance'         => __(
                                'Current balance: {balance}',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'new_balance'             => __(
                                'New balance: {balance}',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'increase'                => __(
                                'Increase Store Credit',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'decrease'                => __(
                                'Decrease Store Credit',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'invalid_price'           => __(
                                'The price entered is not valid',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'make_adjustment'         => __(
                                'Make Adjustment',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'add_note'                => __(
                                'Add Note',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'note_placeholder'        => __(
                                'Enter a note for this adjustment',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'send_email_notification' => __(
                                'Send email notification to customer',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                        ),
                        'labels'             => array(
                            'status'         => __(
                                'Store Credits Status',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'statistics'     => __(
                                'Store Credits Statistics',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'sources'        => __(
                                'Store Credits Sources',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'source'         => __(
                                'Source',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'amount'         => sprintf(
                                /* Translators: %s: store currency symbol. */
                                __(
                                    'Amount (%s)',
                                    'advanced-coupons-for-woocommerce-free'
                                ),
                                html_entity_decode( get_woocommerce_currency_symbol() )
                            ),
                            'customers_list' => __(
                                'Customers List',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'search_label'   => __(
                                'Search by name or email',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'customer_name'  => __(
                                'Customer Name',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'email'          => __(
                                'Email',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'balance'        => __(
                                'Store Credit Balance',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'view_stats'     => __(
                                'View Stats',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'adjust'         => __(
                                'Adjust',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'history'        => __(
                                'Store Credit History',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'date'           => __(
                                'Date',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'activity'       => __(
                                'Activity',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                            'related'        => __(
                                'Related',
                                'advanced-coupons-for-woocommerce-free'
                            ),
                        ),
                    ),
                    'condition_options'       => array(
                        'exactly'        => __(
                            'EXACTLY',
                            'advanced-coupons-for-woocommerce-free'
                        ),
                        'anyexcept'      => __(
                            'ANYTHING EXCEPT',
                            'advanced-coupons-for-woocommerce-free'
                        ),
                        'morethan'       => __(
                            'MORE THAN',
                            'advanced-coupons-for-woocommerce-free'
                        ),
                        'lessthan'       => __(
                            'LESS THAN',
                            'advanced-coupons-for-woocommerce-free'
                        ),
                        'atleast'        => __(
                            'AT LEAST',
                            'advanced-coupons-for-woocommerce-free'
                        ),
                        'all'            => __(
                            'ALL',
                            'advanced-coupons-for-woocommerce-free'
                        ),
                        'withinaperiod'  => __(
                            'Within a period',
                            'advanced-coupons-for-woocommerce-free'
                        ),
                        'numberoforders' => __(
                            'Number of orders',
                            'advanced-coupons-for-woocommerce-free'
                        ),
                    ),
                    'nonces'                  => array(
                        'search_products'       => wp_create_nonce( 'search-products' ),
                        'search_customers'      => wp_create_nonce( 'search-customers' ),
                        'search_taxonomy_terms' => wp_create_nonce( 'search-taxonomy-terms' ),
                        'install_plugin'        => wp_create_nonce( 'acfw_install_plugin' ),
                    ),
                    'is_acfwp_active'         => (bool) $this->_helper_functions->is_plugin_active( Plugin_Constants::PREMIUM_PLUGIN ),
                    'is_storeagent_connected' => apply_filters( 'acfwf_is_storeagent_connected', false ),
                    'is_storeagent_installed' => $this->_helper_functions->is_plugin_installed( Plugin_Constants::STOREAGENT_AI_PLUGIN ),
                    'is_storeagent_active'    => $this->_helper_functions->is_plugin_active( Plugin_Constants::STOREAGENT_AI_PLUGIN ),
                    'storeagent_plugin_slug'  => dirname( Plugin_Constants::STOREAGENT_AI_PLUGIN ),
                    'storeagent_install_url'  => admin_url( 'plugin-install.php?s=storeagent&tab=search&type=term' ),
                    'storeagent_connect_url'  => admin_url( 'admin.php?page=storeagent-dashboard' ),
                    'storeagent_website_url'  => 'https://storeagent.ai/?utm_source=couponai&utm_medium=admin&utm_campaign=poweredby',
                    'storeagent_logo_url'     => $this->_constants->IMAGES_ROOT_URL . 'storeagent-logo.png',
                    'admin_ajax_url'          => admin_url( 'admin-ajax.php' ),
                    'ai_generator'            => array(
                        'title'              => __( 'Generate Coupon with AI', 'advanced-coupons-for-woocommerce-free' ),
                        'description'        => __( 'Describe the promotion you want to create and let AI generate a coupon template for you.', 'advanced-coupons-for-woocommerce-free' ),
                        'placeholder'        => __( 'e.g., "20% off for first-time customers who spend over $50"', 'advanced-coupons-for-woocommerce-free' ),
                        'generate_btn'       => __( 'Generate with AI', 'advanced-coupons-for-woocommerce-free' ),
                        'continue_btn'       => __( 'Continue with AI Template', 'advanced-coupons-for-woocommerce-free' ),
                        'generating'         => __( 'Generating...', 'advanced-coupons-for-woocommerce-free' ),
                        'limitations_notice' => __( 'Note: Currently does not support BOGO, shipping overrides, or adding products.', 'advanced-coupons-for-woocommerce-free' ),
                        'examples_title'     => __( 'Example prompts:', 'advanced-coupons-for-woocommerce-free' ),
                        'examples'           => array(
                            __( '20% off for first-time customers on orders over $50', 'advanced-coupons-for-woocommerce-free' ),
                            __( '10% discount for VIP members on all products', 'advanced-coupons-for-woocommerce-free' ),
                            __( '$15 off on Electronics category for orders above $100', 'advanced-coupons-for-woocommerce-free' ),
                            __( '25% off for newsletter subscribers this weekend', 'advanced-coupons-for-woocommerce-free' ),
                            __( '30% discount on Fall collection for returning customers', 'advanced-coupons-for-woocommerce-free' ),
                            __( 'Buy from Home Decor category and get 15% off', 'advanced-coupons-for-woocommerce-free' ),
                            __( '$20 off for wholesale customers on orders over $200', 'advanced-coupons-for-woocommerce-free' ),
                            __( '10% off site-wide for loyalty program members', 'advanced-coupons-for-woocommerce-free' ),
                            __( '40% discount on Clearance items for all customers', 'advanced-coupons-for-woocommerce-free' ),
                            __( '15% off for customers who spent over $500 in the past', 'advanced-coupons-for-woocommerce-free' ),
                            __( '$10 off on first purchase for new registered users', 'advanced-coupons-for-woocommerce-free' ),
                            __( '20% discount on Apparel category this month only', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                        'error_min_chars'    => __( 'Please provide a more detailed description (at least 10 characters).', 'advanced-coupons-for-woocommerce-free' ),
                        'error_generate'     => __( 'Failed to generate coupon. Please try again.', 'advanced-coupons-for-woocommerce-free' ),
                    ),
                    'storeagent_upsell'       => array(
                        'install_title'        => __( 'Create Coupons with AI', 'advanced-coupons-for-woocommerce-free' ),
                        'install_description'  => __( 'Generate ready-to-use coupons in seconds just by describing your promotion. Powered by StoreAgent AI — a free plugin that brings AI Coupon Generation, Product Descriptions, Review Summaries, and more to your WooCommerce store.', 'advanced-coupons-for-woocommerce-free' ),
                        'install_button'       => __( 'Install StoreAgent AI (Free)', 'advanced-coupons-for-woocommerce-free' ),
                        'install_success'      => __( 'StoreAgent AI installed and activated successfully!', 'advanced-coupons-for-woocommerce-free' ),
                        'install_error'        => __( 'Failed to install StoreAgent AI. Please try again.', 'advanced-coupons-for-woocommerce-free' ),
                        'activate_title'       => __( 'Almost There! Activate StoreAgent AI', 'advanced-coupons-for-woocommerce-free' ),
                        'activate_description' => __( 'StoreAgent AI is installed and ready to go. Activate it to start generating coupons with AI in just a few clicks.', 'advanced-coupons-for-woocommerce-free' ),
                        'activate_button'      => __( 'Activate StoreAgent AI', 'advanced-coupons-for-woocommerce-free' ),
                        'activate_success'     => __( 'StoreAgent AI activated successfully!', 'advanced-coupons-for-woocommerce-free' ),
                        'connect_title'        => __( 'One Last Step — Connect StoreAgent AI', 'advanced-coupons-for-woocommerce-free' ),
                        'connect_description'  => __( 'Connect your store to StoreAgent AI to unlock AI-powered coupon generation and other smart features for your WooCommerce store.', 'advanced-coupons-for-woocommerce-free' ),
                        'connect_button'       => __( 'Connect StoreAgent AI', 'advanced-coupons-for-woocommerce-free' ),
                        'learn_more'           => __( 'Learn More', 'advanced-coupons-for-woocommerce-free' ),
                        'cancel'               => __( 'Cancel', 'advanced-coupons-for-woocommerce-free' ),
                    ),
                )
            )
        );

        wp_localize_script(
            'wp-api',
            'acfwpElements',
            array(
                'is_acfwp_active' => (int) $this->_helper_functions->is_plugin_active( Plugin_Constants::PREMIUM_PLUGIN ),
                'is_lpfw_active'  => (int) $this->_helper_functions->is_plugin_active( Plugin_Constants::LOYALTY_PLUGIN ),
                'is_agc_active'   => (int) $this->_helper_functions->is_plugin_active( Plugin_Constants::GIFT_CARDS_PLUGIN ),
                'is_apk_active'   => (int) $this->_helper_functions->is_plugin_active( Plugin_Constants::PROMO_KIT_PLUGIN ),
            )
        );

        do_action( 'acfw_admin_app_enqueue_scripts_before', $screen, $post_type, false ); // last parameter deprecated.

        wp_enqueue_script( 'acfw-axios', $this->_constants->JS_ROOT_URL . '/lib/axios/axios.min.js', array(), Plugin_Constants::VERSION, true );

        $app_js_vite = new Vite_App(
            'acfwf-admin-app',
            'packages/acfwf-admin-app/index.tsx',
            array( 'wp-api', 'wc-admin-app', 'moment' ),
        );
        $app_js_vite->enqueue();

        do_action( 'acfw_admin_app_enqueue_scripts_after', $screen, $post_type, false ); // last parameter deprecated.
    }

    /*
    |--------------------------------------------------------------------------
    | Utility methods
    |--------------------------------------------------------------------------
     */

    /**
     * Get plugin action link.
     *
     * @since 4.6.5
     * @access private
     *
     * @param string $plugin_slug  Plugin slug.
     * @param string $plugin_file  Optional plugin file path. If not provided, will use the plugin_basename_by_slug.
     * @param string $plugin_key   Optional plugin key for installation. If not provided, will use the slug.
     * @param string $upsell_path  Optional upsell path for premium plugins.
     * @return array Plugin action link data.
     */
    private function _get_plugin_action_link( $plugin_slug, $plugin_file = '', $plugin_key = '', $upsell_path = '' ) {
        // Handle special case for ACFWP.
        if ( 'advanced-coupons-for-woocommerce-premium' === $plugin_slug ) {
            if ( $this->_helper_functions->is_plugin_active( Plugin_Constants::PREMIUM_PLUGIN ) ) {
                return array(
                    'status'      => 'active',
                    'link'        => '',
                    'plugin_slug' => $plugin_slug,
                );
            }

            if ( $this->_helper_functions->is_plugin_installed( Plugin_Constants::PREMIUM_PLUGIN ) ) {
                $basename = plugin_basename( Plugin_Constants::PREMIUM_PLUGIN );
                return array(
                    'status'      => 'installed',
                    'link'        => htmlspecialchars_decode( wp_nonce_url( 'plugins.php?action=activate&amp;plugin=' . $basename . '&amp;plugin_status=all&amp;s', 'activate-plugin_' . $basename ) ),
                    'external'    => false,
                    'plugin_slug' => $plugin_slug,
                );
            }

            $path = $upsell_path ? $upsell_path : 'pricing/';
            return array(
                'status'      => 'not_installed',
                'link'        => apply_filters( 'acfwp_upsell_link', $this->_helper_functions->get_utm_url( $path, 'acfwf', 'upsell', 'aboutpageupgradebutton' ) ),
                'external'    => true,
                'plugin_slug' => $plugin_slug,
            );
        }

        // For all other plugins.
        $basename = $this->_plugin_installer->get_plugin_basename_by_slug( $plugin_slug );

        // If still empty, use just the plugin slug as a fallback.
        if ( empty( $basename ) ) {
            $basename = $plugin_slug;
        }

        if ( $this->_helper_functions->is_plugin_active( $basename ) ) {
            return array(
                'status'      => 'active',
                'link'        => '',
                'external'    => false,
                'plugin_slug' => $plugin_slug,
            );
        }

        if ( $this->_helper_functions->is_plugin_installed( $basename ) ) {
            return array(
                'status'      => 'installed',
                'link'        => htmlspecialchars_decode( wp_nonce_url( 'plugins.php?action=activate&amp;plugin=' . $basename . '&amp;plugin_status=all&amp;s', 'activate-plugin_' . $basename ) ),
                'external'    => false,
                'plugin_slug' => $plugin_slug,
            );
        }

        $install_key = $plugin_key ? $plugin_key : $plugin_slug;
        return array(
            'status'      => 'not_installed',
            'link'        => htmlspecialchars_decode( wp_nonce_url( 'update.php?action=install-plugin&amp;plugin=' . $install_key, 'install-plugin_' . $install_key ) ),
            'external'    => false,
            'plugin_slug' => $plugin_slug,
        );
    }

    /**
     * Register the delete license status cache hooks.
     *
     * @since 4.3
     * @since 4.7.6 Also listen to the site option hooks, as the license forms write the flags via update_site_option().
     * @access private
     */
    private function _register_delete_license_status_cache_hooks() {
        $delete_cache = function () {
            delete_site_transient( Plugin_Constants::PREMIUM_LICENSE_STATUS_CACHE );
        };

        $license_activated_options = array(
            'acfw_license_activated',
            'lpfw_license_activated',
            'agcfw_license_activated',
        );

        /**
         * The license forms save these flags with update_site_option(). On a single site that also fires
         * update_option_*, but on multisite it doesn't, so the plain hook alone misses the write and the cached status
         * stays stale for up to a day. A first-ever write is delegated internally to add_network_option(), which fires
         * add_site_option_* instead. All three are registered so no write path is missed; delete_site_transient() is
         * idempotent, so the single site's double fire is harmless.
         */
        foreach ( $license_activated_options as $option ) {
            add_action( "update_option_{$option}", $delete_cache );
            add_action( "update_site_option_{$option}", $delete_cache );
            add_action( "add_site_option_{$option}", $delete_cache );
        }
    }

    /**
     * Check if we  need to delete transient/cache data for the report widgets in the dashboard when the order status is changed.
     *
     * @since 4.3
     * @access private
     *
     * @param int    $order_id Order ID.
     * @param string $prev_status Previous status.
     * @param string $new_status New status.
     */
    public function maybe_delete_dashboard_report_transients( $order_id, $prev_status, $new_status ) {
        if ( in_array( $prev_status, array( 'pending', 'failed', 'on-hold' ), true )
            && in_array( $new_status, wc_get_is_paid_statuses(), true )
        ) {
            $this->_delete_dashboard_report_transients();
        }
    }

    /**
     * Delete transient/cache data for the report widgets in the dashboard.
     *
     * @since 4.3
     * @access private
     */
    private function _delete_dashboard_report_transients() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%acfwf_dashboard_%'" );
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX methods
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX get free training guide.
     * Subscribe the user to our email campaign so they will receive the PDF download link in their emails.
     *
     * @since 4.3.2
     * @access public
     */
    public function ajax_get_free_training_guide() {
        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Invalid AJAX call', 'advanced-coupons-for-woocommerce-free' ),
            );
        } elseif ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'acfwf_get_free_training_guide' ) ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'You are not allowed to do this', 'advanced-coupons-for-woocommerce-free' ),
            );
        } else {

            // Sanitize input immediately after retrieval to prevent security vulnerabilities.
            $args = array(
                'name'      => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
                'email'     => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
                'title'     => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
                'url'       => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
                'referrer'  => isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '',
                'timestamp' => isset( $_POST['timestamp'] ) ? sanitize_text_field( wp_unslash( $_POST['timestamp'] ) ) : '',
            );

            // Validate email format.
            if ( ! empty( $args['email'] ) && ! is_email( $args['email'] ) ) {
                $args['email'] = '';
            }

            $name = explode( ' ', $args['name'] );

            // prepare drip form data.
            $form_data = array(
                'fields'   => array(
                    'comments' => '',
                    'name'     => $args['name'],
                    'email'    => $args['email'],
                ),
                'page'     => array(
                    'title' => $args['title'],
                    'url'   => $args['url'],
                ),
                'previous' => $args['referrer'],
                'referrer' => $args['url'],
                'site'     => '5a6588252b4b4',
                'tags'     => array(
                    'page_url'        => $args['url'],
                    'referer_url'     => $args['referrer'],
                    'referrer_url'    => $args['referrer'],
                    'pages_visited'   => '1',
                    'time_on_site'    => 5,
                    'visit_timestamp' => $args['timestamp'],
                    'page_title'      => $args['title'],
                    'cn'              => 'LP How To Grow Your WooCommerce Store With Coupons PDF',
                    'campaign_name'   => 'LP How To Grow Your WooCommerce Store With Coupons PDF',
                    'form_name'       => $args['name'],
                    'form_first_name' => isset( $name[0] ) ? $name[0] : '',
                    'form_last_name'  => isset( $name[1] ) ? $name[1] : '',
                    'form_email'      => $args['email'],
                ),
            );

            $is_failed = false;
            $raw_data  = wp_remote_retrieve_body(
                wp_remote_post(
                    'https://campaigns.advancedcouponsplugin.com/api/v2/optin/jrx2wts9wsgjqmff8mt1',
                    array(
                        'headers' => array(
                            'Accept'       => '*/*',
                            'Content-Type' => 'application/json',
                            'User-Agent'   => isset( $_SERVER['HTTP_USER_AGENT'] )
                                ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
                                : '',
                        ),
                        'body'    => wp_json_encode( $form_data ),
                    )
                )
            );

            if ( ! is_wp_error( $raw_data ) ) {

                $data = json_decode( $raw_data, true );

                if ( isset( $data['success'] ) && $data['success'] ) {
                    $response = array(
                        'status'  => 'success',
                        'message' => __( 'Please check your email to get the download link.', 'advanced-coupons-for-woocommerce-free' ),
                    );

                    // hide the form for user on next reload.
                    update_user_meta( get_current_user_id(), '_acfwf_hide_free_guide_form', 'yes' );
                } else {
                    $is_failed = true;
                }
            } else {
                $is_failed = true;
            }

            // return generic response on form submit failure.
            if ( $is_failed ) {
                $response = array(
                    'status'    => 'fail',
                    'error_msg' => __( 'There was an error trying to process your request. Please refresh the page and try again.', 'advanced-coupons-for-woocommerce-free' ),
                );
            }
        }

        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
        echo wp_json_encode( $response );
        wp_die();
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Contract for deactivate.
     *
     * @since 1.0.1
     * @access public
     * @implements ACFWF\Interfaces\Deactivatable_Interface
     */
    public function deactivate() {
        $this->_delete_dashboard_report_transients();
        delete_site_transient( Plugin_Constants::PREMIUM_LICENSE_STATUS_CACHE );
    }

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 1.2
     * @since 4.3.2 add AJAX hook for get free training guide.
     * @access public
     * @implements ACFWF\Interfaces\Initializable_Interface
     */
    public function initialize() {
        add_action( 'wp_ajax_acfwf_get_free_training_guide', array( $this, 'ajax_get_free_training_guide' ) );
    }

    /**
     * Execute Admin_App class.
     *
     * @since 1.2
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'acfw_register_admin_submenus', array( $this, 'register_submenus' ) );
        add_action( 'acfw_after_load_backend_scripts', array( $this, 'register_react_scripts' ), 10, 2 );

        $this->_register_delete_license_status_cache_hooks();
        add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_delete_dashboard_report_transients' ), 10, 3 );
    }
}
