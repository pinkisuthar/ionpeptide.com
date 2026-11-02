/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ 1020:
/***/ ((__unused_webpack_module, exports, __webpack_require__) => {

var __webpack_unused_export__;
/**
 * @license React
 * react-jsx-runtime.production.min.js
 *
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */
var f=__webpack_require__(1609),k=Symbol.for("react.element"),l=Symbol.for("react.fragment"),m=Object.prototype.hasOwnProperty,n=f.__SECRET_INTERNALS_DO_NOT_USE_OR_YOU_WILL_BE_FIRED.ReactCurrentOwner,p={key:!0,ref:!0,__self:!0,__source:!0};
function q(c,a,g){var b,d={},e=null,h=null;void 0!==g&&(e=""+g);void 0!==a.key&&(e=""+a.key);void 0!==a.ref&&(h=a.ref);for(b in a)m.call(a,b)&&!p.hasOwnProperty(b)&&(d[b]=a[b]);if(c&&c.defaultProps)for(b in a=c.defaultProps,a)void 0===d[b]&&(d[b]=a[b]);return{$$typeof:k,type:c,key:e,ref:h,props:d,_owner:n.current}}__webpack_unused_export__=l;exports.jsx=q;exports.jsxs=q;


/***/ }),

/***/ 1609:
/***/ ((module) => {

module.exports = window["React"];

/***/ }),

/***/ 4848:
/***/ ((module, __unused_webpack_exports, __webpack_require__) => {



if (true) {
  module.exports = __webpack_require__(1020);
} else // removed by dead control flow
{}


/***/ })

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};

;// external ["wc","blocksCheckout"]
const external_wc_blocksCheckout_namespaceObject = window["wc"]["blocksCheckout"];
// EXTERNAL MODULE: external "React"
var external_React_ = __webpack_require__(1609);
;// external ["wp","i18n"]
const external_wp_i18n_namespaceObject = window["wp"]["i18n"];
;// external ["wp","hooks"]
const external_wp_hooks_namespaceObject = window["wp"]["hooks"];
;// external ["wp","element"]
const external_wp_element_namespaceObject = window["wp"]["element"];
;// external ["wp","data"]
const external_wp_data_namespaceObject = window["wp"]["data"];
;// external ["wp","compose"]
const external_wp_compose_namespaceObject = window["wp"]["compose"];
;// external ["wp","components"]
const external_wp_components_namespaceObject = window["wp"]["components"];
;// external "jQuery"
const external_jQuery_namespaceObject = window["jQuery"];
var external_jQuery_default = /*#__PURE__*/__webpack_require__.n(external_jQuery_namespaceObject);
;// ./assets/js/src/globals.js


/**
 * External dependencies
 */


/* global yith_wcaf yith */

// these constants will be wrapped inside webpack closure, to prevent collisions

let ajaxUrl, nonces, labels;
if (typeof yith_wcaf !== 'undefined') {
  var _yith_wcaf, _yith_wcaf2, _yith_wcaf3;
  ajaxUrl = (_yith_wcaf = yith_wcaf) === null || _yith_wcaf === void 0 ? void 0 : _yith_wcaf.ajax_url;
  nonces = (_yith_wcaf2 = yith_wcaf) === null || _yith_wcaf2 === void 0 ? void 0 : _yith_wcaf2.nonces;
  labels = (_yith_wcaf3 = yith_wcaf) === null || _yith_wcaf3 === void 0 ? void 0 : _yith_wcaf3.labels;
}
const $document = external_jQuery_default()(document),
  $body = external_jQuery_default()('body'),
  block = $el => {
    if (typeof (external_jQuery_default()).fn.block === 'undefined') {
      return false;
    }
    try {
      $el.block({
        message: null,
        overlayCSS: {
          background: '#fff',
          opacity: 0.6
        }
      });
      return $el;
    } catch (e) {
      return false;
    }
  },
  unblock = $el => {
    if (typeof (external_jQuery_default()).fn.unblock === 'undefined') {
      return false;
    }
    try {
      $el.unblock();
    } catch (e) {
      return false;
    }
  },
  globals_confirm = (title, message, args) => {
    return new Promise((resolve, reject) => {
      var _yith, _yith$ui;
      // if can't display modal, accept by default
      if (typeof ((_yith = yith) === null || _yith === void 0 ? void 0 : (_yith$ui = _yith.ui) === null || _yith$ui === void 0 ? void 0 : _yith$ui.confirm) === 'undefined') {
        reject(new Error('Missing yith.ui utilities'));
      }
      const options = {
        title: title || labels.generic_confirm_title,
        message: message || labels.generic_confirm_message,
        ...args
      };
      options.onConfirm = () => resolve(true);
      options.onCancel = reject;
      yith.ui.confirm(options);
    });
  },
  getDashboardUrl = endpoint => {
    const {
      dashboard_url: dashboardUrl,
      endpoints
    } = yith_wcaf;
    if (!dashboardUrl) {
      return false;
    }
    if (!Object.keys(endpoints).includes(endpoint)) {
      return dashboardUrl;
    }
    return dashboardUrl.replace(/\/$/, '').concat('/', endpoint, '/');
  };

