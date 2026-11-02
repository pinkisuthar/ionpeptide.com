<?php
namespace WDRPro\App\Rules;
if (!defined('ABSPATH')) {
    exit;
}

use Wdr\App\Helpers\Woocommerce;
use Wdr\App\Controllers\Configuration;
use WDRPro\App\Helpers\CoreMethodCheck;

class Set
{
    public static $set_discount_count = array(), $set_discounts = array();

    /**
     * Initialize
     * */
    public static function init()
    {
        self::hooks();
    }

    /**
     * Add hooks
     * */
    protected static function hooks(){
        add_filter('advanced_woo_discount_rules_has_any_discount', array(__CLASS__, 'hasAdjustment'), 10, 2);
        add_filter('advanced_woo_discount_rules_discounts_of_each_rule', array(__CLASS__, 'setDiscountValue'), 10, 10);
        add_filter('advanced_woo_discount_rules_bulk_table_range_based_on_rule', array(__CLASS__, 'setDiscountTable'), 10, 5);
        add_filter('advanced_woo_discount_rules_advance_table_based_on_rule', array(__CLASS__, 'addAdvanceTable'), 10, 6);
        add_filter('advanced_woo_discount_rules_apply_the_discount_as_fee_in_cart', array(__CLASS__, 'applyDiscountAsFee'), 10, 2);
        add_filter('advanced_woo_discount_rules_fee_values', array(__CLASS__, 'buildFeeDetails'), 10, 5);
        add_filter('advanced_woo_discount_rules_calculated_discounts_of_each_rule', array(__CLASS__, 'setCalculatedDiscountValue'), 10, 8);
        add_filter('advanced_woo_discount_rules_calculated_discounts_of_each_rule_for_ajax_price', array(__CLASS__, 'setCalculatedDiscountValue'), 10, 8);
        add_filter('advanced_woo_discount_rules_is_rule_passed_with_out_discount_for_exclusive_rule', array(__CLASS__, 'setExclusiveRule'), 10, 4);
    }

    /**
     * Add advance table / Discount Badge
     * */
    public static function addAdvanceTable($advanced_layout, $rule, $discount_calculator, $product, $product_price, $html_content){
        if ($rule->isFilterPassed($product, true) && !empty($html_content)) {
            $has_set_discount = self::hasDiscount($rule->rule);
            if ($has_set_discount) {
                $discounted_title_text = $rule->getTitle();
                $set_adjustments = self::getAdjustments($rule);
                if (isset($set_adjustments) && !empty($set_adjustments) && isset($set_adjustments->ranges) && !empty($set_adjustments->ranges)) {
                    foreach ($set_adjustments->ranges as $range) {
                        if (isset($range->value) && !empty($range->value)) {
                            $min = intval(isset($range->from) ? $range->from : 0);
                            if (empty($min)) {
                                continue;
                            } else {
                                $discount_method = "set_discount";
                                $discount_type = isset($range->type)? $range->type: 'fixed_set_price';
                                $discount_price = $rule->calculator($discount_type, $product_price, $range->value);
                                $value = (isset($range->value) && !empty($range->value)) ? $range->value : 0;
                                $badge_bg_color = $rule->getAdvancedDiscountMessage('badge_color_picker', '#ffffff');
                                $badge_text_color = $rule->getAdvancedDiscountMessage('badge_text_color_picker', '#000000');
                                self::getDiscountBadgeText($advanced_layout, $rule, $discount_type, $discount_method, $product_price, $value, $discount_price, $discounted_title_text, $html_content, $badge_bg_color, $badge_text_color, $min);
                            }
                        }
                    }
                }
            }
        }

        return $advanced_layout;
    }

