(function ($) {
	'use strict';

	var cfg = window.wc_nmi4_google_pay_params || {};
	var state = {
		ready: false,
		loading: false,
		tokenReady: false,
		submittingAfterToken: false,
		lastSignature: ''
	};

	function gatewaySelector() {
		return 'input[name="payment_method"][value="' + cfg.gateway_id + '"]';
	}

	function isSelected() {
		return $(gatewaySelector()).is(':checked');
	}

	function supportsGooglePay() {
		return true;
	}

	function status(message, type) {
		var $status = $('#nmi4-google-pay-status');
		if (!$status.length) {
			return;
		}
		$status.removeClass('error success').addClass(type || '').text(message || '');
	}

	function clearToken() {
		state.tokenReady = false;
		$('#nmi4_google_pay_token').val('');
		$('#nmi4_google_pay_response').val('');
	}

	function normalizePrice(value) {
		value = (value || '').toString();
		value = value.replace(/[^0-9.]/g, '');
		if (!value) {
			value = cfg.price || '0.00';
		}
		var parts = value.split('.');
		if (parts.length > 2) {
			value = parts.shift() + '.' + parts.join('');
		}
		var parsed = parseFloat(value);
		if (isNaN(parsed) || parsed < 0) {
			parsed = 0;
		}
		return parsed.toFixed(2);
	}

	function extractMoney(text) {
		text = (text || '').toString();
		var matches = text.match(/(?:[$€£]	*\s*)?[0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{2})|[0-9]+(?:\.[0-9]{2})/g);
		if (!matches || !matches.length) {
			return '';
		}
		return matches[matches.length - 1];
	}

	function currentPrice() {
		// Prefer the final Woo/FunnelKit order total over the PHP-rendered data-price.
		// Checkout add-ons can change totals after fragments render; Google Pay must be
		// tokenized for the exact amount Woo will send to NMI.
		var selectors = [
			'#order_review .order-total .woocommerce-Price-amount',
			'#order_review .order-total .amount',
			'.woocommerce-checkout-review-order-table .order-total .woocommerce-Price-amount',
			'.woocommerce-checkout-review-order-table .order-total .amount',
			'.wfacp_order_total .woocommerce-Price-amount',
			'.wfacp_order_total .amount',
			'.wfacp_order_summary_total .woocommerce-Price-amount',
			'.wfacp_order_summary_total .amount',
			'.wfacp_order_summary_container .order-total .woocommerce-Price-amount',
			'.wfacp_order_summary_container .order-total .amount',
			'.wfacp_mini_cart_start_h .woocommerce-Price-amount',
			'.wfacp_show_price_wrap .woocommerce-Price-amount',
			'.order-total .woocommerce-Price-amount',
			'.order-total .amount'
		];

		for (var i = 0; i < selectors.length; i++) {
			var $els = $(selectors[i]);
			for (var j = $els.length - 1; j >= 0; j--) {
				var money = extractMoney($($els[j]).text());
				if (money) {
					return normalizePrice(money);
				}
			}
		}

		var fieldPrice = $('.nmi4-google-pay-payment-data').last().attr('data-price');
		if (fieldPrice) {
			return normalizePrice(fieldPrice);
		}

		return normalizePrice(cfg.price);
	}

	function selectFirstVisibleGatewayIfNeeded() {
		if (!$(gatewaySelector()).is(':checked')) {
			return;
		}
		var $first = $('ul.payment_methods li:visible input[name="payment_method"]').not(gatewaySelector()).first();
		if ($first.length) {
			$first.prop('checked', true).trigger('change');
		}
	}

	function applyAvailability() {
		$('body').removeClass('nmi4-google-pay-unavailable');
		return true;
	}

	function removeExistingCollectJs() {
		$('#nmi4-google-pay-collectjs').remove();
		$('#nmi4-apple-pay-collectjs').remove();
		$('#nmi4-google-pay-button').empty();
		state.ready = false;
		state.loading = false;
	}

	function scriptSignature() {
		return [
			cfg.public_key || '',
			currentPrice(),
			cfg.currency || 'USD',
			cfg.country || 'US',
			cfg.request_billing || 'yes',
			cfg.request_shipping || 'no'
		].join('|');
	}

	function addGooglePayAttributes(script) {
		var price = currentPrice();
		$('#nmi4_google_pay_amount').val(price);

		script.setAttribute('data-tokenization-key', cfg.public_key || '');
		script.setAttribute('data-variant', 'inline');
		script.setAttribute('data-price', price);
		script.setAttribute('data-currency', cfg.currency || 'USD');
		script.setAttribute('data-country', cfg.country || 'US');
		script.setAttribute('data-field-google-pay-selector', cfg.button_selector || '#nmi4-google-pay-button');

		if (cfg.request_billing === 'yes') {
			script.setAttribute('data-field-google-pay-billing-address-required', 'true');
			script.setAttribute('data-field-google-pay-billing-address-parameters-phone-number-required', 'true');
			script.setAttribute('data-field-google-pay-billing-address-parameters-format', 'FULL');
		}

		if (cfg.request_shipping === 'yes') {
			script.setAttribute('data-field-google-pay-shipping-address-required', 'true');
			script.setAttribute('data-field-google-pay-shipping-address-parameters-phone-number-required', 'true');
			script.setAttribute('data-field-google-pay-shipping-address-parameters-allowed-country-codes', cfg.country || 'US');
		}
	}

	function configureCollectJs() {
		if (!window.CollectJS || typeof window.CollectJS.configure !== 'function') {
			status(cfg.i18n_script_error || 'Google Pay could not load.', 'error');
			return;
		}

		try {
			window.CollectJS.configure({
				callback: handleTokenResponse,
				timeoutDuration: 20000,
				timeoutCallback: function () {
					state.loading = false;
					status(cfg.i18n_timeout || 'Google Pay timed out.', 'error');
				},
				fieldsAvailableCallback: function () {
					state.ready = true;
					state.loading = false;
					status(cfg.i18n_ready || 'Click Google Pay to continue.', 'success');
				}
			});
			state.ready = true;
			state.loading = false;
			status(cfg.i18n_ready || 'Click Google Pay to continue.', 'success');
		} catch (err) {
			state.ready = false;
			state.loading = false;
			status((err && err.message) ? err.message : (cfg.i18n_script_error || 'Google Pay could not load.'), 'error');
		}
	}

	function loadCollectJs(force) {
		if (!isSelected()) {
			return;
		}

		if (!applyAvailability()) {
			return;
		}

		if (!cfg.public_key) {
			status('Missing NMI public tokenization key.', 'error');
			return;
		}

		if (!$(cfg.button_selector || '#nmi4-google-pay-button').length) {
			return;
		}

		var signature = scriptSignature();
		if (!force && state.ready && state.lastSignature === signature) {
			return;
		}

		clearToken();
		removeExistingCollectJs();
		state.loading = true;
		state.lastSignature = signature;
		status(cfg.i18n_loading || 'Loading Google Pay…', '');

		var script = document.createElement('script');
		script.id = 'nmi4-google-pay-collectjs';
		script.src = cfg.collect_js_url || 'https://secure.nmi.com/token/Collect.js';
		script.async = true;
		addGooglePayAttributes(script);

		script.onload = function () {
			configureCollectJs();
		};

		script.onerror = function () {
			state.ready = false;
			state.loading = false;
			status(cfg.i18n_script_error || 'Google Pay could not load.', 'error');
		};

		document.head.appendChild(script);
	}

	function setField(selector, value) {
		if (value === undefined || value === null || value === '') {
			return;
		}
		var $field = $(selector);
		if (!$field.length) {
			return;
		}

		// Do not trigger change/update_checkout after Google Pay tokenization.
		// On Ion this changed Woo's final amount after the Google Pay token was created,
		// causing NMI: Google Pay token amount is different from API amount.
		if (!$field.val()) {
			$field.val(value);
		}
	}

	function fillCheckoutFields(response) {
		if (!response || !response.wallet) {
			return;
		}

		var wallet = response.wallet || {};
		var billing = wallet.billingInfo || {};
		var shipping = wallet.shippingInfo || {};

		setField('#billing_first_name', billing.firstName);
		setField('#billing_last_name', billing.lastName);
		setField('#billing_address_1', billing.address1);
		setField('#billing_address_2', billing.address2);
		setField('#billing_city', billing.city);
		setField('#billing_country', billing.country);
		setField('#billing_postcode', billing.postalCode);
		setField('#billing_phone', billing.phone);
		setField('#billing_email', wallet.email || billing.email);
		setField('#billing_state', billing.state);

		window.setTimeout(function () {
			setField('#billing_state', billing.state);
		}, 350);

		if (shipping && Object.keys(shipping).length) {
			setField('#shipping_first_name', shipping.firstName);
			setField('#shipping_last_name', shipping.lastName);
			setField('#shipping_address_1', shipping.address1);
			setField('#shipping_address_2', shipping.address2);
			setField('#shipping_city', shipping.city);
			setField('#shipping_country', shipping.country);
			setField('#shipping_postcode', shipping.postalCode);
			setField('#shipping_state', shipping.state);

			window.setTimeout(function () {
				setField('#shipping_state', shipping.state);
			}, 350);
		}
	}

	function handleTokenResponse(response) {
		if (!response || !response.token) {
			status(cfg.i18n_token_error || 'Google Pay could not create a payment token.', 'error');
			return;
		}

		if (response.tokenType && response.tokenType !== 'googlePay') {
			status(cfg.i18n_token_error || 'Google Pay could not create a payment token.', 'error');
			return;
		}

		$('#nmi4_google_pay_amount').val(currentPrice());
		$('#nmi4_google_pay_token').val(response.token);
		try {
			$('#nmi4_google_pay_response').val(JSON.stringify(response));
		} catch (err) {
			$('#nmi4_google_pay_response').val('');
		}

		fillCheckoutFields(response);
		state.tokenReady = true;
		state.submittingAfterToken = true;
		status('Google Pay authorized. Submitting order…', 'success');

		window.setTimeout(function () {
			$('form.checkout').trigger('submit');
		}, 250);
	}

	function requestGooglePayFromPlaceOrder(event) {
		if (!window.CollectJS || typeof window.CollectJS.startPaymentRequest !== 'function' || state.lastSignature !== scriptSignature()) {
			loadCollectJs(true);
			status(cfg.i18n_use_button || 'Please complete Google Pay first.', 'error');
			return false;
		}

		try {
			status('Opening Google Pay…', '');
			window.CollectJS.startPaymentRequest(event || window.event);
		} catch (err) {
			status((err && err.message) ? err.message : (cfg.i18n_token_error || 'Google Pay could not start.'), 'error');
		}
		return false;
	}

	function bindCheckout() {
		$(document.body).on('payment_method_selected updated_checkout', function () {
			window.setTimeout(function () {
				applyAvailability();
				if (isSelected()) {
					loadCollectJs(true);
				}
			}, 150);
		});

		$(document).on('change', 'input[name="payment_method"]', function () {
			if (isSelected()) {
				loadCollectJs(true);
			}
		});

		$('form.checkout').on('checkout_place_order_' + cfg.gateway_id, function (event) {
			if (!isSelected()) {
				return true;
			}

			if ($('#nmi4_google_pay_token').val()) {
				return true;
			}

			return requestGooglePayFromPlaceOrder(event);
		});

		// If a checkout error occurs after Google Pay tokenization, clear the one-use token.
		$(document.body).on('checkout_error', function () {
			if (isSelected()) {
				clearToken();
				state.submittingAfterToken = false;
				loadCollectJs(true);
			}
		});
	}

	$(function () {
		bindCheckout();
		applyAvailability();
		if (isSelected()) {
			loadCollectJs(true);
		}
	});
})(jQuery);
