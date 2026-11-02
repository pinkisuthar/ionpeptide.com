<?php
namespace ACFWF\Models\REST_API;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Models\Tools\Import_WCSC;
use ACFWF\Models\Tools\Import_WTSC;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the Tools API module logic.
 *
 * @since 4.6.7
 */
class API_Tools extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Custom REST API base.
     *
     * @since 4.6.7
     * @access private
     * @var string
     */
    private $_base = 'import-store-credits';

    /**
     * Property that holds the import schedules data.
     *
     * @since 4.6.7
     * @access private
     * @var array|null
     */
    private $_import_schedules = null;

    /**
     * Property that holds the available import tool.
     *
     * @since 4.6.7
     * @access public
     * @var array
     */
    public $tools = array();

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.6.7
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $main_plugin->add_to_all_plugin_models( $this );
        $main_plugin->add_to_public_models( $this );

        // Register import tools.
        $this->tools = array(
            Import_WCSC::PLUGIN_ID => new Import_WCSC(),
            Import_WTSC::PLUGIN_ID => new Import_WTSC(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Routes.
    |--------------------------------------------------------------------------
     */

    /**
     * Register settings API routes.
     *
     * @since 4.6.7
     * @access public
     */
    public function register_routes() {
        \register_rest_route(
            Plugin_Constants::REST_API_NAMESPACE,
            '/' . $this->_base,
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                    'callback'            => array( $this, 'check_import_store_credits_progress' ),
                ),
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                    'callback'            => array( $this, 'initialize_import_store_credits_tool' ),
                ),
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions.
    |--------------------------------------------------------------------------
     */

    /**
     * Checks if a given request has access to read list of settings options.
     *
     * @since 4.6.7
     * @access public
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
     */
    public function get_admin_permissions_check( $request ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new \WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed access to this endpoint.', 'advanced-coupons-for-woocommerce-free' ), array( 'status' => \rest_authorization_required_code() ) );
        }

        return apply_filters( 'acfw_get_settings_admin_permissions_check', true, $request );
    }

    /**
     * Checks if a given request has access to read list of settings options.
     *
     * @since 4.6.7
     * @access public
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
     */
    public function get_wc_admin_permissions_check( $request ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new \WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed access to this endpoint.', 'advanced-coupons-for-woocommerce-free' ), array( 'status' => \rest_authorization_required_code() ) );
        }

        return apply_filters( 'acfw_get_settings_wc_admin_permissions_check', true, $request );
    }

    /*
    |--------------------------------------------------------------------------
    | Endpoint methods
    |--------------------------------------------------------------------------
     */

    /**
     * Check import store credits progress for a given 3rd party plugin.
     *
     * @since 4.6.7
     * @access public
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function check_import_store_credits_progress( $request ) {
        $plugin  = sanitize_text_field( $request->get_param( 'plugin' ) );
        $results = $this->_check_import_store_credits_tool_progress( $plugin );

        // Skip if the returned results value is an error.
        if ( is_wp_error( $results ) ) {
            return $results;
        }

        // Return an error when there are no scheduled actions for the import process detected.
        if ( 0 >= $results['total'] ) {
            return new \WP_Error(
                'acfw_check_import_error',
                __( 'There are no detected import process for the selected plugin. Please try again.', 'advanced-coupons-for-woocommerce-free' ),
                array( 'status' => 400 )
            );
        }

        $data     = get_transient( Plugin_Constants::IMPORT_STORE_CREDITS_PROCESS_RUNNING );
        $response = array(
            'status'  => 'success',
            'message' => '',
            'data'    => $results,
        );

        // Import process completed.
        if ( 0 >= $results['pending'] ) {

            $response['summary']             = $this->_calculate_import_summary_data( $plugin, $data );
            $response['total_store_credits'] = \ACFWF()->Store_Credits_Calculate->calculate_recently_imported_store_credits( $data['time'] );
            $response['message']             = __( 'Store credits have been imported successfully!', 'advanced-coupons-for-woocommerce-free' );
            $importer                        = $this->_get_plugin_importer_object( $plugin );

            delete_transient( Plugin_Constants::IMPORT_STORE_CREDITS_PROCESS_RUNNING );

            // Deactivate the plugin when the checkbox was checked after the import process is completed.
            if ( isset( $data['deactivate'] ) && $data['deactivate'] && ! is_wp_error( $importer ) ) {
                $importer->deactivate_plugin();
            }
        }

        return \rest_ensure_response( apply_filters( 'acfw_check_import_store_credits_progress', $response ) );
    }

    /**
     * Initialize import store credits tool.
     *
     * @since 4.6.7
     * @access public
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function initialize_import_store_credits_tool( $request ) {

        $plugin     = sanitize_text_field( $request->get_param( 'plugin' ) );
        $deactivate = (bool) intval( $request->get_param( 'deactivate' ) );
        $importer   = $this->_get_plugin_importer_object( $plugin );

        // Validate importer object instance.
        if ( is_wp_error( $importer ) ) {
            return $importer;
        }

        $time      = current_time( 'mysql', true );
        $schedules = $importer->create_import_schedules();

        // Check if schedules were created successfully.
        if ( is_wp_error( $schedules ) ) {
            return $schedules;
        }

        set_transient(
            Plugin_Constants::IMPORT_STORE_CREDITS_PROCESS_RUNNING,
            array(
                'plugin'     => $plugin,
                'time'       => $time,
                'deactivate' => $deactivate,
            ),
            DAY_IN_SECONDS
        );

        $response = array(
            'status'  => 'success',
            'message' => __( 'Importing customer store credits...', 'advanced-coupons-for-woocommerce-free' ),
            'data'    => array( 'schedules' => $schedules ),
        );

        return \rest_ensure_response( apply_filters( 'acfw_initialize_import_store_credits_tool', $response ) );
    }

    /**
     * Run the import store credits process single batch process.
     *
     * @since 4.6.7
     * @access public
     *
     * @param string $plugin Plugin ID.
     * @param int[]  $user_ids List of user IDs.
     */
    public function run_import_store_credits_batch_process( $plugin, $user_ids ) {
        $importer = $this->_get_plugin_importer_object( $plugin );

        // Skip if plugin importer is not valid.
        if ( is_wp_error( $importer ) ) {
            return;
        }

        // Loop through each user IDs and import store credits.
        foreach ( $user_ids as $user_id ) {
            $importer->import_store_credits_for_customer( $user_id );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Utilities
    |--------------------------------------------------------------------------
     */

    /**
     * Get the importer object instance for the selected plugin to import.
     *
     * @since 4.6.7
     * @access private
     *
     * @param string $plugin_id Plugin ID.
     * @return mixed Importer object instance on success, Error object on failure.
     */
    private function _get_plugin_importer_object( $plugin_id ) {

        $importer = null;

        // Get the importer object instance for the selected plugin.
        if ( isset( $this->tools[ $plugin_id ] ) ) {
            $importer = $this->tools[ $plugin_id ];
        }

        if ( ! $importer ) {
            return new \WP_Error(
                'acfw_invalid_plugin_importer',
                __( 'The selected plugin is not supported.', 'advanced-coupons-for-woocommerce-free' ),
                array( 'status' => 400 )
            );
        }

        if ( ! $importer->is_plugin_active() ) {
            return new \WP_Error(
                'acfw_invalid_plugin_importer',
                __( 'The selected plugin is not active.', 'advanced-coupons-for-woocommerce-free' ),
                array( 'status' => 400 )
            );
        }

        return $importer;
    }

    /**
     * Check the progress of the import store credits tool for a given plugin.
     *
     * @since 4.6.7
     * @access private
     *
     * @param string $plugin Plugin key.
     * @return array Import progress data.
     */
    private function _check_import_store_credits_tool_progress( $plugin ) {
        $data = get_transient( Plugin_Constants::IMPORT_STORE_CREDITS_PROCESS_RUNNING );

        if ( ! isset( $data['plugin'] ) || $data['plugin'] !== $plugin ) {
            return new \WP_Error( 'acfw_invalid_plugin_import_check', __( 'Import process for the selected plugin is not yet running.', 'advanced-coupons-for-woocommerce-free' ) );
        }

        // Query all scheduled actions for the current import process.
        $results = $this->_query_import_action_schedules( $plugin, $data );

        // Force Action Scheduler to run the next batch of the scheduled import store credits action.
        if ( ! empty( $results ) && class_exists( '\ActionScheduler_QueueRunner' ) ) {
            $as_runner = \ActionScheduler_QueueRunner::instance();

            foreach ( $results as $row ) {
                if ( 'pending' === $row['status'] ) {
                    $as_runner->process_action( $row['action_id'], __( 'Advanced Coupons: store credits importer', 'advanced-coupons-for-woocommerce-free' ) );
                    break;
                }
            }
        }

        $statuses = array_column( $results, 'status' );
        $counts   = wp_parse_args(
            array_count_values( $statuses ),
            array(
                'total'    => count( $statuses ),
                'complete' => 0,
                'pending'  => 0,
                'failed'   => 0,
            )
        );

        return array_map( 'intval', $counts );
    }

    /**
     * Query the import action schedules for a given plugin.
     *
     * @since 4.6.7
     * @access private
     *
     * @param string $plugin Plugin key.
     * @param array  $data   Import data.
     */
    private function _query_import_action_schedules( $plugin, $data ) {
        global $wpdb;

        if ( ! is_null( $this->_import_schedules ) ) {
            return $this->_import_schedules;
        }

        $this->_import_schedules = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action_id, status, args, extended_args FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=%s 
                AND (args LIKE %s OR extended_args LIKE %s)
                AND CONVERT(scheduled_date_gmt, DATETIME) >= CONVERT(%s, DATETIME)
                ORDER BY scheduled_date_gmt ASC",
                Plugin_Constants::IMPORT_STORE_CREDITS_SCHEDULE_HOOK,
                '%' . $plugin . '%',
                '%' . $plugin . '%',
                $data['time'] ?? current_time( 'mysql', true ),
            ),
            ARRAY_A
        );

        return $this->_import_schedules;
    }

    /**
     * Calculate the import summary data for a given plugin.
     *
     * @since 4.6.7
     * @access private
     *
     * @param string $plugin Plugin key.
     * @param array  $data   Import data.
     * @return array Import summary data.
     */
    private function _calculate_import_summary_data( $plugin, $data ) {
        $results = $this->_query_import_action_schedules( $plugin, $data );
        $summary = array(
            'total'    => 0,
            'imported' => 0,
            'failed'   => 0,
        );

        foreach ( $results as $row ) {
            $args = json_decode( $row['args'] );

            // if args is not an array, try to decode extended_args.
            if ( ! is_array( $args ) ) {
                $args = json_decode( $row['extended_args'] );

                // if extended_args is not an array, skip.
                if ( ! is_array( $args ) ) {
                    continue;
                }
            }

            list( $plugin, $user_ids ) = $args;

            $summary['total'] += count( $user_ids );

            if ( 'complete' === $row['status'] ) {
                $summary['imported'] += count( $user_ids );
            } elseif ( 'failed' === $row['status'] ) {
                $summary['failed'] += count( $user_ids );
            }
        }

        return $summary;
    }

    /*
    |--------------------------------------------------------------------------
    | Utilities
    |--------------------------------------------------------------------------
     */

    /**
     * Get $tools default API_Settings options.
     *
     * @since 4.6.7
     * @access public
     *
     * @return array
     */
    public function get_tools_default_api_setting_options() {
        $default_options = array();

        foreach ( $this->tools as $plugin ) {
            if ( $plugin->is_plugin_active() ) {
                $default_options[] = $plugin->get_default_api_setting_options();
            }
        }

        return $default_options;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Settings class.
     *
     * @since 4.6.7
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( Plugin_Constants::IMPORT_STORE_CREDITS_SCHEDULE_HOOK, array( $this, 'run_import_store_credits_batch_process' ), 10, 2 );
    }
}
