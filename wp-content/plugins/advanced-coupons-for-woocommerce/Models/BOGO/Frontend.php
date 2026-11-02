<?php
namespace ACFWP\Models\BOGO;

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
 * Model that houses the frontend logic of BOGO Deals.
 * Public Model.
 *
 * @since 2.6
 */
class Frontend extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that houses the model name to be used when calling publicly.
     *
     * @since 2.6
     * @access private
     * @var string
     */
    private $_model_name = 'BOGO_Frontend';

    /**
     * Property that houses the cache of products IDs under a category.
     * category_id => array( ...product_ids)
     *
     * @since 2.6
     * @access private
     * @var array
     */
    private $_category_products = array();

    /**
     * Property that houses the cache of products IDs under a brand.
     * brand_id => array( ...product_ids)
     *
     * @since 4.0.9
     * @access private
     * @var array
     */
    private $_brand_products = array();

    /**
     * Property that houses the cache of product variations of a list of variable products.
     * imploded_product_id => array( ...variation_product_ids)
     *
     * @since 2.6
     * @access private
     * @var array
     */
    private $_product_variations = array();

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 2.6
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
    | Advanced BOGO Deals data processing
    |--------------------------------------------------------------------------
     */

    /**
     * Prepare Advanced BOGO trigger data.
     *
     * @since 2.6
     * @since 3.4 Change implementation of product categories to assume it only has one row with multiple categories and quantity data is shared.
     * @access public
     *
     * @param array              $triggers       Trigger data.
     * @param array              $raw_triggers   Raw trigger data.
     * @param string             $trigger_type   Trigger type.
     * @param Abstract_BOGO_Deal $bogo_deal BOGO Deal object.
     * @return array Prepared trigger data.
     */
    public function prepare_advanced_bogo_trigger_data( $triggers, $raw_triggers, $trigger_type, $bogo_deal ) {
        // If deal type is same products, the trigger data is already prepared in the Same_Products class.
        if ( 'same-products' === $bogo_deal->deal_type ) {
            return $triggers;
        }

        switch ( $trigger_type ) {

            case 'combination-products':
                $product_ids = array_column( $raw_triggers['products'], 'product_id' );
                $product_ids = array_unique( array_merge( $product_ids, $this->_get_variation_ids_for_variable_products( $product_ids ) ) );

                $triggers[] = \ACFWF()->Helper_Functions->format_bogo_trigger_deal_entry(
                    array(
                        'ids'      => $product_ids,
                        'quantity' => $raw_triggers['quantity'],
                    )
                );

                break;

            case 'product-categories':
                $category_ids = array_column( $raw_triggers['categories'], 'category_id' );
                $product_ids  = array_reduce(
                    $category_ids,
                    function ( $c, $id ) {
                    return array_merge( $c, $this->_get_products_under_category( $id ) );
                    },
                    array()
                );

                $triggers[] = \ACFWF()->Helper_Functions->format_bogo_trigger_deal_entry(
                    array(
                        'ids'      => array_unique( $product_ids ),
                        'quantity' => $raw_triggers['quantity'],
                    )
                );

                break;

            case 'product-brands':
                $brand_ids   = array_column( $raw_triggers['brands'], 'brand_id' );
                $product_ids = array_reduce(
                    $brand_ids,
                    function ( $c, $id ) {
                    return array_merge( $c, $this->_get_products_under_brand( $id ) );
                    },
                    array()
                );

                $triggers[] = \ACFWF()->Helper_Functions->format_bogo_trigger_deal_entry(
                    array(
                        'ids'      => array_unique( $product_ids ),
                        'quantity' => $raw_triggers['quantity'],
                    )
                );

                break;

            case 'any-products':
                $cart_item_ids = $this->get_cart_item_ids( true );
                $triggers[]    = \ACFWF()->Helper_Functions->format_bogo_trigger_deal_entry(
                    array(
                        'ids'      => $cart_item_ids,
                        'quantity' => $raw_triggers['quantity'],
                    )
                );

                break;
        }

        return $triggers;
    }

    /**
     * Prepare Advanced BOGO deal data.
     *
     * @since 2.6
     * @since 3.4 Change implementation of product categories to assume it only has one row with multiple categories and the quantity and discounts data are shared.
     * @access public
     *
     * @param array  $deals     Deal data.
     * @param array  $raw_deals  Raw deal data.
     * @param string $deal_type Deal type.
     * @return array Prepared Deal data.
     */
    public function prepare_advanced_bogo_deal_data( $deals, $raw_deals, $deal_type ) {
        switch ( $deal_type ) {

            case 'combination-products':
                $product_ids = array_column( $raw_deals['products'], 'product_id' );
                $product_ids = array_unique( array_merge( $product_ids, $this->_get_variation_ids_for_variable_products( $product_ids ) ) );
                $deals[]     = \ACFWF()->Helper_Functions->format_bogo_trigger_deal_entry(
                    array(
                        'ids'      => $product_ids,
                        'quantity' => $raw_deals['quantity'],
                        'discount' => $raw_deals['discount_value'],
                        'type'     => $raw_deals['discount_type'],
                    ),
                    true
                );
                break;

            case 'product-categories':
                $category_ids = array_column( $raw_deals['categories'], 'category_id' );
                $product_ids  = array_reduce(
                    $category_ids,
                    function ( $c, $id ) {
                    return array_merge( $c, $this->_get_products_under_category( $id ) );
                    },
                    array()
                );

                $deals[] = \ACFWF()->Helper_Functions->format_bogo_trigger_deal_entry(
                    array(
                        'ids'      => array_unique( $product_ids ),
                        'quantity' => $raw_deals['quantity'],
                        'discount' => $raw_deals['discount_value'],
                        'type'     => $raw_deals['discount_type'],
                    ),
                    true
                );

                break;

            case 'product-brands':
                $brand_ids   = array_column( $raw_deals['brands'], 'brand_id' );
                $product_ids = array_reduce(
                    $brand_ids,
                    function ( $c, $id ) {
                    return array_merge( $c, $this->_get_products_under_brand( $id ) );
                    },
                    array()
                );

                $deals[] = \ACFWF()->Helper_Functions->format_bogo_trigger_deal_entry(
                    array(
                        'ids'      => array_unique( $product_ids ),
                        'quantity' => $raw_deals['quantity'],
                        'discount' => $raw_deals['discount_value'],
                        'type'     => $raw_deals['discount_type'],
                    ),
                    true
                );

                break;

            case 'any-products':
                $product_ids = array_values(
                    array_map(
                        function ( $i ) {
                            return isset( $i['variation_id'] ) && $i['variation_id'] ? $i['variation_id'] : $i['product_id'];
                        },
                        \WC()->cart->get_cart_contents()
                    )
                );

                $deals[] = \ACFWF()->Helper_Functions->format_bogo_trigger_deal_entry(
                    array(
                        'ids'      => $product_ids,
                        'quantity' => $raw_deals['quantity'],
                        'discount' => $raw_deals['discount_value'],
                        'type'     => $raw_deals['discount_type'],
                    ),
                    true
                );
                break;
        }

        return $deals;
    }

    /**
     * Check if the provided cart item matches the items set in the trigger/deal entry.
     *
     * @since 1.6
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
    public function advanced_bogo_is_cart_item_match_entries( $matched, $cart_item, $entry, $is_deal, $type, $bogo_deal ) {
        if ( 'same-products' === $type || 'same-products' === $bogo_deal->deal_type ) {
            return $matched;
        }

        switch ( $type ) {
            case 'combination-products':
                $product_id   = apply_filters( 'acfw_filter_cart_item_product_id', $cart_item['product_id'] ); // filter for WPML support.
                $variation_id = apply_filters( 'acfw_filter_cart_item_product_id', $cart_item['variation_id'] ); // filter for WPML support.

                // Convert to integers to ensure type consistency.
                $product_id_int   = (int) $product_id;
                $variation_id_int = (int) $variation_id;
                $entry_ids_int    = array_map( 'intval', $entry['ids'] );

                $intersect = array_intersect( array( $product_id_int, $variation_id_int ), $entry_ids_int );
                return ! empty( $intersect ) ? current( $intersect ) : false;

            case 'product-categories':
            case 'product-brands':
                $product_id = apply_filters( 'acfw_filter_cart_item_product_id', $cart_item['product_id'] ); // filter for WPML support.

                // Convert to integers to ensure type consistency.
                $product_id_int = (int) $product_id;
                $entry_ids_int  = array_map( 'intval', $entry['ids'] );

                return in_array( $product_id_int, $entry_ids_int, true ) ? $product_id_int : false;

            case 'any-products':
                $item_id = isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
                return apply_filters( 'acfw_filter_cart_item_product_id', $item_id ); // explicitly just return the cart item ID (true) as this will always be matched.
        }

        return $matched;
    }

    /*
    |--------------------------------------------------------------------------
    | Implementation related methods.
    |--------------------------------------------------------------------------
     */

    /**
     * Filter BOGO Deals is item valid utility function.
     *
     * @since 2.6
     * @access public
     *
     * @param bool  $is_valid Filter value.
     * @param array $item     Cart item.
     * @return bool Filtered value.
     */
    public function filter_bogo_is_item_valid( $is_valid, $item ) {
        // explicity return false if item is added via "Add Products" with discount.
        if ( isset( $item['acfw_add_product'] ) ) {
            return false;
        }

        return $is_valid;
    }

    /**
     * Auto add deal products to cart when deal type is set to 'specific-products'.
     *
     * @since 2.6
     * @access public
     *
     * @param Abstract_BOGO_Deal $bogo_deal BOGO Deal object.
     */
    public function auto_add_deal_products_to_cart( Abstract_BOGO_Deal $bogo_deal ) {
        // skip if deals already verified or if triggers are not verified.
        if ( 0 >= $bogo_deal->get_allowed_deal_quantity() || 0 < $bogo_deal->get_needed_trigger_quantity() ) {
            return;
        }

        $coupon = $bogo_deal->get_coupon();
        $coupon = $coupon instanceof Advanced_Coupon ? $coupon : new Advanced_Coupon( $coupon ); // use ACFWP version of Advanced_Coupon.

        // Don't proceed when setting is not enabled or when deal type is not specific products.
        if ( ! $coupon->get_advanced_prop( 'bogo_auto_add_products' ) || 'specific-products' !== $bogo_deal->deal_type ) {
            return;
        }

        $calculation   = Calculation::get_instance(); // get the calculation object instance from ACFWF.
        $cart_contents = \ACFWF()->Helper_Functions->sort_cart_items_by_price( \WC()->cart->get_cart(), 'asc' );

        // remove main implementation hook to prevent infinite loop.
        remove_action( 'woocommerce_before_calculate_totals', array( \ACFWF()->BOGO_Frontend, 'implement_bogo_deals' ), apply_filters( 'acfw_bogo_implementation_priority', 11 ) );

        foreach ( $bogo_deal->deals as $deal ) {

            // get cart item that matches the specific product deal.
            $cart_item = current(
                array_filter(
                    $cart_contents,
                    function ( $i ) use ( $deal, $calculation ) {
                        $intersect = array_intersect( array( $i['product_id'], $i['variation_id'] ), $deal['ids'] );
                        return $calculation->is_item_valid( $i ) && ! empty( $intersect );
                    }
                )
            );

            // calculate missing quantity of deal item that needs to be added to the cart.
            $allowed_qty = $bogo_deal->get_allowed_deal_quantity( $deal['entry_id'] );
            $qty_to_add  = $allowed_qty > 0 ? max( 0, $allowed_qty - $calculation->calculate_cart_item_spare_quantity( $cart_item ) ) : 0;

            // Add product quantity to cart if there are no spare quantity for the deal item.
            if ( $qty_to_add > 0 ) {

                // get product and variation id values.
                if ( ! empty( $cart_item ) ) {
                    $product_id   = $cart_item['product_id'];
                    $variation_id = $cart_item['variation_id'];
                } elseif ( 'product_variation' === get_post_type( current( $deal['ids'] ) ) ) {
                    $variation_id = current( $deal['ids'] );
                    $product_id   = wp_get_post_parent_id( $variation_id );
                } else {
                    $product_id   = current( $deal['ids'] );
                    $variation_id = 0;
                }

                // add deal product to cart.
                $variation_data = apply_filters( 'acfw_bogo_auto_add_product_variation_data', array(), $variation_id );
                $cart_key       = \WC()->cart->add_to_cart( $product_id, $qty_to_add, $variation_id, $variation_data );
                $cart_item      = \WC()->cart->get_cart_item( $cart_key );

                // set allowed deal quantity to 0 cause we already added the missing quantity.
                $bogo_deal->set_allowed_deal_quantity( $deal['entry_id'], 0 );

                // add the added quantity to the calculation temporary entries.
                $calculation->update_entry(
                    array(
                        'key'           => $cart_item['key'],
                        'coupon'        => $coupon->get_code(),
                        'entry_id'      => $deal['entry_id'],
                        'type'          => 'deal',
                        'quantity'      => $qty_to_add,
                        'discount'      => $deal['discount'],
                        'discount_type' => $deal['type'],
                    ),
                    'temp'
                );
            }
        }

        // re-add implementation hook.
        add_action( 'woocommerce_before_calculate_totals', array( \ACFWF()->BOGO_Frontend, 'implement_bogo_deals' ), apply_filters( 'acfw_bogo_implementation_priority', 11 ) );
    }

    /*
    |--------------------------------------------------------------------------
    | DB Queries
    |--------------------------------------------------------------------------
     */

    /**
     * Get products IDs under a specific category.
     * NOTE: We use this function so we data is explicitly fetched without filtering from 3rd party plugins.
     *
     * @since 2.6
     * @access private
     *
     * @param int $category_id Category ID.
     */
    private function _get_products_under_category( $category_id ) {
        global $wpdb;

        // if products IDs for category have already been queried before, then return cached value.
        // Use array_key_exists() so a category with zero products is cached too (empty result is valid).
        if ( array_key_exists( $category_id, $this->_category_products ) ) {
            return $this->_category_products[ $category_id ];
        }

        $category_id   = absint( $category_id );
        $term_children = get_term_children( $category_id, 'product_cat' );

        if ( is_wp_error( $term_children ) || empty( $term_children ) ) {
            $categories = array( $category_id );
        } else {
            $categories = array_merge( array( $category_id ), $term_children );
        }

        $cats_string = implode( ',', $categories );
        $query       = "SELECT p.ID FROM {$wpdb->posts} AS p
            INNER JOIN {$wpdb->term_relationships} AS tr ON (p.ID = tr.object_id)
            INNER JOIN {$wpdb->term_taxonomy} AS tx ON (tr.term_taxonomy_id = tx.term_taxonomy_id)
            INNER JOIN {$wpdb->terms} AS t ON (tx.term_id = t.term_id)
            WHERE t.term_id IN ({$cats_string})
                AND tx.taxonomy = 'product_cat'
        ";

        $product_ids = array_map( 'absint', $wpdb->get_col( $query ) ); // phpcs:ignore

        $product_ids = apply_filters( 'acfwp_bogo_get_products_under_category', $product_ids, $category_id );

        // save the (filtered) product ids under category to cache so cached and uncached calls stay consistent.
        $this->_category_products[ $category_id ] = $product_ids;

        return $product_ids;
    }

    /**
     * Get products IDs under a specific brand.
     * NOTE: We use this function so data is explicitly fetched without filtering from 3rd party plugins.
     *
     * @since 4.0.9
     * @access private
     *
     * @param int $brand_id Brand term ID.
     * @return array List of product IDs under the brand.
     */
    private function _get_products_under_brand( $brand_id ) {
        // if products IDs for brand have already been queried before, then return cached value.
        // Use array_key_exists() so a brand with zero products is cached too (empty result is valid).
        if ( array_key_exists( $brand_id, $this->_brand_products ) ) {
            return $this->_brand_products[ $brand_id ];
        }

        $taxonomy = \ACFWF()->Helper_Functions->get_product_brand_taxonomy();

        if ( ! $taxonomy ) {
            return array();
        }

        $brand_id      = absint( $brand_id );
        $term_children = get_term_children( $brand_id, $taxonomy );

        if ( is_wp_error( $term_children ) || empty( $term_children ) ) {
            $brands = array( $brand_id );
        } else {
            $brands = array_merge( array( $brand_id ), $term_children );
        }

        // Fetch object IDs directly from the term relationships, mirroring the category query
        // so the result is not filtered by 3rd party visibility/query hooks.
        $object_ids  = get_objects_in_term( $brands, $taxonomy );
        $product_ids = is_wp_error( $object_ids ) ? array() : array_map( 'absint', $object_ids );

        $product_ids = apply_filters( 'acfwp_bogo_get_products_under_brand', $product_ids, $brand_id, $taxonomy );

        // save the (filtered) product ids under brand to cache so cached and uncached calls stay consistent.
        $this->_brand_products[ $brand_id ] = $product_ids;

        return $product_ids;
    }

    /**
     * Public accessor for product IDs under a category.
     * Lets the Same Products BOGO type reuse the same category resolution (incl. child
     * categories and cache) instead of duplicating the query.
     *
     * @since 4.0.9
     * @access public
     *
     * @param int $category_id Category ID.
     * @return array Product IDs under the category.
     */
    public function get_products_under_category( $category_id ) {
        return $this->_get_products_under_category( $category_id );
    }

    /**
     * Get variation ids for given variable product ids.
     *
     * @since 2.6
     * @access private
     *
     * @param array $product_ids Variable product IDs.
     * @return array Relative product variation IDs.
     */
    private function _get_variation_ids_for_variable_products( $product_ids ) {
        global $wpdb;

        $imploded_ids = implode( ',', $product_ids );

        // if variation IDs for parent products have already been queried before, then return cached value.
        if ( isset( $this->_product_variations[ $imploded_ids ] ) ) {
            return $this->_product_variations[ $imploded_ids ];
        }

        $query = "SELECT ID FROM {$wpdb->posts}
            WHERE post_parent IN (
                SELECT tr.object_id FROM {$wpdb->term_relationships} AS tr
                INNER JOIN {$wpdb->terms} AS t ON (t.slug = 'variable')
                INNER JOIN {$wpdb->term_taxonomy} AS tx ON (t.term_id = tx.term_id AND tx.taxonomy = 'product_type')
                WHERE tr.term_taxonomy_id = t.term_id
                    AND tr.object_id IN ({$imploded_ids})
        )";

        // save product ids under imploded ids to cache.
        $this->_product_variations[ $imploded_ids ] = array_map( 'absint', $wpdb->get_col( $query ) ); // phpcs:ignore

        return $this->_product_variations[ $imploded_ids ];
    }

    /**
     * Sort cart items order.
     *
     * @since 4.0.3
     * @access public
     *
     * @param array              $sort_order Sort order.
     * @param string             $context Context.
     * @param Abstract_BOGO_Deal $bogo_deal BOGO deal.
     * @return array Sorted cart items.
     */
    public function sort_cart_items_order( $sort_order, $context, $bogo_deal ) {
        if ( 'deal' === $context ) {
            // we do this because $bogo_deal->get_coupon() is an instance of ACFWF\Models\Objects\Advanced_Coupon.
            // which does not have advanced properties bogo_discount_order.
            $coupon_code         = $bogo_deal->get_coupon()->get_code();
            $coupon              = new Advanced_Coupon( $coupon_code );
            $bogo_discount_order = $coupon->get_advanced_prop( 'bogo_discount_order' );
            $sort_order          = 'expensive' === $bogo_discount_order ? 'desc' : 'asc';
        }

        return $sort_order;
    }

    /*
    |--------------------------------------------------------------------------
    | Utilities
    |--------------------------------------------------------------------------
     */

    /**
     * Compare category triggers/deals by number of products under it.
     * NOTE: this is to prioritize categories that has lower product count.
     *
     * @since 2.6
     * @access private
     *
     * @param int $a Category trigger/deal 1.
     * @param int $b Category trigger/deal 2.
     * @return int Sort comparision value.
     */
    public function compare_categories_by_product_count( $a, $b ) {
        if ( $a['category_id'] === $b['category_id'] ) {
            return 0;
        }

        $a_products = $this->_get_products_under_category( $a['category_id'] );
        $b_products = $this->_get_products_under_category( $b['category_id'] );

        return count( $a_products ) < count( $b_products ) ? -1 : 1;
    }

    /**
     * Get product or varation IDs from cart session.
     *
     * @since 2.6
     * @access public
     *
     * @param bool $is_variation Toggle if to get variation id instead if available.
     * @return array List of cart item IDs.
     */
    public function get_cart_item_ids( $is_variation = true ) {
        if ( $is_variation ) {
            return array_values(
                array_map(
                    function ( $i ) {
                        return isset( $i['variation_id'] ) && $i['variation_id'] ? $i['variation_id'] : $i['product_id'];
                    },
                    \WC()->cart->get_cart_contents()
                )
            );
        }

        return array_column( \WC()->cart->get_cart_contents(), 'product_id' );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Frontend class.
     *
     * @since 2.6
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::BOGO_DEALS_MODULE ) ) {
            return;
        }

        add_filter( 'acfw_bogo_advanced_prepare_trigger_data', array( $this, 'prepare_advanced_bogo_trigger_data' ), 10, 4 );
        add_filter( 'acfw_bogo_advanced_prepare_deal_data', array( $this, 'prepare_advanced_bogo_deal_data' ), 10, 3 );
        add_filter( 'acfw_bogo_is_cart_item_match_entries', array( $this, 'advanced_bogo_is_cart_item_match_entries' ), 10, 6 );

        add_action( 'acfw_bogo_after_verify_trigger_deals', array( $this, 'auto_add_deal_products_to_cart' ) );
        add_filter( 'acfw_bogo_sort_cart_items_order', array( $this, 'sort_cart_items_order' ), 10, 3 );

        add_filter( 'acfw_bogo_is_item_valid', array( $this, 'filter_bogo_is_item_valid' ), 10, 2 );
    }
}