;// ./assets/js/src/modules/ajax.js


/**
 * Internal dependencies
 */

const request = function (method, action, security, params, args) {
    // retrieve wrapper as current context.
    const $wrapper = external_jQuery_default()(this);
    if (params instanceof FormData) {
      params.append('action', `yith_wcaf_${action}`);
      params.append('security', nonces !== null && nonces !== void 0 && nonces[security] ? nonces === null || nonces === void 0 ? void 0 : nonces[security] : security);
    } else {
      params = {
        action: `yith_wcaf_${action}`,
        security: nonces !== null && nonces !== void 0 && nonces[security] ? nonces === null || nonces === void 0 ? void 0 : nonces[security] : security,
        ...params
      };
    }
    const ajaxArgs = {
      url: ajaxUrl,
      data: params,
      method,
      beforeSend: () => $wrapper.length && block($wrapper),
      complete: () => $wrapper.length && unblock($wrapper),
      ...args
    };
    return external_jQuery_default().ajax(ajaxArgs);
  },
  get = function (...params) {
    return request.call(this, 'get', ...params);
  },
  post = function (...params) {
    return request.call(this, 'post', ...params);
  };
/* harmony default export */ const ajax = ({
  request,
  get,
  post
});
// EXTERNAL MODULE: ./node_modules/react/jsx-runtime.js
var jsx_runtime = __webpack_require__(4848);
;// ./assets/js/blocks/src/set-referrer/block.js
/**
 * External dependencies
 */









/**
 * Internal dependencies
 */



