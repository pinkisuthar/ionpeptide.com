<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Vite_App;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Model that houses the logic of loading plugin scripts.
 * Private Model.
 *
 * @since 2.0
 */
class Script_Loader extends Base_Model implements Model_Interface {
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
    }

    /*
    |--------------------------------------------------------------------------
    | Backend
    |--------------------------------------------------------------------------
     */

    /**
     * Register backend styles.
     *
     * @since 2.0
     * @access public
     *
     * @param array $styles Styles list.
     * @return array Filtered styles list.
     */
    public function register_backend_styles( $styles ) {
        $styles['acfw-reports'] = array(
            'src'   => $this->_constants->JS_ROOT_URL . 'apps/acfw-reports/dist/acfw-reports.css',
            'deps'  => array(),
            'ver'   => $this->_constants->VERSION,
            'media' => 'all',
        );

        return $styles;
    }

    /**
     * Register backend scripts.
     *
     * @since 2.0
     * @access public
     *
     * @param array $scripts Styles list.
     * @return array Filtered styles list.
     */
    public function register_backend_scripts( $scripts ) {
        $scripts['acfw-reports'] = array(
            'src'    => $this->_constants->JS_ROOT_URL . 'apps/acfw-reports/dist/acfw-reports.js',
            'deps'   => array(),
            'ver'    => $this->_constants->VERSION,
            'footer' => true,
        );

        return $scripts;
    }

    /**
     * Load backend js and css scripts.
     *
     * @since 2.0
     * @access public
     *
     * @param WP_Screen $screen    Current screen object.
     * @param string    $post_type Current screen post type.
     */
    public function load_backend_scripts( $screen, $post_type ) {

        do_action( 'acfwp_before_load_backend_scripts', $screen, $post_type );

        /**
         * Enqueue script for edit coupon page.
         */
        if ( 'post' === $screen->base && 'shop_coupon' === $screen->id && 'shop_coupon' === $post_type ) {

            $edit_coupon_vite = new Vite_App(
                'acfwp-edit-advanced-coupon',
                'packages/acfwp-edit-advanced-coupon/index.ts',
                array( 'jquery-ui-core', 'jquery-ui-datepicker', 'jquery-ui-sortable' ),
            );
            $edit_coupon_vite->enqueue();

            $edit_coupon_app_vite = new Vite_App(
                'acfwp-edit-coupon-app',
                'packages/acfwp-edit-coupon-app/index.tsx',
                array( 'wc-admin-app', 'wp-api' ),
            );
            $edit_coupon_app_vite->enqueue();
        }

        // reports.
        if ( 'woocommerce_page_wc-reports' === $screen->base && isset( $_GET['tab'] ) && 'acfw_reports' === $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            wp_enqueue_style( 'acfw-reports' );
            wp_enqueue_script( 'acfw-reports' );
            wp_localize_script(
                'acfw-reports',
                'acfw_reports',
                apply_filters(
                    'acfw_reports_js_localize',
                    array(
                        'admin_url'          => admin_url(),
                        'i18n_no_orders_row' => __( 'No orders found', 'advanced-coupons-for-woocommerce' ),
                        'i18n_previous'      => __( '« Previous', 'advanced-coupons-for-woocommerce' ),
                        'i18n_next'          => __( 'Next »', 'advanced-coupons-for-woocommerce' ),
                    )
                )
            );

        }

        do_action( 'acfwp_after_load_backend_scripts', $screen, $post_type );
    }

    /**
     * Filter edit advanced coupon JS localized data.
     *
     * @since 2.0
     * @access public
     *
     * @param array $data Localized data.
     * @return array Filtered localized data.
     */
    public function filter_edit_advanced_coupon_localized_data( $data ) {
        $data['coupon_sort_invalid']             = __( 'Please set a valid custom sort value.', 'advanced-coupons-for-woocommerce' );
        $data['repeat_incompatible_notice']      = __( 'Repeat deals are not yet supported using this combination of Trigger and Apply types. ', 'advanced-coupons-for-woocommerce' );
        $data['condition_exists_field_option']   = array(
            'exists'   => __( 'EXISTS', 'advanced-coupons-for-woocommerce' ),
            'notexist' => __( "DOESN'T EXIST", 'advanced-coupons-for-woocommerce' ),
        );
        $data['condition_contains_field_option'] = array(
            'contains' => __( 'CONTAINS', 'advanced-coupons-for-woocommerce' ),
        );
        $data['cashback_coupon']                 = array(
            'cashback_percentage_label' => __( 'Cashback percentage', 'advanced-coupons-for-woocommerce' ),
            'cashback_amount_label'     => __( 'Cashback amount', 'advanced-coupons-for-woocommerce' ),
        );

        // BOGO deals validation error messages.
        $data['same_products_specific_products_error_msg'] = __( '"Same Products" GET type is not compatible with "Specific Products" BUY type. Please select a different combination.', 'advanced-coupons-for-woocommerce' );

        // Product discount rules panel.
        $data['discount_rules'] = array(
            'no_rules_added'       => __( 'No rules added', 'advanced-coupons-for-woocommerce' ),
            'target_products'      => __( 'Products', 'advanced-coupons-for-woocommerce' ),
            'target_categories'    => __( 'Categories', 'advanced-coupons-for-woocommerce' ),
            'search_products'      => __( 'Search for a product…', 'advanced-coupons-for-woocommerce' ),
            'search_categories'    => __( 'Search for a product category…', 'advanced-coupons-for-woocommerce' ),
            'discount_percent'     => __( 'Percent (%)', 'advanced-coupons-for-woocommerce' ),
            /* translators: %s: store currency symbol. */
            'discount_fixed'       => sprintf( __( 'Fixed (%s)', 'advanced-coupons-for-woocommerce' ), html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ),
            'remove_rule'          => __( 'Remove rule', 'advanced-coupons-for-woocommerce' ),
            'reorder_rule'         => __( 'Drag to reorder the rule', 'advanced-coupons-for-woocommerce' ),
            'move_rule_up'         => __( 'Move rule up', 'advanced-coupons-for-woocommerce' ),
            'move_rule_down'       => __( 'Move rule down', 'advanced-coupons-for-woocommerce' ),
            // accessible names of the rule row controls, which have no visible label of their own.
            'target_type_label'    => __( 'Rule target type', 'advanced-coupons-for-woocommerce' ),
            'discount_type_label'  => __( 'Rule discount type', 'advanced-coupons-for-woocommerce' ),
            'discount_value_label' => __( 'Rule discount value', 'advanced-coupons-for-woocommerce' ),
            'save_failed_message'  => __( 'Failed on saving the discount rules.', 'advanced-coupons-for-woocommerce' ),
        );

        // Tiered cart discount panel.
        $data['tier_discounts'] = array(
            'no_tiers_added'       => __( 'No tiers added', 'advanced-coupons-for-woocommerce' ),
            'discount_percent'     => __( 'Percent (%)', 'advanced-coupons-for-woocommerce' ),
            /* translators: %s: store currency symbol. */
            'discount_fixed'       => sprintf( __( 'Fixed (%s)', 'advanced-coupons-for-woocommerce' ), html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ),
            'remove_tier'          => __( 'Remove tier', 'advanced-coupons-for-woocommerce' ),
            // accessible names of the tier row controls, which have no visible label of their own.
            'min_spend_label'      => __( 'Tier spend threshold', 'advanced-coupons-for-woocommerce' ),
            'discount_value_label' => __( 'Tier discount value', 'advanced-coupons-for-woocommerce' ),
            'duplicate_threshold'  => __( 'Two tiers cannot share the same spend threshold.', 'advanced-coupons-for-woocommerce' ),
            'invalid_value'        => __( 'Every tier needs a discount value greater than zero.', 'advanced-coupons-for-woocommerce' ),
            'invalid_threshold'    => __( 'Every tier needs a spend threshold of zero or more.', 'advanced-coupons-for-woocommerce' ),
            'save_failed_message'  => __( 'Failed on saving the tier discounts.', 'advanced-coupons-for-woocommerce' ),
        );

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | Frontend
    |--------------------------------------------------------------------------
     */

    /**
     * Load frontend js and css scripts.
     *
     * @since 2.0
     * @access public
     */
    public function load_frontend_scripts() {
        global $post, $wp, $wp_query;

        // Load cart js and css.
        if ( is_cart() || is_checkout() ) {
            $cart_vite = new Vite_App(
                'acfwp-cart',
                'packages/acfwp-cart/index.ts',
                array( 'jquery', 'wc-cart' ),
            );
            $cart_vite->enqueue();
        }

        // Load order pay js for store credits feature.
        if ( is_checkout_pay_page() && \ACFWF()->Helper_Functions->is_module( \ACFWF()->Plugin_Constants::STORE_CREDITS_MODULE ) ) {
            $order_id = absint( get_query_var( 'order-pay', 0 ) );

            $order_pay_vite = new Vite_App(
                'acfwp-order-pay',
                'packages/acfwp-order-pay/index.ts',
                array( 'jquery' ),
            );
            $order_pay_vite->enqueue();

            wp_localize_script(
                'acfwp-order-pay',
                'acfwpOrderPay',
                array(
                    'ajax_url'          => admin_url( 'admin-ajax.php' ),
                    'order_id'          => $order_id,
                    'nonce'             => wp_create_nonce( 'acfwp_store_credits_order_pay' ),
                    'enter_valid_price' => __( 'Please enter a valid price', 'advanced-coupons-for-woocommerce' ),
                    'ajax_error'        => __( 'An error occurred. Please refresh the page and try again.', 'advanced-coupons-for-woocommerce' ),
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Admin App
    |--------------------------------------------------------------------------
     */

    /**
     * Enqueue admin app scripts.
     *
     * @since 2.2
     * @access public
     */
    public function enqueue_admin_app_scripts() {
        $admin_app_vite = new Vite_App(
            'acfwp_admin_app',
            'packages/acfwp-admin-app/index.tsx',
            array(),
            array( 'acfwf-admin-app' ),
        );
        $admin_app_vite->enqueue();
    }

    /**
     * Admin app localized data.
     *
     * @since 2.2
     * @access public
     *
     * @param array $data Localized data object.
     */
    public function admin_app_localized_data( $data ) {
        /**
         * START: License Page.
         */
        $data['license_page']['indicator'] = array(
            'active'   => __( 'License is Active', 'advanced-coupons-for-woocommerce' ),
            'inactive' => __( 'Not Activated Yet', 'advanced-coupons-for-woocommerce' ),
        );

        $data['license_page']['premium_content'] = array(
            'title' => __( 'Premium Version', 'advanced-coupons-for-woocommerce' ),
            'text'  => __( 'You are currently using Advanced Coupons for WooCommerce Premium version. The premium version gives you a massive range of extra extra features for your WooCommerce coupons so you can promote your store better. As the Premium version functions like an add-on, you must have Advanced Coupons for WooCommerce Free installed and activated along with WooCommerce (which is required for both).', 'advanced-coupons-for-woocommerce' ),
        );

        $data['license_page']['specs'] = array(
            array(
                'label' => __( 'Plan', 'advanced-coupons-for-woocommerce' ),
                'value' => __( 'Premium Version', 'advanced-coupons-for-woocommerce' ),
            ),
            array(
                'label' => __( 'Version', 'advanced-coupons-for-woocommerce' ),
                'value' => $this->_constants->VERSION,
            ),
        );

        $data['license_page']['formlabels'] = array(
            'license_key' => __( 'License Key:', 'advanced-coupons-for-woocommerce' ),
            'email'       => __( 'Activation Email:', 'advanced-coupons-for-woocommerce' ),
            'button'      => __( 'Activate Key', 'advanced-coupons-for-woocommerce' ),
            'help'        => array(
                'text'  => __( 'Can’t find your key?', 'advanced-coupons-for-woocommerce' ),
                'link'  => 'https://advancedcouponsplugin.com/my-account/?utm_source=acfwp&utm_medium=license&utm_campaign=findkey',
                'login' => __( 'Login to your account', 'advanced-coupons-for-woocommerce' ),
            ),
        );

        $data['license_page']['spinner_img'] = $this->_constants->IMAGES_ROOT_URL . 'spinner-2x.gif';
        $data['license_page']['_formNonce']  = wp_create_nonce( 'acfw_activate_license' );
        /**
         * END: License Page.
         */

        /**
         * START: Help Page.
         */

        $utility_cards = array();

        // rebuild/clear auto apply cache tool.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::AUTO_APPLY_MODULE ) ) {

            $utility_cards[] = array(
                'title'   => __( 'Rebuild/Clear Auto Apply Coupons Cache', 'advanced-coupons-for-woocommerce' ),
                'desc'    => __( 'Manually rebuild and validate all auto apply coupons within the cache or clear the cache entirely.', 'advanced-coupons-for-woocommerce' ),
                'id'      => 'acfw_rebuild_auto_apply_cache',
                'nonce'   => wp_create_nonce( 'acfw_rebuild_auto_apply_cache' ),
                'buttons' => array(
                    array(
                        'text'   => __( 'Rebuild cache', 'advanced-coupons-for-woocommerce' ),
                        'action' => 'rebuild',
                        'type'   => 'primary',
                    ),
                    array(
                        'text'   => __( 'Clear cache', 'advanced-coupons-for-woocommerce' ),
                        'action' => 'clear',
                        'type'   => 'ghost',
                    ),
                ),
            );
        }

        // rebuild/clear apply notification cache tool.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::APPLY_NOTIFICATION_MODULE ) ) {

            $utility_cards[] = array(
                'title'   => __( 'Rebuild/Clear Apply Notification Coupons Cache', 'advanced-coupons-for-woocommerce' ),
                'desc'    => __( 'Manually rebuild and validate all apply notification coupons within the cache or clear the cache entirely.', 'advanced-coupons-for-woocommerce' ),
                'id'      => 'acfw_rebuild_apply_notification_cache',
                'nonce'   => wp_create_nonce( 'acfw_rebuild_apply_notification_cache' ),
                'buttons' => array(
                    array(
                        'text'   => __( 'Rebuild cache', 'advanced-coupons-for-woocommerce' ),
                        'action' => 'rebuild',
                        'type'   => 'primary',
                    ),
                    array(
                        'text'   => __( 'Clear cache', 'advanced-coupons-for-woocommerce' ),
                        'action' => 'clear',
                        'type'   => 'ghost',
                    ),
                ),
            );
        }

        // trigger usage limits reset cron tool.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::USAGE_LIMITS_MODULE ) ) {

            $utility_cards[] = array(
                'title'   => __( 'Reset coupons usage limit', 'advanced-coupons-for-woocommerce' ),
                'desc'    => __( 'Manually run cron for resetting usage limit for all applicable coupons.', 'advanced-coupons-for-woocommerce' ),
                'id'      => 'acfw_reset_coupon_usage_limit',
                'nonce'   => wp_create_nonce( 'acfw_reset_coupon_usage_limit' ),
                'buttons' => array(
                    array(
                        'text'   => __( 'Trigger reset cron', 'advanced-coupons-for-woocommerce' ),
                        'action' => 'reset',
                        'type'   => 'primary',
                    ),
                ),
            );
        }

        // only display this on the main site for multi install. This will also always display for non-multi install.
        if ( is_main_site() ) {
            $utility_cards[] = array(
                'title'   => __( 'Refetch Plugin Update Data', 'advanced-coupons-for-woocommerce' ),
                'desc'    => __( 'This will refetch the plugin update data. Useful for debugging failed plugin update operations.', 'advanced-coupons-for-woocommerce' ),
                'id'      => 'acfwp_slmw_refetch_update_data',
                'nonce'   => wp_create_nonce( 'acfwp_slmw_refetch_update_data' ),
                'buttons' => array(
                    array(
                        'text'   => __( 'Refetch Update Data', 'advanced-coupons-for-woocommerce' ),
                        'action' => 'clear',
                        'type'   => 'primary',
                    ),
                ),
            );

            $utility_cards[] = array(
                'title'   => __( 'Check license status', 'advanced-coupons-for-woocommerce' ),
                'desc'    => __( 'Check the current status of your Advanced Coupons premium license.', 'advanced-coupons-for-woocommerce' ),
                'id'      => 'acfw_refresh_license_status',
                'nonce'   => wp_create_nonce( 'acfw_refresh_license_status' ),
                'buttons' => array(
                    array(
                        'text'   => __( 'Check License Status', 'advanced-coupons-for-woocommerce' ),
                        'action' => 'refresh',
                        'type'   => 'primary',
                    ),
                ),
            );
        }

        // register utility section data.
        if ( ! empty( $utility_cards ) ) {

            $data['help_page']['utilities'] = array(
                'title' => __( 'Utilities', 'advanced-coupons-for-woocommerce' ),
                'cards' => $utility_cards,
            );
        }

        /**
         * END: Help Page.
         */

        /**
         * START: Bulk Adjust tab (Store Credits page).
         *
         * @since 4.0.8
         */
        $date_from_label = __( 'From', 'advanced-coupons-for-woocommerce' );
        $date_to_label   = __( 'To', 'advanced-coupons-for-woocommerce' );

        // Limit the role picker to roles that can realistically own a store
        // credit balance. Without this filter the dropdown also lists every
        // staff role (administrator/editor/etc.), which is technically valid
        // but rarely what a shop owner means when they "bulk adjust customers".
        $default_bulk_adjust_roles = array( 'customer', 'subscriber' );
        /**
         * Filter the list of role keys offered in the Bulk Adjust role picker.
         *
         * @since 4.0.8
         *
         * @param string[] $role_keys Default role keys eligible for bulk adjustment.
         */
        $allowed_role_keys = apply_filters( 'acfwp_bulk_adjust_role_options', $default_bulk_adjust_roles );

        $wp_roles       = wp_roles();
        $role_options   = array();
        $editable_roles = $wp_roles->roles;
        if ( is_array( $editable_roles ) && is_array( $allowed_role_keys ) ) {
            foreach ( $allowed_role_keys as $role_key ) {
                $role_key = (string) $role_key;
                if ( ! isset( $editable_roles[ $role_key ] ) ) {
                    continue;
                }
                $role_data      = $editable_roles[ $role_key ];
                $role_options[] = array(
                    'value' => $role_key,
                    'label' => isset( $role_data['name'] ) ? translate_user_role( $role_data['name'] ) : $role_key,
                );
            }
        }

        $data['store_credits_page']['bulk_adjust'] = array(
            'filter_form'        => array(
                'title'              => __( 'Filter Users', 'advanced-coupons-for-woocommerce' ),
                'description'        => __( 'Set the criteria to find users whose store credit balances you want to adjust.', 'advanced-coupons-for-woocommerce' ),
                'user_roles'         => __( 'User Roles', 'advanced-coupons-for-woocommerce' ),
                'user_roles_holder'  => __( 'Select roles...', 'advanced-coupons-for-woocommerce' ),
                'balance'            => __( 'Balance', 'advanced-coupons-for-woocommerce' ),
                'balance_min'        => __( 'Min', 'advanced-coupons-for-woocommerce' ),
                'balance_max'        => __( 'Max', 'advanced-coupons-for-woocommerce' ),
                'registered'         => __( 'Registered', 'advanced-coupons-for-woocommerce' ),
                'last_order'         => __( 'Last Order', 'advanced-coupons-for-woocommerce' ),
                'date_from'          => $date_from_label,
                'date_to'            => $date_to_label,
                'include_users'      => __( 'Include Users', 'advanced-coupons-for-woocommerce' ),
                'exclude_users'      => __( 'Exclude Users', 'advanced-coupons-for-woocommerce' ),
                'user_search_holder' => __( 'Search by name or email...', 'advanced-coupons-for-woocommerce' ),
                'combine_hint'       => __( 'Users must match all of the filters you set.', 'advanced-coupons-for-woocommerce' ),
                'preview_button'     => __( 'Preview Users', 'advanced-coupons-for-woocommerce' ),
                'preview_disabled'   => __( 'Set at least one filter or user to preview.', 'advanced-coupons-for-woocommerce' ),
                'reset_button'       => __( 'Reset Filters', 'advanced-coupons-for-woocommerce' ),
                'reset_confirm'      => __( 'Clear all filters?', 'advanced-coupons-for-woocommerce' ),
                'reset_confirm_ok'   => __( 'Reset', 'advanced-coupons-for-woocommerce' ),
                'reset_confirm_no'   => __( 'Cancel', 'advanced-coupons-for-woocommerce' ),
            ),
            'preview_table'      => array(
                /* translators: %s: number of users matched. */
                'matched_count'     => __( '%s users matched', 'advanced-coupons-for-woocommerce' ),
                'no_results'        => __( 'No users matched the selected filters.', 'advanced-coupons-for-woocommerce' ),
                'no_results_hint'   => __( 'Try loosening or removing some filters.', 'advanced-coupons-for-woocommerce' ),
                'empty_state'       => __( 'Set your filters and click "Preview Users" to see the matched customers.', 'advanced-coupons-for-woocommerce' ),
                'name'              => __( 'Name', 'advanced-coupons-for-woocommerce' ),
                'email'             => __( 'Email', 'advanced-coupons-for-woocommerce' ),
                'balance'           => __( 'Current Balance', 'advanced-coupons-for-woocommerce' ),
                'role'              => __( 'Role', 'advanced-coupons-for-woocommerce' ),
                'adjustment_amount' => __( 'Adjustment Amount', 'advanced-coupons-for-woocommerce' ),
                'new_balance'       => __( 'New Balance', 'advanced-coupons-for-woocommerce' ),
            ),
            'adjustment_form'    => array(
                'title'                => __( 'Configure Adjustment', 'advanced-coupons-for-woocommerce' ),
                'adjustment_type'      => __( 'Adjustment Type', 'advanced-coupons-for-woocommerce' ),
                'increase'             => __( 'Increase', 'advanced-coupons-for-woocommerce' ),
                'decrease'             => __( 'Decrease', 'advanced-coupons-for-woocommerce' ),
                'amount_mode'          => __( 'Amount Mode', 'advanced-coupons-for-woocommerce' ),
                'fixed'                => __( 'Fixed', 'advanced-coupons-for-woocommerce' ),
                'percentage'           => __( 'Percentage', 'advanced-coupons-for-woocommerce' ),
                'amount'               => __( 'Amount', 'advanced-coupons-for-woocommerce' ),
                // Live projected-impact summary shown directly beneath the Amount input so the
                // effect of the entered amount is visible without scrolling up to the preview table.
                'impact_title'         => __( 'Projected impact', 'advanced-coupons-for-woocommerce' ),
                'impact_users'         => __( 'Users matched', 'advanced-coupons-for-woocommerce' ),
                'impact_total'         => __( 'Projected total', 'advanced-coupons-for-woocommerce' ),
                'impact_sample'        => __( 'Sample new balance', 'advanced-coupons-for-woocommerce' ),
                /* translators: 1: current balance, 2: projected new balance. */
                'impact_sample_format' => __( '%1$s → %2$s', 'advanced-coupons-for-woocommerce' ),
                'impact_updating'      => __( 'Updating preview…', 'advanced-coupons-for-woocommerce' ),
                'note'                 => __( 'Note', 'advanced-coupons-for-woocommerce' ),
                'note_placeholder'     => __( 'e.g. Q2 loyalty bonus', 'advanced-coupons-for-woocommerce' ),
                'send_email'           => __( 'Send email to users', 'advanced-coupons-for-woocommerce' ),
            ),
            'operation_type'     => array(
                'label'          => __( 'Operation', 'advanced-coupons-for-woocommerce' ),
                // Heading that visually separates the destructive operations from the safe "Adjust" default in the radio group.
                'danger_divider' => __( 'Destructive actions', 'advanced-coupons-for-woocommerce' ),
                'options'        => array(
                    'adjust' => __( 'Adjust', 'advanced-coupons-for-woocommerce' ),
                    'reset'  => __( 'Reset to Zero', 'advanced-coupons-for-woocommerce' ),
                    'delete' => __( 'Delete All Entries', 'advanced-coupons-for-woocommerce' ),
                ),
                'help'           => array(
                    'adjust' => __( 'Increase or decrease balances by a fixed or percentage amount.', 'advanced-coupons-for-woocommerce' ),
                    'reset'  => __( 'Set balance to zero, keep history.', 'advanced-coupons-for-woocommerce' ),
                    'delete' => __( 'Remove all entries for matched users — history will be lost.', 'advanced-coupons-for-woocommerce' ),
                ),
            ),
            'confirmation_modal' => array(
                'title'             => __( 'Confirm Bulk Adjustment', 'advanced-coupons-for-woocommerce' ),
                'users_affected'    => __( 'Users affected', 'advanced-coupons-for-woocommerce' ),
                'adjustment_label'  => __( 'Adjustment', 'advanced-coupons-for-woocommerce' ),
                'operation_label'   => __( 'Operation', 'advanced-coupons-for-woocommerce' ),
                /* translators: 1: increase/decrease, 2: formatted amount, 3: fixed/percentage */
                'adjustment_format' => __( '%1$s %2$s (%3$s)', 'advanced-coupons-for-woocommerce' ),
                'total_credits'     => __( 'Total credits', 'advanced-coupons-for-woocommerce' ),
                'email_notify'      => __( 'Email notify', 'advanced-coupons-for-woocommerce' ),
                'yes'               => __( 'Yes', 'advanced-coupons-for-woocommerce' ),
                'no'                => __( 'No', 'advanced-coupons-for-woocommerce' ),
                'confirm_button'    => __( 'Confirm', 'advanced-coupons-for-woocommerce' ),
                // Action-specific confirm labels for the destructive operations. The safe
                // "adjust" path keeps the generic `confirm_button` above.
                'confirm_reset'     => __( 'Reset Balances', 'advanced-coupons-for-woocommerce' ),
                'confirm_delete'    => __( 'Delete Entries', 'advanced-coupons-for-woocommerce' ),
                // Typed-acknowledgment gate for the irreversible "delete" operation. The user
                // must type `ack_phrase` before the Delete Entries button is enabled.
                'ack_phrase'        => _x( 'DELETE', 'bulk store credit delete confirmation phrase the user must type', 'advanced-coupons-for-woocommerce' ),
                /* translators: %s: the word the user must type (e.g. DELETE). */
                'ack_label'         => __( 'Type %s to confirm', 'advanced-coupons-for-woocommerce' ),
                'warning'           => array(
                    'reset'  => __( 'This will set every matched user\'s store credit balance to zero. The ledger history is preserved, but the operation cannot be reversed.', 'advanced-coupons-for-woocommerce' ),
                    'delete' => __( 'This will permanently delete every store credit entry for the matched users. Both the balance and the full ledger history will be wiped, and the operation cannot be reversed.', 'advanced-coupons-for-woocommerce' ),
                ),
            ),
            'steps'              => array(
                // Guided 3-step wizard header (antd Steps) titles/descriptions plus the
                // forward/back navigation and the first-run orientation intro.
                'find_title'            => __( 'Find users', 'advanced-coupons-for-woocommerce' ),
                'find_description'      => __( 'Filter & preview', 'advanced-coupons-for-woocommerce' ),
                'configure_title'       => __( 'Configure', 'advanced-coupons-for-woocommerce' ),
                'configure_description' => __( 'Set the adjustment', 'advanced-coupons-for-woocommerce' ),
                'review_title'          => __( 'Review & run', 'advanced-coupons-for-woocommerce' ),
                'review_description'    => __( 'Confirm & apply', 'advanced-coupons-for-woocommerce' ),
                'next_button'           => __( 'Next', 'advanced-coupons-for-woocommerce' ),
                'back_button'           => __( 'Back', 'advanced-coupons-for-woocommerce' ),
                'next_disabled_preview' => __( 'Preview at least one matching user to continue.', 'advanced-coupons-for-woocommerce' ),
                'how_it_works_title'    => __( 'How it works', 'advanced-coupons-for-woocommerce' ),
                'how_it_works_body'     => __( 'Adjust store credit for many customers in three steps: (1) find users with filters, (2) configure the adjustment, (3) review and run. Nothing changes until you run it on the final step.', 'advanced-coupons-for-woocommerce' ),
            ),
            'progress_view'      => array(
                'title_queued'              => __( 'Bulk Adjustment Queued', 'advanced-coupons-for-woocommerce' ),
                'title_in_progress'         => __( 'Bulk Adjustment In Progress', 'advanced-coupons-for-woocommerce' ),
                'title_completed'           => __( 'Bulk Adjustment Completed', 'advanced-coupons-for-woocommerce' ),
                'title_failed'              => __( 'Bulk Adjustment Failed', 'advanced-coupons-for-woocommerce' ),
                'processed_label'           => __( 'Progress', 'advanced-coupons-for-woocommerce' ),
                /* translators: 1: processed count, 2: total count. */
                'processed_running'         => __( '%1$s / %2$s', 'advanced-coupons-for-woocommerce' ),
                /* translators: 1: processed count, 2: total count. */
                'processed_completed'       => __( '%1$s of %2$s users processed', 'advanced-coupons-for-woocommerce' ),
                'status_label'              => __( 'Status', 'advanced-coupons-for-woocommerce' ),
                'started_label'             => __( 'Started', 'advanced-coupons-for-woocommerce' ),
                'failed_label'              => __( 'Failed', 'advanced-coupons-for-woocommerce' ),
                'failed_message'            => __( 'The bulk adjustment did not finish successfully. Some users may not have been processed.', 'advanced-coupons-for-woocommerce' ),
                'navigate_away_note'        => __( 'You can navigate away and return later.', 'advanced-coupons-for-woocommerce' ),
                'start_new_button'          => __( 'Start New Adjustment', 'advanced-coupons-for-woocommerce' ),
                // Surfaced inline when GET /bulk/status fails — see ProgressView (during a running operation) and
                // BulkAdjustContent (above the filter form when the mount-time poll fails).
                'status_error_prefix'       => __( 'Status refresh failed:', 'advanced-coupons-for-woocommerce' ),
                'status_error_form_message' => __( 'Could not verify whether a bulk operation is already running.', 'advanced-coupons-for-woocommerce' ),
                'status_error_retry'        => __( 'Retry', 'advanced-coupons-for-woocommerce' ),
                'dismiss_failed_message'    => __( 'Could not dismiss the completed operation. Please try again.', 'advanced-coupons-for-woocommerce' ),
                // moment.js format string for rendering the operation start time. Kept
                // as a sensible default rather than converting WP's PHP date_format/time_format
                // tokens to moment tokens — the two grammars diverge (PHP `Y` = year vs moment
                // `Y` = week-year), so converting would risk silently rendering the wrong year
                // on ISO weeks 53/01.
                'datetime_format'           => 'MMMM D, YYYY h:mm A',
                'status_labels'             => array(
                    'queued'      => __( 'Queued', 'advanced-coupons-for-woocommerce' ),
                    'in_progress' => __( 'In Progress', 'advanced-coupons-for-woocommerce' ),
                    'completed'   => __( 'Completed', 'advanced-coupons-for-woocommerce' ),
                    'failed'      => __( 'Failed', 'advanced-coupons-for-woocommerce' ),
                    'idle'        => __( 'Idle', 'advanced-coupons-for-woocommerce' ),
                ),
            ),
            'export_csv'         => array(
                'button_label'   => __( 'Export CSV', 'advanced-coupons-for-woocommerce' ),
                'error_failed'   => __( 'Failed to export users. Please try again.', 'advanced-coupons-for-woocommerce' ),
                'error_over_cap' => __( 'Too many users matched to export at once. Please narrow your filters and try again.', 'advanced-coupons-for-woocommerce' ),
                'filename'       => __( 'store-credits-users', 'advanced-coupons-for-woocommerce' ),
                'headers'        => array(
                    'user_id'       => __( 'User ID', 'advanced-coupons-for-woocommerce' ),
                    'email'         => __( 'Email', 'advanced-coupons-for-woocommerce' ),
                    'display_name'  => __( 'Display Name', 'advanced-coupons-for-woocommerce' ),
                    'balance'       => __( 'Current Balance', 'advanced-coupons-for-woocommerce' ),
                    'registered_at' => __( 'Registered At', 'advanced-coupons-for-woocommerce' ),
                ),
            ),
            'errors'             => array(
                'preview_failed'  => __( 'Failed to load preview. Please try again.', 'advanced-coupons-for-woocommerce' ),
                'invalid_balance' => __( 'Min balance cannot be greater than Max balance.', 'advanced-coupons-for-woocommerce' ),
                'invalid_dates'   => sprintf(
                    /* translators: 1: From-date label, 2: To-date label. */
                    __( '%1$s date cannot be after %2$s date.', 'advanced-coupons-for-woocommerce' ),
                    $date_from_label,
                    $date_to_label
                ),
                'schedule_failed' => __( 'Failed to schedule the bulk adjustment. Please try again.', 'advanced-coupons-for-woocommerce' ),
                'invalid_amount'  => __( 'Please enter a valid amount greater than zero.', 'advanced-coupons-for-woocommerce' ),
            ),
            'success'            => array(
                'scheduled' => __( 'Bulk adjustment scheduled successfully.', 'advanced-coupons-for-woocommerce' ),
            ),
            'role_options'       => $role_options,
        );
        /**
         * END: Bulk Adjust tab.
         */

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute plugin script loader.
     *
     * @since 2.0
     * @access public
     * @implements ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_filter( 'acfw_register_backend_styles', array( $this, 'register_backend_styles' ) );
        add_filter( 'acfw_register_backend_scripts', array( $this, 'register_backend_scripts' ) );
        add_filter( 'acfw_edit_advanced_coupon_localize', array( $this, 'filter_edit_advanced_coupon_localized_data' ) );
        add_action( 'acfw_after_load_backend_scripts', array( $this, 'load_backend_scripts' ), 10, 2 );
        add_action( 'wp_enqueue_scripts', array( $this, 'load_frontend_scripts' ) );

        add_action( 'acfw_admin_app_enqueue_scripts_before', array( $this, 'enqueue_admin_app_scripts' ) );
        add_filter( 'acfwf_admin_app_localized', array( $this, 'admin_app_localized_data' ) );
    }
}
