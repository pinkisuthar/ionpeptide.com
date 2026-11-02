/*!
 * Variation Swatches for WooCommerce
 *
 * Author: Emran Ahmed ( emran.bd.08@gmail.com )
 * Date: 8/20/2026, 7:27:43 PM
 * Released under the GPLv3 license.
 */
/******/ (function() { // webpackBootstrap
/******/ 	var __webpack_modules__ = ({

/***/ "./src/js/PluginHelper.js":
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

"use strict";
__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   PluginHelper: function() { return /* binding */ PluginHelper; }
/* harmony export */ });
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function _createForOfIteratorHelper(r, e) { var t = "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (!t) { if (Array.isArray(r) || (t = _unsupportedIterableToArray(r)) || e && r && "number" == typeof r.length) { t && (r = t); var _n = 0, F = function F() {}; return { s: F, n: function n() { return _n >= r.length ? { done: !0 } : { done: !1, value: r[_n++] }; }, e: function e(r) { throw r; }, f: F }; } throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); } var o, a = !0, u = !1; return { s: function s() { t = t.call(r); }, n: function n() { var r = t.next(); return a = r.done, r; }, e: function e(r) { u = !0, o = r; }, f: function f() { try { a || null == t["return"] || t["return"](); } finally { if (u) throw o; } } }; }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
function _classCallCheck(a, n) { if (!(a instanceof n)) throw new TypeError("Cannot call a class as a function"); }
function _defineProperties(e, r) { for (var t = 0; t < r.length; t++) { var o = r[t]; o.enumerable = o.enumerable || !1, o.configurable = !0, "value" in o && (o.writable = !0), Object.defineProperty(e, _toPropertyKey(o.key), o); } }
function _createClass(e, r, t) { return r && _defineProperties(e.prototype, r), t && _defineProperties(e, t), Object.defineProperty(e, "prototype", { writable: !1 }), e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
/*global woo_variation_swatches_admin, wp, woocommerce_admin_meta_boxes*/

var PluginHelper = function ($) {
  var PluginHelper = /*#__PURE__*/function () {
    function PluginHelper() {
      _classCallCheck(this, PluginHelper);
    }
    return _createClass(PluginHelper, null, [{
      key: "GWPAdmin",
      value: function GWPAdmin() {
        if ($().gwp_live_feed) {
          $().gwp_live_feed();
        }
        if ($().gwp_deactivate_popup) {
          $().gwp_deactivate_popup('woo-variation-swatches');
        }
      }
    }, {
      key: "PaginationAjax",
      value: function PaginationAjax($product_id, $attribute_id, $attribute_name, $offset, $selector) {
        var data = {
          offset: $offset,
          product_id: $product_id,
          attribute_id: $attribute_id,
          attribute_name: $attribute_name,
          _wpnonce: woo_variation_swatches_admin._wpnonce
        };
        $.ajax({
          global: false,
          url: woo_variation_swatches_admin.wc_ajax_url.toString().replace('%%endpoint%%', 'woo_variation_swatches_load_product_terms'),
          method: 'POST',
          data: data,
          beforeSend: function beforeSend(xhr, settings) {
            $selector.block({
              message: null,
              overlayCSS: {
                background: '#DDDDDD',
                opacity: 0.6
              }
            });
          }
        }).fail(function (jqXHR, textStatus) {
          console.error("not available on: ".concat($product_id, " ").concat($attribute_key, "."), textStatus);
        }).always(function () {
          $selector.unblock();
        }).done(function (termsMarkup) {
          if (termsMarkup) {
            $selector.html(termsMarkup);

            //   _.delay(() => {
            $(document.body).trigger('woo_variation_swatches_product_term_paging_done', $selector);
            //   }, 300)

            //$('#woocommerce-product-data').trigger('woocommerce_variations_loaded');
          }
        });
      }
    }, {
      key: "MetaboxToggle",
      value: function MetaboxToggle() {
        // Meta-Boxes - Open/close

        var $wrapper = $('#woo_variation_swatches_variation_product_options');
        $wrapper.on('click', '.wc-metabox > h4', function (event) {
          var box = $(this).parent('.wc-metabox');
          var content = $(this).next('.wc-metabox-content');

          // If the user clicks on some form input inside the h3, like a select list (for variations), the box should not be toggled
          if ($(event.target).filter(':input, option, .sort, select, label, .select2-selection__rendered').length) {
            return false;
          }
          if (box.hasClass('closed')) {
            box.removeClass('closed open').addClass('open');
            content.slideDown();
          } else {
            box.removeClass('closed open').addClass('closed');
            content.slideUp();
          }
        });
      }
    }, {
      key: "AttributeTypeSwitch",
      value: function AttributeTypeSwitch() {
        var $wrapper = $('#woo_variation_swatches_variation_product_options')
        // ATTRIBUTE TYPE
        .on('change', 'select.woo_variation_swatches_attribute_type_switch', function (event) {
          var value = $(this).val();
          if (['select'].includes(value)) {
            //    $(this).closest('.wc-metabox').find('.wc-metabox-content select.woo_variation_swatches_attribute_term_type_switch').val('').trigger('change');
          }
          if (['image', 'color', 'button'].includes(value)) {
            $(this).closest('.wc-metabox').find('.wc-metabox-content select.woo_variation_swatches_attribute_term_type_switch').val(value).trigger('change');
          }
        })
        // TERM TYPE
        .on('change', 'select.woo_variation_swatches_attribute_term_type_switch', function (event) {
          var attribute_type = $(this).closest('.woo-variation-swatches-attribute-options-wrapper').find('select.woo_variation_swatches_attribute_type_switch').val();
          if ($(this).val() !== attribute_type) {
            $(this).closest('.woo-variation-swatches-attribute-options-wrapper').find('select.woo_variation_swatches_attribute_type_switch').val('mixed').trigger('change');
          }
        });
      }
    }, {
      key: "SetAttributeTypePaging",
      value: function SetAttributeTypePaging(selector) {
        var attribute_type_val = $(selector).closest('.woo-variation-swatches-attribute-options-wrapper').find('select.woo_variation_swatches_attribute_type_switch').val();
        var new_mode = $(selector).find('select.woo_variation_swatches_attribute_term_type_switch').hasClass('new-mode');
        // Set based on attribute value
        if (['image', 'color', 'button'].includes(attribute_type_val)) {
          $(selector).find('select.woo_variation_swatches_attribute_term_type_switch.new-mode').val(attribute_type_val).trigger('change');
        }
        $(selector).find('select.woo_variation_swatches_attribute_term_type_switch.new-mode').each(function () {
          var value = $(this).val();
          if (!value) {
            //    $(this).trigger('change')
          }
        });
      }
    }, {
      key: "__LoadProductAttributes",
      value: function __LoadProductAttributes() {
        $('#woocommerce-product-data').on('woocommerce_variations_loaded', function (event) {
          var $wrapper = $('#woo_variation_swatches_variation_product_options');
          var product_id = $wrapper.data('product_id');
          $.ajax({
            global: false,
            url: woo_variation_swatches_admin.wc_ajax_url.toString().replace('%%endpoint%%', 'woo_variation_swatches_load_product_options'),
            method: 'POST',
            data: {
              product_id: product_id,
              _wpnonce: woo_variation_swatches_admin._wpnonce
            },
            beforeSend: function beforeSend(xhr, settings) {
              $('#woo_variation_swatches_variation_product_options_inner').block({
                message: null,
                overlayCSS: {
                  background: '#DDDDDD',
                  opacity: 0.6
                }
              });
            }
          }).fail(function (jqXHR, textStatus) {
            console.error("not load option: ".concat(product_id, "."), textStatus);
          }).always(function () {
            $('#woo_variation_swatches_variation_product_options_inner').unblock();
          }).done(function (contents) {
            $(document.body).trigger('woo_variation_swatches_variation_product_options_loaded', product_id);
          });
        });
      }
    }, {
      key: "SaveProductAttributes",
      value: function SaveProductAttributes() {
        var changed = false;
        var $wrapper = $('#woo_variation_swatches_variation_product_options');
        $wrapper.on('change input color-changed', ':input:not(.wvs-skip-field)', function () {
          if (!changed) {
            window.onbeforeunload = function () {
              return woo_variation_swatches_admin.nav_warning;
            };
            changed = true;
          }
        }).on('click', '.woo_variation_swatches_save_product_attributes, .woo_variation_swatches_reset_product_attributes', function () {
          window.onbeforeunload = '';
        }).on('click', '.woo_variation_swatches_save_product_attributes', function (event) {
          event.preventDefault();
          var data = $wrapper.find(':input:not(.wvs-skip-field)').serializeJSON({
            disableColonTypes: true
          });
          var key = Object.keys(data) ? Object.keys(data).shift() : 'woo_variation_swatches_product_options';
          var product_id = $wrapper.data('product_id');
          var timeOut;
          $.ajax({
            global: false,
            url: woo_variation_swatches_admin.wc_ajax_url.toString().replace('%%endpoint%%', 'woo_variation_swatches_save_product_options'),
            method: 'POST',
            data: {
              data: data[key],
              product_id: product_id,
              _wpnonce: woo_variation_swatches_admin._wpnonce
            },
            beforeSend: function beforeSend(xhr, settings) {
              clearTimeout(timeOut);
              $('#woo_variation_swatches_variation_product_options_inner').block({
                message: null,
                overlayCSS: {
                  background: '#DDDDDD',
                  opacity: 0.6
                }
              });
            }
          }).fail(function (jqXHR, textStatus) {
            console.error("not saved on: ".concat(product_id, "."), textStatus);
          }).always(function () {
            $('#woo_variation_swatches_variation_product_options_inner').unblock();
          }).done(function (contents) {
            $('#saved-message').show();
            timeOut = setTimeout(function () {
              $('#saved-message').hide(600, function () {
                $('#individual-swatches-info').removeClass('swatches-info-hide');
              });
            }, 5000);
            $(document.body).trigger('woo_variation_swatches_variation_product_options_saved', product_id);
          });
        }).on('click', '.woo_variation_swatches_reset_product_attributes', function (event) {
          event.preventDefault();
          if (confirm(woo_variation_swatches_admin.reset_notice)) {
            var product_id = $(this).data('product_id');
            $.ajax({
              global: false,
              url: woo_variation_swatches_admin.wc_ajax_url.toString().replace('%%endpoint%%', 'woo_variation_swatches_reset_product_options'),
              method: 'POST',
              data: {
                product_id: product_id,
                _wpnonce: woo_variation_swatches_admin._wpnonce
              },
              beforeSend: function beforeSend(xhr, settings) {
                $('#woo_variation_swatches_variation_product_options_inner').block({
                  message: null,
                  overlayCSS: {
                    background: '#DDDDDD',
                    opacity: 0.6
                  }
                });
              }
            }).fail(function (jqXHR, textStatus) {
              console.error("not reset on: ".concat(product_id, "."), textStatus);
            }).always(function () {
              $('#woo_variation_swatches_variation_product_options_inner').unblock();
            }).done(function (contents) {
              var $html = $(contents).find('#woo_variation_swatches_variation_product_options_inner').html();
              $('#woo_variation_swatches_variation_product_options_inner').html($html);

              // $('#woocommerce-product-data').trigger('woocommerce_variations_loaded')
              $(document.body).trigger('woo_variation_swatches_variation_product_options_reset', product_id);
            });
          }
        });
      }
    }, {
      key: "ResetProductAttributes",
      value: function ResetProductAttributes() {
        var $wrapper = $('#woo_variation_swatches_variation_product_options');
      }
    }, {
      key: "Pagination",
      value: function Pagination() {
        var _this = this;
        var changed = false;
        var $wrapper = $('#woo_variation_swatches_variation_product_options');
        $wrapper.on('change input color-changed', ':input:not(.wvs-skip-field)', function (event) {
          if (!changed) {
            changed = true;
          }
        }).on('click', '.woo_variation_swatches_reset_product_attributes', function (event) {
          event.preventDefault();
          changed = false;
        }).on('click', '.woo_variation_swatches_save_product_attributes', function (event) {
          event.preventDefault();
          changed = false;
        }).on('click', '.first-page:not(.disabled), .prev-page:not(.disabled), .last-page:not(.disabled), .next-page:not(.disabled)', function (event) {
          if (changed) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            alert(woo_variation_swatches_admin.nav_warning);
          }
        }).on('click', '.first-page.disabled, .prev-page.disabled, .last-page.disabled, .next-page.disabled', function (event) {
          event.preventDefault();
        }).on('click', '.first-page:not(.disabled)', function (event) {
          event.preventDefault();
          var $selector = $(event.currentTarget).closest('.product-term-label-settings').find('.product-term-label-settings-contents');
          var $this = $(event.currentTarget).closest('.product-term-label-settings-pagination');
          var $product_id = $selector.data('product_id');
          var $attribute_id = $selector.data('attribute_id');
          var $attribute_name = $selector.data('attribute_name');
          var $offset = 0;
          _this.PaginationAjax($product_id, $attribute_id, $attribute_name, $offset, $selector);
          $this.find('.next-page, .last-page').removeClass('disabled');
          $this.find('.current-page').text(1);
          $selector.data('current', 1);
          $this.find('.first-page, .prev-page').addClass('disabled');
        }).on('click', '.prev-page:not(.disabled)', function (event) {
          event.preventDefault();
          var $selector = $(event.currentTarget).closest('.product-term-label-settings').find('.product-term-label-settings-contents');
          var $this = $(event.currentTarget).closest('.product-term-label-settings-pagination');
          $selector.block({
            message: null,
            overlayCSS: {
              background: '#DDDDDD',
              opacity: 0.6
            }
          });
          var $product_id = $selector.data('product_id');
          var $pages = $selector.data('pages');
          var $attribute_id = $selector.data('attribute_id');
          var $attribute_name = $selector.data('attribute_name');
          var $current = $selector.data('current'); // 1
          var $limit = $selector.data('limit'); // 3
          var $total = $selector.data('total');
          var $offset = ($current - 1) * $limit - $limit;
          var $prev = $current - 1;
          _this.PaginationAjax($product_id, $attribute_id, $attribute_name, $offset, $selector);
          $this.find('.next-page, .last-page').removeClass('disabled');
          $this.find('.current-page').text($prev);
          $selector.data('current', $prev);
          if ($offset === 0) {
            $this.find('.first-page, .prev-page').addClass('disabled');
          }
        }).on('click', '.next-page:not(.disabled)', function (event) {
          event.preventDefault();
          var $selector = $(event.currentTarget).closest('.product-term-label-settings').find('.product-term-label-settings-contents');
          var $this = $(event.currentTarget).closest('.product-term-label-settings-pagination');
          $selector.block({
            message: null,
            overlayCSS: {
              background: '#DDDDDD',
              opacity: 0.6
            }
          });
          var $product_id = $selector.data('product_id');
          var $pages = $selector.data('pages');
          var $attribute_id = $selector.data('attribute_id');
          var $attribute_name = $selector.data('attribute_name');
          var $current = $selector.data('current'); // 1
          var $limit = $selector.data('limit'); // 3
          var $total = $selector.data('total');
          var $offset = $current * $limit;
          var $next = $current + 1;
          _this.PaginationAjax($product_id, $attribute_id, $attribute_name, $offset, $selector);
          $this.find('.first-page, .prev-page').removeClass('disabled');
          $this.find('.current-page').text($next);
          $selector.data('current', $next);
          if ($pages === $next) {
            $this.find('.next-page, .last-page').addClass('disabled');
          }
        }).on('click', '.last-page:not(.disabled)', function (event) {
          event.preventDefault();
          var $selector = $(event.currentTarget).closest('.product-term-label-settings').find('.product-term-label-settings-contents');
          var $this = $(event.currentTarget).closest('.product-term-label-settings-pagination');
          $selector.block({
            message: null,
            overlayCSS: {
              background: '#DDDDDD',
              opacity: 0.6
            }
          });
          var $product_id = $selector.data('product_id');
          var $pages = $selector.data('pages');
          var $attribute_id = $selector.data('attribute_id');
          var $attribute_name = $selector.data('attribute_name');
          var $current = $selector.data('current'); // 1
          var $limit = $selector.data('limit'); // 3
          var $offset = $pages * $limit - $limit;
          _this.PaginationAjax($product_id, $attribute_id, $attribute_name, $offset, $selector);
          $this.find('.first-page, .prev-page').removeClass('disabled');
          $this.find('.current-page').text($pages);
          $selector.data('current', $pages);
          $this.find('.next-page, .last-page').addClass('disabled');
        });
      }
    }, {
      key: "ResetAfterTermCreate",
      value: function ResetAfterTermCreate() {
        $(document.body).on('woo_variation_swatches_admin_term_meta_added', this.ClearImagePicker);
        $(document.body).on('woo_variation_swatches_admin_term_meta_added', this.ClearColorPicker);
        $(document).ajaxComplete(function (event, request, settings) {
          try {
            var data = Object.fromEntries(new URLSearchParams(settings.data));
            if ('add-tag' === data.action && '' === $('#tag-name').val()) {
              _.delay(function () {
                $(document.body).trigger('woo_variation_swatches_admin_term_meta_added', data);
              }, 300);
            }
          } catch (err) {}
        });
      }
    }, {
      key: "ImageUploader",
      value: function ImageUploader() {
        $(document.body).off('click', 'button.wvs_upload_image_button');
        $(document.body).on('click', 'button.wvs_upload_image_button', this.AddImage);
        $(document.body).on('click', 'button.wvs_remove_image_button', this.RemoveImage);
        // $(document.body).on('woo_variation_swatches_admin_term_meta_added', this.ResetTagForm);
      }
    }, {
      key: "AddImage",
      value: function AddImage(event) {
        var _this2 = this;
        event.preventDefault();
        event.stopPropagation();
        var file_frame;
        if (typeof wp !== 'undefined' && wp.media && wp.media.editor) {
          // If the media frame already exists, reopen it.
          if (file_frame) {
            file_frame.open();
            return;
          }

          // Create the media frame.
          file_frame = wp.media.frames.select_image = wp.media({
            title: woo_variation_swatches_admin.media_title,
            button: {
              text: woo_variation_swatches_admin.button_title
            },
            multiple: false
          });

          // When an image is selected, run a callback.
          file_frame.on('select', function () {
            var attachment = file_frame.state().get('selection').first().toJSON();
            if ($.trim(attachment.id) !== '') {
              var url = typeof attachment.sizes.thumbnail === 'undefined' ? attachment.sizes.full.url : attachment.sizes.thumbnail.url;
              $(_this2).prev().val(attachment.id);
              $(_this2).closest('.meta-image-field-wrapper').find('img').attr('src', url);
              $(_this2).next().show();
            }
            //file_frame.close();
          });

          // When open select selected
          file_frame.on('open', function () {
            // Grab our attachment selection and construct a JSON representation of the model.
            var selection = file_frame.state().get('selection');
            var current = $(_this2).prev().val();
            var attachment = wp.media.attachment(current);
            attachment.fetch();
            selection.add(attachment ? [attachment] : []);
          });

          // Finally, open the modal.
          file_frame.open();
        }
      }
    }, {
      key: "RemoveImage",
      value: function RemoveImage(event) {
        event.preventDefault();
        event.stopPropagation();
        var placeholder = $(this).closest('.meta-image-field-wrapper').find('img').data('placeholder');
        $(this).closest('.meta-image-field-wrapper').find('img').attr('src', placeholder);
        $(this).prev().prev().val('');
        $(this).hide();
        return false;
      }
    }, {
      key: "ClearImagePicker",
      value: function ClearImagePicker() {
        $('#addtag').find('.wvs_remove_image_button').trigger('click');
      }
    }, {
      key: "InitTooltip",
      value: function InitTooltip() {
        $(document.body).trigger('init_tooltips');
      }
    }, {
      key: "SelectWoo",
      value: function SelectWoo() {
        try {
          $(document.body).trigger('wc-enhanced-select-init');
        } catch (err) {
          // If failed (conflict?) log the error but don't stop other scripts breaking.
          window.console.log(err);
        }
      }
    }, {
      key: "ColorSuggestion",
      value: function ColorSuggestion() {
        if ('no' === woo_variation_swatches_admin.is_color_api_enabled) {
          return;
        }
        var debounce = function debounce(fn) {
          var wait = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : 300;
          var timer;
          return function () {
            var _this3 = this;
            for (var _len = arguments.length, args = new Array(_len), _key = 0; _key < _len; _key++) {
              args[_key] = arguments[_key];
            }
            clearTimeout(timer);
            timer = setTimeout(function () {
              return fn.apply(_this3, args);
            }, wait);
          };
        };
        var is_type_color = $('#woo_variation_swatches_taxonomy_type').val() === 'color';

        // Skip If type is not "color"
        if (!is_type_color) {
          return;
        }
        var $title = $('#wvs-color-suggestion-title');
        var $spinner = $('#wvs-color-search-spinner');
        var $suggestions = $('#wvs-color-suggestions');
        var $suggestion_list = $('#wvs-color-suggestion-list');
        var $selector = $('#tag-name, #name', '.term-name-wrap');
        var $edit_selector = $('#name', '.term-name-wrap');
        $('#tag-name, #name', '.term-name-wrap').after($suggestions);
        $('#tag-name, #name', '.term-name-wrap').after($spinner);
        var controller = null;
        var getColors = /*#__PURE__*/function () {
          var _ref = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee(value) {
            var _controller;
            var args, _yield$wp$apiFetch, success, color, message, markup, _iterator, _step, col, _controller2, _message, _t;
            return _regenerator().w(function (_context) {
              while (1) switch (_context.p = _context.n) {
                case 0:
                  (_controller = controller) === null || _controller === void 0 || _controller.abort();
                  controller = new AbortController();
                  args = {
                    name: value
                  };
                  _context.p = 1;
                  $title.text('');
                  $spinner.addClass('is-active');
                  _context.n = 2;
                  return wp.apiFetch({
                    path: wp.url.addQueryArgs("/woo-variation-swatches/v1/get-color", args),
                    signal: controller.signal
                  });
                case 2:
                  _yield$wp$apiFetch = _context.v;
                  success = _yield$wp$apiFetch.success;
                  color = _yield$wp$apiFetch.color;
                  message = _yield$wp$apiFetch.message;
                  if (success) {
                    markup = [];
                    $suggestion_list.find('a').remove();
                    markup.push("<a style=\"--palette-color: ".concat(color.hex, ";\" data-color=\"").concat(color.hex, "\" title=\"").concat(color.hex, "\" href=\"#product_attribute_color\"></a>"));
                    _iterator = _createForOfIteratorHelper(color.palette);
                    try {
                      for (_iterator.s(); !(_step = _iterator.n()).done;) {
                        col = _step.value;
                        markup.push("<a style=\"--palette-color: ".concat(col, ";\" data-color=\"").concat(col, "\" title=\"").concat(col, "\" href=\"#product_attribute_color\"></a>"));
                      }
                    } catch (err) {
                      _iterator.e(err);
                    } finally {
                      _iterator.f();
                    }
                    $suggestion_list.append(markup.join(''));
                  } else {
                    $suggestion_list.find('a').remove();
                    $title.text(message);
                  }
                  _context.n = 4;
                  break;
                case 3:
                  _context.p = 3;
                  _t = _context.v;
                  _message = _t.message;
                  if ((_controller2 = controller) !== null && _controller2 !== void 0 && _controller2.signal.aborted) {

                    // User start typing again.
                  } else {
                    $suggestion_list.find('a').remove();
                    $title.text(_message);
                  }
                case 4:
                  _context.p = 4;
                  $spinner.removeClass('is-active');
                  return _context.f(4);
                case 5:
                  return _context.a(2);
              }
            }, _callee, null, [[1, 3, 4, 5]]);
          }));
          return function getColors(_x) {
            return _ref.apply(this, arguments);
          };
        }();

        // User started typing, Clear Text and show loading...
        $selector.on('input', function () {
          var _controller3;
          var value = $(this).val().trim();
          (_controller3 = controller) === null || _controller3 === void 0 || _controller3.abort();
          $title.text('');
          if (value.length > 0) {
            $spinner.addClass('is-active');
          } else {
            $spinner.removeClass('is-active');
          }
        });

        // User left input field blank.
        $selector.on('blur', function () {
          var value = $(this).val().trim();
          if (value.length === 0) {
            var _controller4;
            (_controller4 = controller) === null || _controller4 === void 0 || _controller4.abort();
            $spinner.removeClass('is-active');
            $suggestion_list.find('a').remove();
            $title.text('');
          }
        });
        $selector.on('input', debounce(function () {
          var value = this.value.trim();
          if (value.length > 0) {
            getColors(value);
          } else {
            $suggestion_list.find('a').remove();
          }
        }, 2000));

        // Auto Request On Edit Mode
        if ($edit_selector.length > 0) {
          var value = $edit_selector.val().trim();
          if (value.length > 0) {
            setTimeout(function () {
              $spinner.addClass('is-active');
              getColors(value);
            }, 500);
          }
        }

        // User chose a color.
        $suggestion_list.on('click', 'a[data-color]', function () {
          var color = $(this).data('color');
          var $picker = $('input.wvs-color-picker', '.product_attribute_color');
          $picker.wpColorPicker('color', color);
          $picker.trigger('color-changed');
        });
      }
    }, {
      key: "ColorPicker",
      value: function ColorPicker() {
        var selector = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : 'input.wvs-color-picker';
        try {
          $(document.body).on('woo_variation_swatches_color_picker_init', function (event) {
            $(selector).wpColorPicker({
              change: function change(event, ui) {
                $(selector).trigger('color-changed');
              },
              clear: function clear() {
                $(selector).trigger('color-changed');
              }
            });
          }).trigger('woo_variation_swatches_color_picker_init');
        } catch (err) {
          // If failed (conflict?) log the error but don't stop other scripts breaking.
          window.console.log(err);
        }
      }
    }, {
      key: "ClearColorPicker",
      value: function ClearColorPicker() {
        $('#addtag').find('.wp-picker-clear').trigger('click');
      }
    }, {
      key: "FieldDependency",
      value: function FieldDependency() {
        var selector = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : '[data-gwp_dependency]';
        try {
          $(document.body).on('init_form_field_dependency', function () {
            $(selector).GWPFormFieldDependency();
          }).trigger('init_form_field_dependency');
        } catch (err) {
          // If failed (conflict?) log the error but don't stop other scripts breaking.
          window.console.log(err);
        }
      }
    }, {
      key: "FieldDependencyTrigger",
      value: function FieldDependencyTrigger() {
        $(document.body).trigger('init_form_field_dependency');
      }
    }, {
      key: "savingDialog",
      value: function savingDialog($wrapper, $dialog, taxonomy) {
        var data = {};
        var term = '';

        // @TODO: We should use form data, because we have to pick array based data also :)

        $dialog.find("input, select").each(function () {
          var key = $(this).attr('name');
          var value = $(this).val();
          if (key) {
            if (key === 'tag_name') {
              term = value;
            } else {
              data[key] = value;
            }
            $(this).val('');
          }
        });
        if (term) {
          $('.product_attributes').block({
            message: null,
            overlayCSS: {
              background: '#FFFFFF',
              opacity: 0.6
            }
          });
          var ajax_data = _objectSpread(_objectSpread({}, data), {}, {
            action: 'woocommerce_add_new_attribute',
            taxonomy: taxonomy,
            term: term,
            security: woocommerce_admin_meta_boxes.add_attribute_nonce
          });
          $.post(woocommerce_admin_meta_boxes.ajax_url, ajax_data, function (response) {
            if (response.error) {
              // Error.
              window.alert(response.error);
            } else if (response.slug) {
              // Success.
              $wrapper.find('select.attribute_values').append('<option value="' + response.term_id + '" selected="selected">' + response.name + '</option>');
              $wrapper.find('select.attribute_values').change();
            }
            $('.product_attributes').unblock();
          });
        } else {
          $('.product_attributes').unblock();
        }
      }
    }, {
      key: "AttributeDialog",
      value: function AttributeDialog() {
        var self = this;
        $('.product_attributes').on('click', 'button.wvs_add_new_attribute', function (event) {
          event.preventDefault();
          var $wrapper = $(this).closest('.woocommerce_attribute');
          var attribute = $wrapper.data('taxonomy');
          var title = $(this).data('dialog_title');
          $('.wvs-attribute-dialog-for-' + attribute).dialog({
            title: '',
            dialogClass: 'wp-dialog wvs-attribute-dialog',
            classes: {
              'ui-dialog': 'wp-dialog wvs-attribute-dialog'
            },
            autoOpen: false,
            draggable: true,
            width: 'auto',
            modal: true,
            resizable: false,
            closeOnEscape: true,
            position: {
              my: 'center',
              at: 'center',
              of: window
            },
            open: function open() {
              // close dialog by clicking the overlay behind it
              $('.ui-widget-overlay').bind('click', function () {
                $('#attribute-dialog').dialog('close');
              });
            },
            create: function create() {
              // style fix for WordPress admin
              // $('.ui-dialog-titlebar-close').addClass('ui-button');
            }
          }).dialog('option', 'title', title).dialog('option', 'buttons', [{
            text: woo_variation_swatches_admin.dialog_save,
            click: function click() {
              self.savingDialog($wrapper, $(this), attribute);
              $(this).dialog('close').dialog('destroy');
            }
          }, {
            text: woo_variation_swatches_admin.dialog_cancel,
            click: function click() {
              $(this).dialog('close').dialog('destroy');
            }
          }]).dialog('open');
        });
      }
    }]);
  }();
  return PluginHelper;
}(jQuery);


/***/ }),