const Block = ({
  instanceId,
  className,
  defaultReferrer,
  displayReferrerForm
}) => {
  const {
    createNotice
  } = (0,external_wp_data_namespaceObject.useDispatch)('core/notices');
  const [referrer, setReferrer] = (0,external_wp_element_namespaceObject.useState)(defaultReferrer);
  const [isLoading, setIsLoading] = (0,external_wp_element_namespaceObject.useState)(false);
  const [isReferrerFormHidden, setIsReferrerFormHidden] = (0,external_wp_element_namespaceObject.useState)(!displayReferrerForm);
  const textInputId = `yith-block-components-set-referrer__input-${instanceId}`;
  const formWrapperClass = `yith-block-components-set-referrer__content ${isReferrerFormHidden ? 'screen-reader-text' : ''}`;
  const handleReferrerAnchorClick = e => {
    e.preventDefault();
    setIsReferrerFormHidden(false);
  };
  const handleReferrerSubmit = e => {
    e.preventDefault();
    setIsLoading(true);
    ajax.post.call(null, 'set_referrer', 'set_referrer', {
      referrer
    }).done(data => {
      setIsLoading(false);
      if (data !== null && data !== void 0 && data.success) {
        var _data$data;
        setReferrer(referrer);
        setIsReferrerFormHidden(true);
        if (data !== null && data !== void 0 && (_data$data = data.data) !== null && _data$data !== void 0 && _data$data.message) {
          var _data$data2;
          createNotice('info', data === null || data === void 0 ? void 0 : (_data$data2 = data.data) === null || _data$data2 === void 0 ? void 0 : _data$data2.message, {
            id: 'coupon-form',
            type: 'snackbar',
            context: 'wc/checkout'
          });
        }
        $document.trigger('yith_wcaf_referrer_set');
      }
    });
  };
  const label = (0,external_wp_hooks_namespaceObject.applyFilters)('yith_wcaf_set_referrer_message', (0,external_wp_i18n_namespaceObject._x)('Did anyone suggest our site to you?', '[FRONTEND] Set referrer shortcode', 'yith-woocommerce-affiliates'));
  return /*#__PURE__*/(0,jsx_runtime.jsx)(external_wc_blocksCheckout_namespaceObject.TotalsWrapper, {
    className: className,
    children: /*#__PURE__*/(0,jsx_runtime.jsx)("div", {
      className: "yith-block-components-set-referrer",
      children: isReferrerFormHidden ? /*#__PURE__*/(0,jsx_runtime.jsx)("a", {
        role: "button",
        href: "#yith-block-components-set-referrer__form",
        className: "yith-block-components-set-referrer-link",
        "aria-label": label,
        onClick: handleReferrerAnchorClick,
        children: label
      }) : /*#__PURE__*/(0,jsx_runtime.jsx)("div", {
        className: formWrapperClass,
        children: /*#__PURE__*/(0,jsx_runtime.jsxs)("form", {
          className: "yith-block-components-set-referrer__form",
          id: "yith-block-components-set-referrer__form",
          children: [/*#__PURE__*/(0,jsx_runtime.jsx)(external_wc_blocksCheckout_namespaceObject.ValidatedTextInput, {
            id: textInputId,
            errorId: "coupon",
            className: "yith-block-components-set-referrer__input",
            label: (0,external_wp_i18n_namespaceObject._x)('Affiliate code', '[FRONTEND] Set referrer shortcode', 'yith-woocommerce-affiliates'),
            value: referrer,
            onChange: newReferrer => {
              setReferrer(newReferrer);
            },
            focusOnMount: true,
            validateOnMount: false,
            showError: false
          }), /*#__PURE__*/(0,jsx_runtime.jsx)(external_wp_components_namespaceObject.Button, {
            className: "yith-block-components-set-referrer__button wp-element-button",
            disabled: isLoading || !referrer,
            onClick: handleReferrerSubmit,
            type: "submit",
            children: (0,external_wp_i18n_namespaceObject._x)('Set affiliate', '[FRONTEND] Set referrer shortcode', 'yith-woocommerce-affiliates')
          })]
        })
      })
    })
  });
};
/* harmony default export */ const set_referrer_block = ((0,external_wp_compose_namespaceObject.withInstanceId)(Block));
;// ./assets/js/blocks/src/set-referrer/block.json
const set_referrer_block_namespaceObject = /*#__PURE__*/JSON.parse('{"apiVersion":3,"name":"yith/yith-wcaf-set-referrer","version":"2.16.0","title":"YITH Affiliates \\"Set Referrer\\" box","category":"yith","description":"Shows the \\"Set referrer\\" box, to allow customer set the user that suggested the site.","supports":{"html":false,"align":false,"multiple":false,"reusable":false},"parent":["woocommerce/checkout-totals-block","woocommerce/checkout-fields-block"],"textdomain":"yith-woocommerce-affiliates","editorStyle":"file:../../../../css/yith-wcaf.css"}');
;// ./assets/js/blocks/src/set-referrer/frontend.js
/**
 * External dependencies
 */


/**
 * Internal dependencies
 */


(0,external_wc_blocksCheckout_namespaceObject.registerCheckoutBlock)({
  metadata: set_referrer_block_namespaceObject,
  component: set_referrer_block
});
var __webpack_export_target__ = window;
for(var __webpack_i__ in __webpack_exports__) __webpack_export_target__[__webpack_i__] = __webpack_exports__[__webpack_i__];
if(__webpack_exports__.__esModule) Object.defineProperty(__webpack_export_target__, "__esModule", { value: true });
/******/ })()
;
//# sourceMappingURL=yith-wcaf-set-referrer-frontend.bundle.js.map