/**
 * 3thix Payments — WooCommerce Blocks (Gutenberg checkout block) integration.
 *
 * Registers the gateway as a native blocks payment method. In inline card mode
 * (display = inline + mode = card) the secure 3thix card iframe mounts right
 * inside the payment step: the buyer pays in place, then the checkout submits
 * with the resulting payment id + server-issued session token (the exact same
 * binding `process_inline_payment` enforces on the classic checkout).
 *
 * Plain ES5 on purpose — served unbundled, no build step.
 */
(function () {
	'use strict';

	if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var el = window.wp.element.createElement;
	var useEffect = window.wp.element.useEffect;
	var useState = window.wp.element.useState;
	var useRef = window.wp.element.useRef;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;
	var __ = window.wp.i18n.__;

	var settings = getSetting('thix_data', {});
	var TITLE = decodeEntities(settings.title || __('Credit Card Payments', 'thix-payments'));
	var DESCRIPTION = decodeEntities(settings.description || '');
	var PROVIDERS = settings.providers || ['stripe'];
	var PROVIDER_KEY = 'thix_provider_pick';
	var CARD_CURRENCY_UNSUPPORTED = String(settings.storeCurrency || '').toUpperCase() !== 'USD';

	function storedProvider() {
		try {
			var v = window.sessionStorage.getItem(PROVIDER_KEY) || '';
			return PROVIDERS.indexOf(v) !== -1 ? v : PROVIDERS[0];
		} catch (e) {
			return PROVIDERS[0];
		}
	}

	function storeProvider(v) {
		try { window.sessionStorage.setItem(PROVIDER_KEY, v); } catch (e) {}
	}

	/**
	 * Submit the block checkout after the iframe payment succeeded.
	 *
	 * Newer WooCommerce Blocks versions pass `onSubmit` to the payment method
	 * content component (usePaymentMethodInterface); older ones may not. Try
	 * it first, and fall back to un-hiding and clicking the checkout's own
	 * Place Order button — which always exists on the block checkout — so the
	 * order is placed on every Blocks version. `onPaymentSetup` supplies the
	 * stored payment id + token either way.
	 *
	 * @param {Function|undefined} onSubmit Prop from the payment method interface.
	 * @return {string} Which path submitted ('onSubmit' | 'button' | 'none').
	 */
	function submitCheckout(onSubmit) {
		if (typeof onSubmit === 'function') {
			try {
				onSubmit();
				return 'onSubmit';
			} catch (e) { /* fall through to the button */ }
		}
		var btn = document.querySelector('.wc-block-components-checkout-place-order-button');
		if (btn) {
			btn.style.display = '';
			btn.click();
			return 'button';
		}
		return 'none';
	}

	// Exposed for the regression test (tests/test-blocks-submit.js) only.
	window.__thixBlocksTest = { submitCheckout: submitCheckout };

	// Load the 3thix embed loader once; invokes cb when window.ThixEmbed exists.
	function loadEmbedJs(cb) {
		if (window.ThixEmbed) { cb(); return; }
		if (!settings.embedSrc) { return; }
		var s = document.querySelector('script[data-thix-embed-js]');
		if (!s) {
			s = document.createElement('script');
			s.src = settings.embedSrc;
			s.async = true;
			s.setAttribute('data-thix-embed-js', '1');
			document.head.appendChild(s);
		}
		s.addEventListener('load', function () { cb(); });
	}

	// All the buyer details the server needs before payment may be taken —
	// mirrors the classic checkout's required-fields gate.
	function billingComplete(billing) {
		var d = (billing && billing.billingData) || {};
		var required = ['first_name', 'last_name', 'address_1', 'city', 'country', 'email'];
		for (var i = 0; i < required.length; i++) {
			if (String(d[required[i]] == null ? '' : d[required[i]]).trim() === '') {
				return false;
			}
		}
		return true;
	}

	function panelStyle() {
		return {
			border: '1px solid #e3e6ea',
			borderRadius: '14px',
			background: '#fff',
			padding: '18px 18px 16px',
			boxShadow: '0 1px 3px rgba(16,24,40,.08)',
			margin: '10px 0 2px'
		};
	}

	function panelHead() {
		return el('div', { style: { display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '12px' } },
			el('span', { style: { fontWeight: 700, fontSize: '15px', color: '#101828' } },
				__('Pay securely by card', 'thix-payments')),
			el('span', {
				style: {
					marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: '6px',
					fontSize: '12px', fontWeight: 600, color: '#067647', background: '#ecfdf3',
					border: '1px solid #abefc6', padding: '4px 10px', borderRadius: '999px'
				}
			}, __('Encrypted', 'thix-payments'))
		);
	}

	function panelFoot() {
		return el('div', { style: { display: 'flex', alignItems: 'center', marginTop: '12px' } },
			el('span', { style: { marginLeft: 'auto', fontSize: '11.5px', color: '#98a2b3', display: 'inline-flex', alignItems: 'center', gap: '6px' } },
				el('span', { style: { width: '7px', height: '7px', borderRadius: '50%', background: '#2CE28D', display: 'inline-block' } }),
				' ',
				__('Powered by 3thix', 'thix-payments'))
		);
	}

	// Stripe / Coinbase selector (only when both providers are enabled).
	function ProviderPicker(props) {
		if (PROVIDERS.length < 2) { return null; }
		function opt(value, name, sub) {
			var sel = props.provider === value;
			return el('label', {
				key: value,
				style: {
					display: 'flex', alignItems: 'center', gap: '10px',
					border: '1px solid ' + (sel ? '#2CE28D' : '#e3e6ea'),
					boxShadow: sel ? '0 0 0 3px rgba(44,226,141,.16)' : 'none',
					borderRadius: '11px', background: '#fff', padding: '11px 13px', cursor: 'pointer'
				}
			},
				el('input', {
					type: 'radio', name: 'thix_provider_blocks', value: value, checked: sel,
					onChange: function () { props.onChange(value); }, style: { margin: 0 }
				}),
				el('span', null,
					el('span', { style: { display: 'block', fontWeight: 700, fontSize: '13.5px', color: '#101828' } }, name),
					el('span', { style: { display: 'block', fontSize: '12px', color: '#667085' } }, sub))
			);
		}
		return el('div', {
			role: 'radiogroup',
			'aria-label': __('Payment provider', 'thix-payments'),
			style: { display: 'flex', flexDirection: 'column', gap: '8px', margin: '0 0 12px' }
		},
			opt('stripe', __('Stripe', 'thix-payments'), __('ACH, Credit Card, Debit Card, Apple Pay, Google Pay', 'thix-payments')),
			opt('coinbase', __('Coinbase', 'thix-payments'), __('ACH, Debit Card, Apple Pay, Google Pay, PayPal, Wire', 'thix-payments'))
		);
	}

	function Content(props) {
		var billing = props.billing;
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var onSubmit = props.onSubmit;

		var providerState = useState(storedProvider());
		var provider = providerState[0];
		var setProvider = providerState[1];

		// Server-minted session token + the amount it was minted for.
		var tokenState = useState(null);
		var token = tokenState[0];
		var setToken = tokenState[1];

		var embedReadyState = useState(!!window.ThixEmbed);
		var embedReady = embedReadyState[0];
		var setEmbedReady = embedReadyState[1];

		var paidState = useState('');
		var paidId = paidState[0];
		var setPaidId = paidState[1];

		var containerRef = useRef(null);
		var mountedKeyRef = useRef('');
		var paidRef = useRef('');
		var paidTokenRef = useRef('');

		var inlineActive = !!settings.inline && !CARD_CURRENCY_UNSUPPORTED && provider !== 'coinbase';
		var cartTotal = billing && billing.cartTotal ? billing.cartTotal.value : 0;
		var gateOpen = billingComplete(billing);

		// Payment data handed to the Store API → bridged into $_POST for
		// process_payment (thix_provider always; payment id + token in inline mode).
		useEffect(function () {
			var unsubscribe = eventRegistration.onPaymentSetup(function () {
				if (CARD_CURRENCY_UNSUPPORTED && provider !== 'coinbase') {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __('3thix direct card payments currently support USD orders only. Please choose another payment method.', 'thix-payments')
					};
				}
				var data = { thix_provider: provider };
				if (inlineActive) {
					if (!paidRef.current) {
						return {
							type: emitResponse.responseTypes.ERROR,
							message: __('Please complete the secure card payment before placing your order.', 'thix-payments')
						};
					}
					data.thix_payment_id = paidRef.current;
					data.thix_eoid = paidTokenRef.current;
				}
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: { paymentData: data }
				};
			});
			return unsubscribe;
		}, [provider, inlineActive]);

		// Load the 3thix embed loader once inline mode is in play.
		useEffect(function () {
			if (!inlineActive || embedReady) { return; }
			var cancelled = false;
			loadEmbedJs(function () { if (!cancelled) { setEmbedReady(true); } });
			return function () { cancelled = true; };
		}, [inlineActive, embedReady]);

		// Mint (or re-mint) the server session token whenever the cart total
		// changes — the token is amount-bound so a stale total can't be charged.
		useEffect(function () {
			if (!inlineActive || !settings.ajaxUrl) { return; }
			var cancelled = false;
			var body = new window.URLSearchParams();
			body.set('action', 'thix_inline_token');
			body.set('nonce', settings.tokenNonce || '');
			window.fetch(settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			}).then(function (r) { return r.json(); }).then(function (res) {
				if (cancelled || !res || !res.success || !res.data || !res.data.token) { return; }
				setToken({ token: String(res.data.token), amountCents: parseInt(res.data.amountCents, 10) || 0 });
			}).catch(function () {});
			return function () { cancelled = true; };
		}, [inlineActive, cartTotal]);

		// Mount / remount the secure card iframe when everything is ready.
		useEffect(function () {
			if (!inlineActive || !gateOpen || !embedReady || !token || !window.ThixEmbed) { return; }
			var node = containerRef.current;
			if (!node) { return; }
			var key = token.amountCents + '|' + token.token;
			if (mountedKeyRef.current === key) { return; }
			mountedKeyRef.current = key;
			try { node.innerHTML = ''; } catch (e) {}
			node.removeAttribute('data-thix-mounted');
			var email = (billing && billing.billingData && billing.billingData.email) || '';
			var brand = settings.brand || {};
			window.ThixEmbed.inline(node, {
				apiKey: settings.publishableKey,
				amountCents: token.amountCents,
				orderId: token.token,
				email: email || undefined,
				mode: 'card',
				currency: 'usd',
				theme: brand.theme || undefined,
				accent: brand.accent || undefined,
				logo: brand.logo || undefined,
				brand: brand.brand || undefined,
				minHeight: 320,
				onSuccess: function (d) {
					paidRef.current = (d && d.paymentId) ? String(d.paymentId) : '';
					paidTokenRef.current = token.token;
					setPaidId(paidRef.current);
					// Defer one tick so the paid-state update has restored the
					// Place Order button before the fallback path clicks it.
					window.setTimeout(function () { submitCheckout(onSubmit); }, 0);
				}
			});
		}, [inlineActive, gateOpen, embedReady, token]);

		// The buyer pays inside the iframe, so hide the checkout's own Place
		// Order button while the card form is live. Once paid, restore it so a
		// late validation error can be fixed and the order re-submitted (the
		// stored payment id is reused — the buyer is never charged twice).
		useEffect(function () {
			var hide = inlineActive && !paidId;
			var btn = document.querySelector('.wc-block-components-checkout-place-order-button');
			if (!btn) { return; }
			btn.style.display = hide ? 'none' : '';
			return function () { btn.style.display = ''; };
		}, [inlineActive, paidId]);

		var children = [panelHead()];
		if (CARD_CURRENCY_UNSUPPORTED && provider !== 'coinbase') {
			children.push(el('p', { key: 'currency', style: { margin: '0 0 12px', color: '#b42318', fontSize: '13.5px', lineHeight: 1.55 } },
				__('3thix direct card payments currently support USD orders only. Please choose another payment method.', 'thix-payments')));
		}
		children.push(el(ProviderPicker, {
			key: 'picker',
			provider: provider,
			onChange: function (v) { storeProvider(v); setProvider(v); }
		}));

		if (inlineActive) {
			children.push(el('div', { key: 'wrap', style: { position: 'relative', minHeight: '120px' } },
				el('div', { ref: containerRef }),
				(!gateOpen || !token || !embedReady) ? el('div', {
					style: {
						position: 'absolute', inset: 0, display: 'flex', alignItems: 'center',
						justifyContent: 'center', textAlign: 'center', padding: '22px',
						background: 'rgba(255,255,255,.94)', border: '1px dashed #cfd4dc',
						borderRadius: '12px', color: '#475467', fontSize: '13.5px', lineHeight: 1.55, zIndex: 3
					},
					role: 'status'
				}, gateOpen
					? __('Loading your secure card form…', 'thix-payments')
					: __('Fill in your details above — your secure card form will appear here to complete payment.', 'thix-payments')
				) : null
			));
			if (paidId) {
				children.push(el('div', { key: 'paid', style: { marginTop: '10px', fontSize: '13px', color: '#067647' } },
					__('Payment received — placing your order…', 'thix-payments')));
			}
		} else if (DESCRIPTION) {
			children.push(el('p', { key: 'desc', style: { margin: '0 0 4px', color: '#475467', fontSize: '13.5px', lineHeight: 1.55 } }, DESCRIPTION));
		}

		children.push(panelFoot());
		return el('div', { style: panelStyle() }, children);
	}

	// Static preview for the block editor — no session tokens or iframes there.
	function EditContent() {
		return el('div', { style: panelStyle() },
			panelHead(),
			DESCRIPTION ? el('p', { style: { margin: 0, color: '#475467', fontSize: '13.5px' } }, DESCRIPTION) : null,
			panelFoot());
	}

	function Label() {
		var parts = [el('span', { key: 't', style: { marginRight: '8px' } }, TITLE)];
		if (settings.iconHtml) {
			parts.push(el('span', { key: 'i', dangerouslySetInnerHTML: { __html: settings.iconHtml } }));
		}
		return el('span', { style: { display: 'inline-flex', alignItems: 'center', width: '100%' } }, parts);
	}

	registerPaymentMethod({
		name: 'thix',
		label: el(Label),
		content: el(Content),
		edit: el(EditContent),
		canMakePayment: function () { return true; },
		ariaLabel: TITLE,
		supports: {
			features: settings.supports || ['products']
		}
	});
})();
