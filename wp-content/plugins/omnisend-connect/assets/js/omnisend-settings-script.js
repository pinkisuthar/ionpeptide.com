jQuery(document).ready(function ($) {
    $(document).on({
        ajaxStart: function () {
            $('body').addClass('omnisend-ajax-loading');
        },
        ajaxStop: function () {
            $('body').removeClass('omnisend-ajax-loading');
        }
    });

    handleClick('#ajax__checkout_opt_in_status', function (e) {
        post(
            {
                action: 'omnisend_update_plugin_setting',
                setting_name: 'checkout_opt_in_status',
                setting_value: e.target.checked ? 'enabled' : 'disabled'
            },
            e.target
        );
    });

    handleClick('#ajax__checkout_sms_opt_in_status', function (e) {
        post(
            {
                action: 'omnisend_update_plugin_setting',
                setting_name: 'checkout_sms_opt_in_status',
                setting_value: e.target.checked ? 'enabled' : 'disabled'
            },
            e.target
        );
    });

    handleClick('#ajax__checkout_opt_in_text_submit', function (e) {
        post(
            {
                action: 'omnisend_update_plugin_setting',
                setting_name: 'checkout_opt_in_text',
                setting_value: document.querySelector('#ajax__checkout_opt_in_text').value
            },
            e.target
        );
    });

    handleClick('#ajax__checkout_sms_opt_in_text_submit', function (e) {
        post(
            {
                action: 'omnisend_update_plugin_setting',
                setting_name: 'checkout_sms_opt_in_text',
                setting_value: document.querySelector('#ajax__checkout_sms_opt_in_text').value
            },
            e.target
        );
    });

    handleClick('#ajax__checkout_opt_in_preselected_status', function (e) {
        post(
            {
                action: 'omnisend_update_plugin_setting',
                setting_name: 'checkout_opt_in_preselected_status',
                setting_value: e.target.checked ? 'enabled' : 'disabled'
            },
            e.target
        );
    });

    handleClick('#ajax__contact_tag_status', function (e) {
        post(
            {
                action: 'omnisend_update_plugin_setting',
                setting_name: 'contact_tag_status',
                setting_value: e.target.checked ? 'enabled' : 'disabled'
            },
            e.target
        );
    });

    handleClick('#ajax__contact_tag_submit', function (e) {
        post(
            {
                action: 'omnisend_update_plugin_setting',
                setting_name: 'contact_tag',
                setting_value: document.querySelector('#ajax__contact_tag').value
            },
            e.target
        );
    });

    function post(data, element) {
        element?.setAttribute('disabled', 'disabled');

        return $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: { ...data, _wpnonce: omnisend_settings_script_var.nonce }
        }).always(() => {
            element?.removeAttribute('disabled');
        });
    }

    function handleClick(selector, handler) {
        const element = document.querySelector(selector);

        if (!element) {
            return;
        }

        element.addEventListener('click', handler);
    }
});

function disconnectCurrentSite() {
    if (!confirm('Are you sure you want to disconnect this site from Omnisend?')) {
        return;
    }
    
    const button = document.querySelector('.omnisend-disconnect-button');
    if (!button) {
        console.error('Disconnect button not found');
        return;
    }
    
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Disconnecting...';
    
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'omnisend_disconnect_current_site',
            _wpnonce: omnisend_settings_script_var.nonce
        },
        success: function(response) {
            if (response.success) {
                location.reload(); // Refresh to show disconnected state
            } else {
                throw new Error(response.data || 'Disconnect failed');
            }
        },
        error: function(xhr, status, error) {
            button.disabled = false;
            button.textContent = originalText;
            const errorMessage = xhr.responseJSON?.data || 'Failed to disconnect site. Please try again.';
            alert(errorMessage);
        }
    });
}