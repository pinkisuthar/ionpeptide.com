<?php
namespace ACFWF\Models\Third_Party_Integrations;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the WPML_Support module.
 *
 * @since 1.3
 */
class WPML_Support implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that holds the single main instance of URL_Coupon.
     *
     * @since 1.3
     * @access private
     * @var WPML_Support
     */
    private static $_instance;

    /**
     * Model that houses all the plugin constants.
     *
     * @since 1.3
     * @access private
     * @var Plugin_Constants
     */
    private $_constants;

    /**
     * Property that houses all the helper functions of the plugin.
     *
     * @since 1.3
     * @access private
     * @var Helper_Functions
     */
    private $_helper_functions;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 1.3
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
        $main_plugin->add_to_public_models( $this );
    }

    /**
     * Ensure that only one instance of this class is loaded or can be loaded ( Singleton Pattern ).
     *
     * @since 1.3
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     * @return WPML_Support
     */
    public static function get_instance( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        if ( ! self::$_instance instanceof self ) {
            self::$_instance = new self( $main_plugin, $constants, $helper_functions );
        }

        return self::$_instance;
    }

    /**
     * Remove all filters related to currency settings when the "acfw_rest_api_context" action hook is triggered.
     *
     * @since 4.5.6
     * @access public
     *
     * @param WP_REST_Request $request Full details about the request.
     */
    public function remove_currency_setting_filters( $request ) {
        if ( 'admin' === $request->get_header( 'X-ACFW-Context' ) ) {
            remove_all_filters( 'option_woocommerce_currency' );
            remove_all_filters( 'woocommerce_currency' );
            remove_all_filters( 'option_woocommerce_price_thousand_sep' );
            remove_all_filters( 'option_woocommerce_price_decimal_sep' );
            remove_all_filters( 'option_woocommerce_price_num_decimals' );
            remove_all_filters( 'option_woocommerce_currency_pos' );
            remove_all_filters( 'woocommerce_currency_symbol' );
            remove_all_filters( 'woocommerce_price_format' );
            remove_all_filters( 'acfw_filter_amount' );
        } elseif ( 'frontend' === $request->get_header( 'X-ACFW-Context' ) ) {
            // Force WCML to set the client currency for REST API requests.
            add_filter( 'woocommerce_rest_is_request_to_rest_api', '__return_true' );
            $this->_get_multi_currency()->set_request_currency( array(), array(), $request );
        }
    }

    /**
     * Check if screen is currently on WPML translation pages.
     *
     * @since 1.3
     * @access private
     *
     * @return bool True if WPML translation pages, false otherwise.
     */
    private function _is_wpml_admin_translation_pages() {
        $screens = array(
            'wpml-package-management',
            'wpml-string-translation/menu/string-translation.php',
            'wpml-translation-management/menu/main.php',
        );

        return is_admin() && isset( $_GET['page'] ) && in_array( $_GET['page'], $screens, true ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Register translatable strings for applicable coupons.
     *
     * @since 1.3
     * @access public
     */
    public function register_translatable_strings_for_coupons() {
        if ( ! $this->_is_wpml_admin_translation_pages() ) {
            return;
        }

        $query = new \WP_Query(
            array(
                'post_type'      => 'shop_coupon',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
            )
        );

        if ( ! is_array( $query->posts ) || empty( $query->posts ) ) {
            return;
        }

        $coupons = array_map(
            function ( $id ) {
            return new Advanced_Coupon( $id );
            },
            $query->posts
        );

        foreach ( $coupons as $coupon ) {

            $package = array(
                'kind'      => 'Coupon', // explicitly set not to be translatable.
                'name'      => $coupon->get_id(),
                'title'     => 'Coupon: ' . $coupon->get_code(), // explicitly set not to be translatable.
                'edit_link' => get_edit_post_link( $coupon->get_id() ),
            );

            // register package.
            do_action( 'wpml_start_string_package_registration', $package );

            $translate = array();

            // URL Coupon success message.
            if ( $coupon->get_advanced_prop_edit( 'success_message' ) ) {
                $translate[] = array(
                    'value' => $coupon->get_advanced_prop_edit( 'success_message' ),
                    'name'  => 'success_message',
                    'label' => __( 'URL Coupon: success message', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'AREA',
                );
            }

            // URL Coupon redirect to url.
            if ( $coupon->get_advanced_prop_edit( 'after_redirect_url' ) ) {
                $translate[] = array(
                    'value' => $coupon->get_advanced_prop_edit( 'after_redirect_url' ),
                    'name'  => 'after_redirect_url',
                    'label' => __( 'URL Coupon: Redirect To URL', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'LINE',
                );
            }

            // Role restrictions error message.
            if ( $coupon->get_advanced_prop_edit( 'role_restrictions_error_msg' ) ) {
                $translate[] = array(
                    'value' => $coupon->get_advanced_prop_edit( 'role_restrictions_error_msg' ),
                    'name'  => 'role_restrictions_error_msg',
                    'label' => __( 'Role restrictions error message', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'AREA',
                );
            }

            // Cart Conditions notice.
            $cart_condition_notice = $coupon->get_advanced_prop_edit( 'cart_condition_notice' );
            if ( is_array( $cart_condition_notice ) ) {

                if ( isset( $cart_condition_notice['message'] ) ) {
                    $translate[] = array(
                        'value' => $cart_condition_notice['message'],
                        'name'  => 'cart_condition_notice_message',
                        'label' => __( 'Cart Conditions: Non-qualifying message', 'advanced-coupons-for-woocommerce-free' ),
                        'type'  => 'AREA',
                    );
                }

                if ( isset( $cart_condition_notice['btn_text'] ) ) {
                    $translate[] = array(
                        'value' => $cart_condition_notice['btn_text'],
                        'name'  => 'cart_condition_notice_btn_text',
                        'label' => __( 'Cart Conditions: Non-qualifying button text', 'advanced-coupons-for-woocommerce-free' ),
                        'type'  => 'AREA',
                    );
                }

                if ( isset( $cart_condition_notice['btn_url'] ) ) {
                    $translate[] = array(
                        'value' => $cart_condition_notice['btn_url'],
                        'name'  => 'cart_condition_notice_btn_url',
                        'label' => __( 'Cart Conditions: Non-qualifying button URL', 'advanced-coupons-for-woocommerce-free' ),
                        'type'  => 'AREA',
                    );
                }
}

            // BOGO Deals notice.
            $bogo_notice = $coupon->get_bogo_notice_settings();
            if ( is_array( $bogo_notice ) ) {

                if ( isset( $bogo_notice['message'] ) ) {
                    $translate[] = array(
                        'value' => $bogo_notice['message'],
                        'name'  => 'bogo_deals_notice_message',
                        'label' => __( 'BOGO Deals: Notice message', 'advanced-coupons-for-woocommerce-free' ),
                        'type'  => 'AREA',
                    );
                }

                if ( isset( $bogo_notice['button_text'] ) ) {
                    $translate[] = array(
                        'value' => $bogo_notice['button_text'],
                        'name'  => 'bogo_deals_notice_button_text',
                        'label' => __( 'BOGO Deals: Notice button text', 'advanced-coupons-for-woocommerce-free' ),
                        'type'  => 'AREA',
                    );
                }

                if ( isset( $bogo_notice['button_url'] ) ) {
                    $translate[] = array(
                        'value' => $bogo_notice['button_url'],
                        'name'  => 'bogo_deals_notice_button_url',
                        'label' => __( 'BOGO Deals: Notice button url', 'advanced-coupons-for-woocommerce-free' ),
                        'type'  => 'AREA',
                    );
                }
            }

            $translate = apply_filters( 'acfw_wpml_translate_coupon_fields', $translate, $coupon );

            // register translations for fields.
            foreach ( $translate as $t ) {
                do_action( 'wpml_register_string', $t['value'], Plugin_Constants::META_PREFIX . $t['name'], $package, $t['label'], $t['type'] );
            }
}
    }

    /**
     * Apply translation for setting strings.
     *
     * @since 1.3
     * @access public
     *
     * @param string              $value  Setting value.
     * @param string              $meta   Meta name.
     * @param Advanced_Coupon|int $coupon Coupon object.
     * @return string Filtered setting value.
     */
    public function apply_translation_coupon_field( $value, $meta, $coupon ) {
        $package = array(
            'kind' => 'Coupon',
            'name' => $coupon instanceof Advanced_Coupon ? $coupon->get_id() : $coupon,
        );

        $test = apply_filters( 'wpml_translate_string', $value, Plugin_Constants::META_PREFIX . $meta, $package );
        return $test;
    }

    /**
     * Register setting fields as translateable string in one package (domain).
     *
     * @since 1.3
     * @access public
     */
    public function register_translatable_setting_strings() {
        if ( ! $this->_is_wpml_admin_translation_pages() ) {
            return;
        }

        $package = array(
            'kind'  => 'ACFW Settings',
            'name'  => 'acfw_settings',
            'title' => 'ACFW Settings', // intentionally not translable.
        );

        $translate = apply_filters(
            'acfw_wpml_translate_setting_options',
            array(
                array(
                    'value' => get_option( Plugin_Constants::BOGO_DEALS_NOTICE_MESSAGE ),
                    'name'  => Plugin_Constants::BOGO_DEALS_NOTICE_MESSAGE,
                    'label' => __( 'BOGO Deals: Global notice message', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'AREA',
                ),
                array(
                    'value' => get_option( Plugin_Constants::BOGO_DEALS_NOTICE_BTN_TEXT ),
                    'name'  => Plugin_Constants::BOGO_DEALS_NOTICE_BTN_TEXT,
                    'label' => __( 'BOGO Deals: Global notice button text', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'LINE',
                ),
                array(
                    'value' => get_option( Plugin_Constants::BOGO_DEALS_NOTICE_BTN_URL ),
                    'name'  => Plugin_Constants::BOGO_DEALS_NOTICE_BTN_URL,
                    'label' => __( 'BOGO Deals: Global notice button URL', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'LINE',
                ),
                array(
                    'value' => get_option( Plugin_Constants::ROLE_RESTRICTIONS_ERROR_MESSAGE ),
                    'name'  => Plugin_Constants::ROLE_RESTRICTIONS_ERROR_MESSAGE,
                    'label' => __( 'Role Restrictions: Invalid user role error message (global)', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'AREA',
                ),
                array(
                    'value' => get_option( Plugin_Constants::CUSTOM_SUCCESS_MESSAGE_GLOBAL ),
                    'name'  => Plugin_Constants::CUSTOM_SUCCESS_MESSAGE_GLOBAL,
                    'label' => __( 'URL Coupons: Custom success message', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'AREA',
                ),
                array(
                    'value' => get_option( Plugin_Constants::CUSTOM_DISABLE_MESSAGE ),
                    'name'  => Plugin_Constants::CUSTOM_DISABLE_MESSAGE,
                    'label' => __( 'URL Coupons: Custom disable message', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'AREA',
                ),
                array(
                    'value' => get_option( Plugin_Constants::AFTER_APPLY_COUPON_REDIRECT_URL_GLOBAL ),
                    'name'  => Plugin_Constants::AFTER_APPLY_COUPON_REDIRECT_URL_GLOBAL,
                    'label' => __( 'URL Coupons: Redirect to URL after applying coupon', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'LINE',
                ),
                array(
                    'value' => get_option( Plugin_Constants::INVALID_COUPON_REDIRECT_URL ),
                    'name'  => Plugin_Constants::INVALID_COUPON_REDIRECT_URL,
                    'label' => __( 'URL Coupons: Redirect to URL if invalid coupon is visited', 'advanced-coupons-for-woocommerce-free' ),
                    'type'  => 'LINE',
                ),
            )
        );

        // register translations for fields.
        foreach ( $translate as $t ) {
            if ( $t['value'] ) {
                do_action( 'wpml_register_string', $t['value'], $t['name'], $package, $t['label'], $t['type'] );
            }
        }
    }

    /**
     * Apply translation for setting strings.
     *
     * @since 1.3
     * @access public
     *
     * @param string $value Setting value.
     * @param string $option Option name.
     * @return string Filtered setting value.
     */
    public function apply_translation_setting_strings( $value, $option ) {
        $package = array(
            'kind'  => 'ACFW Settings',
            'name'  => 'acfw_settings',
            'title' => 'ACFW Settings',
        );

        return apply_filters( 'wpml_translate_string', $value, $option, $package );
    }

    /**
     * Remove translated version of products in product search.
     *
     * @since 1.3
     * @since 4.2.1 Make sure the main language products are returned on product search AJAX when the product was originally created on a secondary language.
     * @access public
     *
     * @param array $products List of products.
     * @return array Filtered list of products.
     */
    public function remove_translated_versions_of_products( $products ) {
        global $sitepress, $woocommerce_wpml, $wpml_post_translations;

        $default_language = $sitepress->get_default_language();

        // only run filter when WCML is properly setup.
        if ( $woocommerce_wpml && $woocommerce_wpml->products ) {

            $products = array_filter(
                $products,
                function ( $name, $id ) use ( $wpml_post_translations, $default_language ) {
                return $default_language === $wpml_post_translations->get_element_lang_code( $id );
                },
                ARRAY_FILTER_USE_BOTH
            );
        }

        return $products;
    }

    /**
     * Remove translated version of categories category search.
     *
     * @since 1.3
     * @access public
     *
     * @param array $categories List of categories.
     * @return array $categories Filtered list of categories.
     */
    public function remove_translated_versions_of_categories( $categories ) {
        global $woocommerce_wpml;

        // only run filter when WCML is properly setup.
        if ( $woocommerce_wpml && $woocommerce_wpml->terms ) {

            $categories = array_filter(
                $categories,
                function ( $name, $id ) use ( $woocommerce_wpml ) {
                $test = $woocommerce_wpml->terms->is_original_category( $id, 'tax_product_cat' );
                return $test;
                },
                ARRAY_FILTER_USE_BOTH
            );
        }

        return $categories;
    }

    /**
     * Get the translated cart item's original product ID.
     *
     * @since 1.3
     * @access public
     *
     * @param int $product_id Product ID.
     * @return int Filtered product ID.
     */
    public function get_cart_item_original_product_id( $product_id ) {
        global $woocommerce_wpml;

        if ( $woocommerce_wpml && $woocommerce_wpml->products ) {
            return $woocommerce_wpml->products->get_original_product_id( $product_id );
        } else {
            return $product_id;
        }
    }

    /**
     * Get the translated versions of categories and append it the original list of category ids.
     * NOTE: This needs to be used on condition values and not on actual categories detected on cart because
     *       the categories detected on the cart are always the translated ones.
     *
     * @since 1.3
     * @access public
     *
     * @param array  $category_ids List of category ids.
     * @param string $taxonomy     Taxonomy slug.
     * @return array Filtered list of category ids.
     */
    public function get_translated_taxonomy_terms_and_append_to_list( $category_ids, $taxonomy = 'product_cat' ) {
        global $sitepress;

        if ( $sitepress ) {
            $translated_category_ids = wpml_collect( $category_ids )
                ->map(
                    function ( $category_id ) use ( $sitepress, $taxonomy ) {
                    return $sitepress->get_object_id( $category_id, $taxonomy );
                    }
                )
                ->filter();

            $category_ids = array_merge( $category_ids, $translated_category_ids->all() );
        }

        return $category_ids;
    }

    /**
     * Convert amount to from base currency to user selected currency (or reverse).
     *
     * @since 1.4
     * @access public
     *
     * @param float $amount     Amount to convert.
     * @param bool  $is_reverse Convert from user to base currency if true.
     * @param array $settings   List of settings.
     * @return float Converted amount.
     */
    public function convert_amount_to_user_selected_currency( $amount, $is_reverse = false, $settings = array() ) {
        // Check if amount is null before doing the conversion.
        if ( null === $amount ) {
            return $amount;
        }

        $multi_currency = $this->_get_multi_currency();

        if ( ! $multi_currency ) {
            return $amount;
        }

        $user_currency = isset( $settings['user_currency'] ) ? $settings['user_currency'] : $multi_currency->get_client_currency();
        $site_currency = isset( $settings['site_currency'] ) ? $settings['site_currency'] : wcml_get_woocommerce_currency_option();

        if ( $site_currency === $user_currency ) {
            return $amount;
        }

        // convert from user to base.
        if ( $is_reverse ) {
            return $multi_currency->prices->convert_price_amount_by_currencies( $amount, $user_currency, $site_currency );
        } else { // convert from base to user.
            return $multi_currency->prices->convert_price_amount_by_currencies( $amount, $site_currency, $user_currency );
        }
    }

    /**
     * Return cart total converted to user selected currency.
     *
     * @since 4.0
     * @access public
     *
     * @param float $cart_total Cart total.
     * @return float Filtered cart total.
     */
    public function convert_cart_total_to_user_based_currency( $cart_total ) {
        $multi_currency = $this->_get_multi_currency();
        $user_currency  = $multi_currency->get_client_currency();
        $site_currency  = wcml_get_woocommerce_currency_option();

        return $multi_currency->prices->convert_price_amount_by_currencies( $cart_total, $site_currency, $user_currency );
    }

    /**
     * Save user currency to store credits discount session.
     *
     * @since 4.0
     * @access public
     *
     * @param array $sc_discount Session data.
     * @return array Filtered session data.
     */
    public function save_user_currency_to_store_credits_discount_session( $sc_discount ) {
        global $woocommerce_wpml;

        // if multi currency is disabled, return the session data as is.
        if ( ! $woocommerce_wpml->settings['enable_multi_currency'] ) {
            return $sc_discount;
        }

        $sc_discount['currency'] = $this->_get_multi_currency()->get_client_currency();
        return $sc_discount;
    }

    /**
     * Validate the store credits discount currency on cart totals calculation.
     * When the currency saved in session is different from the users currency in WPML, then we convert the currency from
     * session to the new value, and update the session data as well.
     *
     * @since 4.0
     * @access public
     *
     * @param array  $sc_discount Session data.
     * @param string $session_name Session option name.
     * @return float Filtered discount amount.
     */
    public function validate_user_currency_on_apply_store_credits_discount( $sc_discount, $session_name ) {
        global $woocommerce_wpml;

        // if multi currency is disabled, return the session data as is.
        if ( ! $woocommerce_wpml->settings['enable_multi_currency'] ) {
            return $sc_discount;
        }

        if ( isset( $sc_discount['currency'] ) && $sc_discount['currency'] !== $this->_get_multi_currency()->get_client_currency() ) {

            // convert back from previously selected currency to site currency.
            $amount = $this->_get_multi_currency()->prices->convert_price_amount_by_currencies(
                $sc_discount['amount'],
                $sc_discount['currency'],
                wcml_get_woocommerce_currency_option()
            );

            // convert from site currency to newly selected currency.
            $amount = $this->_get_multi_currency()->prices->convert_price_amount_by_currencies(
                $amount,
                wcml_get_woocommerce_currency_option(),
                $this->_get_multi_currency()->get_client_currency()
            );

            if ( 0 >= $amount ) {
                \WC()->session->set( $session_name, null );
            } else {
                $sc_discount['amount']   = $amount;
                $sc_discount['currency'] = $this->_get_multi_currency()->get_client_currency();
                \WC()->session->set( $session_name, $sc_discount );
            }
        }

        return $sc_discount;
    }

    /**
     * Add additional instruction for coupon URL field (URL Coupons module).
     *
     * @since 1.4
     * @access public
     *
     * @param array $fields List of fields.
     * @return array Filtered list of fields.
     */
    public function add_instructions_to_coupon_url_field( $fields ) {
        global $sitepress;

        if ( ! $sitepress ) {
            return $fields;
        }

        $fields = array_map(
            function ( $f ) use ( $sitepress ) {

                if ( Plugin_Constants::META_PREFIX . 'coupon_url' === $f['args']['id'] ) {

                    $active_languages = $sitepress->get_active_languages();
                    $default_language = $sitepress->get_default_language();
                    $prepend_desc     = '';
                    $redirect_url     = $f['args']['value'];
                    $language_format  = apply_filters( 'wpml_setting', 0, 'language_negotiation_type' ); // Get language format option.
                    $language_format  = ( $language_format ) ? intval( $language_format ) : $language_format; // Convert to integer.

                    foreach ( $active_languages as $key => $lang ) {
                        // Continue if the language is the default language.
                        if ( $default_language === $key ) {
                            continue;
                        }

                        /**
                         * There are 3 types of language format:
                         * 1. Directory format: http://example.com/en/
                         * 2. Different domain per language (includes subdomains): http://example.fr/ or http://fr.example.com/
                         * 3. Query format: http://example.com/?lang=en
                         */
                        $format = '';
                        if ( 1 === $language_format ) {
                            $translated_url = $redirect_url;

                            // Remove the default language directory first to prevent double directories (e.g., /en/bn/).
                            $translated_url = $this->_maybe_remove_default_language_directory( $translated_url, $sitepress, $default_language );

                            // Add the secondary language directory.
                            $translated_url = str_replace( '/coupon/', '/' . $key . '/coupon/', $translated_url );

                            $format = sprintf(
                                '<code>%s</code>',
                                $translated_url
                            );
                        } elseif ( 2 === $language_format ) {
                            // Different domain per language format.
                            $language_domains = $sitepress->get_setting( 'language_domains' );
                            if ( is_array( $language_domains ) && ! empty( $language_domains[ $key ] ) ) {
                                $parsed_url      = wp_parse_url( $redirect_url );
                                $language_domain = $language_domains[ $key ];

                                // Remove protocol if present in the language domain.
                                $language_domain = preg_replace( '#^https?://#', '', $language_domain );

                                // Construct the translated URL with the language-specific domain.
                                $translated_url = sprintf(
                                    '%s://%s%s',
                                    isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] : 'https',
                                    $language_domain,
                                    isset( $parsed_url['path'] ) ? $parsed_url['path'] : ''
                                );

                                $format = sprintf(
                                    '<code>%s</code>',
                                    $translated_url
                                );
                            }
                        } elseif ( 3 === $language_format ) {
                            $format = sprintf( '<code>%s?lang=%s</code>', $redirect_url, $key );
                        }

                        // Add the language format to the description.
                        $prepend_desc .= sprintf( '<br><strong>%s:</strong> %s', $lang['display_name'], $format );
                    }

                    $f['args']['description'] = $prepend_desc . $f['args']['description'];
                }

                return $f;
            },
            $fields
        );

        return $fields;
    }

    /**
     * Filter the priority level for implementing BOGO (Buy One Get One) deals.
     *
     * Ensures that advanced BOGO logic runs after WPML's recursive
     * `calculate_totals()` adjustments, preventing duplicate processing
     * and product ID mismatches.
     *
     * @since 4.6.9
     * @access public
     *
     * @param int $priority The default priority value.
     * @return int The filtered priority value (20) for stable BOGO execution.
     */
    public function filter_bogo_implementation_priority( $priority ) {
        return 20;
    }

    /**
     * Remove default language directory from coupon URL for secondary languages.
     *
     * When WPML is set to "Use directory for default language" and a secondary language is active,
     * this removes the default language directory from the coupon URL to prevent URLs like
     * /en/bn/coupon/code/ and ensures they become /bn/coupon/code/ instead.
     *
     * @since 4.6.8.1
     * @access public
     *
     * @param string          $coupon_url Coupon URL.
     * @param Advanced_Coupon $coupon     Coupon object.
     * @return string Filtered coupon URL.
     */
    public function remove_default_language_directory_from_coupon_url( $coupon_url, $coupon ) {
        global $sitepress;

        // Return early if WPML is not active or sitepress is not available.
        if ( ! $sitepress ) {
            return $coupon_url;
        }

        $current_language = $sitepress->get_current_language();
        $default_language = $sitepress->get_default_language();

        // Only process if current language is not the default language.
        if ( $current_language === $default_language ) {
            return $coupon_url;
        }

        // Remove default language directory if the setting is enabled.
        $coupon_url = $this->_maybe_remove_default_language_directory( $coupon_url, $sitepress, $default_language );

        return $coupon_url;
    }

    /**
     * Remove default language directory from URL if the WPML setting is enabled.
     *
     * This helper method checks if "Use directory for default language" option is enabled
     * and removes the default language directory from the URL to prevent double directories.
     *
     * @since 4.7.1
     * @access private
     *
     * @param string $url               The URL to process.
     * @param object $sitepress         The WPML sitepress object.
     * @param string $default_language  The default language code.
     * @return string The processed URL with default language directory removed if applicable.
     */
    private function _maybe_remove_default_language_directory( $url, $sitepress, $default_language ) {
        // Check if "Use directory for default language" option is enabled.
        $language_settings = $sitepress->get_setting( 'urls' );
        if ( ! isset( $language_settings['directory_for_default_language'] ) || 1 !== (int) $language_settings['directory_for_default_language'] ) {
            return $url;
        }

        // Remove the default language directory from the URL.
        // Example: https://wpml.test/en/bn/coupon/welcome10/ -> https://wpml.test/bn/coupon/welcome10/.
        $pattern = '#/' . preg_quote( $default_language, '#' ) . '/#';
        $url     = preg_replace( $pattern, '/', $url, 1 );

        return $url;
    }

    /**
     * Check if all required WPML plugins are active.
     *
     * @since 1.4.2
     * @access private
     *
     * @return bool True if all plugins active, false otherwise.
     */
    private function _is_wpml_requirements_installed() {
        return $this->_helper_functions->is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' )
        && $this->_helper_functions->is_plugin_active( 'woocommerce-multilingual/wpml-woocommerce.php' )
        && \function_exists( 'icl_st_init' ) // string translation plugin.
        && \function_exists( 'wpml_tm_load_element_translations' ); // translation management plugin.
    }

    /**
     * Get multi currency class instance.
     *
     * @since 4.0
     * @access private
     *
     * @return object
     */
    private function _get_multi_currency() {
        global $woocommerce_wpml;

        return $woocommerce_wpml ? $woocommerce_wpml->get_multi_currency() : null;
    }

    /**
     * Dequeue WPML styles and scripts on AGC admin pages.
     *
     * @since 1.4
     * @access public
     *
     * @param string $handle Unique identifier of the current backend page.
     */
    public function dequeue_wpml_styles_scripts_agc_admin( $handle ) {
        if ( ! did_action( 'acfw_admin_app_enqueue_scripts_after' ) ) {
            return;
        }

        wp_dequeue_script( 'wpml-select-2' );
        wp_dequeue_style( 'sitepress-style' );
        wp_dequeue_style( 'otgs-dialogs' );
        wp_dequeue_style( 'wpml-dialog' );
        wp_dequeue_style( 'otgs-dialogs' );
        wp_dequeue_style( 'otgs-icons' );
        wp_dequeue_style( 'wpml-wizard' );
        wp_dequeue_script( 'sitepress-scripts-js' );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Add hooks when WPML plugin is loaded.
     *
     * @since 3.1.2
     * @access public
     */
    public function wpml_loaded() {
        if ( ! $this->_is_wpml_requirements_installed() ) {
            return;
        }

        $this->register_translatable_strings_for_coupons();
        $this->register_translatable_setting_strings();

        add_filter( 'acfw_bogo_implementation_priority', array( $this, 'filter_bogo_implementation_priority' ) );

        add_filter( 'acfw_string_meta', array( $this, 'apply_translation_coupon_field' ), 10, 3 );
        add_filter( 'acfw_string_option', array( $this, 'apply_translation_setting_strings' ), 10, 2 );

        add_action( 'acfw_rest_api_context', array( $this, 'remove_currency_setting_filters' ) );
        add_filter( 'acfw_json_search_products_response', array( $this, 'remove_translated_versions_of_products' ) );
        add_filter( 'acfw_json_search_product_categories_response', array( $this, 'remove_translated_versions_of_categories' ) );
        add_filter( 'acfwf_cart_condition_category_option', array( $this, 'remove_translated_versions_of_categories' ) );
        add_filter( 'acfw_filter_cart_item_product_id', array( $this, 'get_cart_item_original_product_id' ) );
        add_filter( 'acfw_filter_product_tax_terms', array( $this, 'get_translated_taxonomy_terms_and_append_to_list' ), 10, 2 );
        add_filter( 'acfw_filter_amount', array( $this, 'convert_amount_to_user_selected_currency' ), 10, 3 );
        add_filter( 'acfw_store_credits_get_cart_total', array( $this, 'convert_cart_total_to_user_based_currency' ) );
        add_filter( 'acfw_store_credits_discount_session', array( $this, 'save_user_currency_to_store_credits_discount_session' ) );
        add_filter( 'acfw_before_apply_store_credit_discount', array( $this, 'validate_user_currency_on_apply_store_credits_discount' ), 10, 2 );

        add_filter( 'acfw_url_coupons_admin_data_panel_fields', array( $this, 'add_instructions_to_coupon_url_field' ) );
        add_filter( 'acfw_coupon_url', array( $this, 'remove_default_language_directory_from_coupon_url' ), 10, 2 );
    }

    /**
     * Execute WPML_Support class.
     *
     * @since 1.3
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        // priority is set to 110 so it runs after the WPML strings translation is loaded.
        add_action( 'wpml_loaded', array( $this, 'wpml_loaded' ), 110 );
        add_action( 'admin_enqueue_scripts', array( $this, 'dequeue_wpml_styles_scripts_agc_admin' ), 999 );
    }
}
