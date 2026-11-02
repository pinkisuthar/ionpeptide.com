/**
 * POP Recovery Email v1.5.8 \u2014 Admin JS
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        /* \u2500\u2500 Test Email \u2500\u2500 */
        $('#prer-send-test').on('click', function() {
            var $btn = $(this), email = $('#prer-test-email').val(), $r = $('#prer-test-result');
            var orderId = $('#prer-test-order').val() || 0;
            if (!email) { $r.html('<span style="color:#dc2626">Enter an email address.</span>'); return; }
            $btn.prop('disabled', true).text('Sending...');
            $r.html('<span style="color:#64748b">Sending\u2026</span>');
            $.post(prer.ajax_url, {
                action: 'prer_send_test_email', nonce: prer.nonce, email: email,
                template_id: 0, order_id: orderId
            }, function(res) {
                $r.empty().append($('<span>').css('color', res.success ? '#16a34a' : '#dc2626').text(res.data || 'Error'));
            }).fail(function() { $r.html('<span style="color:#dc2626">Request failed.</span>'); })
              .always(function() { $btn.prop('disabled', false).text('Send Test Email'); });
        });

        /* \u2500\u2500 Dashboard Auto-Refresh \u2500\u2500 */
        var dashRefreshInterval = null;
        function refreshDashboard() {
            $.post(prer.ajax_url, {
                action: 'prer_refresh_dashboard', nonce: prer.nonce
            }, function(res) {
                if (res.success) {
                    $('#prer-queue-body').html(res.data.queue_html);
                    $('#prer-recent-body').html(res.data.recent_html);
                    $('#prer-queue-count').text(res.data.queue_count);
                    $('#prer-refresh-status').html('<span class="prer-pulse-dot"></span> Updated ' + new Date().toLocaleTimeString([], {hour:'numeric',minute:'2-digit'}));
                }
            });
        }
        if ($('#prer-queue-card').length) {
            dashRefreshInterval = setInterval(refreshDashboard, 30000);
            $('#prer-refresh-queue').on('click', function() {
                var $svg = $(this).find('svg');
                $svg.addClass('prer-spinning');
                refreshDashboard();
                setTimeout(function() { $svg.removeClass('prer-spinning'); }, 600);
            });
            $('#prer-clear-queue').on('click', function() {
                var count = $('#prer-queue-count').text();
                if (!confirm('Cancel all ' + count + ' pending emails in the queue? This cannot be undone.')) return;
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing...');
                $.post(prer.ajax_url, {
                    action: 'prer_clear_queue', nonce: prer.nonce
                }, function(res) {
                    if (res.success) {
                        refreshDashboard();
                        $btn.hide();
                    }
                    $btn.prop('disabled', false).html('<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg> Clear Queue');
                });
            });
        }

        /* \u2500\u2500 Template Editor Tabs \u2500\u2500 */
        $('.prer-editor-tab').on('click', function() {
            var tab = $(this).data('tab');
            $('.prer-editor-tab').removeClass('active');
            $(this).addClass('active');
            $('.prer-tab-panel').hide();
            $('#prer-panel-' + tab).show();

            if (tab === 'visual') {
                updatePreview();
            }
        });

        /* \u2500\u2500 Live Preview \u2500\u2500 */
        function updatePreview() {
            var html = window.prer_cm ? window.prer_cm.getValue() : $('#prer-tpl-html').val();
            var subject = $('#prer-tpl-subject').val();
            var orderId = $('#prer-preview-order').val() || 0;
            if (!html) return;

            $.post(prer.ajax_url, {
                action: 'prer_preview_template', nonce: prer.nonce,
                html_body: html, subject: subject, order_id: orderId
            }, function(res) {
                if (res.success) {
                    var iframe = document.getElementById('prer-preview-iframe');
                    if (iframe) {
                        var doc = iframe.contentDocument || iframe.contentWindow.document;
                        doc.open();
                        doc.write(res.data.html);
                        doc.close();
                        setTimeout(function() {
                            try { iframe.style.height = doc.body.scrollHeight + 40 + 'px'; } catch(e) {}
                        }, 200);
                    }
                }
            });
        }

        /* \u2500\u2500 Preview Button \u2500\u2500 */
        $('#prer-preview-btn').on('click', function() {
            $('.prer-editor-tab[data-tab="visual"]').click();
        });

        /* \u2500\u2500 Re-render preview when data source changes \u2500\u2500 */
        $('#prer-preview-order').on('change', function() {
            if ($('#prer-panel-visual').is(':visible')) {
                updatePreview();
            }
        });

        /* \u2500\u2500 Refresh preview button \u2500\u2500 */
        $('#prer-refresh-preview').on('click', function() {
            updatePreview();
        });

        /* \u2500\u2500 Send Test Email from Template Editor \u2500\u2500 */
        $('#prer-send-test-tpl').on('click', function() {
            var $btn = $(this), $r = $('#prer-test-tpl-result');
            var originalHtml = $btn.html();
            var email = $('#prer-test-email-tpl').val();
            var orderId = $('#prer-preview-order').val() || 0;
            var tplId = $('#prer-tpl-id').val() || 0;
            var html = window.prer_cm ? window.prer_cm.getValue() : $('#prer-tpl-html').val();
            var subject = $('#prer-tpl-subject').val();

            if (!email) { $r.text('Enter an email.').css('color','#dc2626'); return; }
            if (!html || !subject) { $r.text('Subject and HTML required.').css('color','#dc2626'); return; }

            $btn.prop('disabled', true).html('Sending\u2026');
            $r.text('').css('color','#64748b');

            $.post(prer.ajax_url, {
                action: 'prer_send_test_email', nonce: prer.nonce,
                email: email, order_id: orderId, template_id: tplId,
                html_body: html, subject: subject
            }, function(res) {
                $r.text(res.data || 'Error').css('color', res.success ? '#16a34a' : '#dc2626');
            }).fail(function() {
                $r.text('Request failed.').css('color','#dc2626');
            }).always(function() {
                $btn.prop('disabled', false).html(originalHtml);
            });
        });

        /* \u2500\u2500 Merge Tag Insertion \u2500\u2500 */
        var lastFocusedInput = null;

        // Track which input/textarea was last focused
        $('#prer-tpl-subject, #prer-tpl-html').on('focus', function() {
            lastFocusedInput = this;
        });

        $('.prer-tag-btn').on('click', function() {
            var tag = $(this).data('tag');
            var subject = document.getElementById('prer-tpl-subject');
            var target = lastFocusedInput || document.getElementById('prer-tpl-html');

            if (!target) return;

            // 1.5.8: insert into exactly one place. Previously, with CodeMirror active, a
            // tag clicked while the subject was focused went into the subject AND the body.
            if (window.prer_cm && target !== subject) {
                var doc = window.prer_cm.getDoc();
                doc.replaceSelection(tag);
                window.prer_cm.focus();
                return;
            }

            // Insert at cursor position (subject field, or plain textarea without CodeMirror)
            if (target.selectionStart !== undefined) {
                var start = target.selectionStart;
                var end = target.selectionEnd;
                var val = target.value;
                target.value = val.substring(0, start) + tag + val.substring(end);
                target.selectionStart = target.selectionEnd = start + tag.length;
                target.focus();
            } else {
                $(target).val($(target).val() + tag);
                target.focus();
            }
        });

        /* \u2500\u2500 Save Template \u2500\u2500 */
        $('#prer-save-tpl-btn').on('click', function() {
            var $btn = $(this);
            var id = $('#prer-tpl-id').val();
            var name = $('#prer-tpl-name').val();
            var subject = $('#prer-tpl-subject').val();
            var html = window.prer_cm ? window.prer_cm.getValue() : $('#prer-tpl-html').val();

            if (!name || !subject || !html) {
                alert('Please fill in template name, subject, and HTML body.');
                return;
            }

            $btn.prop('disabled', true).text('Saving...');

            $.post(prer.ajax_url, {
                action: 'prer_save_template', nonce: prer.nonce,
                id: id, name: name, subject: subject, html_body: html
            }, function(res) {
                if (res.success) {
                    // If new template, redirect to edit view
                    if (!id || id === '0') {
                        window.location.href = prer.ajax_url.replace('admin-ajax.php', 'admin.php?page=pop-email-recovery&tab=templates&edit=' + res.data.id + '&saved=1');
                    } else {
                        $btn.text('Saved \u2713');
                        setTimeout(function() { $btn.text('Save Template').prop('disabled', false); }, 2000);
                    }
                } else {
                    alert(res.data || 'Error saving template.');
                    $btn.prop('disabled', false).text('Save Template');
                }
            }).fail(function() {
                alert('Request failed.');
                $btn.prop('disabled', false).text('Save Template');
            });
        });

        /* \u2500\u2500 Template List Actions \u2500\u2500 */
        $(document).on('click', '.prer-set-active-btn', function() {
            var id = $(this).data('id');
            if (!confirm('Set this template as the active recovery email?')) return;
            $.post(prer.ajax_url, { action: 'prer_set_active_template', nonce: prer.nonce, id: id }, function(res) {
                if (res.success) location.reload();
                else alert(res.data || 'Error');
            });
        });

        $(document).on('click', '.prer-duplicate-btn', function() {
            var id = $(this).data('id');
            $.post(prer.ajax_url, { action: 'prer_duplicate_template', nonce: prer.nonce, id: id }, function(res) {
                if (res.success) location.reload();
                else alert(res.data || 'Error');
            });
        });

        $(document).on('click', '.prer-delete-btn', function() {
            var id = $(this).data('id');
            if (!confirm('Delete this template? This cannot be undone.')) return;
            $.post(prer.ajax_url, { action: 'prer_delete_template', nonce: prer.nonce, id: id }, function(res) {
                if (res.success) location.reload();
                else alert(res.data || 'Error');
            });
        });

        /* \u2500\u2500 Debug Log Viewer \u2500\u2500 */
        function loadLog(file) {
            $.post(prer.ajax_url, {
                action: 'prer_get_log', nonce: prer.nonce, file: file || ''
            }, function(res) {
                if (res.success) {
                    $('#prer-log-content').text(res.data.content || 'Empty.');
                    // Populate file selector
                    var $sel = $('#prer-log-file');
                    if ($sel.children().length === 0 && res.data.files) {
                        $.each(res.data.files, function(i, f) {
                            $sel.append($('<option>').val(f).text(f.replace('prer-','').replace('.log','')));
                        });
                        if (res.data.file) $sel.val(res.data.file);
                    }
                    // Scroll to bottom
                    var el = document.getElementById('prer-log-content');
                    if (el) el.scrollTop = el.scrollHeight;
                }
            });
        }
        if ($('#prer-log-content').length) {
            loadLog('');
            $('#prer-refresh-log').on('click', function() { loadLog($('#prer-log-file').val()); });
            $('#prer-log-file').on('change', function() { loadLog($(this).val()); });
            $('#prer-download-log').on('click', function() {
                var content = $('#prer-log-content').text();
                var filename = ($('#prer-log-file').val() || 'prer-debug') + '.log';
                var blob = new Blob([content], {type: 'text/plain'});
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = filename;
                a.click();
                URL.revokeObjectURL(a.href);
            });
        }

        /* \u2500\u2500 CodeMirror Init \u2500\u2500 */
        if ($('#prer-tpl-html').length && typeof wp !== 'undefined' && wp.codeEditor) {
            try {
                var cmSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
                cmSettings.codemirror = $.extend({}, cmSettings.codemirror, {
                    mode: 'htmlmixed',
                    lineNumbers: true,
                    lineWrapping: true,
                    indentUnit: 2,
                    tabSize: 2,
                    autoCloseTags: true,
                    matchBrackets: true,
                    theme: 'default',
                });
                var editor = wp.codeEditor.initialize($('#prer-tpl-html'), cmSettings);
                window.prer_cm = editor.codemirror;

                // Track CodeMirror focus so merge tags go to the body, not the subject
                window.prer_cm.on('focus', function() {
                    lastFocusedInput = document.getElementById('prer-tpl-html');
                });

                // Sync on change
                window.prer_cm.on('change', function() {
                    window.prer_cm.save();
                });
            } catch(e) {
                console.log('CodeMirror init skipped:', e);
            }
        }
    });
})(jQuery);
