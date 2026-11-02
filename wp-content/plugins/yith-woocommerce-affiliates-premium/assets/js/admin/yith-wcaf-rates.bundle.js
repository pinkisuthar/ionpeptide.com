/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	// The require scope
/******/ 	var __webpack_require__ = {};
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

;// ./node_modules/@babel/runtime/helpers/esm/typeof.js
function _typeof(o) {
  "@babel/helpers - typeof";

  return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) {
    return typeof o;
  } : function (o) {
    return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o;
  }, _typeof(o);
}

;// ./node_modules/@babel/runtime/helpers/esm/toPrimitive.js

function toPrimitive(t, r) {
  if ("object" != _typeof(t) || !t) return t;
  var e = t[Symbol.toPrimitive];
  if (void 0 !== e) {
    var i = e.call(t, r || "default");
    if ("object" != _typeof(i)) return i;
    throw new TypeError("@@toPrimitive must return a primitive value.");
  }
  return ("string" === r ? String : Number)(t);
}

;// ./node_modules/@babel/runtime/helpers/esm/toPropertyKey.js


function toPropertyKey(t) {
  var i = toPrimitive(t, "string");
  return "symbol" == _typeof(i) ? i : i + "";
}

;// ./node_modules/@babel/runtime/helpers/esm/defineProperty.js

function _defineProperty(e, r, t) {
  return (r = toPropertyKey(r)) in e ? Object.defineProperty(e, r, {
    value: t,
    enumerable: !0,
    configurable: !0,
    writable: !0
  }) : e[r] = t, e;
}

;// ./assets/js/src/modules/dependencies.js


/**
 * Internal dependencies
 */


class YITH_WCAF_Dependencies_Handler {
  constructor($container) {
    var _this$$container, _this$$fields;
    // container
    _defineProperty(this, "$container", void 0);
    // fields;
    _defineProperty(this, "$fields", void 0);
    // dependencies tree.
    _defineProperty(this, "dependencies", {});
    this.$container = $container;
    if (!((_this$$container = this.$container) !== null && _this$$container !== void 0 && _this$$container.length)) {
      return;
    }
    this.initFields();
    if (!((_this$$fields = this.$fields) !== null && _this$$fields !== void 0 && _this$$fields.length)) {
      return;
    }
    this.initDependencies();
  }
  initFields() {
    this.$fields = this.$container.find(':input');
  }
  initDependencies() {
    this.buildDependenciesTree();
    if (!Object.keys(this.dependencies).length) {
      return;
    }
    this.handleDependencies();
  }
  buildDependenciesTree() {
    this.$fields.closest('[data-dependencies]').each((i, el) => {
      const $field = external_jQuery_default()(el),
        id = $field.attr('id');
      if (!id) {
        return;
      }
      const newBranch = {
        [id]: $field.data('dependencies')
      };
      this.dependencies = external_jQuery_default().extend(this.dependencies, newBranch);
    });

    // backward compatibility with plugin-fw
    this.$container.find('[data-dep-target]').each((i, el) => {
      const $container = external_jQuery_default()(el),
        id = $container.data('dep-id'),
        target = $container.data('dep-target'),
        value = $container.data('dep-value');
      if (!id || !target || !value) {
        return;
      }
      const newBranch = {
        [target]: {
          [id]: value.toString().split(',')
        }
      };
      this.dependencies = external_jQuery_default().extend(this.dependencies, newBranch);
    });
  }
  handleDependencies() {
    this.$fields.on('change', this.applyDependencies.bind(this));
    this.applyDependencies();
  }
  applyDependencies() {
    external_jQuery_default().each(this.dependencies, (field, conditions) => {
      const $container = this.findFieldContainer(field);
      if (!$container.length) {
        return;
      }
      const show = this.checkConditions(conditions);
      if (show) {
        $container === null || $container === void 0 ? void 0 : $container.fadeIn();
      } else {
        $container === null || $container === void 0 ? void 0 : $container.hide();
      }
    });
    this.$container.trigger('yith_wcaf_dependencies_applied', [this]);
  }
  findField(field) {
    let $field = this.$container.find(`#${field}`);
    if (!$field.length) {
      $field = this.$container.find(`#${field}_field`);
    }
    if (!$field.length) {
      return false;
    }
    if (!$field.is(':input')) {
      $field = $field.find(':input');
    }
    return $field;
  }
  findFieldContainer(field) {
    const $field = this.findField(field);
    if (!($field !== null && $field !== void 0 && $field.length)) {
      return false;
    }

    // first of all search for container by id.
    let $container = $field.closest(`#${field}_container`);

    // if we couldn't find item with correct id, search for a .form-row
    if (!$container.length) {
      $container = $field.closest('.form-row');
    }

    // finally, just assume closest table row is a valid container
    if (!$container.length) {
      $container = $field.closest('.yith-plugin-fw__panel__option');
    }

    // if none of the previous worked, just fail to false
    if (!$container.length) {
      return false;
    }
    return $container;
  }
  checkConditions(conditions) {
    let result = true;
    external_jQuery_default().each(conditions, (field, condition) => {
      const $field = this.findField(field);
      let fieldValue;
      if (!result || !($field !== null && $field !== void 0 && $field.length)) {
        return;
      }
      if ($field.first().is('input[type="radio"]')) {
        var _$field$filter, _$field$filter$val;
        fieldValue = (_$field$filter = $field.filter(':checked')) === null || _$field$filter === void 0 ? void 0 : (_$field$filter$val = _$field$filter.val()) === null || _$field$filter$val === void 0 ? void 0 : _$field$filter$val.toString();
      } else {
        var _$field$val;
        fieldValue = $field === null || $field === void 0 ? void 0 : (_$field$val = $field.val()) === null || _$field$val === void 0 ? void 0 : _$field$val.toString();
      }
      if (Array.isArray(condition)) {
        result = condition.includes(fieldValue);
      } else if (typeof condition === 'function') {
        result = condition(fieldValue);
      } else if (condition.indexOf(':') === 0) {
        result = $field.is(condition);
      } else if (condition.indexOf('!:') === 0) {
        result = !$field.is(condition.toString().substring(1));
      } else if (condition.indexOf('!') === 0) {
        result = condition.toString().substring(1) !== fieldValue;
      } else {
        result = condition.toString() === fieldValue;
      }
      if (typeof this.dependencies[field] !== 'undefined') {
        result = result && this.checkConditions(this.dependencies[field]);
      }
    });
    return result;
  }
}
function initDependencies($container) {
  var _$container;
  // init container
  if (!((_$container = $container) !== null && _$container !== void 0 && _$container.length)) {
    $container = $document;
  }
  return new YITH_WCAF_Dependencies_Handler($container);
}
;// ./assets/js/src/modules/validation.js


