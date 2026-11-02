<?php
namespace ACFWF\Models;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the Scheduler module logic.
 * Public Model.
 *
 * @since 4.5
 */
class Scheduler extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Date range schedule error.
     * This property is used to store the date range schedule error.
     *
     * @since 4.6.0
     * @access private
     *
     * @var bool
     */
    private $_date_range_schedule_error = null;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.5
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
    | Implementation.
    |--------------------------------------------------------------------------
     */

    /**
     * Get valid schedule datetime object.
     *
     * @since 4.5
     * @access private
     *
     * @param string          $prop   Date prop name.
     * @param Advanced_Coupon $coupon WC_Coupon object.
     * @return \DateTime object if the date is set or false if there is no date.
     */
    private function _get_coupon_schedule_datetime( $prop, $coupon ) {
        $date = apply_filters( 'acfw_get_coupon_schedule_date', $coupon->get_advanced_prop( $prop ), $prop, $coupon );

        // if schedule start is not set or is already a datetime object, then don't proceed.
        if ( ! $date || $date instanceof \DateTime ) {
            return $date;
        }

        $site_timezone = new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() );
        $datetime      = \DateTime::createFromFormat( 'Y-m-d H:i:s', $date, $site_timezone );

        // if datetime object is not created due to the date string has no time value, then we add 0 time value and recreate.
        if ( ! $datetime instanceof \DateTime ) {
            $datetime = \DateTime::createFromFormat( 'Y-m-d H:i:s', $date . ' 00:00:00', $site_timezone );
        }

        return $datetime;
    }

    /**
     * Check coupon schedule.
     *
     * @since 4.6.0
     * @access public
     *
     * @param \WC_Coupon $coupon WC_Coupon object.
     *
     * @return array Schedule Error.
     */
    public function check_coupon_schedule_error( $coupon ) {
        $coupon         = new Advanced_Coupon( $coupon );
        $schedule_error = array();

        if ( $this->is_date_range_enabled( $coupon ) ) {
            $schedule_start = $this->_get_coupon_schedule_datetime( 'schedule_start', $coupon );
            $schedule_end   = $this->_get_coupon_schedule_datetime( 'schedule_end', $coupon );

            // if schedule start is datetime object and current time is less than schedule start time, then we show error message.
            if ( $schedule_start instanceof \DateTime && time() < $schedule_start->getTimestamp() ) {
                $schedule_error['type']    = 'schedule_start_error_msg';
                $schedule_error['message'] = $coupon->get_advanced_prop( $schedule_error['type'], __( 'This coupon has not started yet.', 'advanced-coupons-for-woocommerce-free' ), true );
            } elseif ( $schedule_end instanceof \DateTime && time() > $schedule_end->getTimestamp() ) { // if schedule end is datetime object and current time is greater than schedule end time, then we show error message.
                $schedule_error['type']    = 'schedule_expire_error_msg';
                $schedule_error['message'] = $coupon->get_advanced_prop( $schedule_error['type'], __( 'This coupon has expired.', 'advanced-coupons-for-woocommerce-free' ), true );
            }
        }

        return $schedule_error;
    }

    /**
     * Implement coupon schedule start feature.
     *
     * @since 4.5
     * @access public
     *
     * @deprecated 4.6.0
     *
     * @param bool       $value Filter return value.
     * @param \WC_Coupon $coupon WC_Coupon object.
     * @return bool True if valid, false otherwise.
     * @throws \Exception Error message.
     */
    public function implement_coupon_schedule_start( $value, $coupon ) {
        wc_deprecated_function( 'ACFWF\Models\Scheduler::' . __FUNCTION__, '4.6.0', 'ACFWF\Models\Scheduler::implement_coupon_error_message' );
        return $value;
    }

    /**
     * Implement coupon schedule start feature.
     *
     * @since 4.5
     * @access public
     *
     * @deprecated 4.6.0
     *
     * @param bool      $value Filter return value.
     * @param WC_Coupon $coupon WC_Coupon object.
     * @return bool True if valid, false otherwise.
     * @throws \Exception Error message.
     */
    public function implement_coupon_schedule_expire( $value, $coupon ) {
        wc_deprecated_function( 'ACFWF\Models\Scheduler::' . __FUNCTION__, '4.6.0', 'ACFWF\Models\Scheduler::implement_coupon_error_message' );
        return $value;
    }

    /**
     * Implement coupon schedule start feature.
     *
     * @since 4.5
     * @access public
     *
     * @param bool      $value Filter return value.
     * @param WC_Coupon $coupon WC_Coupon object.
     * @return bool True if valid, false otherwise.
     * @throws \Exception Error message.
     */
    public function implement_coupon_error_message( $value, $coupon ) {
        // Check coupon schedule.
        $schedule_error = $this->check_coupon_schedule_error( $coupon );
        if ( is_array( $schedule_error ) && ! empty( $schedule_error ) ) {
            $this->_date_range_schedule_error = true;
            throw new \Exception(
                wp_kses_post( $schedule_error['message'] ),
                esc_html( \WC_Coupon::E_WC_COUPON_EXPIRED )
            );
        }

        return $value;
    }

    /**
     * Shows custom error message for coupon in cart/checkout block.
     *
     * @since 4.6.0
     * @access public
     *
     * @param string    $error_message Error message.
     * @param int       $error_code Error code.
     * @param WC_Coupon $coupon Coupon data.
     *
     * @return string
     */
    public function implement_coupon_error_message_block( $error_message, $error_code, $coupon ) {
        if ( $this->_date_range_schedule_error && \WC_Coupon::E_WC_COUPON_EXPIRED === $error_code ) {
            $schedule_error = $this->check_coupon_schedule_error( $coupon );

            /*
             * Only replace the message when this coupon actually has a schedule error of its own.
             * A single request can report on more than one coupon, so the coupon being described
             * here is not necessarily the scheduled one that set the flag.
             */
            if ( ! empty( $schedule_error['message'] ) ) {
                $error_message = $schedule_error['message'];
            }
        }

        return $error_message;
    }

    /**
     * Disable WC default check for coupon expiry on frontend.
     *
     * Used to register a `woocommerce_coupon_get_date_expires` filter that reported no expiry
     * date for every coupon on the frontend, which left WooCommerce's own expiry check with
     * nothing to check. `maybe_override_date_expires()` now does this per coupon instead.
     *
     * @since 4.5
     * @access public
     *
     * @deprecated 4.8
     */
    public function disable_wc_default_coupon_expiry_check() {
        wc_deprecated_function( 'ACFWF\Models\Scheduler::' . __FUNCTION__, '4.8', 'ACFWF\Models\Scheduler::maybe_override_date_expires' );
    }

    /**
     * Scheduler input field callback method.
     * This method is based on woocommerce_wp_text_input function.
     *
     * @since 4.5
     * @access public
     *
     * @param array $field Field data.
     */
    public function scheduler_input_field( $field ) {
        global $post;

        $coupon                 = $post instanceof \WC_Coupon ? $post : new \WC_Coupon( $post->ID );
        $field['placeholder']   = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
        $field['class']         = isset( $field['class'] ) ? $field['class'] : 'short';
        $field['style']         = isset( $field['style'] ) ? $field['style'] : '';
        $field['wrapper_class'] = isset( $field['wrapper_class'] ) ? $field['wrapper_class'] : '';
        $field['value']         = isset( $field['value'] ) ? $field['value'] : $coupon->get_meta( $field['id'], true );
        $field['name']          = isset( $field['name'] ) ? $field['name'] : $field['id'];
        $field['type']          = isset( $field['type'] ) ? $field['type'] : 'text';
        $field['desc_tip']      = isset( $field['desc_tip'] ) ? $field['desc_tip'] : false;
        $data_type              = empty( $field['data_type'] ) ? '' : $field['data_type'];

        // Custom attribute handling.
        $custom_attributes = array();

        if ( ! empty( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] ) ) {
            foreach ( $field['custom_attributes'] as $attribute => $value ) {
                $custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $value ) . '"';
            }
        }

        $temp = explode( ' ', $field['value'] );
        $date = isset( $temp[0] ) ? $temp[0] : '';
        $time = isset( $temp[1] ) ? explode( ':', $temp[1] ) : '';

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons/view-scheduler-input-field.php';
    }

    /**
     * Check if the date range schedules feature is enabled or not.
     *
     * @since 4.5
     * @access public
     *
     * @param Advanced_Coupon $coupon Coupon object.
     * @return bool True if enabled, false otherwise.
     */
    public function is_date_range_enabled( $coupon ) {
        $is_enabled = $coupon->get_advanced_prop( 'enable_date_range_schedule' );

        /**
         * Backwards compatibility: set toggle as enabled for coupons that already have scheduler data in them.
         *
         * The stored meta is read directly instead of the `schedule_start` / `schedule_end`
         * advanced props. `schedule_end` falls back to WooCommerce's own expiry date when the
         * coupon has no scheduler end date of its own, so reading the prop reported every coupon
         * that merely had a WooCommerce expiry date as a scheduler coupon.
         *
         * @since 4.5
         * @since 4.8 Read the stored scheduler meta instead of the derived props.
         */
        if ( apply_filters( 'acfwf_enable_scheduler_when_schedule_values_are_set', true ) &&
            '' === $is_enabled && ( $this->_get_stored_schedule_date( $coupon, 'schedule_start' ) || $this->_get_stored_schedule_date( $coupon, 'schedule_end' ) ) ) {
            $is_enabled = 'yes';
        }

        return 'yes' === $is_enabled;
    }

    /**
     * Get a scheduler date as it is stored on the coupon.
     *
     * Only the coupon's own scheduler meta is returned. Unlike the `schedule_start` /
     * `schedule_end` advanced props, this never falls back to WooCommerce's native expiry date,
     * so it can be used to tell a coupon that genuinely uses the scheduler apart from one that
     * only has a WooCommerce expiry date.
     *
     * @since 4.8
     * @access private
     *
     * @param Advanced_Coupon $coupon Coupon object.
     * @param string          $prop   Scheduler date prop name. Either `schedule_start` or `schedule_end`.
     * @return string Stored date value, or an empty string when the coupon has none.
     */
    private function _get_stored_schedule_date( $coupon, $prop ) {
        $coupon_id = $coupon->get_id();

        if ( ! $coupon_id ) {
            return '';
        }

        $stored_date = get_post_meta( $coupon_id, Plugin_Constants::META_PREFIX . $prop, true );

        return is_string( $stored_date ) ? $stored_date : '';
    }

    /**
     * Check if the current request is rendering the coupons list table.
     *
     * @since 4.8
     * @access private
     *
     * @return bool True if the coupons list table is being rendered, false otherwise.
     */
    private function _is_coupons_list_screen() {
        if ( ! is_admin() || wp_doing_ajax() || ! function_exists( 'get_current_screen' ) ) {
            return false;
        }

        $screen = get_current_screen();

        return $screen instanceof \WP_Screen && 'edit-shop_coupon' === $screen->id;
    }

    /**
     * Override the coupon expiration date reported by WooCommerce.
     *
     * Coupons that do not use the date range schedules are never touched, so WooCommerce keeps
     * full ownership of their expiry date. Everything below only applies to coupons that do.
     *
     * On the coupons list table the scheduler's own expiry date is reported, falling back to the
     * coupon's WooCommerce expiry date when the scheduler has none. WooCommerce renders that
     * column straight from the coupon object and echoes it, so this filter is the only place the
     * value can be corrected.
     *
     * Everywhere else a scheduled coupon reports no expiry date at all, which hands the decision
     * to `check_coupon_schedule_error()`. That is deliberate: WooCommerce checks the expiry date
     * before `woocommerce_coupon_is_valid` runs, so leaving the date in place would have
     * WooCommerce reject the coupon with its own message before the schedule was ever consulted,
     * and the merchant's custom start and expiry messages would never be shown. The schedule
     * still enforces the coupon's WooCommerce expiry date when it has no expiry date of its own,
     * because `schedule_end` falls back to it. This applies equally to the classic cart and
     * checkout, the cart and checkout blocks, the Store API, and applying a coupon to an order in
     * wp-admin.
     *
     * The coupon editor is unaffected either way: WooCommerce's own coupon data meta box reads
     * the expiry date in `edit` context, which this filter does not run for.
     *
     * @since 4.7.1
     * @since 4.8 Report the scheduler expiry date on the coupons list table, and report no expiry
     *              date everywhere else instead of consulting the schedule.
     * @access public
     *
     * @param \WC_DateTime|null $date_expires Date expires.
     * @param \WC_Coupon        $coupon WC_Coupon object.
     * @return \WC_DateTime|null Date expires.
     */
    public function maybe_override_date_expires( $date_expires, $coupon ) {
        $advanced_coupon = new Advanced_Coupon( $coupon );

        // Only override if scheduler is enabled for this coupon.
        if ( ! $this->is_date_range_enabled( $advanced_coupon ) ) {
            return $date_expires;
        }

        if ( $this->_is_coupons_list_screen() ) {
            return $this->_get_schedule_end_date_expires( $advanced_coupon, $date_expires );
        }

        return null; // treat as no expiry so the schedule check decides on its own.
    }

    /**
     * Get the scheduler expiry date to report for a coupon, as a date object.
     *
     * @since 4.8
     * @access private
     *
     * @param Advanced_Coupon   $coupon       Coupon object.
     * @param \WC_DateTime|null $date_expires WooCommerce expiry date, used when the coupon's
     *                                        scheduler has no expiry date of its own.
     * @return \WC_DateTime|null Date expires.
     */
    private function _get_schedule_end_date_expires( $coupon, $date_expires ) {
        $schedule_end = $this->_get_stored_schedule_date( $coupon, 'schedule_end' );

        if ( ! $schedule_end ) {
            return $date_expires;
        }

        try {
            return new \WC_DateTime( $schedule_end, new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() ) );
        } catch ( \Exception $e ) {
            return $date_expires;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Scheduler class.
     *
     * @since 4.5
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! $this->_helper_functions->is_module( Plugin_Constants::SCHEDULER_MODULE ) ) {
            return;
        }

        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'implement_coupon_error_message' ), 10, 2 );
        add_filter( 'woocommerce_coupon_error', array( $this, 'implement_coupon_error_message_block' ), 10, 3 );
        add_filter( 'woocommerce_coupon_get_date_expires', array( $this, 'maybe_override_date_expires' ), 10, 2 );
    }
}
