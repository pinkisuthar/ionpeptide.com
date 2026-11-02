<?php
namespace ACFWF\Models;

use ACFWF\Abstracts\Base_Model;
use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Activatable_Interface;
use ACFWF\Interfaces\Model_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the Getting_Started module logic.
 * Public Model.
 *
 * @since 4.6.6
 */
class Getting_Started extends Base_Model implements Model_Interface, Activatable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.6.6
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

    /*
    |--------------------------------------------------------------------------
    | Implementation.
    |--------------------------------------------------------------------------
     */

    /**
     * Adds the "Getting Started" submenu under the specified top-level menu.
     *
     * @since 4.6.6
     * @access public
     *
     * @param string $toplevel_slug The slug of the top-level admin menu where the submenu will be added.
     */
    public function add_getting_started_submenu( $toplevel_slug ) {
        $show_getting_started = get_option( Plugin_Constants::GETTING_STARTED_SHOW ) === 'yes';

        if ( $show_getting_started ) {
            add_submenu_page(
                $toplevel_slug,
                __( 'Getting Started', 'advanced-coupons-for-woocommerce-free' ),
                sprintf(
                    '%1$s <span class="awaiting-mod"><span class="plugin-count">%2$s</span></span>',
                    __( 'Getting Started', 'advanced-coupons-for-woocommerce-free' ),
                    __( 'NEW', 'advanced-coupons-for-woocommerce-free' )
                ),
                'edit_shop_coupons',
                Plugin_Constants::GETTING_STARTED_URL,
                array( $this, 'getting_started_page' ),
                0
            );
        }
    }

    /**
     * Getting Started Page
     *
     * @since 4.6.6
     */
    public function getting_started_page() {
        include $this->_constants->VIEWS_ROOT_PATH . 'getting-started/index.php';
    }

    /**
     * Enqueue Getting Started page styles and scripts.
     *
     * @since 4.6.6
     * @access public
     *
     * @param WP_Screen $screen    Current screen object.
     * @param string    $post_type Screen post type.
     */
    public function enqueue_getting_started_scripts( $screen, $post_type ) {
        if ( 'coupons_page_' . Plugin_Constants::GETTING_STARTED_URL === $screen->id ) {
            wp_enqueue_style( 'acfwf_getting_started', $this->_constants->CSS_ROOT_URL . 'acfw-getting-started.css', array( 'dashicons' ), Plugin_Constants::VERSION, 'all' );
        }
    }

    /**
     * Maybe redirect to Getting Started page.
     *
     * @since 4.6.6
     * @access public
     *
     * @param string $plugin The plugin file path basename.
     */
    public function maybe_redirect_to_getting_started_page( $plugin ) {
        // Check if the plugin is activated via WP-CLI or CLI and just output a message to the console to connect the plugin via a connect URL.
        if ( ( ( defined( 'WP_CLI' ) && WP_CLI ) || php_sapi_name() === 'cli' ) ) {
            return;
        }

        if ( $this->_constants->PLUGIN_BASENAME === $plugin ) {
            // Check if the plugin is activated via plugins.php Bulk Actions and redirect to the connect page if it's the only plugin activated. Otherwise, do nothing.
            if ( ! empty( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
                $definition = array(
                    'checked' => array(
                        'filter' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
                        'flags'  => FILTER_REQUIRE_ARRAY,
                    ),
                    'action'  => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
                    'action2' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
                );

                $posted = filter_input_array( INPUT_POST, $definition );
                if ( ! empty( $posted['checked'] ) && is_array( $posted['checked'] ) &&
                    count( $posted['checked'] ) === 1 &&
                    isset( $posted['action'], $posted['action2'] ) &&
                    'activate-selected' === $posted['action'] &&
                    'activate-selected' === $posted['action2'] &&
                    array_shift( $posted['checked'] ) === $this->_constants->PLUGIN_BASENAME
                ) {
                    wp_safe_redirect( admin_url( 'admin.php?page=' . Plugin_Constants::GETTING_STARTED_URL ) );
                    exit;
                }
            } else {
                wp_safe_redirect( admin_url( 'admin.php?page=' . Plugin_Constants::GETTING_STARTED_URL ) );
                exit;
            }
        }
    }

    /**
     * Maybe hide Getting Started notice.
     *
     * @since 4.6.6
     */
    public function maybe_hide_getting_started_notice() {
        global $pagenow;

        // Once the Getting Started page is visited, we disable the menu item.
        if ( 'admin.php' === $pagenow && filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) === Plugin_Constants::GETTING_STARTED_URL &&
            get_option( Plugin_Constants::GETTING_STARTED_SHOW ) !== 'no' ) {
            update_option( Plugin_Constants::GETTING_STARTED_SHOW, 'no', 'no' );
        }
    }

    /**
     * Remove Getting Started submenu.
     *
     * @since 4.6.6
     */
    public function maybe_remove_getting_started_submenu() {

        global $submenu, $pagenow;

        $show_getting_started    = get_option( Plugin_Constants::GETTING_STARTED_SHOW ) === 'yes';
        $is_getting_started_page = 'admin.php' === $pagenow && filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) === Plugin_Constants::GETTING_STARTED_URL;
        if ( ! $show_getting_started ) {
            if ( $is_getting_started_page ) {
                wp_safe_redirect( admin_url( 'admin.php?page=acfw-dashboard' ) );
                exit;
            }

            return;
        }

        if ( $is_getting_started_page ) {
            remove_all_actions( 'admin_notices' );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 4.6.6
     * @access public
     * @implements ACFWF\Interfaces\Activatable_Interface
     */
    public function activate() {
        if ( ! get_option( Plugin_Constants::GETTING_STARTED_SHOW, false ) ) {
            update_option( Plugin_Constants::GETTING_STARTED_SHOW, 'yes', 'no' );
        }
    }

    /**
     * Execute Getting_Started class.
     *
     * @since 4.6.6
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'activated_plugin', array( $this, 'maybe_redirect_to_getting_started_page' ) );
        add_action( 'admin_footer', array( $this, 'maybe_hide_getting_started_notice' ) );
        add_action( 'acfw_register_admin_submenus', array( $this, 'add_getting_started_submenu' ), 100 );
        add_action( 'admin_menu', array( $this, 'maybe_remove_getting_started_submenu' ), 100 );
        add_action( 'acfw_after_load_backend_scripts', array( $this, 'enqueue_getting_started_scripts' ), 10, 2 );
    }
}
