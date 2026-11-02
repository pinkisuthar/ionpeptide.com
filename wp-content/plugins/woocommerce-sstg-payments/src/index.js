/**
 * SSTG Payments - WooCommerce Checkout Integration
 * Supports both Block Checkout and Classic (legacy) Checkout
 * Uses HPP iframe widget for card tokenization
 */

import { decodeEntities } from '@wordpress/html-entities';
import { useEffect, useRef, useState, useCallback } from '@wordpress/element';
import { select } from '@wordpress/data';

// Detect block checkout
let isBlockCheckout = false;
let alreadyInitialized = false;

const detectBlockCheckout = () => {
    if (alreadyInitialized) return true;
    if (!window?.wc?.wcBlocksRegistry?.getRegisteredBlockComponents) return false;
    const registered = window.wc.wcBlocksRegistry.getRegisteredBlockComponents();
    const hasBlocks = registered && Object.keys(registered).length > 0;
    const blockExists = document.querySelector('div.wp-block-woocommerce-checkout');
    return hasBlocks && !!blockExists;
};

isBlockCheckout = detectBlockCheckout();

/**
 * Block Checkout Integration
 */
function initBlockCheckout() {
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const STORE_KEY = 'wc/store/checkout';
    let settings = getSetting('sstg_payments_data', {});

    const fetchInvoiceData = async (orderId) => {
        try {
            const response = await fetch(sstgPaymentsData.restUrl + 'create-invoice', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-WP-Nonce': sstgPaymentsData.nonce,
                },
                body: JSON.stringify({ order_id: orderId }),
            });
            const json = await response.json();
            if (json && json.success && json.widget_url) {
                return json;
            }
        } catch (err) {
            console.error('Failed to fetch SSTG Payments invoice:', err);
        }
        return null;
    };

    const Content = (props) => {
        const { eventRegistration, emitResponse, billing, components } = props;
        const { onPaymentProcessing } = eventRegistration;

        const [isLoading, setIsLoading] = useState(true);
        const [error, setError] = useState(null);
        const [widgetUrl, setWidgetUrl] = useState(null);
        const [isProcessing, setIsProcessing] = useState(false);
        const [alreadyPaid, setAlreadyPaid] = useState(false);

        const iframeRef = useRef(null);
        const paymentTokenRef = useRef(null);
        const hppTokenRef = useRef(null);
        const paymentCompleteRef = useRef(false);
        const transactionIdRef = useRef(null);
        const invoiceDataRef = useRef(null);
        // Amount (in cents) the current invoice was created for. Used to detect when the
        // cart/order total changes after the invoice was created (e.g. shipping, coupons,
        // ATUM inventory pricing) so we can refresh the invoice instead of charging a stale amount.
        const invoiceAmountRef = useRef(null);

        // Get order ID from URL (for order-pay page)
        const getOrderIdFromUrl = () => {
            const match = window.location.pathname.match(/order-pay\/(\d+)/);
            return match ? match[1] : null;
        };

        // Get order ID from WC checkout store (draft order)
        const getDraftOrderId = () => {
            try {
                return select(STORE_KEY).getOrderId() || null;
            } catch (e) {
                return null;
            }
        };

        // Get the current cart total in cents from the WC cart store.
        // total_price is a string in the store's minor unit (cents).
        const getCurrentCartTotalCents = () => {
            try {
                const totals = select('wc/store/cart').getCartTotals();
                if (totals && totals.total_price != null) {
                    return parseInt(totals.total_price, 10);
                }
            } catch (e) {
                /* cart store not ready */
            }
            return null;
        };

        // Wait for WC to create the draft order
        const waitForDraftOrder = () => {
            return new Promise((resolve, reject) => {
                const timeout = setTimeout(() => {
                    unsubscribe();
                    reject(new Error('Checkout not ready. Please refresh and try again.'));
                }, 15000);

                const unsubscribe = wp.data.subscribe(() => {
                    const id = getDraftOrderId();
                    if (id) {
                        clearTimeout(timeout);
                        unsubscribe();
                        resolve(id);
                    }
                });
            });
        };

        // Fetch invoice on mount
        const loadInvoice = useCallback(async () => {
            setIsLoading(true);
            setError(null);

            try {
                // Order-pay page: get from URL; block checkout: get draft order from WC store
                let orderId = getOrderIdFromUrl() || getDraftOrderId();

                // Draft order may not be ready yet — wait for WC to create it
                if (!orderId) {
                    orderId = await waitForDraftOrder();
                }

                const result = await fetchInvoiceData(orderId);

                if (!result || !result.widget_url) {
                    throw new Error('Failed to initialize payment form.');
                }

                invoiceDataRef.current = result;
                invoiceAmountRef.current = (typeof result.amount === 'number') ? result.amount : null;
                setWidgetUrl(result.widget_url);
                hppTokenRef.current = result.hpp_token;
            } catch (err) {
                setError(err.message || 'Failed to load payment form.');
            } finally {
                setIsLoading(false);
            }
        }, []);

        // Handle postMessage from HPP iframe
        useEffect(() => {
            const handleMessage = (event) => {
                const data = event.data;
                if (!data || typeof data !== 'object') return;

                console.log('HPP Message:', data);

                // Token ready - card has been tokenized
                if (data.type === 'hpp_token_ready') {
                    paymentTokenRef.current = data.paymentToken;
                    hppTokenRef.current = data.hppToken || hppTokenRef.current;

                    // Auto-submit the order
                    const placeOrderButton = document.querySelector(
                        '.wc-block-components-checkout-place-order-button, #place_order'
                    );
                    if (placeOrderButton) {
                        placeOrderButton.click();
                    }
                }

                if (data.type === 'hpp_3ds_redirect') {
                    event.source.postMessage({ type: 'hpp_3ds_ack' }, '*');
                    window.open(data.redirectUrl, 'hpp3ds', 'width=500,height=600');
                }

                // Payment complete
                if (data.type === 'hpp_payment_complete') {
                    paymentCompleteRef.current = true;
                    transactionIdRef.current = data.transactionId;
                    hppTokenRef.current = data.hppToken || hppTokenRef.current;
                    paymentTokenRef.current = data.transactionId || paymentTokenRef.current;

                    if (data.success) {
                        setAlreadyPaid(true);
                        setIsProcessing(true);
                        setTimeout(() => {
                            const placeOrderButton = document.querySelector(
                                '.wc-block-components-checkout-place-order-button, #place_order'
                            );
                            if (placeOrderButton) {
                                placeOrderButton.click();
                            }
                        }, 100);
                    } else {
                        setError(data.error || data.message || 'Payment was not successful.');
                        setIsProcessing(false);
                    }
                }

                // Error
                if (data.type === 'hpp_error') {
                    setError(data.error || 'An error occurred during payment.');
                    setIsProcessing(false);
                }
            };

            window.addEventListener('message', handleMessage);
            return () => window.removeEventListener('message', handleMessage);
        }, []);

        // Fetch invoice on mount
        useEffect(() => {
            loadInvoice();
        }, [loadInvoice]);

        // Keep the invoice in sync with the live cart total. If the total changes after the
        // invoice was created (shipping method, coupon, ATUM inventory pricing, etc.) and the
        // customer has not paid yet, refresh the invoice so the widget charges the correct amount.
        useEffect(() => {
            const unsubscribe = wp.data.subscribe(() => {
                // Don't disrupt a payment that is already completed or in flight.
                if (paymentCompleteRef.current || alreadyPaid || isProcessing) return;
                if (invoiceAmountRef.current == null || !invoiceDataRef.current) return;

                const currentTotal = getCurrentCartTotalCents();
                if (currentTotal == null) return;

                if (currentTotal !== invoiceAmountRef.current) {
                    // Total drifted — invalidate and rebuild the invoice for the new total.
                    invoiceAmountRef.current = null;
                    invoiceDataRef.current = null;
                    loadInvoice();
                }
            });
            return () => unsubscribe();
        }, [loadInvoice, alreadyPaid, isProcessing]);

        // Handle payment processing
        useEffect(() => {
            const detach = onPaymentProcessing(async () => {
                // If already paid, resolve immediately
                if (alreadyPaid && hppTokenRef.current) {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                sstg_payment_token: transactionIdRef.current || paymentTokenRef.current,
                                sstg_hpp_token: hppTokenRef.current,
                                sstg_transaction_id: transactionIdRef.current || '',
                            },
                        },
                    };
                }

                // Payment complete via postMessage
                if (paymentCompleteRef.current && hppTokenRef.current) {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                sstg_payment_token: transactionIdRef.current || paymentTokenRef.current,
                                sstg_hpp_token: hppTokenRef.current,
                                sstg_transaction_id: transactionIdRef.current || '',
                            },
                        },
                    };
                }

                // Token ready (from hpp_token_ready)
                if (paymentTokenRef.current || hppTokenRef.current) {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                sstg_payment_token: paymentTokenRef.current,
                                sstg_hpp_token: hppTokenRef.current,
                                sstg_transaction_id: transactionIdRef.current || '',
                            },
                        },
                    };
                }

                // No invoice yet - try fetching with orderId from checkout store
                if (!invoiceDataRef.current) {
                    const orderId = getDraftOrderId() || getOrderIdFromUrl();
                    if (!orderId) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: 'Order not ready. Please try again.',
                        };
                    }

                    const result = await fetchInvoiceData(orderId);
                    if (!result || !result.widget_url) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: 'Failed to prepare payment. Please try again.',
                        };
                    }

                    invoiceDataRef.current = result;
                    invoiceAmountRef.current = (typeof result.amount === 'number') ? result.amount : null;
                    setWidgetUrl(result.widget_url);
                    hppTokenRef.current = result.hpp_token;
                }

                // Widget is loaded but no token yet - user hasn't completed the form
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: 'Please complete the card details in the payment form.',
                };
            });

            return () => detach();
        }, [onPaymentProcessing, emitResponse, alreadyPaid]);

        // Render
        if (isLoading) {
            return (
                <div className="sstg-payments-loading" style={{ padding: '20px', textAlign: 'center' }}>
                    <p style={{ margin: 0, color: '#6b7280' }}>Loading payment form...</p>
                </div>
            );
        }

        if (error && !widgetUrl) {
            return (
                <div className="sstg-payments-error" style={{ padding: '15px', background: '#fef2f2', borderRadius: '6px', color: '#dc2626' }}>
                    <p style={{ margin: '0 0 10px' }}>{error}</p>
                    <button
                        type="button"
                        onClick={loadInvoice}
                        style={{ padding: '8px 16px', background: '#dc2626', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                    >
                        Try Again
                    </button>
                </div>
            );
        }

        return (
            <div className="sstg-payments-content">
                {settings.description && (
                    <p dangerouslySetInnerHTML={{ __html: decodeEntities(settings.description) }} style={{ marginBottom: '15px' }} />
                )}

                {error && (
                    <div style={{ padding: '10px', background: '#fef2f2', borderRadius: '4px', color: '#dc2626', marginBottom: '15px' }}>
                        {error}
                    </div>
                )}

                {isProcessing && (
                    <div style={{ padding: '10px', background: '#eff6ff', borderRadius: '4px', color: '#2563eb', marginBottom: '15px' }}>
                        Processing payment...
                    </div>
                )}

                <div className="sstg-payments-iframe-wrapper" style={{ border: '1px solid #e5e7eb', borderRadius: '6px', overflow: 'hidden' }}>
                    <iframe
                        ref={iframeRef}
                        src={widgetUrl}
                        style={{ width: '100%', minHeight: '400px', border: 'none', display: 'block' }}
                        title="SSTG Payment Form"
                        allow="payment"
                    />
                </div>
            </div>
        );
    };

    const SSTGPaymentMethod = {
        name: 'sstg_payments',
        label: decodeEntities(settings.title || 'Pay with Card'),
        content: <Content />,
        edit: <Content />,
        canMakePayment: () => true,
        ariaLabel: decodeEntities(settings.title || 'Pay with Card'),
        supports: {
            features: settings.supports || ['products'],
        },
    };

    registerPaymentMethod(SSTGPaymentMethod);
    alreadyInitialized = true;
}

