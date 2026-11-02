<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Helpers\Helper_Functions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Coupon Tab module.
 * Public Model.
 *
 * @since 3.5.6
 */
class Coupon_Tab extends Base_Model implements Initiable_Interface, Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Class constructor.
     *
     * @since 3.5.6
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $main_plugin->add_to_all_plugin_models( $this );
    }

    /**
     * Register my coupons custom endpoint.
     *
     * @since 3.5.6
     * @access public
     */
    private function _register_coupon_tab_endpoint() {
        add_rewrite_endpoint( $this->_get_my_coupons_endpoint(), EP_ROOT | EP_PAGES );
    }

    /**
     * Register my coupons my account tab endpoint.
     *
     * @since 3.5.6
     * @access public
     *
     * @param array $vars WP query vars.
     * @return array Filtered query vars.
     */
    public function register_my_coupons_query_vars( $vars ) {
        $vars[] = $this->_get_my_coupons_endpoint();
        return $vars;
    }

    /**
     * Add custom my coupons page to my account.
     *
     * @since 3.5.6
     * @access public
     *
     * @param array $items My account menu items.
     * @return array Filtered my account menu items.
     */
    public function register_my_coupons_menu_item( $items ) {
        if ( ! is_user_logged_in() ) {
            return $items;
        }

        $filtered_items = array();
        foreach ( $items as $key => $item ) {
            $filtered_items[ $key ] = $item;
            // insert my accounts menu item after account details.
            if ( 'edit-account' === $key ) {
                $filtered_items[ $this->_get_my_coupons_endpoint() ] = __( 'My Coupons', 'advanced-coupons-for-woocommerce' );
            }
        }

        return $filtered_items;
    }

    /**
     * Display my coupons content.
     *
     * The order of function call is :
     * 1. Expired Coupons
     * 2. Owned Coupons
     * 3. Available Coupons
     *
     * The order is required to avoid duplicate coupons within the section.
     *
     * @since 3.5.6
     * @access public
     */
    public function my_coupons_page() {
        // Enqueue blocks frontend styles (needed after WP version 6.9.0).
        wp_enqueue_style( 'acfw-blocks-frontend' );

        \ACFWP()->Helper_Functions->load_template(
            'acfw-my-coupons.php',
            array(
                'usedexpired' => \ACFWP()->Coupon_Card->get_coupon_cards_used_or_expired(
                    array(
                        'display_type' => 'both', // Both means show regular and virtual coupons.
                        'columns'      => 2,
                    )
                ),
                'owned'       => \ACFWP()->Coupon_Card->get_coupon_cards_owned_by_customer(
                    array(
                        'display_type' => 'both', // Both means show regular and virtual coupons.
                        'columns'      => 2,
                    )
                ),
                'cards'       => \ACFWP()->Coupon_Card->get_coupon_cards_markup_on_my_coupons_page( '', array( 'columns' => 2 ) ),
                'labels'      => array(
                    'available'   => __( 'Available Coupons', 'advanced-coupons-for-woocommerce' ),
                    'owned'       => __( 'Owned Coupons', 'advanced-coupons-for-woocommerce' ),
                    'usedexpired' => __( 'Used / Expired Coupons', 'advanced-coupons-for-woocommerce' ),
                    'none'        => __( 'You have no coupons.', 'advanced-coupons-for-woocommerce' ),
                ),
            )
        );
    }

    /**
     * Set my coupons my account tab page title.
     *
     * @since 3.5.6
     * @access public
     *
     * @param string $title Page title.
     * @return string Filtered page title.
     */
    public function my_coupons_page_title( $title ) {
        global $wp_query;

        $is_endpoint = isset( $wp_query->query_vars[ $this->_get_my_coupons_endpoint() ] );

        if ( $is_endpoint && ! is_admin() && is_main_query() && in_the_loop() && is_account_page() ) {
            $title = __( 'My Coupons', 'advanced-coupons-for-woocommerce' );
            remove_filter( 'the_title', array( $this, 'my_coupons_page_title' ) );
        }

        return $title;
    }

    /*
    |--------------------------------------------------------------------------
    | Utility Functions
    |--------------------------------------------------------------------------
     */

    /**
     * Get my_coupons custom endpoint.
     *
     * @since 3.5.6
     * @access private
     *
     * @return string Endpoint.
     */
    private function _get_my_coupons_endpoint() {
        return apply_filters( 'acfw_my_coupons_endpoint', $this->_constants->MY_COUPONS_ENDPOINT );
    }

    /**
     * Extend general settings option.
     *
     * @since 3.5.5
     * @access public
     *
     * @param array $option General settings option.
     */
    public function setting_general_option( $option ) {
        $option[] = array(
            'title'   => __( 'Hide my coupons tab', 'advanced-coupons-for-woocommerce' ),
            'type'    => 'checkbox',
            'desc'    => __( 'If checked, this feature will hide my coupons tab on my account page.', 'advanced-coupons-for-woocommerce' ),
            'id'      => $this->_constants->OPTION_HIDE_MY_COUPONS_TAB,
            'default' => 'no',
        );

        return $option;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
    */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 3.5.6
     * @access public
     * @implements ACFWP\Interfaces\Model_Interface
     */
    public function initialize() {
        $this->_register_coupon_tab_endpoint();
    }

    /**
     * Execute Coupon_Label class.
     *
     * @since 3.5.6
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_filter( 'acfw_setting_general_options', array( $this, 'setting_general_option' ), 10, 1 );

        // Add My Coupons tab.
        if ( 'yes' !== get_option( $this->_constants->OPTION_HIDE_MY_COUPONS_TAB ) ) {
            add_filter( 'query_vars', array( $this, 'register_my_coupons_query_vars' ) );
            add_filter( 'woocommerce_account_menu_items', array( $this, 'register_my_coupons_menu_item' ) );
            add_action( 'woocommerce_account_' . $this->_get_my_coupons_endpoint() . '_endpoint', array( $this, 'my_coupons_page' ) );
            add_filter( 'the_title', array( $this, 'my_coupons_page_title' ) );
        }
    }
}
