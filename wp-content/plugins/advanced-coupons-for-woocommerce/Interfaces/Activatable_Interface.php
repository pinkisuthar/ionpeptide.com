<?php
namespace ACFWP\Interfaces;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstraction that provides contract relating to activation.
 * Any model that needs some sort of activation must implement this interface.
 *
 * @since 2.0
 */
interface Activatable_Interface {

    /**
     * Contract for activation.
     *
     * @since 2.0
     * @access public
     */
    public function activate();

}
