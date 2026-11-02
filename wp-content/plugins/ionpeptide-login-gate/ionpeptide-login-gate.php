<?php
/**
 * Plugin Name: Ion Peptide Login Gate
 * Plugin URI: https://buildrightweb.com
 * Description: Standalone Ion Peptide research access gate with native WordPress auth, reCAPTCHA v3, honeypot, disposable email blocking, and email verification.
 * Version: 1.7.10
 * Author: Build Right Web
 * Author URI: https://buildrightweb.com
 * License: GPLv2 or later
 * Text Domain: ionpeptide-login-gate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'IONLG_VER', '1.7.10' );
if ( ! defined( 'IONLG_BUNDLED_GOOGLE_CLIENT_ID' ) ) {
    define( 'IONLG_BUNDLED_GOOGLE_CLIENT_ID', '437092261506-llv55ko6anvqescip00frtgio3e9akns.apps.googleusercontent.com' );
}
if ( ! defined( 'IONLG_BUNDLED_SENDGRID_API_KEY' ) ) {
    define( 'IONLG_BUNDLED_SENDGRID_API_KEY', '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' );
}
if ( ! defined( 'IONLG_BUNDLED_OMNISEND_API_KEY' ) ) {
    define( 'IONLG_BUNDLED_OMNISEND_API_KEY', '' );
}
define( 'IONLG_DIR', plugin_dir_path( __FILE__ ) );
define( 'IONLG_URL', plugin_dir_url( __FILE__ ) );
require_once IONLG_DIR . 'includes/class-ionlg-registration-blocks.php';


final class IonPeptide_Login_Gate {

    private static $instance = null;

    const OPTION_KEY    = 'ionpeptide_login_gate_options';
    const VERIFIED_META = 'ionpeptide_email_verified';
    const TOKEN_META    = 'ionpeptide_email_verification_token';
    const TOKEN_TIME    = 'ionpeptide_email_token_time';

    private $gate_slug = 'research-access';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'register_rewrite' ) );
        add_action( 'init', array( $this, 'handle_posts' ), 8 );
        add_action( 'init', array( $this, 'handle_email_verification' ), 9 );

        add_action( 'template_redirect', array( $this, 'maybe_redirect_to_gate' ), 1 );
        add_action( 'template_redirect', array( $this, 'maybe_render_gate_page' ), 0 );
        add_action( 'wp_footer', array( $this, 'maybe_render_ui_gate_overlay' ), 999 );

        add_action( 'send_headers', array( $this, 'send_no_cache_headers' ), 0 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_ionlg_test_omnisend', array( $this, 'handle_admin_omnisend_test' ) );

        add_action( 'wp_ajax_nopriv_ionlg_google_login', array( $this, 'ajax_google_login' ) );
        add_action( 'wp_ajax_nopriv_ionlg_google_complete', array( $this, 'ajax_google_complete' ) );

        add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );
        add_filter( 'lostpassword_url', array( $this, 'filter_lostpassword_url' ), 10, 2 );
        add_filter( 'logout_url', array( $this, 'filter_logout_url' ), 10, 2 );
        add_filter( 'logout_redirect', array( $this, 'filter_logout_redirect' ), 10, 3 );

        // Backward-compatible shortcode from the old child theme.
        add_shortcode( 'ionpeptide_resend_verification', array( $this, 'resend_verification_shortcode' ) );
    }

    public static function activate() {
        $existing = get_option( self::OPTION_KEY, array() );
        update_option( self::OPTION_KEY, wp_parse_args( is_array( $existing ) ? $existing : array(), self::defaults() ), false );
        self::instance()->register_rewrite();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    private static function defaults() {
        return array(
            'enabled'                    => 'yes',
            'gate_slug'                  => 'research-access',
            'logo_url'                   => 'https://i0.wp.com/ionpeptide.com/wp-content/uploads/2025/08/ion-peptide-logo-1.jpg?resize=300%2C148&ssl=1',
            'right_bg_url'               => 'https://ionpeptide.com/wp-content/uploads/2026/02/Ion-peptide.png',
            'brand_title'                => 'Ion Peptide',
            'brand_subtitle'             => 'Wholesale Research Supply Portal',
            'google_login_enabled'       => 'yes',
            'google_client_id'           => IONLG_BUNDLED_GOOGLE_CLIENT_ID,
            'email_verification_enabled' => 'yes',
            'verify_existing_users'      => 'no',
            'resend_cooldown_seconds'    => '60',
            'block_disposable_email'     => 'yes',
            'recaptcha_enabled'          => 'no',
            'recaptcha_site_key'         => '',
            'recaptcha_secret_key'       => '',
            'recaptcha_min_score'        => '0.3',
            'recaptcha_fail_open'        => 'yes',
            'allow_approved_crawlers'    => 'yes',
            'from_email'                 => 'support@ionpeptide.com',
            'from_name'                  => 'Ion Peptide',
            'email_delivery_method'      => 'sendgrid',
            'sendgrid_api_key'           => IONLG_BUNDLED_SENDGRID_API_KEY,
            'omnisend_enabled'           => 'yes',
            'omnisend_api_key'           => IONLG_BUNDLED_OMNISEND_API_KEY,
        );
    }

    private function options() {
        $opts = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $opts ) ) {
            $opts = array();
        }
        $opts = wp_parse_args( $opts, self::defaults() );
        $this->gate_slug = sanitize_title( $opts['gate_slug'] ?: 'research-access' );
        return $opts;
    }

    private function opt( $key ) {
        $opts = $this->options();
        return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
    }

    private function enabled() {
        return 'yes' === $this->opt( 'enabled' );
    }

    private function recaptcha_enabled() {
        return 'yes' === $this->opt( 'recaptcha_enabled' ) && $this->opt( 'recaptcha_site_key' ) && $this->opt( 'recaptcha_secret_key' );
    }

    private function email_verification_enabled() {
        return 'yes' === $this->opt( 'email_verification_enabled' );
    }

    private function google_client_id() {
        $client_id = defined( 'IONLG_GOOGLE_CLIENT_ID' ) ? IONLG_GOOGLE_CLIENT_ID : $this->opt( 'google_client_id' );
        return is_string( $client_id ) ? trim( $client_id ) : '';
    }

    private function google_login_available() {
        return 'yes' === $this->opt( 'google_login_enabled' ) && '' !== $this->google_client_id();
    }

    public function register_rewrite() {
        $slug = sanitize_title( $this->opt( 'gate_slug' ) ?: 'research-access' );
        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'ionlg_gate';
            return $vars;
        } );
        add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?ionlg_gate=1', 'top' );
    }

    private function request_path_matches_gate() {
        if ( (int) get_query_var( 'ionlg_gate', 0 ) === 1 ) {
            return true;
        }

        $slug = sanitize_title( $this->opt( 'gate_slug' ) ?: 'research-access' );
        $path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        $path = is_string( $path ) ? '/' . trim( $path, '/' ) : '';
        $target = '/' . trim( $slug, '/' );

        $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $home_path = is_string( $home_path ) ? rtrim( $home_path, '/' ) : '';
        if ( $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
            $path = substr( $path, strlen( $home_path ) );
            $path = '/' . trim( $path, '/' );
        }

        return rtrim( $path, '/' ) === rtrim( $target, '/' );
    }

    private function gate_url( $redirect_to = '' ) {
        $slug = sanitize_title( $this->opt( 'gate_slug' ) ?: 'research-access' );
        $url = home_url( '/' . trim( $slug, '/' ) . '/' );
        if ( $redirect_to ) {
            $url = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url );
        }
        return $url;
    }

    private function current_full_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? wp_parse_url( home_url(), PHP_URL_HOST );
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return esc_url_raw( $scheme . '://' . $host . $uri );
    }

    private function is_exempt_request() {
        if ( is_admin() || wp_doing_ajax() ) return true;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return true;
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) return true;
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) return true;

        $path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        $path = is_string( $path ) ? $path : '';

        if ( false !== stripos( $path, 'wp-login.php' ) ) return true;
        if ( false !== stripos( $path, 'wp-admin' ) ) return true;
        if ( 0 === strpos( $path, '/wp-content/' ) ) return true;
        if ( 0 === strpos( $path, '/wp-includes/' ) ) return true;
        if ( 0 === strpos( $path, '/wp-json/' ) ) return true;
        if ( 0 === strpos( $path, '/wc-api/' ) ) return true;
        if ( preg_match( '#\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|map|txt|xml)$#i', $path ) ) return true;

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if ( is_string( $accept ) && $accept && false === stripos( $accept, 'text/html' ) && false === stripos( $accept, '*/*' ) ) return true;

        return false;
    }

    /**
     * Allow approved SEO/audit crawlers to see protected public content while humans stay gated.
     *
     * This keeps the storefront gated for normal visitors, but prevents Google/Bing and
     * Lighthouse/PageSpeed from being redirected to the standalone access page.
     *
     * Important: user-agent checks are meant for SEO access, not high-security access control.
     * The store still blocks normal visitors, but a determined technical user could spoof a UA.
     */
    private function approved_crawlers_enabled() {
        return 'yes' === $this->opt( 'allow_approved_crawlers' );
    }

    private function is_approved_crawler_request() {
        if ( ! $this->approved_crawlers_enabled() ) {
            return false;
        }

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( '' === $ua ) {
            return false;
        }

        $approved_patterns = array(
            'Googlebot',
            'Googlebot-Image',
            'Googlebot-News',
            'Googlebot-Video',
            'Google-InspectionTool',
            'Google-Site-Verification',
            'AdsBot-Google',
            'Mediapartners-Google',
            'Chrome-Lighthouse',
            'Lighthouse',
            'PageSpeed',
            'bingbot',
            'BingPreview',
            'DuckDuckBot',
            'Applebot',
            'Slurp',
            'YandexBot',
            'Baiduspider',
            'Sogou',
            'SemrushBot',
            'AhrefsBot',
            'SiteAuditBot',
            'Screaming Frog SEO Spider',
        );

        $approved_patterns = apply_filters( 'ionpeptide_login_gate_approved_crawler_user_agents', $approved_patterns );

        foreach ( $approved_patterns as $pattern ) {
            $pattern = is_string( $pattern ) ? trim( $pattern ) : '';
            if ( '' !== $pattern && false !== stripos( $ua, $pattern ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Certified Pep gate logic: only capture the destination on normal HTML GET requests.
     * This keeps AJAX/assets/POST requests from poisoning the redirect cookie and causing loops.
     */
    private function should_capture_intent() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ( 'GET' !== strtoupper( $method ) ) return false;

        $path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        $path = is_string( $path ) ? $path : '';
        if ( '' === $path ) return false;

        if ( 0 === strpos( $path, '/wp-content/' ) ) return false;
        if ( 0 === strpos( $path, '/wp-includes/' ) ) return false;
        if ( 0 === strpos( $path, '/wp-json/' ) ) return false;
        if ( 0 === strpos( $path, '/wc-api/' ) ) return false;
        if ( preg_match( '#\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|map|txt|xml)$#i', $path ) ) return false;

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if ( is_string( $accept ) && '' !== $accept && false === stripos( $accept, 'text/html' ) && false === stripos( $accept, '*/*' ) ) {
            return false;
        }

        return true;
    }

    private function safe_redirect_target( $maybe_url = '' ) {
        $fallback = home_url( '/' );
        $maybe_url = is_string( $maybe_url ) ? trim( $maybe_url ) : '';
        if ( '' === $maybe_url ) return $fallback;

        $maybe_url = esc_url_raw( $maybe_url );
        $maybe_url = wp_validate_redirect( $maybe_url, $fallback );

        $path = wp_parse_url( $maybe_url, PHP_URL_PATH );
        $path = is_string( $path ) ? $path : '';
        $gate_path = '/' . trim( sanitize_title( $this->opt( 'gate_slug' ) ?: 'research-access' ), '/' );

        if ( $path && 0 === stripos( rtrim( $path, '/' ), rtrim( $gate_path, '/' ) ) ) return $fallback;
        if ( false !== stripos( $path, 'wp-login.php' ) ) return $fallback;
        if ( false !== stripos( $path, 'wp-admin' ) ) return $fallback;
        if ( preg_match( '#\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|map)$#i', $path ) ) return $fallback;

        return $maybe_url ?: $fallback;
    }


    private function current_page_logout_redirect_target() {
        $target = $this->current_full_url();

        if ( ! empty( $_REQUEST['redirect_to'] ) ) {
            $target = sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
            $target = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
        }

        return $this->safe_redirect_target( $target );
    }

    private function get_intent() {
        $rt = isset( $_GET['redirect_to'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) ) : '';
        if ( ! $rt && ! empty( $_COOKIE['ionlg_intent'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_COOKIE['ionlg_intent'] ) );
            $pad = strlen( $raw ) % 4;
            if ( $pad ) $raw .= str_repeat( '=', 4 - $pad );
            $decoded = base64_decode( strtr( $raw, '-_', '+/' ) );
            if ( $decoded ) $rt = esc_url_raw( $decoded );
        }
        return $this->safe_redirect_target( $rt );
    }

    private function set_intent_cookie( $url ) {
        $b64 = rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );
        setcookie( 'ionlg_intent', $b64, time() + 900, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
        if ( COOKIEPATH !== '/' ) {
            setcookie( 'ionlg_intent', $b64, time() + 900, '/', COOKIE_DOMAIN, is_ssl(), true );
        }
    }

    private function resolve_post_auth_redirect( $redirect_to = '' ) {
        $rt = is_string( $redirect_to ) ? trim( $redirect_to ) : '';

        if ( ! $rt && ! empty( $_COOKIE['ionlg_intent'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_COOKIE['ionlg_intent'] ) );
            $pad = strlen( $raw ) % 4;
            if ( $pad ) $raw .= str_repeat( '=', 4 - $pad );
            $decoded = base64_decode( strtr( $raw, '-_', '+/' ) );
            if ( $decoded ) $rt = esc_url_raw( $decoded );
        }

        return $this->safe_redirect_target( $rt );
    }

    private function clear_intent_cookie() {
        if ( isset( $_COOKIE['ionlg_intent'] ) ) {
            setcookie( 'ionlg_intent', '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
            if ( COOKIEPATH !== '/' ) {
                setcookie( 'ionlg_intent', '', time() - 3600, '/', COOKIE_DOMAIN, is_ssl(), true );
            }
        }
    }

    /**
     * Ion Peptide hybrid gate logic.
     *
     * UI gate: SEO-facing public catalog pages still render their full HTML/source,
     * then logged-out human visitors see a front-end compliance/login layer.
     *
     * Redirect/true gate: private account and transaction pages still redirect
     * before content loads.
     */
    private function normalized_request_path() {
        $path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        $path = is_string( $path ) ? '/' . trim( $path, '/' ) : '/';
        if ( '//' === $path || '' === $path ) {
            $path = '/';
        }

        $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $home_path = is_string( $home_path ) ? rtrim( $home_path, '/' ) : '';
        if ( $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
            $path = substr( $path, strlen( $home_path ) );
            $path = '/' . trim( $path, '/' );
        }

        return '/' === $path ? '/' : rtrim( $path, '/' );
    }

    private function should_redirect_gate_current_request() {
        if ( function_exists( 'is_cart' ) && is_cart() ) return true;
        if ( function_exists( 'is_checkout' ) && is_checkout() ) return true;
        if ( function_exists( 'is_account_page' ) && is_account_page() ) return true;

        $path = $this->normalized_request_path();

        $protected_exact = array(
            '/cart',
            '/checkout',
            '/checkout-new',
            '/my-account',
        );

        $protected_prefixes = array(
            '/cart/',
            '/checkout/',
            '/checkout-new/',
            '/my-account/',
            '/order-pay/',
            '/order-received/',
        );

        if ( in_array( $path, $protected_exact, true ) ) {
            return true;
        }

        foreach ( $protected_prefixes as $prefix ) {
            if ( 0 === stripos( $path . '/', $prefix ) ) {
                return true;
            }
        }

        return (bool) apply_filters( 'ionpeptide_login_gate_should_redirect_gate_request', false, $path );
    }

    private function should_ui_gate_current_request() {
        // Never UI-gate private/transactional pages. Those stay true-gated.
        if ( $this->should_redirect_gate_current_request() ) {
            return false;
        }

        if ( is_front_page() || is_home() ) {
            return true;
        }

        if ( function_exists( 'is_shop' ) && is_shop() ) return true;
        if ( function_exists( 'is_product' ) && is_product() ) return true;
        if ( function_exists( 'is_product_category' ) && is_product_category() ) return true;
        if ( function_exists( 'is_product_tag' ) && is_product_tag() ) return true;

        $path = $this->normalized_request_path();

        $ui_exact = array(
            '/',
            '/shop',
            '/shop-all',
            '/shop-peptides',
            '/shop-bioregulators',
            '/shop-peptide-capsules',
        );

        $ui_prefixes = array(
            '/shop/',
            '/shop-all/',
            '/shop-peptides/',
            '/shop-bioregulators/',
            '/shop-peptide-capsules/',
            '/product/',
            '/product-category/',
            '/product-tag/',
        );

        if ( in_array( $path, $ui_exact, true ) ) {
            return true;
        }

        foreach ( $ui_prefixes as $prefix ) {
            if ( 0 === stripos( $path . '/', $prefix ) ) {
                return true;
            }
        }

        return (bool) apply_filters( 'ionpeptide_login_gate_should_ui_gate_request', false, $path );
    }

    private function should_gate_current_request() {
        return $this->should_redirect_gate_current_request() || $this->should_ui_gate_current_request();
    }

    public function maybe_redirect_to_gate() {
        if ( ! $this->enabled() ) return;
        if ( is_user_logged_in() ) return;
        if ( $this->is_approved_crawler_request() ) return;
        if ( $this->request_path_matches_gate() ) return;
        if ( $this->is_exempt_request() ) return;
        if ( ! $this->should_redirect_gate_current_request() ) return;

        if ( $this->should_capture_intent() ) {
            $intent = $this->current_full_url();
            if ( $intent ) $this->set_intent_cookie( $intent );
        }

        wp_safe_redirect( $this->gate_url(), 302 );
        exit;
    }


    private function render_gate_layout( $opts, $msg, $panel, $mode, $redirect_to ) {
        $asset = trailingslashit( IONLG_URL . 'assets/img' );
        ?>
        <div class="ion-auth-container">
            <header class="ion-gate-header">
                <div class="ion-gate-header-inner">
                    <img class="ion-auth-logo" src="<?php echo esc_url( $asset . 'ion-logo-transparent.png' ); ?>" alt="Ion Peptide logo">
                    <div class="ion-gate-trust" aria-label="Portal qualities">
                        <span>High-Purity Compounds</span><i aria-hidden="true"></i><span>Trusted Quality</span><i aria-hidden="true"></i><span>Research Use Only</span>
                    </div>
                </div>
            </header>

            <div class="ion-gate-main">
                <section class="ion-auth-left" aria-label="Ion Peptide account access">
                    <div class="ion-auth-box">
                        <div class="ion-card-intro">
                            <h2 class="ion-brand-title">Welcome to <span>Ion Peptide</span></h2>
                            <p class="ion-brand-sub">Wholesale Research Supply Portal</p>
                        </div>

                        <div class="ion-auth-form-surface">
                            <?php $this->render_notice( $msg ); ?>

                            <?php if ( 'forgot' === $mode ) : ?>
                                <?php $this->render_lost_password_form( $redirect_to ); ?>
                            <?php else : ?>
                                <div class="ion-auth-tabs" role="tablist" aria-label="Account access">
                                    <button type="button" class="ion-tab <?php echo 'register' === $panel ? '' : 'active'; ?>" data-target="login">Sign In</button>
                                    <button type="button" class="ion-tab <?php echo 'register' === $panel ? 'active' : ''; ?>" data-target="register">Register</button>
                                </div>
                                <div class="ion-woo-account">
                                    <?php $this->render_login_form( $redirect_to, 'register' !== $panel ); ?>
                                    <?php $this->render_register_form( $redirect_to, 'register' === $panel ); ?>
                                    <?php $this->render_google_complete_form( $redirect_to ); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="ion-auth-right" aria-label="Ion Peptide research quality highlights">
                    <div class="ion-right-content">
                        <p class="ion-eyebrow">Research Today. Discover Tomorrow.</p>
                        <h1>High-Purity <span>Research Compounds</span></h1>
                        <p class="ion-hero-copy">Ion Peptide supplies analytically verified research materials produced under stringent quality control standards, ensuring purity, stability, and batch consistency.</p>

                        <div class="ion-feature-grid">
                            <article class="ion-feature">
                                <div class="ion-feature-icon"><img src="<?php echo esc_url( $asset . 'icon-pricing.svg' ); ?>" alt="" aria-hidden="true"></div>
                                <div><h4>Institutional &amp;<br>Bulk Pricing</h4><p>Competitive wholesale rates for laboratories, distributors, and research supply partners.</p></div>
                            </article>
                            <article class="ion-feature">
                                <div class="ion-feature-icon"><img src="<?php echo esc_url( $asset . 'icon-testing.svg' ); ?>" alt="" aria-hidden="true"></div>
                                <div><h4>Third-Party<br>Analytical Testing</h4><p>Batch-controlled synthesis with independent analytical testing. Research Use Only.</p></div>
                            </article>
                            <article class="ion-feature">
                                <div class="ion-feature-icon"><img src="<?php echo esc_url( $asset . 'icon-consistency.svg' ); ?>" alt="" aria-hidden="true"></div>
                                <div><h4>Batch Consistency</h4><p>Reliable, reproducible materials, produced under strict quality control standards.</p></div>
                            </article>
                            <article class="ion-feature">
                                <div class="ion-feature-icon"><img src="<?php echo esc_url( $asset . 'icon-support.svg' ); ?>" alt="" aria-hidden="true"></div>
                                <div><h4>Dedicated Support</h4><p>Same-day shipping on in-stock inventory.<br>Live Chat daily.<br>9:00 A.M. – 1:00 A.M. EST.</p></div>
                            </article>
                        </div>
                    </div>

                    <div class="ion-hero-art" aria-hidden="true">
                        <img class="ion-hex-pattern" src="<?php echo esc_url( $asset . 'hex-pattern.svg' ); ?>" alt="">
                        <div class="ion-lab-frame"><img src="<?php echo esc_url( $asset . 'lab-art-v172.webp' ); ?>" alt=""></div>
                        <div class="ion-molecule-frame"><img src="<?php echo esc_url( $asset . 'molecule-art-v172.webp' ); ?>" alt=""></div>
                    </div>
                </section>
            </div>

            <footer class="ion-gate-footer">
                <div class="ion-gate-footer-inner">
                    <span>© 2026 Ion Peptide. All rights reserved.</span>
                    <span>Research Use Only. Not for human or veterinary use.</span>
                </div>
            </footer>
        </div>
        <?php
    }

    public function maybe_render_gate_page() {
        if ( ! $this->enabled() || ! $this->request_path_matches_gate() ) return;

        if ( is_user_logged_in() ) {
            $dest = $this->get_intent();
            $this->clear_intent_cookie();
            wp_safe_redirect( $dest, 302 );
            exit;
        }

        status_header( 200 );
        $this->send_gate_cache_headers();

        $opts = $this->options();
        $msg = isset( $_GET['ionlg_msg'] ) ? sanitize_key( wp_unslash( $_GET['ionlg_msg'] ) ) : '';
        $panel = isset( $_GET['ionlg_panel'] ) && 'register' === $_GET['ionlg_panel'] ? 'register' : 'login';
        $mode = isset( $_GET['mode'] ) && 'forgot' === $_GET['mode'] ? 'forgot' : 'access';
        $redirect_to = $this->get_intent();
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <meta name="description" content="Ion Peptide provides secure account access to its research product catalog, checkout, order history, and customer resources.">
    <title><?php echo esc_html( $opts['brand_title'] ); ?> — Access</title>
    <?php wp_head(); ?>
</head>
<body class="ionlg-body">
    <main id="ion-auth-overlay" class="<?php echo 'register' === $panel ? 'ion-mode-register' : ( 'forgot' === $mode ? 'ion-mode-forgot' : 'ion-mode-login' ); ?>" data-ion-login-gate="standalone-page">
        <?php $this->render_gate_layout( $opts, $msg, $panel, $mode, $redirect_to ); ?>
    </main>
    <?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }


    public function maybe_render_ui_gate_overlay() {
        if ( ! $this->enabled() ) return;
        if ( is_user_logged_in() ) return;
        if ( $this->is_approved_crawler_request() ) return;
        if ( $this->request_path_matches_gate() ) return;
        if ( $this->is_exempt_request() ) return;
        if ( ! $this->should_ui_gate_current_request() ) return;

        $opts = $this->options();
        $msg = isset( $_GET['ionlg_msg'] ) ? sanitize_key( wp_unslash( $_GET['ionlg_msg'] ) ) : '';
        $panel = isset( $_GET['ionlg_panel'] ) && 'register' === $_GET['ionlg_panel'] ? 'register' : 'login';
        $mode = isset( $_GET['mode'] ) && 'forgot' === $_GET['mode'] ? 'forgot' : 'access';
        $redirect_to = $this->current_full_url();
        ?>
        <main id="ion-auth-overlay" class="ionlg-ui-overlay <?php echo 'register' === $panel ? 'ion-mode-register' : ( 'forgot' === $mode ? 'ion-mode-forgot' : 'ion-mode-login' ); ?>" data-ion-login-gate="ui-overlay" aria-modal="true" role="dialog" aria-label="Ion Peptide account access">
            <?php $this->render_gate_layout( $opts, $msg, $panel, $mode, $redirect_to ); ?>
        </main>
        <script>
        (function(){
            document.documentElement.classList.add('ion-ui-gate-active');
            if (document.body) document.body.classList.add('ion-ui-gate-active');
        })();
        </script>
        <?php
    }

    public function handle_posts() {
        if ( empty( $_POST['ionlg_action'] ) ) return;
        if ( is_admin() ) return;

        $this->no_cache_constants();
        $action = sanitize_key( wp_unslash( $_POST['ionlg_action'] ) );
        $redirect_to = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';

        if ( 'login' === $action ) {
            $this->process_login( $redirect_to );
        } elseif ( 'login_2fa' === $action ) {
            $this->process_login_2fa( $redirect_to );
        } elseif ( 'register' === $action ) {
            $this->process_register( $redirect_to );
        } elseif ( 'lostpass' === $action ) {
            $this->process_lost_password( $redirect_to );
        } elseif ( 'resend' === $action ) {
            $this->process_resend();
        }
    }

    private function process_login( $redirect_to ) {
        if ( ! isset( $_POST['ionlg_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ionlg_nonce'] ) ), 'ionlg_login' ) ) {
            $this->redirect_back( 'security', array( 'redirect_to' => $redirect_to ) );
        }
        $this->guard_bot_or_redirect( array( 'redirect_to' => $redirect_to ) );

        $raw_login = isset( $_POST['user_login'] ) ? wp_unslash( $_POST['user_login'] ) : '';
        $user_login = is_string( $raw_login ) ? trim( $raw_login ) : '';
        $user_pass  = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : '';
        $remember   = ! empty( $_POST['rememberme'] );

        if ( '' === $user_login || '' === $user_pass ) {
            $this->redirect_back( 'invalid_login', array( 'redirect_to' => $redirect_to ) );
        }

        /*
         * v1.4.2 hard fix:
         * Some hosts/security plugins/Woo stacks can poison wp_signon() on custom gate URLs and return
         * the same generic wrong-password message even when the password is correct. So we resolve
         * the user first, check the stored WP password hash directly, then set the auth cookies ourselves.
         * Honeypot + reCAPTCHA + nonce still run before this point.
         */
        $lookup_key = sanitize_text_field( $user_login );
        $user = is_email( $lookup_key ) ? get_user_by( 'email', sanitize_email( $lookup_key ) ) : get_user_by( 'login', sanitize_user( $lookup_key, true ) );

        if ( ! $user && ! is_email( $lookup_key ) ) {
            $maybe = get_user_by( 'slug', sanitize_title( $lookup_key ) );
            if ( $maybe ) {
                $user = $maybe;
            }
        }

        if ( ! $user || empty( $user->ID ) || empty( $user->user_pass ) ) {
            $this->redirect_back( 'invalid_login', array( 'redirect_to' => $redirect_to ) );
        }

        if ( $this->user_must_verify( $user ) ) {
            $this->redirect_back( 'not_verified', array( 'redirect_to' => $redirect_to ) );
        }

        $password_ok = wp_check_password( $user_pass, $user->user_pass, $user->ID );

        if ( ! $password_ok ) {
            // Fallback for sites using custom external auth storage.
            $signon = wp_signon( array(
                'user_login'    => $user->user_login,
                'user_password' => $user_pass,
                'remember'      => $remember,
            ), is_ssl() );

            if ( is_wp_error( $signon ) ) {
                $this->redirect_back( 'invalid_login', array( 'redirect_to' => $redirect_to ) );
            }

            $user = $signon;
        }

        $wfls_state = $this->wordfence_2fa_state_for_user( $user );

        if ( 'blocked' === $wfls_state ) {
            $this->redirect_back( 'twofactor_not_setup', array( 'redirect_to' => $redirect_to ) );
        }

        if ( 'challenge' === $wfls_state ) {
            $challenge = $this->create_wordfence_2fa_challenge( $user, $remember, $redirect_to );
            if ( ! $challenge ) {
                $this->redirect_back( 'twofactor_unavailable', array( 'redirect_to' => $redirect_to ) );
            }

            $this->redirect_back( 'twofactor_required', array(
                'redirect_to'      => $redirect_to,
                'ionlg_2fa'        => '1',
                'ionlg_challenge'  => $challenge,
            ) );
        }

        $this->complete_login( $user, $remember, $redirect_to );
    }

    private function process_login_2fa( $redirect_to ) {
        if ( ! isset( $_POST['ionlg_2fa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ionlg_2fa_nonce'] ) ), 'ionlg_login_2fa' ) ) {
            $this->redirect_back( 'security', array( 'redirect_to' => $redirect_to ) );
        }

        $challenge = isset( $_POST['ionlg_challenge'] ) ? sanitize_text_field( wp_unslash( $_POST['ionlg_challenge'] ) ) : '';
        $code      = isset( $_POST['ionlg_2fa_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ionlg_2fa_code'] ) ) ) : '';

        if ( '' === $challenge || '' === $code ) {
            $this->redirect_back( 'twofactor_invalid', array( 'redirect_to' => $redirect_to ) );
        }

        $payload = $this->get_wordfence_2fa_challenge( $challenge );
        if ( ! is_array( $payload ) || empty( $payload['user_id'] ) ) {
            $this->redirect_back( 'twofactor_expired', array( 'redirect_to' => $redirect_to ) );
        }

        $user = get_user_by( 'id', absint( $payload['user_id'] ) );
        if ( ! $user || empty( $user->ID ) ) {
            $this->delete_wordfence_2fa_challenge( $challenge );
            $this->redirect_back( 'twofactor_expired', array( 'redirect_to' => $redirect_to ) );
        }

        $wfls_state = $this->wordfence_2fa_state_for_user( $user );
        if ( 'blocked' === $wfls_state ) {
            $this->delete_wordfence_2fa_challenge( $challenge );
            $this->redirect_back( 'twofactor_not_setup', array( 'redirect_to' => $redirect_to ) );
        }

        if ( 'challenge' !== $wfls_state ) {
            $this->delete_wordfence_2fa_challenge( $challenge );
            $this->complete_login( $user, ! empty( $payload['remember'] ), ! empty( $payload['redirect_to'] ) ? $payload['redirect_to'] : $redirect_to );
        }

        $valid = $this->validate_wordfence_2fa_code( $user, $code );

        if ( true !== $valid ) {
            $attempts = isset( $payload['attempts'] ) ? absint( $payload['attempts'] ) + 1 : 1;
            if ( $attempts >= 5 ) {
                $this->delete_wordfence_2fa_challenge( $challenge );
                $this->redirect_back( 'twofactor_expired', array( 'redirect_to' => $redirect_to ) );
            }

            $payload['attempts'] = $attempts;
            $this->store_wordfence_2fa_challenge( $challenge, $payload );
            $this->redirect_back( 'twofactor_invalid', array(
                'redirect_to'      => ! empty( $payload['redirect_to'] ) ? $payload['redirect_to'] : $redirect_to,
                'ionlg_2fa'        => '1',
                'ionlg_challenge'  => $challenge,
            ) );
        }

        $this->delete_wordfence_2fa_challenge( $challenge );
        $this->complete_login( $user, ! empty( $payload['remember'] ), ! empty( $payload['redirect_to'] ) ? $payload['redirect_to'] : $redirect_to );
    }

    private function complete_login( $user, $remember, $redirect_to ) {
        update_user_meta( $user->ID, 'ionpeptide_research_accepted_at', time() );

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
        if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
            wc_set_customer_auth_cookie( $user->ID );
        }

        do_action( 'wp_login', $user->user_login, $user );

        $dest = $this->resolve_post_auth_redirect( $redirect_to );
        $this->clear_intent_cookie();

        wp_safe_redirect( $dest, 302 );
        exit;
    }

    private function wordfence_2fa_state_for_user( $user ) {
        if ( defined( 'IONLG_WORDFENCE_2FA_DISABLE' ) && IONLG_WORDFENCE_2FA_DISABLE ) {
            return 'none';
        }

        if ( ! $user || empty( $user->ID ) || ! $this->wordfence_2fa_user_in_scope( $user ) ) {
            return 'none';
        }

        if ( ! class_exists( '\\WordfenceLS\\Controller_Users' ) || ! class_exists( '\\WordfenceLS\\Controller_TOTP' ) ) {
            return 'none';
        }

        $users = \WordfenceLS\Controller_Users::shared();

        $has_active = method_exists( $users, 'has_2fa_active' ) ? $users->has_2fa_active( $user ) : false;

        if ( $has_active ) {
            return 'challenge';
        }

        $requires = false;
        if ( method_exists( $users, 'requires_2fa' ) ) {
            $in_grace_period = false;
            $required_at = null;
            $requires = $users->requires_2fa( $user, $in_grace_period, $required_at );
        }

        if ( $requires ) {
            return 'blocked';
        }

        return 'none';
    }

    private function wordfence_2fa_user_in_scope( $user ) {
        if ( is_multisite() && is_super_admin( $user->ID ) ) {
            return true;
        }

        return user_can( $user, 'manage_options' );
    }

    private function validate_wordfence_2fa_code( $user, $code ) {
        if ( ! class_exists( '\\WordfenceLS\\Controller_TOTP' ) ) {
            return null;
        }

        return \WordfenceLS\Controller_TOTP::shared()->validate_2fa( $user, $code );
    }

    private function wordfence_2fa_transient_key( $challenge ) {
        return 'ionlg_wfls_2fa_' . preg_replace( '/[^a-f0-9]/', '', strtolower( $challenge ) );
    }

    private function create_wordfence_2fa_challenge( $user, $remember, $redirect_to ) {
        try {
            $challenge = bin2hex( random_bytes( 32 ) );
        } catch ( Exception $e ) {
            $challenge = wp_generate_password( 64, false, false );
        }

        $payload = array(
            'user_id'     => absint( $user->ID ),
            'remember'    => $remember ? 1 : 0,
            'redirect_to' => sanitize_text_field( $redirect_to ),
            'created'     => time(),
            'attempts'    => 0,
        );

        $this->store_wordfence_2fa_challenge( $challenge, $payload );

        return $challenge;
    }

    private function store_wordfence_2fa_challenge( $challenge, $payload ) {
        set_transient( $this->wordfence_2fa_transient_key( $challenge ), $payload, 10 * MINUTE_IN_SECONDS );
    }

    private function get_wordfence_2fa_challenge( $challenge ) {
        return get_transient( $this->wordfence_2fa_transient_key( $challenge ) );
    }

    private function delete_wordfence_2fa_challenge( $challenge ) {
        delete_transient( $this->wordfence_2fa_transient_key( $challenge ) );
    }

    private function process_register( $redirect_to ) {
        if ( ! isset( $_POST['ionlg_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ionlg_nonce'] ) ), 'ionlg_register' ) ) {
            $this->redirect_back( 'security', array( 'ionlg_panel' => 'register' ) );
        }
        $this->guard_bot_or_redirect( array( 'ionlg_panel' => 'register' ) );

        $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
        $confirm  = isset( $_POST['confirm_password'] ) ? (string) wp_unslash( $_POST['confirm_password'] ) : '';
        $full     = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
        $dob      = isset( $_POST['dob'] ) ? sanitize_text_field( wp_unslash( $_POST['dob'] ) ) : '';
        $business = isset( $_POST['business_type'] ) ? sanitize_text_field( wp_unslash( $_POST['business_type'] ) ) : '';
        $phone    = isset( $_POST['phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number'] ) ) : '';
        $company  = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';

        if ( ! is_email( $email ) || '' === $password || '' === $confirm || '' === $full || '' === $dob || '' === $business || '' === $phone || empty( $_POST['acknowledge_terms'] ) ) {
            $this->redirect_back( 'register_required', array( 'ionlg_panel' => 'register' ) );
        }
        if ( $password !== $confirm ) {
            $this->redirect_back( 'password_mismatch', array( 'ionlg_panel' => 'register' ) );
        }
        if ( strlen( $password ) < 8 ) {
            $this->redirect_back( 'password_short', array( 'ionlg_panel' => 'register' ) );
        }
        if ( email_exists( $email ) ) {
            $this->redirect_back( 'email_exists', array( 'ionlg_panel' => 'register' ) );
        }
        if ( IonLG_Registration_Blocks::matched_rule( $email ) ) {
            $this->redirect_back( 'email_domain_blocked', array( 'ionlg_panel' => 'register' ) );
        }
        if ( 'yes' === $this->opt( 'block_disposable_email' ) && $this->is_disposable_email( $email ) ) {
            $this->redirect_back( 'temp_email', array( 'ionlg_panel' => 'register' ) );
        }

        if ( ! $this->is_at_least_21( $dob ) ) {
            $this->redirect_back( 'underage', array( 'ionlg_panel' => 'register' ) );
        }

        $base = sanitize_user( current( explode( '@', $email ) ), true );
        $username = $base ?: 'ionuser';
        if ( username_exists( $username ) ) {
            $username = $base . '_' . wp_generate_password( 6, false, false );
        }

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            $this->redirect_back( 'register_failed', array( 'ionlg_panel' => 'register' ) );
        }

        $user = new WP_User( $user_id );
        if ( get_role( 'customer' ) ) {
            $user->set_role( 'customer' );
        }
        $this->save_profile_fields( $user_id, $full, $dob, $company, $business, $phone );
        $this->sync_user_to_omnisend( $user_id, $full, $dob, $company, $business, $phone );

        if ( $this->email_verification_enabled() ) {
            update_user_meta( $user_id, self::VERIFIED_META, 0 );
            $this->send_verification_email( $user_id );
            $this->redirect_back( 'registered_verify' );
        }

        update_user_meta( $user_id, self::VERIFIED_META, 1 );
        $this->redirect_back( 'registered_login' );
    }

    private function process_lost_password( $redirect_to ) {
        if ( ! isset( $_POST['ionlg_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ionlg_nonce'] ) ), 'ionlg_lostpass' ) ) {
            $this->redirect_back( 'security', array( 'mode' => 'forgot' ) );
        }
        $this->guard_bot_or_redirect( array( 'mode' => 'forgot' ) );

        $login = isset( $_POST['lost_user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['lost_user_login'] ) ) : '';
        if ( '' === $login ) {
            $this->redirect_back( 'lost_required', array( 'mode' => 'forgot' ) );
        }

        $user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
        if ( $user && ! empty( $user->user_login ) ) {
            retrieve_password( $user->user_login );
        }
        $this->redirect_back( 'reset_sent' );
    }

    private function process_resend() {
        if ( ! isset( $_POST['ionlg_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ionlg_nonce'] ) ), 'ionlg_resend' ) ) {
            $this->redirect_back( 'security' );
        }
        if ( $this->honeypot_filled() ) {
            $this->redirect_back( 'bot_blocked' );
        }
        $email = isset( $_POST['ion_email'] ) ? sanitize_email( wp_unslash( $_POST['ion_email'] ) ) : '';
        $user = is_email( $email ) ? get_user_by( 'email', $email ) : false;
        if ( ! $user ) {
            $this->redirect_back( 'invalid_login' );
        }
        if ( ! $this->user_must_verify( $user ) ) {
            $this->redirect_back( 'registered_login' );
        }

        $last = intval( get_user_meta( $user->ID, self::TOKEN_TIME, true ) );
        $cooldown = intval( $this->opt( 'resend_cooldown_seconds' ) );
        if ( $cooldown > 0 && $last && ( time() - $last ) < $cooldown ) {
            $this->redirect_back( 'resend_wait' );
        }
        $this->send_verification_email( $user->ID );
        $this->redirect_back( 'resent' );
    }

    private function guard_bot_or_redirect( $extra = array() ) {
        if ( $this->honeypot_filled() ) {
            $this->redirect_back( 'bot_blocked', $extra );
        }
        $captcha = $this->verify_recaptcha();
        if ( is_wp_error( $captcha ) ) {
            $this->redirect_back( 'bot_blocked', $extra );
        }
    }

    private function honeypot_filled() {
        return ! empty( $_POST['ion_company_website'] );
    }

    private function verify_recaptcha() {
        if ( ! $this->recaptcha_enabled() ) return true;

        $token = isset( $_POST['ion_recaptcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ion_recaptcha_token'] ) ) : '';
        if ( '' === $token ) {
            return 'yes' === $this->opt( 'recaptcha_fail_open' ) ? true : new WP_Error( 'recaptcha_missing', 'Security verification failed.' );
        }

        $response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
            'timeout' => 10,
            'body'    => array(
                'secret'   => $this->opt( 'recaptcha_secret_key' ),
                'response' => $token,
                'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return 'yes' === $this->opt( 'recaptcha_fail_open' ) ? true : new WP_Error( 'recaptcha_error', 'Security verification failed.' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['success'] ) ) {
            return 'yes' === $this->opt( 'recaptcha_fail_open' ) ? true : new WP_Error( 'recaptcha_failed', 'Security verification failed.' );
        }
        if ( isset( $body['score'] ) && floatval( $body['score'] ) < floatval( $this->opt( 'recaptcha_min_score' ) ) ) {
            return new WP_Error( 'recaptcha_low_score', 'Security verification failed.' );
        }
        return true;
    }

    private function redirect_back( $code, $extra = array() ) {
        $redirect_to = '';
        if ( isset( $extra['redirect_to'] ) ) {
            $redirect_to = sanitize_text_field( $extra['redirect_to'] );
            unset( $extra['redirect_to'] );
        } elseif ( ! empty( $_POST['redirect_to'] ) ) {
            $redirect_to = sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) );
        } elseif ( ! empty( $_GET['redirect_to'] ) ) {
            $redirect_to = rawurldecode( sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) );
        }

        $args = array_merge( array( 'ionlg_msg' => sanitize_key( $code ), 'nocache' => time() ), $extra );

        if ( ! empty( $_POST['ionlg_ui_gate'] ) ) {
            $url = $this->safe_redirect_target( $redirect_to ?: $this->current_full_url() );
            wp_safe_redirect( add_query_arg( $args, $url ), 302 );
            exit;
        }

        $url  = $this->gate_url( $redirect_to );
        wp_safe_redirect( add_query_arg( $args, $url ), 302 );
        exit;
    }

    public function send_no_cache_headers() {
        if ( ! $this->enabled() ) return;
        if ( $this->is_approved_crawler_request() && ! $this->request_path_matches_gate() ) return;

        $is_gate_flow = $this->request_path_matches_gate() || ! empty( $_POST['ionlg_action'] ) || ! empty( $_GET['ionlg_msg'] ) || ! empty( $_GET['ion_verify'] );

        // True-gated/private flows must never cache. UI-gated catalog pages are allowed
        // to render normally for SEO; only form/message flows get no-cache headers.
        if ( $is_gate_flow || ( ! is_user_logged_in() && ! $this->is_exempt_request() && $this->should_redirect_gate_current_request() && $this->should_capture_intent() ) ) {
            $this->send_gate_cache_headers();
        }
    }

    private function no_cache_constants() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
        if ( ! defined( 'DONOTCACHEDB' ) ) define( 'DONOTCACHEDB', true );
    }

    private function send_gate_cache_headers() {
        $this->no_cache_constants();
        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
        header( 'Pragma: no-cache', true );
        header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
        header( 'Surrogate-Control: no-store', true );
        header( 'Vary: Cookie', false );
        header( 'X-Ion-Login-Gate: no-cache' );
    }

    public function enqueue_assets() {
        $needs_gate_assets = $this->request_path_matches_gate() || ( $this->enabled() && ! is_user_logged_in() && ! $this->is_exempt_request() && ! $this->is_approved_crawler_request() && $this->should_ui_gate_current_request() );
        if ( ! $needs_gate_assets ) return;

        if ( $this->request_path_matches_gate() ) {
            $this->no_cache_constants();
        }
        wp_enqueue_style( 'ionpeptide-login-gate', IONLG_URL . 'assets/css/ionpeptide-login-gate.css', array(), IONLG_VER );

        $script_deps = array();
        if ( $this->google_login_available() ) {
            wp_enqueue_script( 'google-identity-services', 'https://accounts.google.com/gsi/client', array(), null, true );
            $script_deps[] = 'google-identity-services';
        }

        wp_enqueue_script( 'ionpeptide-login-gate', IONLG_URL . 'assets/js/ionpeptide-login-gate.js', $script_deps, IONLG_VER, true );

        wp_localize_script( 'ionpeptide-login-gate', 'IonPeptideLoginGate', array(
            'recaptchaEnabled' => $this->recaptcha_enabled() ? 'yes' : 'no',
            'recaptchaSiteKey' => $this->recaptcha_enabled() ? $this->opt( 'recaptcha_site_key' ) : '',
            'recaptchaAction'  => 'ion_login_gate',
            'googleEnabled'    => $this->google_login_available() ? 'yes' : 'no',
            'googleClientId'   => $this->google_login_available() ? $this->google_client_id() : '',
            'googleAjaxUrl'    => admin_url( 'admin-ajax.php' ),
            'googleNonce'      => wp_create_nonce( 'ionlg_google_login' ),
        ) );

        if ( $this->recaptcha_enabled() ) {
            wp_enqueue_script( 'google-recaptcha-v3', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $this->opt( 'recaptcha_site_key' ) ), array(), null, true );
        }
    }

    private function render_notice( $code ) {
        $messages = array(
            'invalid_login'     => array( 'error', 'Invalid username/email or password. Please try again.' ),
            'security'          => array( 'error', 'Security check failed. Please refresh and try again.' ),
            'not_verified'      => array( 'error', 'Please verify your email before logging in. You can resend the verification email below.' ),
            'bot_blocked'       => array( 'error', 'Security verification failed. Please refresh and try again.' ),
            'register_required' => array( 'error', 'Please complete all required registration fields.' ),
            'password_mismatch' => array( 'error', 'Passwords do not match.' ),
            'password_short'    => array( 'error', 'Password must be at least 8 characters.' ),
            'underage'          => array( 'error', 'You must be at least 21 years old to register.' ),
            'email_exists'      => array( 'error', 'An account already exists with that email address. Please sign in.' ),
            'temp_email'        => array( 'error', 'Please use a permanent email address.' ),
            'register_failed'   => array( 'error', 'Registration failed. Please try again.' ),
            'email_domain_blocked' => array( 'error', IonLG_Registration_Blocks::ERROR ),
            'registered_verify' => array( 'success', 'Account created. Please check your email to verify your account before signing in.' ),
            'registered_login'  => array( 'success', 'Account created. You can now sign in.' ),
            'reset_sent'        => array( 'success', 'If an account matches that email/username, a reset link has been sent.' ),
            'lost_required'     => array( 'error', 'Please enter your email or username.' ),
            'resent'            => array( 'success', 'Verification email sent. Please check your inbox.' ),
            'resend_wait'       => array( 'error', 'Please wait before requesting another verification email.' ),
            'verified'          => array( 'success', 'Your email has been verified. You can now sign in.' ),
            'verify_invalid'    => array( 'error', 'Invalid verification link. Please request a new verification email.' ),
            'verify_expired'    => array( 'error', 'Verification link expired. Please request a new verification email.' ),
            'twofactor_required' => array( 'success', 'Admin password verified. Enter your two-factor authentication code to continue.' ),
            'twofactor_invalid'  => array( 'error', 'Invalid or expired two-factor authentication code. Please try again.' ),
            'twofactor_expired'  => array( 'error', 'Two-factor authentication session expired. Please sign in again.' ),
            'twofactor_not_setup'=> array( 'error', 'Administrator two-factor authentication is required but is not active on this account. Set up Wordfence 2FA before logging in through the gate.' ),
            'twofactor_unavailable' => array( 'error', 'Two-factor authentication could not start. Please refresh and try again.' ),
            'google_failed'      => array( 'error', 'Google sign-in could not be completed. Please try again or use email sign in.' ),
        );
        if ( $code && isset( $messages[ $code ] ) ) {
            echo '<div class="ion-notice ion-notice-' . esc_attr( $messages[ $code ][0] ) . '">' . esc_html( $messages[ $code ][1] ) . '</div>';
            if ( in_array( $code, array( 'not_verified', 'registered_verify', 'verify_invalid', 'verify_expired' ), true ) ) {
                $this->render_resend_form();
            }
        }
    }

    private function render_honeypot() {
        echo '<div class="ion-hp-field" aria-hidden="true"><label>Leave this field empty <input type="text" name="ion_company_website" tabindex="-1" autocomplete="off" value=""></label></div>';
    }

    private function render_recaptcha_field() {
        if ( $this->recaptcha_enabled() ) {
            echo '<input type="hidden" name="ion_recaptcha_token" class="ion-recaptcha-token" value="">';
        }
    }


    private function is_ui_gate_form_context() {
        return ( ! $this->request_path_matches_gate() && ! is_admin() );
    }

    private function form_action_url() {
        return $this->is_ui_gate_form_context() ? $this->current_full_url() : $this->gate_url();
    }

    private function form_context_hidden_fields() {
        if ( $this->is_ui_gate_form_context() ) {
            echo '<input type="hidden" name="ionlg_ui_gate" value="1">';
        }
    }

    private function gate_link_url( $args = array(), $redirect_to = '' ) {
        if ( $this->is_ui_gate_form_context() ) {
            $url = $this->current_full_url();
            if ( ! empty( $args ) ) {
                $url = add_query_arg( $args, $url );
            }
            return $url;
        }
        $url = $this->gate_url( $redirect_to );
        if ( ! empty( $args ) ) {
            $url = add_query_arg( $args, $url );
        }
        return $url;
    }

    private function render_wordfence_2fa_form( $redirect_to, $visible = true ) {
        $challenge = isset( $_GET['ionlg_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['ionlg_challenge'] ) ) : '';
        ?>
        <div class="ion-auth-panel ion-auth-panel-login ion-auth-panel-2fa" data-panel="login" style="<?php echo $visible ? '' : 'display:none;'; ?>">
            <h3>Two-Factor Authentication</h3>
            <p>Enter the 6-digit authenticator code for this administrator account.</p>
            <form class="ion-gate-form" method="post" action="<?php echo esc_url( $this->form_action_url() ); ?>" autocomplete="one-time-code">
                <input type="hidden" name="ionlg_action" value="login_2fa">
                <?php $this->form_context_hidden_fields(); ?>
                <?php wp_nonce_field( 'ionlg_login_2fa', 'ionlg_2fa_nonce' ); ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
                <input type="hidden" name="ionlg_challenge" value="<?php echo esc_attr( $challenge ); ?>">
                <p class="form-row form-row-wide"><label for="ionlg_2fa_code">Authentication code&nbsp;<span class="required">*</span></label><input type="text" class="input-text" name="ionlg_2fa_code" id="ionlg_2fa_code" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" required autofocus></p>
                <p class="form-row ion-login-actions-row"><button type="submit" class="button ion-submit-btn" value="1">Verify code</button></p>
                <p class="lost_password"><a href="<?php echo esc_url( $this->gate_link_url( array( 'mode' => 'login' ), $redirect_to ) ); ?>">Back to login</a></p>
            </form>
        </div>
        <?php
    }

    private function render_google_button( $redirect_to, $context = 'login' ) {
        if ( ! $this->google_login_available() ) {
            return;
        }

        $divider = 'register' === $context ? 'or create an account with email' : 'or sign in with email';
        $note = 'register' === $context
            ? 'Google verifies your email first. New customers will complete the required research profile before access.'
            : 'By continuing with Google, you confirm the research-use access requirements for this portal.';
        ?>
        <div class="ion-google-block" data-ion-google-context="<?php echo esc_attr( $context ); ?>">
            <button type="button" class="ion-google-btn" data-ion-google-login data-context="<?php echo esc_attr( $context ); ?>" data-redirect-to="<?php echo esc_attr( $redirect_to ); ?>" data-ui-gate="<?php echo $this->is_ui_gate_form_context() ? '1' : '0'; ?>">
                <span class="ion-google-mark" aria-hidden="true">
                    <svg viewBox="0 0 18 18" focusable="false" aria-hidden="true"><path fill="#EA4335" d="M17.64 9.205c0-.638-.057-1.252-.164-1.841H9v3.482h4.844a4.14 4.14 0 0 1-1.797 2.715v2.258h2.909c1.703-1.568 2.684-3.878 2.684-6.614Z"/><path fill="#4285F4" d="M9 18c2.43 0 4.467-.806 5.956-2.181l-2.909-2.258c-.806.54-1.835.859-3.047.859-2.344 0-4.328-1.584-5.037-3.711H.956v2.332A9 9 0 0 0 9 18Z"/><path fill="#FBBC05" d="M3.963 10.709A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.281-1.709V4.959H.956A9 9 0 0 0 0 9c0 1.45.347 2.824.956 4.041l3.007-2.332Z"/><path fill="#34A853" d="M9 3.58c1.321 0 2.507.454 3.441 1.346l2.581-2.581C13.463.892 11.426 0 9 0A9 9 0 0 0 .956 4.959l3.007 2.332C4.672 5.164 6.656 3.58 9 3.58Z"/></svg>
                </span>
                <span class="ion-google-btn-label">Continue with Google</span>
                <span class="ion-google-spinner" aria-hidden="true"></span>
            </button>
            <p class="ion-google-note"><?php echo esc_html( $note ); ?></p>
            <div class="ion-auth-divider" role="separator"><span><?php echo esc_html( $divider ); ?></span></div>
            <div class="ion-google-error" data-ion-google-error role="alert" aria-live="polite"></div>
        </div>
        <?php
    }

    private function render_google_complete_form( $redirect_to ) {
        if ( ! $this->google_login_available() ) {
            return;
        }
        $max_dob = gmdate( 'Y-m-d', strtotime( '-21 years' ) );
        ?>
        <div class="ion-auth-panel ion-auth-panel-google-complete" data-panel="google-complete" style="display:none;">
            <div class="ion-google-complete-heading">
                <span class="ion-google-verified-badge">Google email verified</span>
                <h3>Complete your research profile</h3>
                <p>Finish the required Ion Peptide details below. You will not need to create a password.</p>
            </div>
            <form class="ion-gate-form ion-google-complete-form" data-ion-google-complete-form autocomplete="on">
                <input type="hidden" name="action" value="ionlg_google_complete">
                <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'ionlg_google_login' ) ); ?>">
                <input type="hidden" name="google_onboarding_token" value="" data-ion-google-token>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
                <input type="hidden" name="ionlg_ui_gate" value="<?php echo $this->is_ui_gate_form_context() ? '1' : '0'; ?>">
                <?php $this->render_honeypot(); ?>
                <p class="form-row form-row-wide ion-reg-wide"><label for="ion_google_email">Google email</label><input type="email" class="input-text ion-google-email-field" id="ion_google_email" value="" data-ion-google-email readonly aria-readonly="true"></p>
                <p class="form-row form-row-wide ion-reg-half"><label for="ion_google_name">Full Name&nbsp;<span class="required">*</span></label><input type="text" class="input-text" name="full_name" id="ion_google_name" data-ion-google-name autocomplete="name" required></p>
                <p class="form-row form-row-wide ion-reg-half"><label for="ion_google_dob">Date of Birth&nbsp;<span class="required">*</span></label><input type="date" class="input-text" name="dob" id="ion_google_dob" max="<?php echo esc_attr( $max_dob ); ?>" required></p>
                <p class="form-row form-row-wide ion-reg-half"><label for="ion_google_company">Company / Institution</label><input type="text" class="input-text" name="company" id="ion_google_company" autocomplete="organization"></p>
                <p class="form-row form-row-wide ion-reg-half"><label for="ion_google_business_type">Business Type / Industry&nbsp;<span class="required">*</span></label><select name="business_type" id="ion_google_business_type" class="input-select" required><option value="">Select your business type</option><option value="Researcher">Researcher</option><option value="Clinic">Clinic</option><option value="University">University</option><option value="Distributor">Distributor</option><option value="Other">Other</option></select></p>
                <p class="form-row form-row-wide ion-reg-wide"><label for="ion_google_phone">Phone Number&nbsp;<span class="required">*</span></label><input type="tel" class="input-text" name="phone_number" id="ion_google_phone" autocomplete="tel" required></p>
                <div class="ion-terms-box ion-reg-wide"><p><strong>Ion Peptides Terms of Use</strong></p><p>All products sold by <strong>www.IonPeptide.com</strong> are intended strictly for in-vitro laboratory research use only. They are not for human or animal use, consumption, or application, and are not approved by the FDA for any therapeutic, diagnostic, or medical purposes.</p><p>By registering, you confirm that you are at least 21 years of age, a qualified professional, and that any products purchased will be used exclusively for lawful research purposes.</p><p><a href="https://ionpeptide.com/terms-of-service/" target="_blank" rel="noopener">View Terms &amp; Conditions</a></p></div>
                <p class="form-row form-row-wide ion-reg-wide ion-reg-check"><label><input type="checkbox" name="acknowledge_terms" value="1" required> I acknowledge and confirm that I am accessing these materials solely for in-vitro research use, that I have read and agree to the Terms of Service, and that I am authorized to handle and use them.</label></p>
                <p class="form-row form-row-wide ion-reg-wide ion-reg-check"><label><input type="checkbox" name="subscribe_newsletter" value="1" checked> Send me newsletters, promotions, and updates.</label></p>
                <?php $this->render_recaptcha_field(); ?>
                <div class="ion-google-complete-error" data-ion-google-complete-error role="alert" aria-live="polite"></div>
                <p class="form-row ion-reg-wide ion-submit-row"><button type="submit" class="button ion-submit-btn" value="1"><span>Create account &amp; continue</span><svg class="ion-submit-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button></p>
                <p class="ion-google-back-row"><button type="button" class="ion-google-back" data-ion-google-back>Use email registration instead</button></p>
            </form>
        </div>
        <?php
    }

    private function render_login_form( $redirect_to, $visible = true ) {
        if ( ! empty( $_GET['ionlg_2fa'] ) && ! empty( $_GET['ionlg_challenge'] ) ) {
            $this->render_wordfence_2fa_form( $redirect_to, $visible );
            return;
        }
        ?>
        <div class="ion-auth-panel ion-auth-panel-login" data-panel="login" style="<?php echo $visible ? '' : 'display:none;'; ?>">
            <h3 class="ion-sr-only">Sign in</h3>
            <?php $this->render_google_button( $redirect_to, 'login' ); ?>
            <form class="ion-gate-form" method="post" action="<?php echo esc_url( $this->form_action_url() ); ?>" autocomplete="on">
                <input type="hidden" name="ionlg_action" value="login">
                <?php $this->form_context_hidden_fields(); ?>
                <?php wp_nonce_field( 'ionlg_login', 'ionlg_nonce' ); ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
                <?php $this->render_honeypot(); ?>
                <p class="form-row form-row-wide"><label for="ion_user_login">Username or email address&nbsp;<span class="required">*</span></label><input type="text" class="input-text" name="user_login" id="ion_user_login" autocomplete="username" placeholder="Enter your username or email" required></p>
                <p class="form-row form-row-wide"><label for="ion_user_pass">Password&nbsp;<span class="required">*</span></label><span class="ion-password-field"><input type="password" class="input-text" name="user_pass" id="ion_user_pass" autocomplete="current-password" placeholder="Enter your password" required><button type="button" class="ion-password-toggle" data-ion-password-toggle aria-label="Show password" aria-pressed="false"><svg class="ion-password-eye ion-password-eye-show" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5c5.5 0 9.5 5.2 9.7 5.4a1 1 0 0 1 0 1.2C21.5 11.8 17.5 17 12 17S2.5 11.8 2.3 11.6a1 1 0 0 1 0-1.2C2.5 10.2 6.5 5 12 5Zm0 2c-3.6 0-6.6 3-7.6 4 1 1 4 4 7.6 4s6.6-3 7.6-4c-1-1-4-4-7.6-4Zm0 1.5A2.5 2.5 0 1 1 12 13a2.5 2.5 0 0 1 0-5Z"/></svg><svg class="ion-password-eye ion-password-eye-hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.3 2 22 20.7 20.7 22l-3.1-3.1A10.9 10.9 0 0 1 12 20C6.5 20 2.5 14.8 2.3 14.6a1 1 0 0 1 0-1.2 19.8 19.8 0 0 1 3.1-3.3L2 6.7 3.3 5.4 19 21.1 17.7 22.4 3.3 8Zm3.5 9.5A16 16 0 0 0 4.4 14c1 1 4 4 7.6 4a8.8 8.8 0 0 0 4.1-1l-1.7-1.7A4.5 4.5 0 0 1 8.7 9.6l-1.9-1.9ZM12 8c5.5 0 9.5 5.2 9.7 5.4a1 1 0 0 1 0 1.2 18.2 18.2 0 0 1-2.1 2.4l-1.4-1.4A15.6 15.6 0 0 0 19.6 14c-1-1-4-4-7.6-4-.5 0-1 0-1.5.1L8.8 8.4A11.2 11.2 0 0 1 12 8Z"/></svg></button></span></p>
                <?php $this->render_recaptcha_field(); ?>
                <div class="form-row ion-login-actions-row"><label class="ion-remember"><input name="rememberme" type="checkbox" value="1"> <span>Remember me</span></label><a class="ion-forgot-link" href="<?php echo esc_url( $this->gate_link_url( array( 'mode' => 'forgot' ), $redirect_to ) ); ?>">Forgot your password?</a></div>
                <p class="form-row ion-submit-row"><button type="submit" class="button ion-submit-btn" value="1"><span>Log in</span><svg class="ion-submit-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button></p>
            </form>
        </div>
        <?php
    }

    private function render_register_form( $redirect_to, $visible = false ) {
        ?>
        <div class="ion-auth-panel ion-auth-panel-register" data-panel="register" style="<?php echo $visible ? '' : 'display:none;'; ?>">
            <?php $this->render_google_button( $redirect_to, 'register' ); ?>
            <form method="post" class="ion-gate-form" action="<?php echo esc_url( $this->form_action_url() ); ?>" autocomplete="on">
                <input type="hidden" name="ionlg_action" value="register">
                <?php $this->form_context_hidden_fields(); ?>
                <?php wp_nonce_field( 'ionlg_register', 'ionlg_nonce' ); ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
                <?php $this->render_honeypot(); ?>
                <p class="form-row form-row-wide ion-reg-wide ion-reg-email"><label for="reg_email">Email address&nbsp;<span class="required">*</span></label><input type="email" class="input-text" name="email" id="reg_email" autocomplete="email" required></p>
                <p class="form-row form-row-wide ion-reg-half ion-reg-password"><label for="reg_password">Password&nbsp;<span class="required">*</span></label><span class="ion-password-field"><input type="password" class="input-text" name="password" id="reg_password" autocomplete="new-password" required><button type="button" class="ion-password-toggle" data-ion-password-toggle aria-label="Show password" aria-pressed="false"><svg class="ion-password-eye ion-password-eye-show" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5c5.5 0 9.5 5.2 9.7 5.4a1 1 0 0 1 0 1.2C21.5 11.8 17.5 17 12 17S2.5 11.8 2.3 11.6a1 1 0 0 1 0-1.2C2.5 10.2 6.5 5 12 5Zm0 2c-3.6 0-6.6 3-7.6 4 1 1 4 4 7.6 4s6.6-3 7.6-4c-1-1-4-4-7.6-4Zm0 1.5A2.5 2.5 0 1 1 12 13a2.5 2.5 0 0 1 0-5Z"/></svg><svg class="ion-password-eye ion-password-eye-hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.3 2 22 20.7 20.7 22l-3.1-3.1A10.9 10.9 0 0 1 12 20C6.5 20 2.5 14.8 2.3 14.6a1 1 0 0 1 0-1.2 19.8 19.8 0 0 1 3.1-3.3L2 6.7 3.3 5.4 19 21.1 17.7 22.4 3.3 8Zm3.5 9.5A16 16 0 0 0 4.4 14c1 1 4 4 7.6 4a8.8 8.8 0 0 0 4.1-1l-1.7-1.7A4.5 4.5 0 0 1 8.7 9.6l-1.9-1.9ZM12 8c5.5 0 9.5 5.2 9.7 5.4a1 1 0 0 1 0 1.2 18.2 18.2 0 0 1-2.1 2.4l-1.4-1.4A15.6 15.6 0 0 0 19.6 14c-1-1-4-4-7.6-4-.5 0-1 0-1.5.1L8.8 8.4A11.2 11.2 0 0 1 12 8Z"/></svg></button></span></p>
                <p class="form-row form-row-wide ion-reg-half ion-reg-confirm"><label for="reg_confirm_password">Confirm Password&nbsp;<span class="required">*</span></label><span class="ion-password-field"><input type="password" class="input-text" name="confirm_password" id="reg_confirm_password" autocomplete="new-password" required><button type="button" class="ion-password-toggle" data-ion-password-toggle aria-label="Show password" aria-pressed="false"><svg class="ion-password-eye ion-password-eye-show" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5c5.5 0 9.5 5.2 9.7 5.4a1 1 0 0 1 0 1.2C21.5 11.8 17.5 17 12 17S2.5 11.8 2.3 11.6a1 1 0 0 1 0-1.2C2.5 10.2 6.5 5 12 5Zm0 2c-3.6 0-6.6 3-7.6 4 1 1 4 4 7.6 4s6.6-3 7.6-4c-1-1-4-4-7.6-4Zm0 1.5A2.5 2.5 0 1 1 12 13a2.5 2.5 0 0 1 0-5Z"/></svg><svg class="ion-password-eye ion-password-eye-hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.3 2 22 20.7 20.7 22l-3.1-3.1A10.9 10.9 0 0 1 12 20C6.5 20 2.5 14.8 2.3 14.6a1 1 0 0 1 0-1.2 19.8 19.8 0 0 1 3.1-3.3L2 6.7 3.3 5.4 19 21.1 17.7 22.4 3.3 8Zm3.5 9.5A16 16 0 0 0 4.4 14c1 1 4 4 7.6 4a8.8 8.8 0 0 0 4.1-1l-1.7-1.7A4.5 4.5 0 0 1 8.7 9.6l-1.9-1.9ZM12 8c5.5 0 9.5 5.2 9.7 5.4a1 1 0 0 1 0 1.2 18.2 18.2 0 0 1-2.1 2.4l-1.4-1.4A15.6 15.6 0 0 0 19.6 14c-1-1-4-4-7.6-4-.5 0-1 0-1.5.1L8.8 8.4A11.2 11.2 0 0 1 12 8Z"/></svg></button></span></p>
                <p class="form-row form-row-wide ion-reg-half ion-reg-name"><label for="reg_name">Full Name&nbsp;<span class="required">*</span></label><input type="text" class="input-text" name="full_name" id="reg_name" required></p>
                <p class="form-row form-row-wide ion-reg-half ion-reg-dob"><label for="reg_dob">Date of Birth&nbsp;<span class="required">*</span></label><input type="date" class="input-text" name="dob" id="reg_dob" max="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '-21 years' ) ) ); ?>" required></p>
                <p class="form-row form-row-wide ion-reg-half ion-reg-company"><label for="reg_company">Company / Institution</label><input type="text" class="input-text" name="company" id="reg_company"></p>
                <p class="form-row form-row-wide ion-reg-half ion-reg-business"><label for="reg_business_type">Business Type / Industry&nbsp;<span class="required">*</span></label><select name="business_type" id="reg_business_type" class="input-select" required><option value="">Select your business type</option><option value="Researcher">Researcher</option><option value="Clinic">Clinic</option><option value="University">University</option><option value="Distributor">Distributor</option><option value="Other">Other</option></select></p>
                <p class="form-row form-row-wide ion-reg-wide ion-reg-phone"><label for="reg_phone">Phone Number&nbsp;<span class="required">*</span></label><input type="tel" class="input-text" name="phone_number" id="reg_phone" required></p>
                <div class="ion-terms-box ion-reg-wide"><p><strong>Ion Peptides Terms of Use</strong></p><p>All products sold by <strong>www.IonPeptide.com</strong> are intended strictly for in-vitro laboratory research use only. They are not for human or animal use, consumption, or application, and are not approved by the FDA for any therapeutic, diagnostic, or medical purposes.</p><p>By registering, you confirm that you are at least 21 years of age, a qualified professional, and that any products purchased will be used exclusively for lawful research purposes.</p><p><a href="https://ionpeptide.com/terms-of-service/" target="_blank" rel="noopener">View Terms &amp; Conditions</a></p></div>
                <p class="form-row form-row-wide ion-reg-wide ion-reg-check"><label><input type="checkbox" name="acknowledge_terms" value="1" required> I acknowledge and confirm that I am accessing these materials solely for in-vitro research use, that I have read and agree to the Terms of Service, and that I am authorized to handle and use them.</label></p>
                <p class="form-row form-row-wide ion-reg-wide ion-reg-check"><label><input type="checkbox" name="subscribe_newsletter" value="1" checked> Send me newsletters, promotions, and updates.</label></p>
                <?php $this->render_recaptcha_field(); ?>
                <p class="form-row ion-reg-wide ion-submit-row"><button type="submit" class="button ion-submit-btn" value="1"><span>Register</span><svg class="ion-submit-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button></p>
            </form>
        </div>
        <?php
    }

    private function render_lost_password_form( $redirect_to ) {
        ?>
        <div class="ion-auth-panel ion-auth-panel-login" data-panel="forgot">
            <h3>Reset Password</h3>
            <p>Enter your username or email address and we’ll send a password reset link.</p>
            <form class="ion-gate-form" method="post" action="<?php echo esc_url( $this->form_action_url() ); ?>" autocomplete="on">
                <input type="hidden" name="ionlg_action" value="lostpass">
                <?php $this->form_context_hidden_fields(); ?>
                <?php wp_nonce_field( 'ionlg_lostpass', 'ionlg_nonce' ); ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
                <?php $this->render_honeypot(); ?>
                <p class="form-row form-row-wide"><label for="lost_user_login">Username or email address&nbsp;<span class="required">*</span></label><input type="text" class="input-text" name="lost_user_login" id="lost_user_login" autocomplete="username" required></p>
                <?php $this->render_recaptcha_field(); ?>
                <p class="form-row"><button type="submit" class="button ion-submit-btn" value="1">Send reset link</button></p>
                <p class="lost_password"><a href="<?php echo esc_url( $this->gate_link_url( array(), $redirect_to ) ); ?>">Back to sign in</a></p>
            </form>
        </div>
        <?php
    }

    private function render_resend_form() {
        ?>
        <div class="ion-resend-verification-box">
            <form method="post" class="ion-resend-verification-form ion-gate-form" action="<?php echo esc_url( $this->form_action_url() ); ?>">
                <input type="hidden" name="ionlg_action" value="resend">
                <?php $this->form_context_hidden_fields(); ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $this->current_full_url() ); ?>">
                <?php wp_nonce_field( 'ionlg_resend', 'ionlg_nonce' ); ?>
                <?php $this->render_honeypot(); ?>
                <input type="email" name="ion_email" placeholder="Enter your email" required>
                <button type="submit" class="button ion-submit-btn" value="1">Resend Verification Email</button>
            </form>
        </div>
        <?php
    }

    private function is_at_least_21( $dob ) {
        $dob = trim( (string) $dob );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
            return false;
        }

        try {
            $birth = DateTimeImmutable::createFromFormat( '!Y-m-d', $dob, new DateTimeZone( 'UTC' ) );
            if ( ! $birth || $birth->format( 'Y-m-d' ) !== $dob ) {
                return false;
            }
            $today = new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) );
            return $birth <= $today->modify( '-21 years' );
        } catch ( Exception $e ) {
            return false;
        }
    }

    private function google_user_is_privileged( $user ) {
        if ( ! $user || empty( $user->ID ) ) {
            return true;
        }
        if ( is_multisite() && is_super_admin( $user->ID ) ) {
            return true;
        }
        foreach ( array( 'manage_options', 'manage_woocommerce', 'edit_users' ) as $capability ) {
            if ( user_can( $user, $capability ) ) {
                return true;
            }
        }
        $blocked_roles = array( 'administrator', 'editor', 'shop_manager' );
        return (bool) array_intersect( $blocked_roles, (array) $user->roles );
    }

    private function google_onboarding_transient_key( $token ) {
        return 'ionlg_google_' . hash( 'sha256', (string) $token );
    }

    private function verify_google_access_token( $access_token ) {
        $access_token = trim( (string) $access_token );
        if ( '' === $access_token || ! $this->google_login_available() ) {
            return new WP_Error( 'google_missing', 'Google sign-in is not available.' );
        }

        $token_response = wp_remote_get(
            add_query_arg( 'access_token', $access_token, 'https://oauth2.googleapis.com/tokeninfo' ),
            array( 'timeout' => 12 )
        );
        if ( is_wp_error( $token_response ) || 200 !== (int) wp_remote_retrieve_response_code( $token_response ) ) {
            return new WP_Error( 'google_token', 'Google could not verify this sign-in. Please try again.' );
        }

        $token_info = json_decode( wp_remote_retrieve_body( $token_response ), true );
        $audience = '';
        if ( is_array( $token_info ) ) {
            foreach ( array( 'audience', 'aud', 'issued_to', 'azp' ) as $audience_key ) {
                if ( ! empty( $token_info[ $audience_key ] ) && is_scalar( $token_info[ $audience_key ] ) ) {
                    $audience = (string) $token_info[ $audience_key ];
                    break;
                }
            }
        }
        if ( ! hash_equals( $this->google_client_id(), $audience ) ) {
            return new WP_Error( 'google_audience', 'Google sign-in was issued for a different application.' );
        }

        if ( isset( $token_info['expires_in'] ) && (int) $token_info['expires_in'] <= 0 ) {
            return new WP_Error( 'google_expired', 'Google sign-in expired. Please try again.' );
        }

        $userinfo_response = wp_remote_get( 'https://www.googleapis.com/oauth2/v3/userinfo', array(
            'timeout' => 12,
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
        ) );
        if ( is_wp_error( $userinfo_response ) || 200 !== (int) wp_remote_retrieve_response_code( $userinfo_response ) ) {
            return new WP_Error( 'google_profile', 'Google account details could not be verified.' );
        }

        $userinfo = json_decode( wp_remote_retrieve_body( $userinfo_response ), true );
        $email = is_array( $userinfo ) && ! empty( $userinfo['email'] ) ? sanitize_email( $userinfo['email'] ) : '';
        $verified = is_array( $userinfo ) && isset( $userinfo['email_verified'] ) && in_array( $userinfo['email_verified'], array( true, 1, '1', 'true' ), true );
        if ( ! is_email( $email ) || ! $verified ) {
            return new WP_Error( 'google_email', 'Google did not return a verified email address.' );
        }

        return array(
            'email' => $email,
            'sub'   => isset( $userinfo['sub'] ) ? sanitize_text_field( (string) $userinfo['sub'] ) : '',
            'name'  => isset( $userinfo['name'] ) ? sanitize_text_field( (string) $userinfo['name'] ) : '',
        );
    }

    private function establish_google_session_and_respond( $user, $redirect_to ) {
        update_user_meta( $user->ID, 'ionpeptide_research_accepted_at', time() );
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true, is_ssl() );
        if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
            wc_set_customer_auth_cookie( $user->ID );
        }
        do_action( 'wp_login', $user->user_login, $user );

        $dest = $this->resolve_post_auth_redirect( $redirect_to );
        $this->clear_intent_cookie();
        wp_send_json_success( array(
            'status'      => 'logged_in',
            'redirect_to' => $dest,
        ) );
    }

    public function ajax_google_login() {
        $this->no_cache_constants();
        if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid Google sign-in request.' ), 405 );
        }
        if ( ! check_ajax_referer( 'ionlg_google_login', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Google sign-in security check failed. Refresh and try again.' ), 403 );
        }
        if ( ! $this->google_login_available() ) {
            wp_send_json_error( array( 'message' => 'Google sign-in is not configured.' ), 503 );
        }

        $access_token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';
        $redirect_to  = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';
        $profile = $this->verify_google_access_token( $access_token );
        if ( is_wp_error( $profile ) ) {
            wp_send_json_error( array( 'message' => $profile->get_error_message() ), 401 );
        }

        if ( 'yes' === $this->opt( 'block_disposable_email' ) && $this->is_disposable_email( $profile['email'] ) ) {
            wp_send_json_error( array( 'message' => 'Please use a permanent Google email address.' ), 400 );
        }

        $user = get_user_by( 'email', $profile['email'] );
        if ( ! $user && IonLG_Registration_Blocks::matched_rule( $profile['email'] ) ) {
            wp_send_json_error( array( 'message' => IonLG_Registration_Blocks::ERROR ), 403 );
        }
        if ( $user && ! empty( $user->ID ) ) {
            if ( $this->google_user_is_privileged( $user ) ) {
                wp_send_json_error( array( 'message' => 'Administrator and privileged staff accounts must use password sign-in so two-factor authentication remains enforced.' ), 403 );
            }

            $linked_sub = (string) get_user_meta( $user->ID, 'ionpeptide_google_sub', true );
            if ( '' !== $linked_sub && '' !== $profile['sub'] && ! hash_equals( $linked_sub, $profile['sub'] ) ) {
                wp_send_json_error( array( 'message' => 'This email is already linked to a different Google identity. Please use email sign-in.' ), 403 );
            }

            if ( '' !== $profile['sub'] ) {
                update_user_meta( $user->ID, 'ionpeptide_google_sub', $profile['sub'] );
            }
            update_user_meta( $user->ID, 'ionpeptide_google_email_verified', 1 );
            update_user_meta( $user->ID, self::VERIFIED_META, 1 );
            $this->establish_google_session_and_respond( $user, $redirect_to );
        }

        $onboarding_token = wp_generate_password( 48, false, false );
        set_transient( $this->google_onboarding_transient_key( $onboarding_token ), array(
            'email'       => $profile['email'],
            'sub'         => $profile['sub'],
            'name'        => $profile['name'],
            'redirect_to' => $this->safe_redirect_target( $redirect_to ),
            'created_at'  => time(),
        ), 20 * MINUTE_IN_SECONDS );

        wp_send_json_success( array(
            'status'           => 'complete_profile',
            'onboarding_token' => $onboarding_token,
            'email'            => $profile['email'],
            'name'             => $profile['name'],
        ) );
    }

    public function ajax_google_complete() {
        $this->no_cache_constants();
        if ( ! check_ajax_referer( 'ionlg_google_login', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Account setup security check failed. Refresh and try again.' ), 403 );
        }
        if ( ! $this->google_login_available() ) {
            wp_send_json_error( array( 'message' => 'Google sign-in is not configured.' ), 503 );
        }
        if ( $this->honeypot_filled() ) {
            wp_send_json_error( array( 'message' => 'Security verification failed. Please refresh and try again.' ), 400 );
        }
        $captcha = $this->verify_recaptcha();
        if ( is_wp_error( $captcha ) ) {
            wp_send_json_error( array( 'message' => 'Security verification failed. Please refresh and try again.' ), 400 );
        }

        $token    = isset( $_POST['google_onboarding_token'] ) ? sanitize_text_field( wp_unslash( $_POST['google_onboarding_token'] ) ) : '';
        $full     = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
        $dob      = isset( $_POST['dob'] ) ? sanitize_text_field( wp_unslash( $_POST['dob'] ) ) : '';
        $business = isset( $_POST['business_type'] ) ? sanitize_text_field( wp_unslash( $_POST['business_type'] ) ) : '';
        $phone    = isset( $_POST['phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number'] ) ) : '';
        $company  = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
        $subscribed = ! empty( $_POST['subscribe_newsletter'] );

        $payload = '' !== $token ? get_transient( $this->google_onboarding_transient_key( $token ) ) : false;
        if ( ! is_array( $payload ) || empty( $payload['email'] ) ) {
            wp_send_json_error( array( 'message' => 'Your Google account setup session expired. Please choose Continue with Google again.' ), 410 );
        }
        if ( '' === $full || '' === $dob || '' === $business || '' === $phone || empty( $_POST['acknowledge_terms'] ) ) {
            wp_send_json_error( array( 'message' => 'Please complete all required registration fields.' ), 400 );
        }
        if ( ! $this->is_at_least_21( $dob ) ) {
            wp_send_json_error( array( 'message' => 'You must be at least 21 years old to register.' ), 400 );
        }

        $email = sanitize_email( $payload['email'] );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Google email verification is no longer valid. Please try again.' ), 400 );
        }

        $existing = get_user_by( 'email', $email );
        if ( $existing && ! empty( $existing->ID ) ) {
            if ( $this->google_user_is_privileged( $existing ) ) {
                wp_send_json_error( array( 'message' => 'This account must use password sign-in.' ), 403 );
            }
            $linked_sub = (string) get_user_meta( $existing->ID, 'ionpeptide_google_sub', true );
            if ( '' !== $linked_sub && ! empty( $payload['sub'] ) && ! hash_equals( $linked_sub, (string) $payload['sub'] ) ) {
                wp_send_json_error( array( 'message' => 'This email is already linked to a different Google identity.' ), 403 );
            }
            update_user_meta( $existing->ID, self::VERIFIED_META, 1 );
            update_user_meta( $existing->ID, 'ionpeptide_google_email_verified', 1 );
            if ( ! empty( $payload['sub'] ) ) {
                update_user_meta( $existing->ID, 'ionpeptide_google_sub', sanitize_text_field( (string) $payload['sub'] ) );
            }
            delete_transient( $this->google_onboarding_transient_key( $token ) );
            $this->establish_google_session_and_respond( $existing, ! empty( $payload['redirect_to'] ) ? $payload['redirect_to'] : '' );
        }

        if ( IonLG_Registration_Blocks::matched_rule( $email ) ) {
            wp_send_json_error( array( 'message' => IonLG_Registration_Blocks::ERROR ), 403 );
        }

        $base = sanitize_user( current( explode( '@', $email ) ), true );
        $username = $base ?: 'ionuser';
        while ( username_exists( $username ) ) {
            $username = ( $base ?: 'ionuser' ) . '_' . wp_generate_password( 6, false, false );
        }

        $password = wp_generate_password( 32, true, true );
        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( array( 'message' => 'Account creation failed. Please try again.' ), 500 );
        }

        $user = new WP_User( $user_id );
        if ( get_role( 'customer' ) ) {
            $user->set_role( 'customer' );
        }
        $this->save_profile_fields( $user_id, $full, $dob, $company, $business, $phone );
        update_user_meta( $user_id, self::VERIFIED_META, 1 );
        update_user_meta( $user_id, 'ionpeptide_google_email_verified', 1 );
        update_user_meta( $user_id, 'ionpeptide_google_registered_at', current_time( 'mysql' ) );
        update_user_meta( $user_id, 'ionpeptide_research_accepted_at', time() );
        if ( ! empty( $payload['sub'] ) ) {
            update_user_meta( $user_id, 'ionpeptide_google_sub', sanitize_text_field( (string) $payload['sub'] ) );
        }
        update_user_meta( $user_id, 'subscribe_newsletter', $subscribed ? 'yes' : 'no' );
        $this->sync_user_to_omnisend( $user_id, $full, $dob, $company, $business, $phone, $subscribed, 'google_registration' );

        delete_transient( $this->google_onboarding_transient_key( $token ) );
        $user = get_userdata( $user_id );
        $this->establish_google_session_and_respond( $user, ! empty( $payload['redirect_to'] ) ? $payload['redirect_to'] : '' );
    }

    private function user_must_verify( $user ) {
        if ( ! $this->email_verification_enabled() ) return false;
        if ( in_array( 'administrator', (array) $user->roles, true ) ) return false;
        $meta = get_user_meta( $user->ID, self::VERIFIED_META, true );
        if ( '1' === (string) $meta || 'yes' === $meta ) return false;
        if ( '0' === (string) $meta || 0 === $meta ) return true;
        return 'yes' === $this->opt( 'verify_existing_users' );
    }

    private function save_profile_fields( $user_id, $full_name, $dob, $company, $business, $phone ) {
        update_user_meta( $user_id, 'full_name', $full_name );
        update_user_meta( $user_id, 'dob', $dob );
        update_user_meta( $user_id, 'company', $company );
        update_user_meta( $user_id, 'billing_company', $company );
        update_user_meta( $user_id, 'business_type', $business );
        update_user_meta( $user_id, 'phone_number', $phone );
        update_user_meta( $user_id, 'billing_phone', $phone );
        update_user_meta( $user_id, 'subscribe_newsletter', isset( $_POST['subscribe_newsletter'] ) ? 'yes' : 'no' );
        update_user_meta( $user_id, 'acknowledge_terms', 'yes' );
        update_user_meta( $user_id, 'ionpeptide_gate_registered_at', current_time( 'mysql' ) );
        $parts = preg_split( '/\s+/', trim( $full_name ) );
        $first = $parts ? array_shift( $parts ) : '';
        $last = $parts ? implode( ' ', $parts ) : '';
        update_user_meta( $user_id, 'first_name', $first );
        update_user_meta( $user_id, 'last_name', $last );
        update_user_meta( $user_id, 'billing_first_name', $first );
        update_user_meta( $user_id, 'billing_last_name', $last );
        wp_update_user( array( 'ID' => $user_id, 'display_name' => $full_name ) );
    }

    private function get_omnisend_api_key() {
        $api_key = defined( 'IONLG_OMNISEND_API_KEY' ) ? IONLG_OMNISEND_API_KEY : $this->opt( 'omnisend_api_key' );
        $api_key = is_string( $api_key ) ? trim( $api_key ) : '';

        return $api_key;
    }

    private function split_full_name_for_omnisend( $full_name ) {
        $full_name = trim( preg_replace( '/\s+/', ' ', (string) $full_name ) );
        if ( '' === $full_name ) {
            return array( '', '' );
        }

        $parts = explode( ' ', $full_name );
        $first = array_shift( $parts );
        $last  = trim( implode( ' ', $parts ) );

        return array( $first, $last );
    }

    private function build_omnisend_contact_payload( $email, $full_name, $dob, $company, $business, $phone, $subscribed, $user_id = 0, $source = 'website_registration' ) {
        list( $first_name, $last_name ) = $this->split_full_name_for_omnisend( $full_name );

        $email_status = $subscribed ? 'subscribed' : 'nonSubscribed';

        $payload = array(
            'identifiers' => array(
                array(
                    'type'     => 'email',
                    'id'       => sanitize_email( $email ),
                    'channels' => array(
                        'email' => array(
                            'status'     => $email_status,
                            'statusDate' => gmdate( 'c' ),
                        ),
                    ),
                ),
            ),
            'tags' => array(
                'source: ion login gate',
                'ion login gate registration',
            ),
            'customProperties' => array(
                'full_name'         => sanitize_text_field( $full_name ),
                'dob'               => sanitize_text_field( $dob ),
                'company'           => sanitize_text_field( $company ),
                'business_type'     => sanitize_text_field( $business ),
                'phone_number'      => sanitize_text_field( $phone ),
                'newsletter_signup' => $subscribed ? 'yes' : 'no',
                'source'            => sanitize_key( $source ),
                'wp_user_id'        => absint( $user_id ),
                'registered_at'     => current_time( 'mysql' ),
            ),
        );

        if ( '' !== $first_name ) {
            $payload['firstName'] = sanitize_text_field( $first_name );
        }
        if ( '' !== $last_name ) {
            $payload['lastName'] = sanitize_text_field( $last_name );
        }

        $dob_ts = strtotime( (string) $dob );
        if ( $dob_ts ) {
            $payload['birthdate'] = gmdate( 'Y-m-d', $dob_ts );
        }

        if ( '' !== trim( (string) $phone ) ) {
            $payload['customProperties']['phone_raw'] = sanitize_text_field( $phone );
        }

        return $payload;
    }

    private function record_omnisend_result( $ok, $status, $body, $email = '' ) {
        $body = is_string( $body ) ? wp_strip_all_tags( $body ) : '';
        if ( strlen( $body ) > 800 ) {
            $body = substr( $body, 0, 800 ) . '...';
        }

        update_option( 'ionpeptide_login_gate_omnisend_last_result', array(
            'ok'      => $ok ? 1 : 0,
            'status'  => absint( $status ),
            'body'    => $body,
            'email'   => sanitize_email( $email ),
            'time'    => current_time( 'mysql' ),
        ), false );
    }

    private function post_omnisend_contact_payload( $payload, $api_key, $email = '' ) {
        if ( '' === trim( (string) $api_key ) ) {
            $this->record_omnisend_result( false, 0, 'Omnisend sync enabled but no API key is configured.', $email );
            error_log( 'Ion Login Gate: Omnisend sync enabled but no API key is configured.' );
            return false;
        }

        $response = wp_remote_post( 'https://api.omnisend.com/v3/contacts', array(
            'method'  => 'POST',
            'headers' => array(
                'X-API-KEY'    => trim( (string) $api_key ),
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            $message = $response->get_error_message();
            $this->record_omnisend_result( false, 0, $message, $email );
            error_log( 'Ion Login Gate Omnisend Error: ' . $message );
            return false;
        }

        $status = intval( wp_remote_retrieve_response_code( $response ) );
        $body   = wp_remote_retrieve_body( $response );

        if ( $status < 200 || $status >= 300 ) {
            $this->record_omnisend_result( false, $status, $body, $email );
            error_log( 'Ion Login Gate Omnisend Failed: ' . $status . ' | ' . $body );
            return false;
        }

        $this->record_omnisend_result( true, $status, $body, $email );
        return true;
    }

    private function sync_user_to_omnisend( $user_id, $full_name, $dob, $company, $business, $phone, $subscribed = null, $source = 'website_registration' ) {
        if ( 'yes' !== $this->opt( 'omnisend_enabled' ) ) {
            return false;
        }

        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return false;
        }

        $api_key = $this->get_omnisend_api_key();
        if ( null === $subscribed ) {
            $subscribed = isset( $_POST['subscribe_newsletter'] );
        }
        $payload = $this->build_omnisend_contact_payload( $user->user_email, $full_name, $dob, $company, $business, $phone, (bool) $subscribed, $user_id, $source );

        $ok = $this->post_omnisend_contact_payload( $payload, $api_key, $user->user_email );
        if ( ! $ok ) {
            return false;
        }

        update_user_meta( $user_id, 'ionpeptide_omnisend_synced_at', current_time( 'mysql' ) );
        return true;
    }

    public function handle_admin_omnisend_test() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to test Omnisend.', 'ionpeptide-login-gate' ) );
        }

        check_admin_referer( 'ionlg_test_omnisend' );

        $email = isset( $_POST['ionlg_test_email'] ) ? sanitize_email( wp_unslash( $_POST['ionlg_test_email'] ) ) : '';
        if ( ! is_email( $email ) ) {
            $email = get_option( 'admin_email' );
        }

        $payload = $this->build_omnisend_contact_payload(
            $email,
            'Ion Gate Test',
            '',
            'Ion Peptide',
            'diagnostic',
            '',
            true,
            0,
            'admin_test'
        );

        $this->post_omnisend_contact_payload( $payload, $this->get_omnisend_api_key(), $email );

        wp_safe_redirect( add_query_arg( array(
            'page'               => 'ionpeptide-login-gate',
            'ionlg_omnisend_test' => '1',
        ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    private function send_verification_email( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return false;

        $token = wp_generate_password( 32, false );
        update_user_meta( $user_id, self::TOKEN_META, $token );
        update_user_meta( $user_id, self::TOKEN_TIME, time() );

        $verify_url = add_query_arg( array(
            'ion_verify' => rawurlencode( $token ),
            'user'       => absint( $user_id ),
        ), $this->gate_url() );

        $subject = 'Verify your email address';
        $message = '<h2>Email Verification</h2><p>Hi ' . esc_html( $user->display_name ) . ',</p><p>Please verify your email by clicking below:</p><p><a href="' . esc_url( $verify_url ) . '" style="background:#0D0D67;color:#fff;padding:12px 20px;text-decoration:none;border-radius:6px;display:inline-block;">Verify Email</a></p><p>Or copy this link:</p><p>' . esc_html( $verify_url ) . '</p>';

        if ( 'sendgrid' === $this->opt( 'email_delivery_method' ) || $this->has_sendgrid_key() ) {
            $sent = $this->send_email_via_sendgrid( $user, $subject, $message );
            if ( $sent ) {
                return true;
            }
            error_log( 'Ion Login Gate: SendGrid verification email failed; falling back to wp_mail.' );
        }

        $headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $this->opt( 'from_name' ) . ' <' . $this->opt( 'from_email' ) . '>' );
        return wp_mail( $user->user_email, $subject, $message, $headers );
    }

    private function has_sendgrid_key() {
        $api_key = defined( 'IONLG_SENDGRID_API_KEY' ) ? IONLG_SENDGRID_API_KEY : $this->opt( 'sendgrid_api_key' );
        if ( ( ! is_string( $api_key ) || '' === trim( $api_key ) ) && defined( 'IONLG_BUNDLED_SENDGRID_API_KEY' ) ) {
            $api_key = IONLG_BUNDLED_SENDGRID_API_KEY;
        }
        return is_string( $api_key ) && '' !== trim( $api_key );
    }

    private function send_email_via_sendgrid( $user, $subject, $message ) {
        $api_key = defined( 'IONLG_SENDGRID_API_KEY' ) ? IONLG_SENDGRID_API_KEY : $this->opt( 'sendgrid_api_key' );
        if ( ( ! is_string( $api_key ) || '' === trim( $api_key ) ) && defined( 'IONLG_BUNDLED_SENDGRID_API_KEY' ) ) {
            $api_key = IONLG_BUNDLED_SENDGRID_API_KEY;
        }
        $api_key = is_string( $api_key ) ? trim( $api_key ) : '';

        if ( '' === $api_key ) {
            error_log( 'Ion Login Gate: SendGrid selected but no API key is configured.' );
            return false;
        }

        $payload = array(
            'personalizations' => array(
                array(
                    'to'      => array(
                        array(
                            'email' => $user->user_email,
                            'name'  => $user->display_name,
                        ),
                    ),
                    'subject' => $subject,
                ),
            ),
            'from' => array(
                'email' => $this->opt( 'from_email' ),
                'name'  => $this->opt( 'from_name' ),
            ),
            'content' => array(
                array(
                    'type'  => 'text/html',
                    'value' => $message,
                ),
            ),
        );

        $response = wp_remote_post( 'https://api.sendgrid.com/v3/mail/send', array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Ion Login Gate SendGrid Error: ' . $response->get_error_message() );
            return false;
        }

        $status = intval( wp_remote_retrieve_response_code( $response ) );
        if ( 202 !== $status ) {
            error_log( 'Ion Login Gate SendGrid Failed: ' . $status . ' | ' . wp_remote_retrieve_body( $response ) );
            return false;
        }

        return true;
    }

    public function resend_verification_shortcode() {
        ob_start();
        $this->render_resend_form();
        return ob_get_clean();
    }

    public function handle_email_verification() {
        if ( empty( $_GET['ion_verify'] ) || empty( $_GET['user'] ) ) return;
        $user_id = absint( $_GET['user'] );
        $token = sanitize_text_field( wp_unslash( $_GET['ion_verify'] ) );
        $stored = get_user_meta( $user_id, self::TOKEN_META, true );
        $time = intval( get_user_meta( $user_id, self::TOKEN_TIME, true ) );
        if ( ! $stored || ! hash_equals( $stored, $token ) ) {
            $this->redirect_back( 'verify_invalid' );
        }
        if ( $time && ( time() - $time ) > DAY_IN_SECONDS ) {
            delete_user_meta( $user_id, self::TOKEN_META );
            $this->redirect_back( 'verify_expired' );
        }
        update_user_meta( $user_id, self::VERIFIED_META, 1 );
        delete_user_meta( $user_id, self::TOKEN_META );
        delete_user_meta( $user_id, self::TOKEN_TIME );
        $this->redirect_back( 'verified' );
    }

    private function is_disposable_email( $email ) {
        $domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );
        if ( ! $domain ) return true;
        $blocked = array(
            '10minutemail.com', '10minutemail.net', '20minutemail.com', '33mail.com', 'anonaddy.com', 'burnermail.io',
            'dispostable.com', 'emailondeck.com', 'fakeinbox.com', 'getnada.com', 'guerrillamail.com', 'guerrillamail.net',
            'maildrop.cc', 'mailinator.com', 'mailnesia.com', 'mintemail.com', 'moakt.com', 'mytemp.email', 'sharklasers.com',
            'spam4.me', 'tempmail.com', 'tempmail.net', 'temp-mail.org', 'throwawaymail.com', 'trashmail.com', 'yopmail.com',
            'tempmailo.com', 'mail.tm', 'inboxkitten.com', 'fakemail.net', 'throwawaymail.io', 'tmpmail.org', 'guerrillamail.org',
            'grr.la', 'guerrillamail.biz', 'guerrillamail.de', 'mailnesia.com', 'mail-temp.com', 'tempmail.dev', 'temp-mail.io'
        );
        $blocked = apply_filters( 'ionpeptide_login_gate_blocked_email_domains', $blocked );
        return in_array( $domain, array_map( 'strtolower', $blocked ), true );
    }

    public function filter_login_url( $login_url, $redirect, $force_reauth ) {
        if ( is_admin() ) return $login_url;
        return $this->gate_url( $redirect );
    }

    public function filter_lostpassword_url( $lostpassword_url, $redirect ) {
        if ( is_admin() ) return $lostpassword_url;
        return add_query_arg( 'mode', 'forgot', $this->gate_url( $redirect ) );
    }

    public function filter_logout_url( $logout_url, $redirect ) {
        if ( is_admin() ) return $logout_url;

        $target = $redirect ? $redirect : $this->current_full_url();
        $target = $this->safe_redirect_target( $target );

        $logout_url = remove_query_arg( 'redirect_to', $logout_url );
        return add_query_arg( 'redirect_to', $target, $logout_url );
    }

    public function filter_logout_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( is_admin() ) return $redirect_to;

        if ( ! empty( $requested_redirect_to ) ) {
            return $this->safe_redirect_target( $requested_redirect_to );
        }

        return $this->current_page_logout_redirect_target();
    }

    public function admin_menu() {
        add_options_page( 'Ion Login Gate', 'Ion Login Gate', 'manage_options', 'ionpeptide-login-gate', array( $this, 'settings_page' ) );
    }

    public function register_settings() {
        register_setting( 'ionpeptide_login_gate_group', self::OPTION_KEY, array( $this, 'sanitize_options' ) );
    }

    public function sanitize_options( $input ) {
        $defaults = self::defaults();
        $clean = array();
        foreach ( array( 'enabled', 'email_verification_enabled', 'verify_existing_users', 'block_disposable_email', 'recaptcha_enabled', 'recaptcha_fail_open', 'allow_approved_crawlers', 'omnisend_enabled', 'google_login_enabled' ) as $key ) {
            $clean[ $key ] = isset( $input[ $key ] ) ? 'yes' : 'no';
        }
        $clean['gate_slug'] = isset( $input['gate_slug'] ) ? sanitize_title( $input['gate_slug'] ) : $defaults['gate_slug'];
        if ( '' === $clean['gate_slug'] ) $clean['gate_slug'] = $defaults['gate_slug'];
        $clean['logo_url'] = isset( $input['logo_url'] ) ? esc_url_raw( $input['logo_url'] ) : $defaults['logo_url'];
        $clean['right_bg_url'] = isset( $input['right_bg_url'] ) ? esc_url_raw( $input['right_bg_url'] ) : $defaults['right_bg_url'];
        $clean['brand_title'] = isset( $input['brand_title'] ) ? sanitize_text_field( $input['brand_title'] ) : $defaults['brand_title'];
        $clean['brand_subtitle'] = isset( $input['brand_subtitle'] ) ? sanitize_text_field( $input['brand_subtitle'] ) : $defaults['brand_subtitle'];
        $clean['google_client_id'] = isset( $input['google_client_id'] ) ? trim( sanitize_text_field( $input['google_client_id'] ) ) : $defaults['google_client_id'];
        $clean['from_email'] = isset( $input['from_email'] ) && is_email( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : $defaults['from_email'];
        $clean['from_name'] = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : $defaults['from_name'];
        $method = isset( $input['email_delivery_method'] ) ? sanitize_key( $input['email_delivery_method'] ) : $defaults['email_delivery_method'];
        $clean['email_delivery_method'] = in_array( $method, array( 'wp_mail', 'sendgrid' ), true ) ? $method : $defaults['email_delivery_method'];
        $clean['sendgrid_api_key'] = isset( $input['sendgrid_api_key'] ) ? trim( sanitize_text_field( $input['sendgrid_api_key'] ) ) : '';
        if ( '' === $clean['sendgrid_api_key'] && isset( $input['_existing_sendgrid_api_key'] ) ) {
            $clean['sendgrid_api_key'] = trim( sanitize_text_field( $input['_existing_sendgrid_api_key'] ) );
        }
        $clean['omnisend_api_key'] = isset( $input['omnisend_api_key'] ) ? trim( sanitize_text_field( $input['omnisend_api_key'] ) ) : '';
        if ( '' === $clean['omnisend_api_key'] && isset( $input['_existing_omnisend_api_key'] ) ) {
            $clean['omnisend_api_key'] = trim( sanitize_text_field( $input['_existing_omnisend_api_key'] ) );
        }
        $clean['resend_cooldown_seconds'] = isset( $input['resend_cooldown_seconds'] ) ? (string) max( 0, intval( $input['resend_cooldown_seconds'] ) ) : $defaults['resend_cooldown_seconds'];
        $clean['recaptcha_site_key'] = isset( $input['recaptcha_site_key'] ) ? sanitize_text_field( $input['recaptcha_site_key'] ) : '';
        $clean['recaptcha_secret_key'] = isset( $input['recaptcha_secret_key'] ) ? sanitize_text_field( $input['recaptcha_secret_key'] ) : '';
        $clean['recaptcha_min_score'] = isset( $input['recaptcha_min_score'] ) ? (string) max( 0, min( 1, floatval( $input['recaptcha_min_score'] ) ) ) : $defaults['recaptcha_min_score'];
        return wp_parse_args( $clean, $defaults );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
        ?>
        <div class="wrap ionrb-wrap">
            <h1>Ion Peptide Login Gate</h1>
            <nav class="nav-tab-wrapper" aria-label="Ion Login Gate settings">
                <a class="nav-tab <?php echo 'registration-blocks' !== $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'options-general.php?page=ionpeptide-login-gate' ) ); ?>">General Settings</a>
                <a class="nav-tab <?php echo 'registration-blocks' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'options-general.php?page=ionpeptide-login-gate&tab=registration-blocks' ) ); ?>">Registration Blocks</a>
            </nav>
            <?php if ( 'registration-blocks' === $tab ) {
                IonLG_Registration_Blocks::admin_page();
                echo '</div>';
                return;
            }
            $o = $this->options();
            ?>
            <p>Standalone gate page using native WordPress auth. This avoids WooCommerce My Account template submit handling.</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'ionpeptide_login_gate_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Enable gate</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" <?php checked( $o['enabled'], 'yes' ); ?>> Show gate for logged-out visitors</label></td></tr>
                    <tr><th scope="row">SEO crawler access</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[allow_approved_crawlers]" <?php checked( $o['allow_approved_crawlers'], 'yes' ); ?>> Allow approved search/audit bots to bypass the gate</label><p class="description">Lets Google/Bing/Lighthouse/PageSpeed see protected storefront pages while normal logged-out visitors still see the access gate.</p></td></tr>
                    <tr><th scope="row">Gate slug</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[gate_slug]" value="<?php echo esc_attr( $o['gate_slug'] ); ?>"><p class="description">Default: research-access. Save Permalinks once after changing.</p></td></tr>
                    <tr><th scope="row">Logo URL</th><td><input class="regular-text" type="url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[logo_url]" value="<?php echo esc_attr( $o['logo_url'] ); ?>"></td></tr>
                    <tr><th scope="row">Right image URL</th><td><input class="regular-text" type="url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[right_bg_url]" value="<?php echo esc_attr( $o['right_bg_url'] ); ?>"></td></tr>
                    <tr><th scope="row">Brand title</th><td><input class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[brand_title]" value="<?php echo esc_attr( $o['brand_title'] ); ?>"></td></tr>
                    <tr><th scope="row">Brand subtitle</th><td><input class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[brand_subtitle]" value="<?php echo esc_attr( $o['brand_subtitle'] ); ?>"></td></tr>
                    <tr><th scope="row">Google sign-in</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[google_login_enabled]" <?php checked( $o['google_login_enabled'], 'yes' ); ?>> Enable Continue with Google on Sign In and Register</label><br><br><input class="large-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[google_client_id]" value="<?php echo esc_attr( $o['google_client_id'] ); ?>" placeholder="Google OAuth Web Client ID"><p class="description">Uses Google Identity Services in the browser and verifies the access token server-side. No Google client secret is stored in this plugin. In Google Cloud, add https://ionpeptide.com as an Authorized JavaScript origin (and https://www.ionpeptide.com only if that hostname is used).</p></td></tr>
                    <tr><th scope="row">Email verification</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_verification_enabled]" <?php checked( $o['email_verification_enabled'], 'yes' ); ?>> Require verification for new registrations</label><br><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[verify_existing_users]" <?php checked( $o['verify_existing_users'], 'yes' ); ?>> Require existing users with no verification meta to verify</label><br><label>Resend cooldown seconds <input type="number" min="0" style="width:90px" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[resend_cooldown_seconds]" value="<?php echo esc_attr( $o['resend_cooldown_seconds'] ); ?>"></label></td></tr>
                    <tr><th scope="row">Email sender</th><td><input type="email" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[from_email]" value="<?php echo esc_attr( $o['from_email'] ); ?>"> <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[from_name]" value="<?php echo esc_attr( $o['from_name'] ); ?>"></td></tr>
                    <tr><th scope="row">Email delivery</th><td><label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_delivery_method]" value="wp_mail" <?php checked( $o['email_delivery_method'], 'wp_mail' ); ?>> WordPress mail</label><br><label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_delivery_method]" value="sendgrid" <?php checked( $o['email_delivery_method'], 'sendgrid' ); ?>> SendGrid API</label><br><br><input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[_existing_sendgrid_api_key]" value="<?php echo esc_attr( $o['sendgrid_api_key'] ); ?>"><input class="regular-text" type="password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sendgrid_api_key]" value="<?php echo esc_attr( $o['sendgrid_api_key'] ); ?>" placeholder="SendGrid API key"><p class="description">SendGrid is bundled from the previous child-theme verification system. A key can also be defined in wp-config.php as IONLG_SENDGRID_API_KEY to override it.</p></td></tr>
                    <tr><th scope="row">Omnisend registration sync</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[omnisend_enabled]" <?php checked( $o['omnisend_enabled'], 'yes' ); ?>> Sync new gate registrations to Omnisend</label><br><br><input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[_existing_omnisend_api_key]" value="<?php echo esc_attr( $o['omnisend_api_key'] ); ?>"><input class="regular-text" type="password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[omnisend_api_key]" value="<?php echo esc_attr( $o['omnisend_api_key'] ); ?>" placeholder="Omnisend API key"><p class="description">Pushes the registration email consent plus full name, DOB, company, business type, phone, source, and WordPress user ID into Omnisend custom properties. A key can also be defined in wp-config.php as IONLG_OMNISEND_API_KEY to override it.</p></td></tr>
                    <tr><th scope="row">Bot protection</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[block_disposable_email]" <?php checked( $o['block_disposable_email'], 'yes' ); ?>> Block common disposable/temporary emails</label><p class="description">A hidden honeypot field is always active.</p></td></tr>
                    <tr><th scope="row">reCAPTCHA v3</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[recaptcha_enabled]" <?php checked( $o['recaptcha_enabled'], 'yes' ); ?>> Enable reCAPTCHA v3</label><br><br><input class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[recaptcha_site_key]" value="<?php echo esc_attr( $o['recaptcha_site_key'] ); ?>" placeholder="Site key"><br><br><input class="regular-text" type="password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[recaptcha_secret_key]" value="<?php echo esc_attr( $o['recaptcha_secret_key'] ); ?>" placeholder="Secret key"><br><br><label>Minimum score <input type="number" step="0.1" min="0" max="1" style="width:90px" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[recaptcha_min_score]" value="<?php echo esc_attr( $o['recaptcha_min_score'] ); ?>"></label><br><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[recaptcha_fail_open]" <?php checked( $o['recaptcha_fail_open'], 'yes' ); ?>> Allow login/register if token is missing/blocked</label><p class="description">Keep enabled to avoid Safari Private Browsing/privacy extension loops. If a token exists and scores too low, it still blocks.</p></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Omnisend connection test</h2>
            <?php
            $last = get_option( 'ionpeptide_login_gate_omnisend_last_result', array() );
            if ( is_array( $last ) && ! empty( $last['time'] ) ) :
                $ok = ! empty( $last['ok'] );
                ?>
                <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> inline" style="padding:10px 12px;">
                    <p><strong>Last Omnisend result:</strong> <?php echo $ok ? 'Success' : 'Failed'; ?><?php echo isset( $last['status'] ) && $last['status'] ? ' — HTTP ' . esc_html( $last['status'] ) : ''; ?></p>
                    <p><strong>Email:</strong> <?php echo esc_html( isset( $last['email'] ) ? $last['email'] : '' ); ?> &nbsp; <strong>Time:</strong> <?php echo esc_html( $last['time'] ); ?></p>
                    <?php if ( ! empty( $last['body'] ) ) : ?><p><code style="white-space:normal;"><?php echo esc_html( $last['body'] ); ?></code></p><?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ionlg_test_omnisend' ); ?>
                <input type="hidden" name="action" value="ionlg_test_omnisend">
                <p><input type="email" class="regular-text" name="ionlg_test_email" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" placeholder="test@email.com"> <?php submit_button( 'Send test contact to Omnisend', 'secondary', 'submit', false ); ?></p>
                <p class="description">This creates/updates a test contact using the saved Omnisend API key and the same corrected v3 contact payload used by new registrations.</p>
            </form>
        </div>
        <?php
    }
}

register_activation_hook( __FILE__, array( 'IonPeptide_Login_Gate', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'IonPeptide_Login_Gate', 'deactivate' ) );
IonPeptide_Login_Gate::instance();
