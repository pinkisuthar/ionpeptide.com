<?php

namespace WDRPro\App\Helpers;

use Wdr\App\Helpers\Helper;
use Wdr\App\Helpers\Validation;
use Wdr\App\Helpers\Woocommerce;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class CoreMethodCheck
{
    public static function getConvertedFixedPrice($value, $type = ''){
        if(method_exists('\Wdr\App\Helpers\Woocommerce', 'getConvertedFixedPrice')){
            return Woocommerce::getConvertedFixedPrice($value, $type);
        }
        return $value;
    }

    public static function create_nonce($action = -1){
        if(method_exists('\Wdr\App\Helpers\Helper', 'create_nonce')){
            return Helper::create_nonce($action);
        }
        return '';
    }

    public static function validateRequest($method){
        if(method_exists('\Wdr\App\Helpers\Helper', 'validateRequest')){
            return Helper::validateRequest($method);
        }
        return false;
    }

    public static function isValidLicenceKey($licence_key){
        if(method_exists('\Wdr\App\Helpers\Validation', 'validateLicenceKay')){
            return Validation::validateLicenceKay($licence_key);
        }
        return false;
    }

    public static function hasAdminPrivilege(){
        if(method_exists('\Wdr\App\Helpers\Helper', 'hasAdminPrivilege')){
            return Helper::hasAdminPrivilege();
        }
        return false;
    }

    public static function getCleanHtml($html){
        if(method_exists('\Wdr\App\Helpers\Helper', 'getCleanHtml')){
            return Helper::getCleanHtml($html);
        } else {
            try {
                $html = html_entity_decode($html);
                $html =   preg_replace('/(<(script|style|iframe)\b[^>]*>).*?(<\/\2>)/is', "$1$3", $html);
                $allowed_html = array(
                    'br' => array(),
                    'strong' => array(),
                    'span' => array('class' => array()),
                    'div' => array('class' => array()),
                    'p' => array('class' => array()),
                );
                return wp_kses($html, $allowed_html);
            } catch (\Exception $e){
                return '';
            }
        }
    }

    /**
     * check rtl site
     * @return bool
     */
    public static function isRTLEnable(){
        if(method_exists('\Wdr\App\Helpers\Woocommerce', 'isRTLEnable')){
            return Woocommerce::isRTLEnable();
        }
        return false;
    }

    /**
     * Check custom order table feature (HPOS) is enabled or not
     *
     * @since 2.6.0
     *
     * @return bool
     */
    static function customOrdersTableIsEnabled()
    {
        if(method_exists('\Wdr\App\Helpers\Woocommerce', 'customOrdersTableIsEnabled')){
            return Woocommerce::customOrdersTableIsEnabled();
        } else {
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')) {
                return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            }
        }
        return false;
    }

    /**
     * get orders list by condition
     * @param array $conditions
     * @return int[]|WP_Post[]
     */
    static function getOrdersThroughWPQuery($args = array())
    {
        if(method_exists('\Wdr\App\Helpers\Woocommerce', 'getOrdersThroughWPQuery')){
            return Woocommerce::getOrdersThroughWPQuery($args);
        } else {
            $default_args = array(
                'posts_per_page' => -1,
                'post_type' => Woocommerce::getOrderPostType(),
                'post_status' => array_keys(Woocommerce::getOrderStatusList()),
                'orderby' => 'ID',
                'order' => 'DESC'
            );
            $args = array_merge($default_args, $args);
            $query = new \WP_Query($args);
            return  $query->get_posts();
        }
    }

    /**
     * To prepare COT query args through WP Query args
     */
    static function prepareCOTQueryArgsThroughWPQuery($args)
    {
        $map_keys = [
            'post_type' => 'type',
            'post_status' => 'status',
            'posts_per_page' => 'limit',
        ];
        $map_meta_keys = [
            '_customer_user' => 'customer_id',
            '_billing_email' => 'billing_email',
        ];

        foreach ($map_keys as $from => $to) {
            if (isset($args[$from])) {
                $args[$to] = $args[$from];
                unset($args[$from]);
            }
        }

        if (isset($args['meta_query'])) {
            if (isset($args['meta_query']['relation'])) {
                $args['conditions_relation'] = strtoupper($args['meta_query']['relation']);
                unset($args['meta_query']['relation']);
            }
            foreach ($args['meta_query'] as $meta) {
                if (isset($meta['key']) && isset($map_meta_keys[$meta['key']])) {
                    if (isset($meta['value']) && isset($meta['compare']) && $meta['compare'] == '=') {
                        $args['conditions'][$map_meta_keys[$meta['key']]] = sanitize_text_field($meta['value']);
                    }
                }
            }
            unset($args['meta_query']);
        }

        if (isset($args['date_query'])) {
            foreach ($args['date_query'] as $key => $value) {
                $args['date_' . sanitize_key($key)] = $value;
            }
            unset($args['date_query']);
        }

        return $args;
    }

    /**
     * To get WC custom order table (COT) results.
     */
    static function performCOTQuery($args)
    {
        global $wpdb;
        $order_table = $wpdb->prefix . 'wc_orders';
        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $order_items_meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

        $order_items_meta_key_map = [
            'product_id' => '_product_id',
            'variation_id' => '_variation_id',
            'quantity' => '_qty',
        ];

        $select = ['id'];
        if (isset($args['select'])) {
            if (is_array($args['select'])) {
                $select = esc_sql($args['select']);
            } else {
                $select = esc_sql(explode(",", $args['select']));
            }
        }

        $where_queries = $meta_where_queries = [];
        $order_type = isset($args['type']) ? $args['type'] : Woocommerce::getOrderPostType();
        $order_status = isset($args['status']) ? $args['status'] : array_keys(Woocommerce::getOrderStatusList());
        $where_queries[] = "type IN (" . implode(",", array_map(function ($type) { return "'" . esc_sql($type) . "'"; }, $order_type)) . ")";
        $where_queries[] = "status IN (" . implode(",", array_map(function ($status) { return "'" . esc_sql($status) . "'"; }, $order_status)) . ")";

        if (!empty($args['where']) && is_array($args['where'])) {
            $where = $meta_where = [];
            foreach ($args['where'] as $data) {
                if (is_array($data['value'])) {
                    $data['value'] = '(' . implode(',', array_map(function ($value) { return "'" . esc_sql($value) . "'"; }, $data['value'])) . ')';
                } else {
                    $data['value'] = "'" . esc_sql($data['value']) . "'";
                }
                if (!empty($data['column']) && !empty($data['operator']) && !empty($data['value'])) {
                    $where_query = esc_sql($data['column']) . " " . esc_sql($data['operator']) . " " . $data['value'];
                    if (in_array($data['column'], array_keys($order_items_meta_key_map))) {
                        $meta_where[] = $where_query;
                    } else {
                        $where[] = $where_query;
                    }
                }
            }
            $relation = isset($args['where_relation']) ? $args['where_relation'] : 'AND';
            if (!empty($where)) {
                $where_queries[] = "(" . implode(" $relation ", $where) . ")";
            }
            if (!empty($meta_where)) {
                $meta_where_queries[] = "(" . implode(" $relation ", $meta_where) . ")";
            }
        }

        if (!empty($args['conditions'])) {
            $conditions = [];
            $relation = isset($args['conditions_relation']) ? $args['conditions_relation'] : 'AND';
            foreach ($args['conditions'] as $column => $value) {
                $conditions[] = "$column = '" . esc_sql($value) . "'";
            }
            $where_queries[] = "(" . implode(" $relation ", $conditions) . ")";
        }

        if (!empty($args['date_before'])) {
            $where_queries[] = "date_created_gmt < '" . esc_sql($args['date_before']) . "'";
        }
        if (!empty($args['date_after'])) {
            $where_queries[] = "date_created_gmt > '" . esc_sql($args['date_after']) . "'";
        }

        $meta_select = [];
        if (isset($args['join']) && $args['join'] == 'order_items') {
            $order_table .= " LEFT JOIN " . $order_items_table . " AS oi ON id = oi.order_id AND oi.order_item_type = 'line_item'";

            $meta_select = $select;
            $prepared_select_queries = [];
            foreach ($select as $column) {
                $column = trim($column);
                if ($column == 'id') {
                    $prepared_select_queries[] = 'id';
                } elseif ($column == 'item_id') {
                    $prepared_select_queries[] = 'oi.order_item_id AS item_id';
                } else {
                    $meta_key = $column;
                    if (isset($order_items_meta_key_map[$meta_key])) {
                        $meta_key = $order_items_meta_key_map[$meta_key];
                    }
                    $prepared_select_queries[] = "(SELECT meta_value FROM $order_items_meta_table WHERE order_item_id = oi.order_item_id AND meta_key = '" . sanitize_key($meta_key) . "') AS " . $column;
                }
            }
            $select = $prepared_select_queries;
        }

        $query = "SELECT " . implode(",", $select) . " FROM $order_table WHERE " . implode(" AND ", $where_queries);

        $sum = isset($args['sum']) ? esc_sql($args['sum']) : null;
        $count = isset($args['count']) ? esc_sql($args['count']) : null;
        $count_distinct = isset($args['count_distinct']) && $args['count_distinct'];
        if (!empty($meta_where_queries) || ($sum && in_array($sum, array_keys($order_items_meta_key_map))) || $count && in_array($count, array_keys($order_items_meta_key_map))) {
            $meta_select_query = '';
            if ($sum) {
                $meta_select_query .= "SUM(m.$sum) AS sum";
            }
            if ($count) {
                $meta_select_query .= "COUNT(" . ($count_distinct ? 'DISTINCT ' : '') . "m.$count) AS count";
            }
            if (empty($meta_select_query)) {
                $meta_select_query = implode(",", array_map(function ($select) { return "m." . $select; }, $meta_select));
            }
            $query = "SELECT $meta_select_query FROM ($query) AS m WHERE " . implode(" AND ", $meta_where_queries);
        }

        $query .= isset($args['group_by']) ? " GROUP BY " . esc_sql($args['group_by']) : "";
        $query .= isset($args['order_by']) ? " ORDER BY " . esc_sql($args['order_by']) : "";
        $query .= isset($args['limit']) ? " LIMIT " . (int) $args['limit'] : "";

        if ($count && $count_distinct) {
            $query = "SELECT SUM(r.count) AS count FROM ($query) AS r";
        }

        if (isset($args['return']) && $args['return'] == 'var') {
            return $wpdb->get_var($query);//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- query built internally with esc_sql/sanitize_key/int casts, results cached by callers
        } elseif (isset($args['return']) && $args['return'] == 'row') {
            return $wpdb->get_row($query);//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- query built internally with esc_sql/sanitize_key/int casts, results cached by callers
        }
        return $wpdb->get_results($query);//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- query built internally with esc_sql/sanitize_key/int casts, results cached by callers
    }

    /**
     * Generate key from any data
     * @param array/object/string $data
     * @return string
     */
    static function generateBase64Encode($data){
        if(method_exists('\Wdr\App\Helpers\Woocommerce', 'generateBase64Encode')){
            return Woocommerce::generateBase64Encode($data);
        } else {
            return base64_encode(serialize($data));
        }
    }

    static function wc_format_decimal($price, $dp = false, $trim_zeros = false){
        if(method_exists('\Wdr\App\Helpers\Woocommerce', 'wc_format_decimal')){
            return Woocommerce::wc_format_decimal($price, $dp, $trim_zeros);
        } else {
            return $price;
        }
    }

    /**
     * Get title of product
     * @param $product - woocommerce product object
     * @return string
     */
    static function getTitleOfProduct($product){
        if(method_exists('\Wdr\App\Helpers\Woocommerce', 'getTitleOfProduct')){
            return Woocommerce::getTitleOfProduct($product);
        } else {
            return get_the_title($product);
        }
    }
}