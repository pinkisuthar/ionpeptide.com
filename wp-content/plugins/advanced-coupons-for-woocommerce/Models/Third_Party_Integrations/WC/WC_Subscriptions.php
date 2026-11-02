<?php
namespace ACFWP\Models\Third_Party_Integrations\WC;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;

use ACFWP\Abstracts\Base_Model;
use ACFWP\Interfaces\Model_Interface;

use ACFWP\Models\Objects\Advanced_Coupon;

use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants as ACFWF_Constants;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of extending the coupon system of woocommerce.
 * It houses the logic of handling coupon url.
 * Public Model.
 *
 * @since 2.4.2
 */
class WC_Subscriptions extends Base_Model implements Model_Interface {
    /**
     * Property that holds the URL of the JS files.
     *
     * @since 3.5.8
     * @access private
     * @var string
     */
    private $_js_url;

    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
    */

    const E_PRODUCT_SEARCH_ACTION         = 'acfwp_add_products_search';
    const ALLOW_STORE_CREDITS_FOR_RENEWAL = 'acfw_allow_store_credits_for_renewal';

    /**
     * Subscription coupon types.
     *
     * @since 2.4.2
     * @access private
     * @var array
     */
    private static $recurring_coupons = array(
        'recurring_fee'     => 1,
        'recurring_percent' => 1,
    );

