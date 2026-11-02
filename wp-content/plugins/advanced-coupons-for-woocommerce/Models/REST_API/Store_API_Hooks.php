<?php
namespace ACFWP\Models\REST_API;

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
 * Model that houses the logic of store api.
 *
 * @since 3.5.7
 */
class Store_API_Hooks extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Class constructor.
     *
     * @since 3.5.7
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
     * Extend Store API Coupon Endpoint.
     *
     * @since 3.5.7
     * @access public
     */
    public function extend_store_api_coupon_endpoint() {
        \ACFWP\Models\REST_API\Store_API_Extend_Endpoint::init();
    }

    /**
     * Execute Hooks.
     *
     * @since 3.5.7
     * @access public
     */
    public function run() {
        add_action( 'woocommerce_blocks_loaded', array( $this, 'extend_store_api_coupon_endpoint' ) );
    }
}
