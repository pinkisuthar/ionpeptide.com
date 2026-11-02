<?php
/**
 * Plugin Name: Ion Peptide Promo Control
 * Description: Admin-controlled top announcement bar and promo popup for Ion Peptide.
 * Version: 1.0.10
 * Author: BuildRightWeb
 * Author URI: https://buildrightweb.com
 * Text Domain: ion-promo-control
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ION_Promo_Control {
    private $topbar_rendered = false;
    const OPTION_KEY = 'ion_promo_control_settings';
    const NONCE_KEY  = 'ion_promo_control_nonce';
    const VERSION    = '1.0.10';

    public function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'maybe_save_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_assets'));
        add_action('wp_body_open', array($this, 'render_topbar'), 1);
        add_action('wp_footer', array($this, 'render_topbar'), 1); // fallback for themes missing wp_body_open
        add_action('wp_footer', array($this, 'render_popup'), 20);
    }

    public static function defaults() {
        return array(
            'topbar_enabled'       => '0',
            'topbar_bg'            => '#0D0D67',
            'topbar_text_color'    => '#ffffff',
            'topbar_accent_color'  => '#527DF4',
            'topbar_messages'      => 'Weekend Sale is Live! Please allow 1-3 extra business days for order processing!',

            'popup_enabled'        => '0',
            'popup_eyebrow'        => 'Weekend Sale Notice',
            'popup_title'          => 'Weekend Sale Is Live',
            'popup_subtitle'       => '',
            'popup_body'           => 'Our weekend sale is active now. Orders will be processed in the order they are received, and tracking updates will be sent once fulfillment is complete.',
            'popup_coupon'         => 'Processing notice: 1-3 extra business days may be needed during the sale.',
            'popup_button_text'    => 'Shop Weekend Sale',
            'popup_button_url'     => '/shop/',
            'popup_secondary_text' => 'Continue browsing',
            'popup_logo_url'       => 'https://ionpeptide.com/wp-content/uploads/2026/04/ion-peptide-icon.png',
            'popup_delay'          => '1200',
            'popup_cooldown_hours' => '0',
            'popup_scope'          => 'all',
        );
    }

    public static function settings() {
        $saved = get_option(self::OPTION_KEY, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return wp_parse_args($saved, self::defaults());
    }

    public function admin_menu() {
        add_menu_page(
            'Ion Promo Control',
            'Ion Promo Control',
            'manage_options',
            'ion-promo-control',
            array($this, 'admin_page'),
            'dashicons-megaphone',
            56
        );
    }

    public function maybe_save_settings() {
        if (!is_admin() || empty($_POST['ion_promo_control_save'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer(self::NONCE_KEY);

        $defaults = self::defaults();
        $settings = array();

        $settings['topbar_enabled']       = empty($_POST['topbar_enabled']) ? '0' : '1';
        $settings['topbar_bg']            = $this->sanitize_hex_or_default($_POST['topbar_bg'] ?? '', $defaults['topbar_bg']);
        $settings['topbar_text_color']    = $this->sanitize_hex_or_default($_POST['topbar_text_color'] ?? '', $defaults['topbar_text_color']);
        $settings['topbar_accent_color']  = $this->sanitize_hex_or_default($_POST['topbar_accent_color'] ?? '', $defaults['topbar_accent_color']);
        $settings['topbar_messages']      = $this->sanitize_topbar_html($_POST['topbar_messages'] ?? $defaults['topbar_messages']);

        $settings['popup_enabled']        = empty($_POST['popup_enabled']) ? '0' : '1';
        $settings['popup_eyebrow']        = sanitize_text_field($_POST['popup_eyebrow'] ?? $defaults['popup_eyebrow']);
        $settings['popup_title']          = sanitize_text_field($_POST['popup_title'] ?? $defaults['popup_title']);
        $settings['popup_subtitle']       = $this->sanitize_multiline($_POST['popup_subtitle'] ?? $defaults['popup_subtitle']);
        $settings['popup_body']           = $this->sanitize_rich_multiline($_POST['popup_body'] ?? $defaults['popup_body']);
        $settings['popup_coupon']         = $this->sanitize_multiline($_POST['popup_coupon'] ?? $defaults['popup_coupon']);
        $settings['popup_button_text']    = sanitize_text_field($_POST['popup_button_text'] ?? $defaults['popup_button_text']);
        $settings['popup_button_url']     = esc_url_raw($_POST['popup_button_url'] ?? $defaults['popup_button_url']);
        $settings['popup_secondary_text'] = sanitize_text_field($_POST['popup_secondary_text'] ?? $defaults['popup_secondary_text']);
        $settings['popup_logo_url']       = esc_url_raw($_POST['popup_logo_url'] ?? $defaults['popup_logo_url']);
        $settings['popup_delay']          = (string) max(0, min(15000, absint($_POST['popup_delay'] ?? $defaults['popup_delay'])));
        $settings['popup_cooldown_hours'] = (string) max(0, min(720, absint($_POST['popup_cooldown_hours'] ?? $defaults['popup_cooldown_hours'])));

        $scope = sanitize_key($_POST['popup_scope'] ?? $defaults['popup_scope']);
        $settings['popup_scope'] = in_array($scope, array('all', 'home', 'shop'), true) ? $scope : 'all';

        update_option(self::OPTION_KEY, $settings, false);

        wp_safe_redirect(add_query_arg(array('page' => 'ion-promo-control', 'updated' => '1'), admin_url('admin.php')));
        exit;
    }

    private function sanitize_hex_or_default($value, $default) {
        $value = sanitize_hex_color($value);
        return $value ? $value : $default;
    }

    private function sanitize_multiline($value) {
        $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
        $lines = array_filter(array_map('trim', explode("\n", $value)), 'strlen');
        $lines = array_map('sanitize_text_field', $lines);
        return implode("\n", $lines);
    }

    private function sanitize_rich_multiline($value) {
        $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
        $value = wp_kses_post($value);
        return trim($value);
    }

    private function topbar_allowed_html() {
        return array(
            'br'     => array(),
            'strong' => array(),
            'b'      => array(),
            'em'     => array(),
            'i'      => array(),
            'span'   => array('class' => true),
            'a'      => array(
                'href'   => true,
                'target' => true,
                'rel'    => true,
                'class'  => true,
            ),
        );
    }

    private function sanitize_topbar_html($value) {
        $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
        $value = trim($value);
        if ($value === '') {
            return self::defaults()['topbar_messages'];
        }
        return wp_kses($value, $this->topbar_allowed_html());
    }

    private function topbar_html($settings) {
        $html = trim((string) $settings['topbar_messages']);
        if ($html === '') {
            $html = self::defaults()['topbar_messages'];
        }
        $html = wp_kses($html, $this->topbar_allowed_html());
        return apply_filters('ion_promo_topbar_html', $html);
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_ion-promo-control') {
            return;
        }

        wp_register_style('ion-promo-control-admin', false, array(), self::VERSION);
        wp_enqueue_style('ion-promo-control-admin');
        wp_add_inline_style('ion-promo-control-admin', $this->admin_css());
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s = self::settings();
        $preview_url = add_query_arg('ion_promo_preview', '1', home_url('/'));
        ?>
        <div class="wrap ionpc-admin">
            <div class="ionpc-hero">
                <div>
                    <p class="ionpc-kicker">Ion Peptide</p>
                    <h1>Promo Control</h1>
                    <p>Control the top website announcement bar and optional popup without touching Elementor or the header template.</p>
                </div>
                <a class="ionpc-preview" href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener">Preview Popup</a>
            </div>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Ion promo settings saved.</p></div>
            <?php endif; ?>

            <form method="post" class="ionpc-form">
                <?php wp_nonce_field(self::NONCE_KEY); ?>
                <input type="hidden" name="ion_promo_control_save" value="1">

                <section class="ionpc-card">
                    <div class="ionpc-card-head">
                        <div>
                            <h2>Top Announcement Bar</h2>
                            <p>Adds a standalone floating bar at the very top of the website without moving the Elementor header.</p>
                        </div>
                        <label class="ionpc-switch">
                            <input type="checkbox" name="topbar_enabled" value="1" <?php checked($s['topbar_enabled'], '1'); ?>>
                            <span></span>
                            <strong>Enabled</strong>
                        </label>
                    </div>

                    <label class="ionpc-label">Bar message <small>Safe HTML allowed. Use &lt;br&gt; for exact line breaks.</small></label>
                    <textarea name="topbar_messages" rows="5" class="large-text" spellcheck="false"><?php echo esc_textarea($s['topbar_messages']); ?></textarea>

                    <div class="ionpc-grid-3">
                        <p>
                            <label class="ionpc-label">Background</label>
                            <input type="text" name="topbar_bg" value="<?php echo esc_attr($s['topbar_bg']); ?>" class="regular-text">
                        </p>
                        <p>
                            <label class="ionpc-label">Text color</label>
                            <input type="text" name="topbar_text_color" value="<?php echo esc_attr($s['topbar_text_color']); ?>" class="regular-text">
                        </p>
                        <p>
                            <label class="ionpc-label">Accent color</label>
                            <input type="text" name="topbar_accent_color" value="<?php echo esc_attr($s['topbar_accent_color']); ?>" class="regular-text">
                        </p>
                    </div>
                </section>

                <section class="ionpc-card">
                    <div class="ionpc-card-head">
                        <div>
                            <h2>Promo Popup</h2>
                            <p>Use this for sale notices, processing delay updates, shipping notices, or urgent customer-facing alerts.</p>
                        </div>
                        <label class="ionpc-switch">
                            <input type="checkbox" name="popup_enabled" value="1" <?php checked($s['popup_enabled'], '1'); ?>>
                            <span></span>
                            <strong>Enabled</strong>
                        </label>
                    </div>

                    <div class="ionpc-grid-2">
                        <p>
                            <label class="ionpc-label">Eyebrow</label>
                            <input type="text" name="popup_eyebrow" value="<?php echo esc_attr($s['popup_eyebrow']); ?>" class="regular-text">
                        </p>
                        <p>
                            <label class="ionpc-label">Popup title</label>
                            <input type="text" name="popup_title" value="<?php echo esc_attr($s['popup_title']); ?>" class="large-text">
                        </p>
                        <p>
                            <label class="ionpc-label">Subtitle <small>Line breaks are supported.</small></label>
                            <textarea name="popup_subtitle" rows="3" class="large-text"><?php echo esc_textarea($s['popup_subtitle']); ?></textarea>
                        </p>
                        <p>
                            <label class="ionpc-label">Logo URL</label>
                            <input type="url" name="popup_logo_url" value="<?php echo esc_attr($s['popup_logo_url']); ?>" class="large-text">
                        </p>
                    </div>

                    <label class="ionpc-label">Popup body</label>
                    <textarea name="popup_body" rows="5" class="large-text"><?php echo esc_textarea($s['popup_body']); ?></textarea>

                    <div class="ionpc-grid-2">
                        <p>
                            <label class="ionpc-label">Highlight box <small>Leave blank to hide.</small></label>
                            <textarea name="popup_coupon" rows="3" class="large-text"><?php echo esc_textarea($s['popup_coupon']); ?></textarea>
                        </p>
                        <p>
                            <label class="ionpc-label">Display scope</label>
                            <select name="popup_scope">
                                <option value="all" <?php selected($s['popup_scope'], 'all'); ?>>All pages</option>
                                <option value="home" <?php selected($s['popup_scope'], 'home'); ?>>Homepage only</option>
                                <option value="shop" <?php selected($s['popup_scope'], 'shop'); ?>>Shop/product pages only</option>
                            </select>
                        </p>
                        <p>
                            <label class="ionpc-label">Main button text</label>
                            <input type="text" name="popup_button_text" value="<?php echo esc_attr($s['popup_button_text']); ?>" class="regular-text">
                        </p>
                        <p>
                            <label class="ionpc-label">Main button URL</label>
                            <input type="text" name="popup_button_url" value="<?php echo esc_attr($s['popup_button_url']); ?>" class="regular-text">
                        </p>
                        <p>
                            <label class="ionpc-label">Close text</label>
                            <input type="text" name="popup_secondary_text" value="<?php echo esc_attr($s['popup_secondary_text']); ?>" class="regular-text">
                        </p>
                        <p>
                            <label class="ionpc-label">Delay milliseconds</label>
                            <input type="number" name="popup_delay" min="0" max="15000" value="<?php echo esc_attr($s['popup_delay']); ?>" class="regular-text">
                        </p>
                        <p>
                            <label class="ionpc-label">Cooldown hours <small>0 means show every visit.</small></label>
                            <input type="number" name="popup_cooldown_hours" min="0" max="720" value="<?php echo esc_attr($s['popup_cooldown_hours']); ?>" class="regular-text">
                        </p>
                    </div>
                </section>

                <p class="submit ionpc-submit">
                    <button type="submit" class="button button-primary button-hero">Save Promo Settings</button>
                </p>
            </form>
        </div>
        <?php
    }

    public function frontend_assets() {
        $s = self::settings();

        wp_register_style('ion-promo-control-front', false, array(), self::VERSION);
        wp_enqueue_style('ion-promo-control-front');
        wp_add_inline_style('ion-promo-control-front', $this->frontend_css($s));

        wp_register_script('ion-promo-control-front', false, array(), self::VERSION, true);
        wp_enqueue_script('ion-promo-control-front');
        wp_add_inline_script('ion-promo-control-front', $this->frontend_js($s));
    }

    public function render_topbar() {
        if ($this->topbar_rendered) {
            return;
        }
        $s = self::settings();
        if ($s['topbar_enabled'] !== '1') {
            return;
        }
        $this->topbar_rendered = true;
        ?>
        <div id="ion-promo-topbar" class="ion-promo-topbar" role="note" aria-label="Website announcement">
            <div class="ion-promo-topbar__inner"><?php echo $this->topbar_html($s); ?></div>
        </div>
        <?php
    }

    public function render_popup() {
        $s = self::settings();
        if ($s['popup_enabled'] !== '1' && empty($_GET['ion_promo_preview'])) {
            return;
        }

        if (!$this->popup_scope_allowed($s)) {
            return;
        }

        $highlight = trim((string) $s['popup_coupon']);
        ?>
        <div id="ion-promo-popup" class="ion-promo-popup" hidden aria-hidden="true">
            <div class="ion-promo-backdrop" data-ion-promo-close></div>
            <div class="ion-promo-dialog" role="dialog" aria-modal="true" aria-labelledby="ion-promo-title">
                <button type="button" class="ion-promo-x" aria-label="Close promotion" data-ion-promo-close>&times;</button>
                <div class="ion-promo-glow"></div>

                <?php if (!empty($s['popup_logo_url'])) : ?>
                    <div class="ion-promo-logo-wrap">
                        <img class="ion-promo-logo" src="<?php echo esc_url($s['popup_logo_url']); ?>" alt="Ion Peptide">
                    </div>
                <?php endif; ?>

                <p class="ion-promo-eyebrow"><?php echo esc_html($s['popup_eyebrow']); ?></p>
                <h2 id="ion-promo-title"><?php echo esc_html($s['popup_title']); ?></h2>
                <h3><?php echo nl2br(esc_html($s['popup_subtitle'])); ?></h3>

                <div class="ion-promo-body"><?php echo wp_kses_post(wpautop($s['popup_body'])); ?></div>

                <?php if ($highlight !== '') : ?>
                    <div class="ion-promo-highlight" role="note">
                        <span><?php echo nl2br(esc_html($highlight)); ?></span>
                    </div>
                <?php endif; ?>

                <div class="ion-promo-actions">
                    <?php if (!empty($s['popup_button_text']) && !empty($s['popup_button_url'])) : ?>
                        <?php
                        $popup_button_url = (string) $s['popup_button_url'];
                        if ( str_starts_with( $popup_button_url, '/' ) ) {
                            $popup_button_url = home_url( $popup_button_url );
                        }
                        ?>
                        <a class="ion-promo-primary" href="<?php echo esc_url($popup_button_url); ?>"><?php echo esc_html($s['popup_button_text']); ?></a>
                    <?php endif; ?>
                    <button type="button" class="ion-promo-secondary" data-ion-promo-close><?php echo esc_html($s['popup_secondary_text']); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    private function popup_scope_allowed($settings) {
        if (!empty($_GET['ion_promo_preview'])) {
            return true;
        }

        if ($settings['popup_scope'] === 'home') {
            return is_front_page() || is_home();
        }

        if ($settings['popup_scope'] === 'shop') {
            return function_exists('is_woocommerce') && (is_woocommerce() || is_shop() || is_product() || is_product_category() || is_product_tag());
        }

        return true;
    }

    private function frontend_js($settings) {
        $payload = array(
            'version'       => self::VERSION,
            'popupEnabled'  => $settings['popup_enabled'] === '1' || !empty($_GET['ion_promo_preview']),
            'popupDelay'    => max(0, min(15000, absint($settings['popup_delay']))),
            'cooldownHours' => max(0, min(720, absint($settings['popup_cooldown_hours']))),
            'forcePreview'  => !empty($_GET['ion_promo_preview']),
            'topbarEnabled' => $settings['topbar_enabled'] === '1',
            'topbarHtml'    => $this->topbar_html($settings),
            'popupScopeAllowed' => $this->popup_scope_allowed($settings),
        );

        $json = wp_json_encode($payload);

        return <<<JS
(function(){
  'use strict';
  var cfg = {$json};

  function gatesAreClear(){
    var blockers = [
      '#ion-auth-overlay',
      '#sp-ruo-stage1-overlay',
      '#sp-ruo-stage2-overlay',
      '#sp-login-gate-modal',
      '.sp-login-gate-overlay',
      '.age-gate',
      '.age-gate__wrapper'
    ];

    for (var i = 0; i < blockers.length; i++) {
      var el = document.querySelector(blockers[i]);
      if (!el) continue;
      var st = window.getComputedStyle(el);
      if (st.display !== 'none' && st.visibility !== 'hidden' && parseFloat(st.opacity || '1') > 0.01) {
        return false;
      }
    }
    return true;
  }

  function syncTopbarLayout(){
    var bar = document.getElementById('ion-promo-topbar');
    if (!bar || !document.body) return;

    document.documentElement.classList.add('ion-promo-topbar-active');
    document.body.classList.add('ion-promo-topbar-active');

    var h = Math.ceil(bar.getBoundingClientRect().height || 0);
    if (h < 1) h = 44;

    var header = document.querySelector('header.elementor-element.elementor-element-3ff1e64d, #masthead header.elementor-element.elementor-element-3ff1e64d, .elementor-element.elementor-element-3ff1e64d[data-settings*="fixed"]');
    var hh = header ? Math.ceil(header.getBoundingClientRect().height || 0) : 82;
    if (hh < 1) hh = 82;

    document.documentElement.style.setProperty('--ionpc-bar-real-height', h + 'px');
    document.documentElement.style.setProperty('--ionpc-mobile-header-height', hh + 'px');
    document.body.style.setProperty('--ionpc-bar-real-height', h + 'px');
    document.body.style.setProperty('--ionpc-mobile-header-height', hh + 'px');
  }

  function ensureTopbar(){
    if (!cfg.topbarEnabled) return;
    var existing = document.getElementById('ion-promo-topbar');
    if (existing) {
      if (document.body && existing.parentNode !== document.body) {
        document.body.insertBefore(existing, document.body.firstChild);
      } else if (document.body && document.body.firstChild !== existing) {
        document.body.insertBefore(existing, document.body.firstChild);
      }
      syncTopbarLayout();
      return;
    }
    if (!document.body) return;
    var bar = document.createElement('div');
    bar.id = 'ion-promo-topbar';
    bar.className = 'ion-promo-topbar';
    bar.setAttribute('role', 'note');
    bar.setAttribute('aria-label', 'Website announcement');
    var inner = document.createElement('div');
    inner.className = 'ion-promo-topbar__inner';
    inner.innerHTML = cfg.topbarHtml || '';
    bar.appendChild(inner);
    document.body.insertBefore(bar, document.body.firstChild);
    syncTopbarLayout();
  }

  function isMobileViewport(){
    return !!(window.matchMedia && window.matchMedia('(max-width: 767px)').matches);
  }

  function setMobileMenuMode(isOpen){
    if (!document.documentElement || !document.body) return;

    if (isOpen && isMobileViewport()) {
      document.documentElement.classList.add('ionpc-mobile-menu-open');
      document.body.classList.add('ionpc-mobile-menu-open');
      return;
    }

    document.documentElement.classList.remove('ionpc-mobile-menu-open');
    document.body.classList.remove('ionpc-mobile-menu-open');
    window.setTimeout(syncTopbarLayout, 60);
    window.setTimeout(syncTopbarLayout, 300);
  }

  function targetMatches(selector, target){
    return !!(target && target.closest && target.closest(selector));
  }

  function bindMobileMenuWatcher(){
    document.addEventListener('click', function(e){
      if (!isMobileViewport()) return;
      var target = e.target;

      var openTrigger = targetMatches('.hfe-flyout-trigger, .hfe-nav-menu__toggle, button.elementskit-menu-hamburger, .elementskit-menu-toggler', target);
      var closeTrigger = targetMatches('.hfe-flyout-close, .hfe-flyout-overlay, .hfe-flyout-content a, .elementskit-menu-close, .elementskit-menu-overlay, .elementskit-menu-container a', target);

      if (openTrigger && !closeTrigger) {
        setMobileMenuMode(true);
        window.setTimeout(function(){ setMobileMenuMode(true); }, 80);
        return;
      }

      if (closeTrigger) {
        window.setTimeout(function(){ setMobileMenuMode(false); }, 220);
      }
    }, true);

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') {
        setMobileMenuMode(false);
      }
    });
  }

  bindMobileMenuWatcher();

  window.addEventListener('resize', function(){
    if (!isMobileViewport()) {
      setMobileMenuMode(false);
    }
    syncTopbarLayout();
  });
  window.addEventListener('orientationchange', function(){
    setMobileMenuMode(false);
    window.setTimeout(syncTopbarLayout, 250);
  });
  window.addEventListener('load', function(){
    syncTopbarLayout();
    window.setTimeout(syncTopbarLayout, 250);
    window.setTimeout(syncTopbarLayout, 1000);
  });

  function bootPopup(){
    ensureTopbar();
    if (!cfg.popupEnabled || !cfg.popupScopeAllowed) return;

    var popup = document.getElementById('ion-promo-popup');
    if (!popup) return;

    var key = 'ion_promo_control_seen_' + cfg.version;
    var now = Date.now();
    var cooldown = (parseInt(cfg.cooldownHours, 10) || 0) * 60 * 60 * 1000;

    if (!cfg.forcePreview && cooldown > 0) {
      try {
        var last = parseInt(localStorage.getItem(key) || '0', 10);
        if (last && now - last < cooldown) return;
      } catch(e) {}
    }

    function open(){
      popup.hidden = false;
      popup.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('ion-promo-open');
      try { if (!cfg.forcePreview) localStorage.setItem(key, String(Date.now())); } catch(e) {}
    }

    function close(){
      popup.hidden = true;
      popup.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('ion-promo-open');
    }

    popup.querySelectorAll('[data-ion-promo-close]').forEach(function(el){
      el.addEventListener('click', close);
    });

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && !popup.hidden) close();
    });

    var tries = 0;
    function waitAndOpen(){
      tries++;
      if (gatesAreClear() || tries > 80 || cfg.forcePreview) {
        window.setTimeout(open, parseInt(cfg.popupDelay, 10) || 0);
        return;
      }
      window.setTimeout(waitAndOpen, 250);
    }

    waitAndOpen();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootPopup);
  } else {
    bootPopup();
  }
})();
JS;
    }

    private function frontend_css($settings) {
        $topbar_bg = sanitize_hex_color($settings['topbar_bg']) ?: '#0D0D67';
        $topbar_color = sanitize_hex_color($settings['topbar_text_color']) ?: '#ffffff';
        $accent_color = sanitize_hex_color($settings['topbar_accent_color']) ?: '#527DF4';

        return <<<CSS
:root {
  --ionpc-bar-height: 44px;
  --ionpc-bar-real-height: 44px;
  --ionpc-mobile-header-height: 82px;
}
#ion-promo-topbar,
#ion-promo-topbar * {
  box-sizing: border-box;
}
#ion-promo-topbar {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 2147483200;
  min-height: var(--ionpc-bar-height);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 8px 14px;
  background:
    radial-gradient(520px 140px at 12% 0%, rgba(82,125,244,.34), transparent 68%),
    linear-gradient(90deg, {$topbar_bg}, #02106d 58%, #007eff) !important;
  color: {$topbar_color} !important;
  border-bottom: 2px solid {$accent_color};
  box-shadow: 0 8px 24px rgba(0,0,0,.16);
  font-family: Poppins, Inter, system-ui, -apple-system, "Segoe UI", sans-serif;
  text-align: center;
}
#ion-promo-topbar .ion-promo-topbar__inner {
  width: min(100%, 1180px);
  margin: 0 auto;
  color: {$topbar_color} !important;
  font-size: 14px;
  line-height: 1.35;
  font-weight: 800;
  letter-spacing: .01em;
}
#ion-promo-topbar strong,
#ion-promo-topbar b {
  color: #ffffff !important;
  font-weight: 900 !important;
}
#ion-promo-topbar a {
  color: #ffffff !important;
  font-weight: 900 !important;
  text-decoration: underline !important;
  text-underline-offset: 2px !important;
}
body:has(#ion-promo-topbar).admin-bar #ion-promo-topbar {
  top: 32px;
}
@media (max-width: 767px) {
  html.ion-promo-topbar-active,
  html.ion-promo-topbar-active body.ion-promo-topbar-active {
    padding-top: 0 !important;
    scroll-padding-top: calc(var(--ionpc-bar-real-height) + var(--ionpc-mobile-header-height)) !important;
  }

  body.ion-promo-topbar-active #ion-promo-topbar {
    top: 0 !important;
    position: fixed !important;
    z-index: 2147483300 !important;
  }

  body.ion-promo-topbar-active.admin-bar #ion-promo-topbar {
    top: 46px !important;
  }

  body.ion-promo-topbar-active header.elementor-element.elementor-element-3ff1e64d,
  body.ion-promo-topbar-active #masthead header.elementor-element.elementor-element-3ff1e64d,
  body.ion-promo-topbar-active .elementor-element.elementor-element-3ff1e64d[data-settings*="fixed"] {
    top: var(--ionpc-bar-real-height) !important;
    z-index: 2147482500 !important;
  }

  body.ion-promo-topbar-active.admin-bar header.elementor-element.elementor-element-3ff1e64d,
  body.ion-promo-topbar-active.admin-bar #masthead header.elementor-element.elementor-element-3ff1e64d,
  body.ion-promo-topbar-active.admin-bar .elementor-element.elementor-element-3ff1e64d[data-settings*="fixed"] {
    top: calc(var(--ionpc-bar-real-height) + 46px) !important;
  }

  body.ion-promo-topbar-active #page {
    padding-top: calc(var(--ionpc-bar-real-height) + var(--ionpc-mobile-header-height)) !important;
  }

  body.ion-promo-topbar-active.woocommerce-shop #page,
  body.ion-promo-topbar-active.woocommerce-page #page {
    padding-top: calc(var(--ionpc-bar-real-height) + var(--ionpc-mobile-header-height)) !important;
  }

  body.ion-promo-topbar-active .hfe-flyout-wrapper,
  body.ion-promo-topbar-active .hfe-flyout-container,
  body.ion-promo-topbar-active .elementskit-menu-overlay,
  body.ion-promo-topbar-active .elementskit-menu-container {
    top: var(--ionpc-bar-real-height) !important;
    z-index: 2147482600 !important;
  }

  body.ion-promo-topbar-active .hfe-flyout-content,
  body.ion-promo-topbar-active .hfe-side {
    padding-top: 0 !important;
  }

  html.ionpc-mobile-menu-open #ion-promo-topbar,
  body.ionpc-mobile-menu-open #ion-promo-topbar {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
    height: 0 !important;
    min-height: 0 !important;
    max-height: 0 !important;
    overflow: hidden !important;
  }

  html.ionpc-mobile-menu-open body.ion-promo-topbar-active .hfe-flyout-wrapper,
  html.ionpc-mobile-menu-open body.ion-promo-topbar-active .hfe-flyout-container,
  html.ionpc-mobile-menu-open body.ion-promo-topbar-active .hfe-flyout-overlay,
  html.ionpc-mobile-menu-open body.ion-promo-topbar-active .hfe-side,
  html.ionpc-mobile-menu-open body.ion-promo-topbar-active .hfe-flyout-content,
  html.ionpc-mobile-menu-open body.ion-promo-topbar-active .elementskit-menu-overlay,
  html.ionpc-mobile-menu-open body.ion-promo-topbar-active .elementskit-menu-container {
    top: 0 !important;
    z-index: 2147483647 !important;
  }
}
html.ion-promo-open,
html.ion-promo-open body {
  overflow: hidden !important;
}
.ion-promo-popup[hidden] { display:none !important; }
.ion-promo-popup, .ion-promo-popup * { box-sizing:border-box; }
.ion-promo-popup {
  position:fixed;
  inset:0;
  z-index:2147483600;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:22px;
  font-family:Poppins, Inter, system-ui, -apple-system, "Segoe UI", sans-serif;
}
.ion-promo-backdrop {
  position:absolute;
  inset:0;
  background:rgba(11,20,64,.68);
  -webkit-backdrop-filter:blur(12px);
  backdrop-filter:blur(12px);
}
.ion-promo-dialog {
  position:relative;
  width:min(100%, 540px);
  overflow:hidden;
  border:1px solid rgba(82,125,244,.32);
  border-radius:28px;
  background:
    radial-gradient(360px 220px at 10% 0%, rgba(0,126,255,.22), transparent 70%),
    radial-gradient(320px 190px at 90% 8%, rgba(82,125,244,.18), transparent 68%),
    linear-gradient(180deg,#ffffff 0%,#f4f8ff 100%);
  color:#0B1440;
  box-shadow:0 34px 90px rgba(0,0,0,.34);
  padding:30px 28px 26px;
  text-align:center;
  animation:ionPromoIn .28s ease-out both;
}
@keyframes ionPromoIn {
  from { opacity:0; transform:translateY(16px) scale(.98); }
  to { opacity:1; transform:translateY(0) scale(1); }
}
.ion-promo-glow {
  position:absolute;
  left:-90px;
  top:-90px;
  width:230px;
  height:230px;
  border-radius:999px;
  background:rgba(0,126,255,.16);
  filter:blur(8px);
  pointer-events:none;
}
.ion-promo-x {
  position:absolute;
  top:14px;
  right:14px;
  width:38px;
  height:38px;
  border:1px solid rgba(13,13,103,.10) !important;
  border-radius:999px !important;
  background:#ffffff !important;
  color:#0D0D67 !important;
  font-size:28px !important;
  line-height:34px !important;
  padding:0 !important;
  cursor:pointer;
  box-shadow:0 4px 12px rgba(0,0,0,.08) !important;
}
.ion-promo-logo-wrap {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:96px;
  min-height:66px;
  margin:0 auto 14px;
  padding:10px 14px;
  border-radius:18px;
  background:#ffffff;
  border:1px solid rgba(13,13,103,.08);
  box-shadow:0 12px 26px rgba(13,13,103,.10);
}
.ion-promo-logo {
  display:block;
  max-width:76px;
  max-height:48px;
  width:auto;
  height:auto;
  object-fit:contain;
}
.ion-promo-eyebrow {
  margin:2px 0 10px;
  color:#007EFF;
  font-size:12px;
  line-height:1;
  font-weight:900;
  letter-spacing:.16em;
  text-transform:uppercase;
}
.ion-promo-dialog h2 {
  margin:0 0 9px !important;
  color:#0D0D67 !important;
  font-size:clamp(32px, 5vw, 48px) !important;
  line-height:.98 !important;
  font-weight:900 !important;
  letter-spacing:-.055em !important;
}
.ion-promo-dialog h3 {
  margin:0 0 15px !important;
  color:#1d2b6f !important;
  font-size:18px !important;
  line-height:1.32 !important;
  font-weight:800 !important;
}
.ion-promo-body {
  width:min(100%, 440px);
  margin:0 auto 18px;
  color:#334155;
  font-size:15px;
  line-height:1.62;
  font-weight:600;
}
.ion-promo-body p { margin:0 0 10px; }
.ion-promo-body p:last-child { margin-bottom:0; }
.ion-promo-highlight {
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  width:min(100%, 430px);
  min-height:64px !important;
  margin:0 auto 20px !important;
  padding:12px 18px !important;
  border:1px solid rgba(82,125,244,.22) !important;
  border-radius:16px !important;
  background:linear-gradient(135deg, rgba(0,126,255,.10), rgba(13,13,103,.06)) !important;
  color:#0D0D67 !important;
  box-shadow:0 12px 24px rgba(13,13,103,.08) !important;
}
.ion-promo-highlight span {
  display:block;
  font-size:15px;
  line-height:1.4;
  font-weight:900;
}
.ion-promo-actions {
  display:flex;
  align-items:center;
  justify-content:center;
  gap:12px;
  flex-wrap:wrap;
}
.ion-promo-primary,
.ion-promo-primary:visited {
  display:inline-flex !important;
  align-items:center;
  justify-content:center;
  min-height:50px;
  padding:14px 24px;
  border-radius:999px;
  background:linear-gradient(90deg,#0D0D67,#007EFF) !important;
  color:#ffffff !important;
  font-size:15px;
  line-height:1;
  font-weight:900;
  text-decoration:none !important;
  box-shadow:0 16px 34px rgba(0,126,255,.22);
}
.ion-promo-primary:hover,
.ion-promo-primary:focus { color:#ffffff !important; transform:translateY(-1px); }
.ion-promo-secondary {
  min-height:48px !important;
  padding:13px 18px !important;
  border:0 !important;
  border-radius:999px !important;
  background:transparent !important;
  color:#475569 !important;
  font-size:14px !important;
  font-weight:900 !important;
  box-shadow:none !important;
  cursor:pointer;
}

@media (max-width:782px) {
  body:has(#ion-promo-topbar).admin-bar #ion-promo-topbar { top: 46px; }
}
@media (max-width:600px) {
  :root { --ionpc-bar-height: 50px; }
  #ion-promo-topbar .ion-promo-topbar__inner { font-size: 12px; line-height: 1.3; }
  .ion-promo-popup { padding:14px; align-items:center; }
  .ion-promo-dialog { border-radius:22px; padding:26px 18px 22px; }
  .ion-promo-actions { display:grid; grid-template-columns:1fr; }
  .ion-promo-primary, .ion-promo-secondary { width:100%; }
}
CSS;
    }

    private function admin_css() {
        return <<<CSS
.ionpc-admin { max-width:1120px; }
.ionpc-hero {
  margin:22px 0 18px;
  padding:26px 28px;
  border-radius:22px;
  background:
    radial-gradient(440px 180px at 12% 0%, rgba(82,125,244,.32), transparent 70%),
    linear-gradient(135deg,#0B1440,#0D0D67 58%,#007EFF);
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:20px;
  box-shadow:0 18px 42px rgba(13,13,103,.22);
}
.ionpc-kicker { margin:0 0 5px; color:#ffffff; font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.18em; opacity:.85; }
.ionpc-hero h1 { margin:0 0 6px; color:#fff; font-size:36px; line-height:1; font-weight:900; letter-spacing:-.04em; }
.ionpc-hero p { margin:0; max-width:640px; color:rgba(255,255,255,.88); font-size:15px; }
.ionpc-preview { display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:0 18px; border-radius:999px; background:#fff; color:#0D0D67; text-decoration:none; font-weight:900; white-space:nowrap; }
.ionpc-preview:hover { color:#02106d; background:#eef5ff; }
.ionpc-card { margin:0 0 18px; padding:24px; border:1px solid #dde6f4; border-radius:18px; background:#fff; box-shadow:0 12px 30px rgba(13,13,103,.06); }
.ionpc-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:24px; margin-bottom:18px; padding-bottom:16px; border-bottom:1px solid #edf3ff; }
.ionpc-card h2 { margin:0 0 5px; color:#0D0D67; font-size:22px; font-weight:900; letter-spacing:-.025em; }
.ionpc-card p { color:#475569; }
.ionpc-card-head p { margin:0; }
.ionpc-label { display:block; margin:0 0 7px; color:#0D0D67; font-weight:900; }
.ionpc-label small { display:block; margin-top:2px; color:#64748b; font-weight:600; }
.ionpc-grid-2 { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px 22px; }
.ionpc-grid-3 { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px 22px; margin-top:16px; }
.ionpc-grid-2 p, .ionpc-grid-3 p { margin:0; }
.ionpc-admin input[type=text], .ionpc-admin input[type=url], .ionpc-admin input[type=number], .ionpc-admin select, .ionpc-admin textarea { border-color:#dbe6f4; border-radius:10px; min-height:42px; }
.ionpc-admin textarea { padding:12px; font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace; }
.ionpc-switch { display:flex; align-items:center; gap:10px; color:#0D0D67; font-weight:900; }
.ionpc-switch input { display:none; }
.ionpc-switch span { position:relative; width:54px; height:30px; border-radius:999px; background:#cbd5e1; transition:.18s ease; box-shadow:inset 0 0 0 1px rgba(0,0,0,.06); }
.ionpc-switch span:after { content:""; position:absolute; top:4px; left:4px; width:22px; height:22px; border-radius:999px; background:#fff; transition:.18s ease; box-shadow:0 2px 7px rgba(0,0,0,.18); }
.ionpc-switch input:checked + span { background:#007EFF; }
.ionpc-switch input:checked + span:after { transform:translateX(24px); }
.ionpc-submit { margin-top:18px !important; }
@media (max-width:780px) { .ionpc-hero, .ionpc-card-head { display:block; } .ionpc-preview { margin-top:16px; } .ionpc-grid-2, .ionpc-grid-3 { grid-template-columns:1fr; } .ionpc-switch { margin-top:14px; } }
CSS;
    }
}

new ION_Promo_Control();
