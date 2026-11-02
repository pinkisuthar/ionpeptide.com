<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;
use Automattic\WooCommerce\Utilities\OrderUtil;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of extending the coupon system of woocommerce.
 * It houses the logic of handling coupon url.
 * Public Model.
 *
 * @since 2.0
 */
class Shipping_Overrides extends Base_Model implements Model_Interface, Initiable_Interface {
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
        $main_plugin->add_to_public_models( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | Implementation related  functions.
    |--------------------------------------------------------------------------
     */

    /**
     * Filter package rates to apply shipping override discounts directly to rate costs.
     *
     * Instead of adding negative fees, this adjusts the shipping rate cost so the
     * discounted amount appears on the shipping line itself.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $rates   Array of WC_Shipping_Rate objects.
     * @param array $package Shipping package data.
     * @return array Filtered rates with discounts applied.
     */
    public function filter_package_rates( $rates, $package ) {
        if ( empty( $rates ) || ! \WC()->cart ) {
            return $rates;
        }

        foreach ( \WC()->cart->get_applied_coupons() as $code ) {
            $is_applied = $this->_apply_shipping_overrides_to_rates( $rates, $code );

            // Don't proceed with other applied coupons if a discount was already applied.
            if ( $is_applied ) {
                break;
            }
        }

        return $rates;
    }

    /**
     * Apply shipping overrides for a coupon directly to the package rates.
     *
     * @since 4.0.8
     * @access private
     *
     * @param array  $rates       Array of WC_Shipping_Rate objects.
     * @param string $coupon_code Coupon code.
     * @return bool True if any discount was applied, false otherwise.
     */
    private function _apply_shipping_overrides_to_rates( $rates, $coupon_code ) {
        $coupon    = new Advanced_Coupon( $coupon_code );
        $overrides = $coupon->get_advanced_prop( 'shipping_overrides', array() );

        // For backward compatibility, if enable_shipping_overrides is not set but has data, consider it enabled.
        $enable_state = $coupon->get_advanced_prop( 'enable_shipping_overrides' );
        if ( 'no' === $enable_state || ( '' === $enable_state && empty( $overrides ) ) ) {
            return false;
        }

        if ( ! is_array( $overrides ) || empty( $overrides ) ) {
            return false;
        }

        $classnames       = \WC()->shipping->get_shipping_method_class_names();
        $shipping_classes = $this->_find_shipping_classes_from_cart();
        $has_discount     = false;

        foreach ( $rates as $rate_key => $shipping_rate ) {

            $method_id = $shipping_rate->get_method_id();

            // Get the classname of the shipping method of current shipping rate.
            // Added filter to allow 3rd party shipping plugins to override the classname value.
            $classname = isset( $classnames[ $method_id ] ) ? $classnames[ $method_id ] : '';
            $classname = apply_filters( 'acfwp_shipping_overrides_classname_support', $classname, $shipping_rate );

            // Skip if class doesn't exist.
            if ( ! class_exists( $classname ) ) {
                continue;
            }

            // Filter the valid overrides.
            $valid_overrides = $this->_get_valid_shipping_overrides( $overrides, $shipping_rate, $shipping_classes );

            if ( empty( $valid_overrides ) || ! $classname ) {
                continue;
            }

            // Calculate the total discount for this rate.
            $discount = $this->_calculate_rate_discount( $classname, $valid_overrides, $shipping_rate, $coupon );

            if ( $discount <= 0 ) {
                continue;
            }

            // Apply discount directly to rate cost.
            // In "coupon" discount application mode the rate cost is left unchanged — the
            // discount is attributed to the coupon amount instead (see
            // register_coupon_amount_contributions).
            $original_cost = (float) $shipping_rate->get_cost();
            $new_cost      = max( 0, $original_cost - $discount );

            if ( ! $this->_is_coupon_amount_mode() ) {
                $shipping_rate->set_cost( $new_cost );
            }

            // Recalculate taxes proportionally based on the new cost. This runs in both
            // discount application modes so the customer pays the same shipping tax either
            // way (in "coupon" mode only the cost presentation moves to the coupon line).
            $taxes = $shipping_rate->get_taxes();
            if ( ! empty( $taxes ) && $original_cost > 0 ) {
                $ratio = $new_cost / $original_cost;
                foreach ( $taxes as $tax_id => $tax_amount ) {
                    $taxes[ $tax_id ] = (float) $tax_amount * $ratio;
                }
                $shipping_rate->set_taxes( $taxes );
            }

            // Store discount metadata on the rate for order processing.
            // Underscore-prefix the keys so WooCommerce treats them as hidden — without
            // it, item meta is rendered to customers in My Account → Order details.
            $shipping_rate->add_meta_data( '_acfw_shipping_override_discount', min( $discount, $original_cost ) );
            $shipping_rate->add_meta_data( '_acfw_shipping_override_coupon', $coupon->get_code() );
            $shipping_rate->add_meta_data( '_acfw_shipping_override_original_cost', $original_cost );

            $has_discount = true;
        }

        return $has_discount;
    }

    /**
     * Get valid shipping overrides data based on the cart and the currently selected shipping rate.
     *
     * @since 3.5.2
     * @access private
     *
     * @param array             $overrides Coupon shipping overrides data.
     * @param \WC_Shipping_Rate $shipping_rate Shipping rate object.
     * @param array             $shipping_classes List of shipping classes.
     */
    private function _get_valid_shipping_overrides( $overrides, $shipping_rate, $shipping_classes ) {

        $filtered_overrides = array_filter(
            $overrides,
            function ( $data ) use ( $shipping_rate, $shipping_classes ) {

                // return early for nozone options and just validate actual method selected method id.
                if ( 'nozone' === $data['shipping_zone'] ) {
                    return $data['shipping_method'] === $shipping_rate->get_method_id();
                }

                if ( $data['shipping_zone'] < 0 ) {
                    return false;
                }

                // check if shipping method option selected has a specific shipping class.
                if ( str_contains( $data['shipping_method'], 'class' ) ) {
                    $temp            = explode( '_class_', $data['shipping_method'] );
                    $shipping_method = absint( $temp[0] );
                    $shipping_class  = absint( $temp[1] );

                    return $shipping_method === $shipping_rate->get_instance_id() && in_array( $shipping_class, $shipping_classes, true );
                }

                // normal method under shipping zone.
                return absint( $data['shipping_method'] ) === $shipping_rate->get_instance_id();
            }
        );

        return array_values( $filtered_overrides );
    }

    /**
     * Calculate the shipping cost for a specific shipping class in the cart.
     *
     * This function loops through the cart items and calculates the total cost
     * for products in the specified shipping class, evaluating any cost formulas
     * with [qty] placeholders.
     *
     * @since 4.0.4
     *
     * @param int               $term_id       The shipping class term ID.
     * @param \WC_Shipping_Rate $shipping_rate The shipping rate object for the current method.
     *
     * @return float The shipping cost for the specified shipping class.
     */
    private function _get_shipping_class_total_cost( $term_id, $shipping_rate ) {
        $settings = get_option( 'woocommerce_' . $shipping_rate->get_method_id() . '_' . $shipping_rate->get_instance_id() . '_settings' );
        $key      = 'class_cost_' . $term_id;

        // Return 0 if no settings found for this shipping class.
        if ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] ) {
            return 0;
        }

