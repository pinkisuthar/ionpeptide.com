jQuery(document).ready(function($) {
    'use strict';

    var monarchCheckoutDobDefault = '01/01/2001';

    function monarchRemoveQueryParam(param) {
        try {
            const url = new URL(window.location.href);
            if (!url.searchParams.has(param)) return;
            url.searchParams.delete(param);
            const newUrl = url.pathname + (url.search ? url.search : '') + (url.hash ? url.hash : '');
            window.history.replaceState({}, document.title, newUrl);
        } catch (e) {}
    }

    function ensureAchSelected() {
        try {
            const $radio = $('#payment_method_monarch_ach, input[name="payment_method"][value="monarch_ach"]');
            if (!$radio.length) return;
            if ($radio.prop('checked')) return;
            $radio.prop('checked', true).trigger('click').trigger('change');
            $(document.body).trigger('update_checkout');
        } catch (e) {}
    }

    function monarchGetDisconnectMessage() {
        if (typeof monarch_ach_params !== 'undefined' && monarch_ach_params.bank_disconnected_message) {
            return monarch_ach_params.bank_disconnected_message;
        }
        return 'Your bank account was disconnected. Please complete the checkout form again.';
    }

    function monarchInsertDisconnectedBanner() {
        const $existing = $('.monarch-bank-disconnected-banner');
        if ($existing.length) return;

        const msg = monarchGetDisconnectMessage();
        const $banner = $('<div class="monarch-bank-disconnected-banner" role="alert" aria-live="assertive"></div>');
        $banner.text(msg);

        // Try to place it in a highly visible spot on checkout.
        const $target =
            $('.woocommerce-notices-wrapper').first().length ? $('.woocommerce-notices-wrapper').first() :
            $('form.checkout').first().length ? $('form.checkout').first() :
            $('.woocommerce').first();

        if ($target && $target.length) {
            $target.prepend($banner);
        } else {
            $('body').prepend($banner);
        }
    }

    function monarchClearMonarchFields() {
        // Avoid wiping the whole Woo checkout (that can look like a "reset after success").
        // The gateway already purges/blank-checkout server-side for true disconnects.
        $('#monarch_org_id, #monarch_paytoken_id, #monarch_bank_verified, #monarch_info_confirmed').val('');
        $('#monarch_phone').val('');
        $('#monarch_dob').val(monarchCheckoutDobDefault);
        $('#monarch_routing_number, #monarch_account_number').val('');
        try {
            window.monarchReturningProfileBaseline = null;
        } catch (e) {}
        $(document.body).trigger('update_checkout');
    }

    // If redirected after a bank disconnect, show banner and clear the form UI immediately.
    try {
        const params = new URLSearchParams(window.location.search || '');
        if (params.get('monarch_bank_disconnected')) {
            // If the bank is already connected, treat this as a stale flag and do not reset the UI.
            const hasPaytoken = !!($('#monarch_paytoken_id').val() || $('input[name="monarch_paytoken_id"]').val());
            const hasConnectedUi = $('.monarch-bank-connected').length > 0;
            if (hasPaytoken || hasConnectedUi) {
                monarchRemoveQueryParam('monarch_bank_disconnected');
            } else {
                monarchInsertDisconnectedBanner();
                monarchClearMonarchFields();
                // Prevent repeated clearing if anything causes a soft reload/navigation.
                monarchRemoveQueryParam('monarch_bank_disconnected');
            }
        }
    } catch (e) {}

    function normalizeEmail(email) {
        return (email || '').toString().trim().toLowerCase();
    }

    function setPlaceOrderEnabled(enabled) {
        const $btn = $('#place_order');
        if ($btn.length) {
            $btn.prop('disabled', !enabled);
        }
    }

    function showEmailMismatchNotice(registeredEmail, billingEmail) {
        const msg = 'Your bank connection is linked to "' + registeredEmail + '". To pay using "' + billingEmail + '", please click "Use a different bank account" and reconnect your bank using that billing email.';

        // Ensure we have somewhere to show it even when the "connected" UI is shown (which has no #monarch-ach-errors)
        let $container = $('.monarch-bank-connected');
        if (!$container.length) {
            $container = $('#monarch-ach-form');
        }
        if (!$container.length) {
            $container = $('.monarch-returning-user');
        }
        if (!$container.length) {
            $container = $('.payment_method_monarch_ach');
        }
        if (!$container.length) {
            return;
        }

        let $notice = $('.monarch-email-mismatch-notice');
        if (!$notice.length) {
            $notice = $('<div class="woocommerce-error monarch-email-mismatch-notice"></div>');
            $container.after($notice);
        }
        $notice.text(msg).show();

        // Avoid showing two stacked error boxes: hide/clear the legacy #monarch-ach-errors container if present
        const $legacy = $('#monarch-ach-errors');
        if ($legacy.length) {
            $legacy.hide().empty();
        }
    }

    function clearEmailMismatchNotice() {
        $('.monarch-email-mismatch-notice').remove();
    }

    function enforceEmailMatchIfConnected() {
        if (typeof monarch_ach_params === 'undefined') return;

        const registeredEmail = normalizeEmail(monarch_ach_params.monarch_registered_email);
        if (!registeredEmail) return;

        // Only enforce when a bank is already connected (connected UI present or verified hidden fields)
        const hasConnectedUi = $('.monarch-bank-connected').length > 0;
        const verifiedHidden = normalizeEmail($('#monarch_bank_verified').val()) === 'true';
        const hasHiddenPaytoken = normalizeEmail($('#monarch_paytoken_id').val()) !== '';
        const isConnected = hasConnectedUi || (verifiedHidden && hasHiddenPaytoken);

        if (!isConnected) {
            clearEmailMismatchNotice();
            setPlaceOrderEnabled(true);
            return;
        }

        const billingEmail = normalizeEmail($('#billing_email').val());
        if (!billingEmail) return; // don't block while empty

        if (billingEmail !== registeredEmail) {
            showEmailMismatchNotice(monarch_ach_params.monarch_registered_email, $('#billing_email').val());
            setPlaceOrderEnabled(false);
        } else {
            clearEmailMismatchNotice();
            setPlaceOrderEnabled(true);
        }
    }

    // Phone number formatting
    $(document).on('input', '#monarch_phone', function() {
        let value = this.value.replace(/[^0-9]/g, '');
        if (value.length >= 6) {
            value = value.replace(/(\d{3})(\d{3})(\d{4})/, '($1) $2-$3');
        } else if (value.length >= 3) {
            value = value.replace(/(\d{3})(\d{0,3})/, '($1) $2');
        }
        this.value = value;
    });

    // Lock field and show display mode with Edit button
    function lockField(fieldType) {
        const wrapper = $('#monarch-' + fieldType + '-wrapper');
        const input = wrapper.find('input');
        const inputContainer = wrapper.find('.monarch-field-input');
        const displayContainer = wrapper.find('.monarch-field-display');
        const displayValue = wrapper.find('.monarch-field-value');

        let displayText = input.val();

        // Format the display text
        if (fieldType === 'dob' && displayText) {
            // Format date as MM/DD/YYYY
            const date = new Date(displayText);
            displayText = (date.getMonth() + 1).toString().padStart(2, '0') + '/' +
                          date.getDate().toString().padStart(2, '0') + '/' +
                          date.getFullYear();
        }

        displayValue.text(displayText);
        inputContainer.hide();
        displayContainer.show();
        wrapper.addClass('confirmed');
    }

    // Unlock field for editing
    function unlockField(fieldType) {
        const wrapper = $('#monarch-' + fieldType + '-wrapper');
        const inputContainer = wrapper.find('.monarch-field-input');
        const displayContainer = wrapper.find('.monarch-field-display');

        displayContainer.hide();
        inputContainer.show();
        wrapper.removeClass('confirmed');
        wrapper.find('input').focus();
    }

    // Handle Edit button click
    $(document).on('click', '.monarch-edit-btn', function(e) {
        e.preventDefault();
        const fieldType = $(this).data('field');
        unlockField(fieldType);
    });

    // Lock fields when user leaves the field (blur) if valid
    $(document).on('blur', '#monarch_phone', function() {
        const phone = $(this).val().replace(/[^0-9]/g, '');
        if (phone.length >= 10) {
            lockField('phone');
        }
    });

    // Routing number formatting (numbers only, max 9 digits) - both main form and modal
    $(document).on('input', '#monarch_routing_number, #modal_routing_number', function() {
        this.value = this.value.replace(/[^0-9]/g, '').substring(0, 9);
    });

    // Account number formatting (numbers only) - both main form and modal
    $(document).on('input', '#monarch_account_number, #modal_account_number', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
    });

    // Handle method toggle buttons inside modal
    $(document).on('click', '.monarch-modal-method-btn', function(e) {
        e.preventDefault();
        const method = $(this).data('method');

        // Update button states
        $('.monarch-modal-method-btn').removeClass('active');
        $(this).addClass('active');

        // Update hidden field
        $('#monarch_entry_method').val(method);

        // Show/hide sections (class + !important in CSS — jQuery .hide() loses to display:flex!important)
        if (method === 'manual') {
            $('#modal-auto-section').addClass('monarch-modal-section--hidden');
            $('#modal-manual-section').removeClass('monarch-modal-section--hidden');
        } else {
            $('#modal-manual-section').addClass('monarch-modal-section--hidden');
            $('#modal-auto-section').removeClass('monarch-modal-section--hidden');
        }
    });

    // Handle Connect Bank Account button click
    $(document).on('click', '#monarch-connect-bank', function(e) {
        e.preventDefault();

        // Validate required fields first
        if (!validateCustomerInfo()) {
            return false;
        }

        const $button = $(this);
        const $spinner = $('#monarch-connect-spinner');

        // Disable button and show spinner
        $button.prop('disabled', true).text('Connecting...');
        $spinner.show();

        const snap = monarchCollectBillingSnapshot();

        const customerData = {
            action: 'monarch_create_organization',
            nonce: monarch_ach_params.nonce,
            monarch_phone: $('#monarch_phone').val(),
            monarch_dob: $('#monarch_dob').val(),
            monarch_company: ($('#billing_company').val() || $('#monarch_company').val() || ''),
            billing_first_name: snap.first_name,
            billing_last_name: snap.last_name,
            billing_email: snap.email,
            billing_address_1: snap.address_1,
            billing_address_2: snap.address_2,
            billing_city: snap.city,
            billing_state: snap.state,
            billing_postcode: snap.postcode,
            billing_country: snap.country
        };

        console.log('Customer data being sent:', customerData);

        // Create organization and get bank linking URL
        $.ajax({
            url: monarch_ach_params.ajax_url,
            method: 'POST',
            data: customerData,
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data.has_bank) {
                    // User already has a bank connected - skip bank linking
                    console.log('User already has bank connected, paytoken:', response.data.paytoken_id);

                    // Store the connection data
                    $('#monarch_org_id').val(response.data.org_id);
                    $('#monarch_paytoken_id').val(response.data.paytoken_id);
                    $('#monarch_bank_verified').val('true');

                    // Refresh checkout to show connected status
                    $button.prop('disabled', false).text('Bank Already Connected');
                    $spinner.hide();
                    location.reload();
                } else if (response.success && response.data.bank_linking_url) {
                    // Store organization data
                    $('#monarch_org_id').val(response.data.org_id);

                    // Open bank linking in new window/popup
                    // Check if this is an existing user (existing_user flag from backend)
                    var isExisting = response.data.existing_user || false;

                    // IMPORTANT:
                    // If this email already exists in Monarch, Monarch will re-use the existing org/purchaser.
                    // That means the sandbox UI can still show the *old* name unless we explicitly update
                    // the org profile before initiating the bank-linking flow.
                    if (isExisting) {
                        const orgId = response.data.org_id;
                        const snapExisting = monarchCollectBillingSnapshot();
                        const phone = ($('#monarch_phone').val() || $('#billing_phone').val() || '').toString().trim();
                        const dob = ($('#monarch_dob').val() || '').toString().trim();

                        const updatePayload = {
                            action: 'monarch_update_organization',
                            nonce: monarch_ach_params.nonce,
                            org_id: orgId,
                            first_name: snapExisting.first_name,
                            last_name: snapExisting.last_name,
                            phone: phone,
                            companyName: ($('#billing_company').val() || $('#monarch_company').val() || '').toString().trim(),
                            add1: snapExisting.address_1,
                            add2: snapExisting.address_2,
                            city: snapExisting.city,
                            state: snapExisting.state,
                            zip: snapExisting.postcode,
                            country: snapExisting.country,
                            dob: dob,
                            heloxPushNotificationEnable: true
                        };

                        // Block the flow if the update fails; otherwise Monarch may keep showing stale purchaser details.
                        $.ajax({
                            url: monarch_ach_params.ajax_url,
                            method: 'POST',
                            data: updatePayload,
                            dataType: 'json',
                            success: function(updateResp) {
                                if (updateResp && updateResp.success) {
                                    openBankConnectionWindow(response.data.bank_linking_url, response.data.org_id, isExisting);
                                } else {
                                    const msg = (updateResp && updateResp.data && updateResp.data.message) ? updateResp.data.message : (updateResp && updateResp.data ? updateResp.data : 'Failed to update details. Please try again.');
                                    showError(msg);
                                    $button.prop('disabled', false).text('Connect Bank Account');
                                    $spinner.hide();
                                }
                            },
                            error: function(jqXHR, textStatus, errorThrown) {
                                showError('Connection error: ' + errorThrown);
                                $button.prop('disabled', false).text('Connect Bank Account');
                                $spinner.hide();
                            }
                        });
                    } else {
                        openBankConnectionWindow(response.data.bank_linking_url, response.data.org_id, isExisting);
                    }
                } else {
                    showError(response.data || 'Failed to create organization. Please try again.');
                    $button.prop('disabled', false).text('Connect Bank Account');
                    $spinner.hide();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                showError('Connection error: ' + errorThrown);
                $button.prop('disabled', false).text('Connect Bank Account');
                $spinner.hide();
            }
        });
    });

    // Open bank connection in IFRAME with redirect detection
    // When Yodlee redirects after Continue button, opens a new browser window with success page
    function openBankConnectionWindow(connectionUrl, orgId, isExistingUser) {
        let url = connectionUrl;
        isExistingUser = isExistingUser || false;
        window.monarchIsExistingUser = isExistingUser; // Store globally for the button handler

        console.log('Original bank linking URL from Monarch:', connectionUrl);

        // Get the current page URL for callbacks
        const currentUrl = window.location.href;
        let locationUrl = currentUrl.split('?')[0]; // Clean URL without query params

        // Build callback URL - when Yodlee redirects to this, we'll detect it and open new window
        let callbackUrl = locationUrl + '?monarch_bank_callback=1&org_id=' + orgId;

        // Replace placeholders in URL
        if (url.includes('{redirectUrl}') || url.includes('{price}')) {
            url = url.replace(/\{price\}/g, '100');
            url = url.replace(/\{redirectUrl\}/g, encodeURIComponent(callbackUrl));
            console.log('Replaced URL placeholders - redirectUrl:', callbackUrl);
        }

        console.log('Final iframe URL:', url);

        // Store orgId and callback URL globally
        window.monarchCurrentOrgId = orgId;
        window.monarchCallbackUrl = callbackUrl;

        // Create modal with IFRAME for bank linking.
        // Append to <html> (see move below): block themes often set transform on body, which breaks
        // position:fixed for nodes inside body — modal then appears after footer instead of as overlay.
        const modal = $('<div id="bank-connection-modal" class="monarch-bank-connection-modal">' +
            '<div class="monarch-modal-overlay"></div>' +
            '<div class="monarch-modal-content monarch-modal-iframe-content">' +
            '<div class="monarch-modal-header">' +
            '<h3>Connect Your Bank Account</h3>' +
            '<button type="button" id="close-bank-modal" class="monarch-modal-close">&times;</button>' +
            '</div>' +
            // Toggle buttons
            '<div class="monarch-modal-toggle">' +
            '<button type="button" class="monarch-modal-method-btn active" data-method="auto">Automatic (Secure)</button>' +
            '<button type="button" class="monarch-modal-method-btn" data-method="manual">Manual Entry</button>' +
            '</div>' +
            // Automatic section - iframe for bank linking
            '<div id="modal-auto-section" class="monarch-modal-section">' +
            '<div class="monarch-iframe-container">' +
            '<iframe id="monarch-bank-iframe" src="' + url + '" frameborder="0" allowfullscreen></iframe>' +
            '</div>' +
            '<div class="monarch-modal-footer">' +
            '<button type="button" id="monarch-bank-connected-btn" class="button alt">I\'ve Connected My Bank</button>' +
            '</div>' +
            '</div>' +
            // Manual entry section
            '<div id="modal-manual-section" class="monarch-modal-section monarch-modal-section--hidden">' +
            '<div class="monarch-modal-manual-form">' +
            '<div class="monarch-manual-form-inner">' +
            '<p class="form-row">' +
            '<label for="modal_bank_name">Bank Name <span class="required">*</span></label>' +
            '<input id="modal_bank_name" type="text" placeholder="e.g., Chase, Bank of America">' +
            '</p>' +
            '<p class="form-row form-row-half">' +
            '<label for="modal_routing_number">Routing Number <span class="required">*</span></label>' +
            '<input id="modal_routing_number" type="text" maxlength="9" placeholder="9 digits">' +
            '</p>' +
            '<p class="form-row form-row-half">' +
            '<label for="modal_account_number">Account Number <span class="required">*</span></label>' +
            '<input id="modal_account_number" type="text" placeholder="Your account number">' +
            '</p>' +
            '<p class="form-row">' +
            '<label for="modal_account_type">Account Type <span class="required">*</span></label>' +
            '<select id="modal_account_type">' +
            '<option value="CHECKING">Checking</option>' +
            '<option value="SAVINGS">Savings</option>' +
            '</select>' +
            '</p>' +
            '<div class="monarch-modal-manual-footer">' +
            '<button type="button" id="monarch-manual-submit-modal" class="button alt">Submit Bank Details</button>' +
            '<span id="monarch-manual-spinner-modal" class="spinner" style="display:none;"></span>' +
            '</div>' +
            '<p class="monarch-manual-note">Your bank details are securely transmitted and encrypted.</p>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '</div>');

        $(document.documentElement).append(modal);

        // Pin as viewport overlay (fixes block themes where body has transform and breaks position:fixed).
        try {
            $('#bank-connection-modal').css({
                position: 'fixed',
                top: '0',
                left: '0',
                right: '0',
                bottom: '0',
                width: '100%',
                height: '100%',
                'max-width': 'none',
                margin: '0',
                padding: '0',
                'box-sizing': 'border-box',
                'z-index': '2147483647',
                transform: 'none'
            });
        } catch (e) {}

        // Critical modal layout (scoped + !important): appended after theme CSS so block/WC overrides don’t break flex/overlay.
        var monarchModalCriticalCss =
            '#bank-connection-modal *,#bank-connection-modal *::before,#bank-connection-modal *::after{box-sizing:border-box!important}' +
            '#bank-connection-modal .monarch-modal-overlay{position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100%!important;height:100%!important;margin:0!important;padding:0!important;background:rgba(0,0,0,.65)!important;z-index:0!important;pointer-events:auto!important}' +
            '#bank-connection-modal .monarch-modal-content{position:absolute!important;top:50%!important;left:50%!important;right:auto!important;bottom:auto!important;transform:translate(-50%,-50%)!important;display:flex!important;flex-direction:column!important;align-items:stretch!important;justify-content:flex-start!important;background:#fff!important;width:min(95%,920px)!important;max-width:920px!important;min-width:0!important;height:85vh!important;max-height:90vh!important;min-height:280px!important;margin:0!important;padding:0!important;border-radius:12px!important;overflow:hidden!important;box-shadow:0 20px 60px rgba(0,0,0,.35)!important;z-index:1!important;pointer-events:auto!important}' +
            '#bank-connection-modal .monarch-modal-header{position:relative!important;flex-shrink:0!important;display:block!important;padding:20px 25px!important;margin:0!important;border-bottom:1px solid #eee!important;background:#f8f9fa!important;border-radius:12px 12px 0 0!important}' +
            '#bank-connection-modal .monarch-modal-header h3{margin:0!important;padding:0 40px 0 0!important;font-size:20px!important;font-weight:600!important;line-height:1.3!important;color:#333!important}' +
            '#bank-connection-modal #close-bank-modal,#bank-connection-modal .monarch-modal-close{position:absolute!important;top:15px!important;right:15px!important;left:auto!important;width:36px!important;height:36px!important;min-width:36px!important;margin:0!important;padding:0!important;display:flex!important;align-items:center!important;justify-content:center!important;background:transparent!important;border:none!important;border-radius:50%!important;font-size:28px!important;line-height:1!important;color:#999!important;cursor:pointer!important;box-shadow:none!important;z-index:2!important}' +
            '#bank-connection-modal .monarch-modal-toggle{display:flex!important;flex-direction:row!important;flex-wrap:nowrap!important;align-items:stretch!important;width:100%!important;margin:0!important;padding:0!important;border-bottom:1px solid #eee!important;background:#f8f9fa!important}' +
            '#bank-connection-modal .monarch-modal-method-btn{flex:1 1 0!important;width:auto!important;min-width:0!important;max-width:none!important;margin:0!important;padding:15px 20px!important;border:none!important;border-bottom:3px solid transparent!important;border-radius:0!important;background:transparent!important;color:#666!important;font-size:15px!important;font-weight:500!important;line-height:1.3!important;text-align:center!important;cursor:pointer!important;box-shadow:none!important;appearance:none!important;-webkit-appearance:none!important}' +
            '#bank-connection-modal .monarch-modal-method-btn.active{color:#007cba!important;background:#fff!important;border-bottom-color:#007cba!important}' +
            '#bank-connection-modal .monarch-modal-section.monarch-modal-section--hidden{display:none!important}' +
            '#bank-connection-modal .monarch-modal-section:not(.monarch-modal-section--hidden){display:flex!important;flex-direction:column!important;flex:1 1 auto!important;min-height:0!important;overflow:hidden!important;margin:0!important;padding:0!important}' +
            '#bank-connection-modal .monarch-modal-iframe-content .monarch-iframe-container{flex:1 1 auto!important;min-height:0!important;display:flex!important;flex-direction:column!important;width:100%!important;overflow:hidden!important;background:transparent!important}' +
            '#bank-connection-modal #monarch-bank-iframe{flex:1 1 auto!important;width:100%!important;min-height:200px!important;height:100%!important;border:none!important;margin:0!important;padding:0!important;background:#fff!important}' +
            '#bank-connection-modal .monarch-modal-footer{flex-shrink:0!important;display:block!important;width:100%!important;margin:0!important;padding:15px 25px!important;text-align:center!important;border-top:1px solid #eee!important;background:#f8f9fa!important;border-radius:0 0 12px 12px!important}' +
            '#bank-connection-modal #monarch-bank-connected-btn,#bank-connection-modal .monarch-modal-footer .button.alt{width:auto!important;max-width:100%!important;display:inline-block!important;margin:0 auto!important;padding:12px 30px!important;background:#28a745!important;color:#fff!important;border:none!important;border-radius:6px!important;font-size:16px!important;font-weight:600!important;line-height:1.3!important;cursor:pointer!important;box-shadow:none!important;text-align:center!important}' +
            '#bank-connection-modal #modal-manual-section:not(.monarch-modal-section--hidden){min-width:0!important;width:100%!important;align-self:stretch!important;overflow-y:auto!important;overflow-x:hidden!important}' +
            '#bank-connection-modal .monarch-modal-manual-form{flex:1 1 auto!important;min-height:0!important;min-width:0!important;width:100%!important;max-width:100%!important;overflow-y:auto!important;overflow-x:hidden!important;box-sizing:border-box!important;padding:24px 24px 28px 24px!important}' +
            '#bank-connection-modal .monarch-manual-form-inner{max-width:100%!important;width:100%!important;min-width:0!important;margin-left:0!important;margin-right:0!important;box-sizing:border-box!important}' +
            '#bank-connection-modal .monarch-manual-form-inner .form-row{margin-left:0!important;margin-right:0!important;max-width:100%!important;box-sizing:border-box!important}' +
            '#bank-connection-modal .monarch-manual-form-inner .form-row input,#bank-connection-modal .monarch-manual-form-inner .form-row select{box-sizing:border-box!important}';
        if (!$('#monarch-iframe-style').length) {
            $('head').append('<style id="monarch-iframe-style">' + monarchModalCriticalCss + '</style>');
        } else {
            $('#monarch-iframe-style').text(monarchModalCriticalCss);
        }

        // Close modal handler
        $(document).on('click', '#close-bank-modal', function() {
            if (window.monarchIframeMonitor) {
                clearInterval(window.monarchIframeMonitor);
            }
            window.removeEventListener('message', handleBankMessage);
            $('#bank-connection-modal').remove();
            // Reset both new user and returning user buttons
            $('#monarch-connect-bank').prop('disabled', false).text('Connect Bank Account');
            $('#monarch-connect-spinner').hide();
            $('#monarch-reconnect-bank').prop('disabled', false).text('Continue with Bank');
            $('#monarch-reconnect-spinner').hide();
        });

        // Close on overlay click
        $(document).on('click', '.monarch-modal-overlay', function() {
            if (window.monarchIframeMonitor) {
                clearInterval(window.monarchIframeMonitor);
            }
            window.removeEventListener('message', handleBankMessage);
            $('#bank-connection-modal').remove();
            // Reset both new user and returning user buttons
            $('#monarch-connect-bank').prop('disabled', false).text('Connect Bank Account');
            $('#monarch-connect-spinner').hide();
            $('#monarch-reconnect-bank').prop('disabled', false).text('Continue with Bank');
            $('#monarch-reconnect-spinner').hide();
        });

        // Handle "I've Connected My Bank" button
        $(document).on('click', '#monarch-bank-connected-btn', function() {
            $(this).prop('disabled', true).text('Verifying...');
            checkBankConnectionStatus(orgId, 0, window.monarchIsExistingUser);
        });

        // Listen for postMessage from iframe
        window.addEventListener('message', handleBankMessage);

        // Monitor iframe for navigation events
        // When Yodlee clicks Continue, it tries to redirect - we detect this and open success window
        const iframe = document.getElementById('monarch-bank-iframe');
        let redirectDetected = false;
        let iframeLoadCount = 0;

        // Handle iframe load events - each load after the first indicates navigation
        $(iframe).on('load', function() {
            iframeLoadCount++;
            console.log('Iframe load event #' + iframeLoadCount);

            // Skip the first load (initial page load)
            if (iframeLoadCount === 1) {
                console.log('Initial iframe load - Monarch bank linking page');
                return;
            }

            // Second or later load means navigation occurred (Continue was clicked)
            if (!redirectDetected) {
                redirectDetected = true;
                console.log('Navigation detected in iframe - opening success window');

                // Try to check if we can read the URL (same-origin)
                try {
                    const iframeUrl = iframe.contentWindow.location.href;
                    console.log('Iframe navigated to:', iframeUrl);

                    // If it's our callback URL, open success window
                    if (iframeUrl.includes('monarch_bank_callback=1') || iframeUrl.includes('localhost')) {
                        openSuccessWindow(callbackUrl);
                        return;
                    }
                } catch (e) {
                    // CORS blocked - still open success window since navigation happened
                    console.log('CORS blocked, but navigation detected - opening success window');
                }

                // Open success window regardless since Continue was clicked
                openSuccessWindow(callbackUrl);
            }
        });

        // Store reference for cleanup
        window.monarchIframeLoadCount = iframeLoadCount;
    }

    // Show success message inside the modal and verify bank connection
    function openSuccessWindow(callbackUrl) {
        console.log('Bank linking detected, showing success in modal');

        // Get org_id from the callback URL
        const urlParams = new URLSearchParams(callbackUrl.split('?')[1]);
        const orgId = urlParams.get('org_id') || window.monarchCurrentOrgId;

        // Update modal to show success message inside iframe container
        $('.monarch-iframe-container').html(
            '<div class="monarch-success-container">' +
            '<div class="monarch-spinner-large"></div>' +
            '<h4>Bank Linked Successfully!</h4>' +
            '<p>Verifying your bank connection...</p>' +
            '</div>'
        );

        // Add CSS for success container if not already added
        if (!$('#monarch-spinner-style').length) {
            $('head').append('<style id="monarch-spinner-style">' +
                '.monarch-success-container { text-align: center; padding: 60px 20px; background: #f8fff8; border-radius: 8px; }' +
                '.monarch-spinner-large { width: 50px; height: 50px; border: 4px solid #f3f3f3; border-top: 4px solid #28a745; border-radius: 50%; animation: monarch-spin 1s linear infinite; margin: 0 auto 20px; }' +
                '@keyframes monarch-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }' +
                '.monarch-success-container h4 { margin: 0 0 10px; color: #28a745; font-size: 20px; }' +
                '.monarch-success-container p { color: #666; margin: 5px 0; }' +
                '.monarch-success-icon { font-size: 48px; color: #28a745; margin-bottom: 15px; }' +
                '</style>');
        }

        // Hide the "I've Connected My Bank" button since we're auto-verifying
        $('#monarch-bank-connected-btn').hide();

        // Automatically start verifying the bank connection
        setTimeout(function() {
            checkBankConnectionStatus(orgId, 0, window.monarchIsExistingUser);
        }, 1000);
    }

    // Handle messages from Monarch iframe
    // Supports Yodlee FastLink 4 callbacks: onSuccess, onClose, onError, onEvent
    // See: https://developer.yodlee.com/docs/fastlink/4.0/advanced
    function handleBankMessage(event) {
        try {
            // Log all messages for debugging
            console.log('Received postMessage from:', event.origin, 'Data:', event.data);

            // Only accept messages from trusted Monarch/Yodlee domains
            const allowedOrigins = [
                'https://devapi.monarch.is',
                'https://api.monarch.is',
                'https://appsandbox.monarch.is',
                'https://app.monarch.is',
                'https://dag2.yodlee.com',
                'https://fl4.prod.yodlee.com',
                'https://node.yodlee.com',
                'https://fastlink.yodlee.com',
                'https://finapp.yodlee.com',
                'https://aggregation.yodlee.com'
            ];

            const isAllowed = allowedOrigins.some(origin =>
                event.origin.startsWith(origin) ||
                event.origin.includes('yodlee') ||
                event.origin.includes('monarch') ||
                event.origin.includes('envestnet')
            );

            if (!isAllowed) {
                console.log('Message from non-allowed origin, ignoring:', event.origin);
                return;
            }

            console.log('Allowed origin - processing message');

            // Skip focus-related messages
            if (event.data && event.data.action === 'focus') {
                return;
            }

            // Parse message data
            let messageData = event.data;
            if (typeof event.data === 'string') {
                try {
                    messageData = JSON.parse(event.data);
                } catch (e) {
                    // Not JSON, check for specific strings
                    if (event.data.includes('SUCCESS') || event.data.includes('COMPLETE')) {
                        console.log('Bank linking success detected from string message');
                        triggerBankVerification();
                        return;
                    }
                    return;
                }
            }

            if (!messageData || typeof messageData !== 'object') {
                return;
            }

            // =====================================================
            // Handle Yodlee FastLink 4 callback events
            // =====================================================

            // onSuccess callback - account successfully added
            // Contains: providerAccountId, requestId, status='SUCCESS', additionalStatus
            if (messageData.fnToCall === 'onSuccess' ||
                (messageData.status === 'SUCCESS' && messageData.providerAccountId)) {
                console.log('FastLink onSuccess callback received:', messageData);
                console.log('Provider Account ID:', messageData.providerAccountId);
                triggerBankVerification();
                return;
            }

            // onClose callback - user closed or completed flow
            // Contains: action, sites array, status
            if (messageData.fnToCall === 'onClose' || messageData.fnToCall === 'close') {
                console.log('FastLink onClose callback received:', messageData);
                // Check if there are successfully linked sites
                if (messageData.sites && messageData.sites.length > 0) {
                    const successSites = messageData.sites.filter(s => s.status === 'SUCCESS');
                    if (successSites.length > 0) {
                        console.log('Successfully linked sites found:', successSites);
                        triggerBankVerification();
                        return;
                    }
                }
                // User closed without completing - still check in case linking completed
                if (messageData.action !== 'exit' || messageData.status === 'SUCCESS') {
                    triggerBankVerification();
                }
                return;
            }

            // onError callback - error occurred
            if (messageData.fnToCall === 'onError') {
                console.log('FastLink onError callback received:', messageData);
                console.error('FastLink error:', messageData.code, messageData.message);
                // Don't automatically fail - let user retry or use manual
                return;
            }

            // onEvent callback - intermediate status updates
            if (messageData.fnToCall === 'onEvent') {
                console.log('FastLink onEvent callback received:', messageData);
                // Could show progress to user here
                return;
            }

            // =====================================================
            // Handle other message formats (legacy/Monarch-specific)
            // =====================================================

            // Yodlee accountStatus function call
            if (messageData.fnToCall === 'accountStatus') {
                console.log('accountStatus callback received');
                triggerBankVerification();
                return;
            }

            // POST_MESSAGE type from Yodlee
            if (messageData.type === 'POST_MESSAGE') {
                console.log('POST_MESSAGE received, checking for success indicators');
                if (messageData.providerAccountId || messageData.status === 'SUCCESS') {
                    triggerBankVerification();
                }
                return;
            }

            // Sites array present (Yodlee success indicator)
            if (messageData.sites && Array.isArray(messageData.sites)) {
                console.log('Sites array received:', messageData.sites);
                triggerBankVerification();
                return;
            }

            // Provider account linked
            if (messageData.providerAccountId && messageData.providerId) {
                console.log('Provider account linked:', messageData.providerAccountId);
                triggerBankVerification();
                return;
            }

            // Direct PayToken from Monarch
            const payTokenId = messageData.payTokenId || messageData.paytoken_id || messageData.paytokenId || messageData._id;
            if (payTokenId) {
                console.log('Received PayToken ID directly:', payTokenId);
                completeBankConnection(payTokenId);
                return;
            }

            // Exit action
            if (messageData.action === 'exit') {
                console.log('Exit action received, checking bank status...');
                triggerBankVerification();
                return;
            }

            // Handle our own callback page messages (from redirect)
            if (messageData.type === 'MONARCH_BANK_CALLBACK') {
                console.log('Monarch bank callback received:', messageData);

                if (messageData.status === 'SUCCESS') {
                    // Bank linking complete with paytoken - finalize connection
                    if (messageData.paytoken_id) {
                        console.log('Bank linked with paytoken:', messageData.paytoken_id);
                        completeBankConnection(messageData.paytoken_id);
                    } else {
                        // Success without paytoken - trigger verification
                        triggerBankVerification();
                    }
                    return;
                }

                if (messageData.status === 'LANDED') {
                    // User landed on callback page - update UI to show progress
                    console.log('User landed on callback page, waiting for them to click confirm');
                    $('#monarch-bank-connected-btn').text('Waiting for confirmation...');
                    return;
                }
            }

            // Generic success indicators
            if (messageData.type === 'BANK_CONNECTION_SUCCESS' ||
                messageData.success === true ||
                messageData.action === 'bankConnected' ||
                messageData.status === 'SUCCESS') {
                console.log('Bank connection success indicator received');
                triggerBankVerification();
                return;
            }

            console.log('Unhandled message format:', messageData);

        } catch (error) {
            console.error('Error handling postMessage:', error);
        }
    }

    // Trigger bank verification with a small delay
    function triggerBankVerification() {
        $('#monarch-bank-connected-btn').text('Connection Successful! Verifying...').addClass('success-pulse');
        setTimeout(function() {
            if ($('#monarch-bank-connected-btn').length && !$('#monarch-bank-connected-btn').prop('disabled')) {
                $('#monarch-bank-connected-btn').click();
            }
        }, 1500);
    }

    // Check if bank connection was successful
    // This calls the /v1/getlatestpaytoken/[organizationID] endpoint
    // Per Monarch embedded bank linking documentation
    // Includes retry logic since paytoken may take a moment to be available after bank linking
    function checkBankConnectionStatus(orgId, retryCount, isExistingUser) {
        retryCount = retryCount || 0;
        isExistingUser = isExistingUser || false;

        // Always allow retries - user may have just linked their bank in the iframe
        const maxRetries = 5;
        const retryDelay = 3000; // 3 seconds between retries (bank linking can take time)

        console.log('Checking bank connection status for org:', orgId, '(attempt', retryCount + 1, 'of', maxRetries + 1, ')');

        $('#monarch-bank-connected-btn').text('Verifying...' + (retryCount > 0 ? ' (Attempt ' + (retryCount + 1) + ')' : ''));

        $.ajax({
            url: monarch_ach_params.ajax_url,
            method: 'POST',
            data: {
                action: 'monarch_get_latest_paytoken',
                nonce: monarch_ach_params.nonce,
                org_id: orgId
            },
            dataType: 'json',
            success: function(response) {
                console.log('getLatestPayToken response:', response);

                if (response.success && response.data.paytoken_id) {
                    // Successfully retrieved paytoken - bank linking is complete
                    console.log('Bank linked successfully, paytoken:', response.data.paytoken_id);
                    completeBankConnection(response.data.paytoken_id);
                } else {
                    // Check if email is locked to different merchant - STOP immediately, no retries
                    if (response.data && response.data.action === 'email_locked') {
                        alert('ERROR: ' + (response.data.message || 'This email is registered under a different merchant account.') + '\n\nPlease close this window and use a DIFFERENT email address.');
                        $('#bank-connection-modal').remove();
                        // Reset both new user and returning user buttons
                        $('#monarch-connect-bank').prop('disabled', false).text('Connect Bank Account');
                        $('#monarch-connect-spinner').hide();
                        $('#monarch-reconnect-bank').prop('disabled', false).text('Continue with Bank');
                        $('#monarch-reconnect-spinner').hide();
                        return;
                    }

                    // Check if this is a credentials mismatch that requires refresh
                    if (response.data && response.data.action === 'refresh_required') {
                        alert('Your account needs to be reconnected. The page will refresh so you can connect your bank again.');
                        $('#bank-connection-modal').remove();
                        location.reload();
                        return;
                    }

                    // PayToken not found - might need to retry
                    var errorMsg = (response.data && response.data.message) ? response.data.message : (response.data || 'PayToken not found');
                    console.log('PayToken not found:', errorMsg);
                    console.log('Full response object:', JSON.stringify(response, null, 2));

                    if (retryCount < maxRetries) {
                        // Retry after delay - paytoken may not be immediately available
                        console.log('Retrying in', retryDelay, 'ms...');
                        $('#monarch-bank-connected-btn').text('Verifying... Please wait (' + (maxRetries - retryCount) + ' attempts remaining)');
                        setTimeout(function() {
                            checkBankConnectionStatus(orgId, retryCount + 1, isExistingUser);
                        }, retryDelay);
                    } else {
                        // Max retries reached - show error
                        console.error('Max retries reached. Error details:', errorMsg);
                        alert('Bank connection not detected. Please complete the bank linking in the window above, then click the button again.\n\nIf you continue to see this error, try using "Manual Entry" instead.');
                        $('#monarch-bank-connected-btn').prop('disabled', false).text('I\'ve Connected My Bank');
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Error retrieving paytoken:', error);
                console.error('XHR response:', xhr.responseText);

                if (retryCount < maxRetries) {
                    // Retry on error
                    console.log('Request error, retrying in', retryDelay, 'ms...');
                    setTimeout(function() {
                        checkBankConnectionStatus(orgId, retryCount + 1, isExistingUser);
                    }, retryDelay);
                } else {
                    alert('Failed to verify bank connection.\n\nError: ' + error + '\n\nPlease try refreshing the page or using the "Manual Entry" option.');
                    $('#monarch-bank-connected-btn').prop('disabled', false).text('I\'ve Connected My Bank');
                }
            }
        });
    }

    // Complete bank connection after successful linking
    function completeBankConnection(payTokenId) {
        // Show completion message in modal before closing
        $('.monarch-success-container, .monarch-iframe-container').html(
            '<div class="monarch-success-container">' +
            '<div class="monarch-success-checkmark">✓</div>' +
            '<h4>Bank Connected Successfully!</h4>' +
            '<p>Completing setup...</p>' +
            '</div>'
        );

        // Add checkmark style if not already added
        if (!$('#monarch-checkmark-style').length) {
            $('head').append('<style id="monarch-checkmark-style">' +
                '.monarch-success-checkmark { font-size: 60px; color: #28a745; margin-bottom: 15px; }' +
                '</style>');
        }

        $.ajax({
            url: monarch_ach_params.ajax_url,
            method: 'POST',
            data: {
                action: 'monarch_bank_connection_complete',
                nonce: monarch_ach_params.nonce,
                paytoken_id: payTokenId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Update form with connection data
                    $('#monarch_org_id').val(response.data.org_id);
                    $('#monarch_paytoken_id').val(response.data.paytoken_id);
                    $('#monarch_bank_verified').val('true');
                }

                // Store paytoken in form (even if backend had issues)
                $('#monarch_paytoken_id').val(payTokenId);
                $('#monarch_bank_verified').val('true');

                // Clean up and close modal
                if (window.monarchIframeMonitor) {
                    clearInterval(window.monarchIframeMonitor);
                }
                window.removeEventListener('message', handleBankMessage);
                $('#bank-connection-modal').remove();

                // Update UI to show bank is connected (NO page reload)
                showBankConnectedUI();
                ensureAchSelected();
                monarchRemoveQueryParam('monarch_bank_disconnected');
                var oid = ($('#monarch_org_id').val() || window.monarchCurrentOrgId || '').toString();
                var ptid = ($('#monarch_paytoken_id').val() || payTokenId || '').toString();
                $(document.body).trigger('monarch:bank-connected', [{ org_id: oid, paytoken_id: ptid }]);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // Even on error, if we have paytoken, the bank IS connected
                console.log('Connection error but bank is connected. PayToken:', payTokenId);

                // Store paytoken in form
                $('#monarch_paytoken_id').val(payTokenId);
                $('#monarch_bank_verified').val('true');

                // Clean up and close modal
                if (window.monarchIframeMonitor) {
                    clearInterval(window.monarchIframeMonitor);
                }
                window.removeEventListener('message', handleBankMessage);
                $('#bank-connection-modal').remove();

                // Update UI to show bank is connected (NO page reload)
                showBankConnectedUI();
                ensureAchSelected();
                monarchRemoveQueryParam('monarch_bank_disconnected');
                var oid2 = ($('#monarch_org_id').val() || window.monarchCurrentOrgId || '').toString();
                var ptid2 = ($('#monarch_paytoken_id').val() || payTokenId || '').toString();
                $(document.body).trigger('monarch:bank-connected', [{ org_id: oid2, paytoken_id: ptid2 }]);
            }
        });
    }

    // Show bank connected UI
    function showBankConnectedUI() {
        // Always reset button/spinner states (new user + returning user)
        $('#monarch-connect-bank').prop('disabled', false).text('Connect Bank Account');
        $('#monarch-connect-spinner').hide();
        $('#monarch-reconnect-bank').prop('disabled', false).text('Continue with Bank');
        $('#monarch-reconnect-spinner').hide();

        const orgIdVal = $('#monarch_org_id').val() || $('input[name="monarch_org_id"]').val() || '';
        const paytokenVal = $('#monarch_paytoken_id').val() || $('input[name="monarch_paytoken_id"]').val() || '';

        const connectedHtml =
            '<div class="monarch-bank-connected">' +
            '<p><strong>Bank account connected</strong></p>' +
            '<p>Your bank account has been verified. You can now complete your order.</p>' +
            '<p><a href="#" id="monarch-use-different-bank" class="monarch-disconnect-link">Use a different bank account</a></p>' +
            '<input type="hidden" name="monarch_org_id" value="' + orgIdVal + '">' +
            '<input type="hidden" name="monarch_paytoken_id" value="' + paytokenVal + '">' +
            '</div>';

        // New-user checkout form container
        if ($('#monarch-ach-form').length) {
            $('#monarch-ach-form').html(connectedHtml);
            ensureAchSelected();
            return;
        }

        // Returning-user container (paytoken expired flow)
        if ($('.monarch-returning-user').length) {
            $('.monarch-returning-user').html(connectedHtml);
        }
        ensureAchSelected();
    }

    /**
     * Read billing fields from the active checkout form (name= + id fallbacks).
     * Fixes first-load issues when themes render fragments late or use non-standard markup.
     */
    function monarchCollectBillingSnapshot() {
        const $form = $('form.checkout');
        function fieldVal(id, nameAttr) {
            let v = '';
            if ($form.length) {
                const $byName = $form.find('[name="' + nameAttr + '"]');
                if ($byName.length) {
                    v = $byName.val();
                }
            }
            if (v === undefined || v === null || v === '') {
                v = $('#' + id).val();
            }
            if (id === 'billing_state' && (!v || v === '')) {
                v = $('select#billing_state').val() || $('input#billing_state').val() || '';
            }
            return (v || '').toString().trim();
        }
        const snap = {
            first_name: fieldVal('billing_first_name', 'billing_first_name'),
            last_name: fieldVal('billing_last_name', 'billing_last_name'),
            email: fieldVal('billing_email', 'billing_email'),
            company: fieldVal('billing_company', 'billing_company'),
            address_1: fieldVal('billing_address_1', 'billing_address_1'),
            address_2: fieldVal('billing_address_2', 'billing_address_2'),
            city: fieldVal('billing_city', 'billing_city'),
            state: fieldVal('billing_state', 'billing_state'),
            postcode: fieldVal('billing_postcode', 'billing_postcode'),
            country: fieldVal('billing_country', 'billing_country')
        };
        // Block checkout: billing may only exist in wc/store/cart (no classic form fields in DOM).
        if ((!snap.first_name && !snap.email) && window.wp && window.wp.data && window.wp.data.select) {
            try {
                const cartSel = window.wp.data.select('wc/store/cart');
                if (cartSel && typeof cartSel.getCustomerData === 'function') {
                    const b = (cartSel.getCustomerData().billingAddress || {});
                    if (b.first_name || b.email) {
                        return {
                            first_name: (b.first_name || '').toString().trim(),
                            last_name: (b.last_name || '').toString().trim(),
                            email: (b.email || '').toString().trim(),
                            company: (b.company || '').toString().trim(),
                            address_1: (b.address_1 || '').toString().trim(),
                            address_2: (b.address_2 || '').toString().trim(),
                            city: (b.city || '').toString().trim(),
                            state: (b.state || '').toString().trim(),
                            postcode: (b.postcode || '').toString().trim(),
                            country: (b.country || '').toString().trim()
                        };
                    }
                }
            } catch (e) {}
        }
        return snap;
    }

    // Validate customer information
    function validateCustomerInfo() {
        let isValid = true;
        hideError();

        // Clear previous error states
        $('#monarch-ach-form input').removeClass('error');

        const snap = monarchCollectBillingSnapshot();

        // Check billing fields
        if (!snap.first_name || !snap.last_name) {
            showError('Please fill in your billing name above first.');
            return false;
        }

        if (!snap.email) {
            showError('Please fill in your email address above first.');
            return false;
        }

        // Check billing address fields required by Monarch
        if (!snap.address_1) {
            showError('Please fill in your billing address above first.');
            return false;
        }

        if (!snap.city) {
            showError('Please fill in your billing city above first.');
            return false;
        }

        if (!snap.state) {
            showError('Please select your billing state/province above first.');
            return false;
        }

        if (!snap.postcode) {
            showError('Please fill in your billing postcode/ZIP above first.');
            return false;
        }

        // Required field validation
        const requiredFields = [
            {id: 'monarch_phone', message: 'Phone number is required'}
        ];

        requiredFields.forEach(function(field) {
            const $field = $('#' + field.id);
            if (!$field.val() || $field.val().trim() === '') {
                $field.addClass('error');
                if (isValid) {
                    showError(field.message);
                }
                isValid = false;
            }
        });

        if (!isValid) {
            return false;
        }

        // Phone number validation
        const phone = $('#monarch_phone').val().replace(/[^0-9]/g, '');
        if (phone.length < 10) {
            $('#monarch_phone').addClass('error');
            showError('Please enter a valid 10-digit phone number');
            return false;
        }

        return true;
    }

    function showError(message) {
        ensureMonarchNoticeStyles();
        let $errorDiv = $('#monarch-ach-errors');
        // Prevent showing an empty WooCommerce error box (icon + blank space)
        if (!message || !message.toString().trim()) {
            if ($errorDiv.length) {
                $errorDiv.hide().empty();
            }
            return;
        }
        if (!$errorDiv.length) {
            $errorDiv = $('<div id="monarch-ach-errors" class="woocommerce-error monarch-ach-notice monarch-ach-notice--error"></div>');
            // Try to prepend to form, or to bank-connected div, or to payment method
            if ($('#monarch-ach-form').length) {
                $('#monarch-ach-form').prepend($errorDiv);
            } else if ($('.monarch-bank-connected').length) {
                $('.monarch-bank-connected').before($errorDiv);
            } else if ($('.monarch-returning-user').length) {
                $('.monarch-returning-user').before($errorDiv);
            } else {
                $('.payment_method_monarch_ach').append($errorDiv);
            }
        }
        $errorDiv.html(message).show();

        if ($errorDiv.length && $errorDiv.offset()) {
            $('html, body').animate({
                scrollTop: $errorDiv.offset().top - 100
            }, 500);
        }
    }

    // Form validation before checkout submission.
    // WooCommerce uses $form.triggerHandler('checkout_place_order_monarch_ach') — it does NOT bubble, so handlers on
    // document never run. Bind directly on form.checkout and re-bind after update_checkout refreshes fragments.
    function bindMonarchPlaceOrderValidation() {
        $('form.checkout').each(function() {
            var $form = $(this);
            $form.off('checkout_place_order_monarch_ach.monarchAch');
            $form.on('checkout_place_order_monarch_ach.monarchAch', function() {
                var $orgId = $form.find('#monarch_org_id').add($form.find('input[name="monarch_org_id"]'));
                var $payTokenId = $form.find('#monarch_paytoken_id').add($form.find('input[name="monarch_paytoken_id"]'));
                var orgVal = ($orgId.first().val() || '').toString().trim();
                var payVal = ($payTokenId.first().val() || '').toString().trim();
                var hasConnectedUi = $form.find('.monarch-bank-connected').length > 0 || $('.monarch-bank-connected').length > 0;

                if ((!orgVal || !payVal) && !hasConnectedUi) {
                    showError('Please connect your bank account before placing your order.');
                    return false;
                }

                return true;
            });
        });
    }

    $(document.body).on('init_checkout updated_checkout', bindMonarchPlaceOrderValidation);
    bindMonarchPlaceOrderValidation();

    function showSuccess(message, autoHideMs) {
        ensureMonarchNoticeStyles();
        autoHideMs = typeof autoHideMs === 'number' ? autoHideMs : 5000;

        // Reuse the same container, but switch it to a success notice
        let $noticeDiv = $('#monarch-ach-errors');
        if (!$noticeDiv.length) {
            $noticeDiv = $('<div id="monarch-ach-errors" class="woocommerce-message monarch-ach-notice monarch-ach-notice--success"></div>');
            if ($('#monarch-ach-form').length) {
                $('#monarch-ach-form').prepend($noticeDiv);
            } else if ($('.monarch-bank-connected').length) {
                $('.monarch-bank-connected').before($noticeDiv);
            } else if ($('.monarch-returning-user').length) {
                $('.monarch-returning-user').prepend($noticeDiv);
            } else {
                $('.payment_method_monarch_ach').append($noticeDiv);
            }
        } else {
            $noticeDiv
                .removeClass('woocommerce-error monarch-ach-notice--error')
                .addClass('woocommerce-message monarch-ach-notice monarch-ach-notice--success');
        }

        $noticeDiv.html(message).show();

        if ($noticeDiv.length && $noticeDiv.offset()) {
            $('html, body').animate({
                scrollTop: $noticeDiv.offset().top - 100
            }, 500);
        }

        window.clearTimeout(window.monarchNoticeHideTimer);
        window.monarchNoticeHideTimer = window.setTimeout(function() {
            $noticeDiv.fadeOut(200);
        }, autoHideMs);
    }

    function hideError() {
        $('#monarch-ach-errors').hide();
    }

    function ensureMonarchNoticeStyles() {
        if ($('#monarch-ach-notice-style').length) return;
        $('head').append(
            '<style id="monarch-ach-notice-style">' +
            // Align WooCommerce notice icons (avoid overlap)
            '.monarch-ach-notice{position:relative;border-radius:10px;padding:12px 14px 12px 44px;margin:12px 0;line-height:1.4;}' +
            '.monarch-ach-notice::before{position:absolute;left:14px;top:14px;line-height:1;}' +
            '.monarch-ach-notice ul{margin:0;padding-left:18px;}' +
            '.monarch-ach-notice li{margin:0.25em 0;}' +
            '.monarch-ach-notice--success{border-left:4px solid #2e7d32;background:#f1fff3;}' +
            '.monarch-ach-notice--error{border-left:4px solid #b81c23;background:#fff1f1;}' +
            '</style>'
        );
    }

    // Handle Manual Bank Entry submit from modal
    $(document).on('click', '#monarch-manual-submit-modal', function(e) {
        e.preventDefault();

        // Validate bank fields from modal
        if (!validateModalBankFields()) {
            return false;
        }

        const $button = $(this);
        const $spinner = $('#monarch-manual-spinner-modal');

        // Disable button and show spinner
        $button.prop('disabled', true).text('Processing...');
        $spinner.show();

        const snapManual = monarchCollectBillingSnapshot();

        const requestData = {
            action: 'monarch_manual_bank_entry',
            nonce: monarch_ach_params.nonce,
            monarch_phone: ($('#monarch_phone').val() || $('#monarch_phone_block').val() || ''),
            monarch_dob: ($('#monarch_dob').val() || $('#monarch_dob_block').val() || ''),
            monarch_company: (snapManual.company || $('#billing_company').val() || $('#monarch_company').val() || ''),
            bank_name: $('#modal_bank_name').val(),
            routing_number: $('#modal_routing_number').val(),
            account_number: $('#modal_account_number').val(),
            account_type: $('#modal_account_type').val(),
            billing_first_name: snapManual.first_name,
            billing_last_name: snapManual.last_name,
            billing_email: snapManual.email,
            billing_address_1: snapManual.address_1,
            billing_address_2: snapManual.address_2,
            billing_city: snapManual.city,
            billing_state: snapManual.state,
            billing_postcode: snapManual.postcode,
            billing_country: snapManual.country
        };

        console.log('Manual bank entry data:', requestData);

        $.ajax({
            url: monarch_ach_params.ajax_url,
            method: 'POST',
            data: requestData,
            dataType: 'json',
            success: function(response) {
                console.log('Manual bank entry response:', response);
                if (response.success) {
                    // Update form with connection data
                    $('#monarch_org_id').val(response.data.org_id);
                    $('#monarch_paytoken_id').val(response.data.paytoken_id);
                    $('#monarch_bank_verified').val('true');

                    // Close modal and update UI WITHOUT reloading (reload wipes checkout fields)
                    $('#bank-connection-modal').remove();

                    // Show connected UI and ensure ACH is selected
                    showBankConnectedUI();
                    try { ensureAchSelected(); } catch (e) {}
                    try { monarchRemoveQueryParam('monarch_bank_disconnected'); } catch (e) {}
                    $(document.body).trigger('update_checkout');
                    $(document.body).trigger('monarch:bank-connected', [{
                        org_id: response.data.org_id,
                        paytoken_id: response.data.paytoken_id
                    }]);
                    showSuccess('Bank account connected successfully.');
                } else {
                    showModalError(response.data || 'Failed to connect bank account. Please try again.');
                    $button.prop('disabled', false).text('Submit Bank Details');
                    $spinner.hide();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log('Manual bank entry error:', textStatus, errorThrown, jqXHR.responseText);
                showModalError('Connection error: ' + errorThrown);
                $button.prop('disabled', false).text('Submit Bank Details');
                $spinner.hide();
            }
        });
    });

    // Validate bank fields from modal
    function validateModalBankFields() {
        // Clear previous error states
        $('#modal-manual-section input, #modal-manual-section select').removeClass('error');

        const bankName = $('#modal_bank_name').val();
        const routingNumber = $('#modal_routing_number').val();
        const accountNumber = $('#modal_account_number').val();

        if (!bankName || bankName.trim() === '') {
            $('#modal_bank_name').addClass('error');
            showModalError('Bank name is required');
            return false;
        }

        if (!routingNumber || routingNumber.length !== 9) {
            $('#modal_routing_number').addClass('error');
            showModalError('Routing number must be exactly 9 digits');
            return false;
        }

        if (!accountNumber || accountNumber.trim() === '') {
            $('#modal_account_number').addClass('error');
            showModalError('Account number is required');
            return false;
        }

        return true;
    }

    // Show error inside modal
    function showModalError(message) {
        let $errorDiv = $('#monarch-modal-errors');
        if (!$errorDiv.length) {
            $errorDiv = $('<div id="monarch-modal-errors" class="monarch-modal-error"></div>');
            $('.monarch-manual-form-inner').prepend($errorDiv);
        }
        $errorDiv.html(message).show();
    }

    // Handle returning user "Continue with Bank" button
    // This is for users who have org_id but their paytoken expired after a transaction
    function getBillingStateValue() {
        let billingState = $('#billing_state').val() || '';
        if (!billingState) billingState = $('select#billing_state').val() || '';
        if (!billingState) billingState = $('input#billing_state').val() || '';
        return billingState || '';
    }

    /**
     * Org id for Monarch flows — prefer checkout form fields (classic checkout can have duplicate name/id after fragment updates).
     */
    function monarchGetOrgIdFromCheckout() {
        var $form = $('form.checkout');
        var $inputs = $form.length ? $form.find(':input[name="monarch_org_id"]') : $();
        if (!$inputs.length) {
            $inputs = $(':input[name="monarch_org_id"]');
        }
        var v = '';
        $inputs.each(function() {
            var t = (this.value || '').toString().trim();
            if (t) {
                v = t;
                return false;
            }
        });
        if (!v) {
            v = ($('#monarch_org_id').val() || '').toString().trim();
        }
        return v;
    }

    function collectReturningUserProfile() {
        return {
            first_name: ($('#billing_first_name').val() || '').toString().trim(),
            last_name: ($('#billing_last_name').val() || '').toString().trim(),
            phone: ($('#billing_phone').val() || '').toString().trim(),
            companyName: ($('#billing_company').val() || '').toString().trim(),
            add1: ($('#billing_address_1').val() || '').toString().trim(),
            add2: ($('#billing_address_2').val() || '').toString().trim(),
            city: ($('#billing_city').val() || '').toString().trim(),
            state: (getBillingStateValue() || '').toString().trim(),
            zip: ($('#billing_postcode').val() || '').toString().trim(),
            country: ($('#billing_country').val() || '').toString().trim(),
            // Optional: only present on new-user flow, but safe to read
            dob: ($('#monarch_dob').val() || '').toString().trim()
        };
    }

    function hasProfileChanged(baseline, current) {
        if (!baseline) return false;
        const keys = Object.keys(current || {});
        for (let i = 0; i < keys.length; i++) {
            const k = keys[i];
            if ((baseline[k] || '') !== (current[k] || '')) return true;
        }
        return false;
    }

    function buildUpdateOrganizationPayload(orgId) {
        const p = collectReturningUserProfile();
        const payload = {
            action: 'monarch_update_organization',
            nonce: monarch_ach_params.nonce,
            org_id: orgId,
            first_name: p.first_name,
            last_name: p.last_name,
            phone: p.phone,
            companyName: p.companyName,
            add1: p.add1,
            add2: p.add2,
            city: p.city,
            dob: p.dob,
            state: p.state,
            zip: p.zip,
            country: p.country,
            heloxPushNotificationEnable: true
        };
        return payload;
    }

    // Capture an initial baseline snapshot (best-effort) for change detection
    setTimeout(function() {
        if (!window.monarchReturningProfileBaseline) {
            window.monarchReturningProfileBaseline = collectReturningUserProfile();
        }
    }, 0);

    $(document).on('click', '#monarch-reconnect-bank', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $button = $(this);
        const $spinner = $('#monarch-reconnect-spinner');
        const orgId = monarchGetOrgIdFromCheckout();

        if (typeof monarch_ach_params === 'undefined' || !monarch_ach_params.ajax_url) {
            showError('Payment scripts did not load. Please refresh the page and try again.');
            return;
        }

        if (!orgId) {
            showError('Organization ID not found. Please refresh the page.');
            return;
        }

        // If this returning user is already linked to a Monarch purchaser email, don't allow
        // reconnecting the bank under a different billing email (it will still charge the old purchaser).
        const billingEmailRaw = (
            $('#billing_email').val() ||
            $('input#billing_email').val() ||
            $('input[name="billing_email"]').val() ||
            $('#monarch_billing_email').val() ||
            (typeof monarch_ach_params !== 'undefined' ? (monarch_ach_params.customer_email || '') : '') ||
            ''
        );
        const billingEmail = normalizeEmail(billingEmailRaw);
        const registeredEmail = (typeof monarch_ach_params !== 'undefined') ? normalizeEmail(monarch_ach_params.monarch_registered_email) : '';

        if (registeredEmail && billingEmail && registeredEmail !== billingEmail) {
            showEmailMismatchNotice(monarch_ach_params.monarch_registered_email, billingEmailRaw);
            return;
        }

        // Disable button and show spinner
        $button.prop('disabled', true).text('Connecting...');
        $spinner.show();

        const currentProfile = collectReturningUserProfile();
        const baselineProfile = window.monarchReturningProfileBaseline;
        const needsUpdate = (!baselineProfile) || hasProfileChanged(baselineProfile, currentProfile);

        const proceedToBankLinking = function() {
            // For returning users, we need to get a new paytoken
            // Call getLatestPayToken to get the bank linking URL or paytoken
            $.ajax({
                url: monarch_ach_params.ajax_url,
                method: 'POST',
                data: {
                    action: 'monarch_get_bank_linking_url',
                    nonce: monarch_ach_params.nonce,
                    org_id: orgId,
                    // Needed for guest / logged-out returning customers
                    billing_email: billingEmailRaw
                },
                dataType: 'json',
                success: function(response) {
                    
                    if (response.success && response.data.bank_linking_url) {
                        // Hide spinner before opening bank linking modal
                        $spinner.hide();
                        // Open bank linking modal - this is for existing/returning users
                        openBankConnectionWindow(response.data.bank_linking_url, orgId, true);
                    } else if (response.success && response.data.paytoken_id) {
                        // User already has a valid paytoken (rare case)
                        $('#monarch_paytoken_id').val(response.data.paytoken_id);
                        location.reload();
                    } else if (!response.success && response.data && response.data.action === 'missing_email') {
                        // Need billing email to fetch bank linking URL (guest / logged-out returning user)
                        showError(response.data.message || 'Please enter your billing email and try again.');
                        const $emailField = $('#billing_email').length ? $('#billing_email') : $('input[name="billing_email"]');
                        if ($emailField.length) {
                            $('html, body').animate({ scrollTop: $emailField.offset().top - 120 }, 400);
                            $emailField.trigger('focus');
                        }
                        $button.prop('disabled', false).text('Continue with Bank');
                        $spinner.hide();
                    } else if (!response.success && response.data && response.data.action === 'email_mismatch') {
                        // Prefer the single styled notice (prevents duplicate/blank notice areas)
                        const reg = (typeof monarch_ach_params !== 'undefined' && monarch_ach_params.monarch_registered_email) ? monarch_ach_params.monarch_registered_email : '';
                        const bill = billingEmailRaw;
                        if (reg && bill) {
                            showEmailMismatchNotice(reg, bill);
                        } else {
                            showError(response.data.message || 'Billing email does not match your connected bank profile.');
                        }
                        $button.prop('disabled', false).text('Continue with Bank');
                        $spinner.hide();
                    } else if (!response.success && response.data && response.data.action === 'show_registration_form') {
                        // Bank connection expired - need to re-register
                        // Reload the page to show the registration form (data has been cleared server-side)
                        showError(response.data.message || 'Please complete registration to reconnect your bank.');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        var errorMsg = (response.data && response.data.message) ? response.data.message : (response.data || 'Failed to get bank linking URL. Please try again.');
                        showError(errorMsg);
                        $button.prop('disabled', false).text('Continue with Bank');
                        $spinner.hide();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    showError('Connection error: ' + errorThrown);
                    $button.prop('disabled', false).text('Continue with Bank');
                    $spinner.hide();
                }
            });
        };

        // If customer changed name/address/etc (excluding email), update org profile before bank linking.
        // Best-effort: if update fails, stop and show error (so customer can retry).
        if (needsUpdate) {
            $.ajax({
                url: monarch_ach_params.ajax_url,
                method: 'POST',
                data: buildUpdateOrganizationPayload(orgId),
                dataType: 'json',
                success: function(updateResp) {
                    if (updateResp && updateResp.success) {
                        // Update baseline so repeat clicks don't re-update unless customer changes again
                        window.monarchReturningProfileBaseline = currentProfile;
                        proceedToBankLinking();
                    } else {
                        const msg = (updateResp && updateResp.data && updateResp.data.message) ? updateResp.data.message : (updateResp && updateResp.data ? updateResp.data : 'Failed to update details. Please try again.');
                        showError(msg);
                        $button.prop('disabled', false).text('Continue with Bank');
                        $spinner.hide();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    showError('Connection error: ' + errorThrown);
                    $button.prop('disabled', false).text('Continue with Bank');
                    $spinner.hide();
                }
            });
        } else {
            proceedToBankLinking();
        }
    });

    // Handle "Use a different bank account" for returning users
    $(document).on('click', '#monarch-use-different-bank', function(e) {
        e.preventDefault();

        if (!confirm('This will disconnect your current bank account. You will need to go through the full setup again. Continue?')) {
            return;
        }

        const $link = $(this);
        $link.text('Disconnecting...').css('pointer-events', 'none');

        $.ajax({
            url: monarch_ach_params.ajax_url,
            method: 'POST',
            data: {
                action: 'monarch_disconnect_bank',
                nonce: monarch_ach_params.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Force a clean checkout state after disconnect.
                    const redirectUrl =
                        (response.data && response.data.redirect_url) ? response.data.redirect_url :
                        ((typeof monarch_ach_params !== 'undefined' && monarch_ach_params.checkout_url) ? (monarch_ach_params.checkout_url + '?monarch_bank_disconnected=1') : (window.location.href));
                    window.location.href = redirectUrl;
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : (response.data || 'Failed to disconnect bank account');
                    showError(msg);
                    $link.text('Use a different bank account').css('pointer-events', 'auto');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                showError('Connection error: ' + errorThrown);
                $link.text('Use a different bank account').css('pointer-events', 'auto');
            }
        });
    });

    // Handle disconnect bank account click
    $(document).on('click', '#monarch-disconnect-bank', function(e) {
        e.preventDefault();
        console.log('Disconnect bank clicked');

        if (!confirm('Are you sure you want to disconnect your bank account? You will need to connect again.')) {
            return;
        }

        const $link = $(this);
        $link.text('Disconnecting...').css('pointer-events', 'none');

        console.log('Sending disconnect request to:', monarch_ach_params.ajax_url);

        $.ajax({
            url: monarch_ach_params.ajax_url,
            method: 'POST',
            data: {
                action: 'monarch_disconnect_bank',
                nonce: monarch_ach_params.nonce
            },
            dataType: 'json',
            success: function(response) {
                console.log('Disconnect response:', response);
                if (response.success) {
                    // Force a clean checkout state after disconnect.
                    const redirectUrl =
                        (response.data && response.data.redirect_url) ? response.data.redirect_url :
                        ((typeof monarch_ach_params !== 'undefined' && monarch_ach_params.checkout_url) ? (monarch_ach_params.checkout_url + '?monarch_bank_disconnected=1') : (window.location.href));
                    window.location.href = redirectUrl;
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : (response.data || 'Failed to disconnect bank account');
                    showError(msg);
                    $link.text('Use a different bank account').css('pointer-events', 'auto');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log('Disconnect error:', textStatus, errorThrown, jqXHR.responseText);
                showError('Connection error: ' + errorThrown);
                $link.text('Use a different bank account').css('pointer-events', 'auto');
            }
        });
    });

    // Auto-fill form with test data in development mode
    if (typeof monarch_ach_params !== 'undefined' && monarch_ach_params.test_mode === 'yes') {
        // Test mode is active - could add debug helpers here
    }

    // Enforce that billing email matches the Monarch registered email when a bank is already connected.
    // This prevents "same purchaser details in sandbox" confusion when customers change billing email after linking.
    $(document).on('change blur keyup', '#billing_email', function() {
        enforceEmailMatchIfConnected();
    });
    // Also re-check on checkout updates (Woo can refresh fragments)
    $(document.body).on('updated_checkout', function() {
        enforceEmailMatchIfConnected();
    });
    // Initial check
    enforceEmailMatchIfConnected();

    // Listen for WooCommerce checkout errors and auto-refresh if bank connection expired
    $(document.body).on('checkout_error', function() {
        // Check if the error message contains "bank connection has expired"
        var errorText = $('.woocommerce-error').text();
        if (errorText.indexOf('bank connection has expired') !== -1 ||
            errorText.indexOf('Please reconnect your bank') !== -1) {
            // Show a message and reload after 2 seconds
            setTimeout(function() {
                location.reload();
            }, 2000);
        }
    });

    // Classic checkout: trigger update_checkout → update_order_review so payment_fields() HTML matches session.
    // Do NOT require ACH to be :checked before the first trigger — WooCommerce may init payment radios AFTER
    // this script's ready() runs, so "ACH selected" was false and we skipped every warmup (no API calls).
    function monarchAchClassicCheckoutPage() {
        if (typeof wc_checkout_params !== 'undefined') {
            return wc_checkout_params.is_checkout === '1';
        }
        try {
            return document.body.classList.contains('woocommerce-checkout') &&
                !!document.querySelector('form.checkout') &&
                !document.body.classList.contains('woocommerce-order-pay');
        } catch (e) {
            return false;
        }
    }

    function monarchAchMonarchGatewayInForm() {
        return $('input[name="payment_method"][value="monarch_ach"]').length > 0 ||
            $('.payment_method_monarch_ach').length > 0;
    }

    function monarchAchTriggerCheckoutUpdate() {
        try {
            $(document.body).trigger('update_checkout');
        } catch (e) {}
    }

    /** True when ?monarch_ach_debug=1 or gateway test mode — enables checkout console warnings. */
    function monarchAchCheckoutDebugEnabled() {
        try {
            if (typeof monarch_ach_params !== 'undefined' && monarch_ach_params.test_mode === 'yes') {
                return true;
            }
            return new URLSearchParams(window.location.search || '').get('monarch_ach_debug') === '1';
        } catch (e) {
            return false;
        }
    }

    (function monarchAchWarnMissingWcCheckoutParams() {
        if (typeof wc_checkout_params !== 'undefined') {
            return;
        }
        if (!monarchAchCheckoutDebugEnabled()) {
            return;
        }
        try {
            if (!document.body.classList.contains('woocommerce-checkout') || !document.querySelector('form.checkout')) {
                return;
            }
            if (!$('input[name="payment_method"][value="monarch_ach"]').length && !$('.payment_method_monarch_ach').length) {
                return;
            }
            console.warn(
                '[Monarch ACH] wc_checkout_params is missing — WooCommerce checkout.js is probably not loaded. ' +
                    '$(body).trigger("update_checkout") will not call update_order_review. ' +
                    'Check that wc-checkout is enqueued and not deferred incorrectly. ' +
                    '(Disable this warning by removing ?monarch_ach_debug=1 or turning off test mode.)'
            );
        } catch (e) {}
    })();

    (function monarchAchClassicFragmentWarmup() {
        if (!monarchAchClassicCheckoutPage() || !monarchAchMonarchGatewayInForm()) {
            return;
        }
        // Staggered: ensures wc_checkout_form has bound handlers and serialize() is meaningful.
        setTimeout(monarchAchTriggerCheckoutUpdate, 0);
        setTimeout(monarchAchTriggerCheckoutUpdate, 120);
        setTimeout(monarchAchTriggerCheckoutUpdate, 450);
        setTimeout(monarchAchTriggerCheckoutUpdate, 1100);
        $(window).on('load', function() {
            setTimeout(monarchAchTriggerCheckoutUpdate, 50);
            setTimeout(monarchAchTriggerCheckoutUpdate, 600);
        });
    })();

    // If init_checkout fires after our listeners attach, nudge one more refresh.
    $(document.body).on('init_checkout', function() {
        if (!monarchAchClassicCheckoutPage() || !monarchAchMonarchGatewayInForm()) {
            return;
        }
        setTimeout(monarchAchTriggerCheckoutUpdate, 200);
    });

    var monarchAchFragmentExtraSyncs = 0;
    var monarchAchMaxFragmentExtraSyncs = 2;

    $(document.body).on('change', 'input[name="payment_method"]', function() {
        if ($(this).val() === 'monarch_ach') {
            monarchAchFragmentExtraSyncs = 0;
        }
    });

    $(document.body).on('updated_checkout', function() {
        if (!monarchAchClassicCheckoutPage() || !monarchAchMonarchGatewayInForm()) {
            return;
        }
        if (monarchAchFragmentExtraSyncs >= monarchAchMaxFragmentExtraSyncs) {
            return;
        }
        monarchAchFragmentExtraSyncs += 1;
        setTimeout(monarchAchTriggerCheckoutUpdate, 450);
    });

    /**
     * Cart & Checkout Blocks: open the same "Connect Your Bank Account" modal as classic checkout.
     */
    window.MonarchAchBankModal = {
        open: function(connectionUrl, orgId, isExistingUser) {
            openBankConnectionWindow(connectionUrl, orgId, !!isExistingUser);
        }
    };
});
