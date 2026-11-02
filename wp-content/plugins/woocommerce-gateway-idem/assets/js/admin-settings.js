/**
 * IDEM Payment Gateway - Admin Settings (per-environment "Test API" buttons)
 *
 * Each environment (Sandbox / Production) has its own IDEM API Token field, so a Test API
 * button is placed next to each and tests that environment's credentials. The buttons ride
 * inside their token rows, so they follow the fields when admin-connect.js folds them into tabs.
 */
jQuery(document).ready(function($) {

    function addTestButton(env) {
        var $row = $('#woocommerce_idem_' + env + '_api_token').closest('tr');
        if (!$row.length || $row.find('.idem-test-api').length) {
            return;
        }
        var $btn = $('<button/>', {
            type: 'button',
            'class': 'button idem-test-api',
            text: 'Test API'
        }).attr('data-env', env).css({ marginLeft: '8px' });
        $row.find('td.forminp').append($btn);
    }

    addTestButton('sandbox');
    addTestButton('prod');

    // Auto-run the notification reachability test once, right after a Connect (flag set by
    // admin-connect.js before the page reload).
    try {
        if (sessionStorage.getItem('idem_ipn_autotest')) {
            sessionStorage.removeItem('idem_ipn_autotest');
            setTimeout(function () { $('.ep-test-ipn').first().trigger('click'); }, 400);
        }
    } catch (e) {}

    $(document).on('click', '.idem-test-api', function(e) {
        e.preventDefault();

        var env      = ($(this).data('env') === 'prod') ? 'prod' : 'sandbox';
        var $btn     = $(this);
        var $row     = $btn.closest('tr');
        var apiKey   = $('#woocommerce_idem_' + env + '_api_token').val();
        var testMode = (env === 'sandbox') ? 'yes' : 'no';
        var testUrl  = (env === 'sandbox')
            ? $('#woocommerce_idem_test_url').val()
            : $('#woocommerce_idem_gateway_url').val();

        if (!apiKey) {
            window.alert('Please enter or Connect an IDEM API Token for this environment first.');
            return;
        }

        $row.block({
            message: null,
            overlayCSS: { background: '#fff', opacity: 0.6 }
        });

        $.post(idem_admin_settings.ajax_url, {
            action: 'idem_check_api',
            nonce: idem_admin_settings.check_nonce,
            api_key: apiKey,
            test_mode: testMode,
            test_url: testUrl
        }).done(function(response) {
            if (response && response.success) {
                window.alert(response.data && response.data.message ? response.data.message : 'API check successful.');
            } else {
                var msg = response && response.data && response.data.message ? response.data.message : 'API check failed.';
                window.alert(msg);
            }
        }).fail(function() {
            window.alert('API check failed due to a network error.');
        }).always(function() {
            $row.unblock();
        });
    });

    // --- Notification (IPN) reachability test ---------------------------------------------------
    // Asks the platform to probe this store's notification URL from OUR servers, so the merchant can
    // catch a firewall/WAF (e.g. Cloudflare) silently blocking our inbound IPNs.
    function ipnResultHtml(d) {
        var ipList = (d.ips && d.ips.length) ? d.ips.join('&nbsp; ') : '';
        var whitelist = ipList
            ? '<br/>Allow our servers in your firewall / WAF for <code>' + (d.ipn_path || '') + '</code>:<br/><code>' + ipList + '</code>'
            : '';
        if (d.blocked) {
            var by = 'a firewall / security service';
            if (d.blocked_by === 'cloudflare') { by = 'Cloudflare'; }
            else if (d.blocked_by === 'waf')   { by = 'a firewall (WAF)'; }
            else if (d.blocked_by && d.blocked_by.indexOf('http_') === 0) { by = 'a firewall (HTTP ' + d.blocked_by.slice(5) + ')'; }
            var ray = d.ray ? ' <em>(Ray ID: ' + d.ray + ')</em>' : '';
            return '<span style="color:#b32d2e;font-weight:600;">✗ Blocked by ' + by + ray + '.</span> '
                + 'Paid orders will stay "pending".' + whitelist;
        }
        if (d.reachable && d.blocked_by === 'unexpected_response') {
            return '<span style="color:#996800;font-weight:600;">⚠ Reached your store, but the notification listener did not answer as expected.</span> Check that your Notification URL is correct.';
        }
        if (d.reachable) {
            return '<span style="color:#1a7f37;font-weight:600;">✓ Reachable.</span> Our servers can deliver payment notifications to your store.';
        }
        if (d.reason === 'no_url') {
            return '<span style="color:#996800;font-weight:600;">Save your settings / Connect first</span> so we know your notification URL.';
        }
        return '<span style="color:#b32d2e;font-weight:600;">✗ Our servers could not reach your store.</span> Check that your Notification URL is public and correct.' + whitelist;
    }

    $(document).on('click', '.ep-test-ipn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $out = $btn.siblings('.ep-ipn-result');
        var env  = ($('#woocommerce_idem_test_mode').val() === 'yes') ? 'sandbox' : 'prod';

        $btn.prop('disabled', true);
        $out.html('<em>Testing…</em>');

        $.post(idem_admin_settings.ajax_url, {
            action: 'idem_verify_notification',
            nonce: idem_admin_settings.check_nonce,
            env: env
        }).done(function(response) {
            if (response && response.success && response.data) {
                $out.html(ipnResultHtml(response.data));
            } else {
                var msg = (response && response.data && response.data.message) ? response.data.message : 'The reachability test failed.';
                $out.html('<span style="color:#b32d2e;font-weight:600;">✗ ' + $('<div/>').text(msg).html() + '</span>');
            }
        }).fail(function() {
            $out.html('<span style="color:#b32d2e;">The reachability test failed due to a network error.</span>');
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });
});
