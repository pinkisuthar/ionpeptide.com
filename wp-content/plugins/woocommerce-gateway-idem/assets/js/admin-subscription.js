jQuery(function ($) {
    'use strict';

    const idem_admin = {
        init: function () {
            $('#idem-update-amount').on('click', this.updateAmount);
            $('#idem-update-cycles').on('click', this.updateCycles);
            $('#idem-skip-payment').on('click', this.skipPayment);
        },

        block: function () {
            $('#idem-subscription-actions-container').block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
        },

        unblock: function () {
            $('#idem-subscription-actions-container').unblock();
        },

        getRequestData: function () {
            const container = $('#idem-subscription-actions-container');
            return {
                request_id: container.data('request-id'),
                subscription_id: container.data('subscription-id'),
                nonce: idem_admin_subscription.nonce
            };
        },

        sendRequest: function (action, data) {
            this.block();

            const requestData = this.getRequestData();
            const payload = {
                ...requestData,
                action: 'idem_subscription_action',
                idem_action: action,
                ...data
            };

            $.post(idem_admin_subscription.ajax_url, payload)
                .done(function (response) {
                    if (response.success) {
                        alert(idem_admin_subscription.i18n.action_success);
                        window.location.reload();
                    } else {
                        alert(idem_admin_subscription.i18n.action_error + response.data.message);
                    }
                })
                .fail(function () {
                    alert(idem_admin_subscription.i18n.action_error + 'Request failed.');
                })
                .always(function () {
                    idem_admin.unblock();
                });
        },

        updateAmount: function (e) {
            e.preventDefault();
            const newAmount = prompt(idem_admin_subscription.i18n.prompt_amount);
            if (newAmount) {
                const scope = confirm(idem_admin_subscription.i18n.confirm_scope) ? 'all' : 'next';
                idem_admin.sendRequest('update_amount', { amount: newAmount, scope: scope });
            }
        },

        updateCycles: function (e) {
            e.preventDefault();
            const newCycles = prompt(idem_admin_subscription.i18n.prompt_cycles);
            if (newCycles) {
                idem_admin.sendRequest('update_cycles', { cycles: newCycles });
            }
        },

        skipPayment: function (e) {
            e.preventDefault();
            if (confirm(idem_admin_subscription.i18n.confirm_action)) {
                const extend = confirm(idem_admin_subscription.i18n.confirm_extend);
                idem_admin.sendRequest('skip_payment', { extend: extend });
            }
        }
    };

    idem_admin.init();
});
