<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that handles coupon usage restriction based on disallowed email addresses.
 * Public Model.
 *
 * @since 4.0.4
 */
class Disallowed_Emails extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.0.4
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
    | Implementation
    |--------------------------------------------------------------------------
     */

    /**
     * Implement disallowed email coupon usage restriction feature.
     *
     * @since 4.0.4
     * @access public
     *
     * @param bool      $disallowed Filter return value.
     * @param WC_Coupon $coupon WC_Coupon object.
     * @return bool True if valid, false otherwise.
     * @throws \Exception Error message.
     */
    public function implement_disallowed_emails( $disallowed, $coupon ) {
        // Don't run if virtual coupons is enabled for this coupon.
        if ( (bool) $coupon->get_meta( '_acfw_enable_virtual_coupons' ) ) {
            return $disallowed;
        }

        $disallowed_emails = $this->get_disallowed_emails_for_coupon( $coupon->get_id() );
        $current_user      = wp_get_current_user();
        $user_email        = $current_user && isset( $current_user->user_email ) ? strtolower( trim( $current_user->user_email ) ) : '';

        if ( is_array( $disallowed_emails ) && ! empty( $disallowed_emails ) ) {
            foreach ( $disallowed_emails as $pattern ) {
                $pattern = strtolower( trim( $pattern ) );

                // Convert wildcard pattern to regex.
                $regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';

                if ( preg_match( $regex, $user_email ) ) {
                    $error_message = apply_filters(
                        'acfwp_disallowed_emails_error_message',
                        __( 'You are not allowed to use this coupon with your email address.', 'advanced-coupons-for-woocommerce' ),
                        $disallowed_emails,
                        $coupon
                    );

                    throw new \Exception( wp_kses_post( $error_message ) );
                }
            }
        }

        return $disallowed;
    }

    /*
    |--------------------------------------------------------------------------
    | Admin field
    |--------------------------------------------------------------------------
     */

    /**
     * Display disallowed emails field inside "Usage restriction" tab.
     *
     * @since 4.0.4
     * @access public
     *
     * @param int $coupon_id WC_Coupon ID.
     */
    public function display_disallowed_emails_field( $coupon_id ) {
        $coupon                       = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );
        $disallowed_emails            = $this->get_disallowed_emails_for_coupon( $coupon_id );
        $field_name_disallowed_emails = $this->_constants->DISALLOWED_EMAIL;

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-disallowed-emails-field.php';
    }

    /**
     * Save disallowed emails for the coupon.
     *
     * @since 4.0.4
     * @access public
     *
     * @param int             $coupon_id Coupon ID.
     * @param Advanced_Coupon $coupon    Advanced coupon object.
     */
    public function save_disallowed_emails_data( $coupon_id, $coupon ) {
        $meta_name = $this->_constants->META_PREFIX . 'disallowed_emails';

        // Verify ACFW's dedicated nonce to ensure the request is valid before saving ACFW data.
        // Uses _acfw_nonce (not _wpnonce) to avoid conflicts with plugins like WooPayments that
        // may modify the shared _wpnonce field before form submission.
        if ( ! isset( $_POST['_acfw_nonce'] ) || false === wp_verify_nonce( sanitize_key( $_POST['_acfw_nonce'] ), 'acfw_save_coupon_data_' . $coupon_id ) ) {
            return;
        }

        /**
         * Skip if post data is empty and virtual coupons feature is enabled for coupon.
         * When post data is empty and virtual coupons feature is disabled, this means that the select field was simply emptied.
         */
        if ( ! isset( $_POST[ $meta_name ] ) && isset( $_POST[ $this->_constants->META_PREFIX . 'enable_virtual_coupons' ] ) ) {
            return;
        }

        $current  = array_map( 'sanitize_email', $this->get_disallowed_emails_for_coupon( $coupon_id ) );
        $new_data = array();

        if ( isset( $_POST[ $meta_name ] ) && is_array( $_POST[ $meta_name ] ) ) {
            $raw_inputs = array_map( 'sanitize_text_field', wp_unslash( $_POST[ $meta_name ] ) );

            // Flatten, clean, and sanitize all emails.
            $emails = array_filter(
                array_map(
                    'sanitize_email',
                    array_map(
                        'trim',
                        preg_split( '/\s*,\s*/', implode( ',', $raw_inputs ) )
                    )
                )
            );

            $new_data = $emails;
        }

        if ( $current === $new_data ) {
            return;
        }

        /**
         * Add user IDs that were not present in the current data.
         */
        $to_add = array_diff( $new_data, $current );
        foreach ( $to_add as $email ) {
            add_post_meta( $coupon_id, $meta_name, $email );
        }

        /**
         * Delete user IDs that are not present in the new data.
         */
        $to_delete = array_diff( $current, $new_data );
        foreach ( $to_delete as $email ) {
            delete_post_meta( $coupon_id, $meta_name, $email );
        }
    }

    /**
     * Get disallowed emails for a given coupon.
     *
     * @since 4.0.4
     * @access public
     *
     * @param int $coupon_id Coupon ID.
     * @return string[] List of email addresses.
     */
    public function get_disallowed_emails_for_coupon( $coupon_id ) {
        $raw_data          = get_post_meta( $coupon_id, $this->_constants->META_PREFIX . 'disallowed_emails' );
        $disallowed_emails = is_array( $raw_data ) && ! empty( $raw_data ) ? $raw_data : array();

        return array_map( 'sanitize_email', $disallowed_emails );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Disallowed_Emails class.
     *
     * @since 4.0.4
     * @access public
     */
    public function run() {
        // Admin.
        add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'display_disallowed_emails_field' ) );
        add_action( 'acfw_before_save_coupon', array( $this, 'save_disallowed_emails_data' ), 10, 2 );

        // Frontend Implementation.
        add_action( 'woocommerce_coupon_is_valid', array( $this, 'implement_disallowed_emails' ), 10, 2 );
    }
}
