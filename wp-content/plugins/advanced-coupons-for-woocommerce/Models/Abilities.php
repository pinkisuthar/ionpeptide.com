<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;
use ACFWF\Models\Objects\Store_Credit_Entry;
use ACFWF\Models\Store_Credits\Queries as Store_Credit_Queries;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that registers the plugin's WordPress Abilities API abilities.
 * Public Model.
 *
 * Mirrors the free plugin's ACFWF\Models\Abilities pattern. Every ability calls
 * into the existing service/helper layer the admin UI uses so the same WP/Woo
 * actions fire and the same emails are sent — there is no parallel "via-AI" path.
 *
 * @since 4.0.8
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
     * @since 4.0.8
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
     * Guarded by wp_has_ability_category() because a sibling Advanced Coupons
     * plugin (e.g. the free plugin) may have registered it first.
     *
     * @since 4.0.8
     * @access public
     */
    public function register_category() {
        if ( ! wp_has_ability_category( 'advanced-coupons' ) ) {
            wp_register_ability_category(
                'advanced-coupons',
                array(
                    'label'       => __( 'Advanced Coupons', 'advanced-coupons-for-woocommerce' ),
                    'description' => __( 'Abilities for the Advanced Coupons plugin family.', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }
    }

    /**
     * Register all abilities.
     *
     * @since 4.0.8
     * @access public
     */
    public function register_abilities() {
        wp_register_ability(
            'advanced-coupons/create-cashback-coupon',
            array(
                'label'               => __( 'Create cashback coupon', 'advanced-coupons-for-woocommerce' ),
                'description'         => __( 'Create a cashback coupon (percentage or fixed) that awards store credit to the customer.', 'advanced-coupons-for-woocommerce' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'code'                    => array( 'type' => 'string' ),
                        'cashback_type'           => array(
                            'type' => 'string',
                            'enum' => array( 'percentage', 'fixed' ),
                        ),
                        'amount'                  => array( 'type' => 'number' ),
                        'cashback_waiting_period' => array( 'type' => 'integer' ),
                        'description'             => array( 'type' => 'string' ),
                        'individual_use'          => array( 'type' => 'boolean' ),
                        'usage_limit'             => array( 'type' => 'integer' ),
                        'date_expires'            => array( 'type' => 'string' ),
                    ),
                    'required'   => array( 'code', 'amount' ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'create_cashback_coupon' ),
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
            'advanced-coupons/create-add-products-coupon',
            array(
                'label'               => __( 'Create add products coupon', 'advanced-coupons-for-woocommerce' ),
                'description'         => __( 'Create a coupon that adds specific products to the cart when applied.', 'advanced-coupons-for-woocommerce' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'code'                  => array( 'type' => 'string' ),
                        'discount_type'         => array( 'type' => 'string' ),
                        'amount'                => array( 'type' => 'number' ),
                        'products'              => array( 'type' => 'array' ),
                        'add_before_conditions' => array( 'type' => 'boolean' ),
                        'description'           => array( 'type' => 'string' ),
                        'individual_use'        => array( 'type' => 'boolean' ),
                        'usage_limit'           => array( 'type' => 'integer' ),
                        'date_expires'          => array( 'type' => 'string' ),
                    ),
                    'required'   => array( 'code', 'products' ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'create_add_products_coupon' ),
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
            'advanced-coupons/set-shipping-override',
            array(
                'label'               => __( 'Set shipping override', 'advanced-coupons-for-woocommerce' ),
                'description'         => __( 'Configure shipping-method overrides on a coupon.', 'advanced-coupons-for-woocommerce' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'        => array( 'type' => 'integer' ),
                        'code'      => array( 'type' => 'string' ),
                        'enable'    => array( 'type' => 'boolean' ),
                        'overrides' => array( 'type' => 'array' ),
                    ),
                    'required'   => array( 'enable' ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'set_shipping_override' ),
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
            'advanced-coupons/set-recurring-schedule',
            array(
                'label'               => __( 'Set recurring schedule', 'advanced-coupons-for-woocommerce' ),
                'description'         => __( 'Configure a recurring day/time schedule on a coupon (e.g. active Fridays 9-5).', 'advanced-coupons-for-woocommerce' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'            => array( 'type' => 'integer' ),
                        'code'          => array( 'type' => 'string' ),
                        'enable'        => array( 'type' => 'boolean' ),
                        'schedules'     => array( 'type' => 'object' ),
                        'error_message' => array( 'type' => 'string' ),
                    ),
                    'required'   => array( 'enable' ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'set_recurring_schedule' ),
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
            'advanced-coupons/set-auto-apply',
            array(
                'label'               => __( 'Set auto apply', 'advanced-coupons-for-woocommerce' ),
                'description'         => __( 'Toggle auto-apply for a coupon and optionally set its cart conditions.', 'advanced-coupons-for-woocommerce' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'              => array( 'type' => 'integer' ),
                        'code'            => array( 'type' => 'string' ),
                        'enable'          => array( 'type' => 'boolean' ),
                        'cart_conditions' => array( 'type' => 'array' ),
                    ),
                    'required'   => array( 'enable' ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'set_auto_apply' ),
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
            'advanced-coupons/list-store-credits',
            array(
                'label'               => __( 'List store credits', 'advanced-coupons-for-woocommerce' ),
                'description'         => __( 'List customer store-credit balances.', 'advanced-coupons-for-woocommerce' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'search' => array( 'type' => 'string' ),
                        'limit'  => array( 'type' => 'integer' ),
                        'page'   => array( 'type' => 'integer' ),
                    ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'list_store_credits' ),
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
            'advanced-coupons/adjust-store-credit',
            array(
                'label'               => __( 'Adjust store credit', 'advanced-coupons-for-woocommerce' ),
                'description'         => __( 'Issue or deduct store credit for a customer with a reason.', 'advanced-coupons-for-woocommerce' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'user_id'                 => array( 'type' => 'integer' ),
                        'type'                    => array(
                            'type' => 'string',
                            'enum' => array( 'increase', 'decrease' ),
                        ),
                        'amount'                  => array( 'type' => 'number' ),
                        'note'                    => array( 'type' => 'string' ),
                        'send_email_notification' => array( 'type' => 'boolean' ),
                    ),
                    'required'   => array( 'user_id', 'type', 'amount' ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'adjust_store_credit' ),
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
            'advanced-coupons/get-store-credit-ledger',
            array(
                'label'               => __( 'Get store credit ledger', 'advanced-coupons-for-woocommerce' ),
                'description'         => __( 'Retrieve a customer\'s store-credit adjustment history.', 'advanced-coupons-for-woocommerce' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'user_id'    => array( 'type' => 'integer' ),
                        'type'       => array( 'type' => 'string' ),
                        'action'     => array( 'type' => 'string' ),
                        'start_date' => array( 'type' => 'string' ),
                        'end_date'   => array( 'type' => 'string' ),
                        'per_page'   => array( 'type' => 'integer' ),
                        'page'       => array( 'type' => 'integer' ),
                    ),
                    'required'   => array( 'user_id' ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'get_store_credit_ledger' ),
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
            'advanced-coupons/export-coupons',
            array(
                'label'               => __( 'Export coupons', 'advanced-coupons-for-woocommerce' ),
                'description'         => __( 'Export coupon configurations as structured data. By default all non-trash statuses (publish, draft, pending, private, future) are included; pass "status" to narrow the set.', 'advanced-coupons-for-woocommerce' ),
                'category'            => 'advanced-coupons',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'ids'    => array( 'type' => 'array' ),
                        'status' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type' => 'string',
                                'enum' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
                            ),
                        ),
                        'limit'  => array( 'type' => 'integer' ),
                        'page'   => array( 'type' => 'integer' ),
                    ),
                ),
                'output_schema'       => array( 'type' => 'object' ),
                'execute_callback'    => array( $this, 'export_coupons' ),
                'permission_callback' => array( $this, 'can_read' ),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'   => true,
                        'idempotent' => false,
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
     * The issue suggests the `read` capability for read abilities, but every read
     * ability here exposes store-admin sensitive data — customer balances and PII
     * (list-store-credits, get-store-credit-ledger) or full coupon configurations
     * (export-coupons). Following the issue's own "tighten if the ability touches
     * PII, money, or guest data" guidance and the merged free-plugin sibling, these
     * require manage_woocommerce.
     *
     * @since 4.0.8
     * @access public
     *
     * @return bool True if the current user can manage WooCommerce.
     */
    public function can_read() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * Permission callback for management abilities.
     *
     * @since 4.0.8
     * @access public
     *
     * @return bool True if the current user can manage WooCommerce.
     */
    public function can_manage() {
        return current_user_can( 'manage_woocommerce' );
    }

    /*
    |--------------------------------------------------------------------------
    | Execute callbacks — coupon configuration
    |--------------------------------------------------------------------------
     */

    /**
     * Create a cashback coupon.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Created coupon result or error.
     */
    public function create_cashback_coupon( $input ) {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            return new \WP_Error( 'acfw_module_disabled', __( 'The Store Credits module is not enabled.', 'advanced-coupons-for-woocommerce' ) );
        }

        $date_check = $this->_validate_date_expires( $input );
        if ( is_wp_error( $date_check ) ) {
            return $date_check;
        }

        $coupon = $this->_create_coupon_from_code( $input );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }

        $cashback_type = isset( $input['cashback_type'] ) && 'fixed' === $input['cashback_type'] ? 'acfw_fixed_cashback' : 'acfw_percentage_cashback';
        $coupon->set_discount_type( $cashback_type );

        return $this->_save_with_result(
            $coupon,
            function ( $coupon ) use ( $input ) {
                $this->_apply_core_props( $coupon, $input );
                if ( isset( $input['cashback_waiting_period'] ) ) {
                    $coupon->set_advanced_prop( 'cashback_waiting_period', absint( $input['cashback_waiting_period'] ) );
                }
            }
        );
    }

    /**
     * Create a coupon that adds specific products to the cart on apply.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Created coupon result or error.
     */
    public function create_add_products_coupon( $input ) {
        if ( empty( $input['products'] ) || ! is_array( $input['products'] ) ) {
            return new \WP_Error( 'acfw_invalid_input', __( 'A non-empty products list is required.', 'advanced-coupons-for-woocommerce' ) );
        }

        $date_check = $this->_validate_date_expires( $input );
        if ( is_wp_error( $date_check ) ) {
            return $date_check;
        }

        $coupon = $this->_create_coupon_from_code( $input );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }

        $coupon->set_discount_type( isset( $input['discount_type'] ) ? sanitize_text_field( $input['discount_type'] ) : 'percent' );

        return $this->_save_with_result(
            $coupon,
            function ( $coupon ) use ( $input ) {
                $this->_apply_core_props( $coupon, $input );
                $coupon->set_advanced_prop( 'enable_add_products', 'yes' );
                $coupon->set_advanced_prop( 'add_products_data', $this->_sanitize_products_data( $input['products'] ) );
                $coupon->set_advanced_prop( 'add_before_conditions', ! empty( $input['add_before_conditions'] ) );
            }
        );
    }

    /**
     * Set shipping-method overrides on a coupon.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Current shipping override state or error.
     */
    public function set_shipping_override( $input ) {
        $coupon = $this->_load_coupon( $input );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }

        $enable = ! empty( $input['enable'] );

        $result = $this->_save_with_result(
            $coupon,
            function ( $coupon ) use ( $input, $enable ) {
                $coupon->set_advanced_prop( 'enable_shipping_overrides', $enable ? 'yes' : '' );

                if ( $enable && isset( $input['overrides'] ) && is_array( $input['overrides'] ) ) {
                    $coupon->set_advanced_prop( 'shipping_overrides', $this->_sanitize_shipping_overrides( $input['overrides'] ) );
                }
            }
        );

        if ( ! is_wp_error( $result ) ) {
            $result['shipping_override_enabled'] = $enable;
        }

        return $result;
    }

    /**
     * Set a recurring day/time schedule on a coupon.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Current schedule state or error.
     */
    public function set_recurring_schedule( $input ) {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::SCHEDULER_MODULE ) ) {
            return new \WP_Error( 'acfw_module_disabled', __( 'The Scheduler module is not enabled.', 'advanced-coupons-for-woocommerce' ) );
        }

        $coupon = $this->_load_coupon( $input );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }

        $enable = ! empty( $input['enable'] );

        $result = $this->_save_with_result(
            $coupon,
            function ( $coupon ) use ( $input, $enable ) {
                $coupon->set_advanced_prop( 'enable_day_time_schedules', $enable ? 'yes' : '' );

                if ( $enable && isset( $input['schedules'] ) && is_array( $input['schedules'] ) ) {
                    $coupon->set_advanced_prop( 'day_time_schedules', $this->_sanitize_day_time_schedules( $input['schedules'] ) );
                }

                if ( isset( $input['error_message'] ) ) {
                    $coupon->set_advanced_prop( 'day_time_schedule_error_msg', sanitize_text_field( $input['error_message'] ) );
                }
            }
        );

        if ( ! is_wp_error( $result ) ) {
            $result['recurring_schedule_enabled'] = $enable;
        }

        return $result;
    }

    /**
     * Toggle auto-apply on a coupon and optionally set its cart conditions.
     *
     * Sets the `auto_apply_coupon` advanced prop, which the Advanced_Coupon object
     * syncs to the `acfw_auto_apply_coupons` option cache on save — exactly the
     * path the coupon editor uses (see Edit_Coupon::save_url_coupons_data).
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Current auto-apply state or error.
     */
    public function set_auto_apply( $input ) {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::AUTO_APPLY_MODULE ) ) {
            return new \WP_Error( 'acfw_module_disabled', __( 'The Auto Apply module is not enabled.', 'advanced-coupons-for-woocommerce' ) );
        }

        $coupon = $this->_load_coupon( $input );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }

        $enable = ! empty( $input['enable'] );

        $result = $this->_save_with_result(
            $coupon,
            function ( $coupon ) use ( $input, $enable ) {
                $coupon->set_advanced_prop( 'auto_apply_coupon', $enable );

                if ( isset( $input['cart_conditions'] ) && is_array( $input['cart_conditions'] ) ) {
                    // Run the same sanitizer the admin save path uses before persisting.
                    $coupon->set_advanced_prop( 'cart_conditions', \ACFWF()->Cart_Conditions->sanitize_cart_conditions( $input['cart_conditions'] ) );
                }
            }
        );

        if ( ! is_wp_error( $result ) ) {
            $result['auto_apply'] = $enable;
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Execute callbacks — store credit
    |--------------------------------------------------------------------------
     */

    /**
     * List customer store-credit balances.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Customer balances or error.
     */
    public function list_store_credits( $input ) {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            return new \WP_Error( 'acfw_module_disabled', __( 'The Store Credits module is not enabled.', 'advanced-coupons-for-woocommerce' ) );
        }

        $limit = isset( $input['limit'] ) ? min( 100, max( 1, absint( $input['limit'] ) ) ) : 50;
        $page  = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;

        $query_args = array(
            'meta_key' => \ACFWF\Helpers\Plugin_Constants::STORE_CREDIT_USER_BALANCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'number'   => $limit,
            'paged'    => $page,
            'fields'   => array( 'ID', 'user_email', 'display_name' ),
        );

        if ( ! empty( $input['search'] ) ) {
            $query_args['search']         = '*' . sanitize_text_field( $input['search'] ) . '*';
            $query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
        }

        $user_query = new \WP_User_Query( $query_args );
        $customers  = array();

        foreach ( (array) $user_query->get_results() as $user ) {
            $balance     = (float) \ACFWF()->Store_Credits_Calculate->get_customer_balance( absint( $user->ID ), true );
            $customers[] = array(
                'user_id' => (int) $user->ID,
                'email'   => $user->user_email,
                'name'    => $user->display_name,
                'balance' => $balance,
            );
        }

        return array(
            'customers' => $customers,
            'total'     => (int) $user_query->get_total(),
            'count'     => count( $customers ),
            'page'      => $page,
            'limit'     => $limit,
        );
    }

    /**
     * Issue or deduct store credit for a customer.
     *
     * Creates a Store_Credit_Entry and saves it, firing the same
     * acfw_create_store_credit_entry / acfw_store_credits_total_changed actions
     * the admin "Adjust store credit" form fires.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Adjustment result or error.
     */
    public function adjust_store_credit( $input ) {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            return new \WP_Error( 'acfw_module_disabled', __( 'The Store Credits module is not enabled.', 'advanced-coupons-for-woocommerce' ) );
        }

        $user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
        $type    = isset( $input['type'] ) && 'decrease' === $input['type'] ? 'decrease' : 'increase';
        $amount  = isset( $input['amount'] ) ? (float) wc_format_decimal( $input['amount'] ) : 0.0;

        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return new \WP_Error( 'acfw_invalid_input', __( 'A valid user id is required.', 'advanced-coupons-for-woocommerce' ) );
        }

        if ( $amount <= 0 ) {
            return new \WP_Error( 'acfw_invalid_input', __( 'The adjustment amount must be greater than zero.', 'advanced-coupons-for-woocommerce' ) );
        }

        $entry = new Store_Credit_Entry();
        $entry->set_prop( 'user_id', $user_id );
        $entry->set_prop( 'type', $type );
        $entry->set_prop( 'action', 'increase' === $type ? 'admin_increase' : 'admin_decrease' );
        $entry->set_prop( 'amount', $amount );
        $entry->set_prop( 'object_id', get_current_user_id() );
        $entry->set_prop( 'note', isset( $input['note'] ) ? sanitize_text_field( $input['note'] ) : '' );

        $check = $entry->save();
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $balance = (float) \ACFWF()->Store_Credits_Calculate->get_customer_balance( $user_id, true );

        // Send the same customer email the admin UI sends when notification is requested.
        if ( ! empty( $input['send_email_notification'] ) ) {
            $customer = $entry->get_customer();
            if ( $customer && $customer->get_email() ) {
                do_action( 'acfwf_send_store_credit_adjustment_email', $entry, $customer );
            }
        }

        return array(
            'user_id' => $user_id,
            'type'    => $type,
            'amount'  => $amount,
            'balance' => $balance,
        );
    }

    /**
     * Get a customer's store-credit ledger (adjustment history).
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Ledger entries or error.
     */
    public function get_store_credit_ledger( $input ) {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            return new \WP_Error( 'acfw_module_disabled', __( 'The Store Credits module is not enabled.', 'advanced-coupons-for-woocommerce' ) );
        }

        $user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return new \WP_Error( 'acfw_invalid_input', __( 'A valid user id is required.', 'advanced-coupons-for-woocommerce' ) );
        }

        $per_page = isset( $input['per_page'] ) ? min( 100, max( 1, absint( $input['per_page'] ) ) ) : 20;
        $page     = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;

        $params = array(
            'user_id'     => $user_id,
            'page'        => $page,
            'per_page'    => $per_page,
            'is_admin'    => true,
            'type'        => isset( $input['type'] ) ? sanitize_text_field( $input['type'] ) : '',
            'action'      => isset( $input['action'] ) ? sanitize_text_field( $input['action'] ) : '',
            'startPeriod' => isset( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : '',
            'endPeriod'   => isset( $input['end_date'] ) ? sanitize_text_field( $input['end_date'] ) : '',
        );

        $queries = Store_Credit_Queries::get_instance( \ACFWF()->Plugin_Constants, \ACFWF()->Helper_Functions );
        $entries = $queries->query_store_credit_entries( $params );
        $total   = $queries->query_store_credit_entries( $params, true );

        if ( is_wp_error( $entries ) ) {
            return $entries;
        }

        $rows = array();
        foreach ( (array) $entries as $row ) {
            $rows[] = array(
                'id'     => isset( $row->entry_id ) ? (int) $row->entry_id : 0,
                'type'   => isset( $row->entry_type ) ? $row->entry_type : '',
                'action' => isset( $row->entry_action ) ? $row->entry_action : '',
                'amount' => isset( $row->entry_amount ) ? (float) $row->entry_amount : 0.0,
                'date'   => isset( $row->entry_date ) ? $row->entry_date : '',
                'note'   => isset( $row->entry_note ) ? $row->entry_note : '',
            );
        }

        return array(
            'user_id' => $user_id,
            'entries' => $rows,
            'total'   => is_wp_error( $total ) ? count( $rows ) : (int) $total,
            'count'   => count( $rows ),
            'page'    => $page,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Execute callbacks — export
    |--------------------------------------------------------------------------
     */

    /**
     * Export coupon configurations.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $input Input arguments.
     * @return array|\WP_Error Exported coupon configs or error.
     */
    public function export_coupons( $input ) {
        $limit = isset( $input['limit'] ) ? min( 100, max( 1, absint( $input['limit'] ) ) ) : 50;
        $page  = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;

        // Default to every non-trash status so "export all coupons" is complete; a caller may
        // narrow this via the status input. Anything not in the allow-list (e.g. trash) is dropped.
        $allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
        $status           = $allowed_statuses;
        if ( ! empty( $input['status'] ) ) {
            $requested = array_intersect( array_map( 'sanitize_key', (array) $input['status'] ), $allowed_statuses );
            if ( ! empty( $requested ) ) {
                $status = array_values( $requested );
            }
        }

        $query_args = array(
            'post_type'      => 'shop_coupon',
            'post_status'    => $status,
            'posts_per_page' => $limit,
            'paged'          => $page,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        );

        if ( ! empty( $input['ids'] ) && is_array( $input['ids'] ) ) {
            $query_args['post__in'] = array_map( 'absint', $input['ids'] );
            $query_args['orderby']  = 'post__in';
        }

        $query   = new \WP_Query( $query_args );
        $coupons = array();

        foreach ( (array) $query->posts as $coupon_id ) {
            try {
                $coupon = new Advanced_Coupon( absint( $coupon_id ) );
            } catch ( \Exception $e ) {
                continue;
            }

            $date_expires = $coupon->get_date_expires();

            $coupons[] = array(
                'id'                      => $coupon->get_id(),
                'code'                    => $coupon->get_code(),
                'discount_type'           => $coupon->get_discount_type(),
                'amount'                  => (float) $coupon->get_amount(),
                'description'             => $coupon->get_description(),
                'individual_use'          => $coupon->get_individual_use(),
                'free_shipping'           => $coupon->get_free_shipping(),
                'usage_limit'             => $coupon->get_usage_limit(),
                'usage_count'             => $coupon->get_usage_count(),
                'date_expires'            => $date_expires ? $date_expires->date( 'c' ) : null,
                'cart_conditions'         => $coupon->get_advanced_prop( 'cart_conditions' ),
                'add_products_data'       => $coupon->get_advanced_prop( 'add_products_data' ),
                'shipping_overrides'      => $coupon->get_advanced_prop( 'shipping_overrides' ),
                'day_time_schedules'      => $coupon->get_advanced_prop( 'day_time_schedules' ),
                'cashback_waiting_period' => $coupon->get_advanced_prop( 'cashback_waiting_period' ),
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

    /*
    |--------------------------------------------------------------------------
    | Utility methods
    |--------------------------------------------------------------------------
     */

    /**
     * Create a new Advanced_Coupon from a (unique) code in the input.
     *
     * @since 4.0.8
     * @access private
     *
     * @param array $input Input arguments.
     * @return Advanced_Coupon|\WP_Error Coupon object or error.
     */
    private function _create_coupon_from_code( $input ) {
        $code = isset( $input['code'] ) ? wc_format_coupon_code( $input['code'] ) : '';
        if ( ! $code ) {
            return new \WP_Error( 'acfw_invalid_input', __( 'A coupon code is required.', 'advanced-coupons-for-woocommerce' ) );
        }

        if ( wc_get_coupon_id_by_code( $code ) ) {
            return new \WP_Error( 'acfw_coupon_exists', __( 'A coupon with this code already exists.', 'advanced-coupons-for-woocommerce' ) );
        }

        try {
            $coupon = new Advanced_Coupon( new \WC_Coupon() );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'acfw_coupon_error', esc_html( $e->getMessage() ) );
        }

        $coupon->set_code( $code );

        return $coupon;
    }

    /**
     * Load an Advanced_Coupon from the input id or code.
     *
     * @since 4.0.8
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
            return new \WP_Error( 'acfw_invalid_input', __( 'A coupon id or code is required.', 'advanced-coupons-for-woocommerce' ) );
        }

        if ( ! $coupon_id || 'shop_coupon' !== get_post_type( $coupon_id ) ) {
            return new \WP_Error( 'not_found', __( 'Coupon not found.', 'advanced-coupons-for-woocommerce' ) );
        }

        // Object-level authorization guard (defense-in-depth alongside the permission callback).
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new \WP_Error( 'acfw_unauthorized', __( 'You do not have permission to access this coupon.', 'advanced-coupons-for-woocommerce' ) );
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
     * @since 4.0.8
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
        if ( isset( $input['usage_limit'] ) ) {
            $coupon->set_usage_limit( absint( $input['usage_limit'] ) );
        }
        if ( isset( $input['date_expires'] ) ) {
            // A non-empty value is validated up front by _validate_date_expires(), so strtotime()
            // here is guaranteed to parse; an empty value intentionally clears the expiry.
            $coupon->set_date_expires( $input['date_expires'] ? strtotime( $input['date_expires'] ) : '' );
        }
    }

    /**
     * Validate an optional date_expires input value.
     *
     * A non-empty but unparseable value would otherwise be silently accepted as "no expiry"
     * (strtotime() returns false and set_date_expires( false ) clears it), so report it instead.
     *
     * @since 4.0.8
     * @access private
     *
     * @param array $input Input arguments.
     * @return true|\WP_Error True when the value is absent, empty, or parseable; WP_Error otherwise.
     */
    private function _validate_date_expires( $input ) {
        if ( ! empty( $input['date_expires'] ) && false === strtotime( $input['date_expires'] ) ) {
            return new \WP_Error( 'acfw_invalid_input', __( 'The date_expires value could not be parsed.', 'advanced-coupons-for-woocommerce' ) );
        }

        return true;
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
     * @since 4.0.8
     * @access private
     *
     * @param Advanced_Coupon $coupon    Coupon object.
     * @param callable        $set_props Callback that receives the coupon and sets its props.
     */
    private function _save_with_hooks( $coupon, $set_props ) {
        do_action( 'acfw_before_save_coupon', $coupon->get_id(), $coupon );

        $set_props( $coupon );

        /*
         * Some acfw_save_coupon listeners read $_POST directly to persist their field, on the
         * assumption the hook only fires during the admin coupon-editor form save (nonce checked
         * upstream by Edit_Coupon::save_url_coupons_data). Fired from a non-form context (Abilities
         * API), $_POST is empty and those handlers reset their advanced prop to empty — silently
         * wiping an existing coupon's setting. No ability here writes these props, so snapshot them
         * before the action and restore them afterward to prevent that data loss. Force_Apply is
         * guarded at its source; this also shields the free plugin's Wholesale_Suite handler, which
         * lives in a separate repository and can't be fixed from here.
         */
        $preserved = array(
            'force_apply_url_coupon'  => $coupon->get_advanced_prop( 'force_apply_url_coupon' ),
            'exclude_wholesale_items' => $coupon->get_advanced_prop( 'exclude_wholesale_items' ),
        );

        do_action( 'acfw_save_coupon', $coupon->get_id(), $coupon );

        foreach ( $preserved as $prop => $value ) {
            $coupon->set_advanced_prop( $prop, $value );
        }

        $coupon->save();
        $coupon->advanced_save();

        do_action( 'acfw_after_save_coupon', $coupon->get_id(), $coupon );
    }

    /**
     * Save a coupon via the shared hook sequence and return a standard result.
     *
     * @since 4.0.8
     * @access private
     *
     * @param Advanced_Coupon $coupon    Coupon object.
     * @param callable        $set_props Callback that receives the coupon and sets its props.
     * @return array Saved coupon result.
     */
    private function _save_with_result( $coupon, $set_props ) {
        $this->_save_with_hooks( $coupon, $set_props );

        return array(
            'id'   => $coupon->get_id(),
            'code' => $coupon->get_code(),
        );
    }

    /**
     * Sanitize the add-products data array.
     *
     * Mirrors the shape Add_Products::_sanitize_products_data() produces so the
     * runtime "add products" logic reads identical meta. The admin sanitizer reads
     * $_POST directly and so can't be reused here.
     *
     * KEEP IN SYNC WITH: ACFWP\Models\Add_Products::_sanitize_products_data().
     * If a key is added/renamed there, mirror it here or this ability writes stale meta.
     *
     * @since 4.0.8
     * @access private
     *
     * @param array $data Raw products data.
     * @return array Sanitized products data.
     */
    private function _sanitize_products_data( $data ) {
        $sanitized = array();

        if ( is_array( $data ) ) {
            foreach ( $data as $key => $row ) {
                if ( ! is_array( $row ) || ! isset( $row['product_id'] ) || ! isset( $row['quantity'] ) ) {
                    continue;
                }

                $sanitized[ $key ] = array(
                    'product_id'     => intval( $row['product_id'] ),
                    'quantity'       => intval( $row['quantity'] ),
                    'product_label'  => isset( $row['product_label'] ) ? sanitize_text_field( $row['product_label'] ) : '',
                    'discount_type'  => isset( $row['discount_type'] ) ? sanitize_text_field( $row['discount_type'] ) : 'override',
                    'discount_value' => isset( $row['discount_value'] ) ? (float) wc_format_decimal( $row['discount_value'] ) : 0.0,
                );
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize the shipping-overrides array.
     *
     * Mirrors the shape Shipping_Overrides::_sanitize_shipping_override() produces.
     * The admin sanitizer reads $_POST directly and so can't be reused here.
     *
     * KEEP IN SYNC WITH: ACFWP\Models\Shipping_Overrides::_sanitize_shipping_override().
     * If a key is added/renamed there, mirror it here or this ability writes stale meta.
     *
     * @since 4.0.8
     * @access private
     *
     * @param array $data Raw shipping overrides.
     * @return array Sanitized shipping overrides.
     */
    private function _sanitize_shipping_overrides( $data ) {
        $sanitized = array();

        if ( is_array( $data ) ) {
            foreach ( $data as $key => $row ) {
                if ( ! is_array( $row ) || ! isset( $row['shipping_method'] ) ) {
                    continue;
                }

                $shipping_zone     = isset( $row['shipping_zone'] ) && 'nozone' !== $row['shipping_zone'] ? absint( $row['shipping_zone'] ) : 'nozone';
                $sanitized[ $key ] = array(
                    'shipping_zone'   => $shipping_zone,
                    'shipping_method' => sanitize_text_field( $row['shipping_method'] ),
                    'discount_type'   => isset( $row['discount_type'] ) ? sanitize_text_field( $row['discount_type'] ) : 'fixed',
                    'discount_value'  => isset( $row['discount_value'] ) ? (float) wc_format_decimal( $row['discount_value'] ) : 0.0,
                );
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize the day/time schedules map.
     *
     * Mirrors the shape Scheduler::_parse_day_time_schedules_data() produces from $_POST.
     * The admin parser reads $_POST directly and so can't be reused here.
     *
     * KEEP IN SYNC WITH: ACFWP\Models\Scheduler::_parse_day_time_schedules_data().
     * If a key is added/renamed there, mirror it here or this ability writes stale meta.
     *
     * @since 4.0.8
     * @access private
     *
     * @param array $data Raw day/time schedules keyed by weekday.
     * @return array Sanitized day/time schedules.
     */
    private function _sanitize_day_time_schedules( $data ) {
        $sanitized = array();
        $days      = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

        foreach ( $days as $day ) {
            if ( ! isset( $data[ $day ] ) || ! is_array( $data[ $day ] ) ) {
                continue;
            }

            $row               = $data[ $day ];
            $sanitized[ $day ] = array(
                'is_enabled' => ! empty( $row['is_enabled'] ),
                'start_time' => isset( $row['start_time'] ) ? sanitize_text_field( $row['start_time'] ) : '',
                'end_time'   => isset( $row['end_time'] ) ? sanitize_text_field( $row['end_time'] ) : '',
            );
        }

        return $sanitized;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Abilities class.
     *
     * @since 4.0.8
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        // Escape hatch: allow disabling via constant or filter.
        if ( defined( 'ACFW_DISABLE_ABILITIES' ) && ACFW_DISABLE_ABILITIES ) {
            return;
        }

        if ( apply_filters( 'acfwp_disable_abilities', false ) ) {
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
