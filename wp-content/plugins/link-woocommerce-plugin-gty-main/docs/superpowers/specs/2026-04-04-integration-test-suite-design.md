# Link Money WooCommerce Plugin — Integration Test Suite Design

## Goal

Full integration test suite using PHPUnit with the WordPress test framework and WooCommerce, covering all gateway flows. Runs in GitHub Actions CI on every push/PR.

## Architecture

**WordPress test framework** (`wp-phpunit`) provides a real WordPress + WooCommerce environment with an in-memory test database. Tests create real WC orders, sessions, and hooks — not mocks of WooCommerce internals.

**External API calls** (Link Money OAuth, sessions, payments, refunds, accounts) are intercepted at the WordPress HTTP layer using the `pre_http_request` filter. Tests never hit the real API. No credentials needed in CI.

**Tech stack:** PHP 8.2, PHPUnit 10, WordPress test suite, WooCommerce 10.6.x, MySQL (CI service container).

## File Structure

```
tests/
  bootstrap.php                    — loads WP test framework + WooCommerce + plugin
  Helpers/
    MockHttpTrait.php              — trait to intercept wp_remote_* via pre_http_request
    OrderHelper.php                — factory for creating test orders with Link Money config
  Gateway/
    ProcessPaymentTest.php         — classic checkout: new + saved account
    ProcessSavedPaymentTest.php    — saved account API flow + status transitions
    StoreApiProcessTest.php        — Blocks checkout: new + saved account
    CallbackTest.php               — handle_wc_api_callback: success/decline/pending/cancel
    HandleReturnTest.php           — linkmoney_handle_return: thank-you page handler
    RestoreCartTest.php            — maybe_restore_cart_from_order + restore_cart_from_order
    SessionTest.php                — create_session + get_access_token
    RefundTest.php                 — process_refund
    WebhookTest.php                — handle_webhook: authorized/succeeded/failed
    SavedAccountsTest.php          — get_saved_accounts
    StatusTransitionsTest.php      — cross-cutting status logic + email triggers
composer.json                      — phpunit, wp-phpunit dependencies
phpunit.xml                        — test suite config
.github/workflows/tests.yml        — GitHub Actions CI
bin/install-wp-tests.sh            — standard WordPress test suite installer
```

## Test Helpers

### MockHttpTrait

Trait used by all test classes to intercept WordPress HTTP calls:

```php
trait MockHttpTrait {
    private array $mock_responses = [];

    protected function mock_http_response(string $url_pattern, int $status, array $body): void;
    protected function mock_token_response(string $token = 'test-token'): void;
    protected function mock_session_response(string $session_url = 'https://link.money/session/test'): void;
    protected function mock_payment_response(string $payment_id, string $status = 'AUTHORIZED'): void;
    protected function mock_refund_response(string $payment_id): void;
    protected function mock_accounts_response(array $accounts = []): void;
}
```

The trait hooks into `pre_http_request` filter. URL patterns are matched against the request URL. Unmatched requests fail the test (no accidental real API calls).

### OrderHelper

Factory class for creating WooCommerce test orders with Link Money as the payment method:

```php
class OrderHelper {
    public static function create_pending_order(array $overrides = []): WC_Order;
    public static function create_checkout_draft_order(array $overrides = []): WC_Order;
    public static function create_processing_order(string $payment_id): WC_Order;
    public static function create_failed_order(bool $ever_processing = false): WC_Order;
    public static function create_simple_product(float $price = 20.00): WC_Product;
}
```

Orders are created with full billing details (name, email, address) by default.

## Test Coverage

### ProcessPaymentTest (3 tests)

Tests `process_payment()` — classic checkout entry point.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_new_account_creates_session_and_redirects` | No saved account selected | Session API called, order set to checkout-draft, returns success + sessionUrl redirect |
| `test_new_account_session_failure` | Session API returns error | Returns failure, error notice added |
| `test_saved_account_delegates_to_process_saved_payment` | Saved account selected via POST | Calls process_saved_payment with account ID |

### ProcessSavedPaymentTest (7 tests)

Tests `process_saved_payment()` — payment API call for saved accounts.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_success_sets_processing` | API returns paymentId, no PENDING | Order → processing, META_EVER_PROCESSING set, cart emptied |
| `test_pending_sets_on_hold` | API returns paymentStatus=PENDING | Order → on-hold |
| `test_declined_sets_failed` | API returns HTTP 4xx | Order → failed, error notice |
| `test_terminal_failed_sets_failed` | API returns TERMINAL_FAILED | Order → failed |
| `test_duplicate_guard_blocks_resubmit` | Order has META_PAYMENT_ID, not failed | Returns failure with duplicate notice |
| `test_retry_failed_order_sets_completed` | Order is failed + ever_processing | Order → completed |
| `test_token_failure` | get_access_token returns WP_Error | Order → failed |

### StoreApiProcessTest (6 tests)