    /**
     * Property that holds the list of subscription product types.
     *
     * @since 2.4.2
     * @access private
     * @var array
     */
    private $_product_types = array(
        'subscription',
        'subscription_variation',
    );

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Class constructor.
     *
     * @since 2.4.2
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $this->_js_url = $this->_constants->THIRD_PARTY_URL . 'WC/js/';
        $main_plugin->add_to_all_plugin_models( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | WC Subscriptions implementation
    |--------------------------------------------------------------------------
    */

    /**
     * Maybe allow store credits on renewal.
     *
     * @since 4.0.4
     * @access public
     *
     * @param bool $is_allowed Whether the store credits are allowed on renewal.
     * @return bool Whether the store credits are allowed on renewal.
     */
    public function maybe_allow_store_credits_on_renewal( $is_allowed ) {
        if ( 'yes' === get_option( self::ALLOW_STORE_CREDITS_FOR_RENEWAL, 'no' ) ) {
            return true;
        }

        return $is_allowed;
    }

    /**
     * Update the renewal WooCommerce Subscriptions order total after it's created.
     *
     * @since 4.0.4
     * @access public
     *
     * @param \WC_Order        $renewal_order The renewal order object.
     * @param \WC_Subscription $subscription The subscription object.
     * @return \WC_Order The renewal order object.
     */
    public function update_renewal_order_total( $renewal_order, $subscription ) {

        if ( 'yes' !== get_option( self::ALLOW_STORE_CREDITS_FOR_RENEWAL, 'no' ) ) {
            return $renewal_order;
        }

        $store_credit_data = $renewal_order->get_meta( ACFWF_Constants::STORE_CREDITS_ORDER_PAID, true );
        $is_after_tax      = is_array( $store_credit_data ) && ! empty( $store_credit_data['amount'] );

        $sc_amount       = $this->_get_store_credit_amount( $renewal_order, $is_after_tax );
        $user_sc_balance = \ACFWF()->Store_Credits_Calculate->get_customer_balance( $renewal_order->get_customer_id(), true );

        // If credit used exceeds user's balance, remove store credit usage.
        if ( $sc_amount > $user_sc_balance ) {

            // Before tax.
            if ( ! $is_after_tax ) {

                // Initialize store credit discount values.
                $store_credit_discount     = 0;
                $store_credit_discount_tax = 0;

                foreach ( $renewal_order->get_items( 'coupon' ) as $item_id => $coupon ) {
                    if ( $coupon->get_code() === \ACFWF()->Store_Credits_Checkout->get_store_credit_coupon_code() ) {
                        $store_credit_discount     = $coupon->get_discount();
                        $store_credit_discount_tax = $coupon->get_discount_tax();

                        // Remove the store credit coupon from the order.
                        $renewal_order->remove_item( $item_id );
                    }
                }

                $line_items = $renewal_order->get_items( 'line_item' );

                // Calculate the total of all item subtotals (before tax).
                $total_subtotal = array_sum( array_map( fn( $i ) => $i->get_subtotal(), $line_items ) );

                // If there's a subtotal and the store credit discount exists.
                if ( $total_subtotal > 0 && ( $store_credit_discount > 0 || $store_credit_discount_tax > 0 ) ) {

                    foreach ( $line_items as $item ) {
                        // Get the item's subtotal.
                        $subtotal = $item->get_subtotal();

                        // Calculate what percentage this item contributes to the order.
                        $share = $subtotal / $total_subtotal;

                        // Calculate this item's share of the store credit discount.
                        $sc_discount_share     = round( $store_credit_discount * $share, 2 );
                        $sc_discount_tax_share = round( $store_credit_discount_tax * $share, 2 );

                        // Increase the item's total to cancel the discount.
                        $item->set_total( $item->get_total() + $sc_discount_share );
                        $item->set_total_tax( $item->get_total_tax() + $sc_discount_tax_share );
                        $item->save();
                    }
                }
            }

            $renewal_order->delete_meta_data( ACFWF_Constants::STORE_CREDITS_ORDER_PAID );
            $renewal_order->save_meta_data();

            return $renewal_order;
        }

        // Store credit is valid: create entry and recalculate totals.
        \ACFWF()->Store_Credits_Checkout->create_discount_store_credit_entry( $sc_amount, $renewal_order );

        $renewal_order->calculate_totals();
        $renewal_order->save();

        return $renewal_order;
    }

    /**
     * This function ensures that the store credit coupon and regular coupons remain applied when WooCommerce Subscriptions attempts to remove coupons.
     *
     * @since 4.0.5
     * @access public
     *
     * @param bool      $bypass Whether to bypass the coupon removal.
     * @param WC_Coupon $coupon The coupon object being checked.
     *
     * @return bool True if the coupon should not be removed, otherwise false.
     */
    public function bypass_store_credit_coupon_removal( $bypass, $coupon ) {

        // The store credit coupon is a fixed_cart coupon, so without this guard it would be kept on
        // recurring carts by the regular-types bypass below. When renewals are enabled, keep it on
        // recurring carts; when disabled, store credit must only apply to the initial purchase, so
        // let WooCommerce Subscriptions remove it from recurring carts.
        $is_store_credit_coupon = $coupon->get_code() === \ACFWF()->Store_Credits_Checkout->get_store_credit_coupon_code();
        if ( $is_store_credit_coupon ) {
            return 'yes' === get_option( self::ALLOW_STORE_CREDITS_FOR_RENEWAL, 'no' ) ? true : $bypass;
        }

        // Allow regular WooCommerce coupon types to remain applied when subscription products are in cart.
        // This fixes the issue where multiple coupons cannot be applied when subscription products are in cart.
        $coupon_type          = $coupon->get_discount_type();
        $regular_coupon_types = array( 'fixed_cart', 'percent', 'fixed_product', 'percent_product' );

        if ( in_array( $coupon_type, $regular_coupon_types, true ) ) {
            return true;
        }

        return $bypass;
    }

    /**
     * Add allow store credits for renewal settings.
     *
     * @since 4.6.6
     * @access public
     *
     * @param array $options Existing store credit settings.
     * @return array Updated settings.
     */
    public function admin_setting_store_credits_options( $options ) {

        $options[] = array(
            'id'      => self::ALLOW_STORE_CREDITS_FOR_RENEWAL,
            'title'   => __( 'Allow store credits for subscription renewals', 'advanced-coupons-for-woocommerce' ),
            'type'    => 'checkbox',
            'desc'    => __( 'Enable this option to allow customers to use store credits for automatic subscription renewals.', 'advanced-coupons-for-woocommerce' ),
            'default' => 'no',
        );

        return $options;
    }

    /**
     * Display store credit discount row in subscription recurring carts.
     *
     * @since 4.6.6
     * @access public
     *
     * @param array $recurring_carts List of recurring cart objects.
     */
    public function display_subscription_store_credit( $recurring_carts ) {

        if ( 'yes' !== get_option( self::ALLOW_STORE_CREDITS_FOR_RENEWAL, 'no' ) ) {
            return;
        }

        $sc_data = \WC()->session->get( ACFWF_Constants::STORE_CREDITS_SESSION, null );

        // skip displaying discount if not yet applied.
        if ( ! $sc_data || ! isset( $sc_data['amount'] ) ) {
            return;
        }

        $user_balance = apply_filters( 'acfw_filter_amount', \ACFWF()->Store_Credits_Calculate->get_customer_balance( get_current_user_id() ) );

        // load store credit discount row template.
        \ACFWF()->Helper_Functions->load_template(
            'acfw-store-credits/checkout-discount.php',
            array(
                'display_order_total' => false,
                'user_balance'        => $user_balance,
                'amount'              => $sc_data['amount'] * -1,
                'order_total'         => $sc_data['cart_total'],
            )
        );
    }

    /**
     * Maybe apply store credit to early renewal cart.
     *
     * Hooked into 'woocommerce_order_again_cart_item_data'.
     * Triggered when the customer clicks "Renew Now" from the subscription view,
     * initiating an early renewal cart (?subscription_renewal_early=ID&subscription_renewal=true).
     *
     * This function restores the store credit from the last parent order, either via
     * session-based after-tax discount or by re-applying the store credit coupon.
     *
     * @since 4.6.7
     * @since 4.0.9 Clear stale store credit session when renewals are disabled (#1513).
     * @access public
     *
     * @param array            $cart_item_data Data being added to the cart.
     * @param \WC_Order_Item   $line_item      Order line item.
     * @param \WC_Subscription $subscription   The subscription object being renewed.
     *
     * @return array
     */
    public function maybe_apply_store_credit( $cart_item_data, $line_item, $subscription ) {

        // Only act on subscription early renewals, not regular "order again" re-orders.
        if ( ! $subscription instanceof \WC_Subscription ) {
            return $cart_item_data;
        }

        if ( 'yes' !== get_option( self::ALLOW_STORE_CREDITS_FOR_RENEWAL, 'no' ) ) {
            // Clear any stale store credit session from the original purchase so it can't
            // bleed into the early renewal cart and get re-applied as a coupon (#1513).
            \ACFWF()->Store_Credits_Checkout->clear_store_credit_session();
            return $cart_item_data;
        }

        $parent_order = $subscription;

        if ( ! $parent_order instanceof \WC_Order ) {
            return $cart_item_data;
        }

        $meta_value          = $parent_order->get_meta( ACFWF_Constants::STORE_CREDITS_ORDER_PAID );
        $is_after_tax        = ! empty( $meta_value );
        $sc_amount           = $this->_get_store_credit_amount( $parent_order, $is_after_tax );
        $user_sc_balance     = \ACFWF()->Store_Credits_Calculate->get_customer_balance( $parent_order->get_customer_id(), true );
        $use_store_credit    = $sc_amount > 0 && $sc_amount <= $user_sc_balance;
        $store_credit_coupon = \ACFWF()->Store_Credits_Checkout->get_store_credit_coupon_code();

        if ( ! $use_store_credit ) {
            \ACFWF()->Store_Credits_Checkout->clear_store_credit_session();
            return $cart_item_data;
        }

        // After-tax: set session discount amount directly.
        if ( $is_after_tax ) {
            \WC()->session->set( ACFWF_Constants::STORE_CREDITS_SESSION, $meta_value );
            return $cart_item_data;
        }

        // Before-tax: re-apply the coupon if it was used previously.
        $used_coupons = $parent_order->get_coupon_codes();

        if ( in_array( $store_credit_coupon, $used_coupons, true ) ) {
            if ( \WC()->cart && ! \WC()->cart->has_discount( $store_credit_coupon ) ) {

                // Get coupon amount directly from the parent order's coupon items.
                $amount = 0;
                foreach ( $parent_order->get_items( 'coupon' ) as $coupon_item ) {
                    if ( $coupon_item->get_code() === $store_credit_coupon ) {
                        $amount = (float) $coupon_item->get_discount();
                        break;
                    }
                }

                $cart_total  = $parent_order->get_subtotal();
                $cart_total += wc_prices_include_tax() ? $parent_order->get_subtotal_tax() : 0;

                \WC()->session->set(
                    ACFWF_Constants::STORE_CREDITS_COUPON_SESSION,
                    array(
                        'amount'     => (float) $amount,
                        'cart_total' => (float) $cart_total,
                        'currency'   => get_woocommerce_currency(),
                    )
                );

                \ACFWF()->Store_Credits_Checkout->set_coupon_override_data( $store_credit_coupon );
            }
        }

        return $cart_item_data;
    }

    /**
     * Ensure store credit coupon is removed if session data is missing after early renewal setup.
     *
     * @since 4.6.7
     * @since 4.0.9 Remove lingering coupon and clear session when renewals are disabled (#1513).
     * @access public
     *
     * @param \WC_Subscription $subscription The subscription object.
     */
    public function maybe_remove_missing_store_credit_coupon( $subscription ) {

        if ( ! $subscription instanceof \WC_Subscription ) {
            return;
        }

        $store_credit_coupon = \ACFWF()->Store_Credits_Checkout->get_store_credit_coupon_code();

        if ( 'yes' !== get_option( self::ALLOW_STORE_CREDITS_FOR_RENEWAL, 'no' ) ) {
            // Setting disabled: ensure no stale store credit coupon or session lingers on the
            // early renewal cart (#1513).
            if ( \WC()->cart && \WC()->cart->has_discount( $store_credit_coupon ) ) {
                \WC()->cart->remove_coupon( $store_credit_coupon );
            }

            \ACFWF()->Store_Credits_Checkout->clear_store_credit_session();
            return;
        }

        if ( \WC()->cart && \WC()->cart->has_discount( $store_credit_coupon ) ) {
            $session_data = \WC()->session->get( ACFWF_Constants::STORE_CREDITS_COUPON_SESSION, null );

            if ( empty( $session_data ) ) {
                \WC()->cart->remove_coupon( $store_credit_coupon );
            }
        }
    }

    /**
     * Retrieve the store credit amount from renewal order.
     *
     * @since 4.6.7
     * @access private
     *
     * @param \WC_Order $renewal_order The renewal order object.
     * @param bool      $is_after_tax  Whether the store credit was applied after tax.
     * @return float
     */
    private function _get_store_credit_amount( $renewal_order, $is_after_tax ) {
        if ( $is_after_tax ) {
            // After tax: read from metadata.
            $sc_data = $renewal_order->get_meta( ACFWF_Constants::STORE_CREDITS_ORDER_PAID, true );
            if ( is_array( $sc_data ) && ! empty( $sc_data['amount'] ) ) {
                return floatval( $sc_data['amount'] );
            }
        } else {
            // Before tax: look for the coupon with code 'store credit'.
            foreach ( $renewal_order->get_items( 'coupon' ) as $coupon ) {
                if ( $coupon->get_code() === \ACFWF()->Store_Credits_Checkout->get_store_credit_coupon_code() ) {
                    return floatval( $coupon->get_discount() );
                }
            }
        }

        return 0;
    }

    /**
     * Filter subscription IDs from a list of WC_Product objects.
     *
     * @since 2.4.1
     * @access public
     *
     * @param WC_Product[] $products List of product objects.
     * @return int[] List of subscription ids.
     */
    public function filter_subscription_ids_from_products( $products ) {

        $sub_products = array_reduce(
            $products,
            function ( $c, $p ) {

            if ( $p instanceof \WC_Product && in_array( $p->get_type(), $this->_product_types, true ) ) {
                $c[] = $p->get_id();
            }

            return $c;
            },
            array()
        );

        return $sub_products;
    }

    /**
     * Append product type info in products search response data.
     *
     * @since 2.4.2
     * @access public
     *
     * @param array $response Product ID and name pairs.
     * @param array $products Array of WC_Product objects.
     * @param array $params   Raw search parameters.
     * @return array Filtered response data.
     */
    public function append_product_type_in_search_response( $response, $products, $params ) {

        if ( isset( $params['action'] ) && self::E_PRODUCT_SEARCH_ACTION === $params['action'] ) {

            // get all subscription product ids from search response.
            $sub_products = $this->filter_subscription_ids_from_products( $products );

            @header( 'X-Subscription-IDs: ' . implode( ',', $sub_products ) ); // phpcs:ignore
        }

        return $response;
    }

    /**
     * Populate subscription IDs to the Add Products panel data attributes.
     *
     * @since 2.4.2
     * @access public
     *
     * @param array $atts Panel attributes.
     * @param array $add_products Add products data.
     */
    public function populate_subscription_ids_panel_data_atts( $atts, $add_products ) {

        $products = array_filter(
            array_map(
                function ( $a ) {
                return wc_get_product( $a['product_id'] );
                },
                $add_products
            )
        ); // Remove false entries for products that have been deleted.

        $atts['subscription_ids']            = $this->filter_subscription_ids_from_products( $products );
        $atts['subscription_discount_error'] = __( 'Custom discounts for subscription products are not supported for this feature.', 'advanced-coupons-for-woocommerce' );

        return $atts;
    }

    /**
     * Loop through each Add Products item and ensure subscription products have "nodiscount" discount type.
     *
     * @since 2.4.2
     * @access public
     *
     * @param array $add_products Add products data.
     * @return array Filtered add products data.
     */
    public function set_subscription_discount_type_in_add_products( $add_products ) {

        $add_products = array_map(
            function ( $a ) {

            $product = wc_get_product( $a['product_id'] );

            if ( $product instanceof \WC_Product && in_array( $product->get_type(), $this->_product_types, true ) ) {
                $a['discount_type']  = 'nodiscount';
                $a['discount_value'] = 0;
            }

            return $a;
            },
            $add_products
        );

        return $add_products;
    }

    /**
     * Uset the remove coupon method for virtual coupons during WC Subscriptions coupon validation process which is run
     * when the `woocommerce_before_calculate_totals` action hook is called.
     *
     * @since 3.5.3
     * @access public
     */
    public function unset_virtual_coupon_remove_coupon_method() {
        remove_action( 'woocommerce_removed_coupon', array( \ACFWP()->Virtual_Coupon_Frontend, 'remove_unused_virtual_coupons_from_session' ) );
    }

    /**
     * Set the remove coupon method for virtual coupons on a later priority of the `woocommerce_before_calculate_totals` action hook.
     *
     * @since 3.5.3
     * @access public
     */
    public function set_virtual_coupon_remove_coupon_method() {
        add_action( 'woocommerce_removed_coupon', array( \ACFWP()->Virtual_Coupon_Frontend, 'remove_unused_virtual_coupons_from_session' ) );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
    */

    /**
     * Execute WC_Subscriptions class.
     *
     * @since 2.4.2
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {

        if ( ! $this->_helper_functions->is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) ) {
            return;
        }

        add_filter( 'wcs_bypass_coupon_removal', array( $this, 'bypass_store_credit_coupon_removal' ), 10, 2 );

        add_filter( 'acfw_setting_store_credits_options', array( $this, 'admin_setting_store_credits_options' ) );

        add_filter( 'acfw_allow_store_credits_on_renewal', array( $this, 'maybe_allow_store_credits_on_renewal' ), 10, 2 );

        // Update the renewal WooCommerce Subscriptions order total after it's created.
        add_filter( 'wcs_renewal_order_created', array( $this, 'update_renewal_order_total' ), 9, 2 );

        // Displays store credit discount row in recurring subscription totals.
        add_action( 'woocommerce_subscriptions_recurring_subscription_totals', array( $this, 'display_subscription_store_credit' ), 10, 1 );

        // Applies store credit discount to cart items during manual order renewal by customer.
        add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'maybe_apply_store_credit' ), 100, 3 );

        // Ensures store credit coupon is removed from cart if the required session data is missing after early renewal setup.
        add_action( 'wcs_after_early_renewal_setup_cart_subscription', array( $this, 'maybe_remove_missing_store_credit_coupon' ) );

        add_action( 'acfw_json_search_products_response', array( $this, 'append_product_type_in_search_response' ), 10, 3 );
        add_action( 'acfwp_add_products_panel_data_atts', array( $this, 'populate_subscription_ids_panel_data_atts' ), 10, 2 );
        add_filter( 'acfwp_sanitize_add_products_data', array( $this, 'set_subscription_discount_type_in_add_products' ) );
        add_filter( 'acfwp_coupon_get_add_products_data', array( $this, 'set_subscription_discount_type_in_add_products' ) );

        add_action( 'woocommerce_before_calculate_totals', array( $this, 'unset_virtual_coupon_remove_coupon_method' ), 1 );
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_virtual_coupon_remove_coupon_method' ), 11 );
    }
}