    /**
     * Get discount badge text
     * */
    protected static function getDiscountBadgeText(&$advanced_layout, $rule, $type, $discount_method, $product_price, $value, $discount_price, $discounted_title_text, $html_content, $badge_bg_color, $badge_text_color, $min = 0, $max = 0)
    {
        $discount_text = '';
        $discounted_price_text = '';
        $save_amount = '';
        switch ($type) {
            case 'flat':
                if (!empty($value)) {
                    $value = CoreMethodCheck::getConvertedFixedPrice($value, 'flat');
                    $discount = $product_price - $value;
                    $discount_text = Woocommerce::formatPrice($value);
                    $discounted_price_text = Woocommerce::formatPrice($discount);
                    $save_amount = Woocommerce::formatPrice($discount_price);
                }
                break;
            case 'fixed_set_price':
                if (!empty($value) && !empty($min)) {
                    $value = CoreMethodCheck::getConvertedFixedPrice($value, 'fixed_set_price');
                    $discounted_price = 0;
                    if($min > 0){
                        $discounted_price = $value / $min;
                    }
                    $discount = $product_price - $discounted_price;
                    $discount_text = Woocommerce::formatPrice($value);
                    $discounted_price_text = Woocommerce::formatPrice($discount);
                    $save_amount = Woocommerce::formatPrice($discount);
                }
                break;
            case 'percentage':
                if (!empty($value) && !empty($discount_price)) {
                    $discount = $product_price - $discount_price;
                    $discount_text = $value . '%';
                    $discounted_price_text = Woocommerce::formatPrice($discount);
                    $save_amount = Woocommerce::formatPrice($discount_price);
                }
                break;
        }
        if (!empty($discount_text) && !empty($discounted_price_text)) {
            $dont_allow_duplicate = true;
            $searchForReplace = array('{{title}}', '{{min_quantity}}', '{{discount}}', '{{discounted_price}}','{{save_amount}}');
            $string_to_replace = array($discounted_title_text, $min, $discount_text, $discounted_price_text, $save_amount);
            $html_content = str_replace($searchForReplace, $string_to_replace, $html_content);
            $searchForRemove = array('/{{max_quantity}}/');
            $replacements = array('');
            $html_content = preg_replace($searchForRemove, $replacements, $html_content);
            if (!empty($advanced_layout)) {
                foreach ($advanced_layout as $layout_options) {
                    $check_exists = array($layout_options['badge_text']);
                    if (in_array($html_content, $check_exists)) {
                        $dont_allow_duplicate = false;
                        break;
                    }
                }
            }
            if ($dont_allow_duplicate) {
                $advanced_layout[] = array(
                    'badge_bg_color' => $badge_bg_color,
                    'badge_text_color' => $badge_text_color,
                    'badge_text' => $html_content,
                    'rule_id' => $rule->rule->id,
                );
            }
        }
    }

    /**
     * load set discount table
     * @param $response_ranges
     * @param $rule
     * @param $discount_calculator
     * @param $product
     * @param $product_price
     * @return mixed
     */
    public static function setDiscountTable($response_ranges, $rule, $discount_calculator, $product, $product_price){
        $has_set_discount = self::hasDiscount($rule->rule);
        if ($has_set_discount) {
            if ($rule->isFilterPassed($product) && ($rule->isEnabled())) {
                $set_adjustments = self::getAdjustments($rule);
                $rule_id = $rule->getId();
                if (isset($set_adjustments) && !empty($set_adjustments) && isset($set_adjustments->ranges) && !empty($set_adjustments->ranges)) {
                    foreach ($set_adjustments->ranges as $range) {
                        if (isset($range->value) && !empty($range->value)) {
                            $discount_type = (isset($range->type) && !empty($range->type)) ? $range->type : 0;
                            $from = intval(isset($range->from) ? $range->from : 0);
                            if (empty($from) || empty($discount_type)) {
                                continue;
                            } else {
                                $core_config = new Configuration();
                                $calculate_discount_from = $core_config->getConfig('calculate_discount_from', 'sale_price');
                                if ($discount_type == 'fixed_set_price') {
                                    $range->value = CoreMethodCheck::getConvertedFixedPrice($range->value, 'fixed_set_price');
                                    $discount_price = $range->value;
                                    $discounted_price = 0;
                                    if($from > 0){
                                        $discounted_price = $range->value / $from;
                                    }
                                } else {
                                    $discount_price = $rule->calculator($discount_type, $product_price, $range->value);
                                    $discounted_price = $product_price - $discount_price;
                                }

                                $discounted_price = $discount_calculator->mayHaveTax($product, $discounted_price);

                                if ($discounted_price < 0) {
                                    $discounted_price = 0;
                                }
                                $rule_title = isset($range->label) && !empty($range->label) ? $range->label : $rule->getTitle();
                                $discount_value = $range->value;
                                $discount_method = 'set';
                                $to = '';
                                $discount_calculator->defaultLayoutRowDataFormation($response_ranges, $from, $to, $rule_id, $discount_method, $discount_type, $discount_value, $discount_price, $discounted_price, $rule_title);
                            }
                        }
                    }
                }
            }
        }
        return $response_ranges;
    }

