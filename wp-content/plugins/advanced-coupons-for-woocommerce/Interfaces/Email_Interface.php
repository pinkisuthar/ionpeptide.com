<?php
namespace ACFWP\Interfaces;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstraction that provides email hook interface.
 *
 * @since 3.5.5
 */
interface Email_Interface {

    /**
     * Trigger email (Action Scheduler).
     * - Email instance should have a trigger method that accepts a user id.
     * - This method will be executed by Action Scheduler.
     *
     * @since 3.5.5
     * @access public
     *
     * @param array $user_id User ID.
     */
    public function trigger( $user_id );

    /**
     * Preview email.
     * - Email instance should have a preview method to preview the email.
     *
     * @since 3.5.5
     * @access public
     */
    public function preview();

    /**
     * Send email.
     * - Email instance should have a send method to send the email.
     *
     * @since 3.5.5
     * @access public
     *
     * @param WC_Customer $customer Customer object.
     */
    public function send( $customer );

}
