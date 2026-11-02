<?php
/**
 * Plugin Name: Ion Rebate Request Form
 * Description: Clean AJAX HPLC rebate request form with typed Testing Lab Company field, dashboard saving, email notifications, optional uploads, honeypot, temp email blocking, and same-page validation. Use [ion_rebate_form].
 * Version: 2.4.7
 * Author: Ion Peptide
 */

if (!defined('ABSPATH')) {
  exit;
}

final class Ion_Rebate_Request_Form_Clean_V246 {
  const VERSION = '2.4.7';
  const NONCE_ACTION = 'ion_rebate_request_action';
  const NONCE_NAME = 'ion_rebate_nonce';
  const CPT = 'ion_rebate_request';
  const MAX_UPLOAD_BYTES = 8388608; // 8 MB
  const RECAPTCHA_SITE_KEY = '6LcwLoYsAAAAAAAVMp8A5YDA-c5xbI7g1cZO_eF4';
  const RECAPTCHA_SECRET_KEY = '6LcwLoYsAAAAACJWlwnuGy28WytulBhrJd53XrOy';
  const RECAPTCHA_ACTION = 'ion_rebate_submit';
  const RECAPTCHA_MIN_SCORE = 0.3;


  private static $instance = null;
  private $notice = null;
  private $assets_registered = false;
  private $assets_enqueued = false;

