<?php
/**
 * Plugin Name: Ion Peptide Contact Form
 * Description: Contact form for Ion Peptide contact page with dashboard saving, invisible reCAPTCHA v3, honeypot, disposable email blocking, rate limiting, admin emails, and customer confirmations.
 * Version: 1.0.0
 * Author: Ion Peptide
 */

if (!defined('ABSPATH')) exit;

final class Ion_Peptide_Contact_Form {
  private static $instance = null;
  private $post_type = 'ion_contact_req';

  const VERSION = '1.0.0';
  const RECAPTCHA_SITE_KEY = '6LcwLoYsAAAAAAAVMp8A5YDA-c5xbI7g1cZO_eF4';
  const RECAPTCHA_SECRET_KEY = '6LcwLoYsAAAAACJWlwnuGy28WytulBhrJd53XrOy';
  const RECAPTCHA_ACTION = 'ion_contact_submit';
  const RECAPTCHA_MIN_SCORE = 0.3;

  public static function instance() {
    if (!self::$instance) self::$instance = new self();
    return self::$instance;
  }

  private function __construct() {
    add_shortcode('ion_contact_form', [$this, 'render_shortcode']);
    add_action('init', [$this, 'register_post_type']);
    add_action('wp_ajax_ion_contact_submit', [$this, 'ajax_submit']);
    add_action('wp_ajax_nopriv_ion_contact_submit', [$this, 'ajax_submit']);
    add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    add_filter('manage_' . $this->post_type . '_posts_columns', [$this, 'columns']);
    add_action('manage_' . $this->post_type . '_posts_custom_column', [$this, 'column_content'], 10, 2);
    add_action('pre_get_posts', [$this, 'admin_query_all_statuses']);
    add_action('admin_menu', [$this, 'remove_add_new_menu']);
  }

  public static function activate() {
    self::instance()->register_post_type();
    flush_rewrite_rules();
  }

  public static function deactivate() {
    flush_rewrite_rules();
  }

