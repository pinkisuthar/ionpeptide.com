<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Store_Credit_Entry;
use Automattic\WooCommerce\Utilities\NumberUtil;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic for applying store credits on the WooCommerce order pay page.
 * This allows customers to pay manually created (pending) orders using store credits.
 *
 * @since 4.0.7
 */
class Order_Pay extends Base_Model implements Model_Interface, Initiable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that houses the model name to be used when calling publicly.
     *
     * @since 4.0.7
     * @access private
     * @var string
     */
    private $_model_name = 'Store_Credits_Order_Pay';

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.0.7
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $main_plugin->add_to_all_plugin_models( $this, $this->_model_name );
        $main_plugin->add_to_public_models( $this, $this->_model_name );
    }

    /*
    |--------------------------------------------------------------------------
    | Feature implementation.
    |--------------------------------------------------------------------------
     */

    /**
     * Check if store credits are allowed on the order pay page for the given order.
     *
     * @since 4.0.7
     * @access public
     *
     * @param \WC_Order $order Order object.
     * @return bool True if allowed, false otherwise.
     */
    public function is_allowed_on_order_pay_page( ?\WC_Order $order ) {
        if ( ! is_user_logged_in() || ! is_checkout_pay_page() ) {
            return false;
        }

        if ( 'yes' !== get_option( \ACFWF()->Plugin_Constants::DISPLAY_STORE_CREDITS_REDEEM_FORM, 'yes' ) ) {
            return false;
        }

        if ( ! $order instanceof \WC_Order ) {
            return false;
        }

        // Order must belong to the current user.
        if ( (int) $order->get_customer_id() !== get_current_user_id() ) {
            return false;
        }

        // Order must still need payment.
        if ( ! $order->needs_payment() ) {
            return false;
        }

        // Respect the "hide when zero balance" setting.
        if ( 'yes' === get_option( \ACFWF()->Plugin_Constants::STORE_CREDITS_HIDE_CHECKOUT_ZERO_BALANCE, 'no' ) &&
            \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id() ) <= 0 ) {
            return false;
        }

        return apply_filters( 'acfw_is_allow_store_credits_order_pay', true, $order );
    }

    /**
     * Display the store credits UI on the WooCommerce order pay page.
     * Hooked to `woocommerce_pay_order_before_payment`.
     *
     * @since 4.0.7
     * @access public
     */
    public function display_store_credits_on_order_pay_page() {
        $order_id = absint( get_query_var( 'order-pay', 0 ) );
        $order    = wc_get_order( $order_id );
        $order    = $order ? $order : null;

        if ( ! $this->is_allowed_on_order_pay_page( $order ) ) {
            return;
        }

        $sc_data = $order->get_meta( \ACFWF()->Plugin_Constants::STORE_CREDITS_ORDER_PAID, true );

        // If store credits are already applied, show the "applied" state with a remove option.
        if ( is_array( $sc_data ) && ! empty( $sc_data ) ) {
            $this->_helper_functions->load_template(
                'order-pay-applied.php',
                array(
                    'sc_data' => $sc_data,
                    'order'   => $order,
                )
            );
            return;
        }

        // Render the store credits label and form directly without accordion wrapper.
        $labels       = \ACFWF()->Checkout->get_store_credits_redeem_form_labels();
        $user_balance = apply_filters( 'acfw_filter_amount', \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id() ) );

        echo '<h3 class="acfw-store-credits-order-pay-title">' . esc_html__( 'Pay with store credits?', 'advanced-coupons-for-woocommerce' ) . '</h3>';

        \ACFWF()->Helper_Functions->load_template(
            'acfw-store-credits/accordion.php',
            array(
                'user_balance' => $user_balance,
                'labels'       => $labels,
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX handlers.
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX handler: apply or remove store credits on the order pay page.
     *
     * POST params:
     *   - wpnonce  : nonce for `acfwp_store_credits_order_pay`
     *   - order_id : the order to apply credits to
     *   - amount   : credits amount to apply (0 = remove)
     *
     * @since 4.0.7
     * @access public
     */
    public function ajax_apply_store_credits_to_order() {
        $nonce    = isset( $_POST['wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpnonce'] ) ) : '';
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $amount   = isset( $_POST['amount'] ) ? floatval( wp_unslash( $_POST['amount'] ) ) : 0;

        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            wp_die();
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json(
                array(
                    'status'    => 'fail',
                    'error_msg' => __( 'You must be logged in to apply store credits.', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }

        if ( ! wp_verify_nonce( $nonce, 'acfwp_store_credits_order_pay' ) ) {
            wp_send_json(
                array(
                    'status'    => 'fail',
                    'error_msg' => __( 'You are not allowed to do this.', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order ) {
            wp_send_json(
                array(
                    'status'    => 'fail',
                    'error_msg' => __( 'Invalid order.', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }

        if ( (int) $order->get_customer_id() !== get_current_user_id() ) {
            wp_send_json(
                array(
                    'status'    => 'fail',
                    'error_msg' => __( 'Invalid order.', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }

        if ( ! $order->needs_payment() ) {
            wp_send_json(
                array(
                    'status'    => 'fail',
                    'error_msg' => __( 'This order does not need payment.', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }

        // Remove any previously applied store credits from this order first.
        $this->_remove_store_credits_from_order( $order );

        if ( $amount <= 0 ) {
            wc_add_notice( __( 'Store credit has been removed.', 'advanced-coupons-for-woocommerce' ) );
            wp_send_json(
                array(
                    'status'  => 'success',
                    'message' => __( 'Store credit has been removed.', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }

        // Round the amount to the correct number of decimals.
        $amount  = NumberUtil::round( $amount, wc_get_price_decimals() );
        $balance = apply_filters( 'acfw_filter_amount', \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id() ) );

        if ( $amount > $balance ) {
            wp_send_json(
                array(
                    'status'    => 'fail',
                    'error_msg' => __( 'The provided amount is invalid or the store credits balance is insufficient.', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }

        // Recalculate to get the clean order total (no SC deduction).
        $order->calculate_totals();
        $order_total = floatval( $order->get_total() );
        $amount      = min( $amount, $order_total );

        // Apply maximum percentage of store credits redemption allowed per order.
        // Uses the same option as the checkout flow (MAX_STORE_CREDITS_AMOUNT_REDEEM, default 100%).
        $max_redeemable = is_callable( array( \ACFWP()->Store_Credits, 'calculate_max_points_redeemable' ) )
            ? \ACFWP()->Store_Credits->calculate_max_points_redeemable( $order_total )
            : $order_total;
        if ( $amount > $max_redeemable ) {
            $amount = $max_redeemable;
        }

        // Apply minimum order total constraint.
        $min_order_total_allowed = floatval( get_option( \ACFWF()->Plugin_Constants::STORE_CREDIT_MIN_ORDER_TOTAL_ALLOWED, 0 ) );
        if ( ( $order_total - $amount ) < $min_order_total_allowed ) {
            $amount = $order_total - $min_order_total_allowed;
        }

        if ( $amount <= 0 ) {
            wp_send_json(
                array(
                    'status'    => 'fail',
                    'error_msg' => __( 'The store credits amount is invalid.', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }

        $raw_amount = apply_filters( 'acfw_filter_amount', $amount, true );

        // Save the store credits data as order meta.
        $meta_data = array(
            'amount'     => $amount,
            'raw_amount' => $raw_amount,
            'cart_total' => $order_total,
            'currency'   => $order->get_currency(),
        );

        $order->update_meta_data( \ACFWF()->Plugin_Constants::STORE_CREDITS_ORDER_PAID, $meta_data );
        $order->save_meta_data();

        // Recalculate order totals; this triggers `deduct_store_credits_discount_from_order_total`
        // (hooked in the free plugin) which deducts the SC amount from the order discount and total.
        $order->calculate_totals( true );

        // Create a store credit decrease entry to record the deduction.
        if ( is_callable( array( \ACFWF()->Store_Credits_Checkout, 'create_discount_store_credit_entry' ) ) ) {
            \ACFWF()->Store_Credits_Checkout->create_discount_store_credit_entry( $raw_amount, $order );
        }

        // Refresh the cached balance.
        \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id(), true );

        // When store credits cover the full order amount the total becomes $0.
        // WooCommerce's needs_payment() returns false for zero-total orders, so reloading
        // the order pay page would show an error. Instead, mark the order as paid and
        // redirect to the thank-you page.
        $order = wc_get_order( $order_id );
        if ( (float) $order->get_total() <= 0.0 ) {
            $order->payment_complete();
            wp_send_json(
                array(
                    'status'       => 'success',
                    'message'      => __( 'Store credit was applied successfully.', 'advanced-coupons-for-woocommerce' ),
                    'redirect_url' => $order->get_checkout_order_received_url(),
                )
            );
        }

        wc_add_notice( __( 'Store credit was applied successfully.', 'advanced-coupons-for-woocommerce' ) );

        wp_send_json(
            array(
                'status'  => 'success',
                'message' => __( 'Store credit was applied successfully.', 'advanced-coupons-for-woocommerce' ),
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Utility methods.
    |--------------------------------------------------------------------------
     */

    /**
     * Remove any previously applied store credits from an order.
     * Restores the user's balance and recalculates order totals.
     *
     * @since 4.0.7
     * @access private
     *
     * @param \WC_Order $order Order object.
     */
    private function _remove_store_credits_from_order( \WC_Order $order ) {
        $sc_data = $order->get_meta( \ACFWF()->Plugin_Constants::STORE_CREDITS_ORDER_PAID, true );

        if ( ! is_array( $sc_data ) || empty( $sc_data ) ) {
            return;
        }

        // Use the raw_amount stored in order meta — this is exactly what was deducted
        // during this apply. Avoid get_total_store_credits_discount_for_order() as it sums
        // ALL past decrease entries for the order, which accumulates across apply/remove cycles.
        $amount = floatval( $sc_data['raw_amount'] ?? 0 );

        // Remove the store credits metadata so the recalculation hook skips the deduction.
        $order->delete_meta_data( \ACFWF()->Plugin_Constants::STORE_CREDITS_ORDER_PAID );
        $order->delete_meta_data( \ACFWF()->Plugin_Constants::STORE_CREDITS_VERSION );
        $order->save_meta_data();

        // Recalculate totals without the store credit deduction.
        $order->calculate_totals( true );
        $order->save();

        // Create a store credit increase entry to restore the user's balance.
        if ( $amount > 0 ) {
            $store_credit_entry = new Store_Credit_Entry();
            $store_credit_entry->set_prop( 'amount', $amount );
            $store_credit_entry->set_prop( 'user_id', $order->get_customer_id() );
            $store_credit_entry->set_prop( 'object_id', $order->get_id() );
            $store_credit_entry->set_prop( 'type', 'increase' );
            $store_credit_entry->set_prop( 'action', 'cancelled_order' );
            $store_credit_entry->save();

            \ACFWF()->Store_Credits_Calculate->get_customer_balance( $order->get_customer_id(), true );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts.
    |--------------------------------------------------------------------------
     */

    /**
     * Execute initialisation code that needs to run on plugin activation.
     *
     * @since 4.0.7
     * @access public
     * @implements ACFWP\Interfaces\Initiable_Interface
     */
    public function initialize() {
        if ( ! \ACFWF()->Helper_Functions->is_module( \ACFWF()->Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            return;
        }

        add_action( 'wp_ajax_acfwp_apply_store_credits_order_pay', array( $this, 'ajax_apply_store_credits_to_order' ) );
    }

    /**
     * Execute Order_Pay class.
     *
     * @since 4.0.7
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! \ACFWF()->Helper_Functions->is_module( \ACFWF()->Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            return;
        }

        add_action( 'woocommerce_pay_order_before_payment', array( $this, 'display_store_credits_on_order_pay_page' ) );
    }
}
