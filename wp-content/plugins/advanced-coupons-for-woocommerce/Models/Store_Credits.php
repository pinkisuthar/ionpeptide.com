<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Store_Credit_Entry;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of store credits.
 * Public Model.
 *
 * @since 3.6.1
 */
class Store_Credits extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.6.1
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
    | Feature implementation.
    |--------------------------------------------------------------------------
     */

    /**
     * Calculate the maximum points that can be redeemed based on the maximum percentage.
     *
     * @since 4.0.3
     *
     * @param float $cart_total The total cart amount.
     * @return float The maximum points that can be redeemed.
     */
    public function calculate_max_points_redeemable( $cart_total ) {
        $max_percentage_points_redeem = get_option( $this->_constants->MAX_STORE_CREDITS_AMOUNT_REDEEM, 100 );

        // Calculate the maximum points that can be redeemed based on the maximum percentage.
        $max_points_redeemable = ( $cart_total * $max_percentage_points_redeem ) / 100;

        return $max_points_redeemable;
    }

    /**
     * Validate that the store credit redemption amount meets the minimum required amount.
     *
     * @since 4.0.7
     * @access public
     *
     * @param bool|WP_Error $is_valid   True if the store credits redeem amount is valid, WP_Error otherwise.
     * @param float         $amount     The amount of store credits to redeem.
     * @param float         $cart_total The total cart amount.
     * @return bool|\WP_Error True if valid, WP_Error if amount is below the minimum.
     */
    public function validate_minimum_store_credit_redemption_amount( $is_valid, $amount, $cart_total ) {
        if ( is_wp_error( $is_valid ) ) {
            return $is_valid;
        }

        $min_redemption_amount = (float) get_option( $this->_constants->MIN_STORE_CREDITS_AMOUNT_REDEEM, 0 );

        if ( $min_redemption_amount > 0 && $amount > 0 && $amount < $min_redemption_amount ) {
            $min_price = wc_price( $min_redemption_amount );

            // Strip HTML tags for block checkout context where notices are rendered as plain text.
            $is_block_checkout = defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['is_cart_checkout_block'] ) && sanitize_text_field( wp_unslash( $_POST['is_cart_checkout_block'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( $is_block_checkout ) {
                $min_price = html_entity_decode( wp_strip_all_tags( $min_price ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
            }

            return new \WP_Error(
                'acfw_store_credits_below_minimum_redeem_amount',
                sprintf(
                    /* translators: %s: minimum redemption amount */
                    __( 'The minimum store credits redemption amount is %s.', 'advanced-coupons-for-woocommerce' ),
                    $min_price
                ),
                array(
                    'status'                => 400,
                    'amount'                => $amount,
                    'min_redemption_amount' => $min_redemption_amount,
                )
            );
        }

        return $is_valid;
    }

    /**
     * Set the maximum amount of store credit points that can be redeemed in a single order based on total order.
     *
     * @since  3.6.1
     * @access public
     *
     * @param float $amount Filter amount value.
     * @param float $cart_total Filter cart total.
     * @return float float The adjusted amount of points that can be redeemed.
     */
    public function maximum_store_credit_points_redeem( $amount, $cart_total ) {
        $max_points_redeemable = $this->calculate_max_points_redeemable( $cart_total );

        return min( $amount, $max_points_redeemable );
    }

    /**
     * Validate the maximum store credit points redeemable amount.
     *
     * @since 4.0.3
     *
     * @param bool  $is_valid True if the store credits redeem amount is valid, false otherwise.
     * @param float $amount The amount of store credits to redeem.
     * @param float $cart_total The total cart amount.
     * @return bool|\WP_Error True if the store credits redeem amount is valid, WP_Error otherwise.
     */
    public function validate_maximum_store_credit_points_redeem( $is_valid, $amount, $cart_total ) {
        $sc_data               = \WC()->session ? \WC()->session->get( ACFWF()->Store_Credits_Checkout->get_store_credit_session_name(), null ) : null;
        $max_points_redeemable = $this->calculate_max_points_redeemable( $cart_total );

        if ( $sc_data && $amount > $max_points_redeemable && $sc_data['amount'] === $max_points_redeemable ) {
            return new \WP_Error(
                'acfw_store_credits_invalid_redeem_amount',
                __( 'The maximum store credits redeemable amount is already applied.', 'advanced-coupons-for-woocommerce' ),
                array(
                    'status'                => 400,
                    'amount'                => $amount,
                    'max_points_redeemable' => $max_points_redeemable,
                )
            );
        }

        return $is_valid;
    }

    /**
     * Adds the 'revoked_cashback' action type to handle the refund of cashback orders.
     *
     * @since 3.6.1
     * @access public
     *
     * @param array $actions An array of existing store credit decrease action types.
     * @return array Modified array of store credit decrease action types.
     */
    public function store_credit_decrease_action_types( $actions ) {
        $actions['revoked_cashback'] = array(
            'name'    => __( 'Revoked Cashback', 'advanced-coupons-for-woocommerce' ),
            'slug'    => 'refund',
            'related' => array(
                'object_type'         => 'order',
                'admin_label'         => __( 'View Order', 'advanced-coupons-for-woocommerce' ),
                'label'               => __( 'View Order', 'advanced-coupons-for-woocommerce' ),
                'admin_link_callback' => 'get_edit_post_link',
                'link_callback'       => array( \ACFWF()->Helper_Functions, 'get_order_frontend_link' ),
            ),
        );

        return $actions;
    }

    /**
     * Revokes the cashback store credits applied to an order when it is refunded.
     *
     * @since 3.6.1
     * @access public
     *
     * @param int $order_id  Order ID.
     * @param int $refund_id Refund ID.
     */
    public function revoke_cashback_store_credits( $order_id, $refund_id ) {
        // Check if the 'AUTO_REVOKE_CASHBACK_COUPON' option is enabled. If not, exit early.
        if ( ! get_option( $this->_constants->AUTO_REVOKE_CASHBACK_COUPON_STORE_CREDITS, false ) ) {
            return;
        }

        $total_cashback_amount = 0;
        $order                 = wc_get_order( $order_id );
        $store_credit_entry    = new Store_Credit_Entry();

        // Iterate over each coupon applied to the order.
        foreach ( $order->get_coupons() as $item ) {

            // reload coupon order items meta to ensure the custom metas we added are loaded.
            $item->read_meta_data();

            $cashback_amount = $item->get_meta( $this->_constants->ORDER_COUPON_CASHBACK_AMOUNT, true );

            // skip if coupon is not of cashback discount type.
            if ( is_null( $cashback_amount ) || $cashback_amount <= 0 ) {
                continue;
            }

            // Accumulate the total cashback amount for the order.
            $total_cashback_amount += $cashback_amount;
        }

        // If there's no cashback amount, no action is required.
        if ( $total_cashback_amount <= 0 ) {
            return;
        }

        $total_cashback_amount = apply_filters(
            'acfw_filter_amount',
            (float) $total_cashback_amount,
            true,
            array(
                'user_currency' => $order->get_currency(),
                'site_currency' => get_option( 'woocommerce_currency' ),
            )
        );

        // Set up the store credit entry properties for revocation.
        $store_credit_entry->set_prop( 'amount', $total_cashback_amount );
        $store_credit_entry->set_prop( 'type', 'decrease' );
        $store_credit_entry->set_prop( 'user_id', $order->get_customer_id() );
        $store_credit_entry->set_prop( 'action', 'revoked_cashback' );
        $store_credit_entry->set_prop( 'object_id', $order->get_id() );

        // Save the store credit entry to revoke the cashback amount.
        $store_credit_entry->save();
    }

    /**
     * Set the maximum store credit points redeemable amount.
     *
     * @since 4.0.5
     * @access public
     *
     * @param array $sc_data Store credit session data.
     * @return array Filtered store credit session data.
     */
    public function set_maximum_store_credit_points_redeem( $sc_data ) {
        if ( ! $sc_data ) {
            return $sc_data;
        }

        $store_credit_apply_type_option = get_option( \ACFWF()->Plugin_Constants::STORE_CREDIT_APPLY_TYPE, 'coupon' );

        // should recalculate the cart total if store credit apply type is coupon.
        // Always use current cart data, not session data for accuracy.
        if ( 'coupon' === $store_credit_apply_type_option ) {
            $cart_total  = \WC()->cart->get_subtotal();
            $cart_total += wc_prices_include_tax() ? \WC()->cart->get_subtotal_tax() : 0;

            // Set the maximum store credit amount to the minimum of the user's entered amount and the max redeemable based on percentage limit.
            // This ensures we don't override the user's balance or entered amount while still applying the percentage limit.
            $sc_data['amount'] = min( $sc_data['amount'], $this->calculate_max_points_redeemable( $cart_total ) );
        }

        return $sc_data;
    }

    /**
     * Automatically apply available store credits to the cart when the setting is enabled.
     *
     * @since 4.0.6
     * @access public
     *
     * @return void
     */
    public function maybe_auto_apply_store_credits() {
        // Skip if auto-apply setting is not enabled.
        if ( 'yes' !== get_option( $this->_constants->STORE_CREDITS_AUTO_APPLY_AVAILABLE_AMOUNT, 'no' ) ) {
            return;
        }

        // Skip if not on checkout page or if on a WooCommerce endpoint (e.g., order-received, order-pay).
        if ( ! is_checkout() || is_wc_endpoint_url() ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        if ( ! \WC()->cart || \WC()->cart->is_empty() ) {
            return;
        }

        if ( ! \WC()->session ) {
            return;
        }

        // Check if store credits are already applied.
        $sc_data = \WC()->session->get( \ACFWF()->Plugin_Constants::STORE_CREDITS_SESSION, null );
        if ( $sc_data && isset( $sc_data['amount'] ) && $sc_data['amount'] > 0 ) {
            return; // Store credits already applied.
        }

        // Skip if the store credits were manually removed.
        if ( \WC()->session->get( $this->_constants->STORE_CREDITS_MANUALLY_REMOVED, false ) ) {
            return;
        }

        // We only trigger store credit redemption using the customer's available balance.
        // The final redeemable amount will be recalculated within the redeem_store_credits() method.
        $amount = \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id() );
        if ( $amount > 0 ) {
            $result = \ACFWF()->Store_Credits_Checkout->redeem_store_credits( get_current_user_id(), $amount );
            if ( is_wp_error( $result ) ) {
                return;
            }
        }
    }

    /**
     * Maybe flag manual store credits removal to prevent auto-apply from re-applying (after tax apply type).
     *
     * @since 4.0.6
     * @access public
     *
     * @param float $amount The amount of store credits to redeem.
     * @param float $cart_total The total cart amount.
     * @return float The amount of store credits to redeem.
     */
    public function maybe_flag_manual_store_credits_removal_after_tax( $amount, $cart_total ) {
        // Skip if the option is not enabled.
        if ( 'yes' !== get_option( $this->_constants->STORE_CREDITS_AUTO_APPLY_AVAILABLE_AMOUNT, 'no' ) ) {
            return $amount;
        }

        // $amount <= 0 means that the store credits were removed.
        if ( $amount <= 0 && \WC()->session ) {
            \WC()->session->set( $this->_constants->STORE_CREDITS_MANUALLY_REMOVED, true );
        }

        return $amount;
    }

    /**
     * Maybe flag manual store credits removal to prevent auto-apply from re-applying (before tax apply type).
     *
     * @since 4.0.6
     * @access public
     *
     * @param bool   $should_clear Whether to clear the store credit session.
     * @param string $coupon_code  The store credit coupon code.
     * @return bool Whether to clear the store credit session.
     */
    public function maybe_flag_manual_store_credits_removal_before_tax( $should_clear, $coupon_code ) {
        // Skip if the option is not enabled.
        if ( 'yes' !== get_option( $this->_constants->STORE_CREDITS_AUTO_APPLY_AVAILABLE_AMOUNT, 'no' ) ) {
            return $should_clear;
        }

        // Flag manual store credits removal.
        if ( \WC()->session ) {
            \WC()->session->set( $this->_constants->STORE_CREDITS_MANUALLY_REMOVED, true );
        }

        return $should_clear;
    }

    /**
     * Reset the manually removed store credits flag after order is created.
     *
     * @since 4.0.6
     * @access public
     *
     * @return void
     */
    public function reset_manually_removed_store_credits_flag_on_order_created() {
        if ( \WC()->session ) {
            \WC()->session->set( $this->_constants->STORE_CREDITS_MANUALLY_REMOVED, null );
        }
    }

    /**
     * Reset the manually removed store credits flag after cart is emptied.
     *
     * @since 4.0.6
     * @access public
     *
     * @param string   $cart_item_key The cart item key.
     * @param \WC_Cart $cart The cart object.
     * @return void
     */
    public function reset_manually_removed_store_credits_flag_on_cart_emptied( $cart_item_key, $cart ) {
        if ( $cart->is_empty() && \WC()->session ) {
            \WC()->session->set( $this->_constants->STORE_CREDITS_MANUALLY_REMOVED, false );
        }
    }

    /**
     * Hide the store credit redemption UI when a coupon is applied to the cart.
     *
     * @since 4.0.9
     * @access public
     *
     * @param bool $is_allowed Whether store credits are allowed on checkout.
     * @return bool Filtered allowed value.
     */
    public function maybe_hide_store_credits_when_coupon_applied( $is_allowed ) {
        // Skip if store credits are already disallowed or the setting is disabled.
        if ( ! $is_allowed || 'yes' !== get_option( $this->_constants->HIDE_STORE_CREDITS_WHEN_COUPON_APPLIED, 'no' ) ) {
            return $is_allowed;
        }

        if ( ! \WC()->cart instanceof \WC_Cart ) {
            return $is_allowed;
        }

        // Exclude the store credit pseudo-coupon (used by the "before tax" apply type) from the check.
        $store_credit_coupon_code = \ACFWF()->Store_Credits_Checkout->get_store_credit_coupon_code();
        $applied_coupons          = array_diff( \WC()->cart->get_applied_coupons(), array( $store_credit_coupon_code ) );

        // Hide the store credit redemption UI when an actual coupon is applied to the cart.
        return empty( $applied_coupons ) ? $is_allowed : false;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Store_Credits class.
     *
     * @since 3.6.1
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_filter( 'acfw_store_credits_redeem_amount', array( $this, 'maximum_store_credit_points_redeem' ), 10, 2 );
        add_filter( 'acfw_get_store_credit_decrease_action_types', array( $this, 'store_credit_decrease_action_types' ), 10, 1 );
        add_action( 'woocommerce_order_refunded', array( $this, 'revoke_cashback_store_credits' ), 10, 2 );
        add_filter( 'acfw_is_valid_store_credits_redeem_amount', array( $this, 'validate_maximum_store_credit_points_redeem' ), 10, 3 );
        add_filter( 'acfw_is_valid_store_credits_redeem_amount', array( $this, 'validate_minimum_store_credit_redemption_amount' ), 11, 3 );
        add_filter( 'acfw_before_apply_store_credit_discount', array( $this, 'set_maximum_store_credit_points_redeem' ), 10, 1 );
        add_action( 'template_redirect', array( $this, 'maybe_auto_apply_store_credits' ), 10 );
        add_filter( 'acfw_store_credits_redeem_amount', array( $this, 'maybe_flag_manual_store_credits_removal_after_tax' ), 10, 2 );
        add_filter( 'acfw_should_clear_store_credit_session', array( $this, 'maybe_flag_manual_store_credits_removal_before_tax' ), 10, 2 );
        add_filter( 'acfw_is_allow_store_credits', array( $this, 'maybe_hide_store_credits_when_coupon_applied' ), 10, 1 );
        add_action( 'woocommerce_checkout_order_created', array( $this, 'reset_manually_removed_store_credits_flag_on_order_created' ), 10 );
        add_action( 'woocommerce_cart_item_removed', array( $this, 'reset_manually_removed_store_credits_flag_on_cart_emptied' ), 10, 2 );
    }
}
