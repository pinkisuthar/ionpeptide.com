<?php
namespace ACFWP\Interfaces;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstraction that provides contract relating to initialization.
 * Any model that needs some sort of initialization must implement this interface.
 *
 * @since 2.0
 */
interface Initiable_Interface {

    /**
     * Contruct for initialization.
     *
     * @since 2.0
     * @access public
     */
    public function initialize();

}
