=== Ion Shop Redesign ===
Contributors: BuildRightWeb
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.25
License: GPLv2 or later

Standalone WooCommerce shop archive redesign for Ion Peptide.

== Features ==
- Custom shop archive template
- Product search
- Category filtering
- Product sorting
- Product catalog sidebar
- AJAX filtering
- YITH Infinite Scroll compatibility
- Ion-styled product cards
- Support/live chat section
- Static current-media catalog with no frontend PHP or database request

== Changelog ==

= 1.0.25 =
- Uses each CSS/JS file modification time as its frontend asset version so direct Kinsta/FTP asset edits automatically bust browser and CDN caches.
- Keeps the plugin semantic version separate for normal plugin and media-catalog version tracking.

= 1.0.24 =
- Removes the 120-product cap from the Product Catalog sidebar so the full eligible published catalog can scroll through A-Z.
- Preserves existing visibility and prior-order exclusions, alphabetical sorting, shop pagination, and infinite-scroll behavior.

= 1.0.23 =
- Locks non-All dropdown views against the separate site-wide infinite-scroll loader and removes any unrelated products it attempts to inject.
- Leaves the All view and every other shop feature unchanged.

= 1.0.22 =
- Loads each selected dropdown category as one complete exact result set so no later infinite-scroll request can append products from other categories.
- Leaves the All view and every other shop feature unchanged.

= 1.0.21 =
- Keeps every category dropdown selection limited to products assigned to that category while preserving the unfiltered All view.

= 1.0.19 =
- Stops browsers from choosing obsolete renamed files left inside responsive srcset metadata.
- Forces shop cards to use the current backend src URL and removes stale lazy-load alternates.
- Uses the existing static per-product media manifest only when the plain src itself fails.
- No frontend PHP, MySQL, REST, or admin-ajax image lookup.
- No layout, filtering, sorting, stock, button, category, infinite-scroll, or mobile-column changes.

= 1.0.18 =
- Accepts current generated image sizes even when a media-renaming tool leaves the full-size metadata basename out of sync.
- Rebuilds the static shop media catalog once on upgrade.
- No changes to layout, filters, sorting, stock handling, buttons, or mobile columns.

= 1.0.17 =
- Repairs renamed product images from current backend attachment data.
- Uses one tiny static JSON catalog file; no frontend PHP/AJAX/database load.