/**
 * Internal dependencies
 */


class YITH_WCAF_Validation_Handler {
  constructor($container) {
    var _this$$container;
    // container
    _defineProperty(this, "$container", void 0);
    // error class to add/remove to fields wrapper
    _defineProperty(this, "ERROR_CLASS", 'woocommerce-invalid');
    this.$container = $container;
    if (!((_this$$container = this.$container) !== null && _this$$container !== void 0 && _this$$container.length)) {
      return;
    }
    this.initValidation();
  }

  // init validation.

  initValidation() {
    this.initForm();
    this.initFields();
  }
  initForm() {
    const $forms = this.$container.is('form') ? this.$container : this.$container.find('form');
    if (!$forms.length) {
      return;
    }
    $forms.on('submit yith_wcaf_validate_fields', ev => {
      const $form = external_jQuery_default()(ev.target),
        res = this.validateForm($form);
      if (!res) {
        ev.stopImmediatePropagation();
        return false;
      }
      return true;
    });
  }
  initFields() {
    const $fields = this.getFields(this.$container);
    if (!$fields.length) {
      return;
    }
    $fields.on('keyup change', ev => {
      const $field = external_jQuery_default()(ev.target);
      this.validateField($field);
    });
  }

  // fields handling.

  getFieldWrapper($field) {
    return $field.closest('.form-row, .yith-plugin-fw-panel-wc-row');
  }
  getFields($container) {
    const $fields = external_jQuery_default()('input, select, textarea', $container);
    return $fields.not('input[type="submit"]').not('.select2-search__field');
  }
  getVisibleFields($container) {
    const $fields = this.getFields($container);
    return $fields.filter((index, field) => {
      const $field = external_jQuery_default()(field),
        $fieldWrapper = this.getFieldWrapper($field);
      return $fieldWrapper.is(':visible');
    });
  }
  getInvalidVisibleFields($container) {
    const $fields = this.getVisibleFields($container);
    return $fields.filter((i, el) => {
      const $wrapper = this.getFieldWrapper(external_jQuery_default()(el));
      return $wrapper.length && $wrapper.is(`.${this.ERROR_CLASS}`);
    });
  }
  isFieldValid($field) {
    const $wrapper = this.getFieldWrapper($field),
      fieldType = $field.attr('type'),
      value = $field.val(),
      alwaysRequiredFields = ['reg_username', 'reg_email', 'reg_password'];

    // check for required fields
    if ($field.prop('required') || $wrapper.hasClass('required') || $wrapper.hasClass('validate-required') || $wrapper.hasClass('yith-plugin-fw--required') || alwaysRequiredFields.includes($field.get(0).id)) {
      if ($wrapper.hasClass('multi-check') && fieldType === 'checkbox') {
        const $siblings = this.getFields($wrapper);
        if (!$siblings.filter(':checked').length) {
          throw 'missing';
        }
      } else if (fieldType === 'checkbox' && !$field.is(':checked')) {
        throw 'missing';
      } else if (!value) {
        throw 'missing';
      }
    }

    // if not required and empty, no further validation is required.
    if (!value) {
      return true;
    }

    // check for patterns
    const pattern = $wrapper.data('pattern');
    if (pattern) {
      const regex = new RegExp(pattern);
      if (!regex.test(value)) {
        throw 'malformed';
      }
    }

    // check for min length
    const minLength = $wrapper.data('min_length') || $field.data('min_length');
    if (minLength && value.length < minLength) {
      throw 'short';
    }

    // check for max length
    const maxLength = $wrapper.data('max_length') || $field.data('max_length');
    if (maxLength && value.length > maxLength) {
      throw 'long';
    }

    // check for number
    if (fieldType === 'number') {
      const min = parseFloat($field.attr('min')),
        max = parseFloat($field.attr('max')),
        numVal = parseFloat(value);
      if (min && min > numVal || max && max < numVal) {
        throw 'overflow';
      }
    }

    // check for validation.
    const validation = $wrapper.data('validation') || $field.data('validation');
    if (validation === 'url' && !value.match(/^(https?:\/\/)?([\w.-]+)\.([a-z]{2,})(\/\S*)?$/i)) {
      throw 'wrong_url';
    } else if (validation === 'email' && !value.match(/^[\w-.]+@([\w-]+\.)+[\w-]{2,4}$/)) {
      throw 'wrong_email';
    }

    // all validation passed; we can return true.
    return true;
  }
  validateField($field) {
    let valid = false;
    try {
      valid = this.isFieldValid($field);
    } catch (e) {
      this.reportError($field, e);
      return valid;
    } finally {
      $field.trigger('yith_wcaf_validated_field', [this, $field, valid]);
    }
    this.removeError($field);
    return valid;
  }
  validateForm($form) {
    const $visibleFields = this.getVisibleFields($form);
    if (!$visibleFields.length) {
      return true;
    }
    let valid = true;
    $visibleFields.each((i, el) => {
      const $field = external_jQuery_default()(el);
      if (!this.validateField($field)) {
        valid = false;
      }
    });
    try {
      if (!valid) {
        // scroll top.
        this.scrollToFirstError($form);

        // stop form submitting.
        return false;
      }
      return true;
    } finally {
      $form.trigger('yith_wcaf_validated_form', [this, $form, valid]);
    }
  }

