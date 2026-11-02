<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;

use ACFWP\Interfaces\Model_Interface;
use ACFWP\Interfaces\Activatable_Interface;
use ACFWP\Interfaces\Deactivatable_Interface;
use ACFWP\Interfaces\Initiable_Interface;

use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Helpers\Helper_Functions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of 'Bootstraping' the plugin.
 *
 * @since 2.0
 */
class Bootstrap implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
    */

    /**
     * Property that holds the single main instance of Bootstrap.
     *
     * @since 2.0
     * @access private
     * @var Bootstrap
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
     * Array of models implementing the ACFWP\Interfaces\Activatable_Interface.
     *
     * @since 2.0
     * @access private
     * @var array
     */
    private $_activatables;

    /**
     * Array of models implementing the ACFWP\Interfaces\Initiable_Interface.
     *
     * @since 2.0
     * @access private
     * @var array
     */
    private $_initiables;

    /**
     * Array of models implementing the ACFWP\Interfaces\Deactivatable_Interface.
     *
     * @since 2.0
     * @access private
     * @var array
     */
    private $_deactivatables;




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
     * @param array                      $activatables     Array of models implementing ACFWP\Interfaces\Activatable_Interface.
     * @param array                      $initiables       Array of models implementing ACFWP\Interfaces\Initiable_Interface.
     * @param array                      $deactivatables   Array of models implementing ACFWP\Interfaces\Deactivatable_Interface.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions, array $activatables = array(), array $initiables = array(), array $deactivatables = array() ) {
        $this->_constants        = $constants;
        $this->_helper_functions = $helper_functions;
        $this->_activatables     = $activatables;
        $this->_initiables       = $initiables;
        $this->_deactivatables   = $deactivatables;

        $main_plugin->add_to_all_plugin_models( $this );
        $this->_register_custom_database_tables();
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
     * @param array                      $activatables     Array of models implementing ACFWP\Interfaces\Activatable_Interface.
     * @param array                      $initiables       Array of models implementing ACFWP\Interfaces\Initiable_Interface.
     * @param array                      $deactivatables   Array of models implementing ACFWP\Interfaces\Deactivatable_Interface.
     * @return Bootstrap
     */
    public static function get_instance( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions, array $activatables = array(), array $initiables = array(), array $deactivatables = array() ) {
        if ( ! self::$_instance instanceof self ) {
            self::$_instance = new self( $main_plugin, $constants, $helper_functions, $activatables, $initiables, $deactivatables );
        }

        return self::$_instance;
    }

    /**
     * Load plugin text domain.
     *
     * @since 2.0
     * @access public
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain( $this->_constants->TEXT_DOMAIN, false, $this->_constants->PLUGIN_DIRNAME . '/languages' );
    }

    /**
     * Register custom database tables through WC filter.
     *
     * @since 3.5.2
     * @access public
     */
    private function _register_custom_database_tables() {
        global $wpdb;

        $custom_tables = array(
            $this->_constants->VIRTUAL_COUPONS_DB_NAME, // virtual coupon codes db.
        );

        foreach ( $custom_tables as $table ) {
            $wpdb->$table   = $wpdb->prefix . $table;
            $wpdb->tables[] = $table;
        }
    }

    /**
     * Method that houses the logic relating to activating the plugin.
     *
     * @since 2.0
     * @access public
     *
     * @global wpdb $wpdb Object that contains a set of functions used to interact with a database.
     *
     * @param boolean $network_wide Flag that determines whether the plugin has been activated network wid ( on multi site environment ) or not.
     */
    public function activate_plugin( $network_wide ) {
        global $wpdb;

        if ( is_multisite() ) {

            update_site_option( $this->_constants->INSTALLED_VERSION, $this->_constants->VERSION );
            update_site_option( $this->_constants->OPTION_ACFWP_ACTIVATION_CODE_TRIGGERED, 'yes' );

            if ( $network_wide ) {

                // get ids of all sites.
                $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

                foreach ( $blog_ids as $blog_id ) {

                    switch_to_blog( $blog_id );
                    $this->_activate_plugin( $blog_id );

                }
                restore_current_blog();

            } else {
                $this->_activate_plugin( $wpdb->blogid ); // activated on a single site, in a multi-site.
            }
        } else {
            $this->_activate_plugin( $wpdb->blogid ); // activated on a single site.
        }
    }

    /**
     * Method to initialize the plugin in a newly created site in a multi site set up.
     *
     * @since 2.0
     * @access public
     *
     * @param int    $blog_id Blog ID of the created blog.
     * @param int    $user_id User ID of the user creating the blog.
     * @param string $domain Domain used for the new blog.
     * @param string $path Path to the new blog.
     * @param int    $site_id Site ID. Only relevant on multi-network installs.
     * @param array  $meta Meta data. Used to set initial site options.
     */
    public function new_mu_site_init( $blog_id, $user_id, $domain, $path, $site_id, $meta ) {
        if ( $this->_helper_functions->is_plugin_active_for_network( 'advanced-coupons-for-woocommerce/advanced-coupons-for-woocommerce.php' ) ) {

            switch_to_blog( $blog_id );
            $this->_activate_plugin( $blog_id );
            restore_current_blog();

        }
    }

    /**
     * Initialize plugin settings options.
     * This is a compromise to my idea of 'Modularity'. Ideally, bootstrap should not take care of plugin settings stuff.
     * However due to how WooCommerce do its thing, we need to do it this way. We can't separate settings on its own.
     *
     * @since 2.0
     * @access private
     */
    private function _initialize_plugin_settings_options() {
        // Help settings section options.

        // Set initial value of 'no' for the option that sets the option that specify whether to delete the options on plugin uninstall. Optionception.
        if ( ! get_option( $this->_constants->OPTION_CLEAN_UP_PLUGIN_OPTIONS, false ) ) {
            update_option( $this->_constants->OPTION_CLEAN_UP_PLUGIN_OPTIONS, 'no', false );
        }
    }

    /**
     * Reconcile the autoload flag on plugin-managed options that should not be autoloaded.
     *
     * `update_option()` only honors the $autoload argument on insert, so updating the
     * call sites alone does not fix existing installs. This runs on activation and on
     * every version mismatch (via Bootstrap::initialize()).
     *
     * @since 4.0.8
     *
     * @return void
     */
    private function _set_plugin_options_autoload() {
        $autoload_map = array(
            $this->_constants->OPTION_CLEAN_UP_PLUGIN_OPTIONS => 'no',
            $this->_constants->SHOW_GETTING_STARTED_NOTICE => 'no',
            $this->_constants->GETTING_STARTED_PREMIUM_SHOWN => 'no',
            $this->_constants->SHOW_NEW_UPDATE_NOTICE      => 'no',
            $this->_constants->VIRTUAL_COUPONS_DB_CREATED  => 'no',
            // Literal mirrors BOGO\Migration::BOGO_MIGRATION_NOTICE — keep in sync if renamed.
            'acfwp_bogo_migration_notice'                  => 'no',
        );

        if ( function_exists( 'wp_set_option_autoload_values' ) ) {
            wp_set_option_autoload_values( $autoload_map );
            return;
        }

        // Fallback for WP < 6.4: group option names by autoload value and run one batched UPDATE per group.
        $names_by_autoload = array();
        foreach ( $autoload_map as $option_name => $autoload ) {
            $names_by_autoload[ $autoload ][] = $option_name;
        }

        global $wpdb;
        foreach ( $names_by_autoload as $autoload => $option_names ) {
            $placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );
            // Placeholder count is variable; the IN-clause mask is built from a fixed format string with no user input. Safe to interpolate.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET autoload = %s WHERE option_name IN ({$placeholders})", array_merge( array( $autoload ), $option_names ) ) );
        }
        wp_cache_delete( 'alloptions', 'options' );
    }

    /**
     * Actual function that houses the code to execute on plugin activation.
     *
     * @since 2.0
     * @access private
     *
     * @param int $blogid Blog ID of the created blog.
     */
    private function _activate_plugin( $blogid ) {
        // Initialize settings options.
        $this->_initialize_plugin_settings_options();

        // Reconcile autoload flags for plugin-managed options (handles existing installs).
        $this->_set_plugin_options_autoload();

        // show the getting started notice when plugin is activated for the first time.
        if ( get_option( $this->_constants->INSTALLED_VERSION, false ) === false && get_option( $this->_constants->GETTING_STARTED_PREMIUM_SHOWN ) !== 'yes' ) {
            update_option( $this->_constants->SHOW_GETTING_STARTED_NOTICE, 'yes', false );
            update_option( $this->_constants->GETTING_STARTED_PREMIUM_SHOWN, 'yes', false );
        }

        // Execute 'activate' contract of models implementing ACFWP\Interfaces\Activatable_Interface.
        foreach ( $this->_activatables as $activatable ) {
            if ( $activatable instanceof Activatable_Interface ) {
                $activatable->activate();
            }
        }

        flush_rewrite_rules();

        update_option( $this->_constants->INSTALLED_VERSION, $this->_constants->VERSION );
        update_option( $this->_constants->OPTION_ACFWP_ACTIVATION_CODE_TRIGGERED, 'yes' );
    }

    /**
     * Method that houses the logic relating to deactivating the plugin.
     *
     * @since 2.0
     * @access public
     *
     * @global wpdb $wpdb Object that contains a set of functions used to interact with a database.
     *
     * @param boolean $network_wide Flag that determines whether the plugin has been activated network wid ( on multi site environment ) or not.
     */
    public function deactivate_plugin( $network_wide ) {
        global $wpdb;

        // check if it is a multisite network.
        if ( is_multisite() ) {

            // check if the plugin has been activated on the network or on a single site.
            if ( $network_wide ) {

                // get ids of all sites.
                $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

                foreach ( $blog_ids as $blog_id ) {

                    switch_to_blog( $blog_id );
                    $this->_deactivate_plugin( $wpdb->blogid );

                }

                restore_current_blog();

            } else {
                $this->_deactivate_plugin( $wpdb->blogid ); // activated on a single site, in a multi-site.
            }
        } else {
            $this->_deactivate_plugin( $wpdb->blogid ); // activated on a single site.
        }
    }

    /**
     * Actual method that houses the code to execute on plugin deactivation.
     *
     * @since 2.0
     * @access private
     *
     * @param int $blogid Blog ID of the created blog.
     */
    private function _deactivate_plugin( $blogid ) {
        // Execute 'deactivate' contract of models implementing ACFWP\Interfaces\Deactivatable_Interface.
        foreach ( $this->_deactivatables as $deactivatable ) {
            if ( $deactivatable instanceof Deactivatable_Interface ) {
                $deactivatable->deactivate();
            }
        }

        flush_rewrite_rules();
    }

    /**
     * Method that houses codes to be executed on init hook.
     *
     * @since 2.0
     * @access public
     */
    public function initialize() {
        /**
         * Execute activation codebase if not yet executed.
         * This occurs when plugin is activated and plugin requirements fails.
         * It just enables the plugin but won't execute activation code.
         * When you meet the plugin requirements, since the plugin is already enabled, it won't execute the
         * activation codebase anymore.
         *
         * Also there are cases where activation codebase isn't executed after update.
         * This ensures the activation codebase is executed after plugin update.
         */
        if ( version_compare( get_option( $this->_constants->INSTALLED_VERSION, false ), $this->_constants->VERSION, '!=' ) ||
            get_option( $this->_constants->OPTION_ACFWP_ACTIVATION_CODE_TRIGGERED, false ) !== 'yes'
        ) {

            $network_wide = $this->_helper_functions->is_plugin_active_for_network( 'advanced-coupons-for-woocommerce/advanced-coupons-for-woocommerce.php' );
            $this->activate_plugin( $network_wide );
        }

        // Execute 'initialize' contract of models implementing ACFWP\Interfaces\Initiable_Interface.
        foreach ( $this->_initiables as $initiable ) {
            if ( $initiable instanceof Initiable_Interface ) {
                $initiable->initialize();
            }
        }
    }

    /**
     * Add settings link to plugin action links.
     *
     * @since 2.0
     * @access public
     *
     * @param array $links Array of plugin action links.
     * @return array Array of modified plugin action links.
     */
    public function plugin_settings_action_link( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=acfw-settings' ) . '">' . __( 'Settings', 'advanced-coupons-for-woocommerce' ) . '</a>';
        array_unshift( $links, $settings_link );

        return $links;
    }

    /**
     * Initialize REST API Controllers.
     *
     * @since 2.0
     * @access public
     *
     * @return void
     */
    public function rest_api_init() {
    }

    /**
     * Declare WooCommerce features compatibility.
     *
     * Covers high performance order storage, the cart and checkout blocks, and
     * product instance caching.
     *
     * @since 3.5.6
     * @since 4.1 Renamed from declare_hpos_compatibility. Added the cart and checkout blocks and product instance caching declarations.
     * @access public
     */
    public function declare_woocommerce_features_compatibility() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            // Declare that the plugin is compatible with hpos feature.
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                $this->_constants->MAIN_PLUGIN_FILE_PATH,
                true
            );

            // Declare that the plugin is compatible with the cart and checkout blocks feature.
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                $this->_constants->MAIN_PLUGIN_FILE_PATH,
                true
            );

            // Declare that the plugin is compatible with the product instance caching feature.
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'product_instance_caching',
                $this->_constants->MAIN_PLUGIN_FILE_PATH,
                true
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Interface contract functions
    |--------------------------------------------------------------------------
    */

    /**
     * Execute plugin bootstrap code.
     *
     * @since 2.0
     * @access public
     */
    public function run() {
        // Internationalization.
        add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

        // Execute plugin activation/deactivation.
        register_activation_hook( $this->_constants->MAIN_PLUGIN_FILE_PATH, array( $this, 'activate_plugin' ) );
        register_deactivation_hook( $this->_constants->MAIN_PLUGIN_FILE_PATH, array( $this, 'deactivate_plugin' ) );

        // Execute plugin initialization ( plugin activation ) on every newly created site in a multi site set up.
        add_action( 'wpmu_new_blog', array( $this, 'new_mu_site_init' ), 10, 6 );

        // Execute codes that need to run on 'init' hook.
        add_action( 'init', array( $this, 'initialize' ) );

        // Add settings link to plugin action links.
        add_filter( 'plugin_action_links_' . $this->_constants->PLUGIN_BASENAME, array( $this, 'plugin_settings_action_link' ), 10, 2 );

        // Initialize API.
        add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );

        // Declare WooCommerce features compatibility.
        add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_features_compatibility' ) );
    }
}
