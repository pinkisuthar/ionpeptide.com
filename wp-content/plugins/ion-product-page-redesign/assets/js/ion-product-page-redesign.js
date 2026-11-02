(function ($) {
  'use strict';

  var cfg = window.ionProductRedesign || {};
  var state = {
    unitPrice: parseFloat(cfg.basePrice || 0) || 0,
    regularPrice: parseFloat(cfg.regularPrice || cfg.basePrice || 0) || 0,
    selectedVariation: null,
    isChangingVariation: false,
    variationChangeStartedAt: 0
  };

  function money(value) {
    value = parseFloat(value || 0) || 0;
    var decimals = typeof cfg.decimals === 'number' ? cfg.decimals : parseInt(cfg.decimals || 2, 10);
    var fixed = value.toFixed(decimals);
    var parts = fixed.split('.');
    var thousand = cfg.thousandSeparator || ',';
    var decimal = cfg.decimalSeparator || '.';
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousand);
    var formatted = parts.join(decimal);
    var symbol = cfg.currencySymbol || '$';
    var position = cfg.currencyPosition || 'left';

    if (position === 'right') return formatted + symbol;
    if (position === 'left_space') return symbol + ' ' + formatted;
    if (position === 'right_space') return formatted + ' ' + symbol;
    return symbol + formatted;
  }

  function cleanNumber(text) {
    if (!text) return 0;
    var normalized = String(text).replace(/[^0-9.,-]/g, '').replace(/,/g, '');
    var value = parseFloat(normalized);
    return isNaN(value) ? 0 : value;
  }


  function escapeHtml(text) {
    return $('<div>').text(String(text || '')).html();
  }

  function svgIcon(name, className) {
    var cls = className ? ' class="' + escapeHtml(className) + '"' : '';
    var common = ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
    var paths = {
      tag: '<path d="M20.59 13.41 11 3.83V3H4v7h.83l9.58 9.59a2 2 0 0 0 2.82 0l3.36-3.36a2 2 0 0 0 0-2.82Z"/><path d="M7.5 6.5h.01"/>',
      check: '<path d="m20 6-11 11-5-5"/>',
      cart: '<circle cx="9" cy="20" r="1"/><circle cx="19" cy="20" r="1"/><path d="M3 4h2l2.4 10.4a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6"/>'
    };
    return '<svg' + cls + common + '>' + (paths[name] || '') + '</svg>';
  }

  function liveBulkRows() {
    var best = null;

    // Fail closed: inspect each WDR table independently and only accept a table
    // that positively identifies itself as a bulk/tier/volume/pack pricing rule.
    // This prevents unrelated flash/sitewide/sale rules from ever being merged
    // into or selected as the pack-pricing source.
    $('.awdr-bulk-customizable-table').each(function () {
      var $tableWrap = $(this);
      var $rows = $tableWrap.find('tr.bulk_table_row');
      if (!$rows.length) return;

      var title = '';
      var mins = [];
      $rows.each(function () {
        var $row = $(this);
        var rowTitle = $.trim($row.find('.wdr_bulk_title').text());
        if (rowTitle && !title) title = rowTitle;

        var rangeText = $.trim($row.find('.wdr_bulk_range').text());
        var match = rangeText.match(/(\d+)/);
        var min = match ? parseInt(match[1], 10) : 0;
        if (min >= 3 && mins.indexOf(min) === -1) mins.push(min);
      });

      var normalizedTitle = String(title || '').toLowerCase();
      var positiveTitle = /(bulk|tier|volume|pack|multi(?:[- ]?buy)?|quantity)/i.test(normalizedTitle);
      var negativeTitle = /(flash|site[ -]?wide|promo|promotion|coupon|first[ -]?order|clearance)/i.test(normalizedTitle);

      // A genuine ION bulk table must identify itself as bulk-style pricing and
      // expose more than one multi-quantity threshold. If it does not, show no
      // pack selector rather than risk binding to the wrong discount rule.
      if (!positiveTitle || negativeTitle || mins.length < 2) return;

      mins.sort(function (a, b) { return a - b; });
      if (mins[mins.length - 1] < 5) return;

      var $bulkRows = $rows.filter(function () {
        var rangeText = $.trim($(this).find('.wdr_bulk_range').text());
        var match = rangeText.match(/(\d+)/);
        var min = match ? parseInt(match[1], 10) : 0;
        return min >= 3;
      });

      var score = mins.length * 10;
      if (/(bulk|tier)/i.test(normalizedTitle)) score += 100;
      if (mins.indexOf(10) !== -1) score += 20;
      if (mins.indexOf(20) !== -1) score += 20;

      if (!best || score > best.score) {
        best = { score: score, rows: $bulkRows };
      }
    });

    return best ? best.rows : $();
  }

  function bulkRows() {
    if (bulkPreviewEnabled()) {
      return $('.ionpd-preview-bulk-source tr.bulk_table_row');
    }
    return liveBulkRows();
  }

  function bulkPreviewEnabled() {
    return !!cfg.bulkPreview;
  }

  function ensureBulkPreviewData() {
    if (!bulkPreviewEnabled()) {
      $('.ionpd-preview-bulk-source').remove();
      return false;
    }

    $('body').addClass('ionpd-bulk-preview-mode');

    var $controls = $('form.cart .ionpd-buy-controls').first();
    if ($controls.length && !$controls.find('.ionpd-bulk-preview-notice').length) {
      var notice = '<div class="ionpd-bulk-preview-notice" role="status">' +
        '<strong>Admin bulk preview</strong>' +
        '<span>Mock tiers only. Live WDR promotions are ignored and cart testing is blocked.</span>' +
        '</div>';
      $controls.prepend(notice);
    }

    // Always use an isolated mock source in preview mode. Never borrow rows from
    // live WDR tables because those may belong to sitewide/flash promotions.
    var $previewSource = $('.ionpd-preview-bulk-source').first();
    if (!$previewSource.length) {
      $previewSource = $('<div class="ionpd-preview-bulk-source" aria-hidden="true" style="display:none!important"></div>');
      $('form.cart').first().prepend($previewSource);
    }

    var tiers = $.isArray(cfg.bulkPreviewTiers) ? cfg.bulkPreviewTiers : [];
    if (!tiers.length) {
      tiers = [
        { min: 3, max: 4, discount: 5 },
        { min: 5, max: 9, discount: 10 },
        { min: 10, max: 19, discount: 15 },
        { min: 20, max: 9999, discount: 20 }
      ];
    }

    var rows = '';
    $.each(tiers, function (_, tier) {
      var min = parseInt(tier.min, 10) || 1;
      var max = parseInt(tier.max, 10) || min;
      var discount = parseFloat(tier.discount || 0) || 0;
      var range = max >= 9999 ? min + '+' : min + '-' + max;
      rows += '<tr class="bulk_table_row ionpd-preview-tier">' +
        '<td class="wdr_bulk_range">' + escapeHtml(range) + '</td>' +
        '<td class="wdr_table_discounted_value">' + escapeHtml(discount) + '%</td>' +
        '<td class="wdr_bulk_title">' + escapeHtml(discount) + '% off</td>' +
        '<td class="wdr_table_discounted_price"></td>' +
        '</tr>';
    });

    $previewSource.attr('style', 'display:none!important').hide().html('<table aria-hidden="true"><tbody>' + rows + '</tbody></table>');
    return true;
  }

  function bindBulkPreviewSafety() {
    if (!bulkPreviewEnabled() || document.documentElement.getAttribute('data-ionpd-bulk-preview-bound') === '1') return;
    document.documentElement.setAttribute('data-ionpd-bulk-preview-bound', '1');

    $(document).on('click.ionpdBulkPreview', 'form.cart .single_add_to_cart_button, .ionpd-visible-atc, [data-ionpd-sticky-add]', function (event) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return false;
    });

    $(document).on('submit.ionpdBulkPreview', 'form.cart', function (event) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return false;
    });
  }

  function priceMarkup(price, regular) {
    price = parseFloat(price || 0) || 0;
    regular = parseFloat(regular || 0) || price;

    if (price > 0 && regular > price + 0.005) {
      return '<span class="ionpd-sale-price"><del>' + escapeHtml(money(regular)) + '</del><ins>' + escapeHtml(money(price)) + '</ins></span>';
    }

    return escapeHtml(price ? money(price) : '—');
  }

  function setPriceHtml($target, price, regular) {
    var hasDiscount = parseFloat(regular || 0) > parseFloat(price || 0) + 0.005;
    $target.toggleClass('ionpd-has-discount', hasDiscount).html(priceMarkup(price, regular));
  }

  function readPricePairFromHtml(html) {
    if (!html) return { price: 0, regular: 0 };
    var $html = $('<div>').html(html);
    var sale = cleanNumber($html.find('ins .amount, ins .woocommerce-Price-amount').last().text());
    var regular = cleanNumber($html.find('del .amount, del .woocommerce-Price-amount').first().text());

    if (sale > 0) {
      return { price: sale, regular: regular > 0 ? regular : sale };
    }

    var amount = cleanNumber($html.find('.amount, .woocommerce-Price-amount').first().text());
    return { price: amount, regular: amount };
  }

  function getQty() {
    var $qty = $('form.cart').find('input.qty').first();
    var qty = parseInt($qty.val(), 10);
    return qty > 0 ? qty : 1;
  }

  function previewBaseUnitPrice() {
    if (!bulkPreviewEnabled()) return state.unitPrice || 0;

    // Preview tiers are visual-only and must never derive their base price from
    // any live WDR discount table. Use WooCommerce's current product/variation
    // price already loaded into state instead.
    return state.unitPrice || cleanNumber($('.summary > p.price .amount').first().text()) || 0;
  }

  function baseUnitPrice() {
    return bulkPreviewEnabled() ? previewBaseUnitPrice() : (state.unitPrice || cleanNumber($('.summary > p.price .amount').first().text()) || 0);
  }

  function getTierDiscount(qty) {
    var discount = 0;
    var tierPrice = 0;
    var activeRow = null;

    bulkRows().each(function () {
      var tier = parseBulkRow($(this));
      if (qty >= tier.min && qty <= tier.max) {
        activeRow = tier.row;
        if (tier.discount > 0) discount = tier.discount;
        if (tier.price > 0) tierPrice = tier.price;
      }
    });

    bulkRows().removeClass('ionpd-tier-active');
    if (activeRow) activeRow.addClass('ionpd-tier-active');

    return { discount: discount, tierPrice: tierPrice };
  }

  function currentUnitPrice(qty) {
    var tier = getTierDiscount(qty);
    var unit = baseUnitPrice();

    if (tier.tierPrice > 0) {
      unit = tier.tierPrice;
    } else if (tier.discount > 0) {
      unit = unit * (1 - (tier.discount / 100));
    }

    return { unit: unit, discount: tier.discount };
  }

  function updatePricePanel() {
    var qty = getQty();
    var pricing = currentUnitPrice(qty);
    var unit = pricing.unit || state.unitPrice || 0;
    var total = unit * qty;
    var originalUnit = state.unitPrice || unit;
    var regularUnit = state.regularPrice || originalUnit || unit;
    var regularTotal = regularUnit * qty;
    var saved = Math.max(0, regularTotal - total);

    setPriceHtml($('[data-ionpd-unit-price]'), unit, regularUnit);
    setPriceHtml($('[data-ionpd-order-total]'), total, regularTotal);
    $('[data-ionpd-qty-label]').text(qty > 1 ? '(' + qty + ' vials)' : '(1 vial)');

    if (saved > 0.005) {
      $('[data-ionpd-saving-label]').text('Savings: ' + money(saved)).show();
    } else if (pricing.discount > 0) {
      $('[data-ionpd-saving-label]').text('Volume savings: ' + pricing.discount + '%').show();
    } else {
      $('[data-ionpd-saving-label]').text('').hide();
    }

    var $button = $('form.cart').find('.single_add_to_cart_button').first();
    if (!bulkPreviewEnabled() && $button.length && !$button.hasClass('disabled') && total > 0 && $button.text().toLowerCase().indexOf('waitlist') === -1) {
      $button.text('Add to Cart - ' + money(total));
    }

    updateStickyTotals(total, saved, pricing.discount);
    updateActionRow(total);
    updateNudgeCard(qty, unit);
    renderPackSelector();
    updateStockLabel();
  }

  function addQtyControls() {
    $('form.cart .quantity').each(function () {
      var $wrap = $(this);
      var $input = $wrap.find('input.qty').first();
      if (!$input.length || $wrap.data('ionpd-ready')) return;

      $wrap.data('ionpd-ready', true);
      $('<button type="button" class="ionpd-qty-button ionpd-minus" aria-label="Decrease quantity">−</button>').insertBefore($input);
      $('<button type="button" class="ionpd-qty-button ionpd-plus" aria-label="Increase quantity">+</button>').insertAfter($input);
    });

    $(document).off('click.ionpdQty').on('click.ionpdQty', '.ionpd-qty-button', function () {
      var $button = $(this);
      var $input = $button.closest('.quantity').find('input.qty').first();
      var current = parseInt($input.val(), 10) || 1;
      var min = parseInt($input.attr('min'), 10) || 1;
      var max = parseInt($input.attr('max'), 10) || 0;
      var next = $button.hasClass('ionpd-plus') ? current + 1 : current - 1;

      if (next < min) next = min;
      if (max && next > max) next = max;

      $input.val(next).trigger('change');
    });
  }

  function insertUnitLabel() {
    var $qty = $('form.cart .quantity').first();
    if (!$qty.length || $('.ionpd-unit-label').length) return;
    $('<span class="ionpd-unit-label">' + getQty() + (getQty() === 1 ? ' vial' : ' vials') + '</span>').insertAfter($qty);
  }

  function updateUnitLabel() {
    var qty = getQty();
    $('.ionpd-unit-label').text(qty + (qty === 1 ? ' vial' : ' vials'));
  }

  function movePriceTools() {
    buildBuyLayout();
  }

  function normalizeBulkTable() {
    bulkRows().each(function () {
      var $row = $(this);
      if ($row.data('ionpd-normalized')) return;
      $row.data('ionpd-normalized', true);

      var discountLabel = $.trim($row.find('.wdr_bulk_title').text()) || $.trim($row.find('.wdr_table_discounted_value').text());
      var rangeLabel = $.trim($row.find('.wdr_bulk_range').text());
      var priceLabel = $.trim($row.find('.wdr_table_discounted_price').text());

      $row.attr('data-discount-label', discountLabel);
      $row.attr('data-range-label', rangeLabel);
      $row.attr('data-price-label', priceLabel);

      /* Keep the plugin's own discount column as the single source of truth.
         Older builds injected .ionpd-bulk-meta here, which caused duplicate
         labels like two 10% off / two 25% off entries after variation refreshes. */
      $row.find('.ionpd-bulk-meta').remove();
    });
  }

  function ensureActionRow() {
    var $product = $('.product.type-product').first();
    var $coa = $('.ionpd-coa-button').first();
    var $realButton = $('form.cart .single_add_to_cart_button').first();

    if (!$product.length || !$coa.length || !$realButton.length) return;

    var $row = $product.children('.ionpd-main-actions').first();
    if (!$row.length) {
      $row = $('<div class="ionpd-main-actions"></div>');
      var $tabs = $product.children('.woocommerce-tabs').first();
      if ($tabs.length) {
        $row.insertBefore($tabs);
      } else {
        $product.append($row);
      }
    }

    if (!$coa.closest('.ionpd-main-actions').length) {
      $row.append($coa);
    }

    var $visible = $row.find('.ionpd-visible-atc').first();
    if (!$visible.length) {
      $visible = $('<button type="button" class="ionpd-visible-atc"><span class="ionpd-atc-icon">' + svgIcon('cart', 'ionpd-svg') + '</span><em>Add to Cart</em></button>');
      $row.append($visible);
    }

    $visible.off('click.ionpdAtc').on('click.ionpdAtc', function (event) {
      if (bulkPreviewEnabled()) {
        event.preventDefault();
        return false;
      }
      $realButton.trigger('click');
    });

    $('body').addClass('ionpd-actions-ready').removeClass('ionpd-proxy-ready');
  }

  function updateActionRow(total) {
    var $visible = $('.ionpd-visible-atc').first();
    if (!$visible.length) return;

    var currentOos = isCurrentSelectionOutOfStock();
    var preview = bulkPreviewEnabled();
    var label = preview ? 'Preview only - cart disabled' : (currentOos ? 'Out of Stock' : (total && total > 0 ? 'Add to Cart - ' + money(total) : 'Add to Cart'));
    $visible.find('em').text(label);

    var $realButton = $('form.cart .single_add_to_cart_button').first();
    var disabled = preview || currentOos || !!($realButton.length && ($realButton.hasClass('disabled') || $realButton.is(':disabled')));
    $visible.prop('disabled', disabled).toggleClass('disabled', disabled);
    if (disabled) $visible.attr('aria-disabled', 'true');
    else $visible.removeAttr('aria-disabled');
  }

  function parseBulkRow($row) {
    var rangeText = $.trim($row.find('.wdr_bulk_range').text());
    var discountText = $.trim($row.find('.wdr_table_discounted_value').text()) || $.trim($row.find('.wdr_bulk_title').text());
    var priceText = $.trim($row.find('.wdr_table_discounted_price').text());
    var min = 0;
    var max = Infinity;
    var plusMatch = rangeText.match(/(\d+)\s*\+/);
    var rangeMatch = rangeText.match(/(\d+)\s*[-–]\s*(\d+)/);
    var singleMatch = rangeText.match(/^(\d+)$/);

    if (plusMatch) {
      min = parseInt(plusMatch[1], 10);
    } else if (rangeMatch) {
      min = parseInt(rangeMatch[1], 10);
      max = parseInt(rangeMatch[2], 10);
    } else if (singleMatch) {
      min = parseInt(singleMatch[1], 10);
      max = min;
    }

    return {
      row: $row,
      rangeText: rangeText,
      discountText: discountText,
      priceText: priceText,
      min: min,
      max: max,
      discount: cleanNumber(discountText),
      price: cleanNumber(priceText)
    };
  }

  function pricingForPackQty(qty) {
    qty = parseInt(qty, 10) || 1;
    var discount = 0;
    var tierPrice = 0;

    bulkRows().each(function () {
      var tier = parseBulkRow($(this));
      if (qty >= tier.min && qty <= tier.max) {
        if (tier.discount > 0) discount = tier.discount;
        if (tier.price > 0) tierPrice = tier.price;
      }
    });

    var unit = baseUnitPrice();
    if (tierPrice > 0) {
      unit = tierPrice;
    } else if (discount > 0) {
      unit = unit * (1 - (discount / 100));
    }

    return { unit: unit, discount: discount, tierPrice: tierPrice };
  }

  function packRangeForQty(qty) {
    qty = parseInt(qty, 10) || 1;
    if (qty >= 20) return 20;
    if (qty >= 10) return 10;
    if (qty >= 5) return 5;
    if (qty >= 3) return 3;
    return 1;
  }

  function setQtyFromPack(qty) {
    var $input = $('form.cart input.qty').first();
    if (!$input.length) return;

    var min = parseInt($input.attr('min'), 10) || 1;
    var max = parseInt($input.attr('max'), 10) || 0;
    qty = Math.max(min, parseInt(qty, 10) || 1);
    if (max && qty > max) qty = max;

    $input.val(qty).trigger('change');
  }

  function renderPackSelector() {
    var $controls = $('form.cart .ionpd-buy-controls').first();
    var $selectorRow = $controls.find('.ionpd-selector-row').first();
    var $qtyRow = $controls.find('.ionpd-quantity-row').first();
    var hasBulkRows = bulkRows().length > 0;

    if (!$controls.length || !$selectorRow.length) return;

    var hasStrengthChoices = $selectorRow.find('table.variations').length > 0;
    var $strengthLabel = $selectorRow.children('.ionpd-section-label--strength').first();
    if (hasStrengthChoices) {
      if (!$strengthLabel.length) {
        $strengthLabel = $('<div class="ionpd-section-label ionpd-section-label--strength">Strength</div>');
        $selectorRow.prepend($strengthLabel);
      }
      $selectorRow.removeClass('ionpd-selector-empty');
    } else {
      $strengthLabel.remove();
      $selectorRow.addClass('ionpd-selector-empty');
    }

    var $pack = $controls.find('.ionpd-pack-section').first();
    if (!hasBulkRows) {
      if ($pack.length) $pack.hide();
      return;
    }

    if (!$pack.length) {
      $pack = $('<section class="ionpd-pack-section" aria-label="Pack size"><div class="ionpd-pack-head"><h3>Pack Size</h3><span class="ionpd-pack-save">' + svgIcon('tag', 'ionpd-svg') + '<span>Buy more, save more.</span></span></div><div class="ionpd-pack-grid" role="group" aria-label="Choose pack size"></div></section>');
      if ($qtyRow.length) $pack.insertBefore($qtyRow);
      else $pack.insertAfter($selectorRow);
    }

    $pack.show();
    var $grid = $pack.find('.ionpd-pack-grid').first();
    var sizes = [1, 3, 5, 10, 20];
    var activePack = packRangeForQty(getQty());
    var regularUnit = state.regularPrice || state.unitPrice || 0;
    var html = '';

    $.each(sizes, function (_, packQty) {
      var pricing = pricingForPackQty(packQty);
      var unit = pricing.unit || baseUnitPrice() || 0;
      var total = unit * packQty;
      var regularTotal = regularUnit * packQty;
      var hasPriceDrop = regularTotal > total + 0.005;
      var active = activePack === packQty;
      var badge = '';
      if (packQty === 10) badge = '<span class="ionpd-pack-badge ionpd-pack-badge--recommended">Recommended</span>';
      if (packQty === 20) badge = '<span class="ionpd-pack-badge ionpd-pack-badge--best">Best Value</span>';

      var discountPill = pricing.discount > 0
        ? '<span class="ionpd-pack-discount">' + escapeHtml(pricing.discount) + '% off</span>'
        : '<span class="ionpd-pack-discount ionpd-pack-discount--empty" aria-hidden="true"></span>';

      html += '<button type="button" class="ionpd-pack-card' + (active ? ' is-active' : '') + '" data-ionpd-pack="' + packQty + '" aria-pressed="' + (active ? 'true' : 'false') + '">';
      html += badge;
      html += '<span class="ionpd-pack-name">' + packQty + (packQty === 1 ? ' VIAL' : ' VIALS') + '</span>';
      html += discountPill;
      html += '<span class="ionpd-pack-prices">';
      if (hasPriceDrop) html += '<del>' + escapeHtml(money(regularTotal)) + '</del>';
      else html += '<del class="is-empty" aria-hidden="true">&nbsp;</del>';
      html += '<strong>' + escapeHtml(money(total)) + '</strong>';
      html += '<small>' + escapeHtml(money(unit)) + ' / vial</small>';
      html += '</span></button>';
    });

    $grid.html(html);

    $grid.off('click.ionpdPack').on('click.ionpdPack', '.ionpd-pack-card', function () {
      setQtyFromPack($(this).attr('data-ionpd-pack'));
    });
  }

  function ensureNudgeCard() {
    var $pricePanel = $('[data-ionpd-price-panel]').first();
    if (!$pricePanel.length) return $();

    var $card = $('.ionpd-next-tier-card').first();
    if (!$card.length) {
      $card = $('<div class="ionpd-next-tier-card" data-ionpd-next-tier-card><div class="ionpd-next-tier-main"><span class="ionpd-next-tier-icon">' + svgIcon('tag', 'ionpd-svg') + '</span><strong data-ionpd-next-tier-title></strong></div><small>Unlock multi pricing</small><div class="ionpd-next-tier-track"><b data-ionpd-next-tier-progress></b></div></div>');
      $card.insertAfter($pricePanel);
    }
    return $card;
  }

  function updateNudgeCard(qty, unit) {
    var $card = ensureNudgeCard();
    if (!$card.length) return;

    var next = null;
    bulkRows().each(function () {
      var tier = parseBulkRow($(this));
      if (tier.min > qty && (!next || tier.min < next.min)) {
        next = tier;
      }
    });

    if (!next || !next.min || next.min === Infinity) {
      $card.hide();
      return;
    }

    var needed = Math.max(1, next.min - qty);
    var drop = 0;
    if (next.price > 0 && unit > next.price) {
      drop = unit - next.price;
    }

    var unitWord = needed === 1 ? 'unit' : 'units';
    var msg = 'Add ' + needed + ' more ' + unitWord;
    if (drop > 0.005) {
      msg += ' to reduce unit cost by ' + money(drop);
    } else if (next.discount > 0) {
      msg += ' to unlock ' + next.discount + '% off';
    } else {
      msg += ' to unlock multi pricing';
    }

    $card.find('[data-ionpd-next-tier-title]').text(msg);
    var progress = Math.max(4, Math.min(100, (qty / next.min) * 100));
    $card.find('[data-ionpd-next-tier-progress]').css('width', progress + '%');
    $card.show();
  }

  function buildBuyLayout() {
    var $form = $('form.cart').first();
    var $inner = $form.children('div[data-product_id]').first();
    if (!$form.length) return;
    if (!$inner.length) $inner = $form;

    var $vars = $form.find('table.variations').first();
    var $qty = $form.find('.quantity').first();
    var $unit = $('.ionpd-unit-label').first();
    var $pricePanel = $('[data-ionpd-price-panel]').first();
    var $bulkTable = $('.awdr-bulk-customizable-table').first();
    var $variationWrap = $form.find('.single_variation_wrap').first();

    var $controls = $form.find('.ionpd-buy-controls').first();
    if (!$controls.length) {
      $controls = $('<div class="ionpd-buy-controls"></div>');
      $inner.prepend($controls);
    }

    var $selectorRow = $controls.find('.ionpd-selector-row').first();
    if (!$selectorRow.length) {
      $selectorRow = $('<div class="ionpd-selector-row"></div>');
      $controls.prepend($selectorRow);
    }

    var $qtyRow = $controls.find('.ionpd-quantity-row').first();
    if (!$qtyRow.length) {
      $qtyRow = $('<div class="ionpd-quantity-row"></div>');
      $qtyRow.insertAfter($selectorRow);
    }

    if ($vars.length) $vars.detach().appendTo($selectorRow);

    var $qtyLabel = $qtyRow.children('.ionpd-section-label--quantity').first();
    if (!$qtyLabel.length) {
      $qtyLabel = $('<div class="ionpd-section-label ionpd-section-label--quantity">Quantity</div>');
      $qtyRow.prepend($qtyLabel);
    }

    if ($qty.length) $qty.detach().appendTo($qtyRow);
    if ($unit.length) $unit.detach().appendTo($qtyRow);

    var $stock = $controls.find('.ionpd-stock-label').first();
    if (!$stock.length) {
      $stock = $('<span class="ionpd-stock-label" aria-live="polite"></span>');
    }
    $stock.detach().appendTo($qtyRow);
    renderPackSelector();
    suppressNativeInStock();

    if ($pricePanel.length) $pricePanel.insertAfter($controls);
    ensureNudgeCard();
    var $nudge = $('.ionpd-next-tier-card').first();
    if ($nudge.length && $pricePanel.length) $nudge.insertAfter($pricePanel);
    if ($bulkTable.length) $bulkTable.insertAfter($nudge.length ? $nudge : $pricePanel.length ? $pricePanel : $controls);
    if ($variationWrap.length) $variationWrap.insertAfter($bulkTable.length ? $bulkTable : $nudge.length ? $nudge : $pricePanel.length ? $pricePanel : $controls);
  }

  function suppressNativeInStock() {
    // Keep WooCommerce's native simple-product stock node available as data,
    // but never render the old full-width green bar above the product title.
    $('.summary > p.stock.in-stock').attr('aria-hidden', 'true').css('display', 'none');
  }

  function updateStockLabel() {
    suppressNativeInStock();
    var $stock = $('.ionpd-stock-label').first();
    if (!$stock.length) return;
    var $p = $('.woocommerce-variation-availability p.stock, .ionpd-waitlist-slot p.stock, .summary > p.stock').first();
    var text = $.trim($p.text()).replace(/^✓\s*/, '') || '';
    if (!text) {
      $stock.hide().removeClass('is-in is-out');
      return;
    }
    var isIn = $p.hasClass('in-stock');
    var icon = isIn ? svgIcon('check', 'ionpd-svg') : '';
    $stock.html(icon + '<span>' + escapeHtml(text) + '</span>')
      .toggleClass('is-in', isIn)
      .toggleClass('is-out', $p.hasClass('out-of-stock'))
      .show();
  }


  function ensureWaitlistVisibility() {
    var $cart = $('form.cart').first();
    if (!$cart.length) return;

    var selector = [
      '.ionpd-waitlist-slot',
      '.wcwl_elements',
      '.wcwl_intro',
      '.wcwl_optin',
      '.wcwl_notice',
      '.wcwl_control',
      '.woocommerce_waitlist',
      '[class*="waitlist"]',
      '[id*="waitlist"]'
    ].join(', ');

    $cart.find(selector).not('.wcwl_hide, .wcwl_hide *').addClass('ionpd-waitlist-area');

    $cart.find('button, input[type="submit"], a').each(function () {
      var $el = $(this);
      var label = $.trim($el.text() || $el.val() || $el.attr('value') || '');
      if (/waitlist|back\s*in\s*stock|notify\s*me|notify/i.test(label)) {
        $el.addClass('ionpd-waitlist-action').prop('disabled', false).removeClass('disabled');
      }
    });
  }

  function getSelectedAttributeData($form) {
    var data = { attrs: {}, complete: true };
    $form.find('select[name^="attribute_"]').each(function () {
      var $select = $(this);
      var attrName = $select.data('attribute_name') || $select.attr('name') || '';
      var val = $select.val() || '';
      if (!attrName) return;
      data.attrs[attrName] = val;
      if (!val) data.complete = false;
    });
    return data;
  }

  function variationMatchesSelectedAttributes(variation, $form) {
    if (!variation || !variation.attributes || !$form || !$form.length) return false;
    var selected = getSelectedAttributeData($form);
    if (!selected.complete) return false;

    var matched = true;
    $.each(selected.attrs, function (attrName, selectedValue) {
      var variationValue = variation.attributes[attrName];
      if (typeof variationValue === 'undefined') {
        variationValue = variation.attributes['attribute_' + attrName.replace(/^attribute_/, '')];
      }
      if (String(variationValue || '') !== String(selectedValue || '')) {
        matched = false;
        return false;
      }
    });

    return matched;
  }

  function getCurrentVariation() {
    var $form = $('form.variations_form').first();
    if (!$form.length) return null;

    if (state.selectedVariation && variationMatchesSelectedAttributes(state.selectedVariation, $form)) {
      return state.selectedVariation;
    }

    var variations = getProductVariations($form);
    for (var i = 0; i < variations.length; i++) {
      if (variationMatchesSelectedAttributes(variations[i], $form)) {
        return variations[i];
      }
    }

    return null;
  }

  function isVariationOutOfStock(variation) {
    return !!(variation && !variationIsInStock(variation));
  }

  function isCurrentSelectionOutOfStock() {
    var now = Date.now ? Date.now() : new Date().getTime();
    if (state.isChangingVariation && (now - state.variationChangeStartedAt) < 180) {
      return false;
    }
    return isVariationOutOfStock(getCurrentVariation());
  }

  function ensureWaitlistSlot() {
    var $form = $('form.variations_form').first();
    if (!$form.length) return $();

    var $slot = $form.find('.ionpd-waitlist-slot').first();
    if ($slot.length) return $slot;

    $slot = $('<div class="ionpd-waitlist-slot" aria-live="polite"></div>').hide();

    var $variationAdd = $form.find('.woocommerce-variation-add-to-cart').first();
    var $singleWrap = $form.find('.single_variation_wrap').first();

    if ($variationAdd.length) {
      $slot.insertAfter($variationAdd);
    } else if ($singleWrap.length) {
      $singleWrap.append($slot);
    } else {
      $form.append($slot);
    }

    return $slot;
  }

  function waitlistHtmlFromVariation(variation) {
    var html = variation && variation.availability_html ? String(variation.availability_html) : '';
    return /wcwl|waitlist|woocommerce_waitlist/i.test(html) ? html : '';
  }

  function renderWaitlistForVariation(variation) {
    var $slot = ensureWaitlistSlot();
    if (!$slot.length) return;

    var $liveWaitlist = $('form.variations_form .woocommerce-variation-availability .wcwl_elements').first();
    if ($liveWaitlist.length && !$liveWaitlist.closest('.ionpd-waitlist-slot').length) {
      $slot.empty().append($liveWaitlist.detach());
    } else {
      var html = waitlistHtmlFromVariation(variation);
      if (html) {
        $slot.html(html);
      } else {
        $slot.html('<p class="stock out-of-stock">Out of stock</p>');
      }
    }

    $slot.find('.wcwl_nojs').removeClass('wcwl_nojs').addClass('wcwl_js');
    $slot.find('.woocommerce_waitlist').addClass('ionpd-waitlist-action');
    $slot.find('.wcwl_control').removeClass('ionpd-waitlist-action');
    $slot.show().addClass('is-active');
    ensureWaitlistVisibility();
  }

  function clearWaitlistSlot() {
    var $slot = $('form.variations_form .ionpd-waitlist-slot').first();
    if ($slot.length) {
      $slot.empty().hide().removeClass('is-active');
    }
  }

  function currentVariationCanBePurchased() {
    var $form = $('form.variations_form').first();
    if (!$form.length) return true;

    var selected = getSelectedAttributeData($form);
    if (!selected.complete) return false;

    var current = getCurrentVariation();
    return !!(current && variationIsInStock(current));
  }

  function beginVariationSelectionRefresh() {
    var $form = $('form.variations_form').first();
    if (!$form.length) return;

    state.selectedVariation = null;
    state.isChangingVariation = true;
    state.variationChangeStartedAt = Date.now ? Date.now() : new Date().getTime();

    clearWaitlistSlot();
    $('body').removeClass('ionpd-current-variation-oos');
    $('#ionpd-sticky-buy').removeClass('ionpd-selected-oos');
    setAddToCartBlockedForOos(false);
    updateStockLabel();
  }

  function setAddToCartBlockedForOos(blocked) {
    var $mainButton = $('form.cart .single_add_to_cart_button').first();
    var $visibleButton = $('.ionpd-visible-atc').first();
    var $stickyButton = $('[data-ionpd-sticky-add]').first();
    var $stickyBar = $('#ionpd-sticky-buy');

    if (blocked) {
      if ($mainButton.length) {
        if (!$mainButton.data('ionpd-original-label')) {
          $mainButton.data('ionpd-original-label', $.trim($mainButton.text()) || 'Add to Cart');
        }
        $mainButton
          .data('ionpd-oos-blocked', true)
          .prop('disabled', true)
          .addClass('disabled wc-variation-is-unavailable')
          .attr('aria-disabled', 'true')
          .text('Out of Stock');
      }

      if ($visibleButton.length) {
        $visibleButton
          .prop('disabled', true)
          .addClass('disabled')
          .attr('aria-disabled', 'true')
          .find('em').text('Out of Stock');
      }

      if ($stickyButton.length) {
        $stickyButton
          .prop('disabled', true)
          .addClass('disabled')
          .attr('aria-disabled', 'true')
          .text('Out of Stock');
      }

      $stickyBar.addClass('ionpd-sticky-disabled ionpd-selected-oos');
      $('body').addClass('ionpd-current-variation-oos');
      return;
    }

    $('body').removeClass('ionpd-current-variation-oos');
    $stickyBar.removeClass('ionpd-selected-oos');

    var canPurchaseCurrent = currentVariationCanBePurchased();

    if ($mainButton.length && $mainButton.data('ionpd-oos-blocked')) {
      $mainButton
        .data('ionpd-oos-blocked', false)
        .removeClass('wc-variation-is-unavailable')
        .prop('disabled', !canPurchaseCurrent)
        .toggleClass('disabled', !canPurchaseCurrent);

      if (!canPurchaseCurrent) $mainButton.attr('aria-disabled', 'true');
      else $mainButton.removeAttr('aria-disabled');

      var originalLabel = $mainButton.data('ionpd-original-label');
      if (originalLabel) $mainButton.text(originalLabel);
    }

    if ($visibleButton.length) {
      $visibleButton
        .prop('disabled', !canPurchaseCurrent)
        .toggleClass('disabled', !canPurchaseCurrent);
      if (!canPurchaseCurrent) $visibleButton.attr('aria-disabled', 'true');
      else $visibleButton.removeAttr('aria-disabled');
    }

    if ($stickyButton.length) {
      $stickyButton
        .prop('disabled', !canPurchaseCurrent)
        .toggleClass('disabled', !canPurchaseCurrent)
        .text('Add to Cart');
      if (!canPurchaseCurrent) $stickyButton.attr('aria-disabled', 'true');
      else $stickyButton.removeAttr('aria-disabled');
    }
  }

  function syncVariationAvailabilityUi(variation) {
    var now = Date.now ? Date.now() : new Date().getTime();

    /* During a swatch/select transition, WooCommerce briefly leaves the old
       variation data in the DOM. Do not let the previous OOS variation bleed
       into the new click while Woo resolves the real selected variation. */
    if (!variation && state.isChangingVariation && (now - state.variationChangeStartedAt) < 180) {
      clearWaitlistSlot();
      $('body').removeClass('ionpd-current-variation-oos');
      setAddToCartBlockedForOos(false);
      updateStockLabel();
      return;
    }

    var current = variation || getCurrentVariation();
    state.isChangingVariation = false;

    if (current && isVariationOutOfStock(current)) {
      state.selectedVariation = current;
      renderWaitlistForVariation(current);
      setAddToCartBlockedForOos(true);
      updateStockLabel();
      return;
    }

    if (current && variationIsInStock(current)) {
      state.selectedVariation = current;
      clearWaitlistSlot();
      setAddToCartBlockedForOos(false);
      updateStockLabel();
      return;
    }

    state.selectedVariation = null;
    clearWaitlistSlot();
    $('body').removeClass('ionpd-current-variation-oos');
    setAddToCartBlockedForOos(false);
    updateStockLabel();
  }

  function bindVariationAvailabilityGuards() {
    if (window.ionpdVariationAvailabilityGuardsBound) return;
    window.ionpdVariationAvailabilityGuardsBound = true;

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;

      var control = target.closest('form.cart .single_add_to_cart_button, .ionpd-visible-atc, [data-ionpd-sticky-add]');
      if (!control) return;

      var current = getCurrentVariation();
      if (!isVariationOutOfStock(current)) return;

      event.preventDefault();
      event.stopImmediatePropagation();
      syncVariationAvailabilityUi(current);
      return false;
    }, true);

    $(document).on('submit.ionpdOosGuard', 'form.variations_form', function (event) {
      var current = getCurrentVariation();
      if (!isVariationOutOfStock(current)) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      syncVariationAvailabilityUi(current);
      return false;
    });

    $(document).on('click.ionpdOosSync change.ionpdOosSync', 'form.variations_form .variable-item, form.variations_form select[name^="attribute_"], [data-ionpd-variant-value]', function () {
      beginVariationSelectionRefresh();
      window.setTimeout(function () { syncVariationAvailabilityUi(); }, 60);
      window.setTimeout(function () { syncVariationAvailabilityUi(); }, 220);
      window.setTimeout(function () { syncVariationAvailabilityUi(); }, 500);
    });
  }


  function getProductVariations($form) {
    var variations = $form.data('product_variations');

    if (!variations) {
      var raw = $form.attr('data-product_variations') || '';
      if (raw) {
        try {
          variations = JSON.parse(raw);
        } catch (e) {
          variations = [];
        }
      }
    }

    return $.isArray(variations) ? variations : [];
  }

  function variationIsInStock(variation) {
    if (!variation) return true;
    if (variation.is_in_stock === false) return false;
    if (variation.availability_html && String(variation.availability_html).indexOf('out-of-stock') !== -1) return false;
    return true;
  }

  function variationAttributeValue(variation, attrName) {
    if (!variation || !variation.attributes) return '';
    if (typeof variation.attributes[attrName] !== 'undefined') {
      return String(variation.attributes[attrName] || '');
    }

    var normalized = 'attribute_' + String(attrName || '').replace(/^attribute_/, '');
    if (typeof variation.attributes[normalized] !== 'undefined') {
      return String(variation.attributes[normalized] || '');
    }

    return '';
  }

  function firstSelectableOptionValue($select) {
    var value = '';
    $select.find('option').each(function () {
      var $option = $(this);
      if (value || !$option.val() || $option.prop('disabled')) return;
      value = String($option.val());
    });
    return value;
  }

  function optionRank($select, value) {
    var rank = 999999;
    if (!value) return 0;

    $select.find('option').each(function (index) {
      if (String($(this).val()) === String(value) && rank === 999999) {
        rank = index;
      }
    });

    return rank;
  }

  function defaultVariationCandidate($form) {
    var variations = getProductVariations($form);
    if (!variations.length) return null;

    var $selects = $form.find('select[name^="attribute_"]');
    var candidates = $.grep(variations, function (variation) {
      if (!variation || variation.variation_is_active === false || variation.variation_is_visible === false) return false;
      if (variation.is_purchasable === false) return false;
      return variationIsInStock(variation);
    });

    if (!candidates.length) return null;

    candidates.sort(function (a, b) {
      var result = 0;
      $selects.each(function () {
        if (result !== 0) return;
        var $select = $(this);
        var attrName = $select.data('attribute_name') || $select.attr('name') || '';
        var aValue = variationAttributeValue(a, attrName);
        var bValue = variationAttributeValue(b, attrName);
        var aRank = optionRank($select, aValue);
        var bRank = optionRank($select, bValue);
        if (aRank !== bRank) result = aRank < bRank ? -1 : 1;
      });
      return result;
    });

    return candidates[0] || null;
  }

  function syncSwatchUiToSelects($form) {
    $form.find('select[name^="attribute_"]').each(function () {
      var $select = $(this);
      var attrName = $select.data('attribute_name') || $select.attr('name') || '';
      var value = String($select.val() || '');
      var $items = $form.find('.variable-item[data-attribute_name="' + cssEscapeValue(attrName) + '"]');

      if (!$items.length) {
        $items = $form.find('.variable-item').filter(function () {
          return String($(this).attr('data-attribute_name') || '') === String(attrName);
        });
      }

      $items.removeClass('selected').attr('aria-checked', 'false');
      if (!value) return;

      $items.filter(function () {
        return String($(this).attr('data-value') || '') === value;
      }).addClass('selected').attr('aria-checked', 'true');
    });
  }

  function applyVariationPriceToState(variation) {
    if (!variation) return;

    if (typeof variation.display_price !== 'undefined') {
      state.unitPrice = parseFloat(variation.display_price) || state.unitPrice;
    }
    if (typeof variation.display_regular_price !== 'undefined') {
      state.regularPrice = parseFloat(variation.display_regular_price) || state.regularPrice || state.unitPrice;
    }
    if (variation.price_html) {
      var pair = readPricePairFromHtml(variation.price_html);
      if (pair.price > 0) state.unitPrice = pair.price;
      if (pair.regular > 0) state.regularPrice = pair.regular;
    }
  }

  function selectLowestVariationByDefault() {
    var $form = $('form.variations_form').first();
    if (!$form.length || $form.data('ionpd-default-selection-attempted')) return;

    var $selects = $form.find('select[name^="attribute_"]');
    if (!$selects.length) return;

    /* Preserve WooCommerce defaults, URL-driven selections and browser-restored choices. */
    var hasExistingSelection = false;
    $selects.each(function () {
      if ($(this).val()) hasExistingSelection = true;
    });

    $form.data('ionpd-default-selection-attempted', true);
    if (hasExistingSelection) {
      syncSwatchUiToSelects($form);
      return;
    }

    var variation = defaultVariationCandidate($form);
    if (!variation) return;

    state.isChangingVariation = true;
    state.variationChangeStartedAt = Date.now ? Date.now() : new Date().getTime();

    $selects.each(function () {
      var $select = $(this);
      var attrName = $select.data('attribute_name') || $select.attr('name') || '';
      var value = variationAttributeValue(variation, attrName);
      if (!value) value = firstSelectableOptionValue($select);
      if (!value) return;

      var exists = $select.find('option').filter(function () {
        return String($(this).val()) === String(value) && !$(this).prop('disabled');
      }).length > 0;
      if (exists) $select.val(value);
    });

    /* Fire WooCommerce's normal variation resolution so every dependent plugin
       (sale pricing, stock, waitlist, gallery and add-to-cart state) receives the
       same events it would receive after a customer click. */
    $selects.trigger('change');
    $form.trigger('check_variations');
    syncSwatchUiToSelects($form);
    applyVariationPriceToState(variation);
    state.selectedVariation = variation;
    updatePricePanel();

    window.setTimeout(function () {
      syncSwatchUiToSelects($form);
      $form.trigger('check_variations');
      updatePricePanel();
      syncStickyVariantActive();
      syncVariationAvailabilityUi();
      filterCoaBySelectedDose();
    }, 80);
  }

  function cssEscapeValue(value) {
    return String(value || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  function isOutOfStockOptionNode(node) {
    if (!node || !node.closest) return false;
    return !!node.closest('form.variations_form .variable-item.ionpd-variation-oos, [data-ionpd-variant-value].ionpd-variation-oos');
  }

  function bindOutOfStockBlocker() {
    /* v1.1.14: intentionally do not block out-of-stock variation clicks.
       WooCommerce Waitlist needs the customer to select the unavailable variation
       so it can render the correct back-in-stock notification form. */
    return;
  }

  function clearUnavailableSelection($form) {
    /* v1.1.14: do not clear an out-of-stock variation selection.
       The selected OOS variation is how the waitlist plugin knows which
       product/variation to attach the back-in-stock request to. */
    return;
  }

  function markOutOfStockVariations() {
    var $form = $('form.variations_form').first();
    if (!$form.length) return;

    var variations = getProductVariations($form);
    if (!variations.length) return;

    var values = {};

    $.each(variations, function (_, variation) {
      var attrs = variation && variation.attributes ? variation.attributes : {};
      var inStock = variationIsInStock(variation);

      $.each(attrs, function (attrName, value) {
        if (!value) return;

        var key = attrName + '||' + value;
        if (!values[key]) {
          values[key] = {
            attrName: attrName,
            value: value,
            seen: 0,
            inStock: 0
          };
        }

        values[key].seen++;
        if (inStock) values[key].inStock++;
      });
    });

    $form.find('.variable-item.ionpd-variation-oos')
      .removeClass('ionpd-variation-oos')
      .removeAttr('aria-disabled')
      .removeAttr('data-ionpd-oos')
      .each(function () {
        var $item = $(this);
        var oldTabindex = $item.attr('data-ionpd-prev-tabindex');
        if (typeof oldTabindex !== 'undefined') {
          if (oldTabindex === '') $item.removeAttr('tabindex');
          else $item.attr('tabindex', oldTabindex);
          $item.removeAttr('data-ionpd-prev-tabindex');
        }
      });

    $('[data-ionpd-variant-value].ionpd-variation-oos')
      .removeClass('ionpd-variation-oos')
      .removeAttr('aria-disabled')
      .removeAttr('data-ionpd-oos')
      .prop('disabled', false);

    $.each(values, function (_, item) {
      if (!item.seen || item.inStock > 0) return;

      var safeValue = cssEscapeValue(item.value);

      $form.find('.variable-item[data-attribute_name="' + item.attrName + '"][data-value="' + safeValue + '"], .variable-item[data-value="' + safeValue + '"]')
        .each(function () {
          var $item = $(this);
          if (typeof $item.attr('data-ionpd-prev-tabindex') === 'undefined') {
            $item.attr('data-ionpd-prev-tabindex', $item.attr('tabindex') || '');
          }
          $item
            .addClass('ionpd-variation-oos')
            .attr('data-ionpd-oos', '1')
            .removeAttr('aria-disabled');
        });

      $('[data-ionpd-variant-value="' + safeValue + '"]')
        .addClass('ionpd-variation-oos')
        .attr('data-ionpd-oos', '1')
        .removeAttr('aria-disabled')
        .prop('disabled', false);
    });

    clearUnavailableSelection($form);
  }

  function normalizeRelatedProducts() {
    $('.related.products li.product').each(function () {
      var $item = $(this);
      var $link = $item.find('a.woocommerce-LoopProduct-link').first();
      var href = $link.attr('href') || $item.find('a').first().attr('href') || '#';
      var $btn = $item.find('a.button').first();

      if ($btn.length) {
        $btn.text('Add to Cart').addClass('ionpd-related-add');
      }

      if (!$item.find('.ionpd-more-details').length) {
        $('<a class="ionpd-more-details">More Details</a>').attr('href', href).appendTo($item);
      }
    });
  }

  function setupCoaClick() {
    $(document).on('click', '[data-ionpd-coa-link]', function (e) {
      var $tabs = $('.woocommerce-tabs').first();
      var $details = $('#tab-details_tab').first();
      var $coa = $('#tab-coa_tab').first();
      if (!$tabs.length || !$coa.length) return;

      e.preventDefault();
      filterCoaBySelectedDose();
      $('body').addClass('ionpd-show-coa');
      $details.hide().removeClass('active').attr('aria-hidden', 'true');
      $coa.show().addClass('active').attr('aria-hidden', 'false');
      $('html, body').animate({ scrollTop: $tabs.offset().top - 120 }, 350);
    });
  }

  function normalizeCoaDose(value) {
    return String(value || '').toLowerCase().replace(/[^a-z0-9.]+/g, '');
  }

  function extractCoaDose(value) {
    var text = String(value || '');
    var match = text.match(/(?:\d+(?:\.\d+)?\s*(?:(?:mcg|mg|g|ml|iu|units?)\s*)?[\/+]\s*)+\d+(?:\.\d+)?\s*(?:mcg|mg|g|ml|iu|units?)|\d+(?:\.\d+)?\s*(?:mcg|mg|g|ml|iu|units?)/i);
    return match ? normalizeCoaDose(match[0]) : '';
  }

  function selectedCoaSelection(variation) {
    var dose = '';
    var variationId = 0;

    if (variation && variation.variation_id) {
      variationId = parseInt(variation.variation_id, 10) || 0;
    }

    if (variation && variation.attributes) {
      $.each(variation.attributes, function (name, value) {
        if (!dose) {
          dose = extractCoaDose(value);
        }
      });
    }

    if (!variationId) {
      variationId = parseInt($('form.variations_form input.variation_id, form.variations_form input[name="variation_id"]').first().val(), 10) || 0;
    }

    if (!dose) {
      $('form.variations_form select[name^="attribute_"]').each(function () {
        if (!dose) {
          dose = extractCoaDose($(this).val());
        }
      });
    }

    if (!dose) {
      var $active = $('form.variations_form .variable-item.selected, form.variations_form .variable-item[aria-checked="true"]').first();
      dose = extractCoaDose($active.attr('data-value') || $active.text() || '');
    }

    return {
      variationId: variationId,
      dose: dose
    };
  }

  function filterCoaBySelectedDose(variation) {
    var $results = $('.ionpd-live-coa-results').first();
    if (!$results.length) return;

    var $sections = $results.find('[data-ionpd-coa-dose]');
    var $empty = $results.find('.ionpd-live-coa-dose-empty').first();
    var $form = $('form.variations_form').first();

    /*
     * Simple products do not have a variation selector. Their mapped reports
     * should be visible immediately.
     */
    if (!$form.length) {
      $sections.prop('hidden', false).show();
      $empty.prop('hidden', true).hide();
      return;
    }

    var selection = selectedCoaSelection(variation);
    var dose = selection.dose;
    var variationId = selection.variationId;
    var visible = 0;

    $sections.each(function () {
      var $section = $(this);
      var sectionVariationId = parseInt($section.attr('data-ionpd-coa-variation'), 10) || 0;
      var sectionDose = normalizeCoaDose($section.attr('data-ionpd-coa-dose'));
      var matches = false;

      /* Prefer the saved Woo variation ID whenever the Lab Result is mapped. */
      if (sectionVariationId && variationId) {
        matches = sectionVariationId === variationId;
      } else if (dose) {
        matches = sectionDose === dose;
      }

      $section.prop('hidden', !matches).toggle(matches);
      if (matches) visible++;
    });

    if (!variationId && !dose) {
      $empty.text('Select a strength to view its lab reports.').prop('hidden', false).show();
    } else if (!visible) {
      $empty.text('No lab report is available for the selected strength.').prop('hidden', false).show();
    } else {
      $empty.prop('hidden', true).hide();
    }
  }

  function bindCoaVariationFilter() {
    var $form = $('form.variations_form').first();
    if (!$form.length || $form.data('ionpdCoaFilterBound')) return;
    $form.data('ionpdCoaFilterBound', true);

    $form.on('found_variation.ionpdCoa', function (event, variation) {
      filterCoaBySelectedDose(variation);
    });
    $form.on('reset_data.ionpdCoa hide_variation.ionpdCoa', function () {
      setTimeout(function () { filterCoaBySelectedDose(); }, 0);
    });
    $form.on('change.ionpdCoa', 'select[name^="attribute_"]', function () {
      setTimeout(function () { filterCoaBySelectedDose(); }, 0);
    });

    $(document).on('click.ionpdCoa', 'form.variations_form .variable-item', function () {
      setTimeout(function () { filterCoaBySelectedDose(); }, 30);
    });

    filterCoaBySelectedDose();
  }

  function setupLiveChat() {
    $(document).on('click', '.ionpd-support-button, .ion-team-live-chat-button', function (e) {
      if (window.Tawk_API && typeof window.Tawk_API.toggle === 'function') {
        e.preventDefault();
        window.Tawk_API.toggle();
      }
    });
  }

  function readInitialPriceFromDOM() {
    var pair = readPricePairFromHtml($('.woocommerce-variation-price').first().html());

    if (!pair.price && !$('form.variations_form').length) {
      pair = readPricePairFromHtml($('.summary > p.price').first().html() || cfg.priceHtml || '');
    }

    if (pair.price > 0) {
      state.unitPrice = pair.price;
      state.regularPrice = pair.regular || pair.price;
    }
  }

  function bindVariationEvents() {
    var $form = $('form.variations_form').first();
    if (!$form.length) return;

    $form.on('woocommerce_variation_select_change', function () {
      beginVariationSelectionRefresh();
    });

    $form.on('found_variation show_variation', function (event, variation) {
      state.isChangingVariation = false;
      state.selectedVariation = variation || null;
      syncVariationAvailabilityUi(variation || null);
      applyVariationPriceToState(variation);
      setTimeout(function () {
        addQtyControls();
        insertUnitLabel();
        movePriceTools();
        normalizeBulkTable();
        buildBuyLayout();
        updateStockLabel();
        markOutOfStockVariations();
        ensureWaitlistVisibility();
        normalizeRelatedProducts();
        ensureActionRow();
        setupDetailsPanel();
        setupStickyBuyBar();
        updateUnitLabel();
        updatePricePanel();
        syncVariationAvailabilityUi(variation || null);
        ensureWaitlistVisibility();
      }, 20);
    });

    $form.on('reset_data hide_variation', function () {
      state.selectedVariation = null;
      state.isChangingVariation = true;
      state.variationChangeStartedAt = Date.now ? Date.now() : new Date().getTime();
      clearWaitlistSlot();
      readInitialPriceFromDOM();
      setTimeout(function () {
        updatePricePanel();
        syncVariationAvailabilityUi();
        ensureWaitlistVisibility();
      }, 220);
    });
  }


  function normalizeDetailsCard() {
    var $panel = $('#tab-details_tab').first();
    if (!$panel.length || $panel.data('ionpd-normalized')) return;
    $panel.data('ionpd-normalized', true);

    $panel.find('h2').first().text('Product Details');

    var $tbody = $panel.find('table tbody').first();
    if (!$tbody.length) return;

    var rows = [];
    $tbody.find('tr').each(function () {
      var $row = $(this);
      var label = $.trim($row.find('td').first().text()).replace(/\s+/g, ' ');
      var value = $.trim($row.find('td').eq(1).text()).replace(/\s+/g, ' ');
      if (!label || !value) return;

      if (/^form$/i.test(label)) label = 'Physical form';
      if (/^intended use$/i.test(label)) label = 'Intended use';
      if (/^cas$/i.test(label)) label = 'CAS Number';

      $row.find('td').first().html('<strong>' + label + '</strong>');
      rows.push({ label: label, key: label.toLowerCase(), row: $row.detach() });
    });

    var hasSku = rows.some(function (item) { return item.key === 'sku'; });
    if (!hasSku) {
      var sku = cfg.sku || $('.product_meta .sku').first().text() || 'N/A';
      rows.push({
        label: 'SKU',
        key: 'sku',
        row: $('<tr><td><strong>SKU</strong></td><td></td></tr>').find('td').eq(1).text(sku).end().end()
      });
    }

    var order = [
      'chemical formula',
      'cas number',
      'molecular mass',
      'storage',
      'physical form',
      'shelf life',
      'intended use',
      'sku'
    ];

    order.forEach(function (key) {
      rows.forEach(function (item) {
        if (item.key === key) {
          $tbody.append(item.row);
        }
      });
    });

    rows.forEach(function (item) {
      if (order.indexOf(item.key) === -1) {
        $tbody.append(item.row);
      }
    });
  }

  function setupDetailsPanel() {
    var $tabs = $('.woocommerce-tabs').first();
    var $details = $('#tab-details_tab').first();
    var $coa = $('#tab-coa_tab').first();

    normalizeDetailsCard();

    if ($tabs.length && $details.length) {
      $('body').removeClass('ionpd-show-coa');
      $tabs.find('ul.tabs').hide();
      $details.show().addClass('active').attr('aria-hidden', 'false');
      if ($coa.length) $coa.hide().removeClass('active').attr('aria-hidden', 'true');
    }
  }

  function buildStickyVariants() {
    var $wrap = $('[data-ionpd-sticky-variants]').first();
    var $form = $('form.variations_form').first();
    if (!$wrap.length || !$form.length || $wrap.data('ionpd-built')) return;

    var $select = $form.find('select[name^="attribute_"]').first();
    if (!$select.length) {
      $wrap.hide();
      return;
    }

    $wrap.data('ionpd-built', true).empty();
    $select.find('option').each(function () {
      var val = $(this).val();
      if (!val) return;
      var label = $.trim($(this).text());
      var $btn = $('<button type="button" class="ionpd-sticky-variant"></button>')
        .text(label)
        .attr('data-ionpd-variant-value', val);
      $wrap.append($btn);
    });

    syncStickyVariantActive();
  }

  function syncStickyVariantActive() {
    var $select = $('form.variations_form select[name^="attribute_"]').first();
    if (!$select.length) return;
    var val = $select.val() || '';
    $('[data-ionpd-variant-value]').removeClass('active').filter(function () {
      return String($(this).attr('data-ionpd-variant-value')) === String(val);
    }).addClass('active');
  }

  function syncStickyQtyFromMain() {
    var qty = getQty();
    $('[data-ionpd-sticky-qty-input]').val(qty);
    $('[data-ionpd-sticky-unit-label]').text(qty + (qty === 1 ? ' unit' : ' units'));
  }

  function updateStickyTotals(total, saved, discount) {
    var $bar = $('#ionpd-sticky-buy');
    if (!$bar.length) return;

    $bar.find('[data-ionpd-sticky-total]').text(total ? money(total) : '—');

    var $save = $bar.find('[data-ionpd-sticky-savings]');
    if (saved && saved > 0.005) {
      $save.text('savings: ' + money(saved)).show();
    } else if (discount && discount > 0) {
      $save.text('savings: ' + discount + '%').show();
    } else {
      $save.text('').hide();
    }

    syncStickyQtyFromMain();
    syncStickyVariantActive();

    var $mainButton = $('form.cart').find('.single_add_to_cart_button').first();
    var currentOos = isCurrentSelectionOutOfStock();
    var preview = bulkPreviewEnabled();
    var disabled = preview || currentOos || !!($mainButton.length && ($mainButton.hasClass('disabled') || $mainButton.is(':disabled')));
    $bar.toggleClass('ionpd-sticky-disabled', disabled).toggleClass('ionpd-selected-oos', currentOos);
    var $stickyAdd = $bar.find('[data-ionpd-sticky-add]')
      .prop('disabled', disabled)
      .toggleClass('disabled', disabled)
      .text(preview ? 'Preview only' : (currentOos ? 'Out of Stock' : 'Add to Cart'));
    if (disabled) $stickyAdd.attr('aria-disabled', 'true');
    else $stickyAdd.removeAttr('aria-disabled');
  }

  function setupStickyBuyBar() {
    var $bar = $('#ionpd-sticky-buy');
    var $mainForm = $('form.cart').first();
    if (!$bar.length || !$mainForm.length || $bar.data('ionpd-ready')) return;

    $bar.data('ionpd-ready', true);
    buildStickyVariants();
    syncStickyQtyFromMain();

    $(document).on('click', '[data-ionpd-variant-value]', function (event) {
      if ($(this).prop('disabled')) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return false;
      }

      var val = $(this).attr('data-ionpd-variant-value');
      var $select = $('form.variations_form select[name^="attribute_"]').first();
      var $swatch = $('form.variations_form .variable-item[data-value="' + cssEscapeValue(val) + '"]').first();

      beginVariationSelectionRefresh();

      if ($swatch.length) {
        $swatch.trigger('click');
      } else if ($select.length) {
        $select.val(val).trigger('change');
      }

      setTimeout(function () {
        syncStickyVariantActive();
        updatePricePanel();
        syncVariationAvailabilityUi();
      }, 220);
    });

    $(document).on('click', '[data-ionpd-sticky-minus], [data-ionpd-sticky-plus]', function () {
      var $input = $('form.cart input.qty').first();
      if (!$input.length) return;
      var current = parseInt($input.val(), 10) || 1;
      var min = parseInt($input.attr('min'), 10) || 1;
      var max = parseInt($input.attr('max'), 10) || 0;
      var next = $(this).is('[data-ionpd-sticky-plus]') ? current + 1 : current - 1;

      if (next < min) next = min;
      if (max && next > max) next = max;

      $input.val(next).trigger('change');
    });

    $(document).on('change input', '[data-ionpd-sticky-qty-input]', function () {
      var $input = $('form.cart input.qty').first();
      if (!$input.length) return;
      var val = parseInt($(this).val(), 10) || 1;
      var min = parseInt($input.attr('min'), 10) || 1;
      var max = parseInt($input.attr('max'), 10) || 0;
      if (val < min) val = min;
      if (max && val > max) val = max;
      $input.val(val).trigger('change');
    });

    $(document).on('click', '[data-ionpd-sticky-add]', function () {
      var $button = $('form.cart').find('.single_add_to_cart_button').first();
      if (!$button.length) return;
      $button.trigger('click');
    });

    $(document).on('click change', 'form.variations_form .variable-item, form.variations_form select[name^="attribute_"]', function (event) {
      beginVariationSelectionRefresh();
      setTimeout(function () {
        syncStickyVariantActive();
        updatePricePanel();
        syncVariationAvailabilityUi();
      }, 220);
    });

    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        var entry = entries[0];
        var show = !entry.isIntersecting && window.pageYOffset > 420;
        $bar.toggleClass('ionpd-sticky-visible', show).attr('aria-hidden', show ? 'false' : 'true');
      }, { threshold: 0 });
      observer.observe($mainForm[0]);
    } else {
      $(window).on('scroll.ionpdSticky', function () {
        var showFallback = window.pageYOffset > 720;
        $bar.toggleClass('ionpd-sticky-visible', showFallback).attr('aria-hidden', showFallback ? 'false' : 'true');
      });
    }
  }


  /**
   * Read the current product media from a tiny static JSON file in /uploads.
   *
   * This repairs stale full-page-cache HTML without calling admin-ajax, REST,
   * PHP, or MySQL. The only extra request is a very small static file served by
   * Nginx, so it is safe even when product traffic increases.
   */
  function currentProductId() {
    var id = parseInt(cfg.productId || 0, 10) || 0;
    if (id > 0) return id;

    var bodyClass = document.body ? String(document.body.className || '') : '';
    var bodyMatch = bodyClass.match(/(?:^|\s)postid-(\d+)(?:\s|$)/);
    if (bodyMatch) return parseInt(bodyMatch[1], 10) || 0;

    var $form = $('form.variations_form').first();
    id = parseInt($form.attr('data-product_id') || $form.data('product_id') || 0, 10) || 0;
    if (id > 0) return id;

    id = parseInt(
      $('form.cart input[name="product_id"]').first().val() ||
      $('form.cart button[name="add-to-cart"]').first().val() ||
      0,
      10
    ) || 0;

    return id;
  }

  function staticManifestUrl(productId) {
    if (cfg.mediaManifestUrl) {
      return String(cfg.mediaManifestUrl);
    }

    var uploadBase = '';
    var imageSrc = $('.woocommerce-product-gallery img[src*="/wp-content/uploads/"]').first().attr('src') || '';
    var uploadMatch = imageSrc.match(/^(.*\/wp-content\/uploads)\//i);
    if (uploadMatch) {
      uploadBase = uploadMatch[1];
    }

    if (!uploadBase) {
      var scriptSrc = $('script[src*="ion-product-page-redesign.js"]').last().attr('src') || '';
      try {
        var parsed = new URL(scriptSrc, window.location.href);
        var contentIndex = parsed.pathname.indexOf('/wp-content/');
        if (contentIndex !== -1) {
          uploadBase = parsed.origin + parsed.pathname.substring(0, contentIndex) + '/wp-content/uploads';
        }
      } catch (e) {}
    }

    if (!uploadBase) {
      uploadBase = window.location.origin + '/wp-content/uploads';
    }

    return uploadBase.replace(/\/+$/, '') + '/ion-product-media/product-' + productId + '.json';
  }

  function validMediaRecord(record) {
    return !!(record && typeof record === 'object' && record.url);
  }

  function applyRecordToSlide($slide, record, index) {
    if (!$slide.length || !validMediaRecord(record)) return;

    var url = String(record.url);
    var alt = String(record.alt || record.title || 'Product image');
    var width = parseInt(record.width || 1420, 10) || 1420;
    var height = parseInt(record.height || 1420, 10) || 1420;

    $slide
      .attr('data-thumb', url)
      .attr('data-thumb-alt', alt)
      .attr('data-ionpd-live-media', '1');

    var $link = $slide.find('a').first();
    if (!$link.length) {
      $link = $('<a></a>').appendTo($slide);
    }
    $link.attr('href', url);

    var $img = $link.find('img').first();
    if (!$img.length) {
      $img = $('<img decoding="async">').appendTo($link);
    }

    $img
      .removeAttr('srcset sizes data-srcset data-lazy-srcset')
      .attr({
        src: url,
        alt: alt,
        width: width,
        height: height,
        'data-src': url,
        'data-lazy-src': url,
        'data-original': url,
        'data-large_image': url,
        'data-large_image_width': width,
        'data-large_image_height': height,
        'data-caption': ''
      });

    if (index === 0) {
      $img.addClass('wp-post-image').attr({
        'data-o_src': url,
        'data-o_srcset': '',
        'data-o_sizes': '',
        'data-o_title': String(record.title || ''),
        'data-o_alt': alt,
        'data-o_full_src': url
      });
      $link.attr('data-o_href', url);
    }
  }

  function rebuildBaseGalleryFromManifest(manifest) {
    var records = [];
    if (validMediaRecord(manifest.main)) records.push(manifest.main);

    if ($.isArray(manifest.gallery)) {
      $.each(manifest.gallery, function (_, record) {
        if (validMediaRecord(record)) records.push(record);
      });
    }

    if (!records.length) return;

    var $gallery = $('.woocommerce-product-gallery').first();
    var $wrapper = $gallery.find('.woocommerce-product-gallery__wrapper').first();
    if (!$gallery.length || !$wrapper.length) return;

    $wrapper.children('.clone').remove();
    $wrapper.children('.woocommerce-product-gallery__image').remove();

    $.each(records, function (index, record) {
      var $slide = $('<div class="woocommerce-product-gallery__image"></div>');
      applyRecordToSlide($slide, record, index);
      $wrapper.append($slide);
    });

    $gallery.attr('data-ionpd-current-slide', '0');
    $gallery.find('.ionpd-gallery-thumbs').remove();
    normalizeProductGallery();
  }

  function wooImageData(record) {
    if (!validMediaRecord(record)) return null;

    var width = parseInt(record.width || 1420, 10) || 1420;
    var height = parseInt(record.height || 1420, 10) || 1420;
    var url = String(record.url);
    var alt = String(record.alt || record.title || 'Product image');

    return {
      title: String(record.title || ''),
      caption: '',
      url: url,
      alt: alt,
      src: url,
      srcset: '',
      sizes: '',
      full_src: url,
      full_src_w: width,
      full_src_h: height,
      gallery_thumbnail_src: url,
      gallery_thumbnail_src_w: width,
      gallery_thumbnail_src_h: height,
      thumb_src: url,
      thumb_src_w: width,
      thumb_src_h: height,
      src_w: width,
      src_h: height
    };
  }

  function patchVariationImagesFromManifest(manifest) {
    var $form = $('form.variations_form').first();
    if (!$form.length || !manifest.variations || typeof manifest.variations !== 'object') return;

    var variations = $form.data('product_variations');
    if (!$.isArray(variations)) {
      var raw = $form.attr('data-product_variations') || '';
      try {
        variations = raw ? JSON.parse(raw) : [];
      } catch (e) {
        variations = [];
      }
    }

    if (!$.isArray(variations) || !variations.length) return;

    $.each(variations, function (_, variation) {
      if (!variation || !variation.variation_id) return;

      var key = String(variation.variation_id);
      if (!Object.prototype.hasOwnProperty.call(manifest.variations, key)) return;

      var image = wooImageData(manifest.variations[key]);
      if (image) {
        variation.image = $.extend({}, variation.image || {}, image);
      }
    });

    $form.data('product_variations', variations);

    try {
      $form.attr('data-product_variations', JSON.stringify(variations));
    } catch (e) {}

    /*
     * WooCommerce keeps its own in-memory variationData copy. This event tells
     * it to reload that copy from the freshly patched form data.
     */
    $form.trigger('reload_product_variations');
    $form.trigger('check_variations');

    window.setTimeout(function () {
      normalizeProductGallery();
    }, 80);
    window.setTimeout(function () {
      normalizeProductGallery();
    }, 300);
  }

  function applyStaticMediaManifest(manifest) {
    if (!manifest || typeof manifest !== 'object') return;

    rebuildBaseGalleryFromManifest(manifest);
    patchVariationImagesFromManifest(manifest);

    document.documentElement.setAttribute('data-ionpd-live-media', '1');
  }

  function refreshStaticMediaManifest() {
    if (!window.fetch || document.documentElement.getAttribute('data-ionpd-media-requested') === '1') {
      return;
    }

    var productId = currentProductId();
    if (!productId) return;

    var url = staticManifestUrl(productId);
    if (!url) return;

    document.documentElement.setAttribute('data-ionpd-media-requested', '1');

    /*
     * A unique query value prevents Cloudflare/browser cache from returning an
     * older JSON file after a filename change. This still hits only a static
     * file, never WordPress/PHP.
     */
    var separator = url.indexOf('?') === -1 ? '?' : '&';
    var liveUrl = url + separator + 'ionpd_live=' + Date.now();

    window.fetch(liveUrl, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('Manifest unavailable');
        return response.json();
      })
      .then(function (manifest) {
        if (parseInt(manifest.product_id || 0, 10) !== productId) return;
        applyStaticMediaManifest(manifest);
      })
      .catch(function () {
        /*
         * Keep the normal WooCommerce gallery untouched if the static file has
         * not been generated yet or the upload directory is temporarily blocked.
         */
      });
  }



  function normalizeProductGallery() {
    var $gallery = $('.woocommerce-product-gallery').first();
    if (!$gallery.length) return;

    var $wrapper = $gallery.find('.woocommerce-product-gallery__wrapper').first();
    if (!$wrapper.length) return;

    var $slides = $wrapper.children('.woocommerce-product-gallery__image').not('.clone');
    if (!$slides.length) return;

    $gallery.addClass('ionpd-gallery-normalized ionpd-gallery-stage-ready').css({
      opacity: '1',
      visibility: 'visible'
    });

    function killWooZoom() {
      $gallery.find('.zoomImg').remove();
      $gallery.find('.woocommerce-product-gallery__image a, .woocommerce-product-gallery__image img').each(function () {
        try { $(this).trigger('zoom.destroy'); } catch (e) {}
      });
    }

    killWooZoom();

    var $viewport = $gallery.find('.flex-viewport').first();
    if ($viewport.length) {
      $viewport.css({
        overflow: 'hidden',
        height: 'auto',
        width: '100%',
        maxWidth: '100%',
        margin: '0',
        padding: '0',
        transform: 'none'
      });
    }

    $wrapper.css({
      position: 'relative',
      display: 'block',
      transform: 'none',
      transition: 'none',
      left: '0',
      right: 'auto',
      width: '100%',
      maxWidth: '100%',
      marginLeft: '0',
      marginRight: '0'
    });

    $slides.each(function () {
      var $slide = $(this);
      $slide.css({
        position: 'relative',
        top: 'auto',
        right: 'auto',
        bottom: 'auto',
        left: 'auto',
        float: 'none',
        width: '100%',
        maxWidth: '100%',
        height: 'auto',
        margin: '0',
        padding: '0',
        opacity: '1',
        visibility: 'visible',
        transform: 'none'
      });
      $slide.find('a').css({
        width: '100%',
        height: '100%',
        alignItems: 'center',
        justifyContent: 'center',
        textAlign: 'center'
      });
      $slide.find('img').css({
        opacity: '1',
        visibility: 'visible',
        marginLeft: 'auto',
        marginRight: 'auto',
        objectPosition: 'center center'
      });
    });

    var current = parseInt($gallery.attr('data-ionpd-current-slide'), 10);
    if (isNaN(current) || current < 0 || current >= $slides.length) current = 0;

    var $thumbs = $gallery.find('.ionpd-gallery-thumbs').first();
    if ($slides.length < 2) {
      if ($thumbs.length) $thumbs.remove();
    } else if (!$thumbs.length) {
      $thumbs = $('<div class="ionpd-gallery-thumbs" role="tablist" aria-label="Product images"></div>');
    }

    if ($slides.length > 1) {
      var existingCount = parseInt($thumbs.attr('data-ionpd-count'), 10) || 0;
      if (existingCount !== $slides.length) {
        $thumbs.empty().attr('data-ionpd-count', $slides.length);

        $slides.each(function (index) {
          var $slide = $(this);
          var $img = $slide.find('img').first();
          var thumb = $slide.attr('data-thumb') || $img.attr('data-thumb') || $img.attr('src') || $img.attr('data-src') || '';
          var alt = $img.attr('alt') || 'Product image ' + (index + 1);
          var $button = $('<button type="button" class="ionpd-gallery-thumb" role="tab"></button>')
            .attr('aria-label', 'View ' + alt)
            .attr('aria-selected', index === current ? 'true' : 'false')
            .attr('data-ionpd-gallery-index', index);

          if (thumb) {
            $('<img loading="lazy" decoding="async" alt="">').attr('src', thumb).appendTo($button);
          } else {
            $('<span></span>').text(index + 1).appendTo($button);
          }

          $thumbs.append($button);
        });
      }

      var $anchor = $viewport.length ? $viewport : $wrapper;
      if (!$thumbs.parent().is($gallery)) {
        $thumbs.insertAfter($anchor);
      } else if (!$thumbs.prev().is($anchor)) {
        $thumbs.insertAfter($anchor);
      }
    }

    function activateGallerySlide(index) {
      index = parseInt(index, 10);
      if (isNaN(index) || index < 0 || index >= $slides.length) index = 0;

      current = index;
      $gallery.attr('data-ionpd-current-slide', index);

      $slides.each(function (slideIndex) {
        var active = slideIndex === index;
        $(this)
          .toggleClass('ionpd-gallery-slide-active flex-active-slide', active)
          .attr('aria-hidden', active ? 'false' : 'true')
          .css({ display: active ? 'flex' : 'none' });
      });

      if ($thumbs && $thumbs.length) {
        $thumbs.find('.ionpd-gallery-thumb').each(function (thumbIndex) {
          var active = thumbIndex === index;
          $(this)
            .toggleClass('ionpd-gallery-thumb-active', active)
            .attr('aria-selected', active ? 'true' : 'false');
        });
      }
    }

    if ($thumbs && $thumbs.length) {
      $thumbs.off('click.ionpdGallery').on('click.ionpdGallery', '.ionpd-gallery-thumb', function (event) {
        event.preventDefault();
        event.stopPropagation();
        activateGallerySlide($(this).attr('data-ionpd-gallery-index'));
      });
    }

    $gallery
      .off('mouseenter.ionpdNoZoom mousemove.ionpdNoZoom mouseleave.ionpdNoZoom')
      .on('mouseenter.ionpdNoZoom mousemove.ionpdNoZoom mouseleave.ionpdNoZoom', '.woocommerce-product-gallery__image a, .woocommerce-product-gallery__image img', function () {
        killWooZoom();
      });

    activateGallerySlide(current);
    killWooZoom();
    window.setTimeout(killWooZoom, 80);
    window.setTimeout(killWooZoom, 350);
  }

  /** Keep the displayed title tied to the exact backend product title. */
  function syncExactProductTitle() {
    var title = $.trim(cfg.productName || '');
    if (!title) return;
    $('h1.product_title.entry-title').first().text(title).attr('data-ionpd-exact-title', '1');
    $('.ionpd-sticky-title').text(title);
  }

  /** Restore variation button labels from their actual select options. */
  function syncVariationLabelsFromSelect() {
    var $form = $('form.variations_form').first();
    if (!$form.length) return;

    $form.find('select[name^="attribute_"]').each(function () {
      var $select = $(this);
      var labels = {};
      $select.find('option').each(function () {
        if (this.value) labels[String(this.value)] = $.trim($(this).text());
      });

      $form.find('.variable-item').each(function () {
        var $item = $(this);
        var value = String($item.attr('data-value') || '');
        if (!value || !labels[value]) return;
        var $label = $item.find('.variable-item-span, .variable-item-contents span').first();
        if ($label.length) $label.text(labels[value]);
        else $item.attr('title', labels[value]);
      });
    });
  }

  /**
   * The redesign's custom controls must submit WooCommerce's exact variation
   * values, never a shortened visual label.
   */
  function syncVariationSubmission() {
    var $form = $('form.variations_form').first();
    if (!$form.length) return true;

    var variation = getCurrentVariation();
    if (!variation) {
      var id = parseInt($form.find('input.variation_id').val(), 10) || 0;
      if (id) {
        var list = getProductVariations($form);
        for (var i = 0; i < list.length; i++) {
          if (parseInt(list[i].variation_id, 10) === id) {
            variation = list[i];
            break;
          }
        }
      }
    }

    if (!variation || !variation.attributes) return true;

    $.each(variation.attributes, function (name, value) {
      if (!value) return;
      var $select = $form.find('select[name="' + cssEscapeValue(name) + '"]');
      if (!$select.length) {
        $select = $form.find('select[name="attribute_' + cssEscapeValue(String(name).replace(/^attribute_/, '')) + '"]');
      }
      if ($select.length) $select.val(value);
    });

    if (variation.variation_id) {
      $form.find('input.variation_id').val(variation.variation_id);
      $form.find('input[name="variation_id"]').val(variation.variation_id);
    }

    return true;
  }

  function bindExactVariationSubmission() {
    if (document.documentElement.getAttribute('data-ionpd-exact-submit') === '1') return;
    document.documentElement.setAttribute('data-ionpd-exact-submit', '1');

    document.addEventListener('click', function (event) {
      var button = event.target && event.target.closest ? event.target.closest('form.variations_form .single_add_to_cart_button') : null;
      if (button) syncVariationSubmission();
    }, true);

    document.addEventListener('submit', function (event) {
      if (event.target && event.target.matches && event.target.matches('form.variations_form')) {
        syncVariationSubmission();
      }
    }, true);
  }

  function boot() {
    refreshStaticMediaManifest();
    syncExactProductTitle();
    syncVariationLabelsFromSelect();
    bindExactVariationSubmission();
    readInitialPriceFromDOM();
    normalizeProductGallery();
    addQtyControls();
    insertUnitLabel();
    movePriceTools();
    ensureBulkPreviewData();
    bindBulkPreviewSafety();
    normalizeBulkTable();
    buildBuyLayout();
    updateStockLabel();
    ensureWaitlistVisibility();
    bindOutOfStockBlocker();
    bindVariationAvailabilityGuards();
    markOutOfStockVariations();
    syncVariationAvailabilityUi();
    normalizeRelatedProducts();
    ensureActionRow();
    setupCoaClick();
    bindCoaVariationFilter();
    setupLiveChat();
    setupDetailsPanel();
    setupStickyBuyBar();
    bindVariationEvents();
    selectLowestVariationByDefault();
    updateUnitLabel();
    updatePricePanel();

    $(document).on('change input', 'form.cart input.qty', function () {
      updateUnitLabel();
      buildBuyLayout();
      updatePricePanel();
      updateStockLabel();
      markOutOfStockVariations();
      ensureWaitlistVisibility();
    });

    setTimeout(function () {
      syncExactProductTitle();
      syncVariationLabelsFromSelect();
      normalizeProductGallery();
      addQtyControls();
      insertUnitLabel();
      movePriceTools();
      ensureBulkPreviewData();
      normalizeBulkTable();
      buildBuyLayout();
      updateStockLabel();
      markOutOfStockVariations();
      ensureWaitlistVisibility();
      normalizeRelatedProducts();
      ensureActionRow();
      bindCoaVariationFilter();
      filterCoaBySelectedDose();
      setupDetailsPanel();
      updateUnitLabel();
      updatePricePanel();
    }, 350);

    $(window).on('load.ionpd', function () {
      setTimeout(function () {
        syncExactProductTitle();
        syncVariationLabelsFromSelect();
        normalizeProductGallery();
        ensureBulkPreviewData();
        normalizeBulkTable();
        buildBuyLayout();
        updateStockLabel();
        markOutOfStockVariations();
        ensureWaitlistVisibility();
        normalizeRelatedProducts();
        ensureActionRow();
        bindCoaVariationFilter();
        filterCoaBySelectedDose();
        setupDetailsPanel();
        updatePricePanel();
      }, 120);
      setTimeout(function () {
        syncExactProductTitle();
        syncVariationLabelsFromSelect();
        normalizeProductGallery();
        ensureBulkPreviewData();
        normalizeBulkTable();
        buildBuyLayout();
        updateStockLabel();
        markOutOfStockVariations();
        ensureWaitlistVisibility();
        normalizeRelatedProducts();
        ensureActionRow();
        bindCoaVariationFilter();
        filterCoaBySelectedDose();
        setupDetailsPanel();
        updatePricePanel();
      }, 1200);
    });
  }

  $(boot);
})(jQuery);
