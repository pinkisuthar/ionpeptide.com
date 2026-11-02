# Link Money API Call Caching — Design Spec

**Date:** 2026-04-24
**Branch:** `feature/cache-link-api-calls`
**Status:** Approved for implementation planning

## Problem

Two outbound HTTP calls to the Link Money API fire on every checkout page render for logged-in customers:

1. `POST /v1/tokens` — OAuth client-credentials token (in `WC_Gateway_LinkMoney::get_access_token()`)
2. `GET /v1/customers/{id}/accounts` — saved bank accounts (in `WC_Gateway_LinkMoney::get_saved_accounts()`)

Neither has any caching. Both block page render. The blocks integration also calls `get_saved_accounts()` from `get_payment_method_script_handles()` with no `is_checkout()` guard, which means the same calls fire whenever WooCommerce Blocks resolves payment-method script handles in the block editor (admin, non-AJAX context) too.

## Goals

- Eliminate redundant token + saved-accounts HTTP calls on repeated checkout loads.
- Stop the blocks integration from making live API calls in the block editor.
- Preserve correctness: a freshly linked bank must appear in the list on the next checkout view.

## Non-Goals

- No object-cache layer (transients are sufficient for multi-request persistence).
- No new admin settings for TTLs (hardcoded constants).
- No cache warming.
- No widening of method visibility for testing — caching behavior is observable through existing public methods.

## Design

### 1. Token cache

**Method:** `WC_Gateway_LinkMoney::get_access_token($scope = 'Link-Payment')` at `includes/class-wc-gateway-linkmoney.php:249`

**Cache key:** `linkmoney_token_{environment}_{scope}_{md5(client_id)}`

- `environment`: `production` or `sandbox` — required because tokens issued by the sandbox auth server must never satisfy a production call (and vice versa).
- `scope`: `Link-Core` and `Link-Payment` are distinct scopes used by different code paths.
- `md5(client_id)`: scopes the cache to the configured credentials. Rotating credentials naturally invalidates the cache.

**TTL:** 50 minutes default (Link tokens live 60 min). If the token response includes `expires_in`, use `min(50 * MINUTE_IN_SECONDS, expires_in - 60)` so the cache always expires before the token does.

**Error handling:** If `wp_remote_post` returns `WP_Error` or the response code is not 200, do not write the transient; return the existing error path unchanged.

### 2. Saved-accounts cache

**Method:** `WC_Gateway_LinkMoney::get_saved_accounts($customer_id)` at `includes/class-wc-gateway-linkmoney.php:430`

**Cache key:** `linkmoney_accounts_{md5($customer_id)}`

**TTL:** 10 minutes.

**Empty customer_id:** existing early return preserved. Do not write a transient.

**Error handling:** API failure (non-200 or `WP_Error`) returns `[]` as today, but does **not** write the cache (avoid pinning empty results during a transient API outage).

**Invalidation:** Bust the cache for `$customer_id` at the two return-from-Link entry points:

- `WC_Gateway_LinkMoney::handle_wc_api_callback()` — at `includes/class-wc-gateway-linkmoney.php:519`. Invalidate after the success branch resolves the customer ID (around line 562).
- `WC_Gateway_LinkMoney::linkmoney_handle_return($order_id)` — at `includes/class-wc-gateway-linkmoney.php:625`. Invalidate after customer ID is resolved (around line 655).

Both branches run when the user is redirected back from Link Money, which is the only point at which a new bank account may have been added. Bust unconditionally (do not gate on whether `customer_id` changed) so re-linking the same customer with a new bank still invalidates.

**Helper method:** Add `protected delete_saved_accounts_cache($customer_id)` to keep call sites DRY.

### 3. Admin / block-editor guard

**Method:** `WC_Gateway_LinkMoney_Blocks::get_payment_method_script_handles()` at `includes/class-wc-gateway-linkmoney-blocks.php:16`

**Guard:** Early-return *the API portion* when `is_admin() && ! wp_doing_ajax()`:

```php
$saved_accounts = [];
$user_id        = get_current_user_id();
$skip_api       = is_admin() && ! wp_doing_ajax();

if ( $user_id && ! $skip_api ) {
    $gateway        = new WC_Gateway_LinkMoney();
    $saved_accounts = $gateway->get_saved_accounts(
        get_user_meta( $user_id, WC_Gateway_LinkMoney::META_CUSTOMER_ID, true )
    );
}
```

