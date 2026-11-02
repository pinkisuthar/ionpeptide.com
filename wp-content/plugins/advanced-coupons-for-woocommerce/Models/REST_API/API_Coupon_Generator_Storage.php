<?php
namespace ACFWP\Models\REST_API;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Interfaces\REST_API_Interface;
use ACFWP\Models\Coupon_Generator_Template_Manager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API for Coupon Generator Storage.
 * Handles permanent storage of AI-generated coupon templates to files.
 *
 * @since 4.1
 */
class API_Coupon_Generator_Storage extends Base_Model implements Model_Interface, REST_API_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Custom REST API base.
     *
     * @since 4.1
     * @access private
     * @var string
     */
    private $_base = 'coupon-generator-ai';

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.1
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        $this->_constants        = $constants;
        $this->_helper_functions = $helper_functions;
        $main_plugin->add_to_all_plugin_models( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | Routes.
    |--------------------------------------------------------------------------
     */

    /**
     * Register routes.
     *
     * @since 4.1
     * @access public
     */
    public function register_routes() {
        // POST /coupons/v1/coupon-generator-ai/save.
        \register_rest_route(
            $this->_constants->REST_API_NAMESPACE,
            '/' . $this->_base . '/save',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'save_template' ),
                'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                'args'                => array(
                    'name'   => array(
                        'description'       => __( 'Custom template name.', 'advanced-coupons-for-woocommerce' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'prompt' => array(
                        'description'       => __( 'Original AI prompt.', 'advanced-coupons-for-woocommerce' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        // GET /coupons/v1/coupon-generator-ai/saved.
        \register_rest_route(
            $this->_constants->REST_API_NAMESPACE,
            '/' . $this->_base . '/saved',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'list_templates' ),
                'permission_callback' => array( $this, 'get_admin_permissions_check' ),
            )
        );

        // GET /coupons/v1/coupon-generator-ai/saved/:id.
        \register_rest_route(
            $this->_constants->REST_API_NAMESPACE,
            '/' . $this->_base . '/saved/(?P<id>[a-zA-Z0-9_-]+)',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_template' ),
                'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                'args'                => array(
                    'id' => array(
                        'description'       => __( 'Unique template identifier.', 'advanced-coupons-for-woocommerce' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        // DELETE /coupons/v1/coupon-generator-ai/saved/:id.
        \register_rest_route(
            $this->_constants->REST_API_NAMESPACE,
            '/' . $this->_base . '/saved/(?P<id>[a-zA-Z0-9_-]+)',
            array(
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_template' ),
                'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                'args'                => array(
                    'id' => array(
                        'description'       => __( 'Unique template identifier.', 'advanced-coupons-for-woocommerce' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );
    }

    /**
     * Check if user has admin permissions.
     *
     * @since 4.1
     * @access public
     *
     * @param \WP_REST_Request $request Request object.
     * @return bool|\WP_Error
     */
    public function get_admin_permissions_check( $request ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new \WP_Error(
                'rest_forbidden_context',
                __( 'Sorry, you are not allowed access to this endpoint.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => \rest_authorization_required_code() )
            );
        }

        return apply_filters( 'acfwp_coupon_generator_storage_admin_permissions_check', true, $request );
    }

    /*
    |--------------------------------------------------------------------------
    | REST API Callbacks.
    |--------------------------------------------------------------------------
     */

    /**
     * Save AI-generated template permanently.
     *
     * @since 4.1
     * @access public
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error Response or error.
     */
    public function save_template( $request ) {
        // Get template from transient.
        $user_id       = get_current_user_id();
        $transient_key = 'acfw_ai_coupon_template_' . $user_id;
        $template_data = get_transient( $transient_key );

        if ( false === $template_data ) {
            return new \WP_Error(
                'no_template',
                __( 'No AI-generated template found to save.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 404 )
            );
        }

        if ( ! is_array( $template_data ) ) {
            return new \WP_Error(
                'invalid_template',
                __( 'Invalid template data format.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        $custom_name = $request->get_param( 'name' );
        $prompt      = $request->get_param( 'prompt' );

        $template_id = Coupon_Generator_Template_Manager::save_template( $template_data, $prompt, $custom_name );

        if ( $template_id ) {
            // Delete transient to prevent duplicate saves.
            delete_transient( $transient_key );
        }

        if ( ! $template_id ) {
            return new \WP_Error(
                'save_failed',
                __( 'Failed to save template.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 500 )
            );
        }

        return \rest_ensure_response(
            array(
                'success'     => true,
                'template_id' => $template_id,
                'message'     => __( 'Template saved successfully.', 'advanced-coupons-for-woocommerce' ),
            )
        );
    }

    /**
     * List all saved AI templates for current user.
     *
     * @since 4.1
     * @access public
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response.
     */
    public function list_templates( $request ) {
        $templates = Coupon_Generator_Template_Manager::list_templates();

        return \rest_ensure_response(
            array(
                'success'   => true,
                'templates' => $templates,
            )
        );
    }

    /**
     * Get single saved template by ID.
     *
     * @since 4.1
     * @access public
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error Response or error.
     */
    public function get_template( $request ) {
        $id       = $request->get_param( 'id' );
        $template = Coupon_Generator_Template_Manager::get_template( $id );

        if ( ! $template ) {
            return new \WP_Error(
                'not_found',
                __( 'Template not found.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 404 )
            );
        }

        // Use Template_Processor for consistency with existing flow.
        $template_data = isset( $template['template_data'] ) ? $template['template_data'] : array();

        if ( function_exists( 'ACFWF' ) && class_exists( '\ACFWF\Models\REST_API\Template_Processor' ) ) {
            $processor = new \ACFWF\Models\REST_API\Template_Processor(
                \ACFWF()->Plugin_Constants,
                \ACFWF()->Helper_Functions
            );
            $processed = $processor->process_template( $template_data );
        } else {
            $processed = $template_data;
        }

        return \rest_ensure_response(
            array(
                'success' => true,
                'data'    => $processed,
            )
        );
    }

    /**
     * Delete saved template.
     *
     * @since 4.1
     * @access public
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error Response or error.
     */
    public function delete_template( $request ) {
        $id     = $request->get_param( 'id' );
        $result = Coupon_Generator_Template_Manager::delete_template( $id );

        if ( ! $result ) {
            return new \WP_Error(
                'delete_failed',
                __( 'Failed to delete template.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 500 )
            );
        }

        return \rest_ensure_response(
            array(
                'success' => true,
                'message' => __( 'Template deleted successfully.', 'advanced-coupons-for-woocommerce' ),
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute class.
     *
     * @since 4.1
     * @access public
     * @inheritdoc
     */
    public function run() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }
}
