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
 * Model that houses the logic of extending the coupon system of woocommerce.
 * It houses the logic of handling coupon url.
 * Public Model.
 *
 * @since 3.6.0
 */
class Allowed_Coupons extends Base_Model implements Model_Interface {
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
     * @since 3.6.0
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
    | Admin Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Display the allowed coupons field in the coupon options.
     *
     * @since 3.6.0
     * @access public
     *
     * @param int $coupon_id Coupon ID.
     */
    public function display_allowed_coupons_field( $coupon_id ) {

        $coupon      = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );
        $excluded    = $coupon->get_advanced_prop( 'allowed_coupons', array() );
        $options     = $this->_helper_functions->prepare_coupon_select_options( $excluded );
        $classname   = 'acfw_allowed_coupons_field acfw-coupons-restriction-field';
        $field_name  = '_acfw_allowed_coupon_ids[]';
        $field_label = __( 'Allowed coupons', 'advanced-coupons-for-woocommerce' );
        $placeholder = __( 'Search coupons and/or coupon categories&hellip;', 'advanced-coupons-for-woocommerce' );
        $tooltip     = __( 'Select the coupons that can be used with this coupon when the individual use option is enabled.', 'advanced-coupons-for-woocommerce' );

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-select-multiple-coupon-field.php';
    }

    /**
     * Save the allowed coupons field data.
     *
     * @since 3.6.0
     * @access public
     *
     * @param int             $coupon_id Coupon ID.
     * @param Advanced_Coupon $coupon Advanced coupon object.
     */
    public function save_allowed_coupons_field_data( $coupon_id, $coupon ) {

        // Verify ACFW's dedicated nonce to ensure the request is a genuine coupon edit submission before touching saved data.
        // Uses _acfw_nonce (not _wpnonce) to avoid conflicts with plugins like WooPayments that may modify the shared
        // _wpnonce field. Without this guard, stray saves (e.g. triggered while WooPayments is active) would fire this
        // callback without the field data and wipe the saved allowed coupons. See issue #1427.
        if ( ! isset( $_POST['_acfw_nonce'] ) || false === wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_acfw_nonce'] ) ), 'acfw_save_coupon_data_' . $coupon_id ) ) {
            return;
        }

        // skip if the field data is not set.
        if ( ! isset( $_POST['_acfw_allowed_coupon_ids'] ) ) {
            $coupon->set_advanced_prop( 'allowed_coupons', array() );
            return;
        }

        $allowed_coupon_ids = array_map( 'sanitize_text_field', wp_unslash( $_POST['_acfw_allowed_coupon_ids'] ) );
        $coupon->set_advanced_prop( 'allowed_coupons', $allowed_coupon_ids );
    }

    /*
    |--------------------------------------------------------------------------
    | Implementation Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Get the allowed coupons for individual use coupon.
     *
     * @since 3.6.0
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     */
    public function get_individual_use_coupon_allowed_coupons( $coupon ) {
        $allowed_coupon_ids = (array) $coupon->get_meta( $this->_constants->META_PREFIX . 'allowed_coupons', true );
        return $this->_helper_functions->get_coupon_ids_for_select_coupons_field_value( $allowed_coupon_ids );
    }

    /**
     * Allow coupon codes for individual use.
     *
     * @since 3.6.0
     * @since 4.0.5 Enhanced to support case-insensitive coupon code comparison
     * @access public
     *
     * @param array      $value Allowed coupon codes list.
     * @param \WC_Coupon $coupon Coupon object of the coupon currently being applied in the cart.
     * @param array      $applied_coupons Applied coupon codes list.
     * @return array Filtered allowed coupon codes list.
     */
    public function allow_coupon_codes_for_individual_use( $value, $coupon, $applied_coupons ) {
        $allowed_coupon_ids = $this->get_individual_use_coupon_allowed_coupons( $coupon );
        $allowed_coupons    = array_map( 'wc_get_coupon_code_by_id', $allowed_coupon_ids );

        // Normalize allowed and applied coupon codes for case-insensitive comparison,
        // but return the original applied coupon codes for compatibility.
        $allowed_normalized = array_map( 'wc_format_coupon_code', $allowed_coupons );
        $applied_map        = array();
        foreach ( (array) $applied_coupons as $applied_code ) {
            $applied_map[ \wc_format_coupon_code( $applied_code ) ] = $applied_code;
        }

        $to_keep = array();
        foreach ( $allowed_normalized as $normalized_code ) {
            if ( isset( $applied_map[ $normalized_code ] ) ) {
                $to_keep[] = $applied_map[ $normalized_code ];
            }
        }

        if ( ! empty( $to_keep ) ) {
            $value = array_values( array_unique( array_merge( (array) $value, $to_keep ) ) );
        }

        return $value;
    }

    /**
     * Validate that the already applied coupon are allowed to be retained when an individual use only coupon is being applied in the cart.
     *
     * @since 3.6.0
     * @access public
     *
     * @param bool       $value       Validation result.
     * @param \WC_Coupon $the_coupon  Coupon object of the coupon currently being applied in the cart.
     * @param \WC_Coupon $coupon     Coupon object of the coupon that's already applied in the cart.
     * @return bool Filtered validation result.
     */
    public function validate_applied_coupon_allowed_for_individual_use( $value, $the_coupon, $coupon ) {

        $allowed_coupon_ids = (array) $coupon->get_meta( $this->_constants->META_PREFIX . 'allowed_coupons', true );
        $allowed_coupon_ids = $this->_helper_functions->get_coupon_ids_for_select_coupons_field_value( $allowed_coupon_ids );

        if ( in_array( $the_coupon->get_id(), $allowed_coupon_ids, true ) ) {
            $value = true;
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Exclude_Coupons class.
     *
     * @since 3.6.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'display_allowed_coupons_field' ), 10 );
        add_action( 'acfw_before_save_coupon', array( $this, 'save_allowed_coupons_field_data' ), 10, 2 );

        add_filter( 'woocommerce_apply_individual_use_coupon', array( $this, 'allow_coupon_codes_for_individual_use' ), 10, 3 );
        add_filter( 'woocommerce_apply_with_individual_use_coupon', array( $this, 'validate_applied_coupon_allowed_for_individual_use' ), 10, 3 );
    }
}
