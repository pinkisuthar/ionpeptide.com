# Link Money API Caching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cache the Link Money OAuth token and per-customer saved-accounts list via WordPress transients, invalidate the saved-accounts cache when the customer returns from Link, and stop the WooCommerce Blocks integration from making live API calls in the block editor.

**Architecture:** Two transient caches keyed for safety (token: env + scope + client_id hash; accounts: customer_id hash). Invalidation hooks at the two return-from-Link entry points. Admin/non-AJAX guard in the Blocks `get_payment_method_script_handles()` method. All behavior verified against the existing PHPUnit + `MockHttpTrait` test infrastructure (which returns a 400 "unmocked-request" for any unmocked HTTP call — perfect for proving cache hits).

**Tech Stack:** PHP 7.4+, WordPress transients (`set_transient` / `get_transient` / `delete_transient`), WooCommerce 6+, PHPUnit 9 with `WP_UnitTestCase`.

**Spec:** `docs/superpowers/specs/2026-04-24-link-api-caching-design.md`

---

## File Map

| File | Change |
|---|---|
| `includes/class-wc-gateway-linkmoney.php` | Add 3 constants. Wrap `get_access_token()` with transient cache. Wrap `get_saved_accounts()` with transient cache. Add `delete_saved_accounts_cache()` helper. Call helper in `handle_wc_api_callback()` and `linkmoney_handle_return()`. |
| `includes/class-wc-gateway-linkmoney-blocks.php` | Add `is_admin() && ! wp_doing_ajax()` guard around the `get_saved_accounts()` call inside `get_payment_method_script_handles()`. |
| `tests/Gateway/TokenCacheTest.php` | NEW — 5 tests for token caching behavior. |
| `tests/Gateway/SavedAccountsTest.php` | EXTEND — +3 tests for accounts caching behavior. |
| `tests/Gateway/CallbackTest.php` | EXTEND — +1 test for cache invalidation. |
| `tests/Gateway/HandleReturnTest.php` | EXTEND — +1 test for cache invalidation. |
| `tests/Gateway/BlocksAdminGuardTest.php` | NEW — 2 tests for the admin/non-AJAX guard. |

---

## Conventions Used Throughout

**Run a single test:**
```bash
vendor/bin/phpunit --filter test_method_name tests/Gateway/SomeTest.php
```

**Run a full test class:**
```bash
vendor/bin/phpunit tests/Gateway/SomeTest.php
```

**Run the entire suite:**
```bash
vendor/bin/phpunit
```

**Cache key formats** (used throughout the plan, MUST match exactly):
- Token: `linkmoney_token_{environment}_{scope}_{md5(client_id)}`
- Accounts: `linkmoney_accounts_{md5(customer_id)}`

**Standard test settings** (already used by existing tests — every test relies on these):
```php
update_option( 'woocommerce_linkmoney_settings', [
    'enabled'       => 'yes',
    'environment'   => 'sandbox',
    'client_id'     => 'test-client-id',
    'client_secret' => 'test-client-secret',
    'merchant_id'   => 'test-merchant-id',
] );
```

With those values: `md5('test-client-id') = '0fb3eba1f3c4083036e6f9555de14e6c'`.
So a token transient key in tests will look like:
`linkmoney_token_sandbox_Link-Core_0fb3eba1f3c4083036e6f9555de14e6c`

`WP_UnitTestCase` wraps each test in a DB transaction that is rolled back on tearDown — transients in the options table are automatically cleaned between tests. No manual cache flush needed.

---

## Task 1: Add cache constants and token caching

**Files:**
- Create: `tests/Gateway/TokenCacheTest.php`
- Modify: `includes/class-wc-gateway-linkmoney.php` (add constants after line 35; modify `get_access_token()` at lines 249-271)

- [ ] **Step 1.1: Create the failing test file**

Create `tests/Gateway/TokenCacheTest.php` with the following content:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;

