ION Product Page Redesign v1.1.31.2
================================

COA fixes in this build
-----------------------
1. Keeps the v1.1.30 fix for simple products:
   - no variation selector = show the linked Lab Result immediately.

2. Fixes long variation labels:
   - "CJC-1295 10mg" is now interpreted as strength "10mg" rather than
     comparing the whole label against the Lab Result strength.

3. Supports stable COA mapping metadata from ION Lab Results v2.8:
   - _ioncoa_product_id
   - _ioncoa_variation_id
   - _ioncoa_strength

4. When a Lab Result has a saved variation ID, the product page matches the
   exact WooCommerce variation ID first. Strength matching remains as a safe
   fallback for old/unmapped records.

5. Backwards compatible:
   - old Lab Results without mapping metadata still use the previous title
     matching behavior until they are mapped.

Recommended companion update
----------------------------
Replace the existing WPCode snippet "Ion Lab Results Coa Current Batch" with
"Ion Lab Results Coa Current Batch - v2.8.0.txt" supplied with this build.
Do not run the old and new COA snippets together.

v1.1.31.1 product-selection / sale-price fix
---------------------------------------------
1. On a variable product with no WooCommerce default already selected, the page
   selects the first/lowest available option according to the product's actual
   variation dropdown order.
2. Existing WooCommerce defaults, URL-selected attributes, and restored browser
   selections are preserved and are never overwritten.
3. Out-of-stock variations are skipped when choosing the automatic default.
4. The automatic selection runs through WooCommerce's normal variation events,
   so sale pricing, stock, waitlist, gallery, quantity pricing and add-to-cart
   state update the same way they do after a manual option click.
5. Sale/regular prices are also applied directly from the selected variation's
   own price_html as a safe first-paint fallback.

Composite-strength COA fix (v1.1.31.2)
-----------------------------------------
- Read full combination strengths such as 5/5mg and 12/6mg.
- Support Lab Result titles with trailing (No DAC) or (With DAC) qualifiers.
- Preserve exact variation-ID matching priority and simple-product behavior.
- Requires the companion COA snippet update for accurate new auto-mapping.


1.1.32
- Refreshed purchase UI to match approved pack-card design while retaining Woo Discount Rules pricing logic.
- Added 1/3/5/10/20 pack cards, per-vial pricing, strike-through totals, recommended/best-value badges.
- Cleaned Strength, Quantity, Order Total, Add to Cart, and CoA presentation using ION colors.
- Replaced decorative text glyphs in refreshed controls with inline SVG icons.


v1.1.34 fixes: preview tiers are isolated from unrelated live WDR flash/sitewide tables; simple products no longer show an empty Strength heading; native stock is moved into the unified Quantity stock badge; desktop purchase column is widened and pack cards no longer compress into miniature tiles.

v1.1.35 hardening: live pack UI now fails closed and only binds to one positively identified bulk/tier/volume/pack WDR table; unrelated flash/sitewide/promo/coupon tables are excluded; preview pricing no longer reads any live WDR table.


v1.1.36 fix: corrected an escaped CSS block that browsers ignored; preview mock tier source is now always hidden; native simple-product In stock bar is suppressed with CSS + JS and only the Quantity-row stock badge is shown.
