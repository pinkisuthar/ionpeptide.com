<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;
use ACFWF\Models\Objects\Store_Credit_Entry;
use ACFWF\Models\Store_Credits\Queries;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Cashback Coupon feature.
 *
 * @since 3.5.2
 */
class Cashback_Coupon extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that houses the calculated cashback coupons amount.
     *
     * @since 3.5.2
     * @access private
     * @var array
     */
    private $_cashback_coupons = array();

    /**
     * Model that houses all the store credit query methods.
     *
     * @since 3.5.6
     * @access private
     * @var Queries
     */
    private $_queries;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.5.2
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $this->_queries = Queries::get_instance( ACFWF()->Plugin_Constants, ACFWF()->Helper_Functions );
        $main_plugin->add_to_all_plugin_models( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
     */

    /**
     * Register cashback coupon types.
     *
     * @since 3.5.2
     * @access public
     *
     * @param array $types Coupon types.
     * @return array Filtered coupon types.
     */
    public function register_cashback_coupon_types( $types ) {

        $types['acfw_percentage_cashback'] = __( 'Percentage cashback (store credits)', 'advanced-coupons-for-woocommerce' );
        $types['acfw_fixed_cashback']      = __( 'Fixed cashback (store credits)', 'advanced-coupons-for-woocommerce' );

        return $types;
    }

    /**
     * Register cashback coupon store credit source type.
     *
     * @since 3.5.2
     * @access public
     *
     * @param array $source_types Store credit source types.
     * @return array Filtered store credit source types.
     */
    public function register_cashback_store_credit_source_type( $source_types ) {

        $source_types['cashback_coupon'] = array(
            'name'    => __( 'Cashback coupon', 'advanced-coupons-for-woocommerce' ),
            'slug'    => 'cashback_coupon',
            'related' => array(
                'object_type'         => 'order',
                'admin_label'         => __( 'View Order', 'advanced-coupons-for-woocommerce' ),
                'label'               => __( 'View Order', 'advanced-coupons-for-woocommerce' ),
                'admin_link_callback' => 'get_edit_post_link',
                'link_callback'       => array( \ACFWF()->Helper_Functions, 'get_order_frontend_link' ),
            ),
        );

        return $source_types;
    }

    /**
     * Display cashback coupon fields in the coupon editor.
     *
     * @since 3.5.2
     * @access public
     *
     * @param int $coupon_id Coupon ID.
     */
    public function display_cashback_coupon_fields( $coupon_id ) {

        $coupon = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );

        woocommerce_wp_text_input(
            array(
                'id'                => $this->_constants->META_PREFIX . 'cashback_waiting_period',
                'label'             => __( 'Cashback waiting period', 'advanced-coupons-for-woocommerce' ),
                'placeholder'       => wc_format_localized_price( 0 ),
                'description'       => __( 'Number of days to delay giving the store credits cashback after the order is completed.', 'advanced-coupons-for-woocommerce' ),
                'type'              => 'number',
                'desc_tip'          => true,
                'value'             => $coupon->get_advanced_prop( 'cashback_waiting_period' ),
                'custom_attributes' => array(
                    'step' => 1,
                    'min'  => 0,
                ),
            )
        );
    }

    /**
     * Save cashback coupon fields.
     *
     * @since 3.5.2
     * @access public
     *
     * @param int             $coupon_id Coupon ID.
     * @param Advanced_Coupon $coupon    Advanced coupon object.
     */
    public function save_cashback_coupon_fields( $coupon_id, $coupon ) {
        $meta_name = $this->_constants->META_PREFIX . 'cashback_waiting_period';

        // Verify ACFW's dedicated nonce to ensure the request is valid before saving ACFW data.
        // Uses _acfw_nonce (not _wpnonce) to avoid conflicts with plugins like WooPayments that
        // may modify the shared _wpnonce field before form submission.
        if ( ! isset( $_POST['_acfw_nonce'] ) || false === wp_verify_nonce( sanitize_key( $_POST['_acfw_nonce'] ), 'acfw_save_coupon_data_' . $coupon_id ) || ! isset( $_POST[ $meta_name ] ) ) {
            return;
        }

        $coupon->set_advanced_prop( 'cashback_waiting_period', absint( $_POST[ $meta_name ] ) );
    }

    /*
    |--------------------------------------------------------------------------
    | Frontend Implementation
    |--------------------------------------------------------------------------
     */

    /**
     * Register percentage cashback coupon type as a product coupon type.
     * NOTE: this is needed so WC can calculate the valid discount amount based on the products existing in the cart.
     *
     * @since 3.5.2
     * @access public
     *
     * @param array $coupon_types List of coupon types.
     * @return array Filtered list of coupon types.
     */
    public function register_cashback_product_coupon_types( $coupon_types ) {
        $coupon_types[] = 'acfw_percentage_cashback';
        return $coupon_types;
    }

    /**
     * Register fixed cashback coupon type as a cart coupon type.
     * NOTE: this is not really needed during calculation but we're just adding it for completion purposes.
     *
     * @since 3.5.2
     * @access public
     *
     * @param array $coupon_types List of coupon types.
     * @return array Filtered list of coupon types.
     */
    public function register_cashback_cart_coupon_types( $coupon_types ) {
        $coupon_types[] = 'acfw_fixed_cashback';
        return $coupon_types;
    }

    /**
     * Restrict cashback coupon.
     *
     * @since 3.5.2
     * @access public
     *
     * @param bool      $restricted Filter return value.
     * @param WC_Coupon $coupon WC_Coupon object.
     * @return bool True if valid, false otherwise.
     * @throws \Exception Error message.
     */
    public function restrict_cashback_coupon( $restricted, $coupon ) {

        // Don't allow non logged in users to apply the coupon.
        if ( $this->is_cashback_coupon( $coupon ) && ! is_user_logged_in() ) {

            $message     = ACFWF()->Helper_Functions->get_option( $this->_constants->CASHBACK_COUPON_NOTICE_MESSAGE, __( 'Cashback coupons are only for registered customers.', 'advanced-coupons-for-woocommerce' ) );
            $button_url  = ACFWF()->Helper_Functions->get_option( $this->_constants->CASHBACK_COUPON_NOTICE_BTN_URL, wc_get_page_permalink( 'myaccount' ) );
            $button_text = ACFWF()->Helper_Functions->get_option( $this->_constants->CASHBACK_COUPON_NOTICE_BTN_TEXT, __( 'Login', 'advanced-coupons-for-woocommerce' ) );
            $notice_text = sprintf( '%s <a href="%s" class="button">%s</a>', $message, $button_url, $button_text );

            throw new \Exception( wp_kses_post( $notice_text ) );
        }

        return $restricted;
    }

    /**
     * Override the discount calculation for a given cart item so WC can calculate it similarly to a normal percent coupon type.
     * This value is only for calculation purpose and is reset back to zero later, so no actual discount is applied for the coupon.
     *
     * @since 3.5.2
     * @access public
     *
     * @param float      $discount           Coupon discount amount for cart item.
     * @param float      $discounting_amount Amount that needs to be discounted.
     * @param array      $cart_item          Cart item data.
     * @param bool       $single             True if discounting a single qty item, false if its the line.
     * @param \WC_Coupon $coupon             Coupon object.
     */
    public function override_get_item_cashback_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {

        if ( 'acfw_percentage_cashback' === $coupon->get_discount_type() ) {
            $coupon_amount    = apply_filters( 'acfw_percentage_cashback_amount', $coupon->get_amount(), $coupon );
            $precise_discount = wc_add_number_precision( $discounting_amount ) * ( wc_add_number_precision( $coupon_amount ) / wc_add_number_precision( 100 ) );
            $discount         = wc_remove_number_precision( $precise_discount );
        }

        return $discount;
    }

    /**
     * Forcefully set the cashback discount amount to zero so no discount is applied for the coupon.
     *
     * @since 3.5.2
     * @access public
     *
     * @param array      $coupon_discounts List of discounts of validated items for the coupon.
     * @param \WC_Coupon $coupon           Coupon object.
     * @return array Filtered list of discounts of validated items for the coupon.
     */
    public function force_set_cashback_coupon_discount_to_zero( $coupon_discounts, $coupon ) {

        if ( 'acfw_percentage_cashback' === $coupon->get_discount_type() ) {

            $cashback_amount = wc_remove_number_precision( array_sum( $coupon_discounts ) );

            // save the calculated cashback amount to object property.
            $this->_cashback_coupons[ $coupon->get_code() ] = apply_filters( 'acfwp_calculated_percent_cashback_amount', $cashback_amount, $coupon );

            // force discount array values to zero.
            $coupon_discounts = array_map(
                function () {
                return 0;
                },
                $coupon_discounts
            );
        }

        return $coupon_discounts;
    }

    /**
     * Display cashback amount in cart totals table.
     *
     * @since 3.5.2
     * @access public
     *
     * @param string     $discount_amount_html Discount amount html.
     * @param \WC_Coupon $coupon               Coupon object.
     * @return string Filtered discount amount html.
     */
    public function display_cashback_amount_in_cart_totals( $discount_amount_html, $coupon ) {

        if ( $this->is_cashback_coupon( $coupon ) ) {
            $discount_amount_html = sprintf( '%s %s', wc_price( $this->_calculate_cashback_amount( $coupon ) ), __( 'cashback', 'advanced-coupons-for-woocommerce' ) );
        }

        return $discount_amount_html;
    }

    /**
     * Get the cashback coupon waiting period text.
     *
     * @since 4.0.3
     * @access private
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return string Waiting period text.
     */
    private function _get_cashback_coupon_waiting_period_text( $coupon ) {
        $waiting_period = $coupon->get_meta( $this->_constants->META_PREFIX . 'cashback_waiting_period', true );
        if ( $waiting_period ) {
            $text = sprintf(
                /* Translators: %s number of days. */
                _n(
                    'Will be earned as store credits %s day after the order has been completed.',
                    'Will be earned as store credits %s days after the order has been completed.',
                    $waiting_period,
                    'advanced-coupons-for-woocommerce'
                ),
                $waiting_period
            );
        } else {
            $text = __( 'Will be earned as store credits after the order has been completed.', 'advanced-coupons-for-woocommerce' );
        }

        return $text;
    }

    /**
     * Append cashback amount in coupon summary for cart checkout block.
     *
     * @since 3.5.9
     * @access public
     *
     * @param string     $summary Coupon summary.
     * @param \WC_Coupon $coupon  Coupon object.
     * @return string Filtered coupon summary.
     */
    public function append_cashback_amount_in_coupon_summary_for_cart_checkout_block( $summary, $coupon ) {
        if ( $this->is_cashback_coupon( $coupon ) ) {
            $summary .= sprintf(
                '%s %s <small>(%s)</small>',
                wc_price( $this->_calculate_cashback_amount( $coupon ) ),
                __( 'cashback', 'advanced-coupons-for-woocommerce' ),
                $this->_get_cashback_coupon_waiting_period_text( $coupon )
            );
        }

        return $summary;
    }

    /**
     * Display cashback amount in cart totals table.
     *
     * @since 3.5.2
     * @access public
     *
     * @param string     $coupon_html Coupon row html.
     * @param \WC_Coupon $coupon      Coupon object.
     * @return string Filtered Coupon row html.
     */
    public function display_cashback_earn_after_order_complete_notice( $coupon_html, $coupon ) {

        if ( $this->is_cashback_coupon( $coupon ) ) {
            $coupon_html .= sprintf( '<p class="acfw-cashback-earn-note"><small><em>%s</em></small></p>', $this->_get_cashback_coupon_waiting_period_text( $coupon ) );
        }

        return $coupon_html;
    }

    /**
     * Save cashback amount as meta data for the coupon order item.
     *
     * @since 3.5.2
     * @access public
     *
     * @param \WC_Order_Item_Coupon $item        Coupon order item.
     * @param string                $coupon_code Coupon code.
     * @param \WC_Coupon            $coupon      Coupon object.
     */
    public function save_cashback_amount_order_item_meta( $item, $coupon_code, $coupon ) {
        $cashback_amount = $this->_calculate_cashback_amount( $coupon );

        // skip if coupon doesn't have a valid cashback amount.
        if ( is_null( $cashback_amount ) || $cashback_amount <= 0 ) {
            return;
        }

        $cashback_amount = apply_filters( 'acfw_filter_amount', $cashback_amount, true );

        $item->add_meta_data( $this->_constants->ORDER_COUPON_CASHBACK_AMOUNT, $cashback_amount );

        $waiting_period = $coupon->get_meta( $this->_constants->META_PREFIX . 'cashback_waiting_period', true );

        if ( $waiting_period ) {
            $item->add_meta_data( $this->_constants->ORDER_COUPON_CASHBACK_WAITING_PERIOD, $waiting_period );
        }
    }

    /**
     * Add the cashback coupon amounts of the order to the customer's store credits.
     * NOTE: We are keeping this function to add support for custom paid order statuses.
     *
     * @since 3.5.2
     * @access public
     *
     * @param int       $order_id    Order ID.
     * @param string    $prev_status Previous status.
     * @param string    $new_status  New status.
     * @param \WC_Order $order       Order object.
     */
    public function add_cashback_amount_to_customer_store_credit( $order_id, $prev_status, $new_status, $order ) {
        $this->process_cashback_coupons_for_order( $order_id, $order );
    }

    /**
     * Process cashback coupons for order.
     *
     * @since 3.5.3
     * @access public
     *
     * @param int       $order_id    Order ID.
     * @param \WC_Order $order       Order object.
     */
    public function process_cashback_coupons_for_order( $order_id, $order ) {

        $cashback_points_order_status_setting = get_option( $this->_constants->CASHBACK_POINTS_ORDER_STATUS, wc_get_is_paid_statuses() );

        if ( ! in_array( $order->get_status(), $cashback_points_order_status_setting, true ) || ! $order->get_customer_id() ) {
            return;
        }

        // Prevent running the process twice.
        remove_action( 'woocommerce_order_status_changed', array( $this, 'add_cashback_amount_to_customer_store_credit' ), 10, 4 );

        foreach ( $order->get_coupons() as $item ) {

            $is_entry_exists = true && $item->get_meta( $this->_constants->ORDER_COUPON_CASHBACK_STORE_CREDIT_ENTRY, true );
            $cashback_amount = $item->get_meta( $this->_constants->ORDER_COUPON_CASHBACK_AMOUNT, true );
            $waiting_period  = (int) $item->get_meta( $this->_constants->ORDER_COUPON_CASHBACK_WAITING_PERIOD, true );

            // skip if cashback entry already exists or if cashback amount is not valid.
            if ( $is_entry_exists || $cashback_amount <= 0 ) {
                continue;
            }

            // create the cashback store credit entry directly if there's no waiting period set.
            if ( $waiting_period <= 0 ) {
                $this->create_cashback_store_credit_entry( $item->get_id() );
                return;
            }

            $search_key   = sprintf( 'acfwp_cashback_%s', $item->get_id() );
            $is_scheduled = \WC()->queue()->get_next(
                $this->_constants->CASHBACK_ACTION_SCHEDULE,
                array( $item->get_id(), $search_key ),
                'ACFWP'
            );

            // schedule cashback store credit entry creation.
            if ( ! $is_scheduled ) {
                \WC()->queue()->schedule_single(
                    time() + ( $waiting_period * DAY_IN_SECONDS ),
                    $this->_constants->CASHBACK_ACTION_SCHEDULE,
                    array( $item->get_id(), $search_key ),
                    'ACFWP'
                );
            }
        }
    }

    /**
     * Create casback store credit entry for a given order coupon item.
     *
     * @since 3.5.2
     * @access public
     *
     * @param int|\WC_Order_Item_Coupon $coupon_item Order coupon item ID or object.
     */
    public function create_cashback_store_credit_entry( $coupon_item ) {
        $coupon_item     = $coupon_item instanceof \WC_Order_Item_Coupon ? $coupon_item : new \WC_Order_Item_Coupon( $coupon_item );
        $order           = $coupon_item->get_id() ? $coupon_item->get_order() : null;
        $cashback_amount = $coupon_item->get_id() ? $coupon_item->get_meta( $this->_constants->ORDER_COUPON_CASHBACK_AMOUNT, true ) : 0;
        $is_entry_exists = $coupon_item->get_id() && $coupon_item->get_meta( $this->_constants->ORDER_COUPON_CASHBACK_STORE_CREDIT_ENTRY, true );

        // skip if order or cashback amount is not valid.
        if ( ! $order instanceof \WC_Order || $is_entry_exists || $cashback_amount <= 0 ) {
            return;
        }

        // skip if order is not of paid status anymore, or has no customer set.
        if ( ! in_array( $order->get_status(), wc_get_is_paid_statuses(), true ) || ! $order->get_customer_id() ) {
            return;
        }

        $store_credit_entry = new Store_Credit_Entry();

        $store_credit_entry->set_prop( 'amount', (float) $cashback_amount );
        $store_credit_entry->set_prop( 'user_id', $order->get_customer_id() );
        $store_credit_entry->set_prop( 'type', 'increase' );
        $store_credit_entry->set_prop( 'action', 'cashback_coupon' );
        $store_credit_entry->set_prop( 'object_id', $order->get_id() );

        $check = $store_credit_entry->save();

        // don't proceed when store credit failed to be created.
        if ( is_wp_error( $check ) ) {
            return;
        }

        $coupon_item->add_meta_data( $this->_constants->ORDER_COUPON_CASHBACK_STORE_CREDIT_ENTRY, $store_credit_entry->get_id(), true );
        $coupon_item->save_meta_data();
    }

    /**
     * Display cashback amount in the order review table.
     *
     * @since 3.5.2
     * @access public
     *
     * @param array    $total_rows Order review total rows.
     * @param WC_Order $order     Order object.
     * @return array Filtered order review total rows.
     */
    public function display_cashback_amount_in_order_review( $total_rows, $order ) {

        // only add these rows on order emails, order received page, and on the view order page to prevent this data
        // be sent on invoice and/or accounting related plugins.
        if ( ! did_action( 'woocommerce_email_before_order_table' )
            && ! did_action( 'woocommerce_order_details_before_order_table' )
            && ! apply_filters( 'acfwp_display_cashback_amount_in_order_review', false )
        ) {
            return $total_rows;
        }

        $cashback_coupons = $this->get_cashback_summary_for_order( $order );

        if ( is_array( $cashback_coupons ) && ! empty( $cashback_coupons ) ) {
            foreach ( $cashback_coupons as $row ) {
                $key = sprintf( 'acfwp_cashback_%s', $row['code'] );

                // get text value to display based on the status.
                switch ( $row['status'] ) {

                    case 'created':
                        $text = wc_price( $row['amount'] );
                        break;

                    case 'scheduled':
                        $datetime = new \WC_DateTime( $row['schedule'] );
                        $datetime->setTimezone( new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() ) );

                        $text = sprintf(
                            '%s <small><em>(%s %s)</small></em>',
                            wc_price( $row['amount'] ),
                            /* Translators: Partial sentence, used on the order summary as "$123.00 (will be added on 1st January 2023)" */
                            __( 'will be added on', 'advanced-coupons-for-woocommerce' ),
                            $datetime->format( $this->_helper_functions->get_datetime_format() )
                        );
                        break;

                    default:
                        $text = sprintf(
                            '%s <small><em>(%s)</small></em>',
                            wc_price( $row['amount'] ),
                            __( 'pending', 'advanced-coupons-for-woocommerce' )
                        );
                        break;
                }

                $total_rows[ $key ] = array(
                    'label' => __( 'Cashback (store credits)', 'advanced-coupons-for-woocommerce' ) . ':',
                    'value' => $text,
                );
            }
        }

        return $total_rows;
    }

    /**
     * Display a summary of the cashback coupons in the edit order page.
     *
     * @since 3.5.2
     * @access public
     *
     * @param int $order_id Order ID.
     */
    public function display_cashback_summary_in_edit_order_page( $order_id ) {
        $order            = wc_get_order( $order_id );
        $cashback_coupons = $this->get_cashback_summary_for_order( $order );

        // don't proceed if order has no cashback coupons.
        if ( ! is_array( $cashback_coupons ) || empty( $cashback_coupons ) ) {
            return;
        }

        $revoked_cashback = $this->_queries->query_single_store_credit_entry(
            array(
                'type'      => 'decrease',
                'action'    => 'revoked_cashback',
                'object_id' => $order->get_id(),
            )
        );

        include $this->_constants->VIEWS_ROOT_PATH . 'orders' . DIRECTORY_SEPARATOR . 'view-order-cashback-coupon-summary.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Utility functions.
    |--------------------------------------------------------------------------
     */

    /**
     * Check if a given coupon is of cashback discount type.
     *
     * @since 3.5.2
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return bool True if cashback coupon, false otherwise.
     */
    public function is_cashback_coupon( $coupon ) {

        if ( ! $coupon instanceof \WC_Coupon ) {
            return false;
        }

        return in_array( $coupon->get_discount_type(), array( 'acfw_percentage_cashback', 'acfw_fixed_cashback' ), true );
    }

    /**
     * Calculate cashback amount.
     *
     * @since 3.5.2
     * @access private
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return float|null Cashback amount if valid cashback coupon, null if not.
     */
    private function _calculate_cashback_amount( $coupon ) {

        do_action( 'acfwp_before_calculate_cashback_amount', $coupon );

        $cashback_amount = null;

        switch ( $coupon->get_discount_type() ) {

            case 'acfw_percentage_cashback':
                // NOTE: calculated amount value is already converted for Aelia, WPML and Woocs.
                $cashback_amount = isset( $this->_cashback_coupons[ $coupon->get_code() ] ) ? $this->_cashback_coupons[ $coupon->get_code() ] : 0;
                break;

            case 'acfw_fixed_cashback':
                $cashback_amount = apply_filters( 'acfw_filter_amount', $coupon->get_amount() );
                break;
        }

        do_action( 'acfwp_after_calculate_cashback_amount', $coupon );

        return $cashback_amount;
    }

    /**
     * Get cashback coupons summary for a given order.
     *
     * @since 3.5.2
     * @access public
     *
     * @param \WC_Order $order Order object.
     * @return array Order cashback coupons data.
     */
    public function get_cashback_summary_for_order( $order ) {

        $cashback_coupons = array();

        foreach ( $order->get_coupons() as $item ) {

            // reload coupon order items meta to ensure the custom metas we added are loaded.
            $item->read_meta_data();

            $cashback_amount = $item->get_meta( $this->_constants->ORDER_COUPON_CASHBACK_AMOUNT, true );

            // skip if coupon is not of cashback discount type.
            if ( is_null( $cashback_amount ) || $cashback_amount <= 0 ) {
                continue;
            }

            $key             = sprintf( 'acfwp_cashback_%s', $item->get_code() );
            $is_entry_exists = true && $item->get_meta( $this->_constants->ORDER_COUPON_CASHBACK_STORE_CREDIT_ENTRY, true );
            $waiting_period  = (int) $item->get_meta( $this->_constants->ORDER_COUPON_CASHBACK_WAITING_PERIOD, true );
            $status          = 'pending';
            $is_scheduled    = false;

            // cashback store credit entry already created.
            if ( $is_entry_exists ) {
                $status = 'created';
            } elseif ( $waiting_period > 0 ) {

                // check if order already completed but cashback store credit entry will be added later on a schedule.
                $search_key   = sprintf( 'acfwp_cashback_%s', $item->get_id() );
                $is_scheduled = \WC()->queue()->get_next(
                    $this->_constants->CASHBACK_ACTION_SCHEDULE,
                    array( $item->get_id(), $search_key ),
                    'ACFWP'
                );

                $status = $is_scheduled ? 'scheduled' : $status;
            }

            $cashback_coupons[] = array(
                'item_id'    => $item->get_id(),
                'code'       => $item->get_code(),
                'raw_amount' => $cashback_amount,
                'amount'     => apply_filters( 'acfw_filter_amount', $cashback_amount, false, array( 'user_currency' => $order->get_currency() ) ),
                'status'     => $status,
                'schedule'   => $is_scheduled,
            );
        }

        return $cashback_coupons;
    }

    /**
     * Remove cashback coupon store credit entry if cashback coupon is removed from order.
     *
     * @since 3.5.6
     * @access public
     *
     * @param \WC_Data          $order      The object being saved.
     * @param \WC_Data_Store_WP $data_store THe data store persisting the data.
     */
    public function remove_cashback_coupon_store_credit_entry( $order, $data_store ) {
        $is_remove_coupon = isset( $_POST['action'] ) && 'woocommerce_remove_order_coupon' === sanitize_text_field( wp_unslash( $_POST['action'] ) ) ?? false; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $coupon_code      = isset( $_POST['coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        // Check if action is remove coupon and coupon code is valid.
        if ( ! $is_remove_coupon || ! $coupon_code ) {
            return;
        }

        // Check if coupon is a cashback coupon.
        $coupon = new \WC_Coupon( $coupon_code );
        if ( ! $this->is_cashback_coupon( $coupon ) ) {
            return;
        }

        // Query cashback store credit entry.
        $order              = wc_get_order( $order->get_id() );
        $store_credit_entry = $this->_queries->query_single_store_credit_entry(
            array(
                'type'      => 'increase',
                'action'    => 'cashback_coupon',
                'object_id' => $order->get_id(),
            )
        );

        // don't proceed when store credit entry is not found.
        if ( ! $store_credit_entry instanceof Store_Credit_Entry ) {
            return;
        }

        // delete order meta data and store credit entry.
        $order->delete_meta_data( $this->_constants->ORDER_COUPON_CASHBACK_AMOUNT );
        $order->delete_meta_data( $this->_constants->ORDER_COUPON_CASHBACK_STORE_CREDIT_ENTRY );
        $store_credit_entry->delete();

        // recalculate coupons and order totals.
        $order->recalculate_coupons();
        $order->calculate_totals( true );

        // recalculate user store credit balance.
        \ACFWF()->Store_Credits_Calculate->get_customer_balance( $order->get_customer_id(), true );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Cashback_Coupon class.
     *
     * @since 3.5.2
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::CASHBACK_COUPON_MODULE )
            || ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            return;
        }

        add_filter( 'woocommerce_coupon_discount_types', array( $this, 'register_cashback_coupon_types' ) );
        add_filter( 'acfw_get_store_credits_increase_source_types', array( $this, 'register_cashback_store_credit_source_type' ) );
        add_action( 'woocommerce_coupon_options', array( $this, 'display_cashback_coupon_fields' ) );
        add_action( 'acfw_before_save_coupon', array( $this, 'save_cashback_coupon_fields' ), 10, 2 );

        add_filter( 'woocommerce_product_coupon_types', array( $this, 'register_cashback_product_coupon_types' ) );
        add_filter( 'woocommerce_cart_coupon_types', array( $this, 'register_cashback_cart_coupon_types' ) );
        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'restrict_cashback_coupon' ), 11, 2 );
        add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'override_get_item_cashback_amount' ), 10, 5 );
        add_filter( 'woocommerce_coupon_custom_discounts_array', array( $this, 'force_set_cashback_coupon_discount_to_zero' ), 10, 2 );
        add_filter( 'woocommerce_coupon_discount_amount_html', array( $this, 'display_cashback_amount_in_cart_totals' ), 10, 2 );
        add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'display_cashback_earn_after_order_complete_notice' ), 10, 2 );
        add_filter( 'acfwf_cart_checkout_block_coupon_summary', array( $this, 'append_cashback_amount_in_coupon_summary_for_cart_checkout_block' ), 5, 2 );
        add_action( 'woocommerce_checkout_create_order_coupon_item', array( $this, 'save_cashback_amount_order_item_meta' ), 10, 3 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'add_cashback_amount_to_customer_store_credit' ), 10, 4 );
        add_action( $this->_constants->CASHBACK_ACTION_SCHEDULE, array( $this, 'create_cashback_store_credit_entry' ) );
        add_action( 'woocommerce_before_order_object_save', array( $this, 'remove_cashback_coupon_store_credit_entry' ), 10, 2 );

        add_filter( 'woocommerce_get_order_item_totals', array( $this, 'display_cashback_amount_in_order_review' ), 10, 2 );
        add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_cashback_summary_in_edit_order_page' ), 10, 999 );
    }
}
