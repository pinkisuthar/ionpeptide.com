/**
 * IDEM Payment Gateway — per-environment "Sub-account" dropdown.
 *
 * For each environment tab, populate the sub-account <select> from the merchant's EasyProcess
 * sub-accounts (fetched with that environment's API token), wire a Refresh link, and a
 * "Create a sub-account for this store" button that provisions one in EasyProcess seeded from the
 * store. Progressive enhancement: if the markers are missing nothing breaks and the select keeps
 * its saved value.
 */
jQuery(function ($) {

    var data = window.idem_subaccounts_data || {};
    var i18n = data.i18n || {};

    function sprintf1(tpl, a) { return String(tpl).replace('%s', a); }

    function isConnected(env) { return !!(data.connected && data.connected[env]); }

    function $create(env) { return $('.idem-subaccount-create[data-env="' + env + '"]'); }

    function setStatus(env, msg, color) {
        $('#idem_subaccount_status_' + env).css('color', color || '').text(msg || '');
    }

    // Populate the select, keeping the saved value selected (adding a fallback option for it if the
    // returned list doesn't include it, so a stored value is never silently lost).
    function fill(env, subs) {
        var $sel = $('.idem-subaccount-select[data-env="' + env + '"]');
        if (!$sel.length) { return; }
        var saved = String($sel.data('saved') || '');
        var found = false;

        $sel.empty();
        $sel.append($('<option>').val('').text(i18n.none || '— None —'));
        $.each(subs, function (i, s) {
            var id = String(s.sub_id);
            if (id === saved) { found = true; }
            var label = '#' + id + (s.name ? ' · ' + s.name : (s.reference ? ' · ' + s.reference : ''));
            $sel.append($('<option>').val(id).text(label));
        });
        if (saved !== '' && !found) {
            $sel.append($('<option>').val(saved).text('#' + saved));
        }
        $sel.val(saved);
    }

    function load(env, showStatus) {
        var $sel = $('.idem-subaccount-select[data-env="' + env + '"]');
        if (!$sel.length) { return; }

        if (!isConnected(env)) {
            setStatus(env, i18n.needs_connect || '', '');
            $create(env).prop('disabled', true);
            return;
        }
        if (showStatus) { setStatus(env, i18n.loading || '', ''); }

        $.post(data.ajax_url, {
            action: 'idem_list_subaccounts',
            nonce:  data.nonce,
            env:    env
        }, function (resp) {
            if (resp && resp.success) {
                fill(env, resp.data.subaccounts || []);
                if (!resp.data.subaccounts_enabled) {
                    setStatus(env, i18n.disabled || '', '');
                    $create(env).prop('disabled', true);
                } else {
                    setStatus(env, '', '');
                    $create(env).prop('disabled', false);
                }
            } else {
                var msg = (resp && resp.data && resp.data.needs_connect)
                    ? (i18n.needs_connect || '')
                    : ((resp && resp.data && resp.data.message) ? resp.data.message : (i18n.error || 'Error'));
                setStatus(env, msg, 'red');
                $create(env).prop('disabled', true);
            }
        }).fail(function () {
            setStatus(env, i18n.request_failed || 'Request failed', 'red');
        });
    }

    /* ---------- Refresh ---------- */
    $(document).on('click', '.idem-subaccount-refresh', function (e) {
        e.preventDefault();
        load($(this).data('env') === 'prod' ? 'prod' : 'sandbox', true);
    });

    /* ---------- Create a sub-account for this store ---------- */
    $(document).on('click', '.idem-subaccount-create', function () {
        var env  = $(this).data('env') === 'prod' ? 'prod' : 'sandbox';
        var $btn = $(this);
        if (i18n.confirm_create && !window.confirm(i18n.confirm_create)) { return; }

        $btn.prop('disabled', true);
        setStatus(env, i18n.creating || '', '');
        $.post(data.ajax_url, {
            action: 'idem_create_subaccount',
            nonce:  data.nonce,
            env:    env
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success && resp.data && resp.data.subaccount) {
                var sub  = resp.data.subaccount;
                var $sel = $('.idem-subaccount-select[data-env="' + env + '"]');
                $sel.data('saved', String(sub.sub_id));
                if (!$sel.find('option[value="' + sub.sub_id + '"]').length) {
                    var label = '#' + sub.sub_id + (sub.name ? ' · ' + sub.name : '');
                    $sel.append($('<option>').val(String(sub.sub_id)).text(label));
                }
                // trigger 'change' so the Customer Support editor retargets this new sub-account
                $sel.val(String(sub.sub_id)).trigger('change');
                var msg = sprintf1(resp.data.created ? (i18n.created || '') : (i18n.reused || ''), sub.sub_id);
                if (resp.data.warnings && resp.data.warnings.length) {
                    msg += ' (' + resp.data.warnings.join('; ') + ')';
                }
                setStatus(env, msg, 'green');
            } else {
                var m = (resp && resp.data && resp.data.message) ? resp.data.message : (i18n.error || 'Error');
                setStatus(env, m, 'red');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            setStatus(env, i18n.request_failed || 'Request failed', 'red');
        });
    });

    /* ---------- Initial load (connected environments only) ---------- */
    $('.idem-subaccount-select').each(function () {
        load($(this).data('env') === 'prod' ? 'prod' : 'sandbox', false);
    });
});
