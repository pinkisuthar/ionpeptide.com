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
 * Model that houses the SaveTo Wishlist admin notice logic.
 *
 * @since 4.7.2
 */
class SaveTo_Wishlist_Notice extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.7.2
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
     * Maybe display the SaveTo Wishlist admin notice.
     *
     * @since 4.7.2
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

        // 3. SaveTo already active.
        if ( defined( 'STWLITE_VERSION' ) ) {
            return;
        }

        // 4. Already dismissed.
        if ( get_option( Plugin_Constants::SAVETO_NOTICE_DISMISSED ) === 'yes' ) {
            return;
        }

        // 5. Timing check.
        $show_after = get_option( Plugin_Constants::SAVETO_NOTICE_SHOW_AFTER );
        if ( false === $show_after ) {
            $days_since_install = $this->_helper_functions->get_days_since_install();
            if ( $days_since_install > 20 ) {
                // Existing install -- show immediately.
                update_option( Plugin_Constants::SAVETO_NOTICE_SHOW_AFTER, time(), false );
                $show_after = time();
            } else {
                // New install -- delay 20 days from install date.
                $installation_date = get_option( Plugin_Constants::INSTALLATION_DATE );
                if ( $installation_date ) {
                    $install_timestamp = strtotime( $installation_date );
                    $show_after_time   = $install_timestamp + ( 20 * DAY_IN_SECONDS );
                } else {
                    $show_after_time = time() + ( 20 * DAY_IN_SECONDS );
                }
                update_option( Plugin_Constants::SAVETO_NOTICE_SHOW_AFTER, $show_after_time, false );
                return;
            }
        }

        if ( time() < (int) $show_after ) {
            return;
        }

        // 6. Render.
        $this->render_notice();
    }

    /**
     * Check if the current screen is one where the notice should display.
     *
     * @since 4.7.2
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
     * Render the SaveTo Wishlist admin notice.
     *
     * @since 4.7.2
     * @access private
     */
    private function render_notice() {
        $logo_url         = $this->_constants->IMAGES_ROOT_URL . 'savetowishlist-logo.svg';
        $dismiss_nonce    = wp_create_nonce( 'acfwf_saveto_notice_nonce' );
        $install_nonce    = wp_create_nonce( 'acfw_install_plugin' );
        $plugin_installed = file_exists( WP_PLUGIN_DIR . '/' . Plugin_Constants::SAVETO_WISHLIST_LITE_PLUGIN );
        $button_text      = $plugin_installed ? __( 'Activate', 'advanced-coupons-for-woocommerce-free' ) : __( 'Install & Activate', 'advanced-coupons-for-woocommerce-free' );

        include $this->_constants->VIEWS_ROOT_PATH . 'notices/view-saveto-wishlist-notice.php';
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX Functions
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX handler to dismiss the SaveTo Wishlist notice.
     *
     * @since 4.7.2
     * @access public
     */
    public function ajax_dismiss_notice() {
        check_ajax_referer( 'acfwf_saveto_notice_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        update_option( Plugin_Constants::SAVETO_NOTICE_DISMISSED, 'yes', false );
        wp_send_json_success();
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute SaveTo_Wishlist_Notice class.
     *
     * @since 4.7.2
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'all_admin_notices', array( $this, 'maybe_display_notice' ) );
        add_action( 'wp_ajax_acfwf_dismiss_saveto_notice', array( $this, 'ajax_dismiss_notice' ) );
    }
}
