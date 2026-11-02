<?php
/**
 * Self-hosted plugin update integration.
 *
 * Lets WordPress discover and install new plugin releases served by the 3thix
 * server (`GET <api_base>/plugin/info`) instead of the merchant having to
 * re-download and re-upload the zip by hand. The plugin is also force-enabled
 * for auto-updates, so payment fixes roll out to every store automatically when
 * we bump the plugin version.
 *
 * Chicken-and-egg note: a store must run a build that CONTAINS this updater
 * before it can auto-pull future releases. Stores on an older version update
 * once manually; everything after that is automatic.
 *
 * @package Thix_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Thix_Updater {
    /** Transient key for the cached remote manifest. */
    const CACHE_KEY = 'thix_plugin_update_info';
    /** How long to cache the remote manifest (seconds). */
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;
    /** Plugin slug / folder name (must match the zip's top-level folder). */
    const SLUG = '3thix-payments';
    /**
     * Official update source, used when the gateway hasn't been configured yet
     * so EVERY install can still discover updates. The merchant's saved
     * `api_base` (and the `thix_update_api_base` filter) override it — e.g. a
     * test store pointed at a sandbox server pulls that server's build.
     */
    const DEFAULT_API_BASE = 'https://pay.3thix.com/api';

    /** @var string plugin_basename, e.g. 3thix-payments/3thix-payments.php */
    private static $basename = '';

    public static function init() {
        self::$basename = plugin_basename(THIX_PAYMENTS_FILE);

        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 20, 3);
        // Keep this plugin on auto-update so payment fixes ship without a manual
        // step. A merchant can still turn it off from the Plugins screen.
        add_filter('auto_update_plugin', [__CLASS__, 'enable_auto_update'], 10, 2);
        // Drop the cached manifest after any plugin upgrade so the next check is fresh.
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache'], 10, 0);
    }

    /**
     * Resolve the 3thix API base (ending in /api) to check for updates.
     *
     * Order of precedence: the merchant's saved gateway `api_base`, else the
     * official default, then a `thix_update_api_base` override filter. The result
     * MUST be HTTPS (auto-update delivers executable code) — plain HTTP is only
     * tolerated against localhost for development; anything else fails closed.
     *
     * @return string '' when no safe update source is available.
     */
    private static function api_base() {
        $settings = get_option('woocommerce_thix_settings');
        $raw = is_array($settings) && !empty($settings['api_base']) ? $settings['api_base'] : '';
        $base = $raw !== '' ? self::normalize_base($raw) : self::DEFAULT_API_BASE;

        /**
         * Override the update source (e.g. a staging server). Must be the API
         * base ending in /api.
         */
        $base = (string) apply_filters('thix_update_api_base', $base);
        if ($base === '' || !self::is_secure_url($base)) {
            return '';
        }
        return $base;
    }

    /**
     * Normalize a raw base URL to end in /api. Reuses the gateway's normalizer
     * when loaded, with a minimal fallback for when WooCommerce is inactive.
     *
     * @param string $raw
     * @return string
     */
    private static function normalize_base($raw) {
        if (class_exists('Thix_Api')) {
            return Thix_Api::normalize_base($raw);
        }
        $base = preg_replace('/[?#].*$/', '', rtrim(trim((string) $raw), '/'));
        if ($base === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        if (!preg_match('#/api$#i', $base)) {
            $base .= '/api';
        }
        return $base;
    }

    /**
     * True only for HTTPS URLs, or plain-HTTP against localhost (dev). Guards the
     * update fetch + package download against MITM code injection.
     *
     * @param string $url
     * @return bool
     */
    private static function is_secure_url($url) {
        if (preg_match('#^https://#i', (string) $url)) {
            return true;
        }
        return (bool) preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?(/|$)#i', (string) $url);
    }

    /**
     * Fetch + cache the remote update manifest.
     *
     * @param bool $force Bypass the transient cache.
     * @return array|null Decoded manifest, or null when unavailable/unconfigured.
     */
    private static function fetch_info($force = false) {
        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $base = self::api_base();
        if ($base === '') {
            return null;
        }

        // Identify this install so the server can show admins which plugin
        // versions are actually running in the field (host + installed
        // version; no secrets, keys, or buyer data).
        $site_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $headers = [
            'Accept'                => 'application/json',
            'X-Thix-Plugin-Version' => THIX_PAYMENTS_VERSION,
            'X-Thix-Site-Host'      => $site_host,
        ];
        // Truncated publishable-key hint (first 18 chars, matching the server's
        // stored key prefix) so the server can link this store to its merchant
        // exactly even when the merchant's saved website is stale or missing.
        // The publishable key is browser-safe by design, and only this short
        // prefix is ever sent — NEVER the full key, and never the secret key.
        $hint = self::publishable_key_hint();
        if ($hint !== '') {
            $headers['X-Thix-Key-Hint'] = $hint;
        }
        $response = wp_remote_get($base . '/plugin/info', [
            'timeout' => 15,
            'headers' => $headers,
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $info = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($info) || empty($info['version']) || empty($info['download_url'])) {
            return null;
        }
        set_transient(self::CACHE_KEY, $info, self::CACHE_TTL);
        return $info;
    }

    /**
     * Inject an available update into WordPress's plugin-update transient when
     * the remote version is newer than what's installed.
     *
     * @param mixed $transient
     * @return mixed
     */
    public static function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }
        $info = self::fetch_info();
        if (!$info) {
            return $transient;
        }
        $remote = (string) $info['version'];
        if (version_compare($remote, THIX_PAYMENTS_VERSION, '<=')) {
            return $transient;
        }
        // The package is executable code — never install it over an insecure URL.
        if (!self::is_secure_url((string) $info['download_url'])) {
            return $transient;
        }

        $item = (object) [
            'slug'         => self::SLUG,
            'plugin'       => self::$basename,
            'new_version'  => $remote,
            'url'          => isset($info['homepage']) ? (string) $info['homepage'] : 'https://3thix.com',
            'package'      => (string) $info['download_url'],
            'tested'       => isset($info['tested']) ? (string) $info['tested'] : '',
            'requires'     => isset($info['requires']) ? (string) $info['requires'] : '',
            'requires_php' => isset($info['requires_php']) ? (string) $info['requires_php'] : '',
            'icons'        => [],
        ];

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        $transient->response[self::$basename] = $item;
        return $transient;
    }

    /**
     * Provide the data for the "View details" modal (Plugins → View details).
     *
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }
        $info = self::fetch_info();
        if (!$info) {
            return $result;
        }

        $sections = isset($info['sections']) && is_array($info['sections']) ? $info['sections'] : [];

        return (object) [
            'name'          => isset($info['name']) ? (string) $info['name'] : '3thix Payments for WooCommerce',
            'slug'          => self::SLUG,
            'version'       => (string) $info['version'],
            'author'        => isset($info['author']) ? (string) $info['author'] : '3thix',
            'homepage'      => isset($info['homepage']) ? (string) $info['homepage'] : 'https://3thix.com',
            'requires'      => isset($info['requires']) ? (string) $info['requires'] : '',
            'tested'        => isset($info['tested']) ? (string) $info['tested'] : '',
            'requires_php'  => isset($info['requires_php']) ? (string) $info['requires_php'] : '',
            'download_link' => (string) $info['download_url'],
            'sections'      => $sections,
        ];
    }

    /**
     * Force auto-updates ON for this plugin regardless of the site default.
     *
     * @param bool|null    $update Whether to auto-update.
     * @param object|array $item   The update offer (object) or plugin row (array).
     * @return bool|null
     */
    public static function enable_auto_update($update, $item) {
        $plugin = '';
        if (is_object($item) && isset($item->plugin)) {
            $plugin = (string) $item->plugin;
        } elseif (is_array($item) && isset($item['plugin'])) {
            $plugin = (string) $item['plugin'];
        }
        if ($plugin === self::$basename) {
            return true;
        }
        return $update;
    }

    /**
     * First 18 chars of the configured publishable key (`thx_{env}_pk_` + first
     * 6 of the random tail — the same shape the server stores as the key's
     * display prefix), or '' when no publishable key is configured. Refuses to
     * derive a hint from anything that isn't a publishable key so a secret key
     * pasted in the wrong field can never leak even a truncated form.
     *
     * @return string
     */
    private static function publishable_key_hint() {
        $settings = get_option('woocommerce_thix_settings');
        $key = is_array($settings) && !empty($settings['publishable_key']) ? trim((string) $settings['publishable_key']) : '';
        if ($key === '' || !preg_match('/^thx_(test|live)_pk_/', $key)) {
            return '';
        }
        return substr($key, 0, 18);
    }

    /** Forget the cached manifest (after an upgrade). */
    public static function clear_cache() {
        delete_transient(self::CACHE_KEY);
    }
}
