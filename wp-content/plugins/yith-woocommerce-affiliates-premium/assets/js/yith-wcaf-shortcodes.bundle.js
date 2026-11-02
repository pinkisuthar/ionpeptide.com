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

;// ./assets/js/src/modules/enhanced-toggles.js


/**
 * Internal dependencies
 */

const enhanceToggle = $field => {
    if ($field.next('.toggle').length) {
      return;
    }
    const text_on = $field.data('text-on'),
      text_off = $field.data('text-off'),
      $toggle = external_jQuery_default()('<span/>', {
        class: 'toggle',
        'data-text-on': text_on || labels.toggle_on,
        'data-text-off': text_off || labels.toggle_off
      });
    $field.after($toggle).addClass('enhanced');
  },
  enhanceToggles = $container => {
    var _$container;
    // init container
    if (!((_$container = $container) !== null && _$container !== void 0 && _$container.length)) {
      $container = $document;
    }
    const $toggles = external_jQuery_default()('.yith-wcaf-toggle', $container).not('.enhanced');
    if (!$toggles.length) {
      return;
    }
    $toggles.each(function () {
      const $field = external_jQuery_default()(this);
      enhanceToggle($field);
    });
  };

// export utilities

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
;// ./assets/images/eye.svg
const eye_namespaceObject = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg width=\"24px\" height=\"24px\" viewBox=\"0 0 24 24\" version=\"1.1\" xmlns=\"http://www.w3.org/2000/svg\" xmlns:xlink=\"http://www.w3.org/1999/xlink\">\n    <defs>\n        <rect id=\"path-1\" x=\"0\" y=\"0\" width=\"24\" height=\"24\"></rect>\n    </defs>\n    <g id=\"Symbols\" stroke=\"none\" stroke-width=\"1\">\n        <g id=\"edit-/-show\">\n            <mask id=\"mask-2\" fill=\"white\">\n                <use xlink:href=\"#path-1\"></use>\n            </mask>\n            <g id=\"edit-/-show-(Background/Mask)\"></g>\n            <path d=\"M9,12 C9,13.642 10.358,15 12,15 C13.641,15 15,13.642 15,12 C15,10.359 13.641,9 12,9 C10.358,9 9,10.359 9,12 Z M2.10543073,11.684 L2,12 L2.10443617,12.316 C2.12631801,12.383 4.40799665,19 12,19 C19.5920014,19 21.8736803,12.383 21.8945675,12.316 L22,12 L21.8955631,11.684 C21.8736813,11.617 19.5920014,5 12,5 C4.40799665,5 2.12631794,11.617 2.10543073,11.684 Z M4.11657,12 C4.617863,10.842 6.68072367,7 12,7 C17.322258,7 19.3841249,10.846 19.8834286,12 C19.3821356,13.158 17.3192744,17 12,17 C6.6777401,17 4.61587369,13.154 4.11657,12 Z\" fill=\"#000\" mask=\"url(#mask-2)\"></path>\n        </g>\n    </g>\n</svg>";
;// ./assets/js/src/modules/fields.js


/**
 * Internal dependencies
 */









const initFields = $container => {
  var _$container;
  // init container
  if (!((_$container = $container) !== null && _$container !== void 0 && _$container.length)) {
    $container = $document;
  }

  // init toggles
  enhanceToggles($container);

  // init datepicker
  enhanceDatepickers($container);

  // init accordions
  enhanceAccordions($container);

  // init selects
  enhanceSelects($container);

  // attach field
  enhanceUploaders($container);

  // password field
  (() => {
    const $password = external_jQuery_default()('input[type="password"]', $container);
    if (!$password.length) {
      return;
    }
    $password.each(function () {
      const $field = external_jQuery_default()(this);
      if ($field.hasClass('enhanced')) {
        return;
      }
      const $wrapper = external_jQuery_default()('<div/>', {
          class: 'password-wrapper'
        }),
        $toggleButton = external_jQuery_default()('<a/>', {
          class: 'toggle-button click-to-show',
          role: 'button',
          href: '',
          html: eye_namespaceObject,
          title: (labels === null || labels === void 0 ? void 0 : labels.show_password_text) || 'Show/Hide password'
        });
      $field.wrap($wrapper);
      $field.after($toggleButton);
      $toggleButton.on('click', function (ev) {
        const type = $field.attr('type'),
          isPassword = type === 'password',
          newType = isPassword ? 'text' : 'password',
          newClass = isPassword ? 'click-to-hide' : 'click-to-show';
        ev.preventDefault();
        $field.attr('type', newType);
        $toggleButton.removeClass('click-to-show click-to-hide').addClass(newClass);
      });
      $field.addClass('enhanced');
    });
  })();

  // type field
  (() => {
    const $type = external_jQuery_default()('#invoice_type_field', $container);
    if (!$type.length || $type.hasClass('enhanced')) {
      return;
    }
    let cnt = 1;
    $type.addClass('enhanced').find('label').each(function () {
      const $label = external_jQuery_default()(this),
        $checkbox = $label.prev(),
        wrapperClass = `col${cnt}`;
      $label.add($checkbox).wrapAll(`<div class="${wrapperClass}">`);
      cnt++;
    });
  })();

  // init validation/dependencies on forms
  (() => {
    const $form = external_jQuery_default()('form', $container);
    if (!$form.length) {
      return false;
    }
    initValidation($form);
    initDependencies($form);
  })();

  // trigger after init
  $document.trigger('yith_wcaf_init_fields');
};

