<?php
namespace ACFWP\Models\Objects\Blocks;

use ACFWP\Abstracts\Base_Model;
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use ACFWP\Models\Objects\Vite_App;

/**
 * Class for integrating with WooCommerce Blocks
 *
 * @since 3.5.7
 */
class Cart_Integration extends Base_Model implements IntegrationInterface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * The name of the integration.
     * This is used internally to identify the integration and should be unique.
     *
     * @since 3.5.7
     * @access public
     *
     * @return string
     */
    public function get_name() {
        return 'acfwp-wc-cart-block';
    }

    /**
     * When called invokes any initialization/setup for the integration.
     *
     * @since 3.5.7
     * @access public
     */
    public function initialize() {
        $this->register_scripts();
    }

    /**
     * Register Scripts
     * - This is where you will register custom scripts for your block
     *
     * @since 3.5.7
     * @access private
     */
    private function register_scripts() {
        // Register Script.
        $vite_app = new Vite_App(
            'acfwp-wc-cart-block-integration', // Don't forget to register this handle in the get_script_handles() or get_editor_script_handles() method.
            'packages/acfwp-cart-block/index.tsx',
            array( 'wc-blocks-checkout', 'wc-blocks-data-store' )
        );
        $vite_app->register();
    }

    /**
     * Returns an array of script handles to enqueue in the frontend context.
     *
     * @since 3.5.7
     * @access public
     *
     * @return string[]
     */
    public function get_script_handles() {
        return array( 'acfwp-wc-cart-block-integration' );
    }

    /**
     * Returns an array of script handles to enqueue in the editor context.
     *
     * @since 3.5.7
     * @access public
     *
     * @return string[]
     */
    public function get_editor_script_handles() {
        return array();
    }

    /**
     * An array of key, value pairs of data made available to the block on the client side.
     * - To access this data in the block see code sample here : https://github.com/agungsundoro/woocommerce-blocks-test/blob/0a520112707e7e09af67e3fbbbb8876a846e6c56/packages/acfw-wc-blocks/cart/data.tsx#L19-L26
     *
     * @since 3.5.7
     * @access public
     *
     * @return array
     */
    public function get_script_data() {
        return array();
    }
}