  public static function instance() {
    if (self::$instance === null) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  private function __construct() {
    add_action('init', array($this, 'register_post_type'));
    add_shortcode('ion_rebate_form', array($this, 'render_shortcode'));

    add_action('wp_enqueue_scripts', array($this, 'register_assets'));

    add_action('wp_ajax_ion_rebate_request_refresh_nonce', array($this, 'ajax_refresh_nonce'));
    add_action('wp_ajax_nopriv_ion_rebate_request_refresh_nonce', array($this, 'ajax_refresh_nonce'));

    add_action('wp_ajax_ion_rebate_request_submit', array($this, 'ajax_submit'));
    add_action('wp_ajax_nopriv_ion_rebate_request_submit', array($this, 'ajax_submit'));

    add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
    add_filter('manage_' . self::CPT . '_posts_columns', array($this, 'admin_columns'));
    add_action('manage_' . self::CPT . '_posts_custom_column', array($this, 'admin_column_content'), 10, 2);
    add_action('admin_menu', array($this, 'remove_add_new_submenu'));
    add_action('pre_get_posts', array($this, 'include_all_rebate_request_statuses'));
  }

  public function register_post_type() {
    register_post_type(self::CPT, array(
      'labels' => array(
        'name'               => 'Rebate Requests',
        'singular_name'      => 'Rebate Request',
        'menu_name'          => 'Rebate Requests',
        'name_admin_bar'     => 'Rebate Request',
        'edit_item'          => 'View Rebate Request',
        'view_item'          => 'View Rebate Request',
        'search_items'       => 'Search Rebate Requests',
        'not_found'          => 'No rebate requests found',
        'not_found_in_trash' => 'No rebate requests found in Trash',
      ),
      'public'              => false,
      'publicly_queryable'  => false,
      'show_ui'             => true,
      'show_in_menu'        => true,
      'show_in_admin_bar'   => false,
      'show_in_nav_menus'   => false,
      'can_export'          => true,
      'menu_position'       => 28,
      'menu_icon'           => 'dashicons-awards',
      'supports'            => array('title'),
      'capability_type'     => 'post',
      'map_meta_cap'        => true,
      'has_archive'         => false,
      'rewrite'             => false,
      'query_var'           => false,
      'exclude_from_search' => true,
    ));
  }

  public function include_all_rebate_request_statuses($query) {
    if (!is_admin() || !$query->is_main_query()) {
      return;
    }

    $post_type = $query->get('post_type');

    if ($post_type === self::CPT) {
      $query->set('post_status', array('publish', 'private', 'draft', 'pending'));
    }
  }

  public function remove_add_new_submenu() {
    global $submenu;

    $menu_key = 'edit.php?post_type=' . self::CPT;

    if (isset($submenu[$menu_key])) {
      foreach ($submenu[$menu_key] as $key => $item) {
        if (isset($item[2]) && $item[2] === 'post-new.php?post_type=' . self::CPT) {
          unset($submenu[$menu_key][$key]);
        }
      }
    }
  }

  public function register_assets() {
    $this->register_asset_handles();

    if ($this->should_load_assets()) {
      $this->enqueue_assets();
    }
  }

  private function register_asset_handles() {
    if ($this->assets_registered) {
      return;
    }

    wp_register_style('ion-rebate-request-form-style', false, array(), self::VERSION);

    wp_register_script(
      'google-recaptcha-v3',
      'https://www.google.com/recaptcha/api.js?render=' . rawurlencode(self::RECAPTCHA_SITE_KEY),
      array(),
      null,
      true
    );

    wp_register_script('ion-rebate-request-form-script', false, array('google-recaptcha-v3'), self::VERSION, true);

    $this->assets_registered = true;
  }

  private function enqueue_assets() {
    if ($this->assets_enqueued) {
      return;
    }

    $this->register_asset_handles();

    wp_enqueue_style('ion-rebate-request-form-style');
    wp_add_inline_style('ion-rebate-request-form-style', $this->get_css());

    wp_enqueue_script('google-recaptcha-v3');
    wp_enqueue_script('ion-rebate-request-form-script');

    wp_localize_script('ion-rebate-request-form-script', 'IonRebateRequestForm', array(
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'recaptchaSiteKey' => self::RECAPTCHA_SITE_KEY,
      'recaptchaAction' => self::RECAPTCHA_ACTION,
      'recaptchaMinScore' => self::RECAPTCHA_MIN_SCORE,
    ));

    wp_add_inline_script('ion-rebate-request-form-script', $this->get_js());

    $this->assets_enqueued = true;
  }

  private function should_load_assets() {
    if (is_admin()) {
      return false;
    }

    if (is_page(array('rebate', 'hplc-rebate', 'rebate-request', 'submit-rebate-request'))) {
      return true;
    }

    if (!is_singular()) {
      return false;
    }

    global $post;

    if (!$post || empty($post->post_content)) {
      return false;
    }

    return has_shortcode($post->post_content, 'ion_rebate_form');
  }

  public function ajax_refresh_nonce() {
    $this->clean_output_buffers();

    wp_send_json_success(array(
      'nonce' => wp_create_nonce(self::NONCE_ACTION),
      'time'  => time(),
    ));
  }

  public function render_shortcode() {
    $this->enqueue_assets();

    if (
      $_SERVER['REQUEST_METHOD'] === 'POST' &&
      isset($_POST['ion_rebate_request_submit']) &&
      !isset($_POST['ion_rebate_ajax'])
    ) {
      $result = $this->process_submission($_POST, $_FILES);
      $this->notice = is_wp_error($result)
        ? array('type' => 'error', 'message' => $result->get_error_message())
        : array('type' => 'success', 'message' => $result['message']);
    }

    ob_start();
    ?>
    <div id="rebate-form" class="ion-rebate-form-card">
      <div class="ion-rebate-form-head">
        <div class="ion-rebate-form-icon" aria-hidden="true">
          <svg viewBox="0 0 64 64" fill="none">
            <path d="M32 8L52 18V32C52 44 43.8 53.4 32 57C20.2 53.4 12 44 12 32V18L32 8Z" stroke="currentColor" stroke-width="5" stroke-linejoin="round"></path>
            <path d="M23 33L29.5 39.5L42 25" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"></path>
          </svg>
        </div>
        <span class="ion-rebate-kicker">HPLC Rebate Request</span>
        <h2>Submit Rebate Request</h2>
        <p>Please complete the form below. Required fields are marked with an asterisk. Uploads are optional.</p>
      </div>

      <div class="ion-rebate-alert<?php echo !empty($this->notice) ? ' is-visible is-' . esc_attr($this->notice['type']) : ''; ?>" data-ion-rebate-alert>
        <?php echo !empty($this->notice) ? esc_html($this->notice['message']) : ''; ?>
      </div>

      <form
        class="ion-rebate-form"
        data-ion-rebate-form
        method="post"
        action="<?php echo esc_url($this->get_current_url()); ?>#rebate-form"
        enctype="multipart/form-data"
        novalidate
      >
        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

        <input type="hidden" name="ion_rebate_started_at" value="<?php echo esc_attr(time()); ?>">
        <input type="hidden" name="ion_rebate_ajax" value="0">
        <input type="hidden" name="ion_rebate_page_url" value="<?php echo esc_url($this->get_current_url()); ?>">
        <input type="hidden" name="ion_rebate_recaptcha_token" value="">
        <input type="hidden" name="ion_rebate_recaptcha_action" value="<?php echo esc_attr(self::RECAPTCHA_ACTION); ?>">

        <div class="ion-rebate-hp" aria-hidden="true">
          <label for="ion_rebate_company_url">Company URL</label>
          <input type="text" id="ion_rebate_company_url" name="ion_rebate_company_url" value="" tabindex="-1" autocomplete="off">
        </div>

        <div class="ion-rebate-grid">
          <div class="ion-rebate-field">
            <label for="ion_rebate_name">Name <span>*</span></label>
            <input type="text" id="ion_rebate_name" name="ion_rebate_name" required>
          </div>

          <div class="ion-rebate-field">
            <label for="ion_rebate_order_number">Order Number <span>*</span></label>
            <input type="text" id="ion_rebate_order_number" name="ion_rebate_order_number" required>
          </div>
        </div>

        <div class="ion-rebate-field">
          <label for="ion_rebate_email">Email <span>*</span></label>
          <input type="email" id="ion_rebate_email" name="ion_rebate_email" required>
        </div>

        <div class="ion-rebate-field">
          <label for="ion_rebate_testing_lab_company">Testing Lab Company <span>*</span></label>
          <input type="text" id="ion_rebate_testing_lab_company" name="ion_rebate_testing_lab_company" placeholder="Type the testing lab company name" required>
          <small>Example: Freedom Diagnostics, Janoshik, Chromate, Eurofins, or the lab used for the HPLC test.</small>
        </div>

        <div class="ion-rebate-field">
          <label for="ion_rebate_results_link">Test Results Link</label>
          <input type="url" id="ion_rebate_results_link" name="ion_rebate_results_link" placeholder="https://...">
          <small>Optional: paste the result or verification link from the testing facility if available.</small>
        </div>

        <div class="ion-rebate-field">
          <label for="ion_rebate_results_file">Optional COA / Test Result File</label>
          <input type="file" id="ion_rebate_results_file" name="ion_rebate_results_file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
          <small>Optional: accepted file types are JPG, PNG, WEBP, or PDF. Max 8 MB.</small>
        </div>

        <div class="ion-rebate-field">
          <label for="ion_rebate_photos">Optional Pre-Shipment Photo(s)</label>
          <input type="file" id="ion_rebate_photos" name="ion_rebate_photos[]" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" multiple>
          <small>Optional: upload product, package, batch, or cap color photos if helpful. Max 8 MB each.</small>
        </div>

        <div class="ion-rebate-field">
          <label for="ion_rebate_notes">Additional Notes</label>
          <textarea id="ion_rebate_notes" name="ion_rebate_notes" rows="5"></textarea>
        </div>
<label class="ion-rebate-agree ion-rebate-field" for="ion_rebate_agree">
          <span class="ion-rebate-agree-row">
            <input type="checkbox" id="ion_rebate_agree" name="ion_rebate_agree" value="1" required>
            <span>I understand and agree that rebate approval is subject to Ion Peptide review, eligibility requirements, and submitted information verification. <strong>*</strong></span>
          </span>
        </label>

        <button class="ion-rebate-submit" type="submit" name="ion_rebate_request_submit" value="1" data-ion-rebate-submit>
          Submit Rebate Request
        </button>
      </form>
    </div>
    <?php

    return ob_get_clean();
  }

  public function ajax_submit() {
    $this->clean_output_buffers();

    $result = $this->process_submission($_POST, $_FILES);

    if (is_wp_error($result)) {
      wp_send_json_error(array(
        'message' => $result->get_error_message(),
      ), 200);
    }

    wp_send_json_success($result, 200);
  }

  private function process_submission($post, $files) {
    $honeypot = isset($post['ion_rebate_company_url'])
      ? trim((string) wp_unslash($post['ion_rebate_company_url']))
      : '';

    if ($honeypot !== '') {
      return array(
        'message' => 'Your rebate request has been submitted.',
        'bot'     => true,
      );
    }

    $started_at = isset($post['ion_rebate_started_at']) ? absint($post['ion_rebate_started_at']) : 0;
    $nonce = isset($post[self::NONCE_NAME]) ? sanitize_text_field(wp_unslash($post[self::NONCE_NAME])) : '';
    $nonce_ok = $nonce && wp_verify_nonce($nonce, self::NONCE_ACTION);

    /*
     * Cache-safe security behavior:
     * This mirrors the working return request plugin. We refresh the nonce before AJAX submission,
     * but cached pages can still cause stale nonce issues. Do not show "Security check failed" to customers.
     * Honeypot, validation, upload checks, spam checks, and rate limiting remain active.
     */
    $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
      ? trim((string) wp_unslash($_SERVER['HTTP_USER_AGENT']))
      : '';

    if ($user_agent === '') {
      return array(
        'message' => 'Your rebate request has been submitted.',
        'bot'     => true,
      );
    }

    $recaptcha = $this->verify_recaptcha($post);
    $recaptcha_status = is_wp_error($recaptcha) ? 'Review Required' : 'Passed';

    $name = isset($post['ion_rebate_name']) ? sanitize_text_field(wp_unslash($post['ion_rebate_name'])) : '';
    $order_number = isset($post['ion_rebate_order_number']) ? sanitize_text_field(wp_unslash($post['ion_rebate_order_number'])) : '';
    $email = isset($post['ion_rebate_email']) ? sanitize_email(wp_unslash($post['ion_rebate_email'])) : '';
    $testing_lab_company = isset($post['ion_rebate_testing_lab_company']) ? sanitize_text_field(wp_unslash($post['ion_rebate_testing_lab_company'])) : '';
    $results_link = isset($post['ion_rebate_results_link']) ? esc_url_raw(wp_unslash($post['ion_rebate_results_link'])) : '';
    $agree = isset($post['ion_rebate_agree']) ? sanitize_text_field(wp_unslash($post['ion_rebate_agree'])) : '';
    $notes = isset($post['ion_rebate_notes']) ? sanitize_textarea_field(wp_unslash($post['ion_rebate_notes'])) : '';
    $submitted_from = isset($post['ion_rebate_page_url']) ? esc_url_raw(wp_unslash($post['ion_rebate_page_url'])) : $this->get_current_url();

    if (!$name || !$order_number || !$email || !$testing_lab_company || !$agree) {
      return new WP_Error('missing_fields', 'Please complete all required fields and confirm the agreement before submitting.');
    }

    if (!is_email($email)) {
      return new WP_Error('bad_email', 'Please enter a valid email address.');
    }

    if ($this->is_disposable_email($email)) {
      return new WP_Error('temp_email', 'Please use a permanent email address. Temporary email addresses are not accepted.');
    }

    if ($results_link && !$this->valid_url($results_link)) {
      return new WP_Error('bad_link', 'Please enter a valid test results link starting with http or https.');
    }

    if ($this->looks_like_spam($notes . "\n" . $results_link)) {
      return new WP_Error('spam_content', 'Please remove extra links or spam-like content and try again.');
    }

    if (!$this->rate_limit_ok($email)) {
      return new WP_Error('rate_limited', 'Please wait a few minutes before submitting another request.');
    }

    $results_upload = $this->handle_single_upload($files, 'ion_rebate_results_file', 'COA / test result file');

    if (is_wp_error($results_upload)) {
      return $results_upload;
    }

    $photo_uploads = $this->handle_multiple_uploads($files, 'ion_rebate_photos', 'pre-shipment photo');

    if (is_wp_error($photo_uploads)) {
      return $photo_uploads;
    }

    $data = array(
      'name'                   => $name,
      'order_number'           => $order_number,
      'email'                  => $email,
      'testing_lab_company'    => $testing_lab_company,
      'results_link'           => $results_link,
      'agreement'              => $agree ? 'Agreed' : '',
      'results_attachment_id'  => isset($results_upload['attachment_id']) ? absint($results_upload['attachment_id']) : 0,
      'results_attachment_url' => isset($results_upload['url']) ? esc_url_raw($results_upload['url']) : '',
      'photo_attachment_ids'   => wp_list_pluck($photo_uploads, 'attachment_id'),
      'photo_attachment_urls'  => wp_list_pluck($photo_uploads, 'url'),
      'notes'                  => $notes,
      'submitted_from'         => $submitted_from,
      'ip_address'             => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'Unknown',
      'user_agent'             => $user_agent,
      'recaptcha_status'       => $recaptcha_status,
    );

    $request_id = $this->save_request($data);

    if (is_wp_error($request_id)) {
      return new WP_Error('save_failed', 'The rebate request could not be saved to the admin dashboard. Please try again or contact support.');
    }

    $email_sent = $this->send_notifications($data, $request_id);

    if ($request_id) {
      update_post_meta($request_id, '_ion_rebate_email_status', $email_sent ? 'Sent' : 'Failed');
      update_post_meta($request_id, '_ion_rebate_email_sent_at', $email_sent ? current_time('mysql') : '');
    }

    if (!$email_sent) {
      return array(
        'message' => 'Thank you. Your rebate request has been submitted. Our support team will review your request and contact you if more information is needed.',
        'request_id' => $request_id,
      );
    }

    return array(
      'message' => 'Thank you. Your rebate request has been submitted. Our support team will review your request and contact you if more information is needed.',
      'request_id' => $request_id,
    );
  }

  private function handle_single_upload($files, $field, $label) {
    if (
      !isset($files[$field]) ||
      !isset($files[$field]['name']) ||
      empty($files[$field]['name'])
    ) {
      return array(
        'attachment_id' => 0,
        'url' => '',
      );
    }

    return $this->process_upload_file($files[$field], $label);
  }

  private function handle_multiple_uploads($files, $field, $label) {
    if (
      !isset($files[$field]) ||
      !isset($files[$field]['name']) ||
      empty($files[$field]['name'])
    ) {
      return array();
    }

    $normalized = $this->normalize_files_array($files[$field]);
    $uploads = array();

    foreach ($normalized as $index => $file) {
      if (empty($file['name'])) {
        continue;
      }

      $upload = $this->process_upload_file($file, $label . ' #' . ($index + 1));

      if (is_wp_error($upload)) {
        return $upload;
      }

      $uploads[] = $upload;
    }

    return $uploads;
  }

  private function normalize_files_array($files) {
    if (!is_array($files['name'])) {
      return array($files);
    }

    $normalized = array();

    foreach ($files['name'] as $key => $name) {
      $normalized[] = array(
        'name'     => $name,
        'type'     => isset($files['type'][$key]) ? $files['type'][$key] : '',
        'tmp_name' => isset($files['tmp_name'][$key]) ? $files['tmp_name'][$key] : '',
        'error'    => isset($files['error'][$key]) ? $files['error'][$key] : 0,
        'size'     => isset($files['size'][$key]) ? $files['size'][$key] : 0,
      );
    }

    return $normalized;
  }

  private function process_upload_file($file, $label) {
    if (!empty($file['error'])) {
      return new WP_Error('upload_error', 'The uploaded ' . $label . ' could not be processed. Please try again.');
    }

    if (empty($file['size']) || $file['size'] > self::MAX_UPLOAD_BYTES) {
      return new WP_Error('upload_size', 'The uploaded ' . $label . ' is too large. Maximum file size is 8 MB.');
    }

    $allowed_mimes = array(
      'jpg|jpeg' => 'image/jpeg',
      'png'      => 'image/png',
      'webp'     => 'image/webp',
      'pdf'      => 'application/pdf',
    );

    $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);

    if (empty($check['type']) || !in_array($check['type'], $allowed_mimes, true)) {
      return new WP_Error('upload_type', 'Invalid file type for ' . $label . '. Please upload JPG, PNG, WEBP, or PDF only.');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $upload = wp_handle_upload($file, array(
      'test_form' => false,
      'mimes' => $allowed_mimes,
    ));

    if (!empty($upload['error'])) {
      return new WP_Error('upload_failed', $upload['error']);
    }

    $attachment = array(
      'post_mime_type' => $upload['type'],
      'post_title'     => sanitize_file_name(pathinfo($upload['file'], PATHINFO_FILENAME)),
      'post_content'   => '',
      'post_status'    => 'inherit',
    );

    $attachment_id = wp_insert_attachment($attachment, $upload['file']);

    if (!is_wp_error($attachment_id)) {
      $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
      wp_update_attachment_metadata($attachment_id, $metadata);
    }

    return array(
      'attachment_id' => is_wp_error($attachment_id) ? 0 : absint($attachment_id),
      'url' => esc_url_raw($upload['url']),
    );
  }

  private function save_request($data) {
    $title = sprintf(
      'Rebate Request - Order %s - %s',
      $data['order_number'],
      current_time('M j, Y g:i A')
    );

    if (!post_type_exists(self::CPT)) {
      $this->register_post_type();
    }

    $content = sprintf(
      "Name: %s\nEmail: %s\nOrder Number: %s\nTesting Lab Company: %s\nTest Results Link: %s\nSubmitted At: %s",
      $data['name'],
      $data['email'],
      $data['order_number'],
      $data['testing_lab_company'],
      $data['results_link'] ? $data['results_link'] : 'Not provided',
      current_time('mysql')
    );

    $post_id = wp_insert_post(array(
      'post_type'      => self::CPT,
      'post_status'    => 'publish',
      'post_title'     => sanitize_text_field($title),
      'post_content'   => sanitize_textarea_field($content),
      'post_author'    => get_current_user_id(),
      'comment_status' => 'closed',
      'ping_status'    => 'closed',
    ), true);

    if (is_wp_error($post_id)) {
      return $post_id;
    }

    $meta = array(
      '_ion_rebate_dashboard_saved'        => 'Yes',
      '_ion_rebate_dashboard_saved_at'     => current_time('mysql'),
      '_ion_rebate_status'                 => 'New',
      '_ion_rebate_name'                   => $data['name'],
      '_ion_rebate_order_number'           => $data['order_number'],
      '_ion_rebate_email'                  => $data['email'],
      '_ion_rebate_testing_lab_company'    => $data['testing_lab_company'],
      '_ion_rebate_results_link'           => $data['results_link'],
      '_ion_rebate_agreement'              => $data['agreement'],
      '_ion_rebate_results_attachment_id'  => $data['results_attachment_id'],
      '_ion_rebate_results_attachment_url' => $data['results_attachment_url'],
      '_ion_rebate_photo_attachment_ids'   => implode(',', array_map('absint', $data['photo_attachment_ids'])),
      '_ion_rebate_photo_attachment_urls'  => implode("\n", array_map('esc_url_raw', $data['photo_attachment_urls'])),
      '_ion_rebate_notes'                  => $data['notes'],
      '_ion_rebate_submitted_from'         => $data['submitted_from'],
      '_ion_rebate_ip_address'             => $data['ip_address'],
      '_ion_rebate_user_agent'             => $data['user_agent'],
      '_ion_rebate_recaptcha_status'       => isset($data['recaptcha_status']) ? $data['recaptcha_status'] : '',
      '_ion_rebate_submitted_at'           => current_time('mysql'),
      '_ion_rebate_email_status'           => 'Pending',
    );

    foreach ($meta as $key => $value) {
      update_post_meta($post_id, $key, $value);
    }

    return $post_id;
  }

  private function send_notifications($data, $request_id) {
    $admin_to = apply_filters('ion_rebate_request_recipient', 'support@ionpeptide.com');

    $subject = sprintf('HPLC Rebate Request - Order %s', $data['order_number']);

    $body = "New HPLC rebate request submitted:\n\n";
    $body .= "Name: {$data['name']}\n";
    $body .= "Email: {$data['email']}\n";
    $body .= "Order Number: {$data['order_number']}\n";
    $body .= "Testing Lab Company: {$data['testing_lab_company']}\n";
    $body .= "Test Results Link: " . ($data['results_link'] ? $data['results_link'] : 'Not provided') . "\n";
    $body .= "Agreement: {$data['agreement']}\n\n";
    $body .= "COA / Test Result File: " . ($data['results_attachment_url'] ? $data['results_attachment_url'] : 'None') . "\n";
    $body .= "Pre-Shipment Photo(s):\n" . (!empty($data['photo_attachment_urls']) ? implode("\n", $data['photo_attachment_urls']) : 'None') . "\n\n";
    $body .= "Additional Notes:\n" . ($data['notes'] ? $data['notes'] : 'None') . "\n\n";
    $body .= "Dashboard Entry: " . ($request_id ? admin_url('post.php?post=' . absint($request_id) . '&action=edit') : 'Not saved') . "\n";
    $body .= "Submitted From: {$data['submitted_from']}\n";
    $body .= "IP Address: {$data['ip_address']}\n";
    $body .= "reCAPTCHA Status: " . (isset($data['recaptcha_status']) ? $data['recaptcha_status'] : '') . "\n";

    $headers = array(
      'Content-Type: text/plain; charset=UTF-8',
      'Reply-To: ' . $data['name'] . ' <' . $data['email'] . '>',
    );

    $admin_sent = wp_mail($admin_to, $subject, $body, $headers);

    $customer_subject = 'We received your HPLC rebate request';
    $customer_body = "Hi {$data['name']},\n\n";
    $customer_body .= "We received your HPLC rebate request for order {$data['order_number']}.\n\n";
    $customer_body .= "Testing lab company submitted: {$data['testing_lab_company']}\n\n";
    $customer_body .= "Our support team will review the submitted details and eligibility requirements. Rebate approval is subject to review and is not automatic.\n\n";
    $customer_body .= "Thank you,\nIon Peptide Support";

    wp_mail($data['email'], $customer_subject, $customer_body, array('Content-Type: text/plain; charset=UTF-8'));

    return $admin_sent;
  }

  private function verify_recaptcha($post) {
    $token = isset($post['ion_rebate_recaptcha_token'])
      ? sanitize_text_field(wp_unslash($post['ion_rebate_recaptcha_token']))
      : '';

    $action = isset($post['ion_rebate_recaptcha_action'])
      ? sanitize_text_field(wp_unslash($post['ion_rebate_recaptcha_action']))
      : '';

    if (!$token) {
      return new WP_Error('recaptcha_missing', 'reCAPTCHA token missing. Saved for review.');
    }

    $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
      'timeout' => 15,
      'body' => array(
        'secret' => self::RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
      ),
    ));

