# Checkout-Draft Status for New Account Flow

## Summary

Use WooCommerce's built-in `checkout-draft` order status to hide orders from merchants while the customer is off-site at Link Money during the new account (redirect-based) payment flow. On callback, transition to the appropriate final status.

## Motivation

When a customer is redirected to Link Money to link a new bank account, the order sits in `pending` status and is visible to the merchant in the orders list. This creates noise — the merchant sees orders that haven't been confirmed yet. The `checkout-draft` status (built into WooCommerce core since v5.0) is designed for exactly this scenario: it hides orders from the default orders list while they're still in the checkout process.

## Scope

- **In scope:** The redirect-based new account flow only (`process_payment()` → `create_session()` → redirect to Link Money → callback)
- **Out of scope:** Saved account flow (`process_saved_payment()`), duplicate order prevention logic, webhook handler, abandonment cleanup
- **Note:** The Blocks/Store API new-account flow (`store_api_process_linkmoney()`) returns early for new accounts and falls through to `process_payment()`, so `checkout-draft` will apply to that path implicitly

## Design

### Status Registration

No registration needed. `checkout-draft` is a core WooCommerce status available since v5.0 (2021). The plugin already requires WooCommerce Blocks support, so any compatible store will have it.

### Changes

All changes are in `includes/class-wc-gateway-linkmoney.php`.

#### 1. `process_payment()` — Set checkout-draft before redirect

After `create_session()` succeeds and before returning the redirect URL, set the order status to `checkout-draft`:

```php
$order->update_status('checkout-draft', __('Customer redirected to Link Money to link bank account.', 'linkmoney'));
```

This hides the order from the merchant's orders list while the customer is off-site.

#### 2. `handle_wc_api_callback()` — Transition on return

The wc-api callback handler processes the customer's return from Link Money. Status transitions from `checkout-draft`:

| Payment Status Code | Transition To | Notes |
|---|---|---|
| 0 (Success) | `processing` | Existing logic applies; set `META_EVER_PROCESSING` |
| 1 (Declined) | `failed` | Restore cart, redirect to checkout with error |
| 2 (Pending) | `on-hold` | **Requires guard fix** (see below) |
| >= 204 (Cancellation) | stays `checkout-draft` | Add order note: "Customer dropped off without linking their account." WooCommerce auto-cleanup handles deletion. |

**Required logic changes:**

1. **Pending guard fix:** The existing code guards the `on-hold` transition with `$order->has_status('pending')`. Since the order will now be in `checkout-draft`, this guard must be updated to `$order->has_status(['pending', 'checkout-draft'])`.

2. **Cancellation order note:** The existing cancellation path (status >= 204) restores the cart and redirects. Add `$order->add_order_note(...)` with "Customer dropped off without linking their account." The order stays in `checkout-draft` — invisible to the merchant — and WooCommerce's built-in cleanup will delete it automatically.

#### 3. `linkmoney_handle_return()` — Same transitions

The receipt/thank-you page handler mirrors the wc-api callback logic. Same transitions and required logic changes apply:

1. **Pending guard fix:** Update `has_status('pending')` to `has_status(['pending', 'checkout-draft'])` for the pending (status code 2) path.
2. **Cancellation order note:** Add `$order->add_order_note(...)` with "Customer dropped off without linking their account." in the cancellation path (status >= 204). Order stays in `checkout-draft`.

#### 4. Webhook handler — No changes

The webhook handler already transitions orders to `processing` or `failed` based on payment events. `update_status()` works from `checkout-draft` just as it does from `pending`. No modifications needed.

### Abandonment

No custom cleanup. WooCommerce's built-in mechanism automatically deletes stale `checkout-draft` orders (default: after 1 day). Abandoned orders from customers who never return from Link Money will quietly disappear.

### Duplicate Order Prevention

No changes. The duplicate check in `process_saved_payment()` is only reached via the saved account flow, which is out of scope. The new account flow (the only flow using `checkout-draft`) does not pass through that code path.

## Files Modified

| File | Changes |
|---|---|
| `includes/class-wc-gateway-linkmoney.php` | (1) Set `checkout-draft` in `process_payment()` after `create_session()`, (2) fix `has_status('pending')` guards in `handle_wc_api_callback()` and `linkmoney_handle_return()` to include `checkout-draft`, (3) add order note in cancellation paths |

## Risk Assessment

**Risk: Low**

- The change is isolated to one code path (new account redirect flow)
- `checkout-draft` is a stable, core WooCommerce feature
- `update_status()` transitions work from any starting status
- No changes to saved account flow, webhooks, or duplicate prevention
- Abandonment handled by existing WooCommerce infrastructure
- **Edge case:** If a webhook arrives after WooCommerce auto-deletes a stale `checkout-draft` order, the webhook handler will return 404 (existing behavior for missing orders)
