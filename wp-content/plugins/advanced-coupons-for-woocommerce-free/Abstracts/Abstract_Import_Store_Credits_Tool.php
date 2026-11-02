<?php
namespace ACFWF\Abstracts;

use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Models\Objects\Store_Credit_Entry;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract class for the import tool used in the Store Credits system.
 *
 * Provides the base functionality for importing store credit data.
 *
 * @since 4.6.7
 */
abstract class Abstract_Import_Store_Credits_Tool {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that houses the data model of the object.
     *
     * @since 4.6.7
     * @access protected
     * @var array
     */
    protected $_data = array(
        'plugin_id'                       => '',
        'plugin_name'                     => '',
        'imported_store_credits_meta_key' => '',
        'plugin_basename'                 => '',
    );

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Magic property getter.
     * We use this magic method to automatically access data from the _data property so
     * we do not need to create individual methods to expose each of the object's properties.
     *
     * @since 4.6.7
     * @access public
     *
     * @throws \Exception Error message.
     * @param string $prop The name of the data property to access.
     * @return mixed Data property value.
     */
    public function __get( $prop ) {
        if ( array_key_exists( $prop, $this->_data ) ) {
            return $this->_data[ $prop ];
        } else {
            throw new \Exception( 'Trying to access unknown property' );
        }
    }

    /**
     * Create schedules via the action scheduler to trigger importing of customer store credits.
     *
     * @since 4.6.7
     * @access public
     *
     * @return string[]|\WP_Error Schedule action IDs on success, error object on failure.
     */
    public function create_import_schedules() {

        $user_ids = $this->_get_users_with_store_credits();
        $batches  = array_chunk( $user_ids, 50 );

        // Skip if there are no store credits data to be imported.
        if ( empty( $user_ids ) || empty( $batches ) ) {
            return new \WP_Error(
                'acfw_no_store_credits_data',
                __( 'There are no customer store credits data to be imported.', 'advanced-coupons-for-woocommerce-free' ),
                array( 'status' => 400 )
            );
        }

        $schedules = array();
        foreach ( $batches as $ids ) {
            $schedules[] = \WC()->queue()->schedule_single(
                time(),
                Plugin_Constants::IMPORT_STORE_CREDITS_SCHEDULE_HOOK,
                array( $this->plugin_id, $ids, 'import_store_credits_' . $this->plugin_id ),
                'acfw'
            );
        }

        return $schedules;
    }

    /**
     * Import store credits for a single customer.
     *
     * @since 4.6.7
     * @access public
     *
     * @param int $user_id Customer ID.
     * @return bool True on success, false on failure.
     */
    public function import_store_credits_for_customer( $user_id ) {
        $total_store_credits    = intval( $this->_get_customer_store_credits( $user_id ) );
        $imported_store_credits = intval( get_user_meta( $user_id, $this->imported_store_credits_meta_key, true ) );

        // Skip if customer's total store credits is zero or less or store credits are already imported.
        if ( 1 > $total_store_credits || $imported_store_credits >= $total_store_credits ) {
            return false;
        }

        $store_credit_entry = new Store_Credit_Entry();

        $store_credit_entry->set_prop( 'user_id', absint( $user_id ) );
        $store_credit_entry->set_prop( 'type', 'increase' );
        $store_credit_entry->set_prop( 'action', 'imported_sc' );
        $store_credit_entry->set_prop(
            'note',
            sprintf(
                /* translators: %s: plugin name */
                __( 'Imported store credits from %s', 'advanced-coupons-for-woocommerce-free' ),
                $this->plugin_name
            )
        );
        $store_credit_entry->set_prop( 'amount', (float) wc_format_decimal( $total_store_credits - $imported_store_credits ) );

        $check = $store_credit_entry->save();

        if ( is_wp_error( $check ) ) {
            return false;
        }

        update_user_meta( $user_id, $this->imported_store_credits_meta_key, $imported_store_credits + $store_credit_entry->get_prop( 'amount', true ) );

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Utilities
    |--------------------------------------------------------------------------
     */

    /**
     * Check if the third party plugin is active.
     *
     * @since 4.6.7
     * @access public
     */
    public function is_plugin_active() {
        return \ACFWF()->Helper_Functions->is_plugin_active( $this->plugin_basename );
    }

    /**
     * Deactivate the third aprty plugin.
     *
     * @since 4.6.7
     * @access public
     */
    public function deactivate_plugin() {
        deactivate_plugins( $this->plugin_basename );
    }

    /**
     * Get users with store credits based on the 3rd party plugin database table.
     *
     * @since 4.6.7
     * @access protected
     *
     * @return array List of user IDs.
     */
    abstract protected function _get_users_with_store_credits(): array;

    /**
     * Get the customer's total store credits for this 3rd party plugin.
     *
     * @since 4.6.7
     * @access protected
     *
     * @param int $user_id Customer ID.
     * @return int Customer's total store credits.
     */
    abstract protected function _get_customer_store_credits( $user_id ): int;

    /**
     * Get default API_Settings options.
     *
     * @since 4.6.7
     * @access public
     *
     * @return array Default API_Settings options.
     */
    public function get_default_api_setting_options() {
        return array(
            'key'   => static::PLUGIN_ID,
            'label' => static::PLUGIN_NAME,
        );
    }
}
