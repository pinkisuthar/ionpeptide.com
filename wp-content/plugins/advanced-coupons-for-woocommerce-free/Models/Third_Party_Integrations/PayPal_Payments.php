<?php
namespace ACFWF\Models\Third_Party_Integrations;

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
 * Model that houses the logic of the WooCommerce PayPal Payments integration.
 *
 * WooCommerce PayPal Payments builds the amount it sends to PayPal from the cart breakdown
 * (item total + shipping + tax - discount total). It never reads the cart total, because PayPal
 * requires the amount to equal the sum of the breakdown fields exactly.
 *
 * The "apply store credit on checkout after tax and shipping" mode deducts the store credit by
 * overwriting the cart total, so the discount never reaches the discount total and PayPal charges
 * the full pre credit amount. PayPal Payments ships extension filters for this exact case. This
 * model feeds the applied store credit discount into the two cart side filters.
 *
 * The order side filter is deliberately not hooked. PayPal Payments adds it on top of
 * `WC_Order::get_total_discount()`, and `Store_Credits\Checkout::deduct_store_credits_discount_from_order_total()`
 * already writes the store credit into the order discount total. Hooking it would double the discount.
 *
 * @since 4.7.6
 */
class PayPal_Payments extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.7.6
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

    /*
    |--------------------------------------------------------------------------
    | Store Credits
    |--------------------------------------------------------------------------
     */

    /**
     * Add the applied store credit discount to the PayPal amount built from the cart object.
     *
     * Used by the smart buttons on the classic cart and checkout pages. PayPal Payments expects a
     * float in the major unit of the store currency.
     *
     * @since 4.7.6
     * @access public
     *
     * @param float    $extra Extra discount collected from other integrations.
     * @param \WC_Cart $cart  Cart object.
     * @return float Extra discount, with the applied store credit discount added.
     */
    public function add_store_credit_discount_to_cart_amount( $extra, $cart = null ) {
        $discount = \ACFWF()->Store_Credits_Checkout->get_applied_store_credit_discount( $cart );

        if ( 0 >= $discount ) {
            return $extra;
        }

        return (float) $extra + $discount;
    }

    /**
     * Add the applied store credit discount to the PayPal amount built from the Store API cart.
     *
     * Used when the customer changes the shipping address or the shipping option inside the PayPal
     * popup. PayPal calls the site back, and PayPal Payments loads the cart over a loopback HTTP
     * request that carries a cart token instead of the customer cookie. That request runs in a
     * process with no customer session, so `WC()->cart` there is an empty cart that belongs to
     * nobody. The totals object passed to this filter is the only trustworthy source, and it is
     * already in the minor unit of the cart currency.
     *
     * The measurement matches `get_applied_store_credit_discount()`, but it cannot apply the store
     * credit session gate for the reason above. Any gap between the breakdown and the payable total
     * is reported, which is what the gateway needs to charge the total the customer agreed to.
     *
     * @since 4.7.6
     * @access public
     *
     * @param int   $extra       Extra discount collected from other integrations, in the minor unit.
     * @param mixed $cart_totals Store API cart totals object supplied by PayPal Payments.
     * @return int Extra discount, with the applied store credit discount added.
     */
    public function add_store_credit_discount_to_store_api_cart_amount( $extra, $cart_totals = null ) {
        $discount = $this->_measure_store_api_discount( $cart_totals );

        // Return the value untouched, so a cart with no store credit keeps the caller's own type.
        if ( 0 >= $discount ) {
            return $extra;
        }

        return (int) $extra + $discount;
    }

    /**
     * Measure the discount already taken off the Store API payable total.
     *
     * The totals object belongs to PayPal Payments, so it is read by duck typing rather than by
     * class name. A build that renames or namespaces it degrades to "no discount" instead of a
     * fatal error.
     *
     * @since 4.7.6
     * @access private
     *
     * @param mixed $cart_totals Store API cart totals object supplied by PayPal Payments.
     * @return int Applied discount in the minor unit of the cart currency. 0 when it cannot be read.
     */
    private function _measure_store_api_discount( $cart_totals ) {
        if ( ! is_object( $cart_totals ) ) {
            return 0;
        }

        $amounts = array();

        foreach ( array( 'total_items', 'total_fees', 'total_shipping', 'total_tax', 'total_discount', 'total_price' ) as $field ) {
            if ( ! method_exists( $cart_totals, $field ) ) {
                return 0;
            }

            $money = $cart_totals->$field();

            if ( ! is_object( $money ) || ! method_exists( $money, 'value' ) ) {
                return 0;
            }

            $amounts[ $field ] = (int) $money->value();
        }

        // Sum the breakdown the way PayPal Payments does, then measure what the payable total lost.
        $breakdown = $amounts['total_items']
            + $amounts['total_fees']
            + $amounts['total_shipping']
            + $amounts['total_tax']
            - $amounts['total_discount'];

        $applied = $breakdown - $amounts['total_price'];

        return $applied > 0 ? $applied : 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute PayPal_Payments class.
     *
     * @since 4.7.6
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! $this->_helper_functions->is_plugin_active( Plugin_Constants::PAYPAL_PAYMENTS_PLUGIN ) ) {
            return;
        }

        add_filter( 'woocommerce_paypal_payments_cart_extra_discount', array( $this, 'add_store_credit_discount_to_cart_amount' ), 10, 2 );
        add_filter( 'woocommerce_paypal_payments_store_api_cart_extra_discount', array( $this, 'add_store_credit_discount_to_store_api_cart_amount' ), 10, 2 );
    }
}
