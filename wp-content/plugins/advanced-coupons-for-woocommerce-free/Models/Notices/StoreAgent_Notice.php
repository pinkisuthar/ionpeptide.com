<?php
namespace ACFWF\Models\Notices;

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
 * Model that houses the StoreAgent admin notice logic.
 *
 * @since 4.7.3
 */
class StoreAgent_Notice extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.7.3
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants         Plugin constants object.
     * @param Helper_Functions           $helper_functions  Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );

        $main_plugin->add_to_all_plugin_models( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | Implementation.
    |--------------------------------------------------------------------------
     */

    /**
     * Maybe display the StoreAgent admin notice.
     *
     * @since 4.7.3
     * @access public
     */
    public function maybe_display_notice() {
        // 1. Must have install_plugins capability.
        if ( ! current_user_can( 'install_plugins' ) ) {
            return;
        }

        // 2. Only show on relevant screens.
        if ( ! $this->is_allowed_screen() ) {
            return;
        }

        // 3. StoreAgent already active.
        if ( $this->_helper_functions->is_plugin_active( Plugin_Constants::STOREAGENT_AI_PLUGIN ) ) {
            return;
        }

        // 4. Already dismissed.
        if ( get_option( Plugin_Constants::STOREAGENT_NOTICE_DISMISSED ) === 'yes' ) {
            return;
        }

        // 5. Render.
        $this->render_notice();
    }

    /**
     * Check if the current screen is one where the notice should display.
     *
     * @since 4.7.3
     * @access private
     *
     * @return bool
     */
    private function is_allowed_screen() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return false;
        }

        // Never show on edit/add new screens (including HPOS orders).
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( in_array( $action, array( 'edit', 'new' ), true ) || 'add' === $screen->action || isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }

        // Plugins list page.
        if ( 'plugins' === $screen->id ) {
            return true;
        }

        // WooCommerce subpages (orders, settings, status, etc.).
        if ( str_contains( $screen->id, 'woocommerce' ) ) {
            return true;
        }

        // WooCommerce post type list views (coupons, orders, products).
        if ( in_array( $screen->post_type, array( 'shop_coupon', 'shop_order', 'product' ), true ) ) {
            return true;
        }

        // ACFW own pages (dashboard, settings, etc.).
        if ( str_contains( $screen->id, 'acfw-' ) ) {
            return true;
        }

        return false;
    }

    /**
     * Render the StoreAgent admin notice.
     *
     * @since 4.7.3
     * @access private
     */
    private function render_notice() {
        $logo_url      = $this->_constants->IMAGES_ROOT_URL . 'storeagent-logo.png';
        $dismiss_nonce = wp_create_nonce( 'acfwf_storeagent_notice_nonce' );
        $install_nonce = wp_create_nonce( 'acfw_install_plugin' );
        $button_text   = __( 'Install Now', 'advanced-coupons-for-woocommerce-free' );
        $plugin_slug   = dirname( Plugin_Constants::STOREAGENT_AI_PLUGIN );

        include $this->_constants->VIEWS_ROOT_PATH . 'notices/view-storeagent-notice.php';
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX Functions
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX handler to dismiss the StoreAgent notice.
     *
     * @since 4.7.3
     * @access public
     */
    public function ajax_dismiss_notice() {
        check_ajax_referer( 'acfwf_storeagent_notice_nonce', 'nonce' );

        if ( ! current_user_can( 'install_plugins' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        update_option( Plugin_Constants::STOREAGENT_NOTICE_DISMISSED, 'yes', false );
        wp_send_json_success();
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute StoreAgent_Notice class.
     *
     * @since 4.7.3
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'all_admin_notices', array( $this, 'maybe_display_notice' ) );
        add_action( 'wp_ajax_acfwf_dismiss_storeagent_notice', array( $this, 'ajax_dismiss_notice' ) );
    }
}
