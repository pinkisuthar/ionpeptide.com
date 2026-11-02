jQuery(document).ready(function($) {
    'use strict';

    console.log('Monarch Admin JS loaded - Version 2.0');
    console.log('monarch_admin_params:', typeof monarch_admin_params !== 'undefined' ? monarch_admin_params : 'NOT DEFINED');

    // Test API connection
    $('#test-api-connection').on('click', function() {
        const $button = $(this);
        const $spinner = $('#test-spinner');
        const $results = $('#test-results');
        const $notice = $('#test-notice');
        
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $results.hide();
        
        $.ajax({
            url: monarch_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'monarch_test_connection',
                nonce: monarch_admin_params.nonce
            },
            success: function(response) {
                if (response.success) {
                    $notice.removeClass('notice-error').addClass('notice-success')
                           .html('<p><strong>Success:</strong> ' + response.data + '</p>');
                } else {
                    $notice.removeClass('notice-success').addClass('notice-error')
                           .html('<p><strong>Error:</strong> ' + response.data + '</p>');
                }
                $results.show();
            },
            error: function() {
                $notice.removeClass('notice-success').addClass('notice-error')
                       .html('<p><strong>Error:</strong> Connection test failed</p>');
                $results.show();
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
    
    // View transaction details
    $(document).on('click', '.view-details', function() {
        console.log('View Details button clicked');
        const transactionId = $(this).data('transaction');
        const $button = $(this);

        console.log('Transaction ID:', transactionId);

        if (typeof monarch_admin_params === 'undefined') {
            alert('Error: Admin params not loaded. Please refresh the page.');
            return;
        }

        // Disable button while loading
        $button.prop('disabled', true).text('Loading...');

        console.log('Making AJAX request to:', monarch_admin_params.ajax_url);

        $.ajax({
            url: monarch_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'monarch_get_transaction_details',
                nonce: monarch_admin_params.nonce,
                transaction_id: transactionId
            },
            success: function(response) {
                console.log('AJAX Response:', response);
                if (response.success) {
                    showTransactionModal(response.data);
                } else {
                    alert('Error: ' + (response.data || 'Failed to load transaction details'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                console.error('Response:', xhr.responseText);
                alert('Error: Failed to load transaction details. Check console for details.');
            },
            complete: function() {
                $button.prop('disabled', false).text('View Details');
            }
        });
    });

    // Show transaction details in a modal
    function showTransactionModal(data) {
        // Remove any existing modal
        $('#monarch-transaction-modal').remove();

        // Build order items HTML
        var orderItemsHtml = '';
        if (data.order_items && data.order_items.length > 0) {
            orderItemsHtml = '<table class="widefat" style="margin-top: 10px;">' +
                '<thead><tr><th>Product</th><th>Qty</th><th>Total</th></tr></thead><tbody>';
            data.order_items.forEach(function(item) {
                orderItemsHtml += '<tr>' +
                    '<td>' + escapeHtml(item.name) + '</td>' +
                    '<td>' + item.quantity + '</td>' +
                    '<td>' + item.total + '</td>' +
                    '</tr>';
            });
            orderItemsHtml += '</tbody></table>';
        }

        // Build modal HTML
        var modalHtml = '<div id="monarch-transaction-modal" class="monarch-modal-overlay">' +
            '<div class="monarch-modal-box">' +
            '<div class="monarch-modal-header">' +
            '<h2>Transaction Details</h2>' +
            '<button type="button" class="monarch-modal-close-btn">&times;</button>' +
            '</div>' +
            '<div class="monarch-modal-body">' +

            // Transaction Info
            '<div class="monarch-detail-section">' +
            '<h3>Transaction Information</h3>' +
            '<table class="form-table">' +
            '<tr><th>Transaction ID:</th><td><code>' + escapeHtml(data.transaction_id) + '</code></td></tr>' +
            '<tr><th>Amount:</th><td><strong>' + data.amount + '</strong></td></tr>' +
            '<tr><th>Status:</th><td><span class="status-badge status-' + escapeHtml(data.status) + '">' + escapeHtml(data.status_label) + '</span></td></tr>' +
            '<tr><th>Date:</th><td>' + escapeHtml(data.created_at) + '</td></tr>' +
            '<tr><th>Currency:</th><td>' + escapeHtml(data.currency) + '</td></tr>' +
            '</table>' +
            '</div>' +

            // Order Info
            '<div class="monarch-detail-section">' +
            '<h3>Order Information</h3>' +
            '<table class="form-table">' +
            '<tr><th>Order ID:</th><td><a href="' + escapeHtml(data.order_edit_url) + '" target="_blank">#' + data.order_id + '</a></td></tr>' +
            '<tr><th>Order Status:</th><td>' + escapeHtml(data.order_status) + '</td></tr>' +
            '<tr><th>Order Total:</th><td>' + data.order_total + '</td></tr>' +
            '</table>' +
            orderItemsHtml +
            '</div>' +

            // Customer Info
            '<div class="monarch-detail-section">' +
            '<h3>Customer Information</h3>' +
            '<table class="form-table">' +
            '<tr><th>Name:</th><td>' + escapeHtml(data.customer_name) + '</td></tr>' +
            '<tr><th>Email:</th><td><a href="mailto:' + escapeHtml(data.customer_email) + '">' + escapeHtml(data.customer_email) + '</a></td></tr>' +
            (data.customer_phone ? '<tr><th>Phone:</th><td>' + escapeHtml(data.customer_phone) + '</td></tr>' : '') +
            '</table>' +
            '</div>' +

            // Billing Address
            '<div class="monarch-detail-section">' +
            '<h3>Billing Address</h3>' +
            '<p>' + data.billing_address + '</p>' +
            '</div>' +

            // Monarch IDs
            '<div class="monarch-detail-section">' +
            '<h3>Monarch Details</h3>' +
            '<table class="form-table">' +
            '<tr><th>Organization ID:</th><td><code style="font-size: 11px;">' + escapeHtml(data.monarch_org_id) + '</code></td></tr>' +
            '<tr><th>PayToken ID:</th><td><code style="font-size: 11px;">' + escapeHtml(data.paytoken_id) + '</code></td></tr>' +
            '</table>' +
            '</div>' +

            '</div>' +
            '<div class="monarch-modal-footer">' +
            '<a href="' + escapeHtml(data.order_edit_url) + '" class="button button-primary" target="_blank">View Order</a> ' +
            '<button type="button" class="button monarch-modal-close-btn">Close</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        // Append modal to body
        $('body').append(modalHtml);

        // Close modal handlers
        $(document).on('click', '.monarch-modal-close-btn', function() {
            $('#monarch-transaction-modal').remove();
        });

        $(document).on('click', '.monarch-modal-overlay', function(e) {
            if ($(e.target).hasClass('monarch-modal-overlay')) {
                $('#monarch-transaction-modal').remove();
            }
        });

        // Close on Escape key
        $(document).on('keydown.monarchModal', function(e) {
            if (e.key === 'Escape') {
                $('#monarch-transaction-modal').remove();
                $(document).off('keydown.monarchModal');
            }
        });
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
    
    // Auto-refresh transaction status
    function refreshTransactionStatus() {
        $('.status-badge.status-pending').each(function() {
            const $this = $(this);
            const $row = $this.closest('tr');
            // Implementation to check and update status
        });
    }
    
    // Refresh every 30 seconds if on transactions page
    if ($('.monarch-admin-section .wp-list-table').length) {
        setInterval(refreshTransactionStatus, 30000);
    }

    // Manual status update button is handled by inline script in the Status Sync tab
    // to ensure it works regardless of external JS loading issues
});