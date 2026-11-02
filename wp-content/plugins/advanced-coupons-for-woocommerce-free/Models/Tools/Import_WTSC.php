<?php

namespace ACFWF\Models\Tools;

use ACFWF\Abstracts\Abstract_Import_Store_Credits_Tool;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tool for importing earned store credits from the WTSC plugin.
 *
 * Extends the abstract import tool to provide support for WooCommerce Store Credit (WTSC) data imports.
 *
 * @since 4.6.7
 */
class Import_WTSC extends Abstract_Import_Store_Credits_Tool {

    const PLUGIN_ID   = 'wtsc';
    const PLUGIN_NAME = 'Smart Coupons for WooCommerce by WebToffee';

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new WTSC Import tool object instance.
     *
     * @since 4.6.7
     * @access public
     */
    public function __construct() {
        $this->_data = array(
            'plugin_id'                       => self::PLUGIN_ID,
            'plugin_name'                     => self::PLUGIN_NAME,
            'imported_store_credits_meta_key' => 'acfw_imported_store_credits_from_wtsc',
            'plugin_basename'                 => 'wt-smart-coupon-pro/wt-smart-coupon-pro.php',
        );
    }

    /**
     * Get users with store credits based on the WTSC database table.
     *
     * @since 4.6.7
     * @access protected
     *
     * @return array List of user IDs.
     */
    protected function _get_users_with_store_credits(): array {
        global $wpdb;

        $results = $wpdb->get_col(
            "
            SELECT DISTINCT u.ID
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->postmeta} pm_email ON pm_email.meta_key = 'customer_email' 
                AND (pm_email.meta_value = u.user_email 
                    OR pm_email.meta_value LIKE CONCAT('a:1:{i:0;s:', LENGTH(u.user_email), ':\"', u.user_email, '\";}'))
            INNER JOIN {$wpdb->posts} p ON p.ID = pm_email.post_id 
                AND p.post_type = 'shop_coupon'
                AND p.post_status = 'publish'
            INNER JOIN {$wpdb->postmeta} pm_type ON pm_type.post_id = p.ID 
                AND pm_type.meta_key = 'discount_type'
                AND pm_type.meta_value = 'store_credit'
            INNER JOIN {$wpdb->postmeta} pm_amount ON pm_amount.post_id = p.ID 
                AND pm_amount.meta_key = 'coupon_amount'
                AND pm_amount.meta_value > 0
            "
        );

        return $results;
    }

    /**
     * Get the customer's total store credits for WTSC plugin.
     *
     * @since 4.6.7
     * @access protected
     *
     * @param int $user_id Customer ID.
     * @return int Customer's total store credits.
     */
    protected function _get_customer_store_credits( $user_id ): int {
        global $wpdb;

        $user = get_user_by( 'ID', $user_id );

        if ( ! $user ) {
            return 0;
        }

        $total = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT SUM(CAST(pm_amount.meta_value AS DECIMAL(10,2)))
                FROM {$wpdb->postmeta} pm_email
                INNER JOIN {$wpdb->posts} p ON p.ID = pm_email.post_id 
                    AND p.post_type = 'shop_coupon' 
                    AND p.post_status = 'publish'
                INNER JOIN {$wpdb->postmeta} pm_type ON pm_type.post_id = pm_email.post_id 
                    AND pm_type.meta_key = 'discount_type' 
                    AND pm_type.meta_value = 'store_credit'
                INNER JOIN {$wpdb->postmeta} pm_amount ON pm_amount.post_id = pm_email.post_id 
                    AND pm_amount.meta_key = 'coupon_amount'
                WHERE pm_email.meta_key = 'customer_email' 
                    AND (pm_email.meta_value = %s 
                        OR pm_email.meta_value LIKE CONCAT('a:1:{i:0;s:', %d, ':\"', %s, '\";}'))
                    AND pm_amount.meta_value > 0
                ",
                $user->user_email,
                strlen( $user->user_email ),
                $user->user_email
            )
        );

        return (int) $total;
    }
}