/**
 * Tests for token caching in WC_Gateway_LinkMoney::get_access_token().
 *
 * Caching behavior is observed indirectly: the only public path that exercises
 * get_access_token() in a self-contained way is get_saved_accounts() (which
 * uses scope=Link-Core) and process_saved_payment() (Link-Payment scope).
 * For scope-isolation we use reflection to call the protected method directly.
 *
 * Cache hits are proven by NOT registering a token mock — MockHttpTrait returns
 * 400 "unmocked-request" for unmatched URLs, so a leaked HTTP call would make
 * get_access_token return WP_Error and get_saved_accounts return [].
 *
 * 5 tests total.
 */
class TokenCacheTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    /** @var \WC_Gateway_LinkMoney */
    private \WC_Gateway_LinkMoney $gateway;

    public function setUp(): void {
        parent::setUp();
        $this->setup_mock_http();

        update_option( 'woocommerce_linkmoney_settings', [
            'enabled'       => 'yes',
            'environment'   => 'sandbox',
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'merchant_id'   => 'test-merchant-id',
        ] );

        $this->gateway = new \WC_Gateway_LinkMoney();
    }

    public function tearDown(): void {
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * Build the expected token cache key for the standard test settings.
     */
    private function token_key( string $environment, string $scope ): string {
        return 'linkmoney_token_' . $environment . '_' . $scope . '_' . md5( 'test-client-id' );
    }

    /**
     * Invoke the protected get_access_token() method via reflection.
     */
    private function invoke_get_access_token( string $scope ): string {
        $method = new \ReflectionMethod( $this->gateway, 'get_access_token' );
        $method->setAccessible( true );
        $result = $method->invoke( $this->gateway, $scope );
        $this->assertIsString( $result, 'Expected token string, got: ' . var_export( $result, true ) );
        return $result;
    }

    /**
     * @test
     * After a successful token fetch, the token MUST be stored in a transient
     * keyed by environment + scope + md5(client_id).
     */
    public function test_token_cached_after_first_call(): void {
        $this->mock_token_response( 'token-from-api' );
        $this->mock_accounts_response();

        $this->gateway->get_saved_accounts( 'cust-test-001' );

        $value = get_transient( $this->token_key( 'sandbox', 'Link-Core' ) );

        $this->assertSame( 'token-from-api', $value );
    }

    /**
     * @test
     * If a token transient exists, get_access_token() MUST NOT make an HTTP call.
     * We register only the accounts mock; if the token endpoint is hit, the
     * MockHttpTrait fallback returns 400 and get_saved_accounts returns [].
     */
    public function test_token_cache_hit_skips_http(): void {
        set_transient( $this->token_key( 'sandbox', 'Link-Core' ), 'cached-token-xyz', 60 * 50 );

        // No token mock registered — only accounts.
        $this->mock_accounts_response();

        $accounts = $this->gateway->get_saved_accounts( 'cust-test-002' );

        $this->assertNotEmpty( $accounts, 'Cache hit failed — token endpoint was hit and returned an error.' );
    }

    /**
     * @test
     * The cache key MUST include the environment so a sandbox token is never
     * served to a production-environment call.
     */
    public function test_token_cache_key_includes_environment(): void {
        $this->mock_token_response( 'sandbox-token' );
        $this->mock_accounts_response();

        $this->gateway->get_saved_accounts( 'cust-test-003' );

        $this->assertSame( 'sandbox-token', get_transient( $this->token_key( 'sandbox', 'Link-Core' ) ) );
        $this->assertFalse( get_transient( $this->token_key( 'production', 'Link-Core' ) ) );
    }

    /**
     * @test
     * The cache key MUST include the scope so a Link-Core token is not served
     * to a Link-Payment caller.
     */
    public function test_token_cache_key_includes_scope(): void {
        $this->mock_token_response( 'scope-test-token' );

        $this->invoke_get_access_token( 'Link-Core' );
        $this->invoke_get_access_token( 'Link-Payment' );

        $core_key    = $this->token_key( 'sandbox', 'Link-Core' );
        $payment_key = $this->token_key( 'sandbox', 'Link-Payment' );

        $this->assertSame( 'scope-test-token', get_transient( $core_key ) );
        $this->assertSame( 'scope-test-token', get_transient( $payment_key ) );
        $this->assertNotSame( $core_key, $payment_key, 'Scopes must produce distinct cache keys.' );
    }

    /**
     * @test
     * A failed token fetch (non-200) MUST NOT write the transient.
     */
    public function test_token_error_not_cached(): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/tokens',
            401,
            [ 'error' => 'invalid_client' ]
        );

        // get_saved_accounts catches the WP_Error and returns [].
        $this->gateway->get_saved_accounts( 'cust-test-004' );

        $this->assertFalse( get_transient( $this->token_key( 'sandbox', 'Link-Core' ) ), 'Failed token fetch must not be cached.' );
    }
}
```

- [ ] **Step 1.2: Run the new tests to confirm they fail**

Run:
```bash
vendor/bin/phpunit tests/Gateway/TokenCacheTest.php
```

Expected: All 5 tests FAIL (mostly because no transient is being written by the current implementation).

- [ ] **Step 1.3: Add cache constants to the gateway class**

In `includes/class-wc-gateway-linkmoney.php`, locate the constants block at lines 33-35:

```php
    const META_CUSTOMER_ID = '_linkmoney_customer_id';
    const META_PAYMENT_ID = '_linkmoney_payment_id';
    const META_EVER_PROCESSING = '_linkmoney_ever_processing';
