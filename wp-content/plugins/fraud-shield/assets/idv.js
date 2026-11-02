/**
 * Fraud Shield — ID verification (Didit) checkout flow.
 *
 * Renders/uses the #fs-idv-box notice, opens the Didit hosted verification in
 * a popup, then polls until the decision lands. On approval the payment
 * methods refresh: classic checkout re-renders its fragments, the blocks
 * checkout reloads the page once no order submission is in flight.
 */
/* global FS_IDV_JS, jQuery */
(function ($) {
	'use strict';

	if (typeof FS_IDV_JS === 'undefined') {
		return;
	}

	var cfg = FS_IDV_JS;
	var pollTimer = null;
	var popup = null;
	var isClassic = false;

	// Count in-flight blocks-checkout submissions (POST /wc/store/…/checkout).
	// Didit's hosted pages send Cross-Origin-Opener-Policy, which severs
	// window.opener in the popup — so completion signalling and the approval
	// refresh below must never assume the popup/opener wiring survived, and
	// the refresh must never reload the page out from under a payment that is
	// being processed: that abandons the redirect to the thank-you page, the
	// cart survives, and the customer can pay a second time (duplicate order).
	var checkoutBusy = 0;
	if (window.fetch) {
		var nativeFetch = window.fetch;
		window.fetch = function (input, init) {
			var url = (typeof input === 'string') ? input : ((input && input.url) || '');
			var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
			var isCheckoutPost = method === 'POST' && url.indexOf('/wc/store') !== -1 && url.indexOf('checkout') !== -1;
			if (isCheckoutPost) {
				checkoutBusy++;
			}
			var promise = nativeFetch.apply(this, arguments);
			if (isCheckoutPost) {
				promise.then(function () { checkoutBusy--; }, function () { checkoutBusy--; });
			}
			return promise;
		};
	}

	// Which way this window left for Didit: 'popup' (window.open succeeded) or
	// 'tab' (popup blocked, this tab navigated away). The return page reads it
	// to decide "close myself" vs "travel back to checkout" — it cannot rely
	// on window.opener because COOP severs it.
	function setMode(mode) {
		try { window.localStorage.setItem('fs_idv_mode', mode); } catch (e) { /* noop */ }
	}

	// The one session THIS flow is about. Seeded by the server when a session
	// is already in flight, set from fs_idv_start's answer, and sent with every
	// status poll — so the poll asks "what happened to MY session", never "is
	// anyone who looks like me verified". sessionStorage carries it across the
	// same-tab redirect used when the popup is blocked.
	var sessionId = cfg.session || '';
	try {
		if (!sessionId) {
			sessionId = window.sessionStorage.getItem('fs_idv_session') || '';
		}
	} catch (e) { /* storage unavailable — the server-seeded id still works */ }

	function rememberSession(id) {
		if (!id) {
			return;
		}
		sessionId = id;
		try { window.sessionStorage.setItem('fs_idv_session', id); } catch (e) { /* noop */ }
	}

	function forgetSession() {
		sessionId = '';
		try { window.sessionStorage.removeItem('fs_idv_session'); } catch (e) { /* noop */ }
	}

	function box() {
		return $('#fs-idv-box');
	}

	/**
	 * Where an injected card goes: above the blocks checkout when present,
	 * otherwise above the classic form (normally the PHP hook already printed
	 * the card there, but a theme with a custom payment template may have
	 * dropped the hook — this keeps the card reachable regardless).
	 */
	function injectionTarget() {
		return document.querySelector('.wp-block-woocommerce-checkout') ||
			document.querySelector('form.checkout');
	}

	/**
	 * Put the server-rendered card into the page: replace the one that is
	 * there, inject when there is none, remove it when the server now says
	 * there is nothing to show. Retries while the checkout is still painting
	 * (the blocks checkout renders asynchronously).
	 *
	 * @param {string} html    Rendered card ('' = nothing to show).
	 * @param {number} attempt Internal retry counter.
	 */
	function renderBox(html, attempt) {
		var $existing = box();
		if (!html) {
			// Nothing applies for this identity anymore — but never yank an
			// in-flight flow out from under the customer.
			if ($existing.length && !pollTimer && $existing.attr('data-state') !== 'pending') {
				$existing.remove();
			}
			return;
		}
		if ($existing.length) {
			$existing.replaceWith(html);
			return;
		}
		var target = injectionTarget();
		if (!target) {
			// The checkout may not have painted yet — retry briefly.
			attempt = attempt || 0;
			if (attempt < 20) {
				setTimeout(function () { renderBox(html, attempt + 1); }, 500);
			}
			return;
		}
		$(html).insertBefore(target);
	}

	/**
	 * Re-ask the server what this checkout should show for the identity now on
	 * the form. The page-load answer is provisional on a guest checkout: the
	 * session had no email yet, so nothing was shown — then the customer types
	 * the email of a restricted or velocity-locked identity and a verification
	 * card is exactly what they need to see. Classic checkout re-renders its
	 * card server-side through fragments, but only this call can (re)start the
	 * polling and surface the card on the blocks checkout.
	 */
	var refreshTimer = null;
	var lastRefreshKey = null;

	function refreshState() {
		if (pollTimer) {
			return; // An active flow owns the card; the poll will update it.
		}
		var email = customerFields().email || '';
		var key = email.toLowerCase();
		if (key === lastRefreshKey) {
			return;
		}
		lastRefreshKey = key;

		var body = { action: 'fs_idv_state', nonce: cfg.nonce, email: email };
		$.post(cfg.ajax, body).done(function (res) {
			if (!res || !res.success || !res.data) {
				return;
			}
			if (pollTimer) {
				return; // A flow started while we were asking.
			}
			renderBox(res.data.html || '');
			if (res.data.state === 'pending') {
				rememberSession(res.data.session);
				startPolling();
			}
		}).fail(function () {
			// Allow the next trigger to retry the same identity.
			lastRefreshKey = null;
		});
	}

	function queueRefresh(delay) {
		if (refreshTimer) {
			clearTimeout(refreshTimer);
		}
		refreshTimer = setTimeout(function () {
			refreshTimer = null;
			refreshState();
		}, delay || 600);
	}

	function fieldVal(selectors) {
		for (var i = 0; i < selectors.length; i++) {
			var el = document.querySelector(selectors[i]);
			if (el && el.value) {
				return el.value;
			}
		}
		return '';
	}

	function customerFields() {
		return {
			// Classic checkout ids first, then blocks checkout ids.
			email: fieldVal(['#billing_email', '#email']),
			first_name: fieldVal(['#billing_first_name', '#billing-first_name', '#shipping-first_name']),
			last_name: fieldVal(['#billing_last_name', '#billing-last_name', '#shipping-last_name'])
		};
	}

	function setSpinner(on) {
		box().find('.fs-idv-spinner').toggleClass('is-on', !!on);
		box().find('.fs-idv-btn').prop('disabled', !!on);
	}

	function setText(text) {
		box().find('.fs-idv-text').text(text);
	}

	function start() {
		var f = customerFields();
		setSpinner(true);

		$.post(cfg.ajax, {
			action: 'fs_idv_start',
			nonce: cfg.nonce,
			email: f.email,
			first_name: f.first_name,
			last_name: f.last_name,
			context: box().data('context') === 'page' ? 'page' : 'checkout'
		}).done(function (res) {
			if (!res || !res.success || !res.data || !res.data.url) {
				setSpinner(false);
				setText((res && res.data && res.data.message) ? res.data.message : cfg.texts.error);
				return;
			}
			rememberSession(res.data.session);
			setMode('popup');
			popup = window.open(res.data.url, 'fs_idv', 'width=480,height=780,menubar=no,toolbar=no');
			if (!popup) {
				// Popup blocked — same-tab fallback (Didit redirects back after).
				setMode('tab');
				setSpinner(false);
				setText(cfg.texts.popup);
				window.location.href = res.data.url;
				return;
			}
			startPolling();
		}).fail(function (jqXHR) {
			// Errors ride non-2xx responses — surface the specific message
			// (rate limit, missing email) instead of the generic one.
			var msg = (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message)
				? jqXHR.responseJSON.data.message
				: cfg.texts.error;
			setSpinner(false);
			setText(msg);
		});
	}

	function startPolling() {
		if (pollTimer) {
			return;
		}
		pollTimer = setInterval(poll, cfg.poll_ms || 4000);
		poll();
	}

	function stopPolling() {
		if (pollTimer) {
			clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	function poll() {
		var f = customerFields();
		var body = {
			action: 'fs_idv_status',
			nonce: cfg.nonce,
			email: f.email
		};
		if (sessionId) {
			body.session = sessionId;
		}
		$.post(cfg.ajax, body).done(function (res) {
			if (!res || !res.success || !res.data) {
				return;
			}
			var state = res.data.state;
			if (state === 'approved') {
				stopPolling();
				forgetSession();
				onApproved();
			} else if (state === 'declined' || state === 'mismatch') {
				stopPolling();
				forgetSession();
				setSpinner(false);
				if (res.data.message) {
					setText(res.data.message);
				}
			} else if (state === 'expired' || (state === 'none' && sessionId)) {
				// The session we were polling died (Didit expired it, or it is
				// simply gone). Waiting on it would mean a spinner forever —
				// stop, explain, and re-render a fresh card so the button
				// invites a clean second attempt.
				stopPolling();
				forgetSession();
				setSpinner(false);
				if (res.data.message) {
					setText(res.data.message);
				}
				lastRefreshKey = null;
				setTimeout(function () { refreshState(); }, 4000);
			}
			// 'pending' → keep waiting while the timer runs.
		});
	}

	function onApproved() {
		if (popup && !popup.closed) {
			try { popup.close(); } catch (e) { /* noop */ }
		}
		setSpinner(false);

		// Turn the invitation into the green confirmation in place, so the customer
		// is told it worked rather than watching the box vanish.
		var $b = box();
		$b.attr('data-state', 'approved').addClass('fs-card--ok');
		$b.find('.fs-card-title').text(cfg.texts.verified_title || 'You have been verified.');
		$b.find('.fs-card-text').text(cfg.texts.verified || '');
		$b.find('.fs-card-actions, .fs-card-fine').remove();

		if (box().data('context') === 'page') {
			return;
		}
		if (isClassic) {
			// Re-render the payment fragment so the unlocked gateways appear. Our
			// card lives above it and survives the fragment refresh.
			$(document.body).trigger('update_checkout');
		} else {
			reloadWhenSafe(false);
		}
	}

	/**
	 * Reload the blocks checkout so the unlocked gateways appear — but only
	 * when no order submission is in flight. Reloading mid-payment abandons
	 * the redirect to the thank-you page while the server completes the order;
	 * the cart survives and the customer can pay again — a duplicate order.
	 *
	 * @param {boolean} waited Whether a submission was seen in flight already.
	 */
	function reloadWhenSafe(waited) {
		if (checkoutBusy > 0) {
			setTimeout(function () { reloadWhenSafe(true); }, 800);
			return;
		}
		if (waited) {
			// A submission just settled. Give a successful payment's redirect
			// time to leave this page; reload only if we are still here.
			setTimeout(function () {
				if (checkoutBusy === 0 && document.querySelector('.wp-block-woocommerce-checkout')) {
					window.location.reload();
				}
			}, 2500);
			return;
		}
		window.location.reload();
	}

	$(function () {
		isClassic = $('form.checkout').length > 0;

		// Paint whatever the server decided at page load (the blocks checkout
		// receives its card only through this injection).
		if (cfg.offer_html) {
			renderBox(cfg.offer_html);
			lastRefreshKey = (customerFields().email || '').toLowerCase();
		}

		// (Re)bind through delegation: the classic checkout re-renders the
		// payment fragment (and our box with it) on every update_checkout, and
		// injected cards arrive after this handler is installed.
		$(document.body).on('click', '.fs-idv-btn', function (e) {
			e.preventDefault();
			start();
		});

		// Live identity: the page-load answer only covered the identity the
		// session knew THEN. Re-check when the classic checkout refreshes
		// (WooCommerce has just pushed the typed fields into the session) and
		// when an email field settles on either checkout — that is the moment
		// a guest becomes "a restricted customer who needs the button".
		$(document.body).on('updated_checkout', function () {
			queueRefresh(400);
		});
		$(document).on('change focusout', '#billing_email, #email', function () {
			var v = String(this.value || '');
			if (v.indexOf('@') > 0) {
				queueRefresh(700);
			}
		});

		// Autofill / back-forward cache: the form may already carry an email
		// the session never saw, with nothing rendered at load.
		if (cfg.state === 'none' && !box().length && (customerFields().email || '').indexOf('@') > 0) {
			queueRefresh(1200);
		}

		// The popup's return page pings us the moment Didit redirects back —
		// postMessage when the opener link survived, and a localStorage write
		// (the cross-window 'storage' event) when Didit's COOP severed it.
		window.addEventListener('message', function (event) {
			if (event && event.data === 'fs-idv-complete') {
				startPolling();
			}
		});
		window.addEventListener('storage', function (event) {
			if (event && event.key === 'fs_idv_done') {
				startPolling();
			}
		});

		if (cfg.state === 'pending') {
			startPolling();
		}
	});
})(jQuery);
