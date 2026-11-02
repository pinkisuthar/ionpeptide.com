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

  function processMethods() {
    var nodes = getNodes();
    if (!nodes.length) {
      applyBadges();
      return;
    }

    if (allowedIds.length) {
      var groups = new Map();

      nodes.forEach(function (node) {
        var methodId = getMethodIdFromNode(node);
        var parent = node.parentNode;
        if (!methodId || !parent) {
          return;
        }

        var isAllowed = allowedIds.indexOf(methodId) !== -1;
        if (allowedIds.length) {
          node.style.display = isAllowed ? "" : "none";
          node.hidden = !isAllowed;
          node.setAttribute("aria-hidden", isAllowed ? "false" : "true");
        }

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

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!target || target.nodeType !== 1) {
      return;
    }

    var isPaymentInput = target.matches('input[name="payment_method"]') ||
      target.matches('.wc-block-components-payment-method__input') ||
      target.matches('input[type="radio"][value]');

    if (!isPaymentInput) {
      return;
    }

    var gatewayId = target.value ? String(target.value) : "";
    if (gatewayId) {
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
    queueProcess();
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true
  });
})();
