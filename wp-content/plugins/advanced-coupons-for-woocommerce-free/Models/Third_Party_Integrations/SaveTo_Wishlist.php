<?php
namespace ACFWF\Models\Third_Party_Integrations;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the SaveTo Wishlist coupon usage restriction.
 *
 * Restricts a coupon so it only applies when the cart holds at least one product
 * that is in the logged in customer's SaveTo Wishlist. Requires SaveTo Wishlist
 * Lite only, so it works on the free tier of that plugin.
 *
 * @since 4.8
 */
class SaveTo_Wishlist extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Constants
    |--------------------------------------------------------------------------
     */

    /**
     * Advanced coupon property that holds the restriction setting.
     *
     * The property is saved as the post meta Plugin_Constants::META_PREFIX . PROP,
     * so the stored meta key is _acfw_enable_wishlist_restriction.
     *
     * @since 4.8
     */
    const PROP = 'enable_wishlist_restriction';

    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Wishlisted product and variation ID sets for the current request, keyed by user ID.
     *
     * The coupon validity filter fires once per coupon per cart recalculation, and
     * both Auto Apply and the "show only eligible coupons" My Coupons page validate
     * every published coupon. Fetching the wishlist per coupon would cost one query
     * per coupon, so the bulk fetch is memoized here for the rest of the request.
     *
     * The cache is keyed by user ID on purpose. A single request can change the
     * current user (WP-CLI, cron, an admin acting on an order), and an unkeyed cache
     * would hand one customer's wishlist to another.
     *
     * @since 4.8
     * @access private
     * @var array
     */
    private $_wishlisted_ids_by_user = array();

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.8
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $main_plugin->add_to_all_plugin_models( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | Implementation
    |--------------------------------------------------------------------------
     */

    /**
     * Implement the wishlisted products coupon usage restriction.
     *
     * @since 4.8
     * @access public
     *
     * @param bool       $valid  Filter return value.
     * @param \WC_Coupon $coupon WC_Coupon object.
     * @return bool True if valid, false otherwise.
     * @throws \Exception Error message.
     */
    public function implement_wishlist_restriction( $valid, $coupon ) {
        $coupon = $coupon instanceof Advanced_Coupon ? $coupon : new Advanced_Coupon( $coupon );

        // Skip when virtual coupons are enabled, matching the sibling restrictions.
        // The meta is only ever set by the premium plugin, so this reads as empty on
        // a free only install.
        if ( (bool) $coupon->get_meta( '_acfw_enable_virtual_coupons' ) ) {
            return $valid;
        }

        if ( 'yes' !== $coupon->get_advanced_prop( self::PROP ) ) {
            return $valid;
        }

        // The filter also fires outside a customer cart, for example when an admin
        // adds a coupon to an order or through a REST request. There is no cart to
        // check there, so pass the value through untouched.
        if ( ! function_exists( 'WC' ) || is_null( \WC()->cart ) ) {
            return $valid;
        }

        if ( ! $this->_cart_has_wishlisted_product() ) {
            $error_message = apply_filters(
                'acfwf_wishlist_restriction_error_message',
                __( 'This coupon requires at least one product from your wishlist to be in your cart.', 'advanced-coupons-for-woocommerce-free' ),
                $coupon
            );

            throw new \Exception( wp_kses_post( $error_message ) );
        }

        return $valid;
    }

    /**
     * Check if the cart holds at least one product that is in the customer's wishlist.
     *
     * Matching is variation precise. A cart item qualifies when:
     *
     * - it was recorded as wishlisted when the customer added it to the cart, or
     * - its variation ID was wishlisted as a specific variation, or
     * - its product ID was wishlisted without a variation.
     *
     * So wishlisting "Blue / L" qualifies only "Blue / L" in the cart, while
     * wishlisting a variable parent on its own qualifies any of its variations.
     *
     * @since 4.8
     * @access private
     *
     * @return bool True if at least one cart item is wishlisted, false otherwise.
     */
    private function _cart_has_wishlisted_product() {
        $recorded   = $this->_get_recorded_cart_item_keys();
        $wishlisted = $this->_get_wishlisted_ids();

        if ( empty( $recorded ) && empty( $wishlisted['products'] ) && empty( $wishlisted['variations'] ) ) {
            return false;
        }

        foreach ( \WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            // The item was wishlisted when it entered the cart. SaveTo Wishlist Lite
            // deletes the wishlist row on its own add to cart button by default, so
            // the live wishlist alone would reject the flow this feature rewards.
            if ( isset( $recorded[ $cart_item_key ] ) ) {
                return true;
            }

            $product_id   = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
            $variation_id = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

            if ( $this->_is_wishlisted( $product_id, $variation_id, $wishlisted ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a product or variation ID is in the given wishlist sets.
     *
     * The match is variation precise. A variation matches only its own ID, because
     * 'products' holds only the rows wishlisted without a variation. So a sibling
     * variation of a wishlisted variation does not match.
     *
     * The wishlist sets are ID-keyed, so each check is a hash lookup. This matters
     * because the coupon validity filter runs per coupon, and Auto Apply and the
     * eligible-coupon list validate every published coupon.
     *
     * @since 4.8
     * @access private
     *
     * @param int   $product_id   Product ID.
     * @param int   $variation_id Variation ID, 0 when the product is not a variation.
     * @param array $wishlisted   Wishlist sets from _get_wishlisted_ids().
     * @return bool True if the ID pair is wishlisted, false otherwise.
     */
    private function _is_wishlisted( $product_id, $variation_id, array $wishlisted ) {
        return ( $variation_id > 0 && isset( $wishlisted['variations'][ $variation_id ] ) )
            || ( $product_id > 0 && isset( $wishlisted['products'][ $product_id ] ) );
    }

    /**
     * Record that a cart item was wishlisted when the customer added it to the cart.
     *
     * SaveTo Wishlist Lite deletes the wishlist row right after its own add to cart
     * button succeeds, because stwlite_settings_auto_remove_product_from_wishlist
     * defaults to "yes". This action fires inside WC_Cart::add_to_cart(), so the row
     * is still present here. The record keeps the cart item qualified for the rest of
     * the session, after the row is gone.
     *
     * The record holds cart item keys, not product IDs, so it does not change the
     * cart item hash and does not split a line into two.
     *
     * @since 4.8
     * @access public
     *
     * @param string $cart_item_key Cart item key.
     * @param int    $product_id    Product ID.
     * @param int    $quantity      Quantity added.
     * @param int    $variation_id  Variation ID, 0 when the product is not a variation.
     */
    public function record_wishlisted_cart_item( $cart_item_key, $product_id, $quantity, $variation_id ) {
        if ( ! function_exists( 'WC' ) || ! \WC()->session ) {
            return;
        }

        $wishlisted   = $this->_get_wishlisted_ids();
        $product_id   = absint( $product_id );
        $variation_id = absint( $variation_id );

        if ( ! $this->_is_wishlisted( $product_id, $variation_id, $wishlisted ) ) {
            return;
        }

        $recorded                   = $this->_get_recorded_cart_item_keys();
        $recorded[ $cart_item_key ] = true;

        \WC()->session->set( Plugin_Constants::WISHLISTED_CART_ITEMS_SESSION, $recorded );
    }

    /**
     * Get the recorded cart item keys that were wishlisted at add to cart time.
     *
     * Keys of items that left the cart are dropped on read, so the record cannot grow
     * without bound across a long session.
     *
     * @since 4.8
     * @access private
     *
     * @return array Cart item keys, used as array keys.
     */
    private function _get_recorded_cart_item_keys() {
        if ( ! function_exists( 'WC' ) || ! \WC()->session ) {
            return array();
        }

        $recorded = \WC()->session->get( Plugin_Constants::WISHLISTED_CART_ITEMS_SESSION, array() );

        if ( ! is_array( $recorded ) || empty( $recorded ) ) {
            return array();
        }

        if ( is_null( \WC()->cart ) ) {
            return $recorded;
        }

        $pruned = array_intersect_key( $recorded, \WC()->cart->get_cart() );

        if ( count( $pruned ) !== count( $recorded ) ) {
            \WC()->session->set( Plugin_Constants::WISHLISTED_CART_ITEMS_SESSION, $pruned );
        }

        return $pruned;
    }

    /**
     * Get the wishlisted product and variation IDs for the current customer.
     *
     * Runs a single bulk fetch through SaveTo Wishlist Lite's public API and memoizes
     * the result per user for the rest of the request.
     *
     * Guests are never passed to the API. SaveTo's query drops its user_id clause when
     * the ID is empty, so a guest call would return every customer's wishlist rows.
     *
     * SaveTo stores a variation add as two columns on one row: product_id holds the
     * variable parent and variation_id holds the chosen variation. To keep matching
     * variation precise, a row that names a variation contributes ONLY that variation,
     * never its parent. Otherwise wishlisting one variation would qualify all of them.
     *
     * @since 4.8
     * @access private
     *
     * @return array {
     *     @type array $products   IDs wishlisted without a variation, used as array keys.
     *     @type array $variations Wishlisted variation IDs, used as array keys.
     * }
     */
    private function _get_wishlisted_ids() {
        $user_id = get_current_user_id();
        $empty   = array(
            'products'   => array(),
            'variations' => array(),
        );

        // Guests have no server side wishlist in SaveTo Wishlist Lite.
        if ( $user_id < 1 ) {
            return $empty;
        }

        if ( isset( $this->_wishlisted_ids_by_user[ $user_id ] ) ) {
            return $this->_wishlisted_ids_by_user[ $user_id ];
        }

        // Defensive only: run() already gated on the plugin being active. Do not
        // memoize this, so a class that loads later in the request is not masked.
        if ( ! class_exists( '\SaveToWishlist\Classes\Factories\Collections' ) ) {
            return $empty;
        }

        $rows = \SaveToWishlist\Classes\Factories\Collections::instance()->get_products_in_wishlist(
            0,
            0,
            $user_id,
            array( 'product_id', 'variation_id' ),
            true,
            false,
            true
        );

        // The API returns null when its query fails, for example when the wishlist
        // table is missing because the plugin was never activated. Treat that as an
        // empty wishlist so the restriction still holds and no warning is raised.
        $rows = is_array( $rows ) ? $rows : array();

        $wishlisted = $empty;

        foreach ( $rows as $row ) {
            $product_id   = isset( $row->product_id ) ? absint( $row->product_id ) : 0;
            $variation_id = isset( $row->variation_id ) ? absint( $row->variation_id ) : 0;

            // Keyed by ID so lookups in _cart_has_wishlisted_product() are hash
            // lookups rather than a linear scan of a possibly large wishlist.
            if ( $variation_id > 0 ) {
                $wishlisted['variations'][ $variation_id ] = true;
            } elseif ( $product_id > 0 ) {
                $wishlisted['products'][ $product_id ] = true;
            }
        }

        $this->_wishlisted_ids_by_user[ $user_id ] = $wishlisted;

        return $wishlisted;
    }

    /**
     * Hook the add to cart recorder only when a coupon uses the restriction.
     *
     * Runs on wp_loaded, because the check queries the shop_coupon post type, which
     * WooCommerce registers on init. The recorded action fires later than wp_loaded
     * in every add to cart path, so nothing is missed.
     *
     * @since 4.8
     * @access public
     */
    public function maybe_hook_wishlisted_cart_item_recorder() {
        if ( ! $this->_any_coupon_uses_wishlist_restriction() ) {
            return;
        }

        add_action( 'woocommerce_add_to_cart', array( $this, 'record_wishlisted_cart_item' ), 10, 4 );
    }

    /**
     * Check if at least one published coupon enables the wishlist restriction.
     *
     * The add to cart recorder costs one wishlist query per add to cart, so it is
     * only worth hooking when a coupon actually uses the feature. The answer changes
     * only when the setting is written, so it is cached until then.
     *
     * @since 4.8
     * @access private
     *
     * @return bool True if a published coupon enables the restriction, false otherwise.
     */
    private function _any_coupon_uses_wishlist_restriction() {
        $cached = get_transient( Plugin_Constants::WISHLIST_RESTRICTION_IN_USE_CACHE );

        if ( false !== $cached ) {
            return 'yes' === $cached;
        }

        $query = new \WP_Query(
            array(
                'post_type'      => 'shop_coupon',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    array(
                        'key'   => Plugin_Constants::META_PREFIX . self::PROP,
                        'value' => 'yes',
                    ),
                ),
            )
        );

        $in_use = ! empty( $query->posts );

        set_transient( Plugin_Constants::WISHLIST_RESTRICTION_IN_USE_CACHE, $in_use ? 'yes' : 'no', DAY_IN_SECONDS );

        return $in_use;
    }

    /**
     * Clear the cached answer when the restriction setting is written.
     *
     * Hooked on the post meta actions rather than on a coupon save action, so every
     * write path clears the cache, including the REST API and an import.
     *
     * @since 4.8
     * @access public
     *
     * @param int|array $meta_id   Meta ID, or IDs when the meta is deleted.
     * @param int       $object_id Post ID.
     * @param string    $meta_key  Meta key.
     */
    public function maybe_flush_wishlist_restriction_cache( $meta_id, $object_id, $meta_key ) {
        if ( Plugin_Constants::META_PREFIX . self::PROP !== $meta_key ) {
            return;
        }

        delete_transient( Plugin_Constants::WISHLIST_RESTRICTION_IN_USE_CACHE );
    }

    /**
     * Clear the cached answer when a coupon changes status.
     *
     * The check counts published coupons only, so a draft that goes live, or a
     * coupon that leaves the trash, changes the answer without writing the meta.
     *
     * @since 4.8
     * @access public
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Old post status.
     * @param \WP_Post $post       Post object.
     */
    public function maybe_flush_wishlist_restriction_cache_on_status_change( $new_status, $old_status, $post ) {
        if ( $new_status === $old_status || ! $post instanceof \WP_Post || 'shop_coupon' !== $post->post_type ) {
            return;
        }

        delete_transient( Plugin_Constants::WISHLIST_RESTRICTION_IN_USE_CACHE );
    }

    /*
    |--------------------------------------------------------------------------
    | Admin field
    |--------------------------------------------------------------------------
     */

    /**
     * Register the wishlist restriction field in the usage restrictions tab.
     *
     * @since 4.8
     * @access public
     *
     * @param int $coupon_id Coupon ID.
     */
    public function register_wishlist_restriction_field( $coupon_id ) {
        $coupon = new Advanced_Coupon( $coupon_id );

        woocommerce_wp_checkbox(
            array(
                'id'          => Plugin_Constants::META_PREFIX . self::PROP,
                'label'       => __( 'Wishlisted products only', 'advanced-coupons-for-woocommerce-free' ),
                'description' => __( "When checked, this coupon only applies if the cart contains at least one product that is in the logged in customer's SaveTo Wishlist. Guests cannot use the coupon, because they have no saved wishlist.", 'advanced-coupons-for-woocommerce-free' ),
                'desc_tip'    => true,
                'value'       => $coupon->get_advanced_prop( self::PROP ),
            )
        );
    }

    /**
     * Register the wishlist restriction property to the acfw default data.
     *
     * @since 4.8
     * @access public
     *
     * @param array $data ACFW default data.
     * @return array Filtered ACFW default data.
     */
    public function register_wishlist_restriction_default_data( $data ) {
        $data[ self::PROP ] = '';

        return $data;
    }

    /**
     * Save the wishlist restriction setting for the coupon.
     *
     * @since 4.8
     * @access public
     *
     * @param int             $coupon_id Coupon ID.
     * @param Advanced_Coupon $coupon    Advanced coupon object.
     */
    public function save_wishlist_restriction_data( $coupon_id, $coupon ) {
        // The nonce is checked before this fires, in ACFWF\Models\Edit_Coupon.php.
        $data = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $key   = Plugin_Constants::META_PREFIX . self::PROP;
        $value = sanitize_text_field( wp_unslash( $data[ $key ] ?? '' ) );

        $coupon->set_advanced_prop( self::PROP, 'yes' === $value ? 'yes' : '' );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute SaveTo_Wishlist class.
     *
     * @since 4.8
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! $this->_helper_functions->is_plugin_active( Plugin_Constants::SAVETO_WISHLIST_LITE_PLUGIN ) ) {
            return;
        }

        // Admin.
        add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'register_wishlist_restriction_field' ) );
        add_filter( 'acfw_default_data', array( $this, 'register_wishlist_restriction_default_data' ) );
        add_action( 'acfw_save_coupon', array( $this, 'save_wishlist_restriction_data' ), 10, 2 );

        // Cache invalidation for the "is the feature in use" check.
        add_action( 'added_post_meta', array( $this, 'maybe_flush_wishlist_restriction_cache' ), 10, 3 );
        add_action( 'updated_post_meta', array( $this, 'maybe_flush_wishlist_restriction_cache' ), 10, 3 );
        add_action( 'deleted_post_meta', array( $this, 'maybe_flush_wishlist_restriction_cache' ), 10, 3 );
        add_action( 'transition_post_status', array( $this, 'maybe_flush_wishlist_restriction_cache_on_status_change' ), 10, 3 );

        // Frontend Implementation.
        add_action( 'wp_loaded', array( $this, 'maybe_hook_wishlisted_cart_item_recorder' ) );
        add_action( 'woocommerce_coupon_is_valid', array( $this, 'implement_wishlist_restriction' ), 10, 2 );
    }
}
