<?php
/**
 * Regression coverage for the optional accepted-card logos setting.
 *
 * Run:
 *   php wordpress-plugin/3thix-payments/tests/test-card-logos.php
 *
 * This is deliberately standalone: it loads the gateway with small
 * WordPress/WooCommerce shims and verifies the setting, classic gateway icon,
 * and Blocks iconHtml output without needing a WordPress install.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ABSPATH', __DIR__ . '/');

function __($value, $domain = null) { return $value; }
function esc_url($value) { return $value; }
function esc_url_raw($value) { return $value; }
function get_bloginfo($key = '') { return 'Test Store'; }
function get_theme_mod($key, $default = false) { return $default; }
function get_site_icon_url() { return ''; }
function rest_url($path = '') { return 'https://store.example.test/wp-json/' . $path; }
function admin_url($path = '') { return 'https://store.example.test/wp-admin/' . $path; }
function wp_create_nonce($action = '') { return 'nonce'; }
function get_woocommerce_currency() { return 'USD'; }

$GLOBALS['__thix_logo_filter_calls'] = [];
function apply_filters($tag, $value, ...$args) {
    $GLOBALS['__thix_logo_filter_calls'][] = [$tag, $value, $args];
    return $value;
}

if (!class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway {
        public $id = '';
        public $supports = [];
        public $form_fields = [];
        protected $settings = [];

        public function get_option($key, $default = '') {
            return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
        }

        public function get_form_fields() {
            return $this->form_fields;
        }

        public function supports($feature) {
            return in_array($feature, $this->supports, true);
        }
    }
}

require __DIR__ . '/../includes/class-thix-api.php';
require __DIR__ . '/../includes/class-thix-gateway.php';

class Logo_Test_Gateway extends Thix_Gateway {
    public function __construct(array $settings = []) {
        $this->id = 'thix';
        $this->supports = ['products', 'refunds'];
        $this->settings = $settings;
    }
}

$expected_icons = '<span class="thix-method-icons" style="display:inline-flex;align-items:center;white-space:nowrap;vertical-align:middle;">'
    . '<span style="display:inline-flex;align-items:center;justify-content:center;height:21px;min-width:34px;padding:0 6px;margin:0 0 0 4px;border:1px solid #e3e6ea;border-radius:4px;background:#fff;font-size:10px;font-weight:800;letter-spacing:.4px;line-height:1;vertical-align:middle;box-shadow:0 1px 1px rgba(16,24,40,.04);font-family:Arial,Helvetica,sans-serif;color:#1a1f71;font-style:italic;">VISA</span>'
    . '<span style="display:inline-flex;align-items:center;justify-content:center;height:21px;min-width:34px;padding:0 6px;margin:0 0 0 4px;border:1px solid #e3e6ea;border-radius:4px;background:#fff;font-size:10px;font-weight:800;letter-spacing:.4px;line-height:1;vertical-align:middle;box-shadow:0 1px 1px rgba(16,24,40,.04);font-family:Arial,Helvetica,sans-serif;padding:0 5px;"><svg width="26" height="16" viewBox="0 0 30 19" aria-hidden="true" style="display:block"><circle cx="12" cy="9.5" r="8" fill="#EB001B"></circle><circle cx="18" cy="9.5" r="8" fill="#F79E1B" fill-opacity=".85"></circle></svg></span>'
    . '<span style="display:inline-flex;align-items:center;justify-content:center;height:21px;min-width:34px;padding:0 6px;margin:0 0 0 4px;border:1px solid #e3e6ea;border-radius:4px;background:#fff;font-size:10px;font-weight:800;letter-spacing:.4px;line-height:1;vertical-align:middle;box-shadow:0 1px 1px rgba(16,24,40,.04);font-family:Arial,Helvetica,sans-serif;background:#1f72cd;border-color:#1f72cd;color:#fff;">AMEX</span>'
    . '<span style="display:inline-flex;align-items:center;justify-content:center;height:21px;min-width:34px;padding:0 6px;margin:0 0 0 4px;border:1px solid #e3e6ea;border-radius:4px;background:#fff;font-size:10px;font-weight:800;letter-spacing:.4px;line-height:1;vertical-align:middle;box-shadow:0 1px 1px rgba(16,24,40,.04);font-family:Arial,Helvetica,sans-serif;color:#e06b1f;">DISCOVER</span>'
    . '</span>';

$failures = 0;
function check($label, $condition) {
    global $failures;
    if ($condition) {
        echo "ok    - {$label}\n";
    } else {
        $failures++;
        echo "FAIL  - {$label}\n";
    }
}

$settings_gateway = new Logo_Test_Gateway();
$settings_gateway->init_form_fields();
$field = $settings_gateway->get_form_fields()['show_card_logos'] ?? null;
check('show_card_logos setting exists', is_array($field));
check('show_card_logos is a checkbox', is_array($field) && ($field['type'] ?? null) === 'checkbox');
check('show_card_logos defaults to yes', is_array($field) && ($field['default'] ?? null) === 'yes');

$GLOBALS['__thix_logo_filter_calls'] = [];
$default_icon = (new Logo_Test_Gateway())->get_icon();
check('missing saved setting keeps logos enabled by default', $default_icon === $expected_icons);
check('default icon passes through woocommerce_gateway_icon filter', count($GLOBALS['__thix_logo_filter_calls']) === 1
    && $GLOBALS['__thix_logo_filter_calls'][0][0] === 'woocommerce_gateway_icon'
    && ($GLOBALS['__thix_logo_filter_calls'][0][2][0] ?? null) === 'thix');

$GLOBALS['__thix_logo_filter_calls'] = [];
$on_icon = (new Logo_Test_Gateway(['show_card_logos' => 'yes']))->get_icon();
check('explicit yes retains the exact existing logo markup', $on_icon === $expected_icons);
check('explicit yes retains the existing icon filter', count($GLOBALS['__thix_logo_filter_calls']) === 1
    && $GLOBALS['__thix_logo_filter_calls'][0][0] === 'woocommerce_gateway_icon');

$GLOBALS['__thix_logo_filter_calls'] = [];
$off_icon = (new Logo_Test_Gateway(['show_card_logos' => 'no']))->get_icon();
check('explicit no returns an empty icon', $off_icon === '');
check('disabled logos do not invoke the icon filter', count($GLOBALS['__thix_logo_filter_calls']) === 0);

$GLOBALS['__thix_logo_filter_calls'] = [];
$default_blocks = (new Logo_Test_Gateway())->blocks_payment_method_data();
check('Blocks iconHtml uses the default enabled logos', ($default_blocks['iconHtml'] ?? null) === $expected_icons);

$GLOBALS['__thix_logo_filter_calls'] = [];
$off_blocks = (new Logo_Test_Gateway(['show_card_logos' => 'no']))->blocks_payment_method_data();
check('Blocks iconHtml is empty when logos are disabled', ($off_blocks['iconHtml'] ?? null) === '');

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nall assertions passed\n";