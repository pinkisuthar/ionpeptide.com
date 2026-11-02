<?php

namespace WDRPro\App\Rules;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Helpers\Woocommerce;
use WDRPro\App\Helpers\CoreMethodCheck;

class Cheapest
{
    public static $cart_item_discount_products = array();

    public static $discountable_items = array('any_item_in_cart' => false, 'products' => array(), 'categories' => array());

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
    protected static function hooks(){}

    /**
     * Get free product quantity for recursive range
     *
     * @param $range_start int
     * @param $cart_quantity int
     * @param $free_quantity int
     * @param $increment int
     * @return int
     * */
    /*public static function getRecursiveQuantityOld($range_start, $cart_quantity, $free_quantity, $increment = 1){
        if($cart_quantity < $range_start){
            return $free_quantity*(--$increment);
        } else {
            $range_start = $range_start/$increment;
            $increment++;
            $range_start *= $increment;
            return self::getRecursiveQuantity($range_start, $cart_quantity, $free_quantity, $increment);
        }
    }*/

    /**
     * Get free product quantity for recursive range
     *
     * @param $range_start int
     * @param $cart_quantity int
     * @param $free_quantity int
     * @param $increment int
     * @return int
     * */
    public static function getRecursiveQuantity($range_start, $cart_quantity, $free_quantity, $increment = 1){
        if($cart_quantity < $range_start){
            return $free_quantity;
        } else {
            $customized_range_start = 0;
            if($range_start > 0){
                $customized_range_start = (int)($cart_quantity/$range_start);
            }
            $free_quantity = $customized_range_start * $free_quantity;
            return $free_quantity;
        }
    }

    /**
     * Calculate discount price from rule range
     *
     * @param $matched_rule object
     * @param $price int/float
     * @param $discount_quantity int
     * @param $quantity int
     * @param $product object
     * @param $cart_item array
     *
     * @return int/float
     * */
    public static function calculateDiscountPriceFromRuleRange($matched_rule, $price, $discount_quantity, $quantity, $product){
        $discount_price = 0;
        if(!empty($matched_rule)){
            if(!empty($matched_rule->free_type)){
                if($matched_rule->free_type == "percentage"){
                    if($matched_rule->free_value > 0){
                        $discount_value = self::getDiscountValueFromRule($matched_rule, $price);
                        $discount_price = self::getDiscountPriceForProductFromQuantityBasedPercentageDiscount($product, $price, $quantity, $discount_value, $discount_quantity);
                    }
                } else if($matched_rule->free_type == "flat"){
                    if($matched_rule->free_value > 0){
                        $discount_value = self::getDiscountValueFromRule($matched_rule, $price);
                        $discount_price = self::getDiscountPriceForProductFromQuantityBasedFlatDiscount($product, $price, $quantity, $discount_value, $discount_quantity);
                    }
                }
            }

        }

        return $discount_price;
    }

    /**
     * Get discount value from matched rule
     *
     * @param $matched_rule object
     * @param $price int/float
     * @return int/float
     */
    public static function getDiscountValueFromRule($matched_rule, $price)
    {
        $discount_value = 0;
        if(!empty($matched_rule)){
            if(!empty($matched_rule->free_type)){
                if($matched_rule->free_type == "percentage"){
                    if($matched_rule->free_value > 0){
                        $discount_value = $matched_rule->free_value;
                        if ($discount_value > 100) {
                            $discount_value = 100;
                        }
                    }
                } else if($matched_rule->free_type == "flat"){
                    if($matched_rule->free_value > 0){
                        $discount_value = CoreMethodCheck::getConvertedFixedPrice($matched_rule->free_value, 'flat');
                        if ($discount_value > $price) {
                            $discount_value = $price;
                        }
                    }
                }
            }
        }

        return $discount_value;
    }

    /**
     * Calculate discount price from rule range
     *
     * @param $matched_rule object
     * @param $price int/float
     *
     * @return int/float
     * */
    public static function calculateDiscountPricePerQuantity($matched_rule, $price){
        $discount_amount_per_product = 0;
        if(!empty($matched_rule)){
            if(!empty($matched_rule->free_type)){
                if($matched_rule->free_type == "percentage"){
                    if($matched_rule->free_value > 0){
                        $discount_amount_per_product = ($matched_rule->free_value / 100) * floatval($price);
                    }
                } else if($matched_rule->free_type == "flat"){
                    if($matched_rule->free_value > 0){
                        $free_value = CoreMethodCheck::getConvertedFixedPrice($matched_rule->free_value, 'flat');
                        $discount_amount_per_product = $free_value;
                    }
                }
            }

        }

        return $discount_amount_per_product;
    }

