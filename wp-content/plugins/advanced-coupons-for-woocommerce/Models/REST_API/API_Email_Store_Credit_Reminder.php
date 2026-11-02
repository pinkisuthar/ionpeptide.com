<?php

namespace ACFWP\Models\REST_API;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\REST_API_Interface;
use ACFWP\Models\Emails\Email_Store_Credit_Reminder;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class that houses the hooks of the store credit reminder email.
 *
 * @since 3.5.5
 */
class API_Email_Store_Credit_Reminder extends Base_Model implements Initiable_Interface, REST_API_Interface {
    /**
     * Property that holds rest API email base.
     *
     * @since 3.5.5
     * @access public
     * @var $base
     */
    public static $base = 'emails/store-credit-reminder';

    /**
     * Property that holds registered email instances.
     *
     * @since 3.5.5
     * @access private
     * @var Email_Store_Credit_Reminder
     */
    private $_email;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.5.5
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );

        // Register email instance.
        $this->_email = Email_Store_Credit_Reminder::get_instance( $main_plugin, $constants, $helper_functions );
    }

    /**
     * Register REST API routes.
     *
     * @since 3.5.5
     * @access public
     */
    public function register_routes() {
        // REST API send email.
        define( $this::$base . '_is_accessible', current_user_can( 'manage_woocommerce' ) );
        \register_rest_route(
            $this->_constants->REST_API_NAMESPACE,
            $this::$base . '/send',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                    'callback'            => array( $this, 'rest_api_send' ),
                ),
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | REST API callback methods.
    |--------------------------------------------------------------------------
     */

    /**
     * REST API send email.
     *
     * @since 3.5.5
     * @access public
     *
     * @param WP_REST_Request $request Full data about the request.
     */
    public function rest_api_send( $request ) {
        $params  = $request->get_params();
        $user_id = absint( $params['user_id'] );
        $email   = $this->_email;

        // Reschedule email in action scheduler queue.
        // - This is useful to avoid spamming customer.
        $user        = get_userdata( $user_id );
        $email->args = array(
            'user_id'    => intval( $user_id ),
            'user_email' => $user->user_email ?? '',
        );
        if ( $email->is_scheduled() ) {
            $email->reschedule();
        }

        // Check if email is valid before sending it to the customer.
        $is_valid = $this->_email->is_valid( (int) $user_id );
        if ( is_wp_error( $is_valid ) ) { // If invalid then return WP_Error.
            return $is_valid;
        }

        // Send the email immediately, because the admin is sending it manually.
        do_action( $email::$id . '_trigger', $user_id );
        $data           = compact( 'user_id' );
        $data['status'] = 'success';
        return rest_ensure_response( $data );
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions.
    |--------------------------------------------------------------------------
     */

    /**
     * Checks if a given request has access to read list of settings options.
     *
     * @since 3.0
     * @access public
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
     */
    public function get_admin_permissions_check( $request ) {
        $is_accessible = constant( $this::$base . '_is_accessible' );
        if ( ! $is_accessible ) {
            return new \WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed access to this endpoint.', 'advanced-coupons-for-woocommerce' ), array( 'status' => \rest_authorization_required_code() ) );
        }

        return apply_filters( $this::$base . '_admin_permissions_check', true, $request );
    }

    /*
    |--------------------------------------------------------------------------
    | Hooks
    |--------------------------------------------------------------------------
    */

    /**
     * Execute codes that needs to run plugin init.
     *
     * @since 3.5.5
     * @access public
     * @inherit ACFWF\Interfaces\Initializable_Interface
     */
    public function initialize() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) ); // Register API routes.
    }
}
