/**
 * Monarch ACH Payment Method for WooCommerce Blocks Checkout
 * Bank connection uses the same modal as classic checkout (monarch-ach.js → MonarchAchBankModal.open).
 */
(function() {
    'use strict';

    const { registerPaymentMethod } = wc.wcBlocksRegistry;
    const { getSetting } = wc.wcSettings;
    const { createElement, useState, useEffect } = wp.element;
    const { decodeEntities } = wp.htmlEntities;

    const settings = getSetting('monarch_ach_data', {});
    const defaultLabel = 'ACH Bank Transfer';
    const label = decodeEntities(settings.title) || defaultLabel;
    const monarchCheckoutDobDefault = '01/01/2001';

    function monarchFormatPhoneDigits(phone) {
        return (phone || '').replace(/\D/g, '').slice(-10);
    }

    function monarchBuildCreateOrgPayload(settingsObj, phone, billingAddress, dob) {
        const b = billingAddress || {};
        return {
            action: 'monarch_create_organization',
            nonce: settingsObj.nonce,
            monarch_phone: monarchFormatPhoneDigits(phone),
            monarch_dob: dob || monarchCheckoutDobDefault,
            monarch_company: (b.company || '').toString(),
            billing_first_name: (b.first_name || '').toString(),
            billing_last_name: (b.last_name || '').toString(),
            billing_email: (b.email || '').toString(),
            billing_address_1: (b.address_1 || '').toString(),
            billing_address_2: (b.address_2 || '').toString(),
            billing_city: (b.city || '').toString(),
            billing_state: (b.state || '').toString(),
            billing_postcode: (b.postcode || '').toString(),
            billing_country: (b.country || '').toString()
        };
    }

    function monarchBuildUpdateOrgPayload(settingsObj, orgId, phone, dob, billingAddress) {
        const b = billingAddress || {};
        return {
            action: 'monarch_update_organization',
            nonce: settingsObj.nonce,
            org_id: orgId,
            first_name: (b.first_name || '').toString().trim(),
            last_name: (b.last_name || '').toString().trim(),
            phone: monarchFormatPhoneDigits(phone),
            companyName: (b.company || '').toString().trim(),
            add1: (b.address_1 || '').toString().trim(),
            add2: (b.address_2 || '').toString().trim(),
            city: (b.city || '').toString().trim(),
            state: (b.state || '').toString().trim(),
            zip: (b.postcode || '').toString().trim(),
            country: (b.country || '').toString().trim(),
            dob: dob || monarchCheckoutDobDefault,
            heloxPushNotificationEnable: true
        };
    }

    function monarchValidateBlockBilling(customerData) {
        if (!customerData || !customerData.billingAddress) {
            return 'Please complete billing details in the checkout form before connecting your bank.';
        }
        const a = customerData.billingAddress;
        if (!a.first_name || !a.last_name) {
            return 'Please enter your billing first and last name in the checkout form.';
        }
        if (!a.email) {
            return 'Please enter your billing email.';
        }
        if (!a.address_1) {
            return 'Please enter your street address.';
        }
        if (!a.city) {
            return 'Please enter your city.';
        }
        if (!a.state) {
            return 'Please enter your state/province.';
        }
        if (!a.postcode) {
            return 'Please enter your postal code.';
        }
        return '';
    }

    const Content = (props) => {
        const { eventRegistration, emitResponse } = props;
        const { onPaymentSetup } = eventRegistration;

        const [phone, setPhone] = useState('');
        const [bankConnected, setBankConnected] = useState(false);
        const [orgId, setOrgId] = useState('');
        const [paytokenId, setPaytokenId] = useState('');
        const [isProcessing, setIsProcessing] = useState(false);
        const [errorMessage, setErrorMessage] = useState('');

        useEffect(() => {
            try {
                const params = new URLSearchParams(window.location.search || '');
                if (!params.get('monarch_bank_disconnected')) return;

                if (settings.has_saved_bank) {
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.delete('monarch_bank_disconnected');
                        window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '') + (url.hash ? url.hash : ''));
                    } catch (e) {}
                    return;
                }

                if (!document.querySelector('.monarch-bank-disconnected-banner')) {
                    const banner = document.createElement('div');
                    banner.className = 'monarch-bank-disconnected-banner';
                    banner.setAttribute('role', 'alert');
                    banner.setAttribute('aria-live', 'assertive');
                    banner.textContent = 'Your bank account was disconnected. Please complete the checkout form again.';

                    const noticesWrapper = document.querySelector('.woocommerce-notices-wrapper');
                    const checkoutRoot = document.querySelector('.wp-block-woocommerce-checkout') || document.querySelector('body');
                    (noticesWrapper || checkoutRoot).prepend(banner);
                }

                if (window.wp && window.wp.data && window.wp.data.dispatch) {
                    const cartDispatch = window.wp.data.dispatch('wc/store/cart');
                    if (cartDispatch && cartDispatch.setBillingAddress && cartDispatch.setShippingAddress) {
                        cartDispatch.setBillingAddress({});
                        cartDispatch.setShippingAddress({});
                    }
                }

                try {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('monarch_bank_disconnected');
                    window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '') + (url.hash ? url.hash : ''));
                } catch (e) {}
            } catch (e) {}
        }, []);

        // Same modal as classic: fires when manual submit or iframe flow completes (monarch-ach.js).
        useEffect(() => {
            if (!window.jQuery) {
                return undefined;
            }
            const $ = window.jQuery;
            const handler = function(ev, data) {
                if (data && data.org_id && data.paytoken_id) {
                    setOrgId(String(data.org_id));
                    setPaytokenId(String(data.paytoken_id));
                    setBankConnected(true);
                    setErrorMessage('');
                }
            };
            $(document.body).on('monarch:bank-connected', handler);
            return function() {
                $(document.body).off('monarch:bank-connected', handler);
            };
        }, []);

        useEffect(() => {
            if (!bankConnected) return;
            try {
                if (window.wp && window.wp.data && window.wp.data.dispatch) {
                    const payDispatch = window.wp.data.dispatch('wc/store/payment');
                    if (payDispatch) {
                        if (typeof payDispatch.__internalSetActivePaymentMethod === 'function') {
                            payDispatch.__internalSetActivePaymentMethod('monarch_ach');
                        } else if (typeof payDispatch.setActivePaymentMethod === 'function') {
                            payDispatch.setActivePaymentMethod('monarch_ach');
                        }
                    }
                }
            } catch (e) {}
        }, [bankConnected]);

        useEffect(() => {
            if (settings.has_saved_bank) {
                setBankConnected(true);
                setOrgId(settings.saved_org_id || '');
                setPaytokenId(settings.saved_paytoken_id || '');
            }
        }, []);

        useEffect(() => {
            const unsubscribe = onPaymentSetup(() => {
                if (bankConnected && orgId && paytokenId) {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                monarch_org_id: orgId,
                                monarch_paytoken_id: paytokenId,
                                monarch_phone: phone,
                                monarch_dob: monarchCheckoutDobDefault
                            }
                        }
                    };
                }

                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: 'Please connect your bank account before placing an order. Use Connect Bank Account, then complete verification in the popup (automatic or manual).'
                };
            });

            return unsubscribe;
        }, [onPaymentSetup, bankConnected, orgId, paytokenId, phone]);

        const handleConnectBank = () => {
            const phoneDigits = monarchFormatPhoneDigits(phone);
            if (phoneDigits.length < 10) {
                setErrorMessage('Please enter a valid 10-digit phone number.');
                return;
            }

            const customerData = wp.data.select('wc/store/cart').getCustomerData();
            const billingErr = monarchValidateBlockBilling(customerData);
            if (billingErr) {
                setErrorMessage(billingErr);
                return;
            }

            if (typeof window.MonarchAchBankModal === 'undefined' || typeof window.MonarchAchBankModal.open !== 'function') {
                setErrorMessage('Bank connection script failed to load. Please refresh the page.');
                return;
            }

            const billingData = customerData.billingAddress || {};

            setIsProcessing(true);
            setErrorMessage('');

            const payload = monarchBuildCreateOrgPayload(settings, phone, billingData, monarchCheckoutDobDefault);

            jQuery.ajax({
                url: settings.ajax_url,
                type: 'POST',
                data: payload,
                dataType: 'json',
                success: function(response) {
                    setIsProcessing(false);
                    if (!response || !response.success) {
                        const msg = (typeof response.data === 'string')
                            ? response.data
                            : (response.data && response.data.message) ? response.data.message : 'Failed to create organization.';
                        setErrorMessage(msg);
                        return;
                    }
                    const d = response.data || {};
                    if (d.has_bank && d.org_id && d.paytoken_id) {
                        setOrgId(d.org_id);
                        setPaytokenId(d.paytoken_id);
                        setBankConnected(true);
                        return;
                    }
                    const linkUrl = d.bank_linking_url || '';
                    if (!linkUrl) {
                        setErrorMessage('No bank linking URL returned. Please try again.');
                        return;
                    }
                    const organizationId = d.org_id;
                    const existing = !!d.existing_user;
                    setOrgId(organizationId);

                    const openSharedModal = function() {
                        window.MonarchAchBankModal.open(linkUrl, organizationId, existing);
                    };

                    if (existing) {
                        const upPayload = monarchBuildUpdateOrgPayload(settings, organizationId, phone, monarchCheckoutDobDefault, billingData);
                        jQuery.ajax({
                            url: settings.ajax_url,
                            type: 'POST',
                            data: upPayload,
                            dataType: 'json',
                            success: function(upr) {
                                if (upr && upr.success) {
                                    openSharedModal();
                                } else {
                                    const msg = (upr && upr.data)
                                        ? (typeof upr.data === 'string' ? upr.data : (upr.data.message || 'Failed to update profile.'))
                                        : 'Failed to update profile.';
                                    setErrorMessage(msg);
                                }
                            },
                            error: function() {
                                setErrorMessage('Connection error while updating profile. Please try again.');
                            }
                        });
                    } else {
                        openSharedModal();
                    }
                },
                error: function(xhr) {
                    setIsProcessing(false);
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                        setErrorMessage(typeof xhr.responseJSON.data === 'string' ? xhr.responseJSON.data : 'Connection error.');
                    } else {
                        setErrorMessage('Connection error. Please try again.');
                    }
                }
            });
        };

        const handleDisconnect = () => {
            setIsProcessing(true);
            setErrorMessage('');

            jQuery.ajax({
                url: settings.ajax_url,
                type: 'POST',
                data: {
                    action: 'monarch_disconnect_bank',
                    nonce: settings.nonce
                },
                success: function(response) {
                    setIsProcessing(false);
                    if (response && response.success) {
                        const redirectUrl =
                            response.data && response.data.redirect_url ? response.data.redirect_url :
                            (settings.checkout_url ? (settings.checkout_url + '?monarch_bank_disconnected=1') : window.location.href);
                        window.location.href = redirectUrl;
                    } else {
                        setErrorMessage((response && response.data && response.data.message) ? response.data.message : 'Failed to disconnect bank account.');
                    }
                },
                error: function() {
                    setIsProcessing(false);
                    setErrorMessage('Connection error. Please try again.');
                }
            });
        };

        if (bankConnected) {
            return createElement('div', { className: 'monarch-ach-block-form' },
                createElement('div', { style: { padding: '15px', background: '#d4edda', borderRadius: '4px', marginBottom: '10px' } },
                    createElement('strong', null, '✓ Bank account connected'),
                    createElement('br'),
                    createElement('a', {
                        href: '#',
                        onClick: (e) => { e.preventDefault(); handleDisconnect(); },
                        style: { fontSize: '12px' }
                    }, 'Use a different bank account')
                )
            );
        }

        return createElement('div', { className: 'monarch-ach-block-form' },
            errorMessage && createElement('div', {
                style: { padding: '10px', background: '#f8d7da', color: '#721c24', borderRadius: '4px', marginBottom: '10px' }
            }, errorMessage),

            settings.description && createElement('p', null, decodeEntities(settings.description)),

            createElement('p', { className: 'form-row' },
                createElement('label', { htmlFor: 'monarch_phone' }, 'Phone Number ', createElement('span', { className: 'required' }, '*')),
                createElement('input', {
                    type: 'tel',
                    id: 'monarch_phone',
                    name: 'monarch_phone',
                    value: phone,
                    onChange: (e) => setPhone(e.target.value),
                    style: { width: '100%', padding: '8px' }
                })
            ),

            createElement('input', {
                type: 'hidden',
                id: 'monarch_dob',
                name: 'monarch_dob',
                value: monarchCheckoutDobDefault,
                readOnly: true
            }),

            createElement('div', { style: { marginTop: '15px' } },
                createElement('p', { style: { fontSize: '13px', color: '#666', marginBottom: '0.75em' } },
                    'Securely connect your bank account using your online banking credentials, or choose Manual Entry in the popup.'
                ),
                createElement('button', {
                    type: 'button',
                    onClick: handleConnectBank,
                    disabled: isProcessing,
                    className: 'button alt wc-monarch-ach-connect-block',
                    style: {
                        width: '100%',
                        padding: '12px',
                        background: '#0073aa',
                        color: 'white',
                        border: 'none',
                        borderRadius: '4px',
                        cursor: isProcessing ? 'not-allowed' : 'pointer'
                    }
                }, isProcessing ? 'Processing...' : 'Connect Bank Account')
            )
        );
    };

    const Label = (props) => {
        const { PaymentMethodLabel } = props.components;
        return createElement(PaymentMethodLabel, { text: label });
    };

    registerPaymentMethod({
        name: 'monarch_ach',
        label: createElement(Label, null),
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: () => true,
        ariaLabel: label,
        supports: {
            features: settings.supports || ['products']
        }
    });
})();
