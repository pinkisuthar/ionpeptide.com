<?php
namespace ACFWP\Models\Emails;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\REST_API\API_Email_Store_Credit_Reminder;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Emails module.
 *
 * @since 3.5.5
 */
class Emails extends Base_Model implements Model_Interface, Initiable_Interface {

    /**
     * Property that holds registered email instances.
     *
     * @since 3.5.5
     * @access private
     * @var array
     */
    private $_emails = array();

    /**
     * Property that holds registered rest api instances.
     *
     * @since 3.5.5
     * @access private
     * @var array
     */
    private $_apis = array();

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
        $main_plugin->add_to_all_plugin_models( $this );

        // Register email instances.
        $this->_emails[ Email_Store_Credit_Reminder::$id ] = \ACFWP\Models\Emails\Email_Store_Credit_Reminder::get_instance( $main_plugin, $constants, $helper_functions );

        // Register rest api instances.
        $this->_apis[ API_Email_Store_Credit_Reminder::$base ] = \ACFWP\Models\REST_API\API_Email_Store_Credit_Reminder::get_instance( $main_plugin, $constants, $helper_functions );
    }

    /**
     * Override template file check to make sure our custom email templates are found by WC.
     *
     * @since 3.5.5
     * @access public
     *
     * @param string $core_file     Core template file path.
     * @param string $template      Template file name.
     * @param string $template_base Template base path.
     * @param string $email_id      Email ID.
     */
    public function override_template_file_path_check( $core_file, $template, $template_base, $email_id ) {
        if ( array_key_exists( $email_id, $this->_emails ) ) {
            return $this->_constants->TEMPLATES_ROOT_PATH . $template;
        }

        return $core_file;
    }

    /**
     * Initialize AJAX email hooks.
     *
     * @since 3.5.5
     * @access private
     */
    private function _initialize_ajax_email_hooks() {
        foreach ( $this->_emails as $email ) {
            // Preview Email.
            add_action( 'wp_ajax_' . $email::$id . '_preview_email', array( $this, 'ajax_preview' ) );
        }
    }

    /**
     * Register REST API routes.
     *
     * @since 3.5.5
     * @access private
     */
    private function _initialize_rest_api_hooks() {
        foreach ( $this->_apis as $api ) {
            if ( method_exists( $api, 'initialize' ) ) {
                $api->initialize();
            }
        }
    }

    /**
     * Register \WC_Email run() hooks.
     *
     * @since 3.5.5
     * @access private
     */
    private function _register_email_run_hooks() {
        foreach ( $this->_emails as $email ) {
            if ( method_exists( $email, 'run' ) ) {
                $email->run();
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX Functions
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX email preview.
     *
     * @since 3.5.5
     * @access public
     */
    public function ajax_preview() {
        $error_msg = '';
        $data      = wp_unslash( $_GET );
        $email_id  = sanitize_text_field( ( str_replace( '_preview_email', '', $data['action'] ) ) );

        // Request validation before return the preview.
        $nonce = sanitize_key( $_GET['_wpnonce'] ?? '' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            $error_msg = __( 'You are not allowed to do this', 'advanced-coupons-for-woocommerce' );
        } elseif ( ! isset( $this->_emails[ $email_id ] ) ) {
            $error_msg = __( 'Email id is not exists', 'advanced-coupons-for-woocommerce' );
        } elseif ( ! $nonce || ! wp_verify_nonce( $nonce, $this->_emails[ $email_id ]::$id . '_page_preview' ) ) {
            $error_msg = __( 'Nonce verification failed', 'advanced-coupons-for-woocommerce' );
        }

        // Display error message if any.
        if ( $error_msg ) {
            include $this->_constants->VIEWS_ROOT_PATH . 'errors/preview-email.php';
        } else { // Retrieve email preview.
            $args = \ACFWF()->Helper_Functions->api_sanitize_query_parameters( wp_unslash( $data['args'] ) );
            if ( isset( $this->_emails[ $email_id ] ) ) {
                $email       = $this->_emails[ $email_id ];
                $email->args = $args;
                echo $email->preview(); //phpcs:ignore
            } else {
                echo apply_filters( 'acfwp_get_email_preview_content', $email_id, $args ); //phpcs:ignore
            }
        }

        wp_die();
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
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
        $this->_initialize_ajax_email_hooks(); // Preview ajax hook.
        $this->_initialize_rest_api_hooks(); // REST API hooks.
    }

    /**
     * Execute Emails class.
     *
     * @since 3.5.5
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        add_filter( 'woocommerce_locate_core_template', array( $this, 'override_template_file_path_check' ), 10, 4 );
        $this->_register_email_run_hooks(); // Register \WC_Email run() hooks.
    }
}