        $cost_formula = $settings[ $key ];
        $qty          = 0;

        // Calculate total quantity for this shipping class.
        foreach ( \WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];

            if ( ! $product instanceof \WC_Product ) {
                continue;
            }

            $shipping_class_id = $product->get_shipping_class_id();

            if ( $term_id === $shipping_class_id ) {
                $qty += $cart_item['quantity'];
            }
        }

        // Replace [qty] placeholder and evaluate using WC's built-in math evaluator.
        include_once \WC()->plugin_path() . '/includes/libraries/class-wc-eval-math.php';

        $sum  = str_replace( '[qty]', (string) $qty, $cost_formula );
        $sum  = preg_replace( '/\s+/', '', $sum );
        $sum  = rtrim( ltrim( $sum, "\t\n\r\0\x0B+*/" ), "\t\n\r\0\x0B+-*/" );
        $cost = $sum ? (float) \WC_Eval_Math::evaluate( $sum ) : 0;

        return $cost;
    }

    /**
     * Calculate the total discount amount for a shipping rate based on valid overrides.
     *
     * @since 4.0.8
     * @access private
     *
     * @param string            $classname       Shipping method classname.
     * @param array             $valid_overrides Valid overrides data.
     * @param \WC_Shipping_Rate $shipping_rate   Shipping rate object.
     * @param Advanced_Coupon   $coupon          Coupon object.
     * @return float Total discount amount.
     */
    private function _calculate_rate_discount( $classname, $valid_overrides, $shipping_rate, $coupon ) {
        $total_discount = 0;

        foreach ( $valid_overrides as $override ) {

            // Calculate discount amount.
            $type                = $override['discount_type'];
            $value               = $override['discount_value'];
            $shipping_class_cost = null;

            // Check if override is tied to a specific shipping class.
            if ( preg_match( '/^(\d+)_class_(\d+)$/', $override['shipping_method'], $matches ) ) {
                $override_instance_id = absint( $matches[1] );
                $shipping_class_id    = absint( $matches[2] );

                if ( $override_instance_id === $shipping_rate->get_instance_id() ) {
                    $shipping_class_cost = $this->_get_shipping_class_total_cost( $shipping_class_id, $shipping_rate );
                }
            }

            // Determine amount to calculate discount from.
            $base_amount = null !== $shipping_class_cost ? $shipping_class_cost : $shipping_rate->get_cost();

            $amount = \ACFWF()->Helper_Functions->calculate_discount_by_type( $type, $value, $base_amount );

            if ( $amount > 0 ) {
                $total_discount += $amount;
            }
        }

        /**
         * Filter the calculated shipping override discount for a rate.
         *
         * @since 4.0.8
         *
         * @param float             $total_discount  Total discount amount.
         * @param \WC_Shipping_Rate $shipping_rate   Shipping rate object.
         * @param array             $valid_overrides Valid overrides data.
         * @param Advanced_Coupon   $coupon          Coupon object.
         */
        return (float) apply_filters( 'acfwp_shipping_override_rate_discount', $total_discount, $shipping_rate, $valid_overrides, $coupon );
    }

    /**
     * Save shipping overrides discounts to the relative coupon order item meta.
     *
     * Reads discount data from shipping line item meta (set via rate meta_data
     * copied by WooCommerce during order creation) instead of fee items.
     *
     * @since 3.5.2
     * @since 4.0.8 Updated to read from shipping items instead of fee items.
     * @access public
     *
     * @param int      $order_id    Order id.
     * @param array    $posted_data Order posted data.
     * @param WC_Order $order       Order object.
     */
    public function save_shipping_discounts_to_coupon_order_item( $order_id, $posted_data, $order ) {
        // In "coupon" discount application mode the shipping override discount is already
        // part of the native order coupon line discount. Skip the extra meta so downstream
        // consumers (edit order coupon value display, extra discount totals) don't count
        // it twice.
        if ( $this->_is_coupon_amount_mode() ) {
            return;
        }

        $discount_totals = array();

        foreach ( $order->get_shipping_methods() as $shipping ) {

            $coupon_code = $shipping->get_meta( '_acfw_shipping_override_coupon', true );
            $discount    = $shipping->get_meta( '_acfw_shipping_override_discount', true );

            // Skip if shipping item has no override discount data. `is_numeric()` is used
            // rather than `! $discount` so a legitimate 0-discount on a 0-cost rate (i.e.
            // the meta key is set but the resolved amount is 0) still gets recorded —
            // `! $discount` would falsely swallow `'0'`, `0`, and `0.0`. `! is_numeric()`
            // correctly skips `''`, `null`, and `false` while accepting any numeric value.
            if ( ! $coupon_code || ! is_numeric( $discount ) ) {
                continue;
            }

            // Set default total to 0 for coupon that is not yet set on the array.
            if ( ! isset( $discount_totals[ $coupon_code ] ) ) {
                $discount_totals[ $coupon_code ] = 0;
            }

            $discount_totals[ $coupon_code ] += wc_add_number_precision( (float) $discount );
        }

        // Skip if order has no shipping override discounts from coupon.
        if ( empty( $discount_totals ) ) {
            return;
        }

        foreach ( $discount_totals as $key => $discount_total ) {

            // Get the matching coupon order item.
            $order_coupon = current(
                array_filter(
                    $order->get_coupons(),
                    function ( $oc ) use ( $key ) {
                        return str_contains( $oc->get_code(), $key );
                    }
                )
            );

            if ( ! $order_coupon ) {
                continue;
            }

            // Save total shipping override discount to coupon order item meta.
            $order_coupon->update_meta_data( $this->_constants->ORDER_COUPON_SHIPPING_OVERRIDES_DISCOUNT, wc_remove_number_precision( $discount_total ) );
            $order_coupon->save_meta_data();
        }
    }

    /**
     * Save shipping overrides discounts to the relative coupon order item meta on Store API orders.
     *
     * The block checkout does not fire `woocommerce_checkout_order_processed`. It fires
     * `woocommerce_store_api_checkout_order_processed` instead, which passes the order
     * object only. This wrapper adapts that signature to the shared handler.
     *
     * @since 4.1
     * @access public
     *
     * @param WC_Order $order Order object.
     */
    public function save_shipping_discounts_to_coupon_order_item_via_store_api( $order ) {
        $this->save_shipping_discounts_to_coupon_order_item( $order->get_id(), array(), $order );
    }

    /*
    |--------------------------------------------------------------------------
    | Coupon amount discount application mode.
    |--------------------------------------------------------------------------
     */

    /**
     * Check if Shipping Overrides discounts should be applied to the coupon amount instead
     * of reducing the shipping rate cost.
     *
     * Guarded with method_exists so the premium plugin degrades gracefully to price
     * modification mode when running alongside an older free plugin version without
     * the discount application mode resolver.
     *
     * @since 4.1
     * @access private
     *
     * @return bool True when the "coupon amount" discount application mode is selected.
     */
    private function _is_coupon_amount_mode() {
        return method_exists( \ACFWF()->Helper_Functions, 'get_discount_application_mode' )
            && 'coupon' === \ACFWF()->Helper_Functions->get_discount_application_mode( 'shipping_overrides' );
    }

    /**
     * Get the shipping override discounts recorded on the currently chosen shipping rates.
     *
     * @since 4.1
     * @access private
     *
     * @return array List of `array( 'coupon' => string, 'discount' => float, 'label' => string )`
     *               entries for chosen rates that carry a shipping override discount.
     */
    private function _get_chosen_rates_override_discounts() {
        if ( ! \WC()->session || ! \WC()->shipping() ) {
            return array();
        }

        $chosen_methods = \WC()->session->get( 'chosen_shipping_methods' );
        $chosen_methods = is_array( $chosen_methods ) ? $chosen_methods : array();
        $discounts      = array();

        foreach ( \WC()->shipping()->get_packages() as $key => $package ) {

            $chosen_rate_id = isset( $chosen_methods[ $key ] ) ? $chosen_methods[ $key ] : '';

            if ( ! $chosen_rate_id || ! isset( $package['rates'][ $chosen_rate_id ] ) ) {
                continue;
            }

            $rate        = $package['rates'][ $chosen_rate_id ];
            $meta        = $rate->get_meta_data();
            $discount    = isset( $meta['_acfw_shipping_override_discount'] ) ? $meta['_acfw_shipping_override_discount'] : null;
            $coupon_code = isset( $meta['_acfw_shipping_override_coupon'] ) ? (string) $meta['_acfw_shipping_override_coupon'] : '';

            if ( ! $coupon_code || ! is_numeric( $discount ) || 0.0 >= (float) $discount ) {
                continue;
            }

            $discounts[] = array(
                'coupon'   => $coupon_code,
                'discount' => (float) $discount,
                'label'    => $rate->get_label(),
            );
        }

        return $discounts;
    }

    /**
     * Register Shipping Overrides discounts as coupon amount contributions.
     *
     * Runs on the `acfw_coupon_amount_total_contributions` filter (free plugin discount
     * application engine) in "coupon" discount application mode. The shipping rate keeps
     * its full cost, and the discount recorded on the chosen rate's meta is attributed to
     * the coupon's discount total and subtracted from the cart grand total instead.
     *
     * @since 4.1
     * @access public
     *
     * @param array    $contributions Coupon code keyed list of contribution amounts.
     * @param \WC_Cart $cart          Cart object.
     * @return array Filtered contributions.
     */
    public function register_coupon_amount_contributions( $contributions, $cart ) {
        if ( ! $this->_is_coupon_amount_mode() ) {
            return $contributions;
        }

        foreach ( $this->_get_chosen_rates_override_discounts() as $data ) {
            $coupon_code                   = $data['coupon'];
            $contributions[ $coupon_code ] = isset( $contributions[ $coupon_code ] ) ? $contributions[ $coupon_code ] + $data['discount'] : $data['discount'];
        }

        return $contributions;
    }

    /**
     * Add the discount application mode to the shipping packages data.
     *
     * WooCommerce caches calculated shipping rates in the session keyed by a hash of the
     * package data. The rate cost and taxes we produce depend on the discount application
     * mode, so the mode is added to every package to invalidate that cache whenever the
     * store owner switches modes.
     *
     * @since 4.1
     * @access public
     *
     * @param array $packages Shipping packages.
     * @return array Filtered shipping packages.
     */
    public function add_discount_mode_to_shipping_packages( $packages ) {
        $mode = $this->_is_coupon_amount_mode() ? 'coupon' : 'price';

        foreach ( $packages as $key => $package ) {
            $packages[ $key ]['acfw_shipping_discount_application_mode'] = $mode;
        }

        return $packages;
    }

    /**
     * Get the shipping override discounts summary for a coupon.
     *
     * Only used in "coupon" discount application mode — in price modification mode the
     * discount is visible on the shipping line itself.
     *
     * @since 4.1
     * @access public
     *
     * @param \WC_Coupon $coupon Coupon object.
     * @return string Shipping override discounts summary markup, or empty string.
     */
    public function get_shipping_override_summary_for_coupon( $coupon ) {
        if ( ! $this->_is_coupon_amount_mode() ) {
            return '';
        }

        $coupon_code = $coupon->get_code();
        $summary     = '';

        foreach ( $this->_get_chosen_rates_override_discounts() as $data ) {

            if ( $data['coupon'] !== $coupon_code ) {
                continue;
            }

            $summary .= sprintf(
                '<li><span class="label">%1$s</span> <span class="discount">%2$s</span></li>',
                sprintf(
                    /* translators: %s: shipping method label */
                    esc_html__( 'Shipping discount (%s):', 'advanced-coupons-for-woocommerce' ),
                    esc_html( $data['label'] )
                ),
                wc_price( $data['discount'] * -1 )
            );
        }

        return $summary ? sprintf( '<ul class="acfw-shipping-overrides-summary %s-shipping-overrides-summary">%s</ul>', sanitize_html_class( $coupon_code ), $summary ) : '';
    }

    /**
     * Display the shipping override discounts summary on the coupons cart total row.
     *
     * @since 4.1
     * @access public
     *
     * @param string     $coupon_html          Coupon row html.
     * @param \WC_Coupon $coupon               Coupon object.
     * @param string     $discount_amount_html Discount amount html.
     * @return string Filtered coupon row html.
     */
    public function display_shipping_override_discount_summary( $coupon_html, $coupon, $discount_amount_html ) {
        return $coupon_html . $this->get_shipping_override_summary_for_coupon( $coupon );
    }

    /**
     * Add the shipping override discounts summary to the cart/checkout block coupon summary.
     *
     * @since 4.1
     * @access public
     *
     * @param string     $summary Summary content.
     * @param \WC_Coupon $coupon  Coupon object.
     * @return string Filtered summary content.
     */
    public function append_shipping_override_summary_to_cart_checkout_block( $summary, $coupon ) {
        return $summary . $this->get_shipping_override_summary_for_coupon( $coupon );
    }

    /*
    |--------------------------------------------------------------------------
    | Deprecated functions (kept for backward compatibility).
    |--------------------------------------------------------------------------
     */

    /**
     * Implement shipping overrides.
     *
     * @since      2.0
     * @deprecated 4.0.8 Replaced by `filter_package_rates()` which adjusts shipping rate
     *             cost directly via the `woocommerce_package_rates` filter instead of
     *             adding negative fees on `woocommerce_cart_calculate_fees`.
     * @access     public
     */
    public function implement_shipping_overrides() {
        _deprecated_function( __METHOD__, '4.0.8', __CLASS__ . '::filter_package_rates' );
    }

    /**
     * Remove tax data for non-taxable shipping discounts.
     *
     * No-op stub: shipping discounts are no longer applied as fee items, so the
     * tax-stripping filter is unnecessary. Retained to avoid fatals in third-party
     * code that references this callback.
     *
     * @since      2.6.1
     * @deprecated 4.0.8 Shipping discounts are now applied directly to rate cost.
     * @access     public
     *
     * @param array  $taxes Fee taxes data.
     * @param object $fee  Fee object data in cart.
     * @return array Unmodified fee taxes data.
     */
    public function remove_taxes_for_non_taxable_shipping_discounts( $taxes, $fee ) {
        _deprecated_function( __METHOD__, '4.0.8' );
        return $taxes;
    }

    /**
     * Save shipping discount meta data on checkout process.
     *
     * No-op stub: shipping override metadata is now stored directly on the
     * shipping line item via `WC_Shipping_Rate::add_meta_data()`. Retained to
     * avoid fatals in third-party code that references this callback.
     *
     * @since      2.6.1
     * @deprecated 4.0.8 Metadata is now stored on the shipping rate directly.
     * @access     public
     *
     * @param mixed $item    Fee item object.
     * @param mixed $fee_key Loop key.
     * @param mixed $fee     Fee data available in cart.
     */
    public function save_shipping_discount_metadata( $item, $fee_key, $fee ) {
        _deprecated_function( __METHOD__, '4.0.8' );
    }

    /*
    |--------------------------------------------------------------------------
    | Editing related  functions.
    |--------------------------------------------------------------------------
     */

    /**
     * Populate selectable shipping zones with methods data.
     *
     * @since 2.0
     * @since 2.2.3 Add support for non-shipping zone supported methods.
     * @since 4.1 Show shipping class options for the "Not covered locations" zone methods.
     * @access public
     *
     * @param array $options List of shipping zones with methods.
     * @return array Filtered list of shipping zones with methods.
     */
    public function populate_selectable_options( $options = array() ) {
        // list to hold all registered methods under a shipping zone.
        $zoned_methods         = array();
        $zoned_methods_reducer = function ( $c, $sm ) {
            return array_merge( $c, array( $sm->id ) );
        };

        // get all shipping zones.
        $zones = $this->_helper_functions->get_shipping_zones();

        foreach ( $zones as $zone ) {

            $methods       = array_filter( $zone['shipping_methods'], array( $this, '_validate_shipping_method' ) );
            $options[]     = array(
                'zone_id'   => $zone['zone_id'],
                'zone_name' => $zone['zone_name'],
                'methods'   => $this->_get_zone_shipping_method_options( $methods ),
            );
            $zoned_methods = array_reduce( $methods, $zoned_methods_reducer, $zoned_methods );
        }

        // get shipping methods for "Locations not covered by your other zones".
        $zone_0        = \WC_Shipping_Zones::get_zone( 0 );
        $other_methods = array_filter( $zone_0->get_shipping_methods(), array( $this, '_validate_shipping_method' ) );

        if ( $other_methods && ! empty( $other_methods ) ) {
            $options[]     = array(
                'zone_id'   => 0,
                'zone_name' => __( 'Not covered locations', 'advanced-coupons-for-woocommerce' ),
                'methods'   => $this->_get_zone_shipping_method_options( $other_methods ),
            );
            $zoned_methods = array_reduce( $other_methods, $zoned_methods_reducer, $zoned_methods );
        }

        // get methods that doesn't support shipping zones.
        $not_zoned_methods = array_filter(
            \WC()->shipping()->get_shipping_methods(),
            function ( $sm ) use ( $zoned_methods ) {
            return ! in_array( $sm->id, $zoned_methods, true ) && $this->_validate_shipping_method( $sm );
            }
        );

        // add non-zoned methods to a single option.
        if ( $not_zoned_methods && ! empty( $not_zoned_methods ) ) {
            $options[] = array(
                'zone_id'   => 'nozone',
                'zone_name' => __( 'Non-shipping zone methods', 'advanced-coupons-for-woocommerce' ),
                'methods'   => array_values(
                    array_map(
                        function ( $m ) {
                            return array(
                                'value' => $m->id,
                                'label' => $m->title,
                            );
                            },
                        $not_zoned_methods
                    )
                ),
            );
        }

        return $options;
    }

    /**
     * Get shipping method options for a zone given its list of shipping methods.
     *
     * @since 2.3
     * @access private
     *
     * @param array $zone_methods Shipping zone list of shipping methods.
     * @return array list of shipping method options.
     */
    private function _get_zone_shipping_method_options( $zone_methods ) {
        $method_options   = array();
        $shipping_classes = \WC()->shipping()->get_shipping_classes();

        foreach ( $zone_methods as $zone_method ) {

            $method_options[] = array(
                'value' => $zone_method->instance_id,
                'label' => $zone_method->title,
            );

            if ( ! empty( $shipping_classes ) && in_array( 'instance-settings', $zone_method->supports, true ) ) {

                $method_classes = array_filter(
                    $shipping_classes,
                    function ( $c ) use ( $zone_method ) {
                    $index = 'class_cost_' . $c->term_id;
                    return isset( $zone_method->instance_settings[ $index ] );
                    }
                );

                if ( empty( $method_classes ) ) {
                    continue;
                }

                foreach ( $method_classes as $class ) {
                    $method_options[] = array(
                        'value' => sprintf( '%s_class_%s', $zone_method->instance_id, $class->term_id ),
                        'label' => sprintf( '%s: %s', $zone_method->title, $class->name ),
                    );
                }
            }
        }

        return $method_options;
    }

    /**
     * Sanitize shipping override data.
     *
     * @since 2.0
     * @access private
     *
     * @param array $data Shipping override data.
     * @return array Sanizied shipping override data.
     */
    private function _sanitize_shipping_override( $data ) {
        $sanitized = array();

        if ( 'empty' !== $data && ! empty( $data ) ) {
            foreach ( $data as $key => $row ) {

                $shipping_zone     = 'nozone' === $row['shipping_zone'] ? 'nozone' : absint( $row['shipping_zone'] );
                $sanitized[ $key ] = array(
                    'shipping_zone'   => $shipping_zone >= 0 ? $shipping_zone : 'nozone',
                    'shipping_method' => sanitize_text_field( $row['shipping_method'] ),
                    'discount_type'   => sanitize_text_field( $row['discount_type'] ),
                    'discount_value'  => (float) wc_format_decimal( $row['discount_value'] ),
                );
            }
        }

        return $sanitized;
    }

    /**
     * Save shipping overrides.
     *
     * @since 2.0
     * @access private
     *
     * @param int   $coupon_id Coupon ID.
     * @param array $overrides Shipping overrides data.
     * @return bool True if updated, false otherwise.
     */
    private function _save_shipping_overrides( $coupon_id, $overrides ) {
        return update_post_meta( $coupon_id, $this->_constants->META_PREFIX . 'shipping_overrides', $overrides );
    }

    /**
     * Validate shipping methods.
     *
     * @since 2.0
     * @since 2.2.3 Make validation less strict and add filterable list of disallowed methods.
     * @access private
     *
     * @param WC_Shipping_Method $sm Shipping method.
     * @return boolean True if valid, false otherwise.
     */
    private function _validate_shipping_method( $sm ) {
        $disallowed_methods = apply_filters( 'acfw_disallowed_shipping_methods_for_override', array( 'free_shipping' ) );
        return 'yes' === $sm->enabled && ! in_array( $sm->id, $disallowed_methods, true );
    }

    /*
    |--------------------------------------------------------------------------
    | Order admin related functions.
    |--------------------------------------------------------------------------
     */

    /**
     * Recalculate correct order shipping total with discount.
     *
     * @since 2.2.3
     * @access public
     */
    public function recalculate_shipping_total_with_discount() {
        $order_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_id = ! $order_id && isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $order_id || ! OrderUtil::is_order( $order_id, wc_get_order_types() ) ) {
            return;
        }

        // Variables.
        $order          = wc_get_order( $order_id );
        $shipping_total = 0;
        $discount       = 0;

        // skip if order shipping values have already been recalculated.
        if ( $order->get_meta( 'acfw_shipping_discount_recalc' ) === 'yes' ) {
            return;
        }

        // Sum shipping costs.
        foreach ( $order->get_shipping_methods() as $shipping ) {
            $shipping_total += (float) $shipping->get_total( 'edit' );
        }

        foreach ( $order->get_fees() as $fee ) {
            if (
                str_contains( $fee->get_name(), '[shipping_discount]' ) ||
                str_contains( $fee->get_meta( 'acfw_fee_cart_id' ), 'acfw-shipping-discount' )
            ) {
                $discount += (float) $fee->get_total( 'edit' );
            }
        }

        if ( ! $discount ) {
            return;
        }

        // we add because discount value is negative already.
        $total = $shipping_total + $discount;

        // set shipping total and make sure value is not negative.
        $order->set_shipping_total( $total >= 0 ? $total : 0 );
        $order->add_meta_data( 'acfw_shipping_discount_recalc', 'yes' );
        $order->save();
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX functions.
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX save shipping overrides.
     *
     * @since 2.0
     * @access public
     */
    public function ajax_save_shipping_overrides() {
        // Validate nonce.
        $nonce = sanitize_key( $_POST['nonce'] ?? '' );
        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Invalid AJAX call', 'advanced-coupons-for-woocommerce' ),
            );
        } elseif ( ! current_user_can( apply_filters( 'acfw_ajax_save_bogo_deals', 'manage_woocommerce' ) )
            || ! $nonce
            || ! wp_verify_nonce( $nonce, 'acfw_save_shipping_overrides' )
        ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'You are not allowed to do this', 'advanced-coupons-for-woocommerce' ),
            );
        } elseif ( ! isset( $_POST['coupon_id'] ) || ! isset( $_POST['overrides'] ) ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Missing required post data', 'advanced-coupons-for-woocommerce' ),
            );
        } else {
            $coupon_id = absint( $_POST['coupon_id'] );
            $overrides = $this->_sanitize_shipping_override( $_POST['overrides'] ); // phpcs:ignore
            $check     = $this->_save_shipping_overrides( $coupon_id, $overrides );

            if ( $check ) {
                $response = array(
                    'status'  => 'success',
                    'message' => __( 'Shipping overrides have been saved successfully!', 'advanced-coupons-for-woocommerce' ),
                );
            } else {
                $response = array( 'status' => 'fail' );
            }
        }

        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
        echo wp_json_encode( $response );
        wp_die();
    }

    /**
     * AJAX clear shipping overrides.
     *
     * @since 2.0
     * @access public
     */
    public function ajax_clear_shipping_overrides() {
        // Validate nonce.
        $nonce = sanitize_key( $_POST['_wpnonce'] ?? '' );
        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Invalid AJAX call', 'advanced-coupons-for-woocommerce' ),
            );
        } elseif ( ! $nonce || ! wp_verify_nonce( $nonce, 'acfw_clear_shipping_overrides' ) || ! current_user_can( apply_filters( 'acfw_ajax_clear_add_products_data', 'manage_woocommerce' ) ) ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'You are not allowed to do this', 'advanced-coupons-for-woocommerce' ),
            );
        } elseif ( ! isset( $_POST['coupon_id'] ) ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Missing required post data', 'advanced-coupons-for-woocommerce' ),
            );
        } else {

            $coupon_id  = intval( $_POST['coupon_id'] );
            $save_check = update_post_meta( $coupon_id, $this->_constants->META_PREFIX . 'shipping_overrides', array() );

            if ( $save_check ) {
                $response = array(
                    'status'  => 'success',
                    'message' => __( 'Shipping overides has been cleared successfully!', 'advanced-coupons-for-woocommerce' ),
                );
            } else {
                $response = array(
                    'status'    => 'fail',
                    'error_msg' => __( 'Failed on clearing or there were no changes to save.', 'advanced-coupons-for-woocommerce' ),
                );
            }
        }

        @header('Content-Type: application/json; charset=' . get_option('blog_charset')); // phpcs:ignore
        echo wp_json_encode( $response );
        wp_die();
    }

    /**
     * Find shipping classes from cart shipping packages.
     *
     * @since 2.4
     * @access private
     *
     * @return array List of detected shipping classes.
     */
    private function _find_shipping_classes_from_cart() {
        $shipping_classes = array();
        $packages         = \WC()->cart->get_shipping_packages();

        foreach ( $packages as $package ) {

            foreach ( $package['contents'] as $item_id => $values ) {

                if ( $values['data']->needs_shipping() ) {
                    $found_class = $values['data']->get_shipping_class_id();
                    if ( $found_class ) {
                        $shipping_classes[] = $found_class;
                    }
                }
            }
        }

        return $shipping_classes;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 2.0
     * @access public
     * @implements ACFWP\Interfaces\Initializable_Interface
     */
    public function initialize() {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::SHIPPING_OVERRIDES_MODULE ) ) {
            return;
        }

        add_action( 'wp_ajax_acfw_save_shipping_overrides', array( $this, 'ajax_save_shipping_overrides' ) );
        add_action( 'wp_ajax_acfw_clear_shipping_overrides', array( $this, 'ajax_clear_shipping_overrides' ) );
    }

    /**
     * Execute Shipping_Overrides class.
     *
     * @since 2.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::SHIPPING_OVERRIDES_MODULE ) ) {
            return;
        }

        // Apply shipping overrides by adjusting package rate costs directly.
        add_filter( 'woocommerce_package_rates', array( $this, 'filter_package_rates' ), 10, 2 );

        // "Coupon amount" discount application mode: deliver shipping override discounts
        // through the coupon amount (free plugin discount application engine) and show a
        // breakdown summary on the coupon row.
        add_filter( 'acfw_coupon_amount_total_contributions', array( $this, 'register_coupon_amount_contributions' ), 10, 2 );
        add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'add_discount_mode_to_shipping_packages' ), 10, 1 );
        add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'display_shipping_override_discount_summary' ), 10, 3 );
        add_filter( 'acfwf_cart_checkout_block_coupon_summary', array( $this, 'append_shipping_override_summary_to_cart_checkout_block' ), 10, 2 );

        // Save shipping override discount data to coupon order item meta.
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_shipping_discounts_to_coupon_order_item' ), 10, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'save_shipping_discounts_to_coupon_order_item_via_store_api' ), 10, 1 );

        // Admin: selectable options for coupon editor.
        add_filter( 'acfw_shipping_override_selectable_options', array( $this, 'populate_selectable_options' ), 10, 1 );

        // Backward compat: recalculate shipping total for old orders with fee-based discounts.
        add_action( 'admin_init', array( $this, 'recalculate_shipping_total_with_discount' ), 10 );
    }
}
