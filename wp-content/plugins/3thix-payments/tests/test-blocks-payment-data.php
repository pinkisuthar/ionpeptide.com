<?php
/**
 * Standalone tests for the block-checkout (Store API) payment-data bridge and
 * the inline payment's session-token binding — run with:
 *   php wordpress-plugin/3thix-payments/tests/test-blocks-payment-data.php
 *
 * No WordPress needed: the WP/WC surface the gateway touches is stubbed below.
 *
 * Covers:
 *  1. posted_checkout_field() accepts BOTH key namings that reach
 *     process_payment: raw classic form fields AND the Store API's
 *     `payment_data_`-prefixed bridge used by some WooCommerce Blocks
 *     versions (classic wins when both exist).
 *  2. process_inline_payment() driven with Store API-prefixed POST keys:
 *     - session-token binding rejects a forged/foreign token;
 *     - a payment bound to a DIFFERENT externalOrderId is rejected;
 *     - an amount mismatch goes on-hold for review (never fulfilled);
 *     - the happy path (pending finalization) accepts and stays pending.
 */

error_reporting(E_ALL);

// ---- Minimal WP/WC stubs ----------------------------------------------------

define('ABSPATH', __DIR__ . '/');
define('THIX_PAYMENTS_VERSION', 'test');
define('THIX_PAYMENTS_FILE', __FILE__);

function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function esc_attr__($s, $d = null) { return $s; }
function add_action(...$a) {}
function add_filter(...$a) {}
function apply_filters($tag, $value) { return $value; }
function esc_url($u) { return $u; }
function esc_url_raw($u) { return $u; }
function rest_url($p = '') { return 'https://store.example.com/wp-json/' . $p; }
function admin_url($p = '') { return 'https://store.example.com/wp-admin/' . $p; }
function wp_create_nonce($a = '') { return 'nonce'; }
function wp_unslash($v) { return $v; }
function sanitize_text_field($v) { return trim(preg_replace('/[\r\n\t]+/', ' ', (string) $v)); }
function wp_generate_uuid4() { return 'uuid-' . uniqid(); }
function get_bloginfo($k = '') { return 'Test Store'; }
function get_theme_mod($k) { return 0; }
function get_site_icon_url() { return ''; }
function wp_json_encode($v) { return json_encode($v); }

$GLOBALS['__notices'] = [];
function wc_add_notice($msg, $type = 'notice') { $GLOBALS['__notices'][] = [$type, $msg]; }

class Fake_Session {
    public $data = [];
    public function get($k) { return isset($this->data[$k]) ? $this->data[$k] : null; }
    public function set($k, $v) { $this->data[$k] = $v; }
    public function __unset($k) { unset($this->data[$k]); }
}
$GLOBALS['__wc'] = new stdClass();
$GLOBALS['__wc']->session = new Fake_Session();
$GLOBALS['__wc']->cart = null;
$GLOBALS['__wc']->customer = null;
$GLOBALS['__wc']->payment_gateways = null;
function WC() { return $GLOBALS['__wc']; }

class WC_Payment_Gateway {
    public $id;
    public $method_title;
    public $method_description;
    public $has_fields;
    public $supports = [];
    public $title;
    public $description;
    public $enabled;
    public $form_fields = [];
    public $options = [];
    public function init_settings() {}
    public function get_option($key, $default = '') {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }
    public function get_description() { return (string) $this->description; }
    public function supports($feature) { return in_array($feature, $this->supports, true); }
    public function get_return_url($order = null) { return 'https://store.example.com/thanks'; }
}