    /**
     * set discount value
     * @param $discounts
     * @param $rule
     * @param $product_price
     * @param $quantity
     * @param $product
     * @param $ajax_price
     * @param $cart_item
     * @param $price_display_condition
     * @param bool $is_cart
     * @param bool $manual_request
     * @return mixed
     */
    public static function setDiscountValue($discounts, $rule, $product_price, $quantity, $product, $ajax_price, $cart_item, $price_display_condition, $is_cart, $manual_request = false){
        $set_discount = 0;
        if (self::hasDiscount($rule->rule)) {
            $set_discount = self::calculateDiscount($rule, $product_price, $quantity, $product, $ajax_price, $cart_item, $price_display_condition, $is_cart, $manual_request);
        }

        $discounts['product_set_discount'] = $set_discount;

        return $discounts;
    }

    /**
     * Calculate Buy X Get Y Discount
     *
     * @param $rule
     * @param $price
     * @param $quantity
     * @param $product
     * @param $ajax_price
     * @return mixed
     * */
    public static function calculateDiscount($rule, $price, $quantity, $product, $ajax_price = false, $cart_item = array(), $price_display_condition = 'show_when_matched', $is_cart = false, $manual_request = false){
        $return_value = 0;
        if ( $set_discount_data = self::getAdjustments($rule) ) {
            $operator = (isset($set_discount_data->operator) && !empty($set_discount_data->operator)) ? $set_discount_data->operator : false;
            $set_ranges = (isset($set_discount_data->ranges) && !empty($set_discount_data->ranges)) ? $set_discount_data->ranges : false;
            if (empty($operator) || empty($set_ranges)  || empty($quantity) || empty($price)) {
                return 0;
            }

            $discount_per_item_for_all_range = $eligible_quantities = $total_qty_in_cart = $completed_quantity = $valid_ranges = 0;
            $rule_id = isset($rule->rule->id)? $rule->rule->id : null;
            $product_id = Woocommerce::getProductId($product);
            $cart_item_key = isset($cart_item['key']) ? $cart_item['key'] : 0;
            $current_product_qty = $quantity;
            $current_product_parent_id = Woocommerce::getProductParentId($product);
            $process_reached = true;
            $valid_ranges = self::getMatchedDiscount($rule, $product, $price, $operator, $set_ranges, $quantity, $ajax_price, $price_display_condition, $is_cart, $manual_request);
            if (empty($valid_ranges)) {
                return 0;
            }
            $max_quantity = (isset($valid_ranges->from) && !empty($valid_ranges->from)) ? $valid_ranges->from : 0;

            //-----Get Quantity of product in position----------
            // changed by Balakrishnan.D
            if($operator == "variation"){
                if(!empty($current_product_parent_id)) {
                    if (!isset(self::$set_discount_count[$rule_id][$operator][$current_product_parent_id][$cart_item_key])) {
                        self::$set_discount_count[$rule_id][$operator][$current_product_parent_id][$cart_item_key]['qty'] = $quantity;
                    }
                    $calc_completed_qtys = self::$set_discount_count[$rule_id][$operator][$current_product_parent_id];
                    if(!empty($calc_completed_qtys)){
                        $completed_quantity = $pr = $pr_key = 0; //$pr -> to find process reached
                        foreach ($calc_completed_qtys as $key => $calc_completed_qty){
                            if($is_cart === true && $key === 0) continue;
                            if($pr == 0){
                               $pr_key = $key;
                            }
                            $this_product_qty = (isset($calc_completed_qty['qty'])) ? $calc_completed_qty['qty'] : 0;
                            $completed_quantity += $this_product_qty;
                            $pr++;
                            if($key == $cart_item_key){
                                break;
                            }
                        }
                        if ($max_quantity > $completed_quantity || $pr_key == $cart_item_key) {
                            self::$set_discount_count[$rule_id][$operator][$current_product_parent_id]['process'] = '';
                            if($max_quantity == $completed_quantity || $max_quantity < $completed_quantity ){
                                self::$set_discount_count[$rule_id][$operator][$current_product_parent_id]['process'] = true;
                            }
                            $process_reached = true;
                        }else if ($max_quantity <= $completed_quantity){
                            $process_reached = (self::$set_discount_count[$rule_id][$operator][$current_product_parent_id]['process'] == true) ? false : true;
                            self::$set_discount_count[$rule_id][$operator][$current_product_parent_id]['process'] = true;
                        }else{
                            $process_reached = false;
                        }
                    }
                }else{
                    $operator = "product";
                }
            }
            if ($operator == "product_cumulative") {
                if (!isset(self::$set_discount_count[$rule_id][$operator][$cart_item_key])) {
                    self::$set_discount_count[$rule_id][$operator][$cart_item_key]['qty'] = $quantity;
                }
                $calc_completed_qtys = self::$set_discount_count[$rule_id][$operator];
                if(!empty($calc_completed_qtys)){
                    $completed_quantity = $pr = $pr_key = 0; //$pr -> to find process reached
                    foreach ($calc_completed_qtys as $key => $calc_completed_qty){
                        if($is_cart === true && $key === 0) continue;
                        if($pr == 0){
                            $pr_key = $key;
                        }
                        $this_product_qty = (isset($calc_completed_qty['qty'])) ? $calc_completed_qty['qty'] : 0;
                        $completed_quantity += $this_product_qty;
                        $pr++;
                        if($key == $cart_item_key){
                            break;
                        }
                    }

                    if ($max_quantity > $completed_quantity || $pr_key == $cart_item_key) {
                        self::$set_discount_count[$rule_id][$operator]['process'] = '';
                        if($max_quantity == $completed_quantity || $max_quantity < $completed_quantity ){
                            self::$set_discount_count[$rule_id][$operator]['process'] = true;
                        }
                        $process_reached = true;
                    }else if ($max_quantity <= $completed_quantity){
                        $process_reached = (self::$set_discount_count[$rule_id][$operator]['process'] == true) ? false : true;
                        self::$set_discount_count[$rule_id][$operator]['process'] = true;
                    }else{
                        $process_reached = false;
                    }
                }
            }

            if(!$is_cart){
                $completed_quantity = $max_quantity;
                $process_reached = true;
            }
            $value = isset($valid_ranges->value) ? $valid_ranges->value : 0;
            $discount_type = isset($valid_ranges->type) ? $valid_ranges->type : 0;
            if ($value < 0 || empty($max_quantity)) return 0;
            /**
             * Set discount calculator
             */
            if ($discount_type == 'fixed_set_price') {
                $value = CoreMethodCheck::getConvertedFixedPrice($value, 'fixed_set_price');
                /**
                 * this for fixed_set_price discount calculation
                 */
                if ($operator == "product_cumulative" || $operator == "variation") {
                    if (isset($completed_quantity) && $max_quantity <= $completed_quantity && $process_reached) {

                        if ($completed_quantity == $max_quantity) {
                            $per_product_price = 0;
                            if($max_quantity > 0){
                                $per_product_price = $value / $max_quantity;
                            }
                            $per_product_price = Woocommerce::round($per_product_price);
                            $price_difference = Woocommerce::round($value - ($per_product_price * $max_quantity));
                            $per_product_price += $price_difference / $current_product_qty;
                            $discount_per_item_for_all_range = $price - $per_product_price;
                            $total_qty_in_cart_discounted_price = $discount_per_item_for_all_range;
                        } elseif ($completed_quantity > $max_quantity) {
                            $per_product_price = 0;
                            if($max_quantity > 0){
                                $per_product_price = $value / $max_quantity;
                            }
                            $original_price_qty = $completed_quantity - $max_quantity;
                            $discount_price_qty = $quantity - $original_price_qty;
                            $per_product_price = Woocommerce::round($per_product_price);
                            $price_difference = Woocommerce::round($value - ($per_product_price * $max_quantity));
                            $per_product_price += $price_difference / $discount_price_qty;
                            $discount_per_item_for_all_range = $price - $per_product_price;
                            $discounted_prices = $discount_price_qty * $discount_per_item_for_all_range;
                            if ($discounted_prices <= 0) {
                                return 0;
                            }

                            return self::calculatePriceForExtraSetQuantities($product_id, $quantity, $discount_price_qty, $price, $discount_per_item_for_all_range, $discounted_prices, $operator, $current_product_qty, $discount_type);
                        }
                    } elseif (isset($completed_quantity) && $completed_quantity < $max_quantity) {
                        $per_product_price = 0;
                        if($max_quantity > 0){
                            $per_product_price = $value / $max_quantity;
                        }
                        $per_product_price = Woocommerce::round($per_product_price);
                        $discount_per_item_for_all_range = $price - $per_product_price;
                        $total_qty_in_cart_discounted_price = $discount_per_item_for_all_range;
                    }
                } else {
                    $discounted_qty_original_prices = $max_quantity * $price;
                    $discount_for_set = $discounted_qty_original_prices - $value;
                    $discount_per_item_for_all_range = 0;
                    if($max_quantity > 0){
                        $discount_per_item_for_all_range = $discount_for_set / $max_quantity;
                    }
                    if ($max_quantity < $quantity) {
                        return self::calculatePriceForExtraSetQuantities($product_id, $quantity, $max_quantity, $price, $discount_per_item_for_all_range, $value, $operator, $current_product_qty, $discount_type);
                    }
                }
            } else {
                /**
                 * this for percentage and flat discount calculation
                 */
                if ($operator == "product_cumulative" || $operator == "variation") {
                    if (isset($completed_quantity) && $max_quantity <= $completed_quantity && $process_reached) {
                        if (isset($completed_quantity) && $completed_quantity == $max_quantity) {
                            $total_qty_in_cart_discounted_price = $rule->calculator($discount_type, $price, $value);
                            $discount_per_item_for_all_range = $total_qty_in_cart_discounted_price;
                        } elseif (isset($completed_quantity) && $completed_quantity > $max_quantity) {
                            $discount_per_item_for_all_range = $rule->calculator($discount_type, $price, $value);
                            $original_price_qty = $completed_quantity - $max_quantity;
                            $discount_price_qty = $quantity - $original_price_qty;
                            $discounted_prices = $discount_price_qty * $discount_per_item_for_all_range;
                            if ($discounted_prices <= 0) {
                                return 0;
                            }
                            return self::calculatePriceForExtraSetQuantities($product_id, $quantity, $discount_price_qty, $price, $discount_per_item_for_all_range, $discounted_prices, $operator, $current_product_qty, $discount_type);
                        }
                    } else if (isset($completed_quantity) && $completed_quantity < $max_quantity) {
                        $total_qty_in_cart_discounted_price = $rule->calculator($discount_type, $price, $value);
                        $discount_per_item_for_all_range = $total_qty_in_cart_discounted_price;
                    }
                } else {
                    $discount_per_item_for_all_range = $rule->calculator($discount_type, $price, $value);
                    if ($discount_per_item_for_all_range <= 0) {
                        return 0;
                    }
                    if ($max_quantity < $quantity) {
                        $discounted_price = $price - $discount_per_item_for_all_range;
                        $discounted_prices = $max_quantity * $discounted_price;
                        return self::calculatePriceForExtraSetQuantities($product_id, $quantity, $max_quantity, $price, $discount_per_item_for_all_range, $discounted_prices, $operator, $current_product_qty, $discount_type);
                    }
                }
            }
            if (empty($total_qty_in_cart_discounted_price)) {
                $discounted_price = $discount_per_item_for_all_range * $max_quantity;
            } else {
                $discounted_price = $total_qty_in_cart_discounted_price;
            }
            self::$set_discounts[$product_id] = array(
                'original_price_quantity' => 0,
                'discounted_price_quantity' => 0,
                'original_price' => $price,
                'discounted_price' => $discounted_price,
                'discount_value' => $discount_per_item_for_all_range,
                'total_quantity' => $quantity,
                'current_product_qty' => $current_product_qty,
                'discount_operator' => $operator,
                'discount_quantity' => $current_product_qty,
                'discount_price' => $discount_per_item_for_all_range,
                'discount_type' => apply_filters('advanced_woo_discount_rules_select_set_discount_type', 'flat', $discount_type),
            );
            return $discount_per_item_for_all_range;
        }

        return $return_value;
    }

