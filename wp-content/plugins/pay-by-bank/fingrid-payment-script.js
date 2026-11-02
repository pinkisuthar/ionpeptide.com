let isFingridSubmitting = false;
document.addEventListener('DOMContentLoaded', function () {
    // Use MutationObserver to detect the submit button
    const observer = new MutationObserver((mutations, obs) => {
        const submitButton =
            getCheckoutType() === 'blocks'
                ? document.querySelector('.wc-block-components-checkout-place-order-button')
                : document.querySelector('#place_order');
        if (submitButton) {
            setupSubmitButtonListener(submitButton);
            obs.disconnect(); // Stop observing once the button is found
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });

    // Function to set up the submit button listener
    function setupSubmitButtonListener(submitButton) {
        // const $checkoutForm = document.getElementsByClassName('wc-block-components-form wc-block-checkout__form') || document.getElementsByName('checkout');

        function handlePlaceOrder(e) {

            if (isFingridSubmitting) {
                //console.warn('2. Already submitting, ignoring duplicate call');
                return;
            }
            let selectedMethod;

            if (getCheckoutType() === 'blocks') {
                // Block-based checkout
                selectedMethod = document.querySelector(
                    '.wc-block-components-radio-control input[name="radio-control-wc-payment-method-options"]:checked'
                )?.value;
            } else {
                // Classic checkout
                selectedMethod = document.querySelector(
                    'input[name="payment_method"]:checked'
                )?.value;
            }

            if (selectedMethod !== 'my_fingrid_gateway') {
                return; // Let Woo handle other gateways
            }

            const selectedBankOption = document.querySelector(
                'input[name="fingrid_bank_choice"]:checked'
            )?.value;

            // ✅ Case 1: saved bank selected
            if (selectedBankOption && selectedBankOption !== 'new') {
                e.preventDefault();
                e.stopImmediatePropagation();

                jQuery.blockUI({
                    message: '<div><img src="https://res.cloudinary.com/dkrvxw1ql/image/upload/v1748873618/ZKZg_yar7rc.gif" style="height:100px" /></div>',
                });

                jQuery.ajax({
                    url: customGatewayParams.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'fingrid_save_bank_token',
                        fingrid_saved_bank_token: selectedBankOption,
                    },
                    success: function (res) {
                        if (res.success) {
                            console.log('✅ Token saved in session');
                            // 🔑 remove listener to prevent re-intercept
                            submitButton.removeEventListener('click', handlePlaceOrder);
                            // 🔑 submit form directly                           
                            setTimeout(function () {
                                //jQuery('.wc-block-components-checkout-place-order-button').trigger('click');
                                submitForm();
                                jQuery.unblockUI();
                            }, 3000);

                        } else {
                            alert('Failed to save token: ' + res.data.message);
                        }
                    },
                    error: function () {
                        alert('AJAX error while saving token.');
                    },
                });

                return; // stop here
            }

            // ✅ Case 2: new bank (need popup / tokenization)
            if (
                typeof jQuery('input[name="bank_token"]').val() === 'undefined' ||
                jQuery('input[name="bank_token"]').val() === ''
            ) {
                e.preventDefault();
                e.stopImmediatePropagation();

                let email = document.getElementById('email')?.value || document.getElementById('billing_email')?.value || '';
                let fname = '';
                let lname = '';

                const addressSection = document.querySelector(
                    '.wc-block-components-address-card__address-section'
                );
                if (addressSection) {
                    const fullName = addressSection.textContent.trim();
                    const nameParts = fullName.split(/\s+/).filter(Boolean);
                    fname = nameParts[0] || '';
                    lname = nameParts.length > 1 ? nameParts.slice(1).join(' ') : '';
                } else {
                    fname =
                        document.getElementById('shipping-first_name')?.value ||
                        document.getElementById('billing-first_name')?.value ||
                        document.getElementById('billing_first_name')?.value ||
                        '';
                    lname =
                        document.getElementById('shipping-last_name')?.value ||
                        document.getElementById('billing-last_name')?.value ||
                        document.getElementById('billing_last_name')?.value ||
                        '';
                }

                if (!email || !fname || !lname) {
                    alert('Please fill in email, first name, and last name.');
                    return;
                }

                jQuery.blockUI({
                    message:
                        '<div><img src="https://res.cloudinary.com/dkrvxw1ql/image/upload/v1748873618/ZKZg_yar7rc.gif" style="height:100px" /></div>',
                });

                processPaymentToken(email, fname, lname)
                    .then((publicToken) => {
                        console.log('✅ Got public token:', publicToken);
                        // Your popup will eventually call back via postMessage
                        // and you’ll insert hidden fields + resubmit there
                    })
                    .catch((err) => {
                        console.error('❌ Tokenization failed', err);
                        alert('Payment authorization failed, please try again.');
                        jQuery.unblockUI();
                    });
            }
        }

        // attach our fixed handler
        if (getCheckoutType() === 'blocks') {
            submitButton.addEventListener('click', handlePlaceOrder);
        } else {
            const classicForm = document.querySelector('form.woocommerce-checkout');
            if (classicForm) {
                classicForm.addEventListener('submit', handlePlaceOrder);
            }
        }
    }

    function getCheckoutType() {
        if (
            typeof window.wc !== 'undefined' &&
            typeof window.wc.wcBlocksRegistry !== 'undefined' &&
            document.querySelector('.wc-block-components-checkout-place-order-button')
        ) {
            return 'blocks';
        }
        if (document.querySelector('form.woocommerce-checkout')) {
            return 'classic';
        }
        return 'unknown';
    }

    // Function to submit the form programmatically via AJAX
    function submitForm() {

        if (isFingridSubmitting) {
            console.warn('1. Already submitting, ignoring duplicate call');
            return;
        }

        if (getCheckoutType() === 'blocks') {
            // ✅ Trigger blocks checkout button click
            const blockButton = document.querySelector('.wc-block-components-checkout-place-order-button');
            if (blockButton) {
                isFingridSubmitting = true;
                blockButton.click();
            }
        } else {
            // ✅ Trigger WooCommerce classic checkout handler
            const $form = jQuery('form.checkout');
            if ($form.length) {
                isFingridSubmitting = true;
                $form.trigger('submit'); // this runs WC's AJAX checkout
            }
        }
    }

    function removeListeners() {
        const blockButton = document.querySelector('.wc-block-components-checkout-place-order-button');
        if (blockButton) blockButton.removeEventListener('click', handlePlaceOrder);

        const classicButton = document.querySelector('#place_order');
        if (classicButton) classicButton.removeEventListener('click', handlePlaceOrder);
    }

    // Function to initialize and open payment grid
    function initializePaymentGrid(token) {
        if (typeof cabbage !== 'undefined') {
            cabbage.initializeGrid(token);
            cabbage.openGrid(token);
            jQuery.unblockUI();
        } else {
            console.error('Cabbage library is not loaded.');
            jQuery.unblockUI();
        }
    }

    // Process payment token
    function processPaymentToken(email, fname, lname) {
        return new Promise((resolve, reject) => {
            jQuery.ajax({
                url: customGatewayParams.ajax_url,
                type: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                data: {
                    action: 'generate_link_token',
                    email: email,
                    fname: fname,
                    lname: lname,
                },
                success: (response) => {
                    if (response.success && response.data.link_token) {
                        initializePaymentGrid(response.data.link_token);
                        // Resolve in message listener
                    } else {
                        reject(new Error('Failed to generate link token'));
                    }
                },
                error: (xhr, status, error) => {
                    reject(error);
                },
            });
        });
    }


 //Handle payment popup response
     window.addEventListener('message', (event) => {
            try {
                console.log("Incoming event.data:", event.data);
                cabbage.closeGrid();
                const obj = JSON.parse(event.data);
                if (obj.message === 'success' && obj.public_token) {
                    jQuery.blockUI({
                        message: '<div><img src="https://res.cloudinary.com/dkrvxw1ql/image/upload/v1748873618/ZKZg_yar7rc.gif" style="height:100px" /></div>',
                    });

                    jQuery.ajax({
                        url: customGatewayParams.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'exchange_public_token',
                            public_token: obj.public_token,
                            //security: customGatewayParams.nonce
                        },
                        success: (response) => {
                            if (response.success && response.data.bank_token) {
                                console.log('bank_token - ' + response.data.bank_token);

                                // Append bank_token and other fields to the correct form
                                const form = document.querySelector('form.wc-block-checkout__form') || document.querySelector('form');
                                if (form) {

                                    var tokenInput = document.createElement("input");
                                    tokenInput.setAttribute("name", "bank_token");
                                    tokenInput.setAttribute("type", "hidden");
                                    tokenInput.setAttribute("value", response.data.bank_token);
                                    form.appendChild(tokenInput);

                                    //submitForm(); // Submit the form via AJAX
                                    setTimeout(function () {
                                        //jQuery('.wc-block-components-checkout-place-order-button').trigger('click');
                                        submitForm();
                                        jQuery.unblockUI();
                                    }, 2000);


                                } else {
                                    console.error('Form not found for appending bank_token.');
                                    jQuery.unblockUI();
                                    alert('Unable to process payment. Please try again.');
                                }
                            } else {
                                jQuery.unblockUI();
                                alert('Failed to process bank token.');
                            }
                        },
                        error: () => {
                            jQuery.unblockUI();
                            alert('Error processing payment. Please try again.');
                        },
                    });
                } else {
                    jQuery.unblockUI();
                }

            } catch (err) {
                console.error('Error parsing message:', err);
                jQuery.unblockUI();
            }

        });
});
