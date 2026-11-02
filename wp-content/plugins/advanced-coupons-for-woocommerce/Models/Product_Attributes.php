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
 * Model that houses the logic of product attributes for coupon system of woocommerce.
 *
 * @since 3.6.1
 */
class Product_Attributes extends Base_Model implements Model_Interface, Initiable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */


    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.6.1
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
    | Implementation
    |--------------------------------------------------------------------------
     */

    /**
     * Get products by attributes.
     *
     * Retrieves a list of product IDs from the cart that match the specified attributes.
     *
     * @since 3.6.1
     * @access private
     *
     * @param array $cart_products Array of products in the cart.
     * @param array $attributes Array of attributes in the format 'taxonomy-term_id'.
     * @return array List of product IDs that match the specified attributes.
     */
    private function _get_products_by_attributes( $cart_products, $attributes ) {
        $matching_product_ids = array();

        // Return an empty array if no attributes are provided.
        if ( empty( $attributes ) ) {
            return $matching_product_ids;
        }

        // Loop through each product in the cart.
        foreach ( $cart_products as $cart_item ) {
            $product_attributes = $cart_item['data']->get_attributes();

            // Normalize the attributes from the product to match the format of the coupon attributes.
            $normalized_attributes = $this->_normalize_product_attributes( $product_attributes );

            // Check if any of the attributes match.
            foreach ( $attributes as $attribute ) {
                list( $taxonomy, $term_id ) = $this->_helper_functions->parse_product_attribute_string( $attribute );

                // Get the term ID if the attribute is a name instead of ID.
                if ( isset( $normalized_attributes[ $taxonomy ] ) ) {
                    foreach ( $normalized_attributes[ $taxonomy ] as $value ) {
                        // If the value is a term name, convert it to its ID.
                        $term_id_to_compare = is_numeric( $value ) ? $value : $this->_get_term_id_by_name( $taxonomy, $value );

                        if ( (int) $term_id_to_compare === (int) $term_id ) {
                            $matching_product_ids[] = $cart_item['product_id'];
                            break 2; // Stop checking further if a match is found.
                        }
                    }
                }
            }
        }

        return $matching_product_ids;
    }

    /**
     * Check if product attributes match any in the given include list.
     *
     * @since 3.6.1
     * @access private
     *
     * @param WC_Product $product The WooCommerce product object.
     * @param array      $product_attributes_to_check The list of attributes to check for inclusion.
     * @return bool True if there's a match, false otherwise.
     */
    private function _check_if_product_attributes_match( $product, $product_attributes_to_check ) {
        // Get the product attributes.
        $attributes = $product->get_attributes();

        // Normalize the attributes from the product to match the format of the coupon attributes.
        $normalized_attributes = $this->_normalize_product_attributes( $attributes );

        // Prepare an array to hold formatted attributes.
        $product_attribute_ids = array();

        // Check if any of the attributes match.
        foreach ( $product_attributes_to_check as $attribute ) {
            list( $taxonomy, $term_id ) = $this->_helper_functions->parse_product_attribute_string( $attribute );

            // Get the term ID if the attribute is a name instead of ID.
            if ( isset( $normalized_attributes[ $taxonomy ] ) ) {
                foreach ( $normalized_attributes[ $taxonomy ] as $value ) {
                    // If the value is a term name, convert it to its ID.
                    $term_id_to_compare = is_numeric( $value ) ? $value : $this->_get_term_id_by_name( $taxonomy, $value );

                    // If the term ID matches, add it to the list of matched IDs.
                    if ( (int) $term_id_to_compare === (int) $term_id ) {
                        $product_attribute_ids[] = $attribute;
                    }
                }
            }
        }

        // Return true if there is any match.
        return ! empty( $product_attribute_ids );
    }

    /**
     * Normalize product attributes to a consistent format.
     *
     * @param array $product_attributes The attributes of the product.
     * @return array Normalized attributes where keys are taxonomy names and values are arrays of term IDs.
     */
    private function _normalize_product_attributes( $product_attributes ) {
        $normalized_attributes = array();

        foreach ( $product_attributes as $taxonomy => $attribute ) {
            if ( $attribute instanceof \WC_Product_Attribute ) {
                // If the attribute is an object, extract the options (term IDs).
                $normalized_attributes[ $taxonomy ] = $attribute->get_options();
            } else {
                // If the attribute is a simple key-value pair, wrap the value in an array.
                $normalized_attributes[ $taxonomy ] = array( $attribute );
            }
        }

        return $normalized_attributes;
    }

    /**
     * Get term ID by term name.
     *
     * @param string $taxonomy Taxonomy of the term.
     * @param string $term_name Term name.
     * @return int Term ID.
     */
    private function _get_term_id_by_name( $taxonomy, $term_name ) {
        $term = get_term_by( 'slug', $term_name, $taxonomy );
        return $term ? $term->term_id : 0;
    }

    /**
     * Implement product attributes feature.
     *
     * @since 3.6.1
     * @access public
     *
     * @param bool      $value Filter return value.
     * @param WC_Coupon $coupon WC_Coupon object.
     * @return bool True if valid, false otherwise.
     * @throws \Exception Error message.
     */
    public function implement_product_attributes_coupon( $value, $coupon ) {
        // don't proceed if we're not running this on normal cart/checkout environment.
        if ( ! \WC()->cart ) {
            return $value;
        }

        // Create an Advanced_Coupon object from the provided coupon.
        $coupon = new Advanced_Coupon( $coupon );

        // Get included and excluded product attributes from the coupon.
        $product_attribute_include = $coupon->get_advanced_prop( 'product_attributes', array() );
        $product_attribute_include = apply_filters( 'acfwp_coupon_product_attributes', $product_attribute_include, $coupon );
        $product_attribute_exclude = $coupon->get_advanced_prop( 'excluded_product_attributes', array() );
        $product_attribute_exclude = apply_filters( 'acfwp_coupon_excluded_product_attributes', $product_attribute_exclude, $coupon );

        // Get all products in the cart.
        $cart_products = WC()->cart->get_cart();

        // Get product IDs matching the included and excluded product attributes.
        $included_product_ids = $this->_get_products_by_attributes( $cart_products, $product_attribute_include );
        $excluded_product_ids = $this->_get_products_by_attributes( $cart_products, $product_attribute_exclude );

        $coupon_code = '<strong>' . $coupon->get_code() . '</strong>';

        // If included products are specified and none are found, throw an error.
        if ( ! empty( $product_attribute_include ) && empty( $included_product_ids ) ) {
            throw new \Exception(
                wp_kses_post(
                    sprintf(
                    /* Translators: %s: Coupon code. */
                        __( "The %s coupon can't be applied. This coupon requires specific product attribute(s) that are not present in your cart.", 'advanced-coupons-for-woocommerce' ),
                        $coupon_code
                    )
                )
            );
        }

        // If excluded product attributes are specified and matching products are found, and the coupon is not
        // of a per item type, throw an error. Per item types skip just the offending item instead, which is
        // handled by restrict_product_attributes_discount() on woocommerce_coupon_is_valid_for_product.
        if ( ! empty( $product_attribute_exclude ) && ! empty( $excluded_product_ids ) && ! in_array( $coupon->get_discount_type(), array( 'fixed_product', 'percent', 'acfw_percentage_cashback', Product_Discount_Rules::DISCOUNT_TYPE ), true ) ) {
            throw new \Exception(
                wp_kses_post(
                    sprintf(
                    /* Translators: %s: Coupon code. */
                        __( "The %s coupon can't be applied. This coupon is not allowed to be used with certain product attribute(s) present in your cart.", 'advanced-coupons-for-woocommerce' ),
                        $coupon_code
                    )
                )
            );
        }

        return $value;
    }

    /**
     * Restrict product attributes discount.
     *
     * @since 3.6.1
     * @access public
     *
     * @param boolean     $valid Filter return value.
     * @param \WC_Product $product Product object.
     * @param \WC_Coupon  $coupon WC_Coupon object.
     * @param array       $values  Values.
     * @return bool Filtered valid value.
     */
    public function restrict_product_attributes_discount( $valid, $product, $coupon, $values ) {

        // Create an Advanced_Coupon object from the provided coupon.
        $coupon = new Advanced_Coupon( $coupon );

        // Get included and excluded product attributes from the coupon.
        $product_attribute_include = $coupon->get_advanced_prop( 'product_attributes', array() );
        $product_attribute_include = apply_filters( 'acfwp_coupon_product_attributes', $product_attribute_include, $coupon );
        $product_attribute_exclude = $coupon->get_advanced_prop( 'excluded_product_attributes', array() );
        $product_attribute_exclude = apply_filters( 'acfwp_coupon_excluded_product_attributes', $product_attribute_exclude, $coupon );

        // Check for included and excluded product attributes.
        $has_included_attributes = $this->_check_if_product_attributes_match( $product, $product_attribute_include );
        $has_excluded_attributes = $this->_check_if_product_attributes_match( $product, $product_attribute_exclude );

        // Determine the validity based on included and excluded attributes.
        if ( ! empty( $product_attribute_include ) && ! $has_included_attributes ) {
            return false;
        }
        if ( ! empty( $product_attribute_exclude ) && $has_excluded_attributes ) {
            return false;
        }

        return $valid;
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX Functions
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX search product attributes.
     *
     * @since 3.6.1
     * @access public
     */
    public function ajax_search_product_attributes() {
        check_ajax_referer( 'search-products', 'security' );

        if ( ! isset( $_GET['term'] ) || empty( $_GET['term'] ) ) {
            wp_die();
        }

        $search     = sanitize_text_field( wp_unslash( $_GET['term'] ) );
        $attributes = wc_get_attribute_taxonomies();
        $results    = array();

        foreach ( $attributes as $attribute ) {
            $taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );

            // Search terms.
            $terms = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'name__like' => $search,
                    'hide_empty' => false,
                )
            );

            foreach ( $terms as $term ) {
                $key             = $term->taxonomy . '-' . $term->term_id;
                $results[ $key ] = $attribute->attribute_label . ': ' . $term->name;
            }

            // Search taxonomies.
            if ( stripos( $attribute->attribute_label, $search ) !== false ) {
                $terms = get_terms(
                    array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                    )
                );

                foreach ( $terms as $term ) {
                    $key = $term->taxonomy . '-' . $term->term_id;
                    if ( ! isset( $results[ $key ] ) ) {
                        $results[ $key ] = $attribute->attribute_label . ': ' . $term->name;
                    }
                }
            }
        }

        wp_send_json( apply_filters( 'acfw_json_search_product_attributes_response', array_unique( $results ) ) );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 3.6.1
     * @access public
     * @implements ACFWP\Interfaces\Initializable_Interface
     */
    public function initialize() {
        add_action( 'wp_ajax_acfw_search_product_attributes', array( $this, 'ajax_search_product_attributes' ) );
    }

    /**
     * Execute Product_Attributes class.
     *
     * @since 3.6.1
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'woocommerce_coupon_is_valid', array( $this, 'implement_product_attributes_coupon' ), 10, 2 );
        add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'restrict_product_attributes_discount' ), -99, 4 );
    }
}