    /**
     * calculate price for extra quantities in set
     * @param $product_id
     * @param $quantity
     * @param $max_quantity
     * @param $price
     * @param $discount_per_item_for_all_range
     * @param $discounted_prices
     * @param $discount_operator
     * @param $current_product_qty
     * @return float|int
     */
    protected static function calculatePriceForExtraSetQuantities($product_id, $quantity, $max_quantity, $price, $discount_per_item_for_all_range, $discounted_prices, $discount_operator, $current_product_qty, $discount_type = 'flat')
    {
        if ($quantity > $max_quantity) {
            //Calculating number of items having original price
            $original_price_quantity = $quantity - $max_quantity;
            //Calculating original prices of all non-discount quantities
            $original_prices = $original_price_quantity * $price;
            //Calculating discounted price of item
            $discounted_price = $price - $discount_per_item_for_all_range;
            //Calculating discounted prices per item
            $discounted_prices = $max_quantity * $discounted_price;
            $discounted_price_per_item = 0;
            if($quantity > 0){
                $discounted_price_per_item = (($original_prices + $discounted_prices) / $quantity);
            }
            //Calculating discount price per item
            $discount_per_item = $price - $discounted_price_per_item;
            if ($discount_per_item < 0) {
                return 0;
            }
            self::$set_discounts[$product_id] = array(
                'original_price_quantity' => $original_price_quantity,
                'discounted_price_quantity' => $max_quantity,
                'original_price' => $price,
                'discounted_price' => $discounted_price,
                'discount_value' => $discount_per_item_for_all_range,
                'total_quantity' => $quantity,
                'current_product_qty' => $current_product_qty,
                'discount_operator' => $discount_operator,
                'discount_quantity' => $max_quantity,
                'discount_price' => $discount_per_item_for_all_range,
                'discount_type' => apply_filters('advanced_woo_discount_rules_select_set_discount_type', 'flat', $discount_type),
            );
            return $discount_per_item;
        }
        return 0;
    }

