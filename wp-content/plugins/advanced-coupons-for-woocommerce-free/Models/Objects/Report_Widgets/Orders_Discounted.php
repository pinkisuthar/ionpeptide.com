<?php

namespace ACFWF\Models\Objects\Report_Widgets;

use ACFWF\Abstracts\Abstract_Report_Widget;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orders discounted report widget data.
 *
 * @since 4.3
 */
class Orders_Discounted extends Abstract_Report_Widget {
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
        $this->key         = 'orders_discounted';
        $this->widget_name = __( 'Orders Discounted', 'advanced-coupons-for-woocommerce-free' );
        $this->type        = 'big_number';
        $this->description = __( 'Orders Discounted', 'advanced-coupons-for-woocommerce-free' );

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
     * @access protected
     */
    protected function _query_report_data() {
        $order_ids = array();

        foreach ( $this->_get_coupon_usage_rows( false ) as $row ) {
            $order_ids[ $row['order_id'] ] = true;
        }

        $this->raw_data = count( $order_ids );
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
        $this->title = \ACFWF()->Helper_Functions->format_integer_for_display( $this->raw_data );
    }
}