  public function register_post_type() {
    register_post_type($this->post_type, [
      'labels' => [
        'name' => 'Contact Requests',
        'singular_name' => 'Contact Request',
        'menu_name' => 'Contact Requests',
        'all_items' => 'All Contact Requests',
        'edit_item' => 'View Contact Request',
        'view_item' => 'View Contact Request',
        'search_items' => 'Search Contact Requests',
        'not_found' => 'No contact requests found',
      ],
      'public' => false,
      'publicly_queryable' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'show_in_admin_bar' => false,
      'show_in_nav_menus' => false,
      'can_export' => true,
      'menu_position' => 31,
      'menu_icon' => 'dashicons-email-alt2',
      'supports' => ['title'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
      'has_archive' => false,
      'rewrite' => false,
      'query_var' => false,
      'exclude_from_search' => true,
    ]);
  }

  public function remove_add_new_menu() {
    global $submenu;
    $key = 'edit.php?post_type=' . $this->post_type;
    if (!empty($submenu[$key])) {
      foreach ($submenu[$key] as $i => $item) {
        if (!empty($item[0]) && $item[0] === 'Add New') unset($submenu[$key][$i]);
      }
    }
  }

  public function admin_query_all_statuses($query) {
    if (is_admin() && $query->is_main_query() && $query->get('post_type') === $this->post_type) {
      $query->set('post_status', ['publish', 'private', 'draft', 'pending']);
    }
  }

  public function render_shortcode() {
    $this->enqueue_assets();
    $nonce = wp_create_nonce('ion_contact_submit');
    ob_start(); ?>
    <form class="ion-contact-plugin-form" data-ion-contact-form novalidate>
      <div class="ion-contact-alert" data-ion-contact-alert aria-live="polite"></div>

      <input type="hidden" name="action" value="ion_contact_submit">
      <input type="hidden" name="ion_contact_nonce" value="<?php echo esc_attr($nonce); ?>">
      <input type="hidden" name="ion_contact_page_url" value="<?php echo esc_url($this->current_url()); ?>">
      <input type="hidden" name="ion_contact_started_at" value="<?php echo esc_attr(time()); ?>">
      <input type="hidden" name="ion_contact_recaptcha_token" value="">
      <input type="hidden" name="ion_contact_recaptcha_action" value="<?php echo esc_attr(self::RECAPTCHA_ACTION); ?>">

      <div class="ion-contact-hp" aria-hidden="true">
        <label for="ion_contact_company_website">Company Website</label>
        <input type="text" id="ion_contact_company_website" name="ion_contact_company_website" value="" tabindex="-1" autocomplete="off">
      </div>

      <div class="ion-contact-grid ion-contact-grid--two">
        <label class="ion-contact-field" for="ion_contact_first_name">
          <span>First Name <strong>*</strong></span>
          <input type="text" id="ion_contact_first_name" name="ion_contact_first_name" autocomplete="given-name" required>
        </label>

        <label class="ion-contact-field" for="ion_contact_last_name">
          <span>Last Name <strong>*</strong></span>
          <input type="text" id="ion_contact_last_name" name="ion_contact_last_name" autocomplete="family-name" required>
        </label>
      </div>

      <label class="ion-contact-field" for="ion_contact_email">
        <span>Email <strong>*</strong></span>
        <input type="email" id="ion_contact_email" name="ion_contact_email" autocomplete="email" required>
      </label>

      <label class="ion-contact-field" for="ion_contact_phone">
        <span>Phone Number</span>
        <input type="tel" id="ion_contact_phone" name="ion_contact_phone" value="+1 " placeholder="+1 (555) 123-4567" inputmode="tel" autocomplete="tel">
      </label>

      <label class="ion-contact-field" for="ion_contact_order_number">
        <span>Order Number</span>
        <input type="text" id="ion_contact_order_number" name="ion_contact_order_number" autocomplete="off">
      </label>

      <label class="ion-contact-field" for="ion_contact_message">
        <span>Message <strong>*</strong></span>
        <textarea id="ion_contact_message" name="ion_contact_message" rows="5" required></textarea>
      </label>

      <label class="ion-contact-agree ion-contact-field" for="ion_contact_agree">
        <span class="ion-contact-agree-row">
          <input type="checkbox" id="ion_contact_agree" name="ion_contact_agree" value="1" required>
          <span>I understand Ion Peptide support cannot provide medical, dosing, preparation, or human/animal use guidance. <strong>*</strong></span>
        </span>
      </label>

      <button class="ion-contact-submit" type="submit" data-ion-contact-submit>Submit</button>
    </form>
    <?php
    return ob_get_clean();
  }

  private function enqueue_assets() {
    wp_enqueue_script(
      'google-recaptcha-v3',
      'https://www.google.com/recaptcha/api.js?render=' . rawurlencode(self::RECAPTCHA_SITE_KEY),
      [],
      null,
      true
    );

    wp_register_script('ion-contact-form-script', false, ['google-recaptcha-v3'], self::VERSION, true);
    wp_enqueue_script('ion-contact-form-script');

    wp_localize_script('ion-contact-form-script', 'IonContactForm', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'recaptchaSiteKey' => self::RECAPTCHA_SITE_KEY,
      'recaptchaAction' => self::RECAPTCHA_ACTION,
    ]);

    wp_add_inline_script('ion-contact-form-script', $this->frontend_js());

    wp_register_style('ion-contact-form-style', false, [], self::VERSION);
    wp_enqueue_style('ion-contact-form-style');
    wp_add_inline_style('ion-contact-form-style', $this->frontend_css());
  }