    public static function setCalculatedDiscountValue($total_discounts, $product_id, $rule_id, $filter_passed, $cart_item, $is_cart, $rule = false, $manual_request = false){
        if(self::getAdjustments($rule)){
            if(!empty(self::$set_discounts)){
                $set_discounts = self::$set_discounts;
                $total_discounts['set_discount'] = isset($set_discounts[$product_id]) ? $set_discounts[$product_id] : 0;
            }
        }
        return $total_discounts;
    }

    /**
     * get the matched bulk discount (& set discount) row's value
     * @param $operator
     * @param $ranges
     * @param $quantity
     * @param $product
     * @param $price
     * @param $ajax_price
     * @return float|int
     */
    protected static function getMatchedDiscount($rule, $product, $price, $operator, $ranges, $quantity, $ajax_price = false, $price_display_condition = 'show_when_matched', $is_cart = false, $manual_request = false)
    {
        if (empty($ranges)) {
            return 0;
        }
        $cart_items = Woocommerce::getCart();
        if (!$manual_request) {
            if ($price_display_condition == "show_when_matched" && !$is_cart) {
                $quantity = 1;
            } else if ($price_display_condition == "show_after_matched" || $is_cart) {
                $quantity = 0;
            } else {
                $quantity = isset($_POST['qty']) ? floatval(wp_unslash($_POST['qty'])) : 1;//phpcs:ignore WordPress.Security.NonceVerification.Missing -- public frontend price display, read-only
            }
        }

        switch ($operator) {
            case 'product_cumulative':
                $quantity += $rule->getProductCumulativeDiscountQuantity($cart_items);
                break;
            case 'variation':
                $quantity += $rule->getProductVariationDiscountQuantity($product, $cart_items);
                break;
            default:
            case 'product':
                $product_id = Woocommerce::getProductId($product);
                if(!empty($cart_items)){
                    foreach ($cart_items as $cart_item){
                        $cart_item_product_id = Woocommerce::getProductIdFromCartItem($cart_item);
                        if($cart_item_product_id == $product_id){
                            $quantity += isset($cart_item['quantity']) ? $cart_item['quantity'] : 0;
                        }
                    }
                }
                break;
        }
        if (empty($quantity)) {
            return 0;
        }
        $selected_bundle_range = self::getSetDiscountFromRanges($ranges, $quantity, $ajax_price, $is_cart);
        /**
         * Bundle set discount recursive option
         * @since v2.3.6
         * @author balakrishnan
         */
        if(is_object($selected_bundle_range) && isset($selected_bundle_range->recursive) &&  isset($selected_bundle_range->from) && $selected_bundle_range->recursive == 1){
            $range_start = intval($selected_bundle_range->from);
            $value = isset($selected_bundle_range->value) ? $selected_bundle_range->value : 0;
            $recursive_count = 0;
            if($range_start > 0){
                $recursive_count = intval($quantity/$range_start);
            }
            $selected_bundle_range->from =  $recursive_count* $range_start;
            if(isset($selected_bundle_range->type) && $selected_bundle_range->type == 'fixed_set_price'){
                $recursive_value = $recursive_count * $value;
                $selected_bundle_range->value = apply_filters('advanced_woo_discount_rules_bundle_set_recursive_fixed_set_value', $recursive_value, $recursive_count, $value);
            }
        }

        return apply_filters('advanced_woo_discount_rules_matched_set_discount_range', $selected_bundle_range, $product, $rule, $cart_items, $is_cart);
    }

