<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Activatable_Interface;
use ACFWP\Interfaces\Model_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of displaying admin notices.
 *
 * @since 3.3.2
 */
class Notices extends Base_Model implements Model_Interface, Activatable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.3.2
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
     * Register ACFWP admin notice option ids.
     *
     * @since 3.3.2
     * @access public
     *
     * @param array $notice_options Notice option ids.
     * @return array Filtered notice option ids.
     */
    public function register_acfwp_admin_notice_options( $notice_options ) {
        $priority_notices = array(
            'new_update_notice' => $this->_constants->SHOW_NEW_UPDATE_NOTICE,
        );

        return array_merge( $priority_notices, $notice_options );
    }

    /**
     * Register ACFWP admin notices data.
     *
     * @since 3.3.2
     * @access public
     *
     * @param array|null $data       Notice data.
     * @param string     $notice_key Notice key.
     * @return array|null Filtered notice data.
     */
    public function register_acfwp_admin_notices_data( $data, $notice_key ) {
        switch ( $notice_key ) {
            case 'new_update_notice':
                $data = $this->_get_new_update_notice_data();
                break;
        }

        return $data;
    }

    /**
     * Get new update notice data.
     *
     * @since 3.3.2
     * @access private
     *
     * @return array New update notice data.
     */
    private function _get_new_update_notice_data() {
        return array(
            'slug'           => 'new_update_notice',
            'id'             => $this->_constants->SHOW_NEW_UPDATE_NOTICE,
            'logo_img'       => $this->_constants->IMAGES_ROOT_URL . '/acfw-logo.png',
            'is_dismissable' => is_admin(), // make notice dismissable on other pages except for the dashboard.
            'type'           => 'warning',
            'heading'        => __( 'IMPORTANT INFORMATION', 'advanced-coupons-for-woocommerce' ),
            'content'        => array(
                sprintf(
                    /* Translators: %1$s: Formatting tag start. %2$s: Formatting tag end. */
                    __( 'The next update of %1$sAdvanced Coupons Premium%2$s (version 3.4) changes how the discounts of BOGO coupons with product categories as triggers and/or deals are implemented on the cart.', 'advanced-coupons-for-woocommerce' ),
                    '<strong>',
                    '</strong>'
                ),
                __( 'You can learn more about this new changes by reading the blog post linked below.', 'advanced-coupons-for-woocommerce' ),
            ),
            'actions'        => array(
                array(
                    'key'         => 'primary',
                    'link'        => 'https://advancedcouponsplugin.com/knowledgebase/bogo-product-categories-logic-changes/',
                    'text'        => __( 'View Changes', 'advanced-coupons-for-woocommerce' ),
                    'is_external' => true,
                ),
            ),
        );
    }

    /**
     * Always show new plugin update notice on the dashboard page.
     *
     * @since 3.3.2
     * @access public
     *
     * @param string $value New update notice option value.
     * @return string Filtered option value.
     */
    public function always_show_new_update_notice_on_dashboard( $value ) {
        if ( did_action( 'acfw_rest_api_context' ) && 'dismissed' === $value ) {
            return 'yes';
        }

        return $value;
    }

    /**
     * Override ACFWF notices.
     *
     * @since 3.5.6
     * @access public
     *
     * @param array $notices Notices.
     */
    public function override_acfwf_notices( $notices ) {
        // Override getting started notice.
        if ( isset( $notices['getting_started'] ) ) {
            $notices['getting_started'] = array(
                'slug'           => 'getting_started',
                'id'             => ACFWF()->Plugin_Constants::SHOW_GETTING_STARTED_NOTICE,
                'logo_img'       => ACFWF()->Plugin_Constants->IMAGES_ROOT_URL() . '/acfw-logo.png',
                'is_dismissable' => true,
                'type'           => 'success',
                'heading'        => __( 'IMPORTANT INFORMATION', 'advanced-coupons-for-woocommerce' ),
                'content'        => array(
                    __( 'Thank you for purchasing Advanced Coupons for WooCommerce – Advanced Coupons plugin gives WooCommerce store owners extra features on their WooCommerce coupons so they can market their stores better. The Premium version adds lots of extra capabilities to your WooCommerce coupons.', 'advanced-coupons-for-woocommerce' ),
                    __( 'Would you like to read the Advanced Coupons Premium getting started guide?', 'advanced-coupons-for-woocommerce' ),
                ),
                'actions'        => array(
                    array(
                        'key'         => 'primary',
                        'link'        => 'https://advancedcouponsplugin.com/knowledgebase/advanced-coupons-premium-getting-started-guide/?utm_source=acfwp&utm_medium=kb&utm_campaign=acfwpgettingstarted',
                        'text'        => __( 'Read The Getting Started Guide →', 'advanced-coupons-for-woocommerce' ),
                        'is_external' => true,
                    ),
                ),
                'nonce'          => wp_create_nonce( 'acfw_dismiss_notice_getting_started' ),
            );
        }

        return $notices;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 3.3.2
     * @access public
     * @implements ACFWP\Interfaces\Activatable_Interface
     */
    public function activate() {
        /**
         * Display the new plugin update notice upon updating the plugin to version 3.3.2+
         * Force dismiss the new update notice when the version of the plugin is greater or equal to the set upgrade notice version (3.4)
         */
        if ( version_compare( $this->_constants->VERSION, $this->_constants->NEW_UPDATE_NOTICE_VERSION, '>=' ) ) {
            delete_option( $this->_constants->SHOW_NEW_UPDATE_NOTICE );
        } else {
            update_option( $this->_constants->SHOW_NEW_UPDATE_NOTICE, 'yes', false );
        }
    }

    /**
     * Execute Notices class.
     *
     * @since 3.3.2
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_filter( 'acfw_get_all_admin_notices', array( $this, 'override_acfwf_notices' ), 10, 1 );
        add_filter( 'acfw_admin_notice_option_names', array( $this, 'register_acfwp_admin_notice_options' ) );
        add_filter( 'acfw_get_admin_notice_data', array( $this, 'register_acfwp_admin_notices_data' ), 10, 2 );
        add_filter( 'option_' . $this->_constants->SHOW_NEW_UPDATE_NOTICE, array( $this, 'always_show_new_update_notice_on_dashboard' ) );
    }
}
