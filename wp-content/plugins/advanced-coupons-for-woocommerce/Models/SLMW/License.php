<?php
namespace ACFWP\Models\SLMW;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;

use ACFWP\Abstracts\Base_Model;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Activatable_Interface;

use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Models\Objects\Vite_App;

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
class License extends Base_Model implements Model_Interface, Initiable_Interface, Activatable_Interface {
    use \ACFWP\Models\SLMW\Traits\Notices;
    use \ACFWP\Models\SLMW\Traits\License_Check;

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
     * Process license options after activation, check license or update data.
     *
     * @since 3.6.0
     * @access public
     *
     * @param object $response The API response from the activation/check endpoint.
     * @param string $context  The context of the process. Available values are 'activation', 'check' and 'update_data'.
     * @return array $response.
     */
    public function process_license_response( $response, $context = 'check' ) {
        // Skip if the activation point failed to send a response.
        if ( empty( $response ) ) {
            return array(
                'status'         => 'fail',
                'message'        => __( 'Failed to activate license. Failed to connect to license server. Please contact plugin support.', 'advanced-coupons-for-woocommerce' ),
                'license_status' => 'invalid',
            );
        }

        $license_data     = (array) get_site_option( $this->_constants->OPTION_LICENSE_DATA, array() );
        $license_status   = $response->license_status ?? null;
        $expire_timestamp = $response->expiration_timestamp ?? null;

        if ( 'success' === $response->status && 'active' === $license_status ) { // License is active.
            // Update the plugin site update data.
            if ( 'update_data' === $context && ! empty( $response->software_update_data ) ) {
                update_site_option( $this->_constants->OPTION_UPDATE_DATA, $response->software_update_data );
            }

            // change the key from success_msg to just message.
            if ( isset( $response->success_msg ) ) {
                $response->message = $response->success_msg;
                unset( $response->success_msg );
            }

            delete_site_option( $this->_constants->OPTION_LICENSE_EXPIRED );
            update_site_option( $this->_constants->OPTION_LICENSE_ACTIVATED, 'yes' );

        } else { // License is not active.

            // Update the plugin site update data.
            if ( 'update_data' === $context && ! empty( $response->software_update_data ) ) {
                delete_site_option( $this->_constants->OPTION_UPDATE_DATA, $response->software_update_data );
            }

            // change the key from error_msg to just message.
            if ( isset( $response->error_msg ) ) {
                $response->message = $response->error_msg;
                unset( $response->error_msg );
            }

            update_site_option( $this->_constants->OPTION_LICENSE_ACTIVATED, 'no' );

            // Maybe set the license expiration timestamp when the license status is expired or delete it when it's not.
            if ( 'expired' === $license_status && $expire_timestamp ) {
                update_site_option( $this->_constants->OPTION_LICENSE_EXPIRED, $response->expiration_timestamp );
            } else {
                delete_site_option( $this->_constants->OPTION_LICENSE_EXPIRED );
            }
        }

        // Maybe remove the stored update data of our plugin in the update_plugins transient.
        if ( 'update_data' === $context ) {
            $this->_maybe_remove_stored_update_data_in_transient();
        }

        // Overwrite the response data for the plugin.
        if ( $license_status && in_array( $license_status, array( 'active', 'disabled', 'expired' ), true ) ) {

            // Sanitize the response data and convert the type from an object to an array.
            $response = $this->_sanitize_license_response_value( $response );

            $license_data[ $this->_constants->SOFTWARE_KEY ] = $response;
        } else {
            unset( $license_data[ $this->_constants->SOFTWARE_KEY ] );
        }

        // Save license data to the database.
        update_site_option( $this->_constants->OPTION_LICENSE_DATA, $license_data );

        return $response;
    }