class WC_Order {
    public $meta = [];
    public $status = 'pending';
    public $notes = [];
    public $txn = '';
    public $total;
    public $currency;
    public $id = 1;
    public $payment_method = 'thix';
    public function __construct($total, $currency = 'USD') { $this->total = $total; $this->currency = $currency; }
    public function get_total() { return $this->total; }
    public function get_currency() { return $this->currency; }
    public function get_id() { return $this->id; }
    public function get_payment_method() { return $this->payment_method; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function get_meta($k) { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
    public function update_status($status, $note = '') { $this->status = $status; $this->notes[] = $note; }
    public function add_order_note($note) { $this->notes[] = $note; }
    public function set_transaction_id($id) { $this->txn = $id; }
    public function save() {}
}

require __DIR__ . '/../includes/class-thix-api.php';
require __DIR__ . '/../includes/class-thix-gateway.php';

/**
 * Gateway with a scripted API so no network is touched: sync_payment()
 * returns whatever the test primed.
 */
class Test_Gateway extends Thix_Gateway {
    public $synced = null;
    public function __construct() {
        // Skip parent constructor entirely — we only exercise
        // process_inline_payment() + posted_checkout_field().
        $this->id = 'thix';
        $this->options = [
            'display'         => 'inline',
            'mode'            => 'card',
            'publishable_key' => 'thx_test_pk_' . str_repeat('a', 40),
            'api_base'        => 'https://pay.3thix.com/api',
        ];
    }
    public function api() {
        $g = $this;
        return new class($g) {
            private $g;
            public function __construct($g) { $this->g = $g; }
            public function sync_payment($id) { return $this->g->synced; }
        };
    }
    public function run_inline(WC_Order $order) {
        $m = new ReflectionMethod(Thix_Gateway::class, 'process_inline_payment');
        $m->setAccessible(true);
        return $m->invoke($this, $order);
    }
}

// ---- Tiny assertion helper ---------------------------------------------------

$failures = 0;
function check($ok, $label) {
    global $failures;
    if ($ok) {
        echo "ok    - $label\n";
    } else {
        $failures++;
        echo "FAIL  - $label\n";
    }
}

// ---- 1. posted_checkout_field key bridging -----------------------------------

$_POST = ['thix_payment_id' => 'pay_classic'];
check(Thix_Gateway::posted_checkout_field('thix_payment_id') === 'pay_classic', 'classic (raw) POST key is read');

$_POST = ['payment_data_thix_payment_id' => 'pay_blocks'];
check(Thix_Gateway::posted_checkout_field('thix_payment_id') === 'pay_blocks', 'Store API payment_data_-prefixed key is read');

$_POST = ['thix_payment_id' => 'pay_classic', 'payment_data_thix_payment_id' => 'pay_blocks'];
check(Thix_Gateway::posted_checkout_field('thix_payment_id') === 'pay_classic', 'raw key wins when both namings exist');

$_POST = [];
check(Thix_Gateway::posted_checkout_field('thix_payment_id') === '', 'absent field reads as empty string');

$_POST = ['payment_data_thix_eoid' => ['not', 'a', 'string']];
check(Thix_Gateway::posted_checkout_field('thix_eoid') === '', 'array values are rejected, not cast');

// ---- 2. process_inline_payment with Store API-prefixed keys -------------------

function fresh_gateway_session($token, $amount_cents) {
    $GLOBALS['__wc']->session = new Fake_Session();
    $GLOBALS['__wc']->session->set('thix_inline_eoid', ['token' => $token, 'amount' => $amount_cents]);
}

// (a) Token binding: the posted token must match the server session token.
fresh_gateway_session('wc-good-token', 4200);
$_POST = ['payment_data_thix_payment_id' => 'pay_1', 'payment_data_thix_eoid' => 'wc-forged-token'];
$g = new Test_Gateway();
$g->synced = ['status' => 'SUCCEEDED', 'externalOrderId' => 'wc-forged-token', 'amountCents' => 4200];
$order = new WC_Order(42.00);
$res = $g->run_inline($order);
check($res['result'] === 'failure', 'forged session token is rejected');
check($order->status === 'pending' && $order->txn === '', 'rejected order is never completed or stamped');

// (b) Payment binding: synced payment bound to a different externalOrderId.
fresh_gateway_session('wc-good-token', 4200);
$_POST = ['payment_data_thix_payment_id' => 'pay_2', 'payment_data_thix_eoid' => 'wc-good-token'];
$g = new Test_Gateway();
$g->synced = ['status' => 'SUCCEEDED', 'externalOrderId' => 'wc-other-order', 'amountCents' => 4200];
$order = new WC_Order(42.00);
$res = $g->run_inline($order);
check($res['result'] === 'failure', "a payment bound to another checkout's token is rejected");

// (c) Amount binding: charged amount != order total goes on-hold for review.
fresh_gateway_session('wc-good-token', 4200);
$_POST = ['payment_data_thix_payment_id' => 'pay_3', 'payment_data_thix_eoid' => 'wc-good-token'];
$g = new Test_Gateway();
$g->synced = ['status' => 'SUCCEEDED', 'externalOrderId' => 'wc-good-token', 'amountCents' => 100];
$order = new WC_Order(42.00);
$res = $g->run_inline($order);
check($res['result'] === 'success' && $order->status === 'on-hold' && $order->get_meta('_thix_hold_review') === '1',
    'amount mismatch is held for manual review, never silently fulfilled');

// (d) Happy path via Store API keys (finalization lag → stays pending, token
//     persisted for the callback/return reconcile, session token cleared).
fresh_gateway_session('wc-good-token', 4200);
$_POST = ['payment_data_thix_payment_id' => 'pay_4', 'payment_data_thix_eoid' => 'wc-good-token'];
$g = new Test_Gateway();
$g->synced = [
    'status' => 'PENDING',
    'externalOrderId' => 'wc-good-token',
    'amountCents' => 4200,
    'sourceCurrency' => 'usd',
    'sourceAmountCents' => 4200,
    'rail' => 'card',
];
$order = new WC_Order(42.00);
$res = $g->run_inline($order);
check($res['result'] === 'success', 'block-checkout POST keys reach the inline path (accepted)');
check($order->get_meta('_thix_eoid') === 'wc-good-token', 'client token persisted for callback/reconcile matching');
check($order->txn === 'pay_4', 'verified payment id recorded as the transaction id');
check($GLOBALS['__wc']->session->get('thix_inline_eoid') === null, 'session token cleared after use');

// (e) Missing payment id (buyer never paid) fails closed.
fresh_gateway_session('wc-good-token', 4200);
$_POST = [];
$g = new Test_Gateway();
$order = new WC_Order(42.00);
$res = $g->run_inline($order);
check($res['result'] === 'failure', 'missing payment id fails closed');

// (f) A direct inline card payment may never silently treat a EUR order total
// as USD. The on-ramp hosted flow owns EUR conversion instead.
fresh_gateway_session('wc-eur-token', 4200);
$_POST = ['payment_data_thix_payment_id' => 'pay_eur', 'payment_data_thix_eoid' => 'wc-eur-token'];
$g = new Test_Gateway();
$g->synced = ['status' => 'SUCCEEDED', 'externalOrderId' => 'wc-eur-token', 'amountCents' => 4200];
$order = new WC_Order(42.00, 'EUR');
$res = $g->run_inline($order);
check($res['result'] === 'failure' && $order->txn === '', 'EUR direct inline card payment is rejected before a charge can be accepted');

// ---- Result -------------------------------------------------------------------

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nall assertions passed\n";
