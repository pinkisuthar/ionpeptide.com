=== 3thix Payments for WooCommerce ===
Contributors: 3thix
Tags: woocommerce, payments, crypto, usdc, stablecoin, escrow
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.8.4
License: GPLv2 or later

Accept card payments or send eligible escrow buyers to the hosted fiat-to-USDC
on-ramp. Charges the exact WooCommerce cart total, reconciles paid orders, and
syncs physical or digital delivery evidence back to 3thix.

== Description ==

3thix Payments adds a secure hosted, embedded, or inline checkout for the exact
order total. Direct card orders are confirmed when the buyer returns or by a
five-minute WP-Cron reconciliation sweep. Escrow orders can also complete through
a signed `order.paid` callback.

Two checkout experiences (set this to match how your 3thix merchant account was
provisioned):

* **Stable Payments (escrow):** the buyer completes the hosted fiat-to-USDC
  flow. Funds land in an isolated per-order deposit wallet, sweep to 3thix
  custody, and settle to your payout wallet after delivery checks and the
  applicable hold. Add tracking in WooCommerce OR the 3thix console; the plugin
  syncs WooCommerce tracking automatically.
* **Card payment (direct):** a traditional Stripe Connect card charge that
  settles in USD to the merchant's own Stripe account. There is no escrow
  release callback for this rail.

== Installation ==

1. In WordPress: Plugins → Add New → Upload Plugin, choose the 3thix Payments
   zip, and activate it.
2. WooCommerce → Settings → Payments → 3thix Payments → Manage.
3. Fill in:
   * **3thix API base URL** — must end in `/api`. For the live 3thix console use
     `https://pay.3thix.com/api` (the exact value for your account is also shown on
     the console Developers page). Note: there is no `api.3thix.com` — the host is
     `pay.3thix.com`.
   * **Checkout experience** — On-ramp (escrow) or Card.
   * **Environment** — Test while validating, then Live.
   * **Publishable key** — `thx_test_pk_…` / `thx_live_pk_…` (browser-safe).
     Paste the complete minted key, including its 40-character hexadecimal
     suffix; a shortened or masked console hint is not a usable key.
   * **Secret key** — `thx_test_…` / `thx_live_…` (server-only; used for tracking).
     It is never exposed to buyers.
   * **Callback signing secret** — required for Stable Payments only; copy it
     from your merchant page in the 3thix console.
4. For Stable Payments, copy the **Callback URL** shown at the top of the
   settings page and paste it into "Store callbacks" on the merchant page.

== Shipping & escrow release ==

Tracking is sent to 3thix **automatically**. As soon as you add tracking to a
paid 3thix order and fulfil it, the plugin syncs it to 3thix and starts the
applicable escrow release checks — no duplicate console entry is required.

Tracking is read from any of these, so it works with your existing workflow:

* The order's **3thix Shipment Tracking** box (carrier + tracking number).
* The **WooCommerce Shipment Tracking** (official) plugin.
* The **Advanced Shipment Tracking** (Zorem) plugin.

The same carrier + number is never sent twice. To force an immediate re-send,
tick "Re-send to 3thix now" in the 3thix Shipment Tracking box and update the
order. Automatic sync can be turned off under WooCommerce → Settings → Payments
→ 3thix Payments → "Automatic tracking sync". (You can also enter tracking
directly in the 3thix console.)

Fully virtual orders are handled automatically too. When a non-shippable order
completes, the plugin reports a stable digital-delivery reference instead of
shipment tracking, so retries cannot create duplicate delivery events.

== Changelog ==

= 1.8.4 =
* Adds a "Show credit card logos" checkout setting, enabled by default, so
  merchants can hide the accepted-card marks beside the payment method title
  without changing the payment method label or available payment methods.

= 1.8.3 =
* Rejects shortened, masked, malformed, and wrong-environment publishable keys
  before saving settings or launching redirect, embedded, inline, and Blocks
  checkout. Saves are atomic without exposing key values; server-side secret
  key, tracking, refund, and callback behavior is unchanged.