    /**
     * Activate the license with the given email and key.
     *
     * @since 3.6.0
     * @access public
     *
     * @param string $activation_email The email used to activate the license.
     * @param string $license_key      The license key used to activate the license.
     * @return array|\WP_Error Response array on success, error object on failure.
     */
    public function activate_license( $activation_email, $license_key ) {
        $activation_url = add_query_arg(
            array(
                'activation_email' => urlencode( $activation_email ), // phpcs:ignore
                'license_key'      => $license_key,
                'site_url'         => home_url(),
                'software_key'     => $this->_constants->SOFTWARE_KEY,
                'multisite'        => (int) is_multisite(),
            ),
            apply_filters( 'acfw_license_activation_url', $this->_constants->LICENSE_ACTIVATION_ENDPOINT )
        );

        $request_args = apply_filters(
            'acfwp_license_activation_options',
            array(
                'timeout' => 10, // seconds.
                'headers' => array( 'Accept' => 'application/json' ),
            ),
        );

        $result = json_decode( wp_remote_retrieve_body( wp_remote_get( $activation_url, $request_args ) ) );

        // skip if the result is empty.
        if ( empty( $result ) || ! property_exists( $result, 'status' ) ) {
            return new \WP_Error( 'acfwp_license_activation_failed', __( 'Failed to activate license. Failed to connect to activation access point. Please contact plugin support.', 'advanced-coupons-for-woocommerce' ) );
        }

        $license_status = $result->license_status ?? null;

        // Store data if the license email and key are valid.
        if ( 'success' === $result->status || ( 'fail' === $result->status && 'invalid' !== $license_status ) ) {
            update_site_option( $this->_constants->OPTION_ACTIVATION_EMAIL, $activation_email );
            update_site_option( $this->_constants->OPTION_LICENSE_KEY, $license_key );
        }

        // Update the last license check timestamp.
        if ( 'active' === $license_status ) {
            update_site_option( $this->_constants->OPTION_LAST_LICENSE_CHECK, time() );
        }

        $response = $this->process_license_response( $result, 'activation' );

        do_action( 'acfwp_after_activate_license', $response, $activation_email, $license_key );

        return $response;
    }

    /**
     * Get the ACFWP license data.
     *
     * @since 3.6.0
     * @access public
     *
     * @param bool $all Whether to get all license data or just the ACFWP license data.
     * @return array The ACFWP license data.
     */
    public function get_license_data( $all = false ) {
        $license_data = get_site_option( $this->_constants->OPTION_LICENSE_DATA, array() );

        if ( $all ) {
            return $license_data;
        }

        return isset( $license_data['ACFW'] ) ? $license_data['ACFW'] : array();
    }

    /**
     * Get the last license check date object.
     *
     * @since 3.6.0
     * @access public
     *
     * @param int $default_value The default value to return if the option is not set.
     * @return \WC_DateTime The last license check date object.
     */
    public function get_last_license_check( $default_value = 0 ) {
        $last_check = get_site_option( $this->_constants->OPTION_LAST_LICENSE_CHECK, $default_value );
        return new \WC_DateTime( "@{$last_check}", new \DateTimeZone( 'UTC' ) );
    }

    /**
     * Get the expired license data for all premium ACFW plugins.
     *
     * @since 3.6.0
     * @access public
     *
     * @return array The expired license data.
     */
    public function get_expired_license_data() {
        $expired_license_data = array_filter(
            $this->get_license_data( true ),
            function ( $license ) {
                return $this->_helper_functions->is_days_interval_valid( $license['expiration_timestamp'], -1, 'beyond' );
            }
        );

        return $expired_license_data;
    }

    /**
     * Get the license status for all premium ACFW plugins.
     *
     * @since 3.6.0
     * @access public
     *
     * @return array The license status for all premium ACFW plugins.
     */
    public function get_all_license_status_for_display() {
        $license_data = $this->get_license_data( true );
        $plugins      = $this->_get_active_premium_plugins();
        $labels       = array(
            'no_license' => __( 'No license found', 'advanced-coupons-for-woocommerce' ),
            'expired'    => __( 'Expired', 'advanced-coupons-for-woocommerce' ),
            'active'     => __( 'Active', 'advanced-coupons-for-woocommerce' ),
            'disabled'   => __( 'Disabled', 'advanced-coupons-for-woocommerce' ),
        );

        $statuses = array();
        foreach ( $plugins as $plugin_key => $plugin_name ) {
            if ( ! isset( $license_data[ $plugin_key ] ) ) {
                $statuses[ $plugin_key ] = array(
                    'plugin_name' => $plugin_name,
                    'status'      => $labels['no_license'],
                );
                continue;
            }

            $statuses[ $plugin_key ] = array(
                'plugin_name' => $plugin_name,
                'status'      => $labels[ $license_data[ $plugin_key ]['license_status'] ],
            );
        }

        return $statuses;
    }

