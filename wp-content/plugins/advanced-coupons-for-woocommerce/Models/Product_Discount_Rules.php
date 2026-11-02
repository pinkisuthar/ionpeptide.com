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
 * Model that houses the logic of the "Product Discount Rules" coupon type.
 * The coupon holds an ordered list of rules and every cart item takes the discount
 * of the first rule it matches, top to bottom.
 *
 * NOTE: the rules engine applies to the cart and the checkout only, the same scope as the BOGO and
 * Add Products coupon types. An admin order recalculation hands over WC_Order_Item_Product objects,
 * which this model deliberately does not resolve, so a rule discount is not re-applied there.
 *
 * Public Model.
 *
 * @since 4.1
 */
class Product_Discount_Rules extends Base_Model implements Model_Interface, Initiable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Constants
    |--------------------------------------------------------------------------
     */

    /**
     * Discount type key of the product discount rules coupon type.
     *
     * @since 4.1
     */
    const DISCOUNT_TYPE = 'acfw_product_rules';

    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Request scoped cache of the sanitized discount rules, keyed by coupon ID.
     * WooCommerce calls is_valid_for_product() several times per cart item per totals pass, so the
     * rules are only sanitized once per coupon per request.
     *
     * @since 4.1
     * @access private
     * @var array
     */
    private $_rules_cache = array();

    /**
     * Request scoped cache of the product category IDs, keyed by product ID.
     * The matcher runs several times per cart item per totals pass, so the category and ancestor
     * list of a product is only resolved once per request.
     *
     * @since 4.1
     * @access private
     * @var array
     */
    private $_product_cats_cache = array();

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
     * Register the product discount rules coupon type.
     *
     * @since 4.1
     * @access public
     *
     * @param array $types Coupon types.
     * @return array Filtered coupon types.
     */
    public function register_product_rules_coupon_type( $types ) {
        $types[ self::DISCOUNT_TYPE ] = __( 'Product Discount Rules', 'advanced-coupons-for-woocommerce' );

        return $types;
    }

    /**
     * Register the product discount rules coupon type as a product coupon type.
     * NOTE: this is required, otherwise WC_Coupon::is_valid_for_product() short circuits to false
     * before the exclusion settings are ever evaluated.
     *
     * @since 4.1
     * @access public
     *
     * @param array $coupon_types List of product coupon types.
     * @return array Filtered list of product coupon types.
     */
    public function register_product_rules_product_coupon_type( $coupon_types ) {
        $coupon_types[] = self::DISCOUNT_TYPE;

        return $coupon_types;
    }

    /**
     * Display the type label instead of the coupon amount on the coupon's discount value string.
     * The coupon amount is unused by this coupon type so the free plugin's default branch would
     * otherwise render "0 product discount rules".
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
        if ( $this->is_product_rules_coupon( $coupon ) ) {
            $message = __( 'Product Discount Rules', 'advanced-coupons-for-woocommerce' );
        }

        return $message;
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
     */

    /**
     * Display the discount rules panel on the coupon editor's general tab.
     *
     * @since 4.1
     * @access public
     *
     * @param int $coupon_id Coupon ID.
     */
    public function display_discount_rules_panel( $coupon_id ) {
        $panel_id    = 'acfw_discount_rules';
        $coupon      = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );
        $spinner_img = $this->_constants->IMAGES_ROOT_URL . 'spinner-2x.gif';
        $rules       = $this->get_rules_for_editor( $coupon );

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-coupon-discount-rules-data-panel.php';
    }

    /**
     * Get the coupon's discount rules with the target labels resolved for the editor.
     * Labels are deliberately not persisted so a renamed product or category never goes stale.
     *
     * @since 4.1
     * @access public
     *
     * @param \ACFWP\Models\Objects\Advanced_Coupon $coupon Advanced coupon object.
     * @return array List of discount rules for the editor.
     */
    public function get_rules_for_editor( $coupon ) {
        $rules = $this->_sanitize_rules( $coupon->get_advanced_prop( 'discount_rules', array() ) );

        // resolve every label in two batched lookups instead of one query per target.
        $product_ids  = array();
        $category_ids = array();

        foreach ( $rules as $rule ) {
            if ( 'categories' === $rule['target_type'] ) {
                $category_ids = array_merge( $category_ids, $rule['target_ids'] );
            } else {
                $product_ids = array_merge( $product_ids, $rule['target_ids'] );
            }
        }

        $product_labels  = $this->_get_product_labels( array_unique( $product_ids ) );
        $category_labels = $this->_get_category_labels( array_unique( $category_ids ) );

        foreach ( $rules as $index => $rule ) {

            $targets = array();
            $labels  = 'categories' === $rule['target_type'] ? $category_labels : $product_labels;

            foreach ( $rule['target_ids'] as $target_id ) {
                $targets[] = array(
                    'id'    => $target_id,
                    'label' => isset( $labels[ $target_id ] ) ? $labels[ $target_id ] : sprintf( '#%d', $target_id ),
                );
            }

            $rules[ $index ]['targets'] = $targets;

            /**
             * Render a fixed amount as a price, the way the other coupon editor tables do. Note that
             * wc_format_localized_price() only swaps the decimal separator, so the value has to be padded
             * to the store's decimal count first for "5" to come back as "5.00". Percentages are left as
             * they were typed.
             */
            if ( 'fixed' === $rule['discount_type'] ) {
                $rules[ $index ]['discount_value'] = wc_format_localized_price( \wc_format_decimal( $rule['discount_value'], wc_get_price_decimals() ) );
            }
        }

        return $rules;
    }

    /*
    |--------------------------------------------------------------------------
    | Frontend implementation
    |--------------------------------------------------------------------------
     */

    /**
     * Determine if a product is discounted by this coupon.
     * The incoming value is respected when it is already false as that is WooCommerce's own
     * exclusion veto (excluded products, excluded categories, exclude sale items) and the
     * "Allowed Product Attributes" veto that runs at priority -99.
     *
     * @since 4.1
     * @access public
     *
     * @param bool        $valid   Filter return value.
     * @param \WC_Product $product Product object.
     * @param \WC_Coupon  $coupon  Coupon object.
     * @param array       $values  Cart item data.
     * @return bool True if the product matches a rule, false otherwise.
     */
    public function validate_product_matches_rule( $valid, $product, $coupon, $values ) {
        if ( ! $this->is_product_rules_coupon( $coupon ) ) {
            return $valid;
        }

        if ( ! $valid ) {
            return false;
        }

        return null !== $this->_find_matching_rule( $product, $coupon );
    }

    /**
     * Calculate the per unit discount amount of a cart item using the first rule it matches.
     *
     * @since 4.1
     * @access public
     *
     * @param float      $discount           Coupon discount amount for the cart item.
     * @param float      $discounting_amount Amount that needs to be discounted.
     * @param array|null $cart_item          Cart item data.
     * @param bool       $single             True if discounting a single qty item, false if it's the line.
     * @param \WC_Coupon $coupon             Coupon object.
     * @return float Filtered discount amount.
     */
    public function get_rule_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
        if ( ! $this->is_product_rules_coupon( $coupon ) ) {
            return $discount;
        }

        $product = $this->_get_cart_item_product( $cart_item );

        if ( ! $product instanceof \WC_Product ) {
            return $discount;
        }

        $rule = $this->_find_matching_rule( $product, $coupon );

        if ( is_null( $rule ) ) {
            return 0;
        }

        if ( 'percent' === $rule['discount_type'] ) {
            $precise_discount = \wc_add_number_precision( $discounting_amount ) * ( $rule['discount_value'] / 100 );

            return \wc_remove_number_precision( $precise_discount );
        }

        $amount = $rule['discount_value'];

        /**
         * WooCommerce asks for the single unit amount in the custom coupon pass and multiplies it by the
         * quantity itself, so a fixed rule is a per unit amount. WC_Discounts::apply_coupon_custom() is the
         * only core path this coupon type reaches and it always passes true, so the branch below is purely
         * defensive for third party callers that ask for the whole line instead.
         */
        if ( ! $single ) {
            $amount *= max( 1, $this->_get_cart_item_quantity( $cart_item ) );
        }

        // never discount an item below zero.
        return min( $amount, (float) $discounting_amount );
    }

    /**
     * Build the per item discount summary of a product rules coupon for the cart and checkout.
     * The breakdown mirrors the BOGO and Add Products summaries: one row per discounted line reading
     * "{product} x {qty}: {amount}". Each row is recomputed from the rule the item matches, so the
     * rows add up to the coupon's own discount total the same way the calculation does.
     *
     * @since 4.1
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return string Summary markup, or an empty string when nothing is discounted.
     */
    public function get_product_rules_discount_summary_for_coupon( $coupon ) {
        if ( ! $this->is_product_rules_coupon( $coupon ) ) {
            return '';
        }

        // the block summary filter makes this method reachable outside the classic cart context, where
        // the cart is not guaranteed to be set up yet.
        if ( is_null( \WC()->cart ) ) {
            return '';
        }

        $template = '<li><span class="label">%s x %s:</span> <span class="discount">%s</span></li>';
        $summary  = '';

        foreach ( \WC()->cart->get_cart_contents() as $cart_item ) {

            $product = $this->_get_cart_item_product( $cart_item );

            if ( ! $product instanceof \WC_Product ) {
                continue;
            }

            $rule = $this->_find_matching_rule( $product, $coupon );

            if ( is_null( $rule ) ) {
                continue;
            }

            $quantity      = $this->_get_cart_item_quantity( $cart_item );
            $line_subtotal = isset( $cart_item['line_subtotal'] ) ? (float) $cart_item['line_subtotal'] : (float) $product->get_price() * $quantity;

            if ( 'percent' === $rule['discount_type'] ) {
                // mirror get_rule_discount_amount()'s precision handling so the row matches the applied line.
                $discount_total = \wc_remove_number_precision( \wc_add_number_precision( $line_subtotal ) * ( $rule['discount_value'] / 100 ) );
            } else {
                // a fixed rule is a per unit amount, capped so the line is never discounted below zero.
                $discount_total = min( (float) $rule['discount_value'] * $quantity, $line_subtotal );
            }

            // skip items that end up with no discount so the summary never shows a zero row.
            if ( 0 >= $discount_total ) {
                continue;
            }

            // WooCommerce echoes this markup unescaped, so the product name is escaped here.
            $summary .= sprintf( $template, esc_html( $product->get_name() ), $quantity, \wc_price( $discount_total ) );
        }

        return $summary ? sprintf( '<ul class="acfw-product-rules-summary %s-product-rules-summary" style="margin: 10px;">%s</ul>', sanitize_html_class( $coupon->get_code() ), $summary ) : '';
    }

    /**
     * Append the product rules discount summary to the coupon row on the classic cart and checkout.
     *
     * @since 4.1
     * @access public
     *
     * @param string     $coupon_html          Coupon row html.
     * @param \WC_Coupon $coupon               Coupon object.
     * @param string     $discount_amount_html Discount amount html.
     * @return string Filtered coupon row html.
     */
    public function display_product_rules_discount_summary( $coupon_html, $coupon, $discount_amount_html ) {
        $summary = $this->get_product_rules_discount_summary_for_coupon( $coupon );

        return $summary ? $coupon_html . $summary : $coupon_html;
    }

    /**
     * Append the product rules discount summary to the cart and checkout block.
     *
     * @since 4.1
     * @access public
     *
     * @param string     $summary Cart/checkout block summary.
     * @param \WC_Coupon $coupon  Coupon object.
     * @return string Filtered cart/checkout block summary.
     */
    public function append_product_rules_discount_summary_to_cart_checkout_block( $summary, $coupon ) {
        return $summary . $this->get_product_rules_discount_summary_for_coupon( $coupon );
    }

    /**
     * Neutralise the "Products" usage restriction include list for this coupon type.
     * The editor hides the field, but a coupon converted from another discount type keeps its
     * old meta and WooCommerce treats a non empty include list as a hard filter that would
     * silently suppress every rule match. The meta itself is deliberately left untouched.
     *
     * @since 4.1
     * @access public
     *
     * @param array      $product_ids List of product IDs.
     * @param \WC_Coupon $coupon      Coupon object.
     * @return array Filtered list of product IDs.
     */
    public function neutralize_product_ids( $product_ids, $coupon ) {
        return $this->is_product_rules_coupon( $coupon ) ? array() : $product_ids;
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
        return $this->is_product_rules_coupon( $coupon ) ? array() : $product_categories;
    }

    /**
     * Neutralise the "Limit usage to X items" usage limit for this coupon type.
     *
     * @since 4.1
     * @access public
     *
     * @param int|null   $limit  Limit usage to X items value.
     * @param \WC_Coupon $coupon Coupon object.
     * @return int|null Filtered limit usage to X items value.
     */
    public function neutralize_limit_usage_to_x_items( $limit, $coupon ) {
        return $this->is_product_rules_coupon( $coupon ) ? null : $limit;
    }

    /*
    |--------------------------------------------------------------------------
    | Utility functions
    |--------------------------------------------------------------------------
     */

    /**
     * Check if the given coupon is of the product discount rules type.
     *
     * @since 4.1
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return bool True if the coupon is a product discount rules coupon, false otherwise.
     */
    public function is_product_rules_coupon( $coupon ) {
        return $coupon instanceof \WC_Coupon && self::DISCOUNT_TYPE === $coupon->get_discount_type();
    }

    /**
     * Sanitize a list of discount rules.
     * Rows that cannot be applied are dropped rather than clamped, so a malformed row can never
     * silently discount something it was not meant to.
     *
     * @since 4.1
     * @access private
     *
     * @param mixed $rules Raw discount rules data.
     * @return array Sanitized and reindexed list of discount rules.
     */
    private function _sanitize_rules( $rules ) {
        if ( ! is_array( $rules ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $rules as $rule ) {

            if ( ! is_array( $rule ) ) {
                continue;
            }

            $target_type   = isset( $rule['target_type'] ) ? sanitize_text_field( $rule['target_type'] ) : '';
            $discount_type = isset( $rule['discount_type'] ) ? sanitize_text_field( $rule['discount_type'] ) : '';

            if ( ! in_array( $target_type, array( 'products', 'categories' ), true ) ) {
                continue;
            }

            if ( ! in_array( $discount_type, array( 'percent', 'fixed' ), true ) ) {
                continue;
            }

            // target IDs are only cast to positive integers, their existence is not validated so a
            // deleted product simply stops matching.
            $target_ids = isset( $rule['target_ids'] ) && is_array( $rule['target_ids'] ) ? $rule['target_ids'] : array();
            $target_ids = array_values( array_unique( array_filter( array_map( 'absint', $target_ids ) ) ) );

            if ( empty( $target_ids ) ) {
                continue;
            }

            $discount_value = isset( $rule['discount_value'] ) ? $rule['discount_value'] : '';

            if ( ! is_numeric( $discount_value ) || 0 > (float) $discount_value ) {
                continue;
            }

            $discount_value = (float) \wc_format_decimal( $discount_value );

            if ( 'percent' === $discount_type && 100 < $discount_value ) {
                $discount_value = 100.0;
            }

            $sanitized[] = array(
                'target_type'    => $target_type,
                'target_ids'     => $target_ids,
                'discount_type'  => $discount_type,
                'discount_value' => $discount_value,
            );
        }

        return $sanitized;
    }

    /**
     * Get the sanitized discount rules of a coupon.
     *
     * @since 4.1
     * @access private
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return array Ordered list of discount rules.
     */
    private function _get_rules( $coupon ) {
        $coupon_id = $coupon->get_id();

        if ( ! isset( $this->_rules_cache[ $coupon_id ] ) ) {
            $this->_rules_cache[ $coupon_id ] = $this->_sanitize_rules( $coupon->get_meta( $this->_constants->META_PREFIX . 'discount_rules', true ) );
        }

        return $this->_rules_cache[ $coupon_id ];
    }

    /**
     * Find the first rule of the coupon that matches the given product.
     *
     * @since 4.1
     * @access private
     *
     * @param \WC_Product $product Product object.
     * @param \WC_Coupon  $coupon  Coupon object.
     * @return array|null The matching rule, or null when the product matches no rule.
     */
    private function _find_matching_rule( $product, $coupon ) {
        if ( ! $product instanceof \WC_Product ) {
            return null;
        }

        $rules = $this->_get_rules( $coupon );

        if ( empty( $rules ) ) {
            return null;
        }

        // a rule targeting a variable product matches its variations, and vice versa.
        $product_ids  = array( $product->get_id(), $product->get_parent_id() );
        $product_cats = null;

        foreach ( $rules as $rule ) {

            if ( 'categories' === $rule['target_type'] ) {

                if ( is_null( $product_cats ) ) {
                    $product_cats = $this->_get_product_cat_ids( $product );
                }

                if ( array_intersect( $product_cats, $rule['target_ids'] ) ) {
                    return $rule;
                }

                continue;
            }

            if ( array_intersect( $product_ids, $rule['target_ids'] ) ) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Get the category IDs of a product, plus their ancestors.
     * The result is memoized for the request: WooCommerce runs the matcher several times per cart
     * item per totals pass, and the summary walks the cart again, so the same product would otherwise
     * be resolved repeatedly.
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
            // ancestors, so a rule targeting a parent category matches its descendants too.
            $this->_product_cats_cache[ $product_id ] = \wc_get_product_cat_ids( $product_id );
        }

        return $this->_product_cats_cache[ $product_id ];
    }

    /**
     * Get the product object out of a cart item.
     * The custom coupon pass hands over the cart item array, but other call paths hand over the
     * product object itself.
     *
     * NOTE: an order item is deliberately not resolved — the rules engine is cart and checkout only,
     * see the class docblock.
     *
     * @since 4.1
     * @access private
     *
     * @param array|WC_Product $cart_item Cart item data.
     * @return \WC_Product|null Product object, or null when it cannot be resolved.
     */
    private function _get_cart_item_product( $cart_item ) {
        if ( $cart_item instanceof \WC_Product ) {
            return $cart_item;
        }

        if ( is_array( $cart_item ) && isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ) {
            return $cart_item['data'];
        }

        return null;
    }

    /**
     * Get the quantity of a cart item.
     *
     * @since 4.1
     * @access private
     *
     * @param array|null $cart_item Cart item data.
     * @return int Cart item quantity.
     */
    private function _get_cart_item_quantity( $cart_item ) {
        return is_array( $cart_item ) && isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 1;
    }

    /**
     * Get the editor labels of a list of product targets, keyed by product ID.
     *
     * @since 4.1
     * @access private
     *
     * @param array $product_ids List of product IDs.
     * @return array Product labels keyed by product ID.
     */
    private function _get_product_labels( $product_ids ) {
        if ( empty( $product_ids ) ) {
            return array();
        }

        // warm the post and meta caches in one pass so wc_get_product() does not query per ID.
        _prime_post_caches( $product_ids, false, true );

        $labels = array();

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );

            if ( $product instanceof \WC_Product ) {
                $labels[ $product_id ] = $product->get_formatted_name();
            }
        }

        return $labels;
    }

    /**
     * Get the editor labels of a list of product category targets, keyed by term ID.
     *
     * @since 4.1
     * @access private
     *
     * @param array $category_ids List of product category term IDs.
     * @return array Product category labels keyed by term ID.
     */
    private function _get_category_labels( $category_ids ) {
        if ( empty( $category_ids ) ) {
            return array();
        }

        $terms = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'include'    => $category_ids,
                'hide_empty' => false,
            )
        );

        if ( is_wp_error( $terms ) ) {
            return array();
        }

        $labels = array();

        foreach ( $terms as $term ) {
            $labels[ $term->term_id ] = $term->name;
        }

        return $labels;
    }

    /**
     * Save the discount rules of a coupon.
     *
     * @since 4.1
     * @access private
     *
     * @param int   $coupon_id Coupon ID.
     * @param array $rules     Sanitized list of discount rules.
     * @return bool True when saved, false otherwise.
     */
    private function _save_rules( $coupon_id, $rules ) {
        $coupon = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );

        if ( ! $coupon || ! $coupon->get_id() ) {
            return false;
        }

        $coupon->set_advanced_prop( 'discount_rules', $rules );

        // the memoized copy is stale the moment the rules are written.
        unset( $this->_rules_cache[ $coupon_id ] );

        return ! is_wp_error( $coupon->advanced_save() );
    }

    /**
     * Build the editor payload of a saved rule set, so the panel can re-render itself from what was
     * actually persisted rather than from what the user typed.
     *
     * @since 4.1
     * @access private
     *
     * @param int   $coupon_id Coupon ID.
     * @param array $rules     Sanitized list of discount rules.
     * @return array Discount rules with their target labels resolved.
     */
    private function _get_saved_rules_payload( $coupon_id, $rules ) {
        $coupon = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );

        return $coupon ? $this->get_rules_for_editor( $coupon ) : $rules;
    }

    /**
     * Describe what sanitization changed about a submitted rule set, so the editor can tell the user
     * why the table it gets back does not match what was typed.
     *
     * @since 4.1
     * @access private
     *
     * @param array $raw_rules  Submitted list of discount rules.
     * @param array $sanitized  Sanitized list of discount rules.
     * @return string Notice text, empty when nothing was changed.
     */
    private function _get_sanitize_notice( $raw_rules, $sanitized ) {
        $dropped = max( 0, count( $raw_rules ) - count( $sanitized ) );
        $clamped = 0;

        foreach ( $raw_rules as $rule ) {
            if ( is_array( $rule )
                && isset( $rule['discount_type'], $rule['discount_value'] )
                && 'percent' === $rule['discount_type']
                && is_numeric( $rule['discount_value'] )
                && 100 < (float) $rule['discount_value']
            ) {
                ++$clamped;
            }
        }

        $notices = array();

        if ( $dropped ) {
            $notices[] = sprintf(
                /* translators: %d: number of discarded discount rules. */
                _n(
                    '%d incomplete rule was discarded.',
                    '%d incomplete rules were discarded.',
                    $dropped,
                    'advanced-coupons-for-woocommerce'
                ),
                $dropped
            );
        }

        if ( $clamped ) {
            $notices[] = sprintf(
                /* translators: %d: number of discount rules whose percentage was capped at 100. */
                _n(
                    'The percentage of %d rule was capped at 100.',
                    'The percentage of %d rules was capped at 100.',
                    $clamped,
                    'advanced-coupons-for-woocommerce'
                ),
                $clamped
            );
        }

        return implode( ' ', $notices );
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX functions.
    |--------------------------------------------------------------------------
     */

    /**
     * Validate an AJAX request of the discount rules panel.
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
     * AJAX save the discount rules of a coupon.
     *
     * @since 4.1
     * @access public
     */
    public function ajax_save_discount_rules() {
        $response = $this->_validate_ajax_request( 'acfw_save_discount_rules', 'acfw_ajax_save_discount_rules' );

        if ( is_null( $response ) ) {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in _validate_ajax_request().
            $coupon_id = absint( $_POST['coupon_id'] ?? 0 );
            $raw_rules = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            // phpcs:enable WordPress.Security.NonceVerification.Missing
            $rules = $this->_sanitize_rules( $raw_rules );

            if ( $this->_save_rules( $coupon_id, $rules ) ) {
                $response = array(
                    'status'  => 'success',
                    'message' => __( 'Discount rules have been saved successfully!', 'advanced-coupons-for-woocommerce' ),
                    'rules'   => $this->_get_saved_rules_payload( $coupon_id, $rules ),
                    'notice'  => $this->_get_sanitize_notice( $raw_rules, $rules ),
                );
            } else {
                $response = array(
                    'status'    => 'fail',
                    'error_msg' => __( 'Failed on saving the discount rules.', 'advanced-coupons-for-woocommerce' ),
                );
            }
        }

        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
        echo wp_json_encode( $response );
        wp_die();
    }

    /**
     * AJAX clear the discount rules of a coupon.
     *
     * @since 4.1
     * @access public
     */
    public function ajax_clear_discount_rules() {
        $response = $this->_validate_ajax_request( 'acfw_clear_discount_rules', 'acfw_ajax_clear_discount_rules' );

        if ( is_null( $response ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in _validate_ajax_request().
            if ( $this->_save_rules( absint( $_POST['coupon_id'] ?? 0 ), array() ) ) {
                $response = array(
                    'status'  => 'success',
                    'message' => __( 'Discount rules have been cleared successfully!', 'advanced-coupons-for-woocommerce' ),
                );
            } else {
                $response = array(
                    'status'    => 'fail',
                    'error_msg' => __( 'Failed on clearing the discount rules.', 'advanced-coupons-for-woocommerce' ),
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
        add_action( 'wp_ajax_acfw_save_discount_rules', array( $this, 'ajax_save_discount_rules' ) );
        add_action( 'wp_ajax_acfw_clear_discount_rules', array( $this, 'ajax_clear_discount_rules' ) );
    }

    /**
     * Execute Product_Discount_Rules class.
     *
     * @since 4.1
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        // Coupon type registration.
        add_filter( 'woocommerce_coupon_discount_types', array( $this, 'register_product_rules_coupon_type' ) );
        add_filter( 'woocommerce_product_coupon_types', array( $this, 'register_product_rules_product_coupon_type' ) );
        add_filter( 'acfwf_single_coupon_discount_value', array( $this, 'filter_coupon_discount_value_string' ), 10, 4 );

        // Admin.
        add_action( 'woocommerce_coupon_options', array( $this, 'display_discount_rules_panel' ), 20 );

        // Frontend implementation.
        add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'validate_product_matches_rule' ), 10, 4 );
        add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'get_rule_discount_amount' ), 10, 5 );

        // Cart/checkout discount summary (classic + blocks), mirroring BOGO and Add Products.
        add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'display_product_rules_discount_summary' ), 10, 3 );
        add_filter( 'acfwf_cart_checkout_block_coupon_summary', array( $this, 'append_product_rules_discount_summary_to_cart_checkout_block' ), 10, 2 );

        // Ignore usage restrictions that are incompatible with the rules engine.
        add_filter( 'woocommerce_coupon_get_product_ids', array( $this, 'neutralize_product_ids' ), 10, 2 );
        add_filter( 'woocommerce_coupon_get_product_categories', array( $this, 'neutralize_product_categories' ), 10, 2 );
        add_filter( 'woocommerce_coupon_get_limit_usage_to_x_items', array( $this, 'neutralize_limit_usage_to_x_items' ), 10, 2 );
    }
}
