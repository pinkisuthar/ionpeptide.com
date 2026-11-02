<?php
namespace ACFWF\Models\Third_Party_Integrations;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Model that houses the logic of the Speed Optimizer module.
 *
 * @since 4.6.2
 */
class Speed_Optimizer extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.6.2
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
     * Determine if frontend JS should be force loaded for Speed Optimizer screen.
     *
     * @since 4.6.2
     * @access public
     *
     * @return bool Whether the current request URI contains 'siteground-optimizer'.
     */
    public function force_load_frontend_js_speed_optimizer() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        return str_contains( $request_uri, 'siteground-optimizer' );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Speed_Optimizer class.
     *
     * @since 4.6.2
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        if ( $this->_helper_functions->is_plugin_active( Plugin_Constants::SPEED_OPTIMIZER_PLUGIN ) ) {
            add_filter( 'acfw_force_load_frontend_js', array( $this, 'force_load_frontend_js_speed_optimizer' ) );
        }
    }
}
