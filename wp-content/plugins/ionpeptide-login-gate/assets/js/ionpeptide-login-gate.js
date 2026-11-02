(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function isTextControl(el) {
        return !!(el && el.matches && el.matches('input[type="text"], input[type="email"], input[type="password"], input[type="tel"], input[type="date"], input[type="number"], input:not([type]), textarea'));
    }

    function resetProcessingState(root) {
        if (!root) return;

        root.querySelectorAll('form.ion-gate-form').forEach(function (form) {
            form.dataset.ionSubmitting = '';
            form.classList.remove('ion-form-processing');
        });

        root.querySelectorAll('button.ion-is-processing, input.ion-is-processing').forEach(function (submit) {
            submit.disabled = false;
            submit.removeAttribute('aria-busy');
            submit.classList.remove('ion-is-processing');

            if (submit.dataset && (submit.dataset.ionOriginalHtml || submit.dataset.ionOriginalText)) {
                if (submit.tagName === 'BUTTON') {
                    submit.innerHTML = submit.dataset.ionOriginalHtml || submit.dataset.ionOriginalText;
                } else {
                    submit.value = submit.dataset.ionOriginalText;
                }
            }
        });
    }

    ready(function () {
        var overlays = document.querySelectorAll('#ion-auth-overlay');
        if (!overlays.length) return;

        // Defensive guard for cached/duplicated footer output: keep the first real gate only.
        if (overlays.length > 1) {
            overlays.forEach(function (node, index) {
                if (index > 0 && node.parentNode) node.parentNode.removeChild(node);
            });
        }

        var overlay = document.getElementById('ion-auth-overlay');
        if (!overlay) return;

        document.documentElement.classList.add('ion-ui-gate-active');
        if (document.body) document.body.classList.add('ion-ui-gate-active');

        resetProcessingState(overlay);

        // Add an accessible show/hide control to every password field in the gate.
        // This is done in JS so login, registration, and future password fields all inherit it.
        function setupPasswordToggles(root) {
            if (!root) return;

            root.querySelectorAll('input[type="password"]').forEach(function (input) {
                if (input.classList.contains('ion-password-field') || input.closest('.ion-password-field') || input.closest('.ion-password-wrap')) return;

                var wrapper = document.createElement('span');
                wrapper.className = 'ion-password-wrap';

                var toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'ion-password-toggle';
                toggle.setAttribute('aria-label', 'Show password');
                toggle.setAttribute('aria-pressed', 'false');
                toggle.setAttribute('title', 'Show password');
                toggle.innerHTML = '<svg class="ion-password-eye ion-password-eye-show" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.1 12s3.6-6 9.9-6 9.9 6 9.9 6-3.6 6-9.9 6-9.9-6-9.9-6Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg><svg class="ion-password-eye ion-password-eye-hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.2A10.8 10.8 0 0 1 12 5c6.3 0 9.9 7 9.9 7a16.8 16.8 0 0 1-3 3.7M6.6 6.6C3.7 8.4 2.1 12 2.1 12S5.7 19 12 19a10 10 0 0 0 4.1-.9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

                input.parentNode.insertBefore(wrapper, input);
                wrapper.appendChild(input);
                wrapper.appendChild(toggle);
                input.classList.add('ion-password-field');

                toggle.addEventListener('click', function () {
                    var shouldShow = input.type === 'password';

                    try {
                        input.type = shouldShow ? 'text' : 'password';
                    } catch (e) {
                        return;
                    }

                    toggle.classList.toggle('is-visible', shouldShow);
                    toggle.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
                    toggle.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
                    toggle.setAttribute('title', shouldShow ? 'Hide password' : 'Show password');
                });
            });
        }

        setupPasswordToggles(overlay);

        window.addEventListener('pageshow', function () {
            resetProcessingState(overlay);
        });

        // Some mobile browsers/extensions blur fixed-overlay inputs immediately after tap.
        // Keep the tap on the gate and restore focus without changing the gate flow.
        var lastInput = null;
        var lastInputAt = 0;

        ['pointerdown', 'mousedown', 'touchstart'].forEach(function (eventName) {
            overlay.addEventListener(eventName, function (event) {
                if (!isTextControl(event.target)) return;
                lastInput = event.target;
                lastInputAt = Date.now();
                event.stopPropagation();
            }, true);
        });

        overlay.addEventListener('focusin', function (event) {
            if (!isTextControl(event.target)) return;
            lastInput = event.target;
            lastInputAt = Date.now();
        }, true);

        // Do not let a background modal's document-level focus trap steal gate focus.
        // Bubble phase preserves the fields' own focus handlers and native editing.
        overlay.addEventListener('focusin', function (event) {
            event.stopPropagation();
        });

        overlay.addEventListener('focusout', function (event) {
            if (!isTextControl(event.target)) return;
            var field = event.target;
            var focusedTooBriefly = Date.now() - lastInputAt < 450;

            if (!focusedTooBriefly) return;

            window.setTimeout(function () {
                if (!field || !document.documentElement.contains(field)) return;
                if (!overlay.contains(field)) return;
                if (document.activeElement && document.activeElement !== document.body) return;

                try {
                    field.focus({ preventScroll: true });
                } catch (e) {
                    field.focus();
                }
            }, 40);
        }, true);

        overlay.addEventListener('click', function (event) {
            if (!isTextControl(event.target)) return;
            var field = event.target;
            window.setTimeout(function () {
                if (!field || !document.documentElement.contains(field)) return;
                try {
                    field.focus({ preventScroll: true });
                } catch (e) {
                    field.focus();
                }
            }, 0);
        }, true);

        /* Password visibility controls. Buttons are rendered by PHP so they work
         * immediately on both the Sign In and Register panels. */
        overlay.addEventListener('click', function (event) {
            var toggle = event.target.closest ? event.target.closest('[data-ion-password-toggle]') : null;
            if (!toggle || !overlay.contains(toggle)) return;

            var fieldWrap = toggle.closest('.ion-password-field');
            var field = fieldWrap ? fieldWrap.querySelector('input') : null;
            if (!field) return;

            event.preventDefault();
            var makeVisible = field.type === 'password';
            field.type = makeVisible ? 'text' : 'password';
            toggle.classList.toggle('is-visible', makeVisible);
            toggle.setAttribute('aria-pressed', makeVisible ? 'true' : 'false');
            toggle.setAttribute('aria-label', makeVisible ? 'Hide password' : 'Show password');
        });

        var tabs = overlay.querySelectorAll('.ion-tab');
        var panels = overlay.querySelectorAll('.ion-auth-panel');

        function syncLayoutMode(name) {
            var isRegister = name === 'register' || name === 'google-complete';
            overlay.classList.toggle('ion-mode-register', isRegister);
            overlay.classList.toggle('ion-mode-login', name === 'login');
            overlay.classList.toggle('ion-mode-forgot', name === 'forgot');
        }

        function showPanel(name) {
            resetProcessingState(overlay);
            syncLayoutMode(name);

            tabs.forEach(function (tab) {
                var target = tab.getAttribute('data-target');
                tab.classList.toggle('active', target === name || (name === 'google-complete' && target === 'register'));
            });
            panels.forEach(function (panel) {
                panel.style.display = panel.getAttribute('data-panel') === name ? 'block' : 'none';
            });

            var left = overlay.querySelector('.ion-auth-left');
            if (left && typeof left.scrollTo === 'function') {
                left.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        var initiallyActiveTab = overlay.querySelector('.ion-tab.active');
        if (initiallyActiveTab) {
            syncLayoutMode(initiallyActiveTab.getAttribute('data-target') || 'login');
        } else if (overlay.querySelector('[data-panel="forgot"]')) {
            syncLayoutMode('forgot');
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                showPanel(tab.getAttribute('data-target'));
            });
        });

        function setGoogleButtonState(button, busy) {
            if (!button) return;
            button.disabled = !!busy;
            button.classList.toggle('is-loading', !!busy);
            button.setAttribute('aria-busy', busy ? 'true' : 'false');
        }

        function googleErrorTarget(button) {
            var block = button && button.closest ? button.closest('.ion-google-block') : null;
            return block ? block.querySelector('[data-ion-google-error]') : null;
        }

        function showGoogleError(button, message) {
            var target = googleErrorTarget(button);
            if (!target) return;
            target.textContent = message || 'Google sign-in could not be completed. Please try again.';
            target.classList.add('is-visible');
        }

        function clearGoogleErrors() {
            overlay.querySelectorAll('[data-ion-google-error]').forEach(function (target) {
                target.textContent = '';
                target.classList.remove('is-visible');
            });
        }

        function fillGoogleCompletion(data, sourceButton) {
            var panel = overlay.querySelector('[data-panel="google-complete"]');
            if (!panel) {
                showGoogleError(sourceButton, 'Account setup could not be opened. Please refresh and try again.');
                return;
            }

            var token = panel.querySelector('[data-ion-google-token]');
            var email = panel.querySelector('[data-ion-google-email]');
            var name = panel.querySelector('[data-ion-google-name]');
            var redirect = panel.querySelector('input[name="redirect_to"]');

            if (token) token.value = data.onboarding_token || '';
            if (email) email.value = data.email || '';
            if (name && !name.value) name.value = data.name || '';
            if (redirect && sourceButton) redirect.value = sourceButton.getAttribute('data-redirect-to') || redirect.value || '';

            var completeError = panel.querySelector('[data-ion-google-complete-error]');
            if (completeError) {
                completeError.textContent = '';
                completeError.classList.remove('is-visible');
            }

            showPanel('google-complete');
            window.setTimeout(function () {
                var firstEditable = panel.querySelector('input[name="full_name"]');
                if (firstEditable) firstEditable.focus();
            }, 80);
        }

        function postGoogleRequest(formData) {
            return fetch(IonPeptideLoginGate.googleAjaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Google sign-in returned an invalid response.');
                });
            });
        }

        var googleButtons = overlay.querySelectorAll('[data-ion-google-login]');
        var googleTokenClient = null;
        var pendingGoogleButton = null;

        function handleGoogleTokenResponse(tokenResponse) {
            var button = pendingGoogleButton;
            if (!button) return;

            if (!tokenResponse || tokenResponse.error || !tokenResponse.access_token) {
                setGoogleButtonState(button, false);
                showGoogleError(button, 'Google sign-in was cancelled or could not start. Please try again.');
                pendingGoogleButton = null;
                return;
            }

            var data = new FormData();
            data.append('action', 'ionlg_google_login');
            data.append('nonce', IonPeptideLoginGate.googleNonce || '');
            data.append('access_token', tokenResponse.access_token);
            data.append('redirect_to', button.getAttribute('data-redirect-to') || '');
            data.append('ui_gate', button.getAttribute('data-ui-gate') || '0');

            postGoogleRequest(data).then(function (result) {
                if (!result || !result.success || !result.data) {
                    throw new Error(result && result.data && result.data.message ? result.data.message : 'Google sign-in could not be completed.');
                }

                if (result.data.status === 'logged_in' && result.data.redirect_to) {
                    window.location.assign(result.data.redirect_to);
                    return;
                }

                if (result.data.status === 'complete_profile') {
                    setGoogleButtonState(button, false);
                    fillGoogleCompletion(result.data, button);
                    pendingGoogleButton = null;
                    return;
                }

                throw new Error('Google sign-in returned an unexpected response.');
            }).catch(function (error) {
                setGoogleButtonState(button, false);
                showGoogleError(button, error && error.message ? error.message : 'Google sign-in could not be completed.');
                pendingGoogleButton = null;
            });
        }

        function initGoogleTokenClient() {
            if (!window.IonPeptideLoginGate || IonPeptideLoginGate.googleEnabled !== 'yes' || !IonPeptideLoginGate.googleClientId) {
                return false;
            }
            if (!window.google || !google.accounts || !google.accounts.oauth2 || !google.accounts.oauth2.initTokenClient) {
                return false;
            }
            if (!googleTokenClient) {
                googleTokenClient = google.accounts.oauth2.initTokenClient({
                    client_id: IonPeptideLoginGate.googleClientId,
                    scope: 'openid email profile',
                    callback: handleGoogleTokenResponse,
                    error_callback: function () {
                        if (pendingGoogleButton) {
                            setGoogleButtonState(pendingGoogleButton, false);
                            showGoogleError(pendingGoogleButton, 'Google sign-in could not open. Check popup/privacy settings and try again.');
                            pendingGoogleButton = null;
                        }
                    }
                });
            }
            return true;
        }

        googleButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                clearGoogleErrors();

                if (!initGoogleTokenClient()) {
                    showGoogleError(button, 'Google sign-in is still loading. Please try again in a moment.');
                    return;
                }

                pendingGoogleButton = button;
                setGoogleButtonState(button, true);
                try {
                    googleTokenClient.requestAccessToken({ prompt: '' });
                } catch (error) {
                    setGoogleButtonState(button, false);
                    showGoogleError(button, 'Google sign-in could not start. Please try again.');
                    pendingGoogleButton = null;
                }
            });
        });

        overlay.querySelectorAll('[data-ion-google-back]').forEach(function (button) {
            button.addEventListener('click', function () {
                showPanel('register');
            });
        });

        var googleCompleteForm = overlay.querySelector('[data-ion-google-complete-form]');
        if (googleCompleteForm) {
            googleCompleteForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (googleCompleteForm.dataset.ionSubmitting === '1') return;

                var errorBox = googleCompleteForm.querySelector('[data-ion-google-complete-error]');
                if (errorBox) {
                    errorBox.textContent = '';
                    errorBox.classList.remove('is-visible');
                }

                googleCompleteForm.dataset.ionSubmitting = '1';
                googleCompleteForm.classList.add('ion-form-processing');
                setProcessing(googleCompleteForm);

                fillRecaptcha(googleCompleteForm, function () {
                    var data = new FormData(googleCompleteForm);
                    if (!data.get('nonce')) data.set('nonce', IonPeptideLoginGate.googleNonce || '');

                    postGoogleRequest(data).then(function (result) {
                        if (!result || !result.success || !result.data) {
                            throw new Error(result && result.data && result.data.message ? result.data.message : 'Account setup could not be completed.');
                        }
                        if (result.data.status === 'logged_in' && result.data.redirect_to) {
                            window.location.assign(result.data.redirect_to);
                            return;
                        }
                        throw new Error('Account setup returned an unexpected response.');
                    }).catch(function (error) {
                        googleCompleteForm.dataset.ionSubmitting = '';
                        googleCompleteForm.classList.remove('ion-form-processing');
                        resetProcessingState(googleCompleteForm);
                        if (errorBox) {
                            errorBox.textContent = error && error.message ? error.message : 'Account setup could not be completed.';
                            errorBox.classList.add('is-visible');
                        }
                    });
                });
            });
        }

        function fillRecaptcha(form, done) {
            if (!window.IonPeptideLoginGate || IonPeptideLoginGate.recaptchaEnabled !== 'yes') {
                done();
                return;
            }

            var tokenInput = form.querySelector('.ion-recaptcha-token');
            if (!tokenInput) {
                done();
                return;
            }

            if (typeof grecaptcha === 'undefined' || !grecaptcha.ready) {
                done();
                return;
            }

            var finished = false;
            var timer = setTimeout(function () {
                if (finished) return;
                finished = true;
                done();
            }, 1800);

            try {
                grecaptcha.ready(function () {
                    grecaptcha.execute(IonPeptideLoginGate.recaptchaSiteKey, {
                        action: IonPeptideLoginGate.recaptchaAction || 'ion_login_gate'
                    }).then(function (token) {
                        if (finished) return;
                        finished = true;
                        clearTimeout(timer);
                        tokenInput.value = token || '';
                        done();
                    }).catch(function () {
                        if (finished) return;
                        finished = true;
                        clearTimeout(timer);
                        done();
                    });
                });
            } catch (e) {
                clearTimeout(timer);
                done();
            }
        }

        function setProcessing(form) {
            var submit = form.querySelector('button[type="submit"], input[type="submit"]');
            if (!submit) return;

            if (!submit.dataset.ionOriginalText) {
                submit.dataset.ionOriginalText = submit.tagName === 'BUTTON' ? submit.textContent : submit.value;
                if (submit.tagName === 'BUTTON') submit.dataset.ionOriginalHtml = submit.innerHTML;
            }

            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            submit.classList.add('ion-is-processing');

            if (submit.tagName === 'BUTTON') {
                submit.innerHTML = '<span class="ion-btn-spinner" aria-hidden="true"></span><span>Processing...</span>';
            } else {
                submit.value = 'Processing...';
            }
        }

        overlay.querySelectorAll('form.ion-gate-form').forEach(function (form) {
            if (form.hasAttribute('data-ion-google-complete-form')) return;
            form.addEventListener('submit', function (event) {
                if (form.dataset.ionSubmitting === '1') return;

                event.preventDefault();
                form.dataset.ionSubmitting = '1';
                form.classList.add('ion-form-processing');
                setProcessing(form);

                fillRecaptcha(form, function () {
                    HTMLFormElement.prototype.submit.call(form);
                });
            });
        });
    });
})();
