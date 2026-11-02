<?php

namespace ACFWF\Abstracts;

use ACFWF\Abstracts\Abstract_Report_Widget;
use ACFWF\Models\Objects\Report_Coupon_Usage_Query;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract coupons report widget class.
 *
 * @since 4.3
 */
class Abstract_Coupons_Report_Widget extends Abstract_Report_Widget {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
    */

    /**
     * Per-request cache of coupon table data, keyed by report period + order statuses.
     *
     * Top_Coupons and Recent_Coupons ask this class the identical question, so before 4.7.6 the
     * whole computation ran twice per request. Sharing it makes no difference to the figures --
     * same inputs, same transformation -- but it halves how often the per-item report filters are
     * invoked, and on the batched-hydration fallback path it removes a full pass over every order
     * in the period. Static so the two widget instances share it.
     *
     * @since 4.7.6
     * @access private
     * @var array
     */
    private static $_table_data_cache = array();

    /*
    |--------------------------------------------------------------------------
    | Query methods
    |--------------------------------------------------------------------------
    */

    /**
     * Query coupons table usage and discounted data for the provided date period range.
     *
     * @since 4.3
     * @since 4.5.1 Add support for BOGO, Add Products and Shipping overrides discounts.
     * @since 4.7.6 Source rows from the shared coupon-usage dataset instead of hydrating every
     *              order, so memory no longer grows with order volume, and memoise the result
     *              per request so Top_Coupons and Recent_Coupons share one computation.
     * @access protected
     *
     * @return array Coupon table data.
     */
    protected function _query_coupons_table_data() {
        $cache_key = Report_Coupon_Usage_Query::scope_cache_key(
            $this->report_period,
            $this->_report_widget_order_statuses()
        );

        if ( isset( self::$_table_data_cache[ $cache_key ] ) ) {
            return self::$_table_data_cache[ $cache_key ];
        }

        $rows = $this->_get_coupon_usage_rows();
        $data = array();

        foreach ( $rows as $row ) {
            $currency_settings = array( 'user_currency' => $row['order_currency'] );
            $discount          = apply_filters( 'acfw_filter_amount', $row['discount'], $currency_settings );
            $discount_tax      = apply_filters( 'acfw_filter_amount', $row['discount_tax'], $currency_settings );

            $data[] = array(
                'order_item_id'  => $row['order_item_id'],
                'ID'             => $row['coupon_id'] ?? 0,
                'code'           => $row['coupon_code'],
                'discount'       => apply_filters( 'acfw_query_report_get_discount', $discount, $row['item'], $row['order'] ),
                'discount_tax'   => apply_filters( 'acfw_query_report_get_discount_tax', $discount_tax, $row['item'], $row['order'] ),
                'order_currency' => $row['order_currency'],
                'extra_discount' => apply_filters( 'acfw_query_report_extra_discount', Report_Coupon_Usage_Query::get_extra_discount( $row, false ), $row['item'], $row['order'] ),
            );
        }

        self::$_table_data_cache[ $cache_key ] = $data;

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | Utility methods
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate usage per coupon based on the results of the coupon table data.
     *
     * @since 4.3
     * @access protected
     *
     * @param array $results Coupon table data.
     * @return array Coupon and usage key value pair.
     */
    protected function _calculate_usage_per_coupon( $results ) {
        $usage = array();
        foreach ( $results as $row ) {

            // create coupon entry if it doesn't exist yet.
            if ( ! isset( $usage[ $row['ID'] ] ) ) {
                $usage[ $row['ID'] ] = 0;
            }

            // increment usage count for coupon.
            ++$usage[ $row['ID'] ];
        }

        return $usage;
    }

    /**
     * Calculate usage per coupon based on the results of the coupon table data.
     *
     * @since 4.3
     * @since 4.5.1 Add support for BOGO, Add Products and Shipping overrides discounts.
     * @access protected
     *
     * @param array $results Coupon table data.
     * @return array Coupon and discounted total key value pair
     */
    protected function _calculate_discount_total_per_coupon( $results ) {
        $discounted = array();
        foreach ( $results as $row ) {
            // create coupon entry if it doesn't exist yet.
            if ( ! isset( $discounted[ $row['ID'] ] ) ) {
                $discounted[ $row['ID'] ] = 0;
            }

            // add discounted amount to total for coupon.
            $discounted[ $row['ID'] ] += \wc_add_number_precision( $row['discount'] ) + \wc_add_number_precision( $row['discount_tax'] ) + \wc_add_number_precision( $row['extra_discount'] );
        }

        return array_map( 'wc_remove_number_precision', $discounted );
    }

    /**
     * Format coupon table data from raw data.
     *
     * @since 4.3
     * @access protected
     */
    protected function _format_coupon_table_data() {
        $this->table_data = array_map(
            function ( $d ) {
            /* Translators: %s: Coupon usage total value */
            $d['usage_total']    = sprintf( _n( '%s use', '%s uses', $d['usage_total'], 'advanced-coupons-for-woocommerce-free' ), $d['usage_total'] );
            $d['discount_total'] = \ACFWF()->Helper_Functions->api_wc_price( $d['discount_total'] );
            return $d;
            },
            $this->raw_data
        );
    }
}
