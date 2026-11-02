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

;// external ["wp","blocks"]
const external_wp_blocks_namespaceObject = window["wp"]["blocks"];
// EXTERNAL MODULE: external "React"
var external_React_ = __webpack_require__(1609);
;// external ["wp","i18n"]
const external_wp_i18n_namespaceObject = window["wp"]["i18n"];
;// external ["wp","blockEditor"]
const external_wp_blockEditor_namespaceObject = window["wp"]["blockEditor"];
;// external ["wp","components"]
const external_wp_components_namespaceObject = window["wp"]["components"];
;// external ["wp","hooks"]
const external_wp_hooks_namespaceObject = window["wp"]["hooks"];
;// external ["wp","element"]
const external_wp_element_namespaceObject = window["wp"]["element"];
;// external ["wp","data"]
const external_wp_data_namespaceObject = window["wp"]["data"];
;// external ["wp","compose"]
const external_wp_compose_namespaceObject = window["wp"]["compose"];
;// external ["wc","blocksCheckout"]
const external_wc_blocksCheckout_namespaceObject = window["wc"]["blocksCheckout"];
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
;// ./assets/js/blocks/src/set-referrer/edit.js
/**
 * External dependencies
 */





/**
 * Internal dependencies
 */


const Edit = ({
  attributes
}) => {
  const {
    referrer,
    className
  } = attributes;
  const blockProps = (0,external_wp_blockEditor_namespaceObject.useBlockProps)();
  return /*#__PURE__*/(0,jsx_runtime.jsxs)("div", {
    ...blockProps,
    children: [/*#__PURE__*/(0,jsx_runtime.jsx)(external_wp_blockEditor_namespaceObject.InspectorControls, {
      children: /*#__PURE__*/(0,jsx_runtime.jsx)(external_wp_components_namespaceObject.PanelBody, {
        title: (0,external_wp_i18n_namespaceObject._x)('Block options', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates'),
        children: /*#__PURE__*/(0,jsx_runtime.jsx)(external_wp_components_namespaceObject.TextControl, {
          label: (0,external_wp_i18n_namespaceObject._x)('Initial affiliate token to show', '[BUILDERS] Shortcode attributes', 'yith-woocommerce-affiliates'),
          type: "search",
          value: referrer
        })
      })
    }), /*#__PURE__*/(0,jsx_runtime.jsx)(set_referrer_block, {
      className: className,
      referrer: referrer
    })]
  });
};
const Save = () => {
  return /*#__PURE__*/(0,jsx_runtime.jsx)("div", {
    ...external_wp_blockEditor_namespaceObject.useBlockProps.save()
  });
};
;// ./assets/js/blocks/src/set-referrer/block.json
const set_referrer_block_namespaceObject = /*#__PURE__*/JSON.parse('{"apiVersion":3,"name":"yith/yith-wcaf-set-referrer","version":"2.16.0","title":"YITH Affiliates \\"Set Referrer\\" box","category":"yith","description":"Shows the \\"Set referrer\\" box, to allow customer set the user that suggested the site.","supports":{"html":false,"align":false,"multiple":false,"reusable":false},"parent":["woocommerce/checkout-totals-block","woocommerce/checkout-fields-block"],"textdomain":"yith-woocommerce-affiliates","editorStyle":"file:../../../../css/yith-wcaf.css"}');
;// external ["wp","url"]
const external_wp_url_namespaceObject = window["wp"]["url"];
;// ./plugin-fw/includes/builders/gutenberg/src/common/ajaxFetch.js
/**
 * Ajax Fetch
 */

/**
 * WordPress dependencies
 */


/**
 * Check status of ajax call
 * @param response
 * @returns {*}
 */
function ajaxCheckStatus(response) {
  if (response.status >= 200 && response.status < 300) {
    return response;
  }
  throw response;
}

/**
 * Parse the response of the ajax call
 * @param response
 * @returns {*}
 */
function parseResponse(response) {
  return response.json ? response.json() : response.text();
}

/**
 * Fetch using WordPress Ajax
 *
 * @param {object} data The data to use in the ajax call.
 * @param {string} url The ajax URL.
 * @returns {Promise<Response>}
 */
const ajaxFetch = (data, url = yithGutenberg.ajaxurl) => {
  var _data$security;
  data.security = (_data$security = data.security) !== null && _data$security !== void 0 ? _data$security : yithGutenberg.ajaxNonce;
  url = addQueryArgs(url, data);
  return fetch(url).then(ajaxCheckStatus).then(parseResponse);
};
;// ./plugin-fw/includes/builders/gutenberg/src/common/icons.js

/**
 * SVG Icons
 */

/**
 * The YITH Logo Icon
 * @type {JSX.Element}
 */
const yith_icon = /*#__PURE__*/(0,jsx_runtime.jsx)("svg", {
  viewBox: "0 0 22 22",
  xmlns: "http://www.w3.org/2000/svg",
  width: "22",
  height: "22",
  role: "img",
  "aria-hidden": "true",
  focusable: "false",
  children: /*#__PURE__*/(0,jsx_runtime.jsx)("path", {
    width: "22",
    height: "22",
    d: "M 18.24 7.628 C 17.291 8.284 16.076 8.971 14.587 9.688 C 15.344 7.186 15.765 4.851 15.849 2.684 C 15.912 0.939 15.133 0.045 13.514 0.003 C 11.558 -0.06 10.275 1.033 9.665 3.284 C 10.007 3.137 10.359 3.063 10.723 3.063 C 11.021 3.063 11.267 3.184 11.459 3.426 C 11.651 3.668 11.736 3.947 11.715 4.262 C 11.695 5.082 11.276 5.961 10.46 6.896 C 9.644 7.833 8.918 8.3 8.282 8.3 C 7.837 8.3 7.625 7.922 7.646 7.165 C 7.667 6.765 7.804 5.955 8.056 4.735 C 8.287 3.579 8.403 2.801 8.403 2.401 C 8.403 1.707 8.224 1.144 7.867 0.713 C 7.509 0.282 6.994 0.098 6.321 0.161 C 5.858 0.203 5.175 0.624 4.27 1.422 C 3.596 2.035 2.923 2.644 2.25 3.254 L 2.976 4.106 C 3.564 3.664 3.922 3.443 4.048 3.443 C 4.448 3.443 4.637 3.717 4.617 4.263 C 4.617 4.306 4.427 4.968 4.049 6.251 C 3.671 7.534 3.471 8.491 3.449 9.122 C 3.407 9.985 3.565 10.647 3.924 11.109 C 4.367 11.677 5.106 11.919 6.142 11.835 C 7.366 11.751 8.591 11.298 9.816 10.479 C 10.323 10.142 10.808 9.753 11.273 9.311 C 11.105 10.153 10.905 10.868 10.673 11.457 C 8.402 12.487 6.762 13.37 5.752 14.107 C 4.321 15.137 3.554 16.241 3.449 17.419 C 3.259 19.459 4.29 20.479 6.541 20.479 C 8.055 20.479 9.517 19.554 10.926 17.703 C 12.125 16.126 13.166 14.022 14.049 11.394 C 15.578 10.635 16.87 9.892 17.928 9.164 C 17.894 9.409 18.319 7.308 18.24 7.628 Z  M 7.393 16.095 C 7.056 16.095 6.898 15.947 6.919 15.653 C 6.961 15.106 7.908 14.38 9.759 13.476 C 8.791 15.221 8.002 16.095 7.393 16.095 Z"
  })
});
;// external "lodash"
const external_lodash_namespaceObject = window["lodash"];
;// ./plugin-fw/includes/builders/gutenberg/src/common/checkForDeps.js
/**
 * Check for dependencies
 *
 * @param {object} attributeArgs Attribute arguments.
 * @param {object} attributes The attributes.
 * @returns {boolean}
 */


const checkForSingleDep = (attributes, dep, controlType) => {
  let show = true;
  if (dep && dep.id && 'value' in dep) {
    let depValue = dep.value;
    if (['toggle', 'checkbox'].includes(controlType)) {
      depValue = true === depValue || 'yes' === depValue || 1 === depValue;
    }
    depValue = _.isArray(depValue) ? depValue : [depValue];
    show = typeof attributes[dep.id] !== 'undefined' && depValue.includes(attributes[dep.id]);
  }
  return show;
};
const checkForDeps_checkForDeps = (attributeArgs, attributes) => {
  const {
    controlType
  } = attributeArgs;
  let show = true;
  if (attributeArgs.deps) {
    if (_.isArray(attributeArgs.deps)) {
      for (let i in attributeArgs.deps) {
        const singleDep = attributeArgs.deps[i];
        show = checkForSingleDep(attributes, singleDep, controlType);
        if (!show) {
          break;
        }
      }
    } else {
      show = checkForSingleDep(attributes, attributeArgs.deps, controlType);
    }
  }
  return show;
};
;// ./plugin-fw/includes/builders/gutenberg/src/common/generateShortcode.js
/**
 * Internal dependencies
 */


/**
 * Generate the shortcode
 *
 * @param {object} blockArgs The block arguments.
 * @param {object} attributes The attributes
 * @returns {string}
 */
const generateShortcode = (blockArgs, attributes) => {
  let theShortcode = '';
  let callback = false;
  if (typeof blockArgs.callback !== 'undefined') {
    if (jQuery && blockArgs.callback in jQuery.fn) {
      callback = jQuery.fn[blockArgs.callback];
    } else if (blockArgs.callback in window) {
      callback = window[blockArgs.callback];
    }
  }
  if (typeof callback === 'function') {
    theShortcode = callback(attributes, blockArgs);
  } else {
    const shortcodeAttrs = blockArgs.attributes ? Object.entries(blockArgs.attributes).map(([attributeName, attributeArgs]) => {
      const show = checkForDeps(attributeArgs, attributes);
      const value = attributes[attributeName];
      if (show && typeof value !== 'undefined') {
        const shortcodeValue = !!attributeArgs.remove_quotes ? value : `"${value}"`;
        return attributeName + '=' + shortcodeValue;
      }
    }) : [];
    const shortcodeAttrsText = shortcodeAttrs.length ? ' ' + shortcodeAttrs.join(' ') : '';
    theShortcode = `[${blockArgs.shortcode_name}${shortcodeAttrsText}]`;
  }
  return theShortcode;
};
;// ./plugin-fw/includes/builders/gutenberg/src/common/index.js




;// ./assets/js/blocks/src/set-referrer/index.js
/**
 * External dependencies
 */


/**
 * Internal dependencies
 */



(0,external_wp_blocks_namespaceObject.registerBlockType)(set_referrer_block_namespaceObject, {
  icon: {
    src: yith_icon
  },
  edit: Edit,
  save: Save
});
var __webpack_export_target__ = window;
for(var __webpack_i__ in __webpack_exports__) __webpack_export_target__[__webpack_i__] = __webpack_exports__[__webpack_i__];
if(__webpack_exports__.__esModule) Object.defineProperty(__webpack_export_target__, "__esModule", { value: true });
/******/ })()
;
//# sourceMappingURL=yith-wcaf-set-referrer.bundle.js.map