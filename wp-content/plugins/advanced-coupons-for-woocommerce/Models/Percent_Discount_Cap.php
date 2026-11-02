<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Percentage Discount Cap feature.
 * Public Model.
 *
 * @since 3.3
 */
class Percent_Discount_Cap extends Base_Model implements Model_Interface, Initiable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that houses all coupon codes with discount caps feature.
     *
     * @since 3.3
     * @access private
     * @var array
     */
    private $_coupon_discount_caps = array();

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.3
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
    | Admin
    |--------------------------------------------------------------------------
     */

    /**
     * Display percentage discount field under general tab.
     *
     * @since 3.3
     * @access public
     *
     * @param int       $coupon_id Coupon ID.
     * @param WC_Coupon $coupon    Coupon object.
     */
    public function display_percentage_discount_cap_field( $coupon_id, $coupon ) {
        woocommerce_wp_text_input(
            array(
                'id'          => $this->_constants->META_PREFIX . 'percentage_discount_cap',
                'label'       => __( 'Percentage Discount Cap', 'advanced-coupons-for-woocommerce' ),
                'description' => __( 'The maximum discount amount value allowed for this percentage type coupon.', 'advanced-coupons-for-woocommerce' ),
                'data_type'   => 'price',
                'desc_tip'    => true,
                'value'       => $coupon->get_meta( $this->_constants->META_PREFIX . 'percentage_discount_cap', true, 'edit' ),
            )
        );
    }

    /**
     * Save coupon data.
     *
     * @since 3.3
     * @access public
     *
     * @param int             $coupon_id Coupon ID.
     * @param Advanced_Coupon $coupon    Advanced coupon object.
     */
    public function save_percentage_discount_cap_value( $coupon_id, $coupon ) {
        // Verify ACFW's dedicated nonce to ensure the request is valid before saving ACFW data.
        // Uses _acfw_nonce (not _wpnonce) to avoid conflicts with plugins like WooPayments that
        // may modify the shared _wpnonce field before form submission.
        if ( ! isset( $_POST['_acfw_nonce'] ) || false === wp_verify_nonce( sanitize_key( $_POST['_acfw_nonce'] ), 'acfw_save_coupon_data_' . $coupon_id ) ) {
            return;
        }

        $meta_name = $this->_constants->META_PREFIX . 'percentage_discount_cap';

        if ( ! $this->_is_valid_discount_type( $coupon ) || ! isset( $_POST[ $meta_name ] ) ) {
            return;
        }

        $value = $_POST[ $meta_name ] ? \ACFWF()->Helper_Functions->sanitize_price( $_POST[ $meta_name ] ) : $_POST[ $meta_name ]; // phpcs:ignore

        update_post_meta( $coupon_id, $this->_constants->META_PREFIX . 'percentage_discount_cap', $value );
    }

    /*
    |--------------------------------------------------------------------------
    | Frontend implementation
    |--------------------------------------------------------------------------
     */

    /**
     * Register the discount cap values for all percentage coupons applied to the cart before totals are calculated.
     *
     * @since 3.3
     * @access public
     *
     * @param WC_Cart $cart Cart object.
     */
    public function register_applied_coupons_discount_caps( $cart ) {
        foreach ( $cart->get_coupons() as $coupon ) {

            if ( ! in_array( $coupon->get_discount_type(), array( 'percent', Product_Discount_Rules::DISCOUNT_TYPE ), true ) ) {
                continue;
            }

            $discount_cap = apply_filters( 'acfw_filter_amount', (float) $coupon->get_meta( $this->_constants->META_PREFIX . 'percentage_discount_cap', true ) );

            if ( $discount_cap ) {
                $this->_coupon_discount_caps[ $coupon->get_code() ] = \wc_add_number_precision( $discount_cap );
            }
        }
    }

    /**
     * Apply discount cap for a given percentage coupon during coupon's discount amount calculation for each applicable items in the cart.
     * This function is called in a loop of cart items that are valid for the coupon. The discount cap registered for the coupon is deducted
     * every time this function is called. When the discount cap is lesser than the calculated discount value for an item, then the discount
     * cap value will be used as the discount amount for that item.
     *
     * @since 3.3
     * @access public
     *
     * @since 4.1 Scale a per unit discount to the line before comparing it against the remaining cap budget.
     *
     * @param float     $discount           Coupon discount amount for cart item.
     * @param float     $discounting_amount Amount that needs to be discounted.
     * @param array     $cart_item          Cart item data.
     * @param bool      $single             True if discounting a single qty item, false if its the line.
     * @param WC_Coupon $coupon             Coupon object.
     */
    public function apply_percentage_coupon_discount_cap( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
        // skip if coupon is not of a capped type, or when a discount cap value is not available for the coupon.
        if ( ! in_array( $coupon->get_discount_type(), array( 'percent', Product_Discount_Rules::DISCOUNT_TYPE ), true ) || ! isset( $this->_coupon_discount_caps[ $coupon->get_code() ] ) ) {
            return $discount;
        }

        /**
         * The cap is a running budget of the coupon's total discount, so it has to be compared against the
         * amount WooCommerce will actually apply to the line. WC_Discounts::apply_coupon_percent() asks for
         * the whole line ($single is false), but apply_coupon_custom() asks for a single unit and multiplies
         * the returned value by the item quantity itself, so that value has to be scaled up here and back
         * down on the way out.
         */
        $quantity = $single && is_array( $cart_item ) && isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;

        $precise_discount = \wc_add_number_precision( $discount ) * $quantity;
        $discount_cap     = $this->_coupon_discount_caps[ $coupon->get_code() ];

        // use the discount cap value as the discount amount for the item when it's lesser or equal to the calculated discount amount.
        $precise_discount = $discount_cap <= $precise_discount ? $discount_cap : $precise_discount;

        // deduct the discount amount from the registered discount cap or set discount cap's value to zero when it's value is already on the negatives.
        if ( 0 >= $this->_coupon_discount_caps[ $coupon->get_code() ] ) {
            $this->_coupon_discount_caps[ $coupon->get_code() ] = 0;
        } else {
            $this->_coupon_discount_caps[ $coupon->get_code() ] -= $precise_discount;
        }

        return \wc_remove_number_precision( $precise_discount ) / $quantity;
    }

    /**
     * Apply percentage discount cap feature to percentage cashback coupons.
     *
     * @since 3.5.2
     * @access public
     *
     * @param float      $cashback_amount Cashback amount.
     * @param \WC_Coupon $coupon Coupon object.
     * @return float Filtered cashback amount.
     */
    public function apply_percentage_coupon_discount_cap_cashback_coupon( $cashback_amount, $coupon ) {
        $discount_cap    = apply_filters( 'acfw_filter_amount', (float) $coupon->get_meta( $this->_constants->META_PREFIX . 'percentage_discount_cap', true ) );
        $cashback_amount = $discount_cap && $discount_cap <= $cashback_amount ? $discount_cap : $cashback_amount;

        return $cashback_amount;
    }

    /**
     * Check if a coupon's discount type is valid for discount cap feature.
     *
     * @since 3.5.2
     * @access private
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return bool True if valid, false otherwise.
     */
    private function _is_valid_discount_type( $coupon ) {
        return in_array( $coupon->get_discount_type( 'edit' ), array( 'percent', 'acfw_percentage_cashback', Product_Discount_Rules::DISCOUNT_TYPE ), true );
    }


    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 3.3
     * @access public
     * @implements ACFWP\Interfaces\Initializable_Interface
     */
    public function initialize() {     }

    /**
     * Execute Percent_Discount_Cap class.
     *
     * @since 3.3
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'woocommerce_coupon_options', array( $this, 'display_percentage_discount_cap_field' ), 10, 2 );
        add_action( 'acfw_before_save_coupon', array( $this, 'save_percentage_discount_cap_value' ), 10, 2 );

        add_action( 'woocommerce_before_calculate_totals', array( $this, 'register_applied_coupons_discount_caps' ) );
        add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'apply_percentage_coupon_discount_cap' ), 90, 5 );
        add_filter( 'acfwp_calculated_percent_cashback_amount', array( $this, 'apply_percentage_coupon_discount_cap_cashback_coupon' ), 10, 2 );
    }
}