= 1.8.2 =
* Passes the immutable WooCommerce order currency to 3thix checkout. Escrow
  orders can use USD or EUR; direct card checkout rejects non-USD orders rather
  than interpreting their amount as USD.

= 1.8.1 =
* Checkout label update: the payment method now defaults to "Credit Card
  Payments" (previously "Card / Crypto (3thix)"). Stores still using the old
  default label are renamed automatically on update; custom titles are kept.
* Removed the word "Crypto" from the buyer-facing Coinbase payment-method
  description.

= 1.8.0 =
* Native WooCommerce Blocks support: stores using the modern block-based
  checkout (Gutenberg `woocommerce/checkout` block) now get the full 3thix
  payment method in the payment step — including the inline secure card form
  (Checkout display = "Inline card form"), which previously required the
  classic shortcode checkout. Buyers pay in place with the same secure
  session binding; the redirect flow remains for non-inline modes, Coinbase,
  and as an automatic fallback.

= 1.7.14 =
* Digital orders (no item needs shipping) now auto-report delivery
  to 3thix when the order completes, so escrowed funds release without the
  store calling the 3thix API by hand. Uses a stable per-order reference (the
  WooCommerce download-permission id, or the order id + order key for virtual
  items) so retries never double-report. Controlled by the same "Automatic
  tracking sync" setting as shipment tracking.

= 1.7.12 =
* Fixed hosted-checkout URL encoding: the return URL, buyer email and store
  name are now RFC 3986-encoded when building the redirect. Previously an
  unencoded return URL could split the query string (breaking the buyer's
  return to the store) and a "+" in the buyer's email arrived as a space.

= 1.7.11 =
* Update check-ins now include a short (truncated) publishable-key hint so the
  3thix console can link this store to the right merchant account even when the
  merchant's saved website is missing or out of date. Only the first 18
  characters of the browser-safe publishable key are sent — never the full key
  and never the secret key.

= 1.7.10 =
* Tracking auto-sync failure notes (e.g. "tracking number already in use",
  network errors) are now added to the order timeline only once per failure and
  tracking number, instead of repeating on every order save. Changing the
  tracking number or using "Re-send to 3thix now" still records a fresh note.

= 1.7.9 =
* When 3thix's fraud check reports a tracking number as already delivered, the
  order now shows a clear "was this shipment sent for THIS order?" question in
  the 3thix Shipment Tracking box (plus an order note). Confirming it resubmits
  the tracking with the merchant's explicit confirmation instead of dead-ending.
* Tracking delivered too long ago to submit for payout now shows a clear
  "contact a 3thix admin" note and notice instead of a generic failure, and no
  longer repeats the note on every retry.

= 1.7.8 =
* Update checks now identify the store to the 3thix server (site hostname and
  installed plugin version only — no keys, orders, or buyer data), so 3thix
  support can see which plugin version each store is actually running and
  proactively help stores stuck on old builds.

= 1.7.7 =
* Coinbase payments are now OFF by default. Stores that want to offer Coinbase
  must explicitly enable it under WooCommerce → Settings → Payments → 3thix
  Payments → "Coinbase payments". Stores that previously saved their settings
  keep whatever they chose; stores that never saved the setting will stop
  offering Coinbase until it is ticked.

= 1.7.6 =
* The order confirmation page is now titled "Order confirmation" instead of
  "Checkout" for 3thix orders (page heading and browser tab), so buyers who
  just paid aren't confused into thinking they're still mid-checkout.

= 1.7.5 =
* The order confirmation page no longer shows "Pay" / "Cancel" buttons while a
  Stable Payments order is settling on-chain (the ~2-3 minute window after the
  buyer has already paid). Instead, buyers see a clear "Your payment is
  processing" note and the page refreshes itself until the order is confirmed.
  My Account still offers Pay / Cancel for orders that were genuinely
  abandoned before payment.

= 1.7.4 =
* On-ramp payments are no longer forced through the card-only reconcile
  endpoint (which always rejected them); the plugin now waits for the 3thix
  server's own on-chain settlement and the signed callback / background
  lookup to confirm them, eliminating a burst of failed API calls on the
  thank-you page.