// export utilities

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
;// ./assets/js/src/modules/set-cookie.js


/* global yith_wcaf, location */

/**
 * Internal dependencies
 */

const setCookie = () => {
  if (!yith_wcaf.set_cookie_via_ajax || !yith_wcaf.referral_var) {
    return;
  }
  const urlParams = new URLSearchParams(location.search);
  if (!urlParams.has(yith_wcaf.referral_var)) {
    return;
  }
  ajax.get.call(null, 'set_cookie', '', {
    [yith_wcaf.referral_var]: urlParams.get(yith_wcaf.referral_var)
  });
};

// export utilities

;// ./assets/js/src/modules/yith-wcaf-copy-button.js


/* global navigator */

/**
 * Internal dependencies
 */


class YITH_WCAF_Copy_Button {
  constructor($trigger, $target) {
    // copy button
    _defineProperty(this, "$trigger", void 0);
    // event initiator
    _defineProperty(this, "$initiator", void 0);
    // target whose content should be copied
    _defineProperty(this, "target", void 0);
    if (!$trigger.length) {
      return;
    }
    this.$trigger = $trigger;
    this.target = $target;
    this.init();
  }
  init() {
    this.$trigger.off('click').on('click', this.onClick.bind(this));
  }
  onClick(ev) {
    ev.preventDefault();
    this.$initiator = external_jQuery_default()(ev.target);
    if (this.copyContent(ev)) {
      this.outputNotification();
    }
  }
  copyContent(ev) {
    const target = this.target;
    let $target;
    if (typeof target === 'function') {
      $target = target(this.$trigger, ev);
    } else {
      $target = target;
    }
    if (!$target.length) {
      return false;
    }
    if ($target.is('input')) {
      this.copyInputContent($target);
    } else {
      this.copyAnyContent($target);
    }
    return true;
  }
  copyInputContent($target) {
    var _navigator;
    if (window.isSecureContext && (_navigator = navigator) !== null && _navigator !== void 0 && _navigator.clipboard) {
      return navigator.clipboard.writeText($target.val());
    }
    this.selectContent($target);
    document.execCommand('copy');
  }
  copyAnyContent($target) {
    var _navigator2;
    if (window.isSecureContext && (_navigator2 = navigator) !== null && _navigator2 !== void 0 && _navigator2.clipboard) {
      return navigator.clipboard.writeText($target.text());
    }
    const $hidden = external_jQuery_default()('<input/>', {
      val: $target.text(),
      type: 'text'
    });
    $body.append($hidden);
    this.selectContent($hidden);
    document.execCommand('copy');
    $hidden.remove();
  }
  selectContent($target) {
    if (this.isIos()) {
      $target.get(0).setSelectionRange(0, 9999);
    } else {
      $target.select();
    }
  }
  outputNotification() {
    if (!$document.triggerHandler('yith_wcaf_hide_link_copied_alert') && labels !== null && labels !== void 0 && labels.link_copied_message) {
      const $confirmBubble = external_jQuery_default()('<span/>', {
        class: 'copy-confirmation',
        text: labels.link_copied_message
      });
      $confirmBubble.prependTo(this.$initiator).fadeIn(300, () => {
        setTimeout(() => {
          $confirmBubble.fadeOut(300, () => {
            $confirmBubble.remove();
          });
        }, 1000);
      });
    }
  }
  isIos() {
    return navigator.userAgent.match(/ipad|iphone/i);
  }
}
;// ./assets/js/src/modules/yith-wcaf-link-generator.js