/***/ "./src/js/backend.js":
/***/ (function(__unused_webpack_module, __unused_webpack_exports, __webpack_require__) {

function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _interopRequireWildcard(e, t) { if ("function" == typeof WeakMap) var r = new WeakMap(), n = new WeakMap(); return (_interopRequireWildcard = function _interopRequireWildcard(e, t) { if (!t && e && e.__esModule) return e; var o, i, f = { __proto__: null, "default": e }; if (null === e || "object" != _typeof(e) && "function" != typeof e) return f; if (o = t ? n : r) { if (o.has(e)) return o.get(e); o.set(e, f); } for (var _t in e) "default" !== _t && {}.hasOwnProperty.call(e, _t) && ((i = (o = Object.defineProperty) && Object.getOwnPropertyDescriptor(e, _t)) && (i.get || i.set) ? o(f, _t, i) : f[_t] = e[_t]); return f; })(e, t); }
jQuery(function ($) {
  Promise.resolve().then(function () {
    return _interopRequireWildcard(__webpack_require__("./src/js/PluginHelper.js"));
  }).then(function (_ref) {
    var PluginHelper = _ref.PluginHelper;
    // PluginHelper.GWPAdmin();
    // TERM PAGE
    PluginHelper.ResetAfterTermCreate();
    PluginHelper.ColorSuggestion();
    PluginHelper.ColorPicker();
    PluginHelper.ImageUploader();
    PluginHelper.FieldDependency();

    // PRODUCT PAGE
    PluginHelper.Pagination();
    PluginHelper.MetaboxToggle();
    PluginHelper.AttributeTypeSwitch();
    PluginHelper.SaveProductAttributes();
    // PluginHelper.ResetProductAttributes();
    // PluginHelper.AttributeDialog();

    $(document.body).on('woo_variation_swatches_variation_product_options_reset woo_variation_swatches_product_term_paging_done', function (event, $selector) {
      PluginHelper.InitTooltip();
      PluginHelper.SelectWoo();
      PluginHelper.ColorPicker();
      PluginHelper.FieldDependencyTrigger();
      PluginHelper.SetAttributeTypePaging($selector);
    });
  });
}); // end of jquery main wrapper

/***/ }),

