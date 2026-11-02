/**
 * IDEM Payment Gateway - Environment tabs + per-environment "Connect to EasyProcess".
 *
 * Progressive enhancement over the native WooCommerce settings fields: each environment's
 * fields are wrapped in a card and folded into Sandbox/Production tabs, and the manual
 * credential fields are collapsed behind a toggle (Connect is the primary path). If the
 * expected markers are missing the script bails and the fields remain visible as a flat
 * list — nothing breaks.
 */
jQuery(function ($) {

    var i18n = (window.idem_connect_data && idem_connect_data.i18n) || {};

    /* ---------- Environment tabs ---------- */

    var $modeSelect = $('#woocommerce_idem_test_mode');
    var $sbTitle    = $('#woocommerce_idem_sandbox_creds_title');
    var $pdTitle    = $('#woocommerce_idem_prod_creds_title');
    var $ordTitle   = $('#woocommerce_idem_order_settings_title');

    if ($sbTitle.length && $pdTitle.length) {
        // Elements belonging to each environment (the WooCommerce <table> that follows each
        // section <h3>; title fields close/reopen the settings table, so these are siblings).
        var $sbPanel = $sbTitle.nextUntil($pdTitle);
        var $pdPanel = $ordTitle.length ? $pdTitle.nextUntil($ordTitle) : $pdTitle.nextAll();

        // Wrap each environment's fields in a bordered card so the tab has a clear boundary.
        $sbPanel.wrapAll('<div class="idem-tab-content" data-env="sandbox"></div>');
        $pdPanel.wrapAll('<div class="idem-tab-content" data-env="prod"></div>');
        var $sbCard = $('.idem-tab-content[data-env="sandbox"]');
        var $pdCard = $('.idem-tab-content[data-env="prod"]');

        // Build the tab bar and drop it where the Sandbox section title was.
        var $tabs = $(
            '<h2 class="nav-tab-wrapper idem-env-tabs">' +
            '<a href="#" class="nav-tab" data-env="sandbox">' + $sbTitle.text() + '</a>' +
            '<a href="#" class="nav-tab" data-env="prod">' + $pdTitle.text() + '</a>' +
            '</h2>'
        );
        $sbTitle.before($tabs);
        // The section <h3> titles are now redundant with the tab labels.
        $sbTitle.hide();
        $pdTitle.hide();

        function activate(env) {
            var sandbox = (env !== 'prod');
            $tabs.find('.nav-tab').each(function () {
                $(this).toggleClass('nav-tab-active', $(this).data('env') === env);
            });
            $sbCard.toggle(sandbox);
            $pdCard.toggle(!sandbox);
        }

        $tabs.on('click', '.nav-tab', function (e) {
            e.preventDefault();
            activate($(this).data('env'));
        });

        // Keep the visible tab in sync with the live Mode selector.
        $modeSelect.on('change', function () {
            activate($(this).val() === 'yes' ? 'sandbox' : 'prod');
        });

        // Fold the manual fields (URL + credentials) behind a toggle in each tab.
        collapseManual($sbCard);
        collapseManual($pdCard);

        // Default to the currently-selected live environment.
        activate(($modeSelect.val() === 'yes') ? 'sandbox' : 'prod');
    }

    // Collapse everything except the Connect card behind a "manual" toggle, collapsed by default.
    function collapseManual($card) {
        var $rows   = $card.find('.form-table tr');
        // The Connect card, the sub-account dropdown and the customer-support editor stay visible;
        // only the URL + credential rows fold behind the toggle.
        var $manual = $rows.not('.idem-connect-row').not('.idem-subaccount-row').not('.idem-customer-support-row');
        if (!$manual.length) {
            return;
        }
        $manual.addClass('idem-manual-row').hide();

        var showText = i18n.manual_show || 'Enter or edit credentials manually';
        var hideText = i18n.manual_hide || 'Hide manual credentials';
        var $toggleRow = $(
            '<tr class="idem-manual-toggle-row"><td colspan="2">' +
            '<a href="#" class="idem-manual-link"><span class="dashicons dashicons-arrow-right-alt2"></span> ' +
            '<span class="idem-manual-label"></span></a></td></tr>'
        );
        $toggleRow.find('.idem-manual-label').text(showText);

        // Place the toggle after the last always-visible row (Connect card → Sub-account →
        // Customer support), so the order reads: those → [manual credentials toggle] → folded fields.
        var $anchor = $rows.filter('.idem-customer-support-row').last();
        if (!$anchor.length) {
            $anchor = $rows.filter('.idem-subaccount-row').last();
        }
        if (!$anchor.length) {
            $anchor = $rows.filter('.idem-connect-row').last();
        }
        if ($anchor.length) {
            $anchor.after($toggleRow);
        } else {
            $manual.first().before($toggleRow);
        }

        $toggleRow.on('click', '.idem-manual-link', function (e) {
            e.preventDefault();
            var nowVisible = !$manual.first().is(':visible');
            $manual.toggle(nowVisible);
            $(this).find('.dashicons')
                .toggleClass('dashicons-arrow-down-alt2', nowVisible)
                .toggleClass('dashicons-arrow-right-alt2', !nowVisible);
            $(this).find('.idem-manual-label').text(nowVisible ? hideText : showText);
        });
    }

    /* ---------- Per-environment Connect ---------- */

    $(document).on('click', '.idem-connect-btn', function () {
        var env    = $(this).data('env') === 'prod' ? 'prod' : 'sandbox';
        var $btn   = $(this);
        var $status = $('#idem_connect_status_' + env).css('color', '').text(i18n.connecting || 'Connecting…');
        var urlSel = (env === 'sandbox') ? '#woocommerce_idem_test_url' : '#woocommerce_idem_gateway_url';

        $btn.prop('disabled', true);
        $.post(idem_connect_data.ajax_url, {
            action:     'wc_idem_connect',
            nonce:      idem_connect_data.nonce,
            env:        env,
            email:      $('#idem_connect_email_' + env).val(),
            password:   $('#idem_connect_password_' + env).val(),
            env_url:    $(urlSel).val()
        }, function (resp) {
            if (resp && resp.success) {
                $status.css('color', 'green').text(resp.data.message);
                // Auto-run the notification (IPN) reachability test after the reload, so a merchant
                // WAF blocking our inbound IPNs is caught right at onboarding. Flag survives reload.
                try { sessionStorage.setItem('idem_ipn_autotest', env); } catch (e) {}
                setTimeout(function () { location.reload(); }, 900);
            } else {
                $btn.prop('disabled', false);
                $status.css('color', 'red').text((resp && resp.data && resp.data.message) ? resp.data.message : (i18n.error || 'Error'));
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.css('color', 'red').text(i18n.request_failed || 'Request failed');
        });
    });

    /* ---------- Per-environment Disconnect ---------- */

    $(document).on('click', '.idem-disconnect-btn', function () {
        var env  = $(this).data('env') === 'prod' ? 'prod' : 'sandbox';
        var $btn = $(this);
        if (i18n.confirm_disconnect && !window.confirm(i18n.confirm_disconnect)) {
            return;
        }
        var $status = $('#idem_connect_status_' + env).css('color', '').text(i18n.disconnecting || 'Disconnecting…');
        $btn.prop('disabled', true);
        $.post(idem_connect_data.ajax_url, {
            action: 'wc_idem_disconnect',
            nonce:  idem_connect_data.nonce,
            env:    env
        }, function (resp) {
            if (resp && resp.success) {
                setTimeout(function () { location.reload(); }, 500);
            } else {
                $btn.prop('disabled', false);
                $status.css('color', 'red').text((resp && resp.data && resp.data.message) ? resp.data.message : (i18n.error || 'Error'));
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.css('color', 'red').text(i18n.request_failed || 'Request failed');
        });
    });
});
