<?php
namespace ACFWP\Models\SLMW\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait that holds the logic for license check.
 *
 * @since 3.6.0
 */
trait License_Check {

    /**
     * Initialize the last license check value during plugin activation.
     *
     * @since 3.6.0
     * @access private
     */
    private function _init_last_license_check_value() {
        $last_check = get_site_option( $this->_constants->OPTION_LAST_LICENSE_CHECK, 0 );

        // only update the last license check value if it's not set.
        if ( ! $last_check ) {
            get_site_option( $this->_constants->OPTION_LAST_LICENSE_CHECK, time() );
        }
    }

    /**
     * Check Advanced Coupons premium license.
     *
     * @since 3.6.0
     * @access public
     *
     * @param bool $force Whether to force the license check (force is still limited to once per minute).
     * @return array|\WP_Error License data on success, error object on failure.
     */
    public function check_license( $force = false ) {
        $last_check = get_site_transient( 'acfwp_check_license_timeout', 0 );
        $time_limit = $force ? 60 : DAY_IN_SECONDS;

        // Skip if the last license check was less than a day or a minute ago if forced.
        if ( $last_check && ( ( time() - $last_check ) < $time_limit ) ) {
            return new \WP_Error(
                'acfw_license_check_too_soon',
                __( 'Please wait for a minute before checking the license status again.', 'advanced-coupons-for-woocommerce' )
            );
        }

        $activation_email = get_site_option( $this->_constants->OPTION_ACTIVATION_EMAIL );
        $license_key      = get_site_option( $this->_constants->OPTION_LICENSE_KEY );

        $check_url = add_query_arg(
            array(
                'activation_email' => rawurlencode( $activation_email ),
                'license_key'      => $license_key,
                'site_url'         => home_url(),
                'software_key'     => $this->_constants->SOFTWARE_KEY,
                'multisite'        => (int) is_multisite(),
            ),
            apply_filters( 'acfwp_license_check_url', $this->_constants->LICENSE_CHECK_ENDPOINT )
        );

        $args = apply_filters(
            'acfwp_license_check_option',
            array(
                'timeout' => 10, // seconds.
                'headers' => array( 'Accept' => 'application/json' ),
            )
        );

        $response       = json_decode( wp_remote_retrieve_body( wp_remote_get( $check_url, $args ) ) );
        $license_status = $response->license_status ?? null;

        // Update last license check timestamp.
        if ( 'active' === $license_status ) {
            update_site_option( $this->_constants->OPTION_LAST_LICENSE_CHECK, time() );
        }

        // Update the license check timeout transient.
        set_site_transient( 'acfwp_check_license_timeout', time(), DAY_IN_SECONDS );

        // Process license response on check.
        $license_data[ $this->_constants->SOFTWARE_KEY ] = $this->process_license_response( $response, 'check' );

        // Fire post licence check hook.
        do_action( 'acfwp_after_check_license', $license_data, $activation_email, $license_key );

        return $license_data;
    }

    /**
     * Schedule license check.
     *
     * @since 3.6.0
     * @access private
     */
    private function _schedule_license_check() {
        // Skip if the license check is already scheduled.
        if ( \WC()->queue()->get_next( 'acfw_license_check', array(), 'acfw_license_check' ) instanceof \WC_DateTime ) {
            return;
        }

        // Schedule license check, randomize the time in a day to avoid multiple sites checking at the same time.
        \WC()->queue()->schedule_recurring(
            time() + wp_rand( 0, DAY_IN_SECONDS ),
            DAY_IN_SECONDS,
            'acfw_license_check',
            array(),
            'acfw_license_check'
        );
    }

    /**
     * AJAX refresh license status.
     *
     * @since 3.6.0
     * @access public
     */
    public function ajax_refresh_license_status() {
        $post_data = $this->_helper_functions->validate_ajax_request(
            array(
                'nonce_value_key' => 'nonce',
                'nonce_action'    => 'acfw_refresh_license_status',
                'user_capability' => 'manage_woocommerce',
            )
        );

        // Skip if the AJAX request is not valid.
        if ( is_wp_error( $post_data ) ) {
            wp_send_json(
                array(
                    'status'  => 'fail',
                    'message' => $post_data->get_error_message(),
                )
            );
        }

        // Check the license data.
        $license_data = $this->check_license( true );

        // Skip if the license check failed.
        if ( is_wp_error( $license_data ) ) {
            wp_send_json(
                array(
                    'status'  => 'fail',
                    'message' => $license_data->get_error_message(),
                )
            );
        }

        wp_send_json(
            $license_data['ACFW'] ?? array(
                'status'  => 'fail',
                'message' => __( 'Missing plugin license data.', 'advanced-coupons-for-woocommerce' ),
            )
        );
    }
}