  // error handling.

  getErrorMsg($field, errorType) {
    var _labels$errors, _labels$errors2, _labels$errors3, _labels$errors4;
    let msg;
    switch (errorType) {
      case 'missing':
        const $wrapper = this.getFieldWrapper($field),
          $label = $wrapper.find('label');

        // check if we have a field-specific error message.
        msg = $field.data('error') || $wrapper.data('error') || $label.data('error');
        if (msg) {
          return msg;
        }
        const fieldType = $field.attr('type');
        msg = fieldType === 'checkbox' ? labels === null || labels === void 0 ? void 0 : (_labels$errors = labels.errors) === null || _labels$errors === void 0 ? void 0 : _labels$errors.accept_check : labels === null || labels === void 0 ? void 0 : (_labels$errors2 = labels.errors) === null || _labels$errors2 === void 0 ? void 0 : _labels$errors2.compile_field;
        if (msg) {
          return msg;
        }

      // fallthrough if we didn't find a proper message yet.
      default:
        msg = (labels === null || labels === void 0 ? void 0 : (_labels$errors3 = labels.errors) === null || _labels$errors3 === void 0 ? void 0 : _labels$errors3[errorType]) || (labels === null || labels === void 0 ? void 0 : (_labels$errors4 = labels.errors) === null || _labels$errors4 === void 0 ? void 0 : _labels$errors4.general_error);
        break;
    }
    return msg;
  }
  reportError($field, errorType) {
    const $wrapper = this.getFieldWrapper($field),
      errorMsg = this.getErrorMsg($field, errorType);
    $wrapper.addClass(this.ERROR_CLASS);
    if (!errorMsg) {
      return;
    }

    // remove existing errors.
    $wrapper.find('.error-msg').remove();

    // generate and append new error message.
    const $errorMsg = external_jQuery_default()('<span/>', {
      class: 'error-msg',
      text: errorMsg
    });
    $wrapper.append($errorMsg);

    // disable submit until error is not resolved.
    this.maybeDisableSubmit($field);
  }
  removeError($field) {
    const $wrapper = this.getFieldWrapper($field),
      $errorMsg = $wrapper.find('.error-msg');
    $wrapper.removeClass(this.ERROR_CLASS);
    $errorMsg.remove();

    // re-enable submit if all errors have been resolved.
    this.maybeEnableSubmit($field);
  }
  maybeDisableSubmit($container) {
    const $form = $container.is('form') ? $container : $container.closest('form'),
      $fields = this.getInvalidVisibleFields($form),
      $submit = $form.find('.submit, [type="submit"]');
    if (!$submit.length || !$fields.length) {
      return;
    }
    $submit.addClass('disabled');
  }
  maybeEnableSubmit($container) {
    const $form = $container.is('form') ? $container : $container.closest('form'),
      $fields = this.getInvalidVisibleFields($form),
      $submit = $form.find('.submit, [type="submit"]');
    if (!$submit.length || $fields.length) {
      return;
    }
    $submit.removeClass('disabled');
  }
  scrollToFirstError($form) {
    const $firstError = $form.find(`.${this.ERROR_CLASS}`).first();
    if (!$firstError.length) {
      return;
    }
    let $target = this.findScrollableParent($form);
    if (!$target || !$target.length) {
      $target = external_jQuery_default()('html, body');
    }
    const scrollDiff = $firstError.offset().top - $target.offset().top;
    let scrollValue = scrollDiff;
    if (!$target.is('html, body')) {
      scrollValue = $target.get(0).scrollTop + scrollDiff;
    }
    $target.animate({
      scrollTop: scrollValue
    });
  }
  findScrollableParent($node) {
    let node = $node.get(0);
    if (!node) {
      return null;
    }
    let overflowY, isScrollable;
    do {
      if (document === node) {
        return null;
      }
      overflowY = window.getComputedStyle(node).overflowY;
      isScrollable = overflowY !== 'visible' && overflowY !== 'hidden';
    } while (!(isScrollable && node.scrollHeight > node.clientHeight) && (node = node.parentNode));
    return external_jQuery_default()(node);
  }
}
function initValidation($container) {
  var _$container;
  // init container
  if (!((_$container = $container) !== null && _$container !== void 0 && _$container.length)) {
    $container = $document;
  }
  return new YITH_WCAF_Validation_Handler($container);
}
;// ./assets/js/src/modules/enhanced-selects.js