/**
 * Internal dependencies
 */




class YITH_WCAF_Link_Generator {
  constructor($container) {
    // container
    _defineProperty(this, "$container", void 0);
    // source input
    _defineProperty(this, "$source", void 0);
    // username input, if any
    _defineProperty(this, "$username", void 0);
    // destination input
    _defineProperty(this, "$destination", void 0);
    // affiliate token
    _defineProperty(this, "token", void 0);
    // query string for token
    _defineProperty(this, "token_var", void 0);
    // affiliate id, if any
    _defineProperty(this, "affiliate", void 0);
    // timeout used for debouncing
    _defineProperty(this, "timeout", void 0);
    // timout interval for debouncing
    _defineProperty(this, "timeoutInterval", 500);
    if (!($container !== null && $container !== void 0 && $container.length)) {
      return;
    }
    this.$container = $container;
    this.token = this.$container.data('token');
    this.token_var = this.$container.data('token-var');
    this.affiliate = this.$container.data('affiliate');
    this.init();
  }
  init() {
    this.$source = external_jQuery_default()('.origin-url', this.$container);
    this.$destination = external_jQuery_default()('.generated-url', this.$container);
    this.$username = external_jQuery_default()('.username', this.$container);
    if (!this.$source.length || !this.$destination.length) {
      return;
    }
    this.initActions();
    this.initCopyButton();
  }
  initActions() {
    this.$source.on('change keyup', this.onChange.bind(this)).trigger('change');
  }
  initCopyButton() {
    this.$container.find('.copy-trigger').each(function () {
      const $trigger = external_jQuery_default()(this),
        $target = $trigger.parent().find('.copy-target');
      if (!$target.length) {
        return;
      }
      new YITH_WCAF_Copy_Button($trigger, $target);
    });
  }
  onChange() {
    // debounce
    if (this.timeout) {
      clearTimeout(this.timeout);
    }
    if (!this.$source.val()) {
      return;
    }
    this.timeout = setTimeout(this.updateUrl.bind(this), this.timeoutInterval);
  }
  async updateUrl() {
    const base = this.$source.val();
    let url;
    if (this.token_var && this.token) {
      url = this.calculateUrl(base, this.token_var, this.token);
    } else {
      const user = this.$username.val(),
        affiliate = this.affiliate;
      url = await this.requestUrl(base, user, affiliate);
    }
    this.$destination.val(url);
  }
  calculateUrl(base, token_var, token) {
    let url;
    try {
      url = new URL(base);

      // if passed url's origin is different from current one, return.
      if (url.origin !== window.location.origin) {
        return '';
      }

      // append referral param.
      url.searchParams.set(token_var, token);
    } catch (e) {
      return '';
    }
    return url.toString();
  }
  requestUrl(base, user, affiliate_id) {
    return new Promise(resolve => {
      ajax.get.call(this.$container, 'get_referral_url', 'get_referral_url', {
        base,
        user,
        affiliate_id
      }).done(data => {
        let url;
        if (data !== null && data !== void 0 && data.success) {
          var _data$data;
          url = data === null || data === void 0 ? void 0 : (_data$data = data.data) === null || _data$data === void 0 ? void 0 : _data$data.url;
        } else {
          url = '';
        }
        resolve(url);
      }).fail(() => resolve(''));
    });
  }
}
;// ./assets/js/src/modules/yith-wcaf-set-referrer.js


/**
 * Internal dependencies
 */



