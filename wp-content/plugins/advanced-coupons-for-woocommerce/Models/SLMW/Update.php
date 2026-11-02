<?php
namespace ACFWP\Models\SLMW;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Deactivatable_Interface;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\SLMW\License;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of extending the coupon system of woocommerce.
 * It houses the logic of handling coupon url.
 * Public Model.
 *
 * @since 2.0
 */
class Update implements Model_Interface, Initiable_Interface, Deactivatable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that holds the single main instance of URL_Coupon.
     *
     * @since 2.0
     * @access private
     * @var Update
     */
    private static $_instance;

    /**
     * Model that houses all the plugin constants.
     *
     * @since 2.0
     * @access private
     * @var Plugin_Constants
     */
    private $_constants;

    /**
     * Property that houses all the helper functions of the plugin.
     *
     * @since 2.0
     * @access private
     * @var Helper_Functions
     */
    private $_helper_functions;

    /**
     * Property that holds the single main instance of License.
     *
     * @since 3.6.0
     * @access private
     * @var License
     */
    private $_license;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 2.0
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        $this->_constants        = $constants;
        $this->_helper_functions = $helper_functions;
        $this->_license          = License::get_instance( $main_plugin, $constants, $helper_functions );

        $main_plugin->add_to_all_plugin_models( $this );
    }

    /**
     * Ensure that only one instance of this class is loaded or can be loaded ( Singleton Pattern ).
     *
     * @since 2.0
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     * @return Update
     */
    public static function get_instance( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        if ( ! self::$_instance instanceof self ) {
            self::$_instance = new self( $main_plugin, $constants, $helper_functions );
        }

        return self::$_instance;
    }

    /**
     * Process the plugin update.
     *
     * @since 4.0.1.1
     * @access public
     *
     * @param object|false $update      Update data.
     * @param object       $plugin_data Plugin data.
     * @param string       $plugin_file Plugin file.
     */
    public function process_update_plugin( $update, $plugin_data, $plugin_file ) {
        // Skip if the update is not for this plugin.
        if ( $plugin_file !== $this->_constants->PLUGIN_BASENAME ) {
            return $update;
        }

        // Ping plugin for new version.
        $this->ping_for_new_version();

        // Inject new update data if there are any.
        return $this->inject_plugin_update();
    }

    /**
     * Ping plugin for new version. Ping static file first, if indeed new version is available, trigger update data request.
     *
     * @since 2.0
     * @since 1.8   Refactor and improve support for multisite setup.
     * @since 2.7.2 Add flag to force check new version and fetch update data.
     * @access public
     *
     * @param bool $force Flag to force ping new version.
     * @return string|null Version number if new version is available, null otherwise.
     */
    public function ping_for_new_version( $force = false ) {
        $license_activated = get_site_option( $this->_constants->OPTION_LICENSE_ACTIVATED );

        if ( 'yes' !== $license_activated ) {
            delete_site_option( $this->_constants->OPTION_UPDATE_DATA );
            return;
        }

        $retrieving_update_data = get_site_option( $this->_constants->OPTION_RETRIEVING_UPDATE_DATA );

        if ( 'yes' === $retrieving_update_data ) {
            return;
        }

        /**
         * Only attempt to get the existing saved update data when the operation is not forced.
         * Else, if it is forced, we ignore the existing update data if any
         * and forcefully fetch the latest update data from our server.
         *
         * @since 2.7.2
         */
        $update_data = ! $force ? get_site_option( $this->_constants->OPTION_UPDATE_DATA ) : null;

        /**
         * Even if the update data is still valid, we still go ahead and do static json file ping.
         * The reason is on WooCommerce 3.3.x , it seems WooCommerce do not regenerate the download url every time you change the downloadable zip file on WooCommerce store.
         * The side effect is, the download url is still valid, points to the latest zip file, but the update info could be outdated coz we check that if the download url
         * is still valid, we don't check for update info, and since the download url will always be valid even after subsequent release of the plugin coz WooCommerce is reusing the url now
         * then there will be a case our update info is outdated. So that is why we still need to check the static json file, even if update info is still valid.
         */

        $option = array(
            'timeout' => 10, // seconds coz only static json file ping.
            'headers' => array( 'Accept' => 'application/json' ),
        );

        $response = wp_remote_retrieve_body( wp_remote_get( apply_filters( 'acfw_plugin_new_version_ping_url', $this->_constants->STATIC_PING_FILE ), $option ) );
        $response = ! empty( $response ) ? json_decode( $response ) : null;

        // Skip if the response is empty.
        if ( ! is_object( $response ) || ! property_exists( $response, 'version' ) ) {
            return;
        }

        $installed_version = get_site_option( $this->_constants->INSTALLED_VERSION );

        if ( ( ! $update_data && version_compare( $response->version, $installed_version, '>' ) ) ||
            ( $update_data && version_compare( $response->version, $update_data->latest_version, '>' ) ) ) {

            update_site_option( $this->_constants->OPTION_RETRIEVING_UPDATE_DATA, 'yes' );

            $activation_email = get_site_option( $this->_constants->OPTION_ACTIVATION_EMAIL );
            $license_key      = get_site_option( $this->_constants->OPTION_LICENSE_KEY );

            // Fetch software product update data.
            $this->_fetch_software_product_update_data( $activation_email, $license_key, home_url() );

            delete_site_option( $this->_constants->OPTION_RETRIEVING_UPDATE_DATA );

        } elseif ( $update_data && version_compare( $update_data->latest_version, $installed_version, '<=' ) ) {
            /**
             * We delete the option data if update is already installed
             * We encountered a bug when updating the plugin via the dashboard updates page
             * The update is successful but the update notice does not disappear
             */
            delete_site_option( $this->_constants->OPTION_UPDATE_DATA );
        }

        return $response->version;
    }

    /**
     * Fetch software product update data.
     *
     * @since 2.0
     * @since 1.8 Refactor and improve support for multisite setup.
     * @access public
     *
     * @param string $activation_email Activation email.
     * @param string $license_key      License key.
     * @param string $site_url         Site url.
     */
    private function _fetch_software_product_update_data( $activation_email, $license_key, $site_url ) {
        $update_check_url = add_query_arg(
            array(
				'activation_email' => urlencode( $activation_email ), // phpcs:ignore
                'license_key'      => $license_key,
                'site_url'         => $site_url,
                'software_key'     => $this->_constants->SOFTWARE_KEY,
                'multisite'        => is_multisite() ? 1 : 0,
            ),
            apply_filters( 'acfw_software_product_update_data_url', $this->_constants->UPDATE_DATA_ENDPOINT )
        );

        $option = array(
            'timeout' => 30, // seconds for worst case the server is choked and takes little longer to get update data ( this is an ajax end point ).
            'headers' => array( 'Accept' => 'application/json' ),
        );

        $response = json_decode( wp_remote_retrieve_body( wp_remote_get( $update_check_url, $option ) ) );

        // Skip if the result is empty.
        if ( empty( $response ) ) {
            return;
        }

        // Process license options data.
        $this->_license->process_license_response( $response, 'update_data' );

        // Fire post product update data hook.
        do_action( 'acfwp_software_product_update_data', $response, $activation_email, $license_key );
    }

    /**
     * Inject plugin update info to plugin update details page.
     * Note, this is only triggered when there is a new update and the "View version <new version here> details" link is clicked.
     * In short, the pure purpose for this is to provide details and info the update info popup.
     *
     * @since 2.0
     * @since 1.8 Refactor and improve support for multisite setup.
     * @access public
     *
     * @param false|object|array $result The result object or array. Default false.
     * @param string             $action The type of information being requested from the Plugin Install API.
     * @param object             $args   Plugin API arguments.
     * @return array Plugin update info.
     */
    public function inject_plugin_update_info( $result, $action, $args ) {
        if ( 'plugin_information' === $action && isset( $args->slug ) && 'advanced-coupons-for-woocommerce' === $args->slug ) {

            $software_update_data = get_site_option( $this->_constants->OPTION_UPDATE_DATA );

            if ( $software_update_data && \version_compare( $software_update_data->latest_version, $this->_constants->VERSION, '>' ) ) {

                $update_info = new \StdClass();

                $update_info->name          = 'Advanced Coupons For WooCommerce';
                $update_info->slug          = 'advanced-coupons-for-woocommerce';
                $update_info->version       = $software_update_data->latest_version;
                $update_info->tested        = $software_update_data->tested_up_to;
                $update_info->last_updated  = $software_update_data->last_updated;
                $update_info->homepage      = $software_update_data->home_page;
                $update_info->author        = sprintf( '<a href="%s" target="_blank">%s</a>', $software_update_data->author_url, $software_update_data->author );
                $update_info->download_link = $software_update_data->download_url;
                $update_info->sections      = array(
                    'description'  => $software_update_data->description,
                    'installation' => $software_update_data->installation,
                    'changelog'    => $software_update_data->changelog,
                    'support'      => $software_update_data->support,
                );

                $update_info->icons = array(
                    '1x'      => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/icon-128x128.png',
                    '2x'      => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/icon-256x256.png',
                    'default' => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/icon-256x256.png',
                );

                $update_info->banners = array(
                    'low'  => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/banner-772x250.jpg',
                    'high' => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/banner-1544x500.jpg',
                );

                return $update_info;

            }
        }

        return $result;
    }

    /**
     * When WordPress fetch 'update_plugins' transient ( Which holds various data regarding plugins, including which have updates ),
     * we inject our plugin update data in, if any. It is saved on $this->_constants->OPTION_UPDATE_DATA option.
     * It is important we dont delete this option until the plugin have successfully updated.
     * The reason is we are hooking ( and we should do it this way ), on transient read.
     * So if we delete this option on first transient read, then subsequent read will not include our plugin update data.
     *
     * It also checks the validity of the update url. There could be edge case where we stored the update data locally as an option,
     * then later on the store, the product was deleted or any action occurred that would deem the update data invalid.
     * So we check if update url is still valid, if not, we remove the locally stored update data.
     *
     * @since 2.0
     * @since 1.2.3
     * Refactor codebase to adapt being called on set_site_transient function.
     * We don't need to check for software update data validity as its already been checked on ping_for_new_version
     * and this function is immediately called right after that.
     * @since 1.8 Refactor and improve support for multisite setup.
     * @access public
     *
     * @return object|false Plugin update data when update is available, false otherwise.
     */
    public function inject_plugin_update() {
        $software_update_data = get_site_option( $this->_constants->OPTION_UPDATE_DATA );

        if ( $software_update_data && \version_compare( $software_update_data->latest_version, $this->_constants->VERSION, '>' ) ) {

            $update = new \stdClass();

            $update->id          = $software_update_data->download_id;
            $update->slug        = 'advanced-coupons-for-woocommerce';
            $update->plugin      = $this->_constants->PLUGIN_BASENAME;
            $update->new_version = $software_update_data->latest_version;
            $update->version     = $software_update_data->latest_version;
            $update->url         = $this->_constants->PLUGIN_SITE_URL;
            $update->package     = $software_update_data->download_url;
            $update->tested      = $software_update_data->tested_up_to;

            $update->icons = array(
                '1x'      => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/icon-128x128.png',
                '2x'      => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/icon-256x256.png',
                'default' => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/icon-256x256.png',
            );

            $update->banners = array(
                '1x'      => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/banner-772x250.jpg',
                '2x'      => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/banner-1544x500.jpg',
                'default' => 'https://ps.w.org/advanced-coupons-for-woocommerce-free/assets/banner-1544x500.jpg',
            );

            return $update;
        }

        return false;
    }

    /**
     * Delete the plugin update data after the plugin successfully updated.
     *
     * References:
     * https://stackoverflow.com/questions/24187990/plugin-update-hook
     * https://codex.wordpress.org/Plugin_API/Action_Reference/upgrader_process_complete
     *
     * @since 2.0
     * @since 1.8 Refactor and improve support for multisite setup.
     * @access public
     *
     * @param Plugin_Upgrader $upgrader_object Plugin_Upgrader instance.
     * @param array           $options         Options.
     */
    public function after_plugin_update( $upgrader_object, $options ) {
        if ( 'update' === $options['action'] && 'plugin' === $options['type'] && isset( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
            foreach ( $options['plugins'] as $each_plugin ) {
                if ( $each_plugin === $this->_constants->PLUGIN_BASENAME ) {
                    delete_site_option( $this->_constants->OPTION_UPDATE_DATA );
                    break;
                }
            }
        }
    }

    /**
     * Force fetch update data.
     * This will be run when the button under "help" section on the settings is clicked.
     *
     * @since 2.7.2
     * @access private
     */
    private function _force_fetch_update_data() {
        // force ping for new version.
        // refetch update data when new version is higher than current version installed.
        $ping_version = $this->ping_for_new_version( true );

        // If the version is less than or equal to the current version installed, then return success.
        if ( $ping_version && version_compare( $ping_version, $this->_constants->VERSION, '<=' ) ) {
            return array(
                'status'  => 'info',
                'message' => __( 'There is no new version available for the Advanced Coupons Premium plugin.', 'advanced-coupons-for-woocommerce' ),
            );
        }

        // get the key and formatted update data value.
        $result = $this->inject_plugin_update();

        if ( is_object( $result ) ) {

            // get update_plugins transient value via get_site_option so we don't trigger any hooks.
            $update_plugins = get_site_option( '_site_transient_update_plugins' );

            /**
             * Overwrite update data for our plugin.
             * We make sure that response property is present first before overwriting.
             */
            if ( $update_plugins && isset( $update_plugins->response ) && is_array( $update_plugins->response ) ) {

                $update_plugins->response[ $this->_constants->PLUGIN_BASENAME ] = $result;

                // save update_plugins transient value via update_site_options so we don't trigger any hooks.
                update_site_option( '_site_transient_update_plugins', $update_plugins );

                return array(
                    'status'  => 'success',
                    'message' => __( 'Plugin update data has been refetched successfully.', 'advanced-coupons-for-woocommerce' ),
                );
            }

            return array(
                'status'  => 'warning',
                'message' => __( 'Plugin updates transient is not yet present. Please visit Dashboard > Updates page and try again.', 'advanced-coupons-for-woocommerce' ),
            );

        }

        return array(
            'status'    => 'fail',
            'error_msg' => __( 'There was an issue trying to refetch the update data. Please make sure that there is an available update and that your license is activated.', 'advanced-coupons-for-woocommerce' ),
        );
    }

    /**
     * Validate the current version of plugin from the new version available on the update data list.
     * When the new version is less than or equal to the current version installed, then remove the plugin from the
     * list of available updates notice.
     *
     * @since 3.0.1
     * @access public
     *
     * @param object $update_plugins Update plugins transient data.
     * @return object Filtered updated plugins transient data.
     */
    public function validate_current_and_update_data_versions( $update_plugins ) {
        // only run code when update plugins data is available and ACFWP plugin is on the response list.
        if (
            is_object( $update_plugins ) &&
            property_exists( $update_plugins, 'response' ) &&
            $update_plugins->response &&
            isset( $update_plugins->response[ $this->_constants->PLUGIN_BASENAME ] )
        ) {

            // get the ACFWP data.
            $acfwp_data = $update_plugins->response[ $this->_constants->PLUGIN_BASENAME ];

            // compare versions and unset from response list if new version is less than or equal to current version installed.
            if ( \version_compare( $acfwp_data->new_version, $this->_constants->VERSION, '<=' ) ) {
                unset( $update_plugins->response[ $this->_constants->PLUGIN_BASENAME ] );
            }
        }

        return $update_plugins;
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX Functions.
    |--------------------------------------------------------------------------
     */

    /**
     * AJAX clear update data.
     *
     * @since 3.7.2
     * @access public
     */
    public function ajax_refetch_update_data() {
        $check = $this->_helper_functions->validate_ajax_request(
            array(
                'nonce_value_key' => 'nonce',
                'nonce_action'    => 'acfwp_slmw_refetch_update_data',
                'user_capability' => 'manage_woocommerce',
            )
        );

        // Skip if the AJAX request is not valid.
        if ( is_wp_error( $check ) ) {
            $response = array(
                'status'         => 'fail',
                'message'        => $check->get_error_message(),
                'license_status' => '',
            );
        } else {
            $response = $this->_force_fetch_update_data();
        }

        wp_send_json( $response );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run plugin deactivation.
     *
     * @since 2.0
     * @access public
     * @implements ACFWP\Interfaces\Deactivatable_Interface
     */
    public function deactivate() {
        // Delete plugin update option data.
        delete_site_option( $this->_constants->OPTION_UPDATE_DATA );
    }

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 2.7.2
     * @access public
     * @implements ACFWP\Interfaces\Initializable_Interface
     */
    public function initialize() {
        add_action( 'wp_ajax_acfwp_slmw_refetch_update_data', array( $this, 'ajax_refetch_update_data' ) );
    }

    /**
     * Execute Update class.
     *
     * @since 2.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_filter( 'update_plugins_advancedcouponsplugin.com', array( $this, 'process_update_plugin' ), 10, 3 );
        add_action( 'upgrader_process_complete', array( $this, 'after_plugin_update' ), 10, 2 );
        add_filter( 'site_transient_update_plugins', array( $this, 'validate_current_and_update_data_versions' ) );
        add_filter( 'plugins_api', array( $this, 'inject_plugin_update_info' ), 10, 3 );
    }
}
