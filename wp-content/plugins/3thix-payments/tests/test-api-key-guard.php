<?php
/**
 * Focused regression coverage for complete, environment-bound checkout keys.
 *
 * Run:
 *   php wordpress-plugin/3thix-payments/tests/test-api-key-guard.php
 *
 * This is deliberately standalone: it exercises the settings saver and the
 * buyer-facing runtime guards without requiring WordPress or WooCommerce.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ABSPATH', __DIR__ . '/');

function __($value, $domain = null) { return $value; }
function esc_url($value) { return $value; }
function esc_url_raw($value) { return $value; }
function esc_html($value) { return $value; }
function esc_attr($value) { return $value; }
function rest_url($path = '') { return 'https://store.example.test/wp-json/' . $path; }
function add_action(...$args) {}
function add_filter(...$args) {}
function apply_filters($tag, $value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function get_bloginfo($key = '') { return 'Test Store'; }
function get_theme_mod($key, $default = false) { return $default; }
function get_site_icon_url() { return ''; }
function admin_url($path = '') { return 'https://store.example.test/wp-admin/' . $path; }
function wp_create_nonce($action = '') { return 'nonce'; }
function wc_add_notice($message, $type = 'notice') {
    $GLOBALS['__notices'][] = [$type, $message];
}
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim((string) $value); }
function esc_html__($value, $domain = null) { return $value; }
function esc_attr__($value, $domain = null) { return $value; }

if (!class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway {
        public $id = '';
        public $supports = [];
        public $form_fields = [];
        public $title = '';
        public $description = '';
        public $enabled = 'no';
        protected $settings = [];
        protected $posted = [];
        protected $errors = [];

        public function init_settings() {}
        public function get_option($key, $default = '') {
            return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
        }
        public function get_form_fields() { return $this->form_fields; }
        public function get_post_data() { return $this->posted; }
        public function set_post_data(array $data) { $this->posted = $data; }
        public function get_field_type($field) { return isset($field['type']) ? $field['type'] : 'text'; }
        public function get_field_key($key) { return 'woocommerce_thix_' . $key; }
        public function get_field_value($key, $field, $post_data = []) {
            $field_key = $this->get_field_key($key);
            $value = array_key_exists($field_key, $post_data) ? $post_data[$field_key] : null;
            $type = $this->get_field_type($field);
            if ($type === 'checkbox') {
                return $value === null ? 'no' : 'yes';
            }
            return $value === null ? '' : trim(stripslashes((string) $value));
        }
        public function add_error($message) { $this->errors[] = $message; }
        public function get_errors() { return $this->errors; }
        public function get_return_url($order = null) { return 'https://store.example.test/thanks'; }
        public function supports($feature) { return in_array($feature, $this->supports, true); }
        public function process_admin_options() {
            $this->init_settings();
            foreach ($this->get_form_fields() as $key => $field) {
                if ($this->get_field_type($field) !== 'title') {
                    $this->settings[$key] = $this->get_field_value($key, $field, $this->get_post_data());
                }
            }
            return true;
        }
    }
}

require __DIR__ . '/../includes/class-thix-api.php';
require __DIR__ . '/../includes/class-thix-gateway.php';

function key_for($environment) {
    return 'thx_' . $environment . '_pk_' . str_repeat('a', 40);
}

class Guard_Test_Gateway extends Thix_Gateway {
    public function __construct(array $settings = []) {
        $this->id = 'thix';
        $this->supports = ['products', 'refunds'];
        $this->settings = $settings;
        $this->form_fields = [
            'section_connection' => ['type' => 'title'],
            'enabled'           => ['type' => 'checkbox'],
            'environment'       => ['type' => 'select'],
            'publishable_key'   => ['type' => 'text'],
            'secret_key'        => ['type' => 'password'],
        ];
        $this->set_post_data([]);
    }

    public function set_saved_settings(array $settings) {
        $this->settings = $settings;
    }

    public function saved_settings() {
        return $this->settings;
    }
}

class Guard_Test_Order {
    public function get_currency() { return 'USD'; }
    public function get_total() { return 10.00; }
}

$failures = 0;
function check($condition, $label) {
    global $failures;
    if ($condition) {
        echo "ok    - {$label}\n";
    } else {
        $failures++;
        echo "FAIL  - {$label}\n";
    }
}

$test_public = key_for('test');
$test_secret = 'server-secret-display-hint';
$live_public = key_for('live');

// ---- Key format and checkout_url --------------------------------------------
check(Thix_Api::is_valid_publishable_key($test_public, 'test'), 'minted publishable key with a 40-hex suffix is valid');
check(!Thix_Api::is_valid_publishable_key('thx_test_pk_' . str_repeat('a', 12), 'test'), 'shortened publishable key is rejected');
check(!Thix_Api::is_valid_publishable_key('thx_test_' . str_repeat('b', 40), 'test'), 'secret key pasted into the publishable field is rejected');
check(!Thix_Api::is_valid_publishable_key('thx_test_pk_' . str_repeat('a', 40), 'live'), 'wrong-environment publishable key is rejected');

$api = new Thix_Api('https://pay.3thix.com/api', $test_secret, 'signing');
$secret_property = new ReflectionProperty(Thix_Api::class, 'secret_key');
$secret_property->setAccessible(true);
check($secret_property->getValue($api) === $test_secret, 'API constructor retains the existing secret-key behavior');
$url = $api->checkout_url([
    'publishable_key' => $test_public,
    'environment'     => 'test',
    'amount_cents'    => 1000,
    'order_id'        => 'wc-1',
]);
$checkout_errors = [];
foreach ([
    'shortened' => 'thx_test_pk_' . str_repeat('a', 12),
    'wrong env' => $live_public,
] as $label => $bad_key) {
    try {
        $api->checkout_url([
            'publishable_key' => $bad_key,
            'environment'     => 'test',
            'amount_cents'    => 1000,
            'order_id'        => 'wc-1',
        ]);
    } catch (InvalidArgumentException $error) {
        $checkout_errors[$label] = $error->getMessage();
    }
}
check(strpos($url, rawurlencode($test_public)) !== false, 'checkout_url emits the complete publishable key');
check(count($checkout_errors) === 2, 'checkout_url rejects shortened and wrong-environment keys when environment is explicit');
check(strpos(implode(' ', $checkout_errors), 'thx_') === false, 'checkout_url errors do not echo key material');

// ---- Atomic settings save ----------------------------------------------------
$original = [
    'enabled'         => 'yes',
    'environment'     => 'test',
    'publishable_key' => $test_public,
    'secret_key'      => $test_secret,
];
$gateway = new Guard_Test_Gateway($original);
$gateway->set_post_data([
    'woocommerce_thix_enabled'         => '1',
    'woocommerce_thix_environment'     => 'live',
    'woocommerce_thix_publishable_key' => $test_public,
    'woocommerce_thix_secret_key'      => $test_secret,
]);
$saved = $gateway->process_admin_options();
check($saved === false, 'environment change with old-environment keys is rejected');
check($gateway->saved_settings() === $original, 'failed environment change leaves every setting unchanged');

$gateway->set_post_data([
    'woocommerce_thix_enabled'         => '1',
    'woocommerce_thix_environment'     => 'test',
    'woocommerce_thix_publishable_key' => $test_public,
    'woocommerce_thix_secret_key'      => 'thx_test_' . str_repeat('b', 12) . '••••',
]);
$saved = $gateway->process_admin_options();
check($saved === true, 'secret key settings are not newly rejected during publishable-key validation');
check($gateway->saved_settings()['secret_key'] === 'thx_test_' . str_repeat('b', 12) . '••••',
    'secret key value is preserved when the publishable key is valid');

// Empty keys are allowed while the gateway is disabled, so an admin can save
// the disabled state and finish configuration later.
$disabled = new Guard_Test_Gateway([
    'enabled' => 'yes',
    'environment' => 'test',
    'publishable_key' => $test_public,
    'secret_key' => $test_secret,
]);
$disabled->set_post_data([
    'woocommerce_thix_enabled' => null,
    'woocommerce_thix_environment' => 'test',
    'woocommerce_thix_publishable_key' => '',
    'woocommerce_thix_secret_key' => '',
]);
check($disabled->process_admin_options() === true, 'disabled plugin can save without keys');

// ---- Buyer-facing runtime guards --------------------------------------------
$invalid = new Guard_Test_Gateway([
    'enabled' => 'yes',
    'environment' => 'test',
    'api_base' => 'https://pay.3thix.com/api',
    'publishable_key' => 'thx_test_pk_' . str_repeat('a', 12),
    'secret_key' => $test_secret,
    'display' => 'redirect',
    'mode' => 'card',
]);
check($invalid->checkout_runtime_ready() === false, 'runtime rejects a shortened publishable key');
$blocks_data = $invalid->blocks_payment_method_data();
check($blocks_data['publishableKey'] === '' && $blocks_data['inline'] === false && $blocks_data['embedSrc'] === '',
    'Blocks data with an invalid key cannot launch checkout');

$malformed_secret = new Guard_Test_Gateway([
    'enabled' => 'yes',
    'environment' => 'test',
    'api_base' => 'https://pay.3thix.com/api',
    'publishable_key' => $test_public,
    'secret_key' => 'thx_test_' . str_repeat('b', 12) . '••••',
]);
check($malformed_secret->checkout_runtime_ready() === true, 'runtime does not newly reject a malformed secret key');

// The process/receipt/inline guards all fail before they can build a checkout
// URL or call the API. No remote stubs are needed for these early exits.
function wc_get_order($order_id) { return new Guard_Test_Order(); }
$GLOBALS['__notices'] = [];
check($invalid->process_payment(1)['result'] === 'failure', 'process_payment blocks a shortened checkout key');
ob_start();
$invalid->receipt_page(1);
$receipt_output = ob_get_clean();
check(strpos($receipt_output, 'not fully configured') !== false, 'receipt_page blocks a shortened checkout key');
$inline_method = new ReflectionMethod(Thix_Gateway::class, 'process_inline_payment');
$inline_method->setAccessible(true);
check($inline_method->invoke($invalid, new Guard_Test_Order())['result'] === 'failure', 'inline payment blocks a shortened checkout key');

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nall assertions passed\n";