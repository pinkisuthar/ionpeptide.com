<?php
namespace ACFWP\Models\REST_API;

use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

/**
 * WooCommerce Extend Store API for Cart Endpoint.
 *
 * @since 3.5.7
 */
class Store_API_Extend_Endpoint {
    /**
     * Stores Rest Extending instance.
     *
     * @since 3.5.7
     * @var ExtendSchema
     */
    private static $extend;

    /**
     * Plugin Identifier.
     *
     * @since 3.5.7
     * @var string
     */
    const IDENTIFIER = 'acfwp_block';

    /**
     * Cached non-qualifying notices for the current request.
     *
     * @since 4.0.9
     * @var array|null
     */
    private static $non_qualifying_notices = null;

    /**
     * Bootstraps the class and hooks required data.
     *
     * @since 3.5.7
     * @access public
     */
    public static function init() {
        self::$extend = StoreApi::container()->get( ExtendSchema::class );
        self::extend_store();
    }

    /**
     * Registers the actual data into each endpoint.
     * - To see available endpoints to extend please go to : https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/third-party-developers/extensibility/rest-api/available-endpoints-to-extend.md
     *
     * @since 3.5.7
     * @access public
     */
    public static function extend_store() {
        // Register into `cart`.
        if ( is_callable( array( self::$extend, 'register_endpoint_data' ) ) ) {
            self::$extend->register_endpoint_data(
                array(
                    'endpoint'      => CartSchema::IDENTIFIER,
                    'namespace'     => self::IDENTIFIER,
                    'data_callback' => array( 'ACFWP\Models\REST_API\Store_API_Extend_Endpoint', 'extend_data' ),
                    'schema_type'   => ARRAY_A,
                )
            );
        }
    }

    /**
     * Extend endpoint data.
     * - This data will be available in Redux Data Store `cartData.acfwp_block.extension`.
     * - To learn more you can visit : https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/third-party-developers/extensibility/rest-api/extend-rest-api-add-data.md
     *
     * @since 3.5.7
     * @access public
     *
     * @return array $item_data Registered data or empty array if condition is not satisfied.
     */
    public static function extend_data() {
        return array(
            'coupons'         => \ACFWP()->Helper_Functions->get_applied_coupon_data(),
            'add_products'    => self::get_cart_items_with_add_product_data(),
            'one_click_apply' => array(
                'notices'          => \ACFWP()->Apply_Notification->get_one_click_apply_notices(),
                /* Translators: %s: coupon code. */
                'on_apply_success' => sprintf( __( 'Coupon code "%s" has been applied to your cart.', 'advanced-coupons-for-woocommerce' ), '{coupon_code}' ),
            ),
            'non_qualifying'  => array(
                'notices' => self::get_non_qualifying_notices(),
            ),
        );
    }

    /**
     * Get the non-qualifying cart condition notices for auto-applied coupons.
     *
     * WooCommerce's Store API only surfaces "error" notices on block pages
     * (it converts them into exceptions). "info" and "success" notices added
     * via wc_add_notice() are never surfaced and get discarded, so the cart
     * condition non-qualifying notice silently fails for those types on the
     * cart & checkout blocks. This method harvests those queued non-error cart
     * condition notices (tagged with the "acfw-cart-conditions" data marker)
     * so they can be rendered as block notices on the frontend, and removes
     * them from the queue so they don't carry over to a later classic page.
     *
     * @since 4.0.9
     * @access public
     *
     * @return array List of notices, each with "id", "message" and "type" keys.
     */
    public static function get_non_qualifying_notices() {
        if ( ! is_null( self::$non_qualifying_notices ) ) {
            return self::$non_qualifying_notices;
        }

        self::$non_qualifying_notices = array();

        if ( ! function_exists( 'wc_get_notices' ) || is_null( \WC()->session ) ) {
            return self::$non_qualifying_notices;
        }

        $all_notices = wc_get_notices();
        $changed     = false;

        // Only "info" and "success" notices need surfacing here. "error" notices
        // are already surfaced by WooCommerce core as exceptions on block pages,
        // and the "notice" type is mapped to "info" (core/notices has no "notice"
        // status).
        foreach ( array( 'info', 'success', 'notice' ) as $type ) {
            if ( empty( $all_notices[ $type ] ) ) {
                continue;
            }

            foreach ( $all_notices[ $type ] as $index => $notice ) {
                if ( ! is_array( $notice ) || empty( $notice['data']['acfw-cart-conditions'] ) ) {
                    continue;
                }

                self::$non_qualifying_notices[] = array(
                    'id'      => $notice['data']['acfw-cart-conditions'],
                    'message' => $notice['notice'],
                    'type'    => 'notice' === $type ? 'info' : $type,
                );

                unset( $all_notices[ $type ][ $index ] );
                $changed = true;
            }

            $all_notices[ $type ] = array_values( $all_notices[ $type ] );
        }

        if ( $changed ) {
            wc_set_notices( $all_notices );
        }

        return self::$non_qualifying_notices;
    }

    /**
     * Get cart items with add product data.
     *
     * @since 3.5.9
     * @access public
     *
     * @return array Keys of cart items with add product data.
     */
    public static function get_cart_items_with_add_product_data() {
        $items = array_filter(
            \WC()->cart->get_cart_contents(),
            function ( $item ) {
                return isset( $item['acfw_add_product'] );
            }
        );

        return array_column( $items, 'key' );
    }
}
