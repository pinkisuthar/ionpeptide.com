<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
/**
 * @since 2.3.6
 */

add_filter('advanced_woo_discount_rules_do_recalculate_total', '__return_false');