/**
 * Internal dependencies
 */

const enhanceSelect = (select, args) => {
    if (typeof (external_jQuery_default()).fn.selectWoo === 'undefined') {
      return;
    }
    const allowClear = !!select.data('allow_clear'),
      placeholder = select.data('placeholder'),
      minimumInputLength = select.data('minimum_input_length'),
      minimumResultsForSearch = select.data('minimum_results_for_search'),
      action = select.data('action'),
      ajax = !!action;
    let config = {
      allowClear,
      placeholder,
      minimumInputLength: minimumInputLength || 3,
      minimumResultsForSearch: minimumResultsForSearch || 0
    };
    if (ajax) {
      config.ajax = {
        url: ajaxUrl,
        dataType: 'json',
        delay: 250,
        data(params) {
          return {
            term: params.term,
            action,
            security: select.data('security'),
            exclude: select.data('exclude'),
            include: select.data('include'),
            limit: select.data('limit')
          };
        },
        processResults(data) {
          const terms = [];
          if (data) {
            external_jQuery_default().each(data, function (id, text) {
              terms.push({
                id,
                text
              });
            });
          }
          return {
            results: terms
          };
        },
        cache: true
      };
    }
    config = external_jQuery_default().extend(config, args || {});
    try {
      select.selectWoo(config).addClass('enhanced');
    } catch (e) {
      // skip to next.
    }
  },
  enhanceSelects = $container => {
    var _$container;
    if (typeof (external_jQuery_default()).fn.selectWoo === 'undefined') {
      return;
    }

    // init container
    if (!((_$container = $container) !== null && _$container !== void 0 && _$container.length)) {
      $container = $document;
    }
    const fieldToProcess = external_jQuery_default()('.yith-wcaf-enhanced-select', $container).not('.enhanced');
    fieldToProcess.each(function () {
      const select = external_jQuery_default()(this);
      enhanceSelect(select);
    });
  };

;// ./assets/js/src/modules/enhanced-datepickers.js


/**
 * Internal dependencies
 */

const enhanceDatepicker = field => {
    if (typeof (external_jQuery_default()).fn.datepicker === 'undefined') {
      return;
    }
    const format = field.data('format'),
      numberOfMonths = field.data('number-of-months'),
      maxDate = field.data('max-date'),
      minDate = field.data('min-date'),
      altField = field.data('altfield'),
      altFormat = field.data('altformat'),
      config = {
        dateFormat: format || 'yy-mm-dd',
        numberOfMonths: numberOfMonths || 1,
        maxDate: maxDate || null,
        minDate: minDate || null,
        altField: altField ? field.next(altField).get(0) : null,
        altFormat: altFormat || '',
        beforeShow(input, inst) {
          var _inst$dpDiv, _inst$dpDiv$addClass;
          inst === null || inst === void 0 ? void 0 : (_inst$dpDiv = inst.dpDiv) === null || _inst$dpDiv === void 0 ? void 0 : (_inst$dpDiv$addClass = _inst$dpDiv.addClass('yith-wcaf-datepicker')) === null || _inst$dpDiv$addClass === void 0 ? void 0 : _inst$dpDiv$addClass.addClass('yith-plugin-fw-datepicker-div');
        },
        onClose(input, inst) {
          var _inst$dpDiv2, _inst$dpDiv2$hide, _inst$dpDiv2$hide$rem;
          inst === null || inst === void 0 ? void 0 : (_inst$dpDiv2 = inst.dpDiv) === null || _inst$dpDiv2 === void 0 ? void 0 : (_inst$dpDiv2$hide = _inst$dpDiv2.hide()) === null || _inst$dpDiv2$hide === void 0 ? void 0 : (_inst$dpDiv2$hide$rem = _inst$dpDiv2$hide.removeClass('yith-wcaf-datepicker')) === null || _inst$dpDiv2$hide$rem === void 0 ? void 0 : _inst$dpDiv2$hide$rem.removeClass('yith-plugin-fw-datepicker-div');
        }
      };
    try {
      field.datepicker(config).addClass('enhanced');
    } catch (e) {
      // skip to next field.
    }
  },
  enhanceDatepickers = $container => {
    var _$container;
    if (typeof (external_jQuery_default()).fn.datepicker === 'undefined') {
      return;
    }

    // init container
    if (!((_$container = $container) !== null && _$container !== void 0 && _$container.length)) {
      $container = $document;
    }
    const fieldToProcess = external_jQuery_default()('.yith-wcaf-enhanced-date-picker', $container).add('.date-picker-field', $container).add('.date-picker', $container).not('.enhanced');
    fieldToProcess.each(function () {
      const field = external_jQuery_default()(this);
      enhanceDatepicker(field);
    });
  };

;// ./assets/js/src/modules/enhanced-accordion.js


/**
 * Internal dependencies
 */

const enhanceAccordion = $field => {
    const $radio = external_jQuery_default()('.accordion-radio', $field);
    if (!$radio.length) {
      return;
    }
    $radio.on('change', function () {
      const $checked = $radio.filter(':checked'),
        $option = $checked.closest('.accordion-option'),
        $content = $option.find('.accordion-content');
      external_jQuery_default()('.accordion-content', $field).not($content).slideUp();
      $content.slideDown();
    }).trigger('change');
    $field.addClass('enhanced');
  },
  enhanceAccordions = $container => {
    var _$container;
    // init container
    if (!((_$container = $container) !== null && _$container !== void 0 && _$container.length)) {
      $container = $document;
    }
    const $accordion = external_jQuery_default()('.yith-wcaf-accordion', $container).not('.enhanced');
    if (!$accordion.length) {
      return;
    }
    $accordion.each(function () {
      const $field = external_jQuery_default()(this);
      enhanceAccordion($field);
    });
  };

