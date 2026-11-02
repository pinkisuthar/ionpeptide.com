<?php
namespace ACFWP\Models\SLMW;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;

use ACFWP\Abstracts\Base_Model;
use ACFWP\Interfaces\Model_Interface;

use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Helpers\Helper_Functions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * Model that houses the logic of extending the coupon system of woocommerce.
 * It houses the logic of handling coupon url.
 * Public Model.
 *
 * @since 2.0
 */
class Settings extends Base_Model implements Model_Interface {
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
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $main_plugin->add_to_all_plugin_models( $this );
    }

    /**
     * Register slmw settings menu.
     *
     * @since 1.8
     * @access public
     */
    public function register_slmw_settings_menu() {
        add_menu_page(
            __( 'ACFW License', 'advanced-coupons-for-woocommerce' ),
            __( 'ACFW License', 'advanced-coupons-for-woocommerce' ),
            'manage_sites',
            'acfw-ms-license-settings',
            array( $this, 'generate_slmw_settings_page' )
        );
    }

    /**
     * Register slmw settings page.
     *
     * @since 1.8
     * @access public
     */
    public function generate_slmw_settings_page() {
        $license_activated = get_site_option( $this->_constants->OPTION_LICENSE_ACTIVATED );
        $activation_email  = get_site_option( $this->_constants->OPTION_ACTIVATION_EMAIL );
        $license_key       = get_site_option( $this->_constants->OPTION_LICENSE_KEY );
        $constants         = $this->_constants;

        include $this->_constants->VIEWS_ROOT_PATH . 'slmw' . DIRECTORY_SEPARATOR . 'view-license-settings-page.php';
    }

    /**
     * Register slmw settings section.
     *
     * @since 2.0
     * @access public
     *
     * @param array $settings_sections Array of settings sections.
     * @return array Filtered array of settings sections.
     */
    public function register_slmw_settings_section( $settings_sections ) {
        if ( array_key_exists( 'acfw_slmw_settings_section', $settings_sections ) ) {
            return $settings_sections;
        }

        $settings_sections['acfw_slmw_settings_section'] = __( 'License', 'advanced-coupons-for-woocommerce' );

        return $settings_sections;
    }

    /**
     * Register slmw settings section options.
     *
     * @since 2.0
     * @access public
     *
     * @param array  $settings                 Array of options per settings sections.
     * @param string $current_settings_section Current section key.
     * @return array Filtered array of options per settings sections.
     */
    public function register_slmw_settings_section_options( $settings, $current_settings_section ) {
        if ( 'acfw_slmw_settings_section' !== $current_settings_section ) {
            return $settings;
        }

        return array(
            array(
                'type' => 'acfw_license',
                'id'   => 'acfw_license_header',
            ),
        );
    }

    /**
     * Render ACFW license settings header content.
     *
     * @since 2.1
     * @access public
     */
    public function render_slmw_license_page() {

        // hide save changes button.
        $GLOBALS['hide_save_button'] = true;

        $license_activated = get_option( $this->_constants->OPTION_LICENSE_ACTIVATED );
        $activation_email  = get_option( $this->_constants->OPTION_ACTIVATION_EMAIL );
        $license_key       = get_option( $this->_constants->OPTION_LICENSE_KEY );
        $constants         = $this->_constants;

        include $this->_constants->VIEWS_ROOT_PATH . 'slmw' . DIRECTORY_SEPARATOR . 'view-license-settings-page.php';
    }

    /**
     * Remove per subslite license page app.
     *
     * @since 2.2
     * @access public
     *
     * @param array $app_pages List of app pages.
     * @return array Filtered list of app pages.
     */
    public function remove_per_site_license_page_app( $app_pages ) {

        unset( $app_pages['acfw-license'] );

        return $app_pages;
    }

    /**
     * Register network license page.
     *
     * @since 4.5.2
     * @access public
     *
     * @param array $plugins List of advanced coupon plugins.
     * @return array Filtered list of advanced coupon plugins.
     */
    public function register_network_license_page( $plugins ) {

        $plugins['ACFWP'] = array(
            'key'  => 'acfwp_license',
            'name' => __( 'Advanced Coupons Premium', 'advanced-coupons-for-woocommerce' ),
            'url'  => network_admin_url( 'admin.php?page=advanced-coupons&tab=acfwp_license' ),
        );

        return $plugins;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
    */

    /**
     * Execute Settings class.
     *
     * @since 2.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {

        // Add SLMW Settings In Multi-Site Environment.
        if ( is_multisite() ) {
            add_action( 'acfw_network_menu_acfwp_license_content', array( $this, 'generate_slmw_settings_page' ) );
            add_filter( 'acfw_admin_app_pages', array( $this, 'remove_per_site_license_page_app' ) );
            add_filter( 'acfw_network_menu_page_plugins', array( $this, 'register_network_license_page' ) );
            return;
        }

        add_filter( 'woocommerce_get_sections_acfw_settings', array( $this, 'register_slmw_settings_section' ) );
        add_filter( 'woocommerce_get_settings_acfw_settings', array( $this, 'register_slmw_settings_section_options' ), 10, 2 );
        add_filter( 'woocommerce_admin_field_acfw_license', array( $this, 'render_slmw_license_page' ) );
    }
}
