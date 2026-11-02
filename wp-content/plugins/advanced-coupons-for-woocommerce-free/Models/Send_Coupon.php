<?php
namespace ACFWF\Models;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Model that houses the logic of the Send_Coupon module.
 *
 * @since 4.6.4
 */
class Send_Coupon extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.6.4
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
     * Register send coupon localized data.
     *
     * @since 4.6.4
     * @access public
     *
     * @param array $data Localized data.
     * @return array Filtered localized data.
     */
    public function register_send_coupon_localized_data( $data ) {
        $pushengage_api               = null;
        $is_pushengage_site_connected = false;
        $pushengage_connect_site_url  = admin_url( 'admin.php?page=pushengage#/onboarding' );

        // Check if the PushEngage plugin is active.
        if ( $this->_helper_functions->is_plugin_active( Plugin_Constants::PUSHENGAGE_PLUGIN ) ) {
            $pushengage_api               = \Pushengage\Includes\Api\PushengageAPI::instance();
            $is_pushengage_site_connected = $pushengage_api->is_site_connected();
        }

        $data['send_coupon'] = array(
            'labels'                       => array(
                'title'            => __( 'Send Coupon', 'advanced-coupons-for-woocommerce-free' ),
                'description'      => array(
                    'email'                         => __( 'Deliver this coupon via email to a customer.', 'advanced-coupons-for-woocommerce-free' ),
                    'pushengage'                    => __( 'Deliver this coupon via PushEngage.', 'advanced-coupons-for-woocommerce-free' ),
                    'pushengage_not_installed'      => sprintf(
                        '<div class="pushengage-logo"><img src="%s"/></div> %s <button type="button" class="button button-primary acfw-button-pushengage-install">%s</button>',
                        $this->_constants->IMAGES_ROOT_URL . 'pushengage-logo.svg',
                        __( 'Please install and activate the PushEngage plugin to enable push notification integration.', 'advanced-coupons-for-woocommerce-free' ),
                        __( 'Click Here to Install PushEngage', 'advanced-coupons-for-woocommerce-free' ),
                    ),
                    'pushengage_site_not_connected' => sprintf(
                        '<div class="pushengage-logo"><img src="%s"/></div> %s <a href="%s" class="button button-primary acfw-button-pushengage-connect">%s</a>',
                        $this->_constants->IMAGES_ROOT_URL . 'pushengage-logo.svg',
                        __( 'Please connect your site to enable push notification integration.', 'advanced-coupons-for-woocommerce-free' ),
                        esc_url( $pushengage_connect_site_url ),
                        __( 'Click Here to Connect Your Site', 'advanced-coupons-for-woocommerce-free' ),
                    ),
                ),
                'next'             => __( 'Next', 'advanced-coupons-for-woocommerce-free' ),
                'send_coupon_to'   => __( 'Send coupon to', 'advanced-coupons-for-woocommerce-free' ),
                'confirm_and_send' => __( 'Confirm & send', 'advanced-coupons-for-woocommerce-free' ),
                'installing'       => __( 'Installing', 'advanced-coupons-for-woocommerce-free' ),
                'select_options'   => array(
                    'label'   => __( 'Send via: ', 'advanced-coupons-for-woocommerce-free' ),
                    'options' => array(
                        array(
                            'value' => 'email',
                            'label' => __( 'Email', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                        array(
                            'value' => 'pushengage',
                            'label' => __( 'PushEngage', 'advanced-coupons-for-woocommerce-free' ),
                        ),
                    ),
                ),
                'email'            => array(
                    'existing_customer_account' => __( 'Existing customer account', 'advanced-coupons-for-woocommerce-free' ),
                    'new_customer'              => __( 'New customer (no account)', 'advanced-coupons-for-woocommerce-free' ),
                    'customer_details'          => __( 'Customer details', 'advanced-coupons-for-woocommerce-free' ),
                    'search'                    => __( 'Search', 'advanced-coupons-for-woocommerce-free' ),
                    'name'                      => __( 'Name', 'advanced-coupons-for-woocommerce-free' ),
                    'email'                     => __( 'Email', 'advanced-coupons-for-woocommerce-free' ),
                    'create_new_user_account'   => __( 'Create new user account', 'advanced-coupons-for-woocommerce-free' ),
                    'customer'                  => __( 'Customer', 'advanced-coupons-for-woocommerce-free' ),
                    'preview_email'             => __( 'Preview email', 'advanced-coupons-for-woocommerce-free' ),
                    'send_email'                => __( 'Send Email', 'advanced-coupons-for-woocommerce-free' ),
                ),
                'pushengage'       => array(
                    'customer_details'    => __( 'Customer details', 'advanced-coupons-for-woocommerce-free' ),
                    'message_details'     => __( 'Message', 'advanced-coupons-for-woocommerce-free' ),
                    'title'               => __( 'Title', 'advanced-coupons-for-woocommerce-free' ),
                    'message'             => __( 'Message', 'advanced-coupons-for-woocommerce-free' ),
                    'url'                 => __( 'URL', 'advanced-coupons-for-woocommerce-free' ),
                    'segment_placeholder' => __( 'Any customer', 'advanced-coupons-for-woocommerce-free' ),
                    'title_placeholder'   => __( 'Enter title ...', 'advanced-coupons-for-woocommerce-free' ),
                    'message_placeholder' => __( 'Enter message ...', 'advanced-coupons-for-woocommerce-free' ),
                    'url_placeholder'     => __( 'Enter URL ...', 'advanced-coupons-for-woocommerce-free' ),
                    'segments'            => __( 'Segments', 'advanced-coupons-for-woocommerce-free' ),
                    'create_new_segment'  => __( 'Create new segment', 'advanced-coupons-for-woocommerce-free' ),
                    'subscribers'         => __( 'Subscribers', 'advanced-coupons-for-woocommerce-free' ),
                    'search'              => __( 'Search', 'advanced-coupons-for-woocommerce-free' ),
                    'confirm_and_send'    => __( 'Confirm & send', 'advanced-coupons-for-woocommerce-free' ),
                    'preview_pushengage'  => __( 'Preview Push Notification', 'advanced-coupons-for-woocommerce-free' ),
                    'send_pushengage'     => __( 'Send PushEngage', 'advanced-coupons-for-woocommerce-free' ),
                ),
            ),
            'form_email_nonce'             => wp_create_nonce( 'acfw_advanced_coupon_preview_email' ),
            'is_pushengage_plugin_active'  => $this->_helper_functions->is_plugin_active( Plugin_Constants::PUSHENGAGE_PLUGIN ),
            'is_pushengage_site_connected' => $is_pushengage_site_connected,
            'pushengage_download_nonce'    => wp_create_nonce( 'acfw_install_plugin' ),
            'pushengage_segment_page'      => admin_url( 'admin.php?page=pushengage#/audience/segments' ),
            'pushengage_subscriber_nonce'  => wp_create_nonce( 'acfw_pushengage_subscriber' ),
        );

        return $data;
    }

    /**
     * Execute Send_Coupon class.
     *
     * @since 4.6.4
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        add_filter( 'acfw_edit_advanced_coupon_localize', array( $this, 'register_send_coupon_localized_data' ) );
    }
}
