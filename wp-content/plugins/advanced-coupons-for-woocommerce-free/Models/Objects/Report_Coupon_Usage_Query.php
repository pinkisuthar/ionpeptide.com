<?php

namespace ACFWF\Models\Objects;

use ACFWF\Helpers\Plugin_Constants;
use Automattic\WooCommerce\Utilities\OrderUtil;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared coupon order-item dataset for the dashboard report widgets.
 *
 * One row per coupon line item inside a date period, built from two bounded SQL queries
 * (order-item + order data, then order-item meta) instead of hydrating every order object.
 * All six coupon report widgets derive their figures from this one dataset.
 *
 * DIVERGENCE FROM wc_get_orders(): this path queries the order tables directly, so subscribers to
 * 'woocommerce_order_data_store_cpt_get_orders_query' (legacy) and
 * 'woocommerce_orders_table_query_clauses' (HPOS) do not run. That cannot be gated on
 * has_filter(): WooCommerce Subscriptions subscribes to both on every install and its callbacks
 * are no-ops for a query without subscription query vars, so gating would send every store down
 * the slow path. A site that genuinely restricts order visibility through those filters should
 * return false from 'acfw_dashboard_report_use_aggregate_query' to keep wc_get_orders() semantics.
 *
 * @since 4.7.6
 */
class Report_Coupon_Usage_Query {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Per-request memoisation of rows, keyed by a hash of the period + statuses.
     *
     * @since 4.7.6
     * @access private
     * @var array
     */
    private static $_cache = array();

    /**
     * Order item meta keys read for every coupon line item.
     *
     * @since 4.7.6
     * @access private
     * @var array
     */
    private static $_meta_keys = array(
        'discount_amount',
        'discount_amount_tax',
        'coupon_info',
        'coupon_data',
        Plugin_Constants::ORDER_COUPON_BOGO_DISCOUNT,
        Plugin_Constants::ORDER_COUPON_ADD_PRODUCTS_DISCOUNT,
        Plugin_Constants::ORDER_COUPON_SHIPPING_OVERRIDES_DISCOUNT,
    );

    /*
    |--------------------------------------------------------------------------
    | Query methods
    |--------------------------------------------------------------------------
     */

    /**
     * Get one row per coupon line item paid for within the given date period.
     *
     * @since 4.7.6
     * @access public
     *
     * @param Date_Period_Range $period   Date period range object.
     * @param array             $statuses Order statuses (already prefixed with 'wc-').
     * @return array Rows shaped as described in the class docblock.
     */
    public static function get_rows( Date_Period_Range $period, array $statuses ) {
        // An empty status list would build "IN ( )", which is a MySQL syntax error. Nothing can
        // match no statuses, so answer with no rows instead of issuing a broken query.
        if ( empty( $statuses ) ) {
            return array();
        }

        $is_hpos   = self::_is_hpos();
        $cache_key = self::scope_cache_key( $period, $statuses );

        if ( isset( self::$_cache[ $cache_key ] ) ) {
            return self::$_cache[ $cache_key ];
        }

        $base_rows = $is_hpos
            ? self::_query_hpos_base_rows( $period, $statuses )
            : self::_query_legacy_base_rows( $period, $statuses );

        if ( empty( $base_rows ) ) {
            self::$_cache[ $cache_key ] = array();
            return self::$_cache[ $cache_key ];
        }

        $meta_by_item = $is_hpos
            ? self::_query_hpos_item_meta( $period, $statuses )
            : self::_query_legacy_item_meta( $period, $statuses );

        $rows = array();

        foreach ( $base_rows as $base_row ) {
            $meta = $meta_by_item[ (int) $base_row->order_item_id ] ?? array();

            $rows[] = array(
                'order_id'                    => (int) $base_row->order_id,
                'order_item_id'               => (int) $base_row->order_item_id,
                'coupon_code'                 => (string) $base_row->coupon_code,
                'coupon_id'                   => self::resolve_coupon_id( $meta['coupon_info'] ?? null, $meta['coupon_data'] ?? null ),
                'discount'                    => $meta['discount_amount'] ?? '0',
                'discount_tax'                => $meta['discount_amount_tax'] ?? '0',
                'bogo_discount'               => $meta[ Plugin_Constants::ORDER_COUPON_BOGO_DISCOUNT ] ?? null,
                'add_products_discount'       => $meta[ Plugin_Constants::ORDER_COUPON_ADD_PRODUCTS_DISCOUNT ] ?? null,
                'shipping_overrides_discount' => $meta[ Plugin_Constants::ORDER_COUPON_SHIPPING_OVERRIDES_DISCOUNT ] ?? null,
                'order_currency'              => (string) $base_row->order_currency,
                'order_total'                 => (string) $base_row->order_total,
                'item'                        => null,
                'order'                       => null,
            );
        }

        self::$_cache[ $cache_key ] = $rows;

        return $rows;
    }

