<?php
namespace ACFWF\Helpers;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Helpers\Plugin_Constants;

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
    use \ACFWF\Helpers\Traits\Block;
    use \ACFWF\Helpers\Traits\Coupon;
    use \ACFWF\Helpers\Traits\DateTime;
    use \ACFWF\Traits\Singleton;

    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Model that houses all the plugin constants.
     *
     * @since 1.0
     * @access private
     * @var Plugin_Constants
     */
    private $_constants;

    /**
     * Wholesale sale price data, keyed by product id and wholesale role.
     *
     * The get_price() method runs several times per cart line per request, so the WWPP
     * lookup is cached for the request (issue #1622).
     *
     * @since 4.7.6
     * @access private
     * @var array
     */
    private $_wholesale_sale_price_cache = array();

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 1.0
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants Plugin constants object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants ) {
        $this->_constants = $constants;
        $main_plugin->add_to_public_helpers( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Functions
    |--------------------------------------------------------------------------
     */

    /**
     * Write data to plugin log file.
     *
     * @since 1.0
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
     * @since 1.0
     * @access public
     *
     * @param WP_User $user WP_User object.
     * @return boolean True if authorized, False otherwise.
     */
    public function current_user_authorized( $user = null ) {
        // Array of roles allowed to access/utilize the plugin.
        $admin_roles = apply_filters( 'acfw_admin_roles', array( 'administrator' ) );

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
     * Get all user roles.
     *
     * @since 1.0
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
     * @since 1.0
     * @access public
     *
     * @param int    $post_id   Id of the coupon post.
     * @param string $post_type Post type to check.
     * @return bool True if valid save post action, False otherwise.
     */
    public function check_if_valid_save_post_action( $post_id, $post_type ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || get_post_type( $post_id ) != $post_type || empty( $_POST ) ) { // phpcs:ignore
            return false;
        } else {
            return true;
        }
    }

    /**
     * Utility function that determines if a plugin is active or not.
     *
     * @since 1.0
     * @access public
     *
     * @param string $plugin_basename Plugin base name. Ex. woocommerce/woocommerce.php.
     * @return boolean True if active, false otherwise.
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
     * @since 1.1
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
     * Check if a given active plugin's version is older than the set version to compare.
     *
     * @since 1.3.5
     * @access public
     *
     * @param string $plugin_version     Current version of the installed plugin.
     * @param string $version_to_compare Version to compare.
     * @return bool True if plugin is older, false otherwise.
     */
    public function is_plugin_older_than( $plugin_version, $version_to_compare ) {
        return version_compare( $plugin_version, $version_to_compare, '<' );
    }

    /**
     * Exclusive function to check if ACFWP is older than the provided version number.
     *
     * @since 1.3.5
     * @access public
     *
     * @param string $version_to_compare Version to compare.
     * @return bool True if ACFWP plugin is older, false otherwise.
     */
    public function is_acfwp_older_than( $version_to_compare ) {
        // explicity return false if ACFWP is not active.
        if ( ! $this->is_plugin_active( Plugin_Constants::PREMIUM_PLUGIN ) ) {
            return false;
        }

        return $this->is_plugin_older_than( \ACFWP()->Plugin_Constants->VERSION, $version_to_compare );
    }

    /**
     * Get coupon url endpoint. If option value is equivalent to false, return 'coupon'.
     *
     * @since 1.0
     * @access public
     *
     * @return string Coupon endpoint.
     */
    public function get_coupon_url_endpoint() {
        $endpoint = trim( get_option( Plugin_Constants::COUPON_ENDPOINT, 'coupon' ) );
        return $endpoint ? $endpoint : 'coupon';
    }

    /**
     * Check if module is active or not.
     *
     * @since 1.0
     * @access public
     *
     * @param string $module Module option ID.
     * @return string "yes" if active, otherwise blank.
     */
    public function is_module( $module ) {

        $default_modules = class_exists( '\ACFWP\Helpers\Plugin_Constants' ) ? \ACFWP\Helpers\Plugin_Constants::DEFAULT_MODULES() : Plugin_Constants::DEFAULT_MODULES();
        $default         = in_array( $module, $default_modules, true ) ? 'yes' : '';

        return apply_filters( 'acfw_is_module_enabled', get_option( $module, $default ) === 'yes', $module, $default );
    }

    /**
     * Get the discount application mode for a given feature.
     *
     * Resolves the store-owner setting that controls how a feature's discount is applied:
     * - 'price'  : bake the discount into the item/shipping rate price directly (default, legacy behavior).
     * - 'coupon' : keep prices unchanged and attribute the discount to the coupon amount instead.
     *
     * Premium features register their feature => option ID mapping via the
     * `acfw_discount_application_mode_options_map` filter.
     *
     * @since 4.8
     * @access public
     *
     * @param string          $feature Feature slug (e.g. 'bogo_deals', 'add_products', 'shipping_overrides').
     * @param \WC_Coupon|null $coupon  Coupon object context (reserved for future per-coupon overrides).
     * @return string Discount application mode ('price' or 'coupon').
     */
    public function get_discount_application_mode( $feature, $coupon = null ) {
        /**
         * Filter the feature => option ID map used to resolve discount application modes.
         *
         * @since 4.8
         *
         * @param array $options_map Feature slug keyed list of option IDs.
         */
        $options_map = apply_filters(
            'acfw_discount_application_mode_options_map',
            array(
                'bogo_deals' => Plugin_Constants::BOGO_DISCOUNT_APPLICATION_MODE,
            )
        );

        $mode = isset( $options_map[ $feature ] ) ? get_option( $options_map[ $feature ], 'price' ) : 'price';
        $mode = in_array( $mode, array( 'price', 'coupon' ), true ) ? $mode : 'price';

        /**
         * Filter the resolved discount application mode for a feature.
         *
         * @since 4.8
         *
         * @param string          $mode    Discount application mode ('price' or 'coupon').
         * @param string          $feature Feature slug.
         * @param \WC_Coupon|null $coupon  Coupon object context.
         */
        return apply_filters( 'acfw_discount_application_mode', $mode, $feature, $coupon );
    }

    /**
     * Get all currently active modules.
     *
     * @since 1.0
     * @access public
     *
     * @return array List of active modules.
     */
    public function get_active_modules() {
        $all_modules    = class_exists( '\ACFWP\Helpers\Plugin_Constants' ) ? \ACFWP\Helpers\Plugin_Constants::ALL_MODULES() : Plugin_Constants::ALL_MODULES();
        $active_modules = array();

        foreach ( $all_modules as $module ) {
            if ( $this->is_module( $module ) ) {
                $active_modules[] = $module;
            }
        }

        return $active_modules;
    }

    /**
     * Get default allowed user roles.
     *
     * @since 1.0
     * @access public
     *
     * @return array Array of default allowed user roles including "guest".
     */
    public function get_default_allowed_user_roles() {
        $roles = $this->get_all_user_roles();
        $guest = array( 'guest' => __( 'Guest', 'advanced-coupons-for-woocommerce-free' ) );

        return apply_filters( 'acfw_default_allowed_user_roles', array_merge( $guest, $roles ) );
    }

    /**
     * Get the active product brand taxonomy slug.
     *
     * Detects the registered brand taxonomy used by the store, checking the common
     * brand plugin slugs in priority order. Returns an empty string when none is found.
     *
     * @since 4.7.4
     * @access public
     *
     * @return string Registered brand taxonomy slug, or empty string if none is detected.
     */
    public function get_product_brand_taxonomy() {
        $candidates = apply_filters(
            'acfw_product_brand_taxonomy_candidates',
            array(
                'product_brand',        // WooCommerce core brands & WooCommerce Brands extension.
                'pwb-brand',            // Perfect Brands for WooCommerce.
                'yith_product_brand',   // YITH WooCommerce Brands Add-on.
                'product_brands',       // Brands for WooCommerce.
                'berocket_brand',       // BeRocket Brands.
            )
        );

        $taxonomy = '';
        foreach ( $candidates as $candidate ) {
            if ( taxonomy_exists( $candidate ) ) {
                $taxonomy = $candidate;
                break;
            }
        }

        return apply_filters( 'acfw_product_brand_taxonomy', $taxonomy );
    }

    /**
     * This function is an alias for WP get_option(), but will return the default value if option value is empty or invalid.
     *
     * @since 1.0
     * @access public
     *
     * @param string $option_name   Name of the option of value to fetch.
     * @param mixed  $default_value Defaut option value.
     * @return mixed Option value.
     */
    public function get_option( $option_name, $default_value = '' ) {
        $option_value = get_option( $option_name, $default_value );

        return ( gettype( $option_value ) === gettype( $default_value ) && $option_value && ! empty( $option_value ) ) ? $option_value : $default_value;
    }

    /**
     * Get all the product category terms of the current site via wpdb.
     *
     * @since 1.0
     * @access public
     *
     * @param int|null $limit    Limit response.
     * @param string   $order_by Sort order by.
     * @return mixed List of product categories.
     */
    public static function get_all_product_category_terms( $limit = null, $order_by = 'DESC' ) {
        global $wpdb;

        // make sure order_by value is explicit as either DESC or ASC.
        $order_by = 'DESC' === $order_by ? 'DESC' : 'ASC';

        // not wrapped in prepare as there is no user provided input here.
        $query = "SELECT * FROM {$wpdb->terms} AS t
        INNER JOIN {$wpdb->term_taxonomy} AS tx ON (t.term_id = tx.term_id)
        WHERE tx.taxonomy = 'product_cat'
        ORDER BY t.name {$order_by}
        ";

        if ( $limit && is_numeric( $limit ) ) {
            $query .= $wpdb->prepare( 'LIMIT %d', $limit );
        }

        // phpcs is ignored as the query is prepared above.
        return $wpdb->get_results( $query ); // phpcs:ignore
    }

    /**
     * Compare two values based on a given condition.
     *
     * @since 1.0
     * @access public
     *
     * @param mixed  $value_1    First boolean value.
     * @param mixed  $value_2    Second boolean value.
     * @param string $condition Condition to compare.
     * @return bool Result value of comparison.
     */
    public function compare_condition_values( $value_1, $value_2, $condition = null ) {
        $compare = null;

        switch ( $condition ) {

            case 'and':
                $compare = $value_1 && $value_2;
                break;

            case 'or':
                $compare = $value_1 || $value_2;
                break;

            case '=':
                $compare = $value_1 === $value_2;
                break;

            case '!=':
                $compare = $value_1 !== $value_2;
                break;

            case '>':
            case '&rt;':
                $compare = $value_1 > $value_2;
                break;

            case '<':
            case '&lt;':
                $compare = $value_1 < $value_2;
                break;

            default:
                $compare = (bool) $value_2;
                break;
        }

        return $compare;
    }

    /**
     * Get all registered coupons and return as options for <select> element.
     *
     * @since 1.0
     * @access public
     *
     * @return array All registered coupons as options of <select> element.
     */
    public function get_all_coupons_as_options() {
        global $wpdb;

        $raw_results = $wpdb->get_results(
            "SELECT `ID`,`post_title` FROM {$wpdb->posts}
            WHERE post_type = 'shop_coupon'
            AND post_status = 'publish'",
            ARRAY_A
        );

        $options = array();

        if ( ! is_array( $raw_results ) || empty( $raw_results ) ) {
            return $options;
        }

        foreach ( $raw_results as $row ) {
            $options[ intval( $row['ID'] ) ] = sanitize_text_field( $row['post_title'] );
        }

        return $options;
    }

    /**
     * Get all coupon categories as options
     *
     * @since 1.2
     * @access public
     *
     * @return array Categories as options.
     */
    public function get_all_coupon_categories_as_options() {
        $terms = get_terms(
            array(
                'taxonomy'   => Plugin_Constants::COUPON_CAT_TAXONOMY,
                'hide_empty' => false,
            )
        );

        $options = array();
        foreach ( $terms as $term ) {
            $options[ $term->term_id ] = $term->name;
        }

        return $options;
    }

    /**
     * Get all products under category
     *
     * @since 1.0
     * @access public
     *
     * @param mixed $category Category id or ids.
     * @return array Product ids list.
     */
    public function get_all_products_by_category( $category ) {
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category,
                    'operator' => 'IN',
                ),
            ),
        );

        $products = new \WP_Query( $args );
        return $products->posts;
    }

    /**
     * Sanitize notice type value.
     *
     * @since 1.0
     * @access public
     *
     * @param string $type Notice type.
     * @return string Sanitized notice type.
     */
    public function sanitize_notice_type( $type ) {
        $allowed = apply_filters( 'acfw_sanitize_allowed_notice_types', array( 'global', 'notice', 'success', 'error' ) );
        $key     = array_search( $type, $allowed, true );

        return $key > -1 ? $allowed[ $key ] : 'notice';
    }

    /**
     * Check if the current page being viewed is the cart page.
     * This makes sure that it will work for both logged-in and non logged-in users.
     *
     * @since 1.0
     * @since 1.1 make sure request is not wc-ajax related if checking for is_cart()
     * @access public
     *
     * @return bool True if viewing cart page, false otherwise.
     */
    public function is_cart() {
        // if the default is_cart function works and request is not wc-ajax related, then don't proceed.
        if ( is_cart() && ! isset( $_REQUEST['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return true;
        }

        $protocol = ( ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || 443 === $_SERVER['SERVER_PORT'] ) ? 'https://' : 'http://'; // phpcs:ignore
        $url      = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; // phpcs:ignore

        return wc_get_cart_url() === $url;
    }

    /**
     * Check if the current code is running via the checkout fragments refresh AJAX.
     * This makes sure that it will work for both logged-in and non logged-in users.
     *
     * @since 1.0
     * @since 1.1 wc-ajax request value should only be 'update_order_review'
     * @access public
     *
     * @return bool True if viewing cart page, false otherwise.
     */
    public function is_checkout_fragments() {
        if ( ! isset( $_REQUEST['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }

        if ( ! in_array( $_REQUEST['wc-ajax'], array( 'update_order_review', 'checkout' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }

        // if the default is_checkout function works, then don't proceed.
        if ( is_checkout() ) {
            return true;
        }

        return isset( $_SERVER['HTTP_REFERER'] ) && wc_get_checkout_url() === $_SERVER['HTTP_REFERER'];
    }

    /**
     * Check if customer is applying a coupon.
     *
     * @since 1.0
     * @access public
     *
     * @return bool True if applying coupon, false otherwise.
     */
    public function is_apply_coupon() {
        if ( ! isset( $_REQUEST['wc-ajax'] ) || 'apply_coupon' !== $_REQUEST['wc-ajax'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }

        if ( is_checkout() || ( isset( $_SERVER['HTTP_REFERER'] ) && wc_get_checkout_url() === $_SERVER['HTTP_REFERER'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Calculate discount by type.
     *
     * @since 1.0
     * @access public
     *
     * @param string $type  Discount type.
     * @param float  $value Discount value.
     * @param float  $cost  Item cost.
     * @return float Calculated discount.
     */
    public function calculate_discount_by_type( $type, $value, $cost ) {
        switch ( $type ) {

            case 'percent':
                $discount = $cost * ( $value / 100 );
                break;

            case 'fixed':
                $discount = apply_filters( 'acfw_filter_amount', $value );
                break;

            case 'override':
            default:
                // if set value is greater than the cost, then.
                $value = apply_filters( 'acfw_filter_amount', $value );

                // this filter when set to true allows to limit the discount price to the value of the cost to zero.
                // As of version 1.4.1 we are defaulting to allow BOGO price override to be greater than the regular price of the product.
                if ( apply_filters( 'acfwf_filter_override_discount_price_max_limit', false, $type, $value, $cost ) ) {
                    $discount = $value < $cost ? $cost - $value : 0;
                } else {
                    $discount = $cost - $value;
                }

                break;
        }

        return min( $discount, $cost );
    }

    /**
     * Sanitize price string as float.
     *
     * @since 1.0
     * @access public
     *
     * @param string $price Price string.
     * @return float Sanitized price.
     */
    public function sanitize_price( $price ) {
        $thousand_sep = get_option( 'woocommerce_price_thousand_sep' );
        $decimal_sep  = get_option( 'woocommerce_price_decimal_sep' );

        if ( $thousand_sep ) {
            $price = str_replace( $thousand_sep, '', $price );
        }

        if ( $decimal_sep ) {
            $price = str_replace( $decimal_sep, '.', $price );
        }

        $price = str_replace( get_woocommerce_currency_symbol(), '', $price );

        return (float) $price;
    }

    /**
     * Get price with WWP/P support.
     *
     * @since 1.0
     * @since 4.2 Add "Always use regular price" setting
     * @since 4.7.6 Apply the wholesale sale price when one is active (issue #1622)
     * @access private
     *
     * @param WC_Product $product Product object.
     * @param array      $settings Settings array.
     * @return float Product price.
     */
    public function get_price( $product, $settings = array() ) {
        global $wc_wholesale_prices, $wc_wholesale_prices_premium;

        $settings = wp_parse_args(
            $settings,
            array(
                'ignore_always_use_regular_price' => false,
                'cart_item'                       => array(),
            )
        );

        $always_regular_price_option = get_option( Plugin_Constants::ALWAYS_USE_REGULAR_PRICE );
        $use_regular_price           = in_array( $always_regular_price_option, array( 'yes', 'all_valid' ), true ) && ! $settings['ignore_always_use_regular_price'];

        // get wholesale price if present.
        if ( is_object( $wc_wholesale_prices ) && class_exists( 'WWP_Wholesale_Prices' ) ) {

            $wwp_wholesale_roles = $wc_wholesale_prices->wwp_wholesale_roles->getUserWholesaleRole();

            if ( is_array( $wwp_wholesale_roles ) && ! empty( $wwp_wholesale_roles ) && method_exists( 'WWP_Wholesale_Prices', 'get_product_wholesale_price_on_shop_v3' ) ) {

                $data = \WWP_Wholesale_Prices::get_product_wholesale_price_on_shop_v3( $product->get_id(), $wwp_wholesale_roles );

                // Apply the wholesale sale price when the role has an active one. The
                // "Always use regular price" setting suppresses it, the same way it
                // suppresses the shop sale price further down.
                if ( ! $use_regular_price ) {
                    $data = $this->_maybe_apply_wholesale_sale_price( $data, $product, $wwp_wholesale_roles );
                }

                // Get Product level mapping price.
                if ( class_exists( 'WWPP_Helper_Functions' ) && $settings['cart_item'] ) {
                    $quantity_discount = \WWPP_Helper_Functions::get_quantity_discount_mapping_price( $product, $wwp_wholesale_roles, $settings['cart_item'] );
                    if ( ! empty( $quantity_discount ) ) {
                        $data = $quantity_discount;
                    }

                    // Get Category level mapping price.
                    if ( is_cart() ) {
                        $cart_object           = \WC()->cart;
                        $product_category_data = array(
                            'source'                   => 'product_category_level_qty_based',
                            'wholesale_price_with_tax' => $data['wholesale_price_with_tax'],
                            'discount'                 => 0,
                            'wholesale_price'          => $data['wholesale_price'],
                        );
                        $category_discount     = $wc_wholesale_prices_premium->wwpp_wholesale_price_product_category->apply_product_category_level_wholesale_discount( $product_category_data, $product->get_id(), $wwp_wholesale_roles, $settings['cart_item'], $cart_object, true );

                        if ( ! empty( $category_discount ) ) {
                            $data = $category_discount;
                        }
                    }
                }

                // Use wholesale_price_with_tax when setting tax is set to yes and tax display cart is excl.
                if ( $data['wholesale_price_with_tax'] && \wc_tax_enabled() && 'yes' === get_option( 'woocommerce_prices_include_tax' ) && 'excl' === get_option( 'woocommerce_tax_display_cart' ) ) {
                    return (float) $data['wholesale_price_with_tax'];
                }

                if ( $data['wholesale_price'] ) {
                    return (float) $data['wholesale_price'];
                }
            }
        }

        // return regular price when setting is set to yes.
        if ( $use_regular_price ) {
            return (float) $product->get_regular_price();
        }

        return $product->is_on_sale() ? (float) $product->get_sale_price() : (float) $product->get_regular_price();
    }

    /**
     * Replace the wholesale price with the wholesale sale price when one is active.
     *
     * WWP's shop level lookup is not sale price aware. WWPP applies the wholesale sale
     * price only on its cart level filter, so a price resolved from the shop level lookup
     * shows the wholesale regular price on the cart. This mirrors WWPP's own cart rule
     * (issue #1622).
     *
     * @since 4.7.6
     * @access private
     *
     * @param array      $data            Wholesale price data from WWP.
     * @param WC_Product $product         Product object.
     * @param array      $wholesale_roles Wholesale roles of the current user.
     * @return array|mixed Wholesale price data, or the original $data when it is not a wholesale price array.
     */
    private function _maybe_apply_wholesale_sale_price( $data, $product, $wholesale_roles ) {
        if ( ! is_array( $data ) || empty( $data['wholesale_price'] ) || empty( $wholesale_roles ) ) {
            return $data;
        }

        if ( ! class_exists( 'WWPP_Wholesale_Prices' ) || ! method_exists( 'WWPP_Wholesale_Prices', 'get_product_wholesale_sale_price' ) ) {
            return $data;
        }

        // Read the switch first. It keeps the more expensive WWPP lookup off every cart
        // item that has no wholesale sale price.
        $role = reset( $wholesale_roles );
        if ( 'yes' !== $product->get_meta( $role . '_have_on_sale_wholesale_sale_price', true ) ) {
            return $data;
        }

        $cache_key = $product->get_id() . '|' . $role;

        if ( ! isset( $this->_wholesale_sale_price_cache[ $cache_key ] ) ) {
            $this->_wholesale_sale_price_cache[ $cache_key ] = \WWPP_Wholesale_Prices::get_product_wholesale_sale_price( $product->get_id(), $wholesale_roles );
        }

        $sale_data = $this->_wholesale_sale_price_cache[ $cache_key ];

        // WWPP admits any set value here, 0 included. Note that get_price() below still
        // falls through on a 0 price, because it tests the value for truthiness.
        if ( ! is_array( $sale_data ) || empty( $sale_data['is_on_sale'] ) || ! isset( $sale_data['wholesale_sale_price'] ) || '' === (string) $sale_data['wholesale_sale_price'] ) {
            return $data;
        }

        $sale_price_with_tax = isset( $sale_data['wholesale_sale_price_with_tax'] ) ? (string) $sale_data['wholesale_sale_price_with_tax'] : '';

        // Keep both keys in one price domain. get_price() reads wholesale_price_with_tax
        // before wholesale_price on a tax inclusive store, so a sale price paired with the
        // regular price with tax would silently return the regular price.
        if ( ! empty( $data['wholesale_price_with_tax'] ) && '' === $sale_price_with_tax ) {
            return $data;
        }

        // Only these two keys are read back, here and by get_price(). The raw and
        // no-tax keys keep the wholesale regular values.
        $data['wholesale_price'] = $sale_data['wholesale_sale_price'];

        if ( '' !== $sale_price_with_tax ) {
            $data['wholesale_price_with_tax'] = $sale_price_with_tax;
        }

        return $data;
    }

    /**
     * Sanitize condition select value.
     *
     * @since 1.0.0
     * @access public
     *
     * @param string $value    Condition select user inputted value.
     * @param string $fallback Default value.
     * @return string Sanitized condition select value.
     */
    public function sanitize_condition_select_value( $value, $fallback = 'and' ) {
        $allowed_values = apply_filters( 'acfw_condition_select_allowed_values', array( '=', '!=', '>', '<', 'and', 'or' ) );
        $key            = array_search( $value, $allowed_values, true );

        return $key > -1 && isset( $allowed_values[ $key ] ) ? $allowed_values[ $key ] : $fallback;
    }

    /**
     * Sanitize condition array.
     * - this function will also automatically parse value if it's a number.
     *
     * @since 4.5.4
     * @access public
     *
     * @param array $data Array of conditions.
     */
    public function sanitize_condition_array( $data ) {
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $data[ $key ] = $this->sanitize_condition_array( $value );
            } else {
                $data[ $key ] = is_numeric( $value ) ? floatval( $value ) : sanitize_text_field( $value );
            }
        }
        return $data;
    }

    /**
     * Sanitize discount value based on type.
     *
     * @since 1.6
     * @access public
     *
     * @param string $discount_value Discount value.
     * @param string $discount_type  Discount type.
     * @param int    $product_id     Product ID.
     * @return float Sanitized discount value.
     */
    public function sanitize_discount_value( $discount_value, $discount_type, $product_id = 0 ) {
        $sanitized_value = (float) wc_format_decimal( $discount_value );
        if ( 'percent' === $discount_type ) {
            return min( 100.0, $sanitized_value );
        }

        if ( 'fixed' === $discount_type && $product_id ) {
            $product = wc_get_product( $product_id );
            return min( $product->get_regular_price(), $sanitized_value );
        }

        return $sanitized_value;
    }

    /**
     * Sanitize a space-separated string of CSS class names.
     *
     * @since 4.6.2
     * @access public
     *
     * @param string $classes Space-separated CSS class names.
     * @return array Array of sanitized class names.
     */
    public function sanitize_css_classes( $classes ) {
        return array_map( 'sanitize_html_class', explode( ' ', $classes ) );
    }

    /**
     * Prepare setting fields for API.
     *
     * @since 1.2
     * @since 4.5.1 Allow html in settings description but escaped via wp_kses.
     * @access public
     *
     * @param array  $raw_fields Raw setting fields.
     * @param string $section    Section slug.
     * @return array Processed setting fields for API.
     */
    public function prepare_setting_fields_for_api( $raw_fields, $section ) {
        $fields = array_map(
            function ( $o ) {

                // fetch current setting value for field.
                $o['value'] = isset( $o['id'] ) && ( 'title' !== $o['type'] || 'sectionend' !== $o['type'] ) ? get_option( $o['id'] ) : null;

                // if field has options then propragate it.
                if ( isset( $o['options'] ) ) {

                    $temp = array();
                    foreach ( $o['options'] as $key => $label ) {
                        $temp[] = array(
                            'key'   => (string) $key,
                            'label' => $label,
                        );
                    }

                    $o['options'] = $temp;
                }

                if ( isset( $o['class'] ) && str_contains( $o['class'], 'wc_input_price' ) ) {
                    $o['type'] = 'price';
                }

                if ( isset( $o['desc'] ) ) {
                    $o['desc'] = wp_kses_post( $o['desc'] );
                }

                return $o;
            },
            $raw_fields
        );

        $exclude_fields = apply_filters( 'acfw_api_exclude_setting_fields', array( 'acfw_bogo_deals_custom_js', 'acfw_admin_notices_display', 'sectionend' ) );
        $fields         = array_filter(
            $fields,
            function ( $f ) use ( $exclude_fields ) {
            return ! in_array( $f['type'], $exclude_fields, true );
            }
        );

        // change module fields type from checkbox to module.
        if ( 'modules_section' === $section ) {
            $fields = array_map(
                function ( $f ) {
                $f['type'] = 'checkbox' === $f['type'] ? 'module' : $f['type'];
                return $f;
                },
                $fields
            );
        }

        return array_values( $fields );
    }

    /**
     * Check if WC Admin is active
     *
     * @since 1.2
     * @access public
     *
     * @return boolean True if active, false otherwise.
     */
    public function is_wc_admin_active() {
        $package_active = false;
        if ( class_exists( '\Automattic\WooCommerce\Admin\Composer\Package' ) && defined( 'WC_ADMIN_APP' ) && WC_ADMIN_APP ) {
            $package_active = \Automattic\WooCommerce\Admin\Composer\Package::is_package_active();
        } elseif ( self::is_plugin_active( 'woocommerce-admin/woocommerce-admin.php' ) ) {
            return true;
        }

        return $package_active;
    }

    /**
     * Sanitize API request value.
     *
     * @since 1.2
     * @access public
     *
     * @param mixed  $value Unsanitized value.
     * @param string $type Value type.
     * @return mixed $value Sanitized value.
     */
    public function api_sanitize_value( $value, $type = 'string' ) {
        switch ( $type ) {

            case 'post':
                $sanitized = wp_kses( $value, 'post' );
                break;

            case 'array':
            case 'arraystring':
                $sanitized = array_map( 'sanitize_text_field', $value );
                break;

            case 'arrayint':
                $sanitized = array_map( 'intval', $value );
                break;

            case 'objectboolean':
                $value     = (array) $value;
                $sanitized = (object) array_map(
                    function ( $v ) {
                        return (bool) $v;
                    },
                    $value
                );
                break;

            case 'url':
                $sanitized = esc_url_raw( $value );
                break;

            case 'customurl':
                $sanitized = $this->_sanitize_custom_url( $value );
                break;

            case 'price':
            case 'float':
                $sanitized = (float) sanitize_text_field( $value );
                break;

            case 'number':
            case 'integer':
                $sanitized = intval( $value );
                break;

            case 'boolean':
                $sanitized = (bool) $value;
                break;

            case 'switch':
            case 'text':
            case 'textarea':
            case 'string':
                $sanitized = sanitize_text_field( $value );
                break;

            case 'permalink':
                $sanitized = wc_sanitize_permalink( $value );
                break;

            default:
                $sanitized = apply_filters( 'acfw_sanitize_api_request_value', $value, $type );
        }

        return $sanitized;
    }

    /**
     * Sanitize query parameters.
     *
     * @since 4.0
     * @access private
     *
     * @param array $params Query parameters.
     * @return array Sanitized parameters.
     */
    public function api_sanitize_query_parameters( $params ) {
        if ( ! is_array( $params ) || empty( $params ) ) {
            return array();
        }

        $sanitized = array();
        foreach ( $params as $param => $value ) {
            switch ( $param ) {

                case 'page':
                case 'per_page':
                    $sanitized[ $param ] = intval( $value );
                    break;

                case 'search':
                    $sanitized[ $param ] = esc_sql( $value );
                    break;

                case 'user_id':
                case 'object_id':
                case 'coupon_id':
                    $sanitized[ $param ] = absint( $value );
                    break;

                case 'segments':
                case 'segment_ids':
                    $sanitized[ $param ] = array_map( 'sanitize_text_field', (array) $value );
                    break;

                case 'subscribers':
                case 'subscriber_ids':
                    $sanitized[ $param ] = array_map( 'sanitize_text_field', (array) $value );
                    break;

                default:
                    $sanitized[ $param ] = sanitize_text_field( $value );
            }
        }

        return $sanitized;
    }

    /**
     * Format BOGO trigger/deal entry.
     *
     * @since 1.4
     * @access public
     *
     * @param array   $args Entry arguments.
     * @param boolean $is_deal Check if for deal or not.
     * @return array Formatted BOGO trigger/deal entry.
     */
    public function format_bogo_trigger_deal_entry( $args, $is_deal = false ) {
        // Replace extract() with explicit variable assignment for security.
        $ids      = isset( $args['ids'] ) ? $args['ids'] : array();
        $quantity = isset( $args['quantity'] ) ? $args['quantity'] : 0;
        $discount = isset( $args['discount'] ) ? $args['discount'] : 0;
        $type     = isset( $args['type'] ) ? $args['type'] : '';

        $id_prefix = $is_deal ? 'deal_' : 'trigger_';
        $formatted = array(
            'entry_id' => uniqid( $id_prefix ), // create a unique ID for the entry.
            'ids'      => ! is_array( $ids ) ? array( $ids ) : $ids,
            'quantity' => (int) $quantity,
        );

        if ( $is_deal ) {
            $formatted['discount'] = (float) $discount;
            $formatted['type']     = $type;
        }

        return $formatted;
    }

    /**
     * Sort cart items list by price.
     *
     * @since 1.4
     * @access public
     *
     * @param array  $cart_items Cart items array.
     * @param string $sort_order 'desc' for descending or 'asc' for ascending.
     * @return array Sorted cart items.
     */
    public function sort_cart_items_by_price( $cart_items, $sort_order = 'desc' ) {
        usort(
            $cart_items,
            function ( $a, $b ) use ( $sort_order ) {
            if ( $a['key'] === $b['key'] ) {
                return 0;
            }

            $a_price = $this->get_price( $a['data'] );
            $b_price = $this->get_price( $b['data'] );

            if ( 'desc' === $sort_order ) {
                return ( $a_price > $b_price ) ? -1 : 1;
            } else {
                return ( $a_price < $b_price ) ? -1 : 1;
            }
            }
        );

        return $cart_items;
    }

    /**
     * Get cart item data with the provided cart key.
     *
     * @since 1.4.2
     * @access public
     *
     * @param string $cart_key Cart key.
     * @return array Cart item data.
     */
    public function get_cart_item( $cart_key ) {
        $item = \WC()->cart->get_cart_item( $cart_key );

        // WPML support. Needed to properly detect products via cart key.
        if ( empty( $item ) ) {
            $item = current(
                array_filter(
                    \WC()->cart->get_cart(),
                    function ( $i ) use ( $cart_key ) {
                        return $i['key'] === $cart_key;
                    }
                )
            );
        }

        return $item;
    }

    /**
     * Load templates in an overridable manner.
     *
     * @since 3.1
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
     * Check if REST API request is valid.
     *
     * @deprecated 4.5.7
     *
     * @since 4.0
     * @access public
     *
     * @param \WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error True if the request has read access for the item, WP_Error object otherwise.
     */
    public function check_if_valid_api_request( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        wc_deprecated_function( __METHOD__, '4.5.7' );
        return true;
    }

    /**
     * Alternative wc_price function for API display.
     *
     * @since 4.0
     * @access public
     *
     * @param float $price    Price in float.
     * @param array $settings Price display settings.
     * @return string Sanitized price.
     */
    public function api_wc_price( $price, $settings = array() ) {
        // ensure that default currency is always set to site currency when displaying prices.
        if ( ! isset( $settings['currency'] ) ) {
            $settings['currency'] = get_woocommerce_currency();
        }

        return html_entity_decode( wc_clean( wc_price( $price, $settings ) ) );
    }

    /**
     * Format an integer value for display context so it's easier to read.
     *
     * @since 4.3
     * @access public
     *
     * @param int $value Integer value.
     * @return string Formatted integer value.
     */
    public function format_integer_for_display( $value ) {
        return \number_format( $value, 0, '', wc_get_price_thousand_separator() );
    }

    /**
     * Get customer display name.
     *
     * @since 4.0
     * @access public
     *
     * @param int|WC_Customer $cid Customer ID.
     * @return string Customer name.
     */
    public function get_customer_name( $cid ) {
        $customer      = $cid instanceof \WC_Customer ? $cid : new \WC_Customer( $cid );
        $customer_name = sprintf( '%s %s', $customer->get_first_name(), $customer->get_last_name() );

        // set customer name to email if user has no set first and last name.
        if ( ! trim( $customer_name ) ) {
            $customer_name = $this->get_customer_email( $customer );
        }

        return $customer_name;
    }

    /**
     * Get customer display email.
     *
     * @since 4.0
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
     * Get the customer object.
     *
     * @since 4.5.3
     * @access private
     *
     * @param int|string $user_id User ID or email.
     * @return \WC_Customer|WP_Error Customer object on success, error on failure.
     */
    public function get_customer_object( $user_id ) {
        $customer = null;

        if ( \is_email( $user_id ) ) {
            $customer = new \WC_Customer();
            $customer->set_email( $user_id );
            $customer->apply_changes();
        } else {
            $customer = new \WC_Customer( (int) $user_id );
        }

        if ( ! $customer || ! $customer->get_email() ) {
            return new \WP_Error(
                'acfw_invalid_customer',
                __( 'Invalid customer or email', 'advanced-coupons-for-woocommerce-free' ),
                array(
                    'status' => 400,
                    'data'   => $customer,
                )
            );
        }

        return $customer;
    }

    /**
     * Get order frontend link.
     *
     * @since 4.0
     * @access public
     *
     * @param WC_Order $order Order object.
     * @return string Order view frontend URL.
     */
    public function get_order_frontend_link( $order ) {
        $order = $order instanceof \WC_Order ? $order : \wc_get_order( $order );
        return $order->get_view_order_url();
    }

    /**
     * Load single coupon template.
     *
     * @since 3.1
     * @access public
     *
     * @param Advanced_Coupon $coupon     Coupon object.
     * @param object          $visibility Coupon visibility options.
     * @param string          $classname  Custom classname.
     */
    public function load_single_coupon_template( $coupon, $visibility, $classname = '' ) {
        // don't proceed if the coupon doesn't exist.
        if ( ! $coupon->get_id() ) {
            return;
        }

        $schedule_string = $coupon->get_schedule_string();

        // make sure that content visibility values are not of type string.
        foreach ( $visibility as $key => $value ) {
            if ( 'true' === $value ) {
                $visibility->$key = true;
            } elseif ( 'false' === $value ) {
                $visibility->$key = false;
            }
        }

        $classnames = array(
            'acfw-single-coupon-block',
            'acfw-coupon-type-' . $coupon->get_discount_type(),
        );

        if ( $classname ) {
            $classnames = array_merge( $classnames, $this->sanitize_css_classes( $classname ) );
        }

        $this->load_template(
            'acfw-blocks/single-coupon.php',
            array(
                'coupon'             => $coupon,
                'has_usage_limit'    => $visibility->usage_limit && (int) $coupon->get_usage_limit(),
                'has_description'    => $visibility->description && $coupon->get_description(),
                'has_discount_value' => $visibility->discount_value && ( $coupon->get_amount() || 'acfw_bogo' === $coupon->get_discount_type() ),
                'has_schedule'       => $visibility->schedule && $schedule_string,
                'schedule_string'    => $schedule_string,
                'classnames'         => $classnames,
            )
        );
    }

    /**
     * Get the contact support link.
     * If at least one premium plugin is active, then the premium support link is returned. Otherwise the free support link is returned.
     *
     * @since 4.3
     * @access public
     *
     * @return string Support link.
     */
    public function get_contact_support_link() {
        if ( $this->has_paid_plugin_active() ) {
            // Use 'contact-us/' path as 'support/' redirects to the Knowledge Base page.
            return $this->get_utm_url( 'contact-us/', 'acfwf', 'dashboard', 'contactsupportlink' );
        }

        return 'https://wordpress.org/support/plugin/advanced-coupons-for-woocommerce-free/';
    }

    /**
     * Check to see if any paid plugin by Advanced Coupons is active
     *
     * @since 4.5.1
     * @access public
     *
     * @return bool If a paid plugin (ACFWP, LPFW or AGC) is active or not
     */
    public function has_paid_plugin_active() {
        return $this->is_plugin_active( Plugin_Constants::PREMIUM_PLUGIN ) ||
        $this->is_plugin_active( Plugin_Constants::LOYALTY_PLUGIN ) ||
        $this->is_plugin_active( Plugin_Constants::GIFT_CARDS_PLUGIN );
    }

    /**
     * Get scheduler date field value (moved from ACFWP).
     *
     * @since 4.5
     * @access public
     *
     * @param array|string $field Field post value.
     * @return string Date string value (Y-m-d H:i:s).
     */
    public function get_scheduler_date_field_value( $field ) {
        $date   = is_array( $field ) && isset( $field['date'] ) ? $field['date'] : '';
        $hour   = $date && isset( $field['hour'] ) ? sprintf( '%02d', (int) $field['hour'] ) : '00';
        $minute = $date && isset( $field['minute'] ) ? sprintf( '%02d', (int) $field['minute'] ) : '00';
        $second = $date && isset( $field['second'] ) ? sprintf( '%02d', (int) $field['second'] ) : '00';

        return $date ? sanitize_text_field( sprintf( '%s %s:%s:%s', $date, $hour, $minute, $second ) ) : '';
    }

    /**
     * Get extra discounts (BOGO, Add Products) from a given coupon order item.
     *
     * @since 4.5.1
     * @access public
     *
     * @param WC_Order_Item_Coupon|int $coupon_item Coupon order item object or ID.
     * @param bool                     $precise     Flag to check wether to return precision value or not.
     * @return float discount value.
     */
    public function get_coupon_order_item_extra_discounts( $coupon_item, $precise = true ) {
        $coupon_item = $coupon_item instanceof \WC_Order_Item_Coupon ? $coupon_item : new \WC_Order_Item_Coupon( $coupon_item );
        $discount    = 0;

        // skip if ID is not a valid coupon order item.
        if ( ! $coupon_item->get_id() ) {
            return $discount;
        }

        $discount += wc_add_number_precision( (float) $coupon_item->get_meta( Plugin_Constants::ORDER_COUPON_BOGO_DISCOUNT ) );
        $discount += wc_add_number_precision( (float) $coupon_item->get_meta( Plugin_Constants::ORDER_COUPON_ADD_PRODUCTS_DISCOUNT ) );
        $discount += wc_add_number_precision( (float) $coupon_item->get_meta( Plugin_Constants::ORDER_COUPON_SHIPPING_OVERRIDES_DISCOUNT ) );

        return $precise ? $discount : wc_remove_number_precision( $discount );
    }

    /**
     * Get the applied store credit coupon for a given order.
     *
     * @since 4.5.2
     * @access private
     *
     * @param string    $coupon_code Coupon code.
     * @param \WC_Order $order       Order object.
     * @return \WC_Order_Item_Coupon|null Order coupon item object or null.
     */
    public function get_order_applied_coupon_item_by_code( string $coupon_code, \WC_Order $order ) {
        $coupon_item = null;
        foreach ( $order->get_coupons() as $item ) {
            if ( $item->get_code() === $coupon_code ) {
                $coupon_item = $item;
                break;
            }
        }

        return $coupon_item;
    }

    /**
     * Get \WC_Coupon object instances for all coupons that are applied in an order.
     *
     * @since 4.5.3
     * @access private
     *
     * @param \WC_Order $order Order object.
     * @return \WC_Coupon[] List of coupon object instances.
     */
    public function get_coupon_objects_from_order( \WC_Order $order ) {
        return array_map(
            function ( $coupon_item ) {
                $coupon = new \WC_Coupon();
                $coupon->set_props( $coupon_item->get_meta( 'coupon_data' ) );
                $coupon->apply_changes();

                return $coupon;
            },
            $order->get_coupons()
        );
    }

    /**
     * Sanitize custom url.
     *
     * @since 4.5.4
     * @access private
     *
     * @param string $url Custom url.
     */
    private function _sanitize_custom_url( $url ) {
        $characters = array(
            '{' => '__placeholder_start__',
            '}' => '__placeholder_end__',
        );

        // Replace `{` and `}` characters with a placeholder string.
        foreach ( $characters as $character => $placeholder ) {
            $url = str_replace( $character, $placeholder, $url );
        }

        // Sanitize the URL using WordPress' built-in function.
        $url = esc_url_raw( $url );

        // Replace the placeholder string with `{` and `}` characters.
        foreach ( $characters as $character => $placeholder ) {
            $url = str_replace( $placeholder, $character, $url );
        }

        return $url;
    }

    /**
     * Check if current screen is Advanced Coupons screen.
     *
     * @since 4.5.4
     * @access public
     *
     * @return bool True if advanced coupons screen, false otherwise.
     */
    public function is_advanced_coupons_screen() {
        $screen    = get_current_screen();
        $post_type = get_post_type();

        if ( ! $post_type && isset( $_GET['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $post_type = sanitize_text_field( wp_unslash( $_GET['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        return str_contains( $screen->id, 'coupons_page_acfw' ) || 'shop_coupon' === $post_type;
    }

    /**
     * Remove duplicate entries in a multidimensional array.
     *
     * @since 4.6.0
     * @access public
     *
     * @param array $input Multidimensional array.
     * @return array Array with unique entries.
     */
    public function array_unique_multidimensional( $input ) {
        $serialized = array_map( 'serialize', $input );
        $unique     = array_unique( $serialized );
        return array_values( array_intersect_key( $input, $unique ) );
    }

    /**
     * Get the number of days since the installation of the plugin.
     *
     * @since 4.6.2
     * @access public
     *
     * @return int The number of days since installation.
     */
    public function get_days_since_install() {
        // Get the installation date from the options table.
        $installation_date = get_option( Plugin_Constants::INSTALLATION_DATE );

        if ( ! $installation_date ) {
            return 0; // Return 0 if installation date is not set.
        }

        // Calculate the difference between installation date and current date.
        $current_date      = new \WC_DateTime( 'now', new \DateTimeZone( $this->get_site_current_timezone() ) );
        $installation_date = new \WC_DateTime( $installation_date, new \DateTimeZone( $this->get_site_current_timezone() ) );
        $interval          = $current_date->diff( $installation_date );

        // Return the difference in days.
        return $interval->days;
    }

    /**
     * Get the count of coupons created.
     *
     * @since 4.6.2
     * @access public
     *
     * @return int The count of coupons created.
     */
    public function get_coupons_created_count() {
        $args = array(
            'post_type'      => 'shop_coupon',
            'no_found_rows'  => true, // optimize for count retrieval.
            'fields'         => 'ids', // only retrieve post IDs.
            'posts_per_page' => -1, // get all coupons.
        );

        $query = new \WP_Query( $args );

        return count( $query->posts );
    }

    /**
     * Get the count of orders that used coupons.
     *
     * @since 4.6.2
     * @access public
     *
     * @return int The count of orders that used coupons.
     */
    public function get_coupon_orders_processed_count() {
        $args = array(
            'type'   => 'shop_order', // Target shop orders.
            'return' => 'objects', // Retrieve WC_Order objects.
            'status' => array( 'wc-completed', 'wc-processing' ), // Desired order statuses.
        );

        $query             = new \WC_Order_Query( $args );
        $orders            = $query->get_orders();
        $order_with_coupon = 0;

        // No args available to get order with coupon applied only in WC_Order_Query.
        // So we need to loop ther orders, and check if order have coupon code applied.
        if ( ! empty( $orders ) ) {
            foreach ( $orders as $order ) {
                if ( count( $order->get_coupon_codes() ) > 0 ) {
                    ++$order_with_coupon;
                }
            }
        }

        return $order_with_coupon;
    }

    /**
     * Get WC Admin Note object.
     *
     * @since 4.6.2
     * @access private
     *
     * @param mixed $data Note data, object or ID.
     * @return object Admin note.
     */
    public function wc_admin_note( $data = '' ) {

        if ( version_compare( WC()->version, '4.8.0', '>=' ) ) {
            return new \Automattic\WooCommerce\Admin\Notes\Note( $data );
        }

        return new \Automattic\WooCommerce\Admin\Notes\WC_Admin_Note( $data );
    }

    /**
     * Get the relative time from a given date and time.
     *
     * Converts a given datetime to a relative time string such as "1 year ago", "3 months ago", "5 days ago", etc.,
     * based on the site's current timezone.
     *
     * @since 4.6.2
     * @access public
     *
     * @param string $date_time The datetime string to convert.
     * @return string The relative time string.
     */
    public function get_relative_time( $date_time ) {
        $timezone = new \DateTimeZone( $this->get_site_current_timezone() );
        $date     = new \DateTime( $date_time, new \DateTimeZone( $this->get_site_current_timezone() ) );
        $date->setTimezone( $timezone );
        $now  = new \DateTime( 'now', $timezone );
        $diff = $now->diff( $date );

        if ( $diff->y > 0 ) {
            // Translators: %d is the number of years.
            return sprintf( _n( '%d year ago', '%d years ago', $diff->y, 'advanced-coupons-for-woocommerce-free' ), $diff->y );
        }
        if ( $diff->m > 0 ) {
            // Translators: %d is the number of months.
            return sprintf( _n( '%d month ago', '%d months ago', $diff->m, 'advanced-coupons-for-woocommerce-free' ), $diff->m );
        }
        if ( $diff->d > 0 ) {
            // Translators: %d is the number of days.
            return sprintf( _n( '%d day ago', '%d days ago', $diff->d, 'advanced-coupons-for-woocommerce-free' ), $diff->d );
        }
        if ( $diff->h > 0 ) {
            // Translators: %d is the number of hours.
            return sprintf( _n( '%d hour ago', '%d hours ago', $diff->h, 'advanced-coupons-for-woocommerce-free' ), $diff->h );
        }
        if ( $diff->i > 0 ) {
            // Translators: %d is the number of minutes.
            return sprintf( _n( '%d minute ago', '%d minutes ago', $diff->i, 'advanced-coupons-for-woocommerce-free' ), $diff->i );
        }
        // Translators: This refers to a very short time interval (less than a minute).
        return __( 'just now', 'advanced-coupons-for-woocommerce-free' );
    }

    /**
     * Decode HTML entities in an array or string recursively.
     *
     * This method traverses an array and decodes HTML entities in all string elements,
     * leaving other data types untouched. It ensures compatibility with nested arrays
     * and mixed data types.
     *
     * @since 4.6.5
     * @access public
     *
     * @param mixed $input The input value to decode. It can be an array, string, or other data type.
     * @return mixed The input value with HTML entities decoded in strings, preserving the original structure.
     */
    public function decode_html_entities_recursive( $input ) {
        if ( is_array( $input ) ) {
            return array_map( array( $this, 'decode_html_entities_recursive' ), $input );
        } elseif ( is_string( $input ) ) {
            return html_entity_decode( $input );
        }
        return $input;
    }

    /**
     * Get the URL with UTM parameters.
     *
     * @param string $url_path     URL path from main.
     * @param string $utm_source   UTM source.
     * @param string $utm_medium   UTM medium.
     * @param string $utm_campaign UTM campaign.
     * @param string $utm_term     UTM term.
     * @param string $site_url     URL - defaults to `https://advancedcouponsplugin.com/`.
     *
     * @since 4.6.6
     * @return string
     */
    public static function get_utm_url( $url_path = '', $utm_source = 'acfwf', $utm_medium = 'action', $utm_campaign = 'default', $utm_term = false, $site_url = Plugin_Constants::SITE_URL ) {

        $utm_content = get_option( 'acfw_installed_by', false );
        $url         = trailingslashit( $site_url ) . $url_path;

        return add_query_arg(
            array(
                'utm_source'   => $utm_source,
                'utm_medium'   => $utm_medium,
                'utm_campaign' => $utm_campaign,
                'utm_term'     => $utm_term,
                'utm_content'  => $utm_content,
            ),
            trailingslashit( $url )
        );
    }

    /**
     * Get the WordPress.org plugin icon URL
     *
     * @param string $plugin_slug The plugin slug.
     * @param int    $size        The icon size (default: 128).
     *
     * @since 2.2.1
     * @access public
     *
     * @return string
     */
    public static function get_wp_org_plugin_icon_url( $plugin_slug, $size = 128 ) {
        // Default fallback icon.
        $default_icon = \ACFWF()->Plugin_Constants->IMAGES_ROOT_URL . 'acfw-icon.png';

        // If the plugin doesn't exist on WordPress.org, return the default icon.
        if ( ! self::plugin_exists_on_wporg( $plugin_slug ) ) {
            return $default_icon;
        }

        // Known plugins with specific icon formats.
        $icon_formats = array(
            'invoice-gateway-for-woocommerce' => 'jpg',
            // Add more as needed.
        );

        // Determine the file extension based on our known list or default to PNG.
        $extension = isset( $icon_formats[ $plugin_slug ] ) ? $icon_formats[ $plugin_slug ] : 'png';

        // Build the icon URL with dimensions.
        $icon_url = sprintf(
            'https://ps.w.org/%s/assets/icon-%dx%d.%s',
            $plugin_slug,
            $size,
            $size,
            $extension
        );

        return $icon_url;
    }

    /**
     * Check if a plugin exists on WordPress.org
     *
     * @param string $plugin_slug The plugin slug.
     *
     * @since 2.2.1
     * @access public
     *
     * @return bool
     */
    public static function plugin_exists_on_wporg( $plugin_slug ) {
        // These plugins are known to exist on WordPress.org.
        $known_plugins = array(
            'woocommerce-wholesale-prices'         => true,
            'wc-vendors'                           => true,
            'storeagent-ai-for-woocommerce'        => true,
            'invoice-gateway-for-woocommerce'      => true,
            'woo-product-feed-pro'                 => true,
            'funnelkit-stripe-woo-payment-gateway' => true,
            'woocommerce-store-toolkit'            => true,
            'woocommerce-exporter'                 => true,
            'saveto-wishlist-lite-for-woocommerce' => true,
            // Add more as needed.
        );

        // If it's a known plugin, return true.
        if ( isset( $known_plugins[ $plugin_slug ] ) ) {
            return true;
        }

        // For unknown plugins, we'll assume they don't exist.
        return false;
    }
}
