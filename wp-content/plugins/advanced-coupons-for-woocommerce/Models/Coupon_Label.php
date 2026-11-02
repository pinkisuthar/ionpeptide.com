<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Helpers\Helper_Functions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Coupon Label module.
 * Public Model.
 *
 * @since 2.0
 */
class Coupon_Label extends Base_Model implements Model_Interface {
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
        $main_plugin->add_to_public_models( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    /**
     * Display the coupon label edit field in the coupon editor.
     *
     * @since 3.5.2
     * @access public
     *
     * @param int        $coupon_id Coupon Id.
     * @param \WC_Coupon $coupon    Coupon object.
     */
    public function display_coupon_label_edit_field( $coupon_id, $coupon ) {

        $meta_name = $this->_constants->META_PREFIX . 'coupon_label';
        $value     = $coupon->get_meta( $meta_name, true );

        woocommerce_wp_text_input(
            array(
                'id'          => $meta_name,
                'value'       => esc_attr( $value ),
                'label'       => __( 'Coupon label', 'advanced-coupons-for-woocommerce' ),
                'placeholder' => sprintf(
                    /* Translators: %s: Coupon code tag. */
                    __( 'Coupon: %s', 'advanced-coupons-for-woocommerce' ),
                    '{coupon_code}'
                ),
                'description' => sprintf(
                    /* Translators: %s: Coupon code tag. */
                    __( 'Modify the label displayed for the coupon on the cart totals table. Add the %s tag to this text and it will be replaced with the actual coupon code.', 'advanced-coupons-for-woocommerce' ),
                    '{coupon_code}'
                ),
                'desc_tip'    => true,
            )
        );
    }

    /**
     * Save coupon label field value.
     *
     * @since 3.5.2
     * @access public
     *
     * @param int             $coupon_id Coupon ID.
     * @param Advanced_Coupon $coupon    Advanced coupon object.
     */
    public function save_coupon_label_field_value( $coupon_id, $coupon ) {
        // Verify ACFW's dedicated nonce to ensure the request is valid before saving ACFW data.
        // Uses _acfw_nonce (not _wpnonce) to avoid conflicts with plugins like WooPayments that
        // may modify the shared _wpnonce field before form submission.
        if ( ! isset( $_POST['_acfw_nonce'] ) || false === wp_verify_nonce( sanitize_key( $_POST['_acfw_nonce'] ), 'acfw_save_coupon_data_' . $coupon_id ) ) {
            return;
        }

        $meta_name = $this->_constants->META_PREFIX . 'coupon_label';
        $value     = isset( $_POST[ $meta_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $meta_name ] ) ) : '';

        // If the coupon is empty or not valid, then delete the meta.
        if ( ! $value ) {
            delete_post_meta( $coupon_id, $meta_name );
            return;
        }

        update_post_meta( $coupon_id, $meta_name, $value );
    }

    /*
    |--------------------------------------------------------------------------
    | Implementation
    |--------------------------------------------------------------------------
    */

    /**
     * Apply the custom coupon label in the cart.
     *
     * @since 3.5.2
     * @access public
     *
     * @param string    $label  Coupon label.
     * @param WC_Coupon $coupon Coupon object.
     * @return string Filtered coupon label.
     */
    public function apply_custom_coupon_label( $label, $coupon ) {
        $custom_label = $coupon->get_meta( $this->_constants->META_PREFIX . 'coupon_label', true );
        if ( $custom_label ) {
            $label = str_replace( '{coupon_code}', $coupon->get_code(), trim( $custom_label ) );
        }

        return $label;
    }

    /**
     * Hide zero value coupons.
     *
     * @since 3.5.5
     * @access public
     *
     * @param string $coupon_html Coupon html.
     * @param object $coupon      Coupon object.
     */
    public function hide_zero_value_coupons( $coupon_html, $coupon ) {
        $hide_zero_value_coupons = 'yes' === get_option( $this->_constants->OPTION_HIDE_ZERO_DOLLAR_COUPON ) ? true : false;
        if ( $hide_zero_value_coupons && intval( $coupon->get_amount() ) === 0 ) {
            $coupon_html = str_replace( '-<span', '<span', $coupon_html ); // Remove the minus sign.
            $coupon_html = str_replace( wc_price( 0 ), '', $coupon_html );
        }
        return $coupon_html;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
    */

    /**
     * Execute Coupon_Label class.
     *
     * @since 2.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'woocommerce_coupon_options', array( $this, 'display_coupon_label_edit_field' ), 10, 2 );
        add_action( 'acfw_before_save_coupon', array( $this, 'save_coupon_label_field_value' ), 10, 2 );
        add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'apply_custom_coupon_label' ), 10, 2 );
        add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'hide_zero_value_coupons' ), 10, 2 );
    }

}