    if (is_wp_error($response)) {
      return new WP_Error('recaptcha_unavailable', 'Google reCAPTCHA could not be reached. Saved for review.');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($body)) {
      return new WP_Error('recaptcha_bad_response', 'Google reCAPTCHA returned an invalid response. Saved for review.');
    }

    if (empty($body['success'])) {
      $codes = !empty($body['error-codes']) && is_array($body['error-codes'])
        ? implode(', ', array_map('sanitize_text_field', $body['error-codes']))
        : 'unknown-error';

      return new WP_Error('recaptcha_failed', 'Google reCAPTCHA failed: ' . $codes . '. Saved for review.');
    }

    if (!empty($body['action']) && $action && $body['action'] !== $action) {
      return new WP_Error(
        'recaptcha_action_failed',
        'reCAPTCHA action mismatch. Expected ' . $action . ', got ' . sanitize_text_field($body['action']) . '. Saved for review.'
      );
    }

    $score = isset($body['score']) ? (float) $body['score'] : 0.0;

    if ($score < (float) self::RECAPTCHA_MIN_SCORE) {
      return new WP_Error('recaptcha_low_score', 'reCAPTCHA low score: ' . $score . '. Saved for review.');
    }

    return array(
      'success' => true,
      'score' => $score,
      'action' => isset($body['action']) ? sanitize_text_field($body['action']) : '',
      'hostname' => isset($body['hostname']) ? sanitize_text_field($body['hostname']) : '',
    );
  }

  private function valid_url($url) {
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
      return false;
    }

    $scheme = wp_parse_url($url, PHP_URL_SCHEME);

    return in_array($scheme, array('http', 'https'), true);
  }

  private function is_disposable_email($email) {
    $parts = explode('@', strtolower($email));
    $domain = end($parts);

    if (!$domain) {
      return true;
    }

    $blocked_domains = array(
      '10minutemail.com',
      '10minutemail.net',
      '20minutemail.com',
      '33mail.com',
      'anonbox.net',
      'burnermail.io',
      'byom.de',
      'dispostable.com',
      'emailondeck.com',
      'fakeinbox.com',
      'getnada.com',
      'guerrillamail.com',
      'guerrillamail.net',
      'guerrillamail.org',
      'hmamail.com',
      'inboxbear.com',
      'maildrop.cc',
      'mailinator.com',
      'mailnesia.com',
      'mintemail.com',
      'moakt.com',
      'mytemp.email',
      'sharklasers.com',
      'spamgourmet.com',
      'temp-mail.org',
      'tempmail.com',
      'tempmail.net',
      'throwawaymail.com',
      'trashmail.com',
      'yopmail.com',
    );

    $blocked_domains = apply_filters('ion_rebate_blocked_email_domains', $blocked_domains);

    if (in_array($domain, $blocked_domains, true)) {
      return true;
    }

    $blocked_keywords = array(
      '10minute',
      'disposable',
      'guerrilla',
      'mailinator',
      'tempmail',
      'temp-mail',
      'throwaway',
      'trashmail',
      'yopmail',
    );

    foreach ($blocked_keywords as $keyword) {
      if (strpos($domain, $keyword) !== false) {
        return true;
      }
    }

    return false;
  }

  private function looks_like_spam($text) {
    $text = strtolower($text);

    preg_match_all('/https?:\/\//i', $text, $matches);
    $url_count = isset($matches[0]) ? count($matches[0]) : 0;

    if ($url_count >= 4) {
      return true;
    }

    $spam_terms = array(
      '[url=',
      '<a href',
      'viagra',
      'casino',
      'crypto giveaway',
      'free money',
      'seo backlinks',
      'guest post',
    );

    foreach ($spam_terms as $term) {
      if (strpos($text, $term) !== false) {
        return true;
      }
    }

    return false;
  }

  private function rate_limit_ok($email) {
    $ip = isset($_SERVER['REMOTE_ADDR'])
      ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
      : 'unknown';

    $ip_key = 'ion_rebate_rate_ip_' . md5($ip);
    $email_key = 'ion_rebate_rate_email_' . md5(strtolower($email));

    $ip_count = (int) get_transient($ip_key);
    $email_count = (int) get_transient($email_key);

    if ($ip_count >= 5 || $email_count >= 3) {
      return false;
    }

    set_transient($ip_key, $ip_count + 1, HOUR_IN_SECONDS);
    set_transient($email_key, $email_count + 1, HOUR_IN_SECONDS);

    return true;
  }

  public function add_meta_boxes() {
    add_meta_box(
      'ion_rebate_request_details',
      'Rebate Request Details',
      array($this, 'render_details_meta_box'),
      self::CPT,
      'normal',
      'high'
    );
  }

  public function render_details_meta_box($post) {
    $fields = array(
      'Submitted At'            => '_ion_rebate_submitted_at',
      'Dashboard Saved'         => '_ion_rebate_dashboard_saved',
      'Dashboard Saved At'      => '_ion_rebate_dashboard_saved_at',
      'Request Status'          => '_ion_rebate_status',
      'Name'                    => '_ion_rebate_name',
      'Email'                   => '_ion_rebate_email',
      'Order Number'            => '_ion_rebate_order_number',
      'Testing Lab Company'     => '_ion_rebate_testing_lab_company',
      'Test Results Link'       => '_ion_rebate_results_link',
      'Agreement'               => '_ion_rebate_agreement',
      'COA / Test Result File'  => '_ion_rebate_results_attachment_url',
      'Pre-Shipment Photo URLs' => '_ion_rebate_photo_attachment_urls',
      'Additional Notes'        => '_ion_rebate_notes',
      'Email Status'            => '_ion_rebate_email_status',
      'Email Sent At'           => '_ion_rebate_email_sent_at',
      'Submitted From'          => '_ion_rebate_submitted_from',
      'IP Address'              => '_ion_rebate_ip_address',
      'User Agent'              => '_ion_rebate_user_agent',
      'reCAPTCHA Status'        => '_ion_rebate_recaptcha_status',
    );

    echo '<style>
      .ion-rebate-admin-table { width: 100%; border-collapse: collapse; }
      .ion-rebate-admin-table th { width: 230px; text-align: left; vertical-align: top; padding: 12px; background: #f6f7f7; border: 1px solid #dcdcde; font-weight: 700; }
      .ion-rebate-admin-table td { padding: 12px; border: 1px solid #dcdcde; white-space: pre-wrap; }
    </style>';

    echo '<table class="ion-rebate-admin-table"><tbody>';

    foreach ($fields as $label => $meta_key) {
      $value = get_post_meta($post->ID, $meta_key, true);

      if ($label === 'Email' && $value) {
        $display = '<a href="mailto:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
      } elseif (in_array($label, array('Test Results Link', 'COA / Test Result File', 'Submitted From'), true) && $value) {
        $display = '<a href="' . esc_url($value) . '" target="_blank" rel="noopener noreferrer">' . esc_html($value) . '</a>';
      } elseif ($label === 'Pre-Shipment Photo URLs' && $value) {
        $urls = preg_split('/\r\n|\r|\n/', $value);
        $links = array();

        foreach ($urls as $url) {
          $url = trim($url);

          if ($url) {
            $links[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a>';
          }
        }

        $display = $links ? implode('<br>', $links) : '—';
      } else {
        $display = esc_html($value ? $value : '—');
      }

      echo '<tr>';
      echo '<th>' . esc_html($label) . '</th>';
      echo '<td>' . $display . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
  }

  public function admin_columns($columns) {
    return array(
      'cb'           => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />',
      'title'        => 'Request',
      'order_number' => 'Order #',
      'name'         => 'Name',
      'email'        => 'Email',
      'lab'          => 'Testing Lab',
      'request_status' => 'Request Status',
      'email_status' => 'Email Status',
      'date'         => 'Date',
    );
  }

  public function admin_column_content($column, $post_id) {
    switch ($column) {
      case 'order_number':
        echo esc_html(get_post_meta($post_id, '_ion_rebate_order_number', true));
        break;

      case 'name':
        echo esc_html(get_post_meta($post_id, '_ion_rebate_name', true));
        break;

      case 'email':
        $email = get_post_meta($post_id, '_ion_rebate_email', true);
        if ($email) {
          echo '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
        }
        break;

      case 'lab':
        echo esc_html(get_post_meta($post_id, '_ion_rebate_testing_lab_company', true));
        break;

      case 'request_status':
        echo esc_html(get_post_meta($post_id, '_ion_rebate_status', true));
        break;

      case 'email_status':
        echo esc_html(get_post_meta($post_id, '_ion_rebate_email_status', true));
        break;
    }
  }

  private function clean_output_buffers() {
    while (ob_get_level() > 0) {
      ob_end_clean();
    }
  }

  private function get_current_url() {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

    $url = $scheme . $host . $uri;

    return remove_query_arg(array(
      'ion_rebate_status',
      'ion_rebate_error',
    ), $url);
  }

  private function get_css() {
    return '
      .ion-rebate-form-card,
      .ion-rebate-form-card * {
        box-sizing: border-box;
      }

      .ion-rebate-form-card {
        --ion-blue: #007eff;
        --ion-navy: #0d0d67;
        --ion-text: #333333;
        --ion-muted: #626262;
        --ion-font: "Poppins", "Inter", Arial, sans-serif;

        width: 100%;
        padding: 30px;
        border-radius: 28px;
        background:
          radial-gradient(circle at 96% 4%, rgba(0, 126, 255, 0.10), transparent 34%),
          linear-gradient(135deg, #ffffff 0%, #fbfdff 58%, #f4f8ff 100%);
        border: 1px solid rgba(0, 126, 255, 0.16);
        box-shadow: 0 18px 45px rgba(13, 13, 103, 0.10);
        color: var(--ion-text);
        font-family: var(--ion-font);
      }

      .ion-rebate-form-head {
        margin-bottom: 22px;
      }

      .ion-rebate-form-icon {
        width: 82px;
        height: 82px;
        margin: 0 0 22px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 26px;
        background: linear-gradient(145deg, #141489 0%, #07075f 100%);
        color: #ffffff;
        box-shadow:
          0 18px 34px rgba(13, 13, 103, 0.22),
          inset 0 1px 0 rgba(255, 255, 255, 0.14);
      }

      .ion-rebate-form-icon svg {
        width: 46px;
        height: 46px;
        display: block;
      }

      .ion-rebate-kicker {
        display: inline-flex;
        align-items: center;
        min-height: 30px;
        padding: 4px 11px;
        margin-bottom: 12px;
        border-radius: 7px;
        background: rgba(0, 126, 255, 0.12);
        color: var(--ion-blue);
        font-size: 12px;
        line-height: 1;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .ion-rebate-form-card h2 {
        margin: 0 0 10px;
        color: var(--ion-navy);
        font-size: clamp(28px, 3vw, 38px);
        line-height: 1.08;
        font-weight: 900;
        letter-spacing: -0.045em;
      }

      .ion-rebate-form-card p {
        margin: 0;
        color: var(--ion-muted);
        font-size: 15px;
        line-height: 1.7;
      }

      .ion-rebate-alert {
        display: none;
        margin: 0 0 18px;
        padding: 15px 16px;
        border-radius: 16px;
        font-size: 14px;
        line-height: 1.55;
        font-weight: 800;
      }

      .ion-rebate-alert.is-visible {
        display: block;
      }

      .ion-rebate-alert.is-success {
        background: rgba(0, 126, 255, 0.10);
        border: 1px solid rgba(0, 126, 255, 0.20);
        color: var(--ion-navy);
      }

      .ion-rebate-alert.is-error {
        background: rgba(220, 38, 38, 0.08);
        border: 1px solid rgba(220, 38, 38, 0.18);
        color: #8a1f1f;
      }

      .ion-rebate-form {
        display: grid;
        gap: 16px;
      }

      .ion-rebate-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
      }

      .ion-rebate-field {
        display: grid;
        gap: 7px;
      }

      .ion-rebate-field label {
        color: var(--ion-navy);
        font-size: 13px;
        line-height: 1.3;
        font-weight: 900;
      }

      .ion-rebate-field label span {
        color: var(--ion-blue);
      }

      .ion-rebate-field small {
        color: var(--ion-muted);
        font-size: 12px;
        line-height: 1.45;
        font-weight: 700;
      }

      .ion-rebate-field input,
      .ion-rebate-field select,
      .ion-rebate-field textarea {
        width: 100%;
        min-height: 48px;
        padding: 13px 14px;
        border-radius: 14px;
        border: 1px solid rgba(0, 126, 255, 0.18);
        background: rgba(255, 255, 255, 0.92);
        color: var(--ion-text);
        font-family: var(--ion-font);
        font-size: 14px;
        line-height: 1.4;
        outline: none;
        transition: border-color 0.18s ease, box-shadow 0.18s ease;
      }

      .ion-rebate-field input[type="file"] {
        padding: 11px;
        background: #ffffff;
      }

      .ion-rebate-field textarea {
        resize: vertical;
      }

      .ion-rebate-field input:focus,
      .ion-rebate-field select:focus,
      .ion-rebate-field textarea:focus {
        border-color: var(--ion-blue);
        box-shadow: 0 0 0 4px rgba(0, 126, 255, 0.10);
      }

      .ion-rebate-field-error {
        margin-top: 5px;
        color: #8a1f1f;
        font-size: 12px;
        line-height: 1.45;
        font-weight: 800;
      }

      .ion-rebate-field.has-error input,
      .ion-rebate-field.has-error select,
      .ion-rebate-field.has-error textarea {
        border-color: rgba(220, 38, 38, 0.55) !important;
        box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.08) !important;
      }
      .ion-rebate-agree {
        padding: 14px;
        border-radius: 18px;
        background: rgba(247, 249, 255, 0.92);
        border: 1px solid rgba(0, 126, 255, 0.12);
        cursor: pointer;
      }

      .ion-rebate-agree-row {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        color: var(--ion-navy);
        font-size: 13px;
        line-height: 1.55;
        font-weight: 800;
      }

      .ion-rebate-agree-row input {
        width: auto !important;
        min-height: auto !important;
        margin-top: 3px;
        box-shadow: none !important;
      }

      .ion-rebate-agree-row strong {
        color: var(--ion-blue);
      }

      .ion-rebate-agree.has-error {
        border-color: rgba(220, 38, 38, 0.55) !important;
        box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.08) !important;
      }

      .ion-rebate-submit {
        min-height: 54px;
        padding: 0 26px;
        border: 0;
        border-radius: 999px;
        background: var(--ion-blue);
        color: #ffffff;
        font-family: var(--ion-font);
        font-size: 15px;
        font-weight: 900;
        cursor: pointer;
        box-shadow: 0 12px 24px rgba(0, 126, 255, 0.18);
        transition: transform 0.18s ease, background-color 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
      }

      .ion-rebate-submit:hover {
        background: var(--ion-navy);
        transform: translateY(-1px);
        box-shadow: 0 16px 30px rgba(13, 13, 103, 0.20);
      }

      .ion-rebate-submit:disabled {
        opacity: 0.72;
        cursor: not-allowed;
        transform: none;
      }

      .ion-rebate-hp {
        position: absolute !important;
        left: -99999px !important;
        top: auto !important;
        width: 1px !important;
        height: 1px !important;
        overflow: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
      }

      @media (max-width: 760px) {
        .ion-rebate-form-card {
          padding: 24px 20px;
          border-radius: 22px;
        }

        .ion-rebate-grid {
          grid-template-columns: 1fr;
        }

        .ion-rebate-form-icon {
          width: 72px;
          height: 72px;
          border-radius: 23px;
          margin-bottom: 18px;
        }

        .ion-rebate-form-icon svg {
          width: 40px;
          height: 40px;
        }

        .ion-rebate-submit {
          width: 100%;
        }
      }
    ';
  }

  private function get_js() {
    return '
      (function () {
        var cfg = window.IonRebateRequestForm || {};

        function getAlert(form) {
          var card = form.closest(".ion-rebate-form-card");
          return card ? card.querySelector("[data-ion-rebate-alert]") : null;
        }

        function showAlert(form, type, message) {
          var alert = getAlert(form);
          if (!alert) {
            return;
          }

          alert.className = "ion-rebate-alert is-visible is-" + type;
          alert.textContent = message;
          alert.scrollIntoView({ behavior: "smooth", block: "center" });
        }

        function clearAlert(form) {
          var alert = getAlert(form);
          if (!alert) {
            return;
          }

          alert.className = "ion-rebate-alert";
          alert.textContent = "";
        }

        function clearFieldError(field) {
          if (!field) {
            return;
          }

          var wrapper = field.closest(".ion-rebate-field");
          if (!wrapper) {
            return;
          }

          wrapper.classList.remove("has-error");

          var old = wrapper.querySelector(".ion-rebate-field-error");
          if (old) {
            old.remove();
          }
        }

        function showFieldError(field, message) {
          if (!field) {
            return;
          }

          var wrapper = field.closest(".ion-rebate-field");
          if (!wrapper) {
            return;
          }

          wrapper.classList.add("has-error");

          var old = wrapper.querySelector(".ion-rebate-field-error");
          if (old) {
            old.remove();
          }

          var error = document.createElement("div");
          error.className = "ion-rebate-field-error";
          error.setAttribute("role", "alert");
          error.textContent = message;
          wrapper.appendChild(error);
        }

        function isValidEmail(value) {
          return /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(value);
        }

        function isValidUrl(value) {
          try {
            var url = new URL(value);
            return url.protocol === "http:" || url.protocol === "https:";
          } catch (error) {
            return false;
          }
        }

        function isDisposableEmail(email) {
          var domain = (email.split("@").pop() || "").toLowerCase();

          var blocked = [
            "10minutemail.com",
            "10minutemail.net",
            "20minutemail.com",
            "33mail.com",
            "anonbox.net",
            "burnermail.io",
            "dispostable.com",
            "emailondeck.com",
            "fakeinbox.com",
            "getnada.com",
            "guerrillamail.com",
            "guerrillamail.net",
            "guerrillamail.org",
            "hmamail.com",
            "inboxbear.com",
            "maildrop.cc",
            "mailinator.com",
            "mailnesia.com",
            "mintemail.com",
            "moakt.com",
            "mytemp.email",
            "sharklasers.com",
            "spamgourmet.com",
            "temp-mail.org",
            "tempmail.com",
            "tempmail.net",
            "throwawaymail.com",
            "trashmail.com",
            "yopmail.com"
          ];

          if (blocked.indexOf(domain) !== -1) {
            return true;
          }

          return [
            "10minute",
            "disposable",
            "guerrilla",
            "mailinator",
            "tempmail",
            "temp-mail",
            "throwaway",
            "trashmail",
            "yopmail"
          ].some(function (word) {
            return domain.indexOf(word) !== -1;
          });
        }

        function validateForm(form) {
          clearAlert(form);

          form.querySelectorAll("input, textarea, select").forEach(function (field) {
            clearFieldError(field);
          });

          var firstError = null;

          function required(name, message) {
            var field = form.querySelector("[name=\'" + name + "\']");
            if (!field) {
              return;
            }

            if (!String(field.value || "").trim()) {
              showFieldError(field, message);
              if (!firstError) {
                firstError = field;
              }
            }
          }

          required("ion_rebate_name", "Please enter your name.");
          required("ion_rebate_order_number", "Please enter your order number.");
          required("ion_rebate_email", "Please enter your email address.");
          required("ion_rebate_testing_lab_company", "Please enter the testing lab company name.");
          // Test Results Link is optional. Validate format only if provided.

          var email = form.querySelector("[name=\'ion_rebate_email\']");
          if (email && email.value.trim()) {
            if (!isValidEmail(email.value.trim())) {
              showFieldError(email, "Please enter a valid email address.");
              if (!firstError) {
                firstError = email;
              }
            } else if (isDisposableEmail(email.value.trim())) {
              showFieldError(email, "Please use a permanent email address. Temporary emails are not accepted.");
              if (!firstError) {
                firstError = email;
              }
            }
          }

          var resultsLink = form.querySelector("[name=\'ion_rebate_results_link\']");
          if (resultsLink && resultsLink.value.trim() && !isValidUrl(resultsLink.value.trim())) {
            showFieldError(resultsLink, "Please enter a valid URL starting with http or https.");
            if (!firstError) {
              firstError = resultsLink;
            }
          }

          var agree = form.querySelector("[name=\'ion_rebate_agree\']");
          if (agree && !agree.checked) {
            showFieldError(agree, "Please confirm that you understand and agree before submitting.");
            if (!firstError) {
              firstError = agree;
            }
          }

          var allowed = ["image/jpeg", "image/png", "image/webp", "application/pdf"];

          var resultsFile = form.querySelector("[name=\'ion_rebate_results_file\']");
          if (resultsFile && resultsFile.files && resultsFile.files.length) {
            var upload = resultsFile.files[0];

            if (allowed.indexOf(upload.type) === -1) {
              showFieldError(resultsFile, "Please upload JPG, PNG, WEBP, or PDF only.");
              if (!firstError) {
                firstError = resultsFile;
              }
            }

            if (upload.size > 8388608) {
              showFieldError(resultsFile, "Maximum file size is 8 MB.");
              if (!firstError) {
                firstError = resultsFile;
              }
            }
          }

          var photos = form.querySelector("[name=\'ion_rebate_photos[]\']");
          if (photos && photos.files && photos.files.length) {
            Array.prototype.forEach.call(photos.files, function (file) {
              if (allowed.indexOf(file.type) === -1) {
                showFieldError(photos, "Please upload JPG, PNG, WEBP, or PDF only.");
                if (!firstError) {
                  firstError = photos;
                }
              }

              if (file.size > 8388608) {
                showFieldError(photos, "Maximum file size is 8 MB per file.");
                if (!firstError) {
                  firstError = photos;
                }
              }
            });
          }

          if (firstError) {
            showAlert(form, "error", "Please fix the highlighted fields before submitting.");

            setTimeout(function () {
              firstError.scrollIntoView({ behavior: "smooth", block: "center" });
              if (typeof firstError.focus === "function") {
                firstError.focus({ preventScroll: true });
              }
            }, 80);

            return false;
          }

          return true;
        }
        function executeRecaptcha(form) {
          if (!cfg.recaptchaSiteKey) {
            return Promise.reject(new Error("Security verification is not configured. Please contact support."));
          }

          if (!window.grecaptcha || typeof window.grecaptcha.ready !== "function") {
            return Promise.reject(new Error("Security verification could not load. Please refresh the page and try again."));
          }

          return new Promise(function (resolve, reject) {
            window.grecaptcha.ready(function () {
              window.grecaptcha
                .execute(cfg.recaptchaSiteKey, { action: cfg.recaptchaAction || "ion_rebate_submit" })
                .then(function (token) {
                  if (!token) {
                    reject(new Error("Security verification could not complete. Please refresh and try again."));
                    return;
                  }

                  var tokenField = form.querySelector("[name=\"ion_rebate_recaptcha_token\"]");
                  var actionField = form.querySelector("[name=\"ion_rebate_recaptcha_action\"]");

                  if (tokenField) {
                    tokenField.value = token;
                  }

                  if (actionField) {
                    actionField.value = cfg.recaptchaAction || "ion_rebate_submit";
                  }
                  resolve(token);
                })
                .catch(function () {
                  reject(new Error("Security verification could not complete. Please refresh and try again."));
                });
            });
          });
        }

        function refreshNonce(form) {
          if (!cfg.ajaxUrl || !window.fetch) {
            return Promise.resolve("");
          }

          var data = new FormData();
          data.append("action", "ion_rebate_request_refresh_nonce");

          return fetch(cfg.ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            body: data
          })
            .then(function (response) {
              return response.json();
            })
            .then(function (json) {
              var nonce = json && json.success && json.data && json.data.nonce ? json.data.nonce : "";
              if (nonce) {
                var field = form.querySelector("[name=\'' . esc_js(self::NONCE_NAME) . '\']");
                if (field) {
                  field.value = nonce;
                }

              }

              return nonce;
            })
            .catch(function () {
              return "";
            });
        }

        function parseResponse(response) {
          return response.text().then(function (text) {
            var cleaned = (text || "").trim();

            if (cleaned === "0" || cleaned === "-1") {
              throw new Error("The form endpoint did not receive the submission correctly. Please refresh the page and try again.");
            }

            var firstBrace = cleaned.indexOf("{");
            var lastBrace = cleaned.lastIndexOf("}");

            if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
              cleaned = cleaned.substring(firstBrace, lastBrace + 1);
            }

            try {
              return JSON.parse(cleaned);
            } catch (error) {
              throw new Error("The server returned an unexpected response. Please try again.");
            }
          });
        }

        document.addEventListener("DOMContentLoaded", function () {
          document.querySelectorAll("[data-ion-rebate-form]").forEach(function (form) {
            refreshNonce(form);

            form.querySelectorAll("input, textarea, select").forEach(function (field) {
              field.addEventListener("input", function () {
                clearFieldError(field);
              });
              field.addEventListener("change", function () {
                clearFieldError(field);
              });
            });

            form.addEventListener("submit", function (event) {
              event.preventDefault();

              if (!validateForm(form)) {
                return;
              }

              var submit = form.querySelector("[data-ion-rebate-submit]");
              var originalText = submit ? submit.textContent : "Submit Rebate Request";

              if (submit) {
                submit.disabled = true;
                submit.textContent = "Submitting...";
              }

              refreshNonce(form)
                .then(function () {
                  return executeRecaptcha(form);
                })
                .then(function () {
                  var data = new FormData(form);
                  data.set("action", "ion_rebate_request_submit");
                  data.set("ion_rebate_ajax", "1");

                  return fetch(cfg.ajaxUrl, {
                    method: "POST",
                    credentials: "same-origin",
                    body: data
                  });
                })
                .then(parseResponse)
                .then(function (json) {
                  var message = json && json.data && json.data.message
                    ? json.data.message
                    : "Thank you. Your rebate request has been submitted.";

                  if (json && json.success) {
                    showAlert(form, "success", message);
                    form.reset();
                    refreshNonce(form);
                  } else {
                    showAlert(form, "error", message || "Something went wrong. Please try again.");
                  }
                })
                .catch(function (error) {
                  showAlert(form, "error", error.message || "Something went wrong. Please try again.");
                })
                .finally(function () {
                  if (submit) {
                    submit.disabled = false;
                    submit.textContent = originalText;
                  }
                });
            });
          });
        });
      })();
    ';
  }
}

Ion_Rebate_Request_Form_Clean_V246::instance();
