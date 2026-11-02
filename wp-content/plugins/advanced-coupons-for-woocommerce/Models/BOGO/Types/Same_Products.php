<?php
namespace ACFWP\Models\BOGO\Types;

use ACFWF\Abstracts\Abstract_BOGO_Deal;
use ACFWF\Models\Objects\BOGO\Calculation;
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
 * Model that houses the logic of Same Products BOGO Deals.
 * Public Model.
 *
 * @since 4.0.5
 */
class Same_Products extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that houses the model name to be used when calling publicly.
     *
     * @since 4.0.5
     * @access private
     * @var string
     */
    private $_model_name = 'BOGO_Same_Products';

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.0.5
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
    | Same Products BOGO Integration with Main System
    |--------------------------------------------------------------------------
     */

    /**
     * Prepare Same Products BOGO trigger data.
     *
     * @since 4.0.6
     * @access public
     *
     * @param array              $triggers       Trigger data.
     * @param array              $raw_triggers   Raw trigger data.
     * @param string             $trigger_type   Trigger type.
     * @param Abstract_BOGO_Deal $bogo_deal BOGO Deal object.
     * @return array Prepared trigger data.
     */
    public function prepare_same_products_trigger_data( $triggers, $raw_triggers, $trigger_type, $bogo_deal ) {
        if ( 'same-products' !== $bogo_deal->deal_type ) {
            return $triggers;
        }

        // For same-products, create ONE trigger per product in cart.
        // Each trigger is specific to its product (trigger and deal must be from SAME product).
        // Auto-add feature will handle adding missing quantity if needed.
        $cart_items  = \WC()->cart->get_cart_contents();
        $trigger_qty = absint( $raw_triggers['quantity'] );

        $qualified_ids = $this->_get_trigger_qualified_ids( $bogo_deal );

        foreach ( $cart_items as $cart_item ) {
            if ( ! $this->_is_cart_item_qualified( $cart_item, $qualified_ids ) ) {
                continue;
            }

            $item_id = isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];

            // Create trigger for this product.
            $triggers[] = \ACFWF()->Helper_Functions->format_bogo_trigger_deal_entry(
                array(
                    'ids'      => absint( $item_id ),
                    'quantity' => $trigger_qty,
                )
            );
        }

        return $triggers;
    }


    /**
     * Prepare Same Products BOGO deal data.
     * For same products, the deal items must be the same as trigger items.
     * This means we need to get the trigger items and use them as deal items.
     *
     * @since 4.0.5
     * @access public
     *
     * @param array              $deals     Deal data.
     * @param array              $raw_deals Raw deal data.
     * @param string             $deal_type Deal type.
     * @param Abstract_BOGO_Deal $bogo_deal BOGO Deal object.
     * @return array Prepared Deal data.
     */
    public function prepare_same_products_bogo_deal_data( $deals, $raw_deals, $deal_type, $bogo_deal = null ) {
        if ( 'same-products' !== $deal_type ) {
            return $deals;
        }

        // $bogo_deal is always supplied by the acfw_bogo_advanced_prepare_deal_data filter
        // (registered with 4 args), but guard against a null/legacy invocation before
        // _get_trigger_qualified_ids() dereferences it.
        if ( ! $bogo_deal instanceof Abstract_BOGO_Deal ) {
            return $deals;
        }

        // For same-products, create ONE deal per product in cart.
        // Each deal is specific to its product (trigger and deal must be from SAME product).
        $cart_items = \WC()->cart->get_cart_contents();

        $qualified_ids = $this->_get_trigger_qualified_ids( $bogo_deal );

        foreach ( $cart_items as $cart_item ) {
            if ( ! $this->_is_cart_item_qualified( $cart_item, $qualified_ids ) ) {
                continue;
            }

            $item_id = isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];

            // Create deal for this product.
            $deals[] = \ACFWF()->Helper_Functions->format_bogo_trigger_deal_entry(
                array(
                    'ids'      => absint( $item_id ),
                    'quantity' => $raw_deals['quantity'],
                    'discount' => $raw_deals['discount_value'],
                    'type'     => $raw_deals['discount_type'],
                ),
                true
            );
        }

        return $deals;
    }

    /**
     * Resolve the ACFWP Advanced_Coupon instance for a BOGO deal.
     * $bogo_deal->get_coupon() may return the free ACFWF Advanced_Coupon, which lacks the
     * premium advanced props (e.g. bogo_deals), so re-wrap it as the ACFWP version when needed.
     *
     * @since 4.0.9
     * @access private
     *
     * @param \ACFWF\Abstracts\Abstract_BOGO_Deal $bogo_deal BOGO Deal object.
     * @return Advanced_Coupon Advanced coupon instance.
     */
    private function _resolve_coupon( $bogo_deal ) {
        $coupon = $bogo_deal->get_coupon();

        return $coupon instanceof Advanced_Coupon ? $coupon : new Advanced_Coupon( $coupon );
    }

    /**
     * Get the product/variation IDs that qualify under the Customer BUY (trigger) criteria.
     * For same-products deals the GET product is the BUY product, so both trigger and deal
     * entries must be scoped to cart items matching the trigger criteria — otherwise products
     * outside the configured categories/products get incorrectly duplicated.
     *
     * Returns null when every cart item qualifies (any-products) so callers can skip filtering.
     *
     * @since 4.0.9
     * @access private
     *
     * @param \ACFWF\Abstracts\Abstract_BOGO_Deal $bogo_deal BOGO Deal object.
     * @return array|null Qualifying product/variation IDs, or null when unrestricted.
     */
    private function _get_trigger_qualified_ids( $bogo_deal ) {
        $trigger_type = $bogo_deal->trigger_type;

        // Restricted trigger types resolve to a concrete product/variation ID set below.
        // 'any-products' — and any unknown/custom type registered via the
        // acfw_bogo_trigger_type_options filter — is treated as unrestricted (return null)
        // so an unrecognised type fails open instead of silently producing no triggers/deals.
        if ( ! in_array( $trigger_type, array( 'product-categories', 'combination-products', 'specific-products' ), true ) ) {
            return null;
        }

        $coupon = $this->_resolve_coupon( $bogo_deal );

        // The canonical resolvers for these conditions shapes live in
        // Frontend::prepare_advanced_bogo_trigger_data() (categories/combination) and the
        // free Advanced::_prepare_trigger_data() (specific-products); same-products
        // short-circuits those (Frontend.php:103), so the IDs are re-resolved here. Keep
        // this switch in sync with those resolvers if the conditions shape changes.
        $raw_bogo_deals = $coupon->get_advanced_prop( 'bogo_deals' );
        $conditions     = isset( $raw_bogo_deals['conditions'] ) ? $raw_bogo_deals['conditions'] : array();

        $product_ids = array();

        switch ( $trigger_type ) {
            case 'product-categories':
                $category_ids = isset( $conditions['categories'] ) ? array_column( $conditions['categories'], 'category_id' ) : array();
                foreach ( $category_ids as $category_id ) {
                    $product_ids = array_merge( $product_ids, \ACFWP()->BOGO_Frontend->get_products_under_category( $category_id ) );
                }
                break;

            case 'combination-products':
                $product_ids = isset( $conditions['products'] ) ? array_column( $conditions['products'], 'product_id' ) : array();
                break;

            case 'specific-products':
                $product_ids = array_column( $conditions, 'product_id' );
                break;
        }

        return array_unique( array_map( 'absint', $product_ids ) );
    }

    /**
     * Check whether a cart item qualifies under the given list of trigger product IDs.
     * A null list means unrestricted (any-products) and always qualifies.
     *
     * @since 4.0.9
     * @access private
     *
     * @param array      $cart_item     Cart item data.
     * @param array|null $qualified_ids Qualifying product/variation IDs, or null when unrestricted.
     * @return bool True when the cart item qualifies.
     */
    private function _is_cart_item_qualified( $cart_item, $qualified_ids ) {
        if ( is_null( $qualified_ids ) ) {
            return true;
        }

        $item_ids = array( absint( $cart_item['product_id'] ) );
        if ( ! empty( $cart_item['variation_id'] ) ) {
            $item_ids[] = absint( $cart_item['variation_id'] );
        }

        return ! empty( array_intersect( $item_ids, $qualified_ids ) );
    }

    /**
     * Check if the provided cart item matches Same Products BOGO entries.
     * For same products, we need to ensure trigger and deal are the same product.
     * This only applies to deal entries with 'same-products' type.
     *
     * @since 4.0.5
     * @access public
     *
     * @param int|boolean        $matched    Filter return value.
     * @param array              $cart_item Cart item data.
     * @param array              $entry     Trigger/deal entry.
     * @param boolean            $is_deal   Flag if entry is for deal or not.
     * @param string             $type      Trigger/deal type.
     * @param Abstract_BOGO_Deal $bogo_deal BOGO Deal object.
     * @return int|boolean The cart item compare value if matched, false otherwise.
     */
    public function same_products_bogo_is_cart_item_match_entries( $matched, $cart_item, $entry, $is_deal, $type, $bogo_deal ) {
        // Handle both trigger and deal matching for same-products BOGO deals.
        // Triggers use the trigger_type (e.g., 'product-categories'), but the prepared trigger data
        // already contains resolved product/variation IDs, so we match them the same way as deals.
        if ( 'same-products' !== $bogo_deal->deal_type ) {
            return $matched;
        }

        $ids_to_check = ! empty( $cart_item['variation_id'] )
        ? array( $cart_item['variation_id'], $cart_item['product_id'] ) // should check the parent product.
        : array( $cart_item['product_id'] );

        foreach ( $ids_to_check as $item_id ) {
            $filtered_id = apply_filters( 'acfw_filter_cart_item_product_id', $item_id );
            if ( in_array( $filtered_id, $entry['ids'], true ) ) {
                return $filtered_id;
            }
        }

        return false;
    }

    /**
     * Auto add same products deal to cart.
     * For same-products, we need to ensure product has enough quantity for both trigger AND deal.
     *
     * @since 4.0.6
     * @access public
     *
     * @param Abstract_BOGO_Deal $bogo_deal BOGO deal object.
     */
    public function auto_add_same_products_to_cart( $bogo_deal ) {
        // Only process for same-products with auto-add enabled.
        if ( 'same-products' !== $bogo_deal->deal_type ) {
            return;
        }

        $coupon = $this->_resolve_coupon( $bogo_deal );

        // Check if auto-add is enabled.
        $auto_add_enabled = $coupon->get_advanced_prop( 'bogo_auto_add_products' );

        if ( ! $auto_add_enabled ) {
            return;
        }

        $calculation   = Calculation::get_instance();
        $cart_contents = \ACFWF()->Helper_Functions->sort_cart_items_by_price( \WC()->cart->get_cart(), 'asc' );

        // Remove main implementation hook to prevent infinite loop.
        remove_action( 'woocommerce_before_calculate_totals', array( \ACFWF()->BOGO_Frontend, 'implement_bogo_deals' ), apply_filters( 'acfw_bogo_implementation_priority', 11 ) );

        // Get trigger quantity from RAW coupon data (not from processed triggers which are already decremented).
        $raw_bogo_deals = $coupon->get_advanced_prop( 'bogo_deals' );
        $trigger_qty    = isset( $raw_bogo_deals['conditions']['quantity'] ) ? absint( $raw_bogo_deals['conditions']['quantity'] ) : 1;
        $deal_qty       = isset( $raw_bogo_deals['deals']['quantity'] ) ? absint( $raw_bogo_deals['deals']['quantity'] ) : 1;
        $sorted_deals   = array();
        foreach ( $cart_contents as $cart_item ) {
            $item_id = isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];

            foreach ( $bogo_deal->deals as $deal ) {
                $deal_ids        = isset( $deal['ids'] ) ? (array) $deal['ids'] : array();
                $deal_product_id = is_array( $deal_ids ) ? absint( reset( $deal_ids ) ) : absint( $deal_ids );

                if ( $item_id === $deal_product_id ) {
                    $sorted_deals[] = $deal;
                    break;
                }
            }
        }

        // Auto-add the free quantity for every qualifying product. The "same product free" benefit
        // applies per qualifying product, so each matched cart item needs its own free item added —
        // not only the first. Per-product quantity (a single cycle vs. repeated cycles within one
        // product's quantity) is handled by the is_repeat branch in the loop below.
        $products_to_process = $sorted_deals;

        foreach ( $products_to_process as $deal ) {
            $deal_ids            = isset( $deal['ids'] ) ? (array) $deal['ids'] : array();
            $product_id_to_match = is_array( $deal_ids ) ? absint( reset( $deal_ids ) ) : absint( $deal_ids );

            // Find matching cart item.
            $cart_item = current(
                array_filter(
                    $cart_contents,
                    function ( $i ) use ( $product_id_to_match, $calculation ) {
                        $item_id = isset( $i['variation_id'] ) && $i['variation_id'] ? $i['variation_id'] : $i['product_id'];
                        return $calculation->is_item_valid( $i ) && $item_id === $product_id_to_match;
                    }
                )
            );

            // Calculate how many items to auto-add to complete a trigger+deal cycle.
            $cycle_size  = $trigger_qty + $deal_qty;
            $current_qty = ! empty( $cart_item ) ? absint( $cart_item['quantity'] ) : 0;

            if ( $cycle_size <= 0 ) {
                $qty_to_add = 0;
            } elseif ( $bogo_deal->is_repeat && $current_qty >= $cycle_size ) {
                // Repeat mode past the first cycle: the cart already has >=1 complete cycle,
                // so only complete the partial trailing cycle if it has enough trigger items.
                // E.g. qty=3 with buy-1-get-1: one complete cycle uses 2, leaving 1 trigger
                // item → auto-add 1 deal item → qty=4.
                $remainder  = $current_qty % $cycle_size;
                $qty_to_add = ( $remainder >= $trigger_qty ) ? ( $cycle_size - $remainder ) : 0;
            } else {
                // Non-repeat, or repeat mode below one cycle: fill up to one complete cycle.
                $qty_to_add = max( 0, $cycle_size - $current_qty );
            }

            // Add missing quantity to cart.
            if ( $qty_to_add > 0 ) {
                // Get product and variation id values.
                if ( ! empty( $cart_item ) ) {
                    $product_id     = $cart_item['product_id'];
                    $variation_id   = $cart_item['variation_id'];
                    $variation_data = isset( $cart_item['variation'] ) ? $cart_item['variation'] : array();
                } elseif ( 'product_variation' === get_post_type( $product_id_to_match ) ) {
                    $variation_id   = $product_id_to_match;
                    $product_id     = wp_get_post_parent_id( $variation_id );
                    $variation      = wc_get_product( $variation_id );
                    $variation_data = $variation ? $variation->get_variation_attributes() : array();
                } else {
                    $product_id     = $product_id_to_match;
                    $variation_id   = 0;
                    $variation_data = array();
                }

                // Add product to cart.
                $variation_data = apply_filters( 'acfw_bogo_auto_add_product_variation_data', $variation_data, $variation_id );
                $cart_key       = \WC()->cart->add_to_cart( $product_id, $qty_to_add, $variation_id, $variation_data );
                $cart_item      = \WC()->cart->get_cart_item( $cart_key );
            }
        }

        // Re-add implementation hook.
        add_action( 'woocommerce_before_calculate_totals', array( \ACFWF()->BOGO_Frontend, 'implement_bogo_deals' ), apply_filters( 'acfw_bogo_implementation_priority', 11 ) );
    }

    /*
    |--------------------------------------------------------------------------
    | Admin Integration Hooks
    |--------------------------------------------------------------------------
     */

    /**
     * Register apply type same products options.
     *
     * @since 4.0.5
     * @access public
     *
     * @param array $options Field options list.
     * @return array Filtered field options list.
     */
    public function register_apply_type_options( $options ) {
        return array_merge(
            $options,
            array(
                'same-products' => __( 'Same Products', 'advanced-coupons-for-woocommerce' ),
            )
        );
    }

    /**
     * Filter sanitize same products data.
     *
     * @since 4.0.5
     * @access public
     *
     * @param array  $sanitized Sanized data.
     * @param array  $data      Raw data.
     * @param string $type      Data type.
     * @return array Sanitized data.
     */
    public function filter_sanitize_bogo_data( $sanitized, $data, $type ) {
        switch ( $type ) {
            case 'same-products':
                $sanitized = $this->_sanitize_same_products_data( $data );
                break;
        }

        return $sanitized;
    }

    /**
     * Sanitize conditions/deals same products data.
     *
     * @since 4.0.5
     * @access private
     *
     * @param array $data Product data.
     * @return array Sanitized product data.
     */
    private function _sanitize_same_products_data( $data ) {
        if ( ! isset( $data['discount_type'] ) ) { // sanitize trigger data.

            $sanitized = array(
                'quantity' => isset( $data['quantity'] ) && intval( $data['quantity'] ) > 0 ? absint( $data['quantity'] ) : 1,
            );

        } else { // sanitize apply data.

            $sanitized = array(
                'quantity'       => isset( $data['quantity'] ) && intval( $data['quantity'] ) > 0 ? absint( $data['quantity'] ) : 1,
                'discount_type'  => isset( $data['discount_type'] ) ? sanitize_text_field( $data['discount_type'] ) : 'override',
                'discount_value' => isset( $data['discount_value'] ) ? (float) wc_format_decimal( $data['discount_value'] ) : (float) 0,
            );
        }

        return $sanitized;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Same Products class.
     *
     * @since 4.0.5
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::BOGO_DEALS_MODULE ) ) {
            return;
        }

        // Admin integration hooks.
        add_filter( 'acfw_bogo_apply_type_options', array( $this, 'register_apply_type_options' ), 10 );
        add_filter( 'acfw_sanitize_bogo_deals_data', array( $this, 'filter_sanitize_bogo_data' ), 10, 3 );

        // Integrate with main BOGO system for Same Products deals.
        add_filter( 'acfw_bogo_advanced_prepare_trigger_data', array( $this, 'prepare_same_products_trigger_data' ), 10, 4 );
        add_filter( 'acfw_bogo_advanced_prepare_deal_data', array( $this, 'prepare_same_products_bogo_deal_data' ), 10, 4 );
        add_filter( 'acfw_bogo_is_cart_item_match_entries', array( $this, 'same_products_bogo_is_cart_item_match_entries' ), 10, 6 );

        // Auto add same products to cart when conditions are met.
        add_action( 'acfw_bogo_after_verify_trigger_deals', array( $this, 'auto_add_same_products_to_cart' ), 10 );
    }
}
