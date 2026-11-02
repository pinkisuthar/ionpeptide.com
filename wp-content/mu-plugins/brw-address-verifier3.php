<?php
/**
 * Plugin Name: BRW Address Verifier
 * Description: Google Places autocomplete, checkout autofill, and Address Validation API support for WooCommerce checkout.
 * Author: Buildwright Web
 * Author URI: https://BuildwrightWeb.com
 * Plugin URI: https://BuildwrightWeb.com
 * Version: 1.1.6
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CP_Google_Address_Verification')) {
    final class CP_Google_Address_Verification {
        private const API_KEY = 'AIzaSyD96oR4FppwsqgRuFqUCotf4vM8R9GXPzg';

        /**
         * Countries allowed for autocomplete and validation.
         * Use ISO 3166-1 alpha-2 codes.
         */
        private const ALLOWED_COUNTRIES = ['US'];

        /**
         * Validation behavior.
         */
        private const VALIDATE_BILLING  = true;
        private const VALIDATE_SHIPPING = true;
        private const STRICT_MODE       = false; // true = block more aggressively, false = only block clearly bad addresses

        public function __construct() {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts'], 30);
            add_filter('woocommerce_checkout_fields', [$this, 'make_address_line_two_visible'], 9999);
            add_filter('woocommerce_checkout_posted_data', [$this, 'sync_shipping_from_billing_when_same'], 20);
            add_action('woocommerce_after_checkout_validation', [$this, 'validate_checkout_addresses'], 20, 2);
        }


        /**
         * FunnelKit on this checkout uses a visible checkbox named shipping_same_as_billing,
         * but the label says "Use a different shipping address". When that box is NOT checked,
         * keep the hidden shipping fields synced with billing so shipping/payment validators never
         * see an empty shipping ZIP while the customer is using billing as shipping.
         */
        public function sync_shipping_from_billing_when_same(array $data): array {
            if ($this->is_different_shipping_selected($data)) {
                return $data;
            }

            $keys = [
                'first_name',
                'last_name',
                'company',
                'country',
                'address_1',
                'address_2',
                'city',
                'state',
                'postcode',
                'phone',
            ];

            foreach ($keys as $key) {
                $billing_key  = 'billing_' . $key;
                $shipping_key = 'shipping_' . $key;

                if (array_key_exists($billing_key, $data)) {
                    $data[$shipping_key] = $data[$billing_key];
                }
            }

            if (!empty($data['shipping_country']) && isset($data['shipping_postcode'])) {
                $data['shipping_postcode'] = $this->normalize_postcode((string) $data['shipping_postcode'], (string) $data['shipping_country']);
            }

            // Make sure WooCommerce core also treats this as same-as-billing.
            $data['ship_to_different_address'] = '';

            return $data;
        }

        private function is_different_shipping_selected(array $data): bool {
            // Native WooCommerce checkbox.
            if (isset($data['ship_to_different_address']) && $this->is_truthy($data['ship_to_different_address'])) {
                return true;
            }

            // FunnelKit field on this checkout. Name is misleading; the label is "Use a different shipping address".
            if (isset($data['shipping_same_as_billing']) && $this->is_truthy($data['shipping_same_as_billing'])) {
                return true;
            }

            return false;
        }

        private function is_truthy($value): bool {
            if (is_bool($value)) {
                return $value;
            }

            $value = strtolower(trim((string) $value));
            return in_array($value, ['1', 'yes', 'true', 'on'], true);
        }

        /**
         * Keep address line 2 visible when checkout builders respect WooCommerce fields.
         * This does not click/toggle FunnelKit elements and is intentionally low-risk.
         */
        public function make_address_line_two_visible(array $fields): array {
            foreach (['billing', 'shipping'] as $section) {
                $key = $section . '_address_2';

                if (empty($fields[$section][$key]) || !is_array($fields[$section][$key])) {
                    continue;
                }

                // FunnelKit uses floating labels/placeholders. Leaving a real label here
                // creates the doubled/overlapped text seen on checkout, so address_2 stays
                // placeholder-only just like WooCommerce normally expects.
                $fields[$section][$key]['label']        = '';
                $fields[$section][$key]['placeholder']  = __('Apartment, suite, unit, etc. (optional)', 'cp-google-address-verification');
                $fields[$section][$key]['required']     = false;
                $classes = isset($fields[$section][$key]['class']) && is_array($fields[$section][$key]['class'])
                    ? $fields[$section][$key]['class']
                    : [];

                $classes[] = 'form-row-wide';
                $classes[] = 'address-field';

                if ($section === 'billing') {
                    $classes[] = 'brw-address2-always-open';
                }

                $fields[$section][$key]['class'] = array_values(array_unique(array_filter($classes)));
                $fields[$section][$key]['label_class']  = ['screen-reader-text'];
                $fields[$section][$key]['clear']        = true;
                $fields[$section][$key]['autocomplete'] = 'address-line2';

                if (empty($fields[$section][$key]['priority'])) {
                    $fields[$section][$key]['priority'] = 65;
                }
            }

            return $fields;
        }

        public function enqueue_scripts(): void {
            if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
                return;
            }

            $handle = 'brw-address-verifier';
            $src = sprintf(
                'https://maps.googleapis.com/maps/api/js?key=%s&libraries=places',
                rawurlencode(self::API_KEY)
            );

            wp_enqueue_script($handle, $src, ['jquery'], null, true);

            wp_localize_script($handle, 'cpGoogleAddressVerification', [
                'countries' => array_map('strtolower', self::ALLOWED_COUNTRIES),
                'stateDelay' => 350,
                'i18n' => [
                    'badAddress' => __('Please choose a valid address from the suggestions.', 'cp-google-address-verification'),
                ],
            ]);

            wp_add_inline_script($handle, $this->get_inline_js(), 'after');
            wp_register_style('brw-address-verifier-inline', false, [], '1.1.6');
            wp_enqueue_style('brw-address-verifier-inline');
            wp_add_inline_style('brw-address-verifier-inline', $this->get_inline_css());
        }

        public function validate_checkout_addresses(array $data, \WP_Error $errors): void {
            if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
                return;
            }

            if (self::VALIDATE_BILLING) {
                $this->validate_single_address('billing', $data, $errors);
            }

            if ($this->is_different_shipping_selected($data) && self::VALIDATE_SHIPPING) {
                $this->validate_single_address('shipping', $data, $errors);
            }
        }

        private function validate_single_address(string $prefix, array $data, \WP_Error $errors): void {
            $country  = strtoupper(trim((string) ($data[$prefix . '_country'] ?? '')));
            $address1 = trim((string) ($data[$prefix . '_address_1'] ?? ''));
            $address2 = trim((string) ($data[$prefix . '_address_2'] ?? ''));
            $city     = trim((string) ($data[$prefix . '_city'] ?? ''));
            $state    = trim((string) ($data[$prefix . '_state'] ?? ''));
            $postcode = trim((string) ($data[$prefix . '_postcode'] ?? ''));

            if ($country === '') {
                return;
            }

            if (!in_array($country, self::ALLOWED_COUNTRIES, true)) {
                return;
            }

            $postcode = $this->normalize_postcode($postcode, $country);

            if ($address1 === '' || $city === '' || $postcode === '') {
                return;
            }

            $payload = [
                'address' => [
                    'regionCode' => $country,
                    'postalCode' => $postcode,
                    'administrativeArea' => $state,
                    'locality' => $city,
                    'addressLines' => [trim($address1 . ' ' . $address2)],
                ],
            ];

            $response = wp_remote_post(
                'https://addressvalidation.googleapis.com/v1:validateAddress?key=' . rawurlencode(self::API_KEY),
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => wp_json_encode($payload),
                    'timeout' => 18,
                ]
            );

            if (is_wp_error($response)) {
                return;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);

            if ($code < 200 || $code >= 300 || !is_array($body)) {
                return;
            }

            $result      = $body['result'] ?? [];
            $verdict     = $result['verdict'] ?? [];
            $address     = $result['address'] ?? [];
            $missing     = $verdict['missingComponentTypes'] ?? [];
            $unconfirmed = !empty($verdict['hasUnconfirmedComponents']);
            $inferred    = !empty($verdict['hasInferredComponents']);
            $replaced    = !empty($verdict['hasReplacedComponents']);
            $complete    = !empty($verdict['addressComplete']);

            $is_clearly_bad = false;

            if (!$complete && empty($address['postalAddress']['addressLines'][0])) {
                $is_clearly_bad = true;
            }

            if (in_array('route', $missing, true) || in_array('street_number', $missing, true) || in_array('postal_code', $missing, true)) {
                $is_clearly_bad = true;
            }

            if (self::STRICT_MODE) {
                if (!$complete || $unconfirmed || $inferred || $replaced) {
                    $is_clearly_bad = true;
                }
            }

            if ($is_clearly_bad) {
                $field = $prefix === 'shipping' ? 'shipping_address_1' : 'billing_address_1';
                $label = $prefix === 'shipping'
                    ? __('Please check your shipping address and select a suggested match.', 'cp-google-address-verification')
                    : __('Please check your billing address and select a suggested match.', 'cp-google-address-verification');

                $errors->add($field, $label);
            }
        }

        private function normalize_postcode(string $postcode, string $country): string {
            $postcode = trim($postcode);

            if (strtoupper($country) === 'US') {
                $digits = preg_replace('/\D+/', '', $postcode);
                if (!is_string($digits)) {
                    return $postcode;
                }

                if ($digits === '') {
                    return '';
                }

                return substr($digits, 0, 5);
            }

            return $postcode;
        }

        private function get_inline_js(): string {
            return <<<'JS'
(function($){
    'use strict';

    var config = window.cpGoogleAddressVerification || {};
    var allowedCountries = Array.isArray(config.countries) ? config.countries : ['us'];
    var stateDelay = parseInt(config.stateDelay || 350, 10);
    var updateTimer = null;
    var syncTimer = null;

    function getField(id) {
        return document.getElementById(id);
    }

    function getFieldValue(id) {
        var el = getField(id);
        return el ? String(el.value || '') : '';
    }

    function normalizeCountryCode(code) {
        return String(code || '').trim().toLowerCase();
    }

    function normalizeUsZip(zip) {
        zip = String(zip || '').replace(/\D+/g, '');
        return zip.length ? zip.substring(0, 5) : '';
    }

    function normalizePostcodeForPrefix(prefix, rawZip) {
        var country = normalizeCountryCode(getFieldValue(prefix + '_country'));
        if (country === 'us') {
            return normalizeUsZip(rawZip);
        }
        return String(rawZip || '').trim();
    }

    function isChecked(id) {
        var el = getField(id);
        return !!(el && el.checked);
    }

    function shippingFieldsLookVisible() {
        var row = getField('shipping_address_1_field');
        if (!row) {
            return false;
        }

        if (row.classList && row.classList.contains('wfacp_shipping_field_hide')) {
            return false;
        }

        var style = window.getComputedStyle ? window.getComputedStyle(row) : null;
        return !style || (style.display !== 'none' && style.visibility !== 'hidden');
    }

    function isDifferentShippingAddress() {
        // Standard WooCommerce checkbox.
        if (isChecked('ship-to-different-address-checkbox')) {
            return true;
        }

        // FunnelKit field on this checkout. The name is misleading; the label says "Use a different shipping address".
        if (isChecked('shipping_same_as_billing')) {
            return true;
        }

        return shippingFieldsLookVisible();
    }

    function setFieldValue(id, value, triggerEvents) {
        var $field = $('#' + id);
        if (!$field.length) {
            return false;
        }

        var current = String($field.val() || '');
        var next = String(value || '');

        if (current === next) {
            return false;
        }

        $field.val(next);

        if (triggerEvents !== false) {
            $field.trigger('change').trigger('input');
        }

        return true;
    }

    function requestCheckoutUpdate() {
        clearTimeout(updateTimer);
        updateTimer = window.setTimeout(function() {
            if (window.jQuery && document.body) {
                $(document.body).trigger('update_checkout');
            }
        }, 250);
    }

    function syncShippingFromBilling() {
        if (isDifferentShippingAddress()) {
            return false;
        }

        var changed = false;
        var keys = [
            'first_name',
            'last_name',
            'company',
            'country',
            'address_1',
            'address_2',
            'city',
            'state',
            'postcode',
            'phone'
        ];

        keys.forEach(function(key) {
            var value = getFieldValue('billing_' + key);

            if (key === 'postcode') {
                value = normalizePostcodeForPrefix('billing', value);
            }

            if (setFieldValue('shipping_' + key, value, true)) {
                changed = true;
            }
        });

        var nativeDifferent = getField('ship-to-different-address-checkbox');
        if (nativeDifferent && nativeDifferent.checked) {
            nativeDifferent.checked = false;
            changed = true;
        }

        return changed;
    }

    function scheduleBillingShippingSync(withCheckoutUpdate) {
        clearTimeout(syncTimer);
        syncTimer = window.setTimeout(function() {
            var changed = syncShippingFromBilling();
            normalizeAddress2Ui();

            if (withCheckoutUpdate !== false && changed) {
                requestCheckoutUpdate();
            }
        }, 80);
    }

    function parseAddressComponents(place) {
        var parsed = {
            street_number: '',
            route: '',
            locality: '',
            administrative_area_level_1: '',
            postal_code: '',
            postal_code_suffix: '',
            country: ''
        };

        if (!place || !Array.isArray(place.address_components)) {
            return parsed;
        }

        place.address_components.forEach(function(component) {
            if (!Array.isArray(component.types)) {
                return;
            }

            if (component.types.indexOf('street_number') !== -1) {
                parsed.street_number = component.long_name || '';
            }
            if (component.types.indexOf('route') !== -1) {
                parsed.route = component.long_name || '';
            }
            if (component.types.indexOf('locality') !== -1) {
                parsed.locality = component.long_name || '';
            }
            if (component.types.indexOf('postal_town') !== -1 && !parsed.locality) {
                parsed.locality = component.long_name || '';
            }
            if (component.types.indexOf('sublocality') !== -1 && !parsed.locality) {
                parsed.locality = component.long_name || '';
            }
            if (component.types.indexOf('administrative_area_level_1') !== -1) {
                parsed.administrative_area_level_1 = component.short_name || component.long_name || '';
            }
            if (component.types.indexOf('postal_code') !== -1) {
                parsed.postal_code = component.long_name || '';
            }
            if (component.types.indexOf('postal_code_suffix') !== -1) {
                parsed.postal_code_suffix = component.long_name || '';
            }
            if (component.types.indexOf('country') !== -1) {
                parsed.country = component.short_name || '';
            }
        });

        return parsed;
    }

    function applyAddress(prefix, place) {
        var parsed = parseAddressComponents(place);
        var address1 = [parsed.street_number, parsed.route].filter(Boolean).join(' ').trim();
        var postcode = parsed.postal_code || '';

        if (normalizeCountryCode(parsed.country) === 'us') {
            postcode = normalizeUsZip(postcode);
        }

        if (address1) {
            setFieldValue(prefix + '_address_1', address1);
        }
        if (parsed.locality) {
            setFieldValue(prefix + '_city', parsed.locality);
        }
        if (postcode) {
            setFieldValue(prefix + '_postcode', postcode);
        }
        if (parsed.country) {
            setFieldValue(prefix + '_country', parsed.country.toUpperCase());
        }

        window.setTimeout(function() {
            if (parsed.administrative_area_level_1) {
                setFieldValue(prefix + '_state', parsed.administrative_area_level_1);
            }

            if (prefix === 'billing') {
                scheduleBillingShippingSync(true);
            }

            requestCheckoutUpdate();
        }, stateDelay);
    }

    function bindAutocomplete(prefix) {
        var input = getField(prefix + '_address_1');
        if (!input || input.dataset.cpGavBound === '1') {
            return;
        }

        if (!(window.google && google.maps && google.maps.places && google.maps.places.Autocomplete)) {
            return;
        }

        var autocomplete = new google.maps.places.Autocomplete(input, {
            types: ['address'],
            componentRestrictions: { country: allowedCountries }
        });

        autocomplete.addListener('place_changed', function() {
            var place = autocomplete.getPlace();
            applyAddress(prefix, place);
        });

        input.dataset.cpGavBound = '1';
        input.setAttribute('autocomplete', 'off');
    }

    function bindPostcodeNormalization(prefix) {
        var input = getField(prefix + '_postcode');
        if (!input || input.dataset.cpGavZipBound === '1') {
            return;
        }

        var handler = function() {
            var normalized = normalizePostcodeForPrefix(prefix, input.value);
            if (input.value !== normalized) {
                input.value = normalized;
                $(input).trigger('change').trigger('input');
            }

            if (prefix === 'billing') {
                scheduleBillingShippingSync(true);
            }

            requestCheckoutUpdate();
        };

        input.addEventListener('input', handler);
        input.addEventListener('change', handler);
        input.addEventListener('blur', handler);
        input.dataset.cpGavZipBound = '1';
    }

    function bindAddressRefresh(prefix) {
        var ids = [
            prefix + '_address_1',
            prefix + '_address_2',
            prefix + '_city',
            prefix + '_state',
            prefix + '_postcode',
            prefix + '_country'
        ];

        ids.forEach(function(id) {
            var input = getField(id);
            if (!input || input.dataset.cpGavRefreshBound === '1') {
                return;
            }

            input.addEventListener('input', function() {
                if (prefix === 'billing') {
                    scheduleBillingShippingSync(true);
                }
            });
            input.addEventListener('change', function() {
                if (prefix === 'billing') {
                    scheduleBillingShippingSync(true);
                }
                requestCheckoutUpdate();
            });
            input.addEventListener('blur', function() {
                if (prefix === 'billing') {
                    scheduleBillingShippingSync(true);
                }
                requestCheckoutUpdate();
            });
            input.dataset.cpGavRefreshBound = '1';
        });
    }

    function bindShippingToggleWatcher() {
        var ids = ['shipping_same_as_billing', 'ship-to-different-address-checkbox'];

        ids.forEach(function(id) {
            var input = getField(id);
            if (!input || input.dataset.cpGavToggleBound === '1') {
                return;
            }

            input.addEventListener('change', function() {
                normalizeAddress2Ui();
                scheduleBillingShippingSync(true);
                requestCheckoutUpdate();
            });
            input.dataset.cpGavToggleBound = '1';
        });
    }

    function textLooksLikeAddress2Toggle(text) {
        text = String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
        return /^\+?\s*add\s+(apartment|apt|suite|unit|address line 2)/.test(text);
    }

    function hideAddress2Toggles() {
        var candidates = document.querySelectorAll('.woocommerce-checkout a, .woocommerce-checkout button, .wfacp_main_form a, .wfacp_main_form button, .wfacp_checkout_form a, .wfacp_checkout_form button');

        candidates.forEach(function(el) {
            if (!textLooksLikeAddress2Toggle(el.textContent || '')) {
                return;
            }

            el.style.display = 'none';
            el.setAttribute('aria-hidden', 'true');
            el.setAttribute('tabindex', '-1');

            if (el.dataset.cpGavToggleBlockBound !== '1') {
                el.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    return false;
                }, true);
                el.dataset.cpGavToggleBlockBound = '1';
            }

            var parent = el.parentElement;
            if (parent && !parent.querySelector('input, select, textarea') && textLooksLikeAddress2Toggle(parent.textContent || '')) {
                parent.style.display = 'none';
                parent.setAttribute('aria-hidden', 'true');
            }
        });
    }

    function clearForcedAddress2(prefix) {
        var input = getField(prefix + '_address_2');
        var field = getField(prefix + '_address_2_field');
        var items = [input, field].filter(Boolean);

        items.forEach(function(el) {
            ['display', 'visibility', 'opacity', 'height', 'max-height', 'overflow'].forEach(function(prop) {
                el.style.removeProperty(prop);
            });
        });
    }

    function keepAddress2Visible(prefix) {
        var input = getField(prefix + '_address_2');
        if (!input) {
            return;
        }

        if (prefix === 'shipping' && !isDifferentShippingAddress()) {
            clearForcedAddress2('shipping');
            return;
        }

        input.placeholder = 'Apartment, suite, unit, etc. (optional)';
        input.setAttribute('aria-label', 'Apartment, suite, unit, etc.');
        input.setAttribute('autocomplete', 'address-line2');
        input.style.display = '';
        input.style.visibility = 'visible';
        input.style.opacity = '1';

        var labelList = document.querySelectorAll('label[for="' + prefix + '_address_2"]');
        labelList.forEach(function(label) {
            label.style.display = 'none';
            label.setAttribute('aria-hidden', 'true');
        });

        var wrappers = [];
        var field = document.getElementById(prefix + '_address_2_field');
        if (field) {
            wrappers.push(field);
        }

        var closestRows = [
            input.closest('p.form-row'),
            input.closest('.form-row'),
            input.closest('.wfacp-form-control-wrapper'),
            input.closest('.wfacp-form-control-wrap'),
            input.closest('.wfacp_field_wrap'),
            input.closest('.wfacp_custom_field'),
            input.closest('.wfacp_form_control')
        ];

        closestRows.forEach(function(row) {
            if (row && wrappers.indexOf(row) === -1) {
                wrappers.push(row);
            }
        });

        wrappers.forEach(function(row) {
            row.classList.remove('hidden', 'wfacp_hidden', 'wfacp_hidden_class', 'wfacp-hide', 'wfacp_hide', 'wfacp_address_2_hide');

            if (prefix === 'shipping' && isDifferentShippingAddress()) {
                row.classList.remove('wfacp_shipping_field_hide');
            }

            row.style.display = 'block';
            row.style.visibility = 'visible';
            row.style.opacity = '1';
            row.style.height = 'auto';
            row.style.maxHeight = 'none';
            row.style.overflow = 'visible';
        });
    }

    function normalizeAddress2Ui() {
        hideAddress2Toggles();
        keepAddress2Visible('billing');
        keepAddress2Visible('shipping');
    }

    function initAll() {
        bindAutocomplete('billing');
        bindAutocomplete('shipping');
        bindPostcodeNormalization('billing');
        bindPostcodeNormalization('shipping');
        bindAddressRefresh('billing');
        bindAddressRefresh('shipping');
        bindShippingToggleWatcher();
        normalizeAddress2Ui();
        scheduleBillingShippingSync(false);
    }

    function scheduleInitAll() {
        initAll();
        window.setTimeout(initAll, 150);
        window.setTimeout(initAll, 600);
        window.setTimeout(initAll, 1500);
    }

    $(document).ready(scheduleInitAll);
    $(document.body).on('updated_checkout country_to_state_changed wc_fragments_loaded wc_fragments_refreshed', scheduleInitAll);
})(jQuery);
JS;
        }

        private function get_inline_css(): string {
            return <<<'CSS'
.pac-container {
    z-index: 999999 !important;
}

/* Keep billing address line 2 visible without forcing the hidden shipping group open. */
#billing_address_2_field,
p#billing_address_2_field,
.woocommerce-checkout #billing_address_2_field,
.wfacp_main_form #billing_address_2_field,
.wfacp_checkout_form #billing_address_2_field,
#billing_address_2_field.brw-address2-always-open {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    height: auto !important;
    max-height: none !important;
    overflow: visible !important;
}

