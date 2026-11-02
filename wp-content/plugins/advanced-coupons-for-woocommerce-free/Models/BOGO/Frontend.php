<?php
namespace ACFWF\Models\BOGO;

use ACFWF\Abstracts\Abstract_BOGO_Deal;
use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Advanced_Coupon;
use ACFWF\Models\Objects\BOGO\Calculation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Exit if accessed directly.

/**
 * Model that houses the logic of extending the coupon system of woocommerce.
 * It houses the logic of handling coupon url.
 * Public Model.
 *
 * @since 1.4
 */
class Frontend extends Base_Model implements Model_Interface {
    /**
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that houses the model name to be used when calling publicly.
     *
     * @since 2.8
     * @access private
     * @var string
     */
    private $_model_name = 'BOGO_Frontend';

    /**
     * Property that houses the BOGO Calculation instance.
     *
     * @since 1.4
     * @access private
     * @var Calculation
     */
    private $_calculation;

    /**
     * List of products to display on coupon cart total row.
     *
     * @since 1.4
     * @access private
     * @var array
     */
    private $_price_display = array();

    /**
     * Meta key used to store the BOGO-intended price directly on the product object
     * (in-memory only, never saved to DB). The value is a float already in the active
     * currency. A priority-100 woocommerce_product_get_price filter reads this to
     * restore the correct price after WooPayments Multi-Currency re-converts it at
     * priority 99. Following the same pattern as WooPayments's own ProductAddOns
     * compatibility class (_wcpay_multi_currency_addons_converted).
     *
     * @since 4.6.8
     */
    const BOGO_LOCKED_PRICE_META_KEY = '_acfw_bogo_locked_price';

    /**
     * Guard flag set while unqualified deal items are being removed from the cart.
     *
     * Removal happens inside woocommerce_before_calculate_totals. Removing/reducing a
     * cart item can fire third-party handlers (e.g. on woocommerce_before_cart_item_quantity_zero)
     * that call WC()->cart->calculate_totals() again. This flag lets implement_bogo_deals()
     * bail on such re-entry so the BOGO calculation is not re-run against a half-modified cart.
     *
     * @since 4.7.4
     * @access private
     * @var bool
     */
    private $_removing_unqualified_items = false;

    /**
     * Cart item keys whose price BOGO changed in the current request.
     *
     * The reset_bogo_deals_prices() method must only restore the items BOGO actually repriced.
     * Every other cart line keeps the price WooCommerce and other plugins resolved
     * for it (issue #1622).
     *
     * @since 4.7.6
     * @access private
     * @var array
     */
    private $_repriced_cart_items = array();

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 1.4
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
    | Implementation related functions.
    |--------------------------------------------------------------------------
     */

    /**
     * Restrict cart to only allow one BOGO to be applied.
     *
     * @since 4.1
     * @since 4.7.6 Count BOGO coupons from the live applied-coupons list instead of the
     *              Calculation singleton cache. The cache is only rebuilt on instantiation and
     *              on implementation, so within a single request it can still hold a BOGO coupon
     *              that was already removed from the cart (e.g. an auto-apply BOGO invalidated by
     *              its cart conditions). That stale entry incorrectly blocked the replacement
     *              BOGO coupon until the next page load (ACFWP issue #1198).
     * @access public
     *
     * @param bool            $value Filter return value.
     * @param Advanced_Coupon $coupon Advanced coupon object.
     * @return bool Filter return value (unchanged when the coupon is allowed).
     * @throws \Exception When BOGO coupon is already applied to the cart.
     */
    public function restrict_cart_to_only_one_bogo_deal( $value, $coupon ) {
        // Only read the cart once the coupon is known to be a BOGO deal, and only when a cart
        // exists. This filter also runs from WC_Discounts::is_coupon_valid() in cart-less contexts
        // such as the orders REST controller, where WC()->cart is null.
        if ( ! $coupon->is_type( 'acfw_bogo' ) || ! \WC()->cart ) {
            return $value;
        }

        $applied_coupons = \WC()->cart->get_applied_coupons();

        if ( ! empty( $applied_coupons ) ) {

            $bogo_coupon_codes = $this->_get_applied_bogo_coupon_codes( $applied_coupons );

            if ( ! in_array( (string) $coupon->get_code(), $bogo_coupon_codes, true ) && get_option( ACFWF()->Plugin_Constants->ALLOWED_BOGO_COUPONS_COUNT, 1 ) <= count( $bogo_coupon_codes ) ) {
                // Translators: %s is the coupon code.
                $message = __( 'Sorry, coupon "%s" cannot be used in conjunction with the other coupons already applied.', 'advanced-coupons-for-woocommerce-free' );
                do_action( 'acfw_restrict_allowed_bogo_coupons_error_message', $message, 100, $coupon );

                throw new \Exception( esc_html( sprintf( $message, $coupon->get_code() ) ) );
            }
        }

        return $value;
    }

    /**
     * Get the codes of the applied coupons that count as BOGO deals, from the live applied list.
     *
     * Matches the predicate `Calculation::get_bogo_deals_from_cart()` uses: a BOGO-typed coupon
     * with no configured deal data never consumed a restriction slot, and must not start
     * consuming one.
     *
     * Memoised per applied-coupons signature: `woocommerce_coupon_is_valid` fires once per
     * coupon per validation pass, and rebuilding the coupon objects each time would re-fire
     * `woocommerce_get_shop_coupon_data` (and, for store-credit virtual coupons, a
     * session-writing filter chain) more often than before. The cache key is the applied list
     * itself, so the count stays live across cart changes within the request.
     *
     * @since 4.7.6
     * @access private
     *
     * @param array $applied_coupons Applied coupon codes from the cart.
     * @return string[] Applied coupon codes that are configured BOGO deals.
     */
    private function _get_applied_bogo_coupon_codes( $applied_coupons ) {
        static $cache = array();

        $cache_key = md5( (string) wp_json_encode( array_values( $applied_coupons ) ) );

        if ( ! isset( $cache[ $cache_key ] ) ) {
            $bogo_coupon_codes = array();

            foreach ( $applied_coupons as $code ) {
                $applied_coupon = new Advanced_Coupon( (string) $code );

                if ( $applied_coupon->is_type( 'acfw_bogo' ) && $applied_coupon->get_advanced_prop( 'bogo_deals' ) ) {
                    $bogo_coupon_codes[] = (string) $code;
                }
            }

            $cache[ $cache_key ] = $bogo_coupon_codes;
        }

        return $cache[ $cache_key ];
    }

