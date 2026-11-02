<?php

namespace ACFWF\Models\Objects\Report_Widgets;

use ACFWF\Abstracts\Abstract_Coupons_Report_Widget;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Recent coupons report widget data.
 *
 * @since 4.3
 */
class Recent_Coupons extends Abstract_Coupons_Report_Widget {
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
        $this->key         = 'recent_coupons';
        $this->widget_name = __( 'Recently Created Coupons', 'advanced-coupons-for-woocommerce-free' );
        $this->type        = 'table';
        $this->title       = __( 'Recently Created Coupons', 'advanced-coupons-for-woocommerce-free' );

        // build report data.
        parent::__construct( $report_period );
    }

    /*
    |--------------------------------------------------------------------------
    | Query methods
    |--------------------------------------------------------------------------
    */

    /**
     * Query the 5 most recently created coupons based on the end date period.
     *
     * @since 4.3
     * @since 4.7.6 Set the UTC timezone here instead of relying on an earlier widget in the same
     *              request having already flipped the shared Date_Period_Range object. Before,
     *              this bound silently shifted by the site's UTC offset whenever this widget ran
     *              on its own — which is exactly what happens now that a widget can be
     *              unregistered before it runs. Also use $wpdb->prepare().
     *
     * KNOWN PRE-EXISTING SKEW, deliberately preserved: the bound is formatted in UTC but compared
     * against `post_date`, which is stored in site-local time. On a site offset from UTC this can
     * include or exclude coupons created near the period edge. That behaviour predates this change
     * and is kept so the reported figures stay identical; correcting it (comparing
     * `post_date_gmt`, or formatting the bound in site time) changes output and is out of scope
     * for this fix.
     *
     * @access private
     */
    private function _query_most_recent_coupons() {
        global $wpdb;

        $this->report_period->use_utc_timezone();

        $end_period = $this->report_period->end_period->format( 'Y-m-d H:i:s' );

        $query = $wpdb->prepare(
            "SELECT ID, post_title AS code FROM {$wpdb->posts}
            WHERE post_type = 'shop_coupon'
                AND post_status = 'publish'
                AND post_date <= %s
            ORDER BY post_date DESC
            LIMIT 0, 5",
            $end_period
        );

        return $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Query report data freshly from the database.
     *
     * @since 4.3
     * @access protected
     */
    protected function _query_report_data() {
        $coupons    = $this->_query_most_recent_coupons();
        $results    = $this->_query_coupons_table_data();
        $usage      = $this->_calculate_usage_per_coupon( $results );
        $discounted = $this->_calculate_discount_total_per_coupon( $results );

        // prepare data for response.
        $data = array();
        foreach ( $coupons as $coupon ) {
            $data[] = array(
                'id'             => absint( $coupon->ID ),
                'coupon'         => $coupon->code,
                'usage_total'    => isset( $usage[ $coupon->ID ] ) ? $usage[ $coupon->ID ] : 0,
                'discount_total' => isset( $discounted[ $coupon->ID ] ) ? $discounted[ $coupon->ID ] : 0.0,
            );
        }

        $this->raw_data = $data;
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
        $this->_format_coupon_table_data();
    }
}
