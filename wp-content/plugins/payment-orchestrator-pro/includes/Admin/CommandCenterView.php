<?php
/**
 * View helpers for the Command Center dashboard (2.7.35).
 *
 * Icons live in assets/icons/command-center.svg and are referenced with <use>;
 * styles and script are separate asset files enqueued by AdminController.
 *
 * @package PaymentOrchestrator\Admin
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Admin;

defined('ABSPATH') || exit;

final class CommandCenterView {

    /** Icon names available in assets/icons/command-center.svg. */
    public const ICONS = [
        'chart', 'cart', 'target', 'refresh', 'alert', 'shield', 'retry', 'mail', 'calendar', 'octagon',
        'server', 'card', 'dots', 'bank', 'bars', 'left', 'right', 'up', 'down',
    ];

    public static function money(float $value, int $decimals = 0): string {
        return '$' . number_format($value, $decimals);
    }

    /** Compact money: $1.25M, $172k, $9.8k, $950. */
    public static function short(float $value): string {
        if ($value >= 1000000) {
            return '$' . number_format($value / 1000000, 2) . 'M';
        }
        if ($value >= 1000) {
            return '$' . number_format($value / 1000, $value >= 100000 ? 0 : 1) . 'k';
        }
        return '$' . number_format($value);
    }

    /** Inline SVG referencing the icon file. Unknown names render nothing. */
    public static function icon(string $name, string $color = ''): string {
        if (!in_array($name, self::ICONS, true)) {
            return '';
        }
        $hex   = $color !== '' ? sanitize_hex_color($color) : '';
        $style = $hex ? ' style="color:' . esc_attr($hex) . '"' : '';
        $href  = PO_PLUGIN_URL . 'assets/icons/command-center.svg?ver=' . rawurlencode(PO_VERSION) . '#' . $name;
        return '<svg class="po-cc-i" aria-hidden="true" focusable="false"' . $style . '><use href="' . esc_url($href) . '"></use></svg>';
    }

    /** "+12%" / "-8%" pill, or '' when there is nothing to compare with. */
    public static function delta(?float $pct): string {
        if ($pct === null) {
            return '';
        }
        $up = $pct >= 0;
        return '<span class="po-cc-pill ' . ($up ? 'is-up' : 'is-down') . '">' . self::icon($up ? 'up' : 'down')
            . esc_html(($up ? '+' : '') . (int) $pct . '%') . '</span>';
    }
}