    /**
     * get discount range for set discount
     * @param $ranges
     * @param $quantity
     * @param $ajax_price
     * @return array|bool|mixed
     */
    protected static function getSetDiscountFromRanges($ranges, $quantity, $ajax_price, $is_cart)
    {

        $fully_matched = $partially_matched = $qualified_range = array();
        foreach ($ranges as $key => $range) {
            $max_quantity = (isset($range->from) && !empty($range->from)) ? $range->from : 0;
            if ($quantity == $max_quantity) {
                $fully_matched[$key] = $max_quantity;
            } else if ($quantity >= $max_quantity) {
                $partially_matched[$key] = $max_quantity;
            }
        }
        if (empty($fully_matched)) {
            if (empty($partially_matched)) {
                return array();
            }
            $qualified_range = $partially_matched;
            $matched_range = array_keys($qualified_range, max($qualified_range));
            if( $ajax_price || !$is_cart){
                $matched_range_key = isset($matched_range[0]) ? $matched_range[0] : NULL;
                $range = isset($ranges->$matched_range_key) ? $ranges->$matched_range_key : array();
                $bundle_recursive_quantity = (isset($range->from) && !empty($range->from)) ? $range->from : 0 ;
                $recursive_step = 0;
                if($bundle_recursive_quantity > 0){
                    $recursive_step = $quantity / $bundle_recursive_quantity;
                }
                if(!is_int($recursive_step)){
                    return array();
                }
                $actual_quantity_to_be = $recursive_step * $bundle_recursive_quantity;
                if($actual_quantity_to_be != $quantity){
                    return array();
                }
            }
        } else {
            $qualified_range = $fully_matched;
            $matched_range = array_keys($qualified_range, min($qualified_range));
        }
        if (empty($qualified_range)) {
            return array();
        }
        $matched_range_key = isset($matched_range[0]) ? $matched_range[0] : NULL;
        $range = isset($ranges->$matched_range_key) ? $ranges->$matched_range_key : array();
        return $range;
    }