Tests `store_api_process_linkmoney()` — Blocks checkout hook.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_new_account_creates_session` | selected=__new | Session API called, checkout-draft set, result redirect to sessionUrl |
| `test_new_account_session_failure_throws` | Session API fails | Exception thrown |
| `test_empty_selection_treated_as_new` | selected='' | Same as __new flow |
| `test_saved_account_success` | Valid account ID | Payment API called, order → processing |
| `test_saved_account_pending` | paymentStatus=PENDING | Order → on-hold |
| `test_non_linkmoney_returns_early` | payment_method=stripe | Method returns without action |

### CallbackTest (7 tests)

Tests `handle_wc_api_callback()` — wc-api return from Link Money.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_success_status_0` | paymentStatusCode=0 | Order → processing, cart emptied, redirect to thank-you |
| `test_declined_status_1` | paymentStatusCode=1 | Order → failed, cart restored, redirect to checkout |
| `test_pending_status_2` | paymentStatusCode=2 | Order → on-hold |
| `test_cancel_status_204` | status=204 | Redirect to checkout with lm_restore_order params |
| `test_saves_payment_metadata` | paymentId + customerId in GET | Order meta + user meta updated |
| `test_invalid_order` | Bad order_id | Redirect to checkout |
| `test_wrong_payment_method` | Order uses different gateway | Redirect to thank-you (no processing) |

### HandleReturnTest (4 tests)

Tests `linkmoney_handle_return()` — fires on thank-you page.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_success_status_0` | paymentStatusCode=0 | Order → processing, metadata saved |
| `test_declined_status_1` | paymentStatusCode=1 | Order → failed |
| `test_pending_status_2` | paymentStatusCode=2 | Order → on-hold |
| `test_cancel_redirects` | status >= 204 | Redirect to checkout with restore params |

### RestoreCartTest (6 tests)

Tests `maybe_restore_cart_from_order()` and `restore_cart_from_order()`.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_deletes_order_and_restores_cart` | Valid lm_restore_order + key | Order deleted, cart has same items |
| `test_clears_store_api_draft_session` | Valid params, session exists | store_api_draft_order set to 0 |
| `test_invalid_order_key_no_op` | Wrong order key | Order not deleted |
| `test_missing_params_no_op` | No GET params | Nothing happens |
| `test_restore_skips_processing_order` | Order is processing | Cart not restored |
| `test_restore_skips_nonempty_cart` | Cart has items | Cart not modified |

### SessionTest (6 tests)

Tests `create_session()` and `get_access_token()`.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_create_session_success` | API returns sessionUrl | Returns array with sessionUrl |
| `test_create_session_payload` | Inspect request body | Correct name, email, address, amount, redirectUrl |
| `test_create_session_api_failure` | API returns 400 | Returns WP_Error |
| `test_get_access_token_success` | API returns token | Returns token string |
| `test_get_access_token_failure` | API returns 401 | Returns WP_Error |
| `test_guest_uses_order_email` | Not logged in | Session payload uses billing email |

### RefundTest (4 tests)

Tests `process_refund()`.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_full_refund_success` | API returns paymentId | Returns true, order note added |
| `test_partial_refund` | Amount < order total | Correct amount in API payload |
| `test_api_failure` | API returns error | Returns WP_Error |
| `test_missing_payment_id` | No META_PAYMENT_ID on order | Returns WP_Error |

### WebhookTest (5 tests)

Tests `handle_webhook()` — REST API endpoint.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_payment_authorized` | event=payment.authorized | Order → processing, emails sent |
| `test_payment_succeeded` | event=payment.succeeded | Order note added |
| `test_payment_failed` | event=payment.failed | Order → failed |
| `test_invalid_signature` | Wrong webhook secret | Returns 401 |
| `test_missing_order` | Order doesn't exist | Returns 404 |

### SavedAccountsTest (3 tests)

Tests `get_saved_accounts()`.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_returns_active_preferred_accounts` | API returns mixed accounts | Only ACTIVE + preferred returned |
| `test_empty_customer_id` | Empty string | Returns empty array |
| `test_api_failure` | HTTP error | Returns empty array |

### StatusTransitionsTest (3 tests)

Cross-cutting status helpers.

| Test | Scenario | Expected |
|------|----------|----------|
| `test_order_ever_was_processing_true` | META_EVER_PROCESSING set | Returns true |
| `test_order_ever_was_processing_false` | No meta | Returns false |
| `test_trigger_awaiting_payment_emails` | Valid order | WC_Email_New_Order triggered |

**Total: 54 test methods across 11 test classes.**

## CI Pipeline — GitHub Actions

**Trigger:** Push or PR to `main` and `fix/*` branches.

**Steps:**
1. Start MySQL 8.0 service container
2. Install PHP 8.2 + Composer
3. `composer install`
4. Download + install WordPress test suite (`bin/install-wp-tests.sh`)
5. Download WooCommerce 10.6.2 into WordPress plugins directory
6. Run `vendor/bin/phpunit`

**Environment variables:**
- `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_HOST` — MySQL connection (from service container)
- `WP_VERSION` — WordPress version (latest)
- `WC_VERSION` — WooCommerce version (10.6.2)

No API credentials or secrets needed — all external calls are mocked.

## Dependencies (composer.json)

```json
{
  "require-dev": {
    "phpunit/phpunit": "^10.0",
    "yoast/phpunit-polyfills": "^2.0"
  }
}
```

`yoast/phpunit-polyfills` bridges PHPUnit 10 with the WordPress test framework which expects PHPUnit 9 APIs.

`wp-phpunit` and WooCommerce are installed by the test bootstrap script, not via Composer (standard WordPress plugin testing pattern).