class YITH_WCAF_Set_Referrer {
  constructor($container) {
    // container
    _defineProperty(this, "$container", void 0);
    // box opener
    _defineProperty(this, "$opener", void 0);
    // form
    _defineProperty(this, "$form", void 0);
    // token input
    _defineProperty(this, "$token", void 0);
    if (!($container !== null && $container !== void 0 && $container.length)) {
      return;
    }
    this.$container = $container;
    this.$opener = this.$container.find('.show-referrer-form');
    this.$form = this.$container.find('form.referrer-form');
    this.$token = this.$form.find('input[name="referrer_code"]');
    this.init();
  }
  init() {
    this.$opener.on('click', this.onToggle.bind(this));
    this.$form.on('submit', this.onSubmit.bind(this));
  }
  onToggle(ev) {
    ev.preventDefault();
    this.toggleForm();
  }
  onSubmit(ev) {
    ev.preventDefault();
    const referrer = this.$token.val();
    if (!referrer) {
      return false;
    }
    this.$form.addClass('processing');
    this.setReferrer(referrer).done(data => {
      this.$form.removeClass('processing');
      this.afterSubmit(data);
    });
  }
  afterSubmit(data) {
    var _data$data;
    this.$container.find('.woocommerce-error, .woocommerce-message').remove();
    if (data !== null && data !== void 0 && (_data$data = data.data) !== null && _data$data !== void 0 && _data$data.template) {
      var _data$data2;
      this.$form.before(data === null || data === void 0 ? void 0 : (_data$data2 = data.data) === null || _data$data2 === void 0 ? void 0 : _data$data2.template);
    }
    if (data !== null && data !== void 0 && data.success) {
      this.$form.slideUp();
      this.$token.prop('disabled', true);
      $document.trigger('yith_wcaf_referrer_set');
    }
  }
  toggleForm() {
    this.$form.slideToggle();
  }
  setReferrer(referrer) {
    return ajax.post.call(this.$container, 'set_referrer', 'set_referrer', {
      referrer
    });
  }
}
;// ./assets/js/src/modules/yith-wcaf-tooltip.js


/**
 * Internal dependencies
 */


class YITH_WCAF_Tooltip {
  constructor($opener) {
    // copy button
    _defineProperty(this, "$opener", void 0);
    // tooltip
    _defineProperty(this, "$tooltip", void 0);
    if (!$opener.length || !$opener.data('tip')) {
      return;
    }
    this.$opener = $opener.first();
    this.init();
  }
  init() {
    this.$opener.on('mouseenter', () => this.onMouseEnter()).on('mouseleave', () => this.onMouseLeave());
  }
  hasTooltip() {
    return this.$opener.find('.tooltip').length;
  }
  onMouseEnter() {
    if (!this.hasTooltip()) {
      this.attachTooltip();
    }
    this.showTooltip();
  }
  onMouseLeave() {
    this.hideTooltip();
  }
  attachTooltip() {
    if (this.hasTooltip()) {
      return;
    }
    const $tooltip = this.maybeBuildTooltip();
    this.$opener.append($tooltip);
  }
  detachTooltip() {
    var _this$$tooltip;
    if (!((_this$$tooltip = this.$tooltip) !== null && _this$$tooltip !== void 0 && _this$$tooltip.length)) {
      return;
    }
    this.$tooltip.detach();
  }
  showTooltip() {
    var _this$$tooltip2;
    if (!((_this$$tooltip2 = this.$tooltip) !== null && _this$$tooltip2 !== void 0 && _this$$tooltip2.length)) {
      return;
    }
    return this.$tooltip.show().stop().animate({
      opacity: 1
    }, {
      duration: 200
    });
  }
  hideTooltip() {
    var _this$$tooltip3;
    if (!((_this$$tooltip3 = this.$tooltip) !== null && _this$$tooltip3 !== void 0 && _this$$tooltip3.length)) {
      return;
    }
    return this.$tooltip.stop().animate({
      opacity: 0
    }, {
      duration: 200,
      complete: () => {
        this.$tooltip.hide();
        this.detachTooltip();
      }
    });
  }
  maybeBuildTooltip() {
    var _this$$tooltip4;
    if (!((_this$$tooltip4 = this.$tooltip) !== null && _this$$tooltip4 !== void 0 && _this$$tooltip4.length)) {
      const content = this.$opener.data('tip');
      this.$tooltip = external_jQuery_default()('<span/>', {
        html: content,
        class: 'tooltip'
      });
    }
    return this.$tooltip;
  }
}
;// ./assets/js/src/modules/yith-wcaf-modal.js


/**
 * Internal dependencies
 */


class YITH_WCAF_Modal {
  constructor($opener, args) {
    // modal opener
    _defineProperty(this, "$opener", null);
    // target of the open event
    _defineProperty(this, "$target", null);
    // modal object
    _defineProperty(this, "$modal", null);
    // modal content
    _defineProperty(this, "$content", null);
    if (!($opener !== null && $opener !== void 0 && $opener.length)) {
      return;
    }
    this.$opener = $opener;
    this.args = external_jQuery_default().extend({
      title: false,
      shouldOpen: false,
      template: false,
      onOpen: false,
      onClose: false
    }, args || {});
    this.init();
  }
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

