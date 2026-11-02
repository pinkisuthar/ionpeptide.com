<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_LinkMoney extends WC_Payment_Gateway
{

    /** @var string */
    protected $client_id;

    /** @var string */
    protected $client_secret;

    /** @var string */
    protected $environment;

    /** @var string */
    protected $merchant_id;

    /** @var string */
    public $title;

    /** @var string */
    public $description;

    /** @var string */
    public $enabled;

    /** @var string */
    protected $webhook_secret;

    const META_CUSTOMER_ID = '_linkmoney_customer_id';
    const META_PAYMENT_ID = '_linkmoney_payment_id';
    const META_EVER_PROCESSING = '_linkmoney_ever_processing';

    /** Session key: coupon codes from a Link Money order pending re-apply. */
    const SESSION_PENDING_COUPON_CODES = 'linkmoney_pending_coupon_codes';

    /** Session key: draft order id to rebuild the cart from if the customer
     *  returns to checkout without the lm_restore_order param (e.g. browser Back). */
    const SESSION_PENDING_RESTORE_ORDER = 'linkmoney_pending_restore_order';

    const TOKEN_CACHE_PREFIX    = 'linkmoney_token_';
    const ACCOUNTS_CACHE_PREFIX = 'linkmoney_accounts_';
    const TOKEN_CACHE_TTL       = 59 * MINUTE_IN_SECONDS;
    const ACCOUNTS_CACHE_TTL    = 10 * MINUTE_IN_SECONDS;

    public function __construct()
    {
        $this->id = 'linkmoney';
        $this->method_title = __('Same-Day Bank Payment', 'linkmoney');
        $this->method_description = __('Accept bank transfers via Link Money.', 'linkmoney');
        $this->has_fields = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->environment = $this->get_option('environment', 'sandbox');
        $this->client_id = $this->get_option('client_id');
        $this->client_secret = $this->get_option('client_secret');
        $this->merchant_id = $this->get_option('merchant_id');
        $this->webhook_secret = $this->get_option('webhook_secret');

        add_action( 'admin_print_footer_scripts', [ $this, 'inject_icon_on_payments_screen' ] );

        add_filter( 'woocommerce_order_is_payable', [ $this, 'disable_pay_while_awaiting' ], 10, 2 );
        add_action( 'woocommerce_before_pay_form', [ $this, 'show_linkmoney_awaiting_notice' ] );

        add_action(
            'woocommerce_rest_checkout_process_payment_with_context',
            [ $this, 'store_api_process_linkmoney' ],
            10,
            2
        );

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'linkmoney_handle_return']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'linkmoney_handle_return']);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options_and_subscribe']);

        // Register wc-api callback for handling redirects from Link Money.
        // This fires at the WordPress routing level, before any theme or custom
        // checkout plugin can strip URL parameters.
        add_action('woocommerce_api_linkmoney', [$this, 'handle_wc_api_callback']);

        add_action( 'woocommerce_checkout_update_order_review', [ $this, 'sync_restored_coupons_with_payment_method' ] );
        add_action( 'woocommerce_before_checkout_form', [ $this, 'sync_restored_coupons_with_payment_method' ] );

        // Clear any stashed coupons once an order is placed, so they never leak
        // into a later checkout. Fires on both the classic checkout and the
        // Store API / Blocks checkout. For a Link Money order these hooks run
        // before process_payment()/store_api_process_linkmoney() re-stashes, so
        // the codes survive into the redirect; for any other gateway the stash
        // is cleared and nothing re-stashes.
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'clear_pending_coupon_restore' ], 10, 0 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'clear_pending_coupon_restore' ], 10, 0 );

        $this->supports = array(
            'products',
            'refunds',
            'redirect'
        );

        add_action('woocommerce_blocks_loaded', function () {
            wp_enqueue_script('linkmoney-block-integration');
        });

    }

    public function inject_icon_on_payments_screen() {
        // Only on WooCommerce > Settings > Payments (older Woo uses "checkout" tab).
        if (
            ! isset( $_GET['page'], $_GET['tab'] ) ||
            $_GET['page'] !== 'wc-settings' ||
            ! in_array( $_GET['tab'], [ 'payments', 'checkout' ], true )
        ) {
            return;
        }

        $src = esc_js( LINKMONEY_PLUGIN_URL . 'assets/linkmoney-logos.svg' );
        ?>
        <script>
            jQuery(function($){
                // Try a few selectors to be robust across WC versions.
                var $row =
                    $('tr[data-gateway_id="linkmoney"], tr[data-gateway-id="linkmoney"], tr#linkmoney');
                if (!$row.length) return;

                var $target =
                    $row.find('td:first .wc-payment-gateway-method-title, td:first strong, td:first');
                if (!$target.length) $target = $row.find('td:first');

                $('<img>', {
                    src: '<?php echo $src; ?>',
                    alt: 'Link Money',
                    css: { height: '32px', width: 'auto', marginLeft: '8px', verticalAlign: 'middle' }
                }).appendTo($target.eq(0));
            });
        </script>
        <?php
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => ['title' => __('Enable/Disable', 'linkmoney'), 'type' => 'checkbox', 'label' => __('Enable Link Money Pay by Bank', 'linkmoney'), 'default' => 'no'],
            'title' => ['title' => __('Title', 'linkmoney'), 'type' => 'text', 'default' => __('Same-Day Bank Payment', 'linkmoney')],
            'description' => ['title' => __('Description', 'linkmoney'), 'type' => 'textarea', 'default' => __('Secure bank transfer via Link Money.', 'linkmoney')],
            'environment' => ['title' => __('Environment', 'linkmoney'), 'type' => 'select', 'default' => 'sandbox', 'options' => ['sandbox' => __('Sandbox', 'linkmoney'), 'production' => __('Production', 'linkmoney')]],
            'client_id' => ['title' => __('Client ID', 'linkmoney'), 'type' => 'text', 'description' => __('From the Link Money merchant portal.', 'linkmoney')],
            'client_secret' => ['title' => __('Client Secret', 'linkmoney'), 'type' => 'password', 'description' => __('Never expose this in JS.', 'linkmoney')],
            'merchant_id' => ['title' => __('Merchant ID', 'linkmoney'), 'type' => 'text', 'description' => __('Your Link Money Merchant ID.', 'linkmoney')],
            'webhook_secret' => [
                'title' => __('Webhook Secret', 'linkmoney'),
                'type' => 'password',
                'description' => __('A secret key you generate and paste here; used to sign incoming webhooks and to subscribe via the API.', 'linkmoney'),
            ],
            'debug' => ['title' => __('Debug Log', 'linkmoney'), 'type' => 'checkbox', 'label' => __('Enable logging', 'linkmoney'), 'default' => 'no'],
        ];
    }

    public function get_method_description() {
        // What WooCommerce shows under the method name on the Payments screen.
        $desc = $this->method_description ?: __( 'Accept bank transfers via Link Money.', 'linkmoney' );

        // Append our brand icon only in wp-admin.
        if ( is_admin() ) {
            $src = LINKMONEY_PLUGIN_URL . 'assets/linkmoney-logos.svg';
            $desc .= sprintf(
                ' <img src="%s" alt="%s" style="height:32px;width:auto;vertical-align:middle;margin-left:6px;" />',
                esc_url( $src ),
                esc_attr__( 'Pay by bank via Link Money', 'linkmoney' )
            );
        }

        return $desc;
    }

    public function payment_fields()
    {
        if (!is_checkout()) {
            return;
        }

        ?>
        <script>
            (function ($) {
                function toggleLinkFields() {
                    if ($('input[name="payment_method"]:checked').val() === '<?php echo $this->id; ?>') {
                        $('.linkmoney-saved-accounts').show();
                    } else {
                        $('.linkmoney-saved-accounts').hide();
                    }
                }

                $(function () {
                    toggleLinkFields();
                    $('form.checkout').on('change', 'input[name="payment_method"]', toggleLinkFields);
                });
            })(jQuery);
        </script>
        <?php

        $desc = $this->get_description();
        if ( ! empty( $desc ) ) {
            echo '<div class="linkmoney-description" style="margin:0 0 1em;">' . wp_kses_post( wpautop( $desc ) ) . '</div>';
        }

        $user_id = get_current_user_id();
        $customer_id = get_user_meta($user_id, self::META_CUSTOMER_ID, true);
        $accounts = $customer_id ? $this->get_saved_accounts($customer_id) : [];

        echo '<div class="linkmoney-saved-accounts" style="margin:1em 0;">';

        $default_selected = !empty($accounts) ? $accounts[0]['accountId'] : '__new';

        if (!empty($accounts)) {
            echo '<strong>' . esc_html__('Pay with your saved bank account:', 'linkmoney') . '</strong><br><br>';
            foreach ($accounts as $acct) {
                printf(
                    '<label style="display:flex; justify-content:flex-start; align-items:center; gap:0.5em; width: 100%%;">' .
                    '<input type="radio" name="linkmoney_saved_account" value="%1$s" %5$s style="margin:0px;">' .
                    '<img src="%2$s" alt="%3$s logo" style="height:32px; width:auto; object-fit:contain; margin-right: 5px;"><span>%3$s  (****%4$s)</span>' .
                    '</label>',
                    esc_attr($acct['accountId']),
                    esc_url($acct['logoUrl']),
                    esc_html($acct['bankName']),
                    esc_html($acct['last4']),
                    checked($default_selected, $acct['accountId'], false)
                );
            }
        }

        // Always offer “Link a new bank account” option
        printf(
            '<label style="display:flex; align-items:center; gap:0.5em;">' .
            '<input type="radio" name="linkmoney_saved_account" value="__new" %s> %s' .
            '<div style="display:flex; gap:0.5em; margin-left:10px;">' .

            '<svg width="170" height="32" viewBox="0 0 302 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="60" height="60" rx="8" fill="#FEFEFE"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M46.778 22.024H45.8228C38.8699 22.024 31.9282 22.024 24.9753 22.024C24.0645 22.024 23.7424 21.7909 23.7758 20.8473C23.8313 18.4829 23.7758 16.1074 23.7758 13.7429C23.7758 12.4109 24.1645 12.0001 25.464 12.0001C28.796 12.0001 32.1836 12.0001 35.549 12.0001C35.9876 11.9949 36.4148 12.1399 36.7596 12.4109C40.0139 15.4524 43.2238 18.5162 46.4558 21.58C46.5558 21.7021 46.6447 21.8131 46.778 22.024Z" fill="#0F77C6"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M38.0938 46.733C38.0938 46.3223 38.0938 46.0559 38.0938 45.7783C38.0938 38.8183 38.0938 31.8582 38.0938 24.9092C38.0938 23.9657 38.3826 23.6993 39.3044 23.7215C41.6368 23.7881 43.9803 23.7215 46.3238 23.7215C47.6789 23.7215 48.001 24.0212 48.001 25.3311C48.001 28.7611 48.001 32.1912 48.001 35.6213C47.9949 36.1234 47.8187 36.6086 47.5012 36.9978C44.5912 40.117 41.6368 43.1919 38.6935 46.2779C38.5049 46.4439 38.3044 46.596 38.0938 46.733Z" fill="#0F77C6"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M13.4453 38.1201H35.0592C36.3254 38.1201 36.3254 38.1201 36.3254 39.4078C36.3254 41.7056 36.3254 44.0145 36.3254 46.3235C36.3254 47.5778 35.9033 47.9885 34.6594 47.9996C31.2274 47.9996 27.8065 47.9996 24.3744 47.9996C23.9696 48.0075 23.5754 47.8696 23.2638 47.6111C20.0872 44.6362 16.9329 41.6279 13.7896 38.6196C13.6622 38.4622 13.5471 38.2952 13.4453 38.1201Z" fill="#0F77C6"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M21.8962 13.4443C21.8962 13.8773 21.8962 14.177 21.8962 14.4878C21.8962 21.3369 21.8962 28.197 21.8962 35.0572C21.8962 36.0229 21.6296 36.3338 20.6411 36.3005C18.2754 36.2338 15.8985 36.3005 13.5327 36.3005C12.3443 36.3005 12 35.9452 12 34.7353C12 31.3052 12 27.8751 12 24.4561C12 24.0186 12.1444 23.5933 12.4109 23.2462C15.3654 20.0936 18.3642 16.9632 21.2964 13.8329C21.4867 13.689 21.6871 13.5592 21.8962 13.4443Z" fill="#0F77C6"/>
                <rect x="68" width="60" height="60" rx="8" fill="white"/>
                <rect x="68" width="60" height="60" rx="8" fill="white"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M98.2352 42.5902C105.349 36.4431 115.178 30.5573 120.74 28.3335C119.835 27.8104 118.542 27.026 117.12 26.1108C110.134 28.4649 101.857 33.6966 94.4844 39.582C95.7786 40.6282 97.072 41.5437 98.2352 42.5902Z" fill="#E21737"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M95.0145 25.9767C93.9803 25.4536 92.8161 24.9305 91.9103 24.5381C89.1945 25.8464 85.8321 27.9387 81.4336 31.0773C82.3395 31.6007 83.3745 32.2545 84.4086 32.778C87.9012 30.2924 91.2636 27.9387 95.0145 25.9767Z" fill="#081F65"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M101.345 22.9686C100.052 22.3148 95.6541 20.6145 92.5499 19.8301C91.645 20.2221 90.3507 20.7452 89.4458 21.0073C90.6099 21.2683 94.7492 22.4455 98.2417 24.2765C99.1466 23.8845 100.44 23.361 101.345 22.9686Z" fill="#E21737"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M86.3465 22.5737C82.4666 24.4047 78.3274 26.8899 76 28.4591C76.7758 28.8512 77.5516 29.1133 78.7158 29.6364C83.76 26.2357 87.6399 24.143 89.1924 23.4889C88.0283 23.0968 86.9932 22.8355 86.3465 22.5737Z" fill="#081F65"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M104.301 21.9234C105.206 21.662 106.241 21.4003 107.147 21.0082C104.56 19.831 101.197 18.6538 98.2219 18C97.7053 18.1307 96.2819 18.5231 95.377 18.7848C96.4119 19.1769 99.7744 19.9621 104.301 21.9234Z" fill="#E21737"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M87.7559 34.7381C88.7899 35.3919 89.9541 36.3078 91.1183 36.962C98.4908 31.2072 105.734 26.7604 113.752 24.1446C112.588 23.6215 111.683 23.098 110.39 22.4438C105.475 23.7521 97.3267 27.1524 87.7559 34.7381Z" fill="#E21737"/>
                <rect x="136" width="60" height="60" rx="8" fill="white"/>
                <rect x="136" width="60" height="60" rx="8" fill="white"/>
                <path d="M171.855 22.6665H164.249V24.5322H165.408C166.354 24.5322 166.814 24.7454 166.814 25.2784C166.814 25.5627 166.814 25.9892 166.61 26.5489L163.4 34.9888C163.4 34.9888 163.4 34.9799 163.205 34.9799C161.746 30.76 159.747 25.9092 159.747 25.9092C159.641 25.6693 159.605 25.4206 159.605 25.2074C159.605 24.7187 159.924 24.5322 160.764 24.5322H162.17V22.6665H153.281V24.5322H154.405C155.838 24.5322 156.298 25.2429 156.439 26.62L153.131 34.9533C153.131 34.9533 153.06 34.9533 152.901 34.9266C151.371 30.4135 149.443 25.8381 149.443 25.8381C149.337 25.5983 149.372 25.4117 149.372 25.314C149.337 24.7898 149.761 24.5322 150.708 24.5322H151.521V22.6665H143.083V24.5322H143.473C144.702 24.5322 145.542 25.3495 146.108 26.7887L151.061 39.3332H154.157L157.775 30.0848L161.569 39.3332H164.558L169.308 26.5844C169.874 25.0563 170.334 24.5322 171.705 24.5322H172.811C174.243 24.5322 174.739 24.6832 174.739 26.0602V35.9039C174.739 37.2898 174.243 37.432 172.811 37.432L171.855 37.4675V39.3421H182.089V37.4675H180.161C178.728 37.4675 178.507 37.3342 178.507 35.9483V31.604H179.816C181.885 31.604 182.177 32.1725 182.213 34.9622L183.787 34.9977V26.6555H182.248C182.248 29.2319 181.921 29.6228 179.824 29.6228H178.515V24.6388H182.664C184.645 24.6388 185.795 24.7898 186.352 25.776C186.812 26.691 187.015 27.8282 187.015 29.1164H188.926V22.6665H171.864H171.855Z" fill="#AC3A30"/>
                <rect x="204" width="98" height="60" rx="8" fill="white"/>
                <path d="M225.332 38L223.66 36.35C222.237 37.626 220.499 38.264 218.446 38.264C217.317 38.264 216.319 38.0733 215.454 37.692C214.603 37.296 213.943 36.746 213.474 36.042C213.005 35.3233 212.77 34.4947 212.77 33.556C212.77 32.544 213.056 31.6347 213.628 30.828C214.2 30.0213 215.043 29.3687 216.158 28.87C215.762 28.386 215.476 27.924 215.3 27.484C215.124 27.0293 215.036 26.5453 215.036 26.032C215.036 25.328 215.219 24.6973 215.586 24.14C215.953 23.568 216.473 23.1207 217.148 22.798C217.837 22.4753 218.637 22.314 219.546 22.314C220.47 22.314 221.262 22.49 221.922 22.842C222.582 23.194 223.073 23.6633 223.396 24.25C223.719 24.8367 223.858 25.4893 223.814 26.208H220.822C220.837 25.724 220.719 25.3573 220.47 25.108C220.221 24.844 219.883 24.712 219.458 24.712C219.047 24.712 218.703 24.8367 218.424 25.086C218.16 25.3207 218.028 25.6287 218.028 26.01C218.028 26.3767 218.138 26.7433 218.358 27.11C218.578 27.462 218.937 27.902 219.436 28.43L223.308 32.258C223.337 32.2287 223.359 32.1993 223.374 32.17C223.389 32.1407 223.403 32.1113 223.418 32.082L224.672 29.992H227.884L226.344 32.698C226.051 33.27 225.735 33.8127 225.398 34.326L229.116 38H225.332ZM218.534 35.712C219.737 35.712 220.8 35.2867 221.724 34.436L217.94 30.696C216.517 31.3267 215.806 32.2213 215.806 33.38C215.806 34.04 216.055 34.5973 216.554 35.052C217.053 35.492 217.713 35.712 218.534 35.712ZM251.019 25.636C252.515 25.636 253.718 26.098 254.627 27.022C255.551 27.9313 256.013 29.2073 256.013 30.85V38H252.933V31.268C252.933 30.3147 252.691 29.5887 252.207 29.09C251.723 28.5767 251.063 28.32 250.227 28.32C249.391 28.32 248.724 28.5767 248.225 29.09C247.741 29.5887 247.499 30.3147 247.499 31.268V38H244.419V31.268C244.419 30.3147 244.177 29.5887 243.693 29.09C243.209 28.5767 242.549 28.32 241.713 28.32C240.863 28.32 240.188 28.5767 239.689 29.09C239.205 29.5887 238.963 30.3147 238.963 31.268V38H235.883V25.812H238.963V27.286C239.359 26.7727 239.865 26.3693 240.481 26.076C241.112 25.7827 241.801 25.636 242.549 25.636C243.503 25.636 244.353 25.8413 245.101 26.252C245.849 26.648 246.429 27.22 246.839 27.968C247.235 27.264 247.807 26.6993 248.555 26.274C249.318 25.8487 250.139 25.636 251.019 25.636ZM264.348 38.198C263.175 38.198 262.119 37.9413 261.18 37.428C260.241 36.9 259.501 36.1593 258.958 35.206C258.43 34.2527 258.166 33.1527 258.166 31.906C258.166 30.6593 258.437 29.5593 258.98 28.606C259.537 27.6527 260.293 26.9193 261.246 26.406C262.199 25.878 263.263 25.614 264.436 25.614C265.609 25.614 266.673 25.878 267.626 26.406C268.579 26.9193 269.327 27.6527 269.87 28.606C270.427 29.5593 270.706 30.6593 270.706 31.906C270.706 33.1527 270.42 34.2527 269.848 35.206C269.291 36.1593 268.528 36.9 267.56 37.428C266.607 37.9413 265.536 38.198 264.348 38.198ZM264.348 35.514C264.905 35.514 265.426 35.382 265.91 35.118C266.409 34.8393 266.805 34.4287 267.098 33.886C267.391 33.3433 267.538 32.6833 267.538 31.906C267.538 30.7473 267.23 29.86 266.614 29.244C266.013 28.6133 265.272 28.298 264.392 28.298C263.512 28.298 262.771 28.6133 262.17 29.244C261.583 29.86 261.29 30.7473 261.29 31.906C261.29 33.0647 261.576 33.9593 262.148 34.59C262.735 35.206 263.468 35.514 264.348 35.514ZM276.045 27.704C276.441 27.0587 276.955 26.5527 277.585 26.186C278.231 25.8193 278.964 25.636 279.785 25.636V28.87H278.971C278.003 28.87 277.27 29.0973 276.771 29.552C276.287 30.0067 276.045 30.7987 276.045 31.928V38H272.965V25.812H276.045V27.704ZM293.19 31.642C293.19 32.082 293.16 32.478 293.102 32.83H284.192C284.265 33.71 284.573 34.3993 285.116 34.898C285.658 35.3967 286.326 35.646 287.118 35.646C288.262 35.646 289.076 35.1547 289.56 34.172H292.882C292.53 35.3453 291.855 36.3133 290.858 37.076C289.86 37.824 288.636 38.198 287.184 38.198C286.01 38.198 284.954 37.9413 284.016 37.428C283.092 36.9 282.366 36.1593 281.838 35.206C281.324 34.2527 281.068 33.1527 281.068 31.906C281.068 30.6447 281.324 29.5373 281.838 28.584C282.351 27.6307 283.07 26.8973 283.994 26.384C284.918 25.8707 285.981 25.614 287.184 25.614C288.342 25.614 289.376 25.8633 290.286 26.362C291.21 26.8607 291.921 27.572 292.42 28.496C292.933 29.4053 293.19 30.454 293.19 31.642ZM290 30.762C289.985 29.97 289.699 29.3393 289.142 28.87C288.584 28.386 287.902 28.144 287.096 28.144C286.333 28.144 285.688 28.3787 285.16 28.848C284.646 29.3027 284.331 29.9407 284.214 30.762H290Z" fill="#2B2B2B"/>
            </svg>
' .
            '</div></label>',
            checked($default_selected, '__new', false),
            esc_html__('Link a new bank account', 'linkmoney')
        );

        echo '</div>';
    }

    protected function get_access_token($scope = 'Link-Payment')
    {
        $cache_key = self::TOKEN_CACHE_PREFIX . $this->environment . '_' . $scope . '_' . md5( (string) $this->client_id );
        $cached    = get_transient( $cache_key );
        if ( is_string( $cached ) && $cached !== '' ) {
            return $cached;
        }

        $logger = wc_get_logger();
        $context = ['source' => 'linkmoney'];

        $base = $this->environment === 'production' ? 'https://api.link.money' : 'https://api.link-sandbox.money';
        $body = http_build_query(['client_id' => $this->client_id, 'client_secret' => $this->client_secret, 'grant_type' => 'client_credentials', 'scope' => $scope]);

        $resp = wp_remote_post("{$base}/v1/tokens", ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => $body, 'timeout' => 20]);
        if (is_wp_error($resp)) {
            $logger->error('Token error: ' . $resp->get_error_message(), $context);
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw = wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);
        if ($code !== 200 || empty($data['access_token'])) {
            $logger->error("Token failed ({$code}): " . wp_json_encode($data), $context);
            return new WP_Error('linkmoney_token', "Token error ({$code})");
        }

        $ttl = self::TOKEN_CACHE_TTL;
        if ( ! empty( $data['expires_in'] ) && is_numeric( $data['expires_in'] ) ) {
            $ttl = min( $ttl, max( 60, (int) $data['expires_in'] - 60 ) );
        }
        set_transient( $cache_key, $data['access_token'], $ttl );

        return $data['access_token'];
    }

    /**
     * Server-to-server lookup: fetch the authoritative transaction record from
     * Link Money. Used by the wc-api callback / thankyou-return handlers before
     * promoting order status, so a forged return URL (where ?paymentStatusCode=0
     * is appended client-side) cannot mark an unpaid order as processing.
     *
     * @param string $payment_id The Link Money transactionId / paymentId.
     * @return array|WP_Error    Decoded transaction body on success.
     */
    protected function fetch_transaction($payment_id)
    {
        if (empty($payment_id)) {
            return new WP_Error('linkmoney_no_txn_id', 'transactionId required.');
        }

        // GET /v1/transactions/{id} requires Link-Core (read) scope. Link-Payment
        // is for writes (create payment, refund) and Link Money returns 404 — not
        // 401/403 — when the token's scope cannot see the resource.
        $token = $this->get_access_token('Link-Core');
        if (is_wp_error($token)) {
            return $token;
        }

        $base = $this->environment === 'production'
            ? 'https://api.link.money'
            : 'https://api.link-sandbox.money';

        $resp = wp_remote_get("{$base}/v1/transactions/" . rawurlencode($payment_id), [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($resp)) {
            return new WP_Error('linkmoney_verify_http', $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return new WP_Error('linkmoney_verify_bad_response', "HTTP {$code} from transaction lookup.");
        }

        return $body;
    }

    /**
     * Apply a Link Money transaction record to a WC order — the single point
     * that translates Link Money transactionStatus to WC order status.
     *
     * CREATED / SUCCEEDED / RETRYABLE_FAILED (and any future status) are
     * intentionally not actionable here; the webhook is the authoritative
     * settlement signal.
     *
     * @param WC_Order $order
     * @param array    $txn   Decoded body from fetch_transaction().
     */
    private static function apply_transaction_to_order($order, array $txn)
    {
        $status = strtoupper((string) ($txn['transactionStatus'] ?? ''));

        switch ($status) {
            case 'AUTHORIZED':
            case 'INITIATED':
            case 'SCHEDULED':
                if ($order->has_status(['failed']) && self::order_ever_was_processing($order)) {
                    $order->update_status(
                        'completed',
                        __('Customer paying for already failed order; marking completed.', 'linkmoney')
                    );
                } else if (!$order->has_status(['processing', 'completed'])) {
                    $order->update_meta_data(self::META_EVER_PROCESSING, true);
                    $order->add_order_note(__('Link Money authorized – awaiting settlement.', 'linkmoney'));
                    $order->update_status(
                        'processing',
                        __('Link Money authorized – awaiting settlement.', 'linkmoney')
                    );
                    self::trigger_awaiting_payment_emails($order);
                }
                break;

            case 'PENDING':
                if ($order->has_status(['pending', 'checkout-draft'])) {
                    $order->add_order_note(__('Link Money payment pending.', 'linkmoney'));
                    $order->update_status('on-hold', 'Link Money payment pending.');
                }
                break;

            case 'TERMINAL_FAILED':
                if (!$order->has_status(['failed', 'completed'])) {
                    $order->add_order_note(__('Link Money declined the payment.', 'linkmoney'));
                    $order->update_status('failed', 'Link Money declined the payment.');
                }
                break;

            default:
                break;
        }
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!empty($_POST['linkmoney_saved_account']) && $_POST['linkmoney_saved_account'] !== '__new') {
            return $this->process_saved_payment($order, sanitize_text_field($_POST['linkmoney_saved_account']));
        } else {
            $payment_data = isset($_POST['payment_data']) ? wc_clean($_POST['payment_data']) : [];
            $selected_account = '';
            foreach ($payment_data as $data) {
                if ($data['key'] === 'linkmoney_saved_account') {
                    $selected_account = $data['value'];
                    break;
                }
            }
            if ($selected_account && $selected_account !== '__new') {
                return $this->process_saved_payment($order, $selected_account);
            }
        }

        $session = $this->create_session($order);
        if (is_wp_error($session)) {
            wc_add_notice($session->get_error_message(), 'error');
            return [
                'result' => 'failure',
                'redirect' => $order->get_checkout_payment_url(),
            ];
        }
        $order->update_status('checkout-draft', __('Customer redirected to Link Money to link bank account.', 'linkmoney'));
        $this->stash_coupons_for_restore( $order );
        $this->stash_order_for_restore( $order );
        return [
            'result' => 'success',
            'redirect' => esc_url_raw($session['sessionUrl']),
        ];
    }

    public function process_saved_payment( $order, $account_id ) {
        try {
        $this->apply_dedupe_guard( $order );
    } catch ( Exception $e ) {
        wc_add_notice( $e->getMessage(), 'error' );
        
        return [
            'result'   => 'failure',
            'redirect' => $order->get_checkout_payment_url(),
        ];
    }
        $token = $this->get_access_token( 'Link-Payment' );
        if ( is_wp_error( $token ) ) {
            $order->update_status( 'failed', __( 'Link Money authorization failed (token).', 'linkmoney' ) );
            $order->save();
            return [
                'result'   => 'failure',
                'redirect' => $order->get_checkout_payment_url(),
            ];
        }

        // Call payments API only for: (1) new orders never processed, or (2) orders ever processed but in failed state (retry).
        // Otherwise show duplicate payment error.
        $ever_processed = $order->get_meta( self::META_PAYMENT_ID ) || $order->get_meta( self::META_EVER_PROCESSING );
        if ( $ever_processed && ! $order->has_status( [ 'failed' ] ) ) {
            wc_add_notice( __( 'Duplicate Order Alert: Your order is currently being processed. No action is required at this time. Please do not resubmit the order.', 'linkmoney' ), 'error' );
            return [
                'result'   => 'failure',
                'redirect' => $order->get_checkout_payment_url(),
            ];
        }

        // Atomically claim this order's charge slot BEFORE the (slow) payments
        // call. This closes the check-then-act window where two overlapping
        // submissions of the SAME order both passed the meta guard above (whose
        // result is only persisted after the API returns) and both charged.
        // Exactly one concurrent request wins the claim; the rest stop here.
        if ( ! LinkMoney_Payment_Lock::claim( $order->get_id() ) ) {
            wc_add_notice( __( 'Duplicate Order Alert: Your order is currently being processed. No action is required at this time. Please do not resubmit the order.', 'linkmoney' ), 'error' );
            return [
                'result'   => 'failure',
                'redirect' => $order->get_checkout_payment_url(),
            ];
        }

        $amount  = number_format( (float) $order->get_total(), 2, '.', '' );
        $payload = [
            'source'      => [ 'id' => get_user_meta( $order->get_user_id(), self::META_CUSTOMER_ID, true ), 'type' => 'CUSTOMER' ],
            'destination' => [ 'id' => $this->merchant_id, 'type' => 'MERCHANT' ],
            'amount'      => [ 'value' => $amount, 'currency' => $order->get_currency() ],
            'requestKey'  => LinkMoney_Payment_Lock::request_key( $order->get_id() ),
            'clientReferenceId' => $order->get_id(),
        ];
        $base = $this->environment === 'production' ? 'https://api.link.money' : 'https://api.link-sandbox.money';
        $resp = wp_remote_post(
            "{$base}/v1/payments",
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer {$token}",
                ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
            ]
        );

        $code = wp_remote_retrieve_response_code( $resp );
        $raw  = wp_remote_retrieve_body( $resp );
        $body = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            $order->update_status( 'failed', __( 'Link Money authorization was declined.', 'linkmoney' ) );
            $order->save();

            // Show specific error message from API if available

            $display_error = isset($body['errorMessage']) ? $body['errorMessage'] : __( 'Link Money authorization was declined.', 'linkmoney' );
            wc_add_notice( $display_error, 'error' );

            // Release the slot and rotate the idempotency key so a genuine retry
            // of this (now failed) order is treated as a fresh request.
            LinkMoney_Payment_Lock::rotate_request_key( $order->get_id() );
            LinkMoney_Payment_Lock::release( $order->get_id() );

            return [
                'result'   => 'failure',
                'redirect' => '',
            ];
        }

        $immediateTerminal =
            ( isset( $body['paymentStatus'] ) && strtoupper( (string) $body['paymentStatus'] ) === 'TERMINAL_FAILED' ) ||
            ( isset( $body['paymentStatusCode'] ) && (int) $body['paymentStatusCode'] === 1 );

        if ( empty( $body['paymentId'] ) || $immediateTerminal ) {
            $order->update_status( 'failed', __( 'Link Money declined the payment.', 'linkmoney' ) );
            $order->save();

            // get the exact error message from the API response if available

            $error_message = __('Your payment was declined. Please try again with a different bank account.', 'linkmoney');

            if (! empty( $body['errorDetails']['errorMessage'] ) ) {
                $error_message = $body['errorDetails']['errorMessage'];
            } elseif ( ! empty( $body['errorMessage'] ) ) {
                $error_message = $body['errorMessage'];
            }

            wc_add_notice( $error_message, 'error');

            // Release the slot and rotate the idempotency key so a genuine retry
            // of this (now failed) order is treated as a fresh request.
            LinkMoney_Payment_Lock::rotate_request_key( $order->get_id() );
            LinkMoney_Payment_Lock::release( $order->get_id() );

            return [
                'result'   => 'failure',
                'redirect' => '',
            ];
        }

        $order->update_meta_data( self::META_PAYMENT_ID, sanitize_text_field( $body['paymentId'] ) );

        if (isset( $body['paymentStatus'] ) && strtoupper( (string) $body['paymentStatus'] ) === 'PENDING' ) {
            $order->update_status( 'on-hold', __( 'Link Money Pending – Order on hold.', 'linkmoney' ) );
        } else if ($order->has_status(['failed']) && self::order_ever_was_processing( $order )) {
            $order->update_status( 'completed', __( 'Customer paying for already failed order, marking the order status completed.', 'linkmoney' ) );
        } else if (!$order->has_status(['processing', 'completed'])) {
            $order->set_status( 'processing' );
            $order->update_meta_data( self::META_EVER_PROCESSING, true );
        }

        $order->save();

        // Clear the idempotency key AND release the slot now that the charge has
        // reached a terminal state. Clearing on success (not only on failure)
        // means a later, legitimate retry of this order — e.g. after an ACH
        // return marks it failed via webhook, and the customer links a different
        // bank — starts with a fresh key instead of being dedup-blocked by Link
        // Money as a duplicate of the original (now-returned) payment. Release
        // happens only AFTER the payment id is persisted, so the durable meta
        // guard already blocks any concurrent resubmission.
        LinkMoney_Payment_Lock::rotate_request_key( $order->get_id() );
        LinkMoney_Payment_Lock::release( $order->get_id() );

        // Only clear cart on successful payment
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }

    public function get_saved_accounts($customer_id)
    {
        // Validate customer_id before making API call
        if (empty($customer_id) || !is_string($customer_id) || trim($customer_id) === '') {
            $logger = wc_get_logger();
            $context = ['source' => 'linkmoney'];
            $logger->debug('get_saved_accounts called with empty or invalid customer_id: ' . var_export($customer_id, true), $context);
            return [];
        }

        $cache_key = self::ACCOUNTS_CACHE_PREFIX . md5( $customer_id );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $token = $this->get_access_token('Link-Core');
        if (is_wp_error($token)) return [];
        $base = $this->environment === 'production' ? 'https://api.link.money' : 'https://api.link-sandbox.money';
        $resp = wp_remote_get("{$base}/v1/customers/{$customer_id}/accounts", ['headers' => ['Authorization' => "Bearer {$token}"], 'timeout' => 20]);
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code !== 200) return [];
        $data = json_decode($body, true);
        $out = [];
        if (!empty($data['accountDetails']) && is_array($data['accountDetails'])) {
            foreach ($data['accountDetails'] as $acct) {
                if (strtoupper($acct['accountStatus']) === 'ACTIVE' && $acct['preferred']) {
                    $out[] = ['accountId' => $acct['accountId'], 'bankName' => $acct['financialInstitutionName'], 'last4' => $acct['accountLastFourDigits'], 'logoUrl' => $acct['iconUrl'] ?? ''];
                }
            }
        }

        set_transient( $cache_key, $out, self::ACCOUNTS_CACHE_TTL );

        return $out;
    }

    /**
     * Delete the saved-accounts transient for a customer. Called from the
     * return-from-Link entry points where a new bank may have just been linked.
     */
    protected function delete_saved_accounts_cache( $customer_id )
    {
        if ( empty( $customer_id ) || ! is_string( $customer_id ) ) {
            return;
        }
        delete_transient( self::ACCOUNTS_CACHE_PREFIX . md5( $customer_id ) );
    }



    public function create_session($order)
    {
        $base = $this->environment === 'production' ? 'https://api.link.money' : 'https://api.link-sandbox.money';

        $amount = number_format((float)$order->get_total(), 2, '.', '');
        if(is_user_logged_in()){
            $user_email = get_userdata(get_current_user_id())->user_email;
        } else {
            $user_email = $order->get_billing_email();
        }
        $payload = [
            'firstName' => $order->get_billing_first_name(),
            'lastName' => $order->get_billing_last_name(),
            'email' => $user_email,
            'customerProfile' => [
                'guestCheckout' => false,
            ],
            'billingAddress' => [
                'streetAddress' => $order->get_billing_address_1(),
                'city' => $order->get_billing_city(),
                'stateOrProvince' => $order->get_billing_state(),
                'postalCode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ],
            'orderDetails' => ['totalAmount' => ['value' => $amount, 'currency' => $order->get_currency()]],
            'paymentDetails' => ['amount' => ['value' => $amount, 'currency' => $order->get_currency()], 'clientReferenceId' => "{$order->get_id()}", 'softDescriptor' => get_bloginfo('name'), 'requestKey' => wp_generate_uuid4()],
            'redirectUrl' => $this->get_linkmoney_callback_url($order),
        ];
        $resp = wp_remote_post("{$base}/v2/sessions", ['headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Basic ' . base64_encode("{$this->client_id}:{$this->client_secret}")], 'body' => wp_json_encode($payload), 'timeout' => 30]);
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        if ($code >= 400 || empty($data['sessionUrl'])) return new WP_Error('linkmoney_api', 'Link Money session error' . $body);
        return $data;
    }

    /**
     * Build a wc-api callback URL that Link Money will redirect to after payment.
     * This URL is handled at the WordPress routing level, so custom checkout
     * themes/plugins cannot strip the query parameters.
     */
    private function get_linkmoney_callback_url($order)
    {
        return add_query_arg(
            [
                'wc-api'    => 'linkmoney',
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            home_url('/')
        );
    }

    /**
     * Handle the wc-api callback from Link Money redirect.
     * Processes payment parameters, updates order, then redirects to the
     * merchant's thank-you page (works with both default and custom checkouts).
     */
    public function handle_wc_api_callback()
    {
        $logger  = wc_get_logger();
        $context = ['source' => 'linkmoney'];

        $order_id  = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $order_key = isset($_GET['order_key']) ? sanitize_text_field($_GET['order_key']) : '';

        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $order_key) {
            $logger->error("wc-api callback: invalid order_id={$order_id} or order_key mismatch", $context);
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        if ($order->get_payment_method() !== $this->id) {
            $logger->error("wc-api callback: order {$order_id} payment method is not linkmoney", $context);
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        // Check if user cancelled (status >= 204)
        $status = isset($_GET['status']) ? absint($_GET['status']) : 0;
        if ($status >= 204) {
            // Redirect to cart with restore param so items can be restored
            // in the customer's browser session after deleting the draft order.
            $redirect_url = add_query_arg([
                'lm_restore_order' => $order_id,
                'lm_restore_key'   => $order->get_order_key(),
            ], wc_get_checkout_url());
            wp_safe_redirect($redirect_url);
            exit;
        }

        // Extract identifiers from the URL. These are references, not status
        // assertions — the authoritative state comes from the API call below.
        $payment_id  = sanitize_text_field($_GET['paymentId'] ?? '');
        $customer_id = sanitize_text_field($_GET['customerId'] ?? '');

        if ($payment_id) {
            $order->update_meta_data(self::META_PAYMENT_ID, $payment_id);
        }
        if ($customer_id) {
            $order->update_meta_data(self::META_CUSTOMER_ID, $customer_id);
            update_user_meta($order->get_user_id(), self::META_CUSTOMER_ID, $customer_id);
        }
        $order->save();

        // A new bank account may have been linked at Link Money — bust the cache.
        if ($customer_id) {
            $this->delete_saved_accounts_cache($customer_id);
        }

        // No paymentId in the URL means we have nothing to verify. Fall through
        // to thank-you; the webhook will reconcile when (if) it arrives.
        if (!$payment_id) {
            $logger->info("wc-api callback: order {$order_id} returned without paymentId", $context);
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        // Ask Link Money what actually happened. The URL has no authority here.
        $txn = $this->fetch_transaction($payment_id);
        if (is_wp_error($txn)) {
            $logger->error(
                "wc-api callback: txn lookup failed for order {$order_id}, txnId={$payment_id}: "
                . $txn->get_error_message(),
                $context
            );
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        // The transaction must belong to THIS order, and must be a DEBIT. Without
        // these checks, a customer could replay another order's paymentId or a
        // refund's paymentId in a forged return URL.
        $api_ref  = (string) ($txn['clientReferenceId'] ?? '');
        $txn_type = strtoupper((string) ($txn['transactionType'] ?? ''));
        if ($api_ref !== (string) $order_id || $txn_type !== 'DEBIT') {
            $logger->error(
                "wc-api callback: txn {$payment_id} does not belong to order {$order_id} as a DEBIT "
                . "(clientReferenceId={$api_ref}, transactionType={$txn_type})",
                $context
            );
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        self::apply_transaction_to_order($order, $txn);

        // Terminal failure: route the customer back to checkout with the cart
        // restored and an error notice. Everything else lands on thank-you.
        $status = strtoupper((string) ($txn['transactionStatus'] ?? ''));
        if ($status === 'TERMINAL_FAILED') {
            if (function_exists('WC') && WC()->cart) {
                $this->restore_cart_from_order($order);
            }
            if (isset(WC()->session)) {
                WC()->session->set('store_api_draft_order', 0);
                WC()->session->set('order_awaiting_payment', 0);
            }
            wc_add_notice(
                $txn['transactionFailureReason']['description']
                    ?? __('Your payment was declined. Please try again with a different bank account.', 'linkmoney'),
                'error'
            );
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        // Payment was authorized — drop any stashed coupons so they don't leak
        // into the customer's next checkout.
        if (in_array($status, ['AUTHORIZED', 'INITIATED', 'SCHEDULED'], true)) {
            $this->clear_pending_coupon_restore();
        }

        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    public function linkmoney_handle_return($order_id)
    {
        $logger  = wc_get_logger();
        $context = ['source' => 'linkmoney'];

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }

        if (!isset($_GET['status']) && !isset($_GET['paymentStatusCode']) && !isset($_GET['paymentId'])) {
            return;
        }

        if (absint($_GET['status'] ?? 0) >= 204) {
            // Redirect to cart with restore param so items can be restored
            // in the customer's browser session after deleting the draft order.
            $redirect_url = add_query_arg([
                'lm_restore_order' => $order_id,
                'lm_restore_key'   => $order->get_order_key(),
            ], wc_get_checkout_url());
            wp_safe_redirect($redirect_url);
            exit;
        }

        $payment_id  = sanitize_text_field($_GET['paymentId']  ?? '');
        $customer_id = sanitize_text_field($_GET['customerId'] ?? '');

        if ($payment_id) {
            $order->update_meta_data(self::META_PAYMENT_ID, $payment_id);
        }
        if ($customer_id) {
            $order->update_meta_data(self::META_CUSTOMER_ID, $customer_id);
            update_user_meta($order->get_user_id(), self::META_CUSTOMER_ID, $customer_id);
        }
        $order->save();

        // A new bank account may have been linked at Link Money — bust the cache.
        if ($customer_id) {
            $this->delete_saved_accounts_cache($customer_id);
        }

        if (!$payment_id) {
            return;
        }

        $txn = $this->fetch_transaction($payment_id);
        if (is_wp_error($txn)) {
            $logger->error(
                "handle_return: txn lookup failed for order {$order_id}, txnId={$payment_id}: "
                . $txn->get_error_message(),
                $context
            );
            return;
        }

        $api_ref  = (string) ($txn['clientReferenceId'] ?? '');
        $txn_type = strtoupper((string) ($txn['transactionType'] ?? ''));
        if ($api_ref !== (string) $order_id || $txn_type !== 'DEBIT') {
            $logger->error(
                "handle_return: txn {$payment_id} does not belong to order {$order_id} as a DEBIT "
                . "(clientReferenceId={$api_ref}, transactionType={$txn_type})",
                $context
            );
            return;
        }

        self::apply_transaction_to_order($order, $txn);

        $status = strtoupper((string) ($txn['transactionStatus'] ?? ''));
        if ($status === 'TERMINAL_FAILED') {
            if (function_exists('WC') && WC()->cart) {
                $this->restore_cart_from_order($order);
            }
            if (isset(WC()->session)) {
                WC()->session->set('store_api_draft_order', 0);
                WC()->session->set('order_awaiting_payment', 0);
            }
            wc_add_notice(
                $txn['transactionFailureReason']['description']
                    ?? __('Your payment was declined. Please try again with a different bank account.', 'linkmoney'),
                'error'
            );
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // For the success path, mirror the prior behaviour by redirecting to the
        // canonical order-received URL. PENDING and other non-actionable states
        // just return so WC can finish rendering the thank-you / receipt page.
        if (in_array($status, ['AUTHORIZED', 'INITIATED', 'SCHEDULED'], true)) {
            // Payment was authorized — drop any stashed coupons so they don't
            // leak into the customer's next checkout.
            $this->clear_pending_coupon_restore();

            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }
    }

    public function process_admin_options_and_subscribe()
    {

        $this->process_admin_options();


        $show_admin_error = function ($message) {
            add_action('admin_notices', function () use ($message) {
                echo '<div class="notice notice-error"><p>'
                    . esc_html($message)
                    . '</p></div>';
            });
        };


        $secret = sanitize_text_field($this->get_option('webhook_secret'));
        update_option('linkmoney_webhook_secret', $secret);


        $token = $this->get_access_token('Link-Core');
        if (is_wp_error($token)) {
            $show_admin_error('Link Money token error: ' . $token->get_error_message());
            return;
        }

        $auth_header = [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ];
        $base = $this->environment === 'production'
            ? 'https://api.link.money'
            : 'https://api.link-sandbox.money';

        // 4) Unsubscribe old webhook if present
        $old = get_option('linkmoney_webhook_subscription_key', '');
        if ($old) {
            wp_remote_request("{$base}/v1/webhook/unsubscribe", [
                'method' => 'PUT',
                'headers' => $auth_header,
                'body' => wp_json_encode([
                    'subscriptionId' => $old,
                    'subscriptionDetails' => ['type' => 'ALL'],
                ]),
            ]);
        }


        $resp = wp_remote_post("{$base}/v1/webhook/subscribe", [
            'headers' => $auth_header,
            'body' => wp_json_encode([
                'url' => rest_url('linkmoney/v1/webhook'),
                'secretKey' => $secret,
                'subscriptionDetails' => ['type' => 'ALL'],
            ]),
        ]);

        if (is_wp_error($resp)) {
            $show_admin_error('Webhook subscribe error: ' . $resp->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code === 200 && !empty($data['subscriptionKey'])) {
            update_option('linkmoney_webhook_subscription_key',
                sanitize_text_field($data['subscriptionKey'])
            );
        } else {
            $msg = $data['message'] ?? 'Unknown error';
            $show_admin_error('Webhook subscription failed: ' . $msg);
        }
    }


    /**
     * Register our REST route to receive Link Money webhooks.
     */
    public static function register_webhook_endpoint()
    {
        register_rest_route('linkmoney/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle incoming Link Money webhooks for payments.
     */
    public static function handle_webhook(WP_REST_Request $req)
    {
        $logger = wc_get_logger();
        $context = ['source' => 'webhook'];

        $secret = get_option('linkmoney_webhook_secret');
        if (empty($secret)) {
            $logger->error('Webhook secret not set.', $context);
            return new WP_Error('missing_secret', 'Webhook secret not configured.', ['status' => 403]);
        }

        $signature = $req->get_header('x-signature');
        $unique_id = $req->get_header('x-signature-uniqueid');
        $timestamp = $req->get_header('x-signature-timestamp');
        $body = $req->get_body();
        if (empty($signature) || empty($unique_id) || empty($timestamp)) {
            $logger->error('Missing required webhook headers.', $context);
            return new WP_Error('missing_headers', 'Missing required webhook headers.', ['status' => 400]);
        }

        $expected_signature = base64_encode(hash_hmac('sha512', "{$unique_id}.{$timestamp}.{$body}", $secret, true));
        if (!hash_equals($expected_signature, $signature)) {
            $logger->error('Webhook signature mismatch.', $context);
            return new WP_Error('invalid_signature', 'Invalid webhook signature.', ['status' => 403]);
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logger->error('Webhook JSON parse error: ' . json_last_error_msg(), $context);
            return new WP_Error('invalid_json', 'Invalid JSON', ['status' => 400]);
        }

        $payment_id = $data['metadata']['resourceId'] ?? '';
        if (!$payment_id) {
            $logger->error('Webhook missing metadata.resourceId', $context);
            return new WP_Error('no_payment_id', 'No payment ID', ['status' => 400]);
        }
        $logger->debug("Webhook paymentId: {$payment_id}", $context);

        $orders = wc_get_orders([
            'meta_key' => self::META_PAYMENT_ID,
            'meta_value' => $payment_id,
            'limit' => 1,
        ]);

        // Fallback: webhook may arrive before META_PAYMENT_ID is persisted on the
        // order (e.g., the customer's browser hasn't returned to the wc-api callback
        // yet). Resolve via clientReferenceId, which Link Money echoes back as the
        // WooCommerce order ID, and persist the payment ID for future lookups.
        //
        // Skip for CREDIT (refund) events: refund payments echo the original DEBIT's
        // clientReferenceId, so this fallback would mis-bind the refund's payment ID
        // onto the original order's META_PAYMENT_ID.
        $transaction_type = strtoupper((string)($data['metadata']['transactionType'] ?? ''));
        if (empty($orders) && $transaction_type !== 'CREDIT') {
            $client_ref = $data['clientReferenceId'] ?? ($data['metadata']['clientReferenceId'] ?? '');
            if ($client_ref) {
                $fallback = wc_get_order(absint($client_ref));
                if ($fallback && $fallback->get_payment_method() === 'linkmoney') {
                    $fallback->update_meta_data(self::META_PAYMENT_ID, $payment_id);
                    $fallback->save();
                    $orders = [$fallback];
                    $logger->info(
                        "Webhook: resolved order {$fallback->get_id()} via clientReferenceId fallback for payment {$payment_id}",
                        $context
                    );
                }
            }
        }

        if (empty($orders)) {
            $logger->error("Webhook: no order found for payment {$payment_id}", $context);
            return new WP_Error('order_not_found', 'Order not found', ['status' => 404]);
        }
        $order = $orders[0];

        if (!$order || $order->get_payment_method() !== 'linkmoney') {
            return;
        }

        $event = $data['eventType'] ?? '';
        $logger->debug("Webhook eventType: {$event}", $context);

        switch ($event) {
            case 'payment.succeeded':

                if ($order->has_status(['on-hold'])) {
                    $order->update_meta_data(self::META_EVER_PROCESSING, true);
                    $order->update_status('processing');
                }

                $logger->info("Order {$order->get_id()} settled successfully.", $context);
                $order->add_order_note(__('Link Money webhook: payment settled successfully.', 'linkmoney'));
                break;

                case 'payment.authorized':
                // Don't change order status if it's already completed
                if (!$order->has_status(['completed'])) {
                    $order->update_meta_data( self::META_EVER_PROCESSING, true );
                    $order->update_status('processing', 'Link Money webhook: payment authorized.');

                    // Trigger New Order and Order On Hold emails
                    self::trigger_awaiting_payment_emails( $order );

                    $logger->info("Order {$order->get_id()} marked awaiting payment confirmation", $context);
                } else {
                    $logger->info("Order {$order->get_id()} already completed, skipping status change", $context);
                }
                break;

            case 'payment.initiated':
                // Safety net: payment.authorized normally arrives first and promotes
                // on-hold -> processing. For the small subset of orders where that
                // didn't happen, payment.initiated heals the order so the customer's
                // order doesn't stay stuck on-hold.
                if ($order->has_status(['on-hold'])) {
                    $order->update_meta_data(self::META_EVER_PROCESSING, true);
                    $order->update_status('processing');
                    $order->add_order_note('Link Money webhook: payment initiated.');
                    
                    self::trigger_awaiting_payment_emails($order);
                    $logger->info("Order {$order->get_id()} promoted on-hold -> processing on payment.initiated", $context);
                } else {
                    $logger->debug("Order {$order->get_id()} status '{$order->get_status()}' is not on-hold; ignoring payment.initiated", $context);
                }
                break;

            case 'payment.failed':
                $order->update_status('failed', 'Link Money webhook: payment failed.');
                $logger->info("Order {$order->get_id()} marked failed", $context);
                break;

            default:
                $logger->debug("Ignoring webhook eventType {$event}", $context);
                break;
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Handle refunds via Link Money’s API.
     *
     * @param int $order_id WooCommerce order ID
     * @param float $amount Amount to refund (decimal)
     * @param string $reason (optional) memo for the refund
     * @return true|WP_Error    True on success, WP_Error on failure
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {


        $logger = wc_get_logger();
        $context = ['source' => 'refund'];

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('linkmoney_no_order', 'Order not found');
        }


        $payment_id = $order->get_meta(self::META_PAYMENT_ID);
        if (!$payment_id) {
            return new WP_Error('linkmoney_no_payment', 'No Link Money payment ID on order.');
        }


        $token = $this->get_access_token('Link-Payment');
        if (is_wp_error($token)) {
            return $token;
        }


        $amount_str = number_format((float)$amount, 2, '.', '');
        $payload = [
            'paymentId' => $payment_id,
            'amount' => [
                'value' => $amount_str,
                'currency' => $order->get_currency(),
            ],
            'clientReferenceId' => (string)$order_id . '-refund-' . wp_generate_uuid4(),
            'requestKey' => wp_generate_uuid4(),
        ];


        $base = $this->environment === 'production'
            ? 'https://api.link.money'
            : 'https://api.link-sandbox.money';
        $url_called = "{$base}/v1/refunds";
        $resp = wp_remote_post(
            $url_called,
            [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
                'timeout' => 30,
            ]
        );
        if (is_wp_error($resp)) {
            return new WP_Error('linkmoney_refund_http', $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body_resp = wp_remote_retrieve_body($resp);
        $body = json_decode($body_resp, true);
        
        if ($code < 200 || $code >= 300 || empty($body["paymentId"])) {
            $msg = $body['errorMessage'] ?? 'Unknown refund error: ' . $body_resp;

            $logger->debug("Refund Failed: Code {$code} ... Error: {$body_resp}", $context);
            return new WP_Error('linkmoney_refund_failed', $msg);
        } else {


            $order->add_order_note(
                sprintf(
                    'Link Money refund successful. Refund ID: %s, Amount: %s %s',
                    sanitize_text_field($body["paymentId"]),
                    esc_html($amount_str),
                    esc_html($order->get_currency())
                )
            );
        }
        return true;
    }

    public function disable_pay_while_awaiting( $payable, $order ) {
        if ( $order instanceof WC_Order
            && $order->get_payment_method() === $this->id
            && $order->has_status( 'awaiting-payment' )
        ) {
            return false;
        }
        return $payable;
    }

    public function show_linkmoney_awaiting_notice( $order ) {
        if ( $order instanceof WC_Order
            && $order->get_payment_method() === $this->id
            && $order->has_status( 'awaiting-payment' )
        ) {
            wc_print_notice(
                __( 'Your bank payment is being confirmed. This page will reflect the final status shortly.', 'linkmoney' ),
                'notice'
            );
        }
    }

    /**
     * Trigger New Order and Order On Hold emails when order status changes to awaiting-payment
     *
     * @param WC_Order $order The order to send emails for
     */
    private static function trigger_awaiting_payment_emails( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Load the WooCommerce mailer once
        $mailer = WC()->mailer();

        // Email configurations: [email_key, note_message]
        $emails_to_send = [
            ['WC_Email_New_Order', __( 'New Order email notification sent to admin.', 'linkmoney' )]
            //['WC_Email_Customer_On_Hold_Order', __( 'On Hold email notification sent to customer.', 'linkmoney' )]
        ];

        // Send each email
        foreach ( $emails_to_send as $email_config ) {
            $email_key = $email_config[0];
            $note_message = $email_config[1];

            $email = $mailer->emails[ $email_key ];

            if ( $email ) {
                // Trigger the email notification
                $email->trigger( $order->get_id() );

                // Add an order note for reference
                $order->add_order_note( $note_message );
            }
        }
    }

    /**
     * Whether the order ever had status "processing" from order meta data.
     *
     * @param WC_Order $order
     * @return bool
     */
    private static function order_ever_was_processing( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        if ( (bool) $order->get_meta( self::META_EVER_PROCESSING ) ) {
            return true;
        }
        
        return false;
    }

    /**
     * Handle cart restoration on page load.
     * Called via template_redirect when lm_restore_order param is present.
     * Restores the cart immediately so the customer can retry, but defers the
     * order cancellation by 60s so an in-flight Link Money webhook (which may
     * land slightly after the browser redirect) can still flip the order to
     * processing. The delayed handler re-checks status before cancelling.
     */
    public function maybe_restore_cart_from_order() {
        if (empty($_GET['lm_restore_order']) || empty($_GET['lm_restore_key'])) {
            return;
        }

        $order_id  = absint($_GET['lm_restore_order']);
        $order_key = sanitize_text_field($_GET['lm_restore_key']);
        $order     = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            return;
        }

        // Never touch an order that has already been paid.
        if ($order->is_paid() || $order->has_status(['processing', 'completed', 'on-hold'])) {
            return;
        }

        // Drop the Blocks/Store API draft association so the customer's next
        // checkout starts fresh rather than reusing this order.
        if (WC()->session) {
            WC()->session->set('store_api_draft_order', 0);
        }

        // Clear cart and restore items, coupons, and shipping from the order.
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
            $this->restore_cart_from_order($order);
        }

        wc_add_notice(
            __('You didn\'t complete linking your bank account. Please try again.', 'linkmoney'),
            'error'
        );

        // Defer cancellation. If a webhook arrives in the next 120s and marks
        // the order paid, the delayed handler will see that and leave it alone.
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + 120,
                'linkmoney_delayed_abandon_check',
                ['order_id' => $order_id],
                'linkmoney'
            );
        } else {
            // Action Scheduler unavailable — fall back to immediate cancellation
            // rather than leaving the draft order dangling.
            self::handle_delayed_abandon_check($order_id);
        }
    }

    /**
     * Recover the cart when the customer returns to checkout WITHOUT the
     * lm_restore_order param — most commonly the browser Back button from the
     * Link Money hosted page. The Store API (Blocks checkout) empties the cart
     * when the order is placed, so the customer would otherwise land on an empty
     * checkout with their items and coupon lost.
     *
     * Uses the draft order id stashed at redirect time (SESSION_PENDING_RESTORE_ORDER)
     * and only acts when the cart is empty and the order is still unpaid, so it
     * never clobbers a cart the customer is actively using.
     *
     * The page-context gate (checkout/cart only) lives in the template_redirect
     * registration; this method is the testable restore step.
     */
    public function maybe_recover_pending_checkout() {
        if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
            return;
        }

        $order_id = absint( WC()->session->get( self::SESSION_PENDING_RESTORE_ORDER ) );
        if ( ! $order_id ) {
            return;
        }

        // Only restore into an empty cart — never overwrite items the customer
        // has already added/changed since.
        if ( ! WC()->cart->is_empty() ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            WC()->session->__unset( self::SESSION_PENDING_RESTORE_ORDER );
            return;
        }

        // Never touch an order that has already been paid or moved forward.
        if ( $order->is_paid() || $order->has_status( ['processing', 'completed', 'on-hold'] ) ) {
            WC()->session->__unset( self::SESSION_PENDING_RESTORE_ORDER );
            return;
        }

        $this->restore_cart_from_order( $order );
    }

    /**
     * Action Scheduler callback: cancel the abandoned checkout-draft order if
     * no webhook has marked it paid in the meantime.
     *
     * @param int $order_id
     */
    public static function handle_delayed_abandon_check($order_id) {
        $order = wc_get_order(absint($order_id));
        if (!$order || $order->get_payment_method() !== 'linkmoney') {
            return;
        }

        // Webhook (or any other path) already moved it forward — leave it alone.
        if ($order->is_paid() || $order->has_status(['processing', 'completed', 'on-hold', 'cancelled', 'failed'])) {
            return;
        }

        $order->update_status('cancelled');
        $order->add_order_note(__('Customer abandoned the Link Money bank linking flow.', 'linkmoney'));
    }

    /**
     * Restore cart items from a failed order
     *
     * @param WC_Order $order The order to restore items from
     */
    private function restore_cart_from_order( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Don't restore cart if order is already successful
        if ( $order->has_status( ['processing', 'completed'] ) ) {
            return;
        }

        // Rebuild items only when the cart is empty, to avoid duplicates. On the
        // declined-payment path the cart is usually still populated, so this is
        // skipped — but coupons are still re-applied below either way.
        if ( WC()->cart->is_empty() ) {
            // Restore items from the failed order
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $quantity = $item->get_quantity();
                $variation_data = [];

                // Get variation attributes if this is a variation
                if ( $variation_id ) {
                    $variation = wc_get_product( $variation_id );
                    if ( $variation ) {
                        $variation_data = $variation->get_variation_attributes();
                    }
                }

                // Add item back to cart
                $cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_data );

                // Restore any custom item data
                if ( $cart_item_key && ! empty( $item->get_meta_data() ) ) {
                    foreach ( $item->get_meta_data() as $meta ) {
                        if ( ! empty( $meta->key ) && ! empty( $meta->value ) ) {
                            WC()->cart->cart_contents[ $cart_item_key ][ $meta->key ] = $meta->value;
                        }
                    }
                }
            }

            // Restore shipping method if available
            if ( $order->get_shipping_method() ) {
                WC()->session->set( 'chosen_shipping_methods', array( $order->get_shipping_method() ) );
            }
        }

        // Re-apply the order's coupons after the cart is populated, regardless of
        // whether items were rebuilt this call. On the declined-payment path the
        // cart is left populated and the item rebuild above is skipped, but the
        // customer's coupon must still be restored for the retry.
        $this->restore_coupons_from_order( $order );
    }

    /**
     * Stage coupons from a Link Money order for conditional restore on checkout.
     *
     * Coupons are only applied while Link Money remains the selected payment method.
     * If the customer switches gateways after returning, stashed coupons are removed.
     *
     * @param WC_Order $order The order coupons were applied on at checkout.
     */
    private function restore_coupons_from_order( $order ) {
        if ( ! $order instanceof WC_Order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        $this->stash_coupons_for_restore( $order );
        $this->sync_restored_coupons_with_payment_method();
    }

    /**
     * @param WC_Order $order
     */
    private function stash_coupons_for_restore( $order ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        if ( $order->get_billing_email() && WC()->customer ) {
            WC()->customer->set_billing_email( $order->get_billing_email() );
        }

        $coupon_codes = $order->get_coupon_codes();
        if ( empty( $coupon_codes ) ) {
            WC()->session->__unset( self::SESSION_PENDING_COUPON_CODES );
            return;
        }

        WC()->session->set( self::SESSION_PENDING_COUPON_CODES, array_values( $coupon_codes ) );
    }

    /**
     * Remember the draft order so the cart can be rebuilt if the customer returns
     * to checkout without the lm_restore_order param (e.g. the browser Back button
     * from the Link Money hosted page). The Store API empties the cart on order
     * placement, so without this the items would be lost.
     *
     * @param WC_Order $order
     */
    private function stash_order_for_restore( $order ) {
        if ( ! $order instanceof WC_Order || ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        WC()->session->set( self::SESSION_PENDING_RESTORE_ORDER, $order->get_id() );
    }

    /**
     * Apply or remove stashed coupons based on the checkout payment method selection.
     */
    public function sync_restored_coupons_with_payment_method() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
            return;
        }

        $pending_codes = WC()->session->get( self::SESSION_PENDING_COUPON_CODES );
        if ( empty( $pending_codes ) || ! is_array( $pending_codes ) ) {
            return;
        }

        $changed       = false;
        $still_pending = $pending_codes;

        if ( $this->is_linkmoney_chosen_payment_method() ) {
            foreach ( $pending_codes as $code ) {
                if ( WC()->cart->has_discount( $code ) ) {
                    continue;
                }

                // apply_coupon() returns false (and queues a notice) for an
                // invalid/expired/min-spend coupon. Drop it from the stash so we
                // don't re-attempt — and re-notice — it on every order-review AJAX.
                if ( WC()->cart->apply_coupon( $code ) ) {
                    $changed = true;
                } else {
                    $still_pending = array_values( array_diff( $still_pending, [ $code ] ) );
                }
            }
        } else {
            foreach ( $pending_codes as $code ) {
                if ( WC()->cart->has_discount( $code ) ) {
                    WC()->cart->remove_coupon( $code );
                    $changed = true;
                }
            }
        }

        if ( $still_pending !== $pending_codes ) {
            if ( empty( $still_pending ) ) {
                WC()->session->__unset( self::SESSION_PENDING_COUPON_CODES );
            } else {
                WC()->session->set( self::SESSION_PENDING_COUPON_CODES, $still_pending );
            }
        }

        // Recalculate only when we actually applied/removed something. On the
        // common case (Link Money selected, coupons already applied) this hook
        // fires on every order-review AJAX with no work to do, so skipping the
        // recalc avoids a redundant full totals/tax/shipping pass per request.
        if ( $changed ) {
            WC()->cart->calculate_totals();
        }
    }

    /**
     * Clear stashed coupon codes after checkout completes.
     */
    public function clear_pending_coupon_restore() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( self::SESSION_PENDING_COUPON_CODES );
            WC()->session->__unset( self::SESSION_PENDING_RESTORE_ORDER );
        }
    }

    /**
     * Whether Link Money is the customer's current checkout payment method.
     */
    private function is_linkmoney_chosen_payment_method(): bool {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return false;
        }

        $chosen = WC()->session->get( 'chosen_payment_method' );
        if ( $chosen ) {
            return $chosen === $this->id;
        }

        // No explicit selection yet — still in the Link Money return/retry flow.
        $pending = WC()->session->get( self::SESSION_PENDING_COUPON_CODES );
        return ! empty( $pending );
    }

    public function store_api_process_linkmoney( $context, $result ) {
        if ( $context->payment_method !== $this->id ) {
            return;
        }

        $order = $context->order;
    try {
        $this->apply_dedupe_guard( $order );
    } catch ( Exception $e ) {
        throw new \Exception( $e->getMessage() );
    }
        $selected = '';
        if ( is_array( $context->payment_data ) ) {
            foreach ( $context->payment_data as $item ) {
                if ( is_array( $item ) && isset( $item['key'], $item['value'] ) && $item['key'] === 'linkmoney_saved_account' ) {
                    $selected = (string) $item['value'];
                    break;
                }
            }

            if ( ! $selected && isset( $context->payment_data['linkmoney_saved_account'] ) ) {
                $selected = (string) $context->payment_data['linkmoney_saved_account'];
            }
        }

        if ( $selected === '__new' || $selected === '' ) {
            $session_data = $this->create_session( $order );
            if ( is_wp_error( $session_data ) || empty( $session_data['sessionUrl'] ) ) {
                throw new \Exception( __( 'Unable to connect to Link Money. Please try again.', 'linkmoney' ) );
            }
            $order->update_status( 'checkout-draft', __( 'Customer redirected to Link Money to link bank account.', 'linkmoney' ) );
            $order->save();
            $this->stash_coupons_for_restore( $order );
            $this->stash_order_for_restore( $order );
            $result->set_status( 'success' );
            $result->set_redirect_url( $session_data['sessionUrl'] );
            return;
        }

        $token = $this->get_access_token( 'Link-Payment' );
        if ( is_wp_error( $token ) ) {
            throw new \Exception( $token->get_error_message() );
        }

        // Same-order duplicate protection for the Blocks/Store-API charge path.
        // (1) Durable guard: a stored payment id / prior processing means this
        // order was already charged — never charge again unless it truly failed.
        $ever_processed = $order->get_meta( self::META_PAYMENT_ID ) || $order->get_meta( self::META_EVER_PROCESSING );
        if ( $ever_processed && ! $order->has_status( [ 'failed' ] ) ) {
            throw new \Exception( __( 'Duplicate Order Alert: Your order is currently being processed. No action is required at this time. Please do not resubmit the order.', 'linkmoney' ) );
        }
        // (2) Atomic in-flight claim BEFORE the slow payments call so two
        // overlapping submissions of the same order cannot both charge.
        if ( ! LinkMoney_Payment_Lock::claim( $order->get_id() ) ) {
            throw new \Exception( __( 'Duplicate Order Alert: Your order is currently being processed. No action is required at this time. Please do not resubmit the order.', 'linkmoney' ) );
        }

        $amount  = number_format( (float) $order->get_total(), 2, '.', '' );
        $payload = [
            'source'      => [ 'id' => get_user_meta( $order->get_user_id(), self::META_CUSTOMER_ID, true ), 'type' => 'CUSTOMER' ],
            'destination' => [ 'id' => $this->merchant_id, 'type' => 'MERCHANT' ],
            'amount'      => [ 'value' => $amount, 'currency' => $order->get_currency() ],
            'requestKey'  => LinkMoney_Payment_Lock::request_key( $order->get_id() ),
            'clientReferenceId' => $order->get_id(),
        ];

        $base = ( $this->environment === 'production' ) ? 'https://api.link.money' : 'https://api.link-sandbox.money';
        $resp = wp_remote_post(
            "{$base}/v1/payments",
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer {$token}",
                ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
            ]
        );

        $code = wp_remote_retrieve_response_code( $resp );
        $raw  = wp_remote_retrieve_body( $resp );

        if ( $code < 200 || $code >= 300 ) {
            LinkMoney_Payment_Lock::rotate_request_key( $order->get_id() );
            LinkMoney_Payment_Lock::release( $order->get_id() );
            throw new \Exception( __( 'Payment failed – please try again.', 'linkmoney' ) );
        }

        $body = json_decode( $raw, true );

        $immediateTerminal =
            ( isset( $body['paymentStatus'] ) && strtoupper( (string) $body['paymentStatus'] ) === 'TERMINAL_FAILED' );

        if ( empty( $body['paymentId'] ) || $immediateTerminal) {
            $order->update_status( 'failed', __( 'Link Money declined the payment.', 'linkmoney' ) );
            $order->save();
            LinkMoney_Payment_Lock::rotate_request_key( $order->get_id() );
            LinkMoney_Payment_Lock::release( $order->get_id() );
            throw new \Exception( __('Your payment was declined. Please try again with a different bank account.', 'linkmoney') );
            // wc_add_notice(
            //     __('Your payment was declined. Please try again with a different bank account.', 'linkmoney'),
            //     'error'
            // );
            // wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order->update_meta_data( self::META_PAYMENT_ID, sanitize_text_field( $body['paymentId'] ) );

        if ( isset( $body['paymentStatus'] ) && strtoupper( (string) $body['paymentStatus'] ) === 'PENDING' ) {
            $order->update_status( 'on-hold', __( 'Link Money Pending – Order on hold.', 'linkmoney' ) );
        } else if ( $order->has_status( ['failed'] ) && self::order_ever_was_processing( $order ) ) {
            $order->update_status( 'completed', __( 'Customer paying for already failed order, marking the order status completed.', 'linkmoney' ) );
        } else if ( ! $order->has_status( ['processing', 'completed'] ) ) {
            $order->set_status( 'processing', __( 'Link Money authorized – awaiting settlement.', 'linkmoney' ) );
            $order->update_meta_data( self::META_EVER_PROCESSING, true );
        }

        $order->save();

        // Clear the idempotency key AND release the slot now that the charge has
        // reached a terminal state. Clearing on success (not only on failure)
        // means a later, legitimate retry of this order — e.g. after an ACH
        // return marks it failed via webhook, and the customer links a different
        // bank — starts with a fresh key instead of being dedup-blocked by Link
        // Money as a duplicate of the original (now-returned) payment. Release
        // happens only AFTER the payment id is persisted, so the durable meta
        // guard already blocks any concurrent resubmission.
        LinkMoney_Payment_Lock::rotate_request_key( $order->get_id() );
        LinkMoney_Payment_Lock::release( $order->get_id() );

        // Trigger New Order and Order On Hold emails
        self::trigger_awaiting_payment_emails( $order );
        
        // Only clear cart on successful payment
        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->empty_cart();
        }

        $result->set_status( 'success' );
        $result->set_redirect_url( $order->get_checkout_order_received_url() );
    }

    
    private function apply_dedupe_guard( $order ) {
    $current_customer_id = $order->get_customer_id();
    $current_total = round( (float) $order->get_total(), 2 );
    
    if ( ! $current_customer_id ) {
        return;
    }
    $current_structure = [];
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        $sku = $product ? $product->get_sku() : $item->get_product_id();
        $current_structure[ $sku ] = $item->get_quantity();
    }
    ksort( $current_structure ); 
    $recent_orders = wc_get_orders([
        'customer_id' => $current_customer_id,
        'status'      => ['processing', 'completed', 'on-hold'],
        'date_after'  => date('Y-m-d H:i:s', strtotime('-1 hour')),
        'exclude'     => [$order->get_id()],
    ]);

    foreach ( $recent_orders as $recent ) {
        $recent_total = round( (float) $recent->get_total(), 2 );
        if ( $recent_total === $current_total ) {
            $recent_structure = [];
            foreach ( $recent->get_items() as $r_item ) {
                $r_prod = $r_item->get_product();
                $r_sku = $r_prod ? $r_prod->get_sku() : $r_item->get_product_id();
                $recent_structure[ $r_sku ] = $r_item->get_quantity();
            }
            ksort( $recent_structure );
            if ( $current_structure === $recent_structure ) {
                $order->update_status('cancelled', __( '' ));
                $order->add_order_note(
                    __( 'Link Money: Cancellation Reason – A duplicate order (#' .($recent->get_id() ) . ') for the same amount was detected within 1 hour.', 'linkmoney' ), $recent->get_id()
                );
                throw new Exception( __( 'Duplicate order detected: A payment for this amount was recently completed. Please check your email for the order details. No action is needed at this time.', 'linkmoney' ) );
            }
        }
    }
}

}
