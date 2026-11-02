/**
 * Regression test for the WooCommerce Blocks integration (assets/blocks.js).
 *
 * Loads the real script in a stubbed browser/Blocks environment and asserts:
 *  1. the payment method registers under the gateway id 'thix' with the
 *     expected shape (content/edit/label/canMakePayment/supports);
 *  2. checkout submission after an inline iframe payment works on EVERY
 *     WooCommerce Blocks version: via the `onSubmit` prop when provided,
 *     via clicking the checkout's own Place Order button when not, and
 *     degrades to 'none' without throwing when neither exists.
 *
 * Run: node wordpress-plugin/3thix-payments/tests/test-blocks-submit.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let failures = 0;
function ok(cond, label) {
	if (cond) {
		console.log('ok    - ' + label);
	} else {
		failures++;
		console.log('FAIL  - ' + label);
	}
}

// ---- stubbed environment -------------------------------------------------
let registered = null;
const placeOrderBtn = {
	style: { display: 'none' },
	clicks: 0,
	click() { this.clicks++; },
};
let btnPresent = true;

const sandbox = {
	console,
	setTimeout,
	document: {
		querySelector(sel) {
			if (sel === '.wc-block-components-checkout-place-order-button' && btnPresent) {
				return placeOrderBtn;
			}
			return null;
		},
		createElement() { return { setAttribute() {}, addEventListener() {} }; },
		head: { appendChild() {} },
	},
};
sandbox.window = sandbox;
sandbox.window.wc = {
	wcBlocksRegistry: { registerPaymentMethod(cfg) { registered = cfg; } },
	wcSettings: {
		getSetting(key, fallback) {
			return key === 'thix_data'
				? { title: 'Credit Card Payments', supports: ['products', 'refunds'], providers: ['stripe'], inline: true }
				: fallback;
		},
	},
};
sandbox.window.wp = {
	element: {
		createElement() { return {}; },
		useEffect() {},
		useState(v) { return [v, function () {}]; },
		useRef(v) { return { current: v }; },
	},
	htmlEntities: { decodeEntities(s) { return s; } },
	i18n: { __(s) { return s; } },
};
sandbox.window.sessionStorage = { getItem() { return ''; }, setItem() {} };

const src = fs.readFileSync(path.resolve(__dirname, '../assets/blocks.js'), 'utf8');
vm.createContext(sandbox);
vm.runInContext(src, sandbox);

// ---- 1. registration shape -------------------------------------------------
ok(!!registered, 'registerPaymentMethod was called');
ok(registered && registered.name === 'thix', "payment method name is 'thix' (matches gateway id)");
ok(registered && typeof registered.canMakePayment === 'function' && registered.canMakePayment() === true, 'canMakePayment returns true');
ok(registered && registered.supports && String(registered.supports.features) === 'products,refunds', 'supports.features carries gateway supports');
ok(registered && 'content' in registered && 'edit' in registered && 'label' in registered, 'content/edit/label components provided');

// ---- 2. submission fallbacks ------------------------------------------------
const submit = sandbox.window.__thixBlocksTest && sandbox.window.__thixBlocksTest.submitCheckout;
ok(typeof submit === 'function', 'submitCheckout helper exposed for testing');

// (a) Modern Blocks: onSubmit prop provided → used, button untouched.
let calls = 0;
ok(submit(function () { calls++; }) === 'onSubmit' && calls === 1 && placeOrderBtn.clicks === 0,
	'uses the onSubmit prop when the Blocks version provides it');

// (b) onSubmit provided but throws → falls back to clicking Place Order.
placeOrderBtn.style.display = 'none';
ok(submit(function () { throw new Error('unsupported'); }) === 'button'
	&& placeOrderBtn.clicks === 1 && placeOrderBtn.style.display === '',
	'a throwing onSubmit falls back to un-hiding + clicking Place Order');

// (c) Older Blocks: no onSubmit prop at all → clicks Place Order.
placeOrderBtn.style.display = 'none';
ok(submit(undefined) === 'button' && placeOrderBtn.clicks === 2 && placeOrderBtn.style.display === '',
	'without an onSubmit prop, Place Order is un-hidden and clicked');

// (d) Nothing available → returns "none" without throwing (payment id is still
//     posted by onPaymentSetup when the buyer submits manually).
btnPresent = false;
ok(submit(undefined) === 'none', 'degrades to "none" without throwing when no button exists');

if (failures > 0) {
	console.log('\n' + failures + ' assertion(s) FAILED');
	process.exit(1);
}
console.log('\nall assertions passed');
