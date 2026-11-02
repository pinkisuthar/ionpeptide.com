<?php
namespace ACFWF\Models\Third_Party_Integrations;

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
 * Model that houses the logic of the Wholesale Suite module.
 *
 * @since 4.6.1
 */
class Wholesale_Suite extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.6.1
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

    /**
     * Register exclude wholesale items field in usage restrictions tab.
     *
     * @since 4.6.1
     * @access public
     *
     * @param int $coupon_id Coupon ID.
     */
    public function register_exclude_wholesale_items_restriction_field( $coupon_id ) {
        $coupon = new Advanced_Coupon( $coupon_id );

        woocommerce_wp_checkbox(
            array(
                'id'          => Plugin_Constants::META_PREFIX . 'exclude_wholesale_items',
                'class'       => 'toggle-trigger-field',
                'label'       => __( 'Exclude wholesale items', 'advanced-coupons-for-woocommerce-free' ),
                'description' => __( 'Check this box if the coupon should not apply to wholesale items. Per-item coupons will only work if the item is not on wholesale. Per-cart coupons will only work if there are items in the cart that are not on wholesale.', 'advanced-coupons-for-woocommerce-free' ),
                'value'       => $coupon->get_advanced_prop( 'exclude_wholesale_items' ),
            )
        );
    }

    /**
     * Restrict discount if price is wholesale.
     *
     * @since 4.6.1
     * @access public
     *
     * @param boolean     $valid Filter return value.
     * @param \WC_Product $product Product object.
     * @param \WC_Coupon  $coupon WC_Coupon object.
     * @param array       $values  Values.
     * @return bool Filtered valid value.
     */
    public function restrict_wholesale_discount( $valid, $product, $coupon, $values ) {
        $coupon = $coupon instanceof Advanced_Coupon ? $coupon : new Advanced_Coupon( $coupon );

        // check if the product have wwp_data wholesale_priced and exclude_wholesale_items setting is checked.
        if ( isset( $values['wwp_data'] ) && 'yes' === $values['wwp_data']['wholesale_priced'] && 'yes' === $coupon->get_advanced_prop( 'exclude_wholesale_items' ) ) {
            $valid = false;
        }

        return $valid;
    }

    /**
     * Register exclude wholesale items to acfw default data.
     *
     * @since 4.6.1
     * @access public
     *
     * @return array acfw default data.
     */
    public function register_wholesale_suite_default_data() {
        return array(
            'exclude_wholesale_items' => '',
        );
    }

    /**
     * Save wholesale suite coupons data.
     * - Hook : ACFWF\Models\Edit_Coupon.php - save_url_coupons_data
     *
     * @since 4.6.1
     * @access public
     *
     * @param int             $coupon_id Coupon ID.
     * @param Advanced_Coupon $coupon Coupon object.
     */
    public function save_wholesale_suite_coupons_data( $coupon_id, $coupon ) {
        // This is ignored due to nonce has been checked previously in : ACFWF\Models\Edit_Coupon.php - save_url_coupons_data.
        $data = $_POST; // phpcs:ignore

        // Save exlucde wholesale items.
        $key         = Plugin_Constants::META_PREFIX . 'exclude_wholesale_items';
        $field_value = sanitize_text_field( wp_unslash( $data[ $key ] ?? '' ) );
        $coupon->set_advanced_prop( 'exclude_wholesale_items', $field_value );
    }

    /**
     * Implement exclude wholesale items feature.
     *
     * @since 4.6.1
     * @access public
     *
     * @param bool            $value Filter return value.
     * @param Advanced_Coupon $coupon Advanced coupon object.
     * @return string Notice markup.
     * @throws \Exception When BOGO or Fixed Cart coupon applied and wholesale items is included.
     */
    public function implement_exclude_wholesale_items( $value, $coupon ) {
        $coupon = $coupon instanceof Advanced_Coupon ? $coupon : new Advanced_Coupon( $coupon );
        // Check if coupon type is BOGO or Fixed cart and the exclude wholesale items is enabled.
        if ( ( $coupon->is_type( 'acfw_bogo' ) || $coupon->is_type( 'fixed_cart' ) ) && 'yes' === $coupon->get_advanced_prop( 'exclude_wholesale_items' ) ) {
            // If any wholesale item in cart, then throw error.
            foreach ( \WC()->cart->get_cart_contents() as $item ) {
                if ( isset( $item['wwp_data'] ) && 'yes' === $item['wwp_data']['wholesale_priced'] ) {
                    $message = __( 'Sorry, this coupon is not valid for wholesale items', 'advanced-coupons-for-woocommerce-free' );
                    throw new \Exception( esc_html( $message ) );
                }
            }
        }

        return $value;
    }

    /**
     * Execute Wholesale_Suite class.
     *
     * @since 4.6.1
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        if ( $this->_helper_functions->is_plugin_active( Plugin_Constants::WWP_PLUGIN_BASENAME ) ) {
            add_action( 'woocommerce_coupon_is_valid', array( $this, 'implement_exclude_wholesale_items' ), 10, 2 );
            add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'register_exclude_wholesale_items_restriction_field' ) );
            add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'restrict_wholesale_discount' ), 10, 4 );
            add_filter( 'acfw_default_data', array( $this, 'register_wholesale_suite_default_data' ) );
            add_action( 'acfw_save_coupon', array( $this, 'save_wholesale_suite_coupons_data' ), 10, 2 );
        }
    }
}
