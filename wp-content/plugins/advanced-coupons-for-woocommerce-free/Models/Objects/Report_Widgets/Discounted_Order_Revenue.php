<?php

namespace ACFWF\Models\Objects\Report_Widgets;

use ACFWF\Abstracts\Abstract_Report_Widget;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Discounted order revenue report widget data.
 *
 * @since 4.3
 */
class Discounted_Order_Revenue extends Abstract_Report_Widget {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new Report Widget object.
     *
     * @since 4.3
     * @access public
     *
     * @param Date_Period_Range $report_period Date period range object.
     */
    public function __construct( $report_period ) {
        $this->key         = 'discounted_order_revenue';
        $this->widget_name = __( 'Discounted Order Revenue', 'advanced-coupons-for-woocommerce-free' );
        $this->type        = 'big_number';
        $this->description = __( 'Discounted Order Revenue', 'advanced-coupons-for-woocommerce-free' );

        // build report data.
        parent::__construct( $report_period );
    }

    /*
    |--------------------------------------------------------------------------
    | Query methods
    |--------------------------------------------------------------------------
    */

    /**
     * Query report data freshly from the database.
     *
     * @since 4.3
     * @since 4.7.6 Source from the shared coupon-usage dataset instead of hydrating every order.
     *            Each order still counts once, even when it carries more than one coupon line
     *            item, and always at full gross value (refunds do not touch coupon line items).
     * @access protected
     */
    protected function _query_report_data() {
        $total_revenue  = wc_add_number_precision( 0.0 );
        $counted_orders = array();

        // Get total order revenue, once per distinct order.
        foreach ( $this->_get_coupon_usage_rows() as $row ) {
            if ( isset( $counted_orders[ $row['order_id'] ] ) ) {
                continue;
            }

            $counted_orders[ $row['order_id'] ] = true;

            // Get order total.
            $order_total    = apply_filters( 'acfw_filter_amount', (float) $row['order_total'], true, array( 'user_currency' => $row['order_currency'] ) );
            $order_total    = apply_filters( 'acfw_query_report_data_order_total', $order_total, $row['order'] );
            $total_revenue += wc_add_number_precision( $order_total );
        }

        $this->raw_data = wc_remove_number_precision( $total_revenue );
    }

    /*
    |--------------------------------------------------------------------------
    | Utility methods
    |--------------------------------------------------------------------------
     */

    /**
     * NOTE: This method needs to be override on the child class.
     *
     * @since 4.3
     * @access public
     */
    protected function _format_report_data() {
        $this->title = $this->_format_price( $this->raw_data );
    }
}