    /**
     * Get quantity to apply from cheapest item
     *
     * @param $cart_item_key string
     * @param $cheapest_items array
     *
     * @return integer
     * */
    public static function getQuantityToApplyDiscountForCartItemKey($cart_item_key, $cheapest_items){
        if(!empty($cheapest_items)){
            foreach ($cheapest_items as $cheapest_item){
                if($cart_item_key == $cheapest_item['cart_item_key']){
                    return $cheapest_item['cart_item_quantity_to_apply'];
                }
            }
        }

        return 0;
    }

    public static function getAppliedQty($cart_key, $rule_id, $product_page_data){
        $qty = 0;
        $cheapest_items = $product_page_data['already_applied'];
        if(!empty($cheapest_items)){
            foreach ($cheapest_items as $key => $cheapest_item){
                if(!empty($cheapest_item)){
                    foreach ($cheapest_item as $item){
                        if($item['cart_item_key'] == $cart_key && $item['rule_id'] == $rule_id){
                            $qty += $item['cart_item_quantity_to_apply'];
                        }
                    }
                }
            }
        }

        return $qty;
    }

    /**
     * Get Cheapest item from cart
     *
     * @param $rule object
     * @param $matched_rule object
     * @param $discount_quantity int
     * @param $condition string
     * @param $type_and_values array
     * @param $cheapest_items array
     * @param $exclude_cart_item_keys array
     *
     * @return array
     * */
    public static function getCheapestItemsFromCart($rule, $matched_rule, $discount_quantity, $condition = 'cheapest', $type_and_values = array(), $cheapest_items = array(), $exclude_cart_item_keys = array(), $is_cart = true, $product_page_data = array()){
        if($discount_quantity <= 0 ) return $cheapest_items;
        $cart = Woocommerce::getCart();
        $cheapest_price = null;
        $cheapest_item = array();
        if(!empty($cart)){
            foreach ($cart as $key_value => $item){
                $key = $item['key'];
                if(!in_array($key, $exclude_cart_item_keys)){
                    if(apply_filters('advanced_woo_discount_rules_process_cart_item_for_cheapest_rule', true, $item, $rule)){
                        if(self::validateCartItemBasedOnType($item['data'], $type_and_values, $rule, $item)){
                            $cart_item_product = $item['data'];
                            $current_item_price = Woocommerce::getProductPrice($cart_item_product);
                            $current_item_price = apply_filters('advanced_woo_discount_rules_get_price_of_cart_item_on_find_cheapest_item', $current_item_price, $cart_item_product, $item);
                            if($cheapest_price === null || self::isConditionMatched($condition, $cheapest_price, $current_item_price)){
                                $current_qty = $item['quantity'];
                                if(isset($product_page_data['count_type']) && $product_page_data['count_type'] == 'individual'){
                                    $applied_qty = self::getAppliedQty($key, $rule->rule->id, $product_page_data);
                                    if($applied_qty > 0){
                                        $current_qty = $current_qty - $applied_qty;
                                        if($is_cart == false){
                                            $discount_quantity = $discount_quantity - $applied_qty;
                                        }
                                    }
                                }
                                if($current_qty > 0){
                                    $cheapest_price = $current_item_price;
                                    $qty_to_apply = ($current_qty >= $discount_quantity)? $discount_quantity: $current_qty;
                                    $cheapest_item = array(
                                        'cart_item_key' => $key,
                                        'cart_item_price' => $cheapest_price,
                                        'cart_item_quantity' => $item['quantity'],
                                        'cart_item_quantity_to_apply' => $qty_to_apply,
                                        'cart_item_product_id' => Woocommerce::getProductId($cart_item_product),
                                        'matched_rule' => $matched_rule,
                                        'rule_id' => $rule->rule->id,
                                    );
                                    if(apply_filters('advanced_woo_discount_rules_set_cheapest_item_key_as_product_id_for_product_page', false)){
                                        $cheapest_item['cart_item_key'] = $is_cart? $key: Woocommerce::getProductId($cart_item_product);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        if(!empty($cheapest_item)){
            $cheapest_items[] = $cheapest_item;
            $discount_quantity = $discount_quantity - $cheapest_item['cart_item_quantity_to_apply'];
            $exclude_cart_item_keys[] = $cheapest_item['cart_item_key'];
            return self::getCheapestItemsFromCart($rule, $matched_rule, $discount_quantity, $condition, $type_and_values, $cheapest_items, $exclude_cart_item_keys, $is_cart, $product_page_data);
        }
        if($discount_quantity > 0){
            if(!$is_cart){
                if(self::validateCartItemBasedOnType($product_page_data['product'], $type_and_values, $rule)){
                    $qty_to_apply = ($product_page_data['quantity'] >= $discount_quantity)? $discount_quantity: $product_page_data['quantity'];
                    $cheapest_item = array(
                        'cart_item_key' => Woocommerce::getProductId($product_page_data['product']),
                        'cart_item_price' => Woocommerce::getProductPrice($product_page_data['product']),
                        'cart_item_quantity' => $product_page_data['quantity'],
                        'cart_item_quantity_to_apply' => $qty_to_apply,
                        'cart_item_product_id' => Woocommerce::getProductId($product_page_data['product']),
                        'matched_rule' => $matched_rule,
                        'rule_id' => $rule->rule->id,
                    );
                    $cheapest_items[] = $cheapest_item;
                }
            }
        }

        return $cheapest_items;
    }

    /**
     * Validate cart item product based on type
     * */
    public static function validateCartItemBasedOnType($product, $type_and_values, $rule, $cart_item = array()){
        $is_valid_item = true;
        if(!empty($type_and_values) && isset($type_and_values['type'])){
            $type = $type_and_values['type'];
            $product_id = Woocommerce::getProductId($product);
            if($type == 'cheapest_in_cart'){
                if(!empty($cart_item)){
                    self::$discountable_items['any_item_in_cart'] = true;
                }
                //Do noting return true
            } elseif ($type == 'cheapest_from_products'){
                $product_ids = isset($type_and_values['product_ids'])? $type_and_values['product_ids']: array();
                if(!empty($cart_item)){
                    self::$discountable_items['products'] = array_unique(array_merge(self::$discountable_items['products'], $type_and_values['product_ids']));
                }
                if(is_array($product_ids) && !empty($product_ids)){
                    $apply_discount_to_child = apply_filters('advanced_woo_discount_rules_apply_discount_to_variants', true);
                    $parent_id = Woocommerce::getProductParentId($product);
                    if (!empty($apply_discount_to_child) && !empty($parent_id)) {
                        //$product_id = $parent_id;
                        if(!(in_array($product_id, $product_ids) || in_array($parent_id, $product_ids))){
                            $is_valid_item = false;
                        }
                    } elseif(!in_array($product_id, $product_ids)){
                        $is_valid_item = false;
                    }
                }
            } elseif ($type == 'cheapest_from_categories'){
                $categories = Woocommerce::getProductCategories($product);
                $category_ids = isset($type_and_values['category_ids'])? $type_and_values['category_ids']: array();
                if(!empty($cart_item)){
                    self::$discountable_items['categories'] = array_unique(array_merge(self::$discountable_items['categories'], $type_and_values['category_ids']));
                }
                $is_product_in_category = count(array_intersect($categories, $category_ids)) > 0;
                if(!$is_product_in_category){
                    $is_valid_item = false;
                }
            }
        }

        return apply_filters('advanced_woo_discount_rules_is_valid_cheapest_cart_item_based_on_discount_type', $is_valid_item, $product, $type_and_values, $rule, $cart_item);
    }

    /**
     * Is condition matched
     *
     * @param $condition string
     * @param $old_value float
     * @param $new_value float
     * @return boolean
     * */
    protected static function isConditionMatched($condition, $old_value, $new_value){
        if($condition == "cheapest"){
            if($old_value > $new_value) return true;
        } else if($condition == "highest"){
            if($old_value < $new_value) return true;
        }

        return false;
    }

    /**
     * Get Matched Rule
     *
     * @param $buy_x_get_y_ranges object
     * @param $quantity int
     * @param $product object
     *
     * @return mixed
     * */
    public static function getMatchedRule($buy_x_get_y_ranges, $quantity, $product){
        $matched_rule = array();
        foreach ($buy_x_get_y_ranges as $key => $buy_x_get_y_range){
            $start = (int)($buy_x_get_y_range->from);
            if(!empty($buy_x_get_y_range->recursive) && $buy_x_get_y_range->recursive == 1){
                $discount_quantity = (int)($buy_x_get_y_range->free_qty);
                if($quantity < $start){
                    $discount_quantity = 0;
                } else {
                    $discount_quantity = Cheapest::getRecursiveQuantity($start, $quantity, $discount_quantity);
                }
                if($discount_quantity){
                    $matched_rule = clone $buy_x_get_y_range;
                    $matched_rule->free_qty = $discount_quantity;
                }
                return $matched_rule;
            }
            if(empty($buy_x_get_y_range->to)){
                if($buy_x_get_y_range->from <= $quantity){
                    $matched_rule = $buy_x_get_y_range;
                    break;
                }
            } else {
                if($buy_x_get_y_range->from <= $quantity && $buy_x_get_y_range->to >= $quantity){
                    $matched_rule = $buy_x_get_y_range;
                    break;
                }
            }
        }

        return $matched_rule;
    }

    /**
     * Get discount price for product based on percentage for specific quantity
     *
     * @param $product object
     * @param $product_price int/float
     * @param $product_qty int
     * @param $discount_value int/float
     * @param $discount_qty int
     *
     * @return int/float
     * */
    protected static function getDiscountPriceForProductFromQuantityBasedPercentageDiscount($product, $product_price, $product_qty, $discount_value, $discount_qty){
        $discount_amount_per_product = ($discount_value / 100) * floatval($product_price);
        if($product_qty <= $discount_qty){
            $discount_price = $discount_amount_per_product;
        } else {
            $non_discount_qty = $product_qty - $discount_qty;
            $total_price_for_non_discount_qty = $non_discount_qty * $product_price;
            $total_price_for_discount_qty = $discount_qty * ($product_price - $discount_amount_per_product);
            $total_discounted_price = $total_price_for_non_discount_qty + $total_price_for_discount_qty;
            $discounted_price_per_product = 0;
            if($product_qty > 0){
                $discounted_price_per_product = $total_discounted_price / $product_qty;
            }
            $discount_price = $product_price - $discounted_price_per_product;
            if($discount_price < 0){
                $discount_price = 0;
            }
        }

        return $discount_price;
    }

    /**
     * Get discount price for product based on flat discount for specific quantity
     *
     * @param $product object
     * @param $product_price int/float
     * @param $product_qty int
     * @param $discount_amount_per_product int/float
     * @param $discount_qty int
     *
     * @return int/float
     * */
    protected static function getDiscountPriceForProductFromQuantityBasedFlatDiscount($product, $product_price, $product_qty, $discount_amount_per_product, $discount_qty){
        if($product_qty <= $discount_qty){
            $discount_price = $discount_amount_per_product;
        } else {
            $non_discount_qty = $product_qty - $discount_qty;
            $total_price_for_non_discount_qty = $non_discount_qty * $product_price;
            $total_price_for_discount_qty = $discount_qty * ($product_price - $discount_amount_per_product);
            $total_discounted_price = $total_price_for_non_discount_qty + $total_price_for_discount_qty;
            $discounted_price_per_product = 0;
            if($product_qty > 0){
                $discounted_price_per_product = $total_discounted_price / $product_qty;
            }
            $discount_price = $product_price - $discounted_price_per_product;
            if($discount_price < 0){
                $discount_price = 0;
            }
        }

        return $discount_price;
    }

    /**
     * Set Free discount in matched rule object if exists
     *
     * @param $matched_rule object
     * @return object
     * */
    public static function setFreeDiscountInMatchedRule($matched_rule){
        if(isset($matched_rule->free_type)){
            if($matched_rule->free_type == "free_product"){
                $matched_rule->free_type = "percentage";
                $matched_rule->free_value = 100;
            }
        }

        return $matched_rule;
    }
}

Cheapest::init();