```

Add three new constants immediately after, so the block reads:

```php
    const META_CUSTOMER_ID = '_linkmoney_customer_id';
    const META_PAYMENT_ID = '_linkmoney_payment_id';
    const META_EVER_PROCESSING = '_linkmoney_ever_processing';

    const TOKEN_CACHE_PREFIX    = 'linkmoney_token_';
    const ACCOUNTS_CACHE_PREFIX = 'linkmoney_accounts_';
    const TOKEN_CACHE_TTL       = 50 * MINUTE_IN_SECONDS;
    const ACCOUNTS_CACHE_TTL    = 10 * MINUTE_IN_SECONDS;
```

- [ ] **Step 1.4: Add the token caching logic to `get_access_token()`**

In `includes/class-wc-gateway-linkmoney.php`, replace the entire `get_access_token()` method (currently lines 249-271):

```php
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
```

- [ ] **Step 1.5: Run the new tests to confirm they pass**

Run:
```bash
vendor/bin/phpunit tests/Gateway/TokenCacheTest.php
```

Expected: All 5 tests PASS.

- [ ] **Step 1.6: Run the full test suite to confirm no regressions**

Run:
```bash
vendor/bin/phpunit
```

Expected: All tests pass (60 total: 55 existing + 5 new).

- [ ] **Step 1.7: Commit**

```bash
git add includes/class-wc-gateway-linkmoney.php tests/Gateway/TokenCacheTest.php
git commit -m "feat: cache OAuth access token in transient

Cache key: linkmoney_token_{env}_{scope}_{md5(client_id)}
TTL: min(50min, expires_in - 60s).
Errors are not cached. Verified by 5 new tests in TokenCacheTest.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Saved-accounts caching

**Files:**
- Modify: `includes/class-wc-gateway-linkmoney.php` (modify `get_saved_accounts()` at lines 430-457; add `delete_saved_accounts_cache()` helper after `get_saved_accounts()`)
- Modify: `tests/Gateway/SavedAccountsTest.php` (add 3 tests after the existing 3)

- [ ] **Step 2.1: Add 3 failing tests to `SavedAccountsTest.php`**

Append these 3 test methods to the class in `tests/Gateway/SavedAccountsTest.php` (after `test_api_failure()`, before the closing `}`):

