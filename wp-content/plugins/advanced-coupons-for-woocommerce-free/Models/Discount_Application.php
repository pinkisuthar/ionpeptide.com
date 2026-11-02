<?php
namespace ACFWF\Models;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Session_Calculation_Cache;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the shared "coupon amount" discount application engine.
 *
 * Item-based features (BOGO Deals, Add Products) deliver their coupon-amount discounts
 * through the native `woocommerce_coupon_get_discount_amount` filter and do NOT go through
 * this engine. This engine only handles discount amounts that cannot be expressed as
 * per-item coupon discounts because they are calculated after WooCommerce has finalized
 * the coupon totals within the same totals pass (e.g. shipping override discounts, which
 * are only known once `woocommerce_package_rates` has run).
 *
 * Providers register their contributions through the `acfw_coupon_amount_total_contributions`
 * filter (pull model). On `woocommerce_calculated_total` this engine attributes each
 * contribution to its coupon (so it shows on the coupon line and is recorded on the order
 * coupon line item by `WC_Checkout::create_order_coupon_lines()`) and reduces the cart
 * grand total accordingly.
 *
 * Public Model.
 *
 * @since 4.8
 */
class Discount_Application extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.8
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
    | Implementation related functions.
    |--------------------------------------------------------------------------
     */

    /**
     * Apply registered coupon amount contributions to the calculated cart total.
     *
     * Collects contributions from providers, attributes each amount to its coupon on the
     * cart's coupon discount totals (which the classic cart coupon row, the Store API
     * coupon rows and `WC_Checkout::create_order_coupon_lines()` all read from), bumps the
     * cart discount total by the same sum, and returns the reduced grand total.
     *
     * Runs at priority 100 so it executes before the Store Credits after-tax discount
     * (priority 1001) — store credits must apply against the already-discounted total.
     *
     * @since 4.8
     * @access public
     *
     * @param float    $total Calculated cart total.
     * @param \WC_Cart $cart  Cart object.
     * @return float Filtered cart total.
     */
    public function apply_coupon_amount_contributions( $total, $cart ) {
        $contributions = $this->get_contributions( $cart );

        if ( empty( $contributions ) ) {
            return $total;
        }

        $coupon_discount_totals = $cart->get_coupon_discount_totals();
        $applied_sum            = 0.0;

        foreach ( $contributions as $coupon_code => $amount ) {
            $current = isset( $coupon_discount_totals[ $coupon_code ] ) ? (float) $coupon_discount_totals[ $coupon_code ] : 0.0;

            $coupon_discount_totals[ $coupon_code ] = $current + $amount;

            $applied_sum += $amount;
        }

        if ( 0.0 >= $applied_sum ) {
            return $total;
        }

        $cart->set_coupon_discount_totals( $coupon_discount_totals );
        $cart->set_discount_total( (float) $cart->get_discount_total() + $applied_sum );

        return max( 0.0, (float) $total - $applied_sum );
    }

    /**
     * Collect validated coupon amount contributions from registered providers.
     *
     * @since 4.8
     * @access public
     *
     * @param \WC_Cart $cart Cart object.
     * @return array Coupon code keyed list of contribution amounts (float, > 0).
     */
    public function get_contributions( $cart ) {
        /**
         * Filter the coupon amount total contributions for the current cart calculation.
         *
         * Providers should merge their contributions into the array using the coupon code
         * as key and the discount amount (positive float, normal precision) as value. The
         * amounts are attributed to the coupon's discount total and subtracted from the
         * cart grand total. Contributions for coupons that are not applied to the cart are
         * ignored.
         *
         * @since 4.8
         *
         * @param array    $contributions Coupon code keyed list of contribution amounts.
         * @param \WC_Cart $cart          Cart object.
         */
        $contributions = apply_filters( 'acfw_coupon_amount_total_contributions', array(), $cart );

        if ( ! is_array( $contributions ) || empty( $contributions ) ) {
            return array();
        }

        $applied_coupons = $cart->get_applied_coupons();
        $validated       = array();

        foreach ( $contributions as $coupon_code => $amount ) {
            $coupon_code = wc_format_coupon_code( (string) $coupon_code );
            $amount      = (float) $amount;

            // Skip contributions that aren't tied to an applied coupon or aren't a positive amount.
            if ( 0.0 >= $amount || ! in_array( $coupon_code, $applied_coupons, true ) ) {
                continue;
            }

            $validated[ $coupon_code ] = isset( $validated[ $coupon_code ] ) ? $validated[ $coupon_code ] + $amount : $amount;
        }

        return $validated;
    }

    /**
     * Clear all session calculation caches.
     *
     * Hooked on coupon apply/removal as a belt-and-braces clear. Note that admin-side
     * coupon edits cannot be cleared this way (the customer's session is not reachable
     * from the admin request) — callers must include the relevant coupon configuration
     * in their cache hash so edits invalidate implicitly.
     *
     * @since 4.8
     * @access public
     */
    public function clear_session_calculation_caches() {
        Session_Calculation_Cache::get_instance()->clear_all();
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Discount_Application class.
     *
     * @since 4.8
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        add_filter( 'woocommerce_calculated_total', array( $this, 'apply_coupon_amount_contributions' ), 100, 2 );

        // Invalidate session calculation caches when their inputs may have changed.
        add_action( 'woocommerce_applied_coupon', array( $this, 'clear_session_calculation_caches' ) );
        add_action( 'woocommerce_removed_coupon', array( $this, 'clear_session_calculation_caches' ) );
    }
}