// export utilities

;// ./assets/js/src/modules/enhanced-uploaders.js


/**
 * Internal dependencies
 */

const enhanceUploader = $anchor => {
    const $input = $anchor.parent().find('input[type="file"]');
    if (!$input.length) {
      return;
    }
    $anchor.on('click', function (ev) {
      ev.preventDefault();
      $input.trigger('click');
    }).addClass('enhanced');
    $input.on('change', function (ev) {
      const files = ev.target.files,
        fileNames = [];
      let $fileList = $anchor.next('small.files-list');
      for (const i in files) {
        if (!files.hasOwnProperty(i)) {
          continue;
        }
        fileNames.push(files[i].name);
      }
      if (!fileNames.length) {
        if ($fileList.length) {
          $fileList.remove();
        }
      } else {
        if (!$fileList.length) {
          $fileList = external_jQuery_default()('<small/>', {
            class: 'files-list'
          });
          $anchor.after($fileList);
        }
        $fileList.text(fileNames.join(', '));
        if ($anchor.hasClass('auto-submit')) {
          const $form = $anchor.closest('form');
          if (!$form.length) {
            return;
          }
          $form.submit();
        }
      }
    });
  },
  enhanceUploaders = $container => {
    var _$container;
    // init container
    if (!((_$container = $container) !== null && _$container !== void 0 && _$container.length)) {
      $container = $document;
    }
    const $uploaders = external_jQuery_default()('.yith-wcaf-attach-file', $container).not('.enhanced');
    if (!$uploaders.length) {
      return;
    }
    $uploaders.each(function () {
      const $field = external_jQuery_default()(this);
      enhanceUploader($field);
    });
  };

// export utilities

;// ./assets/js/admin/src/modules/fields.js


/**
 * Internal dependencies
 */







const initFields = $container => {
  var _$container;
  // init container
  if (!((_$container = $container) !== null && _$container !== void 0 && _$container.length)) {
    $container = $document;
  }

  // enhance selects
  enhanceSelects($container);

  // enhance datepicker
  enhanceDatepickers($container);

  // enhance toggles
  enhanceAccordions($container);

  // enhance uploader
  enhanceUploaders($container);

  // init dependencies
  initDependencies($container);

  // init validation
  initValidation($container);

  // enhance template fields
  (() => {
    const fieldToProcess = external_jQuery_default()('.yith-wcaf-enhanced-template', $container).not('.enhanced');
    fieldToProcess.each(function () {
      const $template = external_jQuery_default()(this),
        $editor = $template.find('.editor');
      external_jQuery_default()('a.toggle_editor', $template).text(function () {
        const $this = external_jQuery_default()(this);
        $this.text($this.data('view-label'));
      }).on('click', function () {
        const $this = external_jQuery_default()(this),
          editorVisible = $editor.is(':visible');
        $this.text($this.data(`${editorVisible ? 'view' : 'hide'}-label`));
        $editor.slideToggle();
        return false;
      });
      external_jQuery_default()('a.delete_template', $template).on('click', function () {
        return window.confirm(external_jQuery_default()(this).data('confirm')); // eslint-disable-line no-alert
      });
      external_jQuery_default()('textarea', $editor).on('change', function () {
        const $this = external_jQuery_default()(this),
          name = $this.attr('data-name');
        if (name) {
          $this.attr('name', name);
        }
      });
      $template.addClass('enhanced');
    });
  })();

  // editable sections
  (() => {
    const $editable = external_jQuery_default()('.editable', $container).not('.enhanced');
    $editable.one('click', function () {
      const $trigger = external_jQuery_default()(this),
        $parent = $trigger.parent(),
        $target = $parent.find('.edit');
      if (!$target) {
        return;
      }
      const $edited = $parent.find('.edited');
      $edited.slideUp();
      $target.slideDown();
      $trigger.removeClass('editable');
    }).addClass('enhanced');
  })();

  // init custom fields postfix
  (() => {
    const $fields = external_jQuery_default()('[data-postfix]', $container).not('.enhanced');
    $fields.each(function () {
      const $field = external_jQuery_default()(this),
        $postfix = external_jQuery_default()('<span/>', {
          class: 'field-postfix',
          text: $field.data('postfix'),
          style: 'line-height: 2.5; margin-left: 5px;'
        });
      $field.after($postfix);
    });
  })();

  // remove edit prompt when not needed (double $( fn ) is required to perform code after WC's handler)
  external_jQuery_default()(() => {
    // when field is contained in one of these items, onbeforeunload will be ignored
    const exceptions = ['.yith-plugin-fw-list-table-container', '#yith-wcaf-list-table-form'];
    external_jQuery_default()('input, textarea, select', $container).on('change', function () {
      const $closest = external_jQuery_default()(this).closest(exceptions.join(', '));
      if (!$closest.length) {
        return;
      }
      window.onbeforeunload = null;
    });
  });
};

// export utilities

;// ./assets/js/admin/src/modules/yith-wcaf-admin-panel.js


/**
 * Internal dependencies
 */