```php
    /**
     * @test
     * A successful saved-accounts fetch MUST be stored in a transient keyed
     * by md5(customer_id), holding the normalized account array.
     */
    public function test_accounts_cached_after_first_call(): void {
        $this->mock_token_response();
        $this->mock_accounts_response();

        $accounts = $this->gateway->get_saved_accounts( 'cust-test-cache-001' );

        $key    = 'linkmoney_accounts_' . md5( 'cust-test-cache-001' );
        $cached = get_transient( $key );

        $this->assertNotFalse( $cached, 'Accounts should be cached after first call.' );
        $this->assertEquals( $accounts, $cached, 'Cached value must match returned value.' );
    }

    /**
     * @test
     * If an accounts transient exists, get_saved_accounts() MUST NOT make any
     * HTTP call. We register no mocks; if either token or accounts endpoints
     * are hit, MockHttpTrait returns 400 and the result becomes [].
     */
    public function test_accounts_cache_hit_skips_http(): void {
        $cached_accounts = [
            [
                'accountId' => 'acct-from-cache',
                'bankName'  => 'Cached Bank',
                'last4'     => '4321',
                'logoUrl'   => 'https://example.com/cached.png',
            ],
        ];
        $key = 'linkmoney_accounts_' . md5( 'cust-test-cache-002' );
        set_transient( $key, $cached_accounts, 60 * 10 );

        // No mocks registered.
        $accounts = $this->gateway->get_saved_accounts( 'cust-test-cache-002' );

        $this->assertEquals( $cached_accounts, $accounts );
    }

    /**
     * @test
     * Empty customer_id MUST short-circuit before any cache or HTTP work.
     * No transient should be written for an empty key.
     */
    public function test_empty_customer_id_does_not_cache(): void {
        $this->gateway->get_saved_accounts( '' );

        $key = 'linkmoney_accounts_' . md5( '' );
        $this->assertFalse( get_transient( $key ) );
    }
```

- [ ] **Step 2.2: Run the new tests to confirm they fail**

Run:
```bash
vendor/bin/phpunit --filter 'test_accounts_cached_after_first_call|test_accounts_cache_hit_skips_http|test_empty_customer_id_does_not_cache' tests/Gateway/SavedAccountsTest.php
```

Expected: All 3 new tests FAIL.

- [ ] **Step 2.3: Add caching to `get_saved_accounts()` and add the helper**

In `includes/class-wc-gateway-linkmoney.php`, replace the `get_saved_accounts()` method (currently lines 430-457) with:

```php
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
```

- [ ] **Step 2.4: Run the new tests to confirm they pass**

Run:
```bash
vendor/bin/phpunit tests/Gateway/SavedAccountsTest.php
```

Expected: All 6 tests PASS (3 original + 3 new).

- [ ] **Step 2.5: Run the full suite to confirm no regressions**

Run:
```bash
vendor/bin/phpunit
```

Expected: All tests pass (63 total).

- [ ] **Step 2.6: Commit**

```bash
git add includes/class-wc-gateway-linkmoney.php tests/Gateway/SavedAccountsTest.php
git commit -m "feat: cache get_saved_accounts() result per customer

Cache key: linkmoney_accounts_{md5(customer_id)}, TTL 10min.
Adds delete_saved_accounts_cache() helper for invalidation in
later commits. Empty customer_id short-circuits before any cache
work. Verified by 3 new tests in SavedAccountsTest.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Cache invalidation in `handle_wc_api_callback()`

**Files:**
- Modify: `includes/class-wc-gateway-linkmoney.php` (line 565 area — after the `$order->save();` that follows the customer_id meta update)
- Modify: `tests/Gateway/CallbackTest.php` (add 1 test before the closing `}`)

- [ ] **Step 3.1: Add the failing test**

Append to `tests/Gateway/CallbackTest.php` (before the closing `}` of the class):

```php
    /**
     * @test
     * The wc-api callback MUST invalidate the saved-accounts cache for the
     * customer it processes, so a freshly linked bank shows up next checkout.
     */
    public function test_callback_busts_saved_accounts_cache(): void {
        $order = OrderHelper::create_checkout_draft_order();

        // Pre-populate the cache for this customer.
        $key = 'linkmoney_accounts_' . md5( 'cust-callback-bust' );
        set_transient( $key, [ [ 'accountId' => 'old-acct' ] ], 60 * 10 );
        $this->assertNotFalse( get_transient( $key ), 'Sanity check: cache should be populated.' );

        $_GET = [
            'order_id'          => (string) $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-bust-001',
            'customerId'        => 'cust-callback-bust',
            'paymentStatusCode' => '0',
        ];

        $this->capture_redirect( function() {
            $this->gateway->handle_wc_api_callback();
        } );

        $this->assertFalse( get_transient( $key ), 'Cache should be busted after callback.' );
    }
