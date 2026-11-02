<?php
namespace ACFWF\Models\REST_API;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of store api.
 *
 * @since 4.5.8
 */
class Store_API_Hooks extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Class constructor.
     *
     * @since 4.5.8
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

    /**
     * Extend Store API Coupon Endpoint.
     *
     * @since 4.5.8
     * @access public
     */
    public function extend_store_api_coupon_endpoint() {
        \ACFWF\Models\REST_API\Store_API_Extend_Endpoint::init();
    }

    /**
     * Extend Store API Dummy Update.
     *
     * This function is required to update block data store. Some use cases are:
     * - Adding BOGO coupon, where the new item will be added to the block data store.
     *
     * @since 4.5.8
     * @access public
     */
    public function extend_store_api_dummy_update() {
        woocommerce_store_api_register_update_callback(
            array(
                'namespace' => 'acfwf_dummy_update',
                'callback'  => function () {}, // Dummy callback.
            )
        );
    }

    /**
     * Assign coupon category when creating/updating a coupon via the REST API.
     *
     * Hooked into 'woocommerce_rest_insert_shop_coupon_object'.
     * This method reads the 'coupon_category' field from the REST API request
     * and assigns the coupon to the specified term in the 'shop_coupon_cat' taxonomy.
     *
     * Accepts either a term ID or slug for the category.
     *
     * @since 4.6.7
     * @access public
     *
     * @param \WC_Coupon       $coupon   The coupon object being inserted or updated.
     * @param \WP_REST_Request $request  Full data from the REST API request.
     * @param bool             $creating Whether this is a creation or update operation.
     *
     * @return \WC_Coupon The modified coupon object.
     */
    public function assign_coupon_category_from_rest( $coupon, $request, $creating ) {
        if ( isset( $request['coupon_category'] ) ) {
            $category = $request['coupon_category'];

            $term = is_numeric( $category )
                ? get_term_by( 'id', absint( $category ), 'shop_coupon_cat' )
                : get_term_by( 'slug', sanitize_title( $category ), 'shop_coupon_cat' );

            if ( $term && ! is_wp_error( $term ) ) {
                wp_set_object_terms( $coupon->get_id(), array( (int) $term->term_id ), 'shop_coupon_cat', false );
            }
        }
        return $coupon;
    }

    /**
     * Execute Hooks.
     *
     * @since 4.5.8
     * @access public
     */
    public function run() {
        add_action( 'woocommerce_blocks_loaded', array( $this, 'extend_store_api_coupon_endpoint' ) );
        add_action( 'woocommerce_blocks_loaded', array( $this, 'extend_store_api_dummy_update' ) );
        add_filter( 'woocommerce_rest_insert_shop_coupon_object', array( $this, 'assign_coupon_category_from_rest' ), 10, 3 );
    }
}