    /**
     * check the rule has set discount
     * @return bool
     */
    public static function hasDiscount($rule)
    {
        if (isset($rule->set_adjustments)) {
            if (!empty($rule->set_adjustments) && $rule->set_adjustments != '{}' && $rule->set_adjustments != '[]') {
                return true;
            }
        }
        return false;
    }

    /**
     * Register having Set discount
     *
     * @param $has_additional_rules boolean
     * @param $rule object
     *
     * @return boolean
     * */
    public static function hasAdjustment($has_additional_rules, $rule){
        $has_rule = self::hasDiscount($rule->rule);
        if($has_rule){
            $has_additional_rules = true;
        }

        return $has_additional_rules;
    }

    /**
     * Get adjustments
     */
    public static function getAdjustments($rule){
        if(isset($rule->rule) && isset($rule->rule->discount_type)){
            if($rule->rule->discount_type == 'wdr_set_discount'){
                if (self::hasDiscount($rule->rule)) {
                    return json_decode($rule->rule->set_adjustments);
                }
            }
        }
        return false;
    }

    /**
     * check apply discount as fee for set discount
     * @param $result
     * @param $rule
     * @return bool
     */
    public static function applyDiscountAsFee($result, $rule){
        $set_rule = self::getAdjustments($rule);
        if($set_rule && is_object($set_rule) && isset($set_rule->apply_as_cart_rule) && !empty($set_rule->apply_as_cart_rule)){
            return true;
        }
        return $result;
    }

