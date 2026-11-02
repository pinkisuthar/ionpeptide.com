<?php
namespace ACFWP\Models\Third_Party_Integrations;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the FunnelKit module.
 *
 * @since 3.6.1.1
 */
class FunnelKit extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Coupons applied to the cart before FunnelKit rebuilds it, captured so
     * they can be re-applied after FunnelKit's cart-prefill finishes.
     *
     * @since 4.0.8
     * @access private
     * @var array
     */
    private static $snapshotted_coupons = array();

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.6.1.1
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
     * Implement the force apply on funnelkit apply coupon.
     *
     * @since 3.6.1.1
     * @access public
     *
     * @param bool $should_force_apply Should force apply.
     * @return bool Filtered value of should force apply.
     */
    public function implement_force_apply_on_funnelkit_apply_coupon( $should_force_apply ) {
        return did_action( 'wfacp_before_coupon_apply' ) ? true : $should_force_apply;
    }

    /**
     * Capture currently applied coupons before FunnelKit empties the cart.
     *
     * FunnelKit's WFACP_Public::add_to_cart() calls WC()->cart->empty_cart()
     * only when the funnel step has its own products configured. When the
     * step has no products, the cart is left alone and no restore is needed.
     *
     * @since 4.0.8
     * @access public
     *
     * @param mixed $wfacp_public WFACP_Public instance passed by the action.
     */
    public function snapshot_applied_coupons_before_funnelkit_cart_rebuild( $wfacp_public = null ) {
        if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
            return;
        }

        // Only snapshot when the funnel step has products configured — that's
        // the code path that reaches empty_cart and wipes applied coupons.
        if ( is_object( $wfacp_public ) && method_exists( $wfacp_public, 'get_product_count' ) && (int) $wfacp_public->get_product_count() === 0 ) {
            return;
        }

        self::$snapshotted_coupons = (array) WC()->cart->get_applied_coupons();
    }

    /**
     * Re-apply coupons captured before FunnelKit's cart rebuild.
     *
     * Re-applying triggers woocommerce_applied_coupon, which ACFWP's
     * Add_Products model listens to for restoring add-product items. BOGO
     * pricing is restored on the next woocommerce_before_calculate_totals.
     *
     * @since 4.0.8
     * @access public
     */
    public function reapply_snapshotted_coupons_after_funnelkit_cart_rebuild() {
        if ( empty( self::$snapshotted_coupons ) || ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
            self::$snapshotted_coupons = array();
            return;
        }

        foreach ( self::$snapshotted_coupons as $code ) {
            if ( ! WC()->cart->has_discount( $code ) ) {
                WC()->cart->apply_coupon( $code );
            }
        }

        self::$snapshotted_coupons = array();
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute FunnelKit class.
     *
     * @since 3.6.1.1
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! $this->_helper_functions->is_plugin_active( 'funnel-builder/funnel-builder.php' ) ) {
            return;
        }

        add_filter( 'acfw_should_force_apply_run', array( $this, 'implement_force_apply_on_funnelkit_apply_coupon' ), 10, 2 );

        add_action( 'wfacp_add_to_cart_init', array( $this, 'snapshot_applied_coupons_before_funnelkit_cart_rebuild' ), 10, 1 );
        add_action( 'wfacp_after_add_to_cart', array( $this, 'reapply_snapshotted_coupons_after_funnelkit_cart_rebuild' ), 10, 0 );
    }
}
