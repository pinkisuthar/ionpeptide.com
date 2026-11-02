<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Model_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the "Tiered Cart Discount" coupon type.
 * The coupon holds an ordered list of spend thresholds and the cart takes the discount of the
 * highest threshold it reaches. Tiers never stack.
 *
 * NOTE: this is a CART level coupon type. It is registered through "woocommerce_cart_coupon_types"
 * and deliberately NOT through "woocommerce_product_coupon_types", so WooCommerce hands every cart
 * item to the discount filter and the exclusion settings are enforced by this model instead.
 *
 * Public Model.
 *
 * @since 4.1
 */
class Tiered_Cart_Discount extends Base_Model implements Model_Interface, Initiable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Constants
    |--------------------------------------------------------------------------
     */

    /**
     * Discount type key of the tiered cart discount coupon type.
     *
     * @since 4.1
     */
    const DISCOUNT_TYPE = 'acfw_tiered_discount';

    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Request scoped cache of the sanitized tier rows, keyed by coupon ID.
     *
     * @since 4.1
     * @access private
     * @var array
     */
    private $_tiers_cache = array();

    /**
     * Per totals pass cache of the tier every coupon resolved to, keyed by coupon ID and basis
     * object. Flushed on "woocommerce_before_calculate_totals" so the coupon re-tiers live when the
     * cart changes.
     *
     * @since 4.1
     * @access private
     * @var array
     */
    private $_tier_cache = array();

    /**
     * Per totals pass cache of the fixed distribution map, keyed by coupon ID and basis object.
     * Flushed on "woocommerce_before_calculate_totals".
     *
     * @since 4.1
     * @access private
     * @var array
     */
    private $_distribution_cache = array();

    /**
     * Per totals pass cache of the pre coupon items subtotal, keyed by basis object.
     * Flushed on "woocommerce_before_calculate_totals".
     *
     * @since 4.1
     * @access private
     * @var array
     */
    private $_subtotal_cache = array();

    /**
     * Per totals pass cache of the per item eligibility, keyed by coupon ID and item key.
     * Flushed on "woocommerce_before_calculate_totals".
     *
     * @since 4.1
     * @access private
     * @var array
     */
    private $_eligibility_cache = array();

    /**
     * Request scoped cache of the product IDs that are on sale.
     *
     * @since 4.1
     * @access private
     * @var array|null
     */
    private $_sale_ids_cache = null;

    /**
     * Request scoped cache of the product category IDs, keyed by product ID.
     * The eligibility check runs several times per item per totals pass, so the category and
     * ancestor list of a product is only resolved once per request.
     *
     * @since 4.1
     * @access private
     * @var array
     */
    private $_product_cats_cache = array();

    /**
     * Request scoped cache of the coupon objects of the coupons list table, keyed by post ID.
     * Both column callbacks need the same coupon, so it is only loaded once per row.
     *
     * @since 4.1
     * @access private
     * @var array
     */
    private $_list_table_coupons = array();

    /**
     * Output buffer level of the "Coupon amount" column buffer this model opened.
     * Null when no buffer of ours is open.
     *
     * @since 4.1
     * @access private
     * @var int|null
     */
    private $_amount_column_buffer_level = null;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.1
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
    | Coupon type registration
    |--------------------------------------------------------------------------
     */

    /**
     * Register the tiered cart discount coupon type.
     *
     * @since 4.1
     * @access public
     *
     * @param array $types Coupon types.
     * @return array Filtered coupon types.
     */
    public function register_tiered_coupon_type( $types ) {
        $types[ self::DISCOUNT_TYPE ] = __( 'Tiered Cart Discount', 'advanced-coupons-for-woocommerce' );

        return $types;
    }

    /**
     * Register the tiered cart discount coupon type as a cart coupon type.
     * NOTE: this is what makes the type cart level. It is deliberately NOT registered as a product
     * coupon type, so the tier is measured against the whole cart instead of a single line.
     *
     * @since 4.1
     * @access public
     *
     * @param array $coupon_types List of cart coupon types.
     * @return array Filtered list of cart coupon types.
     */
    public function register_tiered_cart_coupon_type( $coupon_types ) {
        $coupon_types[] = self::DISCOUNT_TYPE;

        return $coupon_types;
    }

    /**
     * Display the type label instead of the coupon amount on the coupon's discount value string.
     * The coupon amount is unused by this coupon type so the free plugin's default branch would
     * otherwise render "0".
     *
     * @since 4.1
     * @access public
     *
     * @param string     $message        Discount value string.
     * @param string     $amount         Formatted coupon amount.
     * @param string     $discount_label Discount type label.
     * @param \WC_Coupon $coupon         Coupon object.
     * @return string Filtered discount value string.
     */
    public function filter_coupon_discount_value_string( $message, $amount, $discount_label, $coupon ) {
        if ( $this->is_tiered_coupon( $coupon ) ) {
            $message = __( 'Tiered Cart Discount', 'advanced-coupons-for-woocommerce' );
        }

        return $message;
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
     */

    /**
     * Start buffering the "Coupon amount" column of a tiered coupon on the coupons list.
     * WooCommerce renders that column itself, with no filter of its own, and this coupon type
     * carries no flat coupon amount, so the column would read "0" and hide the whole discount from
     * the store owner. The cell is captured here and replaced on the way out.
     *
     * @since 4.1
     * @access public
     *
     * @param string $column  Column name.
     * @param int    $post_id Coupon ID.
     */
    public function buffer_coupon_amount_column( $column, $post_id ) {
        if ( 'amount' !== $column || ! $this->is_tiered_coupon( $this->_get_list_table_coupon( $post_id ) ) ) {
            return;
        }

        ob_start();

        // remember which level is ours, so the closer can never destroy another plugin's buffer.
        $this->_amount_column_buffer_level = ob_get_level();
    }

    /**
     * Replace the buffered "Coupon amount" column of a tiered coupon with the discount type label.
     * The buffer is only closed when it is still the one this model opened. Anything else means a
     * third party opened or closed a buffer in between, and closing blindly would either destroy
     * their buffer or leave ours open, which would blank the whole coupons list screen.
     *
     * @since 4.1
     * @access public
     *
     * @param string $column  Column name.
     * @param int    $post_id Coupon ID.
     */
    public function replace_coupon_amount_column( $column, $post_id ) {
        if ( 'amount' !== $column || is_null( $this->_amount_column_buffer_level ) ) {
            return;
        }

        $level                             = $this->_amount_column_buffer_level;
        $this->_amount_column_buffer_level = null;

        if ( ob_get_level() !== $level ) {
            return;
        }

        ob_end_clean();

        echo esc_html__( 'Tiered Cart Discount', 'advanced-coupons-for-woocommerce' );
    }

    /**
     * Get the coupon object of a coupons list table row, memoized for the request.
     *
     * @since 4.1
     * @access private
     *
     * @param int $post_id Coupon ID.
     * @return \WC_Coupon Coupon object.
     */
    private function _get_list_table_coupon( $post_id ) {
        $post_id = absint( $post_id );

        if ( ! isset( $this->_list_table_coupons[ $post_id ] ) ) {
            $this->_list_table_coupons[ $post_id ] = new \WC_Coupon( $post_id );
        }

        return $this->_list_table_coupons[ $post_id ];
    }

    /**
     * Display the tier discounts panel on the coupon editor's general tab.
     *
     * @since 4.1
     * @access public
     *
     * @param int $coupon_id Coupon ID.
     */
    public function display_tier_discounts_panel( $coupon_id ) {
        $panel_id      = 'acfw_tier_discounts';
        $coupon        = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );
        $spinner_img   = $this->_constants->IMAGES_ROOT_URL . 'spinner-2x.gif';
        $tiers         = $this->get_tiers_for_editor( $coupon );
        $discount_type = $this->get_tier_discount_type( $coupon );

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-coupon-tier-discounts-data-panel.php';
    }

    /**
     * Get the coupon's tier rows formatted for the editor.
     *
     * @since 4.1
     * @access public
     *
     * @param \ACFWP\Models\Objects\Advanced_Coupon $coupon Advanced coupon object.
     * @return array List of tier rows for the editor.
     */
    public function get_tiers_for_editor( $coupon ) {
        if ( ! $coupon ) {
            return array();
        }

        $discount_type = $this->get_tier_discount_type( $coupon );
        $tiers         = $this->_sanitize_tiers( $coupon->get_advanced_prop( 'tier_discounts', array() ), $discount_type );

        foreach ( $tiers as $index => $tier ) {

            /**
             * Render amounts as prices, the way the other coupon editor tables do. Note that
             * wc_format_localized_price() only swaps the decimal separator, so the value has to be
             * padded to the store's decimal count first for "5" to come back as "5.00".
             * A percentage keeps its own precision, but it still has to go through
             * wc_format_localized_decimal() so a stored 15.0 renders as "15" and not as a raw float.
             */
            $tiers[ $index ]['min_spend'] = wc_format_localized_price( \wc_format_decimal( $tier['min_spend'], wc_get_price_decimals() ) );

            if ( 'fixed' === $discount_type ) {
                $tiers[ $index ]['discount_value'] = wc_format_localized_price( \wc_format_decimal( $tier['discount_value'], wc_get_price_decimals() ) );
            } else {
                $tiers[ $index ]['discount_value'] = wc_format_localized_decimal( \wc_format_decimal( $tier['discount_value'] ) );
            }
        }

        return $tiers;
    }

    /*
    |--------------------------------------------------------------------------
    | Frontend implementation
    |--------------------------------------------------------------------------
     */

    /**
     * Flush the per totals pass caches.
     * WooCommerce recalculates the cart on every change, so the resolved tier and the fixed
     * distribution map must be thrown away before each pass. Without this the coupon would keep
     * paying out the tier it resolved to the first time it was calculated.
     *
     * @since 4.1
     * @access public
     */
    public function flush_calculation_caches() {
        $this->_tier_cache         = array();
        $this->_distribution_cache = array();
        $this->_subtotal_cache     = array();
        $this->_eligibility_cache  = array();
    }

    /**
     * Reject the coupon when the cart does not reach the lowest tier.
     * WC_Cart::check_cart_coupons() re-validates every applied coupon on each recalculation, so this
     * single throw delivers both the apply time error and the self removal notice.
     *
     * @since 4.1
     * @access public
     *
     * @throws \Exception When the cart is below the lowest tier, or the coupon holds no tiers.
     *
     * @param bool          $valid     Coupon validity.
     * @param \WC_Coupon    $coupon    Coupon object.
     * @param \WC_Discounts $discounts Discounts object of the current pass.
     * @return bool Filtered coupon validity.
     */
    public function validate_tier_threshold_reached( $valid, $coupon, $discounts = null ) {
        if ( ! $this->is_tiered_coupon( $coupon ) ) {
            return $valid;
        }

        $tiers = $this->_get_tiers( $coupon );

        if ( empty( $tiers ) ) {
            throw new \Exception( wp_kses_post( __( 'This coupon has no spend tiers set up yet.', 'advanced-coupons-for-woocommerce' ) ) );
        }

        // the discounts object names what is being discounted, so an order recalculation measures
        // the tier against the order and never against the admin's own (empty) cart.
        $context = $this->_get_calculation_context( $discounts );

        if ( is_null( $context['object'] ) ) {
            return $valid;
        }

        $subtotal  = $this->_get_items_subtotal( $context );
        $min_spend = (float) $tiers[0]['min_spend'];

        if ( $subtotal < $min_spend ) {
            throw new \Exception(
                wp_kses_post(
                    sprintf(
                        /* translators: %s: formatted shortfall amount. */
                        __( 'Spend %s more to use this coupon.', 'advanced-coupons-for-woocommerce' ),
                        \wc_price( $min_spend - $subtotal )
                    )
                )
            );
        }

        return $valid;
    }

    /**
     * Calculate the per unit discount amount of a cart item for the tier the cart reached.
     * WooCommerce asks for the single unit amount in the custom coupon pass and multiplies it back
     * by the item quantity itself.
     *
     * @since 4.1
     * @access public
     *
     * @param float                             $discount           Coupon discount amount for the cart item.
     * @param float                             $discounting_amount Amount that needs to be discounted.
     * @param array|\WC_Order_Item_Product|null $cart_item          Cart item data, or the order item on an order recalculation.
     * @param bool                              $single             True if discounting a single qty item, false if it's the line.
     * @param \WC_Coupon                        $coupon             Coupon object.
     * @return float Filtered discount amount.
     */
    public function get_tier_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
        if ( ! $this->is_tiered_coupon( $coupon ) ) {
            return $discount;
        }

        $context = $this->_get_calculation_context( $cart_item );
        $tier    = $this->_resolve_tier( $coupon, $context );

        if ( is_null( $tier ) ) {
            return 0;
        }

        // every item reaches this filter for a cart level type, so the exclusions are ours to enforce.
        if ( ! $this->_is_item_eligible( $cart_item, $coupon ) ) {
            return 0;
        }

        if ( 'percent' === $this->get_tier_discount_type( $coupon ) ) {
            $precise_discount = \wc_add_number_precision( $discounting_amount ) * ( (float) $tier['discount_value'] / 100 );

            return \wc_remove_number_precision( $precise_discount );
        }

        $map = $this->_get_distribution_map( $coupon, $context );
        $key = $this->_get_cart_item_key( $cart_item );

        if ( ! isset( $map[ $key ] ) ) {
            return 0;
        }

        $quantity = max( 1, $this->_get_cart_item_quantity( $cart_item ) );

        // core multiplies the returned per unit amount back by the item quantity.
        return \wc_remove_number_precision( $map[ $key ] ) / $quantity;
    }

    /**
     * Neutralise the "Products" usage restriction include list for this coupon type.
     * This is a cart spend discount with no per product targeting, so the include list is hidden in
     * the editor. WC_Discounts::validate_coupon_product_ids() still runs for every coupon type, so a
     * value left behind by another discount type would otherwise invalidate the whole coupon.
     *
     * @since 4.1
     * @access public
     *
     * @param array      $product_ids List of product IDs.
     * @param \WC_Coupon $coupon      Coupon object.
     * @return array Filtered list of product IDs.
     */
    public function neutralize_product_ids( $product_ids, $coupon ) {
        return $this->is_tiered_coupon( $coupon ) ? array() : $product_ids;
    }

    /**
     * Neutralise the "Product categories" usage restriction include list for this coupon type.
     *
     * @since 4.1
     * @access public
     *
     * @param array      $product_categories List of product category IDs.
     * @param \WC_Coupon $coupon             Coupon object.
     * @return array Filtered list of product category IDs.
     */
    public function neutralize_product_categories( $product_categories, $coupon ) {
        return $this->is_tiered_coupon( $coupon ) ? array() : $product_categories;
    }

    /**
     * Neutralise the "Limit usage to X items" usage limit for this coupon type.
     * The setting is inert for a cart type, and a non null value would make core's apply quantity
     * diverge from the item quantity, which would break the fixed distribution map.
     * NOTE: the woocommerce_coupon_get_apply_quantity filter is the one remaining way a third party
     * can still make core's apply quantity diverge from the item quantity.
     *
     * @since 4.1
     * @access public
     *
     * @param int|null   $limit  Limit usage to X items value.
     * @param \WC_Coupon $coupon Coupon object.
     * @return int|null Filtered limit usage to X items value.
     */
    public function neutralize_limit_usage_to_x_items( $limit, $coupon ) {
        return $this->is_tiered_coupon( $coupon ) ? null : $limit;
    }

    /**
     * Neutralise the "Minimum spend" usage restriction for this coupon type.
     * The tier thresholds replace it, the coupon editor hides it, and a coupon converted from
     * another type can still carry a stale value that would otherwise veto the whole coupon.
     *
     * @since 4.1
     * @access public
     *
     * @param bool       $is_below Whether the cart is below the coupon's minimum spend.
     * @param \WC_Coupon $coupon   Coupon object.
     * @return bool Filtered result.
     */
    public function neutralize_minimum_amount_validation( $is_below, $coupon ) {
        return $this->is_tiered_coupon( $coupon ) ? false : $is_below;
    }

    /**
     * Neutralise WooCommerce's blanket exclusion validation for this coupon type.
     *
     * WC_Discounts::validate_coupon_eligible_items() vetoes a NON product coupon type outright when
     * any cart item is on sale, excluded by ID or excluded by category. This type is a cart type, so
     * that rule would reject the coupon over a single excluded line. An excluded item has to keep
     * counting towards the tier threshold and simply take no discount, which is what
     * _is_item_eligible() enforces line by line, so the blanket getters are emptied here.
     *
     * The stored values stay readable everywhere they are edited: WC_Meta_Box_Coupon_Data::output()
     * reads these props in the "edit" context and WC_Data::get_prop() only applies the
     * woocommerce_coupon_get_* filters in the "view" context, while the REST controller reads
     * get_data() and bypasses the getters altogether. So neither the coupon editor nor the REST API
     * ever sees the neutralized value, and no request context check is needed here.
     *
     * @since 4.1
     * @access public
     *
     * @param mixed      $value  Exclusion setting value.
     * @param \WC_Coupon $coupon Coupon object.
     * @return mixed Filtered exclusion setting value.
     */
    public function neutralize_blanket_exclusion_validation( $value, $coupon ) {
        if ( ! $this->is_tiered_coupon( $coupon ) ) {
            return $value;
        }

        return is_array( $value ) ? array() : false;
    }

    /*
    |--------------------------------------------------------------------------
    | Cart and checkout summary
    |--------------------------------------------------------------------------
     */

    /**
     * Build the summary of the tier a coupon reached, for the cart and the checkout.
     *
     * @since 4.1
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return string Summary markup, or an empty string when no tier is reached.
     */
    public function get_tier_discount_summary_for_coupon( $coupon ) {
        if ( ! $this->is_tiered_coupon( $coupon ) ) {
            return '';
        }

        // the block summary filter makes this method reachable outside the classic cart context,
        // where the cart is not guaranteed to be set up yet.
        if ( is_null( \WC()->cart ) ) {
            return '';
        }

        $tier = $this->_resolve_tier( $coupon, $this->_get_calculation_context() );

        if ( is_null( $tier ) ) {
            return '';
        }

        if ( 'percent' === $this->get_tier_discount_type( $coupon ) ) {
            $value = sprintf(
                /* translators: %s: tier discount percentage. */
                __( '%s%% off', 'advanced-coupons-for-woocommerce' ),
                \wc_format_localized_decimal( $tier['discount_value'] )
            );
        } else {
            $value = \wc_price( (float) $tier['discount_value'] );
        }

        $label = sprintf(
            /* translators: %s: formatted tier spend threshold. */
            __( 'Spend %s or more', 'advanced-coupons-for-woocommerce' ),
            \wc_price( (float) $tier['min_spend'] )
        );

        // WooCommerce echoes this markup unescaped, so every dynamic part is escaped on the way in.
        return sprintf(
            '<ul class="acfw-tier-discount-summary %1$s-tier-discount-summary" style="margin: 10px;"><li><span class="label">%2$s:</span> <span class="discount">%3$s</span></li></ul>',
            sanitize_html_class( $coupon->get_code() ),
            wp_kses_post( $label ),
            wp_kses_post( $value )
        );
    }

    /**
     * Append the tier summary to the coupon row on the classic cart and checkout.
     *
     * @since 4.1
     * @access public
     *
     * @param string     $coupon_html          Coupon row html.
     * @param \WC_Coupon $coupon               Coupon object.
     * @param string     $discount_amount_html Discount amount html.
     * @return string Filtered coupon row html.
     */
    public function display_tier_discount_summary( $coupon_html, $coupon, $discount_amount_html ) {
        $summary = $this->get_tier_discount_summary_for_coupon( $coupon );

        return $summary ? $coupon_html . $summary : $coupon_html;
    }

    /**
     * Append the tier summary to the cart and checkout block.
     *
     * @since 4.1
     * @access public
     *
     * @param string     $summary Cart/checkout block summary.
     * @param \WC_Coupon $coupon  Coupon object.
     * @return string Filtered cart/checkout block summary.
     */
    public function append_tier_discount_summary_to_cart_checkout_block( $summary, $coupon ) {
        return $summary . $this->get_tier_discount_summary_for_coupon( $coupon );
    }

    /*
    |--------------------------------------------------------------------------
    | Utility functions
    |--------------------------------------------------------------------------
     */

    /**
     * Check if the given coupon is of the tiered cart discount type.
     *
     * @since 4.1
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return bool True if the coupon is a tiered cart discount coupon, false otherwise.
     */
    public function is_tiered_coupon( $coupon ) {
        return $coupon instanceof \WC_Coupon && self::DISCOUNT_TYPE === $coupon->get_discount_type();
    }

    /**
     * Get the discount type of the coupon's tiers. The type is uniform for the whole coupon.
     *
     * @since 4.1
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return string Either "percent" or "fixed".
     */
    public function get_tier_discount_type( $coupon ) {
        if ( ! $coupon instanceof \WC_Coupon ) {
            return 'fixed';
        }

        $type = $coupon->get_meta( $this->_constants->META_PREFIX . 'tier_discount_type', true );

        return 'percent' === $type ? 'percent' : 'fixed';
    }

    /**
     * Sanitize a list of tier rows.
     * Rows that cannot be applied are dropped rather than clamped, so a malformed row can never
     * silently discount something it was not meant to. A percentage above 100 is the one exception:
     * it is capped so the cart total can never go negative.
     *
     * @since 4.1
     * @access private
     *
     * @param mixed  $tiers         Raw tier rows.
     * @param string $discount_type Discount type of the coupon's tiers.
     * @return array Sanitized, deduplicated and ascending list of tier rows.
     */
    private function _sanitize_tiers( $tiers, $discount_type = 'fixed' ) {
        if ( ! is_array( $tiers ) ) {
            return array();
        }

        $sanitized  = array();
        $thresholds = array();

        foreach ( $tiers as $tier ) {

            $row = $this->_normalize_tier_row( $tier );

            if ( null === $row ) {
                continue;
            }

            $min_spend      = $row['min_spend'];
            $discount_value = $row['discount_value'];

            // a duplicate threshold is unresolvable, the first row of the pair wins.
            if ( in_array( $min_spend, $thresholds, true ) ) {
                continue;
            }

            if ( 'percent' === $discount_type && 100 < $discount_value ) {
                $discount_value = 100.0;
            }

            $thresholds[] = $min_spend;
            $sanitized[]  = array(
                'min_spend'      => $min_spend,
                'discount_value' => $discount_value,
            );
        }

        // tiers are resolved highest reached first, so a stable ascending order is part of the contract.
        usort(
            $sanitized,
            function ( $a, $b ) {
                return $a['min_spend'] <=> $b['min_spend'];
            }
        );

        return $sanitized;
    }

    /**
     * Get the sanitized tier rows of a coupon.
     *
     * @since 4.1
     * @access private
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return array Ascending list of tier rows.
     */
    private function _get_tiers( $coupon ) {
        $coupon_id = $coupon->get_id();

        if ( ! isset( $this->_tiers_cache[ $coupon_id ] ) ) {
            $this->_tiers_cache[ $coupon_id ] = $this->_sanitize_tiers(
                $coupon->get_meta( $this->_constants->META_PREFIX . 'tier_discounts', true ),
                $this->get_tier_discount_type( $coupon )
            );
        }

        return $this->_tiers_cache[ $coupon_id ];
    }

    /**
     * Resolve what the current discount pass is measuring the tier against.
     *
     * WC_Abstract_Order::recalculate_coupons() re-runs every coupon of an order through
     * WC_Discounts whenever a store owner adds or removes a coupon on the Edit Order screen. In
     * that pass WC_Discounts::set_items_from_order() hands WC_Order_Item_Product objects to the
     * discount filter instead of cart item arrays, and the admin's own cart is empty. Reading the
     * cart there would rewrite every stored line discount to 0.00, so the item set and the tier
     * basis are both taken from whatever object the pass is actually working on.
     *
     * @since 4.1
     * @access private
     *
     * @param mixed $source Cart item array, order item, WC_Discounts object, cart or order.
     * @return array Context array holding the basis object, its items and a cache key.
     */
    private function _get_calculation_context( $source = null ) {
        $object = null;

        if ( $source instanceof \WC_Order_Item_Product ) {
            $object = $source->get_order();
        } elseif ( $source instanceof \WC_Discounts ) {
            $object = $source->get_object();
        } elseif ( $source instanceof \WC_Order || $source instanceof \WC_Cart ) {
            $object = $source;
        }

        if ( ! $object instanceof \WC_Order && ! $object instanceof \WC_Cart ) {
            $object = \WC()->cart instanceof \WC_Cart ? \WC()->cart : null;
        }

        if ( is_null( $object ) ) {
            return array(
                'object'   => null,
                'is_order' => false,
                'items'    => array(),
                'key'      => '0',
            );
        }

        $is_order = $object instanceof \WC_Order;

        return array(
            'object'   => $object,
            'is_order' => $is_order,
            'items'    => $is_order ? $object->get_items() : $object->get_cart_contents(),

            // the per pass caches are keyed by the basis object and not by the coupon alone, so a
            // cart pass and an order pass of the same coupon can never share a tier or a map.
            'key'      => (string) spl_object_id( $object ),
        );
    }

    /**
     * Get the pre coupon items subtotal that the tier is measured against.
     *
     * On a cart pass this is WC_Cart::get_displayed_subtotal(), the very basis WooCommerce's own
     * minimum_amount check uses. It is populated by WC_Cart_Totals::calculate_item_subtotals()
     * before any discount is calculated, and it rounds each line to the store's precision before
     * summing, which a hand rolled sum of the raw line subtotals does not. An order has no
     * equivalent getter, so its lines are summed instead.
     *
     * @since 4.1
     * @access private
     *
     * @param array $context Calculation context.
     * @return float Items subtotal.
     */
    private function _get_items_subtotal( $context ) {
        $cache_key = $context['key'];

        if ( isset( $this->_subtotal_cache[ $cache_key ] ) ) {
            return $this->_subtotal_cache[ $cache_key ];
        }

        $subtotal = 0.0;

        if ( ! is_null( $context['object'] ) ) {

            if ( $context['is_order'] ) {

                $include_tax = 'incl' === get_option( 'woocommerce_tax_display_cart' );

                foreach ( $context['items'] as $item ) {
                    $subtotal += $this->_get_cart_item_amount( $item, $include_tax );
                }
            } else {
                $subtotal = (float) $context['object']->get_displayed_subtotal();
            }
        }

        $this->_subtotal_cache[ $cache_key ] = $subtotal;

        return $subtotal;
    }

    /**
     * Get the amount of an item that counts towards the tier basis.
     *
     * @since 4.1
     * @access private
     *
     * @param array|\WC_Order_Item_Product $item        Cart item data, or an order item.
     * @param bool                         $include_tax Whether the store displays cart prices including tax.
     * @return float Item amount.
     */
    private function _get_cart_item_amount( $item, $include_tax ) {
        if ( $item instanceof \WC_Order_Item_Product ) {

            $amount = (float) $item->get_subtotal();

            return $include_tax ? $amount + (float) $item->get_subtotal_tax() : $amount;
        }

        if ( ! is_array( $item ) ) {
            return 0.0;
        }

        $amount = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0;

        if ( $include_tax && isset( $item['line_subtotal_tax'] ) ) {
            $amount += (float) $item['line_subtotal_tax'];
        }

        return $amount;
    }

    /**
     * Resolve the highest tier the cart or the order reaches.
     *
     * @since 4.1
     * @access private
     *
     * @param \WC_Coupon $coupon  Coupon object.
     * @param array      $context Calculation context.
     * @return array|null The reached tier, or null when no tier is reached.
     */
    private function _resolve_tier( $coupon, $context ) {
        $cache_key = $coupon->get_id() . ':' . $context['key'];

        if ( array_key_exists( $cache_key, $this->_tier_cache ) ) {
            return $this->_tier_cache[ $cache_key ];
        }

        $tiers    = $this->_get_tiers( $coupon );
        $resolved = null;

        if ( ! empty( $tiers ) && ! is_null( $context['object'] ) ) {

            $subtotal = $this->_get_items_subtotal( $context );

            foreach ( $tiers as $tier ) {
                if ( (float) $tier['min_spend'] <= $subtotal ) {
                    $resolved = $tier;
                }
            }
        }

        $this->_tier_cache[ $cache_key ] = $resolved;

        return $resolved;
    }

    /**
     * Build the fixed tier distribution map of the current totals pass, in integer minor units.
     * The tier amount is shared out over the eligible lines in proportion to what each line still
     * has available to discount, allocated by largest remainder.
     *
     * NOTE: the parts sum exactly to the payout, but the payout itself is capped at what the
     * eligible lines are worth, so a cart that cannot carry the whole tier pays out less than the
     * tier value. WC_Discounts::apply_coupon_custom() also caps each line at its own REMAINING
     * discountable price and never redistributes what that cap takes away, so a line that another
     * coupon has already consumed can still trim the payout.
     *
     * The split is deliberately still built against the line subtotal, never the line total.
     * WC_Cart_Totals::calculate_item_totals() writes "line_total" only AFTER calculate_discounts()
     * has run, so during a coupon pass it still holds the value the PREVIOUS pass left behind — a
     * value that already carries this coupon's own discount. Feeding it back in would make the
     * coupon shrink its own basis on every recalculation: a line worth less than the tier would go
     * to zero on one pass, drop out of the map on the next and pay nothing, then reappear, and the
     * cart total would oscillate. The line subtotal is the one basis no coupon can move.
     *
     * @since 4.1
     * @access private
     *
     * @param \WC_Coupon $coupon  Coupon object.
     * @param array      $context Calculation context.
     * @return array Discount amount in minor units, keyed by item key.
     */
    private function _get_distribution_map( $coupon, $context ) {
        $cache_key = $coupon->get_id() . ':' . $context['key'];

        if ( isset( $this->_distribution_cache[ $cache_key ] ) ) {
            return $this->_distribution_cache[ $cache_key ];
        }

        $map  = array();
        $tier = $this->_resolve_tier( $coupon, $context );

        if ( ! is_null( $tier ) && ! is_null( $context['object'] ) ) {

            $include_tax = 'incl' === get_option( 'woocommerce_tax_display_cart' );
            $items       = array();

            foreach ( $context['items'] as $item ) {

                if ( ! $this->_is_item_eligible( $item, $coupon ) ) {
                    continue;
                }

                $item_units = $this->_to_minor_units( $this->_get_cart_item_amount( $item, $include_tax ) );

                if ( 0 >= $item_units ) {
                    continue;
                }

                $items[ $this->_get_cart_item_key( $item ) ] = $item_units;
            }

            /**
             * The cart contents order is kept on purpose: it is stable across recalculations and it
             * is the order the customer sees, so the leftover cents of a split land on the lines
             * nearest the top of the cart, the way core's fixed cart discount allocates them.
             */

            $map = $this->_distribute( $this->_to_minor_units( (float) $tier['discount_value'] ), $items );
        }

        $this->_distribution_cache[ $cache_key ] = $map;

        return $map;
    }

    /**
     * Share a payout out over a set of lines, in integer minor units.
     *
     * @since 4.1
     * @access private
     *
     * @param int   $tier_units Tier amount in minor units.
     * @param array $items      Line amounts in minor units, keyed by cart item key.
     * @return array Allocated amounts in minor units, keyed by cart item key.
     */
    private function _distribute( $tier_units, $items ) {
        $total = array_sum( $items );

        if ( 0 >= $tier_units || 0 >= $total ) {
            return array();
        }

        // the payout can never exceed what the eligible lines are worth.
        $payout    = min( $tier_units, $total );
        $parts     = array_fill_keys( array_keys( $items ), 0 );
        $positions = array_flip( array_keys( $items ) );
        $remaining = $payout;

        /**
         * Two allocation passes. The first shares the payout out in proportion to what each line is
         * worth, and a line can only ever take as much as it is worth, so a cheap line can cap out
         * and leave part of the payout unallocated. The second pass shares that leftover out again
         * over the lines that still have headroom, so the coupon pays out as much of the tier as
         * the cart can carry instead of quietly under paying.
         */
        for ( $pass = 0; $pass < 2 && 0 < $remaining; $pass++ ) {

            $headroom = array();

            foreach ( $items as $key => $item_units ) {

                $room = $item_units - $parts[ $key ];

                if ( 0 < $room ) {
                    $headroom[ $key ] = $room;
                }
            }

            $room_total = array_sum( $headroom );

            if ( 0 >= $room_total ) {
                break;
            }

            $allocate   = min( $remaining, $room_total );
            $allocated  = 0;
            $remainders = array();

            foreach ( $headroom as $key => $room ) {
                $exact              = ( $allocate * $room ) / $room_total;
                $share              = min( (int) floor( $exact ), $room );
                $parts[ $key ]     += $share;
                $allocated         += $share;
                $remainders[ $key ] = $exact - $share;
            }

            $leftover = $allocate - $allocated;

            if ( 0 < $leftover ) {

                // largest fractional remainder first, the cart position as the tie breaker. Lines
                // that share a remainder are extremely common — three identically priced lines share
                // one exactly — so the tie breaker has to be the cart order and never the cart item
                // key, which is a hash and would scatter the leftover cents unpredictably.
                $keys = array_keys( $headroom );

                usort(
                    $keys,
                    function ( $a, $b ) use ( $remainders, $positions ) {
                        $compare = $remainders[ $b ] <=> $remainders[ $a ];

                        return 0 !== $compare ? $compare : ( $positions[ $a ] <=> $positions[ $b ] );
                    }
                );

                // loop until the leftover is exhausted, skipping lines already at their cap.
                while ( 0 < $leftover ) {

                    $allocated_any = false;

                    foreach ( $keys as $key ) {

                        if ( 0 >= $leftover ) {
                            break;
                        }

                        if ( $parts[ $key ] >= $items[ $key ] ) {
                            continue;
                        }

                        ++$parts[ $key ];
                        ++$allocated;
                        --$leftover;
                        $allocated_any = true;
                    }

                    if ( ! $allocated_any ) {
                        break;
                    }
                }
            }

            $remaining -= $allocated;
        }

        return $parts;
    }

    /**
     * Convert a monetary amount to integer minor units, using the store's price precision.
     *
     * @since 4.1
     * @access private
     *
     * @param float $amount Monetary amount.
     * @return int Amount in minor units.
     */
    private function _to_minor_units( $amount ) {
        return (int) round( \wc_add_number_precision( (float) $amount ) );
    }

    /**
     * Check whether a cart item may take a share of the tier discount.
     * WC_Coupon::is_valid_for_product() returns false for a non product coupon type, so it cannot be
     * reused here. Only the exclusion settings are honoured; an excluded item still counts towards
     * the tier threshold, it simply receives no discount.
     *
     * @since 4.1
     * @access private
     *
     * @param array|\WC_Order_Item_Product $cart_item Cart item data, or an order item.
     * @param \WC_Coupon                   $coupon    Coupon object.
     * @return bool True when the item may be discounted, false otherwise.
     */
    private function _is_item_eligible( $cart_item, $coupon ) {
        // the check runs once while the distribution map is built and again for every discount
        // filter call of the same item, so the verdict is only worked out once per pass.
        $item_key  = $this->_get_cart_item_key( $cart_item );
        $cache_key = '' !== $item_key ? $coupon->get_id() . ':' . $item_key : '';

        if ( '' !== $cache_key && isset( $this->_eligibility_cache[ $cache_key ] ) ) {
            return $this->_eligibility_cache[ $cache_key ];
        }

        $eligible = $this->_check_item_eligibility( $cart_item, $coupon );

        if ( '' !== $cache_key ) {
            $this->_eligibility_cache[ $cache_key ] = $eligible;
        }

        return $eligible;
    }

    /**
     * Work out whether an item may take a share of the tier discount.
     *
     * @since 4.1
     * @access private
     *
     * @param array|\WC_Order_Item_Product $cart_item Cart item data, or an order item.
     * @param \WC_Coupon                   $coupon    Coupon object.
     * @return bool True when the item may be discounted, false otherwise.
     */
    private function _check_item_eligibility( $cart_item, $coupon ) {
        $product = $this->_get_cart_item_product( $cart_item );

        if ( ! $product instanceof \WC_Product ) {
            return false;
        }

        $product_ids = array_filter( array( $product->get_id(), $product->get_parent_id() ) );

        // NOTE: every exclusion setting is read in the "edit" context on purpose. The "view"
        // getters are neutralized on the frontend (see neutralize_blanket_exclusion_validation())
        // so WooCommerce cannot veto the whole coupon over one excluded line, and this model is
        // what enforces them per line instead.
        $excluded_ids = $coupon->get_excluded_product_ids( 'edit' );

        if ( ! empty( $excluded_ids ) && array_intersect( $product_ids, $excluded_ids ) ) {
            return false;
        }

        $excluded_cats = $coupon->get_excluded_product_categories( 'edit' );

        if ( ! empty( $excluded_cats ) && array_intersect( $this->_get_product_cat_ids( $product ), $excluded_cats ) ) {
            return false;
        }

        if ( $coupon->get_exclude_sale_items( 'edit' ) && array_intersect( $product_ids, $this->_get_sale_product_ids() ) ) {
            return false;
        }

        return true;
    }

    /**
     * Get the IDs of the products that are on sale, memoized for the request.
     *
     * @since 4.1
     * @access private
     *
     * @return array List of product IDs on sale.
     */
    private function _get_sale_product_ids() {
        if ( is_null( $this->_sale_ids_cache ) ) {
            $this->_sale_ids_cache = array_map( 'absint', \wc_get_product_ids_on_sale() );
        }

        return $this->_sale_ids_cache;
    }

    /**
     * Get the category IDs of a product, memoized for the request.
     *
     * @since 4.1
     * @access private
     *
     * @param \WC_Product $product Product object.
     * @return array List of product category IDs.
     */
    private function _get_product_cat_ids( $product ) {
        $product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

        if ( ! isset( $this->_product_cats_cache[ $product_id ] ) ) {
            // wc_get_product_cat_ids() already returns the product's categories plus their
            // ancestors, so an exclusion on a parent category covers its descendants too.
            $this->_product_cats_cache[ $product_id ] = \wc_get_product_cat_ids( $product_id );
        }

        return $this->_product_cats_cache[ $product_id ];
    }

    /**
     * Get the product object out of an item.
     *
     * @since 4.1
     * @access private
     *
     * @param array|\WC_Product|\WC_Order_Item_Product $cart_item Cart item data, an order item, or a product.
     * @return \WC_Product|null Product object, or null when it cannot be resolved.
     */
    private function _get_cart_item_product( $cart_item ) {
        if ( $cart_item instanceof \WC_Product ) {
            return $cart_item;
        }

        if ( $cart_item instanceof \WC_Order_Item_Product ) {
            $product = $cart_item->get_product();

            return $product instanceof \WC_Product ? $product : null;
        }

        if ( is_array( $cart_item ) && isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ) {
            return $cart_item['data'];
        }

        return null;
    }

    /**
     * Get the key of an item. An order item is keyed by its own ID, which is what
     * WC_Discounts::set_items_from_order() keys the discounts array by.
     *
     * @since 4.1
     * @access private
     *
     * @param array|\WC_Order_Item_Product|null $cart_item Cart item data, or an order item.
     * @return string Item key, empty when it cannot be resolved.
     */
    private function _get_cart_item_key( $cart_item ) {
        if ( $cart_item instanceof \WC_Order_Item_Product ) {
            return (string) $cart_item->get_id();
        }

        return is_array( $cart_item ) && isset( $cart_item['key'] ) ? (string) $cart_item['key'] : '';
    }

    /**
     * Get the quantity of an item.
     *
     * @since 4.1
     * @access private
     *
     * @param array|\WC_Order_Item_Product|null $cart_item Cart item data, or an order item.
     * @return int Item quantity.
     */
    private function _get_cart_item_quantity( $cart_item ) {
        if ( $cart_item instanceof \WC_Order_Item_Product ) {
            return absint( $cart_item->get_quantity() );
        }

        return is_array( $cart_item ) && isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 1;
    }

    /**
     * Save the tier rows and the tier discount type of a coupon.
     *
     * @since 4.1
     * @access private
     *
     * @param int    $coupon_id     Coupon ID.
     * @param array  $tiers         Sanitized list of tier rows.
     * @param string $discount_type Discount type of the coupon's tiers.
     * @return bool True when saved, false otherwise.
     */
    private function _save_tiers( $coupon_id, $tiers, $discount_type ) {
        $coupon = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );

        if ( ! $coupon || ! $coupon->get_id() ) {
            return false;
        }

        $coupon->set_advanced_prop( 'tier_discounts', $tiers );
        $coupon->set_advanced_prop( 'tier_discount_type', $discount_type );

        // the memoized copy is stale the moment the tiers are written.
        unset( $this->_tiers_cache[ $coupon_id ] );

        $saved = $coupon->advanced_save();

        // saving an unchanged tier set registers no change, so Advanced_Coupon::advanced_save()
        // reports an error. The stored state is exactly the one that was asked for, so that is a
        // successful save and never something to report to the store owner as a failure.
        return ! is_wp_error( $saved ) || 'acfw_advanced_coupon_no_changes' === $saved->get_error_code();
    }

    /**
     * Build the editor payload of a saved tier set, so the panel can re-render itself from what was
     * actually persisted rather than from what the user typed.
     *
     * @since 4.1
     * @access private
     *
     * @param int   $coupon_id Coupon ID.
     * @param array $tiers     Sanitized list of tier rows.
     * @return array Tier rows formatted for the editor.
     */
    private function _get_saved_tiers_payload( $coupon_id, $tiers ) {
        $coupon = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );

        return $coupon ? $this->get_tiers_for_editor( $coupon ) : $tiers;
    }

    /**
     * Describe what sanitization changed about a submitted tier set, so the editor can tell the user
     * why the table it gets back does not match what was typed.
     *
     * @since 4.1
     * @access private
     *
     * @param array  $raw_tiers     Submitted list of tier rows.
     * @param array  $sanitized     Sanitized list of tier rows.
     * @param string $discount_type Discount type of the coupon's tiers.
     * @return string Notice text, empty when nothing was changed.
     */
    private function _get_sanitize_notice( $raw_tiers, $sanitized, $discount_type ) {
        $duplicates = $this->_count_duplicate_thresholds( $raw_tiers );
        $dropped    = max( 0, count( $raw_tiers ) - count( $sanitized ) - $duplicates );
        $clamped    = 0;

        if ( 'percent' === $discount_type ) {
            foreach ( $raw_tiers as $tier ) {
                if ( is_array( $tier )
                    && isset( $tier['discount_value'] )
                    && is_numeric( $tier['discount_value'] )
                    && 100 < (float) $tier['discount_value']
                ) {
                    ++$clamped;
                }
            }
        }

        $notices = array();

        if ( $duplicates ) {
            $notices[] = sprintf(
                /* translators: %d: number of tiers dropped for repeating a spend threshold. */
                _n(
                    '%d tier repeated a spend threshold and was discarded.',
                    '%d tiers repeated a spend threshold and were discarded.',
                    $duplicates,
                    'advanced-coupons-for-woocommerce'
                ),
                $duplicates
            );
        }

        if ( $dropped ) {
            $notices[] = sprintf(
                /* translators: %d: number of discarded tiers. */
                _n(
                    '%d incomplete tier was discarded.',
                    '%d incomplete tiers were discarded.',
                    $dropped,
                    'advanced-coupons-for-woocommerce'
                ),
                $dropped
            );
        }

        if ( $clamped ) {
            $notices[] = sprintf(
                /* translators: %d: number of tiers whose percentage was capped at 100. */
                _n(
                    'The percentage of %d tier was capped at 100.',
                    'The percentage of %d tiers was capped at 100.',
                    $clamped,
                    'advanced-coupons-for-woocommerce'
                ),
                $clamped
            );
        }

        return implode( ' ', $notices );
    }

    /**
     * Count the submitted tier rows that were dropped for repeating a spend threshold.
     * A repeated threshold is a different problem from an incomplete row, so the two are reported
     * separately. Only rows that would otherwise be usable are counted, which _normalize_tier_row()
     * decides for both this counter and the sanitizer.
     *
     * @since 4.1
     * @access private
     *
     * @param array $raw_tiers Submitted list of tier rows.
     * @return int Number of rows dropped as duplicates.
     */
    private function _count_duplicate_thresholds( $raw_tiers ) {
        $thresholds = array();
        $duplicates = 0;

        foreach ( $raw_tiers as $tier ) {

            $row = $this->_normalize_tier_row( $tier );

            if ( null === $row ) {
                continue;
            }

            if ( in_array( $row['min_spend'], $thresholds, true ) ) {
                ++$duplicates;
                continue;
            }

            $thresholds[] = $row['min_spend'];
        }

        return $duplicates;
    }

    /**
     * Normalize a single submitted tier row.
     * This is the one place that decides whether a row is usable, so the sanitizer and the notice
     * counter can never disagree about which rows count.
     *
     * @since 4.1
     * @access private
     *
     * @param mixed $tier Raw tier row.
     * @return array|null Row with float min_spend and discount_value, or null when the row is unusable.
     */
    private function _normalize_tier_row( $tier ) {
        if ( ! is_array( $tier ) ) {
            return null;
        }

        $min_spend      = isset( $tier['min_spend'] ) ? $tier['min_spend'] : '';
        $discount_value = isset( $tier['discount_value'] ) ? $tier['discount_value'] : '';

        if ( ! is_numeric( $min_spend ) || 0 > (float) $min_spend ) {
            return null;
        }

        if ( ! is_numeric( $discount_value ) || 0 >= (float) $discount_value ) {
            return null;
        }

        return array(
            'min_spend'      => (float) \wc_format_decimal( $min_spend ),
            'discount_value' => (float) \wc_format_decimal( $discount_value ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX functions.
    |--------------------------------------------------------------------------
     */

    /**
     * Validate an AJAX request of the tier discounts panel.
     * Both panel endpoints open with the same DOING_AJAX, nonce, capability and coupon ID checks.
     *
     * @since 4.1
     * @access private
     *
     * @param string $nonce_action Nonce action of the endpoint.
     * @param string $cap_filter   Name of the filter that overrides the required capability.
     * @return array|null Error response payload, or null when the request is valid.
     */
    private function _validate_ajax_request( $nonce_action, $cap_filter ) {
        $nonce = sanitize_key( $_POST['nonce'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            return array(
                'status'    => 'fail',
                'error_msg' => __( 'Invalid AJAX call', 'advanced-coupons-for-woocommerce' ),
            );
        }

        if ( ! $nonce
            || ! wp_verify_nonce( $nonce, $nonce_action )
            || ! current_user_can( apply_filters( $cap_filter, 'manage_woocommerce' ) )
        ) {
            return array(
                'status'    => 'fail',
                'error_msg' => __( 'You are not allowed to do this', 'advanced-coupons-for-woocommerce' ),
            );
        }

        if ( ! isset( $_POST['coupon_id'] ) ) {
            return array(
                'status'    => 'fail',
                'error_msg' => __( 'Missing required post data', 'advanced-coupons-for-woocommerce' ),
            );
        }

        return null;
    }

    /**
     * AJAX save the tier discounts of a coupon.
     *
     * @since 4.1
     * @access public
     */
    public function ajax_save_tier_discounts() {
        $response = $this->_validate_ajax_request( 'acfw_save_tier_discounts', 'acfw_ajax_save_tier_discounts' );

        if ( is_null( $response ) ) {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in _validate_ajax_request().
            $coupon_id     = absint( $_POST['coupon_id'] ?? 0 );
            $discount_type = 'percent' === sanitize_text_field( wp_unslash( $_POST['discount_type'] ?? '' ) ) ? 'percent' : 'fixed';
            $raw_tiers     = isset( $_POST['tiers'] ) && is_array( $_POST['tiers'] ) ? wp_unslash( $_POST['tiers'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            // phpcs:enable WordPress.Security.NonceVerification.Missing
            $tiers = $this->_sanitize_tiers( $raw_tiers, $discount_type );

            if ( $this->_save_tiers( $coupon_id, $tiers, $discount_type ) ) {
                $response = array(
                    'status'        => 'success',
                    'message'       => __( 'Tier discounts have been saved successfully!', 'advanced-coupons-for-woocommerce' ),
                    'tiers'         => $this->_get_saved_tiers_payload( $coupon_id, $tiers ),
                    'discount_type' => $discount_type,
                    'notice'        => $this->_get_sanitize_notice( $raw_tiers, $tiers, $discount_type ),
                );
            } else {
                $response = array(
                    'status'    => 'fail',
                    'error_msg' => __( 'Failed on saving the tier discounts.', 'advanced-coupons-for-woocommerce' ),
                );
            }
        }

        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
        echo wp_json_encode( $response );
        wp_die();
    }

    /**
     * AJAX clear the tier discounts of a coupon.
     *
     * @since 4.1
     * @access public
     */
    public function ajax_clear_tier_discounts() {
        $response = $this->_validate_ajax_request( 'acfw_clear_tier_discounts', 'acfw_ajax_clear_tier_discounts' );

        if ( is_null( $response ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in _validate_ajax_request().
            $coupon_id = absint( $_POST['coupon_id'] ?? 0 );

            if ( $this->_save_tiers( $coupon_id, array(), $this->get_tier_discount_type( \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id ) ) ) ) {
                $response = array(
                    'status'  => 'success',
                    'message' => __( 'Tier discounts have been cleared successfully!', 'advanced-coupons-for-woocommerce' ),
                );
            } else {
                $response = array(
                    'status'    => 'fail',
                    'error_msg' => __( 'Failed on clearing the tier discounts.', 'advanced-coupons-for-woocommerce' ),
                );
            }
        }

        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
        echo wp_json_encode( $response );
        wp_die();
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run on plugin initialization.
     *
     * @since 4.1
     * @access public
     * @implements ACFWP\Interfaces\Initiable_Interface
     */
    public function initialize() {
        add_action( 'wp_ajax_acfw_save_tier_discounts', array( $this, 'ajax_save_tier_discounts' ) );
        add_action( 'wp_ajax_acfw_clear_tier_discounts', array( $this, 'ajax_clear_tier_discounts' ) );
    }

    /**
     * Execute Tiered_Cart_Discount class.
     *
     * @since 4.1
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        // Coupon type registration.
        add_filter( 'woocommerce_coupon_discount_types', array( $this, 'register_tiered_coupon_type' ) );
        add_filter( 'woocommerce_cart_coupon_types', array( $this, 'register_tiered_cart_coupon_type' ) );
        add_filter( 'acfwf_single_coupon_discount_value', array( $this, 'filter_coupon_discount_value_string' ), 10, 4 );

        // Admin.
        add_action( 'woocommerce_coupon_options', array( $this, 'display_tier_discounts_panel' ), 20 );
        add_action( 'manage_shop_coupon_posts_custom_column', array( $this, 'buffer_coupon_amount_column' ), 9, 2 );
        add_action( 'manage_shop_coupon_posts_custom_column', array( $this, 'replace_coupon_amount_column' ), 11, 2 );

        // Frontend implementation.
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'flush_calculation_caches' ) );
        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_tier_threshold_reached' ), 11, 3 );
        add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'get_tier_discount_amount' ), 10, 5 );

        // Cart/checkout discount summary (classic + blocks).
        add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'display_tier_discount_summary' ), 10, 3 );
        add_filter( 'acfwf_cart_checkout_block_coupon_summary', array( $this, 'append_tier_discount_summary_to_cart_checkout_block' ), 10, 2 );

        // Ignore usage limits that are incompatible with a cart level tier.
        add_filter( 'woocommerce_coupon_get_limit_usage_to_x_items', array( $this, 'neutralize_limit_usage_to_x_items' ), 10, 2 );

        // Ignore the coupon settings that the tier table supersedes, and the blanket exclusion
        // validation that WooCommerce runs for every cart level coupon type.
        add_filter( 'woocommerce_coupon_validate_minimum_amount', array( $this, 'neutralize_minimum_amount_validation' ), 10, 2 );
        add_filter( 'woocommerce_coupon_get_exclude_sale_items', array( $this, 'neutralize_blanket_exclusion_validation' ), 10, 2 );
        add_filter( 'woocommerce_coupon_get_excluded_product_ids', array( $this, 'neutralize_blanket_exclusion_validation' ), 10, 2 );
        add_filter( 'woocommerce_coupon_get_excluded_product_categories', array( $this, 'neutralize_blanket_exclusion_validation' ), 10, 2 );

        add_filter( 'woocommerce_coupon_get_product_ids', array( $this, 'neutralize_product_ids' ), 10, 2 );
        add_filter( 'woocommerce_coupon_get_product_categories', array( $this, 'neutralize_product_categories' ), 10, 2 );
    }
}
