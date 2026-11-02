<?php
namespace ACFWP\Models\Third_Party_Integrations;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Woocs module.
 *
 * @since 3.5.1
 */
class Woocs extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.5.1
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

    /**
     * Remove the currency convertion integration hooks from ACFWF.
     *
     * @since 3.5.1
     * @access public
     */
    public function remove_currency_convert_integration_hooks() {
        if ( has_filter( 'acfw_filter_amount', array( \ACFWF()->Woocs, 'convert_amount_to_user_selected_currency' ) ) ) {
            remove_filter( 'acfw_filter_amount', array( \ACFWF()->Woocs, 'convert_amount_to_user_selected_currency' ), 10, 2 );
        }
    }

    /**
     * Re-add the currency convertion integration hooks from ACFWF.
     *
     * @since 3.5.1
     * @access public
     */
    public function readd_currency_convert_integration_hooks() {
        if ( ! has_filter( 'acfw_filter_amount', array( \ACFWF()->Woocs, 'convert_amount_to_user_selected_currency' ) ) ) {
            add_filter( 'acfw_filter_amount', array( \ACFWF()->Woocs, 'convert_amount_to_user_selected_currency' ), 10, 2 );
        }
    }

    /**
     * Convert the cart item price saved to the cart item data for Add Products module.
     *
     * @since 3.5.1
     * @access public
     *
     * @param array $add_product_data Add product cart item data.
     * @return array Filtered add product cart item data.
     */
    public function convert_add_product_cart_item_price_to_base_currency( $add_product_data ) {
        global $WOOCS;

        // Convert product price back to the default currency.
        if ( $WOOCS->default_currency !== $WOOCS->current_currency ) {
            $add_product_data['acfw_add_product_price'] = \ACFWF()->Woocs->convert_amount_to_user_selected_currency( $add_product_data['acfw_add_product_price'], true );
        }

        return $add_product_data;
    }

    /**
     * Convert add products discounted item price to the user selected currency.
     *
     * @since 3.5.1
     * @access public
     *
     * @param float $discount_total Discount total.
     * @return float Filtered discount total.
     */
    public function convert_add_product_discounted_total_summary_to_current_currency( $discount_total ) {
        global $WOOCS;

        if ( $WOOCS->default_currency !== $WOOCS->current_currency ) {
            $discount_total = \ACFWF()->Woocs->convert_amount_to_user_selected_currency( $discount_total );
        }

        return $discount_total;
    }

    /**
     * Remove currency conversion hook when getting coupon amount for fixed cashback coupon type.
     *
     * @since 3.5.2
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     */
    public function remove_currency_convert_for_fixed_cashback_coupon( $coupon ) {
        if ( has_filter( 'acfw_filter_amount', array( \ACFWF()->Woocs, 'convert_amount_to_user_selected_currency' ) ) ) {
            remove_filter( 'acfw_filter_amount', array( \ACFWF()->Woocs, 'convert_amount_to_user_selected_currency' ), 10, 2 );
        }
    }

    /**
     * Re-add currency conversion hook when getting coupon amount for fixed cashback coupon type.
     *
     * @since 3.5.2
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     */
    public function readd_currency_convert_for_fixed_cashback_coupon( $coupon ) {
        if ( ! has_filter( 'acfw_filter_amount', array( \ACFWF()->Woocs, 'convert_amount_to_user_selected_currency' ) ) ) {
            add_filter( 'acfw_filter_amount', array( \ACFWF()->Woocs, 'convert_amount_to_user_selected_currency' ), 10, 2 );
        }
    }

    /**
     * Remove conversion done by WOOCS for the coupon amount for the percentage cashback coupon type.
     *
     * @since 3.5.2
     * @access public
     *
     * @param float $cashback_amount Cashback amount.
     * @return float Filtered cashback amount.
     */
    public function remove_conversion_for_percentage_cashback_amount( $cashback_amount ) {
        return \ACFWF()->Woocs->convert_amount_to_user_selected_currency( $cashback_amount, true );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Woocs class.
     *
     * @since 3.5.1
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! $this->_helper_functions->is_plugin_active( 'woocommerce-currency-switcher/index.php' ) ) {
            return;
        }

        add_action( 'acfwp_before_update_add_products_cart_item_price', array( $this, 'remove_currency_convert_integration_hooks' ) );
        add_action( 'acfwp_after_update_add_products_cart_item_price', array( $this, 'readd_currency_convert_integration_hooks' ) );
        add_action( 'acfwp_before_display_add_products_discount_summary', array( $this, 'remove_currency_convert_integration_hooks' ) );
        add_action( 'acfwp_after_display_add_products_discount_summary', array( $this, 'readd_currency_convert_integration_hooks' ) );
        add_filter( 'acfw_add_product_cart_item_data', array( $this, 'convert_add_product_cart_item_price_to_base_currency' ) );
        add_filter( 'acfwp_add_product_item_discount_summary_price', array( $this, 'convert_add_product_discounted_total_summary_to_current_currency' ) );
        add_action( 'acfwp_before_calculate_cashback_amount', array( $this, 'remove_currency_convert_for_fixed_cashback_coupon' ) );
        add_action( 'acfwp_after_calculate_cashback_amount', array( $this, 'readd_currency_convert_for_fixed_cashback_coupon' ) );
        add_filter( 'acfw_percentage_cashback_amount', array( $this, 'remove_conversion_for_percentage_cashback_amount' ) );
    }
}