  // events handling

  shouldOpen() {
    var _this$args;
    if (typeof ((_this$args = this.args) === null || _this$args === void 0 ? void 0 : _this$args.shouldOpen) === 'function') {
      return this.args.shouldOpen.call(this);
    }
    return true;
  }
  onOpen() {
    var _this$args2, _this$$content;
    let template = ((_this$args2 = this.args) === null || _this$args2 === void 0 ? void 0 : _this$args2.template) || '',
      $content = null;
    if (typeof template === 'function') {
      template = template.call(this);
    }
    if (!((_this$$content = this.$content) !== null && _this$$content !== void 0 && _this$$content.length)) {
      var _template;
      if (!template) {
        return;
      } else if (typeof template === 'string') {
        $content = external_jQuery_default()(template).detach();
      } else if (typeof template === 'function') {
        $content = template().detach();
      } else if ((_template = template) !== null && _template !== void 0 && _template.lenght) {
        $content = template.detach();
      }
      this.$content = $content;
    }
    this.maybeOpenModal(this.$content);
  }
  onClose() {
    this.maybeCloseModal();
  }
  maybeBuildModal() {
    var _this$$modal, _this$args3;
    if ((_this$$modal = this.$modal) !== null && _this$$modal !== void 0 && _this$$modal.length) {
      return this.$modal;
    }
    const $modal = external_jQuery_default()('<div/>', {
        class: 'yith-wcaf-modal'
      }),
      $contentContainer = external_jQuery_default()('<div/>', {
        class: 'content pretty-scrollbar'
      }),
      $closeButton = external_jQuery_default()('<a/>', {
        class: 'close-button main-close-button',
        html: '&times;',
        role: 'button',
        href: '#'
      });
    this.$modal = $modal;
    $modal.append($contentContainer).append($closeButton);
    if ((_this$args3 = this.args) !== null && _this$args3 !== void 0 && _this$args3.title) {
      const $title = external_jQuery_default()('<div/>', {
        class: 'title',
        html: `<h3>${this.args.title}</h3>`
      });
      $modal.prepend($title);
    }
    $modal.on('click', '.close-button', ev => {
      ev.preventDefault();
      this.onClose();
    });
    this.$target.closest('.yith-wcaf-section').append($modal);
    return this.$modal;
  }
  maybeDestroyModal() {
    var _this$$modal2;
    if (!((_this$$modal2 = this.$modal) !== null && _this$$modal2 !== void 0 && _this$$modal2.length)) {
      return;
    }
    this.$modal.remove();
  }
  maybeOpenModal(content) {
    var _this$$modal3;
    if (!((_this$$modal3 = this.$modal) !== null && _this$$modal3 !== void 0 && _this$$modal3.length)) {
      this.maybeBuildModal();
    }
    if (this.$modal.hasClass('open')) {
      return;
    }
    this.$modal.find('.content').append(content).end().fadeIn(() => {
      var _this$args4;
      this.$modal.addClass('open');
      if (typeof ((_this$args4 = this.args) === null || _this$args4 === void 0 ? void 0 : _this$args4.onOpen) === 'function') {
        var _this$args5;
        (_this$args5 = this.args) === null || _this$args5 === void 0 ? void 0 : _this$args5.onOpen.call(this);
      }
    });
    $body.addClass('yith-wcaf-open-modal');
  }
  maybeCloseModal() {
    var _this$$modal4;
    if (!((_this$$modal4 = this.$modal) !== null && _this$$modal4 !== void 0 && _this$$modal4.length)) {
      this.maybeBuildModal();
    }
    if (!this.$modal.hasClass('open')) {
      return;
    }
    this.$modal.fadeOut(() => {
      var _this$args6;
      this.$modal.removeClass('open');
      $body.removeClass('yith-wcaf-open-modal');
      if (typeof ((_this$args6 = this.args) === null || _this$args6 === void 0 ? void 0 : _this$args6.onClose) === 'function') {
        var _this$args7;
        (_this$args7 = this.args) === null || _this$args7 === void 0 ? void 0 : _this$args7.onClose.call(this);
      }
    });
  }
}
;// ./assets/js/src/modules/yith-wcaf-ajax-submit-modal.js