* Payment completion is now crash-proof against faulty third-party plugins:
  if another plugin fatally errors during WooCommerce's payment-complete
  hooks (previously this silently killed all order emails), the error is
  caught, logged, noted on the order, and the New Order / customer emails
  are still sent via the 3thix email fallback.

= 1.7.3 =
* Order confirmation on buyer return now also runs on a theme-proof hook
  (`template_redirect`), so customized thank-you templates that skip
  WooCommerce's own hooks still complete the order during the buyer's page
  view instead of waiting for the background sweep.
* Email safety net: if marking an order paid generates no outgoing email at
  all (seen on sites where email-customizer plugins only register on normal
  page views), the plugin now triggers the standard New Order and customer
  Processing/Completed emails itself — exactly once per order.
* The signed 3thix callback now completes orders through the same shared
  routine as the buyer-return and background-sweep paths.

= 1.7.2 =
* Fixed missing WooCommerce order emails (New Order / Processing) when an
  order is completed by the signed 3thix callback or the background
  reconcile sweep: WooCommerce's transactional mailer is now loaded before
  the order status changes, so the email hooks always fire regardless of
  which path confirms the payment.

= 1.7.1 =
* Updated the payment-method labels in the provider selector: Stripe now lists
  ACH, Credit Card, Debit Card, Apple Pay, Google Pay; Coinbase lists ACH,
  Debit Card, Apple Pay, Google Pay, PayPal, Wire, Crypto.

= 1.7.0 =
* Buyers can now choose between Stripe (card / Apple Pay / Google Pay) and
  Coinbase directly at your store's checkout. Two new settings — "Stripe
  payments" and "Coinbase payments" — are both on by default; when both are on,
  a selector appears inside the 3thix payment box and the chosen flow starts
  after the buyer places the order. Turn one off to offer only the other.
  Coinbase requires a 3thix crypto (Stable Payments) merchant account; if it
  isn't available on your account, buyers are offered the standard checkout
  instead. Works in all display modes (redirect, embedded, and inline —
  picking Coinbase in inline mode uses the normal redirect flow).

= 1.6.0 =
* Orders paid by card that were left at "pending payment" are now completed
  automatically. Card payments confirm when the buyer returns to your store's
  confirmation page; if a buyer paid but never made it back (closed the tab,
  lost connection), the order previously stayed stuck as "pending payment" even
  though the card was charged. A new background check now re-checks recent unpaid
  3thix orders every 5 minutes and marks any that actually paid as paid — no
  action needed. Orders held for manual review are left untouched. (Tip: for the
  most reliable timing on low-traffic stores, point a real server cron at
  wp-cron.php; WordPress's built-in scheduler only runs when your site gets
  visits.)

= 1.5.5 =
* New "Inline card form on the checkout page" checkout display option (card mode):
  the secure 3thix card form now renders directly inside the "Pay securely by card"
  box on the checkout page, so buyers enter their card and pay without a redirect or
  an extra order-pay step. Card data still runs inside the 3thix iframe and never
  touches your site. The card form only unlocks once the buyer has filled the
  required checkout fields, and the charged amount is verified against the order
  total before the order is completed. Redirect and Embedded modes are unchanged.

= 1.5.4 =
* Redesigned the gateway settings screen: a branded 3thix layout with grouped
  sections (Payment method, Connection, Checkout flow, Branding, Callbacks &
  tracking) and a live preview of your hosted checkout that updates as you edit
  branding. No configuration changes required — existing settings are preserved.

= 1.5.3 =
* The "Checkout experience" setting now defaults to "Card payment" so new
  installs show the inline credit card form out of the box. Merchants set up for
  Stable Payments (fiat → USDC escrow) can still switch back in the gateway
  settings. Existing sites keep whatever they already had selected.

= 1.5.2 =
* Renamed the "Fiat → USDC on-ramp (escrow)" checkout experience to "Stable
  Payments". The callback signing secret is now shown only when Stable Payments
  is selected (it is a crypto-only setting) and is labelled "CRYPTO ONLY". Card
  merchants no longer see callback settings they can't use.

