var BWFAN_Public;
(function ($) {
    "use strict";
    /* Serialize the form object */
    $.fn.bwfanac_serializeAndEncode = function () {
        return $.map(this.serializeArray(), function (val) {
                var field = $("input[name='" + val.name + "']");
                if (field.attr('type') == 'checkbox') {
                    if (field.prop("checked")) {
                        return [val.name, encodeURIComponent('1')].join('=');
                    } else {
                        return [val.name, encodeURIComponent('0')].join('=');
                    }
                } else {
                    return [val.name, encodeURIComponent(val.value)].join('=');
                }
            }
        ).join('&');
    };

    BWFAN_Public = {

        checkout_form: $('form.checkout'),
        last_edit_field: '',
        current_step: '',
        checkout_fields_data: {},
        last_posted_fields_data: {},
        capture_email_xhr: null,
        checkout_fields: [],
        is_block_checkout: false,

        /**
         * Translate a WC Blocks DOM id/name to the canonical billing_ / shipping_ key
         * that insert_abandoned_cart() expects.
         *   'billing-first-name' -> 'billing_first_name'
         *   'email'              -> 'billing_email'
         */
        bwfan_normalize_field_name: function (raw) {
            if (!raw || !BWFAN_Public.is_block_checkout) return raw;
            if (raw === 'email' || raw === 'contact_email' || raw === 'contact-email') return 'billing_email';
            if (raw.indexOf('billing-') === 0)  return 'billing_'  + raw.substring(8).replace(/-/g, '_');
            if (raw.indexOf('shipping-') === 0) return 'shipping_' + raw.substring(9).replace(/-/g, '_');
            if (raw.indexOf('marketing-consent') !== -1) return 'bwfan_user_consent';
            return raw;
        },

        init: function () {
            /** Block checkout shim: swap form scope + selectors when WC Checkout block is active. */
            this.is_block_checkout = !!(typeof bwfanParamspublic !== 'undefined' && parseInt(bwfanParamspublic._is_block_based_checkout)>0);
            if (this.is_block_checkout) {
                var $blockForm = $('div.wp-block-woocommerce-checkout, .wc-block-checkout');
                if ($blockForm.length) {
                    this.checkout_form = $blockForm;
                }
            }
            this.checkout_fields = [
                'billing_first_name',
                'billing_last_name',
                'billing_company',
                'billing_phone',
                'billing_country',
                'billing_address_1',
                'billing_address_2',
                'billing_city',
                'billing_state',
                'billing_postcode',
                'shipping_first_name',
                'shipping_last_name',
                'shipping_company',
                'shipping_country',
                'shipping_address_1',
                'shipping_address_2',
                'shipping_city',
                'shipping_state',
                'shipping_postcode',
                'shipping_phone',
                'ship_to_different_address',
                'shipping_same_as_billing',
                'billing_same_as_shipping',
                'payment_method',
                'bwfan_birthday_date_mm',
                'bwfan_birthday_date_dd',
                'bwfan_birthday_date_yy',
                'bwfan_user_consent',
            ];

            if (bwfanParamspublic.bwfan_custom_checkout_field != undefined && bwfanParamspublic.bwfan_custom_checkout_field != null) {
                this.checkout_fields = this.union_arrays(this.checkout_fields, bwfanParamspublic.bwfan_custom_checkout_field);
            }

            $.each(BWFAN_Public.checkout_fields, function (i, field_name) {
                BWFAN_Public.checkout_fields_data[field_name] = '';
            });

            this.checkout_form.find('input,select').on('change', function () {
                var id = $(this).attr('id');
                if ("undefined" !== id) {
                    BWFAN_Public.last_edit_field = id;
                }
            });


        },
        bwfan_get_cookie: function (cname) {
            var name = cname + "=";
            var ca = document.cookie.split(';');
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i].trim();
                if (c.indexOf(name) !== 0) {
                    continue;
                }
                var c_val = c.substring(name.length, c.length);
                try {
                    c_val = decodeURIComponent(c_val);
                } catch (e) {
                    console.log("Error occurred while decoding (" + c + "). Error is: " + e.message);
                }
                return c_val;
            }
            return "";
        },
        bwfan_check_for_checkout_fields: function () {
            if ($('.wfacp_page').length === 0) {
                return;
            }
            var checkout_fields_data = bwfanParamspublic.bwfan_checkout_js_data;
            if ('no' === checkout_fields_data) {
                return;
            }
            var localstorage_data = window.localStorage.getItem('wfacp_form_values');
            if (null == localstorage_data) {
                localstorage_data = "";
            }
            var final_data = checkout_fields_data.fields;

            if ('' !== localstorage_data) {
                localstorage_data = JSON.parse(localstorage_data);
                for (var key in localstorage_data) {

                    if (localstorage_data.hasOwnProperty(key)) {
                        final_data[key] = localstorage_data[key];
                    }
                }

            }
            if (final_data.hasOwnProperty('shipping_method')) {
                delete final_data.shipping_method;
            }

            if (final_data.hasOwnProperty('ship_to_different_address')) {
                $('#ship-to-different-address-checkbox').prop('checked', true).trigger('change');
                delete final_data.shipping_method;
            }

            window.localStorage.setItem('wfacp_form_values', JSON.stringify(final_data));
            populate_fields_value();
        },
        urlParam: function (name) {
            var results = new RegExp('[\?&]' + name + '=([^&#]*)').exec(window.location.href);
            var value = 'undefined';

            if (null !== results) {
                value = results[1];
            }

            return value;
        },
        bwfan_capture_email: function () {
            var emailSel = BWFAN_Public.is_block_checkout ? '#billing_email, #email' : '#billing_email';
            $(document).on('focusout', emailSel, function () {
                    BWFAN_Public.bwfan_get_checkout_data();
                }
            );

            var billing_email = jQuery('#billing_email').val();
            if ((!billing_email || billing_email === '') && BWFAN_Public.is_block_checkout) {
                billing_email = jQuery('#email').val();
            }
            if (billing_email && billing_email !== '') {
                BWFAN_Public.bwfan_capture_data_on_page_load();
                BWFAN_Public.bwfan_process_email(billing_email);
            }
        },
        bwfan_get_checkout_data: function () {
            var email = $('#billing_email').val();
            if ((!email || email === '') && BWFAN_Public.is_block_checkout) {
                email = $('#email').val();
            }
            if (email && email !== '') {
                BWFAN_Public.bwfan_process_email(email);
            }
        },
        bwfan_capture_data_on_page_load: function () {
            $.each(BWFAN_Public.checkout_fields, function (i, field_name) {
                var $this = $('#' + field_name);
                /** Block checkout uses kebab-case ids (billing-first-name) and #email for billing_email. */
                if ((!$this.length || !$this.val()) && BWFAN_Public.is_block_checkout) {
                    if (field_name === 'billing_email') {
                        $this = $('#email');
                    } else if (field_name.indexOf('billing_') === 0) {
                        $this = $('#billing-' + field_name.substring(8).replace(/_/g, '-'));
                    } else if (field_name.indexOf('shipping_') === 0) {
                        $this = $('#shipping-' + field_name.substring(9).replace(/_/g, '-'));
                    } else if (field_name === 'bwfan_user_consent') {
                        $this = $('input[name*="marketing-consent"], input[id*="marketing-consent"]');
                    }
                }
                /** Checkbox .val() is the static value attr (always "1"); read checked state so unchecked captures as 0. */
                if ($this.attr('type') === 'checkbox') {
                    BWFAN_Public.checkout_fields_data[field_name] = $this.prop('checked') ? 1 : 0;
                } else {
                    BWFAN_Public.checkout_fields_data[field_name] = $this.val();
                }
            });
        },
        bwfanUpdateOnAddressCheckbox: function ( field_name, field_value ) {
            var checkout_formdata = BWFAN_Public.checkout_form.bwfanac_serializeAndEncode();
            checkout_formdata = bwfanac_deserialize_obj(checkout_formdata);
            checkout_formdata[field_name] = field_value;
            if (
               ! (( $('#ship-to-different-address-checkbox').length && $('#ship-to-different-address-checkbox').is(':checked') && !$('.wfacp_page').length ) ||
                $('#billing_same_as_shipping').length && $('#billing_same_as_shipping').is(':checked') ||
                $('#shipping_same_as_billing').length && $('#shipping_same_as_billing').is(':checked'))
            ) {
                for (var i = 0; i < BWFAN_Public.checkout_fields.length; i++) {
                    let field = BWFAN_Public.checkout_fields[i];
                    var fields = '';

                    if (checkout_formdata[field] && ($('#shipping_same_as_billing').length && !$('#shipping_same_as_billing').is(':checked')) || // For Aero Checkout - if shipping same as billing checkbox exists and is not checked
                        (!$('#ship-to-different-address-checkbox').is(':checked'))  ) {
                        fields = field.replace('billing_', 'shipping_');
                    }
                    if (checkout_formdata[field] && ( $('#billing_same_as_shipping').length && !$('#billing_same_as_shipping').is(':checked' ) )) {
                        fields = field.replace('shipping_', 'billing_');
                    }

                    checkout_formdata[fields] = checkout_formdata[field];
                }


            }
            BWFAN_Public.checkout_fields_data = checkout_formdata;
        },
        bwfan_captureCheckoutField: function () {
            var field_name = $(this).attr('name') || $(this).attr('id');
            if (!field_name) {
                return;
            }
            field_name = BWFAN_Public.bwfan_normalize_field_name(field_name);

            var field_value = $(this).val();

            /** for checking checkbox fields **/
            if ($(this).attr('type') == 'checkbox') {
                field_value = $(this).prop('checked') ? 1 : 0;
            }

            if (BWFAN_Public.checkout_fields.indexOf(field_name) === -1) {
                return;
            }

            if (BWFAN_Public.checkout_fields_data[field_name] === field_value) {
                return;
            }

            if ( ['ship_to_different_address', 'billing_same_as_shipping', 'shipping_same_as_billing'].includes( field_name ) ) {
                setTimeout(function () {
                    BWFAN_Public.bwfanUpdateOnAddressCheckbox(field_name, field_value);
                }, 200);
                return;
            }
            BWFAN_Public.checkout_fields_data[field_name] = field_value;

            // For WooCommerce - shipping same as billing
            if ($('#ship-to-different-address-checkbox').length) {
                BWFAN_Public.checkout_fields_data['ship_to_different_address'] = $('#ship-to-different-address-checkbox').is(':checked') ? 1 : 0;
            }

            // For WC Blocks checkout - "use shipping address as billing address" (inverted semantics)
            if (BWFAN_Public.is_block_checkout) {
                var $blockSame = $('.wc-block-checkout__use-address-for-billing input[type=checkbox]');
                if ($blockSame.length) {
                    BWFAN_Public.checkout_fields_data['ship_to_different_address'] = $blockSame.is(':checked') ? 0 : 1;
                }
            }

            // For AeroCheckout - billing same as shipping
            if ($('#billing_same_as_shipping').length) {
                BWFAN_Public.checkout_fields_data['billing_same_as_shipping'] = $('#billing_same_as_shipping').is(':checked') ? 1 : 0;
            }

            // For AeroCheckout - shipping same as billing
            if ($('#shipping_same_as_billing').length) {
                BWFAN_Public.checkout_fields_data['shipping_same_as_billing'] = $('#shipping_same_as_billing').is(':checked') ? 1 : 0;
            }

            var field = '';

            // For Aero Checkout - if billing same as shipping checkbox exists and is not checked
            if ($('#billing_same_as_shipping').length && !$('#billing_same_as_shipping').is(':checked')) {
                if (field_name.indexOf('shipping_') === 0) {
                    field = field_name.replace('shipping_', 'billing_');
                }
            }

            if (
                ($('#shipping_same_as_billing').length && !$('#shipping_same_as_billing').is(':checked')) || // For Aero Checkout - if shipping same as billing checkbox exists and is not checked
                (!$('#ship-to-different-address-checkbox').is(':checked')) // For WooCommerce native checkout
            ) {
                if (field_name.indexOf('billing_') === 0) {
                    field = field_name.replace('billing_', 'shipping_');
                }
            }

            // For Aero Checkout - if billing/shipping same as shipping/billing checkbox not exists
            if(!$('#shipping_same_as_billing').length && !$('#billing_same_as_shipping').length && $('.wfacp_page').length) {
                if (field_name.indexOf('billing_') === 0) {
                    field = field_name.replace('billing_', 'shipping_');
                }
                if (field_name.indexOf('shipping_') === 0) {
                    field = field_name.replace('shipping_', 'billing_');
                }
            }

            if (field !== '') {
                BWFAN_Public.checkout_fields_data[field] = field_value;
            }
        },
        bwfan_process_email: async function (email) {
            if ('undefined' === typeof email) {
                return;
            }
            if (('0' == bwfanParamspublic.bwfan_ab_enable || bwfanParamspublic.bwfan_ab_enable == "")) {
                return;
            }
            /**
             * Removed abort as it was making a loop when bonanza is enabled
             */
            if (null !== BWFAN_Public.capture_email_xhr) {
                return;
            }
            if ('' === email) {
                return;
            }
            if ($('#bwfan_gdpr_consent').length && 1 != $('#bwfan_gdpr_consent').val()) {
                return;
            }

            var aero_id = ($('#wfacp_aero_checkout_id').length > 0) ? $('#wfacp_aero_checkout_id').attr('content') : '';
            var step = '';
            if (aero_id) {
                step = (BWFAN_Public.current_step) ? BWFAN_Public.current_step : 'single_step';
            }

            var timezone = '';

            if (typeof Intl === "object" || typeof Intl.DateTimeFormat() === "object") {
                var resolved = Intl.DateTimeFormat().resolvedOptions();
                if (resolved.hasOwnProperty('timeZone')) {
                    timezone = resolved.timeZone;
                }
            }

            var utm_source = this.bwfan_get_cookie('utm_source');
            var utm_term = this.bwfan_get_cookie('utm_term');
            var utm_campaign = this.bwfan_get_cookie('utm_campaign');
            var utm_medium = this.bwfan_get_cookie('utm_medium');
            var utm_content = this.bwfan_get_cookie('utm_content');
            if ('' != utm_source || '' != utm_term || '' != utm_campaign || '' != utm_medium || '' != utm_content) {
                BWFAN_Public.checkout_fields_data['handle_utm_grabber'] = {
                    'utm_source': utm_source,
                    'utm_term': utm_term,
                    'utm_campaign': utm_campaign,
                    'utm_medium': utm_medium,
                    'utm_content': utm_content,
                };
            }

            var pushengage_token = await BWFAN_Public.getPushToken();

            /**
             * Block checkout: scrape all currently-rendered block inputs into checkout_fields_data
             * right before POST, so values land even if individual change/input events were missed
             * (React-controlled inputs sometimes swallow them).
             */
            if (BWFAN_Public.is_block_checkout) {
                $('#email, #contact_email, [name="contact_email"], [id^="billing-"], [id^="shipping-"], textarea[id*="order-notes"], input[name*="marketing-consent"], input[id*="marketing-consent"]').each(function () {
                    var raw = $(this).attr('name') || $(this).attr('id');
                    var key = BWFAN_Public.bwfan_normalize_field_name(raw);
                    if (!key) return;
                    var val = ($(this).attr('type') === 'checkbox') ? ($(this).prop('checked') ? 1 : 0) : $(this).val();
                    if (val !== undefined && val !== null) {
                        BWFAN_Public.checkout_fields_data[key] = val;
                    }
                });

                /** Capture currently-selected block payment method (covers missed change events). */
                var $payChecked = $('.wp-block-woocommerce-checkout-payment-block input[type=radio]:checked, .wc-block-components-payment-method input[type=radio]:checked').first();
                if ($payChecked.length && $payChecked.val()) {
                    BWFAN_Public.checkout_fields_data['payment_method'] = $payChecked.val();
                }

                /**
                 * When "use shipping as billing" is checked (block's default), the billing form is
                 * not rendered. Mirror shipping_* into billing_* so the cart row stores a usable
                 * billing address for recovery emails / merge tags.
                 */
                var $sameAddr = $('.wc-block-checkout__use-address-for-billing input[type=checkbox]');
                var useSameAddr = ($sameAddr.length === 0) || $sameAddr.is(':checked');
                if (useSameAddr) {
                    /**
                     * Always overwrite billing_* with shipping_* — if the user previously
                     * unchecked the box, typed billing values, then re-checked, the stale
                     * billing values must not win. billing_email is independent of the toggle.
                     */
                    var fields = BWFAN_Public.checkout_fields_data;
                    Object.keys(fields).forEach(function (k) {
                        if (k.indexOf('shipping_') === 0) {
                            var bk = 'billing_' + k.substring(9);
                            if (bk === 'billing_email') return;
                            fields[bk] = fields[k];
                        }
                    });
                }
            }

            /** Snapshot the values this POST carries — the change-listener dedupe compares against posted state, not the live cache. */
            BWFAN_Public.last_posted_fields_data = $.extend(true, {}, BWFAN_Public.checkout_fields_data);

            BWFAN_Public.capture_email_xhr = $.ajax({
                url: bwfanParamspublic.wc_ajax_url.toString().replace('%%endpoint%%', 'bwfan_insert_abandoned_cart'),
                type: 'POST',
                global: false,
                data: {
                    'email': email,
                    'action': 'bwfan_insert_abandoned_cart',
                    'checkout_fields_data': BWFAN_Public.checkout_fields_data,
                    'last_edit_field': BWFAN_Public.last_edit_field,
                    'current_step': step,
                    'current_page_id': bwfanParamspublic.current_page_id,
                    'timezone': timezone,
                    'aerocheckout_page_id': aero_id,
                    'pushengage_token': pushengage_token,
                    '_wpnonce': bwfanParamspublic.ajax_nonce
                },
                success: function (res) {
                    if (parseInt(res.id) > 0 && 0 === $('#bwfan_cart_id').length) {
                        var cartIdHtml = '<input type="hidden" id="bwfan_cart_id" name="bwfan_cart_id" value="' + res.id + '" />';
                        var $anchor = $('#billing_email_field');
                        if (!$anchor.length && BWFAN_Public.is_block_checkout) {
                            $anchor = $('div.wp-block-woocommerce-checkout, .wc-block-checkout').first();
                        }
                        if ($anchor.length) {
                            $anchor.after(cartIdHtml);
                        }
                        console.log('Cart ID: ' + res.id + ' captured.');
                    }
                }
            }).always(function () {
                BWFAN_Public.capture_email_xhr = null;
            });
        },

        union_arrays: function (x, y) {
            var obj = {};
            for (var i = x.length - 1; i >= 0; --i)
                obj[x[i]] = x[i];
            for (var i = y.length - 1; i >= 0; --i)
                obj[y[i]] = y[i];
            var res = [];
            for (var k in obj) {
                if (obj.hasOwnProperty(k))
                    res.push(obj[k]);
            }
            return res;
        },

        getPushToken: async function () {
            try {
                const subscriberID = await window.PushEngage.getSubscriberId();
                return subscriberID;
            } catch (error) {

            }
            return null;
        },
    };

    /**
     * Abandoned cart JS Function Initiate here
     */
    BWFAN_Public.abandoned_cart = function () {
        if ('0' != bwfanParamspublic.bwfan_ab_enable && bwfanParamspublic.bwfan_ab_enable != "") {
            BWFAN_Public.bwfan_capture_email();
        }
    };

    BWFAN_Public.get_selected_unsubscribe_lists = function () {
        const enabled = $('#bwfan-unsubscribe-lists').length > 0;
        if (!enabled) {
            return [];
        }

        const lists = [];
        $('.bwfan-unsubscribe-list-checkbox input').each(function () {
            if ($(this).attr('id') === 'bwfan-list-unsubscribe-all') {
                $(this).is(':checked') && lists.push('all');
                return true;
            }

            if (!$(this).is(':checked')) {
                lists.push(parseInt($(this).val()));
            }
        });
        return lists;
    };

    /**
     * Unsubscribe event to unsubscribe user on a link click
     */
    BWFAN_Public.unsubscribe_event = function () {
        $('#bwfan_unsubscribe').on('click', function (event) {
            event.preventDefault();
            var $this = $(this);
            if ($this.hasClass('bwfan_loading')) {
                return;
            }
            $this.addClass('bwfan_loading');

            var urlParams = new URLSearchParams(window.location.search);

            var recipient = $('#bwfan_unsubscribe_recipient');
            recipient = (0 === recipient.length) ? urlParams.get('subscriber_recipient') : recipient.html();

            var automation_id = $('#bwfan_automation_id');
            automation_id = (0 === automation_id.length) ? urlParams.get('automation_id') : automation_id.val();

            var broadcast_id = $('#bwfan_broadcast_id');
            broadcast_id = (0 === broadcast_id.length) ? urlParams.get('broadcast_id') : broadcast_id.val();

            var form_feed_id = $('#bwfan_form_feed_id');
            form_feed_id = (0 === form_feed_id.length) ? urlParams.get('form_feed_id') : form_feed_id.val();

            var sid = $('#bwfan_sid');
            sid = (0 === sid.length) ? urlParams.get('sid') : sid.val();

            var tid = $('#bwfan_tid');
            tid = (0 === tid.length) ? urlParams.get('tid') : tid.val();

            var oneClick = $('#bwfan_one_click').val();

            var uid = $('#bwfan_form_uid_id');
            uid = (0 === uid.length) ? urlParams.get('bwfan_form_uid_id') : uid.val();

            var bwfan_nonce = $('#bwfan_unsubscribe_nonce');

            var oneClickArgs = urlParams.get('List-Unsubscribe');
            oneClickArgs = (oneClickArgs == 'One-Click') ? 1 : 0;

            if (uid == undefined && recipient == undefined) {
                if ($(".bwfan_response").length === 0) {
                    $this.after("<div class='bwfan_response'>" + bwfanParamspublic.message_no_contact + "</div>");
                }
                $('.bwfan_response').fadeIn();
                $this.removeClass('bwfan_loading');
                setTimeout(function () {
                    $('.bwfan_response').fadeOut("slow");
                }, 2500);
                return;
            }

            var lists = BWFAN_Public.get_selected_unsubscribe_lists();
            if (lists.includes('all')) {
                $('.bwfan-unsubscribe-list-checkbox input').removeAttr('checked');
                $('#bwfan-list-unsubscribe-all').prop('checked', true);
            }
            lists = JSON.stringify(lists);

            $.ajax({
                method: 'post',
                dataType: 'json',
                url: bwfanParamspublic.ajax_url,
                data: $('#bwfan_unsubscribe_fields').serialize() + "&action=bwfan_unsubscribe_user" + "&recipient=" + recipient + "&sid=" + sid + "&tid=" + tid + "&unsubscribe_lists=" + lists + "&_nonce=" + bwfan_nonce.val() + "&one_click=" + oneClick + "&one_click_get=" + oneClickArgs,
                success: function (result) {
                    // Add the redirect handling here
                    if (result.redirect && result.redirect_url) {
                        window.location.href = result.redirect_url;
                        return;
                    }

                    $this.removeClass('bwfan_loading');

                    var response_generated = $this.parent().find('.bwfan_response').html();
                    if ('undefined' === typeof response_generated) {
                        $this.after("<div class='bwfan_response'></div>");
                    }

                    if (0 === result.success) {
                        $('.bwfan_response').addClass('bwfan_error');
                        $('.bwfan_response').fadeIn().html(result.message);

                        if (typeof bwfan_unsubscribe_preference !== 'undefined' && bwfan_unsubscribe_preference == 'one_click') {
                            $('.bwfan-unsubscribe-lists').hide();
                            $('a#bwfan_unsubscribe').hide();
                        } else {
                            setTimeout(function () {
                                $('.bwfan_response').fadeOut("slow");
                            }, 2500);
                        }

                        return;
                    }

                    $('.bwfan_response').addClass('bwfan_success');
                    $('.bwfan_response').fadeIn().html(result.message);
                    if ('1' === $('#bwfan_one_click').val()) {
                        $('#bwfan-list-unsubscribe-all').prop('checked', true);
                    }

                    if (typeof bwfan_unsubscribe_preference !== 'undefined' && bwfan_unsubscribe_preference == 'one_click') {
                        $('.bwfan-unsubscribe-lists').hide();
                        $('a#bwfan_unsubscribe').hide();
                    } else {
                        /** Content is refresh after success */
                        $('#bwfan-unsubscribe-lists').load(
                            location.href + ' #bwfan-unsubscribe-lists > *'
                        );
                        setTimeout(function () {
                            $('.bwfan_response').fadeOut('slow');
                        }, 2500);
                    }
                }
            });
        });
    };

    /**
     * Selected profile tags
     * */
    BWFAN_Public.get_profile_tags = function () {
        const enabled = $('#bwfan-profile-tags').length > 0;
        if (!enabled) {
            return [];
        }

        var tags = [];
        $('.bwfan-profile-tag-checkbox input:checked').each(function () {
            tags.push($(this).val());
        });
        return tags;
    };

    /**
     * selected profile lists
     *
     * */
    BWFAN_Public.get_profile_lists = function () {
        const enabled = $('#bwfan-profile-lists').length > 0;
        if (!enabled) {
            return [];
        }

        const lists = [];
        $('.bwfan-profile-list-checkbox input:checked').each(function () {
            lists.push(parseInt($(this).val()));
        });

        return lists;
    }

    /**
     * Selected profile fields
     *
     * */
    BWFAN_Public.get_profile_fields = function () {
        const enabled = $('#bwfan-profile-fields').length > 0;
        if (!enabled) {
            return [];
        }

        const fields = [];
        const dobParts = { day: '', month: '', year: '' };
        let hasDobField = false;

        $('.bwfan-profile-field input, .bwfan-profile-field textarea, .bwfan-profile-field select').each(function () {
            const fieldSlug = $(this).data('slug');
            const dobPart = $(this).data('dob-part');

            // Handle DOB parts separately
            if (fieldSlug === 'dob' && dobPart) {
                hasDobField = true;
                dobParts[dobPart] = $(this).val();
                return; // Skip adding to fields array
            }

            if ($(this).is(':radio') || $(this).is(':checkbox')) {
                if ($(this).is(':checked')) {
                    if (!fields[fieldSlug]) {
                        fields[fieldSlug] = [];
                    }
                    fields[fieldSlug].push($(this).val());
                }
            } else if (fieldSlug === 'timezone') {
                fields[fieldSlug] = $(this).val();
            } else {
                fields[fieldSlug] = $(this).val();
            }
        });

        for (const slug in fields) {
            if (Array.isArray(fields[slug])) {
                fields[slug] = fields[slug].join(', ');
            }
        }

        const result = [];

        // Add DOB parts if present
        if (hasDobField) {
            result.push({ slug: 'dob', value: dobParts.day, dob_part: 'day' });
            result.push({ slug: 'dob', value: dobParts.month, dob_part: 'month' });
            result.push({ slug: 'dob', value: dobParts.year, dob_part: 'year' });
        }

        for (const slug in fields) {
            const value = Array.isArray(fields[slug]) ? fields[slug].join(', ') : fields[slug];
            result.push({slug: slug, value: value});
        }

        return result;
    }

    BWFAN_Public.update_profile = function () {
        const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        $('#bwfan_manage_profile_update').on('click', function (event) {
            event.preventDefault();
            var $this = $(this);

            // Clear previous DOB errors (errors are added as siblings with .after())
            $('.bwfan-dob-wrapper').next('.bwfan_error').remove();
            $('.bwfan-dob-field').removeClass('bwfan_error');

            // Validate DOB fields if present
            const dobFields = $('.bwfan-dob-field[data-slug="dob"]');
            if (dobFields.length > 0) {
                let dobDay = '';
                let dobMonth = '';
                let dobYear = '';
                let dobWrapper = null;

                dobFields.each(function() {
                    const dobPart = $(this).data('dob-part');
                    if (!dobWrapper) {
                        dobWrapper = $(this).closest('.bwfan-dob-wrapper');
                    }

                    if (dobPart === 'day') dobDay = $(this).val();
                    if (dobPart === 'month') dobMonth = $(this).val();
                    if (dobPart === 'year') dobYear = $(this).val();
                });

                // Check if all parts are selected or all are empty
                const hasAnyValue = dobDay || dobMonth || dobYear;
                const hasAllValues = dobDay && dobMonth && dobYear;
                const allEmpty = !dobDay && !dobMonth && !dobYear;

                // If all empty, it's valid (optional field)
                if (allEmpty) {
                    // DOB is optional, all empty is valid
                } else if (hasAnyValue && !hasAllValues) {
                    // Some parts selected but not all
                    dobFields.addClass('bwfan_error');
                    dobWrapper.after("<p class='bwfan_response bwfan_error'>"+bwfanParamspublic.dob_not_all_parts+"</p>");
                    return;
                } else if (hasAllValues) {
                    // If all values present, validate date is valid
                    const day = parseInt(dobDay, 10);
                    const month = parseInt(dobMonth, 10);
                    const year = parseInt(dobYear, 10);

                    // Check if date is valid
                    const date = new Date(year, month - 1, day);
                    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
                        dobFields.addClass('bwfan_error');
                        dobWrapper.after("<p class='bwfan_response bwfan_error'>"+bwfanParamspublic.dob_not_valid+"</p>");
                        return;
                    }

                    // Check if date is not in the future
                    const today = new Date();
                    if (date > today) {
                        dobFields.addClass('bwfan_error');
                        dobWrapper.after("<p class='bwfan_response bwfan_error'>"+bwfanParamspublic.dob_not_in_future+"</p>");
                        return;
                    }
                }
            }

            var emailField = $('input[data-slug="email"]');
            if( emailField.length > 0 ) {
                if (emailField.val() === '') {
                    // Check if error message already exists
                    if (emailField.next('.bwfan_error').length === 0) {
                        $('#bwfan-field-email').addClass('bwfan_error');
                        emailField.after("<p class='bwfan_response bwfan_error'>"+bwfanParamspublic.email_not_fill+"</p>");
                    } else {
                        emailField.next('.bwfan_error').fadeIn().html( bwfanParamspublic.email_not_fill );
                    }
                    return; // Stop the process if email is empty
                } else if( ! emailRegex.test(emailField.val()) ) {
                    if (emailField.next('.bwfan_error').length === 0) {
                        $('#bwfan-field-email').addClass('bwfan_error');
                        emailField.after("<p class='bwfan_response bwfan_error'>"+bwfanParamspublic.email_not_valid+"</p>");
                    } else {
                        emailField.next('.bwfan_error').fadeIn().html(bwfanParamspublic.email_not_valid);
                    }
                    return;
                } else{
                    emailField.next('.bwfan_error').remove();
                    $('#bwfan-field-email').removeClass('bwfan_error');
                }
            }

            var errorExists = $('#bwfan_manage_profile_fields' ).find('.bwfan_response.bwfan_error').length > 0;
            if( errorExists ) {
                // Get the first error element
                var firstError = $('#bwfan_manage_profile_fields' ).find('.bwfan_response.bwfan_error')[0];
                setTimeout( () => {
                    var distractionMode = $( '.bwfan-manage-profile-wrapper.bwf-distraction-free-mode' ).length > 0;
                    if( distractionMode ) {
                        firstError.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start',
                            inline: 'nearest'
                        });
                        return;
                    }

                    // Scroll to the position of the first error element
                    window.scrollTo({
                        top: firstError.getBoundingClientRect().top + window.scrollY - 100,
                        behavior: 'smooth'
                    });
                }, 300 );
            }

            if ($this.hasClass('bwfan_loading')) {
                return;
            }


            var bwfan_nonce = $('#bwfan_manage_profile_nonce');

            var lists = BWFAN_Public.get_profile_lists();
            lists = JSON.stringify(lists);

            var tags = BWFAN_Public.get_profile_tags();
            tags = JSON.stringify(tags);

            var fields = BWFAN_Public.get_profile_fields();
            fields = JSON.stringify(fields);

            var uid = $('#bwfan_form_uid_id');
            uid = (0 === uid.length) ? urlParams.get('bwfan_form_uid_id') : uid.val();

            $this.addClass('bwfan_loading');

            $.ajax({
                method: 'post',
                dataType: 'json',
                url: bwfanParamspublic.ajax_url,
                data: $('#bwfan_manage_profile_update').serialize() + "&action=bwfan_manage_profile" + "&uid=" + uid + "&profile_lists=" + lists + "&profile_tags=" + tags + "&profile_fields=" + fields + "&_nonce=" + bwfan_nonce.val(),
                success: function (result) {
                    $this.removeClass('bwfan_loading');
                    var response_generated = $this.parent().find('.bwfan_response').html();
                    if ('undefined' === typeof response_generated) {
                        $this.after("<div class='bwfan_response api-response'></div>");
                    }
                    let message = result.hasOwnProperty( 'message' ) ? result.message : bwfanParamspublic.profile_success;
                    switch (parseInt(result.success)) {
                        case 10:
                            if (emailField.next('.bwfan_error').length === 0) {
                                $('#bwfan-field-email').addClass('bwfan_error');
                                emailField.after(`<p class='bwfan_response bwfan_error'>${message}</p>`);
                            }
                            break;
                        case 0:
                            $('.bwfan_response.api-response').addClass('bwfan_error');
                            $('.bwfan_response.api-response').fadeIn().html(message);
                            break;
                        default:
                            if ($('.bwfan_response.api-response').length > 0) {
                                $('.bwfan_response.api-response').removeClass('bwfan_error');
                            }
                            $('.bwfan_response.api-response').addClass('bwfan_success');
                            $('.bwfan_response.api-response').fadeIn().html(message);
                            break;
                    }
                    setTimeout(function () {
                        $('.bwfan_response.api-response').fadeOut("slow");
                    }, 3000);
                }
            });
        });
        $(document).ready(function () {
            // Datetime fields
            const dateTimeFields = document.querySelectorAll('#bwfan-profile-fields .bwfan-profile-field input[type="text"][data-type="date-time"]');
            dateTimeFields.forEach(field => {
                field.addEventListener('input', function () {
                    let value = $(this).val().trim().replace(/\D/g, ''); // Strip non-digits
                    let formatted = '';
                    if (value.length > 0) formatted += value.substring(0, 4);           // YYYY
                    if (value.length > 4) formatted += '-' + value.substring(4, 6);     // MM
                    if (value.length > 6) formatted += '-' + value.substring(6, 8);     // DD
                    if (value.length > 8) formatted += ' ' + value.substring(8, 10);    // HH
                    if (value.length > 10) formatted += ':' + value.substring(10, 12);  // mm
                    if (value.length > 12) formatted += ':' + value.substring(12, 14);  // ss

                    $(this).val(formatted);
                });

                field.addEventListener('blur', function () {
                    const val = $(this).val().trim();

                    if( val == '' ) {
                        $(this).next('.bwfan_error').remove();
                        $(this).removeClass('bwfan_error');
                        return;
                    }

                    if (val.length !== 19) {
                        let error = 'Invalid date-time format. Please use YYYY-MM-DD HH:MM:SS';
                        if ($(this).next('.bwfan_error').length === 0) {
                            $(this).addClass('bwfan_error');
                            $(this).after("<p class='bwfan_response bwfan_error'>"+error+"</p>");
                        } else {
                            $(this).next('.bwfan_error').fadeIn().html(error);
                        }
                        return;
                    }

                    const regex = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/;
                    const match = val.match(regex);

                    if (!match) {
                        let error = 'Invalid format. Please use YYYY-MM-DD HH:MM:SS';
                        if ($(this).next('.bwfan_error').length === 0) {
                            $(this).addClass('bwfan_error');
                            $(this).after("<p class='bwfan_response bwfan_error'>"+error+"</p>");
                        } else {
                            $(this).next('.bwfan_error').fadeIn().html(error);
                        }
                        return;
                    }

                    const [_, year, month, day, hour, minute, second] = match.map(Number);

                    const isValidDate = (y, m, d, h, min, s) => {
                        if (m < 1 || m > 12) return false;
                        if (d < 1 || d > 31) return false;
                        if (h < 0 || h > 23) return false;
                        if (min < 0 || min > 59) return false;
                        if (s < 0 || s > 59) return false;

                        const date = new Date(y, m - 1, d);
                        return date.getFullYear() === y &&
                            date.getMonth() === m - 1 &&
                            date.getDate() === d;
                    };
                    if (!isValidDate(year, month, day, hour, minute, second)) {
                        let error = 'Invalid date or time entered.';
                        if ($(this).next('.bwfan_error').length === 0) {
                            $(this).addClass('bwfan_error');
                            $(this).after("<p class='bwfan_response bwfan_error'>"+error+"</p>");
                        } else {
                            $(this).next('.bwfan_error').fadeIn().html(error);
                        }
                        return;
                    }
                    $(this).next('.bwfan_error').remove();
                    $(this).removeClass('bwfan_error');
                });
            });

            // Date field
            const dateFields = document.querySelectorAll('#bwfan-profile-fields .bwfan-profile-field input[type="text"][data-type="date"]');
            dateFields.forEach(field => {
                field.addEventListener('input', function () {
                    let value = $(this).val().trim().replace(/\D/g, ''); // Strip non-digits
                    let formatted = '';
                    if (value.length > 0) formatted += value.substring(0, 4);           // YYYY
                    if (value.length > 4) formatted += '-' + value.substring(4, 6);     // MM
                    if (value.length > 6) formatted += '-' + value.substring(6, 8);     // DD

                    $(this).val(formatted);
                });

                field.addEventListener('blur', function () {
                    const val = $(this).val().trim();
                    if( val == '' ) {
                        $(this).next('.bwfan_error').remove();
                        $(this).removeClass('bwfan_error');
                        return;
                    }
                    if (val.length !== 10) {
                        let error = 'Invalid date format. Please use YYYY-MM-DD';
                        if ($(this).next('.bwfan_error').length === 0) {
                            $(this).addClass('bwfan_error');
                            $(this).after("<p class='bwfan_response bwfan_error'>"+error+"</p>");
                        } else {
                            $(this).next('.bwfan_error').fadeIn().html(error);
                        }
                        return;
                    }

                    const regex = /^(\d{4})-(\d{2})-(\d{2})$/;
                    const match = val.match(regex);

                    if (!match) {
                        let error = 'Invalid format. Please use YYYY-MM-DD';
                        if ($(this).next('.bwfan_error').length === 0) {
                            $(this).addClass('bwfan_error');
                            $(this).after("<p class='bwfan_response bwfan_error'>"+error+"</p>");
                        } else {
                            $(this).next('.bwfan_error').fadeIn().html(error);
                        }
                        return;
                    }

                    const [_, year, month, day] = match.map(Number);

                    const isValidDate = (y, m, d) => {
                        if (m < 1 || m > 12) return false;
                        if (d < 1 || d > 31) return false;

                        const date = new Date(y, m - 1, d);
                        return date.getFullYear() === y &&
                            date.getMonth() === m - 1 &&
                            date.getDate() === d;
                    };
                    if (!isValidDate(year, month, day)) {
                        let error = 'Invalid date  entered.';
                        if ($(this).next('.bwfan_error').length === 0) {
                            $(this).addClass('bwfan_error');
                            $(this).after("<p class='bwfan_response bwfan_error'>"+error+"</p>");
                        } else {
                            $(this).next('.bwfan_error').fadeIn().html(error);
                        }
                        return;
                    }
                    $(this).next('.bwfan_error').remove();
                    $(this).removeClass('bwfan_error');
                });
            });
        });

        // Time field
        const timeFields = document.querySelectorAll('#bwfan-profile-fields .bwfan-profile-field input[type="text"][data-type="time"]');
        timeFields.forEach(field => {
            field.addEventListener('input', function () {
                let value = $(this).val().trim().replace(/\D/g, ''); // Strip non-digits
                let formatted = '';
                if (value.length > 0) formatted += value.substring(0, 2);           // HH
                if (value.length > 2) formatted += ':' + value.substring(2, 4);     // MM
                if (value.length > 4) formatted += ':' + value.substring(4, 6);     // SS

                $(this).val(formatted);
            });

            field.addEventListener('blur', function () {
                const val = $(this).val().trim();

                if( val == '' ) {
                    $(this).next('.bwfan_error').remove();
                    $(this).removeClass('bwfan_error');
                    return;
                }

                if (val.length !== 8) {
                    let error = 'Invalid time format. Please use HH:MM:SS';
                    if ($(this).next('.bwfan_error').length === 0) {
                        $(this).addClass('bwfan_error');
                        $(this).after("<p class='bwfan_response bwfan_error'>"+error+"</p>");
                    } else {
                        $(this).next('.bwfan_error').fadeIn().html(error);
                    }
                    return;
                }

                const regex = /^(\d{2}):(\d{2}):(\d{2})$/;
                const match = val.match(regex);

                if (!match) {
                    let error = 'Invalid format. Please use HH:MM:SS';
                    if ($(this).next('.bwfan_error').length === 0) {
                        $(this).addClass('bwfan_error');
                        $(this).after("<p class='bwfan_response bwfan_error'>"+error+"</p>");
                    } else {
                        $(this).next('.bwfan_error').fadeIn().html(error);
                    }
                    return;
                }

                const [_, hour, minute, second] = match.map(Number);

                const isValidDate = (h, min, s) => {
                    if (h < 0 || h > 23) return false;
                    if (min < 0 || min > 59) return false;
                    if (s < 0 || s > 59) return false;

                    return true;
                };
                if (!isValidDate(hour, minute, second)) {
                    let error = 'Invalid time entered.';
                    if ($(this).next('.bwfan_error').length === 0) {
                        $(this).addClass('bwfan_error');
                        $(this).after("<p class='bwfan_response bwfan_error'>"+error+"</p>");
                    } else {
                        $(this).next('.bwfan_error').fadeIn().html(error);
                    }
                    return;
                }
                $(this).next('.bwfan_error').remove();
                $(this).removeClass('bwfan_error');
            });
        });
    }

    /* Initialize */
    BWFAN_Public.init();

    $(document).ready(function () {
        if ('0' != bwfanParamspublic.bwfan_ab_enable && bwfanParamspublic.bwfan_ab_enable != '') {
            var bwfan_email_consent_message = '';
            if ('0' != bwfanParamspublic.bwfan_ab_email_consent && bwfanParamspublic.bwfan_ab_email_consent != '') {
                var site_language = bwfanParamspublic.bwfan_site_language;
                var message_index = 'bwfan_ab_email_consent_message_' + site_language;
                var label = bwfanParamspublic.hasOwnProperty(message_index) && bwfanParamspublic[message_index] != '' ? bwfanParamspublic[message_index] : bwfanParamspublic.bwfan_ab_email_consent_message;
                bwfan_email_consent_message = '<label>' + label + '</label>';

                // Remove/Strip slashes
                bwfan_email_consent_message = bwfan_email_consent_message.replace(new RegExp("\\\\", "g"), "");
                var start = bwfan_email_consent_message.search("{{no_thanks label=");
                var end = bwfan_email_consent_message.search("}}");
                if (-1 != start && -1 != end) {
                    var temp_start = start + 19;
                    var temp_end = end - 1;
                    var no_thanks_merge_tag = bwfan_email_consent_message.substring(start, (end + 2));
                    var no_thanks_label = bwfan_email_consent_message.substring(temp_start, temp_end);
                    no_thanks_label = no_thanks_label ? no_thanks_label : bwfanParamspublic.bwfan_no_thanks;
                    bwfan_email_consent_message = bwfan_email_consent_message.replace(no_thanks_merge_tag, "<a class='bwfan_email_consent_no_thanks' style='text-decoration:underline;cursor: pointer;'>" + no_thanks_label + "</a>");
                } else {
                    bwfan_email_consent_message = bwfan_email_consent_message.replace("{{no_thanks}}", "<a class='bwfan_email_consent_no_thanks' style='text-decoration:underline;cursor: pointer;'>" + bwfanParamspublic.bwfan_no_thanks + "</a>");
                }
            }

            var emailConsentHtml = '<input type="hidden" id="bwfan_gdpr_consent" value="1" />';
            if ('' === bwfan_email_consent_message) {
                $('#billing_email_field').after(emailConsentHtml);
            } else {
                bwfan_email_consent_message += emailConsentHtml;
                $('#billing_email_field').after('<p class="form-row form-row-wide wfacp-form-control-wrapper wfacp-col-full wfacp-anim-wrap">' + bwfan_email_consent_message + '</p>');
            }

            $('.bwfan_email_consent_no_thanks').on('click', function (event) {
                event.preventDefault();

                $.post(bwfanParamspublic.wc_ajax_url.toString().replace('%%endpoint%%', 'bwfan_delete_abandoned_cart'), {
                        'email': $('#billing_email').val(),
                        'action': 'bwfan_delete_abandoned_cart',
                        '_wpnonce': bwfanParamspublic.ajax_nonce
                    }, function () {
                        //
                    }
                );
                $('#bwfan_gdpr_consent').val('0');
                $(this).parent().fadeOut("slow");
            });
        }
    });
    $(window).on('load', function () {
        BWFAN_Public.abandoned_cart();
        BWFAN_Public.unsubscribe_event();
        BWFAN_Public.update_profile();
        BWFAN_Public.bwfan_check_for_checkout_fields();
        BWFAN_Public.bwfan_capture_data_on_page_load();
        $(document.body).on('wfacp_step_switching', function (e, v) {
            BWFAN_Public.current_step = v.current_step;
            var email = $('#billing_email').val();
            if (email !== '') {
                BWFAN_Public.bwfan_process_email(email);
            }
        });

        /**
         * Detect change and save data in database
         */
        BWFAN_Public.checkout_form.on('change', 'select', BWFAN_Public.bwfan_captureCheckoutField);
        BWFAN_Public.checkout_form.on('click', '.input-checkbox', BWFAN_Public.bwfan_captureCheckoutField);
        BWFAN_Public.checkout_form.on('change', '.input-checkbox', BWFAN_Public.bwfan_captureCheckoutField);
        BWFAN_Public.checkout_form.on('change', '.input-text', BWFAN_Public.bwfan_captureCheckoutField);

        /**
         * WC Blocks checkout: inputs re-render on every Store API push (cart/shipping/totals),
         * so direct .on() bindings get detached. Delegate via document instead.
         * React-controlled text inputs fire 'input' reliably; 'change'/'blur' may be missed.
         */
        if (BWFAN_Public.is_block_checkout) {
            var blockInputs = '#email, #contact_email, [name="contact_email"], [id^="billing-"], [id^="shipping-"], textarea[id*="order-notes"], input[name*="marketing-consent"], input[id*="marketing-consent"], .wc-block-checkout__use-address-for-billing input[type=checkbox]';
            $(document).on('change input blur focusout', blockInputs, BWFAN_Public.bwfan_captureCheckoutField);

            /**
             * "Use same address for billing" toggle has an auto-generated id (e.g. checkbox-control-1)
             * and no name attribute, so bwfan_captureCheckoutField can't classify it.
             * Wire a dedicated handler: translate state to ship_to_different_address and re-POST.
             */
            var sameAddrSel = '.wc-block-checkout__use-address-for-billing input[type=checkbox]';
            $(document).on('change', sameAddrSel, function () {
                var sameAddr = $(this).is(':checked');
                BWFAN_Public.checkout_fields_data['ship_to_different_address'] = sameAddr ? 0 : 1;
                /**
                 * When the user re-checks "use shipping as billing", the block hides the billing
                 * form so any previously typed billing_* values become unreachable. Drop them so
                 * the pre-POST mirror (shipping_* -> billing_*) is what lands on the cart row.
                 */
                if (sameAddr) {
                    Object.keys(BWFAN_Public.checkout_fields_data).forEach(function (k) {
                        if (k.indexOf('billing_') === 0 && k !== 'billing_email') {
                            delete BWFAN_Public.checkout_fields_data[k];
                        }
                    });
                } else {
                    Object.keys(BWFAN_Public.checkout_fields_data).forEach(function (k) {
                        if (k.indexOf('billing_') === 0 && k !== 'billing_email') {
                            BWFAN_Public.checkout_fields_data[k] = '';
                        }
                    });
                }
                if (typeof BWFAN_Public.bwfan_get_checkout_data === 'function') {
                    setTimeout(BWFAN_Public.bwfan_get_checkout_data, 300);
                }
            });

            /**
             * Block payment method radios use auto-generated names (radio-control-X). The radio
             * value attribute carries the payment gateway slug (cod, bacs, stripe, ...). Map to
             * canonical `payment_method` key.
             */
            var paySel = '.wp-block-woocommerce-checkout-payment-block input[type=radio], .wc-block-components-payment-method input[type=radio]';
            $(document).on('change', paySel, function () {
                var val = $(this).val();
                if (val) {
                    BWFAN_Public.checkout_fields_data['payment_method'] = val;
                    if (typeof BWFAN_Public.bwfan_get_checkout_data === 'function') {
                        setTimeout(BWFAN_Public.bwfan_get_checkout_data, 300);
                    }
                }
            });
        }
        /**
         * Safety net for inputs whose `change` never fires (scripted fills, autofill).
         *
         * This previously called bwfan_captureCheckoutField() from an arrow function with no
         * element context, so `this` was BWFAN_Public and the call returned at its first guard —
         * it captured nothing. Read the field off the blurred element, then capture.
         */
        BWFAN_Public.checkout_form.on('blur focusout', '.input-text', function () {
            BWFAN_Public.bwfan_captureCheckoutField.call(this);
        });
        // Reset the 'triggered' flag when the input element gets focused again
        BWFAN_Public.checkout_form.on('focus', '.input-text', function () {
            $(this).removeData('triggered');
        });

        var interval = null;
        var globalSel = '#billing_email,.input-text,.input-checkbox, select';
        var globalEvents = 'blur change';
        if (BWFAN_Public.is_block_checkout) {
            globalSel += ', #email, #contact_email, [name="contact_email"], [id^="billing-"], [id^="shipping-"], textarea[id*="order-notes"], input[name*="marketing-consent"], input[id*="marketing-consent"]';
            /** React-controlled inputs fire 'input' reliably; blur often missed via delegation. */
            globalEvents = 'blur change input';
        }
        $(document).on(globalEvents, globalSel, function () {
            var field_name = $(this).attr('name') || $(this).attr('id');
            if (!field_name) {
                return;
            }
            field_name = BWFAN_Public.bwfan_normalize_field_name(field_name);

            /**
             * Block checkout: billing_email isn't in checkout_fields but must still
             * trigger the POST, and React-controlled inputs can swallow discrete
             * change events — skip the per-field dedupe and go straight to the
             * debounced POST.
             */
            if (BWFAN_Public.is_block_checkout) {
                if (BWFAN_Public.checkout_fields.indexOf(field_name) === -1 && field_name !== 'billing_email') {
                    return;
                }
                if (interval !== null) clearTimeout(interval);
                interval = setTimeout(BWFAN_Public.bwfan_get_checkout_data, 300);
                return;
            }

            var field_value = $(this).val();

            /** for checking checkbox fields **/
            if ($(this).attr('type') == 'checkbox') {
                field_value = $(this).prop('checked') ? 1 : 0;
            }

            if (BWFAN_Public.checkout_fields.indexOf(field_name) === -1) {
                return;
            }

            /**
             * Dedupe against the last POSTed values, not checkout_fields_data:
             * bwfan_captureCheckoutField is delegated on the form, so it runs
             * earlier in the same event's bubble path and has already synced
             * the live cache — a live-cache comparison always reads "unchanged"
             * and the POST would never fire (e.g. first/last name edits).
             */
            if (BWFAN_Public.last_posted_fields_data[field_name] === field_value) {
                return;
            }

            if (interval !== null) {
                clearTimeout(interval);
            }
            interval = setTimeout(BWFAN_Public.bwfan_get_checkout_data, 100);
        });

        if (typeof bwfan_unsubscribe_preference !== 'undefined' && 'one_click' === bwfan_unsubscribe_preference) {
            var urlParams = new URLSearchParams(window.location.search);
            var recipient = $('#bwfan_unsubscribe_recipient');
            recipient = (0 === recipient.length) ? urlParams.get('subscriber_recipient') : recipient.html();
            var uid = $('#bwfan_form_uid_id');
            uid = (0 === uid.length) ? urlParams.get('bwfan_form_uid_id') : uid.val();
            if ((uid !== '' && uid !== null) || (recipient !== '' && recipient !== null)) {
                $('#bwfan_unsubscribe').click();
            }

            if (uid !== '' && uid !== null) {
                var cookieExpireDate = new Date();
                cookieExpireDate.setFullYear(cookieExpireDate.getFullYear() + 1);
                document.cookie = '_fk_contact_uid=' + uid + '; expires=' + cookieExpireDate.toUTCString() + ';';
            }
        }
    });
    var updateCartTimeout = null;
    $(document).on('updated_checkout', function () {
        if (updateCartTimeout !== null) {
            clearTimeout(updateCartTimeout);
        }
        updateCartTimeout = setTimeout(function (){
            var email = $('#billing_email').val();
            if ((!email || email === '') && BWFAN_Public && BWFAN_Public.is_block_checkout) {
                email = $('#email').val();
            }
            if (email && email !== '') {
                BWFAN_Public.bwfan_process_email(email);
            }
        }, 500);
    });

    /**
     * WC Blocks fires its own store_api_checkout / wc-blocks_checkout_render events on every Store API
     * push. Re-fire process_email on a debounce so the cart row gets touched on cart/shipping changes.
     */
    $(document).on('wc-blocks_checkout_render wc-blocks-checkout-update', function () {
        if (!BWFAN_Public || !BWFAN_Public.is_block_checkout) return;
        var email = $('#email').val();
        if (email && email !== '') {
            BWFAN_Public.bwfan_process_email(email);
        }
    });

    /**
     * Restore the previously-selected payment method on the block checkout after recovery.
     *
     * Modern WC Blocks keeps the active payment method inside the wc/store/payment data store.
     * React-controlled radios ignore native DOM changes, so we must drive the store directly.
     * We subscribe to the store so we detect when payment methods have initialized and when
     * our target becomes active, then stop.
     */
    (function () {
        if (typeof bwfanParamspublic === 'undefined' || parseInt(bwfanParamspublic._is_block_based_checkout) <= 0) return;
        var slug = bwfanParamspublic.bwfan_restored_payment_method;
        if (!slug) return;

        var applied = false;
        var attempts = 0;
        var MAX_ATTEMPTS = 40;

        var setNativeValue = function (el, value) {
            var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'checked').set;
            setter.call(el, value);
        };

        var tryDataStore = function () {
            if (!window.wp || !wp.data) return false;
            try {
                var paymentStore = wp.data.dispatch('wc/store/payment');
                var paymentSelect = wp.data.select('wc/store/payment');
                if (!paymentStore || !paymentSelect) return false;

                if (typeof paymentSelect.paymentMethodsInitialized === 'function' && !paymentSelect.paymentMethodsInitialized()) {
                    return false;
                }

                var available = paymentSelect.getAvailablePaymentMethods ? paymentSelect.getAvailablePaymentMethods() : {};
                if (!available || !Object.prototype.hasOwnProperty.call(available, slug)) {
                    return false;
                }

                var active = paymentSelect.getActivePaymentMethod ? paymentSelect.getActivePaymentMethod() : '';
                if (active === slug) {
                    applied = true;
                    return true;
                }

                if (typeof paymentStore.__internalSetActivePaymentMethod === 'function') {
                    paymentStore.__internalSetActivePaymentMethod(slug);
                } else if (typeof paymentStore.setActivePaymentMethod === 'function') {
                    paymentStore.setActivePaymentMethod(slug);
                }
                return true; // dispatched; let subscriber confirm on next tick
            } catch (e) {
                return false;
            }
        };

        var tryDomFallback = function () {
            var $radio = $('.wp-block-woocommerce-checkout-payment-block input[type=radio][value="' + slug + '"], .wc-block-components-payment-method input[type=radio][value="' + slug + '"]').first();
            if ($radio.length && !$radio.is(':checked')) {
                setNativeValue($radio[0], true);
                $radio[0].dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        var tick = function () {
            if (applied || attempts++ > MAX_ATTEMPTS) {
                if (unsub) unsub();
                if (poll) clearInterval(poll);
                return;
            }
            var storeHandled = tryDataStore();
            if (!storeHandled) {
                tryDomFallback();
            }
        };

        var unsub = null;
        if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
            unsub = wp.data.subscribe(function () {
                if (applied) {
                    if (unsub) unsub();
                    return;
                }
                try {
                    var paymentSelect = wp.data.select('wc/store/payment');
                    if (paymentSelect && typeof paymentSelect.getActivePaymentMethod === 'function') {
                        if (paymentSelect.getActivePaymentMethod() === slug) {
                            applied = true;
                            if (unsub) unsub();
                            if (poll) clearInterval(poll);
                        }
                    }
                } catch (e) {}
            }, 'wc/store/payment');
        }

        $(window).on('load', function () { setTimeout(tick, 300); });
        var poll = setInterval(tick, 350);
    })();
    $("#bwfan-tyb-save-btn").on('click', function () {
        var $this = $(this);
        var $parent = $this.parents(".bwfan-tyb-wrap");
        var res_msg_selector = $parent.find('.bwfan-tyb-msg');

        // Get values from the dropdown fields if they exist
        var yearSelect = $parent.find('select[name="bwfan_birthday_date_yy"]');
        var monthSelect = $parent.find('select[name="bwfan_birthday_date_mm"]');
        var daySelect = $parent.find('select[name="bwfan_birthday_date_dd"]');

        // Check if the dropdowns exist
        var dropdownsExist = yearSelect.length && monthSelect.length && daySelect.length;
        var year = '', month = '', day = '';
        // If dropdowns exist, validate their values
        if (dropdownsExist) {
            year = yearSelect.val();
            month = monthSelect.val();
            day = daySelect.val();
        }

        $this.addClass('bwfan-btn-spin');
        $.ajax({
            type: "post",
            dataType: "json",
            url: bwfanParamspublic.ajax_url,
            data: {
                "order_id": $parent.find('#bwfan-order-id').val(),
                "birthday": $parent.find('#bwfan-tyb-field').val(),
                "bwfan_birthday_date_yy": year,
                "bwfan_birthday_date_mm": month,
                "bwfan_birthday_date_dd": day,
                "cid": $parent.find('#bwfan-cid').val(),
                "nonce": $parent.find('#bwfan-tyb-nonce').val(),
                "action": "save_birthday_on_thankyou"
            },
            success: function (response) {
                $this.removeClass('bwfan-btn-spin');

                res_msg_selector.fadeIn().html(response.msg);

                if (2 === response.status) {
                    res_msg_selector.addClass('bwfan-tyb-error');
                } else {
                    res_msg_selector.removeClass('bwfan-tyb-error');
                }

                setTimeout(function () {
                    res_msg_selector.fadeOut("slow");
                }, 2500);
            }
        });
    });
    $(document).on('focus', '#bwfan_birthday_date', function () {
        this.type = this.getAttribute("type") !== "date" ? 'date' : this.getAttribute("type");
        setTimeout(() => {
            this.showPicker();
        }, 500);
    });

    // If unsubscribe form exists, don't run one-click unsubscribe
    if (0 !== $('form#bwfan_unsubscribe_fields').length) {
        return;
    }

    // If manage profile form exists, don't run one-click unsubscribe (this is NOT the unsubscribe page)
    if (0 !== $('#bwfan_manage_profile_update').length || 0 !== $('#bwfan_manage_profile_fields').length) {
        return;
    }

    let urlParams = new URLSearchParams(window.location.search);
    let automation_id = urlParams.get('automation_id');
    let broadcast_id = urlParams.get('broadcast_id');
    let form_feed_id = urlParams.get('form_feed_id');
    let sid = urlParams.get('sid');
    let uid = urlParams.get('uid');

    if (uid === null || uid === '') {
        return;
    }

    $.ajax({
        method: 'post',
        url: bwfanParamspublic.ajax_url,
        datatype: "JSON",
        data: {
            action: 'bwfan_unsubscribe_user',
            uid: uid,
            automation_id: automation_id ? automation_id : 0,
            broadcast_id: broadcast_id ? broadcast_id : 0,
            form_feed_id: form_feed_id ? form_feed_id : 0,
            sid: sid ? sid : 0,
            _nonce: bwfanParamspublic.unsubscribe_nonce,
            one_click: 1,
        },
        success: function (response) {

        }
    });
})(jQuery);

