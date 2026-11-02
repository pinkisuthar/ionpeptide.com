<?php
/**
 * Single admin page controller - routes between Dashboard, Settings, Templates, Reports.
 *
 * Only ONE entry in POP's submenu. Internal navigation via icon tabs.
 *
 * @package POPRecoveryEmail\Admin
 */

declare(strict_types=1);

namespace POPRecoveryEmail\Admin;

defined('ABSPATH') || exit;

final class AdminController {

    public function register(): void {
        add_action('admin_menu', [$this, 'addMenuPage'], 30);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'registerSettings']);

        // AJAX handlers
        add_action('wp_ajax_prer_send_test_email', [$this, 'ajaxSendTestEmail']);
        add_action('wp_ajax_prer_save_template', [new TemplatePage(), 'ajaxSaveTemplate']);
        add_action('wp_ajax_prer_delete_template', [new TemplatePage(), 'ajaxDeleteTemplate']);
        add_action('wp_ajax_prer_set_active_template', [new TemplatePage(), 'ajaxSetActive']);
        add_action('wp_ajax_prer_preview_template', [new TemplatePage(), 'ajaxPreview']);
        add_action('wp_ajax_prer_duplicate_template', [new TemplatePage(), 'ajaxDuplicate']);
        add_action('wp_ajax_prer_get_log', [$this, 'ajaxGetLog']);
        add_action('wp_ajax_prer_refresh_dashboard', [$this, 'ajaxRefreshDashboard']);
        add_action('wp_ajax_prer_clear_queue', [$this, 'ajaxClearQueue']);
    }

    public function addMenuPage(): void {
        add_submenu_page(
            'payment-orchestrator',
            'Email Recovery',
            'Email Recovery',
            'manage_woocommerce',
            'pop-email-recovery',
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(string $hook): void {
        if (!str_contains($hook, 'pop-email-recovery')) {
            return;
        }
        wp_enqueue_style('prer-admin', PRER_PLUGIN_URL . 'assets/css/admin.css', [], PRER_VERSION);
        wp_enqueue_script('prer-admin', PRER_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], PRER_VERSION, true);
        wp_localize_script('prer-admin', 'prer', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('prer_admin'),
        ]);

        $tab = sanitize_key($_GET['tab'] ?? 'dashboard');
        if ($tab === 'templates' && (isset($_GET['edit']) || isset($_GET['new']))) {
            wp_enqueue_code_editor(['type' => 'text/html']);
        }
    }

    public function registerSettings(): void {
        foreach ([
            'prer_enabled'          => 'sanitize_text_field',
            'prer_email_delay'      => 'absint',
            'prer_non_retryable_delay' => 'absint',
            'prer_pending_enabled'  => 'sanitize_text_field',
            'prer_pending_delay'    => 'absint',
            'prer_onhold_cancel_after' => 'absint',
            'prer_min_order_value'  => 'floatval',
            'prer_from_name'        => 'sanitize_text_field',
            'prer_from_email'       => 'sanitize_email',
            'prer_cc_admin'         => 'sanitize_text_field',
            'prer_debug_log'        => 'sanitize_text_field',
            'prer_delete_data_on_uninstall' => 'sanitize_text_field',
            'prer_sequence_expiry'  => 'absint',
            'prer_unsub_expiry_days'=> 'absint',
            'prer_max_failures_24h' => 'absint',
            'prer_inline_cooldown'  => 'absint',
        ] as $name => $sanitize) {
            register_setting('prer_settings', $name, ['sanitize_callback' => $sanitize]);
        }
        register_setting('prer_settings', 'prer_categories', [
            'sanitize_callback' => fn($v) => is_array($v) ? array_map('sanitize_text_field', $v) : [],
        ]);
        // Parse comma-separated gateway text field into array
        add_action('update_option_prer_onhold_gateways_text', function() {
            // This won't fire because it's not a registered setting. Handle in pre_update instead.
        });
        register_setting('prer_settings', 'prer_onhold_gateways_text', [
            'sanitize_callback' => function($v) {
                $gateways = array_filter(array_map('trim', explode(',', sanitize_text_field($v))));
                update_option('prer_onhold_gateways', $gateways);
                return $v;
            },
        ]);
    }

    public function renderPage(): void {
        $tab = sanitize_key($_GET['tab'] ?? 'dashboard');
        echo '<div class="prer-wrap">';
        AdminNav::render($tab);

        match ($tab) {
            'settings'  => (new SettingsPage())->render(),
            'templates' => (new TemplatePage())->render(),
            'reports'   => (new ReportsPage())->render(),
            'help'      => (new HelpPage())->render(),
            default     => (new DashboardPage())->render(),
        };

        echo '</div>';
    }

    public function ajaxClearQueue(): void {
        check_ajax_referer('prer_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error('Denied'); }

        global $wpdb;
        $table = $wpdb->prefix . 'po_email_sequences';
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='pending'");
        $wpdb->query("UPDATE {$table} SET status='cancelled', cancelled_reason='manual_clear' WHERE status='pending'");

        \POPRecoveryEmail\Core\Logger::info('Queue manually cleared', ['cancelled_count' => $count]);
        wp_send_json_success(['cleared' => $count]);
    }

    public function ajaxRefreshDashboard(): void {
        check_ajax_referer('prer_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error('Denied'); }

        global $wpdb;
        $seq = $wpdb->prefix . 'po_email_sequences';

        $queue = $wpdb->get_results("SELECT order_id, customer_email, order_total, failure_code, failure_category, template_id, send_at, created_at FROM {$seq} WHERE status='pending' ORDER BY send_at ASC LIMIT 50");
        $recent = $wpdb->get_results("SELECT order_id, customer_email, order_total, failure_code, failure_category, status, cancelled_reason, sent_at, opened_at, clicked_at, recovered_at, created_at FROM {$seq} WHERE status != 'pending' ORDER BY COALESCE(sent_at, created_at) DESC LIMIT 15");

        ob_start();
        DashboardPage::renderQueueTable($queue);
        $queueHtml = ob_get_clean();

        ob_start();
        DashboardPage::renderRecentTable($recent);
        $recentHtml = ob_get_clean();

        wp_send_json_success([
            'queue_html'   => $queueHtml,
            'recent_html'  => $recentHtml,
            'queue_count'  => count($queue),
        ]);
    }

    public function ajaxGetLog(): void {
        check_ajax_referer('prer_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error('Permission denied'); }

        $file = sanitize_text_field($_POST['file'] ?? '');
        $logFiles = \POPRecoveryEmail\Core\Logger::getLogFiles();

        if ($file && isset($logFiles[$file])) {
            $content = \POPRecoveryEmail\Core\Logger::readLog($logFiles[$file], 300);
        } else {
            // Default: today's log
            $todayPath = \POPRecoveryEmail\Core\Logger::getTodayLogPath();
            $content = file_exists($todayPath) ? \POPRecoveryEmail\Core\Logger::readLog($todayPath, 300) : 'No log entries yet today.';
            $file = basename($todayPath);
        }

        wp_send_json_success([
            'content' => $content,
            'file'    => $file,
            'files'   => array_keys($logFiles),
        ]);
    }

    public function ajaxSendTestEmail(): void {
        check_ajax_referer('prer_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error('Permission denied'); }

        $testEmail  = sanitize_email(wp_unslash($_POST['email'] ?? get_option('admin_email')));
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $orderId    = (int) ($_POST['order_id'] ?? 0);
        $rawHtml    = isset($_POST['html_body']) ? TemplatePage::cleanHtml($_POST['html_body']) : '';
        $rawSubject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));

        // If raw HTML provided (from template editor), use that directly
        if (!empty($rawHtml) && !empty($rawSubject)) {
            $templateSubject = $rawSubject;
            $templateHtml    = $rawHtml;
        } else {
            // Fall back to DB template
            global $wpdb;
            $template = $templateId
                ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}po_email_templates WHERE id = %d LIMIT 1", $templateId))
                : $wpdb->get_row("SELECT * FROM {$wpdb->prefix}po_email_templates WHERE status='active' LIMIT 1");
            if (!$template) { wp_send_json_error('No template found.'); }
            $templateSubject = $template->subject;
            $templateHtml    = $template->html_body;
        }

        // Build context
        if ($orderId && function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
            if ($order) {
                $context = $this->buildRealOrderContext($order);
            } else {
                $context = \POPRecoveryEmail\Core\MergeTags::buildSampleContext();
            }
        } else {
            $context = \POPRecoveryEmail\Core\MergeTags::buildSampleContext();
        }

        $subject = '[TEST] ' . \POPRecoveryEmail\Core\MergeTags::resolve($templateSubject, $context);
        $html    = \POPRecoveryEmail\Core\MergeTags::resolve(\POPRecoveryEmail\Core\MergeTags::ensureUnsubscribeLink((string) $templateHtml), $context, true);

        $fromName  = get_option('prer_from_name', get_bloginfo('name'));
        $fromEmail = get_option('prer_from_email', get_option('admin_email'));
        $headers   = ["From: {$fromName} <{$fromEmail}>", 'Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($testEmail, $subject, $html, $headers);
        $label = $orderId ? "order #{$orderId}" : 'sample data';
        $sent ? wp_send_json_success("Sent to {$testEmail} with {$label}") : wp_send_json_error('wp_mail failed.');
    }

    /**
     * Build merge tag context from a real WC order + POP failure data.
     */
    private function buildRealOrderContext(\WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $item) {
            $qty = $item->get_quantity();
            $name = $item->get_name();
            $items[] = $qty > 1 ? "{$name} \u{D7} {$qty}" : $name;
        }

        $failureCategory = $order->get_meta('_po_failure_category') ?: 'unknown';

        // Pull POP's per-code customer_message
        global $wpdb;
        $failRow = $wpdb->get_row($wpdb->prepare(
            "SELECT failure_code, failure_reason, failure_category, customer_message FROM {$wpdb->prefix}po_failed_transactions WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $order->get_id()
        ));

        if (!empty($failRow->customer_message)) {
            $failureMessage = $failRow->customer_message;
        } else {
            $metaMessage = $order->get_meta('_po_retry_customer_message');
            $failureMessage = !empty($metaMessage)
                ? $metaMessage
                : 'There was a temporary issue processing your payment. This is usually resolved by trying again.';
        }

        return [
            'customer_name'      => $order->get_billing_first_name() ?: 'there',
            'customer_last_name' => $order->get_billing_last_name() ?: '',
            'customer_full_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: 'Customer',
            'customer_email'     => $order->get_billing_email(),
            'order_number'       => (string) $order->get_order_number(),
            'order_total'        => strip_tags($order->get_formatted_order_total()),
            'order_items'        => implode(', ', $items),
            'order_date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n('M j, Y') : '',
            'cancel_deadline'    => \POPRecoveryEmail\Core\MergeTags::getCancelDeadline(),
            'failure_message'    => $failureMessage,
            'failure_code'       => $failRow->failure_code ?? $order->get_meta('_po_failure_code') ?: 'N/A',
            'card_type'          => $order->get_meta('_po_card_type') ?: 'N/A',
            'failed_gateway'     => $order->get_meta('_po_failed_gateway') ?: 'N/A',
            'pay_url'            => $order->get_checkout_payment_url(),
            'unsubscribe_url'    => home_url('?po_email_track=unsubscribe&token=test_preview'),
            'open_track_url'     => home_url('?po_email_track=open&token=test_preview'),
            'site_name'          => get_bloginfo('name'),
            'site_logo_url'      => \POPRecoveryEmail\Core\MergeTags::getSiteLogoUrl(),
            'site_url'           => home_url(),
            'support_email'      => get_option('prer_from_email', get_option('admin_email')),
            'current_year'       => gmdate('Y'),
        ];
    }
}