```

- [ ] **Step 3.2: Run the test to confirm it fails**

Run:
```bash
vendor/bin/phpunit --filter test_callback_busts_saved_accounts_cache tests/Gateway/CallbackTest.php
```

Expected: FAIL — cache is not yet invalidated.

- [ ] **Step 3.3: Add invalidation in `handle_wc_api_callback()`**

In `includes/class-wc-gateway-linkmoney.php`, locate the block at lines 558-565:

```php
        if ($payment_id) {
            $order->update_meta_data(self::META_PAYMENT_ID, $payment_id);
        }
        if ($customer_id) {
            $order->update_meta_data(self::META_CUSTOMER_ID, $customer_id);
            update_user_meta($order->get_user_id(), self::META_CUSTOMER_ID, $customer_id);
        }
        $order->save();
```

Replace it with:

```php
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
```

- [ ] **Step 3.4: Run the test to confirm it passes**

Run:
```bash
vendor/bin/phpunit --filter test_callback_busts_saved_accounts_cache tests/Gateway/CallbackTest.php
```

Expected: PASS.

- [ ] **Step 3.5: Run the full suite to confirm no regressions**

Run:
```bash
vendor/bin/phpunit
```

Expected: All tests pass (64 total).

- [ ] **Step 3.6: Commit**

```bash
git add includes/class-wc-gateway-linkmoney.php tests/Gateway/CallbackTest.php
git commit -m "feat: bust saved-accounts cache in wc-api callback

When the customer returns from Link Money via the wc-api callback,
invalidate the cached account list so a newly linked bank shows
up on the next checkout view.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Cache invalidation in `linkmoney_handle_return()`

**Files:**
- Modify: `includes/class-wc-gateway-linkmoney.php` (line 658 area — after the `$order->save();` in `linkmoney_handle_return()`)
- Modify: `tests/Gateway/HandleReturnTest.php` (add 1 test before the closing `}`)

- [ ] **Step 4.1: Add the failing test**

Append to `tests/Gateway/HandleReturnTest.php` (before the closing `}` of the class):

```php
    /**
     * @test
     * linkmoney_handle_return() MUST invalidate the saved-accounts cache for
     * the customer it processes (same reason as the wc-api callback).
     */
    public function test_handle_return_busts_saved_accounts_cache(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $key = 'linkmoney_accounts_' . md5( 'cust-handle-return-bust' );
        set_transient( $key, [ [ 'accountId' => 'old-acct' ] ], 60 * 10 );
        $this->assertNotFalse( get_transient( $key ) );

        $_GET = [
            'paymentId'         => 'pay-return-bust-001',
            'customerId'        => 'cust-handle-return-bust',
            'paymentStatusCode' => '0',
            'status'            => '0',
        ];

        $this->capture_redirect( function() use ( $order ) {
            $this->gateway->linkmoney_handle_return( $order->get_id() );
        } );

        $this->assertFalse( get_transient( $key ) );
    }
```

- [ ] **Step 4.2: Run the test to confirm it fails**

Run:
```bash
vendor/bin/phpunit --filter test_handle_return_busts_saved_accounts_cache tests/Gateway/HandleReturnTest.php
```

Expected: FAIL.

- [ ] **Step 4.3: Add invalidation in `linkmoney_handle_return()`**

In `includes/class-wc-gateway-linkmoney.php`, locate the block at lines 651-658:

```php
        if ($payment_id) {
            $order->update_meta_data(self::META_PAYMENT_ID, $payment_id);
        }
        if ($customer_id) {
            $order->update_meta_data(self::META_CUSTOMER_ID, $customer_id);
            update_user_meta($order->get_user_id(), self::META_CUSTOMER_ID, $customer_id);
        }
        $order->save();
```

Replace it with:

```php
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
```

- [ ] **Step 4.4: Run the test to confirm it passes**

