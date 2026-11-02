<?php
/**
 * IonPeptide Login Gate - Registration Blocks
 * Only new accounts are restricted; existing accounts are untouched.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class IonLG_Registration_Blocks {
    const OPTION = 'ionrb_blocked_email_rules';
    const ERROR  = 'Registration is not available for this email address. Please contact support if you believe this is a mistake.';

    public static function init() {
        // Native WordPress registration.
        add_filter( 'registration_errors', array( __CLASS__, 'wp_registration_errors' ), 10, 3 );

        // WooCommerce classic account registration and Store API / Blocks checkout.
        add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'wc_registration_errors' ), 10, 3 );
        add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'wc_process_registration_errors' ), 10, 4 );

        // Existing Profile Builder signup form, if it is still enabled.
        add_filter( 'wppb_check_form_field_default-e-mail', array( __CLASS__, 'profile_builder_email_validation' ), 20, 4 );

        // Multisite signups, if applicable.
        add_filter( 'wpmu_validate_user_signup', array( __CLASS__, 'multisite_signup_errors' ), 10, 1 );

        add_action( 'admin_post_ionrb_add', array( __CLASS__, 'save_new_entries' ) );
        add_action( 'admin_post_ionrb_remove', array( __CLASS__, 'remove_entry' ) );
        add_action( 'wp_ajax_ionrb_check', array( __CLASS__, 'ajax_check_email' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
    }

    public static function activate() {
        if ( false !== get_option( self::OPTION, false ) ) {
            return; // Keep client edits on plugin upgrades / reactivation.
        }
        $initial = array(
            '.gov'          => array( 'group' => 'government', 'label' => 'US federal + state' ),
            '.mil'          => array( 'group' => 'government', 'label' => 'US military' ),
            '.gov.uk'       => array( 'group' => 'government', 'label' => 'UK government' ),
            '.gov.au'       => array( 'group' => 'government', 'label' => 'Australian government' ),
            '.gc.ca'        => array( 'group' => 'government', 'label' => 'Government of Canada' ),
            '.gouv.fr'      => array( 'group' => 'government', 'label' => 'French government' ),
            '.go.jp'        => array( 'group' => 'government', 'label' => 'Japanese government' ),
            '.int'          => array( 'group' => 'government', 'label' => 'Treaty / intergovernmental organizations' ),
            'europa.eu'     => array( 'group' => 'government', 'label' => 'EU institutions (EMA, EC)' ),
            'canada.ca'     => array( 'group' => 'government', 'label' => 'Government of Canada portal' ),
            'nhs.uk'        => array( 'group' => 'government', 'label' => 'UK NHS' ),
            'lilly.com'     => array( 'group' => 'pharma', 'label' => 'Eli Lilly' ),
            'elililly.com'  => array( 'group' => 'pharma', 'label' => 'Eli Lilly' ),
            'novonordisk.com' => array( 'group' => 'pharma', 'label' => 'Novo Nordisk' ),
            'novonordisk.us' => array( 'group' => 'pharma', 'label' => 'Novo Nordisk' ),
            'pfizer.com'    => array( 'group' => 'pharma', 'label' => 'Pfizer' ),
            'merck.com'     => array( 'group' => 'pharma', 'label' => 'Merck' ),
            'msd.com'       => array( 'group' => 'pharma', 'label' => 'MSD (Merck ex-US)' ),
            'novartis.com'  => array( 'group' => 'pharma', 'label' => 'Novartis' ),
            'roche.com'     => array( 'group' => 'pharma', 'label' => 'Roche' ),
            'gene.com'      => array( 'group' => 'pharma', 'label' => 'Genentech' ),
            'brandshield.com' => array( 'group' => 'other', 'label' => 'BrandShield' ),
        );
        add_option( self::OPTION, $initial, '', false );
    }

    public static function rules() {
        $rules = get_option( self::OPTION, array() );
        return is_array( $rules ) ? $rules : array();
    }

    private static function normalize_rule( $raw ) {
        if ( ! is_string( $raw ) ) {
            return '';
        }
        $value = strtolower( trim( $raw ) );
        if ( strlen( $value ) > 253 || strlen( $value ) < 3 ) {
            return '';
        }
        $suffix = '.' === substr( $value, 0, 1 );
        $domain = $suffix ? substr( $value, 1 ) : $value;
        if ( ! preg_match( '/^[a-z0-9.-]+$/D', $domain ) || false === strpos( $domain, '.' ) && ! $suffix ) {
            return '';
        }
        // Domain labels cannot be empty, >63 chars, or begin/end with hyphens.
        foreach ( explode( '.', $domain ) as $label ) {
            if ( '' === $label || strlen( $label ) > 63 || '-' === $label[0] || '-' === substr( $label, -1 ) ) {
                return '';
            }
        }
        return $value;
    }

    public static function email_domain( $email ) {
        if ( ! is_string( $email ) || ! is_email( $email ) ) {
            return '';
        }
        $at = strrpos( $email, '@' );
        return false === $at ? '' : strtolower( substr( $email, $at + 1 ) );
    }

    private static function ends_with( $string, $suffix ) {
        return strlen( $string ) >= strlen( $suffix ) && substr( $string, -strlen( $suffix ) ) === $suffix;
    }

    public static function matched_rule( $email ) {
        $domain = self::email_domain( $email );
        if ( '' === $domain ) {
            return '';
        }
        foreach ( array_keys( self::rules() ) as $rule ) {
            if ( ! is_string( $rule ) || '' === $rule ) {
                continue;
            }
            // A leading dot represents a public suffix: e.g. .gov also blocks agency.gov.
            if ( '.' === $rule[0] ) {
                $bare_suffix = substr( $rule, 1 );
                if ( $domain === $bare_suffix || self::ends_with( $domain, $rule ) ) {
                    return $rule;
                }
            } elseif ( $domain === $rule || self::ends_with( $domain, '.' . $rule ) ) {
                // Exact domain also covers subdomains, but not lookalikes.
                return $rule;
            }
        }
        return '';
    }

    public static function wp_registration_errors( $errors, $login, $email ) {
        if ( is_wp_error( $errors ) && self::matched_rule( $email ) ) {
            $errors->add( 'ionrb_blocked_domain', self::ERROR );
        }
        return $errors;
    }

    public static function wc_registration_errors( $errors, $login, $email ) {
        if ( is_wp_error( $errors ) && self::matched_rule( $email ) ) {
            $errors->add( 'ionrb_blocked_domain', self::ERROR );
        }
        return $errors;
    }

    public static function wc_process_registration_errors( $errors, $username, $password, $email ) {
        if ( is_wp_error( $errors ) && self::matched_rule( $email ) ) {
            $errors->add( 'ionrb_blocked_domain', self::ERROR );
        }
        return $errors;
    }

    public static function profile_builder_email_validation( $message, $field, $request_data, $form_location ) {
        if ( 'register' !== $form_location || ! is_array( $request_data ) ) {
            return $message;
        }
        foreach ( array( 'email', 'e-mail', 'user_email' ) as $key ) {
            if ( isset( $request_data[ $key ] ) && is_string( $request_data[ $key ] ) && self::matched_rule( $request_data[ $key ] ) ) {
                return self::ERROR;
            }
        }
        return $message;
    }

    public static function multisite_signup_errors( $result ) {
        if ( is_array( $result ) && isset( $result['user_email'], $result['errors'] ) && is_wp_error( $result['errors'] ) && self::matched_rule( $result['user_email'] ) ) {
            $result['errors']->add( 'user_email', self::ERROR );
        }
        return $result;
    }

    private static function authorized() {
        return current_user_can( 'manage_options' );
    }

    public static function enqueue_admin( $hook ) {
        if ( ! isset( $_GET['page'], $_GET['tab'] ) ||
             'ionpeptide-login-gate' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ||
             'registration-blocks' !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
            return;
        }
        // Styles and scripts are limited to the Registration Blocks settings tab.
        wp_register_style( 'ionlg-blocks-admin', false, array(), '1.1.0' );
        wp_enqueue_style( 'ionlg-blocks-admin' );
        wp_add_inline_style( 'ionlg-blocks-admin', <<<'IONRB_CSS'
.ionrb-wrap{max-width:1060px}
.ionrb-container{margin-top:20px}
.ionrb-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:22px}
.ionrb-header h2{margin:0 0 8px;font-size:20px}
.ionrb-header p{margin:0;max-width:750px}
.ionrb-count{white-space:nowrap;font-size:13px;color:#646970}
.ionrb-add,.ionrb-test,.ionrb-group{background:#fff;border:1px solid #c3c4c7;padding:20px;margin:0 0 16px}
.ionrb-add label,.ionrb-test label{display:block;font-weight:600;margin-bottom:8px}
.ionrb-add textarea{display:block;width:100%;max-width:750px}
.ionrb-help{font-size:13px;color:#646970;margin:8px 0 18px}
.ionrb-fields{display:grid;grid-template-columns:180px minmax(0,1fr) auto;align-items:end;gap:14px;max-width:820px}
.ionrb-fields select,.ionrb-fields input{width:100%;max-width:none}
.ionrb-test-row{display:flex;align-items:center;gap:10px}
.ionrb-test-row input{width:100%;max-width:480px}
#ionrb-test-result{min-height:18px;margin-bottom:0}
#ionrb-test-result.is-bad{color:#b32d2e}
#ionrb-test-result.is-good{color:#008a20}
.ionrb-list-heading{display:flex;justify-content:space-between;align-items:center;gap:20px;margin:25px 0 14px}
.ionrb-list-heading strong{font-size:16px}
.ionrb-list-heading input{width:260px}
.ionrb-group h2{font-size:15px;margin:0 0 12px}
.ionrb-group h2 span{font-weight:400;color:#646970}
.ionrb-items{border:1px solid #c3c4c7}
.ionrb-item{display:flex;align-items:center;gap:14px;border-top:1px solid #dcdcde;padding:10px 12px}
.ionrb-item:first-child{border-top:0}
.ionrb-kind{font-size:12px;color:#646970;min-width:54px}
.ionrb-item code{font-size:13px;font-weight:600}
.ionrb-label{font-size:13px;color:#646970}
.ionrb-remove-form{margin-left:auto}
.ionrb-remove-form .button-link-delete{cursor:pointer}
.ionrb-notice{background:#fff;border-left:4px solid #00a32a;box-shadow:0 1px 1px rgba(0,0,0,.04);padding:10px 12px;margin-bottom:18px}
.ionrb-footer{color:#646970;font-size:13px;line-height:1.6}
.ionrb-wrap [hidden]{display:none!important}
@media(max-width:720px){.ionrb-header,.ionrb-list-heading{display:block}.ionrb-count{display:block;margin-top:10px}.ionrb-fields{grid-template-columns:1fr}.ionrb-test-row{flex-wrap:wrap}.ionrb-item{flex-wrap:wrap}.ionrb-list-heading input{width:100%;margin-top:10px}}
IONRB_CSS
        );
        wp_register_script( 'ionlg-blocks-admin', false, array(), '1.1.0', true );
        wp_enqueue_script( 'ionlg-blocks-admin' );
        wp_add_inline_script(
            'ionlg-blocks-admin',
            'window.ionrbConfig = ' . wp_json_encode( array(
                'ajax'  => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'ionrb_check' ),
            ) ) . ';',
            'before'
        );
        wp_add_inline_script( 'ionlg-blocks-admin', <<<'IONRB_JS'
(function () {
  'use strict';
  const search = document.getElementById('ionrb-search');
  if (search) {
    search.addEventListener('input', function () {
      const phrase = search.value.toLowerCase().trim();
      document.querySelectorAll('.ionrb-group').forEach(function (section) {
        let visible = 0;
        section.querySelectorAll('.ionrb-item').forEach(function (item) {
          const matches = (item.dataset.search || '').includes(phrase);
          item.hidden = !matches;
          if (matches) visible++;
        });
        section.hidden = visible === 0;
      });
    });
  }
  document.querySelectorAll('.ionrb-remove-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      const domain = form.querySelector('[name="domain"]');
      if (!window.confirm('Remove the block for ' + (domain ? domain.value : 'this domain') + '?')) event.preventDefault();
    });
  });
  const test = document.getElementById('ionrb-test-button');
  const email = document.getElementById('ionrb-test-email');
  const result = document.getElementById('ionrb-test-result');
  if (test && email && result && window.ionrbConfig) {
    const check = function () {
      result.className = '';
      result.textContent = 'Checking…';
      const data = new URLSearchParams({ action: 'ionrb_check', nonce: window.ionrbConfig.nonce, email: email.value });
      fetch(window.ionrbConfig.ajax, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          if (!json.success) throw new Error((json.data && json.data.message) || 'Unable to check email.');
          result.className = json.data.blocked ? 'is-bad' : 'is-good';
          result.textContent = json.data.blocked ? 'Blocked by rule: ' + json.data.rule : 'Allowed: no rule matches this email.';
        })
        .catch(function (error) { result.textContent = error.message || 'Unable to check email.'; });
    };
    test.addEventListener('click', check);
    email.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') { event.preventDefault(); check(); }
    });
  }
})();
IONRB_JS
        );
    }

    private static function redirect_with_message( $message, $count = 0 ) {
        wp_safe_redirect( add_query_arg( array(
            'page'        => 'ionpeptide-login-gate',
            'tab'         => 'registration-blocks',
            'ionrb_msg'   => $message,
            'ionrb_count' => (int) $count,
        ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    public static function save_new_entries() {
        if ( ! self::authorized() ) {
            wp_die( 'Permission denied.' );
        }
        check_admin_referer( 'ionrb_add' );
        $raw = isset( $_POST['entries'] ) && is_string( $_POST['entries'] ) ? wp_unslash( $_POST['entries'] ) : '';
        $group = isset( $_POST['group'] ) && is_string( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : 'other';
        $label = isset( $_POST['label'] ) && is_string( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
        $group = in_array( $group, array( 'government', 'pharma', 'other' ), true ) ? $group : 'other';
        $input = preg_split( '/[\r\n,;]+/', $raw, 201 );
        $rules = self::rules();
        $count = 0;
        foreach ( array_slice( $input, 0, 200 ) as $candidate ) {
            $candidate = self::normalize_rule( $candidate );
            if ( '' !== $candidate && ! isset( $rules[ $candidate ] ) ) {
                $rules[ $candidate ] = array( 'group' => $group, 'label' => ( count( $input ) === 1 ? $label : '' ) );
                ++$count;
            }
        }
        update_option( self::OPTION, $rules, false );
        self::redirect_with_message( $count ? 'added' : 'none', $count );
    }

    public static function remove_entry() {
        if ( ! self::authorized() ) {
            wp_die( 'Permission denied.' );
        }
        check_admin_referer( 'ionrb_remove' );
        $raw = isset( $_POST['domain'] ) && is_string( $_POST['domain'] ) ? wp_unslash( $_POST['domain'] ) : '';
        $domain = self::normalize_rule( $raw );
        $rules = self::rules();
        if ( '' !== $domain && isset( $rules[ $domain ] ) ) {
            unset( $rules[ $domain ] );
            update_option( self::OPTION, $rules, false );
            self::redirect_with_message( 'removed' );
        }
        self::redirect_with_message( 'none' );
    }

    public static function ajax_check_email() {
        if ( ! self::authorized() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }
        check_ajax_referer( 'ionrb_check', 'nonce' );
        $email = isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Enter a valid email address.' ), 400 );
        }
        $matched = self::matched_rule( $email );
        wp_send_json_success( array( 'blocked' => '' !== $matched, 'rule' => $matched ) );
    }

    public static function admin_page() {
        if ( ! self::authorized() ) {
            wp_die( 'Permission denied.' );
        }
        $rules = self::rules();
        $groups = array(
            'government' => 'Government',
            'pharma'     => 'Pharma',
            'other'      => 'Other',
        );
        $notices = array(
            'added'   => sprintf( '%d new rule(s) added.', isset( $_GET['ionrb_count'] ) ? absint( $_GET['ionrb_count'] ) : 0 ),
            'removed' => 'Rule removed.',
            'none'    => 'No new valid domains were added (duplicates and invalid entries are skipped).',
        );
        $msg = isset( $_GET['ionrb_msg'] ) ? sanitize_key( wp_unslash( $_GET['ionrb_msg'] ) ) : '';
        ?>
        <div class="ionrb-container">
            <div class="ionrb-header">
              <div><h2>Registration Blocks</h2><p>Block new registrations using specified domains, including the IonPeptide registration page and Google onboarding. Existing accounts remain unaffected.</p></div>
              <span class="ionrb-count"><?php echo esc_html( count( $rules ) ); ?> blocked entries</span>
            </div>
            <?php if ( isset( $notices[ $msg ] ) ) : ?>
              <div class="ionrb-notice" role="status"><?php echo esc_html( $notices[ $msg ] ); ?></div>
            <?php endif; ?>
            <form class="ionrb-add" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
              <?php wp_nonce_field( 'ionrb_add' ); ?>
              <input type="hidden" name="action" value="ionrb_add">
              <label for="ionrb-entries">Domain or suffix</label>
              <textarea name="entries" id="ionrb-entries" rows="2" required placeholder="pfizer.com or .gov"></textarea>
              <p class="ionrb-help">A domain blocks itself and its subdomains. A suffix like <code>.gov</code> blocks all domains ending with it. Add multiple entries on separate lines.</p>
              <div class="ionrb-fields">
                <div><label for="ionrb-group">Group</label><select name="group" id="ionrb-group"><option value="government">Government</option><option value="pharma">Pharma</option><option value="other" selected>Other</option></select></div>
                <div><label for="ionrb-label">Note (optional)</label><input name="label" id="ionrb-label" type="text" maxlength="100" placeholder="e.g. BrandShield"></div>
                <button type="submit" class="button button-primary">Add block</button>
              </div>
            </form>
            <section class="ionrb-test" aria-label="Test a registration email">
              <label for="ionrb-test-email">Test an email address (no account is created)</label>
              <div class="ionrb-test-row"><input id="ionrb-test-email" type="email" placeholder="name@brandshield.com"><button type="button" class="button" id="ionrb-test-button">Check email</button></div>
              <p id="ionrb-test-result" role="status" aria-live="polite"></p>
            </section>
            <div class="ionrb-list-heading"><strong>Blocked domains</strong><input type="search" id="ionrb-search" placeholder="Search rules…" aria-label="Search blocked rules"></div>
            <?php foreach ( $groups as $group_slug => $group_name ) :
                $selected = array_filter( $rules, static function ( $row ) use ( $group_slug ) {
                    return is_array( $row ) && isset( $row['group'] ) && $group_slug === $row['group'];
                } );
                if ( ! $selected ) {
                    continue;
                }
                ksort( $selected );
                ?>
                <section class="ionrb-group" data-group="<?php echo esc_attr( $group_slug ); ?>">
                  <h2><?php echo esc_html( strtoupper( $group_name ) ); ?> <span>(<?php echo esc_html( count( $selected ) ); ?>)</span></h2>
                  <div class="ionrb-items">
                    <?php foreach ( $selected as $domain => $item ) : ?>
                      <div class="ionrb-item" data-search="<?php echo esc_attr( strtolower( $domain . ' ' . ( isset( $item['label'] ) ? $item['label'] : '' ) ) ); ?>">
                        <span class="ionrb-kind <?php echo '.' === substr( $domain, 0, 1 ) ? 'is-suffix' : 'is-domain'; ?>"><?php echo '.' === substr( $domain, 0, 1 ) ? 'suffix' : 'domain'; ?></span>
                        <code><?php echo esc_html( $domain ); ?></code>
                        <?php if ( ! empty( $item['label'] ) ) : ?><span class="ionrb-label"><?php echo esc_html( $item['label'] ); ?></span><?php endif; ?>
                        <form class="ionrb-remove-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                          <?php wp_nonce_field( 'ionrb_remove' ); ?>
                          <input type="hidden" name="action" value="ionrb_remove"><input type="hidden" name="domain" value="<?php echo esc_attr( $domain ); ?>">
                          <button type="submit" class="button-link-delete" aria-label="Remove <?php echo esc_attr( $domain ); ?>">Remove</button>
                        </form>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </section>
            <?php endforeach; ?>
            <p class="ionrb-footer">These rules restrict only new account creation, not existing accounts, logins, password resets, or existing orders. The initial list contains 22 rules taken from the visible reference plus BrandShield. Additional rules can be added here.</p>
        </div>
        <?php
    }
}

// Seed initial rules only if none exist; preserve every later admin edit across updates.
IonLG_Registration_Blocks::activate();
IonLG_Registration_Blocks::init();