/***/ "./src/scss/backend.scss":
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

"use strict";
__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ }),

/***/ "./src/scss/frontend.scss":
/***/ (function(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

"use strict";
__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


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
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = __webpack_modules__;
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/chunk loaded */
/******/ 	!function() {
/******/ 		var deferred = [];
/******/ 		__webpack_require__.O = function(result, chunkIds, fn, priority) {
/******/ 			if(chunkIds) {
/******/ 				priority = priority || 0;
/******/ 				for(var i = deferred.length; i > 0 && deferred[i - 1][2] > priority; i--) deferred[i] = deferred[i - 1];
/******/ 				deferred[i] = [chunkIds, fn, priority];
/******/ 				return;
/******/ 			}
/******/ 			var notFulfilled = Infinity;
/******/ 			for (var i = 0; i < deferred.length; i++) {
/******/ 				var chunkIds = deferred[i][0];
/******/ 				var fn = deferred[i][1];
/******/ 				var priority = deferred[i][2];
/******/ 				var fulfilled = true;
/******/ 				for (var j = 0; j < chunkIds.length; j++) {
/******/ 					if ((priority & 1 === 0 || notFulfilled >= priority) && Object.keys(__webpack_require__.O).every(function(key) { return __webpack_require__.O[key](chunkIds[j]); })) {
/******/ 						chunkIds.splice(j--, 1);
/******/ 					} else {
/******/ 						fulfilled = false;
/******/ 						if(priority < notFulfilled) notFulfilled = priority;
/******/ 					}
/******/ 				}
/******/ 				if(fulfilled) {
/******/ 					deferred.splice(i--, 1)
/******/ 					var r = fn();
/******/ 					if (r !== undefined) result = r;
/******/ 				}
/******/ 			}
/******/ 			return result;
/******/ 		};
/******/ 	}();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	!function() {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = function(exports, definition) {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	}();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	!function() {
/******/ 		__webpack_require__.o = function(obj, prop) { return Object.prototype.hasOwnProperty.call(obj, prop); }
/******/ 	}();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	!function() {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = function(exports) {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	}();
/******/ 	
/******/ 	/* webpack/runtime/jsonp chunk loading */
/******/ 	!function() {
/******/ 		// no baseURI
/******/ 		
/******/ 		// object to store loaded and loading chunks
/******/ 		// undefined = chunk not loaded, null = chunk preloaded/prefetched
/******/ 		// [resolve, reject, Promise] = chunk loading, 0 = chunk loaded
/******/ 		var installedChunks = {
/******/ 			"/assets/js/admin": 0,
/******/ 			"assets/css/frontend": 0,
/******/ 			"assets/css/admin": 0
/******/ 		};
/******/ 		
/******/ 		// no chunk on demand loading
/******/ 		
/******/ 		// no prefetching
/******/ 		
/******/ 		// no preloaded
/******/ 		
/******/ 		// no HMR
/******/ 		
/******/ 		// no HMR manifest
/******/ 		
/******/ 		__webpack_require__.O.j = function(chunkId) { return installedChunks[chunkId] === 0; };
/******/ 		
/******/ 		// install a JSONP callback for chunk loading
/******/ 		var webpackJsonpCallback = function(parentChunkLoadingFunction, data) {
/******/ 			var chunkIds = data[0];
/******/ 			var moreModules = data[1];
/******/ 			var runtime = data[2];
/******/ 			// add "moreModules" to the modules object,
/******/ 			// then flag all "chunkIds" as loaded and fire callback
/******/ 			var moduleId, chunkId, i = 0;
/******/ 			if(chunkIds.some(function(id) { return installedChunks[id] !== 0; })) {
/******/ 				for(moduleId in moreModules) {
/******/ 					if(__webpack_require__.o(moreModules, moduleId)) {
/******/ 						__webpack_require__.m[moduleId] = moreModules[moduleId];
/******/ 					}
/******/ 				}
/******/ 				if(runtime) var result = runtime(__webpack_require__);
/******/ 			}
/******/ 			if(parentChunkLoadingFunction) parentChunkLoadingFunction(data);
/******/ 			for(;i < chunkIds.length; i++) {
/******/ 				chunkId = chunkIds[i];
/******/ 				if(__webpack_require__.o(installedChunks, chunkId) && installedChunks[chunkId]) {
/******/ 					installedChunks[chunkId][0]();
/******/ 				}
/******/ 				installedChunks[chunkId] = 0;
/******/ 			}
/******/ 			return __webpack_require__.O(result);
/******/ 		}
/******/ 		
/******/ 		var chunkLoadingGlobal = self["webpackChunkwoo_variation_swatches"] = self["webpackChunkwoo_variation_swatches"] || [];
/******/ 		chunkLoadingGlobal.forEach(webpackJsonpCallback.bind(null, 0));
/******/ 		chunkLoadingGlobal.push = webpackJsonpCallback.bind(null, chunkLoadingGlobal.push.bind(chunkLoadingGlobal));
/******/ 	}();
/******/ 	
/************************************************************************/
/******/ 	
/******/ 	// startup
/******/ 	// Load entry module and return exports
/******/ 	// This entry module depends on other loaded chunks and execution need to be delayed
/******/ 	__webpack_require__.O(undefined, ["assets/css/frontend","assets/css/admin"], function() { return __webpack_require__("./src/js/backend.js"); })
/******/ 	__webpack_require__.O(undefined, ["assets/css/frontend","assets/css/admin"], function() { return __webpack_require__("./src/scss/backend.scss"); })
/******/ 	var __webpack_exports__ = __webpack_require__.O(undefined, ["assets/css/frontend","assets/css/admin"], function() { return __webpack_require__("./src/scss/frontend.scss"); })
/******/ 	__webpack_exports__ = __webpack_require__.O(__webpack_exports__);
/******/ 	
/******/ })()
;