/**
 * Classic Checkout Integration (older WooCommerce without blocks)
 */
if (isBlockCheckout) {
    initBlockCheckout();
} else {
    document.addEventListener('DOMContentLoaded', function () {
        if (!window.sstgPaymentsData) return;

        let widgetActive = false;
        let currentOrderId = null;
        let currentHppToken = null;
        let currentOrderReceivedUrl = null;
        let currentWidgetUrl = null;

        const getOrderIdFromUrl = () => {
            const match = window.location.pathname.match(/order-pay\/(\d+)/);
            return match ? match[1] : null;
        };

        /**
         * Show WC-style checkout error notices
         */
        const showCheckoutErrors = (result) => {
            const $form = jQuery('form.checkout');
            $form.removeClass('processing').unblock();

            // WC returns HTML error messages in the 'messages' field
            if (result.messages) {
                // Use WC's standard error display
                const $notices = jQuery('.woocommerce-notices-wrapper').first();
                if ($notices.length) {
                    $notices.html(result.messages);
                    jQuery('html, body').animate({ scrollTop: $notices.offset().top - 100 }, 500);
                }
            } else {
                const msg = result.message || result.error || 'An error occurred. Please try again.';
                const $notices = jQuery('.woocommerce-notices-wrapper').first();
                if ($notices.length) {
                    $notices.html('<div class="woocommerce-error">' + msg + '</div>');
                    jQuery('html, body').animate({ scrollTop: $notices.offset().top - 100 }, 500);
                }
            }

            // Trigger WC event so other plugins can react
            jQuery(document.body).trigger('checkout_error', [result.messages || '']);
        };

        const showError = (message) => {
            const $notices = jQuery('.woocommerce-notices-wrapper').first();
            if ($notices.length) {
                $notices.html('<div class="woocommerce-error">' + message + '</div>');
                jQuery('html, body').animate({ scrollTop: $notices.offset().top - 100 }, 500);
            }
        };

        /**
         * Show the HPP widget iframe in the container
         */
        const showWidget = (result) => {
            currentWidgetUrl = result.sstg_widget_url;
            currentHppToken = result.sstg_hpp_token;
            currentOrderId = result.sstg_order_id;
            currentOrderReceivedUrl = result.sstg_order_received_url;
            widgetActive = true;

            const container = document.getElementById('sstg-payments-iframe-container');
            if (container) {
                container.innerHTML = '';
                const iframe = document.createElement('iframe');
                iframe.src = currentWidgetUrl;
                iframe.style.cssText = 'width: 100%; min-height: 400px; border: none; display: block;';
                iframe.title = 'SSTG Payment Form';
                iframe.allow = 'payment';

                const wrapper = document.createElement('div');
                wrapper.style.cssText = 'border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;';
                wrapper.appendChild(iframe);
                container.appendChild(wrapper);
            }

            // Unblock the form so customer can interact with widget
            const $form = jQuery('form.checkout');
            $form.removeClass('processing').unblock();
        };

        /**
         * Order-pay page: existing order, use create-invoice REST endpoint
         */
        const isOrderPayPage = !!getOrderIdFromUrl();

        if (isOrderPayPage) {
            // Order-pay page flow — order already exists, use existing REST-based approach
            let orderPayWidgetInit = false;
            let orderPayHppToken = null;
            let orderPayWidgetUrl = null;

            const form = document.querySelector('form#order_review');
            if (form) {
                const placeOrderBtn = form.querySelector('#place_order');
                if (placeOrderBtn) {
                    placeOrderBtn.addEventListener('click', function (event) {
                        // Only handle the click when OUR payment method is selected.
                        // Another gateway may be chosen — let its normal submission proceed.
                        const selected = form.querySelector('input[name="payment_method"]:checked');
                        if (!selected || selected.value !== 'sstg_payments') return;

                        if (orderPayWidgetInit) return; // Widget already shown, let postMessage handle it

                        event.preventDefault();
                        placeOrderBtn.disabled = true;
                        const originalText = placeOrderBtn.innerText;
                        placeOrderBtn.innerText = 'Loading ...';

                        const orderId = getOrderIdFromUrl();
                        fetch(sstgPaymentsData.restUrl + 'create-invoice', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-WP-Nonce': sstgPaymentsData.nonce,
                            },
                            body: JSON.stringify({ order_id: orderId }),
                        })
                            .then((r) => r.json())
                            .then((result) => {
                                if (!result.success || !result.widget_url) {
                                    throw new Error(result.error || 'Failed to initialize payment form.');
                                }

                                orderPayHppToken = result.hpp_token;
                                orderPayWidgetUrl = result.widget_url;
                                orderPayWidgetInit = true;

                                const container = document.getElementById('sstg-payments-iframe-container');
                                if (container) {
                                    container.innerHTML = '';
                                    const iframe = document.createElement('iframe');
                                    iframe.src = orderPayWidgetUrl;
                                    iframe.style.cssText = 'width: 100%; min-height: 400px; border: none; display: block;';
                                    iframe.title = 'SSTG Payment Form';
                                    iframe.allow = 'payment';
                                    const wrapper = document.createElement('div');
                                    wrapper.style.cssText = 'border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;';
                                    wrapper.appendChild(iframe);
                                    container.appendChild(wrapper);
                                }

                                placeOrderBtn.innerText = originalText;
                                placeOrderBtn.disabled = false;
                            })
                            .catch((err) => {
                                showError(err.message || 'Failed to load payment form.');
                                placeOrderBtn.innerText = originalText;
                                placeOrderBtn.disabled = false;
                            });
                    });
                }
            }

            // PostMessage handler for order-pay page
            window.addEventListener('message', function (event) {
                const data = event.data;
                if (!data || typeof data !== 'object') return;

                if (data.type === 'hpp_3ds_redirect') {
                    event.source.postMessage({ type: 'hpp_3ds_ack' }, '*');
                    window.open(data.redirectUrl, 'hpp3ds', 'width=500,height=600');
                }

                if (data.type === 'hpp_payment_complete' && data.success) {
                    // Only act on our own widget's completion. Ignore stray messages
                    // (e.g. a stale iframe left in the DOM after switching to another gateway).
                    if (!orderPayWidgetInit) return;
                    const orderId = getOrderIdFromUrl();
                    fetch(sstgPaymentsData.restUrl + 'complete-order', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': sstgPaymentsData.nonce,
                        },
                        body: JSON.stringify({
                            order_id: orderId,
                            hpp_token: orderPayHppToken || data.hppToken || '',
                            transaction_id: data.transactionId || '',
                        }),
                    })
                        .then((r) => r.json())
                        .then((res) => {
                            window.location = res.redirect_url || window.location.href;
                        })
                        .catch(() => {
                            window.location.reload();
                        });
                }

                if (data.type === 'hpp_payment_complete' && !data.success) {
                    showError(data.error || data.message || 'Payment was not successful.');
                    if (orderPayWidgetUrl) {
                        const iframe = document.querySelector('#sstg-payments-iframe-container iframe');
                        if (iframe) iframe.src = orderPayWidgetUrl;
                    }
                }

                if (data.type === 'hpp_error') {
                    showError(data.error || 'An error occurred during payment.');
                }
            });

            return; // Don't set up classic checkout handler on order-pay page
        }

        /**
         * Classic checkout — order-first pattern
         * Hook into WC's checkout_place_order_{gateway} event
         */
        if (typeof jQuery !== 'undefined') {
            const handler = function () {
                if (widgetActive) return false; // Prevent re-submission while widget showing

                const form = this;
                const $form = jQuery(form);

                // Set order-first flag
                const flagInput = form.querySelector('#sstg_order_first');
                if (flagInput) flagInput.value = '1';

                // Block form (mimics WC's loading overlay)
                $form.addClass('processing').block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });

                // Submit to WC checkout ourselves
                const checkoutUrl = (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.checkout_url)
                    ? wc_checkout_params.checkout_url
                    : '/?wc-ajax=checkout';

                fetch(checkoutUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: $form.serialize(),
                })
                    .then((r) => r.json())
                    .then((result) => {
                        if (result.result === 'success' && result.sstg_widget_url) {
                            // ORDER CREATED — now show widget
                            showWidget(result);
                        } else if (result.result === 'success' && result.redirect) {
                            // Already paid or non-widget redirect
                            window.location = result.redirect;
                        } else {
                            // Validation errors or failure
                            showCheckoutErrors(result);
                        }
                    })
                    .catch((err) => {
                        $form.removeClass('processing').unblock();
                        showError(err.message || 'An error occurred. Please try again.');
                    });

                return false; // Prevent WC's default AJAX
            };

            // Bind to WC's gateway-specific checkout event
            const bindCheckoutEvent = () => {
                jQuery('form.checkout')
                    .off('checkout_place_order_sstg_payments.sstg')
                    .on('checkout_place_order_sstg_payments.sstg', handler);
            };

            bindCheckoutEvent();

            // WC rebuilds form.checkout on AJAX updates (country change, coupon, etc.)
            jQuery(document.body).on('updated_checkout', bindCheckoutEvent);
        }

        // Late block checkout detection — watch for block checkout element to appear
        const blockObserver = new MutationObserver(() => {
            if (detectBlockCheckout()) {
                isBlockCheckout = true;
                initBlockCheckout();
                blockObserver.disconnect();
            }
        });
        blockObserver.observe(document.body, { childList: true, subtree: true });

        /**
         * PostMessage handler for classic checkout widget
         */
        window.addEventListener('message', function (event) {
            const data = event.data;
            if (!data || typeof data !== 'object') return;

            // Handle 3DS redirects
            if (data.type === 'hpp_3ds_redirect') {
                event.source.postMessage({ type: 'hpp_3ds_ack' }, '*');
                window.open(data.redirectUrl, 'hpp3ds', 'width=500,height=600');
            }

            // Payment complete — call complete-order endpoint + redirect
            if (data.type === 'hpp_payment_complete' && data.success && currentOrderId) {
                // Show processing state
                const $form = jQuery('form.checkout');
                $form.addClass('processing').block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });

                fetch(sstgPaymentsData.restUrl + 'complete-order', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': sstgPaymentsData.nonce,
                    },
                    body: JSON.stringify({
                        order_id: currentOrderId,
                        hpp_token: currentHppToken || data.hppToken || '',
                        transaction_id: data.transactionId || '',
                    }),
                })
                    .then((r) => r.json())
                    .then((res) => {
                        window.location = res.redirect_url || currentOrderReceivedUrl;
                    })
                    .catch(() => {
                        // Fallback — webhook will handle payment completion
                        window.location = currentOrderReceivedUrl;
                    });
            }

            // Payment failed in widget
            if (data.type === 'hpp_payment_complete' && !data.success) {
                showError(data.error || data.message || 'Payment was not successful.');
                // Reload iframe so customer can retry
                if (currentWidgetUrl) {
                    const iframe = document.querySelector('#sstg-payments-iframe-container iframe');
                    if (iframe) iframe.src = currentWidgetUrl;
                }
            }

            // Error from widget
            if (data.type === 'hpp_error') {
                showError(data.error || 'An error occurred during payment.');
            }
        });
    });
}