/* Shipping address line 2 may open, but only when FunnelKit has opened the shipping group. */
#shipping_address_2_field:not(.wfacp_shipping_field_hide),
p#shipping_address_2_field:not(.wfacp_shipping_field_hide),
.woocommerce-checkout #shipping_address_2_field:not(.wfacp_shipping_field_hide),
.wfacp_main_form #shipping_address_2_field:not(.wfacp_shipping_field_hide),
.wfacp_checkout_form #shipping_address_2_field:not(.wfacp_shipping_field_hide) {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    height: auto !important;
    max-height: none !important;
    overflow: visible !important;
}

#shipping_address_2_field.wfacp_shipping_field_hide,
p#shipping_address_2_field.wfacp_shipping_field_hide {
    display: none !important;
}

#billing_address_2,
#shipping_address_2 {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    height: auto !important;
}

/* Prevent FunnelKit floating-label overlap/double text on address line 2. */
label[for="billing_address_2"],
label[for="shipping_address_2"],
#billing_address_2_field label,
#shipping_address_2_field label,
.wfacp_main_form label[for="billing_address_2"],
.wfacp_main_form label[for="shipping_address_2"],
.wfacp_checkout_form label[for="billing_address_2"],
.wfacp_checkout_form label[for="shipping_address_2"] {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
}

/* Known FunnelKit address-line-2 toggle class guesses. Text-matching JS handles the live unknown class. */
.wfacp_address_2_link,
.wfacp_add_address_2,
.wfacp_add_address_line_2,
.wfacp_additional_address_link,
.wfacp_additional_address {
    display: none !important;
}
CSS;
        }
    }

    new CP_Google_Address_Verification();
}
