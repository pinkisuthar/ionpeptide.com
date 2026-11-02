jQuery(document).ready(function($) {
    'use strict';
    
    let isProcessing = false;
    
    function showMessage(message, type) {
        const messageDiv = $('<div class="wp-payment-message wp-payment-' + type + '">' + message + '</div>');
        $('.wp-payment-form').prepend(messageDiv);
        
        setTimeout(function() {
            messageDiv.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    function validateForm() {
        let isValid = true;
        const requiredFields = [
            '#payment-amount',
            '#payment-email',
            '#cardholder-name',
            '#card-number',
            '#expiry-month',
            '#expiry-year',
            '#cvv'
        ];
        
        requiredFields.forEach(function(field) {
            const $field = $(field);
            if ($field.length && !$field.val().trim()) {
                $field.css('border-color', '#dc3545');
                isValid = false;
            } else if ($field.length) {
                $field.css('border-color', '#ccc');
            }
        });
        
        // Email validation
        const email = $('#payment-email').val();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (email && !emailRegex.test(email)) {
            $('#payment-email').css('border-color', '#dc3545');
            showMessage('Please enter a valid email address', 'error');
            isValid = false;
        }
        
        // Card number validation (basic)
        const cardNumber = $('#card-number').val().replace(/\s/g, '');
        if (cardNumber && (cardNumber.length < 13 || cardNumber.length > 19)) {
            $('#card-number').css('border-color', '#dc3545');
            showMessage('Please enter a valid card number', 'error');
            isValid = false;
        }
        
        // CVV validation
        const cvv = $('#cvv').val();
        if (cvv && (cvv.length < 3 || cvv.length > 4)) {
            $('#cvv').css('border-color', '#dc3545');
            showMessage('Please enter a valid CVV', 'error');
            isValid = false;
        }
        
        return isValid;
    }
    
    function formatCardNumber(cardNumber) {
        return cardNumber.replace(/\s/g, '').replace(/(.{4})/g, '$1 ').trim();
    }
    
    // Format card number as user types
    $(document).on('input', '#card-number', function() {
        const formatted = formatCardNumber($(this).val());
        $(this).val(formatted);
    });
    
    // Only allow numbers for card number and CVV
    $(document).on('input', '#card-number, #cvv', function() {
        this.value = this.value.replace(/[^0-9\s]/g, '');
    });
    
    // Handle payment form submission
    $(document).on('submit', '.wp-payment-form', function(e) {
        e.preventDefault();
        
        if (isProcessing) {
            return;
        }
        
        $('.wp-payment-message').remove();
        
        if (!validateForm()) {
            return;
        }
        
        isProcessing = true;
        const $submitBtn = $('.wp-payment-submit');
        const originalText = $submitBtn.text();
        
        $submitBtn.prop('disabled', true).html('<span class="wp-payment-loading"></span>Processing...');
        
        const formData = {
            action: 'process_payment',
            nonce: wpPayment.nonce,
            amount: $('#payment-amount').val(),
            currency: $('#payment-currency').val() || 'USD',
            email: $('#payment-email').val(),
            cardholder_name: $('#cardholder-name').val(),
            card_number: $('#card-number').val().replace(/\s/g, ''),
            expiry_month: $('#expiry-month').val(),
            expiry_year: $('#expiry-year').val(),
            cvv: $('#cvv').val(),
            payment_method: $('input[name="payment_method"]:checked').val() || 'stripe'
        };
        
        $.ajax({
            url: wpPayment.ajax_url,
            type: 'POST',
            data: formData,
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message || 'Payment processed successfully!', 'success');
                    $('.wp-payment-form')[0].reset();
                } else {
                    showMessage(response.data || 'Payment failed. Please try again.', 'error');
                }
            },
            error: function(xhr, status, error) {
                let message = 'Payment failed. Please try again.';
                if (status === 'timeout') {
                    message = 'Payment request timed out. Please try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.data) {
                    message = xhr.responseJSON.data;
                }
                showMessage(message, 'error');
            },
            complete: function() {
                isProcessing = false;
                $submitBtn.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Initialize payment method tabs if they exist
    $(document).on('click', '.payment-method-tab', function() {
        const method = $(this).data('method');
        $('.payment-method-tab').removeClass('active');
        $(this).addClass('active');
        $('.payment-method-content').hide();
        $('#' + method + '-content').show();
    });
});