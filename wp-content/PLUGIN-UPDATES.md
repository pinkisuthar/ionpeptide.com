# Plugin updates — before / after (Ion Peptide)

Short log of **third-party plugin** version changes and what differs for the store. Update the **Applied** column and **After** version when you finish each upgrade on staging/production.

**Ion custom code** (`ion-*`, `mu-plugins/`) is unchanged unless noted.

| Applied | Plugin | Before | After | What changed (vendor) | Difference for this site |
|---------|--------|--------|-------|----------------------|---------------------------|
| _pending_ | **YITH WooCommerce Affiliates Premium** | 3.30.0 | 3.32.0 | **3.31.1:** fix PHP object injection in admin inline fields (Wordfence ~6.6). **3.32.0:** WooCommerce 11.3 support; YITH framework update. | Same affiliate flows: **Ion Affiliate Compliance** (agreement → application → approve/deny), `[yith_wcaf_affiliate_dashboard]` on `/affiliate-dashboard/`, My Account link, referral/commissions. **Re-test:** Users → Affiliate Agreements approve/deny; dashboard tabs; order commissions metabox; referral cookie → checkout. **Ion Performance Guard** still loads `yith-wcaf` assets only on affiliate URLs. |
| _pending_ | YITH WooCommerce PDF Invoices Premium | 5.3.0 | 5.5.1 | Framework + compatibility fixes (see YITH changelog in wp-admin update screen). | Invoice emails/PDFs on orders; no Ion plugin hooks this directly. **Re-test:** one order invoice download/email. |
| _pending_ | YITH Infinite Scrolling | 2.12.0 | 2.13.0 | Minor release / framework. | Shop “Shop All” infinite scroll via **Ion Shop Redesign** + `nav.ion-shop-yith-nav`. **Re-test:** `/shop/` pagination/infinite scroll. |
| _pending_ | Variation Swatches for WooCommerce | 2.4.0 | 2.6.2 | Swatches UI/compat updates across 2.5.x–2.6.x. | Variable product pages (**ION Product Page Redesign** + swatches). **Re-test:** one variable PDP — swatch, price, image, add to cart. |
| _pending_ | WooCommerce Waitlist | 2.5.1 | 2.7.0 | Waitlist features/fixes across 2.6–2.7. | OOS variation signup on PDP. **Re-test:** waitlist on out-of-stock variation. |
| _pending_ | Header Footer Elementor | 2.9.4 | 2.9.6 | Patch release. | Header/footer templates if used site-wide. **Re-test:** homepage + one inner page header/footer. |
| _pending_ | Yoast SEO (`wordpress-seo`) | 28.4 | 28.8 | Incremental SEO/schema fixes. | Meta/sitemap; no Ion coupling. **Re-test:** one product SEO preview if you rely on Yoast. |
| _pending_ | WP Mail SMTP Pro | 4.9.0 | 4.10.3 | SMTP/delivery improvements. | All wp_mail (affiliate application emails, Woo emails). **Re-test:** send test email from plugin settings. |
| _pending_ | Authorize.Net AIM Gateway | 6.1.26 | 6.1.29 | Gateway patches. | Checkout if gateway enabled. **Re-test:** sandbox transaction. |
| _pending_ | Addify Payment Gateway Fee & Discounts | 1.2.0 | 1.2.1 | Patch. | Checkout fees/discounts if rules active. |
| _pending_ | SSTG Payments | 1.0.9 | 1.1.2 | Gateway update. | Checkout if enabled. |
| _pending_ | Omnisend Connect | 1.22.8 | 1.22.9 | Patch. | Marketing sync; **Ion Performance Guard** may dequeue Omniscript on home. |
| _pending_ | ATUM Inventory Management | 2.8.2 | 2.9.0 | Minor ATUM release. | Stock/admin only unless storefront uses ATUM widgets. |

---

## YITH Affiliates — quick before vs after (detail)

| | **Before (3.30.0)** | **After (3.32.0)** |
|--|---------------------|---------------------|
| **Security** | Vulnerable admin “inline fields” path (fixed in 3.31.1+). | Patched; stay on **≥ 3.31.1** (target 3.32.0). |
| **WooCommerce** | Tested up to **11.1** (plugin header). | Declared support through **11.3** (update feed). |
| **Ion integration** | `YITH_WCAF_Affiliate_Factory`, `yith_wcaf_affiliate_enabled` / `_disabled`, dashboard shortcode. | **No Ion code changes required**; same APIs/hooks expected. |
| **Admin** | Affiliates panel, rates, payments, order commission boxes. | Same areas; inline-field handling hardened. |
| **Storefront** | `/affiliate-agreement/`, `/affiliate-application/`, `/affiliate-dashboard/`. | Same URLs and **Ion Affiliate Compliance** behavior. |

---

## After any row above is applied

1. Set **Applied** to date (e.g. `2026-10-30`) and confirm **After** matches **Plugins** list.
2. Run the **Re-test** note for that row; add a line below if something broke.

**Example completed row:**

`2026-10-30 | YITH WooCommerce Affiliates Premium | 3.30.0 | 3.32.0 | (same as table) | QA passed: agreements, dashboard, test referral order #____`

---

See also: [ION-WOOCOMMERCE-CUSTOMIZATIONS.md](./ION-WOOCOMMERCE-CUSTOMIZATIONS.md) (WooCommerce core upgrades and Ion-owned plugins).
