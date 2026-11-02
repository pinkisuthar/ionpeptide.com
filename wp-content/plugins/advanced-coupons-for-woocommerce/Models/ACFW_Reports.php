<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Helpers\Helper_Functions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of extending the coupon system of woocommerce.
 * It houses the logic of handling coupon url.
 * Public Model.
 *
 * @since 2.0
 */
class ACFW_Reports extends Base_Model implements Model_Interface , Initiable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Class constructor.
     *
     * @since 2.0
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
    | Module settings
    |--------------------------------------------------------------------------
    */

    /**
     * Update $_browser_zone_str class property value.
     *
     * @since 2.0
     * @access private
     *
     * @param string $timezone Timezone set on browser.
     */
    private function _set_browser_zone_str( $timezone ) {

        if ( in_array( $timezone, timezone_identifiers_list(), true ) ) {
            $this->_browser_zone_str = $timezone;
        }
    }

    /**
     * Get timezone to use for the report.
     *
     * @since 2.0
     * @access private
     *
     * @return string Timezone string name.
     */
    private function _get_report_timezone_string() {
        return $this->_browser_zone_str ? $this->_browser_zone_str : $this->_helper_functions->get_site_current_timezone();
    }

    /**
     * Get report range details.
     *
     * @since 2.0
     * @access public
     *
     * @param string $range      Report range type.
     * @param string $start_date Starting date of range.
     * @param string $end_date   Ending date of range.
     * @return array Report range details.
     */
    private function _get_report_range_details( $range = '7day', $start_date = 'now -6 days', $end_date = 'now' ) {
        $data     = array();
        $timezone = new \DateTimeZone( $this->_get_report_timezone_string() );
        $now      = new \DateTime( 'now', $timezone );

        switch ( $range ) {

            case 'year':
                $data['type']       = 'year';
                $data['start_date'] = new \DateTime( 'first day of January' . gmdate( 'Y' ), $timezone );
                $data['end_date']   = $now;
                break;

            case 'last_month':
                $data['type']       = 'last_month';
                $data['start_date'] = new \DateTime( 'first day of last month', $timezone );
                $data['end_date']   = new \DateTime( 'last day of last month', $timezone );
                break;

            case 'month':
                $data['type']       = 'month';
                $data['start_date'] = new \DateTime( 'first day of this month', $timezone );
                $data['end_date']   = $now;
                $data['start_date']->setTime( 0, 0, 0 );
                break;

            case 'custom':
                $data['type']       = 'custom';
                $data['start_date'] = new \DateTime( $start_date, $timezone );
                $data['end_date']   = new \DateTime( $end_date . ' 23:59:59', $timezone );
                break;

            case '24hours':
                $data['type']       = '24hours';
                $data['start_date'] = new \DateTime( 'now -1 day', $timezone );
                $data['end_date']   = new \DateTime( 'now', $timezone );

                $data['start_date']->setTime( $data['start_date']->format( 'H' ), 0, 0 );
                $data['end_date']->setTime( $data['end_date']->format( 'H' ), 59, 59 );
                break;

            case '7day':
            default:
                $start_date = new \DateTime( 'now -6 days', $timezone );

                // set hours, minutes and seconds to zero.
                $start_date->setTime( 0, 0, 0 );
                $now->setTime( 23, 59, 59 );

                $data['type']       = '7day';
                $data['start_date'] = $start_date;
                $data['end_date']   = $now;
                break;
        }

        return apply_filters( 'ta_report_range_data', $data, $range );
    }

    /**
     * Register reports.
     *
     * @since 2.0
     *
     * @param array $reports Registered WC Reports.
     * @return array Filtered registered WC Reports.
     */
    public function register_reports( $reports ) {
        $reports[ $this->_constants->ACFW_REPORTS_TAB ] = array(
            'title'   => __( 'Advanced Coupons', 'advanced-coupons-for-woocommerce' ),
            'reports' => array(

                // registere orders with coupons report.
                'orders_with_coupons' => array(
                    'title'       => __( 'Orders with coupons', 'advanced-coupons-for-woocommerce' ),
                    'description' => __( 'List of orders that had used coupon in them', 'advanced-coupons-for-woocommerce' ),
                    'callback'    => array( $this, 'display_orders_with_coupons_report' ),

                ),
            ),
        );

        return $reports;
    }

    /**
     * Display orders with coupons report.
     *
     * @since 2.0
     *
     * @param string $name Report name.
     */
    public function display_orders_with_coupons_report( $name ) {
        $report_tab    = $this->_constants->ACFW_REPORTS_TAB;
        $today_date    = current_time( 'Y-m-d' );
        $current_range = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : '7day'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $start_date    = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $end_date      = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $overlay_image = $this->_constants->IMAGES_ROOT_URL . 'spinner-2x.gif';
        $statuses      = wc_get_order_statuses();
        $range_nav     = apply_filters(
            'acfw_orders_with_coupons_report_nav',
            array(
                'year'       => __( 'Year', 'advanced-coupons-for-woocommerce' ),
                'last_month' => __( 'Last Month', 'advanced-coupons-for-woocommerce' ),
                'month'      => __( 'This Month', 'advanced-coupons-for-woocommerce' ),
                '7day'       => __( 'Last 7 Days', 'advanced-coupons-for-woocommerce' ),
                '24hours'    => __( '24 Hours', 'advanced-coupons-for-woocommerce' ),
            )
        );

        include $this->_constants->VIEWS_ROOT_PATH . 'reports/orders-with-coupons-report.php';
    }

    /**
     * Get orders with coupons data.
     *
     * @since 2.0
     * @access private
     *
     * @param array  $range    Report range data.
     * @param array  $paged    Current report page to display.
     * @param string $search   Text to search.
     * @param array  $statuses Order statuses.
     * @param int    $limit    Number of rows to query.
     * @param int    $total    Total number of rows without the limit.
     * @return array Query total and click data.
     */
    private function _get_orders_with_coupons( $range, $paged = 1, $search = '', $statuses = array(), $limit = 25, $total = 0 ) {
        // Set timezone to UTC.
        $utc = new \DateTimeZone( 'UTC' );
        $range['start_date']->setTimezone( $utc );
        $range['end_date']->setTimezone( $utc );

        // Set Parameters.
        $start_date = $range['start_date']->format( 'Y-m-d H:i:s' );
        $end_date   = $range['end_date']->format( 'Y-m-d H:i:s' );
        $offset     = ( $paged - 1 ) * $limit;

        // Default Args.
        $args = array(
            'date_created' => strtotime( $start_date ) . '...' . strtotime( $end_date ),
            'limit'        => $limit,
            'offset'       => $offset,
            'return'       => 'ids',
            'paginate'     => true,
        );

        // Filter by Statuses.
        if ( $statuses ) {
            $args['status'] = $statuses;
        }

        $orders_query = wc_get_orders( $args );
        $total        = $orders_query->total;
        $results      = array();

        if ( $total ) {
            foreach ( $orders_query->orders as $order_id ) {
                $order = wc_get_order( $order_id );

                /**
                 * Deprecated filter - Search by post_title, and post_name.
                 * - This should be removed later, but for now we need to keep it for backwards compatibility.
                 * - Incompatible with HPOS, because it doesn't use post_title and post_name.
                 */
                $title = 'Order &ndash;' . $order->get_date_created()->format( 'l, F d Y @ h:i A' );
                $name  = 'order-' . $order->get_date_created()->format( 'm-d-Y-h-i-A' );

                // Grab Items.
                $item_ids   = array();
                $item_types = array();
                $item_names = array();
                $types      = array( 'line_item', 'fee', 'shipping', 'tax', 'coupon' );
                foreach ( $order->get_items( $types ) as $item_id => $item ) {
                    $item_ids[]   = $item_id;
                    $item_types[] = $item->get_type();
                    $item_names[] = $item->get_name();
                }

                // Transform data.
                $status = $order->get_status();
                $status = str_contains( 'wc-', $status ) ? $status : 'wc-' . $status; // Add wc if not exists (wc_get_order_statuses).

                // Conditions.
                $valid = true;
                $valid = in_array( 'coupon', $item_types, true ) ? $valid : false; // Only show orders with coupons.

                // Search.
                if ( $search ) {
                    // Search by item.
                    $match = false;
                    foreach ( $item_names as $item_name ) {
                        if ( preg_match( "/\b$search\b/i", $item_name ) ) {
                            $match = true;
                            break;
                        }
                    }

                    /**
                     * Search by title or name.
                     * - This should be removed later, but for now we need to keep it for backwards compatibility.
                     * - Incompatible with HPOS, because it doesn't use post_title and post_name.
                     */
                    if ( ! $match ) {
                        if ( preg_match( "/\b$search\b/i", $title ) || preg_match( "/\b$search\b/i", $name ) ) {
                            $match = true;
                        }
                    }

                    // Set valid if matched.
                    $valid = $match ? $valid : false;
                }

                // Continue if not valid.
                if ( ! $valid ) {
                    continue;
                }

                // Return the data.
                $results[] = (object) array(
                    'ID'                 => $order_id,
                    'post_title'         => $title, // Deprecated: Should be removed later, incompatible with HPOS.
                    'post_name'          => $name, // Deprecated: Should be removed later, incompatible with HPOS.
                    'post_date'          => $order->get_date_created()->format( 'Y-m-d H:i:s' ),
                    'post_status'        => $status,
                    'order_total'        => $order->get_total(),
                    'discount_total'     => $order->get_discount_total(),
                    'discount_tax_total' => $order->get_discount_tax(),
                    'item_ids'           => implode( '||', array_reverse( $item_ids ) ),
                    'item_types'         => implode( '||', array_reverse( $item_types ) ),
                    'item_names'         => implode( '||', array_reverse( $item_names ) ),
                );
            }
        }

        return array(
            'total'  => intval( $total ),
            'orders' => $this->_format_raw_query_data( $results ),
        );
    }

    /**
     * Format raw query data.
     *
     * @since 2.0
     * @access private
     *
     * @param array $raw_data List of raw data from custom SQL query.
     * @return array Formatted data.
     */
    private function _format_raw_query_data( $raw_data ) {

        $formatted          = array();
        $tax_exclude        = 'excl' === get_option( 'woocommerce_tax_display_cart' );
        $meta_format_cb     = function( $id, $type, $name ) {
            return array(
                'item_id'   => absint( $id ),
                'item_type' => $type,
                'item_name' => $name,
            );
        };
        $get_coupons_cb     = function( $item ) {
            return 'coupon' === $item['item_type'];
        };
        $format_coupon_data = function( $id, $code ) {
            return array(
                'coupon_id'   => $id,
                'coupon_code' => $code,
            );
        };

        foreach ( $raw_data as $data ) {

            // format order items data.
            $item_ids   = explode( '||', $data->item_ids );
            $item_types = explode( '||', $data->item_types );
            $item_names = explode( '||', $data->item_names );
            $item_data  = array_map( $meta_format_cb, $item_ids, $item_types, $item_names );

            // get coupon codes.
            $coupon_codes = array_column( array_filter( $item_data, $get_coupons_cb ), 'item_name' );
            $coupon_ids   = array_map( 'wc_get_coupon_id_by_code', $coupon_codes );

            // calculate discount totals.
            $discount_total = $tax_exclude ? (float) $data->discount_total : (float) $data->discount_total + (float) $data->discount_tax_total;
            $discount_total = round( $discount_total, WC_ROUNDING_PRECISION );

            $formatted[] = array(
                'id'             => absint( $data->ID ),
                'date'           => $data->post_date,
                'coupons'        => array_map( $format_coupon_data, $coupon_ids, $coupon_codes ),
                'status'         => wc_get_order_status_name( $data->post_status ),
                'total'          => wc_clean( wc_price( $data->order_total ) ),
                'discount_total' => wc_clean( wc_price( $discount_total ) ),
                'itemdata'       => $item_data,
            );
        }

        return $formatted;
    }



    /*
    |--------------------------------------------------------------------------
    | AJAX functions
    |--------------------------------------------------------------------------
    */

    /**
     * AJAX get orders with coupons.
     *
     * @since 2.0
     * @access public
     */
    public function ajax_get_orders_with_coupons() {
        // Validate nonce.
        $nonce = sanitize_key( $_POST['_wpnonce'] ?? '' );
        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Invalid AJAX call', 'advanced-coupons-for-woocommerce' ),
            );
        } elseif ( ! current_user_can( 'manage_woocommerce' ) || ! $nonce || ! wp_verify_nonce( $nonce, 'acfw_filter_table_report' ) ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'You are not allowed to do this.', 'advanced-coupons-for-woocommerce' ),
            );
        } else {

            // save timezone to use.
            $timezone = isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : '';
            $this->_set_browser_zone_str( $timezone );

            $paged         = isset( $_POST['paged'] ) ? (int) intval( $_POST['paged'] ) : 1;
            $limit         = isset( $_POST['limit'] ) ? (int) intval( $_POST['limit'] ) : 25;
            $search        = isset( $_POST['search'] ) ? esc_sql( sanitize_text_field( wp_unslash( $_POST['search'] ) ) ) : '';
            $statuses      = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ?
                array_map( 'sanitize_text_field', array_map('wp_unslash', $_POST['statuses'] ) ) // phpcs:ignore
                : array();
            $total         = isset( $_POST['total'] ) ? (int) intval( $_POST['total'] ) : 0;
            $current_range = isset( $_POST['range'] ) ? sanitize_text_field( wp_unslash( $_POST['range'] ) ) : '7day';
            $start_date    = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
            $end_date      = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
            $range         = $this->_get_report_range_details( $current_range, $start_date, $end_date );
            $data          = $this->_get_orders_with_coupons( $range, $paged, $search, $statuses, $limit, $total );

            $response = array(
                'status' => 'success',
                'orders' => $data['orders'],
                'total'  => $data['total'],
                'limit'  => $limit,
                'paged'  => $paged,
            );
        }

        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
        echo wp_json_encode( $response );
        wp_die();
    }

    /**
     * AJAX orders with coupons export CSV.
     *
     * @since 2.0
     * @access public
     */
    public function ajax_orders_with_coupons_export_csv() {
        // Validate nonce.
        $nonce = isset( $_POST['wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpnonce'] ) ) : '';
        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Invalid AJAX call', 'advanced-coupons-for-woocommerce' ),
            );
        } elseif ( ! current_user_can( 'manage_woocommerce' ) || ! $nonce || ! wp_verify_nonce( $nonce, 'acfw_filter_table_report' ) ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'You are not allowed to do this.', 'advanced-coupons-for-woocommerce' ),
            );
        } else {
            // save timezone to use.
            $timezone = isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : '';
            $this->_set_browser_zone_str( $timezone );

            $search        = isset( $_POST['search'] ) ? esc_sql( sanitize_text_field( wp_unslash( $_POST['search'] ) ) ) : '';
            $statuses      = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ?
                array_map( 'sanitize_text_field', array_map('wp_unslash', $_POST['statuses'] ) ) // phpcs:ignore
                : array();
            $current_range = isset( $_POST['range'] ) ? sanitize_text_field( wp_unslash( $_POST['range'] ) ) : '7day';
            $start_date    = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
            $end_date      = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
            $range         = $this->_get_report_range_details( $current_range, $start_date, $end_date );
            $raw_data      = $this->_get_orders_with_coupons( $range, -1, $search, $statuses, 0, 0 );
            $data          = array( 'order_id', 'order_date', 'coupons', 'order_status', 'order_total', 'discount_total' );
            $data          = array( $data );

            // Transform data.
            foreach ( $raw_data['orders'] as $row ) {

                $temp_coupons   = array_map(
                    function( $coupon ) {
                        return $coupon['coupon_code'];
                    },
                    $row['coupons']
                );
                $row['coupons'] = is_array( $temp_coupons ) ? implode( ',', $temp_coupons ) : '';
                unset( $row['itemdata'] );

                $data[] = array_values( $row );
            }

            // Prepare response.
            $response = array(
                'status' => 'success',
                'data'   => $data,
            );
        }

        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
        echo wp_json_encode( $response );
        wp_die();
    }


    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
    */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 2.0
     * @access public
     * @implements ACFWP\Interfaces\Initializable_Interface
     */
    public function initialize() {
        add_action( 'wp_ajax_acfw_orders_with_coupons', array( $this, 'ajax_get_orders_with_coupons' ) );
        add_action( 'wp_ajax_acfw_orders_with_coupon_export_csv', array( $this, 'ajax_orders_with_coupons_export_csv' ) );
    }

    /**
     * Execute ACFW_Reports class.
     *
     * @since 2.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_filter( 'woocommerce_admin_reports', array( $this, 'register_reports' ) );
    }

}
