<?php
/**
 * Plugin Name: BRW Checkout Skimmer Guard
 * Description: Checkout-only CSP report monitor + script inventory change detector for card-skimmer surveillance.
 * Version: 1.0.0
 * Author: BuildRightWeb
 */

if (!defined('ABSPATH')) exit;

final class BRW_Checkout_Skimmer_Guard {
    private const OPT_BASELINE = 'brw_csg_script_baseline_v1';
    private const OPT_KNOWN = 'brw_csg_known_seen_v1';
    private const NONCE_ACTION = 'brw_csg_inventory';
    private const LOG_SOURCE = 'brw-checkout-skimmer-guard';

    public static function init(): void {
        add_action('send_headers', [__CLASS__, 'send_csp_report_only_header'], 20);
        add_action('wp_footer', [__CLASS__, 'inject_inventory_script'], 9999);
        add_action('wp_ajax_brw_csg_inventory', [__CLASS__, 'ajax_inventory']);
        add_action('wp_ajax_nopriv_brw_csg_inventory', [__CLASS__, 'ajax_inventory']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('admin_init', [__CLASS__, 'maybe_reset_baseline']);
    }

    private static function is_checkout_context(): bool {
        if (is_admin()) return false;
        if (function_exists('is_checkout') && is_checkout()) return true;
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        return (strpos($uri, '/checkout') !== false || strpos($uri, 'wc-ajax=checkout') !== false);
    }

    public static function send_csp_report_only_header(): void {
        if (!self::is_checkout_context() || headers_sent()) return;

        $report_url = esc_url_raw(rest_url('brw-csg/v1/csp-report'));

        // Report-only: does not block checkout. It logs suspicious policy violations while we harden.
        $policy = implode('; ', [
            "default-src 'self' https: data: blob:",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://client.crisp.chat https://cdn.brevo.com https://cdn.by.wonderpush.com https://maps.googleapis.com https://load.tracking.certified-pep.com https://cdn.jsdelivr.net https://widget.reviews.io https://secure.snappay.co",
            "connect-src 'self' https: wss:",
            "img-src 'self' https: data: blob:",
            "style-src 'self' 'unsafe-inline' https:",
            "font-src 'self' https: data:",
            "frame-src 'self' https:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https://secure.snappay.co",
            "report-uri {$report_url}"
        ]);

        header('Content-Security-Policy-Report-Only: ' . $policy);
    }

    public static function register_rest_routes(): void {
        register_rest_route('brw-csg/v1', '/csp-report', [
            'methods' => ['POST'],
            'callback' => [__CLASS__, 'handle_csp_report'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_csp_report(WP_REST_Request $request): WP_REST_Response {
        $raw = $request->get_body();
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = ['raw' => substr((string) $raw, 0, 2000)];

        $report = isset($data['csp-report']) && is_array($data['csp-report']) ? $data['csp-report'] : $data;
        $clean = [
            'blocked_uri' => self::clean($report['blocked-uri'] ?? $report['blocked_uri'] ?? ''),
            'violated_directive' => self::clean($report['violated-directive'] ?? $report['violated_directive'] ?? ''),
            'effective_directive' => self::clean($report['effective-directive'] ?? $report['effective_directive'] ?? ''),
            'document_uri' => self::clean($report['document-uri'] ?? $report['document_uri'] ?? ''),
            'source_file' => self::clean($report['source-file'] ?? $report['source_file'] ?? ''),
            'line_number' => isset($report['line-number']) ? absint($report['line-number']) : 0,
            'sample' => self::clean($report['script-sample'] ?? $report['sample'] ?? ''),
        ];

        self::log('warning', 'CSP report received on checkout', $clean);
        return new WP_REST_Response(['ok' => true], 204);
    }

    public static function inject_inventory_script(): void {
        if (!self::is_checkout_context()) return;
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $ajax = admin_url('admin-ajax.php');
        ?>
<script id="brw-checkout-skimmer-guard-js">
(function(){
  'use strict';
  if (window.__BRW_CSG_DONE__) return;
  window.__BRW_CSG_DONE__ = true;

  function b64(buf){
    var bytes = new Uint8Array(buf), s = '';
    for (var i=0;i<bytes.length;i++) s += String.fromCharCode(bytes[i]);
    return btoa(s);
  }
  function sha256(text){
    if (!window.crypto || !crypto.subtle || !window.TextEncoder) return Promise.resolve('no-crypto');
    return crypto.subtle.digest('SHA-256', new TextEncoder().encode(text || '')).then(function(hash){
      return 'sha256-' + b64(hash);
    }).catch(function(){ return 'hash-error'; });
  }
  function cleanSrc(src){
    try {
      var u = new URL(src, window.location.origin);
      u.search = '';
      u.hash = '';
      return u.href;
    } catch(e){ return String(src || '').split('?')[0].split('#')[0]; }
  }
  function collect(){
    var scripts = Array.prototype.slice.call(document.scripts || []);
    return Promise.all(scripts.map(function(s, i){
      if (s.src) {
        return Promise.resolve({type:'external', id:s.id || '', src:cleanSrc(s.src)});
      }
      var text = s.textContent || '';
      return sha256(text).then(function(hash){
        return {type:'inline', id:s.id || '', hash:hash, len:text.length};
      });
    }));
  }
  function send(items){
    var body = new URLSearchParams();
    body.set('action', 'brw_csg_inventory');
    body.set('nonce', <?php echo wp_json_encode($nonce); ?>);
    body.set('url', window.location.href.split('#')[0]);
    body.set('items', JSON.stringify(items || []));
    fetch(<?php echo wp_json_encode($ajax); ?>, {
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString(), keepalive:true
    }).catch(function(){});
  }
  if (document.readyState === 'complete') collect().then(send);
  else window.addEventListener('load', function(){ setTimeout(function(){ collect().then(send); }, 300); });
})();
</script>
        <?php
    }

    public static function ajax_inventory(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
        $items = json_decode((string) $raw, true);
        if (!is_array($items)) $items = [];

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $type = sanitize_key($item['type'] ?? '');
            if ($type === 'external') {
                $src = esc_url_raw((string) ($item['src'] ?? ''));
                if ($src) $normalized[] = 'external|' . $src;
            } elseif ($type === 'inline') {
                $hash = sanitize_text_field((string) ($item['hash'] ?? ''));
                $id = sanitize_text_field((string) ($item['id'] ?? ''));
                $len = absint($item['len'] ?? 0);
                if ($hash) $normalized[] = 'inline|' . $hash . '|id=' . $id . '|len=' . $len;
            }
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        $baseline = get_option(self::OPT_BASELINE, null);
        if (!is_array($baseline) || empty($baseline)) {
            update_option(self::OPT_BASELINE, $normalized, false);
            update_option(self::OPT_KNOWN, $normalized, false);
            self::log('info', 'Checkout script baseline created', ['url' => $url, 'count' => count($normalized)]);
            wp_send_json_success(['baseline_created' => true, 'count' => count($normalized)]);
        }

        $known = get_option(self::OPT_KNOWN, []);
        if (!is_array($known)) $known = [];

        $new = array_values(array_diff($normalized, $known));
        $removed_from_baseline = array_values(array_diff($baseline, $normalized));

        if (!empty($new)) {
            self::log('warning', 'NEW checkout script/hash detected', ['url' => $url, 'new' => $new]);
            $known = array_values(array_unique(array_merge($known, $new)));
            sort($known, SORT_STRING);
            update_option(self::OPT_KNOWN, $known, false);
        }

        if (!empty($removed_from_baseline)) {
            self::log('notice', 'Baseline checkout script/hash missing on this load', ['url' => $url, 'missing' => $removed_from_baseline]);
        }

        wp_send_json_success(['new' => count($new), 'missing' => count($removed_from_baseline), 'count' => count($normalized)]);
    }

    public static function maybe_reset_baseline(): void {
        if (!current_user_can('manage_options')) return;
        if (!isset($_GET['brw_checkout_guard_reset'])) return;
        check_admin_referer('brw_checkout_guard_reset');
        delete_option(self::OPT_BASELINE);
        delete_option(self::OPT_KNOWN);
        self::log('info', 'Checkout script baseline reset by admin', []);
        wp_safe_redirect(remove_query_arg(['brw_checkout_guard_reset', '_wpnonce']));
        exit;
    }

    private static function clean($value): string {
        $value = is_scalar($value) ? (string) $value : '';
        $value = wp_strip_all_tags($value);
        return substr($value, 0, 500);
    }

    private static function log(string $level, string $message, array $context = []): void {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message . ' ' . wp_json_encode($context), ['source' => self::LOG_SOURCE]);
        } else {
            error_log('[BRW Checkout Skimmer Guard] ' . $level . ' ' . $message . ' ' . wp_json_encode($context));
        }
    }
}

BRW_Checkout_Skimmer_Guard::init();