    /**
     * Implement BOGO Deals for all applied coupon in the cart.
     *
     * @since 1.4
     * @since 4.1 Skip calculation when there is no BOGO Deal present to calculate.
     * @access public
     */
    public function implement_bogo_deals() {
        // Bail on re-entry triggered while removing unqualified deal items (issue #909),
        // so the calculation is not re-run against a cart that is mid-modification.
        if ( $this->_removing_unqualified_items ) {
            return;
        }

        if ( ! \WC()->cart instanceof \WC_Cart ) {
            return;
        }

        // Capture the previous calculation's matched deal entries up front, before anything
        // clears the session. Needed to detect deal items that must be removed even when the
        // last BOGO coupon has just been removed and the normal calculation below is skipped
        // (issue #909).
        $previous_session = \WC()->session ? \WC()->session->get( 'acfw_bogo_entries' ) : null;
        $previous_matched = is_array( $previous_session ) && isset( $previous_session['matched'] ) ? $previous_session['matched'] : array();

        // Skip when there are no coupons applied yet. Still sweep for previously deal-granted
        // items to remove first (e.g. the only BOGO coupon was just removed), otherwise the
        // item would silently revert to full price instead of being removed (issue #909).
        if ( empty( \WC()->cart->get_applied_coupons() ) ) {
            if ( ! empty( $previous_matched ) ) {
                if ( ! $this->_calculation instanceof Calculation ) {
                    $this->_calculation = Calculation::get_instance();
                }

                $this->_maybe_remove_unqualified_deal_items( $previous_matched );

                // No coupons remain, so drop the stale session state that seeded the sweep.
                Calculation::clear_session_data();
            }

            return;
        }

        // create BOGO Calculation instance.
        if ( ! $this->_calculation instanceof Calculation ) {
            $this->_calculation = Calculation::get_instance();
        }

        // skip if there's no BOGO coupon or when calculation is already done.
        if ( $this->_calculation->is_calculation_done() ) {
            return;
        }

        // check if calculation is available in session and is still valid.
        if ( ! $this->_calculation->is_calculated_from_session() ) {
            // clear previous session data.
            Calculation::clear_session_data();

            // Refresh BOGO deals from the current cart. The Calculation singleton may have been
            // instantiated by an early consumer (e.g. price filters) before any BOGO coupons were
            // applied, leaving its deal list stale/empty. Re-reading the cart here ensures every
            // applied BOGO deal is processed (e.g. two simultaneous auto-apply BOGOs).
            $this->_calculation->refresh_bogo_deals();

            foreach ( $this->_calculation->get_all_bogo_deals() as $bogo_deal ) {
                $this->_implement_bogo_deal( $bogo_deal );
            }

            // add eligible notices for deals with missing items.
            $this->_add_notice_for_eligible_deals();

            // Remove deal items from the cart when their coupon is opted into removal
            // and the deal no longer qualifies for them (issue #909). Runs before the
            // session is persisted so the saved state reflects the modified cart.
            $this->_maybe_remove_unqualified_deal_items( $previous_matched );

            // save calculation and notices data to session.
            $this->_calculation->set_session_data();
        }

        // apply discount by adjusting cart item prices.
        // NOTE: in "coupon" discount application mode this still runs to populate the
        // price display bookkeeping (used by the coupon summary and order meta), but the
        // actual cart item price mutations are skipped — the discount is instead delivered
        // through the `woocommerce_coupon_get_discount_amount` filter.
        if ( ! empty( $this->_calculation->get_all_entries() ) ) {
            $this->_set_matching_cart_item_deals_prices();

            // apply price of matching cart item triggers (price modification mode only).
            if ( ! $this->is_coupon_amount_mode() && apply_filters( 'acfw_enable_matching_cart_triggers_prices', false ) ) {
                $this->_set_matching_cart_item_triggers_prices();
            }
        }

        // mark calculation as done.
        $this->_calculation->done_calculation();

        // display eligible for deals notices.
        $this->_display_eligible_deal_notices();
    }

    /**
     * Run BOGO implementation
     *
     * @since 1.4
     * @access private
     *
     * @param Abstract_BOGO_Deal $bogo_deal BOGO Deal object.
     */
    private function _implement_bogo_deal( Abstract_BOGO_Deal $bogo_deal ) {
        // skip if the re are no triggers or deals data.
        if ( 0 >= count( $bogo_deal->triggers ) || 0 >= count( $bogo_deal->deals ) ) {
            return;
        }

        // set current BOGO deal being processed in calculation object.
        $this->_calculation->set_bogo_deal( $bogo_deal );

        // allow 3rd party implementations for BOGO deals.
        if ( apply_filters( 'acfwf_before_implement_bogo_for_coupon', false ) ) {
            return;
        }

        do {
            $deals_fulfilled = false;

            // reset counters and temporary entries on each loop instance.
            $bogo_deal->reset_counters();

            // verify triggers with cart items eligible only for triggers.
            // For same-products, don't use trigger_only mode because trigger and deal are the same products.
            $trigger_only = 'same-products' !== $bogo_deal->deal_type;
            $this->_calculation->verify_triggers( $trigger_only );

            /**
             * Verify deal items and then verify triggers again with shared items.
             * If deal items are valid, but triggers are not, then clear the temporarily matched deal items.
             * Then reverify triggers first, and then verify deal items again.
             *
             * Note: For same-products, we skip this double verification because each product is independent.
             * The trigger and deal for each product should be evaluated separately.
             */
            if ( 'same-products' !== $bogo_deal->deal_type && $this->_calculation->verify_deals() && ! $this->_calculation->verify_triggers() ) {

                // reset counters and temp matched.
                $bogo_deal->reset_counters( 'deal' );
                $this->_calculation->clear_temp_entries( 'deal' );

                // reverify triggers, and reverify deals if triggers are valid.
                if ( $this->_calculation->verify_triggers() ) {
                    $this->_calculation->verify_deals();
                }
            } elseif ( 'same-products' === $bogo_deal->deal_type ) {
                // For same-products, simply verify deals after triggers without double verification.
                $this->_calculation->verify_deals();
            }

            // hook to run after verifying items (auto-add).
            do_action( 'acfw_bogo_after_verify_trigger_deals', $bogo_deal );

            // For same-products: re-verify deals after the auto-add hook.
            // auto_add_same_products_to_cart runs after verify_deals() and may add items to the cart
            // (e.g. raising a variation from qty=1 to qty=2). Without a second pass, that variation
            // would have had zero spare quantity during the first verify_deals() and never receives a
            // deal entry — causing the discount to be missing until a page refresh.
            if ( 'same-products' === $bogo_deal->deal_type ) {
                $bogo_deal->reset_counters( 'deal' );
                $this->_calculation->clear_temp_entries( 'deal' );
                $this->_calculation->verify_deals();
            }

            // verify BOGO trigger conditions.
            // For same-products, we check if at least one trigger-deal pair is verified.
            // For other types, we check if all triggers are verified.
            $is_trigger_verified = ( 'same-products' === $bogo_deal->deal_type )
                ? $this->_is_same_products_trigger_verified( $bogo_deal )
                : $bogo_deal->is_trigger_verified();

            if ( $is_trigger_verified ) {

                // check if all deal items for this instance were all fulfilled.
                $deals_fulfilled = $bogo_deal->is_deal_fulfilled();

                // proccess deals that are missing in the cart (display notice for later).
                if ( ! $deals_fulfilled ) {
                    $this->_calculation->process_allowed_deals_data();
                }

                // if at least 1 deal item fulfilled, then confirm the matched triggers and deals.
                // NOTE: This is to ensure that if a BOGO Deal has no deals fulfilled, then the items verified in trigger
                // can still be used by other coupons.
                if ( $bogo_deal->has_deal_fulfilled() ) {
                    $this->_calculation->confirm_matched_triggers();
                }
            }

            // clear temporary matched entries.
            $this->_calculation->clear_temp_entries();

            // Increment run counter for the BOGO Deal.
            $bogo_deal->increment_run_counter();

            // For same-products with repeat, continuation depends solely on whether any product
            // still has spare quantity — NOT on is_deal_fulfilled(). With multiple variations,
            // one product may exhaust its items before others, causing is_deal_fulfilled() to
            // return false (Red's deal unmet) even though other products (Blue) still have
            // cycles to process. Using has_spare alone lets those remaining products complete.
            if ( 'same-products' === $bogo_deal->deal_type && $bogo_deal->is_repeat ) {
                $has_spare       = $this->_same_products_has_spare_quantity( $bogo_deal );
                $deals_fulfilled = $has_spare;
            }
        } while (
            $bogo_deal->is_repeat && $deals_fulfilled
        );
    }

