/**
 * IDEM Payment Gateway — per-environment "Customer Support" editor.
 *
 * The editor targets whatever the sub-account dropdown selects: a specific sub-account's support
 * info when one is chosen (pre-filled from the account default when empty), or the account default
 * when "— None —" is chosen. Email is always required to save; a message is required only for the
 * account default (a sub-account's message is optional — it falls back to the account default at
 * checkout). Progressive enhancement: if the markers are missing nothing breaks.
 */
jQuery(function ($) {

    var data = window.idem_customer_support_data || {};
    var i18n = data.i18n || {};

    function isConnected(env) { return !!(data.connected && data.connected[env]); }
    function $f(env, name) { return $('#idem_cs_' + name + '_' + env); }
    function $save(env) { return $('.idem-cs-save[data-env="' + env + '"]'); }
    function $select(env) { return $('.idem-subaccount-select[data-env="' + env + '"]'); }
    function setStatus(env, msg, color) { $('#idem_cs_status_' + env).css('color', color || '').text(msg || ''); }

    // Current target for an env: '' (account default) or a sub_id string, plus a display label.
    function target(env) {
        var $sel = $select(env);
        var id = $sel.length ? String($sel.val() || '') : '';
        if (id === '') { return { sub_id: '', isSub: false, label: (i18n.account_default || 'Account default') }; }
        var text = $.trim($sel.find('option:selected').text()) || ('#' + id);
        return { sub_id: id, isSub: true, label: text };
    }

    // Reflect the target in the header label, the required note and the message-required star.
    function reflect(env) {
        var t = target(env);
        $('.idem-cs-target[data-env="' + env + '"]').text(t.label ? '— ' + t.label : '');
        $('.idem-cs-required[data-env="' + env + '"]').text(
            t.isSub ? (i18n.required_subaccount || 'A support email is required. Leave the message blank to use your account default.')
                    : (i18n.required_account || 'Required — the gateway will not process payments until a support email and message are set.')
        );
        $('.idem-cs-message-star[data-env="' + env + '"]').toggle(!t.isSub);
    }

    // Fill the fields; for a sub-account with an empty own-value, pre-fill from the account default.
    function fill(env, d, defaults) {
        var isSub = target(env).isSub;
        d = d || {}; defaults = defaults || {};
        function val(key) {
            var v = d[key] ? d[key] : '';
            if (isSub && !v && defaults[key]) { v = defaults[key]; }
            return v;
        }
        $f(env, 'number').val(val('cs_number'));
        $f(env, 'email').val(val('cs_email'));
        $f(env, 'message').val(val('cs_message'));
        if (isSub && !d.cs_email && defaults.cs_email) {
            setStatus(env, i18n.prefilled || '', '');
        }
    }

    function setDisabled(env, disabled) {
        $f(env, 'number').prop('disabled', disabled);
        $f(env, 'email').prop('disabled', disabled);
        $f(env, 'message').prop('disabled', disabled);
        $save(env).prop('disabled', disabled);
    }

    function load(env, showStatus) {
        if (!$f(env, 'email').length) { return; }
        reflect(env);
        if (!isConnected(env)) {
            setStatus(env, i18n.needs_connect || '', '');
            setDisabled(env, true);
            return;
        }
        setDisabled(env, false);
        if (showStatus) { setStatus(env, i18n.loading || '', ''); }

        $.post(data.ajax_url, {
            action: 'idem_get_customer_support',
            nonce:  data.nonce,
            env:    env,
            sub_id: target(env).sub_id
        }, function (resp) {
            if (resp && resp.success) {
                fill(env, resp.data, resp.data.defaults);
            } else {
                var msg = (resp && resp.data && resp.data.needs_connect)
                    ? (i18n.needs_connect || '')
                    : ((resp && resp.data && resp.data.message) ? resp.data.message : (i18n.error || 'Error'));
                setStatus(env, msg, 'red');
            }
        }).fail(function () {
            setStatus(env, i18n.request_failed || 'Request failed', 'red');
        });
    }

    /* ---------- Refresh ---------- */
    $(document).on('click', '.idem-cs-refresh', function (e) {
        e.preventDefault();
        load($(this).data('env') === 'prod' ? 'prod' : 'sandbox', true);
    });

    /* ---------- Reload when the sub-account selection changes (user or after Create) ---------- */
    $(document).on('change', '.idem-subaccount-select', function () {
        load($(this).data('env') === 'prod' ? 'prod' : 'sandbox', false);
    });

    /* ---------- Update (save to EasyProcess) ---------- */
    $(document).on('click', '.idem-cs-save', function () {
        var env  = $(this).data('env') === 'prod' ? 'prod' : 'sandbox';
        var $btn = $(this);
        var t = target(env);
        var email = $.trim($f(env, 'email').val());
        var message = $.trim($f(env, 'message').val());
        if (!email || (!t.isSub && !message)) {
            setStatus(env, t.isSub ? (i18n.required_email || 'A support email is required.')
                                   : (i18n.required || 'A support email and message are required.'), 'red');
            return;
        }

        $btn.prop('disabled', true);
        setStatus(env, i18n.saving || 'Saving…', '');
        $.post(data.ajax_url, {
            action:     'idem_save_customer_support',
            nonce:      data.nonce,
            env:        env,
            sub_id:     t.sub_id,
            cs_email:   email,
            cs_message: message,
            cs_number:  $.trim($f(env, 'number').val())
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success) {
                fill(env, resp.data, resp.data.defaults);
                var msg = i18n.saved || 'Saved.';
                if (resp.data.warnings && resp.data.warnings.length) { msg += ' (' + resp.data.warnings.join('; ') + ')'; }
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
    $('.idem-cs-email').each(function () {
        load($(this).data('env') === 'prod' ? 'prod' : 'sandbox', false);
    });
});