  public function ajax_submit() {
    $result = $this->process_submission();

    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()], 200);
    }

    wp_send_json_success(['message' => $result['message']], 200);
  }

  private function process_submission() {
    $post = wp_unslash($_POST);
    $nonce = isset($post['ion_contact_nonce']) ? sanitize_text_field($post['ion_contact_nonce']) : '';

    if (!$nonce || !wp_verify_nonce($nonce, 'ion_contact_submit')) {
      return new WP_Error('invalid_nonce', 'Security check failed. Please refresh the page and try again.');
    }

    if (!empty($post['ion_contact_company_website'])) {
      return new WP_Error('bot_detected', 'Your message could not be submitted. Please try again.');
    }

    $first = isset($post['ion_contact_first_name']) ? sanitize_text_field($post['ion_contact_first_name']) : '';
    $last = isset($post['ion_contact_last_name']) ? sanitize_text_field($post['ion_contact_last_name']) : '';
    $email = isset($post['ion_contact_email']) ? sanitize_email($post['ion_contact_email']) : '';
    $phone = isset($post['ion_contact_phone']) ? sanitize_text_field($post['ion_contact_phone']) : '';
    $order = isset($post['ion_contact_order_number']) ? sanitize_text_field($post['ion_contact_order_number']) : '';
    $message = isset($post['ion_contact_message']) ? sanitize_textarea_field($post['ion_contact_message']) : '';
    $agree = isset($post['ion_contact_agree']) ? sanitize_text_field($post['ion_contact_agree']) : '';
    $from = isset($post['ion_contact_page_url']) ? esc_url_raw($post['ion_contact_page_url']) : $this->current_url();

    if (!$first || !$last || !$email || !$message || $agree !== '1') {
      return new WP_Error('missing_fields', 'Please complete all required fields and confirm the agreement before submitting.');
    }

    if (!is_email($email)) {
      return new WP_Error('invalid_email', 'Please enter a valid email address.');
    }

    if ($this->is_disposable_email($email)) {
      return new WP_Error('disposable_email', 'Please use a permanent email address.');
    }

    $phone_info = $this->validate_us_phone($phone);
    if (is_wp_error($phone_info)) return $phone_info;

    if ($this->looks_like_spam($message)) {
      return new WP_Error('spam_detected', 'Your message could not be submitted because it appears to contain spam or unsafe content.');
    }

    if ($this->contains_prohibited_request($message)) {
      return new WP_Error('prohibited_request', 'Ion Peptide support cannot assist with medical, dosing, preparation, or human/animal use guidance.');
    }

    if (!$this->rate_limit_ok($email)) {
      return new WP_Error('rate_limited', 'Too many submissions were sent recently. Please wait a few minutes and try again.');
    }

    $recaptcha = $this->verify_recaptcha();
    $recaptcha_status = is_wp_error($recaptcha) ? 'Review Required' : 'Passed';

    $data = [
      'first_name' => $first,
      'last_name' => $last,
      'name' => trim($first . ' ' . $last),
      'email' => $email,
      'phone' => !empty($phone_info['formatted']) ? $phone_info['formatted'] : '',
      'order_number' => $order,
      'message' => $message,
      'agreement' => 'Agreed',
      'recaptcha_status' => $recaptcha_status,
      'submitted_from' => $from,
      'ip_address' => $this->ip_address(),
      'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
    ];

    $id = $this->save_request($data);
    if (is_wp_error($id)) {
      return new WP_Error('save_failed', 'Your message could not be saved. Please try again or contact support directly.');
    }

    $admin_sent = $this->send_admin_email($data);
    $customer_sent = $this->send_customer_email($data);

    update_post_meta($id, '_ion_contact_email_status', ($admin_sent && $customer_sent) ? 'Sent' : 'Partial / Check SMTP');
    update_post_meta($id, '_ion_contact_admin_email_status', $admin_sent ? 'Sent' : 'Failed');
    update_post_meta($id, '_ion_contact_customer_email_status', $customer_sent ? 'Sent' : 'Failed');
    update_post_meta($id, '_ion_contact_email_sent_at', ($admin_sent || $customer_sent) ? current_time('mysql') : '');

    return [
      'message' => '<strong>Thank you.</strong> Your message has been received. Ion Peptide support will review it and follow up if more information is needed.',
    ];
  }

  private function save_request($data) {
    if (!post_type_exists($this->post_type)) $this->register_post_type();

    $title = $data['name'] . ($data['order_number'] ? ' - Order ' . $data['order_number'] : '') . ' - ' . current_time('Y-m-d H:i');
    $content = sprintf(
      "Name: %s\nEmail: %s\nPhone: %s\nOrder Number: %s\nSubmitted At: %s\n\nMessage:\n%s",
      $data['name'], $data['email'], $data['phone'], $data['order_number'], current_time('mysql'), $data['message']
    );

    $id = wp_insert_post([
      'post_type' => $this->post_type,
      'post_status' => 'publish',
      'post_title' => sanitize_text_field($title),
      'post_content' => sanitize_textarea_field($content),
      'post_author' => get_current_user_id(),
      'comment_status' => 'closed',
      'ping_status' => 'closed',
    ], true);

    if (is_wp_error($id)) return $id;

    $meta = [
      '_ion_contact_first_name' => $data['first_name'],
      '_ion_contact_last_name' => $data['last_name'],
      '_ion_contact_name' => $data['name'],
      '_ion_contact_email' => $data['email'],
      '_ion_contact_phone' => $data['phone'],
      '_ion_contact_order_number' => $data['order_number'],
      '_ion_contact_message' => $data['message'],
      '_ion_contact_agreement' => $data['agreement'],
      '_ion_contact_recaptcha_status' => $data['recaptcha_status'],
      '_ion_contact_status' => 'New',
      '_ion_contact_dashboard_saved' => 'Yes',
      '_ion_contact_dashboard_saved_at' => current_time('mysql'),
      '_ion_contact_submitted_from' => $data['submitted_from'],
      '_ion_contact_ip_address' => $data['ip_address'],
      '_ion_contact_user_agent' => $data['user_agent'],
      '_ion_contact_email_status' => 'Pending',
      '_ion_contact_admin_email_status' => 'Pending',
      '_ion_contact_customer_email_status' => 'Pending',
      '_ion_contact_email_sent_at' => '',
      '_ion_contact_submitted_at' => current_time('mysql'),
    ];

    foreach ($meta as $key => $value) update_post_meta($id, $key, $value);

    return $id;
  }

  public function add_meta_boxes() {
    add_meta_box('ion_contact_details', 'Contact Request Details', [$this, 'details_metabox'], $this->post_type, 'normal', 'high');
  }

  public function details_metabox($post) {
    $fields = [
      'Request Status' => '_ion_contact_status',
      'First Name' => '_ion_contact_first_name',
      'Last Name' => '_ion_contact_last_name',
      'Email' => '_ion_contact_email',
      'Phone' => '_ion_contact_phone',
      'Order Number' => '_ion_contact_order_number',
      'Message' => '_ion_contact_message',
      'Agreement' => '_ion_contact_agreement',
      'reCAPTCHA Status' => '_ion_contact_recaptcha_status',
      'Dashboard Saved' => '_ion_contact_dashboard_saved',
      'Dashboard Saved At' => '_ion_contact_dashboard_saved_at',
      'Submitted From' => '_ion_contact_submitted_from',
      'IP Address' => '_ion_contact_ip_address',
      'Email Status' => '_ion_contact_email_status',
      'Admin Email Status' => '_ion_contact_admin_email_status',
      'Customer Confirmation Status' => '_ion_contact_customer_email_status',
      'Email Sent At' => '_ion_contact_email_sent_at',
      'Submitted At' => '_ion_contact_submitted_at',
    ];

    echo '<table class="widefat striped"><tbody>';
    foreach ($fields as $label => $key) {
      $value = get_post_meta($post->ID, $key, true);
      echo '<tr><th style="width:230px;">' . esc_html($label) . '</th><td>';
      if ($key === '_ion_contact_message') {
        echo '<pre style="white-space:pre-wrap;margin:0;font-family:inherit;">' . esc_html($value) . '</pre>';
      } elseif ($key === '_ion_contact_email' && $value) {
        echo '<a href="mailto:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
      } elseif ($key === '_ion_contact_submitted_from' && $value) {
        echo '<a href="' . esc_url($value) . '" target="_blank" rel="noopener noreferrer">' . esc_html($value) . '</a>';
      } else {
        echo esc_html($value ? $value : '—');
      }
      echo '</td></tr>';
    }
    echo '</tbody></table>';
  }

  public function columns($columns) {
    return [
      'cb' => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />',
      'title' => 'Request',
      'email' => 'Email',
      'order_number' => 'Order #',
      'request_status' => 'Request Status',
      'recaptcha_status' => 'reCAPTCHA',
      'email_status' => 'Email Status',
      'date' => 'Date',
    ];
  }

  public function column_content($column, $post_id) {
    if ($column === 'email') {
      $email = get_post_meta($post_id, '_ion_contact_email', true);
      echo $email ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '—';
    } elseif ($column === 'order_number') {
      echo esc_html(get_post_meta($post_id, '_ion_contact_order_number', true) ?: '—');
    } elseif ($column === 'request_status') {
      echo esc_html(get_post_meta($post_id, '_ion_contact_status', true) ?: 'New');
    } elseif ($column === 'recaptcha_status') {
      echo esc_html(get_post_meta($post_id, '_ion_contact_recaptcha_status', true) ?: '—');
    } elseif ($column === 'email_status') {
      echo esc_html(get_post_meta($post_id, '_ion_contact_email_status', true) ?: 'Pending');
    }
  }

  private function send_admin_email($data) {
    $to = apply_filters('ion_contact_form_recipient', 'Support@ionpeptide.com');
    $subject = 'New Ion Peptide Contact Request';

    $body  = "New contact request received.\n\n";
    $body .= "Name: {$data['name']}\n";
    $body .= "Email: {$data['email']}\n";
    $body .= "Phone: " . ($data['phone'] ?: 'Not provided') . "\n";
    $body .= "Order Number: " . ($data['order_number'] ?: 'Not provided') . "\n";
    $body .= "Agreement: {$data['agreement']}\n";
    $body .= "reCAPTCHA Status: {$data['recaptcha_status']}\n";
    $body .= "Submitted From: {$data['submitted_from']}\n";
    $body .= "IP Address: {$data['ip_address']}\n\n";
    $body .= "Message:\n{$data['message']}\n";

    $headers = [
      'Content-Type: text/plain; charset=UTF-8',
      'From: Ion Peptide <Support@ionpeptide.com>',
      'Reply-To: ' . $data['name'] . ' <' . $data['email'] . '>',
    ];

    return wp_mail($to, $subject, $body, $headers);
  }

  private function send_customer_email($data) {
    if (empty($data['email']) || !is_email($data['email'])) return false;

    $subject = 'Ion Peptide Contact Request Received';
    $body  = "Hello {$data['first_name']},\n\n";
    $body .= "Thank you for contacting Ion Peptide. Your message has been received and our support team will review it.\n\n";
    $body .= "If your request is related to an order, please make sure your order number and checkout email are included. If more information is needed, our team will follow up.\n\n";
    $body .= "Request summary:\n";
    $body .= "Name: {$data['name']}\n";
    $body .= "Order Number: " . ($data['order_number'] ?: 'Not provided') . "\n\n";
    $body .= "Important: Ion Peptide support cannot provide medical, dosing, preparation, or human/animal use guidance.\n\n";
    $body .= "Thank you,\nIon Peptide Support\nSupport@ionpeptide.com\n";

    $headers = [
      'Content-Type: text/plain; charset=UTF-8',
      'From: Ion Peptide <Support@ionpeptide.com>',
      'Reply-To: Ion Peptide Support <Support@ionpeptide.com>',
    ];

    return wp_mail($data['email'], $subject, $body, $headers);
  }

  private function verify_recaptcha() {
    $token = isset($_POST['ion_contact_recaptcha_token']) ? sanitize_text_field(wp_unslash($_POST['ion_contact_recaptcha_token'])) : '';
    $action = isset($_POST['ion_contact_recaptcha_action']) ? sanitize_text_field(wp_unslash($_POST['ion_contact_recaptcha_action'])) : '';

    if (!$token) return new WP_Error('recaptcha_missing', 'reCAPTCHA token missing.');

    $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
      'timeout' => 15,
      'body' => [
        'secret' => self::RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
      ],
    ]);

    if (is_wp_error($response)) return new WP_Error('recaptcha_unavailable', 'Google reCAPTCHA could not be reached.');

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['success'])) return new WP_Error('recaptcha_failed', 'Google reCAPTCHA verification issue.');
    if (!empty($body['action']) && $action && $body['action'] !== $action) return new WP_Error('recaptcha_action_failed', 'reCAPTCHA action mismatch.');

    $score = isset($body['score']) ? (float) $body['score'] : 0.0;
    if ($score < (float) self::RECAPTCHA_MIN_SCORE) return new WP_Error('recaptcha_low_score', 'reCAPTCHA low score.');

    return ['success' => true, 'score' => $score];
  }

  private function validate_us_phone($phone) {
    $phone = trim((string) $phone);
    if ($phone === '') return ['formatted' => ''];
    if (preg_match('/[a-zA-Z]/', $phone)) return new WP_Error('invalid_phone_letters', 'Please enter a valid phone number without letters.');

    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '1') return ['formatted' => ''];
    if (strlen($digits) === 11 && strpos($digits, '1') === 0) $digits = substr($digits, 1);
    if (strlen($digits) !== 10) return new WP_Error('invalid_us_phone_length', 'Please enter a valid US phone number.');

    $area = substr($digits, 0, 3);
    $prefix = substr($digits, 3, 3);
    $line = substr($digits, 6, 4);
    if (preg_match('/^[01]/', $area) || preg_match('/^[01]/', $prefix)) return new WP_Error('invalid_us_phone_number', 'Please enter a valid US phone number.');

    return ['formatted' => '+1 ' . $area . '-' . $prefix . '-' . $line];
  }

  private function is_disposable_email($email) {
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    if (!$domain) return true;

    $blocked_domains = [
      '10minutemail.com','10minutemail.net','20minutemail.com','mailinator.com','guerrillamail.com',
      'guerrillamail.net','guerrillamail.org','temp-mail.org','tempmail.com','tempmail.net',
      'throwawaymail.com','trashmail.com','trashmail.net','yopmail.com','yopmail.fr','sharklasers.com',
      'getairmail.com','maildrop.cc','dispostable.com','fakeinbox.com','moakt.com','emailondeck.com',
      'burnermail.io','tempmailo.com'
    ];

    if (in_array($domain, $blocked_domains, true)) return true;

    foreach (['10minute','tempmail','temp-mail','throwaway','trashmail','mailinator','guerrilla','yopmail','disposable','burner','fakeinbox'] as $keyword) {
      if (strpos($domain, $keyword) !== false) return true;
    }

    return false;
  }

  private function looks_like_spam($message) {
    $lower = strtolower((string) $message);
    preg_match_all('/https?:\/\/|www\./i', $message, $matches);
    if (!empty($matches[0]) && count($matches[0]) > 2) return true;

    foreach (['casino','viagra','cialis','crypto investment','loan offer','seo backlinks','rank #1','guest post','telegram:','whatsapp:'] as $phrase) {
      if (strpos($lower, $phrase) !== false) return true;
    }

    return false;
  }

  private function contains_prohibited_request($message) {
    $lower = strtolower((string) $message);
    $terms = ['dose','dosage','dosing','inject','injection','injecting','reconstitute','reconstitution','bacteriostatic water','for my patient','for myself','take this','human use','animal use','medical advice','treat','treatment','diagnosis','side effects'];
    foreach ($terms as $term) {
      if (strpos($lower, $term) !== false) return true;
    }
    return false;
  }

  private function rate_limit_ok($email) {
    $ip_key = 'ion_contact_rate_ip_' . md5($this->ip_address());
    $email_key = 'ion_contact_rate_email_' . md5(strtolower($email));
    $ip_count = (int) get_transient($ip_key);
    $email_count = (int) get_transient($email_key);

    if ($ip_count >= 6 || $email_count >= 4) return false;

    set_transient($ip_key, $ip_count + 1, HOUR_IN_SECONDS);
    set_transient($email_key, $email_count + 1, HOUR_IN_SECONDS);

    return true;
  }

  private function ip_address() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key) {
      if (!empty($_SERVER[$key])) {
        $value = sanitize_text_field(wp_unslash($_SERVER[$key]));
        if ($key === 'HTTP_X_FORWARDED_FOR') {
          $parts = explode(',', $value);
          $value = trim($parts[0]);
        }
        return $value;
      }
    }
    return '';
  }

  private function current_url() {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    return esc_url_raw($scheme . $host . $uri);
  }

  private function frontend_css() {
    return <<<'CSS'
.ion-contact-plugin-form,.ion-contact-plugin-form *{box-sizing:border-box}
.ion-contact-plugin-form{--ion-blue:#007eff;--ion-navy:#0d0d67;--ion-text:#333;--ion-muted:#626262;--ion-line:#dfe8f6;--ion-soft:#f7f9ff;display:grid;gap:16px;width:100%;font-family:"Poppins","Inter",Arial,sans-serif}
.ion-contact-plugin-form .ion-contact-alert{display:none;padding:14px 16px;border-radius:16px;font-size:14px;line-height:1.55;font-weight:800}
.ion-contact-plugin-form .ion-contact-alert.is-visible{display:block}
.ion-contact-plugin-form .ion-contact-alert.is-error{background:#fff1f2;border:1px solid rgba(220,38,38,.24);color:#9f1239}
.ion-contact-plugin-form .ion-contact-alert.is-success{background:#ecfdf5;border:1px solid rgba(5,150,105,.22);color:#065f46}
.ion-contact-plugin-form .ion-contact-grid{display:grid;gap:14px}
.ion-contact-plugin-form .ion-contact-grid--two{grid-template-columns:repeat(2,minmax(0,1fr))}
.ion-contact-plugin-form .ion-contact-field{display:grid;gap:8px;margin:0}
.ion-contact-plugin-form .ion-contact-field>span:first-child{color:var(--ion-navy);font-size:13px;line-height:1.35;font-weight:900}
.ion-contact-plugin-form .ion-contact-field strong{color:var(--ion-blue)}
.ion-contact-plugin-form input[type=text],.ion-contact-plugin-form input[type=email],.ion-contact-plugin-form input[type=tel],.ion-contact-plugin-form textarea{width:100%;min-height:48px;padding:13px 15px;border-radius:15px;background:#fff;border:1px solid var(--ion-line);color:var(--ion-text);font-size:15px;line-height:1.35;outline:none;box-shadow:none;transition:border-color .18s ease,box-shadow .18s ease}
.ion-contact-plugin-form textarea{resize:vertical;min-height:128px}
.ion-contact-plugin-form input:focus,.ion-contact-plugin-form textarea:focus{border-color:rgba(0,126,255,.55);box-shadow:0 0 0 4px rgba(0,126,255,.09)}
.ion-contact-plugin-form .has-error input,.ion-contact-plugin-form .has-error textarea,.ion-contact-plugin-form .ion-contact-agree.has-error{border-color:rgba(220,38,38,.55)!important;box-shadow:0 0 0 4px rgba(220,38,38,.08)!important}
.ion-contact-plugin-form .ion-contact-field-error{color:#b91c1c;font-size:12px;line-height:1.45;font-weight:800}
.ion-contact-plugin-form .ion-contact-agree{padding:14px;border-radius:18px;background:rgba(247,249,255,.92);border:1px solid rgba(0,126,255,.12);cursor:pointer}
.ion-contact-plugin-form .ion-contact-agree-row{display:flex;align-items:flex-start;gap:12px;color:var(--ion-navy);font-size:13px;line-height:1.55;font-weight:800}
.ion-contact-plugin-form .ion-contact-agree-row input{width:auto!important;min-height:auto!important;margin-top:3px;box-shadow:none!important}
.ion-contact-plugin-form .ion-contact-submit{min-height:54px;width:100%;border:0;border-radius:999px;background:var(--ion-blue);color:#fff;cursor:pointer;font-size:15px;line-height:1;font-weight:900;box-shadow:0 14px 26px rgba(0,126,255,.18);transition:transform .18s ease,background-color .18s ease,box-shadow .18s ease}
.ion-contact-plugin-form .ion-contact-submit:hover{background:var(--ion-navy);transform:translateY(-1px);box-shadow:0 18px 32px rgba(13,13,103,.2)}
.ion-contact-plugin-form .ion-contact-submit[disabled]{opacity:.7;cursor:wait;transform:none}
.ion-contact-plugin-form .ion-contact-hp{position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important}
@media(max-width:640px){.ion-contact-plugin-form .ion-contact-grid--two{grid-template-columns:1fr}}
CSS;
  }

  private function frontend_js() {
    return <<<'JS'
(function(){
  var cfg=window.IonContactForm||{};
  function field(input){return input.closest(".ion-contact-field")||input.parentNode}
  function clearField(input){var f=field(input);if(!f)return;f.classList.remove("has-error");f.querySelectorAll(".ion-contact-field-error").forEach(function(e){e.remove()})}
  function showField(input,msg){var f=field(input);if(!f)return;clearField(input);f.classList.add("has-error");var e=document.createElement("div");e.className="ion-contact-field-error";e.textContent=msg;f.appendChild(e)}
  function alertBox(form,type,msg){var a=form.querySelector("[data-ion-contact-alert]");if(!a)return;a.className="ion-contact-alert is-visible is-"+type;a.innerHTML=msg}
  function clearAlert(form){var a=form.querySelector("[data-ion-contact-alert]");if(!a)return;a.className="ion-contact-alert";a.textContent=""}
  function recaptcha(form){if(!cfg.recaptchaSiteKey||!window.grecaptcha||typeof window.grecaptcha.ready!=="function")return Promise.resolve("");return new Promise(function(resolve){window.grecaptcha.ready(function(){window.grecaptcha.execute(cfg.recaptchaSiteKey,{action:cfg.recaptchaAction||"ion_contact_submit"}).then(function(token){var t=form.querySelector("input[name='ion_contact_recaptcha_token']");var a=form.querySelector("input[name='ion_contact_recaptcha_action']");if(t)t.value=token||"";if(a)a.value=cfg.recaptchaAction||"ion_contact_submit";resolve(token||"")}).catch(function(){resolve("")})})})}
  function validate(form){
    var first=null;
    form.querySelectorAll(".has-error").forEach(function(f){f.classList.remove("has-error")});
    form.querySelectorAll(".ion-contact-field-error").forEach(function(e){e.remove()});
    [["ion_contact_first_name","Please enter your first name."],["ion_contact_last_name","Please enter your last name."],["ion_contact_email","Please enter your email address."],["ion_contact_message","Please enter your message."]].forEach(function(item){
      var input=form.querySelector("[name='"+item[0]+"']");
      if(input&&!String(input.value||"").trim()){showField(input,item[1]);if(!first)first=input}
    });
    var email=form.querySelector("[name='ion_contact_email']");
    if(email&&String(email.value||"").trim()&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email.value||"").trim())){showField(email,"Please enter a valid email address.");if(!first)first=email}
    var phone=form.querySelector("[name='ion_contact_phone']");
    if(phone){var v=String(phone.value||"").trim();var digits=v.replace(/\D+/g,"");if(v&&digits!=="1"){var n=digits;if(n.length===11&&n.charAt(0)==="1")n=n.substring(1);if(n.length!==10){showField(phone,"Please enter a valid US phone number.");if(!first)first=phone}}}
    var agree=form.querySelector("[name='ion_contact_agree']");
    if(agree&&!agree.checked){showField(agree,"Please confirm the required agreement before submitting.");if(!first)first=agree}
    if(first){alertBox(form,"error","Please fix the highlighted fields before submitting.");first.focus({preventScroll:true});first.scrollIntoView({behavior:"smooth",block:"center"});return false}
    return true;
  }
  function submit(form){
    var btn=form.querySelector("[data-ion-contact-submit]");
    var data=new FormData(form);
    if(btn){btn.disabled=true;btn.textContent="Submitting..."}
    clearAlert(form);
    fetch(cfg.ajaxUrl||"/wp-admin/admin-ajax.php",{method:"POST",body:data,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(payload){
      if(!payload||!payload.success){var msg=payload&&payload.data&&payload.data.message?payload.data.message:"Your message could not be submitted. Please try again.";alertBox(form,"error",msg);return}
      alertBox(form,"success",payload.data.message||"Your message has been submitted successfully.");form.reset();var phone=form.querySelector("[name='ion_contact_phone']");if(phone)phone.value="+1 ";
    }).catch(function(){alertBox(form,"error","Your message could not be submitted. Please try again.")}).finally(function(){if(btn){btn.disabled=false;btn.textContent="Submit"}})
  }
  document.addEventListener("input",function(e){if(e.target.closest(".ion-contact-plugin-form"))clearField(e.target)});
  document.addEventListener("change",function(e){if(e.target.closest(".ion-contact-plugin-form"))clearField(e.target)});
  document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("input[name='ion_contact_phone']").forEach(function(p){if(!p.value)p.value="+1 ";p.addEventListener("focus",function(){if(!p.value)p.value="+1 "});p.addEventListener("blur",function(){if(!p.value||p.value.trim()==="+1")p.value="+1 "})})});
  document.addEventListener("submit",function(e){var form=e.target.closest(".ion-contact-plugin-form");if(!form)return;e.preventDefault();if(!validate(form))return;recaptcha(form).then(function(){submit(form)})});
})();
JS;
  }
}

register_activation_hook(__FILE__, ['Ion_Peptide_Contact_Form', 'activate']);
register_deactivation_hook(__FILE__, ['Ion_Peptide_Contact_Form', 'deactivate']);
Ion_Peptide_Contact_Form::instance();
