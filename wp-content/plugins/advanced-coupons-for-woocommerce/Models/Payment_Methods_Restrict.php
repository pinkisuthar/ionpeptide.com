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
 * Model that houses the logic of the Payment Methods Restriction module.
 * Public Model.
 *
 * @since 2.0
 */
class Payment_Methods_Restrict extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that holds check if cart is refreshed or not.
     *
     * @since 2.0
     * @access private
     * @var bool
     */
    private $_is_cart_refresh = false;

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
    | Implementation.
    |--------------------------------------------------------------------------
     */

    /**
     * Main feature implementation.
     * Filter the available payment gateways list and only show the payment gateways allowed based on all applied coupons on cart.
     *
     * @since 2.5
     * @access public
     *
     * @param array $available_gateways List of available gateway objects.
     */
    public function implement_coupon_payment_methods_restrict( $available_gateways ) {

        // only run implementation on frontend on cart/checkout page.
        if ( \WC()->cart && ! is_admin() && ( is_cart() || is_checkout() || $this->_helper_functions->is_store_api_request() ) ) {
            foreach ( \WC()->cart->get_applied_coupons() as $coupon_code ) {

                $coupon = new Advanced_Coupon( $coupon_code );

                if ( $coupon->get_advanced_prop( 'enable_payment_methods_restrict' ) !== 'yes' ) {
                    continue;
                }

                $restriction_type = $coupon->get_advanced_prop( 'payment_methods_restrict_type', 'allowed' );
                $selected_methods = $coupon->get_advanced_prop( 'payment_methods_restrict_selection', array() );

                if ( empty( $selected_methods ) ) {
                    continue;
                }

                $available_gateways = array_filter(
                    $available_gateways,
                    function ( $ag ) use ( $restriction_type, $selected_methods ) {

                    if ( 'disallowed' === $restriction_type ) {
                        return ! in_array( $ag->id, $selected_methods, true );
                    }

                    // allowed.
                    return in_array( $ag->id, $selected_methods, true );
                    }
                );
            }
        }

        return $available_gateways;
    }

    /*
    |--------------------------------------------------------------------------
    | Utilities.
    |--------------------------------------------------------------------------
     */

    /**
     * Get payment gateway options (editing context).
     *
     * @since 2.5
     * @access public
     *
     * @return array Payment method options.
     */
    public function get_payment_gateway_options() {
        $wc_gateways = \WC_Payment_Gateways::instance();
        $methods     = array();

        foreach ( $wc_gateways->payment_gateways() as $gateway ) {
            if ( 'yes' === $gateway->enabled ) {
                $methods[ $gateway->id ] = $gateway->method_title;
            }
        }

        return $methods;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Payment_Methods_Restrict class.
     *
     * @since 2.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::PAYMENT_METHODS_RESTRICT ) ) {
            return;
        }

        // NOTE: filter priority is set to 110 here so it will run after WWPP role payment gateway mapping filter.
        add_filter( 'woocommerce_available_payment_gateways', array( $this, 'implement_coupon_payment_methods_restrict' ), 110 );
    }
}
