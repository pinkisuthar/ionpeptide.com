<?php
/**
 * Admin theme options (2.7.27).
 *
 * Default  - POP's original look. Nothing in this class runs: pages render
 *            exactly as before.
 * Midnight Lime / Daylight Ledger - the same pages and layout, recoloured as
 *            they are sent to the browser. Every colour POP uses (inline styles,
 *            <style> blocks, chart data, assets/css/admin.css) is mapped by its
 *            ROLE (text, background, border) and MEANING (dark text, muted text,
 *            surface, border, success, warning, danger, info, header) to the
 *            theme's palette. Where an element sets both a coloured fill and its
 *            text, the text is chosen for that fill, so text stays readable.
 *
 * The choice is stored per admin user (user meta "po_admin_theme").
 *
 * @package PaymentOrchestrator\Admin
 * @since 2.7.27
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Admin;

defined('ABSPATH') || exit;

final class Theme {

    public const DEFAULT = 'default';
    public const META_KEY = 'po_admin_theme';

    /** Theme id => label. */
    public const THEMES = [
        'default'  => 'Default',
        'midnight' => 'Midnight Lime',
        'daylight' => 'Daylight Ledger',
        'command'  => 'Command Center',
    ];

    /** Layout themes: change the Dashboard's layout, not the colours (2.7.33). */
    public const LAYOUT_THEMES = ['command'];

    /** Palettes. Every text/background pair used by the mapping is contrast-tested (QA kit). */
    public const PALETTES = [
        'midnight' => [
            'font'  => "Urbanist, 'Segoe UI', system-ui, -apple-system, sans-serif",
            'gfont' => 'Urbanist:wght@400;500;600;700',
            'page' => '#2B2B2B', 'card' => '#1C1C1C', 'card2' => '#262626', 'track' => '#333333',
            'line' => '#2E2E2E', 'line2' => '#3A3A3A',
            'ink' => '#FFFFFF', 'sub' => '#D4D4D4', 'mut' => '#A3A3A3', 'onDark' => '#FFFFFF', 'hdr' => '#141414',
            'good' => '#D9FF5B', 'goodBg' => '#2A3317', 'goodStrong' => '#D9FF5B', 'onGood' => '#0A0A0A',
            'bad' => '#FF8A80', 'badBg' => '#3A2020', 'badStrong' => '#FF6B6B', 'onBad' => '#0A0A0A',
            'warn' => '#F5B94A', 'warnBg' => '#3A2E17', 'warnStrong' => '#F5B94A', 'onWarn' => '#0A0A0A',
            'info' => '#A8C7FF', 'infoBg' => '#1F2A3D', 'infoStrong' => '#D9FF5B', 'onInfo' => '#0A0A0A',
            'link' => '#D9FF5B',
        ],
        'daylight' => [
            'font'  => "Manrope, 'Segoe UI', system-ui, -apple-system, sans-serif",
            'gfont' => 'Manrope:wght@400;500;600;700;800',
            'page' => '#EDEDED', 'card' => '#FFFFFF', 'card2' => '#F5F5F5', 'track' => '#E6E7EA',
            'line' => '#ECECEC', 'line2' => '#DADDE1',
            'ink' => '#0B0B0B', 'sub' => '#3A3D42', 'mut' => '#62666D', 'onDark' => '#FFFFFF', 'hdr' => '#0B0B0B',
            'good' => '#0B6B3A', 'goodBg' => '#E3F7EC', 'goodStrong' => '#1FC86B', 'onGood' => '#0B0B0B',
            'bad' => '#B42318', 'badBg' => '#FDE7E3', 'badStrong' => '#E5484D', 'onBad' => '#0B0B0B',
            'warn' => '#8A4500', 'warnBg' => '#FFF1DD', 'warnStrong' => '#FF9F2E', 'onWarn' => '#0B0B0B',
            'info' => '#1E5AA8', 'infoBg' => '#E6F0FB', 'infoStrong' => '#0B0B0B', 'onInfo' => '#FFFFFF',
            'link' => '#0B0B0B',
        ],
    ];

    private string $theme = self::DEFAULT;

    /** Three colours that represent a theme (for the Settings picker). */
    public static function swatches(string $theme): array {
        if ($theme === 'command') {
            return ['#f6f7f9', '#378ADD', '#1D9E75']; // Command Center
        }
        if (!isset(self::PALETTES[$theme])) {
            return ['#ffffff', '#1e293b', '#2563eb']; // Default look
        }
        $p = self::PALETTES[$theme];
        return [$p['page'], $p['card'], $p['goodStrong']];
    }

    public function register(): void {
        add_action('current_screen', [$this, 'boot']);
    }

    /** Only acts on POP screens, and only when a non-default theme is chosen. */
    public function boot(): void {
        if (!self::isPopScreen()) {
            return;
        }
        $this->theme = self::current();
        // 2.7.28: apply a theme the moment Settings is saved (the save itself
        // happens later in the same request, while the page renders).
        if (isset($_POST['po_save_settings'], $_POST['po_admin_theme'], $_POST['_wpnonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'po_settings')) {
            $posted = sanitize_key(wp_unslash((string) $_POST['po_admin_theme']));
            if (isset(self::THEMES[$posted])) {
                $this->theme = $posted;
            }
        }
        if ($this->theme === self::DEFAULT || !isset(self::PALETTES[$this->theme])) {
            return; // Default and layout themes (Command Center): no recolouring.
        }
        add_filter('admin_body_class', [$this, 'bodyClass']);
        add_action('admin_head', [$this, 'printHeadStyles'], 99);
        add_action('in_admin_header', [$this, 'startBuffer'], 0);
        add_action('admin_footer', [$this, 'endBuffer'], 0);
    }

    public static function current(): string {
        $t = (string) get_user_meta(get_current_user_id(), self::META_KEY, true);
        return isset(self::THEMES[$t]) ? $t : self::DEFAULT;
    }

    public static function save(string $theme): void {
        if (!isset(self::THEMES[$theme])) {
            return;
        }
        if ($theme === self::DEFAULT) {
            delete_user_meta(get_current_user_id(), self::META_KEY);
        } else {
            update_user_meta(get_current_user_id(), self::META_KEY, $theme);
        }
    }

    private static function isPopScreen(): bool {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return strpos($page, 'payment-orchestrator') === 0;
    }

    public function bodyClass(string $classes): string {
        return $classes . ' po-theme-' . $this->theme;
    }

    /* ------------------------------------------------------------------
     * Output
     * ------------------------------------------------------------------ */

    public function printHeadStyles(): void {
        $p = self::PALETTES[$this->theme];
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
        echo '<link rel="stylesheet" href="' . esc_url('https://fonts.googleapis.com/css2?family=' . $p['gfont'] . '&display=swap') . '">';
        echo '<style id="po-theme-' . esc_attr($this->theme) . '">' . $this->baseCss() . "\n" . $this->themedStylesheet() . '</style>';
    }

    public function startBuffer(): void {
        ob_start();
    }

    public function endBuffer(): void {
        $html = (string) ob_get_clean();
        echo $this->filterHtml($html); // phpcs:ignore WordPress.Security.EscapeOutput - POP's own already-escaped output, recoloured.
    }

    /** Page background, typography and WordPress form controls on POP screens. */
    private function baseCss(): string {
        $p = self::PALETTES[$this->theme];
        $b = 'body.po-theme-' . $this->theme;
        return "html,{$b},{$b} #wpwrap,{$b} #wpcontent,{$b} #wpbody,{$b} #wpbody-content{background:{$p['page']}}"
            . "{$b} #wpbody-content .wrap,{$b} #wpbody-content .wrap *:not(.dashicons):not(code):not(pre){font-family:{$p['font']}}"
            . "{$b} .wrap,{$b} .wrap h1,{$b} .wrap h2,{$b} .wrap h3,{$b} .wrap h4,{$b} .wrap p,{$b} .wrap label,{$b} .wrap td,{$b} .wrap th{color:{$p['ink']}}"
            . "{$b} .wrap a{color:{$p['link']}}"
            . "{$b} .wrap input[type=text],{$b} .wrap input[type=number],{$b} .wrap input[type=email],{$b} .wrap input[type=url],{$b} .wrap input[type=password],{$b} .wrap input[type=time],{$b} .wrap input[type=date],{$b} .wrap select,{$b} .wrap textarea"
            . "{background:{$p['card2']};color:{$p['ink']};border-color:{$p['line2']}}"
            . "{$b} .wrap .button:not(.button-primary){background:{$p['card2']};color:{$p['ink']};border-color:{$p['line2']}}"
            . "{$b} .wrap .button-primary{background:{$p['infoStrong']};color:{$p['onInfo']};border-color:{$p['infoStrong']}}"
            . "{$b} .notice{background:{$p['card']};color:{$p['ink']}}";
    }

    /** POP's admin.css recoloured (cached per theme and version). */
    private function themedStylesheet(): string {
        $file = PO_PLUGIN_DIR . 'assets/css/admin.css';
        if (!is_readable($file)) {
            return '';
        }
        $key = 'po_theme_css_' . $this->theme . '_' . md5(PO_VERSION . '|' . (string) filemtime($file));
        $css = get_transient($key);
        if (!is_string($css)) {
            $css = $this->filterCss((string) file_get_contents($file));
            set_transient($key, $css, DAY_IN_SECONDS);
        }
        return $css;
    }

    /* ------------------------------------------------------------------
     * Recolouring
     * ------------------------------------------------------------------ */

    public function filterHtml(string $html): string {
        // style="..." / style='...'
        $html = (string) preg_replace_callback('/\sstyle\s*=\s*("([^"]*)"|\'([^\']*)\')/i', function ($m) {
            $q = $m[1][0];
            $decl = $q === '"' ? $m[2] : $m[3];
            return ' style=' . $q . $this->filterDeclarations($decl) . $q;
        }, $html);
        // <style> blocks
        $html = (string) preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function ($m) {
            return $m[1] . $this->filterCss($m[2]) . $m[3];
        }, $html);
        // <script> blocks: chart/series colours written as JS strings
        $html = (string) preg_replace_callback('/(<script\b[^>]*>)(.*?)(<\/script>)/is', function ($m) {
            $js = (string) preg_replace_callback('/([\'"])(#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3})\1/', function ($c) {
                return $c[1] . $this->map($c[2], 'fg') . $c[1];
            }, $m[2]);
            return $m[1] . $js . $m[3];
        }, $html);
        // Hex colours inside JSON data attributes / onmouseenter handlers
        $html = (string) preg_replace_callback('/(this\.style\.(color|background|borderColor)\s*=\s*)([\'"])(#[0-9a-fA-F]{3,6})\3/', function ($m) {
            $role = $m[2] === 'color' ? 'fg' : ($m[2] === 'background' ? 'bg' : 'bd');
            return $m[1] . $m[3] . $this->map($m[4], $role) . $m[3];
        }, $html);
        return $html;
    }

    public function filterCss(string $css): string {
        return (string) preg_replace_callback('/\{([^{}]*)\}/', function ($m) {
            return '{' . $this->filterDeclarations($m[1]) . '}';
        }, $css);
    }

    /**
     * Recolour one declaration block. When the block sets a strong coloured
     * background, its text colour is chosen for readability on that fill.
     */
    public function filterDeclarations(string $decl): string {
        $parts = explode(';', $decl);
        $fill = null;
        foreach ($parts as $part) {
            if (preg_match('/^\s*background(?:-color)?\s*:(.*)$/is', $part, $mm)) {
                if (preg_match('/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b/', $mm[1], $hx)) {
                    $fill = self::token($hx[0], 'bg');
                }
            }
        }
        foreach ($parts as $i => $part) {
            if (!preg_match('/^(\s*)([a-zA-Z-]+)(\s*:)(.*)$/s', $part, $mm)) {
                continue;
            }
            $prop = strtolower($mm[2]);
            $role = null;
            if ($prop === 'color' || $prop === 'fill' || $prop === 'stroke') {
                $role = 'fg';
            } elseif ($prop === 'background' || $prop === 'background-color' || $prop === 'background-image') {
                $role = 'bg';
            } elseif (strpos($prop, 'border') === 0 || strpos($prop, 'outline') === 0) {
                $role = 'bd';
            }
            if ($role === null) {
                continue;
            }
            $value = (string) preg_replace_callback('/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b/', function ($h) use ($role, $fill) {
                if ($role === 'fg' && $fill !== null && isset(self::ON_FILL[$fill])) {
                    return self::PALETTES[$this->theme][self::ON_FILL[$fill]];
                }
                return $this->map($h[0], $role);
            }, $mm[4]);
            $parts[$i] = $mm[1] . $mm[2] . $mm[3] . $value;
        }
        return implode(';', $parts);
    }

    /** Text colour to use on each strong fill. */
    private const ON_FILL = [
        'goodStrong' => 'onGood', 'badStrong' => 'onBad', 'warnStrong' => 'onWarn',
        'infoStrong' => 'onInfo', 'hdr' => 'onDark',
    ];

    private function map(string $hex, string $role): string {
        return self::PALETTES[$this->theme][self::token($hex, $role)] ?? $hex;
    }

    /**
     * Classify an original POP colour by role and meaning -> palette token.
     */
    public static function token(string $hex, string $role): string {
        [$h, $s, $l, $chroma] = self::hsl($hex);
        // Neutral = greys, incl. POP's blue-grey "slate" tones (low chroma, hue 190-250).
        // Light tints of real colours (e.g. #DCFCE7, #DBEAFE) stay coloured.
        $neutral = $chroma < 0.06 || ($chroma < 0.17 && $l < 0.90 && $h >= 190 && $h <= 250);
        if ($neutral) {
            if ($role === 'fg') {
                if ($l < 0.30) return 'ink';
                if ($l < 0.50) return 'sub';
                if ($l < 0.85) return 'mut';
                return 'onDark';
            }
            if ($role === 'bg') {
                if ($l >= 0.985) return 'card';
                if ($l >= 0.94) return 'card2';
                if ($l >= 0.80) return 'track';
                if ($l < 0.35) return 'hdr';
                return 'card2';
            }
            return $l >= 0.80 ? 'line' : 'line2';
        }
        $hue = ($h >= 75 && $h < 175) ? 'good'
             : (($h < 15 || $h >= 335) ? 'bad'
             : (($h >= 15 && $h < 70) ? 'warn' : 'info'));
        if ($role === 'bg') {
            return $l >= 0.85 ? $hue . 'Bg' : $hue . 'Strong';
        }
        if ($role === 'bd') {
            return $l >= 0.80 ? $hue . 'Bg' : $hue;
        }
        return $hue;
    }

    /** @return array{0:float,1:float,2:float,3:float} hue (0-360), saturation, lightness, chroma (0-1) */
    public static function hsl(string $hex): array {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = (string) preg_replace('/(.)/', '$1$1', $hex); // #abc -> #aabbcc
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l, 0.0];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max === $r) {
            $h = (($g - $b) / $d) + ($g < $b ? 6 : 0);
        } elseif ($max === $g) {
            $h = (($b - $r) / $d) + 2;
        } else {
            $h = (($r - $g) / $d) + 4;
        }
        return [$h * 60, $s, $l, $d];
    }
}
