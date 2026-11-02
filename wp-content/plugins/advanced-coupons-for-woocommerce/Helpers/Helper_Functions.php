<?php
namespace ACFWP\Helpers;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses all the helper functions of the plugin.
 *
 * 1.0.0
 */
class Helper_Functions {
    /*
    |--------------------------------------------------------------------------
    | Traits
    |--------------------------------------------------------------------------
     */
    use \ACFWP\Traits\Singleton;
    use \ACFWP\Helpers\Traits\I18nUtil;
    use \ACFWP\Helpers\Traits\Coupon;
    use \ACFWP\Helpers\Traits\DateTime;
    use \ACFWP\Helpers\Traits\Product_Category;
    use \ACFWP\Helpers\Traits\Block;

    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Model that houses all the plugin constants.
     *
     * @since 2.0
     * @access private
     * @var Plugin_Constants
     */
    private $_constants;

    /**
     * Property that holds the shipping zones data after queried.
     *
     * @since 2.7.3
     * @access private
     * @var array
     */
    private $_shipping_zones = array();

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
     * @param Abstract_Main_Plugin_Class|null $main_plugin Main plugin object.
     * @param Plugin_Constants|null           $constants   Plugin constants object.
     */
    public function __construct( ?Abstract_Main_Plugin_Class $main_plugin = null, ?Plugin_Constants $constants = null ) {
        $this->_constants = $constants;

        if ( $main_plugin ) {
            $main_plugin->add_to_public_helpers( $this );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Functions
    |--------------------------------------------------------------------------
     */

    /**
     * Write data to plugin log file.
     *
     * @since 2.0
     * @access public
     *
     * @param mixed $log Data to log.
     */
    public function write_debug_log( $log ) {
        error_log( "\n[" . current_time( 'mysql' ) . "]\n" . $log . "\n--------------------------------------------------\n", 3, $this->_constants->LOGS_ROOT_PATH . 'debug.log' ); // phpcs:ignore
    }

    /**
     * Check if current user is authorized to manage the plugin on the backend.
     *
     * @since 2.0
     * @access public
     *
     * @param WP_User $user WP_User object.
     * @return boolean True if authorized, False otherwise.
     */
    public function current_user_authorized( $user = null ) {
        // Array of roles allowed to access/utilize the plugin.
        $admin_roles = apply_filters( 'ucfw_admin_roles', array( 'administrator' ) );

        if ( is_null( $user ) ) {
            $user = wp_get_current_user();
        }

        if ( $user->ID ) {
            return count( array_intersect( (array) $user->roles, $admin_roles ) ) ? true : false;
        } else {
            return false;
        }
    }

    /**
     * Returns the timezone string for a site, even if it's set to a UTC offset
     *
     * Adapted from http://www.php.net/manual/en/function.timezone-name-from-abbr.php#89155
     *
     * Reference:
     * http://www.skyverge.com/blog/down-the-rabbit-hole-wordpress-and-timezones/
     *
     * @since 2.0
     * @access public
     *
     * @return string Valid PHP timezone string
     */
    public function get_site_current_timezone() {
        // if site timezone string exists, return it.
        $timezone = trim( get_option( 'timezone_string' ) );
        if ( $timezone ) {
            return $timezone;
        }

        // get UTC offset, if it isn't set then return UTC.
        $utc_offset = trim( get_option( 'gmt_offset', 0 ) );

        if ( filter_var( $utc_offset, FILTER_VALIDATE_INT ) === 0 || '' === $utc_offset || is_null( $utc_offset ) ) {
            return 'UTC';
        }

        return $this->convert_utc_offset_to_timezone( $utc_offset );
    }

    /**
     * Convert UTC offset to timezone.
     *
     * @since 1.2.0
     * @access public
     *
     * @param int $utc_offset UTC offset.
     * @return string valid PHP timezone string
     */
    public function convert_utc_offset_to_timezone( $utc_offset ) {
        // adjust UTC offset from hours to seconds.
        $utc_offset *= 3600;

        // attempt to guess the timezone string from the UTC offset.
        $timezone = timezone_name_from_abbr( '', $utc_offset, 0 );
        if ( $timezone ) {
            return $timezone;
        }

        // last try, guess timezone string manually.
        $is_dst = date( 'I' ); // phpcs:ignore

        foreach ( timezone_abbreviations_list() as $abbr ) {
            foreach ( $abbr as $city ) {
                if ( $city['dst'] === $is_dst && $city['offset'] === $utc_offset ) {
                    return $city['timezone_id'];
                }
            }
        }

        // fallback to UTC.
        return 'UTC';
    }

    /**
     * Get date and time format value from WP general settings.
     *
     * @since 2.5.1
     * @access public
     *
     * @param string $default_date Default date format.
     * @param string $default_time Default time format.
     */
    public function get_datetime_format( $default_date = 'F j, Y', $default_time = 'g:i a' ) {
        return get_option( 'date_format', $default_date ) . ' ' . get_option( 'time_format', $default_time );
    }

    /**
     * Get the days interval between the provided date and the current date.
     *
     * @since 3.6.0
     * @access public
     *
     * @param \DateTime $compare_datetime  Date to compare.
     * @return int Days interval (positive for future date, negative for past date).
     */
    public function get_days_interval( $compare_datetime ) {
        $current_datetime = new \WC_DateTime( 'now', $compare_datetime->getTimezone() );
        $interval         = $current_datetime->diff( $compare_datetime );
        $days_interval    = intval( $interval->format( '%r%a' ) );

        // If the days interval is zero, then we either set the interval to 1 or -1 based on the compare date value.
        // This is so the current day is not considered as a past/future date.
        if ( 0 === $days_interval ) {
            $days_interval = $compare_datetime > $current_datetime ? 1 : -1;
        }

        return $days_interval;
    }

    /**
     * Validate the interval of days of the provided date versus the current date.
     *
     * @since 3.6.0
     * @access public
     *
     * @param string|\WC_DateTime $date         Date to compare (mysql date format).
     * @param int                 $days_interval Days interval (positive for future date, negative for past date).
     * @param string              $context       Context of the interval (within or beyond).
     * @return bool True if the license is expired within or beyond the interval, false otherwise.
     */
    public function is_days_interval_valid( $date, $days_interval = 0, $context = 'within' ) {
        $datetime        = $date instanceof \WC_DateTime ? $date : new \WC_DateTime( $date, new \DateTimeZone( 'UTC' ) );
        $days_difference = $this->get_days_interval( $datetime, new \DateTimeZone( 'UTC' ) );

        /**
         * If the days interval is negative, then the compare date value should be a past date.
         * We need to reverse the days difference and days interval to make the comparison valid.
         */
        if ( 0 > $days_interval ) {
            $days_difference = $days_difference * -1;
            $days_interval   = abs( $days_interval );
        }

        // If the days difference is negative, then the compare date value is not yet the intended past/future date.
        if ( 0 > $days_difference ) {
            return false;
        }

        if ( 'within' === $context ) {
            return $days_difference <= $days_interval;
        }

        return $days_difference > $days_interval;
    }

    /**
     * Get all user roles.
     *
     * @since 2.0
     * @access public
     *
     * @global WP_Roles $wp_roles Core class used to implement a user roles API.
     *
     * @return array Array of all site registered user roles. User role key as the key and value is user role text.
     */
    public function get_all_user_roles() {
        global $wp_roles;
        return $wp_roles->get_names();
    }

    /**
     * Check validity of a save post action.
     *
     * @since 2.0
     * @access private
     *
     * @param int    $post_id   Id of the coupon post.
     * @param string $post_type Post type to check.
     * @return bool True if valid save post action, False otherwise.
     */
    public function check_if_valid_save_post_action( $post_id, $post_type ) {
        if ( wp_is_post_autosave( $post_id )
            || wp_is_post_revision( $post_id )
            || ! current_user_can( 'edit_page', $post_id )
            || get_post_type() !== $post_type
            || empty( $_POST ) ) {  // phpcs:ignore
            return false;
        } else {
            return true;
        }
    }

    /**
     * Check if REST API request is valid.
     *
     * @deprecated 3.5.7
     *
     * @since 3.0
     * @access public
     *
     * @param \WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error True if the request has read access for the item, WP_Error object otherwise.
     */
    public function check_if_valid_api_request( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        wc_deprecated_function( __METHOD__, '3.5.7' );
        return true;
    }

    /**
     * Utility function that determines if a plugin is active or not.
     * Reference: https://developer.wordpress.org/reference/functions/is_plugin_active/
     *
     * @since 2.0
     * @access public
     *
     * @param string $plugin_basename Plugin base name. Ex. woocommerce/woocommerce.php.
     * @return boolean Returns true if active, false otherwise.
     */
    public function is_plugin_active( $plugin_basename ) {
        // Makes sure the plugin is defined before trying to use it.
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active( $plugin_basename );
    }

    /**
     * Utility function that determines if a plugin is installed or not.
     *
     * @since 2.7.1
     * @access public
     *
     * @param string $plugin_basename Plugin base name. Ex. woocommerce/woocommerce.php.
     * @return boolean True if active, false otherwise.
     */
    public function is_plugin_installed( $plugin_basename ) {
        $plugin_file_path = trailingslashit( WP_PLUGIN_DIR ) . plugin_basename( $plugin_basename );
        return file_exists( $plugin_file_path );
    }

    /**
     * Utility function that determines whether the plugin is active for the entire network.
     * Reference: https://developer.wordpress.org/reference/functions/is_plugin_active_for_network/
     *
     * @since 2.0
     * @access public
     *
     * @param string $plugin_basename Plugin base name. Ex. woocommerce/woocommerce.php.
     * @return boolean Returns true if active for the entire network, false otherwise.
     */
    public function is_plugin_active_for_network( $plugin_basename ) {
        // Makes sure the function is defined before trying to use it.
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network( $plugin_basename );
    }

    /**
     * Gets the ACFWF helper class.
     *
     * @since 2.0
     * @access public
     *
     * @return \ACFWF\Helpers\Helper_Function
     */
    public function acfwf_helper() {
        return \ACFWF()->helpers['Helper_Functions'];
    }

    /**
     * Get advanced coupon object.
     *
     * @since 2.0
     * @access public
     *
     * @param mixed $code WC_Coupon ID, code or object.
     * @return Advanced_Coupon
     */
    public function get_advanced_coupon( $code ) {
        return new Advanced_Coupon( $code );
    }

    /**
     * Generate random string.
     *
     * @since 1.9
     * @access public
     *
     * @param int $length String length.
     * @return string Random string.
     */
    public function random_str( $length ) {
        $x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        return substr( str_shuffle( str_repeat( $x, ceil( $length / strlen( $x ) ) ) ), 1, $length );
    }

    /**
     * Get scheduler date field value.
     *
     * @since 2.1
     * @access public
     *
     * @deprecated 3.5 Moved to ACFWF plugin.
     *
     * @param array|string $field Field post value.
     * @return string Date string value (Y-m-d H:i:s).
     */
    public function get_scheduler_date_field_value( $field ) {
        wc_deprecated_function( 'ACFWP\Helpers\Helper_Functions::' . __FUNCTION__, '3.5', 'ACFWF\Helpers\Helper_Functions::get_scheduler_date_field_value' );
        return \ACFWF()->Helper_Functions->get_scheduler_date_field_value( $field );
    }

    /**
     * Get customer display name.
     *
     * @since 3.0
     * @access public
     *
     * @param int|WC_Customer $cid Customer ID.
     * @return string Customer name.
     */
    public function get_customer_name( $cid ) {
        $customer      = $cid instanceof \WC_Customer ? $cid : new \WC_Customer( $cid );
        $customer_name = sprintf( '%s %s', $customer->get_first_name(), $customer->get_last_name() );

        return trim( $customer_name );
    }

    /**
     * Get customer display email.
     *
     * @since 3.0
     * @access public
     *
     * @param int|WC_Customer $cid Customer ID.
     * @return string Customer email.
     */
    public function get_customer_email( $cid ) {
        $customer = $cid instanceof \WC_Customer ? $cid : new \WC_Customer( $cid );
        return $customer->get_billing_email() ? $customer->get_billing_email() : $customer->get_email();
    }

    /**
     * Get shipping zones data.
     * This helper is intended to prevent duplicate queries when fetching the shipping zones data.
     *
     * @since 2.7.3
     * @access public
     *
     * @return array WC Shipping zones data.
     */
    public function get_shipping_zones() {
        if ( empty( $this->_shipping_zones ) ) {
            $this->_shipping_zones = \WC_Shipping_Zones::get_zones();
        }

        return $this->_shipping_zones;
    }

    /**
     * Check if customer is applying a coupon.
     *
     * @since 3.1.1
     * @access public
     *
     * @return bool True if applying coupon, false otherwise.
     */
    public function is_apply_coupon() {
        $is_apply_coupon = false;

        if ( did_action( 'acfw_before_apply_coupon' ) // Check if coupon is applied via URL.
            || ( isset( $_REQUEST['wc-ajax'] ) && 'apply_coupon' === $_REQUEST['wc-ajax'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ) {
            $is_apply_coupon = true;
        }

        return apply_filters( 'acfwp_is_apply_coupon', $is_apply_coupon );
    }

    /**
     * Get the activation URL for a plugin dependency.
     *
     * @since 3.5.3
     * @access public
     *
     * @param string $plugin_basename Plugin basename.
     * @return string Nonced plugin activation URL.
     */
    public function get_plugin_dependency_activation_url( $plugin_basename ) {
        $url_prefix = is_multisite() && $this->is_plugin_active_for_network( $this->_constants->PLUGIN_BASENAME ) ? network_admin_url() : admin_url();

        return wp_nonce_url(
            $url_prefix . 'plugins.php?action=activate&amp;plugin=' . $plugin_basename . '&amp;plugin_status=all&amp;s',
            'activate-plugin_' . $plugin_basename
        );
    }

    /**
     * Get the install or update URL for a given plugin dependency.
     *
     * @since 3.5.3
     * @access public
     *
     * @param string $plugin_key Plugin key for install or basename for update.
     * @param bool   $is_upgrade Flag if URL is for upgrade or not.
     * @return string Nonced plugin install URL.
     */
    public function get_plugin_dependency_install_url( $plugin_key, $is_upgrade = false ) {
        $url_prefix = is_multisite() ? network_admin_url() : admin_url();
        $action     = $is_upgrade ? 'upgrade-plugin' : 'install-plugin';

        return wp_nonce_url(
            $url_prefix . 'update.php?action=' . $action . '&amp;plugin=' . $plugin_key,
            $action . '_' . $plugin_key
        );
    }

    /**
     * Load templates in an overridable manner.
     *
     * @since 3.5.3
     * @access public
     *
     * @param string $template Template path.
     * @param array  $args     Options to pass to the template.
     * @param string $path     Default template path.
     */
    public function load_template( $template, $args = array(), $path = '' ) {
        $path = $path ? $path : $this->_constants->TEMPLATES_ROOT_PATH;
        wc_get_template( $template, $args, '', $path );
    }

    /**
     * Check if the current request is a store API request.
     *
     * @since 3.5.9
     * @access public
     *
     * @return bool True if store API request, false otherwise.
     */
    public function is_store_api_request() {
        return str_contains( $_SERVER['REQUEST_URI'], 'wc/store/v1' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    }

    /**
     * Validate AJAX request.
     *
     * @since 3.6.0
     * @access public
     *
     * @param array $args Arguments array.
     * @return \WP_Error|array Error object if invalid, array of unslashed post data if valid.
     */
    public function validate_ajax_request( $args ) {
        // Verify if it's an AJAX request.
        if ( ! wp_doing_ajax() ) {
            return new \WP_Error( 'acfwp_invalid_ajax', __( 'Invalid AJAX Operation', 'advanced-coupons-for-woocommerce' ) );
        }

        $args = wp_parse_args(
            $args,
            array(
                'required_parameters' => array(),
                'nonce_value_key'     => '',
                'nonce_action'        => '',
                'user_cap'            => 'manage_woocommerce',
            )
        );

        extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract

        // Validate nonce.
        if ( ! $nonce_value_key || ! $nonce_action || ! isset( $_REQUEST[ $nonce_value_key ] ) || ! wp_verify_nonce( sanitize_key( $_REQUEST[ $nonce_value_key ] ), $nonce_action ) ) {
            return new \WP_Error( 'acfwp_invalid_nonce', __( 'Security check failed', 'advanced-coupons-for-woocommerce' ) );
        }

        // Validate user capability.
        if ( ! current_user_can( $user_cap ) ) {
            return new \WP_Error( 'acfwp_invalid_user', __( 'You are not allowed to do this', 'advanced-coupons-for-woocommerce' ) );
        }

        // Validate required parameters.
        if ( ! empty( $required_parameters ) ) {
            foreach ( $required_parameters as $param ) {
                if ( ! isset( $_REQUEST[ $param ] ) && $_REQUEST[ $param ] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                    return new \WP_Error( 'acfwp_missing_parameters', __( 'Missing required parameters', 'advanced-coupons-for-woocommerce' ) );
                }
            }
        }

        return wp_unslash( $_REQUEST );
    }
}
