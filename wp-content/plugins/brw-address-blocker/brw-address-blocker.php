<?php
/**
 * Plugin Name: BRW Address Blocker (WooCommerce)
 * Description: Admin-managed blacklist to block WooCommerce checkout by billing/shipping address.
 * Version: 1.0.0
 * Author: BuildRightWeb
 */

if (!defined('ABSPATH')) exit;

class CP_Address_Blocker_WC {
  const OPT_KEY = 'cpab_settings';

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);
    add_action('woocommerce_after_checkout_validation', [__CLASS__, 'block_on_checkout'], 10, 2);
  }

  public static function defaults(): array {
    return [
      'enabled'         => 1,
      'match_mode'      => 'exact', // exact | contains
      'check_billing'   => 1,
      'check_shipping'  => 1,
      'blocked_lines'   => "",
      'blocked_emails'  => "",
      'deny_message'    => "We are unable to process this order.",
    ];
  }

  public static function get_settings(): array {
    $saved = get_option(self::OPT_KEY, []);
    if (!is_array($saved)) $saved = [];
    return array_merge(self::defaults(), $saved);
  }

  public static function admin_menu() {
    add_options_page(
      'Address Blocker (WooCommerce)',
      'Address Blocker',
      'manage_options',
      'brw-address-blocker',
      [__CLASS__, 'render_settings_page']
    );
  }

  public static function register_settings() {
    register_setting(self::OPT_KEY, self::OPT_KEY, [__CLASS__, 'sanitize_settings']);
  }

  public static function sanitize_settings($input) {
    $out = self::defaults();
    $out['enabled']        = !empty($input['enabled']) ? 1 : 0;
    $out['match_mode']     = in_array($input['match_mode'] ?? 'exact', ['exact','contains'], true) ? $input['match_mode'] : 'exact';
    $out['check_billing']  = !empty($input['check_billing']) ? 1 : 0;
    $out['check_shipping'] = !empty($input['check_shipping']) ? 1 : 0;
    $out['blocked_lines']  = wp_kses_post($input['blocked_lines'] ?? '');
    $out['blocked_emails'] = wp_kses_post($input['blocked_emails'] ?? '');
    $out['deny_message']   = sanitize_text_field($input['deny_message'] ?? $out['deny_message']);
    return $out;
  }

  public static function render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = self::get_settings();
    ?>
    <div class="wrap">
      <h1>Address Blocker (WooCommerce)</h1>
      <form method="post" action="options.php">
        <?php settings_fields(self::OPT_KEY); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Enable Blocking</th>
            <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[enabled]" value="1" <?php checked($s['enabled'],1); ?>> Enable</label></td>
          </tr>
          <tr>
            <th scope="row">Match Mode</th>
            <td>
              <select name="<?php echo esc_attr(self::OPT_KEY); ?>[match_mode]">
                <option value="exact" <?php selected($s['match_mode'],'exact'); ?>>Exact (Address|City|State|ZIP)</option>
                <option value="contains" <?php selected($s['match_mode'],'contains'); ?>>Contains (partial match)</option>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row">Check Billing</th>
            <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[check_billing]" value="1" <?php checked($s['check_billing'],1); ?>> Yes</label></td>
          </tr>
          <tr>
            <th scope="row">Check Shipping</th>
            <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[check_shipping]" value="1" <?php checked($s['check_shipping'],1); ?>> Yes</label></td>
          </tr>
          <tr>
            <th scope="row">Blocked Addresses</th>
            <td>
              <textarea name="<?php echo esc_attr(self::OPT_KEY); ?>[blocked_lines]" rows="10" cols="60" class="large-text code"><?php echo esc_textarea($s['blocked_lines']); ?></textarea>
              <p class="description">One per line. For exact mode use: <code>address line 1|city|state|zip</code> (or <code>address line 1|address line 2|city|state|zip</code>). Matching is case-insensitive.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Blocked Emails</th>
            <td>
              <textarea name="<?php echo esc_attr(self::OPT_KEY); ?>[blocked_emails]" rows="6" cols="60" class="large-text code"><?php echo esc_textarea($s['blocked_emails']); ?></textarea>
              <p class="description">One per line. Exact match only (case-insensitive). Example: <code>badguy@example.com</code></p>
            </td>
          </tr>
          <tr>
            <th scope="row">Deny Message</th>
            <td><input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[deny_message]" value="<?php echo esc_attr($s['deny_message']); ?>" class="regular-text"></td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  private static function normalize_text($v): string {
    $v = is_string($v) ? strtolower($v) : '';
    $v = preg_replace('/[^a-z0-9\s]/', '', $v);
    $v = trim(preg_replace('/\s+/', ' ', $v));
    return $v;
  }

  private static function normalize_state($v): string {
    $v = self::normalize_text($v);
    $v = preg_replace('/[^a-z]/', '', $v);
    return $v;
  }

  private static function normalize_zip($v): string {
    $v = is_string($v) ? $v : '';
    $digits = preg_replace('/\D+/', '', $v);
    if (strlen($digits) >= 5) return substr($digits, 0, 5);
    return $digits;
  }

  private static function normalize_email($v): string {
    $v = is_string($v) ? strtolower(trim($v)) : '';
    return $v;
  }

  private static function parse_blocked_address_line(string $line): array {
    $parts = array_map('trim', explode('|', $line));
    $parts = array_values(array_filter($parts, static function($p){ return $p !== ''; }));

    $out = [
      'raw'   => $line,
      'parts' => count($parts),
      'addr1' => '',
      'addr2' => '',
      'city'  => '',
      'state' => '',
      'zip'   => '',
      'canon4'=> '',
      'canon5'=> '',
      'needle'=> '',
    ];

    if (count($parts) === 4) {
      [$a1,$city,$state,$zip] = $parts;
      $out['addr1'] = self::normalize_text($a1);
      $out['city']  = self::normalize_text($city);
      $out['state'] = self::normalize_state($state);
      $out['zip']   = self::normalize_zip($zip);
    } elseif (count($parts) >= 5) {
      $a1 = array_shift($parts);
      $a2 = array_shift($parts);
      $city = array_shift($parts);
      $state = array_shift($parts);
      $zip = array_shift($parts);
      if (!empty($parts)) {
        $a2 .= ' ' . implode(' ', $parts);
      }
      $out['addr1'] = self::normalize_text($a1);
      $out['addr2'] = self::normalize_text($a2);
      $out['city']  = self::normalize_text($city);
      $out['state'] = self::normalize_state($state);
      $out['zip']   = self::normalize_zip($zip);
    }

    if ($out['addr1'] !== '' && $out['city'] !== '' && $out['state'] !== '' && $out['zip'] !== '') {
      $out['canon4'] = $out['addr1'] . '|' . $out['city'] . '|' . $out['state'] . '|' . $out['zip'];
      $out['canon5'] = $out['addr1'] . '|' . $out['addr2'] . '|' . $out['city'] . '|' . $out['state'] . '|' . $out['zip'];
    }

    $out['needle'] = self::normalize_text($line);
    return $out;
  }

  public static function block_on_checkout($data, $errors) {
    if (!class_exists('WooCommerce') || !is_object($errors)) return;

    $s = self::get_settings();
    if (empty($s['enabled'])) return;

    $lines  = array_filter(array_map('trim', explode("\n", (string) $s['blocked_lines'])));
    $emails = array_filter(array_map('trim', explode("\n", (string) $s['blocked_emails'])));

    // Email blocking (exact, case-insensitive)
    if (!empty($emails)) {
      $bill_email = self::normalize_email($data['billing_email'] ?? '');
      if ($bill_email !== '') {
        foreach ($emails as $e) {
          $needle_email = self::normalize_email($e);
          if ($needle_email !== '' && $bill_email === $needle_email) {
            $errors->add('cpab_blocked', esc_html($s['deny_message']));
            return;
          }
        }
      }
    }

    if (empty($lines)) return;

    $targets = [];

    if (!empty($s['check_billing'])) {
      $b_a1    = self::normalize_text($data['billing_address_1'] ?? '');
      $b_a2    = self::normalize_text($data['billing_address_2'] ?? '');
      $b_city  = self::normalize_text($data['billing_city'] ?? '');
      $b_state = self::normalize_state($data['billing_state'] ?? '');
      $b_zip   = self::normalize_zip($data['billing_postcode'] ?? '');

      $targets[] = [
        'canon4' => $b_a1 . '|' . $b_city . '|' . $b_state . '|' . $b_zip,
        'canon5' => $b_a1 . '|' . $b_a2 . '|' . $b_city . '|' . $b_state . '|' . $b_zip,
        'blob'   => self::normalize_text(($data['billing_address_1'] ?? '') . ' ' . ($data['billing_address_2'] ?? '') . '|' . ($data['billing_city'] ?? '') . '|' . ($data['billing_state'] ?? '') . '|' . ($data['billing_postcode'] ?? '')),
      ];
    }

    if (!empty($s['check_shipping'])) {
      $s_a1    = self::normalize_text($data['shipping_address_1'] ?? '');
      $s_a2    = self::normalize_text($data['shipping_address_2'] ?? '');
      $s_city  = self::normalize_text($data['shipping_city'] ?? '');
      $s_state = self::normalize_state($data['shipping_state'] ?? '');
      $s_zip   = self::normalize_zip($data['shipping_postcode'] ?? '');

      $targets[] = [
        'canon4' => $s_a1 . '|' . $s_city . '|' . $s_state . '|' . $s_zip,
        'canon5' => $s_a1 . '|' . $s_a2 . '|' . $s_city . '|' . $s_state . '|' . $s_zip,
        'blob'   => self::normalize_text(($data['shipping_address_1'] ?? '') . ' ' . ($data['shipping_address_2'] ?? '') . '|' . ($data['shipping_city'] ?? '') . '|' . ($data['shipping_state'] ?? '') . '|' . ($data['shipping_postcode'] ?? '')),
      ];
    }

    foreach ($lines as $line) {
      $p = self::parse_blocked_address_line($line);
      foreach ($targets as $t) {
        if ($s['match_mode'] === 'exact') {
          if ($p['parts'] === 5 && $p['canon5'] !== '' && $t['canon5'] === $p['canon5']) {
            $errors->add('cpab_blocked', esc_html($s['deny_message']));
            return;
          }
          if ($p['parts'] === 4 && $p['canon4'] !== '' && $t['canon4'] === $p['canon4']) {
            $errors->add('cpab_blocked', esc_html($s['deny_message']));
            return;
          }
        } else {
          if ($p['needle'] !== '' && strpos($t['blob'], $p['needle']) !== false) {
            $errors->add('cpab_blocked', esc_html($s['deny_message']));
            return;
          }
        }
      }
    }
  }
}

CP_Address_Blocker_WC::init();
