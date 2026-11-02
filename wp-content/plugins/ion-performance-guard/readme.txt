=== Ion Performance Guard ===
Contributors: Intangible Treasures LLC
Tags: performance, core web vitals, lighthouse, woocommerce, kinsta
Requires at least: 6.5
Tested up to: 7.0.2
Requires PHP: 7.4
Stable tag: 1.1.1

IonPeptide-specific PHP-request and front-end performance protection based on the July 26, 2026 Lighthouse and request traces.

== Version 1.1.0 priorities ==

The supplied traces showed that the largest immediate scaling problem was not an image or a single CSS file. The custom global header automatically started expensive WooCommerce Store API requests on every page view:

* A full product-catalog request using 100 products per page.
* Multiple duplicate Store API cart requests during initial page load.

Those calls execute WordPress, WooCommerce, plugins, database queries, and object-cache work. Version 1.1.0 stops that automatic request storm while preserving the header features.

== Safe defaults ==

* Delay the full product-catalog request until the visitor opens or searches the product finder.
* Cache catalog pages in sessionStorage for 10 minutes per browser tab.
* Return a local empty-cart response when no WooCommerce cart/session cookie exists.
* Coalesce duplicate Store API cart GETs when a cart exists.
* Disable WooCommerce cart fragments for truly empty sessions outside cart and checkout.
* Apply context-aware asset unloading across the storefront instead of only the homepage.
* Keep checkout assets on checkout, cart assets on cart, account assets on account, product assets on product views, and affiliate assets on affiliate pages.
* Remove Dashicons for guests and customer-level users while keeping them for editors and administrators.
* Remove side-cart confetti JavaScript site-wide.
* Unload Elementor on non-Elementor pages and preserve the existing explicit homepage Elementor cleanup.
* Remove broken Elementor local-font stylesheet requests.
* Convert homepage hero delivery to media-aware picture markup without buffering the whole page.
* Preload only the active hero image.
* Add known image dimensions without frontend Media Library database lookups.
* Repair the identified accessibility markup at runtime without full-page HTML rewriting.
* Protect critical WooCommerce and side-cart scripts from Cloudflare Rocket Loader reordering.

== Caution switches ==

These remain disabled until individually tested:

* Interaction-only delay for Google Tag Manager/gtag, TikTok, Reddit, and Omnisend.
* WooCommerce base CSS removal from the homepage.
* Discount-rule asset removal from the homepage.
* Omnisend homepage JavaScript removal.
* jQuery Migrate removal.
* Theme Font Awesome removal.
* Anonymous edge-cache headers.

== Installation ==

1. Upload and activate the plugin ZIP, replacing the previous Ion Performance Guard version.
2. Open Settings > Ion Performance.
3. Leave the safe defaults enabled.
4. Purge Kinsta site cache and Cloudflare cache.
5. Test homepage, shop, product, account, cart, checkout, product finder, header search, and side cart.
6. Test both a customer with prior-order access and a customer without prior-order access.

== Verification ==

In browser DevTools > Network on a clean page load:

* There should be no automatic /wp-json/wc/store/v1/products?per_page=100 request before interacting with search/product finder.
* A visitor with no WooCommerce cart cookie should not make a PHP-backed /wp-json/wc/store/v1/cart request.
* A visitor with a cart should make at most one Store API cart GET during the initial duplicate sync window.

Response headers should include:

X-Ion-Performance-Guard: active; version=1.1.0

== Emergency bypass ==

Append this to any URL:

?ionpg_bypass=1

Permanent emergency bypass in wp-config.php:

define( 'IONPG_BYPASS', true );

== Important scope ==

This plugin removes the confirmed frontend request storm and unnecessary page-context assets. It cannot by itself guarantee capacity for thousands of simultaneous logged-in users. Logged-in traffic still executes PHP and should be profiled with Kinsta APM after this build is installed.

== Changelog ==

= 1.1.1 =
* Excluded cart, checkout, order-pay, and order-received screens from fetch interception.
* Preserved complete Store API response headers and status text when coalescing cart GET requests.
* Prevented loss of WooCommerce Nonce and Cart-Token response headers.
* Kept the large product-catalog lazy loader active on normal browsing pages.

= 1.1.0 =
* Added site-wide context-aware asset unloading.
* Deferred automatic full-catalog Store API loading until product-search interaction.
* Added 10-minute per-tab catalog response caching.
* Added local empty-cart responses for sessions with no cart cookie.
* Added duplicate Store API cart-request coalescing.
* Enabled empty-session cart-fragment removal outside cart and checkout.
* Enabled customer-level Dashicons removal.
* Enabled side-cart confetti removal.
* Added non-Elementor page detection and unloading.
* Preserved explicit homepage Elementor cleanup.
* Moved hero rewrites to the front-page content filter.
* Removed full-page output buffering from safe defaults.
* Removed runtime Media Library lookups for arbitrary image dimensions.
* Moved accessibility repairs to lightweight runtime JavaScript.

= 1.0.2 =
* Restored responsive hero delivery.
* Fixed literal newline characters appearing at the top-left of the page.
* Hardened picture wrapper CSS.

= 1.0.1 =
* Temporarily disabled the responsive hero rewrite while the artifact was investigated.

= 1.0.0 =
* Initial IonPeptide-specific release.