Script registration and `wp_add_inline_script` still run (with empty `savedAccounts`) so the block editor renders cleanly. The same guard implicitly covers `get_payment_method_script_handles_for_admin()` since it delegates to this method.

## File Changes

| File | Change |
|---|---|
| `includes/class-wc-gateway-linkmoney.php` | Add transient read/write in `get_access_token()` and `get_saved_accounts()`; add `delete_saved_accounts_cache()` helper; add invalidation calls in `handle_wc_api_callback()` and `linkmoney_handle_return()` |
| `includes/class-wc-gateway-linkmoney-blocks.php` | Add admin/non-AJAX guard around `get_saved_accounts()` call |

No new files in `includes/`. Constants for cache key prefixes and TTLs added at the top of `WC_Gateway_LinkMoney` (alongside `META_CUSTOMER_ID`).

## Testing

All tests use the existing `tests/Helpers/MockHttpTrait.php`. Cache hits are verified by *not* registering an HTTP mock — `MockHttpTrait` returns a 400 `unmocked-request` response for any unmatched URL, so a leaked HTTP call surfaces as a test failure. Cache writes are verified via `get_transient()`. Invalidation is verified via `false === get_transient()` after the trigger.

### New: `tests/Gateway/TokenCacheTest.php` (5 tests)

1. `test_token_cached_after_first_call` — call via `get_saved_accounts()`, assert transient exists and contains the mocked token value.
2. `test_token_cache_hit_skips_http` — pre-populate transient, register no token mock, assert call succeeds (proves no HTTP).
3. `test_token_cache_key_includes_environment` — populate sandbox-keyed transient, switch gateway to production, assert a fresh HTTP call is made (no cross-env reuse).
4. `test_token_cache_key_includes_scope` — populate `Link-Core` transient, trigger a `Link-Payment` flow, assert a fresh HTTP call is made.
5. `test_token_error_not_cached` — mock token endpoint with 401, call, assert no transient written.

### Extend: `tests/Gateway/SavedAccountsTest.php` (+3 tests)

6. `test_accounts_cached_after_first_call` — call once, assert transient written.
7. `test_accounts_cache_hit_skips_http` — pre-populate transient with normalized account array, register no mocks, assert returned array matches.
8. `test_empty_customer_id_does_not_cache` — call with `''`, assert no transient written.

### Extend: `tests/Gateway/CallbackTest.php` (+1 test)

9. `test_callback_busts_saved_accounts_cache` — set transient for customer X, simulate the wc-api callback for an order tied to customer X, assert `false === get_transient(...)`.

### Extend: `tests/Gateway/HandleReturnTest.php` (+1 test)

10. `test_handle_return_busts_saved_accounts_cache` — set transient for customer X, invoke `linkmoney_handle_return($order_id)` for an order tied to customer X, assert transient is gone.

### New: `tests/Gateway/BlocksAdminGuardTest.php` (2 tests)

11. `test_admin_non_ajax_skips_api_call` — force admin context (e.g., `set_current_screen('edit-post')` or filter `is_admin` true and `wp_doing_ajax` false), call `get_payment_method_script_handles()`, assert no HTTP call and `linkmoneyData.savedAccounts` is `[]` in the registered inline script.
12. `test_frontend_calls_api` — control: not in admin, mock token + accounts, assert HTTP fires and inline data is populated.

**Total: 12 new tests, bringing suite from 55 → 67.**

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Stale saved-accounts list after a new bank is linked | Invalidation on both return entry points (`handle_wc_api_callback`, `linkmoney_handle_return`) |
| Token cache survives credential rotation | Cache key includes `md5(client_id)` — rotating credentials produces a new key |
| Cross-environment token reuse (sandbox token used for production call) | Cache key includes environment |
| Empty array cached during transient API outage | Errors do not write the cache; next call retries |
| Block editor still hits API in some unforeseen admin context | Guard uses standard `is_admin() && ! wp_doing_ajax()` — matches the documented WordPress admin-context check |

## Out of Scope

- Token refresh on 401 (current code already returns the error; user retries on next checkout)
- Per-user UI for clearing cache
- Background warming
- Migration to WordPress Object Cache (Redis/Memcached) — transients fall back to options table on plain installs and that's acceptable for this volume
