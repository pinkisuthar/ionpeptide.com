<?php
namespace ACFWF\Models;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Advanced_Coupon;
use ACFWF\Models\Objects\Date_Period_Range;
use ACFWF\Models\Objects\Report_Widgets\Amount_Discounted;
use ACFWF\Models\Objects\Report_Widgets\Coupons_Used;
use ACFWF\Models\Objects\Report_Widgets\Discounted_Order_Revenue;
use ACFWF\Models\Objects\Report_Widgets\Orders_Discounted;
use ACFWF\Models\Objects\Report_Widgets\Top_Coupons;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that registers the plugin's WordPress Abilities API abilities.
 * Public Model.
 *
 * @since 4.7.4
 */
class Abilities extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.7.4
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
    | Registration
    |--------------------------------------------------------------------------
     */

    /**
     * Register the ability category for the Advanced Coupons plugin family.
     *
     * @since 4.7.4
     * @access public
     */
    public function register_category() {
        if ( ! wp_has_ability_category( 'advanced-coupons' ) ) {
            wp_register_ability_category(
                'advanced-coupons',
                array(
                    'label'       => __( 'Advanced Coupons', 'advanced-coupons-for-woocommerce-free' ),
                    'description' => __( 'Abilities for the Advanced Coupons plugin family.', 'advanced-coupons-for-woocommerce-free' ),
                )
            );
        }
    }

    /**
     * Register all abilities.
     *
     * @since 4.7.4
     * @access public
     */
    public function register_abilities() {
        wp_register_ability(
            'advanced-coupons/list-coupons',
            array(
                'label'               => __( 'List coupons', 'advanced-coupons-for-woocommerce-free' ),
                'description'         => __( 'Retrieve a filtered list of WooCommerce coupons with their core details.', 'advanced-coupons-for-woocommerce-free' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'type'            => array(
                            'type' => 'string',
                            'enum' => array( 'discount', 'bogo', 'free_shipping' ),
                        ),
                        'status'          => array( 'type' => 'string' ),
                        'min_usage_count' => array( 'type' => 'integer' ),
                        'expires_before'  => array( 'type' => 'string' ),
                        'expires_after'   => array( 'type' => 'string' ),
                        'limit'           => array( 'type' => 'integer' ),
                        'page'            => array( 'type' => 'integer' ),
                    ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'list_coupons' ),
                'permission_callback' => array( $this, 'can_read' ),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'   => true,
                        'idempotent' => true,
                    ),
                    'show_in_rest' => true,
                ),
            )
        );

        wp_register_ability(
            'advanced-coupons/get-coupon',
            array(
                'label'               => __( 'Get coupon', 'advanced-coupons-for-woocommerce-free' ),
                'description'         => __( 'Retrieve the full configuration of a single coupon, including Advanced Coupons settings.', 'advanced-coupons-for-woocommerce-free' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'   => array( 'type' => 'integer' ),
                        'code' => array( 'type' => 'string' ),
                    ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'get_coupon' ),
                'permission_callback' => array( $this, 'can_read' ),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'   => true,
                        'idempotent' => true,
                    ),
                    'show_in_rest' => true,
                ),
            )
        );

        wp_register_ability(
            'advanced-coupons/create-coupon',
            array(
                'label'               => __( 'Create coupon', 'advanced-coupons-for-woocommerce-free' ),
                'description'         => __( 'Create a new WooCommerce coupon with optional Advanced Coupons configuration.', 'advanced-coupons-for-woocommerce-free' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'code'            => array( 'type' => 'string' ),
                        'discount_type'   => array( 'type' => 'string' ),
                        'amount'          => array( 'type' => 'number' ),
                        'description'     => array( 'type' => 'string' ),
                        'individual_use'  => array( 'type' => 'boolean' ),
                        'free_shipping'   => array( 'type' => 'boolean' ),
                        'usage_limit'     => array( 'type' => 'integer' ),
                        'date_expires'    => array( 'type' => 'string' ),
                        'cart_conditions' => array( 'type' => 'array' ),
                    ),
                    'required'   => array( 'code' ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'create_coupon' ),
                'permission_callback' => array( $this, 'can_manage' ),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => true,
                        'idempotent'  => false,
                    ),
                    'show_in_rest' => true,
                ),
            )
        );

        wp_register_ability(
            'advanced-coupons/update-coupon',
            array(
                'label'               => __( 'Update coupon', 'advanced-coupons-for-woocommerce-free' ),
                'description'         => __( 'Update an existing coupon. Only the fields provided are changed.', 'advanced-coupons-for-woocommerce-free' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'              => array( 'type' => 'integer' ),
                        'code'            => array( 'type' => 'string' ),
                        'discount_type'   => array( 'type' => 'string' ),
                        'amount'          => array( 'type' => 'number' ),
                        'description'     => array( 'type' => 'string' ),
                        'individual_use'  => array( 'type' => 'boolean' ),
                        'free_shipping'   => array( 'type' => 'boolean' ),
                        'usage_limit'     => array( 'type' => 'integer' ),
                        'date_expires'    => array( 'type' => 'string' ),
                        'cart_conditions' => array( 'type' => 'array' ),
                    ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'update_coupon' ),
                'permission_callback' => array( $this, 'can_manage' ),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => true,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                ),
            )
        );

        wp_register_ability(
            'advanced-coupons/delete-coupon',
            array(
                'label'               => __( 'Delete coupon', 'advanced-coupons-for-woocommerce-free' ),
                'description'         => __( 'Move a coupon to the trash.', 'advanced-coupons-for-woocommerce-free' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'   => array( 'type' => 'integer' ),
                        'code' => array( 'type' => 'string' ),
                    ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'delete_coupon' ),
                'permission_callback' => array( $this, 'can_manage' ),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => true,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                ),
            )
        );

        wp_register_ability(
            'advanced-coupons/set-coupon-url',
            array(
                'label'               => __( 'Set coupon URL trigger', 'advanced-coupons-for-woocommerce-free' ),
                'description'         => __( 'Enable or disable the URL coupon trigger and its related settings on a coupon.', 'advanced-coupons-for-woocommerce-free' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'                     => array( 'type' => 'integer' ),
                        'code'                   => array( 'type' => 'string' ),
                        'enable'                 => array( 'type' => 'boolean' ),
                        'code_url_override'      => array( 'type' => 'string' ),
                        'success_message'        => array( 'type' => 'string' ),
                        'after_redirect_url'     => array( 'type' => 'string' ),
                        'redirect_to_origin_url' => array( 'type' => 'string' ),
                    ),
                    'required'   => array( 'enable' ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'set_coupon_url' ),
                'permission_callback' => array( $this, 'can_manage' ),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => true,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                ),
            )
        );

        wp_register_ability(
            'advanced-coupons/set-coupon-schedule',
            array(
                'label'               => __( 'Set coupon schedule', 'advanced-coupons-for-woocommerce-free' ),
                'description'         => __( 'Enable or disable the date range schedule on a coupon and set its start and end dates.', 'advanced-coupons-for-woocommerce-free' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'     => array( 'type' => 'integer' ),
                        'code'   => array( 'type' => 'string' ),
                        'enable' => array( 'type' => 'boolean' ),
                        'start'  => array( 'type' => 'string' ),
                        'end'    => array( 'type' => 'string' ),
                    ),
                    'required'   => array( 'enable' ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'set_coupon_schedule' ),
                'permission_callback' => array( $this, 'can_manage' ),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => true,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                ),
            )
        );

        // get-coupons-summary exposes order revenue, so it requires manage_woocommerce
        // (tighter than the issue's suggested 'read') to match the existing reports endpoint.
        wp_register_ability(
            'advanced-coupons/get-coupons-summary',
            array(
                'label'               => __( 'Get coupons summary', 'advanced-coupons-for-woocommerce-free' ),
                'description'         => __( 'Retrieve coupon usage and discount summary metrics for a date range.', 'advanced-coupons-for-woocommerce-free' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'date_after'  => array( 'type' => 'string' ),
                        'date_before' => array( 'type' => 'string' ),
                    ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'get_coupons_summary' ),
                'permission_callback' => array( $this, 'can_manage' ),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'   => true,
                        'idempotent' => true,
                    ),
                    'show_in_rest' => true,
                ),
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Permission callbacks
    |--------------------------------------------------------------------------
     */

    /**
     * Permission callback for read-only abilities.
     *
     * @since 4.7.4
     * @access public
     *
     * @return bool True if the current user can manage WooCommerce.
     */
    public function can_read() {
        // Coupon data (codes, amounts, usage limits, schedules) is store-admin sensitive,
        // so even read abilities require manage_woocommerce rather than the basic read cap.
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Permission callback for management abilities.
     *
     * @since 4.7.4
     * @access public
     *
     * @return bool True if the current user can manage WooCommerce.
     */
    public function can_manage() {
        return current_user_can( 'manage_woocommerce' );
    }

    /*
    |--------------------------------------------------------------------------
    | Execute callbacks
    |--------------------------------------------------------------------------
     */

    /**
     * List coupons matching the provided filters.
     *
     * @since 4.7.4
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Coupon list result or error.
     */
    public function list_coupons( $input ) {
        $status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish';
        $limit  = isset( $input['limit'] ) ? min( 100, max( 1, absint( $input['limit'] ) ) ) : 50;
        $page   = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;

        // Build the filters at the query level so pagination and the reported
        // total operate on the filtered set (not just the current page).
        $meta_query = array();

        if ( isset( $input['type'] ) ) {
            switch ( $input['type'] ) {
                case 'bogo':
                    $meta_query[] = array(
                        'key'   => 'discount_type',
                        'value' => 'acfw_bogo',
                    );
                    break;
                case 'free_shipping':
                    $meta_query[] = array(
                        'key'     => 'discount_type',
                        'value'   => 'acfw_bogo',
                        'compare' => '!=',
                    );
                    $meta_query[] = array(
                        'key'   => 'free_shipping',
                        'value' => 'yes',
                    );
                    break;
                case 'discount':
                    $meta_query[] = array(
                        'key'     => 'discount_type',
                        'value'   => 'acfw_bogo',
                        'compare' => '!=',
                    );
                    $meta_query[] = array(
                        'key'     => 'free_shipping',
                        'value'   => 'yes',
                        'compare' => '!=',
                    );
                    break;
            }
        }

        if ( isset( $input['min_usage_count'] ) ) {
            $meta_query[] = array(
                'key'     => 'usage_count',
                'value'   => absint( $input['min_usage_count'] ),
                'compare' => '>=',
                'type'    => 'NUMERIC',
            );
        }

        if ( ! empty( $input['expires_before'] ) ) {
            $meta_query[] = array(
                'key'     => 'date_expires',
                'value'   => array( 1, (int) strtotime( $input['expires_before'] ) ),
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            );
        }

        if ( ! empty( $input['expires_after'] ) ) {
            $meta_query[] = array(
                'key'     => 'date_expires',
                'value'   => (int) strtotime( $input['expires_after'] ),
                'compare' => '>',
                'type'    => 'NUMERIC',
            );
        }

        $args = array(
            'post_type'      => 'shop_coupon',
            'post_status'    => $status,
            'posts_per_page' => $limit,
            'paged'          => $page,
            'fields'         => 'ids',
        );

        if ( $meta_query ) {
            $args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        }

        $query   = new \WP_Query( $args );
        $coupons = array();

        foreach ( $query->posts as $coupon_id ) {
            $coupon = new \WC_Coupon( $coupon_id );

            /*
             * Read in the 'edit' context. This reports the coupon's stored configuration, while
             * 'view' reports the scheduler's effective expiry date for a scheduled coupon.
             * See Scheduler::maybe_override_date_expires().
             */
            $date_expires = $coupon->get_date_expires( 'edit' );

            $coupons[] = array(
                'id'            => $coupon->get_id(),
                'code'          => $coupon->get_code(),
                'discount_type' => $coupon->get_discount_type(),
                'amount'        => (float) $coupon->get_amount(),
                'status'        => get_post_status( $coupon->get_id() ),
                'usage_count'   => $coupon->get_usage_count(),
                'usage_limit'   => $coupon->get_usage_limit(),
                'date_expires'  => $date_expires ? $date_expires->date( 'c' ) : null,
                'type'          => $this->_resolve_coupon_type( $coupon ),
            );
        }

        return array(
            'coupons' => $coupons,
            'total'   => (int) $query->found_posts,
            'count'   => count( $coupons ),
            'page'    => $page,
            'limit'   => $limit,
        );
    }

    /**
     * Get a single coupon's full configuration.
     *
     * @since 4.7.4
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Coupon detail or error.
     */
    public function get_coupon( $input ) {
        $coupon = $this->_load_coupon( $input );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }

        // 'edit' context, for the same reason as list_coupons() above.
        $date_expires = $coupon->get_date_expires( 'edit' );

        return array(
            'id'                         => $coupon->get_id(),
            'code'                       => $coupon->get_code(),
            'discount_type'              => $coupon->get_discount_type(),
            'amount'                     => (float) $coupon->get_amount(),
            'description'                => $coupon->get_description(),
            'status'                     => get_post_status( $coupon->get_id() ),
            'usage_count'                => $coupon->get_usage_count(),
            'usage_limit'                => $coupon->get_usage_limit(),
            'individual_use'             => $coupon->get_individual_use(),
            'free_shipping'              => $coupon->get_free_shipping(),
            'date_expires'               => $date_expires ? $date_expires->date( 'c' ) : null,
            'product_ids'                => $coupon->get_product_ids(),
            'excluded_product_ids'       => $coupon->get_excluded_product_ids(),
            'minimum_amount'             => $coupon->get_minimum_amount(),
            'maximum_amount'             => $coupon->get_maximum_amount(),
            'cart_conditions'            => $coupon->get_advanced_prop_edit( 'cart_conditions', array() ),
            'bogo_deals'                 => $coupon->get_advanced_prop_edit( 'bogo_deals', array() ),
            'enable_role_restriction'    => $coupon->get_advanced_prop_edit( 'enable_role_restriction' ),
            'role_restrictions'          => $coupon->get_advanced_prop_edit( 'role_restrictions', array() ),
            'role_restrictions_type'     => $coupon->get_advanced_prop_edit( 'role_restrictions_type' ),
            'url_coupon_enabled'         => 'yes' !== $coupon->get_advanced_prop_edit( 'disable_url_coupon' ),
            'code_url_override'          => $coupon->get_advanced_prop_edit( 'code_url_override' ),
            'success_message'            => $coupon->get_advanced_prop_edit( 'success_message' ),
            'after_redirect_url'         => $coupon->get_advanced_prop_edit( 'after_redirect_url' ),
            'redirect_to_origin_url'     => $coupon->get_advanced_prop_edit( 'redirect_to_origin_url' ),
            'enable_date_range_schedule' => $coupon->get_advanced_prop_edit( 'enable_date_range_schedule' ),
            'schedule_start'             => $coupon->get_advanced_prop_edit( 'schedule_start' ),
            'schedule_end'               => $coupon->get_advanced_prop_edit( 'schedule_end' ),
        );
    }

    /**
     * Create a new coupon.
     *
     * @since 4.7.4
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Created coupon result or error.
     */
    public function create_coupon( $input ) {
        $code = isset( $input['code'] ) ? wc_format_coupon_code( $input['code'] ) : '';
        if ( ! $code ) {
            return new \WP_Error( 'acfw_invalid_input', __( 'A coupon code is required.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        if ( wc_get_coupon_id_by_code( $code ) ) {
            return new \WP_Error( 'acfw_coupon_exists', __( 'A coupon with this code already exists.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        try {
            $coupon = new Advanced_Coupon( new \WC_Coupon() );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'acfw_coupon_error', esc_html( $e->getMessage() ) );
        }

        $coupon->set_code( $code );
        $coupon->set_discount_type( isset( $input['discount_type'] ) ? sanitize_text_field( $input['discount_type'] ) : 'percent' );
        $this->_apply_core_props( $coupon, $input );

        return $this->_save_coupon( $coupon, $input );
    }

    /**
     * Update an existing coupon.
     *
     * @since 4.7.4
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Updated coupon result or error.
     */
    public function update_coupon( $input ) {
        $coupon = $this->_load_coupon( $input );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }

        // Apply a rename when a new code is supplied alongside an id lookup.
        if ( ! empty( $input['id'] ) && isset( $input['code'] ) ) {
            $new_code = wc_format_coupon_code( $input['code'] );
            $existing = $new_code ? wc_get_coupon_id_by_code( $new_code ) : 0;
            if ( $existing && $existing !== $coupon->get_id() ) {
                return new \WP_Error( 'acfw_coupon_exists', __( 'Another coupon already uses that code.', 'advanced-coupons-for-woocommerce-free' ) );
            }
            $coupon->set_code( $new_code );
        }

        if ( isset( $input['discount_type'] ) ) {
            $coupon->set_discount_type( sanitize_text_field( $input['discount_type'] ) );
        }

        $this->_apply_core_props( $coupon, $input );

        return $this->_save_coupon( $coupon, $input );
    }

    /**
     * Trash a coupon.
     *
     * @since 4.7.4
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Result or error.
     */
    public function delete_coupon( $input ) {
        $coupon = $this->_load_coupon( $input );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }

        $coupon_id = $coupon->get_id();

        // Idempotent: already trashed coupons still report success.
        if ( 'trash' !== get_post_status( $coupon_id ) ) {
            wp_trash_post( $coupon_id );
        }

        return array(
            'id'      => $coupon_id,
            'trashed' => true,
        );
    }

    /**
     * Set the URL coupon trigger settings on a coupon.
     *
     * @since 4.7.4
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Current URL trigger state or error.
     */
    public function set_coupon_url( $input ) {
        if ( ! $this->_helper_functions->is_module( Plugin_Constants::URL_COUPONS_MODULE ) ) {
            return new \WP_Error( 'acfw_module_disabled', __( 'The URL Coupons module is not enabled.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        $coupon = $this->_load_coupon( $input );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }

        $enable = ! empty( $input['enable'] );

        $this->_save_with_hooks(
            $coupon,
            function ( $coupon ) use ( $input, $enable ) {
                $coupon->set_advanced_prop( 'disable_url_coupon', $enable ? '' : 'yes' );

                if ( ! $enable ) {
                    return;
                }

                if ( isset( $input['code_url_override'] ) ) {
                    $coupon->set_advanced_prop( 'code_url_override', sanitize_title( $input['code_url_override'] ) );
                }
                if ( isset( $input['success_message'] ) ) {
                    $coupon->set_advanced_prop( 'success_message', wp_kses_post( $input['success_message'] ) );
                }
                if ( isset( $input['after_redirect_url'] ) ) {
                    $coupon->set_advanced_prop( 'after_redirect_url', esc_url_raw( $input['after_redirect_url'] ) );
                }
                if ( isset( $input['redirect_to_origin_url'] ) ) {
                    $coupon->set_advanced_prop( 'redirect_to_origin_url', sanitize_text_field( $input['redirect_to_origin_url'] ) );
                }
            }
        );

        return array(
            'id'                     => $coupon->get_id(),
            'url_coupon_enabled'     => 'yes' !== $coupon->get_advanced_prop_edit( 'disable_url_coupon' ),
            'code_url_override'      => $coupon->get_advanced_prop_edit( 'code_url_override' ),
            'success_message'        => $coupon->get_advanced_prop_edit( 'success_message' ),
            'after_redirect_url'     => $coupon->get_advanced_prop_edit( 'after_redirect_url' ),
            'redirect_to_origin_url' => $coupon->get_advanced_prop_edit( 'redirect_to_origin_url' ),
        );
    }

    /**
     * Set the date range schedule settings on a coupon.
     *
     * @since 4.7.4
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Current schedule state or error.
     */
    public function set_coupon_schedule( $input ) {
        if ( ! $this->_helper_functions->is_module( Plugin_Constants::SCHEDULER_MODULE ) ) {
            return new \WP_Error( 'acfw_module_disabled', __( 'The Scheduler module is not enabled.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        $coupon = $this->_load_coupon( $input );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }

        $enable = ! empty( $input['enable'] );

        $this->_save_with_hooks(
            $coupon,
            function ( $coupon ) use ( $input, $enable ) {
                $coupon->set_advanced_prop( 'enable_date_range_schedule', $enable ? 'yes' : 'no' );

                if ( ! $enable ) {
                    return;
                }

                $schedule_start  = isset( $input['start'] ) ? sanitize_text_field( $input['start'] ) : '';
                $schedule_expire = isset( $input['end'] ) ? sanitize_text_field( $input['end'] ) : '';

                $coupon->set_advanced_prop( 'schedule_start', $schedule_start );
                $coupon->set_advanced_prop( 'schedule_end', $schedule_expire );

                if ( $schedule_expire ) {
                    $timezone = new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() );
                    $datetime = \DateTime::createFromFormat( 'Y-m-d H:i:s', $schedule_expire, $timezone );

                    if ( $datetime instanceof \DateTime ) {
                        $coupon->set_date_expires( $datetime->getTimestamp() );
                    }
                } else {
                    $coupon->set_date_expires( '' );
                }
            }
        );

        return array(
            'id'                         => $coupon->get_id(),
            'enable_date_range_schedule' => $coupon->get_advanced_prop_edit( 'enable_date_range_schedule' ),
            'schedule_start'             => $coupon->get_advanced_prop_edit( 'schedule_start' ),
            'schedule_end'               => $coupon->get_advanced_prop_edit( 'schedule_end' ),
        );
    }

    /**
     * Get coupon usage and discount summary metrics for a date range.
     *
     * @since 4.7.4
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Summary metrics or error.
     */
    public function get_coupons_summary( $input ) {
        $date_after  = isset( $input['date_after'] ) ? sanitize_text_field( $input['date_after'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        $date_before = isset( $input['date_before'] ) ? sanitize_text_field( $input['date_before'] ) : gmdate( 'Y-m-d' );

        $report_period = new Date_Period_Range( $date_after, $date_before );

        $top_coupons = new Top_Coupons( $report_period );
        $top         = array();
        if ( is_array( $top_coupons->raw_data ) ) {
            foreach ( $top_coupons->raw_data as $row ) {
                $top[] = array(
                    'id'             => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
                    'coupon'         => isset( $row['coupon'] ) ? $row['coupon'] : '',
                    'usage_total'    => isset( $row['usage_total'] ) ? $row['usage_total'] : 0,
                    'discount_total' => isset( $row['discount_total'] ) ? (float) $row['discount_total'] : 0.0,
                );
            }
        }

        $coupons_used      = new Coupons_Used( $report_period );
        $amount_discounted = new Amount_Discounted( $report_period );
        $orders_discounted = new Orders_Discounted( $report_period );
        $order_revenue     = new Discounted_Order_Revenue( $report_period );

        return array(
            'top_coupons'              => $top,
            'coupons_used'             => (int) $coupons_used->raw_data,
            'amount_discounted'        => (float) $amount_discounted->raw_data,
            'orders_discounted'        => (int) $orders_discounted->raw_data,
            'discounted_order_revenue' => (float) $order_revenue->raw_data,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Utility methods
    |--------------------------------------------------------------------------
     */

    /**
     * Resolve the Advanced Coupons type for a coupon.
     *
     * @since 4.7.4
     * @access private
     *
     * @param \WC_Coupon $coupon WC_Coupon object.
     * @return string Coupon type: bogo, free_shipping or discount.
     */
    private function _resolve_coupon_type( $coupon ) {
        // Discount type is the more meaningful classifier, so check BOGO first;
        // a BOGO coupon that also grants free shipping is still reported as bogo.
        if ( 'acfw_bogo' === $coupon->get_discount_type() ) {
            return 'bogo';
        }

        if ( $coupon->get_free_shipping() ) {
            return 'free_shipping';
        }

        return 'discount';
    }

    /**
     * Load an Advanced_Coupon from the input id or code.
     *
     * @since 4.7.4
     * @access private
     *
     * @param array $input Input arguments.
     * @return Advanced_Coupon|\WP_Error Coupon object or error.
     */
    private function _load_coupon( $input ) {
        $coupon_id = 0;

        if ( ! empty( $input['id'] ) ) {
            $coupon_id = absint( $input['id'] );
        } elseif ( ! empty( $input['code'] ) ) {
            $coupon_id = wc_get_coupon_id_by_code( wc_format_coupon_code( $input['code'] ) );
        } else {
            return new \WP_Error( 'acfw_invalid_input', __( 'A coupon id or code is required.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        if ( ! $coupon_id || 'shop_coupon' !== get_post_type( $coupon_id ) ) {
            return new \WP_Error( 'not_found', __( 'Coupon not found.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        // Object-level authorization guard (defense-in-depth alongside the permission callback).
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new \WP_Error( 'acfw_unauthorized', __( 'You do not have permission to access this coupon.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        try {
            return new Advanced_Coupon( $coupon_id );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'acfw_coupon_error', esc_html( $e->getMessage() ) );
        }
    }

    /**
     * Apply the core WooCommerce props present in the input to a coupon.
     *
     * @since 4.7.4
     * @access private
     *
     * @param Advanced_Coupon $coupon Coupon object.
     * @param array           $input  Input arguments.
     */
    private function _apply_core_props( $coupon, $input ) {
        if ( isset( $input['amount'] ) ) {
            $coupon->set_amount( wc_format_decimal( $input['amount'] ) );
        }
        if ( isset( $input['description'] ) ) {
            $coupon->set_description( sanitize_text_field( $input['description'] ) );
        }
        if ( isset( $input['individual_use'] ) ) {
            $coupon->set_individual_use( (bool) $input['individual_use'] );
        }
        if ( isset( $input['free_shipping'] ) ) {
            $coupon->set_free_shipping( (bool) $input['free_shipping'] );
        }
        if ( isset( $input['usage_limit'] ) ) {
            $coupon->set_usage_limit( absint( $input['usage_limit'] ) );
        }
        if ( isset( $input['date_expires'] ) ) {
            $coupon->set_date_expires( $input['date_expires'] ? strtotime( $input['date_expires'] ) : '' );
        }
    }

    /**
     * Run a coupon write inside the standard ACFW save-hook sequence.
     *
     * Wraps the prop mutations (supplied via $set_props) in the same
     * acfw_before_save_coupon / acfw_save_coupon / acfw_after_save_coupon
     * sequence the admin save path uses, so every write path fires identical
     * hooks. Core is saved before advanced_save() so a newly created coupon
     * has an ID before its post meta is written.
     *
     * @since 4.7.4
     * @access private
     *
     * @param Advanced_Coupon $coupon    Coupon object.
     * @param callable        $set_props Callback that receives the coupon and sets its props.
     */
    private function _save_with_hooks( $coupon, $set_props ) {
        do_action( 'acfw_before_save_coupon', $coupon->get_id(), $coupon );

        $set_props( $coupon );

        do_action( 'acfw_save_coupon', $coupon->get_id(), $coupon );

        $coupon->save();
        $coupon->advanced_save();

        do_action( 'acfw_after_save_coupon', $coupon->get_id(), $coupon );
    }

    /**
     * Save a coupon's core and advanced data via the shared hook sequence.
     *
     * @since 4.7.4
     * @access private
     *
     * @param Advanced_Coupon $coupon Coupon object.
     * @param array           $input  Input arguments.
     * @return array Saved coupon result.
     */
    private function _save_coupon( $coupon, $input ) {
        $this->_save_with_hooks(
            $coupon,
            function ( $coupon ) use ( $input ) {
                if ( isset( $input['cart_conditions'] ) && is_array( $input['cart_conditions'] ) ) {
                    // Run the same sanitizer the admin save path uses before persisting.
                    $coupon->set_advanced_prop( 'cart_conditions', \ACFWF()->Cart_Conditions->sanitize_cart_conditions( $input['cart_conditions'] ) );
                }
            }
        );

        return array(
            'id'   => $coupon->get_id(),
            'code' => $coupon->get_code(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Abilities class.
     *
     * @since 4.7.4
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        // Escape hatch: allow disabling via constant or filter.
        if ( defined( 'ACFWF_DISABLE_ABILITIES' ) && ACFWF_DISABLE_ABILITIES ) {
            return;
        }

        if ( apply_filters( 'acfwf_disable_abilities', false ) ) {
            return;
        }

        // Abilities API is not available, do nothing.
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
        add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
    }
}
