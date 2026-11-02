<?php
namespace ACFWF\Models\Tools;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Interfaces\Initializable_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Plugin_Installer module.
 *
 * @since 4.5.5
 */
class Plugin_Installer extends Base_Model implements Model_Interface, Initializable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.5.5
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
     * Download and activate a plugin.
     *
     * @since 4.5.5
     * @access public
     *
     * @param string $plugin_slug The slug of the plugin to install.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function download_and_activate_plugin( $plugin_slug ) {
        // Check if the current user has the required permissions.
        if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
            return new \WP_Error( 'permission_denied', __( 'You do not have sufficient permissions to install and activate plugins.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        // Check if the plugin is valid.
        if ( ! $this->_is_plugin_allowed_for_install( $plugin_slug ) ) {
            return new \WP_Error( 'acfw_plugin_not_allowed', __( 'The plugin is not valid.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        // Get required files since we're calling this outside of context.
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $plugin_basename = $this->get_plugin_basename_by_slug( $plugin_slug );

        // Check if the plugin is already active.
        if ( is_plugin_active( $plugin_basename ) ) {
            return new \WP_Error( 'acfw_plugin_already_active', __( 'The plugin is already installed and active.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        // Check if the plugin is already installed but inactive, just activate it and return true.
        if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_basename ) ) {
            return $this->_activate_plugin( $plugin_basename, $plugin_slug );
        }

        // Get the plugin info from WordPress.org's plugin repository.
        $api = plugins_api( 'plugin_information', array( 'slug' => $plugin_slug ) );
        if ( is_wp_error( $api ) ) {
            return $api;
        }

        // Download the plugin.
        $skin     = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );
        $result   = $upgrader->install( $api->download_link );

        // Check if the plugin was installed successfully.
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( $skin->get_errors()->has_errors() ) {
            $error = $skin->get_errors()->get_error_message();
            return new \WP_Error( 'plugin_install_error', $error );
        }

        // Activate the plugin.
        return $this->_activate_plugin( $plugin_basename, $plugin_slug );
    }

    /**
     * Get the list of allowed plugins for install.
     *
     * @since 4.5.5
     * @access public
     *
     * @return array List of allowed plugins.
     */
    public function get_allowed_plugins() {

        $allowed_plugins = array(
            'woocommerce-wholesale-prices'         => Plugin_Constants::WWP_PLUGIN_BASENAME,
            'uncanny-automator'                    => Plugin_Constants::UNCANNY_AUTOMATOR_PLUGIN,
            'funnel-builder'                       => Plugin_Constants::FUNNEL_BUILDER_PLUGIN,
            'pushengage'                           => Plugin_Constants::PUSHENGAGE_PLUGIN,
            'storeagent-ai-for-woocommerce'        => Plugin_Constants::STOREAGENT_AI_PLUGIN,
            'woo-product-feed-pro'                 => Plugin_Constants::PRODUCT_FEED_PRO_PLUGIN,
            'wc-vendors'                           => Plugin_Constants::WC_VENDORS_PLUGIN,
            'invoice-gateway-for-woocommerce'      => Plugin_Constants::INVOICE_GATEWAY_PLUGIN,
            'woocommerce-store-toolkit'            => Plugin_Constants::STORE_TOOLKIT_PLUGIN,
            'woocommerce-exporter'                 => Plugin_Constants::STORE_EXPORTER_PLUGIN,
            'saveto-wishlist-lite-for-woocommerce' => Plugin_Constants::SAVETO_WISHLIST_LITE_PLUGIN,
        );

        // Allow other plugins to be installed but not let them overwrite the ones listed above.
        $extra_allowed_plugins = apply_filters( 'acfw_allowed_install_plugins', array() );

        return array_merge( $allowed_plugins, $extra_allowed_plugins );
    }

    /**
     * Update plugin install information.
     *
     * @param string $plugin_slug The plugin slug.
     *
     * @since 2.2.1
     * @access private
     *
     * @return void
     */
    private function _update_plugin_install_information( $plugin_slug ) {
        // Update uncanny automator source option.
        if ( 'uncanny-automator' === $plugin_slug ) {
            update_option( 'uncannyautomator_source', 'acoupons', false );
        }

        // Update StoreAgent AI source option when StoreAgent AI is installed.
        if ( 'storeagent-ai-for-woocommerce' === $plugin_slug ) {
            update_option( 'storeagent_installed_by', 'acfw', false );
        }

        // Update WooCommerce Wholesale Prices source option when WooCommerce Wholesale Prices is installed.
        if ( 'woocommerce-wholesale-prices' === $plugin_slug ) {
            update_option( 'wwp_installed_by', 'acfw', false );
        }
    }

    /**
     * Activate a plugin.
     *
     * @since 4.5.6
     * @access private
     *
     * @param string $plugin_basename Plugin basename.
     * @param string $plugin_slug     Plugin slug.
     * @return bool|\WP_Error True if successful, WP_Error otherwise.
     */
    private function _activate_plugin( $plugin_basename, $plugin_slug ) {
        // Verify the plugin exists before trying to activate.
        if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_basename ) ) {
            return new \WP_Error(
                'plugin_not_found',
                // translators: %s is the plugin basename.
                sprintf( __( 'Cannot activate the plugin because the file %s does not exist.', 'advanced-coupons-for-woocommerce-free' ), $plugin_basename )
            );
        }

        // Attempt activation.
        $result = activate_plugin( $plugin_basename );

        // Check for activation error.
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Verify the plugin was actually activated.
        if ( ! is_plugin_active( $plugin_basename ) ) {
            return new \WP_Error(
                'activation_failed',
                // translators: %s is the plugin basename.
                sprintf( __( 'The plugin was not activated. Plugin file: %s', 'advanced-coupons-for-woocommerce-free' ), $plugin_basename )
            );
        }

        // Update plugin install information.
        $this->_update_plugin_install_information( $plugin_slug );

        return true;
    }

    /**
     * Validate if the given plugin is allowed for install.
     *
     * @since 4.5.5
     * @access private
     *
     * @param string $plugin_slug Plugin slug.
     * @return bool True if valid, false otherwise.
     */
    private function _is_plugin_allowed_for_install( $plugin_slug ) {
        return in_array( $plugin_slug, array_keys( $this->get_allowed_plugins() ), true );
    }

    /**
     * Get the plugin basename by slug.
     *
     * @since 4.5.5
     * @access public
     *
     * @param string $plugin_slug Plugin slug.
     * @return string Plugin basename.
     */
    public function get_plugin_basename_by_slug( $plugin_slug ) {
        $allowed_plugins = $this->get_allowed_plugins();

        return $allowed_plugins[ $plugin_slug ] ?? '';
    }


    /*
    |--------------------------------------------------------------------------
    | AJAX Functions
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX install and activate a plugin.
     *
     * @since 4.5.5
     * @access public
     */
    public function ajax_install_activate_plugin() {
        try {
            // Check nonce.
            check_ajax_referer( 'acfw_install_plugin', 'nonce' );

            // Retrieve the plugin slug from the front-end.
            $plugin_slug = isset( $_REQUEST['plugin_slug'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['plugin_slug'] ) ) : '';

            if ( empty( $plugin_slug ) ) {
                wp_send_json_error( array( 'message' => 'Plugin slug is empty' ) );
                return;
            }

            if ( ! $this->_is_plugin_allowed_for_install( $plugin_slug ) ) {
                wp_send_json_error(
                    array(
                        'message' => sprintf(
                            'Plugin %s is not in the allowed plugins list.',
                            $plugin_slug,
                        ),
                    )
                );
                return;
            }

            $plugin_basename = $this->get_plugin_basename_by_slug( $plugin_slug );

            // Check if the plugin is already active.
            if ( is_plugin_active( $plugin_basename ) ) {
                $message = sprintf(
                    /* translators: %s: plugin slug. */
                    __( 'Plugin %s is already installed and active.', 'advanced-coupons-for-woocommerce-free' ),
                    $plugin_slug
                );
                wp_send_json_success(
                    array(
                        'message'  => $message,
                        'slug'     => $plugin_slug,
                        'basename' => $plugin_basename,
                    )
                );
                return;
            }

            $result = $this->download_and_activate_plugin( $plugin_slug );

            do_action( 'acfw_after_install_activate_plugin', $plugin_slug, $result );

            if ( isset( $_REQUEST['redirect'] ) ) {
                wp_safe_redirect( admin_url( 'plugins.php' ) );
            }

            // Check if the result is a WP_Error.
            if ( is_wp_error( $result ) ) {
                wp_send_json_error(
                    array(
                        'message' => $result->get_error_message(),
                    )
                );
            } else {
                $message = sprintf(
                    /* translators: %s: plugin slug. */
                    __( 'Plugin %s installed and activated successfully.', 'advanced-coupons-for-woocommerce-free' ),
                    $plugin_slug
                );
                wp_send_json_success(
                    array(
                        'message'  => $message,
                        'slug'     => $plugin_slug,
                        'basename' => $plugin_basename,
                    )
                );
            }
        } catch ( \Exception $e ) {
            wp_send_json_error(
                array(
                    'message' => $e->getMessage(),
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run plugin init.
     *
     * @since 4.5.5
     * @access public
     * @inherit ACFWF\Interfaces\Initializable_Interface
     */
    public function initialize() {
        add_action( 'wp_ajax_acfw_install_activate_plugin', array( $this, 'ajax_install_activate_plugin' ) );
    }

    /**
     * Execute Plugin_Installer class.
     *
     * @since 4.5.5
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
    }
}
