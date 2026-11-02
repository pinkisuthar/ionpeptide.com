<?php
/**
 * Plugin Name: BRW Free Shipping Progress
 * Plugin URI: https://buildrightweb.com
 * Version: 1.0.5
 * Author: BuildRightWeb
 * Author URI: https://buildrightweb.com
 * Text Domain: brw-free-shipping-progress
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BRW_Free_Shipping_Progress {
    const VERSION      = '1.0.5';
    const OPTION_KEY   = 'brw_free_shipping_progress_settings';
    const MENU_SLUG    = 'brw-free-shipping-progress';
    const SHORTCODE    = 'brw_free_shipping_progress';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_notices', [ __CLASS__, 'render_admin_notices' ] );
        add_action( 'wp_ajax_brw_fsp_refresh', [ __CLASS__, 'ajax_refresh_markup' ] );
        add_action( 'wp_ajax_nopriv_brw_fsp_refresh', [ __CLASS__, 'ajax_refresh_markup' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'woocommerce_before_cart', [ __CLASS__, 'render_cart_hook' ], 8 );
        add_action( 'woocommerce_before_checkout_form', [ __CLASS__, 'render_checkout_hook' ], 8 );
        add_action( 'woocommerce_before_mini_cart_contents', [ __CLASS__, 'render_mini_cart_hook' ], 4 );
        add_action( 'woocommerce_widget_shopping_cart_before_buttons', [ __CLASS__, 'render_widget_cart_hook' ], 4 );
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'shortcode' ] );
        add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'add_cart_fragments' ] );
        add_filter( 'render_block', [ __CLASS__, 'inject_into_cart_block' ], 20, 2 );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ __CLASS__, 'plugin_action_links' ] );
    }

    public static function defaults() {
        return [
            'enabled'                => 1,
            'threshold_mode'         => 'auto',
            'manual_threshold'       => '250.00',
            'amount_basis'           => 'after_discounts',
            'include_shipping'       => 0,
            'cart_enabled'           => 1,
            'checkout_enabled'       => 1,
            'mini_cart_enabled'      => 1,
            'hide_when_unlocked'     => 0,
            'heading'                => 'Free Shipping',
            'remaining_message'      => 'Add {amount} more to unlock free shipping',
            'success_message'        => 'Free shipping unlocked',
            'bar_bg'                 => '#e7f0ff',
            'bar_fill'               => '#007eff',
            'text_color'             => '#0d0d67',
            'box_bg'                 => '#ffffff',
            'box_border'             => '#e5e7eb',
            'show_icon'              => 1,
        ];
    }

    public static function get_settings() {
        $settings = get_option( self::OPTION_KEY, [] );
        $settings = is_array( $settings ) ? $settings : [];
        return wp_parse_args( $settings, self::defaults() );
    }

    public static function register_admin_menu() {
        add_menu_page(
            'BRW Free Shipping Progress',
            'Shipping Bar',
            'manage_woocommerce',
            self::MENU_SLUG,
            [ __CLASS__, 'render_admin_page' ],
            'dashicons-airplane',
            58
        );
    }

    public static function register_settings() {
        register_setting( 'brw_free_shipping_progress_group', self::OPTION_KEY, [ __CLASS__, 'sanitize_settings' ] );
    }

    public static function sanitize_settings( $input ) {
        $existing = self::get_settings();
        $input    = is_array( $input ) ? $input : [];

        $threshold_mode = in_array( (string) ( $input['threshold_mode'] ?? '' ), [ 'auto', 'manual' ], true ) ? (string) $input['threshold_mode'] : $existing['threshold_mode'];
        $amount_basis   = in_array( (string) ( $input['amount_basis'] ?? '' ), [ 'before_discounts', 'after_discounts' ], true ) ? (string) $input['amount_basis'] : $existing['amount_basis'];

        $settings = [
            'enabled'            => ! empty( $input['enabled'] ) ? 1 : 0,
            'threshold_mode'     => $threshold_mode,
            'manual_threshold'   => self::sanitize_amount( $input['manual_threshold'] ?? $existing['manual_threshold'] ),
            'amount_basis'       => $amount_basis,
            'include_shipping'   => ! empty( $input['include_shipping'] ) ? 1 : 0,
            'cart_enabled'       => ! empty( $input['cart_enabled'] ) ? 1 : 0,
            'checkout_enabled'   => ! empty( $input['checkout_enabled'] ) ? 1 : 0,
            'mini_cart_enabled'  => ! empty( $input['mini_cart_enabled'] ) ? 1 : 0,
            'hide_when_unlocked' => ! empty( $input['hide_when_unlocked'] ) ? 1 : 0,
            'heading'            => sanitize_text_field( (string) ( $input['heading'] ?? $existing['heading'] ) ),
            'remaining_message'  => sanitize_text_field( (string) ( $input['remaining_message'] ?? $existing['remaining_message'] ) ),
            'success_message'    => sanitize_text_field( (string) ( $input['success_message'] ?? $existing['success_message'] ) ),
            'bar_bg'             => self::sanitize_hex_color_or_default( $input['bar_bg'] ?? $existing['bar_bg'], self::defaults()['bar_bg'] ),
            'bar_fill'           => self::sanitize_hex_color_or_default( $input['bar_fill'] ?? $existing['bar_fill'], self::defaults()['bar_fill'] ),
            'text_color'         => self::sanitize_hex_color_or_default( $input['text_color'] ?? $existing['text_color'], self::defaults()['text_color'] ),
            'box_bg'             => self::sanitize_hex_color_or_default( $input['box_bg'] ?? $existing['box_bg'], self::defaults()['box_bg'] ),
            'box_border'         => self::sanitize_hex_color_or_default( $input['box_border'] ?? $existing['box_border'], self::defaults()['box_border'] ),
            'show_icon'          => ! empty( $input['show_icon'] ) ? 1 : 0,
        ];

        add_settings_error( self::OPTION_KEY, 'settings_saved', 'BRW Free Shipping Progress settings saved.', 'updated' );
        return $settings;
    }

    public static function sanitize_amount( $value ) {
        $value = is_numeric( $value ) ? (float) $value : 0.0;
        $value = max( 0, $value );
        return number_format( $value, 2, '.', '' );
    }

    private static function sanitize_hex_color_or_default( $value, $default ) {
        $value = trim( (string) $value );
        $san   = sanitize_hex_color( $value );
        return $san ? $san : $default;
    }

    public static function plugin_action_links( $links ) {
        $links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">Settings</a>';
        return $links;
    }

    public static function render_admin_notices() {
        settings_errors( self::OPTION_KEY );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings     = self::get_settings();
        $auto_minimum = self::get_detected_threshold();
        ?>
        <div class="wrap">
            <h1>BRW Free Shipping Progress</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'brw_free_shipping_progress_group' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">Enable progress bar</th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> Turn the progress UI on</label></td>
                        </tr>
                        <tr>
                            <th scope="row">Threshold source</th>
                            <td>
                                <label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[threshold_mode]" value="auto" <?php checked( $settings['threshold_mode'], 'auto' ); ?> /> Auto detect from enabled WooCommerce free-shipping method</label><br />
                                <label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[threshold_mode]" value="manual" <?php checked( $settings['threshold_mode'], 'manual' ); ?> /> Manual threshold</label>
                                <p class="description">Detected native threshold: <?php echo $auto_minimum > 0 && function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $auto_minimum ) ) : 'No enabled free-shipping minimum amount found.'; ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Manual threshold</th>
                            <td><input type="number" min="0" step="0.01" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[manual_threshold]" value="<?php echo esc_attr( $settings['manual_threshold'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Progress calculation</th>
                            <td>
                                <label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[amount_basis]" value="after_discounts" <?php checked( $settings['amount_basis'], 'after_discounts' ); ?> /> Use subtotal after coupons</label><br />
                                <label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[amount_basis]" value="before_discounts" <?php checked( $settings['amount_basis'], 'before_discounts' ); ?> /> Use subtotal before coupons</label><br />
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[include_shipping]" value="1" <?php checked( ! empty( $settings['include_shipping'] ) ); ?> /> Include shipping total in progress math</label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Display locations</th>
                            <td>
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cart_enabled]" value="1" <?php checked( ! empty( $settings['cart_enabled'] ) ); ?> /> Cart page</label><br />
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[checkout_enabled]" value="1" <?php checked( ! empty( $settings['checkout_enabled'] ) ); ?> /> Checkout page</label><br />
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mini_cart_enabled]" value="1" <?php checked( ! empty( $settings['mini_cart_enabled'] ) ); ?> /> Mini cart / side cart hooks</label><br />
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hide_when_unlocked]" value="1" <?php checked( ! empty( $settings['hide_when_unlocked'] ) ); ?> /> Hide the box once free shipping is unlocked</label>
                                <p class="description">Shortcode available anywhere: <code>[<?php echo esc_html( self::SHORTCODE ); ?>]</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Heading</th>
                            <td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[heading]" value="<?php echo esc_attr( $settings['heading'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Remaining message</th>
                            <td>
                                <input type="text" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[remaining_message]" value="<?php echo esc_attr( $settings['remaining_message'] ); ?>" />
                                <p class="description">Use <code>{amount}</code> where the amount left should appear.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Unlocked message</th>
                            <td><input type="text" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[success_message]" value="<?php echo esc_attr( $settings['success_message'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Colors</th>
                            <td>
                                <label>Box background <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[box_bg]" value="<?php echo esc_attr( $settings['box_bg'] ); ?>" /></label><br />
                                <label>Box border <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[box_border]" value="<?php echo esc_attr( $settings['box_border'] ); ?>" /></label><br />
                                <label>Bar background <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bar_bg]" value="<?php echo esc_attr( $settings['bar_bg'] ); ?>" /></label><br />
                                <label>Bar fill <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bar_fill]" value="<?php echo esc_attr( $settings['bar_fill'] ); ?>" /></label><br />
                                <label>Text color <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[text_color]" value="<?php echo esc_attr( $settings['text_color'] ); ?>" /></label><br />
                                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_icon]" value="1" <?php checked( ! empty( $settings['show_icon'] ) ); ?> /> Show truck icon</label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p><button type="submit" class="button button-primary">Save Settings</button></p>
            </form>
        </div>
        <?php
    }

    public static function enqueue_assets() {
        if ( is_admin() || ! function_exists( 'WC' ) || ! self::is_frontend_enabled() ) {
            return;
        }

        wp_enqueue_style(
            'brw-free-shipping-progress',
            plugins_url( 'assets/brw-free-shipping-progress.css', __FILE__ ),
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'brw-free-shipping-progress',
            plugins_url( 'assets/brw-free-shipping-progress.js', __FILE__ ),
            [ 'jquery' ],
            self::VERSION,
            true
        );

        wp_localize_script(
            'brw-free-shipping-progress',
            'brwFsp',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'brw_fsp_refresh' ),
            ]
        );
    }

    public static function shortcode( $atts = [] ) {
        return self::get_progress_markup( 'shortcode', true );
    }

    public static function ajax_refresh_markup() {
        if ( ! check_ajax_referer( 'brw_fsp_refresh', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ], 403 );
        }

        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->calculate_totals();
        }

        wp_send_json_success(
            [
                'html' => self::get_progress_markup( 'ajax_refresh', true ),
            ]
        );
    }

    public static function render_cart_hook() {
        $settings = self::get_settings();
        if ( empty( $settings['cart_enabled'] ) ) {
            return;
        }
        echo self::get_progress_markup( 'cart' );
    }

    public static function render_checkout_hook() {
        $settings = self::get_settings();
        if ( empty( $settings['checkout_enabled'] ) || is_order_received_page() ) {
            return;
        }
        echo self::get_progress_markup( 'checkout' );
    }

    public static function render_mini_cart_hook() {
        $settings = self::get_settings();
        if ( empty( $settings['mini_cart_enabled'] ) ) {
            return;
        }
        echo self::get_progress_markup( 'mini_cart' );
    }

    public static function render_widget_cart_hook() {
        $settings = self::get_settings();
        if ( empty( $settings['mini_cart_enabled'] ) ) {
            return;
        }
        echo self::get_progress_markup( 'widget_mini_cart' );
    }

    public static function inject_into_cart_block( $block_content, $block ) {
        static $did_inject = false;

        if ( $did_inject || is_admin() || ! is_array( $block ) ) {
            return $block_content;
        }

        $block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

        if ( 'woocommerce/cart' !== $block_name && 'woocommerce/filled-cart-block' !== $block_name ) {
            return $block_content;
        }

        $settings = self::get_settings();
        if ( empty( $settings['cart_enabled'] ) ) {
            return $block_content;
        }

        $markup = self::get_progress_markup( 'cart_block', true );
        if ( '' === trim( $markup ) ) {
            return $block_content;
        }

        $did_inject = true;
        return $markup . $block_content;
    }

    public static function add_cart_fragments( $fragments ) {
        if ( ! self::is_frontend_enabled() ) {
            return $fragments;
        }

        $settings = self::get_settings();
        if ( empty( $settings['mini_cart_enabled'] ) ) {
            return $fragments;
        }

        ob_start();
        echo self::get_progress_markup( 'mini_cart_fragment', true );
        $fragments['div.brw-fsp-fragment'] = ob_get_clean();

        return $fragments;
    }

    private static function get_detected_threshold() {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            return 0.0;
        }

        $minimums = [];
        $zones    = WC_Shipping_Zones::get_zones();
        $zones[]  = [ 'zone_id' => 0 ];

        foreach ( $zones as $zone_data ) {
            $zone_id = isset( $zone_data['zone_id'] ) ? (int) $zone_data['zone_id'] : 0;
            $zone    = new WC_Shipping_Zone( $zone_id );
            $methods = $zone->get_shipping_methods( true );

            foreach ( $methods as $method ) {
                if ( empty( $method->enabled ) || 'yes' !== $method->enabled || 'free_shipping' !== $method->id ) {
                    continue;
                }

                $requires = isset( $method->requires ) ? (string) $method->requires : '';
                $min       = isset( $method->min_amount ) ? (float) $method->min_amount : 0.0;
                if ( $min <= 0 ) {
                    continue;
                }

                if ( in_array( $requires, [ 'min_amount', 'either', 'both' ], true ) ) {
                    $minimums[] = $min;
                }
            }
        }

        if ( empty( $minimums ) ) {
            return 0.0;
        }

        return (float) min( $minimums );
    }

    private static function get_threshold() {
        $settings = self::get_settings();
        if ( 'manual' === $settings['threshold_mode'] ) {
            return (float) $settings['manual_threshold'];
        }

        $detected = self::get_detected_threshold();
        if ( $detected > 0 ) {
            return $detected;
        }

        return (float) $settings['manual_threshold'];
    }

    private static function has_free_shipping_coupon() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }

        $applied_coupons = WC()->cart->get_applied_coupons();
        if ( empty( $applied_coupons ) || ! is_array( $applied_coupons ) ) {
            return false;
        }

        foreach ( $applied_coupons as $coupon_code ) {
            $coupon = new WC_Coupon( $coupon_code );
            if ( is_a( $coupon, 'WC_Coupon' ) && $coupon->get_free_shipping() ) {
                return true;
            }
        }

        return false;
    }

    private static function get_progress_data() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return [
                'threshold'      => 0.0,
                'current'        => 0.0,
                'remaining'      => 0.0,
                'percent'        => 0,
                'is_unlocked'    => false,
                'message'        => '',
                'amount_display' => '',
            ];
        }

        $settings  = self::get_settings();
        $threshold = (float) self::get_threshold();
        $current   = 0.0;

        if ( 'before_discounts' === $settings['amount_basis'] ) {
            $current = (float) WC()->cart->get_subtotal();
        } else {
            $current = (float) WC()->cart->get_cart_contents_total();
        }

        if ( ! empty( $settings['include_shipping'] ) ) {
            $current += (float) WC()->cart->get_shipping_total();
        }

        $current       = max( 0, $current );
        $coupon_unlock = self::has_free_shipping_coupon();
        $remaining     = $coupon_unlock ? 0 : max( 0, $threshold - $current );
        $is_unlocked   = $coupon_unlock || ( $threshold > 0 && $current >= $threshold );
        $percent       = $coupon_unlock ? 100 : ( $threshold > 0 ? (int) round( min( 100, ( $current / $threshold ) * 100 ) ) : 0 );
        $amount_label  = wc_price( $remaining );
        $message       = $is_unlocked ? $settings['success_message'] : str_replace( '{amount}', wp_strip_all_tags( $amount_label ), $settings['remaining_message'] );

        return [
            'threshold'      => $threshold,
            'current'        => $current,
            'remaining'      => $remaining,
            'percent'        => $percent,
            'is_unlocked'    => $is_unlocked,
            'message'        => $message,
            'amount_display' => $amount_label,
        ];
    }

    private static function get_progress_markup( $context = 'default', $force_wrap = false ) {
        if ( ! self::is_frontend_enabled() || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return '';
        }

        $settings = self::get_settings();
        $data     = self::get_progress_data();

        if ( $data['threshold'] <= 0 ) {
            return '';
        }

        if ( ! empty( $settings['hide_when_unlocked'] ) && ! empty( $data['is_unlocked'] ) ) {
            return '';
        }

        ob_start();
        ?>
        <div class="brw-fsp-fragment">
            <div class="brw-fsp brw-fsp--<?php echo esc_attr( sanitize_html_class( $context ) ); ?><?php echo ! empty( $data['is_unlocked'] ) ? ' is-unlocked' : ''; ?>"
                 style="--brw-fsp-box-bg: <?php echo esc_attr( $settings['box_bg'] ); ?>; --brw-fsp-box-border: <?php echo esc_attr( $settings['box_border'] ); ?>; --brw-fsp-bar-bg: <?php echo esc_attr( $settings['bar_bg'] ); ?>; --brw-fsp-bar-fill: <?php echo esc_attr( $settings['bar_fill'] ); ?>; --brw-fsp-text: <?php echo esc_attr( $settings['text_color'] ); ?>;">
                <div class="brw-fsp__topline">
                    <?php if ( ! empty( $settings['show_icon'] ) ) : ?>
                        <span class="brw-fsp__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path d="M3 6.75A1.75 1.75 0 0 1 4.75 5h8.5C14.216 5 15 5.784 15 6.75V8h2.586c.464 0 .909.184 1.237.513l1.664 1.664c.328.328.513.773.513 1.237V15.5a1.5 1.5 0 0 1-1.5 1.5h-.673a2.75 2.75 0 0 1-5.154 0H9.327a2.75 2.75 0 0 1-5.154 0H4.5A1.5 1.5 0 0 1 3 15.5v-8.75Zm2.75-.25a.25.25 0 0 0-.25.25v8.75h.004a2.75 2.75 0 0 1 5.492 0H13.5V6.75a.25.25 0 0 0-.25-.25h-7.5Zm10.75 3V15.5h.173a2.75 2.75 0 0 1 5.154 0H22v-4.086a.25.25 0 0 0-.073-.177l-1.664-1.664a.25.25 0 0 0-.177-.073H16.5ZM7 16.25a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5Zm9.5 0a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5Z" fill="currentColor"/></svg>
                        </span>
                    <?php endif; ?>
                    <div class="brw-fsp__copy">
                        <?php if ( '' !== trim( (string) $settings['heading'] ) ) : ?>
                            <div class="brw-fsp__heading"><?php echo esc_html( $settings['heading'] ); ?></div>
                        <?php endif; ?>
                        <div class="brw-fsp__message"><?php echo esc_html( $data['message'] ); ?></div>
                    </div>
                </div>
                <div class="brw-fsp__bar-row">
                    <div class="brw-fsp__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $data['percent'] ); ?>" aria-label="Free shipping progress">
                        <span class="brw-fsp__bar-fill" style="width: <?php echo esc_attr( (string) $data['percent'] ); ?>%;"></span>
                    </div>
                    <span class="brw-fsp__percent"><?php echo esc_html( (string) $data['percent'] ); ?>%</span>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function is_frontend_enabled() {
        $settings = self::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return false;
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            return false;
        }

        return true;
    }
}

BRW_Free_Shipping_Progress::init();
