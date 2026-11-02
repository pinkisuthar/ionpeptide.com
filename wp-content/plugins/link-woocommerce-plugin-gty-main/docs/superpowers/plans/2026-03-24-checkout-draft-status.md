# Checkout-Draft Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hide orders from merchants while customers are off-site at Link Money by using WooCommerce's `checkout-draft` status in the new account redirect flow.

**Architecture:** Set `checkout-draft` in `process_payment()` before redirecting. On callback return, transition to the appropriate terminal status. On cancellation, keep `checkout-draft` and add an order note.

**Tech Stack:** PHP, WooCommerce

**Spec:** `docs/superpowers/specs/2026-03-24-checkout-draft-status-design.md`

---

## File Structure

All changes are in a single file:

- **Modify:** `includes/class-wc-gateway-linkmoney.php`
  - `process_payment()` (lines 290-301) — set checkout-draft after session creation
  - `handle_wc_api_callback()` (lines 526-538, 593) — add order note on cancellation, fix pending guard
  - `linkmoney_handle_return()` (lines 616-628, 683) — add order note on cancellation, fix pending guard

No new files. No test files (this is a WooCommerce plugin without an existing test suite).

---

### Task 1: Set checkout-draft in process_payment()

**Files:**
- Modify: `includes/class-wc-gateway-linkmoney.php:297-298`

- [ ] **Step 1: Add checkout-draft status before redirect**

In `process_payment()`, after the `create_session()` error check and before the return, add the status update:

```php
        // existing code: error check ends at line 297
        }
        $order->update_status('checkout-draft', __('Customer redirected to Link Money to link bank account.', 'linkmoney'));
        return [
            'result' => 'success',
            'redirect' => esc_url_raw($session['sessionUrl']),
        ];
```

The `$order->update_status()` line is inserted between the closing `}` of the error check (line 297) and the existing `return` (line 298).

- [ ] **Step 2: Commit**

```bash
git add includes/class-wc-gateway-linkmoney.php
git commit -m "feat: set checkout-draft status before Link Money redirect"
```

---

### Task 2: Fix pending guard and add cancellation note in handle_wc_api_callback()

**Files:**
- Modify: `includes/class-wc-gateway-linkmoney.php:526-538, 593`

- [ ] **Step 1: Add order note in cancellation path**

In `handle_wc_api_callback()`, inside the cancellation block (lines 526-538), add an order note after the cart restore and before the error notice. The full block becomes:

```php
        // Check if user cancelled (status >= 204)
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        if ($status >= '204') {
            $order->add_order_note(__('Customer dropped off without linking their account.', 'linkmoney'));
            if (function_exists('WC') && WC()->cart) {
                $this->restore_cart_from_order($order);
            }
            wc_add_notice(
                __('You didn\'t complete linking your bank account. Please try again.', 'linkmoney'),
                'error'
            );
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
```

- [ ] **Step 2: Fix pending guard for status code 2**

On line 593, change:

```php
                case 2: // Pending
                    if ($order->has_status('pending')) {
```

To:

```php
                case 2: // Pending
                    if ($order->has_status(['pending', 'checkout-draft'])) {
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-wc-gateway-linkmoney.php
git commit -m "feat: handle checkout-draft in wc-api callback (cancellation note + pending guard)"
```

---

### Task 3: Fix pending guard and add cancellation note in linkmoney_handle_return()

**Files:**
- Modify: `includes/class-wc-gateway-linkmoney.php:616-628, 683`

- [ ] **Step 1: Add order note in cancellation path**

In `linkmoney_handle_return()`, inside the cancellation block (lines 616-628), add an order note. The full block becomes:

```php
        if ($_GET['status'] >= '204') {
            // User didn't complete the payment process - restore cart items
            $order->add_order_note(__('Customer dropped off without linking their account.', 'linkmoney'));
            if (function_exists('WC') && WC()->cart) {
                $this->restore_cart_from_order($order);
            }

            wc_add_notice(
                __('You didn\'t complete linking your bank account. Please try again.', 'linkmoney'),
                'error'
            );
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
```

- [ ] **Step 2: Fix pending guard for status code 2**

On line 683, change:

```php
                case 2:
                    if ($order->has_status('pending')) {
```

To:

```php
                case 2:
                    if ($order->has_status(['pending', 'checkout-draft'])) {
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-wc-gateway-linkmoney.php
git commit -m "feat: handle checkout-draft in handle_return (cancellation note + pending guard)"
```

---

### Task 4: Manual verification

- [ ] **Step 1: Review all changes**

```bash
git diff HEAD~3..HEAD -- includes/class-wc-gateway-linkmoney.php
```

Verify:
- `process_payment()` sets `checkout-draft` after `create_session()` succeeds
- Both `handle_wc_api_callback()` and `linkmoney_handle_return()` have the order note in the cancellation path
- Both have `has_status(['pending', 'checkout-draft'])` in the pending (case 2) guard
- No other code paths were accidentally modified
