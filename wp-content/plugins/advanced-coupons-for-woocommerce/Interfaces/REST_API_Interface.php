<?php
namespace ACFWP\Interfaces;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstraction that defines the interface for REST API.
 *
 * @since 3.5.5
 */
interface REST_API_Interface {

    /**
     * Register REST API routes.
     * - REST API class should have a register_routes method.
     *
     * @since 3.5.5
     * @access public
     */
    public function register_routes();

    /**
     * REST API permission check implementation.
     * - REST API class should have a permission check for the route.
     *
     * @since 3.5.5
     * @access public
     *
     * @param WP_REST_Request $request Full details about the request.
     */
    public function get_admin_permissions_check( $request );

}
