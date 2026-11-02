<?php
/**
 * Card-brand switch.
 *
 * When the payment option shown at checkout (or on a pay-for-order page) does
 * not accept a card brand that another eligible POP gateway does accept, POP:
 *
 *  - shows a "Pay with <brands>" option under that payment option, and
 *  - switches automatically when PC NMI Accounts reports a refused brand
 *    (browser event `pc_nmi_card_type_refused`, or the server action of the
 *    same name as a backstop).
 *
 * Fully data-driven: brands come from CardBrands::acceptedBrands() per gateway,
 * so it works for any brand any gateway refuses.
 *
 * Integration rules (PC NMI Accounts): POP never loads, re-keys or inspects
 * Collect.js. A switch changes the WooCommerce session lock
 * (po_locked_gateway / po_locked_gateway_time), answers
 * `pc_nmi_preferred_gateway`, and on pay-for-order pages updates the order's
 * payment method (+ _po_retry_gateway when set), then the page reloads so the
 * right Collect.js loads.
 *
 * @package PaymentOrchestrator\Frontend
 * @since 2.7.23
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Frontend;

use PaymentOrchestrator\Core\Logger;
use PaymentOrchestrator\Gateway\CardBrands;
use PaymentOrchestrator\Gateway\Gateway;
use PaymentOrchestrator\Gateway\GatewayRepository;
use PaymentOrchestrator\Routing\RotationManager;

defined('ABSPATH') || exit;

final class CardBrandSwitch {

    private const AJAX_ACTION   = 'po_card_brand_switch';
    private const NONCE_ACTION  = 'po_card_brand_switch';
    private const SESSION_LOCK  = 'po_locked_gateway';
    private const SESSION_TIME  = 'po_locked_gateway_time';
    private const SESSION_FLAG  = 'po_card_switch';
    private const NOTICE_WINDOW = 600;  // seconds a "we switched you" notice stays visible
    private const BACK_WINDOW   = 1800; // seconds "switch back" is offered (= POP's session lock)

    private GatewayRepository $gateways;
    private RotationManager $rotation;

    public function __construct(GatewayRepository $gateways, RotationManager $rotation) {
        $this->gateways = $gateways;
        $this->rotation = $rotation;
    }

    public function register(): void {
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajaxSwitch']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'ajaxSwitch']);

        // Server-side backstop: PC NMI Accounts refused a brand before any charge.
        add_action('pc_nmi_card_type_refused', [$this, 'onServerRefusal'], 10, 2);

        // Tell PC NMI Accounts which NMI gateway POP wants when several are offered.
        add_filter('pc_nmi_preferred_gateway', [$this, 'preferredGateway'], 10, 2);

        // "Pay with <brands>" option, inside the payment section (refreshed with it).
        add_action('woocommerce_review_order_before_submit', [$this, 'render']);
        add_action('woocommerce_pay_order_before_submit', [$this, 'render']);
        // 2.7.24: pay-for-order pages built by page builders (e.g. FunnelKit designs)
        // may not fire woocommerce_pay_order_before_submit. Render once, hidden, in
        // the footer; the script moves it under the payment option.
        add_action('wp_footer', [$this, 'renderPayPageFallback'], 5);

        add_action('wp_enqueue_scripts', [$this, 'enqueue'], 20);
    }

    /* ------------------------------------------------------------------
     * Decision logic
     * ------------------------------------------------------------------ */

    /**
     * Best gateway (other than $excludeId) that accepts $brand and could take
     * this payment right now, or null.
     */
    public function bestTarget(string $brand, string $excludeId, float $total): ?Gateway {
        $brand = CardBrands::normalize($brand);
        if ($brand === '') {
            return null;
        }
        foreach ($this->gateways->getManaged() as $gateway) {
            if ($gateway->id === $excludeId) {
                continue;
            }
            // A switch target must be KNOWN to accept the brand (POP's own flags
            // only cover four brands; anything else is unknown, so not offered).
            if (!in_array($brand, CardBrands::acceptedBrands($gateway) ?? [], true)) {
                continue;
            }
            if ($this->isEligible($gateway, $total)) {
                return $gateway;
            }
        }
        return null;
    }

    /**
     * Could this gateway take this payment right now? (enabled in POP and
     * WooCommerce, available, healthy, within its maximum amount)
     */
    public function isEligible(Gateway $gateway, float $total): bool {
        if (!$gateway->isEnabled || !$gateway->isCardGateway()) {
            return false;
        }
        if ($gateway->maxTransactionAmount > 0 && $total > 0 && $total > $gateway->maxTransactionAmount) {
            return false;
        }
        if (!$this->rotation->getHealthMonitor()->canReceiveTraffic($gateway->id)) {
            return false;
        }
        $wc = $this->wcGateways()[$gateway->id] ?? null;
        return $wc && 'yes' === ($wc->enabled ?? 'no') && $wc->is_available();
    }

    /**
     * Brands $target accepts that the gateway $fromId refuses, in catalogue
     * order (e.g. ['amex', 'discover']). Falls back to [$brand].
     *
     * @return string[]
     */
    private function gainedBrands(Gateway $target, string $fromId, string $brand = ''): array {
        $from   = $this->gateways->getById($fromId);
        $gained = [];
        foreach (CardBrands::acceptedBrands($target) ?? [] as $b) {
            if (!$from || !CardBrands::accepts($from, $b)) {
                $gained[] = $b;
            }
        }
        if (!$gained && $brand !== '') {
            $gained = [$brand];
        }
        $order = array_keys(CardBrands::BRANDS);
        usort($gained, fn($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
        return $gained;
    }

    /**
     * Why each other gateway can or cannot be offered for the brands $fromId
     * refuses (debug logging, 2.7.24).
     */
    private function diagnose(string $fromId, float $total): array {
        $from = $this->gateways->getById($fromId);
        $refused = [];
        foreach (array_keys(CardBrands::BRANDS) as $brand) {
            if (!$from || !CardBrands::accepts($from, $brand)) {
                $refused[] = $brand;
            }
        }
        $out = ['refused_by_shown' => $refused];
        $wcAll = $this->wcGateways();
        foreach ($this->gateways->getManaged() as $g) {
            if ($g->id === $fromId) {
                continue;
            }
            $wc = $wcAll[$g->id] ?? null;
            $out[$g->id] = [
                'accepts'      => CardBrands::acceptedBrands($g),
                'enabled'      => $g->isEnabled,
                'card'         => $g->isCardGateway(),
                'max'          => $g->maxTransactionAmount,
                'healthy'      => $this->rotation->getHealthMonitor()->canReceiveTraffic($g->id),
                'wc_enabled'   => $wc ? ($wc->enabled ?? null) : 'missing',
                'wc_available' => $wc ? (bool) $wc->is_available() : false,
            ];
        }
        return $out;
    }

    /**
     * Diagnostics are off by default (they would add rows on every checkout view).
     * Enable with define('PO_CARD_SWITCH_DEBUG', true) or the
     * 'po_card_switch_debug' filter.
     */
    private static function debugEnabled(): bool {
        return (bool) apply_filters('po_card_switch_debug', defined('PO_CARD_SWITCH_DEBUG') && PO_CARD_SWITCH_DEBUG);
    }

    /** Log (once per request) why no switch option was rendered. */
    private function explain(string $reason, array $context): void {
        if (!self::debugEnabled()) {
            return;
        }
        static $done = [];
        if (isset($done[$reason])) {
            return;
        }
        $done[$reason] = true;
        Logger::debug('Card-brand switch: no option shown ' . "\u{2014}" . ' ' . $reason, $context);
    }

    /**
     * Does $other accept any brand the gateway $currentId refuses?
     */
    private function addsBrands(Gateway $other, string $currentId): bool {
        $current = $this->gateways->getById($currentId);
        if (!$current) {
            return true;
        }
        foreach (CardBrands::acceptedBrands($other) ?? [] as $brand) {
            if (!CardBrands::accepts($current, $brand)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gateway the customer was switched away from, if they can go back to it now.
     * Available while the switch is current (the session lock lasts 30 minutes).
     */
    public function backTarget(string $shownId, float $total): ?Gateway {
        $flag = $this->switchFlag();
        if (!$flag || $flag['to'] !== $shownId || (time() - (int) $flag['time']) > self::BACK_WINDOW) {
            return null;
        }
        $back = $this->gateways->getById((string) $flag['from']);
        return ($back && $back->id !== $shownId && $this->isEligible($back, $total)) ? $back : null;
    }

    /**
     * Brands the given gateway refuses that some other eligible gateway accepts,
     * grouped by that target: [targetId => ['gateway' => Gateway, 'brands' => [...]]].
     */
    public function switchOptions(string $fromId, float $total): array {
        $from = $this->gateways->getById($fromId);
        if (!$from || !$from->isCardGateway()) {
            return [];
        }
        $groups = [];
        foreach (array_keys(CardBrands::BRANDS) as $brand) {
            if (CardBrands::accepts($from, $brand)) {
                continue;
            }
            $target = $this->bestTarget($brand, $fromId, $total);
            if (!$target) {
                continue;
            }
            $groups[$target->id]['gateway']  = $target;
            $groups[$target->id]['brands'][] = $brand;
        }
        return $groups;
    }

    /**
     * Point checkout (and, on a pay page, the order) at $target.
     */
    private function applySwitch(Gateway $target, string $brand, string $fromId, ?\WC_Order $order, bool $back = false): void {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_LOCK, $target->id);
            WC()->session->set(self::SESSION_TIME, time());
            WC()->session->set(self::SESSION_FLAG, $back ? null : [
                'brand'  => $brand,
                'gained' => $this->gainedBrands($target, $fromId, $brand),
                'from'   => $fromId,
                'to'     => $target->id,
                'time'   => time(),
            ]);
            // Show the new option pre-selected after the reload.
            WC()->session->set('chosen_payment_method', $target->id);
        }

        if ($order) {
            $wc = $this->wcGateways()[$target->id] ?? null;
            $order->set_payment_method($wc ?: $target->id);
            if ((string) $order->get_meta('_po_retry_gateway') !== '') {
                $order->update_meta_data('_po_retry_gateway', $target->id);
            }
            $order->add_order_note($back
                ? sprintf('Payment option switched back by the customer: %s ' . "\u{2192}" . ' %s (card-brand switch).', $fromId, $target->id)
                : sprintf('Payment option switched by the customer to pay with %s: %s ' . "\u{2192}" . ' %s (card-brand switch).', CardBrands::label($brand), $fromId, $target->id)
            );
            $order->save();
        }

        Logger::info($back ? 'Card-brand switch back' : 'Card-brand switch', [
            'brand'    => $brand,
            'from'     => $fromId,
            'to'       => $target->id,
            'order_id' => $order ? $order->get_id() : null,
        ]);
    }

    /* ------------------------------------------------------------------
     * Browser request (row click or PC NMI Accounts browser event)
     * ------------------------------------------------------------------ */

    public function ajaxSwitch(): void {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'expired'], 403);
        }
        $mode   = (isset($_POST['mode']) && 'back' === $_POST['mode']) ? 'back' : 'brand';
        $brand  = CardBrands::normalize(isset($_POST['brand']) ? sanitize_key(wp_unslash($_POST['brand'])) : '');
        $fromId = isset($_POST['from']) ? sanitize_key(wp_unslash($_POST['from'])) : '';
        if ($fromId === '' || ('brand' === $mode && $brand === '')) {
            wp_send_json_error(['message' => 'invalid'], 400);
        }

        $order = null;
        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if ($orderId) {
            $order = wc_get_order($orderId);
            $key   = isset($_POST['order_key']) ? wc_clean(wp_unslash($_POST['order_key'])) : '';
            if (!$order || $key === '' || !hash_equals($order->get_order_key(), (string) $key) || !$order->needs_payment()) {
                wp_send_json_error(['message' => 'invalid_order'], 403);
            }
        } elseif (function_exists('WC') && !WC()->cart && function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        if ('back' === $mode) {
            $total = $order ? (float) $order->get_total() : $this->cartTotal();
            $back  = $this->backTarget($fromId, $total);
            if (!$back) {
                wp_send_json_success(['switched' => false, 'message' => '']);
            }
            $this->applySwitch($back, '', $fromId, $order, true);
            wp_send_json_success(['switched' => true, 'message' => __('Switching back' . "\u{2026}" . '', 'payment-orchestrator-pro')]);
        }

        $from = $this->gateways->getById($fromId);
        if (!$from || CardBrands::accepts($from, $brand)) {
            // Nothing to fix (unknown source gateway, or it accepts this brand).
            wp_send_json_success(['switched' => false, 'message' => '']);
        }

        $total  = $order ? (float) $order->get_total() : $this->cartTotal();
        $target = $this->bestTarget($brand, $fromId, $total);
        if (!$target) {
            $accepted = CardBrands::acceptedBrands($from) ?: [];
            wp_send_json_success([
                'switched' => false,
                'message'  => sprintf(
                    /* translators: 1: refused brand, 2: accepted brands */
                    __('%1$s isn\'t accepted for this order. Please use %2$s.', 'payment-orchestrator-pro'),
                    CardBrands::label($brand),
                    $this->humanList(array_map([CardBrands::class, 'label'], $accepted))
                ),
            ]);
        }

        $this->applySwitch($target, $brand, $fromId, $order);
        wp_send_json_success([
            'switched' => true,
            'message'  => $this->switchedMessage($this->gainedBrands($target, $fromId, $brand)),
        ]);
    }

    /* ------------------------------------------------------------------
     * Server-side backstop (PC NMI Accounts refused before any charge)
     * ------------------------------------------------------------------ */

    public function onServerRefusal($gatewayId, $brand): void {
        try {
            $brand = CardBrands::normalize((string) $brand);
            $from  = $this->gateways->getById((string) $gatewayId);
            if ($brand === '' || !$from) {
                return;
            }

            $order = null;
            $isPay = !empty($_POST['woocommerce_pay']) && function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay');
            if ($isPay) {
                global $wp;
                $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
                $order   = $orderId ? wc_get_order($orderId) : null;
                if (!$order) {
                    return;
                }
            }

            $total  = $order ? (float) $order->get_total() : $this->cartTotal();
            $target = $this->bestTarget($brand, (string) $gatewayId, $total);
            if (!$target) {
                return;
            }

            $this->applySwitch($target, $brand, (string) $gatewayId, $order);
            wc_add_notice($this->switchedMessage($this->gainedBrands($target, (string) $gatewayId, $brand)), 'notice');

            // Checkout (AJAX): ask WooCommerce to reload the page after this
            // failure so the new option's card fields load. WooCommerce keeps
            // the notices for the reloaded page (WC_Checkout::send_ajax_failure_response).
            if (!$isPay && wp_doing_ajax() && function_exists('WC') && WC()->session) {
                WC()->session->set('reload_checkout', true);
            }
        } catch (\Throwable $e) {
            Logger::debug('Card-brand switch backstop skipped', ['error' => $e->getMessage()]);
        }
    }

    /* ------------------------------------------------------------------
     * PC NMI Accounts preference
     * ------------------------------------------------------------------ */

    /**
     * @param string   $preferred Current preference ('' = none).
     * @param string[] $offered   NMI gateways currently offered.
     */
    public function preferredGateway($preferred, $offered) {
        if (is_string($preferred) && $preferred !== '') {
            return $preferred;
        }
        if (!function_exists('WC') || !WC()->session || !is_array($offered)) {
            return $preferred;
        }
        $locked = WC()->session->get(self::SESSION_LOCK);
        return (is_string($locked) && in_array($locked, $offered, true)) ? $locked : $preferred;
    }

    /* ------------------------------------------------------------------
     * Output
     * ------------------------------------------------------------------ */

    /** Pay-page row already rendered in this request (avoid a duplicate). */
    private bool $payPageRendered = false;

    public function renderPayPageFallback(): void {
        $this->trace('footer fallback');
        if ($this->payPageRendered || !$this->payPageOrder()) {
            return;
        }
        ob_start();
        $this->render();
        $html = (string) ob_get_clean();
        if ($html !== '') {
            echo '<div class="po-brand-switch-holder" style="display:none">' . $html . '</div>';
        }
    }

    public function render(): void {
        $this->trace('render via ' . (current_action() ?: 'direct call'));
        try {
            // On an order-pay URL, only render for a valid order + key (never fall
            // back to checkout context there).
            global $wp;
            if (isset($wp->query_vars['order-pay']) && !$this->payPageOrder()) {
                return;
            }
            if ($this->payPageOrder()) {
                if ($this->payPageRendered) {
                    return;
                }
                $this->payPageRendered = true;
            }
            if (!function_exists('WC') || !WC()->payment_gateways()) {
                return;
            }
            $order = $this->payPageOrder();
            $available = WC()->payment_gateways()->get_available_payment_gateways();

            $managedShown = [];
            foreach (array_keys($available) as $id) {
                if ($this->gateways->getById((string) $id)) {
                    $managedShown[] = (string) $id;
                }
            }
            if (count($managedShown) !== 1) {
                $this->explain('not exactly one POP option shown', ['shown' => array_keys($available), 'managed_shown' => $managedShown]);
                return; // Only when one POP card option is shown (normal rotation).
            }
            $fromId = $managedShown[0];
            $total  = $order ? (float) $order->get_total() : $this->cartTotal();

            $notice = $this->switchNotice($fromId);
            $groups = $this->switchOptions($fromId, $total);
            $back   = $this->backTarget($fromId, $total);
            // Only offer "switch back" when the previous option accepts brands the
            // current one doesn't, and the brand offer isn't already pointing there.
            if ($back && (isset($groups[$back->id]) || !$this->addsBrands($back, $fromId))) {
                $back = null;
            }
            if (!$groups && $notice === '' && !$back) {
                $this->explain('nothing to offer', [
                    'from'      => $fromId,
                    'total'     => $total,
                    'pay_page'  => $order ? $order->get_id() : null,
                    'candidates' => $this->diagnose($fromId, $total),
                ]);
                return;
            }

            $iconBase = function_exists('WC') ? WC()->plugin_url() . '/assets/images/icons/credit-cards/' : '';
            echo '<div class="po-brand-switch" data-from="' . esc_attr($fromId) . '"'
                . ($order ? ' data-order="' . esc_attr((string) $order->get_id()) . '" data-key="' . esc_attr($order->get_order_key()) . '"' : '')
                . '>';
            if ($notice !== '') {
                echo '<div class="po-brand-switch__notice" role="status">' . esc_html($notice) . '</div>';
            }
            foreach ($groups as $group) {
                $labels = array_map([CardBrands::class, 'label'], $group['brands']);
                echo '<button type="button" class="po-brand-switch__option" data-brand="' . esc_attr($group['brands'][0]) . '">';
                echo '<span class="po-brand-switch__icons">';
                foreach ($group['brands'] as $brand) {
                    echo '<img src="' . esc_url($iconBase . CardBrands::BRANDS[$brand][1]) . '" alt="' . esc_attr(CardBrands::label($brand)) . '" width="38" height="24" loading="lazy">';
                }
                echo '</span><span class="po-brand-switch__text">'
                    . esc_html(sprintf(
                        /* translators: %s: card brands, e.g. "American Express or Discover" */
                        __('Paying with %s?', 'payment-orchestrator-pro'),
                        $this->humanList($labels, 'or')
                    ))
                    . ' <span class="po-brand-switch__cta">' . esc_html__('Use this option instead', 'payment-orchestrator-pro') . '</span>'
                    . '</span></button>';
            }
            if ($back) {
                $backBrands = CardBrands::acceptedBrands($back) ?: [];
                echo '<button type="button" class="po-brand-switch__option po-brand-switch__option--back" data-mode="back">';
                if ($backBrands) {
                    echo '<span class="po-brand-switch__icons">';
                    foreach ($backBrands as $brand) {
                        echo '<img src="' . esc_url($iconBase . CardBrands::BRANDS[$brand][1]) . '" alt="' . esc_attr(CardBrands::label($brand)) . '" width="38" height="24" loading="lazy">';
                    }
                    echo '</span>';
                }
                echo '<span class="po-brand-switch__text">' . esc_html__('Want to use a different card?', 'payment-orchestrator-pro')
                    . ' <span class="po-brand-switch__cta">' . esc_html__('Switch back', 'payment-orchestrator-pro') . '</span></span></button>';
            }
            echo '</div>';
        } catch (\Throwable $e) {
            Logger::debug('Card-brand switch render skipped', ['error' => $e->getMessage()]);
        }
    }

    public function enqueue(): void {
        if (is_admin() || !function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        wp_register_script('po-card-brand-switch', false, ['jquery'], PO_VERSION, true);
        wp_enqueue_script('po-card-brand-switch');
        wp_add_inline_script('po-card-brand-switch', 'window.poCardBrandSwitch = ' . wp_json_encode([
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'action'   => self::AJAX_ACTION,
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            'working'  => __('Switching to a payment option that accepts your card' . "\u{2026}" . '', 'payment-orchestrator-pro'),
        ]) . ';', 'before');
        wp_add_inline_script('po-card-brand-switch', self::script());

        wp_register_style('po-card-brand-switch', false, [], PO_VERSION);
        wp_enqueue_style('po-card-brand-switch');
        wp_add_inline_style('po-card-brand-switch', self::styles());
    }

    private static function script(): string {
        return <<<'JS'
(function ($) {
	var cfg = window.poCardBrandSwitch || {}, busy = false;

	function box() { return $('.po-brand-switch').last(); }

	// Show the option directly under the payment option it applies to.
	// 2.7.24: keep an already-placed row unless a newer one has been rendered
	// (checkout redraws the payment section; the pay page does not, and removing
	// the placed row there on payment_method_selected deleted the only copy).
	function place() {
		var el = box();
		if (!el.length) { $('li.po-brand-switch-li').remove(); return; }
		if (el.closest('li.po-brand-switch-li').length) {
			$('li.po-brand-switch-li').not(el.closest('li.po-brand-switch-li')).remove();
			return;
		}
		$('li.po-brand-switch-li').remove();
		var li = $('li.payment_method_' + el.data('from')).first();
		if (li.length) {
			$('<li class="wc_payment_method po-brand-switch-li"></li>').append(el.detach()).insertAfter(li);
		}
		$('.po-brand-switch-holder:empty').remove();
	}

	function message(text) {
		var el = box();
		if (!el.length || !text) { return; }
		var n = el.find('.po-brand-switch__notice');
		if (!n.length) { n = $('<div class="po-brand-switch__notice" role="status"></div>').prependTo(el); }
		n.text(text);
	}

	var SCROLL_KEY = 'poCardSwitchScroll';

	function paymentArea() {
		var area = $('#payment, .wfacp_payment').first();
		return area.length ? area : box().closest('form');
	}

	function cover(on) {
		var area = paymentArea();
		if (!area.length || !$.fn.block) { return; }
		if (on) {
			area.block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });
		} else {
			area.unblock();
		}
	}

	var FORM_KEY = 'poCardSwitchForm', FORM_TTL = 300000; // 5 minutes

	function checkboxes() {
		return $('form.checkout, form.woocommerce-checkout, form#order_review')
			.find('input[type="checkbox"][name]')
			.filter(function () { return this.name.indexOf('payment_method') !== 0 && this.name.indexOf('wc-') !== 0; });
	}

	// Remember the customer's ticked boxes (e.g. agreement checkboxes that the
	// checkout does not keep in the session) so the switch reload doesn't clear them.
	function saveForm() {
		var boxes = {}, seen = {};
		checkboxes().each(function () {
			var i = seen[this.name] = (seen[this.name] || 0);
			seen[this.name]++;
			boxes[this.name + '#' + i] = this.checked;
		});
		try {
			window.sessionStorage.setItem(FORM_KEY, JSON.stringify({ t: Date.now(), path: window.location.pathname, boxes: boxes }));
		} catch (e) {}
	}

	function restoreForm(clear) {
		var data = null;
		try { data = JSON.parse(window.sessionStorage.getItem(FORM_KEY) || 'null'); } catch (e) {}
		if (!data || !data.boxes) { return; }
		if (data.path !== window.location.pathname || (Date.now() - (data.t || 0)) > FORM_TTL) {
			try { window.sessionStorage.removeItem(FORM_KEY); } catch (e) {}
			return;
		}
		var seen = {};
		checkboxes().each(function () {
			var i = seen[this.name] = (seen[this.name] || 0);
			seen[this.name]++;
			var key = this.name + '#' + i;
			if (Object.prototype.hasOwnProperty.call(data.boxes, key) && this.checked !== !!data.boxes[key]) {
				$(this).prop('checked', !!data.boxes[key]).trigger('change');
			}
		});
		if (clear) {
			try { window.sessionStorage.removeItem(FORM_KEY); } catch (e) {}
		}
	}

	function reloadToPayment() {
		saveForm();
		try { window.sessionStorage.setItem(SCROLL_KEY, '1'); } catch (e) {}
		window.location.reload();
	}

	// After a switch reload, bring the customer back to the payment section.
	function restoreScroll() {
		var flag = null;
		try { flag = window.sessionStorage.getItem(SCROLL_KEY); window.sessionStorage.removeItem(SCROLL_KEY); } catch (e) {}
		if (flag !== '1') { return; }
		window.__poCardSwitchReturned = true;
		var target = box();
		if (!target.length) { target = paymentArea(); }
		if (target.length && target.offset()) {
			$('html, body').scrollTop(Math.max(0, target.offset().top - 160));
		}
	}

	function request(brand, from, mode) {
		if (busy || !from || !cfg.ajaxUrl || (mode !== 'back' && !brand)) { return; }
		busy = true;
		var el = box();
		message(cfg.working);
		cover(true);
		$.post(cfg.ajaxUrl, {
			action: cfg.action, nonce: cfg.nonce, brand: brand || '', from: from, mode: mode || 'brand',
			order_id: el.data('order') || '', order_key: el.data('key') || ''
		}).done(function (res) {
			var data = res && res.data ? res.data : {};
			if (res && res.success && data.switched) {
				message(data.message);
				reloadToPayment();
				return;
			}
			busy = false;
			cover(false);
			if (data.message) { message(data.message); } else { box().find('.po-brand-switch__notice').remove(); }
		}).fail(function () {
			busy = false;
			cover(false);
			box().find('.po-brand-switch__notice').remove();
		});
	}

	$(function () { place(); restoreForm(false); restoreScroll(); });
	$(document.body).on('updated_checkout payment_method_selected', function () { busy = false; place(); });
	// FunnelKit/WooCommerce rebuild the payment section after load; re-apply once,
	// then forget the saved checkbox state.
	$(document.body).one('updated_checkout', function () { restoreForm(true); restoreScrollLate(); });
	setTimeout(function () { restoreForm(true); }, 4000);
	var lateScroll = false;
	function restoreScrollLate() {
		if (lateScroll) { return; }
		lateScroll = true;
		var target = box();
		if (target.length && document.activeElement === document.body && window.__poCardSwitchReturned && target.offset()) {
			$('html, body').scrollTop(Math.max(0, target.offset().top - 160));
		}
	}

	// Customer chose the option.
	$(document).on('click', '.po-brand-switch__option', function (e) {
		e.preventDefault();
		request(String($(this).data('brand') || ''), String(box().data('from') || ''), String($(this).data('mode') || 'brand'));
	});

	// PC NMI Accounts: the card typed into this payment option is a refused brand.
	$(document.body).on('pc_nmi_card_type_refused', function (e, gatewayId, brand) {
		request(String(brand || ''), String(gatewayId || box().data('from') || ''));
	});
})(jQuery);
JS;
    }

    private static function styles(): string {
        // Scoped and !important so theme button styles (colour fills, uppercase,
        // shadows) can't restyle it: a minimal light row with readable text.
        $o = '.po-brand-switch .po-brand-switch__option';
        return '.po-brand-switch-li{list-style:none!important;margin:0!important;padding:0 0 4px!important;border:0!important;background:transparent!important}'
            . '.po-brand-switch{margin:6px 0 10px;display:flex;flex-direction:column;gap:8px}'
            . $o . '{display:flex!important;align-items:center!important;gap:12px!important;width:100%!important;min-height:0!important;height:auto!important;margin:0!important;padding:12px 14px!important;background:#fff!important;color:#333!important;border:1px solid #dcdcdc!important;border-radius:6px!important;box-shadow:none!important;text-align:left!important;text-transform:none!important;letter-spacing:normal!important;font-size:14px!important;font-weight:400!important;line-height:1.4!important;cursor:pointer!important;opacity:1!important}'
            . $o . ':hover,' . $o . ':focus{border-color:#999!important;background:#fafafa!important;color:#111!important;outline:none!important}'
            . $o . ':focus-visible{outline:2px solid #999!important;outline-offset:2px!important}'
            . $o . '--back{background:transparent!important;border-style:dashed!important;font-size:13px!important;padding:10px 14px!important}'
            . '.po-brand-switch__icons{display:inline-flex!important;gap:6px!important;flex:0 0 auto!important}'
            . '.po-brand-switch__icons img{display:block!important;height:24px!important;width:auto!important;margin:0!important;border-radius:3px!important;box-shadow:0 0 0 1px rgba(0,0,0,.08)!important}'
            . '.po-brand-switch__text{color:#333!important}'
            . '.po-brand-switch__cta{text-decoration:underline!important;text-underline-offset:2px!important;font-weight:600!important;color:#111!important;white-space:nowrap}'
            . '.po-brand-switch__notice{margin:0!important;padding:10px 12px!important;border-radius:6px!important;background:#f4f4f2!important;color:#333!important;font-size:14px!important;line-height:1.4!important}';
    }


    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * @param string[] $brands Brands gained by the switch.
     */
    private function switchedMessage(array $brands): string {
        $labels = array_map([CardBrands::class, 'label'], array_values(array_filter($brands)));
        return sprintf(
            /* translators: %s: card brands, e.g. "American Express and Discover" */
            __('We\'ve switched you to a payment option that accepts %s. Please re-enter your card details.', 'payment-orchestrator-pro'),
            $labels ? $this->humanList($labels, 'and') : __('your card', 'payment-orchestrator-pro')
        );
    }

    /**
     * Notice after a switch, shown on the option it switched to. Kept for
     * NOTICE_WINDOW (not consumed on first render: checkout refreshes the
     * payment section right after load, which would hide it immediately).
     */
    private function switchNotice(string $shownId): string {
        $flag = $this->switchFlag();
        if (!$flag || (time() - (int) $flag['time']) > self::NOTICE_WINDOW) {
            return '';
        }
        if ($flag['to'] !== $shownId) {
            return '';
        }
        $gained = isset($flag['gained']) && is_array($flag['gained']) ? $flag['gained'] : [];
        if (!$gained) {
            $one = CardBrands::normalize((string) ($flag['brand'] ?? ''));
            $gained = $one !== '' ? [$one] : [];
        }
        return $this->switchedMessage($gained);
    }

    /** Current switch record from the session, or null. */
    private function switchFlag(): ?array {
        if (!function_exists('WC') || !WC()->session) {
            return null;
        }
        $flag = WC()->session->get(self::SESSION_FLAG);
        if (!is_array($flag) || empty($flag['to']) || empty($flag['from']) || empty($flag['time'])) {
            return null;
        }
        if ((time() - (int) $flag['time']) > self::BACK_WINDOW) {
            WC()->session->set(self::SESSION_FLAG, null);
            return null;
        }
        return $flag;
    }

    private function payPageOrder(): ?\WC_Order {
        $orderId = 0;
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            global $wp;
            $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        }
        // FunnelKit's own order-pay detection (its pay page can render after
        // WooCommerce's endpoint state has changed).
        if (!$orderId && function_exists('WFACP_Core')) {
            $pay = WFACP_Core()->pay ?? null;
            if ($pay && method_exists($pay, 'is_order_pay') && $pay->is_order_pay() && method_exists($pay, 'get_order_id')) {
                $orderId = absint($pay->get_order_id());
            }
        }
        $order = $orderId ? wc_get_order($orderId) : null;
        if (!$order) {
            return null;
        }
        $key = isset($_GET['key']) && is_string($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
        return ($key !== '' && hash_equals($order->get_order_key(), $key)) ? $order : null;
    }

    /**
     * Debug trace (once per entry point per request) of the pay-page state,
     * so a page where the switch option doesn't appear can be diagnosed from
     * POP's log without guesswork.
     */
    private function trace(string $where): void {
        if (!self::debugEnabled()) {
            return;
        }
        static $seen = [];
        if (isset($seen[$where]) || !(function_exists('is_checkout') && is_checkout())) {
            return;
        }
        $seen[$where] = true;
        global $wp;
        $qv = isset($wp->query_vars['order-pay']) ? $wp->query_vars['order-pay'] : null;
        $pay = function_exists('WFACP_Core') ? (WFACP_Core()->pay ?? null) : null;
        $order = null;
        try { $order = $this->payPageOrder(); } catch (\Throwable $e) { $order = null; }
        Logger::debug('Card-brand switch trace: ' . $where, [
            'endpoint_order_pay' => function_exists('is_wc_endpoint_url') ? is_wc_endpoint_url('order-pay') : null,
            'query_var'          => $qv,
            'key_present'        => isset($_GET['key']),
            'funnelkit_pay'      => $pay && method_exists($pay, 'is_order_pay') ? $pay->is_order_pay() : null,
            'funnelkit_order'    => $pay && method_exists($pay, 'get_order_id') ? $pay->get_order_id() : null,
            'pay_order_found'    => $order ? $order->get_id() : null,
            'already_rendered'   => $this->payPageRendered,
        ]);
    }

    private function cartTotal(): float {
        if (function_exists('WC') && WC()->cart) {
            return (float) WC()->cart->get_total('edit');
        }
        return 0.0;
    }

    /** @return array<string, \WC_Payment_Gateway> */
    private function wcGateways(): array {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return [];
        }
        return (array) WC()->payment_gateways()->payment_gateways();
    }

    private function humanList(array $items, string $conj = 'or'): string {
        $items = array_values(array_filter($items, 'strlen'));
        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' ' . $conj . ' ' . $last;
    }
}
