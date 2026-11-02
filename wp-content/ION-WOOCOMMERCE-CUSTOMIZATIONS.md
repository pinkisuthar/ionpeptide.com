# Ion Peptide — WooCommerce customization map

Use this document after **every WooCommerce upgrade**. Custom storefront behavior lives in **plugins and must-use plugins**, not in WooCommerce core. Never edit files under `wp-content/plugins/woocommerce/`.

**WooCommerce version (verify after update):** plugin header in `woocommerce/woocommerce.php`; database option `woocommerce_db_version` should catch up via **WooCommerce → Status** or pending Action Scheduler job `woocommerce_update_db_to_current_version`.

---

## Shop listing & pagination

| Layer | Location | What it does |
|-------|----------|--------------|
| **Ion Shop Redesign** | `plugins/ion-shop-redesign/` | Replaces shop archive template, product grid, filters, AJAX refresh, card markup, media catalog JSON |
| **Category infinite scroll fix** | `mu-plugins/ion-shop-category-infinite-scroll-fix2.php` | Disables conflicting YITH infinite scroll on shop; category-tab AJAX loader (`ion_mu_shop_products`) |
| **YITH Infinite Scrolling** | Third-party plugin | Used only for “Shop All” via `nav.ion-shop-yith-nav` selectors in `ion-shop-redesign.js` |

**Verify:** `/shop/` shows `#ion-shop-redesign`, `.ion-shop-grid`, pagination/`Next` links; category tabs do not mix products from other categories.

---

## Product variations & images

**Do not edit** `wp-content/plugins/woocommerce/` for product UI. Updates replace those files. Use **ION Product Page Redesign** (PHP, CSS, JS) and optional template overrides in `plugins/ion-product-page-redesign/templates/woocommerce/` (see README there).

| Layer | Location | What it does |
|-------|----------|--------------|
| **ION Product Page Redesign** | `plugins/ion-product-page-redesign/` | Layout, price panel, tabs, COA, related products, sticky buy bar |
| **Variation sync** | `sync_variation_request()` in same plugin | Aligns posted attributes with selected variation (custom UI) |
| **Fresh image data** | Filters: `woocommerce_product_get_image_id`, `woocommerce_available_variation`, gallery filters | Bypasses stale cache; custom gallery HTML for renamed media |
| **Frontend JS** | `assets/js/ion-product-page-redesign.js` | Variation selection, waitlist slot, gallery behavior |
| **Variation swatches** | `woo-variation-swatches` | Attribute UI (test after WC updates) |
| **Waitlist** | `woocommerce-waitlist` | OOS variation signup |

**Verify:** Variable product — swatches update price/image; waitlist on OOS; add to cart uses correct `variation_id`.

---

## Cart

| Layer | Location | What it does |
|-------|----------|--------------|
| **Cart page design** | WordPress **Page → Cart** (ID 9) post content | Block/HTML layout (~34k chars) — **export/backup this page** before major WC updates |
| **Side Cart Premium** | `woocommerce-side-cart-premium` | Flyout cart, fragments, header `#ion-site-header .ion-header-cart` |
| **Cart for WooCommerce** | `cart-for-woocommerce` | Additional cart UX (if enabled on site) |
| **BRW limit fragments** | `mu-plugins/brw-limit-cart-fragments.php` | Fragment script tuning |
| **Ion Performance Guard** | `plugins/ion-performance-guard/` | Empty-cart fragment / Store API optimizations |

**Verify:** Add to cart from shop/PDP; side cart totals; full cart page styling and coupons.

---

## Checkout

| Layer | Location | What it does |
|-------|----------|--------------|
| **Checkout design + classic flow** | WordPress **Page → Checkout** (ID 10) — `#ion-classic-checkout` + `[woocommerce_checkout]` shortcode | **Export/backup this page** before major WC updates |
| **BRW Address Verifier** | `mu-plugins/brw-address-verifier3.php` | Google Places, validation, `woocommerce_checkout_fields` / `woocommerce_after_checkout_validation` |
| **Discounts / coupons** | woo-discount-rules, advanced-coupons | Totals at checkout |

**Verify:** US address autocomplete, shipping methods, place order, thank-you page. WC **11.2.x** classic checkout fixes especially affect filtered checkout fields.

---

## Access, URLs, performance

| Layer | Location |
|-------|----------|
| Prior-order category access | `mu-plugins/ion-prior-order-category-access-v2.2.0.php` |
| Subdirectory nav URLs (local) | `mu-plugins/ion-subdirectory-template-urls.php` |
| Performance / cart API | `plugins/ion-performance-guard/` |

---

## Safe update checklist

1. Staging clone → update WooCommerce → confirm **Status → Database version** matches plugin version.
2. Run **Settings → Permalinks → Save** once.
3. Smoke-test: shop grid + pagination, one variable product, cart, checkout (test payment in sandbox).
4. Confirm Ion plugins still active; read `readme.txt` / changelogs in `ion-shop-redesign` and `ion-product-page-redesign`.
5. Re-export **Cart** and **Checkout** page content if those pages were edited.

---

## `woocommerce-old` folder (reference copy only)

| Check | Result |
|-------|--------|
| Version | **11.0.0** (`woocommerce-old/woocommerce.php`) — same era as pre-update active WC |
| Active in WordPress? | **No** — only `woocommerce/woocommerce.php` is active |
| Custom Ion / site edits in templates? | **None found** — sampled and full `single-product/add-to-cart/*.php` match [official WooCommerce 11.0.0](https://github.com/woocommerce/woocommerce/tree/11.0.0/plugins/woocommerce/templates/single-product) |
| Suspicious PHP (eval webshells, extra root files)? | **None found** in `includes/` and `templates/`; `base64_decode` / vendor hits are normal WooCommerce dependencies |

**Product page UI is not in this folder.** It is implemented in **`ion-product-page-redesign`** (hooks, CSS, JS). After WC **11.2.1**, core template changes (gallery/video, `<template class="wc-product-gallery-default-template">`) stay in active `woocommerce/` — do not copy from `woocommerce-old`.

**Recommendation:** Delete `wp-content/plugins/woocommerce-old/` after you are satisfied with QA (keeps one WC install, avoids accidental activation). Keep a zip offline if you want an 11.0.0 reference.

---

## Do not version-control

- `wp-content/plugins/woocommerce/` (replace on upgrade)
- `wp-content/plugins/woocommerce-old/` (remove after audit; not for production)
- Database-only template part **Header** (`wp_template_part`) — custom nav HTML; subdirectory URL fix in `ion-subdirectory-template-urls.php` for local dev