    /**
     * Apply discount of matching cart item deals by adjusting the price of cart line items.
     *
     * @since 1.4
     * @access private
     */
    private function _set_matching_cart_item_deals_prices() {
        foreach ( \WC()->cart->get_cart_contents() as $cart_item ) {

            $key = $cart_item['key'];

            // if cart key already present in price display, then skip.
            // this prevents discount be applied multiple times on the cart.
            if ( isset( $this->_price_display[ $key ] ) ) {
                continue;
            }

            $deals = $this->_calculation->get_entries_by_cart_item( $key, 'deal' );

            // don't proceed if there are no deal entries for the current item.
            if ( empty( $deals ) ) {
                continue;
            }

            // Get prices.
            $price            = array();
            $price['regular'] = $this->_helper_functions->get_price( $cart_item['data'], array( 'cart_item' => $cart_item ) );

            $total_discount     = 0.0;
            $total_discount_qty = 0;
            $discounted_prices  = array(); // list new prices per coupon discount and quantity.

            foreach ( $deals as $deal ) {
                $discount            = \ACFWF()->Helper_Functions->calculate_discount_by_type( $deal['discount_type'], $deal['discount'], $price['regular'] );
                $total_discount     += $discount * $deal['quantity'];
                $total_discount_qty += $deal['quantity'];

                // Cast the discount to a string if it's a float.
                $discount_key = (string) $discount;

                if ( ! isset( $discounted_prices[ $discount_key ] ) ) {
                    $discounted_prices[ $discount_key ] = array(
                        'discount' => $discount,
                        'quantity' => 0,
                    );
                }

                $discounted_prices[ $discount_key ]['quantity'] += $deal['quantity'];
            }

            // calculate new item price based on the total discount and set it.
            // NOTE: this will only be false when $discount value is 0.
            if ( (bool) $total_discount ) {
                // get BOGO Buys price.
                $price['buy'] = $this->_helper_functions->get_price(
                    $cart_item['data'],
                    array(
                        'ignore_always_use_regular_price' => 'all_valid' !== get_option( Plugin_Constants::ALWAYS_USE_REGULAR_PRICE ), // ignore always use regular price option, because BOGO Buys should always use the sale price if present.
                        'cart_item'                       => $cart_item,
                    )
                );

                // Calculate new_price, to get total price of the item.
                // new_price is the average price of the item after discount.
                $total_bogo_buy_qty = $cart_item['quantity'] - $total_discount_qty;
                $total_bogo_buy     = $price['buy'] * $total_bogo_buy_qty;
                $total_bogo_get     = ( $price['regular'] * $total_discount_qty ) - $total_discount;
                $new_price          = ( $total_bogo_buy + $total_bogo_get ) / $cart_item['quantity'];

                // Change displayed price when setting tax is set to yes and tax display cart is excl.
                if ( \wc_tax_enabled() && 'yes' === get_option( 'woocommerce_prices_include_tax' ) && 'excl' === get_option( 'woocommerce_tax_display_cart' ) ) {
                    $price['buy'] = (float) wc_get_price_excluding_tax( $cart_item['data'] );
                }
                // Lock the intended active-currency price directly on the product object
                // (in-memory only). Our priority-100 woocommerce_product_get_price filter
                // reads this meta and returns it, overriding whatever WooPayments at
                // priority 99 computed. This follows the same pattern WooPayments itself
                // uses in its WooCommerceProductAddOns compatibility class.
                // In "coupon" discount application mode the product price is left untouched
                // (no set_price, no locked-price meta) — the discount is applied to the
                // coupon amount instead.
                if ( ! $this->is_coupon_amount_mode() ) {
                    $bogo_new_price = apply_filters( 'acfw_bogo_get_item_new_price', $new_price, $cart_item );
                    $cart_item['data']->update_meta_data( self::BOGO_LOCKED_PRICE_META_KEY, (float) $bogo_new_price );
                    $cart_item['data']->set_price( $bogo_new_price );
                    $this->_repriced_cart_items[ $key ] = true;
                }

                // add details to $this->_price_display property price differences on cart table.
                $this->_price_display[ $key ] = array(
                    'name'              => $cart_item['data']->get_name(),
                    'price'             => $price,
                    'new_price'         => $new_price,
                    'total_discount'    => $total_discount,
                    'discounted_prices' => $discounted_prices,
                );
            }
        }
    }

    /**
     * Apply price of matching cart item triggers by adjusting of cart line items.
     *
     * @since 4.6.5
     * @access private
     */
    private function _set_matching_cart_item_triggers_prices() {
        foreach ( \WC()->cart->get_cart_contents() as $cart_item ) {

            $key = $cart_item['key'];

            // if cart key already present in price display, then skip.
            // this prevents price be applied multiple times on the cart.
            if ( isset( $this->_price_display[ $key ] ) ) {
                continue;
            }

            $triggers = $this->_calculation->get_entries_by_cart_item( $key, 'trigger' );

            // don't proceed if there are no deal triggers for the current item.
            if ( empty( $triggers ) ) {
                continue;
            }

            $price = $this->_helper_functions->get_price( $cart_item['data'], array( 'ignore_always_use_regular_price' => 'all_valid' !== get_option( Plugin_Constants::ALWAYS_USE_REGULAR_PRICE ) ) );
            $cart_item['data']->set_price( apply_filters( 'acfw_bogo_set_trigger_item_price', $price, $cart_item ) );
            $this->_repriced_cart_items[ $key ] = true;
        }
    }

    /**
     * Reset BOGO deal item prices to their original values.
     *
     * This method is used to undo any price modifications applied by the BOGO logic
     * when conditions are not met or the coupon becomes invalid.
     * It skips items already recorded in the internal `_price_display` array to prevent
     * overwriting already discounted items, and it skips items BOGO never repriced. What
     * it can reach is therefore the trigger priced lines only. Trigger pricing sits behind
     * the `acfw_enable_matching_cart_triggers_prices` filter, which defaults to false, so
     * on a default install this method resets nothing.
     *
     * @since 4.6.7
     * @since 4.7.6 Only reset the cart items BOGO actually repriced (issue #1622).
     * @access public
     */
    public function reset_bogo_deals_prices() {
        $cart_keys = array_keys( \WC()->cart->get_cart_contents() );

        // Remove _price_display entries for items that are no longer in cart.
        foreach ( $this->_price_display as $key => $data ) {
            if ( ! in_array( $key, $cart_keys, true ) ) {
                unset( $this->_price_display[ $key ] );
            }
        }

        // Drop reprice records for items that are no longer in cart.
        foreach ( $this->_repriced_cart_items as $key => $unused ) {
            if ( ! in_array( $key, $cart_keys, true ) ) {
                unset( $this->_repriced_cart_items[ $key ] );
            }
        }

        // In "coupon" discount application mode no prices were modified, so there is
        // nothing to reset (and no locked-price meta should ever be stamped).
        if ( $this->is_coupon_amount_mode() ) {
            return;
        }

        // Reset prices for items that don't have BOGO discounts.
        foreach ( \WC()->cart->get_cart_contents() as $cart_item ) {
            $key = $cart_item['key'];

            // Skip items that have BOGO discounts.
            if ( isset( $this->_price_display[ $key ] ) ) {
                continue;
            }

            // Skip items BOGO never repriced. Resetting them would overwrite the price
            // WooCommerce and other plugins resolved for the line — for example the
            // Wholesale Prices sale price on a cart that holds no BOGO deal at all
            // (issue #1622). Deal-priced items are already skipped by the _price_display
            // check above, so what remains here are the trigger-priced items.
            if ( ! isset( $this->_repriced_cart_items[ $key ] ) ) {
                continue;
            }

            // Reset price to original for items without BOGO discounts.
            // Clear the locked-price meta so get_price() returns the WooPayments-converted
            // customer-currency price, then re-lock that value so our priority-100 filter
            // returns it unchanged on subsequent get_price() calls (preventing a second
            // WooPayments conversion of the already-converted value).
            $cart_item['data']->delete_meta_data( self::BOGO_LOCKED_PRICE_META_KEY );
            $price       = $this->_helper_functions->get_price( $cart_item['data'], array( 'ignore_always_use_regular_price' => 'all_valid' !== get_option( Plugin_Constants::ALWAYS_USE_REGULAR_PRICE ) ) );
            $reset_price = apply_filters( 'acfw_bogo_reset_deal_item_price', $price, $cart_item );
            $cart_item['data']->update_meta_data( self::BOGO_LOCKED_PRICE_META_KEY, (float) $reset_price );
            $cart_item['data']->set_price( $reset_price );
        }
    }

