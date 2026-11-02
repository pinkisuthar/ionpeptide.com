<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Auto_Apply module.
 *
 * @since 2.0
 */
class Auto_Apply extends Base_Model implements Model_Interface, Initiable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Coupon base url.
     *
     * @since 2.0
     * @access private
     * @var string
     */
    private $_coupon_base_url;

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
    | Auto_Apply implementation
    |--------------------------------------------------------------------------
     */

    /**
     * Auto apply single coupon.
     *
     * @since 2.0
     * @since 3.3.1 use correct function to add coupon to cart.
     * @access public
     *
     * @param Advanced_Coupon $coupon    Advanced coupon object.
     * @param WC_Discounts    $discounts WooCommerce discounts object.
     * @return bool True if coupon was applied, false otherwise.
     */
    private function _auto_apply_single_coupon( $coupon, $discounts ) {
        if ( ! $this->_validate_auto_apply_coupon( $coupon ) ) {
            return false;
        }

        // check if coupon is valid.
        $check = $discounts->is_coupon_valid( $coupon );

        if ( is_wp_error( $check ) ) {
            do_action( 'acfw_auto_apply_coupon_invalid', $coupon, $check );
            return false;
        }

        // clear notices for previous coupons in loop if current coupon is individual use (prevent multiple coupon applied notices).
        if ( $coupon->get_individual_use() ) {
            wc_clear_notices();
        }

        // apply the coupon.
        return $this->add_coupon_to_cart( $coupon );
    }

    /**
     * Add coupon to cart.
     * Optimized so WC won't need to create a new WC_Coupon object when trying to apply the coupon.
     *
     * @since 2.6
     * @since 3.3 Make function public.
     * @access public
     *
     * @param Advanced_Coupon $coupon Coupon object.
     * @return bool True if applied, false otherwise.
     */
    public function add_coupon_to_cart( $coupon ) {
        if ( \WC()->cart->has_discount( $coupon->get_code() ) ) {
            return false;
        }

        $applied_coupons   = \WC()->cart->get_applied_coupons();
        $applied_coupons[] = $coupon->get_code();

        \WC()->cart->set_applied_coupons( $applied_coupons );

        // add success apply coupon notice.
        if ( ! apply_filters( 'acfw_hide_auto_apply_coupon_success_notice', false, $coupon ) ) {
            $coupon->add_coupon_message( 200 );
        }

        // run hooks after coupon is applied.
        do_action( 'woocommerce_applied_coupon', $coupon );

        return true;
    }

    /**
     * Validate coupon for auto apply.
     *
     * Usage limits and the "Allowed emails" restriction are enforced by
     * `WC_Discounts::is_coupon_valid()` at cart time, so they no longer block auto apply here.
     *
     * The one exception is the per-user usage limit for a guest, and the guard below is the ONLY
     * thing enforcing it — do not "simplify" it away. Core's
     * `validate_coupon_user_usage_limit()` returns early when there is no user ID. On the classic
     * checkout the only fallback is `WC_Cart::check_customer_coupons()` on
     * `woocommerce_after_checkout_validation`, which adds an error notice but does NOT remove the
     * coupon, so the guest is hard-blocked at "Place order" with no way to clear it. On the block
     * checkout there is no fallback at all — the Store API never fires
     * `woocommerce_after_checkout_validation` — so the guest could redeem the coupon without limit.
     * Both outcomes are worse than simply not auto applying it (#1310).
     *
     * @since 2.0
     * @since 4.1 Allow auto apply for coupons with usage limits and allowed emails, except for the
     *             per-user usage limit on a guest shopper (#1310).
     * @access private
     *
     * @param WC_Coupon $coupon WooCommerce coupon object.
     * @return bool True if valid, false otherwise.
     */
    private function _validate_auto_apply_coupon( $coupon ) {
        if ( ! $coupon->get_id() || get_post_status( $coupon->get_id() ) !== 'publish' ) {
            return false;
        }

        // Disable auto apply for guests on coupons limited per user, as core can only enforce that
        // limit once the shopper is identified.
        if ( $coupon->get_usage_limit_per_user() && ! get_current_user_id() ) {
            return false;
        }

        return true;
    }

    /**
     * Implement auto apply coupons.
     *
     * @since 2.0
     * @since 2.4.2 Add individual use condition.
     * @since 4.0.5 Enhanced individual use coupon validation with case-insensitive comparison.
     * @since 4.0.9 Run on `woocommerce_before_calculate_totals` and suppress the re-entrant
     *              `calculate_totals()` recalc to avoid zeroing dynamically priced products (#1450).
     * @since 4.0.10 Re-validate auto-applied coupons and remove invalidated ones before applying
     *               new auto-apply coupons, so they can interchange in a single request (#1198).
     * @access public
     */
    public function implement_auto_apply_coupons() {
        // Disable hide coupon field in cart filter so it won't prevent auto apply.
        remove_filter( 'woocommerce_coupons_enabled', array( \ACFWF()->URL_Coupons, 'hide_coupon_fields' ) );

        /**
         * Detach WC core's `calculate_totals()` from `woocommerce_applied_coupon` while we apply
         * the auto-apply coupons. `add_coupon_to_cart()` still fires `woocommerce_applied_coupon`
         * (so integrations such as BOGO Add-Products and Force Apply keep working), but it no
         * longer triggers a re-entrant `calculate_totals()` run from inside
         * `woocommerce_before_calculate_totals`. The in-progress calculation applies the discounts
         * in a single pass, preserving prices set by other dynamic-pricing plugins (issue #1450).
         *
         * `$suppress_recalc` is only true when THIS invocation actually detached the hook, so a
         * re-entrant call (e.g. a nested `calculate_totals()` fired by another
         * `woocommerce_before_calculate_totals` listener) won't reattach the hook prematurely in
         * its own `finally` while the outer loop is still running.
         */
        $suppress_recalc = ! is_null( \WC()->cart )
            && remove_action( 'woocommerce_applied_coupon', array( \WC()->cart, 'calculate_totals' ), 20 );

        $auto_coupons    = array();
        $applied         = array();
        $removed_coupons = array();

        // Wrap the application logic so the temporarily detached hook/filter are always
        // restored, even if a third-party filter or coupon validation throws (issue #1450).
        try {
            $auto_coupons = apply_filters( 'acfwp_auto_apply_coupons', get_option( $this->_constants->AUTO_APPLY_COUPONS, array() ) );
            $auto_coupons = $this->_filter_auto_apply_coupons_allowed_from_applied_individual_use_coupons( $auto_coupons );

            $individual_use_coupon_applied  = false;
            $individual_use_allowed_coupons = array();

            /**
             * Hook to run before auto apply coupons.
             *
             * @since 4.0.4
             *
             * @param array $auto_coupons List of auto apply coupon IDs.
             */
            do_action( 'acfwp_before_auto_apply_coupons', $auto_coupons );

            // only run when there are coupons to be auto applied and when cart has no individual use coupons already applied.
            if ( is_array( $auto_coupons ) && ! empty( $auto_coupons ) ) {

                $discounts = new \WC_Discounts( \WC()->cart );

                // Seed the subtotal so "cart subtotal" conditions validate correctly here (#1450).
                $this->_seed_cart_subtotal_for_validation( $discounts );

                /**
                 * Re-validate the auto-applied coupons in the cart and drop the ones that are no
                 * longer valid, BEFORE trying to apply new auto-apply coupons — then exclude them
                 * from the apply list so they are not pointlessly re-validated. This lets
                 * auto-apply coupons interchange within a single request (issue #1198).
                 * Requires ACFWF 4.7.6+ for the BOGO case (the "one BOGO coupon" restriction
                 * must read the live applied-coupons list).
                 */
                $removed_coupons = $this->_remove_invalid_auto_applied_coupons( $auto_coupons, $discounts );

                // Normalize to ints so the diff against the removed IDs is an explicit int match.
                $auto_coupons = array_diff( array_map( 'absint', $auto_coupons ), $removed_coupons );

                foreach ( $auto_coupons as $coupon_id ) {

                    if ( get_post_type( $coupon_id ) !== 'shop_coupon' ) {
                        continue;
                    }

                    $coupon      = new Advanced_Coupon( $coupon_id );
                    $coupon_code = $coupon->get_code();

                    // skip if coupon already applied.
                    if ( in_array( $coupon->get_code(), \WC()->cart->get_applied_coupons(), true ) ) {
                        continue;
                    }

                    // Validate coupon.
                    $checked = $discounts->is_coupon_valid( $coupon );

                    // Convert WP_Error to false for consistent boolean handling.
                    if ( is_wp_error( $checked ) ) {
                        /**
                         * Fires when an auto-apply coupon is invalid.
                         *
                         * @since 4.0.6.1
                         * @param WC_Coupon $coupon The coupon object that failed validation.
                         * @param WP_Error  $checked The WP_Error object containing validation error details.
                         */
                        do_action( 'acfw_auto_apply_coupon_invalid', $coupon, $checked );
                        $checked = false;
                    }

                    /**
                     * If an individual-use coupon was already applied,
                     * only allow other coupons that are explicitly allowed alongside it.
                     *
                     * NOTE:
                     * - we need to place this condition after $checked because we need to do invalid coupon check.
                     * - e.g (Cart Conditions: will not be checked if this condition is applied before $checked)
                    */
                    if ( $checked && $individual_use_coupon_applied ) {
                        $allowed_norm = array_map( 'wc_format_coupon_code', (array) $individual_use_allowed_coupons );
                        if ( ! in_array( wc_format_coupon_code( $coupon_code ), $allowed_norm, true ) ) {
                            $checked = false;
                        }
                    }

                    /**
                     * Skip coupon if it's for individual use but there's already another coupon applied on cart.
                     *
                     * NOTE:
                     * - we need to place this condition after $checked because we need to do invalid coupon check.
                     * - e.g (Cart Conditions: will not be checked if this condition is applied before $checked)
                     */
                    if ( $checked && $coupon->get_individual_use() ) {
                        // Check if there are already applied coupons that are not allowed by this individual-use coupon.
                        $allowed_coupons = array_map(
                            'wc_get_coupon_code_by_id',
                            ACFWP()->Allowed_Coupons->get_individual_use_coupon_allowed_coupons( $coupon )
                        );
                        $allowed_norm    = array_map( 'wc_format_coupon_code', $allowed_coupons );
                        $applied_norm    = array_map( 'wc_format_coupon_code', (array) \WC()->cart->get_applied_coupons() );
                        $diff_norm       = array_diff( $applied_norm, $allowed_norm );

                        if ( ! empty( $diff_norm ) ) {
                            $checked = false;
                        }

                        // No conflict, mark this individual-use coupon as applied.
                        $individual_use_coupon_applied  = true;
                        $individual_use_allowed_coupons = $allowed_coupons;
                    }

                    if ( $checked ) {
                        // Auto-apply the coupon.
                        $this->_auto_apply_single_coupon( $coupon, $discounts );

                        // Add coupon to applied coupons list if it's valid and auto applied.
                        $applied[] = $coupon->get_code();
                    }
                }
            }
        } finally {
            // Reattach WC core's `calculate_totals()` to `woocommerce_applied_coupon`.
            if ( $suppress_recalc ) {
                add_action( 'woocommerce_applied_coupon', array( \WC()->cart, 'calculate_totals' ), 20, 0 );
            }

            // Re-enable hide coupon field in cart filter so it won't prevent auto apply.
            add_filter( 'woocommerce_coupons_enabled', array( \ACFWF()->URL_Coupons, 'hide_coupon_fields' ) );
        }

        /**
         * Hook to run after auto apply coupons.
         *
         * @since 4.0.4
         * @since 4.0.10 Add the `$removed_coupons` parameter.
         *
         * @param array $applied         List of coupon codes that were auto applied on this run.
         * @param array $auto_coupons    List of auto apply coupon IDs that remain after the invalid
         *                               ones were removed (see `$removed_coupons`).
         * @param array $removed_coupons List of auto apply coupon IDs that were removed from the cart
         *                               on this run because they were no longer valid.
         */
        do_action( 'acfwp_after_auto_apply_coupons', $applied, $auto_coupons, $removed_coupons );
    }

    /**
     * Seed the cart subtotal so auto-apply cart-condition validation reads a real value.
     *
     * Core resets the cart totals before `woocommerce_before_calculate_totals` (the hook auto-apply
     * runs on), so `get_subtotal()` is 0 and "cart subtotal" conditions never match. Reuse the line
     * prices WooCommerce already computed in `$discounts` instead of recalculating; the following
     * `WC_Cart_Totals` pass overwrites the seeded value (issue #1450).
     *
     * @since 4.0.9
     * @access private
     *
     * @param \WC_Discounts $discounts Discounts object already populated from the current cart.
     */
    private function _seed_cart_subtotal_for_validation( $discounts ) {
        $cart = \WC()->cart;

        // Skip when there is no cart, it's empty, or core already populated the totals.
        if ( is_null( $cart ) || $cart->is_empty() || $cart->get_subtotal() > 0 ) {
            return;
        }

        $subtotal_cents = array_sum( wp_list_pluck( $discounts->get_items(), 'price' ) );
        $cart->set_subtotal( wc_remove_number_precision( $subtotal_cents ) );
    }

    /**
     * Re-validate auto-applied coupons in the cart and drop the ones that are no longer valid.
     *
     * WooCommerce removes invalid applied coupons in `check_cart_coupons()` (hooked to
     * `woocommerce_check_cart_items`) and, on the Store API, in
     * `CartController::validate_cart_coupons()`. Neither runs during the mid-request
     * recalculations (update-cart POST, cart fragments, `update_order_review`), so without this
     * pass a replacement auto-apply coupon whose validity depends on the invalid coupon being
     * gone (e.g. the "only one BOGO coupon allowed" restriction) could only apply on the next
     * page load (issue #1198).
     *
     * Coupons are dropped with `set_applied_coupons()` — the same mechanism as
     * `remove_auto_applied_coupons_from_cart()` — deliberately NOT `remove_coupon()`: this runs
     * inside `woocommerce_before_calculate_totals`, and firing `woocommerce_removed_coupon` from
     * here would trigger a re-entrant `calculate_totals()`, set the `refresh_totals` session
     * flag mid-calculation, and reach removal listeners (e.g. Virtual Coupons) that do not
     * expect to run at this point. Deal/add-product items granted by a dropped coupon are
     * cleaned up by their own `woocommerce_before_calculate_totals` passes (BOGO at priority 11,
     * Add Products at 19), which run after this one (priority 9).
     *
     * Because this pass drops the coupon before WooCommerce gets to it, WooCommerce never shows
     * its own "it has now been removed from your order" notice. This method adds that same notice
     * itself (`WC_Coupon::E_WC_COUPON_INVALID_REMOVED`, the message `check_cart_coupons()` uses),
     * so the shopper still learns the coupon is gone, but only in the contexts
     * `_can_announce_coupon_removal()` allows. The cart-condition reason notice still goes
     * through the existing `acfw_auto_apply_coupon_invalid` pipeline, which honors the coupon's
     * "display notice" setting, notice type, dedupe, and page-context gating.
     *
     * @since 4.0.10
     * @access private
     *
     * @param array         $auto_coupons List of auto apply coupon IDs.
     * @param \WC_Discounts $discounts    WooCommerce discounts object.
     * @return array List of auto apply coupon IDs that were dropped from the cart.
     */
    private function _remove_invalid_auto_applied_coupons( $auto_coupons, $discounts ) {
        $applied_coupons = \WC()->cart->get_applied_coupons();

        if ( empty( $applied_coupons ) ) {
            return array();
        }

        $auto_coupon_ids = array_map( 'absint', (array) $auto_coupons );
        $removed_ids     = array();
        $removed_codes   = array();

        $notify = $this->_can_announce_coupon_removal();

        foreach ( $applied_coupons as $coupon_code ) {

            $coupon_id = wc_get_coupon_id_by_code( $coupon_code );

            // skip coupons that are not registered for auto apply.
            if ( ! $coupon_id || ! in_array( absint( $coupon_id ), $auto_coupon_ids, true ) ) {
                continue;
            }

            $coupon = new Advanced_Coupon( $coupon_id );
            $check  = $discounts->is_coupon_valid( $coupon );

            if ( ! is_wp_error( $check ) ) {
                continue;
            }

            $removed_ids[]   = absint( $coupon_id );
            $removed_codes[] = $coupon_code;

            // tell the shopper the coupon is gone, exactly as WooCommerce would have.
            if ( $notify ) {
                $coupon->add_coupon_message( \WC_Coupon::E_WC_COUPON_INVALID_REMOVED );
            }

            /** This action is documented in Models/Auto_Apply.php (implement_auto_apply_coupons). */
            do_action( 'acfw_auto_apply_coupon_invalid', $coupon, $check );
        }

        if ( ! empty( $removed_codes ) ) {
            \WC()->cart->set_applied_coupons( array_diff( $applied_coupons, $removed_codes ) );
        }

        return $removed_ids;
    }

    /**
     * Check if the current request can show the "coupon has been removed" notice.
     *
     * The notice uses `WC_Coupon::E_WC_COUPON_INVALID_REMOVED` (code 101), so WooCommerce always
     * queues it as an `error` notice. On the Store API, `CartController::validate_cart()` converts
     * every buffered `error` notice into a `WP_Error` and throws a 409 `InvalidCartException`.
     * The cart is valid once the invalid coupon is dropped, so an error notice that reaches that
     * point fails an otherwise good request.
     *
     * Only the Store API checkout routes call `validate_cart()`. The notice is therefore allowed
     * on:
     *
     * - the classic cart and checkout pages, where WooCommerce itself shows it;
     * - the Cart Block page and the Store API cart routes, which never call `validate_cart()`.
     *
     * It is suppressed on the Checkout Block page and on the Store API checkout routes, because a
     * notice queued there is read by the next `validate_cart()` call. The `$removed_coupons`
     * payload passed to `acfwp_after_auto_apply_coupons` stays available for block-aware
     * consumers that want to surface the removal through the cart extension schema.
     *
     * @since 4.0.10
     * @access private
     *
     * @return bool True if the removal notice is safe to queue, false otherwise.
     */
    private function _can_announce_coupon_removal() {
        if ( $this->_helper_functions->is_store_api_request() ) {
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

            return ! str_contains( $request_uri, 'wc/store/v1/checkout' );
        }

        // the checkout block reads queued notices on its next Store API checkout request.
        if ( is_checkout() && $this->_helper_functions->is_cart_block() ) {
            return false;
        }

        return is_cart() || is_checkout() || $this->_helper_functions->is_cart_block();
    }

    /**
     * Filter the auto apply coupons to only list out coupons that are allowed for "individual use" coupons that are already applied in the cart.
     *
     * @since 3.6.2
     * @access private
     *
     * @param array $auto_coupons List of auto apply coupon IDs.
     * @return array Filtered list of auto apply coupon IDs.
     */
    private function _filter_auto_apply_coupons_allowed_from_applied_individual_use_coupons( $auto_coupons ) {
        // Skip if cart has no applied coupons.
        if ( empty( \WC()->cart->get_applied_coupons() ) ) {
            return $auto_coupons;
        }

        $auto_coupons_to_keep = $auto_coupons;
        $has_individual_use   = false;
        $applied_coupons      = array();

        // Check if cart has individual use coupons and filter auto apply coupons when they're not allowed.
        foreach ( \WC()->cart->get_coupons() as $coupon ) {
            $applied_coupons[] = $coupon->get_id();

            if ( $coupon->get_individual_use() ) {
                $has_individual_use   = true;
                $allowed_coupons      = ACFWP()->Allowed_Coupons->get_individual_use_coupon_allowed_coupons( $coupon );
                $auto_coupons_to_keep = array_intersect( $auto_coupons, $allowed_coupons );
            }
        }

        // Remove coupon from auto apply list if the already applied coupons is not allowed for it when it's individual use.
        foreach ( $auto_coupons_to_keep as $coupon_id ) {
            $coupon = new \WC_Coupon( $coupon_id );
            if ( $coupon->get_individual_use() ) {
                $allowed_coupons = ACFWP()->Allowed_Coupons->get_individual_use_coupon_allowed_coupons( $coupon );
                $intersect       = array_intersect( $allowed_coupons, $applied_coupons );

                if ( empty( $intersect ) ) {
                    $auto_coupons_to_keep = array_diff( $auto_coupons_to_keep, array( $coupon_id ) );
                }
            }
        }

        return $has_individual_use ? array_unique( $auto_coupons_to_keep ) : $auto_coupons;
    }

    /**
     * Check if cart already has an individual coupon use applied.
     *
     * @since 2.4.2
     * @access private
     */
    private function _does_cart_have_individual_use_coupon() {
        foreach ( \WC()->cart->get_applied_coupons() as $code ) {
            $coupon = new \WC_Coupon( $code );
            if ( $coupon->get_individual_use() ) {
                return true;
            }
}

        return false;
    }

    /**
     * Force create session when cart is empty and there are coupons to be auto applied.
     *
     * @since 2.4
     * @access public
     */
    public function force_create_cart_session() {
        // function should only run on either cart or checkout pages.
        if ( ! is_cart() && ! is_checkout() ) {
            return;
        }

        $auto_coupons = get_option( $this->_constants->AUTO_APPLY_COUPONS, array() );

        // create session.
        if ( \WC()->cart->is_empty() && is_array( $auto_coupons ) && ! empty( $auto_coupons ) ) {
            \WC()->session->set_customer_session_cookie( true );
        }
    }

    /**
     * Hide the "remove coupon" link in cart totals table for auto applied coupons.
     *
     * @since 2.0
     * @access public
     *
     * @param string    $coupon_html WC coupon cart total table row html markup.
     * @param WC_Coupon $coupon      Current coupon loaded WC_Coupon object.
     * @return string Filtered WC coupon cart total table row html markup.
     */
    public function hide_remove_coupon_link_in_cart_totals( $coupon_html, $coupon ) {
        if ( ! $this->_validate_auto_apply_coupon( $coupon ) ) {
            return $coupon_html;
        }

        $auto_coupons = get_option( $this->_constants->AUTO_APPLY_COUPONS, array() );

        if ( is_array( $auto_coupons ) && ! empty( $auto_coupons ) && in_array( $coupon->get_id(), $auto_coupons, true ) ) {
            $coupon_html = preg_replace( '#<a.*?>.*?</a>#i', '', $coupon_html );
        }

        return $coupon_html;
    }

    /**
     * Clear auto apply cache.
     *
     * @since 2.0
     */
    private function _clear_auto_apply_cache() {
        $previous_ids = get_option( $this->_constants->AUTO_APPLY_COUPONS, array() );
        update_option( $this->_constants->AUTO_APPLY_COUPONS, array() );

        /**
         * Fires after the auto apply coupons cache is cleared or rebuilt.
         * Affected coupon IDs are passed so listeners (e.g. Feature taxonomy)
         * can refresh derived state that depends on this cache.
         *
         * @since 4.0.8
         *
         * @param array  $affected_ids List of coupon IDs whose auto apply state may have changed.
         * @param string $action       Either 'clear' or 'rebuild'.
         */
        do_action( 'acfw_after_auto_apply_cache_changed', (array) $previous_ids, 'clear' );
    }

    /**
     *  Rebuild auto apply cache.
     *
     * @since 2.0
     * @return array List of auto apply coupon ids.
     */
    private function _rebuild_auto_apply_cache() {
        $auto_coupons = get_option( $this->_constants->AUTO_APPLY_COUPONS, array() );
        $verified     = array_filter(
            $auto_coupons,
            function ( $c ) {
            return get_post_type( $c ) === 'shop_coupon' && get_post_status( $c ) === 'publish';
            }
        );

        update_option( $this->_constants->AUTO_APPLY_COUPONS, array_unique( $verified ) );

        $removed = array_diff( (array) $auto_coupons, $verified );
        do_action( 'acfw_after_auto_apply_cache_changed', array_values( $removed ), 'rebuild' );

        return $verified;
    }

    /**
     * Render clear auto apply cache settings field.
     *
     * @since 2.0
     * @access public
     *
     * @param array $value Field value data.
     */
    public function render_rebuild_auto_apply_cache_setting_field( $value ) {
        $spinner_image = $this->_constants->IMAGES_ROOT_URL . 'spinner.gif';

        include $this->_constants->VIEWS_ROOT_PATH . 'settings' . DIRECTORY_SEPARATOR . 'view-render-rebuild-auto-apply-cache-settting-field.php';
    }

    /**
     * AJAX rebuild auto apply cache.
     *
     * @since 2.0
     * @access public
     */
    public function ajax_rebuild_auto_apply_cache() {
        $nonce = sanitize_key( $_POST['nonce'] ?? '' );
        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Invalid AJAX call', 'advanced-coupons-for-woocommerce' ),
            );
        } elseif ( ! $nonce || ! wp_verify_nonce( $nonce, 'acfw_rebuild_auto_apply_cache' ) || ! current_user_can( 'manage_woocommerce' ) ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'You are not allowed to do this', 'advanced-coupons-for-woocommerce' ),
            );
        } else {

            $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
            if ( 'clear' === $type ) {

                $this->_clear_auto_apply_cache();
                $response = array(
                    'status'  => 'success',
                    'message' => __( 'Auto apply coupons cache have been cleared successfully.', 'advanced-coupons-for-woocommerce' ),
                );

            } else {

                $verified = $this->_rebuild_auto_apply_cache();
                $response = array(
                    'status'  => 'success',
                    'message' => sprintf(
                        /* Translators: %s: Count of validated auto apply coupons. */
                        __( 'Auto apply coupons cache has been rebuilt successfully. %s coupon(s) have been validated.', 'advanced-coupons-for-woocommerce' ),
                        count( $verified )
                    ),
                );
            }
        }

        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
        echo wp_json_encode( $response );
        wp_die();
    }

    /**
     * Remove all auto applied coupons from the cart.
     *
     * @since 3.4.1
     * @access public
     */
    public function remove_auto_applied_coupons_from_cart() {
        // Skip if Auto Apply module is disabled. This check is needed as this can be run outside on other modules.
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::AUTO_APPLY_MODULE ) ) {
            return;
        }

        $auto_coupon_ids = apply_filters( 'acfwp_auto_apply_coupons', get_option( $this->_constants->AUTO_APPLY_COUPONS, array() ) );

        // skip if there are no auto apply coupons.
        if ( empty( $auto_coupon_ids ) ) {
            return;
        }

        // get coupon code for all auto applied coupon IDs.
        $auto_coupons = array_map(
            function ( $id ) {
            $coupon = new \WC_Coupon( $id );
            return $coupon->get_code();
            },
            $auto_coupon_ids
        );

        // remove auto apply coupon codes from applied coupons list.
        $applied_coupons = array_diff( \WC()->cart->get_applied_coupons(), $auto_coupons );

        // update applied coupons list in cart.
        \WC()->cart->set_applied_coupons( $applied_coupons );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 2.0
     * @access public
     * @implements ACFWP\Interfaces\Initiable_Interface
     */
    public function initialize() {
        add_action( 'wp_ajax_acfw_rebuild_auto_apply_cache', array( $this, 'ajax_rebuild_auto_apply_cache' ) );
    }

    /**
     * Execute Auto_Apply class.
     *
     * @since 2.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'woocommerce_admin_field_acfw_rebuild_auto_apply_cache', array( $this, 'render_rebuild_auto_apply_cache_setting_field' ) );

        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::AUTO_APPLY_MODULE ) ) {
            return;
        }

        add_action( 'wp', array( $this, 'force_create_cart_session' ) );

        /**
         * Auto apply on `woocommerce_before_calculate_totals` (not `after`) so the auto-apply
         * coupons are present in the applied list before the single `WC_Cart_Totals` pass that
         * computes discounts. This avoids the re-entrant second `calculate_totals()` run that
         * `add_coupon_to_cart()` used to trigger via `woocommerce_applied_coupon`, which fired
         * `woocommerce_before_calculate_totals` a second time and zeroed out line prices set by
         * other dynamic-pricing plugins guarded with `did_action( ... ) >= 2` (issue #1450).
         *
         * Runs early (default priority 9, before BOGO's `implement_bogo_deals` at
         * `acfw_bogo_implementation_priority`, default 11) so an auto-applied Add-Products/BOGO
         * coupon still adds and prices its products within the same pass.
         *
         * @since 4.0.9
         *
         * @param int $priority Hook priority for auto applying coupons.
         */
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'implement_auto_apply_coupons' ), apply_filters( 'acfwp_auto_apply_coupons_priority', 9 ) );
        add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'hide_remove_coupon_link_in_cart_totals' ), 10, 2 );
    }
}
