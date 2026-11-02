<?php
/**
 * Regression test for Thix_Api::checkout_url() URL encoding.
 *
 * Task #73 shipped an UNENCODED hosted-checkout URL because WordPress
 * add_query_arg() appends values raw: a redirect URL containing `&` split the
 * query string (its nested `key=wc_order_…` overrode the publishable key) and
 * a `+` in the buyer email decoded to a space. Fixed in 1.7.12 by building the
 * query with http_build_query(..., PHP_QUERY_RFC3986).
 *
 * This test runs with the plain nix php83 binary (no WordPress) and fails if:
 *   - checkout_url() ever emits a raw `&` or `+` inside a value, or
 *   - user-derived values (redirect, email, brand, logo, accent) stop
 *     round-tripping intact through RFC 3986 decoding, or
 *   - anyone reverts checkout_url() to add_query_arg for user-derived values.
 *
 * Run: php wordpress-plugin/3thix-payments/tests/test-checkout-url.php
 */

error_reporting(E_ALL);

// Minimal WordPress shim so the plugin file loads outside WP. If checkout_url
// is ever reverted to add_query_arg, this stub reproduces WP's real behavior
// (urlencode=false — values appended RAW) so the encoding assertions below
// catch the regression instead of masking it.
define('ABSPATH', __DIR__ . '/');
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        $pairs = [];
        foreach ($args as $k => $v) {
            $pairs[] = $k . '=' . $v; // RAW, like WP with urlencode=false
        }
        return $url . $sep . implode('&', $pairs);
    }
}

require __DIR__ . '/../includes/class-thix-api.php';

$failures = 0;
function check($label, $cond) {
    global $failures;
    if ($cond) {
        echo "ok    - {$label}\n";
    } else {
        $failures++;
        echo "FAIL  - {$label}\n";
    }
}

// Hostile-but-realistic inputs: the redirect is a WooCommerce return URL whose
// own query string contains & and a key= param; the email contains a +.
$redirect = 'https://store.example.com/checkout/order-received/123/?key=wc_order_AbC123&utm_source=news&cmp=a+b';
$email    = 'buyer+tag@example.com';
$brand    = 'Röck & Co / “Store” + Friends';
$logo     = 'https://cdn.example.com/logo.png?v=1&size=2x';
$accent   = '#0af';

$api = new Thix_Api('pay.3thix.com', 'thx_test_secret', 'signing');
$publishable_key = 'thx_test_pk_' . str_repeat('b', 40);
$url = $api->checkout_url([
    'publishable_key' => $publishable_key,
    'environment'     => 'test',
    'amount_cents'    => 1999,
    'currency'        => 'EUR',
    'order_id'        => 'wc-123',
    'email'           => $email,
    'redirect'        => $redirect,
    'brand'           => $brand,
    'logo'            => $logo,
    'accent'          => $accent,
]);

echo "URL: {$url}\n\n";

$qpos  = strpos($url, '?');
check('URL has a query string', $qpos !== false);
$query = substr($url, $qpos + 1);

// 1) No raw & or + inside any VALUE: every value must be fully RFC 3986
//    percent-encoded (only unreserved chars and % remain).
$rawPairs = explode('&', $query);
$badValue = null;
foreach ($rawPairs as $pair) {
    $eq = strpos($pair, '=');
    check("pair '{$pair}' has key=value shape", $eq !== false);
    $v = $eq === false ? '' : substr($pair, $eq + 1);
    if (!preg_match('/^[A-Za-z0-9._~%-]*$/', $v)) {
        $badValue = $pair;
    }
}
check('every value is RFC3986-encoded (no raw &, +, space, #, =, etc.)', $badValue === null);
if ($badValue !== null) {
    echo "        offending pair: {$badValue}\n";
}

// 2) Parse the query the way the hosted checkout does and assert each
//    user-derived value round-trips byte-for-byte.
$parsed = [];
foreach ($rawPairs as $pair) {
    $eq = strpos($pair, '=');
    if ($eq === false) { continue; }
    $parsed[rawurldecode(substr($pair, 0, $eq))] = rawurldecode(substr($pair, $eq + 1));
}
check('publishable key survives (not overridden by nested key=wc_order_…)', ($parsed['key'] ?? null) === $publishable_key);
check('redirect round-trips intact', ($parsed['redirect'] ?? null) === $redirect);
check('email round-trips intact (+ not lost to a space)', ($parsed['email'] ?? null) === $email);
check('brand round-trips intact', ($parsed['brand'] ?? null) === $brand);
check('logo round-trips intact', ($parsed['logo'] ?? null) === $logo);
check('accent round-trips intact', ($parsed['accent'] ?? null) === $accent);
check('amount preserved', ($parsed['amount'] ?? null) === '1999');
check('source currency preserved', ($parsed['currency'] ?? null) === 'eur');
check('order preserved', ($parsed['order'] ?? null) === 'wc-123');

$invalid_rejected = false;
try {
    $api->checkout_url([
        'publishable_key' => $publishable_key,
        'environment'     => 'test',
        'amount_cents'    => 1999,
        'currency'        => 'GBP',
        'order_id'        => 'wc-124',
    ]);
} catch (InvalidArgumentException $e) {
    $invalid_rejected = true;
}
check('unsupported source currency is rejected rather than silently defaulting to USD', $invalid_rejected);

// 3) A `+` must NOT appear as an encoding for space anywhere (RFC 3986 uses
//    %20; a literal + would decode to a space on the server).
check('no raw + anywhere in the query', strpos($query, '+') === false);

// 4) Static guard: checkout_url()'s own body must not call add_query_arg for
//    user-derived values. (add_query_arg remains fine elsewhere for
//    known-safe values, e.g. lookup_payment's id/limit.)
$src = file_get_contents(__DIR__ . '/../includes/class-thix-api.php');
$m = null;
preg_match('/function\s+checkout_url\s*\([^)]*\)\s*\{(.*?)\n    \}/s', $src, $m);
check('found checkout_url body for static inspection', !empty($m[1]));
$body = $m[1] ?? '';
// Strip comments so a mention in a comment doesn't false-positive.
$bodyNoComments = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $body);
check('checkout_url does not use add_query_arg', strpos($bodyNoComments, 'add_query_arg') === false);
check('checkout_url uses PHP_QUERY_RFC3986 encoding', strpos($bodyNoComments, 'PHP_QUERY_RFC3986') !== false);

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "all assertions passed\n";