Run:
```bash
vendor/bin/phpunit --filter test_handle_return_busts_saved_accounts_cache tests/Gateway/HandleReturnTest.php
```

Expected: PASS.

- [ ] **Step 4.5: Run the full suite**

Run:
```bash
vendor/bin/phpunit
```

Expected: All tests pass (65 total).

- [ ] **Step 4.6: Commit**

```bash
git add includes/class-wc-gateway-linkmoney.php tests/Gateway/HandleReturnTest.php
git commit -m "feat: bust saved-accounts cache in linkmoney_handle_return

Mirrors the wc-api callback invalidation. Both entry points are
return-from-Link redirects where a newly linked bank may need to
appear in the next saved-accounts response.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Blocks admin / non-AJAX guard

**Files:**
- Create: `tests/Gateway/BlocksAdminGuardTest.php`
- Modify: `includes/class-wc-gateway-linkmoney-blocks.php` (modify `get_payment_method_script_handles()` at lines 16-45)

- [ ] **Step 5.1: Create the failing test file**

Create `tests/Gateway/BlocksAdminGuardTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;

/**
 * Tests for the admin/non-AJAX guard in
 * WC_Gateway_LinkMoney_Blocks::get_payment_method_script_handles().
 *
 * In the block editor (admin, non-AJAX), no live API call should fire and the
 * inline savedAccounts data should be empty. On the frontend, behavior is
 * unchanged: the API is called and the inline data is populated.
 *
 * Inline script content is captured via wp_scripts()->get_data($handle, 'before').
 *
 * 2 tests total.
 */
class BlocksAdminGuardTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    /** @var \WC_Gateway_LinkMoney_Blocks */
    private \WC_Gateway_LinkMoney_Blocks $blocks_integration;

    /** @var int Test user id with a customer_id meta set. */
    private int $user_id;

    public function setUp(): void {
        parent::setUp();
        $this->setup_mock_http();

        update_option( 'woocommerce_linkmoney_settings', [
            'enabled'       => 'yes',
            'environment'   => 'sandbox',
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'merchant_id'   => 'test-merchant-id',
        ] );

        // Create a logged-in user with a Link Money customer_id so the API path
        // is reachable when the guard is NOT active.
        $this->user_id = self::factory()->user->create();
        wp_set_current_user( $this->user_id );
        update_user_meta( $this->user_id, \WC_Gateway_LinkMoney::META_CUSTOMER_ID, 'cust-blocks-001' );

        $this->blocks_integration = new \WC_Gateway_LinkMoney_Blocks();
        $this->blocks_integration->initialize();
    }

    public function tearDown(): void {
        // Deregister our handle so subsequent tests start clean.
        wp_deregister_script( 'linkmoney-block-checkout' );
        // Reset admin context so it doesn't leak to other test classes.
        set_current_screen( 'front' );
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * Read the 'before' inline script attached to the linkmoney block handle.
     */
    private function get_inline_data(): string {
        $data = wp_scripts()->get_data( 'linkmoney-block-checkout', 'before' );
        if ( is_array( $data ) ) {
            return implode( '', $data );
        }
        return (string) $data;
    }

    /**
     * @test
     * In an admin (non-AJAX) context, no API call should fire and the inline
     * savedAccounts data must be an empty array.
     */
    public function test_admin_non_ajax_skips_api_call(): void {
        // Force admin context for this test only.
        set_current_screen( 'edit-post' );
        $this->assertTrue( is_admin() );
        $this->assertFalse( wp_doing_ajax() );

        // No HTTP mocks — any call would return MockHttpTrait's 400 fallback.
        $this->blocks_integration->get_payment_method_script_handles();

        $inline = $this->get_inline_data();
        $this->assertStringContainsString( '"savedAccounts":[]', $inline );
    }

    /**
     * @test
     * On the frontend, the existing behavior must hold: the API is called
     * and savedAccounts are populated.
     */
    public function test_frontend_calls_api(): void {
        // Ensure NOT in admin.
        set_current_screen( 'front' );
        $this->assertFalse( is_admin() );

        $this->mock_token_response();
        $this->mock_accounts_response( [
            [
                'accountId'                => 'acct-frontend',
                'accountStatus'            => 'ACTIVE',
                'preferred'                => true,
                'financialInstitutionName' => 'Frontend Bank',
                'accountLastFourDigits'    => '7777',
                'iconUrl'                  => 'https://example.com/fb.png',
            ],
        ] );

        $this->blocks_integration->get_payment_method_script_handles();

        $inline = $this->get_inline_data();
        $this->assertStringContainsString( '"acct-frontend"', $inline );
        $this->assertStringContainsString( '"Frontend Bank"', $inline );
    }
}
```

- [ ] **Step 5.2: Run the new tests to confirm they fail**

Run:
```bash
vendor/bin/phpunit tests/Gateway/BlocksAdminGuardTest.php
```

Expected: `test_admin_non_ajax_skips_api_call` FAILS (the API call currently fires regardless of context, returns the 400 unmocked-request fallback, the inline data ends up as `"savedAccounts":[]` only by coincidence — but the test will fail at an earlier assertion if there's a PHP notice or because the unmocked call produces a logged error rather than empty data). `test_frontend_calls_api` likely PASSES (control case).

If `test_admin_non_ajax_skips_api_call` happens to pass by coincidence (because get_saved_accounts returns [] on a 400), proceed to Step 5.3 anyway — the implementation makes the behavior intentional rather than accidental.

- [ ] **Step 5.3: Add the guard in `get_payment_method_script_handles()`**

In `includes/class-wc-gateway-linkmoney-blocks.php`, replace lines 28-33:

```php
        $saved_accounts = [];
        $user_id = get_current_user_id();
        if ( $user_id ) {
            $gateway = new WC_Gateway_LinkMoney();
            $saved_accounts = $gateway->get_saved_accounts( get_user_meta( $user_id, WC_Gateway_LinkMoney::META_CUSTOMER_ID, true ) );
        }
