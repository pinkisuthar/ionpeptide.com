<?php
/**
 * Highlight the field to fix after a decline (2.7.30).
 *
 * After an address (AVS) or security-code (CVV) decline, the billing address /
 * ZIP fields or the card's security-code box are outlined, a short tip is shown
 * and the page scrolls to them. The highlight clears as soon as the customer
 * edits that field.
 *
 *   Checkout (no reload): the decline notice carries an invisible marker
 *     (FailureClassifier::fixMarker) that the script reads on "checkout_error".
 *   Payment pages (reload): the reason box carries the marker; a decline made on
 *     the payment page itself is passed to the page as window.poFixHint.
 *
 * Purely visual - no payment behaviour changes.
 *
 * @package PaymentOrchestrator\Frontend
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Frontend;

use PaymentOrchestrator\Recovery\FailureClassifier;

defined('ABSPATH') || exit;

final class FieldHint {

    /** Field to highlight for a decline made on the payment page in this request. */
    private ?string $pending = null;

    public function register(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue'], 30);
        add_action('po_pay_page_declined', [$this, 'onPayPageDeclined'], 10, 2);
        add_action('wp_footer', [$this, 'printPending'], 5);
    }

    public function onPayPageDeclined($order, $classification): void {
        if (is_array($classification)) {
            $this->pending = FailureClassifier::fixField($classification);
        }
    }

    public function printPending(): void {
        if ($this->pending !== null && function_exists('is_checkout') && is_checkout()) {
            echo '<script>window.poFixHint=' . wp_json_encode($this->pending) . ';</script>';
        }
    }

    public function enqueue(): void {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        wp_register_style('po-field-hint', false, [], PO_VERSION);
        wp_enqueue_style('po-field-hint');
        wp_add_inline_style('po-field-hint', self::css());
        wp_register_script('po-field-hint', false, ['jquery'], PO_VERSION, true);
        wp_enqueue_script('po-field-hint');
        wp_add_inline_script('po-field-hint', self::js());
    }

    private static function css(): string {
        return '.po-fix-hint{display:none!important}'
            . '.po-fix input,.po-fix select,.po-fix textarea{border-color:#d63638!important;box-shadow:0 0 0 2px rgba(214,54,56,.25)!important}'
            . '#nmi-card-cvc-element.po-fix{outline:2px solid #d63638;outline-offset:2px;border-radius:4px}'
            . '.po-fix-tip{display:block;margin-top:4px;color:#b32d2e;font-size:13px;line-height:1.4}'
            // 2.7.32: payment-page processing message
            . '.po-processing{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:100100;background:#fff;color:#1d2327;'
            . 'border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.25);padding:20px 24px;max-width:340px;width:calc(100% - 48px);text-align:center;font-size:15px;line-height:1.5}'
            . '.po-processing strong{display:block;font-size:17px;margin-bottom:4px}'
            . '.po-processing__spin{width:28px;height:28px;margin:0 auto 10px;border:3px solid #dcdcde;border-top-color:#1d2327;border-radius:50%;animation:po-spin .8s linear infinite}'
            . '@keyframes po-spin{to{transform:rotate(360deg)}}@media (prefers-reduced-motion:reduce){.po-processing__spin{animation:none}}';
    }

    private static function js(): string {
        return <<<'JS'
(function ($) {
	var FIELDS = {
		address: ['#billing_address_1', '#billing_postcode', '#billing_city', '#billing_state'],
		cvv: ['#nmi-card-cvc-element']
	};
	var TIPS = {
		address: 'Enter your billing address and ZIP code exactly as they appear on your card statement.',
		cvv: 'Enter the 3-digit code on the back of your card (4 digits on the front for American Express).'
	};
	function clear() {
		$('.po-fix').removeClass('po-fix');
		$('.po-fix-tip').remove();
		$('[data-po-fix-invalid]').removeAttr('aria-invalid data-po-fix-invalid');
	}
	function apply(kind) {
		if (!FIELDS[kind]) { return; }
		clear();
		var first = null;
		$.each(FIELDS[kind], function (i, sel) {
			var $el = $(sel);
			if (!$el.length) { return; }
			if (kind === 'cvv') {
				$el.addClass('po-fix');
				// The CVV box is a secure frame; clear once Collect.js marks it valid.
				if (window.MutationObserver) {
					var mo = new MutationObserver(function () {
						if ($el.find('.CollectJSValid').length) { mo.disconnect(); clear(); }
					});
					mo.observe($el[0], { subtree: true, attributes: true, attributeFilter: ['class'] });
				}
			} else {
				var $row = $el.closest('.form-row, .wfacp-form-control-wrapper, p');
				($row.length ? $row : $el).addClass('po-fix');
				$el.attr({ 'aria-invalid': 'true', 'data-po-fix-invalid': '1' });
			}
			if (!first) { first = $el; }
		});
		if (!first) { return; }
		$('<span class="po-fix-tip" role="note"></span>').text(TIPS[kind]).insertAfter(first);
		var top = first.offset() ? first.offset().top - 140 : null;
		if (top !== null) { $('html, body').animate({ scrollTop: top }, 250); }
		if (kind !== 'cvv' && first.is(':visible')) { first.trigger('focus'); }
	}
	function fromHtml(html) {
		var m = String(html || '').match(/po-fix-(address|cvv)/);
		return m ? m[1] : null;
	}
	// Checkout: declines arrive without a reload
	$(document.body).on('checkout_error', function (e, html) {
		var kind = fromHtml(html) || fromHtml($('.woocommerce-error, .woocommerce-NoticeGroup').html());
		if (kind) { apply(kind); }
	});
	// Clear once the customer edits a highlighted field
	$(document).on('input change', '.po-fix input, .po-fix select, .po-fix textarea', clear);
	// Payment pages: reason box marker, or a decline made on this page
	$(function () {
		var kind = window.poFixHint || ($('.po-fix-hint').length ? fromHtml($('.po-fix-hint').attr('class')) : null);
		if (kind) { setTimeout(function () { apply(kind); }, 400); }
	});
	window.poFieldHint = { apply: apply, clear: clear };

	// 2.7.32: payment pages \u2014 the card plugin greys the form while charging but
	// shows no message. Show one while (and only while) the form is greyed out.
	function payForm() { return $('form#order_review'); }
	var watching = null;
	function hideProcessing() {
		$('.po-processing').remove();
		if (watching) { clearInterval(watching); watching = null; }
	}
	function showProcessingIfBlocked() {
		var $f = payForm();
		if (!$f.length || !$f.find('.blockOverlay, .blockUI').length) { return; }
		if (!$('.po-processing').length) {
			$('<div class="po-processing" role="status" aria-live="polite"><div class="po-processing__spin" aria-hidden="true"></div>'
				+ '<strong>Processing your payment\u2026</strong>Please don\'t close or refresh this page.</div>').appendTo(document.body);
		}
		if (!watching) {
			watching = setInterval(function () {
				if (!payForm().find('.blockOverlay, .blockUI').length) { hideProcessing(); }
			}, 300);
		}
	}
	$(document).on('submit', 'form#order_review', function () { setTimeout(showProcessingIfBlocked, 150); });
	$(document).on('click', 'form#order_review #place_order', function () { setTimeout(showProcessingIfBlocked, 150); });
	$(document).on('nmiError', hideProcessing);
	$(document.body).on('nmiError', hideProcessing);
})(jQuery);
JS;
    }
}