= 1.5.1 =
* Fixed refunds. Using WooCommerce's "Refund" button on a 3thix card order now
  issues the refund through 3thix instead of failing with "An error occurred
  while attempting to create the refund using the payment gateway API." Full and
  partial refunds are both supported, and if a refund can't go through the exact
  reason is shown to you and added to the order notes. (Refunds require your
  SECRET API key in the payment method settings.)

= 1.5.0 =
* The plugin now updates itself. Once this version is installed, WordPress
  checks 3thix over a secure (HTTPS) connection for new releases and installs
  them automatically, so payment fixes and improvements roll out without
  re-downloading and re-uploading the zip. (Stores still on an older version
  need to update to this one manually that one time.)

= 1.4.8 =
* Fixed a bug where a successful order could briefly show stray "Pay" /
  "Cancel" actions on the order-received (thank-you) page. The order is now
  reconciled and marked paid before WooCommerce renders the order details
  table, removing the race. For instant confirmation with no delay, set your
  callback URL and paste the signing secret from your 3thix console (see the
  payment method settings) so paid events confirm server-side before the
  buyer returns.

= 1.4.7 =
* The accepted card networks (Visa, Mastercard, Amex, Discover) now show as
  marks next to the payment method in the checkout list — like other card
  processors — so buyers can see the supported card types at a glance. Removed
  the duplicate card marks from inside the expanded payment panel.

= 1.4.6 =
* The clean, full-page 3thix checkout (Redirect) is now the default and
  recommended display mode — the best-looking buyer experience. The embedded
  on-store checkout is still available as an option for stores that want to
  keep buyers on their own domain.

= 1.4.5 =
* Redesigned the payment method box on the checkout page so it looks clean and
  trustworthy: real Visa / Mastercard / Amex card marks and an "Encrypted"
  badge instead of a generic site-icon logo, with higher-contrast copy.

= 1.4.4 =
* The embedded checkout now shows the same Shopify-style order summary (product
  images, line items, and price breakdown) as the hosted page, instead of just
  an email field. The embed widened to fit the two-pane layout (it stacks the
  summary above the form on narrow screens).

= 1.4.3 =
* Redesigned checkout with a Shopify-style order summary. The hosted checkout
  now shows a two-pane layout with your order summary alongside the payment form:
  product images, line items with quantities, and a price breakdown (subtotal,
  discount, shipping, tax, total). The plugin sends these details automatically.
* Polished thank-you / confirmation screen after payment.

= 1.4.2 =
* New "Checkout appearance" setting. Choose "Match my store" (default) to show the
  checkout in a light theme that automatically picks up your site name and logo so
  it blends into your storefront, or "3thix (black & green)" for the signature
  dark 3thix look.
* New optional "Logo URL" and "Accent color" settings to fine-tune the branding of
  the checkout (logo at the top, accent color on the pay button and highlights).
  These apply to both the embedded and hosted checkout experiences.

= 1.4.1 =
* Fixed a stray "Pay" / "Cancel" action showing on the order-received
  (confirmation) page after a successful payment. The plugin now forces a
  server-side reconcile with 3thix when the buyer returns, so the order is marked
  paid deterministically instead of depending on a background sync or webhook
  arriving within a short polling window.
* Cleaner embedded payment step: the order-pay page now leads with a clear
  "Complete your payment" heading and scrolls the secure 3thix checkout into view
  (helpful on mobile, where the order summary previously pushed it below the
  fold), so it reads as the final step of checkout.

= 1.4.0 =
* Tracking now syncs to 3thix automatically when it is added or updated on a paid
  order — no need to tick a box and send by hand. Reads tracking from the 3thix
  box and from the WooCommerce Shipment Tracking / Advanced Shipment Tracking
  plugins, de-duplicates so the same tracking is never sent twice, and can be
  disabled with the new "Automatic tracking sync" setting.

= 1.1.0 =
* API base URL now defaults to https://pay.3thix.com/api and is auto-corrected for
  common mistakes (missing scheme, missing /api suffix, or an accidentally pasted
  endpoint path). Fixes dead checkout links caused by a misconfigured base URL.

= 1.0.0 =
* Initial release: hosted checkout redirect, signed order.paid / order.released
  callback receiver, and shipment-tracking sync to 3thix.
