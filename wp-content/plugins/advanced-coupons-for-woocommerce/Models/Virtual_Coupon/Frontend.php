<?php
namespace ACFWP\Models\Virtual_Coupon;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;
use ACFWP\Models\Objects\Virtual_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of extending the coupon system of woocommerce.
 * It houses the logic of handling coupon url.
 * Public Model.
 *
 * @since 3.0
 */
class Frontend extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that houses the model name to be used when calling publicly.
     *
     * @since 3.5.3
     * @access private
     * @var string
     */
    private $_model_name = 'Virtual_Coupon_Frontend';

    /**
     * Property that holds the virtual coupon codes that are marked as invalid.
     *
     * @since 3.0
     * @access private
     * @var array
     */
    private $_invalid_virtual_codes = array();

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.0
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $main_plugin->add_to_all_plugin_models( $this, $this->_model_name );
        $main_plugin->add_to_public_models( $this, $this->_model_name );
    }

    /*
    |--------------------------------------------------------------------------
    | Implementation.
    |--------------------------------------------------------------------------
     */

    /**
     * Implement virtual coupon URL.
     *
     * @since 3.5.2
     * @access public
     */
    public function implement_virtual_coupon_url() {
        global $wp_query;

        if ( ! isset( $wp_query->query['post_type'] ) || 'shop_coupon' !== $wp_query->query['post_type'] ) {
            return;
        }

        $coupon_code = isset( $wp_query->query['name'] ) ? sanitize_title( $wp_query->query['name'] ) : '';
        $separator   = $this->_constants->get_virtual_coupon_code_separator();
        $temp_array  = explode( $separator, $coupon_code );
        $coupon_slug = isset( $temp_array[0] ) ? $temp_array[0] : '';
        $vcode       = isset( $temp_array[1] ) ? $temp_array[1] : '';

        // maybe get the post object based on the path/slug but only when coupon slug and the assumed virtual coupon code is available.
        $post = $coupon_slug && $vcode ? get_page_by_path( $coupon_slug, OBJECT, 'shop_coupon' ) : null;

        // skip if the post object wasn't detected.
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        $virtual_coupon = Virtual_Coupon::create_from_coupon_code( $coupon_code );

        // skip if virtual coupon is not valid.
        if ( ! $virtual_coupon->get_id() || $virtual_coupon->get_prop( 'coupon_id' ) !== $post->ID || ! $virtual_coupon->is_valid() ) {
            return;
        }

        // Initialize cart session.
        if ( ! \WC()->session->has_session() ) {
            \WC()->session->set_customer_session_cookie( true );
        }

        // save virtual coupon to session.
        $this->_add_virtual_coupon_to_session( $post->ID, $virtual_coupon );

        // replace the WP query name with the main coupon slug and let ACFWF apply the coupon normally.
        $wp_query->query['name'] = $coupon_slug;

        // Ignore remove coupon session if apply from url.
        // This is required to make sure that the virtual coupon is not removed from the session.
        // Then invoke cart error : Coupon does not exist!
        remove_action( 'woocommerce_removed_coupon', array( $this, 'remove_unused_virtual_coupons_from_session' ) );

        // Additional action hook for developers to implement their own virtual coupon url.
        do_action( 'acfwp_implement_virtual_coupon_url', $virtual_coupon );
    }

    /**
     * Filter woocommerce_get_coupon_id_from_code function to get the coupon ID
     * if the given code is for a virtual coupon.
     *
     * @since 3.0
     * @since 3.5.1 Make sure the virtual coupon object can be properly fetched for installs with persistent object cache setup.
     * @access public
     *
     * @param int    $coupon_id   Coupon ID.
     * @param string $coupon_code Coupon code submitted in form.
     * @return int Filtered coupon ID.
     */
    public function get_coupon_id_for_virtual_coupon( $coupon_id, $coupon_code ) {
        // Validate context that we are running this in the frontend, sessions are available, and that the virtual coupon is not invalid.
        if ( is_admin() || ! is_object( \WC()->session ) || isset( $this->_invalid_virtual_codes[ $coupon_code ] ) ) {
            return $coupon_id;
        }

        $data           = $coupon_id ? $this->_get_virtual_coupon_data_from_session( $coupon_id ) : $this->_get_virtual_coupon_data_from_session_by_code( $coupon_code );
        $virtual_coupon = $data && strtolower( $data['coupon_code'] ) === strtolower( $coupon_code ) ? new Virtual_Coupon( $data['id'] ) : Virtual_Coupon::create_from_coupon_code( $coupon_code );

        if ( $virtual_coupon->get_id() && strtolower( $virtual_coupon->get_coupon_code() ) === strtolower( $coupon_code ) ) {

            // set the value to cache so we don't need to refetch the coupon ID.
            if ( ! $coupon_id ) {
                \wp_cache_set(
                    \WC_Cache_Helper::get_cache_prefix( 'coupons' ) . 'coupon_id_from_code_' . $coupon_code,
                    array( $virtual_coupon->get_prop( 'coupon_id' ) ),
                    'coupons'
                );
            }

            $coupon_id = $virtual_coupon->get_prop( 'coupon_id' );

            // validate virtual coupon.
            if ( $virtual_coupon->is_valid() ) {

                // save virtual coupon to session.
                $check = $this->_add_virtual_coupon_to_session( $coupon_id, $virtual_coupon );

                /**
                 * When virtual coupon is valid but was not added successfully to session, it means another virtual coupon
                 * with the same main coupon was already applied. We need to set this as an invalid virtual coupon code and
                 * set the error code to already applied enum value.
                 */
                if ( ! $check ) {
                    $this->_invalid_virtual_codes[ $coupon_code ] = \WC_Coupon::E_WC_COUPON_ALREADY_APPLIED;
                }
            } else {
                $this->_invalid_virtual_codes[ $coupon_code ] = $virtual_coupon->get_error_code();
                $this->remove_unused_virtual_coupons_from_session_by_id( $coupon_id );
            }
        }

        return $coupon_id;
    }

    /**
     * Override coupon code with virtual coupon when WC_Coupon object is loaded.
     * This function runs after `get_coupon_id_for_virtual_coupon`.
     *
     * @since 3.0
     * @access public
     *
     * @param WC_Coupon $coupon Coupon object.
     */
    public function override_coupon_code_with_virtual_coupon( $coupon ) {
        if ( is_admin() || ! is_object( \WC()->session ) || ! (bool) $coupon->get_meta( '_acfw_enable_virtual_coupons' ) ) {
            return;
        }

        /**
         * If the invalid virtual codes is not empty, then it means that the most recent virtual coupon code applied
         * has the same main coupon for the one already applied in the cart. We need to set the coupon code property of
         * WC_Coupon here so we can customize the error message in the validation check function.
         */
        if ( ! empty( $this->_invalid_virtual_codes ) ) {
            $invalid_code_keys = array_keys( $this->_invalid_virtual_codes );
            $invalid_code      = end( $invalid_code_keys );

            if ( in_array( $invalid_code, \WC()->cart->get_applied_coupons(), true ) ) {
                $coupon->set_code( $invalid_code );
                return;
            }
        }

        $data           = $this->_get_virtual_coupon_data_from_session( $coupon->get_id() );
        $virtual_coupon = $data ? new Virtual_Coupon( $data ) : null;

        // check if virtual coupon data is present in session.
        if ( $virtual_coupon && $virtual_coupon->get_id() ) {

            // set coupon code to the virtual coupon code.
            $coupon->set_code( $virtual_coupon->get_coupon_code() );

            // set date created if available.
            if ( $virtual_coupon->get_date_created() instanceof \WC_DateTime ) {
                $coupon->set_date_created( $virtual_coupon->get_date_created() );
            }

            // set date expire if available.
            if ( $virtual_coupon->get_date_expire() instanceof \WC_DateTime ) {
                $coupon->set_date_expires( $virtual_coupon->get_date_expire() );
            }

            $coupon->apply_changes();
        }
    }

    /**
     * Filter the `woocommerce_coupon_is_valid` hook throw exception error objects for invalid virtual coupons.
     *
     * @since 3.0
     * @access public
     *
     * @param bool      $is_valid Filter return value.
     * @param WC_Coupon $coupon WC_Coupon object.
     * @return bool True if valid, false otherwise.
     * @throws \Exception Error message.
     */
    public function filter_is_coupon_valid_for_virtual_coupon( $is_valid, $coupon ) {

        // skip if not a valid coupon object.
        if ( ! $coupon instanceof \WC_Coupon ) {
            return $is_valid;
        }

        $coupon_code = '';

        if ( (bool) $coupon->get_meta( $this->_constants->META_PREFIX . 'enable_virtual_coupons', true ) ) {

            $post_object = get_post( $coupon->get_id() );

            if (
                'publish' !== $post_object->post_status
                || strtolower( $post_object->post_title ) === strtolower( $coupon->get_code() ) // restrict main coupon code to be applied.
                || ! $this->_validate_coupon_date_created( $coupon )
            ) {
                throw new \Exception( wp_kses_post( $coupon->get_coupon_error( \WC_Coupon::E_WC_COUPON_NOT_EXIST ) ) );
            }

            // explicitly return error when virtual coupon with same main coupon already applied.
            if ( isset( $this->_invalid_virtual_codes[ $coupon->get_code() ] ) ) {
                throw new \Exception( wp_kses_post( $coupon->get_coupon_error( $this->_invalid_virtual_codes[ $coupon->get_code() ] ) ) );
            }

            $data        = $this->_get_virtual_coupon_data_from_session( $coupon->get_id() );
            $coupon_code = $data && isset( $data['coupon_code'] ) ? $data['coupon_code'] : $coupon_code;
        }

        // explicitly return error when the virtual coupon code is the one invalidated.
        if ( $coupon_code && isset( $this->_invalid_virtual_codes[ $coupon_code ] ) ) {
            throw new \Exception( wp_kses_post( $coupon->get_coupon_error( $this->_invalid_virtual_codes[ $coupon_code ] ) ) );
        }

        return $is_valid;
    }

    /**
     * Compare coupon date create value with current date value.
     * If date create value is set for future, then we should invalidate the coupon.
     *
     * @since 3.0
     * @access private
     *
     * @param WC_Coupon $coupon Coupon object.
     * @return bool True if valid, false otherwise.
     */
    private function _validate_coupon_date_created( $coupon ) {
        $now_date = new \WC_Datetime( 'now', new \DateTimeZone( \ACFWP()->Helper_Functions->get_site_current_timezone() ) );
        return $coupon->get_date_created() && $coupon->get_date_created() <= $now_date;
    }

    /**
     * Override the ACFWP scheduler date expire value.
     *
     * @since 3.0
     * @access public
     *
     * @param string          $date Date value in mysql format.
     * @param string          $prop Property name.
     * @param Advanced_Coupon $coupon Coupon object.
     */
    public function override_scheduler_date_expire( $date, $prop, $coupon ) {
        // skip if prop is not for schedule end.
        if ( 'schedule_end' !== $prop ) {
            return $date;
        }

        $data           = $this->_get_virtual_coupon_data_from_session( $coupon->get_id() );
        $virtual_coupon = $data ? new Virtual_Coupon( $data ) : null;

        if ( $virtual_coupon && $virtual_coupon->get_id() && $virtual_coupon->get_date_expire() instanceof \WC_DateTime ) {
            $date = $virtual_coupon->get_date_expire();
        }

        return $date;
    }

    /*
    |--------------------------------------------------------------------------
    | Session handlers.
    |--------------------------------------------------------------------------
     */

    /**
     * Add virtual coupon data to session.
     *
     * @since 3.0
     * @access private
     *
     * @param int            $coupon_id      Coupon ID.
     * @param Virtual_Coupon $virtual_coupon Virtual coupon object.
     */
    private function _add_virtual_coupon_to_session( $coupon_id, Virtual_Coupon $virtual_coupon ) {
        if ( ! is_object( \WC()->session ) ) {
            return false;
        }

        $in_session = \WC()->session->get( 'acfw_virtual_coupons', array() );
        $data       = isset( $in_session[ $coupon_id ] ) ? $in_session[ $coupon_id ] : null;

        // invalidate if a virtual coupon was already applied that shares the same main coupon.
        if ( $data && $virtual_coupon->get_code() !== $data['virtual_coupon'] ) {
            return false;
        }

        // get virtual coupon session data.
        $new_session_data = $virtual_coupon->get_data_for_session();

        // return as valid when trying to apply virtual coupon and session data has not changed.
        if ( $data && $virtual_coupon->get_code() === $data['virtual_coupon'] && $data === $new_session_data ) {
            return true;
        }

        $in_session[ $coupon_id ] = $new_session_data;
        \WC()->session->set( 'acfw_virtual_coupons', $in_session );
        return true;
    }

    /**
     * Remove virtual coupon from session.
     *
     * @since 3.0
     * @access private
     *
     * @param int $coupon_id Coupon ID.
     */
    private function _remove_virtual_coupon_from_session( $coupon_id ) {
        if ( ! is_object( \WC()->session ) ) {
            return;
        }

        $in_session = \WC()->session->get( 'acfw_virtual_coupons', array() );
        unset( $in_session[ $coupon_id ] );

        \WC()->session->set( 'acfw_virtual_coupons', $in_session );
    }

    /**
     * Get virtual coupon from session.
     *
     * @since 3.0
     * @access private
     *
     * @param int $coupon_id Coupon ID.
     * @return array Virtual coupon session data.
     */
    private function _get_virtual_coupon_data_from_session( $coupon_id ) {
        if ( ! is_object( \WC()->session ) ) {
            return null;
        }

        $in_session = \WC()->session->get( 'acfw_virtual_coupons', array() );
        $data       = isset( $in_session[ $coupon_id ] ) ? $in_session[ $coupon_id ] : false;

        return $data ? $data : null;
    }

    /**
     * Get virtual coupon data from session by a given coupon code.
     *
     * @since 3.0
     * @access private
     *
     * @param int $coupon_code Coupon code.
     * @return array Virtual coupon session data.
     */
    private function _get_virtual_coupon_data_from_session_by_code( $coupon_code ) {
        if ( ! is_object( \WC()->session ) ) {
            return null;
        }

        $in_session = \WC()->session->get( 'acfw_virtual_coupons', array() );

        if ( empty( $in_session ) ) {
            return null;
        }

        $data = array_filter(
            $in_session,
            function ( $d ) use ( $coupon_code ) {
                return strtolower( $d['coupon_code'] ) === strtolower( $coupon_code ) || strtolower( $d['parent_coupon_code'] ) === strtolower( $coupon_code );
            }
        );

        return ! empty( $data ) ? current( $data ) : null;
    }

    /**
     * Remove unused virtual coupons from session.
     *
     * @since 3.0
     * @since 3.5.1 Remove main coupon code from list of applied coupons too. Prevent WC to re-apply virtual coupon code during removal.
     * @access public
     *
     * @param int $coupon_code Coupon code.
     */
    public function remove_unused_virtual_coupons_from_session( $coupon_code ) {
        if ( ! is_object( \WC()->session ) ) {
            return;
        }

        $in_session = \WC()->session->get( 'acfw_virtual_coupons', array() );

        if ( ! empty( $in_session ) ) {
            $in_session = array_filter(
                $in_session,
                function ( $d ) use ( $coupon_code ) {
                return strtolower( $d['coupon_code'] ) !== strtolower( $coupon_code );
                }
            );
        }

        \WC()->session->set( 'acfw_virtual_coupons', ! empty( $in_session ) ? $in_session : null );

        /**
         * For some reason WC keeps the main coupon code when trying to remove the virtual coupon code.
         * We need to make sure that the main coupon code from the list of applied coupons to prevent unneccessary error messages.
         *
         * @since 3.5.1
         */
        $applied_coupons = \WC()->cart->get_applied_coupons();
        $is_updated      = false;

        foreach ( $applied_coupons as $key => $applied_coupon ) {
            if ( str_contains( $coupon_code, $applied_coupon ) ) {
                unset( $applied_coupons[ $key ] );
                $is_updated = true;
            }
        }

        if ( $is_updated ) {
            \WC()->cart->set_applied_coupons( $applied_coupons );
        }

        // Remove the filter to prevent WC to re-apply the virtual coupon code when trying to remove it.
        remove_filter( 'woocommerce_get_coupon_id_from_code', array( $this, 'get_coupon_id_for_virtual_coupon' ), 10, 2 );
    }

    /**
     *
     * Clear virtual coupons session.
     *
     * @since 3.1.2
     * @access public
     */
    public function clear_virtual_coupons_session() {
        if ( ! is_object( \WC()->session ) ) {
            return;
        }

        $in_session = \WC()->session->get( 'acfw_virtual_coupons', array() );

        if ( ! empty( $in_session ) ) {
            \WC()->session->set( 'acfw_virtual_coupons', null );
        }
    }

    /**
     * Remove unused virtual coupons from session by ID.
     *
     * @since 3.1.1
     * @since 3.5.1 Add coupon code parameter which when set will only remove the virtual coupon data from session when the coupon code matches.
     * @access public
     *
     * @param int         $coupon_id Coupon ID.
     * @param string|bool $coupon_code Coupon code.
     */
    public function remove_unused_virtual_coupons_from_session_by_id( $coupon_id, $coupon_code = false ) {
        if ( ! is_object( \WC()->session ) ) {
            return;
        }

        $in_session = \WC()->session->get( 'acfw_virtual_coupons', array() );

        if ( isset( $in_session[ $coupon_id ] ) ) {
            if ( ! $coupon_code || strtolower( $in_session[ $coupon_id ]['coupon_code'] ) === strtolower( $coupon_code ) ) {
                unset( $in_session[ $coupon_id ] );
            }
        }

        \WC()->session->set( 'acfw_virtual_coupons', ! empty( $in_session ) ? $in_session : null );
    }

    /**
     * Remove unused virtual coupons from session when the applied coupon is invalid.
     *
     * @since 3.1.1
     * @since 3.5.1 Remove the virtual coupon data from session only when a valid error code is provided.
     * @access public
     *
     * @param string         $message    Error message.
     * @param int            $error_code Error code.
     * @param WC_Coupon|null $coupon     Coupon object.
     * @return string Filtered error message.
     */
    public function remove_unused_virtual_coupons_from_session_on_coupon_error( $message, $error_code, $coupon ) {
        if ( $error_code && $coupon ) {
            $this->remove_unused_virtual_coupons_from_session_by_id( $coupon->get_id(), $coupon->get_code() );
        }

        return $message;
    }

    /*
    |--------------------------------------------------------------------------
    | Checkout process.
    |--------------------------------------------------------------------------
     */

    /**
     * Save virtual coupons applied in order to relative line item meta.
     *
     * @since 3.0
     * @access public
     *
     * @param int|WC_Order $order_id          Order ID or Order object.
     * @param array        $posted_data        Posted data from checkout form (optional).
     * @param WC_Order     $order              Order object (optional).
     */
    public function save_virtual_coupons_data_to_order( $order_id, $posted_data = null, $order = null ) {
        // Handle different hook signatures.
        if ( $order_id instanceof \WC_Order ) {
            $order    = $order_id;
            $order_id = $order->get_id();
        } elseif ( ! $order ) {
            $order = wc_get_order( $order_id );
        }

        // skip if order is not valid.
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $coupons = $order->get_items( 'coupon' ); // array of WC_Order_Item_Coupon.

        foreach ( $coupons as $coupon ) {
            $data           = $this->_get_virtual_coupon_data_from_session_by_code( $coupon->get_code() );
            $virtual_coupon = $data ? new Virtual_Coupon( $data ) : null;

            if ( ! $virtual_coupon || ! $virtual_coupon->get_id() ) {
                continue;
            }

            // Update virtual coupon status to used. Don't update if it's unlimited.
            if ( 'unlimited' !== $virtual_coupon->get_prop( 'status' ) ) {
                $virtual_coupon->set_prop( 'status', 'used' );
            }

            $virtual_coupon->save();

            // save virtual coupon data to coupon order line item meta.
            $coupon->update_meta_data( $this->_constants->VIRTUAL_COUPONS_META_PREFIX . 'data', $virtual_coupon->get_data_for_session() );
            $coupon->update_meta_data( $this->_constants->VIRTUAL_COUPONS_META_PREFIX . 'id', $virtual_coupon->get_id() );
            $coupon->update_meta_data( $this->_constants->VIRTUAL_COUPONS_META_PREFIX . 'main_id', $virtual_coupon->get_prop( 'coupon_id' ) );
            $coupon->save_meta_data();

            do_action( 'acfwp_virtual_coupon_used_in_order', $virtual_coupon->get_coupon_code(), $order_id, $virtual_coupon, $order );
        }

        // clear session data.
        if ( is_object( \WC()->session ) ) {
            \WC()->session->set( 'acfw_virtual_coupons', null );
        }
    }

    /**
     * Reset virtual coupon status back to pending for failed and/or cancelled orders.
     *
     * @since 3.5.2
     * @access public
     *
     * @param int       $order_id    Order ID.
     * @param string    $prev_status Previous order status.
     * @param string    $new_status  New order status.
     * @param \WC_Order $order       Order object.
     */
    public function maybe_reset_virtual_coupon_status_as_pending( $order_id, $prev_status, $new_status, $order ) {

        $failed_statuses = apply_filters( 'acfw_virtual_coupon_reset_status_order_statuses', array( 'failed', 'cancelled' ) );

        // skip if new status is not for failed or cancelled.
        if ( ! in_array( $new_status, $failed_statuses, true ) ) {
            return;
        }

        $coupon_items = array_filter(
            $order->get_coupons(),
            function ( $oc ) {
            $data = $oc->get_meta( $this->_constants->VIRTUAL_COUPONS_META_PREFIX . 'data', true );
            return is_array( $data ) && ! empty( $data );
            }
        );

        // skip if order has no virtual coupon items.
        if ( empty( $coupon_items ) ) {
            return;
        }

        foreach ( $coupon_items as $coupon_item ) {
            $virtual_coupon = new Virtual_Coupon( absint( $coupon_item->get_meta( $this->_constants->VIRTUAL_COUPONS_META_PREFIX . 'id', true ) ) );

            // skip if virtual coupon is not valid.
            if ( ! $virtual_coupon->get_id() ) {
                continue;
            }

            // update virtual coupon status to pending.
            $virtual_coupon->set_prop( 'status', 'pending' );
            $virtual_coupon->save();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Frontend class.
     *
     * @since 3.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::VIRTUAL_COUPONS_MODULE ) ) {
            add_action( 'woocommerce_coupon_loaded', array( $this, 'clear_virtual_coupons_session' ), 1 );
            return;
        }

        add_action( 'template_redirect', array( $this, 'implement_virtual_coupon_url' ), 5 );
        add_filter( 'woocommerce_get_coupon_id_from_code', array( $this, 'get_coupon_id_for_virtual_coupon' ), 10, 2 );
        add_action( 'woocommerce_coupon_loaded', array( $this, 'override_coupon_code_with_virtual_coupon' ) );
        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'filter_is_coupon_valid_for_virtual_coupon' ), 10, 2 );
        add_filter( 'acfw_get_coupon_schedule_date', array( $this, 'override_scheduler_date_expire' ), 10, 3 );
        add_action( 'woocommerce_removed_coupon', array( $this, 'remove_unused_virtual_coupons_from_session' ) );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_virtual_coupons_data_to_order' ), 10, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'save_virtual_coupons_data_to_order' ), 10, 3 );
        add_action( 'woocommerce_coupon_error', array( $this, 'remove_unused_virtual_coupons_from_session_on_coupon_error' ), 10, 3 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_reset_virtual_coupon_status_as_pending' ), 10, 4 );
        add_action( 'acfw_restrict_allowed_bogo_coupons_error_message', array( $this, 'remove_unused_virtual_coupons_from_session_on_coupon_error' ), 10, 3 );
    }
}
