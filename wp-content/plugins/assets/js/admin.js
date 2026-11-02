/**
 * Truvo WooCommerce Gateway - Admin JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';

    // Test API connection functionality
    function initApiTest() {
        const testButton = $('<button type="button" class="button button-secondary truvo-test-api">Test API Connection</button>');
        const resultDiv = $('<div class="truvo-test-result" style="display:none;"></div>');
        
        // Add test button after API key field
        const apiKeyField = $('#woocommerce_truvo_api_key').closest('tr');
        if (apiKeyField.length) {
            const testSection = $('<tr class="truvo-api-test-row"><td colspan="2"><div class="truvo-api-test"><h4>Test API Connection</h4><p>Test your API credentials to ensure they are working correctly.</p></div></td></tr>');
            testSection.find('.truvo-api-test').append(testButton).append(resultDiv);
            apiKeyField.after(testSection);
        }

        // Handle test button click
        testButton.on('click', function() {
            const apiKey = $('#woocommerce_truvo_api_key').val();
            const merchantId = $('#woocommerce_truvo_merchant_id').val();
            const apiEndpoint = $('#woocommerce_truvo_api_endpoint').val();

            if (!apiKey || !merchantId) {
                showTestResult('error', 'Please enter both API Key and Merchant ID before testing.');
                return;
            }

            testButton.prop('disabled', true).text('Testing...');
            resultDiv.hide();

            // Make test request
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'truvo_test_api',
                    api_key: apiKey,
                    merchant_id: merchantId,
                    api_endpoint: apiEndpoint,
                    nonce: $('#_wpnonce').val()
                },
                success: function(response) {
                    if (response.success) {
                        showTestResult('success', 'API connection test successful! Your credentials are working correctly.');
                    } else {
                        showTestResult('error', 'API connection failed: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    showTestResult('error', 'Failed to test API connection. Please try again.');
                },
                complete: function() {
                    testButton.prop('disabled', false).text('Test API Connection');
                }
            });
        });

        function showTestResult(type, message) {
            resultDiv
                .removeClass('success error')
                .addClass(type)
                .text(message)
                .slideDown();
        }
    }

    // Environment change handler
    function initEnvironmentHandler() {
        const environmentField = $('#woocommerce_truvo_environment');
        const endpointField = $('#woocommerce_truvo_api_endpoint');
        
        function updateEndpointPlaceholder() {
            const environment = environmentField.val();
            let placeholder = '';
            
            if (environment === 'live') {
                placeholder = 'https://your-production-domain.com/api/payment/process';
                showEnvironmentNotice('live', 'You are in LIVE mode. Real transactions will be processed and real money will be charged.');
            } else {
                placeholder = 'https://your-sandbox-domain.com/api/payment/process';
                showEnvironmentNotice('sandbox', 'You are in SANDBOX mode. Test transactions only - no real money will be charged.');
            }
            
            endpointField.attr('placeholder', placeholder);
        }
        
        function showEnvironmentNotice(env, message) {
            let notice = $('.truvo-environment-notice');
            if (notice.length === 0) {
                notice = $('<div class="truvo-environment-notice"></div>');
                environmentField.closest('tr').after('<tr class="truvo-environment-notice-row"><td colspan="2"></td></tr>');
                $('.truvo-environment-notice-row td').append(notice);
            }
            
            notice
                .removeClass('live sandbox')
                .addClass(env)
                .html('<strong>Environment:</strong> ' + message);
        }
        
        environmentField.on('change', updateEndpointPlaceholder);
        updateEndpointPlaceholder(); // Initialize
    }

    // Form validation
    function initFormValidation() {
        const form = $('form');
        
        form.on('submit', function() {
            let isValid = true;
            const requiredFields = [
                '#woocommerce_truvo_api_key',
                '#woocommerce_truvo_merchant_id'
            ];
            
            requiredFields.forEach(function(field) {
                const input = $(field);
                if (!input.val().trim()) {
                    input.addClass('error');
                    isValid = false;
                } else {
                    input.removeClass('error');
                }
            });
            
            if (!isValid) {
                alert('Please fill in all required fields.');
                return false;
            }
        });
    }

    // Status indicators
    function initStatusIndicators() {
        const enabledField = $('#woocommerce_truvo_enabled');
        const environmentField = $('#woocommerce_truvo_environment');
        
        function updateStatusIndicator() {
            let indicator = $('.truvo-status-indicator');
            if (indicator.length === 0) {
                const title = $('.wc-settings-sub-title, h2').first();
                indicator = $('<span class="truvo-status-indicator"></span>');
                title.prepend(indicator);
            }
            
            indicator.removeClass('active inactive sandbox');
            
            if (enabledField.is(':checked')) {
                if (environmentField.val() === 'live') {
                    indicator.addClass('active').attr('title', 'Gateway is active in LIVE mode');
                } else {
                    indicator.addClass('sandbox').attr('title', 'Gateway is active in SANDBOX mode');
                }
            } else {
                indicator.addClass('inactive').attr('title', 'Gateway is disabled');
            }
        }
        
        enabledField.on('change', updateStatusIndicator);
        environmentField.on('change', updateStatusIndicator);
        updateStatusIndicator(); // Initialize
    }

    // Initialize all functionality
    if ($('#woocommerce_truvo_enabled').length > 0) {
        initApiTest();
        initEnvironmentHandler();
        initFormValidation();
        initStatusIndicators();
    }
});

// AJAX handler for API test (should be implemented in PHP)
/* 
PHP Implementation needed:
add_action('wp_ajax_truvo_test_api', 'truvo_test_api_callback');

function truvo_test_api_callback() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'truvo_test_nonce')) {
        wp_die('Security check failed');
    }
    
    // Test API connection logic here
    // Return JSON response
}
*/