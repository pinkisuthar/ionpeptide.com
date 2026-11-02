/**
 * Cross-Product Exchange — Frontend Product Search (per-item widgets)
 *
 * Each exchanged item has its OWN search widget, so a customer exchanging
 * several items can pick a different replacement product/variation for every
 * one of them within the same exchange request. Handles live product search,
 * variation picker, a per-item price-difference banner and hidden form field
 * hydration (per-item new_product_id / new_variation_id / cross_variants).
 *
 * Depends on: jQuery, zre_cross (wp_localize_script)
 */
;(function ($) {
	'use strict';

	if (typeof zre_cross === 'undefined') { return; }

	var AJAX_URL = zre_cross.ajax_url;
	var NONCE    = zre_cross.nonce;
	var i18n     = zre_cross.i18n || {};

	// debounce helper
	function debounce(fn, delay) {
		var timer;
		return function () {
			var ctx = this, args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
		};
	}

	// ── Per-widget init ────────────────────────────────────────────────────────
	function initWidget($wrap) {
		var $searchInput  = $wrap.find('.zre-cp-search-input');
		var $results      = $wrap.find('.zre-cp-results');
		var $selected     = $wrap.find('.zre-cp-selected');
		var $varWrap      = $wrap.find('.zre-cp-variation-wrap');
		var $attrSelects  = $wrap.find('.zre-cp-attr-selects');
		var $attrPrice    = $wrap.find('.zre-cp-attr-price');
		var $attrMsg      = $wrap.find('.zre-cp-attr-msg');
		var $banner       = $wrap.find('.zre-cp-price-banner');
		var $pidField     = $wrap.find('.zre-cp-pid-field');
		var $vidField     = $wrap.find('.zre-cp-vid-field');
		var itemId        = $wrap.data('item-id') || 0;
		var $row          = $wrap.closest('.request_item_details');

		var currentProduct = null; // { id, name, image, is_variable }
		var variationData  = null; // { attributes: [...], variations: [...] }

		// ── This item's exchange qty ─────────────────────────────────────────
		// Entire-order mode: the row's qty <select>. Single-item mode: the
		// hidden exchange_product_qty input rendered with the row.
		function getItemQty() {
			var $sel = $row.find('.order_quantity_number');
			if ($sel.length) {
				var q = parseInt($sel.val(), 10);
				return isNaN(q) ? 0 : q;
			}
			var $hidden = $row.find('input[name="exchange_data[exchange_items][' + itemId + '][exchange_product_qty]"]');
			if ($hidden.length) {
				var hq = parseInt($hidden.val(), 10);
				return isNaN(hq) || hq < 1 ? 1 : hq;
			}
			return 1;
		}

		// Whether this item is currently selected for exchange (checkbox mode).
		function isItemActive() {
			var $chk = $row.find('.product_checked');
			if ($chk.length && ! $chk.is(':checked')) { return false; }
			return getItemQty() > 0;
		}

		// Show the widget only while its item is selected for exchange.
		function updateVisibility() {
			if (isItemActive()) {
				$wrap.show();
				if (currentProduct && (! currentProduct.is_variable || ($vidField.val() || '').trim())) {
					calcPriceDiff();
				}
			} else {
				$wrap.hide();
				$banner.hide().removeClass('zre-banner-pay-more zre-banner-credit zre-banner-even');
			}
		}
		$wrap.data('zreUpdateVisibility', updateVisibility);
		updateVisibility();

		// ── Product search ──────────────────────────────────────────────────
		$searchInput.on('input', debounce(function () {
			var kw = $(this).val().trim();
			if (kw.length < 2) { $results.hide().empty(); return; }
			$results.html('<div class="zre-cp-loading">' + (i18n.searching || 'Searching…') + '</div>').show();

			$.post(AJAX_URL, {
				action: 'zre_search_products',
				nonce:  NONCE,
				keyword: kw
			}, function (res) {
				$results.empty();
				if (!res.success || !res.data || !res.data.length) {
					$results.html('<div class="zre-cp-no-results">' + (i18n.no_results || 'No products found.') + '</div>').show();
					return;
				}
				$.each(res.data, function (i, p) {
					var $rowEl = $('<div class="zre-cp-result-row" tabindex="0"></div>')
						.data('product', p)
						.html(
							'<img src="' + escHtml(p.image) + '" alt="" class="zre-cp-result-img">' +
							'<div class="zre-cp-result-info">' +
								'<span class="zre-cp-result-name">' + escHtml(p.name) + '</span>' +
								'<span class="zre-cp-result-price">' + p.price + '</span>' +
							'</div>'
						);
					$results.append($rowEl);
				});
				$results.show();
			});
		}, 300));

		// ── Pick a product from results ─────────────────────────────────────
		$wrap.on('click keypress', '.zre-cp-result-row', function (e) {
			if (e.type === 'keypress' && e.which !== 13) { return; }
			var p = $(this).data('product');
			selectProduct(p);
		});

		function selectProduct(p) {
			currentProduct = p;
			$results.hide().empty();
			$searchInput.val('');

			// Show selected card
			$selected.find('.zre-cp-selected-img').attr('src', p.image).attr('alt', p.name);
			$selected.find('.zre-cp-selected-name').text(p.name);
			$selected.find('.zre-cp-selected-price').html(p.price);
			$selected.show();

			// Clear variation state
			$attrSelects.empty();
			$attrPrice.hide().empty();
			$attrMsg.hide();
			$wrap.find('.zre-cp-attr-hidden').remove();
			variationData = null;
			$varWrap.hide();
			$vidField.val('');
			$banner.hide();

			if (p.is_variable) {
				$pidField.val(p.id);
				loadVariations(p.id);
			} else {
				// Simple product — set hidden fields immediately
				$pidField.val(p.id);
				$vidField.val('');
				calcPriceDiff();
			}
		}

		// ── Load variations for variable product ────────────────────────────
		function loadVariations(productId) {
			$attrSelects.empty();
			$attrPrice.hide().empty();
			$attrMsg.hide();
			variationData = null;

			$.post(AJAX_URL, {
				action:     'zre_get_product_variations',
				nonce:      NONCE,
				product_id: productId
			}, function (res) {
				if (!res.success || !res.data || !res.data.attributes || !res.data.variations) { return; }
				variationData = res.data;
				renderAttributeSelects();
				$varWrap.show();
			});
		}

		// ── WooCommerce-style attribute pickers ─────────────────────────────
		function renderAttributeSelects() {
			$attrSelects.empty();
			$.each(variationData.attributes, function (i, attr) {
				var $sel = $('<select class="zre-cp-attr-select"></select>').attr('data-attr', attr.key);
				$sel.append($('<option></option>').val('').text(i18n.choose_option || 'Choose an option'));
				$.each(attr.options, function (j, o) {
					$sel.append($('<option></option>').val(o.slug).text(o.name));
				});
				$attrSelects.append(
					$('<div class="zre-cp-attr-row"></div>')
						.append($('<label class="zre-cp-attr-label"></label>').text(attr.label))
						.append($sel)
				);
			});
		}

		// Submit the chosen attribute values with the form — PER ITEM — so
		// "Any …" variations resolve to concrete attributes for fulfilment.
		function setChosenAttrFields(chosen) {
			$wrap.find('.zre-cp-attr-hidden').remove();
			if (!chosen) { return; }
			$.each(chosen, function (key, val) {
				if (!val) { return; }
				$('<input type="hidden" class="zre-cp-attr-hidden">')
					.attr('name', 'exchange_data[exchange_items][' + itemId + '][cross_variants][' + key + ']')
					.val(val)
					.appendTo($wrap);
			});
		}

		function resolveVariation() {
			var chosen    = {};
			var allChosen = true;

			$attrSelects.find('.zre-cp-attr-select').each(function () {
				var val = $(this).val();
				if (!val) { allChosen = false; }
				chosen[$(this).data('attr')] = val;
			});

			// Reset selection state until a variation is resolved.
			$vidField.val('');
			setChosenAttrFields(null);
			$attrPrice.hide().empty();
			$attrMsg.hide();
			$banner.hide();

			if (!allChosen || !variationData) { return; }

			// A variation matches when every attribute equals the chosen value
			// or is '' ("Any"). Prefer the most specific match so an explicit
			// variation wins over an "Any" catch-all at the same combination.
			var best      = null;
			var bestScore = -1;
			$.each(variationData.variations, function (i, vr) {
				var ok    = true;
				var score = 0;
				$.each(vr.attributes, function (k, val) {
					if ('' === val || null === val) { return; } // Any — matches everything
					if (val !== chosen[k]) { ok = false; return false; }
					score++;
				});
				if (ok && score > bestScore) { best = vr; bestScore = score; }
			});

			if (best) {
				$vidField.val(best.variation_id);
				setChosenAttrFields(chosen);
				$attrPrice.html(best.price).show();
				calcPriceDiff();
			} else {
				$attrMsg.text(i18n.combo_unavailable || 'Sorry, this combination is unavailable.').show();
			}
		}

		$wrap.on('change', '.zre-cp-attr-select', resolveVariation);

		// ── Per-item price difference banner ─────────────────────────────────
		// Compares THIS item (its qty) against ITS chosen replacement only.
		function calcPriceDiff() {
			if (!currentProduct || !itemId) { return; }
			var qty = getItemQty();
			if (!qty || !isItemActive()) {
				$banner.hide().removeClass('zre-banner-pay-more zre-banner-credit zre-banner-even');
				return;
			}
			$.post(AJAX_URL, {
				action:           'zre_calculate_price_difference',
				nonce:            NONCE,
				original_items:   JSON.stringify([{ item_id: itemId, qty: qty }]),
				new_product_id:   $pidField.val(),
				new_variation_id: parseInt($vidField.val(), 10) || 0,
				new_qty:          qty
			}, function (res) {
				if (!res.success) { return; }
				var d = res.data;
				$banner
					.removeClass('zre-banner-pay-more zre-banner-credit zre-banner-even')
					.addClass('zre-banner-' + d.scenario.replace('_', '-'))
					.html(d.message)
					.show();
			});
		}
		$wrap.data('zreCalcPriceDiff', calcPriceDiff);

		// ── Clear selection ─────────────────────────────────────────────────
		$wrap.on('click', '.zre-cp-clear', function () {
			currentProduct = null;
			variationData  = null;
			$selected.hide();
			$varWrap.hide();
			$banner.hide().removeClass('zre-banner-pay-more zre-banner-credit zre-banner-even');
			$pidField.val('');
			$vidField.val('');
			$wrap.find('.zre-cp-attr-hidden').remove();
		});
	}

	// ── HTML escape helper ───────────────────────────────────────────────────
	function escHtml(str) {
		return $('<div>').text(String(str)).html();
	}

	// Refresh widget visibility + banner when item selection or qty changes.
	$(document).on('change', '.product_checked, .order_quantity_number', function () {
		$('.zre-cross-product-wrap.zre-cp-bound').each(function () {
			var update = $(this).data('zreUpdateVisibility');
			if (typeof update === 'function') { update(); }
		});
	});

	// Hide results on outside click.
	$(document).on('click', function (e) {
		if (!$(e.target).closest('.zre-cp-search-box').length) {
			$('.zre-cross-product-wrap .zre-cp-results').hide();
		}
	});

	// ── Init all widgets on page ─────────────────────────────────────────────
	function bindAll() {
		var $newWraps = $('.zre-cross-product-wrap').not('.zre-cp-bound');
		if (!$newWraps.length) { return; }
		$newWraps.each(function () {
			var $wrap = $(this);
			$wrap.addClass('zre-cp-bound');
			initWidget($wrap);
		});
		$('body').addClass('zre-cross-product-active');
		// Safety net: same-variation pickers never apply in cross-product mode.
		$('.exchange_variations').hide();
	}

	$(document).ready(function () {
		bindAll();

		// Guest return portal injects the exchange form via AJAX AFTER document
		// ready, so the initial bind can't see those wraps. Re-scan after any
		// AJAX completes; the zre-cp-bound marker prevents double-binding.
		$(document).ajaxComplete(function () {
			bindAll();
		});
	});

})(jQuery);
