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
 * Model that houses the logic of the Elementor module.
 *
 * @since 4.7.1
 */
class Elementor extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.7.1
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
     * Check if the current page is a cart checkout element.
     *
     * @since 4.7.1
     * @access public
     *
     * @return bool True if the current page is a cart checkout element, false otherwise.
     */
    public function is_cart_checkout_element() {
        global $post;

        // Check if Elementor is active.
        if ( ! did_action( 'elementor/loaded' ) ) {
            return false;
        }

        // Check if we have a post.
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }

        // Check if the post is built with Elementor.
        $document = \Elementor\Plugin::$instance->documents->get( $post->ID );
        if ( ! $document || ! $document->is_built_with_elementor() ) {
            return false;
        }

        // Check if the page content contains the Elementor checkout widget.
        $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
        if ( empty( $elementor_data ) ) {
            return false;
        }

        // Parse and search for woocommerce-checkout-page widget in the Elementor data.
        // Some hosts (e.g. Cloudways with object cache) return _elementor_data already decoded as an array.
        $data = is_array( $elementor_data ) ? $elementor_data : json_decode( $elementor_data, true );
        if ( ! is_array( $data ) ) {
            return false;
        }

        return $this->_has_widget_type( $data, 'woocommerce-checkout-page' );
    }

    /**
     * Recursively check if a widget type exists in Elementor data.
     *
     * @since 4.7.1
     * @access private
     *
     * @param array  $data        The Elementor data array.
     * @param string $widget_type The widget type to search for.
     * @return bool True if the widget type is found, false otherwise.
     */
    private function _has_widget_type( $data, $widget_type ) {
        if ( ! is_array( $data ) ) {
            return false;
        }

        foreach ( $data as $key => $value ) {
            // Check if current element has the widgetType we're looking for.
            if ( 'widgetType' === $key && $widget_type === $value ) {
                return true;
            }

            // Recursively search nested arrays.
            if ( is_array( $value ) && $this->_has_widget_type( $value, $widget_type ) ) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Elementor class.
     *
     * @since 4.7.1
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        if ( $this->_helper_functions->is_plugin_active( Plugin_Constants::ELEMENTOR_PLUGIN ) ) {
            add_filter( 'acfw_is_cart_checkout_element', array( $this, 'is_cart_checkout_element' ), 10 );
        }
    }
}