class YITH_WCAF_Admin_Panel {
  constructor($container) {
    // container object.
    _defineProperty(this, "$container", void 0);
    this.$container = $container;
    this.initFields();
    this.initBulkAction();
  }
  initFields() {
    initFields(this.$container);
  }
  initBulkAction() {
    const $bulkActionSelect = external_jQuery_default()('#bulk-action-selector-top', this.$container);
    if (!$bulkActionSelect.length) {
      return;
    }
    enhanceSelect($bulkActionSelect, {
      minimumResultsForSearch: Infinity,
      minimumInputLength: 0
    });
  }
}
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
;// ./assets/js/admin/src/modules/yith-wcaf-admin-table.js


/**
 * Internal dependencies
 */



class YITH_WCAF_Admin_Table {
  constructor($context, args) {
    // context
    _defineProperty(this, "$context", null);
    // table
    _defineProperty(this, "$table", null);
    // args
    _defineProperty(this, "args", null);
    if (!$context.length) {
      return;
    }
    let $table = external_jQuery_default()('table.wp-list-table', $context);
    if (!$table.length) {
      $table = external_jQuery_default()('.no-items-found', $context);
    }
    if (!$table.length) {
      return;
    }
    this.$context = $context;
    this.$table = $table;
    this.args = external_jQuery_default().extend({
      action_suffix: '',
      nonce_suffix: ''
    }, args || {});
    this.init();
  }
  init() {}
  doButtonAction(action, $button, onSuccess) {
    var _this$args, _this$args2, _this$args3;
    const $row = $button.closest('tr');
    if (!$row.length) {
      return;
    }
    let data = (_this$args = this.args) === null || _this$args === void 0 ? void 0 : _this$args.data;
    if (typeof data === 'function') {
      data = data.call(this, $row);
    }
    this.doAjax(`${action}${((_this$args2 = this.args) === null || _this$args2 === void 0 ? void 0 : _this$args2.action_suffix) || ''}`, `${action}${((_this$args3 = this.args) === null || _this$args3 === void 0 ? void 0 : _this$args3.nonce_suffix) || ''}`, data, result => {
      if (typeof onSuccess === 'function') {
        onSuccess($row, result);
      }
      if (result !== null && result !== void 0 && result.template) {
        this.replaceTable(result.template);
      }
    });
  }
  doAjax(action, security, data, onSuccess) {
    return ajax.post.call(this.$context, action, security, data).done(answerData => {
      if (typeof onSuccess === 'function' && answerData !== null && answerData !== void 0 && answerData.success) {
        onSuccess(answerData === null || answerData === void 0 ? void 0 : answerData.data);
      }
    });
  }
  replaceTable(newTable) {
    const $newTable = external_jQuery_default()(newTable).find('table');
    if (!$newTable.length) {
      return;
    }
    this.$table.replaceWith($newTable);
    this.$table = $newTable;
  }
}
;// ./assets/js/admin/src/modules/yith-wcaf-admin-modal.js


/* globals yith */

/**
 * Internal dependencies
 */



class YITH_WCAF_Admin_Modal {
  constructor($opener, args) {
    var _yith, _yith$ui;
    // modal opener
    _defineProperty(this, "$opener", null);
    // target of the open event
    _defineProperty(this, "$target", null);
    // modal object
    _defineProperty(this, "modal", null);
    // fields validator
    _defineProperty(this, "validator", null);
    // template variables.
    _defineProperty(this, "args", null);
    if (!($opener !== null && $opener !== void 0 && $opener.length) || typeof ((_yith = yith) === null || _yith === void 0 ? void 0 : (_yith$ui = _yith.ui) === null || _yith$ui === void 0 ? void 0 : _yith$ui.modal) === 'undefined') {
      return;
    }
    this.$opener = $opener;
    this.args = external_jQuery_default().extend({
      title: '',
      content: false,
      footer: false,
      showClose: true,
      width: 500,
      onCreate: false,
      onClose: false,
      shouldOpen: null,
      template: false,
      titleTemplate: false,
      data: false,
      classes: {
        wrap: 'yith-wcaf-modal'
      }
    }, args || {});
    this.init();
  }

  // setters

  addOpeners($newOpeners) {
    this.$opener = this.$opener.add($newOpeners);
    this.reinit();
  }

  // init methods

  init() {
    this.$opener.on('click', ev => {
      this.$target = external_jQuery_default()(ev.target);
      if (!this.shouldOpen()) {
        return;
      }
      ev.preventDefault();
      this.onOpen();
    });
  }
  reinit() {
    this.$opener.off('click');
    this.init();
  }
  initFields() {
    const $fields = this.getFields();

    // init fields values.
    $fields.filter('[data-value]').each(function () {
      const $field = external_jQuery_default()(this),
        value = $field.data('value');
      if ($field.is('input[type="checkbox"]') || $field.is('input[type="radio"]')) {
        if (typeof value === 'boolean') {
          $field.prop('checked', value);
        } else if (value) {
          $field.prop('checked', value === $field.val());
        } else {
          $field.prop('checked', false);
        }
      } else if ($field.is('select') && Array.isArray(value)) {
        $field.val(value);
      } else if ($field.is('select') && typeof value === 'object') {
        for (const i in value) {
          var _$field$find;
          if (!((_$field$find = $field.find(`[value="${i}"]`)) !== null && _$field$find !== void 0 && _$field$find.length)) {
            $field.append(external_jQuery_default()('<option/>', {
              value: i,
              text: value[i]
            }));
          }
        }
        $field.val(Object.keys(value));
      } else if (typeof value === 'boolean') {
        $field.val(value ? 1 : 0);
      } else if (value) {
        $field.val(String(value));
      }
      $field.trigger('change');
    });

    // init custom fields handling
    initFields(this.modal.elements.content);
  }
  initSubmit() {
    const $content = this.modal.elements.content,
      $form = $content.find('form');
    $form.on('submit', this.onSubmit.bind(this));
  }

