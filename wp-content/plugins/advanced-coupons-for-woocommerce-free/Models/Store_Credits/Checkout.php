<?php
namespace ACFWF\Models\Store_Credits;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Initializable_Interface;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Store_Credit_Entry;
use Automattic\WooCommerce\Utilities\NumberUtil;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Store Credits Checkout module.
 *
 * @since 4.0
 */
class Checkout extends Base_Model implements Model_Interface, Initializable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Hook that the reapply store credit discount link carries.
     *
     * The link uses this value as both its class and its "href" fragment.
     *
     * @since 4.7.6
     * @var string
     */
    const REAPPLY_DISCOUNT_LINK_HOOK = 'acfw-reapply-sc-discount';

    /**
     * Property that houses the model name to be used when calling publicly.
     *
     * @since 4.0
     * @access private
     * @var string
     */
    private $_model_name = 'Store_Credits_Checkout';

    /**
     * Property that holds the override data for the store credit dynamic coupon.
     *
     * @since 4.5.2
     * @access private
     * @var null|array
     */
    private $_coupon_override_data = null;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.0
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
    | Checkout displays.
    |--------------------------------------------------------------------------
     */

    /**
     * Display store credits discount row.
     *
     * @since 4.0
     * @access public
     */
    public function display_store_credits_discount_row() {
        if ( ! $this->is_allow_store_credits() ) {
            return;
        }

        $sc_data = \WC()->session->get( Plugin_Constants::STORE_CREDITS_SESSION, null );

        // skip displaying discount if not yet applied.
        if ( ! $sc_data ) {
            return;
        }

        // detect option : `Store credit apply type` is set to `Apply store credit on checkout before tax and shipping.`
        // if the option is active then the checkout should clear store credit session.
        $store_credit_apply_type_option = get_option( Plugin_Constants::STORE_CREDIT_APPLY_TYPE, 'coupon' );
        if ( 'coupon' === $store_credit_apply_type_option ) {
            $this->clear_store_credit_session();
            return;
        }

        $user_balance = apply_filters( 'acfw_filter_amount', \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id() ) );

        // load store credit discount row template.
        $this->_helper_functions->load_template(
            'acfw-store-credits/checkout-discount.php',
            array(
                'user_balance' => $user_balance,
                'amount'       => $sc_data['amount'] * -1,
                'order_total'  => $sc_data['cart_total'],
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Feature implementation.
    |--------------------------------------------------------------------------
     */

    /**
     * Apply store credits as payment in checkout.
     *
     * @since 4.0
     * @since 4.2.1 Wrap redeem amount with NumberUtil::round function to make sure its precise before comparing it with user's balance.
     * @since 4.5.1 Make method public.
     * @since 4.7.6 Cap the redeemed amount against the cart total when the minimum order total allowed setting applies.
     * @access public
     *
     * @param int   $user_id User ID.
     * @param float $amount Amount of credits to redeem.
     * @return bool|WP_Error True on success, error object on failure.
     */
    public function redeem_store_credits( $user_id, $amount ) {
        if ( ! $this->is_allow_store_credits() ) {
            return new \WP_Error(
                'acfw_cart_items_not_allowed_store_credits',
                __( 'Some of the items in your cart are not allowed to be paid via store credit.', 'advanced-coupons-for-woocommerce-free' ),
                array( 'status' => 400 )
            );
        }

        $amount  = NumberUtil::round( $amount, wc_get_price_decimals() );
        $balance = apply_filters( 'acfw_filter_amount', \ACFWF()->Store_Credits_Calculate->get_customer_balance( $user_id ) );

        if ( $amount < 0 || $amount > $balance ) {
            return new \WP_Error(
                'acfw_store_credits_insufficient_balance',
                __( 'The provided amount is invalid or the store credits balance is insufficient.', 'advanced-coupons-for-woocommerce-free' ),
                array(
                    'status' => 400,
                    'amount' => $amount,
                )
            );
        }

        $is_apply_coupon = 'coupon' === get_option( Plugin_Constants::STORE_CREDIT_APPLY_TYPE, 'coupon' );

        // get the cart total to compare the store credits discount based on the store credit apply type setting.
        if ( $is_apply_coupon ) {
            $cart_total  = \WC()->cart->get_subtotal();
            $cart_total += wc_prices_include_tax() ? \WC()->cart->get_subtotal_tax() : 0;
            $cart_total  = apply_filters( 'acfw_store_credits_get_cart_total', $cart_total );
        } else {
            $cart_total = $this->get_cart_total_before_store_credit_discounts();
        }

        if ( 0 >= $cart_total ) {
            $this->clear_store_credit_session();
            return new \WP_Error(
                'acfw_store_credits_zero_cart_total',
                __( 'There was an error trying to apply the store credit discount. Please try again.', 'advanced-coupons-for-woocommerce-free' ),
                array(
                    'status'     => 400,
                    'amount'     => $amount,
                    'cart_total' => $cart_total,
                )
            );
        }

        /**
         * Hook to check if the store credits redeem amount is valid.
         *
         * @since 4.6.7
         *
         * @param bool $is_valid True if the store credits redeem amount is valid, false otherwise.
         * @param float $amount The amount of store credits to redeem.
         * @param float $cart_total The total cart amount.
         */
        $is_valid = apply_filters( 'acfw_is_valid_store_credits_redeem_amount', true, $amount, $cart_total );

        if ( true !== $is_valid ) {
            // Return custom error message if the filter returns a WP_Error object.
            if ( is_wp_error( $is_valid ) ) {
                return $is_valid;
            }

            return new \WP_Error(
                'acfw_store_credits_invalid_redeem_amount',
                __( 'The provided amount is invalid or the store credits balance is insufficient.', 'advanced-coupons-for-woocommerce-free' ),
                array(
                    'status' => 400,
                    'amount' => $amount,
                )
            );
        }

        $amount = apply_filters( 'acfw_store_credits_redeem_amount', min( $amount, $cart_total ), $cart_total );

        // minimum order total allowed after store credit deduction.
        $min_order_total_allowed = (float) get_option( Plugin_Constants::STORE_CREDIT_MIN_ORDER_TOTAL_ALLOWED, 0 );
        if ( ( $cart_total - $amount ) < $min_order_total_allowed ) {
            // cap the redeemed amount to what the cart can spare, so the order total lands exactly on the minimum.
            $amount = NumberUtil::round( $cart_total - $min_order_total_allowed, wc_get_price_decimals() );
        }

        /**
         * NOTE: When currency switcher is active, the amounts saved in session will always be in the user based currency.
         */
        if ( 0 >= $amount ) {
            \WC()->cart->remove_coupon( $this->get_store_credit_coupon_code() );
            $this->clear_store_credit_session();
        } else {
            \WC()->session->set(
                $this->get_store_credit_session_name(),
                apply_filters(
                    'acfw_store_credits_discount_session',
                    array(
                        'amount'         => $amount,
                        'cart_total'     => $cart_total,
                        'subtotal'       => \WC()->cart->get_subtotal(),
                        'shipping_total' => \WC()->cart->get_shipping_total(),
                        'currency'       => get_woocommerce_currency(),
                    )
                )
            );
        }

        \WC()->cart->calculate_totals();

        return true;
    }

    /**
     * Get the cart total value before the store credit discount was applied.
     *
     * @since 4.0
     * @access public
     *
     * @return float Cart total.
     */
    public function get_cart_total_before_store_credit_discounts() {
        $sc_data = \WC()->session->get( Plugin_Constants::STORE_CREDITS_SESSION, null );

        if ( is_array( $sc_data ) && isset( $sc_data['cart_total'] ) ) {
            return $sc_data['cart_total'];
        }

        return apply_filters( 'acfw_store_credits_get_cart_total', \WC()->cart->get_total( 'edit' ) );
    }

    /*
    |--------------------------------------------------------------------------
    | Apply store credits as coupon.
    |--------------------------------------------------------------------------
     */

    /**
     * Apply store credit discount on cart total calculation.
     *
     * @since 4.5.2
     * @since 4.7.7 Remove a stale store credit coupon before applying.
     * @access public
     */
    public function apply_store_credits_as_coupon() {
        $this->remove_stale_store_credit_coupon();

        $sc_data         = \WC()->session->get( Plugin_Constants::STORE_CREDITS_COUPON_SESSION, null );
        $applied_coupons = \WC()->cart->get_applied_coupons();
        $coupon_code     = $this->get_store_credit_coupon_code();

        // skip if data is not valid.
        if ( ! $sc_data
            || ! isset( $sc_data['amount'] )
            || get_woocommerce_currency() !== $sc_data['currency']
        ) {
            return;
        }

        // detect option : `Store credit apply type` is set to `Apply store credit on checkout after tax and shipping.`
        // if the option is active then the checkout shouldn't apply any store credit discount.
        $store_credit_apply_type_option = get_option( Plugin_Constants::STORE_CREDIT_APPLY_TYPE, 'coupon' );
        if (
            'after_tax' === $store_credit_apply_type_option && // check if the option is active.
            in_array( $coupon_code, $applied_coupons, true ) // check if the coupon is already applied.
        ) {
            \WC()->cart->remove_coupon( $coupon_code );
            $this->clear_store_credit_session();
            return;
        }

        // set coupon data for override.
        $this->_coupon_override_data = apply_filters( 'acfw_before_apply_store_credit_discount', $sc_data, Plugin_Constants::STORE_CREDITS_COUPON_SESSION );

        // don't proceed when coupon already applied on cart.
        if ( \WC()->cart->has_discount( $this->get_store_credit_coupon_code() ) ) {
            return;
        }

        // apply store credit coupon.

        $applied_coupons[] = $this->get_store_credit_coupon_code();

        // Silently apply the store credit discount to prevent the "coupon applied successfully" notice to show up.
        \WC()->cart->set_applied_coupons( $applied_coupons );
    }

    /**
     * Silently remove the store credit coupon from the cart when its session data is cleared.
     *
     * The coupon stays in the cart after a declined payment, but the order processing already cleared its session.
     * Without the session data the coupon is not valid, and WC blocks the checkout with a coupon error.
     *
     * @since 4.7.7
     * @access public
     */
    public function remove_stale_store_credit_coupon() {
        if ( ! \WC()->cart || ! is_object( \WC()->session ) || \WC()->session->get( Plugin_Constants::STORE_CREDITS_COUPON_SESSION, null ) ) {
            return;
        }

        $applied_coupons = \WC()->cart->get_applied_coupons();
        $coupon_code     = $this->get_store_credit_coupon_code();

        if ( in_array( $coupon_code, $applied_coupons, true ) ) {
            \WC()->cart->set_applied_coupons( array_values( array_diff( $applied_coupons, array( $coupon_code ) ) ) );
        }
    }

    /**
     * Override store credits coupon object data.
     *
     * @since 4.5.2
     * @access public
     *
     * @param bool|array $filter_value Explicit false or list of coupon properties.
     * @param mixed      $data         Coupon code, ID or object.
     * @param \WC_Coupon $coupon       Coupon object.
     */
    public function override_store_credit_coupon_data( $filter_value, $data, $coupon ) {

        // skip if coupon code is not valid.
        if ( $data !== $this->get_store_credit_coupon_code() ) {
            return $filter_value;
        }

        if ( is_null( $this->_coupon_override_data ) && is_object( \WC()->session ) ) {
            $this->_coupon_override_data = apply_filters( 'acfw_before_apply_store_credit_discount', \WC()->session->get( Plugin_Constants::STORE_CREDITS_COUPON_SESSION, null ), Plugin_Constants::STORE_CREDITS_COUPON_SESSION );
        }

        // skip if store credits data is not valid.
        if ( ! is_array( $this->_coupon_override_data ) || ! isset( $this->_coupon_override_data['amount'] ) ) {
            return $filter_value;
        }

        // set store credits amount as coupon amount.
        $filter_value = apply_filters(
            'acfw_override_store_credit_coupon_data',
            array(
                'amount'        => $this->_coupon_override_data['amount'],
                'discount_type' => 'fixed_cart',
            ),
            $this->_coupon_override_data
        );

        // clear property data.
        $this->_coupon_override_data = null;

        return $filter_value;
    }

    /**
     * Apply the custom coupon label in the cart.
     *
     * @since 4.5.2
     * @access public
     *
     * @param string    $label  Coupon label.
     * @param WC_Coupon $coupon Coupon object.
     * @return string Filtered coupon label.
     */
    public function apply_store_credit_discount_coupon_label( $label, $coupon ) {

        if ( $coupon->get_code() === $this->get_store_credit_coupon_code() && $coupon->get_virtual() ) {
            return apply_filters( 'acfw_store_credit_coupon_discount_label', __( 'Store Credit Discount', 'advanced-coupons-for-woocommerce-free' ) );
        }

        return $label;
    }

    /**
     * Clear session data when store credit coupon is removed.
     *
     * @since 4.5.2
     * @access public
     *
     * @param string $coupon_code Coupon Code.
     */
    public function store_credit_coupon_discount_removed( $coupon_code ) {

        if ( $coupon_code !== $this->get_store_credit_coupon_code() ) {
            return;
        }

        $should_clear = apply_filters( 'acfw_should_clear_store_credit_session', true, $coupon_code );

        if ( $should_clear ) {
            $this->clear_store_credit_session();
        }
    }

    /**
     * Get the coupon code displayed for store credits discount.
     *
     * @since 4.5.2
     * @access public
     */
    public function get_store_credit_coupon_code() {
        return apply_filters( 'acfw_store_credit_coupon_code', 'store credit' );
    }

    /**
     * Process order that has a store credit coupon discount applied.
     *
     * @since 4.5.2
     * @since 4.7.7 Reset a resumed failed order to pending so the store credit is applied again.
     * @access public
     *
     * @param int       $order_id    Order ID.
     * @param array     $posted_data Posted data from checkout form.
     * @param \WC_Order $order       Order object.
     */
    public function process_order_with_store_credit_coupon( $order_id, $posted_data, $order ) {

        $sc_data = \WC()->session->get( Plugin_Constants::STORE_CREDITS_COUPON_SESSION, null );

        // skip if store credits data is not valid.
        if ( ! $sc_data ) {
            return;
        }

        // find coupon item object for store credit.
        $coupon_item = $this->_helper_functions->get_order_applied_coupon_item_by_code( $this->get_store_credit_coupon_code(), $order );

        // skip if there was no store credit coupon found in the order.
        if ( ! $coupon_item instanceof \WC_Order_Item_Coupon ) {
            return;
        }

        $this->_maybe_reset_resumed_failed_order( $order );

        // don't proceed creating store credit entry when order status is failed/cancelled.
        if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
            $order->remove_coupon( $this->get_store_credit_coupon_code() );
            return;
        }

        $coupon_discount = (float) $coupon_item->get_discount();

        // Include the discount tax to the store credit coupon discount amount when prices include tax.
        if ( wc_prices_include_tax() && apply_filters( 'acfw_store_credits_coupon_discount_include_tax', true, $coupon_discount, $coupon_item, $sc_data ) ) {
            $coupon_discount += $coupon_item->get_discount_tax();
        }

        $sc_data['amount'] = min( (float) $sc_data['amount'], $coupon_discount );
        $amount            = apply_filters( 'acfw_filter_amount', $sc_data['amount'], true );

        // create store credit entry object.
        $store_credit_entry = $this->create_discount_store_credit_entry( $amount, $order );

        // update users cached balance value.
        $new_balance = \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id(), true );

        // save session data as coupon item metadata.
        $sc_data['raw_amount'] = $amount;
        $coupon_item->add_meta_data( Plugin_Constants::STORE_CREDITS_ORDER_COUPON_META, $sc_data );
        $coupon_item->save_meta_data();

        // clear session data.
        $this->clear_store_credit_session();

        do_action( 'acfw_after_order_with_store_credit_coupon', $amount, $new_balance, $order, $store_credit_entry );
    }

    /**
     * Checks if the store credit discount has been removed.
     *
     * @since 4.6.0
     * @access public
     *
     * @return bool The changed notice from the session.
     */
    public function is_store_credit_discount_removed() {
        // Get changed notice from session.
        $changed_notice = \WC()->session->get( Plugin_Constants::STORE_CREDITS_SESSION_CHANGED_NOTICE, null ) ?? false;

        // Reset store credits session changed notice.
        \WC()->session->set( Plugin_Constants::STORE_CREDITS_SESSION_CHANGED_NOTICE, null );

        return $changed_notice;
    }

    /**
     * Get the notice text that asks the customer to reapply the store credit discount.
     *
     * The link carries an "href" fragment, not only a class. WooCommerce sanitizes a
     * cart or checkout block notice against a fixed allow-list that keeps "href" and
     * drops "class", so a class alone gives the block checkout no hook to bind on.
     * The class stays for the classic checkout and for any custom styling.
     *
     * @since 4.7.6
     * @access public
     *
     * @return string Notice text, with the reapply link.
     */
    public function get_reapply_discount_notice_text() {
        return sprintf(
            /* translators: %1$s: opening link tag, %2$s: closing link tag. */
            __( 'The total of your order changed, please click here to %1$sreapply the store credit discount%2$s.', 'advanced-coupons-for-woocommerce-free' ),
            '<a class="' . esc_attr( self::REAPPLY_DISCOUNT_LINK_HOOK ) . '" href="#' . esc_attr( self::REAPPLY_DISCOUNT_LINK_HOOK ) . '">',
            '</a>'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Apply store credits after tax.
    |--------------------------------------------------------------------------
     */

    /**
     * Apply store credit discount on cart total calculation.
     *
     * @since 4.0
     * @since 4.5.2 Update session cart total amount when original cart total amount is changed.
     * @access public
     *
     * @param float    $cart_total Cart Total.
     * @param \WC_Cart $cart Cart object.
     * @return float Filtered cart total.
     */
    public function apply_store_credit_discount( $cart_total, $cart ) {
        // Validate the cart before applying store credits.
        if ( ! apply_filters( 'acfw_validate_cart_before_apply_store_credits', true, $cart_total, $cart ) ) {
            return $cart_total;
        }

        /**
         * NOTE: When currency converter is active, the cart total and the discount amount is based on user currency.
         *       When the currency is switched by the user, the filter allows the currency converter plugin to convert
         *       the saved discount amount from the previous currency to the new selected currency.
         */
        $sc_data = apply_filters( 'acfw_before_apply_store_credit_discount', \WC()->session->get( Plugin_Constants::STORE_CREDITS_SESSION, null ), Plugin_Constants::STORE_CREDITS_SESSION );

        /**
         * Skip when currency in session is different with currency. This means that the newly selected currency hasn't
         * propagated yet. The currency converter plugin will recalculate the cart again and would then correctly apply the discount.
         */
        if ( ! $sc_data || ! isset( $sc_data['amount'] ) || get_woocommerce_currency() !== $sc_data['currency'] ) {
            return $cart_total;
        }

        /**
         * Skip when the cart total amount is equal to the actual cart total minus the applied store credit discount.
         * This is to prevent the store credit discount from being applied twice.
         */
        if ( wc_remove_number_precision( wc_add_number_precision( $sc_data['cart_total'] ) - wc_add_number_precision( $sc_data['amount'] ) ) === $cart_total ) {
            return $cart_total;
        }

        // Remove the store credit discount when the new calculated cart total value is less then the applied discount value.
        if ( $sc_data['amount'] > $cart_total ) {
            /**
             * When the SC was applied to cover the full order total (amount == cart_total at apply time),
             * do not remove the SC. Instead, return $0 to keep the full-order SC effective.
             *
             * This handles cases where a dynamic tax plugin (e.g. TaxJar) recalculates taxes to $0
             * after the SC discount brings the cart total to $0 — triggering a second
             * calculate_totals() cycle where cart_total is only the pre-tax subtotal. Without this
             * guard, ACFW would see sc_amount > new_cart_total and incorrectly remove the SC,
             * making it impossible for customers to apply a full-cart store credit when TaxJar
             * (or a similar plugin) is active.
             */
            $is_full_order_sc = isset( $sc_data['cart_total'] ) && 0 === (int) ( wc_add_number_precision( $sc_data['cart_total'] ) - wc_add_number_precision( $sc_data['amount'] ) );

            if ( $is_full_order_sc ) {
                /**
                 * Only bypass removal when the cart subtotal + shipping hasn't changed.
                 * If only tax changed (TaxJar zeroing taxes after a $0 total), the non-tax
                 * portion stays the same and we keep the SC applied. If shipping or products
                 * actually changed, the totals will differ and we fall through to remove the SC.
                 */
                $stored_non_tax_total  = isset( $sc_data['subtotal'], $sc_data['shipping_total'] )
                    ? $sc_data['subtotal'] + $sc_data['shipping_total']
                    : null;
                $current_non_tax_total = $cart->get_subtotal() + $cart->get_shipping_total();
                $is_only_tax_changed   = null !== $stored_non_tax_total
                    && 0 === (int) ( wc_add_number_precision( $stored_non_tax_total ) - wc_add_number_precision( $current_non_tax_total ) );

                if ( $is_only_tax_changed ) {
                    return is_cart() ? $cart_total : 0.0;
                }
            }

            \WC()->session->set( Plugin_Constants::STORE_CREDITS_SESSION_CHANGED_NOTICE, true );
            \WC()->session->set( Plugin_Constants::STORE_CREDITS_SESSION, null );

            // Only trigger on Regular Checkout.
            if ( ! $this->_helper_functions->is_current_request_using_wpjson_wc_api() ) {
                wc_add_notice( $this->get_reapply_discount_notice_text(), 'error' );
            }

            return $cart_total;
        }

        // update session cart total value when the cart total has been changed.
        if ( $sc_data['cart_total'] !== $cart_total ) {
            $sc_data['cart_total'] = $cart_total;
            \WC()->session->set( Plugin_Constants::STORE_CREDITS_SESSION, $sc_data );
        }

        // return the original cart total when viewing it in the cart page.
        return is_cart() ? $cart_total : $cart_total - $sc_data['amount'];
    }

    /**
     * Get the store credit discount that is currently deducted from the cart total.
     *
     * Payment gateways that build their own amount from the cart breakdown (item total, shipping,
     * tax, discount total) never see the "after tax and shipping" store credit discount, because
     * that discount is applied by overwriting the cart total instead of by a coupon or a fee. This
     * accessor gives those gateways the missing amount.
     *
     * There is no check on the "apply type" setting. The "before tax and shipping" mode applies the
     * credit as a real coupon, which lands in the cart discount total and so is already visible to
     * the gateway. That mode also stores its credit under a different session key, so the gate
     * below returns 0.0 for it. Reading the deduction itself, instead of the setting, keeps this
     * accessor correct even while a stale session from the other mode is still being cleared.
     *
     * The value is MEASURED, not read from the session. The session amount is only a gate that
     * proves an after tax store credit is in play. Two reasons:
     *
     * - `apply_store_credit_discount()` does not always deduct `$sc_data['amount']`. On a full
     *   order credit it deducts the whole cart total, and when the credit no longer fits it
     *   deducts nothing.
     * - The cart breakdown sum can differ from the cart total by one minor unit because of per
     *   item tax rounding. Measuring the difference cancels that drift, so the gateway total
     *   matches the total the customer sees, to the cent.
     *
     * @since 4.7.6
     * @access public
     *
     * @param \WC_Cart|null $cart Cart object. Defaults to the current cart.
     * @return float Applied store credit discount, in the active currency. 0.0 when none applies.
     */
    public function get_applied_store_credit_discount( $cart = null ) {
        if ( ! $cart instanceof \WC_Cart ) {
            $cart = \WC()->cart;
        }

        if ( ! $cart instanceof \WC_Cart || ! \WC()->session ) {
            return 0.0;
        }

        // The discount is deliberately not applied to the cart page total, so there is nothing to report there.
        if ( is_cart() ) {
            return 0.0;
        }

        $sc_data = \WC()->session->get( Plugin_Constants::STORE_CREDITS_SESSION, null );

        if ( ! is_array( $sc_data ) || ! isset( $sc_data['amount'], $sc_data['currency'] ) ) {
            return 0.0;
        }

        // Skip while a currency switch is still propagating. The discount is not applied yet either.
        if ( get_woocommerce_currency() !== $sc_data['currency'] || 0 >= (float) $sc_data['amount'] ) {
            return 0.0;
        }

        // Sum the cart the way a gateway builds its amount breakdown, then measure what the store
        // credit deduction took off it.
        $breakdown_total = (float) $cart->get_subtotal()
            + (float) $cart->get_fee_total()
            + (float) $cart->get_shipping_total()
            + (float) $cart->get_total_tax()
            - (float) $cart->get_discount_total();

        $applied = $breakdown_total - max( 0.0, (float) $cart->get_total( 'edit' ) );

        return $applied > 0 ? $applied : 0.0;
    }

    /**
     * Re-apply the SC deduction to the cart total when a dynamic tax plugin (e.g. TaxJar)
     * overwrites WC()->cart->total in woocommerce_after_calculate_totals, which runs AFTER
     * our woocommerce_calculated_total filter has already subtracted the SC amount.
     *
     * This only restores the display total on the cart object — it must NOT mutate the SC
     * session data, because downstream code (deduct_store_credits_discount_from_balance and
     * the admin order UI) relies on $sc_data['cart_total'] remaining the snapshot taken at
     * SC-apply time.
     *
     * @since 4.7.3
     * @access public
     *
     * @param \WC_Cart $cart Cart object.
     */
    public function enforce_store_credit_after_tax_recalculation( $cart ) {
        // Scope strictly to cart/checkout flows (frontend, AJAX, REST/Block checkout). A plain
        // wp-admin page render that incidentally triggers cart recalculation should not have
        // its cart total silently adjusted by this hook.
        if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        // Keep the pre-SC total visible on the cart page (mirrors apply_store_credit_discount).
        if ( is_cart() ) {
            return;
        }

        $sc_data = \WC()->session->get( Plugin_Constants::STORE_CREDITS_SESSION, null );
        if ( ! $sc_data || ! isset( $sc_data['amount'], $sc_data['cart_total'], $sc_data['subtotal'], $sc_data['shipping_total'], $sc_data['currency'] ) ) {
            return;
        }

        if ( get_woocommerce_currency() !== $sc_data['currency'] ) {
            return;
        }

        $sc_amount          = (float) $sc_data['amount'];
        $session_cart_total = (float) $sc_data['cart_total'];
        $cart_total         = (float) $cart->total;

        if ( $sc_amount <= 0 ) {
            return;
        }

        // Only act when the TaxJar signature is clear: products and shipping unchanged.
        // If non-tax components differ, something else changed and apply_store_credit_discount
        // will have already handled (or removed) the SC via its own logic.
        $stored_non_tax  = (float) $sc_data['subtotal'] + (float) $sc_data['shipping_total'];
        $current_non_tax = (float) $cart->get_subtotal() + (float) $cart->get_shipping_total();
        if ( 0 !== (int) ( wc_add_number_precision( $stored_non_tax ) - wc_add_number_precision( $current_non_tax ) ) ) {
            return;
        }

        // If cart->total is already within a cent of the SC-applied target (session cart_total - sc_amount),
        // our deduction is intact. Skip to avoid double-application.
        $expected_applied_precision = wc_add_number_precision( $session_cart_total ) - wc_add_number_precision( $sc_amount );
        if ( abs( wc_add_number_precision( $cart_total ) - $expected_applied_precision ) < 1 ) {
            return;
        }

        // Otherwise, cart->total has been overwritten by a late recalculation (e.g. TaxJar).
        // Re-apply SC against the current cart->total (which now includes the corrected tax).
        $new_total_precision = wc_add_number_precision( $cart_total ) - wc_add_number_precision( $sc_amount );
        $new_total           = (float) wc_remove_number_precision( max( 0, $new_total_precision ) );

        $cart->set_total( $new_total );
    }

    /**
     * Deduct store credits discount from user's balance when order is processed.
     *
     * @since 4.0
     * @since 4.2 Add hook to trigger actions based on user's new balance after an order was paid with store credits.
     * @since 4.7.7 Reset a resumed failed order to pending so the store credit is deducted again.
     * @access public
     *
     * @param int       $order_id    Order ID.
     * @param array     $posted_data Posted data from checkout form.
     * @param \WC_Order $order       Order object.
     */
    public function deduct_store_credits_discount_from_balance( $order_id, $posted_data, $order ) {
        $sc_data = \WC()->session->get( Plugin_Constants::STORE_CREDITS_SESSION, null );

        // don't proceed when store credit session data is not available.
        if ( ! $sc_data ) {
            return;
        }

        $this->_maybe_reset_resumed_failed_order( $order );

        // don't proceed when the order status is failed/cancelled.
        if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
            return;
        }

        /**
         * Save the discount amount the user/order based currency so we don't need to convert them on the backend.
         *
         * Note: for Block checkout this meta was already written earlier by
         * `save_store_credits_order_meta_via_store_api` on `woocommerce_store_api_checkout_update_order_meta`
         * (needed there so payment-method validation sees the post-SC order total). Overwriting it here
         * is intentional — it keeps this function authoritative for classic checkout (which has no
         * earlier hook) and is a no-op for Block since the session values are the same. The
         * re-read after `calculate_totals(true)` below still picks up any upward adjustment done by
         * `deduct_store_credits_discount_from_order_total` regardless of which path got us here.
         */
        $meta_data = array(
            'amount'     => $sc_data['amount'], // user currency based amount.
            'raw_amount' => apply_filters( 'acfw_filter_amount', $sc_data['amount'], true ), // site currency based amount.
            'cart_total' => $sc_data['cart_total'],
            'currency'   => $order->get_currency(),
        );

        // save session data as order meta.
        $order->update_meta_data( Plugin_Constants::STORE_CREDITS_ORDER_PAID, $meta_data );
        $order->save_meta_data();

        // recalculate order totals so the store credit payment is deducted from the order total.
        $order->calculate_totals( true );

        // Re-read meta in case deduct_store_credits_discount_from_order_total() adjusted the SC amount
        // (e.g. when a dynamic tax plugin recalculates taxes at the order level, increasing the order total).
        $updated_meta = $order->get_meta( Plugin_Constants::STORE_CREDITS_ORDER_PAID, true );
        $amount       = floatval( is_array( $updated_meta ) && isset( $updated_meta['raw_amount'] ) ? $updated_meta['raw_amount'] : $meta_data['raw_amount'] );

        // create store credit entry object.
        $store_credit_entry = $this->create_discount_store_credit_entry( $amount, $order );

        // update users cached balance value.
        $new_balance = \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id(), true );

        if ( is_object( \WC()->session ) ) {

            // clear session data.
            $this->clear_store_credit_session();

            // set order ID on session so we can recalculate the order totals when the page is reloaded.
            \WC()->session->set( 'acfw_calculate_order_totals', $order->get_id() );
        }

        do_action( 'acfw_after_order_paid_with_store_credits', $amount, $new_balance, $order, $store_credit_entry );
    }

    /**
     * Deduct store credits discount from user's balance when order is processed via Store API.
     * - This function is required because checkout block uses different hooks to process the order.
     * - Learn more about the change here : https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/third-party-developers/extensibility/hooks/actions.md#woocommerce_store_api_checkout_order_processed
     *
     * @since 4.6.0
     * @access public
     *
     * @param \WC_Order $order       Order object.
     */
    public function deduct_store_credits_discount_from_balance_via_store_api( $order ) {
        // store credit as coupon support (beforet tax).
        $this->process_order_with_store_credit_coupon( $order->get_id(), array(), $order );

        // store credit after tax support.
        $this->deduct_store_credits_discount_from_balance( $order->get_id(), array(), $order );
    }

    /**
     * Save store credit meta and recalculate order total during the Block Store API draft-order
     * creation phase, before payment-method validation runs.
     *
     * `woocommerce_store_api_checkout_update_order_meta` fires inside `create_or_update_draft_order`
     * (WC Blocks `StoreApi/Routes/V1/Checkout.php:704`) — BEFORE `update_order_from_request` calls
     * `get_request_payment_method()` (line 463 → CheckoutTrait.php:145) which throws
     * "No payment method provided" when `$order->needs_payment()` is true.
     *
     * By persisting the STORE_CREDITS_ORDER_PAID meta early and triggering `$order->calculate_totals()`,
     * the order-level deduction at `deduct_store_credits_discount_from_order_total`
     * (`woocommerce_order_after_calculate_totals` priority 100) runs and brings the order total to its
     * real post-SC value. `$order->needs_payment()` then correctly defaults to false for full-SC carts
     * (0 < 0 is false) without needing a session-based filter that isn't reliable inside the REST flow.
     *
     * For partial SC, the order total becomes the non-zero post-SC amount, payment validation
     * proceeds normally, and `deduct_store_credits_discount_from_balance_via_store_api` still runs
     * later to deduct from the customer balance and clear session state.
     *
     * @since 4.7.3
     * @access public
     *
     * @param \WC_Order $order Order object.
     */
    public function save_store_credits_order_meta_via_store_api( $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $sc_data = \WC()->session ? \WC()->session->get( Plugin_Constants::STORE_CREDITS_SESSION, null ) : null;
        if ( ! $sc_data || ! isset( $sc_data['amount'], $sc_data['cart_total'] ) ) {
            return;
        }

        if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
            return;
        }

        // Skip if meta already saved (e.g. Store API called multiple times during the same request).
        $existing = $order->get_meta( Plugin_Constants::STORE_CREDITS_ORDER_PAID, true );
        if ( is_array( $existing ) && ! empty( $existing ) ) {
            return;
        }

        $meta_data = array(
            'amount'     => $sc_data['amount'],
            'raw_amount' => apply_filters( 'acfw_filter_amount', $sc_data['amount'], true ),
            'cart_total' => $sc_data['cart_total'],
            'currency'   => $order->get_currency(),
        );

        $order->update_meta_data( Plugin_Constants::STORE_CREDITS_ORDER_PAID, $meta_data );
        $order->save_meta_data();

        // Recalculate so `deduct_store_credits_discount_from_order_total` can bring the order
        // total down to its real post-SC value before payment validation runs.
        $order->calculate_totals( true );
    }

    /**
     * Recalculate the order totals for an order that was paid via Store Credits after the checkout process.
     *
     * @since 4.2.1
     * @access public
     */
    public function recalculate_order_totals_after_checkout_complete() {
        // skip when session object is not available, or when currently viewing the checkout payment page.
        if ( ! \WC()->session || is_checkout_pay_page() ) {
            return;
        }

        $order_id = \WC()->session->get( 'acfw_calculate_order_totals', null );
        $order    = $order_id ? \wc_get_order( $order_id ) : null;

        if ( $order instanceof \WC_Order ) {
            $order->calculate_totals( true );
        }

        \WC()->session->set( 'acfw_calculate_order_totals', null );
    }

    /*
    |--------------------------------------------------------------------------
    | Order review: Order received, email and frontend order view.
    |--------------------------------------------------------------------------
     */

    /**
     * Display store credits discount total in order review page.
     *
     * @since 4.0
     * @since 4.2.1 We're moving the Store Credit order implementation from being applied as a "discount" to applying it
     *              as a payment instead. We will still be keeping this function for backwards compatibility for old orders
     *              that has store credits discounts in them.
     *
     * @access public
     *
     * @param array    $total_rows Order review total rows.
     * @param WC_Order $order     Order object.
     * @return array Filtered order review total rows.
     */
    public function display_order_review_store_credits_discount_total( $total_rows, $order ) {
        $sc_data = $order->get_meta( Plugin_Constants::STORE_CREDITS_ORDER_META, true );

        if ( ! is_array( $sc_data ) || empty( $sc_data ) ) {
            return $total_rows;
        }

        $filtered_rows = array();

        foreach ( $total_rows as $key => $row ) {
            if ( 'order_total' === $key ) {
                $filtered_rows['acfw_store_credits_discount'] = array(
                    'label' => __( 'Discount (Store Credit)', 'advanced-coupons-for-woocommerce-free' ),
                    'value' => wc_price(
                        $sc_data['amount'] * -1,
                        array( 'currency' => $order->get_currency() )
                    ),
                );
            }

            $filtered_rows[ $key ] = $row;
        }

        return $filtered_rows;
    }

    /**
     * Display store credits discount total in order review page.
     *
     * @since 4.2.1
     * @since 4.5.1.1 Display paid with store credits row as a discount (before total).
     * @access public
     *
     * @param array    $total_rows Order review total rows.
     * @param WC_Order $order     Order object.
     * @return array Filtered order review total rows.
     */
    public function display_order_review_paid_in_store_credits( $total_rows, $order ) {
        $sc_data    = $order->get_meta( Plugin_Constants::STORE_CREDITS_ORDER_PAID, true );
        $sc_version = $order->get_meta( Plugin_Constants::STORE_CREDITS_VERSION, true );

        if ( ! is_array( $sc_data ) || empty( $sc_data ) ) {
            return $total_rows;
        }

        $filtered_rows = array();

        if ( $sc_version ) {

            foreach ( $total_rows as $key => $row ) {

                if ( 'discount' === $key ) {
                    $discount_total = wc_remove_number_precision( wc_add_number_precision( $order->get_discount_total() ) - wc_add_number_precision( $sc_data['amount'] ) );

                    if ( $discount_total <= 0 ) {
                        continue;
                    }

                    $row['value'] = '-' . wc_price( $discount_total );
                }

                if ( 'order_total' === $key ) {
                    $filtered_rows['acfw_store_credits_discount'] = array(
                        'label' => __( 'Paid with Store Credits', 'advanced-coupons-for-woocommerce-free' ) . ':',
                        'value' => '-' . wc_price(
                            $sc_data['amount'],
                            array( 'currency' => $order->get_currency() )
                        ),
                    );
                }

                $filtered_rows[ $key ] = $row;
            }
        } else {
            $filtered_rows = $this->_old_display_order_review_paid_in_store_credits( $total_rows, $order, $sc_data );
        }

        return $filtered_rows;
    }

    /**
     * Old way of displaying store credits discount total in order review page.
     *
     * NOTE: this is the old way of displaying the store credits payment line in the order review table,
     *       where the store credit payment amount was not yet deducted as a discount in the order.
     *
     * @since 4.5.1.1
     * @access private
     *
     * @param array    $total_rows Order review total rows.
     * @param WC_Order $order      Order object.
     * @param array    $sc_data    Store credits payment data for order.
     * @return array Filtered order review total rows.
     */
    private function _old_display_order_review_paid_in_store_credits( $total_rows, $order, $sc_data ) {
        $filtered_rows = array();
        foreach ( $total_rows as $key => $row ) {
            $filtered_rows[ $key ] = $row;

            if ( 'order_total' === $key ) {
                $filtered_rows['acfw_store_credits_discount'] = array(
                    'label' => __( 'Paid with Store Credits', 'advanced-coupons-for-woocommerce-free' ) . ':',
                    'value' => wc_price(
                        $sc_data['amount'],
                        array( 'currency' => $order->get_currency() )
                    ),
                );

                if ( ! in_array( $order->get_status(), array( 'processing', 'completed', 'refunded' ), true ) && empty( $order->get_date_paid() ) ) {
                    $filtered_rows['acfw_order_pending_amount'] = array(
                        'label' => __( 'Pending Amount', 'advanced-coupons-for-woocommerce-free' ) . ':',
                        'value' => wc_price(
                            $order->get_total() - $sc_data['amount'],
                            array( 'currency' => $order->get_currency() )
                        ),
                    );
                }
            }
        }

        return $filtered_rows;
    }

    /**
     * Update order refund item label when it was refunded via store credits.
     *
     * @since 4.5.1
     * @access public
     *
     * @param array    $total_rows Order review total rows.
     * @param WC_Order $order     Order object.
     * @return array Filtered order review total rows.
     */
    public function update_order_refunded_as_store_credits_labels( $total_rows, $order ) {

        // Get parent order if this is a refund.
        // This is needed because the $order object passed to this filter is the refund order.
        // We need the parent order to get the refund order object.
        if ( $order->get_parent_id() ) {
            $order = wc_get_order( $order->get_parent_id() );
        }

        $refunds = $order->get_refunds();

        foreach ( $total_rows as $key => $total_row ) {

            // skip if the row is not for refunds.
            if ( ! str_contains( $key, 'refund_' ) ) {
                continue;
            }

            $refund_index = intval( str_replace( 'refund_', '', $key ) );
            $refund       = $refunds[ $refund_index ] ?? null;

            // Update refund row label when it was refunded via store credits and that the refund reason was not provided.
            if ( $refund && $refund->get_meta( Plugin_Constants::REFUND_ORDER_STORE_CREDIT_ENTRY, true ) && ! $refund->get_reason() ) {
                $total_rows[ $key ]['label'] = __( 'Refund (Store Credits):', 'advanced-coupons-for-woocommerce-free' );
            }
        }

        return $total_rows;
    }

    /**
     * Deduct store credits from the order total (discount behaviour).
     *
     * @since 4.5.1.1
     * @access public
     *
     * @param bool      $and_taxes Calc taxes if true.
     * @param \WC_Order $order Order object.
     */
    public function deduct_store_credits_discount_from_order_total( $and_taxes, $order ) {

        $sc_data = $order->get_meta( Plugin_Constants::STORE_CREDITS_ORDER_PAID, true );

        // skip if order has no store credits data.
        if ( ! is_array( $sc_data ) || empty( $sc_data ) ) {
            return;
        }

        // apply the changes made by WC during the calculation so we can re-use those data.
        $order->apply_changes();

        $sc_amount   = (float) $sc_data['amount'];
        $order_total = (float) $order->get_total( 'edit' );

        /**
         * When the SC was a full-order cover and the order total increased after recalculation
         * (e.g. a dynamic tax plugin like TaxJar recalculated taxes at the order level),
         * adjust the SC amount upward to cover the new total, capped at the customer's balance.
         * Without a dynamic tax plugin, order-level tax matches cart-level tax exactly,
         * so this adjustment never triggers.
         */
        $is_full_order_sc = isset( $sc_data['cart_total'] )
            && 0 === (int) ( wc_add_number_precision( $sc_data['cart_total'] ) - wc_add_number_precision( $sc_data['amount'] ) );

        if ( $is_full_order_sc && $order_total > $sc_amount ) {
            $customer_id = $order->get_customer_id();
            $balance     = (float) apply_filters( 'acfw_filter_amount', \ACFWF()->Store_Credits_Calculate->get_customer_balance( $customer_id ) );
            $sc_amount   = min( $order_total, $balance );

            $sc_data['amount']     = $sc_amount;
            $sc_data['raw_amount'] = apply_filters( 'acfw_filter_amount', $sc_amount, true );
            $order->update_meta_data( Plugin_Constants::STORE_CREDITS_ORDER_PAID, $sc_data );

            // When the customer's available balance can't fully absorb the upward tax shift,
            // the order ends up with a residual unpaid amount even though the customer placed it
            // from a $0 cart. Surface this via an order note so admins can follow up — the
            // payment-method UI was hidden client-side, so the customer can't pay the gap from
            // the receipt page. Guarded by a meta flag because this hook can fire on subsequent
            // recalculations and we only want to log once per order.
            $residual_precision = wc_add_number_precision( $order_total ) - wc_add_number_precision( $sc_amount );

            if ( $residual_precision > 0 && ! $order->get_meta( '_acfw_sc_residual_noted', true ) ) {
                $residual = (float) wc_remove_number_precision( $residual_precision );

                $order->add_order_note(
                    sprintf(
                        /* Translators: 1: residual unpaid amount, 2: customer's available store credit balance, 3: order total after tax recalculation */
                        __( 'Store credit balance (%2$s) is insufficient to fully cover the order total (%3$s) after tax recalculation. Residual unpaid amount: %1$s — please collect via another payment method or top up the customer\'s store credit balance.', 'advanced-coupons-for-woocommerce-free' ),
                        wc_price( $residual, array( 'currency' => $order->get_currency() ) ),
                        wc_price( $balance, array( 'currency' => $order->get_currency() ) ),
                        wc_price( $order_total, array( 'currency' => $order->get_currency() ) )
                    )
                );
                $order->update_meta_data( '_acfw_sc_residual_noted', '1' );
            }
        }

        $precision_discount = wc_add_number_precision( $order->get_discount_total( 'edit' ) ) + wc_add_number_precision( $sc_amount );
        $precision_total    = wc_add_number_precision( $order_total ) - wc_add_number_precision( $sc_amount );

        $order->set_discount_total( wc_remove_number_precision( $precision_discount ) );
        $order->set_total( wc_remove_number_precision( $precision_total ) );

        // save plugin version of store credits when order is recalculated.
        $order->add_meta_data( Plugin_Constants::STORE_CREDITS_VERSION, Plugin_Constants::VERSION, true );
        $order->save_meta_data();
    }

    /**
     * Re-add the customer's store credits when the order status has been changed to failed or cancelled.
     *
     * @since 4.5.2
     * @since 4.7.7 Return the store credit coupon amount of the current attempt only.
     * @access public
     *
     * @param int       $order_id    Order ID.
     * @param string    $prev_status Previous status.
     * @param string    $new_status  New Status.
     * @param \WC_Order $order       Order object.
     */
    public function readd_customer_store_credits_for_failed_orders( $order_id, $prev_status, $new_status, $order ) {

        if ( ! in_array( $new_status, array( 'failed', 'cancelled' ), true ) ) {
            return;
        }

        $sc_paid     = $order->get_meta( Plugin_Constants::STORE_CREDITS_ORDER_PAID, true );
        $coupon_item = $this->_helper_functions->get_order_applied_coupon_item_by_code( $this->get_store_credit_coupon_code(), $order );
        $is_updated  = false;

        if ( is_array( $sc_paid ) && ! empty( $sc_paid ) ) {
            $order->delete_meta_data( Plugin_Constants::STORE_CREDITS_ORDER_PAID );
            $is_updated = true;
        }

        if ( $coupon_item instanceof \WC_Order_Item_Coupon ) {
            $this->_coupon_override_data = $coupon_item->get_meta( Plugin_Constants::STORE_CREDITS_ORDER_COUPON_META, true );

            // use the store credit coupon data for the returned amount, so a resumed order does not return the credit of earlier attempts again.
            if ( ! is_array( $sc_paid ) || empty( $sc_paid ) ) {
                $sc_paid = $this->_coupon_override_data;
            }

            $order->remove_coupon( $this->get_store_credit_coupon_code() );
            $is_updated = true;
        }

        // skip if nothing was updated.
        if ( ! $is_updated ) {
            return;
        }

        // save order and metadata.
        $order->save();
        $order->save_meta_data();
        $order->calculate_totals( true );

        // create order cancelled store credit entry.
        $this->_create_order_cancelled_store_credit_entry( $order, $sc_paid );

        // update users cached balance value.
        \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id(), true );
    }

    /**
     * Allow order to be placed when the order total is zero due to store credits payment (after tax store credits type).
     *
     * @since 4.5.9.1
     * @since 4.7.7 Compare the store credit amount against the session cart total instead of the order total, for the current cart order only.
     * @access public
     *
     * @param bool      $value True if allowed, false otherwise.
     * @param \WC_Order $order Order object.
     * @return bool Filtered value.
     */
    public function allow_placing_order_on_zero_total_with_store_credits( $value, $order ) {

        // Only change the value for the order created from the current cart, and not on the order pay page.
        if ( is_checkout_pay_page() || ! \WC()->cart || ! $order->has_cart_hash( \WC()->cart->get_cart_hash() ) ) {
            return $value;
        }

        /**
         * Set the 'needs_payment' filter as `false` only when the store credits fully cover the cart total.
         * Compare against the cart total saved in the session (before the store credit discount), because the
         * order total can already have the store credit deducted (e.g. a resumed order).
         */
        $sc_data = \WC()->session ? \WC()->session->get( Plugin_Constants::STORE_CREDITS_SESSION, null ) : null;
        if (
            $sc_data
            && isset( $sc_data['amount'], $sc_data['cart_total'] )
            && 0 >= wc_add_number_precision( (float) $sc_data['cart_total'] ) - wc_add_number_precision( (float) $sc_data['amount'] )
        ) {
            return false;
        }

        return $value;
    }

    /**
     * Mark the cart as not needing payment when store credits fully cover it.
     *
     * The Block checkout reads `needs_payment` from the Store API cart endpoint (derived from
     * `WC_Cart::needs_payment()` which applies this filter). Without this override a cart whose
     * total has been zeroed by `apply_store_credit_discount` / `enforce_store_credit_after_tax_recalculation`
     * still has its payment-method UI hidden (total is $0) while the checkout POST continues to
     * require a payment method — leaving Block checkout stuck on "No payment method provided".
     *
     * @since 4.7.3
     * @access public
     *
     * @param bool     $needs_payment True if the cart needs payment.
     * @param \WC_Cart $cart          Cart object.
     * @return bool Filtered value.
     */
    public function allow_cart_zero_total_with_store_credits( $needs_payment, $cart ) {
        if ( ! $needs_payment ) {
            return $needs_payment;
        }

        $sc_data = \WC()->session ? \WC()->session->get( Plugin_Constants::STORE_CREDITS_SESSION, null ) : null;
        if (
            $sc_data
            && isset( $sc_data['amount'] )
            && (float) $sc_data['amount'] > 0
            && wc_add_number_precision( (float) $cart->total ) <= 0
        ) {
            // Precision-int comparison respects the store's currency decimals (e.g. 2 for USD,
            // 3 for BHD/JOD/KWD) — a cart total at or below the smallest currency unit counts as zero.
            return false; // Full SC covers the cart — no payment needed.
        }

        return $needs_payment;
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX Functions
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX redeem store credits.
     *
     * @since 4.0
     * @access public
     */
    public function ajax_redeem_store_credits() {
        $nonce       = isset( $_POST['wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpnonce'] ) ) : '';
        $message     = ''; // Store notice message.
        $notice_type = 'success'; // Store notice type.

        // Validate request and prepare response.
        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Invalid AJAX call', 'advanced-coupons-for-woocommerce-free' ),
            );
        } elseif ( ! is_user_logged_in() ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'You must be logged in to redeem store credits.', 'advanced-coupons-for-woocommerce-free' ),
            );
        } elseif (
            ! isset( $_POST['wpnonce'] ) ||
            ! wp_verify_nonce( $nonce, 'acfwf_redeem_store_credits_checkout' )
        ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'You are not allowed to do this.', 'advanced-coupons-for-woocommerce-free' ),
            );
        } elseif ( ! isset( $_POST['amount'] ) ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Missing required post data', 'advanced-coupons-for-woocommerce-free' ),
            );
        } else {
            $amount = floatval( $_POST['amount'] );
            $check  = $this->redeem_store_credits( get_current_user_id(), $amount );

            if ( is_wp_error( $check ) ) {
                $response = array(
                    'status'    => 'fail',
                    'error_msg' => $check->get_error_message(),
                );
            } else {
                $response = array( 'status' => 'success' );
                $message  = $amount > 0 ?
                    __( 'Store credit discount was applied successfully.', 'advanced-coupons-for-woocommerce-free' ) :
                    __( 'Store credit discount has been removed.', 'advanced-coupons-for-woocommerce-free' );
            }
        }

        // Change notice to error, if there's any.
        if ( 'fail' === $response['status'] ) {
            $message     = $response['error_msg'];
            $notice_type = 'error';
        }

        /**
         * Display store notice message, only if it exists and on regular cart and checkout page only.
         * - We validate is_cart_checkout_block via POST due to `global $post;` is not available in AJAX environment.
         */
        $is_cart_checkout_block = isset( $_POST['is_cart_checkout_block'] ) ? sanitize_text_field( wp_unslash( $_POST['is_cart_checkout_block'] ) ) : false;
        if ( ! empty( $message ) && ! $is_cart_checkout_block ) {
            wc_add_notice( $message, $notice_type );
        }

        // Send response.
        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
        $response['message'] = $message;
        echo wp_json_encode( $response );
        wp_die();
    }

    /*
    |--------------------------------------------------------------------------
    | Utility Functions
    |--------------------------------------------------------------------------
     */

    /**
     * Set the coupon override data.
     *
     * @since 4.5.4
     * @access public
     *
     * @param array $data Coupon override data.
     */
    public function set_coupon_override_data( $data ) {
        $this->_coupon_override_data = wp_parse_args(
            $data,
            array(
                'amount'     => 0,
                'cart_total' => 'fixed_cart',
                'currency'   => \get_woocommerce_currency(),
            )
        );
    }

    /**
     * Check if store credits is allowed on checkout.
     *
     * @since 4.0
     * @since 4.5.7 Changed to public access.
     * @access public
     *
     * @return bool True if allowed, false otherwise.
     */
    public function is_allow_store_credits() {
        $is_allowed = false;

        // Don't allow store credits when user is not logged in.
        if ( ! is_user_logged_in() ) {
            return $is_allowed;
        }

        // Don't allow store credits when "Hide Store Credits when balance is zero" is enabled and the user has no balance.
        if ( 'yes' === get_option( Plugin_Constants::STORE_CREDITS_HIDE_CHECKOUT_ZERO_BALANCE, 'no' ) &&
            \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id() ) <= 0 ) {
            return $is_allowed;
        }

        // Don't allow store credit if \WC()->cart is not an instance of \WC_Cart class.
        if ( ! \WC()->cart instanceof \WC_Cart ) {
            return $is_allowed;
        }

        // Check if advanced gift card product is present in cart.
        $agc_product_exist = false;
        foreach ( \WC()->cart->get_cart_contents() as $cart_item ) {
            if ( isset( $cart_item['agcfw_data'] ) ) {
                $agc_product_exist = true;
                break;
            }
        }

        // disallow store credits when advanced gift card product is present in cart.
        $is_allowed = $agc_product_exist ? false : true;

        return apply_filters( 'acfw_is_allow_store_credits', $is_allowed );
    }

    /**
     * Create discount store credit entry.
     *
     * @since 4.5.2
     * @access public
     *
     * @param float     $amount Store credits amount.
     * @param \WC_Order $order Order object.
     * @return Store_Credit_Entry Store credit entry object.
     */
    public function create_discount_store_credit_entry( float $amount, \WC_Order $order ) {
        $store_credit_entry = new Store_Credit_Entry();

        $store_credit_entry->set_prop( 'amount', $amount );
        $store_credit_entry->set_prop( 'user_id', $order->get_customer_id() );
        $store_credit_entry->set_prop( 'object_id', $order->get_id() );
        $store_credit_entry->set_prop( 'type', 'decrease' );
        $store_credit_entry->set_prop( 'action', 'discount' );

        // save store credit entry to db.
        $store_credit_entry->save();

        return $store_credit_entry;
    }

    /**
     * Create an order cancelled store credit entry.
     *
     * @since 4.5.2
     * @since 4.7.3 Use raw_amount from order meta instead of summing all ledger entries to prevent over-refund after apply/remove cycles.
     * @access private
     *
     * @param \WC_Order   $order   Order object.
     * @param array|false $sc_paid Store credits order paid meta data.
     * @return Store_Credit_Entry Store credit entry object.
     */
    private function _create_order_cancelled_store_credit_entry( \WC_Order $order, $sc_paid = false ) {
        $store_credit_entry = new Store_Credit_Entry();

        // Use the raw_amount from order meta (current/last applied amount) to avoid over-counting
        // from multiple apply/remove cycles in the ledger.
        if ( is_array( $sc_paid ) && isset( $sc_paid['raw_amount'] ) ) {
            $amount = floatval( $sc_paid['raw_amount'] );
        } else {
            $amount = \ACFWF()->Store_Credits_Calculate->get_total_store_credits_discount_for_order( $order->get_id() );
        }

        $store_credit_entry->set_prop( 'amount', $amount );
        $store_credit_entry->set_prop( 'user_id', $order->get_customer_id() );
        $store_credit_entry->set_prop( 'object_id', $order->get_id() );
        $store_credit_entry->set_prop( 'type', 'increase' );
        $store_credit_entry->set_prop( 'action', 'cancelled_order' );

        // save store credit entry to db.
        $store_credit_entry->save();

        return $store_credit_entry;
    }

    /**
     * Reset a resumed failed order back to pending payment.
     *
     * Classic checkout resumes a failed order without changing its status. The store credit handlers skip failed
     * orders, so the store credit would not be deducted again after a declined payment. A pending status lets the
     * store credit be deducted, and lets a next declined payment return it via the order status changed hook.
     *
     * @since 4.7.7
     * @access private
     *
     * @param \WC_Order $order Order object.
     */
    private function _maybe_reset_resumed_failed_order( \WC_Order $order ) {
        // Only reset an order that classic checkout resumed in this request.
        if ( ! did_action( 'woocommerce_resume_order' ) || ! $order->has_status( 'failed' ) ) {
            return;
        }

        $order->update_status(
            'pending',
            __( 'Order resumed after a failed payment. Status reset to pending payment to apply the store credit again.', 'advanced-coupons-for-woocommerce-free' )
        );
    }

    /**
     * Get store credit session name.
     *
     * @since 4.6.0
     *
     * @return string Store credit session name.
     */
    public function get_store_credit_session_name() {
        $is_apply_coupon = 'coupon' === get_option( Plugin_Constants::STORE_CREDIT_APPLY_TYPE, 'coupon' );
        return $is_apply_coupon ? Plugin_Constants::STORE_CREDITS_COUPON_SESSION : Plugin_Constants::STORE_CREDITS_SESSION;
    }

    /**
     * Clear store credit session data.
     *
     * @since 4.5.2
     * @access public
     */
    public function clear_store_credit_session() {
        \WC()->session->set( Plugin_Constants::STORE_CREDITS_SESSION, null );
        \WC()->session->set( Plugin_Constants::STORE_CREDITS_COUPON_SESSION, null );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 4.0
     * @access public
     * @implements ACFWF\Interfaces\Initializable_Interface
     */
    public function initialize() {
        if ( ! $this->_helper_functions->is_module( Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            return;
        }

        add_action( 'wp_ajax_acfwf_redeem_store_credits', array( $this, 'ajax_redeem_store_credits' ) );
    }

    /**
     * Execute Store_Credits class.
     *
     * @since 4.0
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! $this->_helper_functions->is_module( Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            return;
        }

        // store credit as coupon (before tax).
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_store_credits_as_coupon' ) );
        add_action( 'woocommerce_check_cart_items', array( $this, 'remove_stale_store_credit_coupon' ), 0 );
        add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'override_store_credit_coupon_data' ), 10, 3 );
        add_action( 'woocommerce_removed_coupon', array( $this, 'store_credit_coupon_discount_removed' ) );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'process_order_with_store_credit_coupon' ), 10, 3 );
        add_action( 'woocommerce_cart_totals_coupon_label', array( $this, 'apply_store_credit_discount_coupon_label' ), 10, 2 );

        // store credit after tax.
        add_filter( 'woocommerce_calculated_total', array( $this, 'apply_store_credit_discount' ), 1001, 2 );
        add_action( 'woocommerce_after_calculate_totals', array( $this, 'enforce_store_credit_after_tax_recalculation' ), 9999, 1 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'deduct_store_credits_discount_from_balance' ), 10, 3 );
        add_filter( 'woocommerce_get_order_item_totals', array( $this, 'display_order_review_store_credits_discount_total' ), 10, 2 );
        add_filter( 'woocommerce_get_order_item_totals', array( $this, 'display_order_review_paid_in_store_credits' ), 10, 2 );
        add_filter( 'woocommerce_get_order_item_totals', array( $this, 'update_order_refunded_as_store_credits_labels' ), 10, 2 );
        add_action( 'woocommerce_review_order_before_order_total', array( $this, 'display_store_credits_discount_row' ) );
        add_filter( 'woocommerce_order_needs_payment', array( $this, 'allow_placing_order_on_zero_total_with_store_credits' ), 10, 2 );
        add_filter( 'woocommerce_cart_needs_payment', array( $this, 'allow_cart_zero_total_with_store_credits' ), 10, 2 );
        add_action( 'woocommerce_after_register_post_type', array( $this, 'recalculate_order_totals_after_checkout_complete' ) );
        add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'deduct_store_credits_discount_from_order_total' ), 100, 2 );

        // Misc tasks.
        add_action( 'woocommerce_after_register_post_type', array( $this, 'recalculate_order_totals_after_checkout_complete' ) );
        add_action( 'woocommerce_order_status_changed', array( $this, 'readd_customer_store_credits_for_failed_orders' ), 10, 4 );

        // Checkout blocks support.
        add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'save_store_credits_order_meta_via_store_api' ), 10, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'deduct_store_credits_discount_from_balance_via_store_api' ), 10, 1 );
    }
}