    /**
     * build array for apply discount as fee type
     * @param $price_as_cart_discount
     * @param $rule
     * @param $cart_discounted_price
     * @param $product_id
     * @param $cart_item
     * @return array
     */
    public static function buildFeeDetails(array $price_as_cart_discount, $rule, $cart_discounted_price, $product_id, $cart_item){
        $set_discount = self::getAdjustments($rule);
        $rule_id = isset($rule->rule->id) ? $rule->rule->id : '';
        $product_set_discount = self::$set_discounts[$product_id];
        if( $set_discount && isset($set_discount->apply_as_cart_rule) && !empty($set_discount->apply_as_cart_rule) && !empty($cart_item)){
            $price_as_cart_discount[$rule_id][$product_id] = array(
                'discount_type' => 'wdr_set_discount',
                'apply_type' => isset($product_set_discount['discount_type']) ? $product_set_discount['discount_type'] : '',
                'discount_label' => wp_unslash($set_discount->cart_label),
                'discount_value' => isset($product_set_discount['discount_value']) ? $product_set_discount['discount_value'] : 0,
                'discounted_price' => $cart_discounted_price,
                'rule_name' => isset($rule->rule->title) ? $rule->rule->title : '',
                'cart_item_key' => isset($cart_item['key']) ? $cart_item['key'] : '',
                'product_id' => Woocommerce::getProductId($cart_item['data']),
                'rule_id' => $rule_id,
            );
            return $price_as_cart_discount;
        }
        return array();
    }

    /**
     * Check is Exclusive Rule
     *
     * @param $result
     * @param $product
     * @param $rule
     * @param $cart_item
     * @return bool
     */
    public static function setExclusiveRule($result, $product, $rule, $cart_item){
        if($rule->rule->discount_type != 'wdr_set_discount'){
            return $result;
        }
        //calculate quantity from 0
        $price_display_condition = 'show_after_matched';
        //calculate quantity in getMatchedDiscount function. this is initialization quantity
        $quantity = $cart_item['quantity'];
        // Not need for check exclusive
        $price = NULL;
        $set_discount_data = self::getAdjustments($rule);
        $operator = (isset($set_discount_data->operator) && !empty($set_discount_data->operator)) ? $set_discount_data->operator : false;
        $set_ranges = (isset($set_discount_data->ranges) && !empty($set_discount_data->ranges)) ? $set_discount_data->ranges : false;
        if (empty($operator) || empty($set_ranges)  || empty($quantity)) {
            return $result;
        }

        if ($rule->isFilterPassed($product)) {
            $cart = Woocommerce::getCart();
            if ($rule->hasConditions()) {
                if ($rule->isCartConditionsPassed($cart)) {
                    $valid_ranges = self::getMatchedDiscount($rule, $product, $price, $operator, $set_ranges, $quantity, false, $price_display_condition, true);
                    if (!empty($valid_ranges)) {
                        $result = true;
                    }
                }
            } else {
                $valid_ranges = self::getMatchedDiscount($rule, $product, $price, $operator, $set_ranges, $quantity, false, $price_display_condition, true);
                if (!empty($valid_ranges)) {
                    $result = true;
                }
            }
        }
        return $result;
    }
}
Set::init();