/**
 * Internal dependencies
 */





class YITH_WCAF_Ajax_Submit_Modal extends YITH_WCAF_Modal {
  constructor($opener, args) {
    args = external_jQuery_default().extend({
      beforeSubmit: false,
      afterSubmit: false,
      onFormInit: false,
      redirectTo: window.location
    }, args || {});
    super($opener, args);
    // form to submit, if any
    _defineProperty(this, "$form", null);
  }

  // event handler

  maybeOpenModal(content) {
    // open modal.
    super.maybeOpenModal(content);

    // init fields once modal is completely shown
    this.$modal.promise().done(this.maybeInitForm.bind(this));
  }
  maybeInitForm() {
    var _this$$form, _this$args;
    // skip if we already processed form
    if ((_this$$form = this.$form) !== null && _this$$form !== void 0 && _this$$form.length) {
      return;
    }

    // init form, if any inside the modal
    const $form = this.$modal.find('form');
    if (!$form.length) {
      return;
    }

    // init fields
    initFields($form);

    // init form submit handling
    $form.on('submit', this.maybeSubmitForm.bind(this));
    this.$form = $form;
    if (typeof ((_this$args = this.args) === null || _this$args === void 0 ? void 0 : _this$args.onFormInit) === 'function') {
      var _this$args2;
      (_this$args2 = this.args) === null || _this$args2 === void 0 ? void 0 : _this$args2.onFormInit.call(this);
    }
  }
  async maybeSubmitForm(ev) {
    var _this$args3;
    ev.preventDefault();
    if (typeof ((_this$args3 = this.args) === null || _this$args3 === void 0 ? void 0 : _this$args3.beforeSubmit) === 'function') {
      this.args.beforeSubmit.call(this);
    }
    try {
      const answer = await this.ajaxSubmit();
      this.afterSubmit(answer);
    } catch (err) {
      this.reportError(err);
      return;
    }
    setTimeout(() => {
      // close modal
      this.maybeCloseModal();

      // redirect after success
      window.location = this.args.redirectTo;
    }, 3000);
  }
  afterSubmit(answer) {
    var _this$args4;
    if (typeof ((_this$args4 = this.args) === null || _this$args4 === void 0 ? void 0 : _this$args4.afterSubmit) === 'function') {
      this.args.afterSubmit.call(this, answer);
    }
  }

  // error reporting

  reportError(error) {
    const $errors = this.$form.find('.errors');
    if (!$errors.length) {
      this.$form.prepend(external_jQuery_default()('<p/>', {
        class: 'errors woocommerce-error',
        text: error
      }));
    } else {
      $errors.text(error);
    }
    this.$form.closest('.content').animate({
      scrollTop: 0
    });
  }
  hideErrors() {
    this.$form.find('.errors').remove();
  }

  // ajax handling

  ajaxSubmit() {
    const action = this.$form.data('action'),
      nonce = this.$form.data('security');
    return new Promise((resolve, fail) => {
      if (!action || !nonce) {
        fail('');
      }
      this.hideErrors();
      ajax.post.call(this.$modal, action, nonce, new FormData(this.$form.get(0)), {
        processData: false,
        contentType: false
      }).done(data => {
        if (data !== null && data !== void 0 && data.success) {
          resolve(data === null || data === void 0 ? void 0 : data.data);
        } else {
          var _data$data;
          fail(data === null || data === void 0 ? void 0 : (_data$data = data.data) === null || _data$data === void 0 ? void 0 : _data$data.message);
        }
      }).fail(() => fail(''));
    });
  }
}
;// ./assets/js/src/modules/yith-wcaf-withdraw-modal.js


/**
 * Internal dependencies
 */


