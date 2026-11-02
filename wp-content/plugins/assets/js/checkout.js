/**
 * Truvo WooCommerce Gateway - Checkout JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';

    // Card number formatting
    function formatCardNumber(input) {
        // Remove all non-numeric characters
        let value = input.replace(/\D/g, '');
        
        // Add spaces every 4 digits
        value = value.replace(/(\d{4})(?=\d)/g, '$1 ');
        
        return value;
    }

    // Card type detection
    function detectCardType(number) {
        const patterns = {
            visa: /^4/,
            mastercard: /^5[1-5]|^2[2-7]/,
            amex: /^3[47]/,
            discover: /^6(?:011|5)/,
            jcb: /^35/,
            dinersclub: /^3[068]/
        };

        const cleanNumber = number.replace(/\D/g, '');
        
        for (const [type, pattern] of Object.entries(patterns)) {
            if (pattern.test(cleanNumber)) {
                return type;
            }
        }
        
        return null;
    }

    // CVV validation based on card type
    function validateCVV(cvv, cardType) {
        const cleanCVV = cvv.replace(/\D/g, '');
        
        if (cardType === 'amex') {
            return cleanCVV.length === 4;
        } else {
            return cleanCVV.length === 3;
        }
    }

    // Initialize payment form
    function initPaymentForm() {
        const paymentForm = $('#truvo-payment-form');
        if (paymentForm.length === 0) return;

        // Card number formatting and validation
        const cardNumberField = $('#truvo-card-number');
        const cardHolderNameField = $('#truvo-card-holder-name');
        const cardExpiryMonth = $('#truvo-card-expiry-month');
        const cardExpiryYear = $('#truvo-card-expiry-year');
        const cardCVVField = $('#truvo-card-cvv');

        // Format card number input
        cardNumberField.on('input', function() {
            const formatted = formatCardNumber(this.value);
            this.value = formatted;
            
            // Update card type indicator
            const cardType = detectCardType(formatted);
            updateCardTypeIndicator(cardType);
            
            // Update CVV placeholder based on card type
            updateCVVPlaceholder(cardType);
            
            // Auto-advance to expiry month when card number is complete
            if (formatted.replace(/\s/g, '').length >= 16) {
                cardExpiryMonth.focus();
            }
        });

        // CVV input restrictions
        cardCVVField.on('input', function() {
            // Only allow numbers
            this.value = this.value.replace(/\D/g, '');
            
            // Limit length based on card type
            const cardType = detectCardType(cardNumberField.val());
            const maxLength = cardType === 'amex' ? 4 : 3;
            
            if (this.value.length > maxLength) {
                this.value = this.value.slice(0, maxLength);
            }
        });

        // Auto-advance from expiry month to year
        cardExpiryMonth.on('change', function() {
            if (this.value) {
                cardExpiryYear.focus();
            }
        });

        // Auto-advance from expiry year to CVV
        cardExpiryYear.on('change', function() {
            if (this.value) {
                cardCVVField.focus();
            }
        });

        // Real-time validation
        const fields = {
            cardHolderName: cardHolderNameField,
            cardNumber: cardNumberField,
            expiryMonth: cardExpiryMonth,
            expiryYear: cardExpiryYear,
            cvv: cardCVVField
        };

        Object.entries(fields).forEach(([key, field]) => {
            field.on('blur', function() {
                validateField(key, field);
            });
        });

        // Form submission validation
        $('form.checkout').on('checkout_place_order_truvo', function() {
            return validateAllFields();
        });
    }

    // Update card type indicator
    function updateCardTypeIndicator(cardType) {
        let indicator = $('.truvo-card-type');
        if (indicator.length === 0) {
            indicator = $('<div class="truvo-card-type"></div>');
            $('#truvo-card-number').parent().addClass('truvo-card-number-wrapper').append(indicator);
        }

        indicator.removeClass('visa mastercard amex discover jcb dinersclub');
        
        if (cardType) {
            indicator.addClass(cardType).attr('title', cardType.toUpperCase());
        }
    }

    // Update CVV placeholder based on card type
    function updateCVVPlaceholder(cardType) {
        const cvvField = $('#truvo-card-cvv');
        const placeholder = cardType === 'amex' ? '1234' : '123';
        const maxLength = cardType === 'amex' ? 4 : 3;
        
        cvvField.attr('placeholder', placeholder).attr('maxlength', maxLength);
    }

    // Field validation
    function validateField(fieldType, field) {
        const value = field.val().trim();
        let isValid = true;
        let errorMessage = '';

        switch (fieldType) {
            case 'cardHolderName':
                if (!value) {
                    isValid = false;
                    errorMessage = 'Cardholder name is required';
                } else if (value.length < 2) {
                    isValid = false;
                    errorMessage = 'Please enter a valid name';
                }
                break;

            case 'cardNumber':
                const cleanNumber = value.replace(/\s/g, '');
                if (!cleanNumber) {
                    isValid = false;
                    errorMessage = 'Card number is required';
                } else if (cleanNumber.length < 13 || cleanNumber.length > 19) {
                    isValid = false;
                    errorMessage = 'Please enter a valid card number';
                } else if (!luhnCheck(cleanNumber)) {
                    isValid = false;
                    errorMessage = 'Please enter a valid card number';
                }
                break;

            case 'expiryMonth':
                if (!value) {
                    isValid = false;
                    errorMessage = 'Expiry month is required';
                }
                break;

            case 'expiryYear':
                if (!value) {
                    isValid = false;
                    errorMessage = 'Expiry year is required';
                } else {
                    // Check if card is expired
                    const currentDate = new Date();
                    const currentYear = currentDate.getFullYear();
                    const currentMonth = currentDate.getMonth() + 1;
                    const expiryMonth = parseInt($('#truvo-card-expiry-month').val());
                    const expiryYear = parseInt(value);
                    
                    if (expiryYear < currentYear || (expiryYear === currentYear && expiryMonth < currentMonth)) {
                        isValid = false;
                        errorMessage = 'Card has expired';
                    }
                }
                break;

            case 'cvv':
                const cardType = detectCardType($('#truvo-card-number').val());
                if (!value) {
                    isValid = false;
                    errorMessage = 'CVV is required';
                } else if (!validateCVV(value, cardType)) {
                    isValid = false;
                    errorMessage = cardType === 'amex' ? 'CVV must be 4 digits' : 'CVV must be 3 digits';
                }
                break;
        }

        if (isValid) {
            field.removeClass('error');
            removeFieldError(field);
        } else {
            field.addClass('error');
            showFieldError(field, errorMessage);
        }

        return isValid;
    }

    // Validate all fields
    function validateAllFields() {
        const fields = {
            cardHolderName: $('#truvo-card-holder-name'),
            cardNumber: $('#truvo-card-number'),
            expiryMonth: $('#truvo-card-expiry-month'),
            expiryYear: $('#truvo-card-expiry-year'),
            cvv: $('#truvo-card-cvv')
        };

        let allValid = true;

        Object.entries(fields).forEach(([key, field]) => {
            if (!validateField(key, field)) {
                allValid = false;
            }
        });

        if (allValid) {
            showProcessingState();
        }

        return allValid;
    }

    // Luhn algorithm for card number validation
    function luhnCheck(cardNumber) {
        let sum = 0;
        let alternate = false;
        
        for (let i = cardNumber.length - 1; i >= 0; i--) {
            let n = parseInt(cardNumber.charAt(i), 10);
            
            if (alternate) {
                n *= 2;
                if (n > 9) {
                    n = (n % 10) + 1;
                }
            }
            
            sum += n;
            alternate = !alternate;
        }
        
        return (sum % 10) === 0;
    }

    // Show field error
    function showFieldError(field, message) {
        removeFieldError(field);
        const error = $('<div class="truvo-field-error" style="color: #dc3545; font-size: 12px; margin-top: 5px;">' + message + '</div>');
        field.after(error);
    }

    // Remove field error
    function removeFieldError(field) {
        field.siblings('.truvo-field-error').remove();
    }

    // Show processing state
    function showProcessingState() {
        const paymentForm = $('#truvo-payment-form');
        paymentForm.addClass('processing');
        
        const spinner = $('<div class="truvo-loading-spinner active"><div class="truvo-spinner"></div><p>Processing payment...</p></div>');
        paymentForm.append(spinner);
    }

    // Initialize everything when Truvo gateway is selected
    function initWhenSelected() {
        if ($('input[name="payment_method"]:checked').val() === 'truvo') {
            initPaymentForm();
        }
    }

    // Handle payment method change
    $('body').on('change', 'input[name="payment_method"]', function() {
        if ($(this).val() === 'truvo') {
            setTimeout(initPaymentForm, 100);
        }
    });

    // Initialize on page load
    initWhenSelected();
    
    // Re-initialize after checkout update
    $('body').on('updated_checkout', function() {
        setTimeout(initWhenSelected, 500);
    });

    // Handle 3DS popup on checkout page
    if (window.location.search.indexOf('truvo_3ds=1') > -1) {
        var urlParams = new URLSearchParams(window.location.search);
        var orderId = urlParams.get('order_id');
        var transactionId = urlParams.get('transaction_id');
        
        // Show loading message
        $('body').html('<div style="text-align:center;padding:50px;font-family:Arial,sans-serif;"><h2>Processing 3D Secure Authentication...</h2><p>Please complete the verification in the popup window.</p><div id="truvo-status"></div></div>');
        
        // Get 3DS URL from session via AJAX
        $.post(truvo_params.ajax_url, {
            action: 'truvo_get_3ds_url',
            order_id: orderId,
            security: truvo_params.nonce
        }, function(response) {
            if (response.success && response.data.url) {
                // Open 3DS popup
                var popup = window.open(
                    response.data.url,
                    'truvo_3ds',
                    'width=600,height=700,scrollbars=yes,resizable=yes,status=yes,toolbar=no,menubar=no,location=no'
                );
                
                // Check if popup is blocked
                if (!popup) {
                    $('#truvo-status').html('<p style="color:red;">Popup blocked! Please allow popups and <a href="javascript:location.reload()">try again</a>.</p>');
                    return;
                }
                
                // Poll for popup completion
                var pollTimer = setInterval(function() {
                    try {
                        if (popup.closed) {
                            clearInterval(pollTimer);
                            // Check payment status
                            checkPaymentStatus(orderId, transactionId);
                        }
                    } catch(e) {
                        // Cross-origin access error is expected
                    }
                }, 1000);
                
                // Timeout after 10 minutes
                setTimeout(function() {
                    if (!popup.closed) {
                        clearInterval(pollTimer);
                        popup.close();
                        $('#truvo-status').html('<p style="color:red;">3D Secure timeout. Please <a href="javascript:location.reload()">try again</a>.</p>');
                    }
                }, 600000);
                
            } else {
                $('#truvo-status').html('<p style="color:red;">Error: Could not retrieve 3D Secure URL. <a href="javascript:history.back()">Go back</a></p>');
            }
        }).fail(function() {
            $('#truvo-status').html('<p style="color:red;">Network error. <a href="javascript:location.reload()">Try again</a></p>');
        });
    }

    // Function to check payment status after 3DS completion
    function checkPaymentStatus(orderId, transactionId) {
        $('#truvo-status').html('<p>Checking payment status...</p>');
        
        $.post(truvo_params.ajax_url, {
            action: 'truvo_check_payment_status',
            order_id: orderId,
            transaction_id: transactionId,
            security: truvo_params.nonce
        }, function(response) {
            if (response.success) {
                if (response.data.status === 'completed') {
                    $('#truvo-status').html('<p style="color:green;">✓ Payment completed successfully! Redirecting...</p>');
                    setTimeout(function() {
                        window.location.href = response.data.redirect_url;
                    }, 2000);
                } else if (response.data.status === 'failed') {
                    $('#truvo-status').html('<p style="color:red;">✗ Payment failed. Redirecting...</p>');
                    setTimeout(function() {
                        // Fallback to checkout URL if no redirect URL provided
                        var fallbackUrl = window.location.origin + '/checkout';
                        window.location.href = response.data.redirect_url || fallbackUrl;
                    }, 2000);
                } else {
                    $('#truvo-status').html('<p style="color:orange;">Payment status unclear. <a href="' + (response.data.redirect_url || window.location.origin + '/checkout') + '">Continue</a></p>');
                }
            } else {
                $('#truvo-status').html('<p style="color:red;">Error checking payment status: ' + (response.data || 'Unknown error') + '. <a href="javascript:history.back()">Go back</a></p>');
            }
        }).fail(function() {
            $('#truvo-status').html('<p style="color:red;">Network error. <a href="javascript:history.back()">Try again</a></p>');
        });
    }
});