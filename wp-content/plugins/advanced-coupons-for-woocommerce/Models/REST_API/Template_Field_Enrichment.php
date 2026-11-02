<?php
namespace ACFWP\Models\REST_API;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Template Field Enrichment
 *
 * Handles field value enrichment for AI-generated coupon templates.
 * Hooks into the free plugin's Template_Processor to enrich field values
 * with labels for select-type fields (products, categories, coupons).
 *
 * @since 4.1
 */
class Template_Field_Enrichment extends Base_Model implements Model_Interface {

    /**
     * Field-to-entity-type mapping for enrichment.
     *
     * @since 4.1
     * @var array
     */
    const ENRICHABLE_FIELDS = array(
        'product_ids'                 => 'product',
        'excluded_product_ids'        => 'product',
        'excluded_products'           => 'product',
        'product_categories'          => 'category',
        'excluded_product_categories' => 'category',
        'allowed_coupons'             => 'coupon',
        'disallowed_coupons'          => 'coupon',
    );

    /**
     * Cart condition type-to-entity-type mapping for enrichment.
     *
     * @since 4.1
     * @var array
     */
    const ENRICHABLE_CONDITIONS = array(
        'product-category'          => 'category',
        'product-category-quantity' => 'category',
        'product-category-subtotal' => 'category',
    );

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
        $this->_constants        = $constants;
        $this->_helper_functions = $helper_functions;
        $main_plugin->add_to_all_plugin_models( $this );
    }

    /**
     * Enrich field value for AI-generated templates.
     *
     * Handles field value enrichment for AI-generated coupon templates (premium feature).
     * Transforms raw IDs into { label, value } objects for frontend Select components.
     *
     * @since 4.1
     * @access public
     *
     * @param mixed  $raw_value      The original raw value.
     * @param string $field_name     The field name being enriched.
     * @param array  $raw_template   The complete raw template data.
     * @return mixed Enriched value or original value if no enrichment needed.
     */
    public function enrich_field_value( $raw_value, $field_name, $raw_template ) {
        // Only handle enrichment for AI-generated templates (premium feature).
        if ( ! is_array( $raw_template ) || ! $this->_is_ai_template( $raw_template ) ) {
            return $raw_value;
        }

        // If not an enrichable field, return as-is.
        if ( ! isset( self::ENRICHABLE_FIELDS[ $field_name ] ) ) {
            return $raw_value;
        }

        $entity_type = self::ENRICHABLE_FIELDS[ $field_name ];

        // Handle empty values.
        if ( empty( $raw_value ) ) {
            return array(); // Return empty array for multi-select fields.
        }

        // Normalize to array.
        $ids = is_array( $raw_value ) ? $raw_value : array( $raw_value );

        // If already enriched, return as-is.
        if ( $this->_is_already_enriched( $ids ) ) {
            return $ids;
        }

        // Enrich based on entity type.
        switch ( $entity_type ) {
            case 'product':
                return $this->_enrich_product_ids( $ids );
            case 'category':
                return $this->_enrich_category_ids( $ids );
            case 'coupon':
                return $this->_enrich_coupon_ids( $ids );
            default:
                return $ids;
        }
    }

    /**
     * Enrich product IDs with product names.
     *
     * Invalid product IDs are removed from the result.
     *
     * @since 4.1
     * @access private
     *
     * @param array $product_ids Array of product IDs.
     * @return array Array of objects with 'label' and 'value' keys.
     */
    private function _enrich_product_ids( $product_ids ) {
        // Filter to valid integer IDs.
        $valid_ids = array_filter( array_map( 'absint', $product_ids ) );

        if ( empty( $valid_ids ) ) {
            return array();
        }

        // Batch fetch products in a single query.
        $products = wc_get_products(
            array(
                'include' => $valid_ids,
                'limit'   => -1,
            )
        );

        $enriched = array();
        foreach ( $products as $product ) {
            $enriched[] = array(
                'label' => $product->get_name(),
                'value' => (string) $product->get_id(),
            );
        }

        return $enriched;
    }

    /**
     * Enrich category IDs with category names.
     *
     * Invalid category IDs are removed from the result.
     *
     * @since 4.1
     * @access private
     *
     * @param array $category_ids Array of category IDs (term IDs).
     * @return array Array of objects with 'label' and 'value' keys.
     */
    private function _enrich_category_ids( $category_ids ) {
        // Filter to valid integer IDs.
        $valid_ids = array_filter( array_map( 'absint', $category_ids ) );

        if ( empty( $valid_ids ) ) {
            return array();
        }

        // Batch fetch terms in a single query.
        $terms = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'include'    => $valid_ids,
                'hide_empty' => false,
            )
        );

        if ( is_wp_error( $terms ) ) {
            return array();
        }

        $enriched = array();
        foreach ( $terms as $term ) {
            $enriched[] = array(
                'label' => $term->name,
                'value' => (string) $term->term_id,
            );
        }

        return $enriched;
    }

    /**
     * Enrich coupon IDs with coupon codes.
     *
     * Invalid coupon IDs are removed from the result.
     *
     * @since 4.1
     * @access private
     *
     * @param array $coupon_ids Array of coupon IDs (post IDs).
     * @return array Array of objects with 'label' and 'value' keys.
     */
    private function _enrich_coupon_ids( $coupon_ids ) {
        // Filter to valid integer IDs.
        $valid_ids = array_filter( array_map( 'absint', $coupon_ids ) );

        if ( empty( $valid_ids ) ) {
            return array();
        }

        // Batch fetch coupon posts in a single query.
        $coupon_posts = get_posts(
            array(
                'post_type'      => 'shop_coupon',
                'post__in'       => $valid_ids,
                'posts_per_page' => -1,
                'post_status'    => array( 'publish', 'draft', 'private', 'future' ),
            )
        );

        $enriched = array();
        foreach ( $coupon_posts as $coupon_post ) {
            $coupon_code = $coupon_post->post_title;

            if ( empty( $coupon_code ) ) {
                continue;
            }

            $enriched[] = array(
                'label' => $coupon_code,
                'value' => (string) $coupon_post->ID,
            );
        }

        return $enriched;
    }

    /**
     * Enrich cart condition value for AI-generated templates.
     *
     * Enriches cart condition values with labels for select-type conditions.
     *
     * @since 4.1
     * @access public
     *
     * @param mixed  $value         The condition value.
     * @param string $field_type    The condition field type (e.g., 'product-category').
     * @param array  $raw_template  The complete raw template data.
     * @return mixed Enriched value or original value if no enrichment needed.
     */
    public function enrich_cart_condition_value( $value, $field_type, $raw_template ) {
        // Only handle enrichment for AI-generated templates.
        if ( ! is_array( $raw_template ) || ! $this->_is_ai_template( $raw_template ) ) {
            return $value;
        }

        if ( ! isset( self::ENRICHABLE_CONDITIONS[ $field_type ] ) ) {
            return $value; // Not an enrichable condition type.
        }

        $entity_type = self::ENRICHABLE_CONDITIONS[ $field_type ];

        // Handle empty values.
        if ( empty( $value ) ) {
            return array();
        }

        // Normalize to array.
        $ids = is_array( $value ) ? $value : array( $value );

        // If already enriched, return as-is.
        if ( $this->_is_already_enriched( $ids ) ) {
            return $ids;
        }

        // Enrich based on entity type.
        switch ( $entity_type ) {
            case 'category':
                return $this->_enrich_category_ids( $ids );
            default:
                return $ids;
        }
    }

    /**
     * Check if values are already enriched with label/value format.
     *
     * @since 4.1
     * @access private
     *
     * @param array $ids Array of values to check.
     * @return bool True if already enriched.
     */
    private function _is_already_enriched( $ids ) {
        if ( ! is_array( $ids ) || empty( $ids ) ) {
            return false;
        }

        $first_item = reset( $ids );

        return is_array( $first_item ) && isset( $first_item['label'] ) && isset( $first_item['value'] );
    }

    /**
     * Check if a template was generated by AI.
     *
     * @since 4.1
     * @access private
     *
     * @param array $template The template data.
     * @return bool True if the template is AI-generated.
     */
    private function _is_ai_template( $template ) {
        return ! empty( $template['generated_by_ai'] );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute class.
     *
     * @since 4.1
     * @access public
     * @inheritdoc
     */
    public function run() {
        // Hook into Template_Processor field enrichment.
        add_filter( 'acfwf_template_processor_enrich_field_value', array( $this, 'enrich_field_value' ), 10, 3 );

        // Hook into cart condition value enrichment.
        add_filter( 'acfwf_template_processor_enrich_cart_condition_value', array( $this, 'enrich_cart_condition_value' ), 10, 3 );
    }
}