class YITH_WCAF_Withdraw_Modal extends YITH_WCAF_Ajax_Submit_Modal {
  constructor($opener, args) {
    args = {
      template: '#withdraw_modal',
      title: labels === null || labels === void 0 ? void 0 : labels.withdraw_modal_title,
      redirectTo: getDashboardUrl('payments'),
      ...(args || {})
    };
    super($opener, args);
  }
  maybeInitForm() {
    super.maybeInitForm();
    this.initAmountNotesHighlight();
  }
  initAmountNotesHighlight() {
    const minWithdrawNote = this.$form.find('.min-withdraw-note'),
      maxWithdrawNote = this.$form.find('.max-withdraw-note');
    this.$form.on('change keyup', ev => {
      const $field = external_jQuery_default()(ev.target);
      if (!$field.is('.amount')) {
        return;
      }
      const val = parseFloat($field.val()),
        min = parseFloat($field.attr('min')),
        max = parseFloat($field.attr('max'));
      if (val < min) {
        minWithdrawNote.addClass('error');
        maxWithdrawNote.removeClass('error');
      } else if (val > max) {
        minWithdrawNote.removeClass('error');
        maxWithdrawNote.addClass('error');
      } else {
        minWithdrawNote.removeClass('error');
        maxWithdrawNote.removeClass('error');
      }
    });
  }
  afterSubmit(answer) {
    super.afterSubmit(answer);
    if (answer !== null && answer !== void 0 && answer.template) {
      this.afterSuccess(answer.template);
    } else {
      this.maybeCloseModal();
    }
  }
  afterSuccess(template) {
    const $content = this.$modal.find('.content'),
      $title = this.$modal.find('.title');
    $title.fadeOut();
    $content.fadeOut(() => {
      $content.empty().html(template).fadeIn();
    });
  }
}
;// ./assets/js/src/shortcodes.js


/* global yith_wcaf */

/**
 * Internal dependencies
 */









class YITH_WCAF_Shortcodes {
  constructor($container) {
    _defineProperty(this, "$container", void 0);
    if (!$container.length) {
      return;
    }

    // set container object.
    this.$container = $container;

    // start up.
    this.init();
  }
  init() {
    // init sequence.
    this.initTooltips();
    this.initCopyButtons();
    this.initLinkGenerator();
    this.initSetReferrer();
    this.initWithdrawModal();
    this.initSettings();

    // set cookie via ajax.
    this.setCookie();
  }
  initTooltips() {
    const $tooltips = external_jQuery_default()('[data-tip]', this.$container);
    if (!$tooltips.length) {
      return;
    }
    $tooltips.each(function () {
      new YITH_WCAF_Tooltip(external_jQuery_default()(this));
    });
  }
  initCopyButtons() {
    this.$container.find('.copy-trigger').each(function () {
      const $trigger = external_jQuery_default()(this),
        $target = $trigger.parent().find('.copy-target');
      if (!$target.length) {
        return;
      }
      new YITH_WCAF_Copy_Button($trigger, $target);
    });
  }
  initLinkGenerator() {
    const $linkGenerator = external_jQuery_default()('.link-generator', this.$container);
    if (!$linkGenerator.length) {
      return;
    }
    new YITH_WCAF_Link_Generator($linkGenerator);
  }
  initSetReferrer() {
    const $setReferrer = external_jQuery_default()('.set-referrer-wrapper', this.$container);
    if (!$setReferrer.length) {
      return;
    }
    new YITH_WCAF_Set_Referrer($setReferrer);
  }
  initWithdrawModal() {
    const $modalOpener = external_jQuery_default()('#withdraw_modal_opener', this.$container);
    if (!$modalOpener.length || $modalOpener.is('.disabled')) {
      return;
    }
    new YITH_WCAF_Withdraw_Modal($modalOpener);
  }
  initSettings() {
    const $container = this.$container;
    if (!$container.length) {
      return;
    }
    initFields($container);
  }
  setCookie() {
    if (!yith_wcaf.set_cookie_via_ajax || !yith_wcaf.referral_var) {
      return;
    }
    setCookie();
  }
}

// let's start the game.
external_jQuery_default()(() => {
  // init on document ready.
  new YITH_WCAF_Shortcodes(external_jQuery_default()('.yith-wcaf'));

  // init upon request.
  external_jQuery_default()(document).on('yith_wcaf_shortcodes_init', ev => {
    const $wrapper = external_jQuery_default()(ev.target);
    new YITH_WCAF_Shortcodes($wrapper);
  });
});
var __webpack_export_target__ = window;
for(var __webpack_i__ in __webpack_exports__) __webpack_export_target__[__webpack_i__] = __webpack_exports__[__webpack_i__];
if(__webpack_exports__.__esModule) Object.defineProperty(__webpack_export_target__, "__esModule", { value: true });
/******/ })()
;
//# sourceMappingURL=yith-wcaf-shortcodes.bundle.js.map