  // events handling

  getData() {
    var _this$args;
    let data = ((_this$args = this.args) === null || _this$args === void 0 ? void 0 : _this$args.data) || {};
    if (typeof data === 'function') {
      data = data.call(this);
    }
    return data;
  }
  shouldOpen() {
    var _this$args2;
    if (typeof ((_this$args2 = this.args) === null || _this$args2 === void 0 ? void 0 : _this$args2.shouldOpen) === 'function') {
      return this.args.shouldOpen.call(this);
    }
    return true;
  }
  onOpen() {
    const args = {
        ...this.args
      },
      template = (args === null || args === void 0 ? void 0 : args.template) || '',
      titleTemplate = (args === null || args === void 0 ? void 0 : args.titleTemplate) || '',
      data = this.getData();
    if (template) {
      args.content = wp.template(template)(data);
    } else if (typeof (args === null || args === void 0 ? void 0 : args.content) === 'function') {
      args.content = args.content.call(this, data);
    }
    if (titleTemplate) {
      args.title = wp.template(titleTemplate)(data);
    } else if (typeof (args === null || args === void 0 ? void 0 : args.title) === 'function') {
      args.title = args.title.call(this, data);
    }
    this.modal = yith.ui.modal(args);

    // init enhanced selects
    external_jQuery_default()(document.body).trigger('wc-enhanced-select-init');

    // trigger additional functionalities
    this.initFields();

    // trigger submit handling.
    this.initSubmit();
  }
  beforeSubmit() {
    return true;
  }
  onSubmit() {
    var _this$args3;
    if (!this.beforeSubmit()) {
      return false;
    }
    if (typeof ((_this$args3 = this.args) === null || _this$args3 === void 0 ? void 0 : _this$args3.onSubmitSuccess) === 'function') {
      var _this$args4;
      (_this$args4 = this.args) === null || _this$args4 === void 0 ? void 0 : _this$args4.onSubmitSuccess();
    }
  }

  // fields handling

  getField(fieldName) {
    return this.getFields().filter(`[name="${fieldName}"]`).first();
  }
  getFields() {
    const $content = this.modal.elements.content;
    return $content.find(':input').not('button, input[type="submit"], .select2-search__field');
  }
  serialize() {
    const $fields = this.getFields();
    const data = {};
    $fields.get().reduce((a, field) => {
      const $field = external_jQuery_default()(field);
      let value;
      if ($field.is('input[type="checkbox"]')) {
        if (!$field.is(':checked')) {
          return a;
        }
        value = 1;
      } else {
        value = $field.val();
      }
      const name = $field.attr('name');
      if (name.indexOf('[') !== -1) {
        // if name is composite, try to recreate missing structure
        const components = name.split('[').map(c => c.replace(/[\[, \]]/g, '')),
          firstComponent = components.shift(),
          newItem = components.reverse().reduce((res, key) => ({
            [key]: res
          }), value);
        if (typeof data[firstComponent] === 'undefined') {
          data[firstComponent] = newItem;
        } else {
          data[firstComponent] = external_jQuery_default().extend(true, data[firstComponent], newItem);
        }
      } else {
        // else simply append value to result object
        data[name] = value;
      }
      return a;
    }, data);
    return data;
  }

  // modal level errors

  showErrorMessage(message) {
    const $content = this.modal.elements.content,
      $error = external_jQuery_default()('<div/>', {
        class: 'error-message form-row',
        text: message
      });
    this.hideErrorMessage();
    $content.prepend($error);
    $error.get(0).scrollIntoView();
  }
  hideErrorMessage() {
    const $content = this.modal.elements.content;
    $content.children('.error-message').remove();
  }
}
;// ./assets/js/admin/src/modules/yith-wcaf-admin-actions-table.js


/**
 * Internal dependencies
 */




