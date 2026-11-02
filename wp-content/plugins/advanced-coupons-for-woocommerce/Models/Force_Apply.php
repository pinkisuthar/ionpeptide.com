<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Force Apply.
 *
 * @since 3.5.5
 */
class Force_Apply extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Applied coupons cache when trying to force apply.
     *
     * @since 3.5.5
     * @access private
     * @var array
     */
    private $_applied_coupons;

    /**
     * Force applied coupon.
     *
     * @since 3.5.8
     * @access private
     * @var \WC_Coupon
     */
    private $_force_applied_coupon;

    /**
     * List of removed forced applied coupons.
     *
     * @since 3.5.5
     * @access private
     * @var string[]
     */
    private $_rerun_removed_coupon = array();

    /**
     * Flag if apply coupon is called from WC Cart/checkout block.
     *
     * @since 3.5.8
     * @access private
     * @var bool
     */
    private $_apply_coupon_store_api = false;

    /**
     * Reapply depth counter.
     * Tracks how many levels of reapply_coupons_removed_by_force_apply are currently
     * executing. Prevents checkpoint_before_reapply_coupons from triggering a new
     * implement_force_apply_coupons call while we are already reapplying coupons,
     * which would otherwise cause an infinite loop when both the force-applied coupon
     * and the removed coupon have Force Apply = "all" enabled.
     *
     * @since 4.0.8
     * @access private
     * @var int
     */
    private $_reapplying_depth = 0;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.5.5
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
    | Admin Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Add force apply field to coupon General tab.
     *
     * @since 3.5.5
     * @access public
     *
     * @param int        $coupon_id Coupon ID.
     * @param \WC_Coupon $coupon    Coupon data.
     */
    public function display_force_apply_custom_field( $coupon_id, $coupon ) {
        $meta_name = $this->_constants->META_PREFIX . 'force_apply_url_coupon';
        $value     = $coupon->get_meta( $meta_name, true );

        woocommerce_wp_select(
            array(
                'id'          => $meta_name,
                'value'       => esc_attr( $value ),
                'label'       => __( 'Force Apply', 'advanced-coupons-for-woocommerce' ),
                'options'     => array(
                    'disable' => __( 'Disable', 'advanced-coupons-for-woocommerce' ),
                    'yes'     => __( 'When applied via URL only', 'advanced-coupons-for-woocommerce' ), // We use 'yes' as option value because there are a lot of condition logic that uses 'yes' as the value.
                    'all'     => __( 'Enabled', 'advanced-coupons-for-woocommerce' ),
                ),
                'description' => __( 'When enabled, conflicting coupons that are already applied to the cart will be replaced with this coupon instead. This is useful especially if you have an auto applied coupon that you want to be removed when a conflicting coupon is applied.', 'advanced-coupons-for-woocommerce' ),
                'desc_tip'    => true,
            )
        );
    }

    /**
     * Save force apply coupons data.
     * - Hook : ACFWF\Models\Edit_Coupon.php - save_url_coupons_data
     *
     * @since 3.5.5
     * @access public
     *
     * @param int             $coupon_id Coupon ID.
     * @param Advanced_Coupon $coupon Coupon object.
     */
    public function save_force_apply_coupons_data( $coupon_id, $coupon ) {
        // Verify ACFW's dedicated nonce to make sure this is a real coupon-editor form save before mutating data.
        // Without this guard, firing acfw_save_coupon from a non-form context (e.g. the Abilities API,
        // REST, or WP-CLI) where $_POST is empty would silently reset force_apply_url_coupon.
        // Uses _acfw_nonce (not _wpnonce) to avoid conflicts with plugins like WooPayments that
        // may modify the shared _wpnonce field before form submission.
        if ( ! isset( $_POST['_acfw_nonce'] ) || false === wp_verify_nonce( sanitize_key( $_POST['_acfw_nonce'] ), 'acfw_save_coupon_data_' . $coupon_id ) ) {
            return;
        }

        $data = $_POST; // phpcs:ignore

        // Save force apply url coupon.
        $key         = $this->_constants->META_PREFIX . 'force_apply_url_coupon';
        $field_value = sanitize_text_field( wp_unslash( $data[ $key ] ?? '' ) );
        $field_value = in_array( $field_value, array( 'yes', 'all' ), true ) ? $field_value : '';
        $coupon->set_advanced_prop( 'force_apply_url_coupon', $field_value );
    }

    /*
    |--------------------------------------------------------------------------
    | Implementation Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Set flag if apply coupon is called from WC Cart/checkout block.
     *
     * @since 3.5.8
     * @access public
     *
     * @param mixed            $value Filter value.
     * @param \WP_REST_Request $request Request object.
     * @param string           $route REST API route.
     * @return mixed filter value.
     */
    public function check_if_apply_coupon_in_wc_blocks( $value, $request, $route ) {
        if ( '/wc/store/v1/cart/apply-coupon' === $route ) {
            $this->_apply_coupon_store_api = true;
        }

        return $value;
    }

    /**
     * Checkpoint before reapply coupons, and implement force apply coupon.
     *
     * @since 3.5.5
     * @access public
     *
     * @param bool       $is_valid Is valid.
     * @param \WC_Coupon $coupon   Coupon data.
     *
     * @return bool
     * @throws \Exception Error message.
     */
    public function checkpoint_before_reapply_coupons( $is_valid, $coupon ) {
        /**
         * Only run this checkpoint if the action being run is for applying the coupon to the cart/checkout.
         * Skip entirely if we are already inside reapply_coupons_removed_by_force_apply — running force
         * apply for a removed coupon during reapply would reset _applied_coupons and clear the cart,
         * causing an infinite loop when both coupons have Force Apply = "all" enabled.
         */
        if ( $this->_reapplying_depth > 0 ) {
            return $is_valid;
        }

        if ( ( did_action( 'wc_ajax_apply_coupon' ) || $this->_apply_coupon_store_api || apply_filters( 'acfw_should_force_apply_run', false, $coupon ) )
            && ! did_action( 'acfwp_before_auto_apply_coupons' ) // Don't run force apply if the coupon is being applied via the "Auto Apply" feature.
            && ! in_array( $coupon->get_code(), WC()->cart->get_applied_coupons(), true )
            && $this->is_coupon_force_apply( $coupon, 'all' ) ) { // Only force apply if enabled for 'all' (not URL-only).
            // Create checkpoint for force apply rule.
            $this->implement_force_apply_coupons( $coupon );
        }

        return $is_valid;
    }

    /**
     * Implement force apply coupons by removing the already applied coupons so that the coupon being force applied can be
     * checked without being blocked by the other coupons. The coupons will be re-added after the force apply coupon is applied.
     *
     * @since 3.5.5
     * @access public
     *
     * @param string|Advanced_Coupon $coupon Coupon code or object.
     */
    public function implement_force_apply_coupons( $coupon ) {
        $coupon = $coupon instanceof Advanced_Coupon ? $coupon : new Advanced_Coupon( $coupon );

        $this->_force_applied_coupon = $coupon;

        $applied_coupons = \WC()->cart->get_applied_coupons();

        if ( $this->_force_applied_coupon->get_individual_use() ) {
            $temp = array();

            /**
             * This filter is from WC to allow applying the coupon when an individual use coupon is already applied.
             * When the already applied individual use coupon is on the returned array list, it will be kept and not removed due to force apply.
             */
            $coupons_to_keep = apply_filters( 'woocommerce_apply_individual_use_coupon', array(), $this->_force_applied_coupon, $applied_coupons );

            foreach ( $applied_coupons as $applied_coupon ) {
                if ( in_array( $applied_coupon, $coupons_to_keep, true ) ) {
                    $temp[] = $applied_coupon;
                }
            }

            $applied_coupons = $temp;
        }

        // Store coupon locally, so we can reapply it later.
        $this->_applied_coupons = $applied_coupons;

        // Clear all applied coupons so that the current coupon being applied can be applied successfully.
        \WC()->cart->set_applied_coupons( array() );
    }

    /**
     * Implement force apply for URL coupons.
     * Check if coupon is enabled for force apply (either 'yes' for URL-only or 'all'), then implement force apply.
     *
     * @since 4.0.6
     * @access public
     *
     * @param string|Advanced_Coupon $coupon Coupon code or object.
     */
    public function implement_force_apply_for_url_coupons( $coupon ) {
        $coupon = $coupon instanceof Advanced_Coupon ? $coupon : new Advanced_Coupon( $coupon );

        // Only implement force apply if the coupon is enabled for force apply (either 'yes' or 'all').
        if ( $this->is_coupon_force_apply( $coupon ) ) {
            $this->implement_force_apply_coupons( $coupon );
        }
    }

    /**
     * Reapply coupons that was removed when force apply was enabled.
     * - Hook : woocommerce_applied_coupon
     *
     * @since 3.5.5
     * @access public
     *
     * @param string $coupon_code Coupon code.
     */
    public function reapply_coupons_removed_by_force_apply( $coupon_code ) {
        // Skip if there were no coupons removed by force apply.
        if ( empty( $this->_applied_coupons ) ) {
            return;
        }

        // Increment depth counter so checkpoint_before_reapply_coupons skips force-apply
        // logic while we are reapplying. Prevents infinite loops when removed coupons also
        // have Force Apply = "all" enabled.
        ++$this->_reapplying_depth;

        // Clear applied coupons if the coupon is applied via Store API.
        // Only on the outermost call (depth=1) — re-entrant calls must not clear the cart,
        // as that would remove coupons just re-added and defeat the has_discount guard,
        // causing an infinite loop in the Checkout Block even with only one Force Apply coupon.
        if ( $this->_apply_coupon_store_api && 1 === $this->_reapplying_depth ) {
            \WC()->cart->set_applied_coupons( array( $this->_force_applied_coupon->get_code() ) );
        }

        $discounts = new \WC_Discounts( \WC()->cart );

        foreach ( $this->_applied_coupons as $applied_coupon ) {
            // Check if coupon is valid.
            $coupon = new Advanced_Coupon( $applied_coupon );
            $valid  = $discounts->is_coupon_valid( $coupon );

            /**
             * The filter is here from WC to allow a coupon being applied along with an individual use coupon.
             * When the filter is returned as `true` then the individual use coupon will be applied.
             */
            $is_individual_use = $coupon->get_individual_use() && false === apply_filters( 'woocommerce_apply_with_individual_use_coupon', false, $this->_force_applied_coupon, $coupon, $this->_applied_coupons );

            // If valid then add coupon to cart.
            if ( ! is_wp_error( $valid ) && ! $is_individual_use ) {
                add_filter( 'acfw_hide_auto_apply_coupon_success_notice', '__return_true' );
                $coupon = new Advanced_Coupon( $applied_coupon );
                \ACFWP()->Auto_Apply->add_coupon_to_cart( $coupon );
            }
        }

        // Decrement depth counter now that reapply is complete.
        --$this->_reapplying_depth;

        // Clear the stored coupons once the outermost reapply call finishes.
        if ( 0 === $this->_reapplying_depth ) {
            $this->_applied_coupons = array();
        }
    }

    /**
     * Check if a coupon can be force applied.
     *
     * @since 3.5.5
     * @access public
     *
     * @param Advanced_Coupon $coupon Coupon object.
     * @param string|null     $expected_value Expected value.
     * @return bool True if the coupon can be force applied, false otherwise.
     */
    public function is_coupon_force_apply( $coupon, $expected_value = null ) {
        $coupon = $coupon instanceof Advanced_Coupon ? $coupon : new Advanced_Coupon( $coupon );

        $force_apply = $coupon->get_advanced_prop( 'force_apply_url_coupon' );

        if ( $expected_value ) {
            return $force_apply === $expected_value;
        }

        return in_array( $force_apply, array( 'yes', 'all' ), true );
    }

    /**
     * Get the force applied coupon.
     *
     * @since 4.0.6
     * @access public
     *
     * @return Advanced_Coupon|null The force applied coupon or null if no force applied coupon is found.
     */
    public function get_force_applied_coupon() {
        $force_applied_coupon = null;
        foreach ( \WC()->cart->get_applied_coupons() as $coupon_code ) {
            $coupon = new Advanced_Coupon( $coupon_code );
            if ( $this->is_coupon_force_apply( $coupon ) ) {
                $force_applied_coupon = $coupon;
                break;
            }
        }

        return $force_applied_coupon;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Auto_Apply class.
     *
     * @since 3.5.5
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        // Admin Setting.
        add_action( 'woocommerce_coupon_options', array( $this, 'display_force_apply_custom_field' ), 10, 2 ); // Add force apply field to coupon General tab.
        add_action( 'acfw_save_coupon', array( $this, 'save_force_apply_coupons_data' ), 10, 2 ); // Save force apply coupons data.

        // Cart & Checkout Implementation.
        add_filter( 'rest_dispatch_request', array( $this, 'check_if_apply_coupon_in_wc_blocks' ), 10, 3 );
        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'checkpoint_before_reapply_coupons' ), 9, 2 ); // Checkpoint before reapplying coupons.
        add_action( 'woocommerce_applied_coupon', array( $this, 'reapply_coupons_removed_by_force_apply' ), 10, 1 ); // Implement force apply coupons.

        // Integration - URL Coupons.
        add_filter( 'acfw_before_apply_coupon', array( $this, 'implement_force_apply_for_url_coupons' ), 10 ); // Check if we need to implement "force apply" for URL coupon.
    }
}