    /**
     * Remove deal items from the cart when the deal no longer qualifies for them.
     *
     * Opt-in per BOGO coupon (issue #909). When a coupon is configured to "Remove the
     * free item from the cart" and a cart line that previously received a deal-granted
     * quantity from that coupon no longer receives it (trigger removed, cart condition
     * failed, coupon removed, or deal quantity exceeded), the lost deal-granted quantity
     * is removed from the cart instead of reverting to the original price. Quantities the
     * customer added independently of the deal are preserved: only the whole line is
     * removed when the entire line was deal-granted.
     *
     * Detection compares the previous calculation's matched deal entries against what each
     * coupon still grants now, treating a coupon that is no longer applied or no longer valid
     * as granting nothing. Cart mutations use the non-refreshing WooCommerce cart methods and
     * a re-entrancy guard so they are safe to run inside woocommerce_before_calculate_totals.
     *
     * @since 4.7.4
     * @access private
     *
     * @param array $previous_matched Matched entries from the previous calculation (pre-clear).
     */
    private function _maybe_remove_unqualified_deal_items( $previous_matched ) {
        if ( $this->_removing_unqualified_items || ! is_array( $previous_matched ) || empty( $previous_matched ) || ! \WC()->cart instanceof \WC_Cart ) {
            return;
        }

        // Build previous deal-granted quantities keyed by coupon code and cart item key.
        $previous_deals = array();
        foreach ( $previous_matched as $entry ) {
            if ( ! is_array( $entry ) || 'deal' !== ( $entry['type'] ?? '' ) || empty( $entry['coupon'] ) || empty( $entry['key'] ) ) {
                continue;
            }

            $previous_deals[ $entry['coupon'] ][ $entry['key'] ] = ( $previous_deals[ $entry['coupon'] ][ $entry['key'] ] ?? 0 ) + (int) $entry['quantity'];
        }

        if ( empty( $previous_deals ) ) {
            return;
        }

        // A coupon only keeps granting its deal items while it is still applied to the cart
        // AND still valid. A coupon that was removed, or whose cart conditions now fail (so it
        // is applied but invalid), no longer grants anything — its previously granted
        // quantities all count as lost. Resolving "still active" this way (rather than diffing
        // matched entries alone) is what lets removal fire on the coupon-removed and
        // cart-condition-fail paths, not just when the trigger is removed (issue #909).
        $applied_coupons = array_map( 'strtolower', \WC()->cart->get_applied_coupons() );
        $active_cache    = array();
        $is_active       = function ( $code ) use ( &$active_cache, $applied_coupons ) {
            $lc = strtolower( (string) $code );

            if ( ! array_key_exists( $lc, $active_cache ) ) {
                // is_valid() runs WooCommerce's coupon validity filters (cart conditions etc.),
                // so a coupon that is applied but whose conditions now fail resolves to false.
                $active_cache[ $lc ] = in_array( $lc, $applied_coupons, true ) && ( new Advanced_Coupon( $code ) )->is_valid();
            }

            return $active_cache[ $lc ];
        };

        // Build current deal-granted quantities keyed by coupon code and cart item key,
        // plus the per-cart-item total still granted across ALL still-active coupons (used to
        // cap removal so a unit another still-active coupon covers is never deleted). Only
        // count coupons that are still applied and valid; grants from removed/invalid coupons
        // must not protect their lines from removal.
        $current_deals       = array();
        $current_deals_total = array();
        if ( $this->_calculation instanceof Calculation ) {
            foreach ( $this->_calculation->get_all_entries( 'matched' ) as $entry ) {
                if ( ! is_array( $entry ) || 'deal' !== ( $entry['type'] ?? '' ) || empty( $entry['coupon'] ) || empty( $entry['key'] ) ) {
                    continue;
                }

                if ( ! $is_active( $entry['coupon'] ) ) {
                    continue;
                }

                $current_deals[ $entry['coupon'] ][ $entry['key'] ] = ( $current_deals[ $entry['coupon'] ][ $entry['key'] ] ?? 0 ) + (int) $entry['quantity'];
                $current_deals_total[ $entry['key'] ]               = ( $current_deals_total[ $entry['key'] ] ?? 0 ) + (int) $entry['quantity'];
            }
        }

        // Guard against re-entry while mutating the cart. Wrapped in try/finally so an
        // exception from wc_add_notice(), the notice filter, or the removal action (all of
        // which may run untrusted third-party callbacks) can never leave the flag stuck true —
        // which would short-circuit implement_bogo_deals() for the rest of the request and
        // drop BOGO prices from the totals.
        $this->_removing_unqualified_items = true;

        try {
            foreach ( $previous_deals as $coupon_code => $keys ) {
                $coupon = new Advanced_Coupon( $coupon_code );

                // Only act on coupons opted into removal.
                if ( 'remove' !== $coupon->get_bogo_remove_unqualified_deal() ) {
                    continue;
                }

                foreach ( $keys as $key => $previous_qty ) {
                    // A coupon that is no longer active grants nothing now, so its whole
                    // previous quantity is lost regardless of any stale matched entry.
                    $current_qty = $is_active( $coupon_code ) ? ( $current_deals[ $coupon_code ][ $key ] ?? 0 ) : 0;
                    $lost_qty    = $previous_qty - $current_qty;

                    // Deal still grants at least the previous quantity: nothing to remove.
                    if ( $lost_qty <= 0 ) {
                        continue;
                    }

                    $cart_item = \WC()->cart->get_cart_item( $key );

                    // Item is already gone from the cart.
                    if ( empty( $cart_item ) ) {
                        continue;
                    }

                    $line_qty     = (int) $cart_item['quantity'];
                    $product_name = $cart_item['data'] instanceof \WC_Product ? $cart_item['data']->get_name() : '';

                    // Cap the removal so it never eats into quantity that is still deal-granted
                    // by ANY still-active coupon on this line, nor the quantity the customer
                    // added independently. The most we can remove is the line quantity minus
                    // what is still deal-granted across all coupons; this also prevents deleting
                    // a line the deal still legitimately covers when the customer previously
                    // shrank it below the old deal quantity (issue #909).
                    $still_granted_total = $current_deals_total[ $key ] ?? 0;
                    $remove_qty          = min( $lost_qty, max( 0, $line_qty - $still_granted_total ) );

                    if ( $remove_qty <= 0 ) {
                        continue;
                    }

                    // Use set_quantity() with $refresh_totals = false so the cart is not
                    // recalculated mid-hook (remove_cart_item() would call calculate_totals()
                    // and re-enter this calculation). A resulting quantity of 0 removes the line.
                    \WC()->cart->set_quantity( $key, $line_qty - $remove_qty, false );

                    // Escape the product name and strings: wc_add_notice() does not escape, and
                    // custom themes or the filter below could output the notice unescaped. The
                    // wording distinguishes a full line removal from a partial quantity
                    // reduction, since a "was removed" notice for a product still sitting in the
                    // cart would be misleading.
                    $full_removal = $remove_qty >= $line_qty;

                    if ( $product_name ) {
                        $message = $full_removal
                            // Translators: %s is the product name.
                            ? sprintf( esc_html__( '"%s" was removed from your cart because the deal no longer applies.', 'advanced-coupons-for-woocommerce-free' ), esc_html( $product_name ) )
                            // Translators: 1: quantity removed, 2: product name.
                            : sprintf( esc_html__( '%1$d × "%2$s" was removed from your cart because the deal no longer applies.', 'advanced-coupons-for-woocommerce-free' ), $remove_qty, esc_html( $product_name ) );
                    } else {
                        $message = $full_removal
                            ? esc_html__( 'A deal item was removed from your cart because the deal no longer applies.', 'advanced-coupons-for-woocommerce-free' )
                            // Translators: %d is the quantity removed.
                            : sprintf( esc_html__( '%d deal item(s) were removed from your cart because the deal no longer applies.', 'advanced-coupons-for-woocommerce-free' ), $remove_qty );
                    }

                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice(
                            apply_filters( 'acfw_bogo_removed_unqualified_deal_notice', $message, $cart_item, $coupon ),
                            'notice',
                            array(
                                'acfw-bogo' => true,
                                'coupon'    => $coupon_code,
                            )
                        );
                    }

                    do_action( 'acfw_bogo_removed_unqualified_deal_item', $key, $cart_item, $coupon );
                }
            }
        } finally {
            // Note: the coupon itself is intentionally left applied. When a BOGO deal stops
            // qualifying its coupon usually becomes invalid and WooCommerce handles removal or
            // shows an error; forcing coupon removal here would fight that flow. This mirrors
            // the default 'keep' behaviour, which also leaves the coupon applied.
            $this->_removing_unqualified_items = false;
        }
    }

    /**
     * Check if same-products trigger is verified.
     * For same-products, we check if at least one product has its trigger verified.
     * This is different from regular BOGO where ALL triggers must be verified.
     *
     * @since 4.6.9
     * @access private
     *
     * @param Abstract_BOGO_Deal $bogo_deal BOGO Deal object.
     * @return bool True if at least one trigger is verified, false otherwise.
     */
    private function _is_same_products_trigger_verified( $bogo_deal ) {
        if ( 0 >= count( $bogo_deal->needed_triggers ) ) {
            return false;
        }

        // For same-products, check if at least one trigger is fully verified (needed_quantity === 0).
        foreach ( $bogo_deal->needed_triggers as $entry_id => $needed_qty ) {
            if ( 0 >= $needed_qty ) {
                return true; // At least one trigger is verified.
            }
        }

        return false;
    }

    /**
     * Check if any product in same-products BOGO still has spare quantity for repeat.
     * This checks if any cart item matching the triggers still has enough quantity for another trigger+deal.
     *
     * @since 4.6.9
     * @access private
     *
     * @param Abstract_BOGO_Deal $bogo_deal BOGO Deal object.
     * @return bool True if at least one product has spare quantity, false otherwise.
     */
    private function _same_products_has_spare_quantity( $bogo_deal ) {
        $cart_items = \WC()->cart->get_cart_contents();

        foreach ( $bogo_deal->triggers as $trigger ) {
            $trigger_qty = isset( $trigger['quantity'] ) ? absint( $trigger['quantity'] ) : 1;

            // Find matching cart items for this trigger.
            foreach ( $cart_items as $cart_item ) {
                if ( $bogo_deal->is_cart_item_match_entries( $cart_item, $trigger ) ) {
                    // Calculate spare quantity after current matched entries.
                    $spare_qty = $this->_calculation->calculate_cart_item_spare_quantity( $cart_item );

                    // Check if spare quantity is enough for at least one more trigger+deal cycle.
                    // For same-products, we need trigger_qty + deal_qty spare.
                    // Since trigger and deal quantities are usually the same, we need at least 2 * trigger_qty.
                    if ( $spare_qty > $trigger_qty ) {
                        return true; // At least one product has spare quantity for another cycle.
                    }
                }
            }
        }

        return false; // No product has spare quantity for another cycle.
    }

    /**
     * Add notice for all eligible deals.
     *
     * @since 1.4
     * @access private
     */
    private function _add_notice_for_eligible_deals() {
        foreach ( $this->_calculation->get_all_bogo_deals() as $bogo_deal ) {
            // if BOGO Deal last iteration has no fulfilled deals, then reverify triggers.
            if ( ! $bogo_deal->has_deal_fulfilled() ) {
                $this->_calculation->set_bogo_deal( $bogo_deal );
                $bogo_deal->reset_counters();

                // skip displaying notice if triggers are not verified.
                // NOTE: This means that the items that were used to verify the last iteration was used by another coupon.
                if ( ! $this->_calculation->verify_triggers( false, false ) ) {
                    continue;
                }
            }

            $coupon           = $bogo_deal->get_coupon();
            $allowed_entries  = $this->_calculation->get_entries_by_coupon( $coupon->get_code(), 'deal', 'allowed' );
            $allowed_quantity = array_sum( array_column( $allowed_entries, 'quantity' ) );

            if ( ! $allowed_quantity || ! apply_filters( 'acfw_bogo_deals_is_eligible_notice', true, $allowed_quantity, $coupon ) ) {
                continue;
            }

            $settings    = $coupon->get_bogo_notice_settings();
            $message     = isset( $settings['message'] ) && $settings['message'] ? $settings['message'] : __( 'Your current cart is eligible to redeem deals.', 'advanced-coupons-for-woocommerce-free' );
            $message     = str_replace( array( '{acfw_bogo_remaining_deals_quantity}', '{acfw_bogo_coupon_code}' ), array( $allowed_quantity, $coupon->get_code() ), $message );
            $notice_type = isset( $settings['notice_type'] ) && $settings['notice_type'] ? $settings['notice_type'] : 'notice';
            $button_url  = isset( $settings['button_url'] ) && $settings['button_url'] ? $settings['button_url'] : get_permalink( wc_get_page_id( 'shop' ) );
            $button_text = isset( $settings['button_text'] ) && $settings['button_text'] ? $settings['button_text'] : __( 'View Deals', 'advanced-coupons-for-woocommerce-free' );
            $notice_text = sprintf( '<span class="acfw-bogo-notice-text">%s <a href="%s" class="button">%s</a></span>', $message, $button_url, $button_text );

            $this->_calculation->add_notice( $notice_text, $notice_type, $coupon->get_code() );
        }
    }

    /**
     * Get eligible notices WC Blocks parsed htmlentities.
     *
     * @since 4.6.0
     * @since 4.6.1 Call reget_bogo_coupon_codes() to ensure that the coupon codes are up-to-date.
     * @access public
     *
     * @return array BOGO eligible notices WC Blocks.
     */
    public function get_eligible_deal_notices_message_wc_blocks() {
        $notices_wc_blocks = array();

        // Return empty array if calculation is not set.
        if ( ! $this->_calculation instanceof Calculation ) {
            return $notices_wc_blocks;
        }

        // This function is called in the Store API, not in the wc hooks. Therefore, it is important to call get_bogo_deals_from_cart.
        $bogo_deals = $this->_calculation->get_bogo_deals_from_cart();

        // Checking if there are any BOGO coupon codes applied.
        if ( ! empty( $bogo_deals ) ) {
            foreach ( $this->_calculation->get_notices() as $notice ) {
                $notices_wc_blocks[] = htmlentities( $notice['message'] );
            }
        }

        return $notices_wc_blocks;
    }

    /**
     * Display eligible for deals notices.
     *
     * @since 1.4
     * @access private
     */
    private function _display_eligible_deal_notices() {
        if ( ! $this->_is_display_notice() ) {
            return;
        }

        foreach ( $this->_calculation->get_notices() as $notice ) {
            wc_add_notice(
                $notice['message'],
                $notice['type'],
                array(
                    'acfw-bogo' => true,
                    'coupon'    => $notice['coupon_code'],
                )
            );
        }
    }

    /**
     * Remove all eligible for deals notices.
     *
     * @since 1.4
     * @access private
     */
    private function _remove_eligible_for_deals_notices() {
        $all_notices = wc_get_notices();

        if ( empty( $all_notices ) ) {
            return;
        }

        foreach ( $all_notices as $notice_type => $notices ) {
            $all_notices[ $notice_type ] = array_filter(
                $notices,
                function ( $n ) {
                return ! isset( $n['data']['acfw-bogo'] );
                }
            );
        }

        wc_set_notices( $all_notices );
    }

    /**
     * Display discounted price on cart price column.
     *
     * @since 1.0
     * @access public
     *
     * @param string $price_html Item price.
     * @param array  $item       Cart item data.
     * @return string Filtered item price.
     */
    public function display_discounted_price( $price_html, $item ) {
        // In "coupon" discount application mode item prices are not modified, so the
        // price column should display the normal product price.
        if ( $this->is_coupon_amount_mode() ) {
            return $price_html;
        }

        $key               = $item['key'];
        $data              = isset( $this->_price_display[ $key ] ) ? $this->_price_display[ $key ] : array();
        $discounted_prices = isset( $data['discounted_prices'] ) ? $data['discounted_prices'] : array();

        if ( ! empty( $discounted_prices ) ) {

            // show price for undiscounted quantity.
            $undiscounted_quantity = $item['quantity'] - array_sum( array_column( $discounted_prices, 'quantity' ) );
            $price_html            = $undiscounted_quantity > 0 ? sprintf( '<span class="acfw-undiscounted-price">%s × %s</span><br />', wc_price( $data['price']['buy'] ), $undiscounted_quantity ) : '';

            // create separate line for discount and its relative quantity.
            $per_coupon_price = array_map(
                function ( $dp ) use ( $data ) {
                return sprintf( '<span class="acfw-bogo-discounted-price">%s × %s</span>', wc_price( $data['price']['regular'] - $dp['discount'] ), $dp['quantity'] );
                },
                $discounted_prices
            );

            $price_html .= implode( '<br />', $per_coupon_price );
        }

        return $price_html;
    }

    /**
     * Get BOGO discounts summary for a coupon.
     *
     * @since 4.6.0
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return string BOGO discounts summary.
     */
    public function get_bogo_discount_summary_for_coupon( $coupon ) {
        if ( 'acfw_bogo' !== $coupon->get_discount_type() ) {
            return '';
        }

        $discounts = $this->calculate_bogo_discounts_for_coupon( $coupon->get_code() );
        $template  = '<li><span class="label">%s x %s:</span> <span class="discount">%s</span></li>';
        $summary   = '';

        foreach ( $discounts as $discount ) {
            $item = $this->_helper_functions->get_cart_item( $discount['key'] );

            // Skip if the item is not found in the cart.
            if ( empty( $item ) || ! isset( $item['data'] ) ) {
                continue;
            }

            $summary .= sprintf( $template, $item['data']->get_name(), $discount['quantity'], wc_price( $discount['total'] * -1 ) );
        }

        return $summary ? sprintf( '<ul class="acfw-bogo-summary %s-bogo-summary" style="margin: 10px;">%s</ul>', $coupon->get_code(), $summary ) : '';
    }

    /**
     * Display BOGO discounts summary on the coupons cart total row.
     *
     * @since 1.0
     * @access public
     *
     * @param string    $coupon_html Coupon row html.
     * @param WC_Coupon $coupon      Coupon object.
     * @param string    $discount_amount_html      Discount amount html.
     * @return string Filtered Coupon row html.
     */
    public function display_bogo_discount_summary( $coupon_html, $coupon, $discount_amount_html ) {
        if ( ! is_array( $this->_price_display ) || empty( $this->_price_display ) ) {
            return $coupon_html;
        }

        // get coupon raw discount amount.
        $amount  = \WC()->cart->get_coupon_discount_amount( $coupon->get_code(), WC()->cart->display_cart_ex_tax );
        $summary = $this->get_bogo_discount_summary_for_coupon( $coupon );

        // remove coupon discount amount display if value is 0.
        if ( 0 === $amount ) {
            $coupon_html = str_replace( $discount_amount_html, '', $coupon_html );
        }

        return $coupon_html .= $summary;
    }

    /**
     * Add BOGO discounts summary to cart/checkout block.
     *
     * @since 4.6.0
     * @access public
     *
     * @param string     $summary Summary content.
     * @param \WC_Coupon $coupon Coupon object.
     * @return string
     */
    public function add_bogo_discount_summary_to_cart_checkout_block( $summary, $coupon ) {
        $summary .= $this->get_bogo_discount_summary_for_coupon( $coupon );
        return $summary;
    }

    /**
     * Check if BOGO Deals discounts should be applied to the coupon amount instead of
     * modifying the deal item prices.
     *
     * @since 4.7.6
     * @access public
     *
     * @return bool True when the "coupon amount" discount application mode is selected.
     */
    public function is_coupon_amount_mode() {
        return 'coupon' === $this->_helper_functions->get_discount_application_mode( 'bogo_deals' );
    }

    /**
     * Register the BOGO coupon type as a cart coupon type in "coupon" discount application mode.
     *
     * `WC_Discounts::get_items_to_apply_coupon()` only passes cart items to a coupon when
     * the coupon is valid for the product or valid for the cart. Custom coupon types fail
     * both checks by default, which would prevent `apply_coupon_custom()` from ever calling
     * `WC_Coupon::get_discount_amount()` for BOGO coupons. Registering `acfw_bogo` as a cart
     * coupon type makes all cart items eligible (mirrors the Cashback coupon type approach);
     * items without matched deal entries simply receive a zero discount.
     *
     * @since 4.7.6
     * @access public
     *
     * @param array $types Cart coupon types.
     * @return array Filtered cart coupon types.
     */
    public function register_bogo_cart_coupon_type( $types ) {
        if ( $this->is_coupon_amount_mode() && ! in_array( 'acfw_bogo', $types, true ) ) {
            $types[] = 'acfw_bogo';
        }

        return $types;
    }

    /**
     * Deliver BOGO deal discounts through the native coupon discount amount in
     * "coupon" discount application mode.
     *
     * Runs on `woocommerce_coupon_get_discount_amount` during `WC_Discounts::apply_coupon_custom()`.
     * The discount per unit is calculated from the same matched deal entries and price basis as
     * price modification mode, so totals are identical between the two modes. WooCommerce then
     * natively handles the item tax bases, the cart coupon discount totals, and the order
     * coupon line amounts.
     *
     * Notes:
     * - `apply_coupon_custom()` calls this with `$single = true` (per-unit price) and multiplies
     *   the result by the item quantity, so the matched entries' total is averaged across the
     *   full line quantity (deal entries may cover only part of the line).
     * - Price-increasing overrides (override price above the product price) cannot be expressed
     *   as a coupon discount, so entry discounts are clamped at zero.
     * - Order recalculations pass order items instead of cart items and are intentionally
     *   ignored (the persisted order coupon line and meta are the durable record).
     *
     * @since 4.7.6
     * @access public
     *
     * @param float      $discount           Discount amount.
     * @param float      $discounting_amount Amount the coupon is being applied to.
     * @param array|null $cart_item          Cart item data (order item object during order recalculation).
     * @param bool       $single             True if the discount is being applied to a single qty.
     * @param \WC_Coupon $coupon             Coupon object.
     * @return float Filtered discount amount.
     */
    public function filter_bogo_coupon_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
        if (
            ! $this->is_coupon_amount_mode()
            || ! $coupon instanceof \WC_Coupon
            || 'acfw_bogo' !== $coupon->get_discount_type()
            || ! is_array( $cart_item )
            || ! isset( $cart_item['key'], $cart_item['data'], $cart_item['quantity'] )
            || ! $cart_item['data'] instanceof \WC_Product
        ) {
            return $discount;
        }

        if ( ! $this->_calculation instanceof Calculation ) {
            $this->_calculation = Calculation::get_instance();
        }

        $coupon_code = $coupon->get_code();
        $deals       = array_filter(
            $this->_calculation->get_entries_by_cart_item( $cart_item['key'], 'deal' ),
            function ( $entry ) use ( $coupon_code ) {
                return $entry['coupon'] === $coupon_code;
            }
        );

        if ( empty( $deals ) ) {
            return $discount;
        }

        // Use the same price basis as price modification mode (the "regular" price basis,
        // subject to the always-use-regular-price option) so both modes charge the customer
        // the same amount for each deal unit.
        $price_basis = $this->_helper_functions->get_price( $cart_item['data'], array( 'cart_item' => $cart_item ) );

        // Per-unit price the customer is actually being discounted against. The price basis
        // can differ from it (e.g. regular price basis vs. sale price on the line), so the
        // coupon discount per unit is the difference between the current unit price and the
        // unit price that price modification mode would have charged (basis - deal discount).
        $current_unit_price = $single ? (float) $discounting_amount : (float) $discounting_amount / max( 1, (int) $cart_item['quantity'] );
        $total              = 0.0;

        foreach ( $deals as $deal ) {
            $deal_discount  = (float) \ACFWF()->Helper_Functions->calculate_discount_by_type( $deal['discount_type'], $deal['discount'], $price_basis );
            $new_unit_price = max( 0.0, $price_basis - $deal_discount );
            $total         += max( 0.0, $current_unit_price - $new_unit_price ) * $deal['quantity'];
        }

        if ( 0.0 >= $total ) {
            return $discount;
        }

        // Average the matched entries' total across the full line quantity, as WooCommerce
        // multiplies the per-unit discount by the item quantity.
        return $single ? $total / max( 1, (int) $cart_item['quantity'] ) : $total;
    }

    /**
     * Save bogo discounts to order.
     *
     * @since 1.0
     * @since 4.3.3 Save total calculated BOGO discount to coupon line item meta.
     * @access public
     *
     * @param int       $order_id    Order id.
     * @param array     $posted_data Order posted data.
     * @param \WC_Order $order       Order object.
     */
    public function save_bogo_discounts_to_order( $order_id, $posted_data, $order ) {
        if ( ! is_array( $this->_price_display ) || empty( $this->_price_display ) ) {
            return;
        }

        // save overall BOGO discounts data to the order meta.
        $order->update_meta_data( Plugin_Constants::ORDER_BOGO_DISCOUNTS, array_values( $this->_price_display ) );
        $order->save_meta_data();

        // In "coupon" discount application mode the BOGO discount is already part of the
        // native order coupon line discount. Skip the extra meta so downstream consumers
        // (edit order coupon value display, extra discount totals) don't count it twice.
        if ( ! $this->is_coupon_amount_mode() ) {

            $order_coupons = $order->get_items( 'coupon' );

            foreach ( $order_coupons as $order_coupon ) {
                $discounts = $this->calculate_bogo_discounts_for_coupon( $order_coupon->get_code() );

                // calculate the total discount via BOGO for coupon.
                $bogo_discount = array_reduce(
                    $discounts,
                    function ( $c, $d ) {
                        return $c + $d['amount'];
                    },
                    0.0
                );

                // save BOGO total discount to the coupon line item meta.
                $order_coupon->update_meta_data( Plugin_Constants::ORDER_COUPON_BOGO_DISCOUNT, $bogo_discount );
                $order_coupon->save_meta_data();
            }
        }

        // clear session data.
        Calculation::clear_session_data();
    }

    /*
    |--------------------------------------------------------------------------
    | Utility Functions
    |--------------------------------------------------------------------------
     */

    /**
     * Only show notice when a new coupon is being applied or when loading the cart or checkout fragments refresh.
     *
     * @since 1.4
     * @access private
     *
     * @return bool
     */
    private function _is_display_notice() {
        // don't display notice on stripe cart details check.
        if ( isset( $_REQUEST['wc-ajax'] ) && 'wc_stripe_get_cart_details' === $_REQUEST['wc-ajax'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }

        return $this->_helper_functions->is_cart() || $this->_helper_functions->is_checkout_fragments();
    }

    /**
     * Display BOGO eligible deal notices on classic cart pages.
     *
     * Reads notices from the BOGO session data and adds them to the WooCommerce notice
     * queue before WooCommerce outputs all notices (priority 10). This ensures BOGO
     * notices appear immediately on the current page request for classic cart and
     * without relying solely on the woocommerce_before_calculate_totals
     * timing which may fire after notices have already been printed.
     *
     * @since 4.7.2
     * @access public
     */
    public function display_bogo_notices_on_classic_pages() {
        if ( ! $this->_helper_functions->is_module( Plugin_Constants::BOGO_DEALS_MODULE ) ) {
            return;
        }

        // Check if a BOGO notice is already in the WC notice queue to avoid duplicates.
        foreach ( wc_get_notices() as $notices ) {
            foreach ( $notices as $notice ) {
                if ( isset( $notice['data']['acfw-bogo'] ) ) {
                    return;
                }
            }
        }

        // Read notices from the BOGO session data set during the most recent calculation.
        $session_data = \WC()->session ? \WC()->session->get( 'acfw_bogo_entries' ) : null;
        if ( ! is_array( $session_data ) || empty( $session_data['notices'] ) || ! is_array( $session_data['notices'] ) ) {
            return;
        }

        $allowed_notice_types = array( 'error', 'success', 'notice', 'info' );

        foreach ( $session_data['notices'] as $notice ) {
            if ( ! is_array( $notice ) || ! isset( $notice['message'], $notice['type'] ) || ! is_string( $notice['message'] ) ) {
                continue;
            }

            $type = in_array( $notice['type'], $allowed_notice_types, true ) ? $notice['type'] : 'notice';

            wc_add_notice(
                $notice['message'],
                $type,
                array(
                    'acfw-bogo' => true,
                    'coupon'    => $notice['coupon_code'] ?? '',
                )
            );
        }
    }

    /**
     * Calculate BOGO discounts for a coupon.
     *
     * @since 4.5.8
     * @access public
     *
     * @param string $coupon_code Coupon code.
     * @return array BOGO discounts.
     */
    public function calculate_bogo_discounts_for_coupon( $coupon_code ) {
        if ( ! $this->_calculation instanceof Calculation ) {
            $this->_calculation = Calculation::get_instance();
        }

        $deals     = $this->_calculation->get_entries_by_coupon( $coupon_code, 'deal' );
        $discounts = array();

        foreach ( $deals as $deal ) {
            $data              = isset( $this->_price_display[ $deal['key'] ] ) ? $this->_price_display[ $deal['key'] ] : array();
            $discounted_prices = isset( $data['discounted_prices'] ) ? $data['discounted_prices'] : array();

            $price = $data['price']['regular'];
            // Change displayed price when setting tax is set to yes and tax display cart is excl.
            if ( \wc_tax_enabled() && 'yes' === get_option( 'woocommerce_prices_include_tax' ) && 'excl' === get_option( 'woocommerce_tax_display_cart' ) ) {
                $price = $data['price']['buy'];
            }

            // calculate total discount value for matched deal item, by looping on all applied discount prices.
            $amount = \ACFWF()->Helper_Functions->calculate_discount_by_type( $deal['discount_type'], $deal['discount'], $price );

            // In "coupon" discount application mode report the amount actually attributed to
            // the coupon (difference between the item's natural unit price and the unit price
            // that price modification mode would have charged) so the summary rows add up to
            // the coupon line total.
            if ( $this->is_coupon_amount_mode() ) {
                $cart_item = $this->_helper_functions->get_cart_item( $deal['key'] );

                if ( ! empty( $cart_item ) && isset( $cart_item['data'] ) ) {
                    $amount = max( 0.0, (float) $cart_item['data']->get_price() - max( 0.0, (float) $price - $amount ) );
                }
            }

            $total = $amount * $deal['quantity'];

            /**
             * If discount is negative, it means that the new price is greater than the regular price.
             * This happens when the price deal is using override to increase the price instead of a discount.
             * So we don't show this in the discount summary.
             */
            if ( empty( $data ) || 0 >= $total || $data['new_price'] >= $data['price'] ) {
                continue;
            }

            $discounts[] = array(
                'key'      => $deal['key'],
                'amount'   => $amount,
                'quantity' => $deal['quantity'],
                'total'    => $total,
            );
        }

        return $discounts;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Prevent WooPayments Multi-Currency from re-converting BOGO-adjusted prices.
     *
     * When BOGO sets a blended per-item price via set_price(), the value is already
     * expressed in the customer's active currency (because get_price() returns the
     * converted value when WooPayments filters are active). Allowing WooPayments to
     * convert the stored price a second time produces wrong cart totals in non-base
     * currencies. This callback returns false (skip conversion) for any product that
     * has been stamped with the BOGO_LOCKED_PRICE_META_KEY meta flag.
     *
     * @since 4.6.8
     * @access public
     *
     * @param bool       $should_convert Whether the price should be converted.
     * @param WC_Product $product        The product being evaluated.
     *
     * @return bool False when BOGO has already set the price; original value otherwise.
     */
    public function skip_bogo_price_conversion( bool $should_convert, $product ): bool {
        if ( ! $should_convert ) {
            return false;
        }
        // If BOGO has locked a price onto this product object, tell WooPayments to skip
        // conversion — the stored value is already in the customer's active currency.
        return '' === $product->get_meta( self::BOGO_LOCKED_PRICE_META_KEY, true );
    }

    /**
     * Restore the BOGO-intended price after WooPayments Multi-Currency converts it.
     *
     * WooPayments hooks woocommerce_product_get_price at priority 99 and treats the stored
     * value as base-currency, converting it to the active currency. When BOGO has already
     * set a price that is in the active currency (blended or reset), that conversion is
     * wrong. This callback runs at priority 100 (after WooPayments) and overrides the
     * converted value with the correct active-currency price that BOGO stored.
     *
     * Works alongside skip_bogo_price_conversion() as a safety net: even if the
     * WooPayments should_convert filter doesn't fire, this restores the right value.
     *
     * @since 4.6.8
     * @access public
     *
     * @param mixed      $price   The price returned by the previous filter in the chain.
     * @param WC_Product $product The product being evaluated.
     *
     * @return mixed The stored BOGO price when available; otherwise the unchanged $price.
     */
    public function restore_bogo_set_price( $price, $product ) {
        $locked = $product->get_meta( self::BOGO_LOCKED_PRICE_META_KEY, true );
        if ( is_numeric( $locked ) ) {
            return (float) $locked;
        }
        return $price;
    }

    /**
     * Execute Frontend class.
     *
     * @since 1.4
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! $this->_helper_functions->is_module( Plugin_Constants::BOGO_DEALS_MODULE ) ) {
            return;
        }

        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'restrict_cart_to_only_one_bogo_deal' ), 10, 2 );
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'implement_bogo_deals' ), apply_filters( 'acfw_bogo_implementation_priority', 11 ) );
        add_filter( 'woocommerce_cart_item_price', array( $this, 'display_discounted_price' ), 10, 2 );
        // "Coupon amount" discount application mode: deliver BOGO discounts through the
        // native coupon discount amount instead of modifying deal item prices.
        add_filter( 'woocommerce_cart_coupon_types', array( $this, 'register_bogo_cart_coupon_type' ) );
        add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'filter_bogo_coupon_discount_amount' ), 10, 5 );
        add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'display_bogo_discount_summary' ), 10, 3 );
        add_filter( 'acfwf_cart_checkout_block_coupon_summary', array( $this, 'add_bogo_discount_summary_to_cart_checkout_block' ), 10, 2 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_bogo_discounts_to_order' ), 10, 3 );
        add_action( 'woocommerce_before_cart', array( $this, 'display_bogo_notices_on_classic_pages' ), 9 );
        // Prevent WooPayments Multi-Currency from double-converting BOGO-set prices.
        // skip_bogo_price_conversion (priority 50) asks WooPayments to skip conversion.
        // restore_bogo_set_price (priority 100) acts as a safety net: if WooPayments
        // converts anyway, we override the result with the correct active-currency value.
        add_filter( 'wcpay_multi_currency_should_convert_product_price', array( $this, 'skip_bogo_price_conversion' ), 50, 2 );
        add_filter( 'woocommerce_product_get_price', array( $this, 'restore_bogo_set_price' ), 100, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'restore_bogo_set_price' ), 100, 2 );
    }
}