class YITH_WCAF_Admin_Actions_Table extends YITH_WCAF_Admin_Table {
  constructor(...args) {
    super(...args);
    // modal
    _defineProperty(this, "modal", null);
  }
  init() {
    this.initModal();
    this.initDragNDrop();
    this.initCloneButtons();
    this.initDeleteButtons();
    this.initEnableButtons();
    this.initRestoreDefaultButton();
  }
  initCloneButtons() {
    this.$context.on('click', '.clone a', ev => {
      ev.preventDefault();
      this.doButtonAction('clone', external_jQuery_default()(ev.target));
    });
  }
  initDeleteButtons() {
    this.$context.on('click', '.delete a', ev => {
      ev.preventDefault();
      globals_confirm().then(() => {
        this.doButtonAction('delete', external_jQuery_default()(ev.target), $row => $row.remove());
      });
    });
  }
  initEnableButtons() {
    this.$context.on('change', '.enabled input[type="checkbox"]', ev => {
      ev.preventDefault();
      const $field = external_jQuery_default()(ev.target),
        action = $field.is(':checked') ? 'enable' : 'disable';
      this.doButtonAction(action, $field);
    });
  }
  initModal() {
    const $openers = this.getModalOpeners();
    if ($openers.length) {
      this.modal = new YITH_WCAF_Admin_Modal($openers);
    }
  }
  initDragNDrop() {
    this.$table.find('tbody').sortable({
      handle: '.drag',
      update: () => {
        var _this$args, _this$args2;
        const $rows = this.$table.find('tbody').find('tr'),
          rows = $rows.get(),
          order = rows.map(item => item.getAttribute('data-id'));
        this.doAjax(`sort${(_this$args = this.args) === null || _this$args === void 0 ? void 0 : _this$args.action_suffix}s`, `sort${(_this$args2 = this.args) === null || _this$args2 === void 0 ? void 0 : _this$args2.nonce_suffix}s`, {
          order
        });
      }
    });
  }
  initRestoreDefaultButton() {
    external_jQuery_default()('#restore_defaults', this.$context).on('click', ev => {
      var _this$args3, _this$args4;
      ev.preventDefault();
      this.doAjax(`restore_default${(_this$args3 = this.args) === null || _this$args3 === void 0 ? void 0 : _this$args3.action_suffix}s`, `restore${(_this$args4 = this.args) === null || _this$args4 === void 0 ? void 0 : _this$args4.nonce_suffix}s`, null, data => {
        if (data !== null && data !== void 0 && data.template) {
          this.replaceTable(data.template);
        }
      });
    });
  }
  getModalOpeners() {
    return external_jQuery_default()('.edit', this.$table).add('#add_field', this.$context).add('.yith-add-button', this.$context);
  }
  refreshModalOpeners() {
    this.modal.addOpeners(this.getModalOpeners());
  }
  replaceTable(newTable) {
    super.replaceTable(newTable);

    // register new modal openers
    this.initModal();

    // re-init drag&Drop
    this.initDragNDrop();
  }
}
;// ./assets/js/admin/src/modules/yith-wcaf-add-rate-rule-modal.js


/**
 * Internal dependencies
 */



class YITH_WCAF_Add_Rate_Rule_Modal extends YITH_WCAF_Admin_Modal {
  constructor($opener, args) {
    super($opener, {
      template: 'yith-wcaf-add-rate-rule-modal',
      title: data => {
        return data.name ? labels === null || labels === void 0 ? void 0 : labels.edit_rate_rule_title : labels === null || labels === void 0 ? void 0 : labels.add_rate_rule_title;
      },
      data() {
        const $target = this.$target,
          $dataSource = $target === null || $target === void 0 ? void 0 : $target.closest('[data-item]');
        if (!$dataSource || !$dataSource.length) {
          return {};
        }
        return $dataSource.data('item');
      },
      width: 400,
      ...args
    });
  }
  onSubmit() {
    const $content = this.modal.elements.content;
    if (!this.beforeSubmit()) {
      return false;
    }
    this.hideErrorMessage();
    ajax.post.call($content, 'save_rate_rule', 'save_rate_rule', {
      rule: this.serialize()
    }).done(data => {
      var _this$args, _data$data;
      if (typeof ((_this$args = this.args) === null || _this$args === void 0 ? void 0 : _this$args.onSubmitSuccess) === 'function') {
        var _this$args2;
        (_this$args2 = this.args) === null || _this$args2 === void 0 ? void 0 : _this$args2.onSubmitSuccess(data === null || data === void 0 ? void 0 : data.data);
      }
      if (data !== null && data !== void 0 && data.success) {
        this.modal.close();
      } else if (data !== null && data !== void 0 && (_data$data = data.data) !== null && _data$data !== void 0 && _data$data.message) {
        this.showErrorMessage(data.data.message);
      }
    });
    return false;
  }
}
;// ./assets/js/admin/src/modules/yith-wcaf-rate-rules-table.js


/**
 * Internal dependencies
 */


class YITH_WCAF_Rate_Rules_Table extends YITH_WCAF_Admin_Actions_Table {
  constructor($context, args) {
    super($context, {
      action_suffix: '_rate_rule',
      nonce_suffix: '_rate_rule',
      data: $row => {
        return {
          rule_id: $row.data('id')
        };
      },
      ...args
    });
  }
  initModal() {
    const $openers = this.getModalOpeners();
    if ($openers.length) {
      this.modal = new YITH_WCAF_Add_Rate_Rule_Modal($openers, {
        onSubmitSuccess: data => {
          if (!(data !== null && data !== void 0 && data.template)) {
            return;
          }
          this.replaceTable(data === null || data === void 0 ? void 0 : data.template);
        }
      });
    }
  }
}
;// ./assets/js/admin/src/rates.js


/**
 * Internal dependencies
 */



class YITH_WCAF_Rates extends YITH_WCAF_Admin_Panel {
  constructor() {
    super(external_jQuery_default()('#yith_wcaf_panel_affiliates-rates'));
    this.initTable();
  }
  initTable() {
    const $context = external_jQuery_default()('#yith_wcaf_panel_affiliates-rates');
    new YITH_WCAF_Rate_Rules_Table($context);
  }
}
external_jQuery_default()(() => new YITH_WCAF_Rates());
var __webpack_export_target__ = window;
for(var __webpack_i__ in __webpack_exports__) __webpack_export_target__[__webpack_i__] = __webpack_exports__[__webpack_i__];
if(__webpack_exports__.__esModule) Object.defineProperty(__webpack_export_target__, "__esModule", { value: true });
/******/ })()
;
//# sourceMappingURL=yith-wcaf-rates.bundle.js.map