/* Deserialize the form object */
function bwfanac_deserialize_obj(query) {
    var setValue = function (root, path, value) {
        if (path.length > 1) {
            var dir = path.shift();
            if (typeof root[dir] == 'undefined') {
                root[dir] = path[0] === '' ? [] : {};
            }

            arguments.callee(root[dir], path, value);
        } else {
            if (root instanceof Array) {
                root.push(value);
            } else {
                root[path] = value;
            }
        }
    };

    var nvp = query.split('&');
    var data = {};

    for (var i = 0; i < nvp.length; i++) {
        var pair = nvp[i].split('=');
        var name = pair[0];
        var value = pair[1];

        try {
            name = decodeURIComponent(pair[0]);
            value = decodeURIComponent(pair[1]);
        } catch (e) {
            console.log("Error occurred while decoding (" + nvp[i] + "). Error is: " + e.message);
        }

        var path = name.match(/(^[^\[]+)(\[.*\]$)?/);
        var first = path[1];
        if (path[2]) {
            //case of 'array[level1]' || 'array[level1][level2]'
            path = path[2].match(/(?=\[(.*)\]$)/)[1].split('][')
        } else {
            //case of 'name'
            path = [];
        }
        path.unshift(first);

        setValue(data, path, value);
    }

    return data;
}
