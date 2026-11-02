(function () {
  if (typeof window === "undefined" || !window.BRW_PAYMENT_ROTATOR_STICKY) {
    return;
  }

  var cfg = window.BRW_PAYMENT_ROTATOR_STICKY || {};
  var allowedIds = Array.isArray(cfg.allowed_ids) ? cfg.allowed_ids : [];
  var displayOrder = Array.isArray(cfg.display_order) ? cfg.display_order : [];
  var adjustments = cfg.adjustments || {};
  var ajaxUrl = cfg.ajax_url || "";
  var nonce = cfg.nonce || "";
  var methodSelector = "li.wc_payment_method, .wc-block-components-payment-method, .wc-block-checkout__payment-method";
  var queueTimer = null;
  var checkoutUpdateTimer = null;
  var ajaxTimer = null;
  var allIds = Array.isArray(cfg.all_ids) ? cfg.all_ids : [];
  var paymentTypeCfg = cfg.payment_type_routing || {};
  var paymentTypeEnabled = !!paymentTypeCfg.enabled;
  var paymentTypeTypes = paymentTypeCfg.types || {};
  var paymentTypeAllTypes = paymentTypeCfg.all_types || paymentTypeTypes;
  var gatewayTypes = paymentTypeCfg.gateway_types || {};
  var alwaysIds = Array.isArray(paymentTypeCfg.always_ids) ? paymentTypeCfg.always_ids : [];
  var paymentTypeBaseIds = Array.isArray(cfg.payment_type_base_ids) ? cfg.payment_type_base_ids : [];
  var selectedPaymentType = paymentTypeCfg.selected ? String(paymentTypeCfg.selected) : "";
  var paymentTypeNonce = cfg.payment_type_nonce || nonce;
  var paymentTypeAjaxTimer = null;
  var paymentTypePromptTimer = null;
  var paymentTypeModalOpenedThisPage = false;
  var isCheckoutPage = !!cfg.is_checkout;
  var interceptCheckoutLinks = !!cfg.intercept_checkout_links;
  var checkoutUrl = cfg.checkout_url ? String(cfg.checkout_url) : "";
  var pendingCheckoutRedirect = false;
  var pendingCheckoutHref = "";
  var storageKey = "brw_pr_selected_payment_type_v1";
  var processingModalChoice = false;

  function isKnownGatewayId(gatewayId) {
    gatewayId = gatewayId ? String(gatewayId) : "";
    if (!gatewayId) return false;

    var known = [];
    if (allIds.length) known = known.concat(allIds);
    if (allowedIds.length) known = known.concat(allowedIds);
    if (displayOrder.length) known = known.concat(displayOrder);
    if (paymentTypeBaseIds.length) known = known.concat(paymentTypeBaseIds);

    if (!known.length) {
      return true;
    }

    return known.indexOf(gatewayId) !== -1;
  }

  function isPaymentMethodInput(target) {
    if (!target || target.nodeType !== 1 || !target.matches('input[type="radio"]')) {
      return false;
    }

    if (target.matches('input[name="payment_method"]')) {
      return true;
    }

    var holder = target.closest('li.wc_payment_method, .wc-block-components-payment-method, .wc-block-checkout__payment-method, [data-payment-method-id], [data-gateway-id], [data-method-id]');
    if (!holder) {
      return false;
    }

    var methodId = target.value ? String(target.value) : getMethodIdFromNode(holder);
    return isKnownGatewayId(methodId);
  }

  function getMethodIdFromNode(node) {
    if (!node || node.nodeType !== 1) {
      return "";
    }

    var radio = node.querySelector('input[type="radio"]');
    if (radio && radio.value) {
      return String(radio.value);
    }

    var attrs = ["data-payment-method-id", "data-gateway-id", "data-method-id", "data-value"];
    for (var i = 0; i < attrs.length; i++) {
      var attr = node.getAttribute(attrs[i]);
      if (attr) {
        return String(attr);
      }
    }

    var className = node.className || "";
    var match = className.match(/payment_method_([A-Za-z0-9_\-]+)/);
    if (match && match[1]) {
      return String(match[1]);
    }

    var html = node.innerHTML || "";
    var sourceIds = allowedIds.length ? allowedIds : displayOrder;
    for (var j = 0; j < sourceIds.length; j++) {
      if (html.indexOf('value="' + sourceIds[j] + '"') !== -1) {
        return String(sourceIds[j]);
      }
    }

    return "";
  }

  function getNodes() {
    return Array.prototype.slice.call(document.querySelectorAll(methodSelector));
  }

  function sortByDisplayOrder(nodes) {
    return nodes.slice().sort(function (a, b) {
      var aId = getMethodIdFromNode(a);
      var bId = getMethodIdFromNode(b);
      var aIndex = displayOrder.indexOf(aId);
      var bIndex = displayOrder.indexOf(bId);

      if (aIndex === -1) aIndex = 9999;
      if (bIndex === -1) bIndex = 9999;

      return aIndex - bIndex;
    });
  }

  function findLabelNode(node) {
    if (!node || node.nodeType !== 1) {
      return null;
    }

    return node.querySelector('label') ||
      node.querySelector('.wc-block-components-radio-control__label') ||
      node.querySelector('.wc-block-components-payment-method__label') ||
      node.querySelector('.payment-method__label');
  }

  function ensureBadge(label, className, text) {
    if (!label || !text) {
      return;
    }

    var badge = label.querySelector('.' + className);
    if (!badge) {
      badge = document.createElement('span');
      badge.className = className;
      label.appendChild(document.createTextNode(' '));
      label.appendChild(badge);
    }
    badge.textContent = text;
  }

  function removeBadge(label, className) {
    if (!label) {
      return;
    }
    var badge = label.querySelector('.' + className);
    if (badge && badge.parentNode) {
      badge.parentNode.removeChild(badge);
    }
  }

  function applyBadges() {
    var nodes = getNodes();
    if (!nodes.length) {
      return;
    }

    nodes.forEach(function (node) {
      var methodId = getMethodIdFromNode(node);
      var label = findLabelNode(node);
      var rule = methodId && adjustments[methodId] ? adjustments[methodId] : null;

      if (!label) {
        return;
      }

      if (!rule || !rule.discount) {
        removeBadge(label, 'brw-pr-discount-badge');
      } else {
        ensureBadge(label, 'brw-pr-discount-badge', rule.discount_badge || 'Save ' + rule.discount + '%');
      }

      if (!rule || !rule.fee) {
        removeBadge(label, 'brw-pr-fee-badge');
      } else {
        ensureBadge(label, 'brw-pr-fee-badge', rule.fee_badge || rule.fee + '% Fee');
      }
    });
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" })[char];
    });
  }

  function rememberPaymentTypeLocally(paymentType) {
    try {
      if (paymentType) {
        window.sessionStorage && window.sessionStorage.setItem(storageKey, String(paymentType));
      } else {
        window.sessionStorage && window.sessionStorage.removeItem(storageKey);
      }
    } catch (e) {}
  }

  function readPaymentTypeFromUrlOrStorage() {
    var found = "";

    try {
      if (window.URLSearchParams) {
        var params = new URLSearchParams(window.location.search || "");
        found = params.get('brw_pr_payment_type') || "";
      }
    } catch (e) {}

    if (!found) {
      try {
        found = (window.sessionStorage && window.sessionStorage.getItem(storageKey)) || "";
      } catch (e2) {}
    }

    found = found ? String(found) : "";
    if (found && paymentTypeAllTypes && paymentTypeAllTypes[found]) {
      return found;
    }

    return "";
  }

  var storedPaymentType = readPaymentTypeFromUrlOrStorage();
  if (!selectedPaymentType && storedPaymentType) {
    selectedPaymentType = storedPaymentType;
  }

  function getPaymentTypeEntries() {
    if (!paymentTypeEnabled || !paymentTypeTypes || typeof paymentTypeTypes !== "object") {
      return [];
    }

    return Object.keys(paymentTypeTypes).map(function (key) {
      return {
        key: String(key),
        label: String(paymentTypeTypes[key] || (paymentTypeAllTypes && paymentTypeAllTypes[key]) || key)
      };
    });
  }

  function methodMatchesSelectedPaymentType(methodId) {
    methodId = methodId ? String(methodId) : "";
    if (!methodId) return false;
    if (!paymentTypeEnabled) return true;

    var baseIds = paymentTypeBaseIds.length ? paymentTypeBaseIds : allowedIds;
    if (baseIds.length && baseIds.indexOf(methodId) === -1) {
      return false;
    }

    if (!selectedPaymentType) {
      return true;
    }

    if (alwaysIds.indexOf(methodId) !== -1) {
      return true;
    }

    var types = Array.isArray(gatewayTypes[methodId]) ? gatewayTypes[methodId] : [];
    return types.indexOf(selectedPaymentType) !== -1;
  }

  function getPaymentTypeLabel(paymentType) {
    paymentType = paymentType ? String(paymentType) : "";
    if (!paymentType) return "";
    if (paymentTypeTypes && paymentTypeTypes[paymentType]) return String(paymentTypeTypes[paymentType]);
    if (paymentTypeAllTypes && paymentTypeAllTypes[paymentType]) return String(paymentTypeAllTypes[paymentType]);
    return paymentType.replace(/_/g, " ").replace(/\b\w/g, function (letter) { return letter.toUpperCase(); });
  }

  function getPaymentTypeIcon(paymentType) {
    paymentType = paymentType ? String(paymentType) : "";

    var icons = {
      visa: '<svg viewBox="0 0 64 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect width="64" height="24" rx="4" fill="#1a1f71"/><path fill="#fff" d="M27.4 16.7L29 7.3h2.5l-1.6 9.4zM38.7 7.5c-.5-.2-1.3-.4-2.3-.4-2.5 0-4.3 1.3-4.3 3.2 0 1.4 1.3 2.2 2.3 2.6 1 .5 1.4.8 1.4 1.2 0 .6-.8.9-1.5.9-1 0-1.5-.1-2.4-.5l-.3-.2-.4 2.1c.6.3 1.7.5 2.9.5 2.7 0 4.4-1.3 4.4-3.3 0-1.1-.7-2-2.2-2.6-.9-.4-1.5-.7-1.5-1.1 0-.4.5-.8 1.4-.8.8 0 1.4.2 1.9.4l.2.1.4-2zM45 7.3h-1.9c-.6 0-1 .2-1.3.8L38 16.7h2.7l.5-1.5h3.3l.3 1.5h2.4zm-3.1 6c.2-.6 1-2.7 1-2.7 0 .1.2-.6.3-.9l.2.9.6 2.7zM23.6 7.3l-2.5 6.4-.3-1.4c-.5-1.6-2-3.4-3.7-4.3l2.3 8.6h2.8l4.1-9.4z"/><path fill="#f7b600" d="M19.6 7.3h-4.2v.2c3.3.8 5.5 2.8 6.4 5.2L20.8 8c-.2-.6-.6-.7-1.2-.7z"/></svg>',
      mastercard: '<svg viewBox="0 0 64 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect width="64" height="40" rx="5" fill="#fff" stroke="#e5e7eb"/><circle cx="26" cy="20" r="11" fill="#eb001b"/><circle cx="38" cy="20" r="11" fill="#f79e1b"/><path fill="#ff5f00" d="M32 12.5a11 11 0 010 15 11 11 0 010-15z"/></svg>',
      amex: '<svg viewBox="0 0 64 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect width="64" height="24" rx="4" fill="#006fcf"/><text x="32" y="16" text-anchor="middle" font-family="Arial,sans-serif" font-weight="900" font-size="9" fill="#fff" letter-spacing="1">AMEX</text></svg>',
      discover: '<svg viewBox="0 0 64 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect width="64" height="24" rx="4" fill="#fff" stroke="#e5e7eb"/><text x="13" y="16" text-anchor="middle" font-family="Arial,sans-serif" font-weight="900" font-size="8" fill="#000">DISC</text><circle cx="24" cy="13" r="3" fill="#ff6000"/><text x="38" y="16" text-anchor="middle" font-family="Arial,sans-serif" font-weight="900" font-size="8" fill="#000">VER</text></svg>',
      apple_pay: '<svg viewBox="0 0 120 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect width="120" height="32" rx="7" fill="#000"/><text x="60" y="21" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif" font-weight="700" font-size="13" fill="#fff">Apple Pay</text></svg>',
      google_pay: '<svg viewBox="0 0 120 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect width="120" height="32" rx="7" fill="#111827"/><text x="60" y="21" text-anchor="middle" font-family="Arial,sans-serif" font-weight="700" font-size="13" fill="#fff">G Pay</text></svg>'
    };

    return icons[paymentType] || '<svg viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>';
  }

  function ensurePaymentTypeStyles() {
    if (document.getElementById('brw-pr-payment-type-modal-css')) {
      return;
    }

    var css = '' +
      'body.brw-pr-payment-type-modal-open{overflow:hidden!important;}' +
      '.brw-pr-payment-type-modal{position:fixed;inset:0;z-index:2147483000;display:none;align-items:center;justify-content:center;background:rgba(2,6,23,.72);padding:18px;box-sizing:border-box;}' +
      '.brw-pr-payment-type-dialog{width:min(560px,100%);max-height:calc(100vh - 36px);overflow:auto;background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:18px;box-shadow:0 28px 80px rgba(2,6,23,.38);padding:22px;box-sizing:border-box;font-family:inherit;}' +
      '.brw-pr-payment-type-kicker{font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#155e75;margin:0 0 6px;}' +
      '.brw-pr-payment-type-title{font-size:22px;line-height:1.15;font-weight:900;color:#0f172a;margin:0 0 7px;}' +
      '.brw-pr-payment-type-copy{font-size:14px;line-height:1.45;color:#475569;margin:0 0 16px;}' +
      '.brw-pr-payment-type-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}' +
      '.brw-pr-payment-type-option{appearance:none;-webkit-appearance:none;display:flex;align-items:center;gap:10px;width:100%;min-height:68px;border:2px solid #e5e7eb;background:#f8fafc;color:#0f172a;border-radius:14px;padding:12px 14px;text-align:left;font-family:inherit;font-weight:900;cursor:pointer;transition:transform .12s ease,border-color .12s ease,box-shadow .12s ease,background .12s ease;}' +
      '.brw-pr-payment-type-option:hover{background:#fff;border-color:#155e75;box-shadow:0 8px 22px rgba(21,94,117,.14);transform:translateY(-1px);}' +
      '.brw-pr-payment-type-option.is-selected,.brw-pr-payment-type-option[aria-pressed="true"]{background:#eff6ff;border-color:#155e75;box-shadow:0 0 0 3px rgba(21,94,117,.13);}' +
      '.brw-pr-payment-type-option-icon{display:flex;align-items:center;justify-content:center;flex:0 0 72px;min-height:34px;}' +
      '.brw-pr-payment-type-option-icon svg{display:block!important;max-width:72px!important;max-height:34px!important;width:auto!important;height:auto!important;}' +
      '.brw-pr-payment-type-option-label{display:block;font-size:14px;line-height:1.15;}' +
      '.brw-pr-payment-type-actions{display:flex;justify-content:flex-end;margin-top:12px;}' +
      '.brw-pr-payment-type-clear{appearance:none;-webkit-appearance:none;border:0;background:transparent;color:#64748b;font-weight:800;text-decoration:underline;cursor:pointer;padding:8px 0;font-family:inherit;}' +
      '.brw-pr-payment-type-summary{display:none;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px;padding:11px 13px;border:1px solid rgba(21,94,117,.20);background:#f8fafc;border-radius:12px;color:#0f172a;font-size:14px;line-height:1.35;box-sizing:border-box;}' +
      '.brw-pr-payment-type-summary strong{font-weight:900;color:#0f172a;}' +
      '.brw-pr-change-payment-type{appearance:none;-webkit-appearance:none;border:1px solid rgba(21,94,117,.35);background:#fff;color:#155e75;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:900;cursor:pointer;font-family:inherit;white-space:nowrap;}' +
      '@media(max-width:520px){.brw-pr-payment-type-dialog{padding:18px;border-radius:16px;}.brw-pr-payment-type-grid{grid-template-columns:1fr;}.brw-pr-payment-type-summary{align-items:flex-start;flex-direction:column;}.brw-pr-change-payment-type{width:100%;}}';

    var style = document.createElement('style');
    style.id = 'brw-pr-payment-type-modal-css';
    style.textContent = css;
    document.head.appendChild(style);
  }

  function ensureCheckoutHiddenPaymentType() {
    var hidden = document.getElementById('brw-pr-selected-payment-type-field');
    if (!hidden) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.id = 'brw-pr-selected-payment-type-field';
      hidden.name = 'brw_pr_payment_type';

      var form = document.querySelector('form.checkout, form.woocommerce-checkout, form[name="checkout"]');
      if (form) {
        form.appendChild(hidden);
      } else {
        document.body.appendChild(hidden);
      }
    }

    hidden.value = selectedPaymentType || '';
  }

  function hideInlinePaymentTypeSelectors() {
    var selectors = Array.prototype.slice.call(document.querySelectorAll('.brw-pr-payment-type-selector[data-brw-pr-payment-types="1"]'));
    selectors.forEach(function (selector) {
      selector.style.display = 'none';
      var hidden = selector.querySelector('input[name="brw_pr_payment_type"]');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'brw_pr_payment_type';
        selector.appendChild(hidden);
      }
      hidden.value = selectedPaymentType || '';
    });
  }

  function syncWalletIntentFields() {
    var walletIntent = (selectedPaymentType === 'apple_pay' || selectedPaymentType === 'google_pay') ? selectedPaymentType : '';

    Array.prototype.slice.call(document.querySelectorAll('input[name="nmi2_wallet_intent"], .nmi2_wallet_intent')).forEach(function (input) {
      if (input && 'value' in input) {
        input.value = walletIntent;
      }
    });

    Array.prototype.slice.call(document.querySelectorAll('.nmi2-wallet-wrap[data-wallet-intent]')).forEach(function (wrap) {
      wrap.setAttribute('data-wallet-intent', walletIntent);
      if (walletIntent) {
        wrap.classList.add('nmi2-wallet-only');
      } else {
        wrap.classList.remove('nmi2-wallet-only');
      }
    });
  }

  function findPaymentTypeInsertTarget() {
    return document.querySelector('#payment, .woocommerce-checkout-payment, .wc-block-checkout__payment-method, .wp-block-woocommerce-checkout-payment-block, .wc-block-components-checkout-step--payment') || getNodes()[0];
  }

  function ensurePaymentTypeSummaryBar() {
    if (!paymentTypeEnabled || !getPaymentTypeEntries().length) {
      return;
    }

    ensurePaymentTypeStyles();

    var target = findPaymentTypeInsertTarget();
    if (!target || !target.parentNode) {
      return;
    }

    var bar = document.querySelector('.brw-pr-payment-type-summary[data-brw-pr-payment-type-summary="1"]');
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'brw-pr-payment-type-summary';
      bar.setAttribute('data-brw-pr-payment-type-summary', '1');
      target.parentNode.insertBefore(bar, target);
    } else if (bar.parentNode !== target.parentNode) {
      target.parentNode.insertBefore(bar, target);
    }

    var summaryHtml;
    if (selectedPaymentType) {
      summaryHtml = '<span>Payment type selected: <strong>' + escapeHtml(getPaymentTypeLabel(selectedPaymentType)) + '</strong></span>' +
        '<button type="button" class="brw-pr-change-payment-type">Wrong type? Change</button>';
    } else {
      summaryHtml = '<span><strong>Choose your payment type</strong> to show the correct checkout options.</span>' +
        '<button type="button" class="brw-pr-change-payment-type">Select payment type</button>';
    }

    bar.style.display = 'flex';
    if (bar.innerHTML !== summaryHtml) {
      bar.innerHTML = summaryHtml;
    }
  }

  function ensurePaymentTypeModal() {
    if (!paymentTypeEnabled || !getPaymentTypeEntries().length) {
      return null;
    }

    ensurePaymentTypeStyles();

    var modal = document.querySelector('.brw-pr-payment-type-modal[data-brw-pr-payment-type-modal="1"]');
    if (!modal) {
      modal = document.createElement('div');
      modal.className = 'brw-pr-payment-type-modal';
      modal.setAttribute('data-brw-pr-payment-type-modal', '1');
      modal.setAttribute('aria-hidden', 'true');
      modal.innerHTML = '<div class="brw-pr-payment-type-dialog" role="dialog" aria-modal="true" aria-labelledby="brw-pr-payment-type-title">' +
        '<div class="brw-pr-payment-type-kicker">Payment routing</div>' +
        '<div id="brw-pr-payment-type-title" class="brw-pr-payment-type-title">How would you like to pay?</div>' +
        '<p class="brw-pr-payment-type-copy">Choose the payment type you plan to use so checkout can show the correct available options.</p>' +
        '<div class="brw-pr-payment-type-grid" data-brw-pr-payment-type-options="1"></div>' +
      '</div>';
      document.body.appendChild(modal);
    }

    var grid = modal.querySelector('[data-brw-pr-payment-type-options="1"]');
    if (grid) {
      var entries = getPaymentTypeEntries();
      var signature = entries.map(function (entry) {
        return entry.key + ':' + entry.label;
      }).join('|');

      if (grid.getAttribute('data-brw-pr-types-signature') !== signature) {
        grid.innerHTML = entries.map(function (entry) {
          return '<button type="button" class="brw-pr-payment-type-option brw-pr-payment-type-modal-option" data-payment-type="' + escapeHtml(entry.key) + '" aria-pressed="false">' +
            '<span class="brw-pr-payment-type-option-icon">' + getPaymentTypeIcon(entry.key) + '</span>' +
            '<span class="brw-pr-payment-type-option-label">' + escapeHtml(entry.label) + '</span>' +
          '</button>';
        }).join('');
        grid.setAttribute('data-brw-pr-types-signature', signature);
      }
    }

    return modal;
  }

  function openPaymentTypeModal(force) {
    var modal = ensurePaymentTypeModal();
    if (!modal) {
      return;
    }

    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('brw-pr-payment-type-modal-open');
    paymentTypeModalOpenedThisPage = true;
    updatePaymentTypeSelectorState();

    window.setTimeout(function () {
      var active = modal.querySelector('.brw-pr-payment-type-option.is-selected') || modal.querySelector('.brw-pr-payment-type-option');
      if (active && typeof active.focus === 'function') {
        active.focus();
      }
    }, 40);
  }

  function closePaymentTypeModal() {
    var modal = document.querySelector('.brw-pr-payment-type-modal[data-brw-pr-payment-type-modal="1"]');
    if (!modal) {
      return;
    }

    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('brw-pr-payment-type-modal-open');
  }

  function schedulePaymentTypePrompt() {
    if (!isCheckoutPage || !paymentTypeEnabled || !getPaymentTypeEntries().length || selectedPaymentType || paymentTypeModalOpenedThisPage) {
      return;
    }

    if (paymentTypePromptTimer) {
      window.clearTimeout(paymentTypePromptTimer);
    }

    paymentTypePromptTimer = window.setTimeout(function () {
      openPaymentTypeModal(false);
      paymentTypePromptTimer = null;
    }, 350);
  }

  function updatePaymentTypeSelectorState() {
    hideInlinePaymentTypeSelectors();
    ensureCheckoutHiddenPaymentType();
    syncWalletIntentFields();
    ensurePaymentTypeSummaryBar();

    Array.prototype.slice.call(document.querySelectorAll('.brw-pr-payment-type-option')).forEach(function (button) {
      var active = selectedPaymentType && button.getAttribute('data-payment-type') === selectedPaymentType;
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.classList.toggle('is-selected', !!active);
    });

    Array.prototype.slice.call(document.querySelectorAll('.brw-pr-payment-type-clear')).forEach(function (clear) {
      clear.style.display = selectedPaymentType ? '' : '';
    });
  }

  function ensurePaymentTypeSelector() {
    if (!paymentTypeEnabled || !getPaymentTypeEntries().length) {
      return;
    }

    ensurePaymentTypeStyles();
    hideInlinePaymentTypeSelectors();
    ensureCheckoutHiddenPaymentType();
    ensurePaymentTypeSummaryBar();
    ensurePaymentTypeModal();
    updatePaymentTypeSelectorState();
    schedulePaymentTypePrompt();
  }

  function ensureVisiblePaymentMethodSelected(nodes) {
    var checked = document.querySelector('input[name="payment_method"]:checked');
    if (checked) {
      var checkedNode = checked.closest(methodSelector);
      if (checkedNode && checkedNode.style.display !== 'none' && !checkedNode.hidden) {
        return;
      }
    }

    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].style.display === 'none' || nodes[i].hidden) {
        continue;
      }
      var radio = nodes[i].querySelector('input[name="payment_method"]');
      if (radio && !radio.checked) {
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
        return;
      }
    }
  }

  function persistSelectedPaymentType(paymentType) {
    selectedPaymentType = paymentType ? String(paymentType) : "";
    processingModalChoice = true;
    rememberPaymentTypeLocally(selectedPaymentType);
    updatePaymentTypeSelectorState();
    closePaymentTypeModal();
    queueProcess();

    /*
     * Do not use a standalone admin-ajax request here. On some Woo setups that
     * can collide with the active cart/session request and make the cart appear
     * empty after choosing a modal option. Instead, carry the selection through
     * the checkout URL or the normal Woo update_checkout post_data.
     */
    if (redirectToPendingCheckoutIfNeeded()) {
      return;
    }

    triggerClassicCheckoutRefresh();
    window.setTimeout(function () {
      processingModalChoice = false;
      queueProcess();
    }, 350);
  }

  function processMethods() {
    ensurePaymentTypeSelector();

    var nodes = getNodes();
    if (!nodes.length) {
      applyBadges();
      return;
    }

    var baseAllowedIds = (paymentTypeEnabled && paymentTypeBaseIds.length) ? paymentTypeBaseIds : allowedIds;

    if (baseAllowedIds.length || paymentTypeEnabled) {
      var groups = new Map();

      nodes.forEach(function (node) {
        var methodId = getMethodIdFromNode(node);
        var parent = node.parentNode;
        if (!methodId || !parent) {
          return;
        }

        var isAllowed = true;
        if (baseAllowedIds.length) {
          isAllowed = baseAllowedIds.indexOf(methodId) !== -1;
        }

        if (isAllowed && paymentTypeEnabled) {
          isAllowed = methodMatchesSelectedPaymentType(methodId);
        }

        node.style.display = isAllowed ? "" : "none";
        node.hidden = !isAllowed;
        node.setAttribute("aria-hidden", isAllowed ? "false" : "true");

        if (!isAllowed) {
          return;
        }

        if (!groups.has(parent)) {
          groups.set(parent, []);
        }
        groups.get(parent).push(node);
      });

      groups.forEach(function (groupNodes, parent) {
        var ordered = sortByDisplayOrder(groupNodes);
        var changed = false;

        for (var i = 0; i < ordered.length; i++) {
          if (parent.children[i] !== ordered[i]) {
            changed = true;
            break;
          }
        }

        if (!changed) {
          return;
        }

        ordered.forEach(function (node) {
          parent.appendChild(node);
        });
      });
    }

    ensureVisiblePaymentMethodSelected(nodes);
    applyBadges();
  }

  function queueProcess() {
    if (queueTimer) {
      window.clearTimeout(queueTimer);
    }

    queueTimer = window.setTimeout(function () {
      processMethods();
      queueTimer = null;
    }, 120);
  }

  function triggerClassicCheckoutRefresh() {
    if (checkoutUpdateTimer) {
      window.clearTimeout(checkoutUpdateTimer);
    }

    checkoutUpdateTimer = window.setTimeout(function () {
      if (window.jQuery && window.jQuery(document.body).length) {
        window.jQuery(document.body).trigger("update_checkout");
      }
      checkoutUpdateTimer = null;
    }, 120);
  }

  function normalizeGateUrl(url) {
    if (!url) return "";
    var a = document.createElement('a');
    a.href = String(url);
    var path = (a.pathname || '/').replace(/\/+$/, '').toLowerCase();
    if (!path) path = '/';
    return (a.host || window.location.host).toLowerCase() + path;
  }

  function appendPaymentTypeToUrl(url) {
    url = url || checkoutUrl || '/checkout/';
    if (!selectedPaymentType || !window.URL) {
      return url;
    }

    try {
      var u = new URL(url, window.location.href);
      u.searchParams.set('brw_pr_payment_type', selectedPaymentType);
      return u.toString();
    } catch (e) {
      var joiner = url.indexOf('?') === -1 ? '?' : '&';
      return url + joiner + 'brw_pr_payment_type=' + encodeURIComponent(selectedPaymentType);
    }
  }

  function redirectToPendingCheckoutIfNeeded() {
    if (!pendingCheckoutRedirect) {
      return false;
    }

    var destination = appendPaymentTypeToUrl(pendingCheckoutHref || checkoutUrl || '/checkout/');
    pendingCheckoutRedirect = false;
    pendingCheckoutHref = "";
    window.location.href = destination;
    return true;
  }

  function closeKnownCartOverlays() {
    try {
      document.documentElement.classList.remove('fkcart-trigger-open');
      document.body.classList.remove('fkcart-trigger-open');
      Array.prototype.slice.call(document.querySelectorAll('.fkcart-modal,#fkcart-modal,.fkcart-side,.fkcart-side-cart,[class*="fkcart-modal"],[class*="fkcart-side"],[class*="fkcart-drawer"]')).forEach(function (el) {
        el.style.display = 'none';
        el.style.visibility = 'hidden';
        el.style.pointerEvents = 'none';
      });
      Array.prototype.slice.call(document.querySelectorAll('.fkcart-overlay,.fkcart-backdrop,.fkcart-modal-backdrop,.fkcart-modal-overlay,.fkcart-side-cart-overlay,[class*="fkcart-overlay"],[class*="fkcart-backdrop"]')).forEach(function (el) {
        el.style.display = 'none';
        el.style.visibility = 'hidden';
        el.style.pointerEvents = 'none';
        el.style.opacity = '0';
      });
      if (window.jQuery && window.jQuery(document.body).length) {
        window.jQuery(document.body).trigger('fkcart_close_modal').trigger('fkcart_close').trigger('fkcart-close');
      }
    } catch (e) {}
  }

  function shouldSkipCheckoutGate(el) {
    if (!el || el.nodeType !== 1) return true;
    if (el.closest('#wpadminbar,.ab-item,.brw-pr-payment-type-modal,.brw-pr-payment-type-summary,.brw-pr-checkout-gate-skip,.brw-pr-checkout-gate-allow')) {
      return true;
    }
    return false;
  }

  function isCheckoutGateTrigger(el) {
    if (!interceptCheckoutLinks || isCheckoutPage || !paymentTypeEnabled || !getPaymentTypeEntries().length || shouldSkipCheckoutGate(el)) {
      return false;
    }

    var trigger = el.closest('a,button,input[type=submit],input[type=button]');
    if (!trigger || shouldSkipCheckoutGate(trigger)) {
      return false;
    }

    if (trigger.matches('.checkout-button,.wc-forward.checkout,.wc-proceed-to-checkout a,.fkcart-checkout-btn,.fkcart-checkout-button,.fkcart-mini-checkout-btn,.fkcart-modal-checkout-btn,a.fkcart-cta')) {
      return trigger;
    }

    var href = trigger.getAttribute('href') || trigger.getAttribute('formaction') || trigger.getAttribute('data-url') || trigger.getAttribute('data-href') || '';
    if (href) {
      var normalizedHref = normalizeGateUrl(href);
      var normalizedCheckout = normalizeGateUrl(checkoutUrl || '/checkout/');
      if (normalizedCheckout && normalizedHref === normalizedCheckout) {
        return trigger;
      }
      if (/\/checkout\/?$/i.test((document.createElement('a'), href).split('?')[0].replace(/\/+$/, ''))) {
        return trigger;
      }
    }

    return false;
  }

  function persistSelectedGateway(gatewayId) {
    if (!ajaxUrl || !gatewayId || !window.FormData || !window.fetch) {
      triggerClassicCheckoutRefresh();
      return;
    }

    if (ajaxTimer) {
      window.clearTimeout(ajaxTimer);
    }

    ajaxTimer = window.setTimeout(function () {
      var formData = new FormData();
      formData.append('action', 'brw_pr_select_gateway');
      formData.append('gateway_id', gatewayId);
      formData.append('nonce', nonce);

      fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
      }).catch(function () {
      }).finally(function () {
        triggerClassicCheckoutRefresh();
      });

      ajaxTimer = null;
    }, 40);
  }


  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!target || target.nodeType !== 1) {
      return;
    }

    var checkoutTrigger = target.closest ? isCheckoutGateTrigger(target) : false;
    if (checkoutTrigger) {
      event.preventDefault();
      event.stopPropagation();
      pendingCheckoutRedirect = true;
      pendingCheckoutHref = checkoutTrigger.getAttribute('href') || checkoutTrigger.getAttribute('formaction') || checkoutTrigger.getAttribute('data-url') || checkoutTrigger.getAttribute('data-href') || checkoutUrl || '/checkout/';
      closeKnownCartOverlays();
      openPaymentTypeModal(true);
      return false;
    }

    var changeType = target.closest ? target.closest('.brw-pr-change-payment-type') : null;
    if (changeType) {
      event.preventDefault();
      openPaymentTypeModal(true);
      return;
    }

    var option = target.closest ? target.closest('.brw-pr-payment-type-option') : null;
    if (option) {
      event.preventDefault();
      persistSelectedPaymentType(option.getAttribute('data-payment-type') || '');
      return;
    }

    var clear = target.closest ? target.closest('.brw-pr-payment-type-clear') : null;
    if (clear) {
      event.preventDefault();
      persistSelectedPaymentType('');
      closePaymentTypeModal();
    }
  }, true);

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!target || target.nodeType !== 1) {
      return;
    }

    if (!isPaymentMethodInput(target)) {
      return;
    }

    var gatewayId = target.value ? String(target.value) : "";
    if (gatewayId && isKnownGatewayId(gatewayId)) {
      persistSelectedGateway(gatewayId);
    } else {
      triggerClassicCheckoutRefresh();
    }

    queueProcess();
  }, true);

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", queueProcess);
  } else {
    queueProcess();
  }

  window.addEventListener("load", queueProcess);

  var observer = new MutationObserver(function () {
    if (processingModalChoice || document.body.classList.contains('brw-pr-payment-type-modal-open')) {
      return;
    }
    queueProcess();
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true
  });
})();