    /**
     * Build the per-request cache key for one report scope.
     *
     * This class and Abstract_Coupons_Report_Widget both memoise per request against the same
     * conceptual scope (period + statuses + storage backend). They build the key here so the two
     * caches can never key the same scope differently.
     *
     * @since 4.7.6
     * @access public
     *
     * @param Date_Period_Range $period   Date period range object.
     * @param array             $statuses Order statuses (already prefixed with 'wc-').
     * @return string Cache key.
     */
    public static function scope_cache_key( Date_Period_Range $period, array $statuses ) {
        return md5(
            (string) wp_json_encode(
                array(
                    $period->start_period->getTimestamp(),
                    $period->end_period->getTimestamp(),
                    $statuses,
                    self::_is_hpos(),
                )
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shared scope predicate
    |--------------------------------------------------------------------------
     */

    /**
     * Build the shared join + WHERE predicate that selects the coupon items in scope, on legacy
     * (post-based) storage.
     *
     * The base-row query and the item-meta query must select over exactly the same set of coupon
     * items, or the two result sets drift apart and the figures stop matching. Keeping the
     * predicate in one place is what guarantees that.
     *
     * @since 4.7.6
     * @access private
     *
     * @param Date_Period_Range $period   Date period range object.
     * @param array             $statuses Order statuses.
     * @return array {
     *     @type string $join   SQL joins, assuming the coupon items table is aliased 'oi'.
     *     @type string $where  SQL predicate carrying the bind placeholders.
     *     @type array  $params Bind params, in placeholder order.
     * }
     */
    private static function _legacy_scope( $period, $statuses ) {
        global $wpdb;

        $status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

        return array(
            'join'   => "INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
                 INNER JOIN {$wpdb->postmeta} dpm ON dpm.post_id = p.ID AND dpm.meta_key = '_date_paid'",
            'where'  => "oi.order_item_type = 'coupon'
                   AND p.post_type = 'shop_order'
                   AND p.post_status IN ( {$status_placeholders} )
                   AND CAST( dpm.meta_value AS UNSIGNED ) BETWEEN %d AND %d",
            'params' => array_merge(
                $statuses,
                array( $period->start_period->getTimestamp(), $period->end_period->getTimestamp() )
            ),
        );
    }

    /**
     * Build the shared join + WHERE predicate that selects the coupon items in scope, on HPOS.
     *
     * The HPOS counterpart of _legacy_scope(); see that method for why the predicate lives in one
     * place.
     *
     * @since 4.7.6
     * @access private
     *
     * @param Date_Period_Range $period   Date period range object.
     * @param array             $statuses Order statuses.
     * @return array {
     *     @type string $join   SQL joins, assuming the coupon items table is aliased 'oi'.
     *     @type string $where  SQL predicate carrying the bind placeholders.
     *     @type array  $params Bind params, in placeholder order.
     * }
     */
    private static function _hpos_scope( $period, $statuses ) {
        global $wpdb;

        $status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

        return array(
            'join'   => "INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
                 INNER JOIN {$wpdb->prefix}wc_order_operational_data od ON od.order_id = o.id",
            'where'  => "oi.order_item_type = 'coupon'
                   AND o.type = 'shop_order'
                   AND o.status IN ( {$status_placeholders} )
                   AND od.date_paid_gmt BETWEEN %s AND %s",
            'params' => array_merge(
                $statuses,
                array(
                    gmdate( 'Y-m-d H:i:s', $period->start_period->getTimestamp() ),
                    gmdate( 'Y-m-d H:i:s', $period->end_period->getTimestamp() ),
                )
            ),
        );
    }

    /**
     * Query base coupon-item + order rows on legacy (post-based) storage.
     *
     * @since 4.7.6
     * @access private
     *
     * @param Date_Period_Range $period   Date period range object.
     * @param array             $statuses Order statuses.
     * @return array Row objects.
     */
    private static function _query_legacy_base_rows( $period, $statuses ) {
        global $wpdb;

        $scope = self::_legacy_scope( $period, $statuses );

        // The join/WHERE come from the scope helper below: SQL literals plus %s/%d placeholders
        // only, with every value bound through prepare() in $scope['params']. The sniff cannot
        // follow the placeholders across the helper call.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT oi.order_item_id, oi.order_id, oi.order_item_name AS coupon_code,
                        curm.meta_value AS order_currency, totm.meta_value AS order_total
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 {$scope['join']}
                 LEFT JOIN {$wpdb->postmeta} curm ON curm.post_id = p.ID AND curm.meta_key = '_order_currency'
                 LEFT JOIN {$wpdb->postmeta} totm ON totm.post_id = p.ID AND totm.meta_key = '_order_total'
                 WHERE {$scope['where']}
                 ORDER BY p.post_date DESC",
                $scope['params']
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    }

    /**
     * Query the item meta for the coupon items in scope, on legacy storage.
     *
     * @since 4.7.6
     * @access private
     *
     * @param Date_Period_Range $period   Date period range object.
     * @param array             $statuses Order statuses.
     * @return array Meta keyed by order_item_id, then meta_key.
     */
    private static function _query_legacy_item_meta( $period, $statuses ) {
        global $wpdb;

        $scope             = self::_legacy_scope( $period, $statuses );
        $meta_placeholders = implode( ', ', array_fill( 0, count( self::$_meta_keys ), '%s' ) );

        // The join/WHERE come from the scope helper below: SQL literals plus %s/%d placeholders
        // only, with every value bound through prepare() in $scope['params']. The sniff cannot
        // follow the placeholders across the helper call.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return self::_group_meta_rows(
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT oim.order_item_id, oim.meta_key, oim.meta_value
                     FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
                     INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
                     {$scope['join']}
                     WHERE {$scope['where']}
                       AND oim.meta_key IN ( {$meta_placeholders} )",
                    array_merge( $scope['params'], self::$_meta_keys )
                )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    }

    /**
     * Query base coupon-item + order rows on HPOS.
     *
     * @since 4.7.6
     * @access private
     *
     * @param Date_Period_Range $period   Date period range object.
     * @param array             $statuses Order statuses.
     * @return array Row objects.
     */
    private static function _query_hpos_base_rows( $period, $statuses ) {
        global $wpdb;

        $scope = self::_hpos_scope( $period, $statuses );

        // The join/WHERE come from the scope helper below: SQL literals plus %s/%d placeholders
        // only, with every value bound through prepare() in $scope['params']. The sniff cannot
        // follow the placeholders across the helper call.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT oi.order_item_id, oi.order_id, oi.order_item_name AS coupon_code,
                        o.currency AS order_currency, o.total_amount AS order_total
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 {$scope['join']}
                 WHERE {$scope['where']}
                 ORDER BY o.date_created_gmt DESC",
                $scope['params']
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    }

    /**
     * Query the item meta for the coupon items in scope, on HPOS.
     *
     * @since 4.7.6
     * @access private
     *
     * @param Date_Period_Range $period   Date period range object.
     * @param array             $statuses Order statuses.
     * @return array Meta keyed by order_item_id, then meta_key.
     */
    private static function _query_hpos_item_meta( $period, $statuses ) {
        global $wpdb;

        $scope             = self::_hpos_scope( $period, $statuses );
        $meta_placeholders = implode( ', ', array_fill( 0, count( self::$_meta_keys ), '%s' ) );

        // The join/WHERE come from the scope helper below: SQL literals plus %s/%d placeholders
        // only, with every value bound through prepare() in $scope['params']. The sniff cannot
        // follow the placeholders across the helper call.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return self::_group_meta_rows(
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT oim.order_item_id, oim.meta_key, oim.meta_value
                     FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
                     INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
                     {$scope['join']}
                     WHERE {$scope['where']}
                       AND oim.meta_key IN ( {$meta_placeholders} )",
                    array_merge( $scope['params'], self::$_meta_keys )
                )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    }

    /**
     * Group flat (order_item_id, meta_key, meta_value) rows into a lookup array.
     *
     * @since 4.7.6
     * @access private
     *
     * @param array $meta_rows Flat meta rows.
     * @return array Meta keyed by order_item_id, then meta_key. First value wins per key,
     *               matching WC_Data::get_meta()'s single-value behaviour.
     */
    private static function _group_meta_rows( $meta_rows ) {
        $grouped = array();

        foreach ( (array) $meta_rows as $meta_row ) {
            $item_id = (int) $meta_row->order_item_id;

            if ( ! isset( $grouped[ $item_id ] ) ) {
                $grouped[ $item_id ] = array();
            }

            if ( ! isset( $grouped[ $item_id ][ $meta_row->meta_key ] ) ) {
                $grouped[ $item_id ][ $meta_row->meta_key ] = $meta_row->meta_value;
            }
        }

        return $grouped;
    }

    /*
    |--------------------------------------------------------------------------
    | Row building from hydrated orders (fallback path)
    |--------------------------------------------------------------------------
     */

    /**
     * Build a row, shaped identically to get_rows(), from a real hydrated order + coupon item.
     * Used by the batched-hydration fallback path when an integration needs real objects.
     *
     * @since 4.7.6
     * @access public
     *
     * @param \WC_Order_Item_Coupon $item  Coupon order item.
     * @param \WC_Order             $order Order object.
     * @return array Row.
     */
    public static function build_row_from_order_item( $item, $order ) {
        $coupon_info = $item->get_meta( 'coupon_info' );
        $coupon_data = $item->get_meta( 'coupon_data' );

        return array(
            'order_id'                    => $order->get_id(),
            'order_item_id'               => $item->get_id(),
            'coupon_code'                 => $item->get_code(),
            'coupon_id'                   => self::resolve_coupon_id( $coupon_info, $coupon_data ),
            'discount'                    => $item->get_discount(),
            'discount_tax'                => $item->get_discount_tax(),
            'bogo_discount'               => $item->get_meta( Plugin_Constants::ORDER_COUPON_BOGO_DISCOUNT ),
            'add_products_discount'       => $item->get_meta( Plugin_Constants::ORDER_COUPON_ADD_PRODUCTS_DISCOUNT ),
            'shipping_overrides_discount' => $item->get_meta( Plugin_Constants::ORDER_COUPON_SHIPPING_OVERRIDES_DISCOUNT ),
            'order_currency'              => $order->get_currency(),
            'order_total'                 => $order->get_total(),
            'item'                        => $item,
            'order'                       => $order,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Utility methods
    |--------------------------------------------------------------------------
     */

    /**
     * Resolve a coupon post id from the raw coupon_info / coupon_data item meta.
     *
     * @since 4.7.6
     * @access public
     *
     * @param mixed $coupon_info_raw Raw (JSON string or already-decoded) coupon_info meta value.
     * @param mixed $coupon_data_raw Raw (serialized string or already-unserialized) coupon_data meta value.
     * @return int Coupon post id, or 0 when it cannot be resolved.
     */
    public static function resolve_coupon_id( $coupon_info_raw, $coupon_data_raw ) {
        $coupon_info = is_string( $coupon_info_raw ) ? json_decode( $coupon_info_raw, true ) : $coupon_info_raw;
        $coupon_id   = is_array( $coupon_info ) && isset( $coupon_info[0] ) ? $coupon_info[0] : 0;

        if ( ! $coupon_id ) {
            $coupon_data = is_string( $coupon_data_raw ) ? maybe_unserialize( $coupon_data_raw ) : $coupon_data_raw;
            $coupon_id   = is_array( $coupon_data ) && isset( $coupon_data['id'] ) ? $coupon_data['id'] : 0;
        }

        return (int) $coupon_id;
    }

    /**
     * Sum of the BOGO / Add Products / Shipping overrides discount components for a row.
     * Mirrors Helper_Functions::get_coupon_order_item_extra_discounts() exactly, but reads the
     * already-fetched row values instead of re-querying item meta.
     *
     * @since 4.7.6
     * @access public
     *
     * @param array $row     Row from get_rows() or build_row_from_order_item().
     * @param bool  $precise Flag to check whether to return the precision value or not.
     * @return float Extra discount value.
     */
    public static function get_extra_discount( array $row, $precise = true ) {
        $discount  = wc_add_number_precision( (float) ( $row['bogo_discount'] ?? 0 ) );
        $discount += wc_add_number_precision( (float) ( $row['add_products_discount'] ?? 0 ) );
        $discount += wc_add_number_precision( (float) ( $row['shipping_overrides_discount'] ?? 0 ) );

        return $precise ? $discount : wc_remove_number_precision( $discount );
    }

    /**
     * Check if HPOS is the authoritative order storage backend. Evaluated at query time
     * (never cached across requests) so a store that toggles HPOS is honoured immediately.
     *
     * @since 4.7.6
     * @access private
     *
     * @return bool True when the custom orders table is in use.
     */
    private static function _is_hpos() {
        return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && OrderUtil::custom_orders_table_usage_is_enabled();
    }
}