    /**
     * Prepend the license related links to plugin action links.
     *
     * @since 3.6.0
     * @access public
     *
     * @param array $links Array of plugin action links.
     * @return array Array of modified plugin action links.
     */
    public function prepend_license_plugin_action_links( $links ) {

        $license_data      = $this->get_license_data();
        $license_form_link = $this->_constants->get_license_page_url();

        if ( ( ! is_array( $license_data ) || empty( $license_data ) ) && $license_form_link ) {

            $add_license_link = sprintf( '<a href="%s">%s</a>', $license_form_link, __( 'Add license key', 'advanced-coupons-for-woocommerce' ) );
            array_unshift( $links, $add_license_link );

        } elseif ( in_array( 'license_expired', $this->_active_notices, true ) || in_array( 'license_expired_interstitial', $this->_active_notices, true ) ) {
            $renew_license_url = $this->_add_drm_query_args( $license_data['management_url'], 'pluginlinkrenew' );
            $renew_link        = sprintf( '<a href="%s">%s</a>', $renew_license_url, __( 'Renew License', 'advanced-coupons-for-woocommerce' ) );

            array_unshift( $links, $renew_link );
        }

        return $links;
    }

    /**
     * Enqueue the license related styles and scripts.
     *
     * @since 3.6.0
     * @access public
     *
     * @param \WP_Screen $screen    The current screen object.
     * @param string     $post_type The current post type.
     */
    public function enqueue_license_scripts( $screen, $post_type ) {
        // set the is_acfw_screen property value.
        $this->_is_acfw_screen = ACFWF()->Notices->is_acfw_screen( $screen, $post_type ) && 'edit-shop_coupon' !== $screen->id; // The coupons list screen is not treated as an AC screen.
        $active_notices        = $this->get_active_notices();

        /**
         * Enqueue script for all ACFW related admin pages.
         */
        if ( ! empty( $active_notices ) ) {
            $license_vite = new Vite_App(
                'acfwp-license',
                'packages/acfwp-license/index.ts',
            );
            $license_vite->enqueue();

            wp_localize_script(
                'acfwp-license',
                'acfwpLicense',
                array(
                    'dismiss_notice_nonce'  => wp_create_nonce( 'acfw_dismiss_license_notice' ),
                    'refresh_license_nonce' => wp_create_nonce( 'acfw_refresh_license_status' ),
                )
            );
        }

        /**
         * Enqueue the license reminder pointer script after 3 days of no activation and the license key is not set.
         */
        if ( in_array( 'no_license_reminder', $active_notices, true )
            && ! get_site_option( $this->_constants->OPTION_LICENSE_KEY )
            && 'yes' !== get_site_transient( $this->_constants->NO_LICENSE_REMINDER_DISMISSED )
        ) {
            wp_enqueue_style( 'wp-pointer' );
            $reminder_vite = new Vite_App(
                'acfwp-license-reminder',
                'packages/acfwp-license-reminder/index.ts',
                array( 'wp-pointer' )
            );
            $reminder_vite->enqueue();

            wp_localize_script(
                'acfwp-license-reminder',
                'acfwpLicenseReminder',
                array(
                    'nonce'          => wp_create_nonce( 'acfw_dismiss_license_notice' ),
                    'licensePageUrl' => $this->_constants->get_license_page_url(),
                    'i18n'           => array(
                        'content'           => $this->_get_no_license_reminder_content(),
                        'close'             => __( 'Close', 'advanced-coupons-for-woocommerce' ),
                        'enter_license_key' => __( 'Enter License Key', 'advanced-coupons-for-woocommerce' ),
                    ),
                )
            );
        }

        /**
         * Enqueue script for multisite license page.
         */
        $tab = $_GET['tab'] ?? ''; // phpcs:ignore
        if ( 'toplevel_page_advanced-coupons-network' === $screen->base && in_array( $tab, array( 'acfwp_license', '' ), true ) ) {

            $slmw_vite = new Vite_App(
                'acfw_mu_license',
                'packages/acfwp-mu-license/index.ts',
                array( 'vex' ),
                array( 'vex', 'vex-theme-plain' ),
            );
            $slmw_vite->enqueue();

            wp_add_inline_script( 'vex', 'vex.defaultOptions.className = "vex-theme-plain"', 'after' );
            wp_localize_script(
                'acfw_mu_license',
                'slmw_args',
                array(
                    'acfw_slmw_activation_email'        => get_option( 'acfw_slmw_activation_email' ),
                    'acfw_slmw_license_key'             => get_option( 'acfw_slmw_license_key' ),
                    'nonce_activate_license'            => wp_create_nonce( 'acfw_activate_license' ),
                    'i18n_activate_license'             => __( 'Activate Key', 'advanced-coupons-for-woocommerce' ),
                    'i18n_activating_license'           => __( 'Activating. Please wait...', 'advanced-coupons-for-woocommerce' ),
                    'i18n_please_fill_activation_creds' => __( 'Please fill in activation email and license key', 'advanced-coupons-for-woocommerce' ),
                    'i18n_failed_to_activate_license'   => __( 'Failed to activated license. Server error occurred on ajax request. Please contact support.', 'advanced-coupons-for-woocommerce' ),
                    'i18n_license_activated'            => __( 'License is Active', 'advanced-coupons-for-woocommerce' ),
                    'i18n_license_not_active'           => __( 'Not Activated Yet', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX Functions.
    |--------------------------------------------------------------------------
    */

    /**
     * AJAX activate license for this site.
     *
     * @since 2.0
     * @since 1.8   Refactor and improve support for multisite setup.
     * @since 3.4.1 Add license status in response.
     * @since 3.6.0 Refactor and add redirect to license page when activating the license via the "no license" notice.
     * @access public
     */
    public function ajax_activate_license() {

        $post_data = $this->_helper_functions->validate_ajax_request(
            array(
                'required_parameters' => array( 'activation-email', 'license-key' ),
                'nonce_value_key'     => 'ajax-nonce',
                'nonce_action'        => 'acfw_activate_license',
                'user_capability'     => 'manage_woocommerce',
            )
        );

        // Skip if the AJAX request is not valid.
        if ( is_wp_error( $post_data ) ) {
            // Redirect to the license page when activating the license via the "no license" notice.
            if ( isset( $_POST['is_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                wp_safe_redirect( $this->_constants->get_license_page_url() );
                exit;
            }

            wp_send_json(
                array(
                    'status'         => 'fail',
                    'message'        => $post_data->get_error_message(),
                    'license_status' => 'invalid',
                )
            );
        }

        $activation_email = trim( sanitize_text_field( $post_data['activation-email'] ) );
        $license_key      = trim( sanitize_text_field( $post_data['license-key'] ) );

        // Activate the license.
        $response = $this->activate_license( $activation_email, $license_key );

        // Redirect to the license page when activating the license via the "no license" notice.
        if ( isset( $post_data['is_notice'] ) ) {
            wp_safe_redirect( $this->_constants->get_license_page_url() );
            exit;
        }

        if ( is_wp_error( $response ) ) {
            wp_send_json(
                array(
                    'status'         => 'fail',
                    'message'        => $response->get_error_message(),
                    'license_status' => 'invalid',
                )
            );
        }

        wp_send_json( $response );
    }

    /**
     * AJAX get license details.
     *
     * @since 2.2
     * @access public
     */
    public function ajax_get_license_details() {

        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Invalid AJAX Operation', 'advanced-coupons-for-woocommerce' ),
            );
        } else {

            $response = array(
                'status'      => 'success',
                'license_key' => get_option( $this->_constants->OPTION_LICENSE_KEY ),
                'email'       => get_option( $this->_constants->OPTION_ACTIVATION_EMAIL ),
                'is_active'   => get_option( $this->_constants->OPTION_LICENSE_ACTIVATED ),
            );
        }

        wp_send_json( $response );
    }

    /*
    |--------------------------------------------------------------------------
    | Utility functions.
    |--------------------------------------------------------------------------
    */

    /**
     * Get the plugin names for all the active premium plugins.
     *
     * @since 3.6.0
     * @access public
     *
     * @return array The plugin names for all the active premium plugins.
     */
    private function _get_active_premium_plugins() {
        $active  = array();
        $plugins = array(
            'ACFW' => 'Advanced Coupons',
            'LPFW' => 'Loyalty Program',
            'AGC'  => 'Advanced Gift Cards',
        );

        foreach ( $plugins as $key => $plugin_name ) {
            $is_active = false;

            if ( 'LPFW' === $key ) {
                $is_active = $this->_helper_functions->is_plugin_active( \ACFWF\Helpers\Plugin_Constants::LOYALTY_PLUGIN );
            } elseif ( 'AGC' === $key ) {
                $is_active = $this->_helper_functions->is_plugin_active( \ACFWF\Helpers\Plugin_Constants::GIFT_CARDS_PLUGIN );
            } else {
                $is_active = true;
            }

            if ( $is_active ) {
                $active[ $key ] = $plugin_name;
            }
        }

        return $active;
    }

    /**
     * Maybe remove the stored update data of our plugin in the update_plugins transient.
     *
     * @since 3.6.0
     * @access private
     */
    private function _maybe_remove_stored_update_data_in_transient() {
        $update_plugins_transient = get_site_transient( 'update_plugins' );

        if ( ! $update_plugins_transient ) {
            return;
        }

        $should_update_transient = false;

        if ( $update_plugins_transient->checked && isset( $update_plugins_transient->checked[ $this->_constants->PLUGIN_BASENAME ] ) ) {
            unset( $update_plugins_transient->checked[ $this->_constants->PLUGIN_BASENAME ] );
            $should_update_transient = true;
        }

        if ( $update_plugins_transient->response && isset( $update_plugins_transient->response[ $this->_constants->PLUGIN_BASENAME ] ) ) {
            unset( $update_plugins_transient->response[ $this->_constants->PLUGIN_BASENAME ] );
            $should_update_transient = true;
        }

        if ( $should_update_transient ) {
            set_site_transient( 'update_plugins', $update_plugins_transient );
            wp_update_plugins();
        }
    }

    /**
     * Sanitize license response value based on the provided key.
     *
     * @since 3.6.0
     * @access private
     *
     * @param object $response The license data response.
     * @return mixed The sanitized value of the license response.
     */
    private function _sanitize_license_response_value( $response ) {
        $sanitized = array();

        foreach ( $response as $key => $value ) {
            switch ( $key ) {
                case 'max_activation':
                case 'max_staging_activation':
                case 'activated_sites_count':
                case 'activated_staging_sites_count':
                    $sanitized[ $key ] = intval( $value );
                    break;

                case 'management_url':
                case 'upgrade_url':
                    $sanitized[ $key ] = esc_url_raw( $value );
                    break;

                /**
                 * No need to sanitize software_update_data.
                 */
                case 'software_update_data':
                    $sanitized[ $key ] = $value;
                    break;

                default:
                    $sanitized[ $key ] = sanitize_text_field( $value );
                    break;
            }
        }

        return $sanitized;
    }

    /**
     * Add the DRM UTM query args to the given URL.
     *
     * @since 3.6.0
     * @access private
     *
     * @param string $url      The URL to add the UTM query args to.
     * @param string $campaign The campaign name to add to the UTM query args.
     */
    private function _add_drm_query_args( $url, $campaign ) {
        $utm_args = array(
            'utm_source'   => 'acfwp',
            'utm_medium'   => 'drm',
            'utm_campaign' => 'acfwpdrm' . $campaign,
        );

        return add_query_arg( $utm_args, $url );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
    */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 3.6.0
     * @access public
     * @implements ACFWP\Interfaces\Activatable_Interface
     */
    public function activate() {
        $this->_init_last_license_check_value();
        $this->_schedule_license_check();
    }

    /**
     * Execute codes that needs to run plugin init.
     *
     * @since 2.0
     * @access public
     * @implements ACFWP\Interfaces\Initializable_Interface
     */
    public function initialize() {

        add_action( 'wp_ajax_acfw_get_license_details', array( $this, 'ajax_get_license_details' ) );
        add_action( 'wp_ajax_acfw_activate_license', array( $this, 'ajax_activate_license' ) );
        add_action( 'wp_ajax_acfw_dismiss_license_notice', array( $this, 'ajax_dismiss_license_notice' ) );
        add_action( 'wp_ajax_acfw_refresh_license_status', array( $this, 'ajax_refresh_license_status' ) );
    }

    /**
     * Execute License class.
     *
     * @since 2.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {

        add_action( 'admin_init', array( $this, 'maybe_display_license_notices' ) );
        add_action( 'acfw_license_check', array( $this, 'check_license' ) );
        add_action( 'acfwp_after_load_backend_scripts', array( $this, 'enqueue_license_scripts' ), 10, 2 );
        add_filter( 'plugin_action_links_' . $this->_constants->PLUGIN_BASENAME, array( $this, 'prepend_license_plugin_action_links' ), 20, 2 );

        if ( is_multisite() ) {
            add_action( 'network_admin_notices', array( $this, 'display_license_notices' ) );
            return;
        }

        add_action( 'admin_notices', array( $this, 'display_license_notices' ) );
        add_action( 'admin_footer', array( $this, 'display_no_license_interstitial' ), 999 );
        add_action( 'admin_footer', array( $this, 'display_disabled_license_interstitial' ), 999 );
        add_action( 'admin_footer', array( $this, 'display_post_expiry_interstitial' ), 999 );
    }
}