```

with:

```php
        $saved_accounts = [];
        $user_id        = get_current_user_id();
        $skip_api       = is_admin() && ! wp_doing_ajax();

        if ( $user_id && ! $skip_api ) {
            $gateway        = new WC_Gateway_LinkMoney();
            $saved_accounts = $gateway->get_saved_accounts( get_user_meta( $user_id, WC_Gateway_LinkMoney::META_CUSTOMER_ID, true ) );
        }
```

- [ ] **Step 5.4: Run the new tests to confirm they pass**

Run:
```bash
vendor/bin/phpunit tests/Gateway/BlocksAdminGuardTest.php
```

Expected: Both tests PASS.

- [ ] **Step 5.5: Run the full suite**

Run:
```bash
vendor/bin/phpunit
```

Expected: All tests pass (67 total).

- [ ] **Step 5.6: Commit**

```bash
git add includes/class-wc-gateway-linkmoney-blocks.php tests/Gateway/BlocksAdminGuardTest.php
git commit -m "feat: skip Link API call in block editor (admin, non-AJAX)

WooCommerce Blocks calls get_payment_method_script_handles() in
the block editor, which previously triggered live token + saved-
accounts HTTP calls. Guard with is_admin() && !wp_doing_ajax():
script registration still runs (so the editor renders) but the
inline data falls back to an empty savedAccounts array.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Final verification

- [ ] **Step 6.1: Run the full test suite**

Run:
```bash
vendor/bin/phpunit
```

Expected: All 67 tests pass (55 pre-existing + 12 new).

- [ ] **Step 6.2: Confirm commit history is clean**

Run:
```bash
git log --oneline main..HEAD
```

Expected: 5 commits, one per Task 1–5, on branch `feature/cache-link-api-calls`.

- [ ] **Step 6.3: Confirm no stray modifications**

Run:
```bash
git status
```

Expected: working tree clean.

- [ ] **Step 6.4: Report back to the user**

Report:
- Number of tests added / total
- Behavior summary (token cache, accounts cache, invalidation points, blocks guard)
- Branch name and commit count
- Suggest next step: open PR or run another verification (e.g., manual smoke test against a sandbox WooCommerce instance)
