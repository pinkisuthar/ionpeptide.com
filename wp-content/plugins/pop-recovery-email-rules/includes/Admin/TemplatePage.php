<?php
declare(strict_types=1);
namespace POPRecoveryEmail\Admin;
use POPRecoveryEmail\Core\MergeTags;
defined('ABSPATH') || exit;

final class TemplatePage {

    public function render(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'po_email_templates';
        $templates = $wpdb->get_results("SELECT * FROM {$table} ORDER BY status DESC, id ASC");
        $activeId = (int) get_option('prer_active_template_id', 0);
        $editId = (int)($_GET['edit'] ?? 0);
        $isNew = isset($_GET['new']);
        $editing = $editId ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $editId)) : null;
        $mergeTags = MergeTags::getAll();

        if ($editing || $isNew):
            // Fetch recent failed orders for preview/test
            $poTable = $wpdb->prefix . 'po_failed_transactions';
            $recentFails = [];
            if ($wpdb->get_var("SHOW TABLES LIKE '{$poTable}'")) {
                $recentFails = $wpdb->get_results("SELECT DISTINCT f.order_id, f.failure_code, f.failure_reason, f.failure_category, f.order_total, f.customer_email, f.gateway_id FROM {$poTable} f ORDER BY f.id DESC LIMIT 10") ?: [];
            }
        ?>
        <!-- Editor View -->
        <div class="prer-editor-bar">
            <a href="<?php echo admin_url('admin.php?page=pop-email-recovery&tab=templates'); ?>" class="prer-btn prer-btn-ghost">&#x2190; Back to Templates</a>
            <div style="display:flex;gap:8px">
                <button type="button" class="prer-btn prer-btn-outline-dark" id="prer-preview-btn">Preview</button>
                <button type="button" class="prer-btn prer-btn-primary" id="prer-save-tpl-btn">Save Template</button>
            </div>
        </div>

        <div class="prer-editor-layout">
            <div class="prer-editor-main">
                <input type="hidden" id="prer-tpl-id" value="<?php echo $editing ? (int)$editing->id : 0; ?>">
                <div class="prer-card">
                    <div class="prer-field"><label>Template Name</label><input type="text" id="prer-tpl-name" value="<?php echo esc_attr($editing->name ?? ''); ?>" placeholder="e.g. Default Recovery" class="large-text"></div>
                    <div class="prer-field"><label>Subject Line</label><input type="text" id="prer-tpl-subject" value="<?php echo esc_attr($editing->subject ?? ''); ?>" placeholder="Your order #{{order_number}} is waiting" class="large-text"><p class="prer-help">Click merge tags in the sidebar to insert.</p></div>
                </div>
                <div class="prer-card prer-editor-card">
                    <div class="prer-editor-tabs">
                        <button type="button" class="prer-editor-tab active" data-tab="code">Code</button>
                        <button type="button" class="prer-editor-tab" data-tab="visual">Visual Preview</button>
                    </div>

                    <!-- Preview Data Source Bar (shown in both tabs) -->
                    <div class="prer-preview-source-bar">
                        <div class="prer-source-left">
                            <label>Preview with:</label>
                            <select id="prer-preview-order" class="prer-source-select">
                                <option value="0">Sample data</option>
                                <?php foreach ($recentFails as $f):
                                    $lbl = sprintf('#%d ' . "\u{2014}" . ' %s ' . "\u{2014}" . ' Code %s ' . "\u{2014}" . ' %s', $f->order_id, str_replace('_',' ',$f->failure_category), $f->failure_code, wc_price((float)$f->order_total));
                                ?>
                                <option value="<?php echo (int)$f->order_id; ?>"><?php echo esc_html($lbl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="prer-source-right">
                            <input type="email" id="prer-test-email-tpl" value="<?php echo esc_attr(get_option('admin_email')); ?>" placeholder="test@email.com" class="prer-test-input">
                            <button type="button" class="prer-btn prer-btn-send-test" id="prer-send-test-tpl">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                Send Test
                            </button>
                            <span id="prer-test-tpl-result" class="prer-test-inline-result"></span>
                        </div>
                    </div>

                    <div class="prer-tab-panel" id="prer-panel-code">
                        <textarea id="prer-tpl-html" class="prer-code-textarea"><?php echo esc_textarea($editing->html_body ?? ''); ?></textarea>
                    </div>
                    <div class="prer-tab-panel" id="prer-panel-visual" style="display:none">
                        <div class="prer-preview-toolbar">
                            <span class="prer-preview-label">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                Read-only preview &#x2014; edit in Code tab
                            </span>
                            <button type="button" class="prer-preview-refresh" id="prer-refresh-preview">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                                Refresh
                            </button>
                        </div>
                        <div class="prer-preview-frame-wrap"><iframe id="prer-preview-iframe" class="prer-preview-iframe"></iframe></div>
                    </div>
                </div>
            </div>
            <div class="prer-editor-sidebar">
                <div class="prer-card prer-sticky">
                    <h3 class="prer-card-title" style="margin-bottom:4px">Merge Tags</h3>
                    <p class="prer-help" style="margin:0 0 16px">Click to insert at cursor.</p>
                    <?php foreach ($mergeTags as $group => $tags): ?>
                    <div class="prer-tag-group">
                        <div class="prer-tag-group-label"><?php echo esc_html($group); ?></div>
                        <?php foreach ($tags as $tag => $desc): ?>
                        <button type="button" class="prer-tag-btn" data-tag="<?php echo esc_attr($tag); ?>" title="<?php echo esc_attr($desc); ?>">
                            <span class="prer-tag-code"><?php echo esc_html($tag); ?></span>
                            <span class="prer-tag-desc"><?php echo esc_html($desc); ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- List View -->
        <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
            <a href="<?php echo admin_url('admin.php?page=pop-email-recovery&tab=templates&new=1'); ?>" class="prer-btn prer-btn-primary">+ New Template</a>
        </div>

        <?php if (empty($templates)): ?>
        <div class="prer-card" style="text-align:center;padding:48px">
            <p style="font-size:16px;color:#64748b">No templates yet.</p>
            <a href="<?php echo admin_url('admin.php?page=pop-email-recovery&tab=templates&new=1'); ?>" class="prer-btn prer-btn-primary" style="margin-top:16px">Create Template</a>
        </div>
        <?php else: ?>
        <div class="prer-template-grid">
            <?php foreach ($templates as $tpl): $isA = (int)$tpl->id === $activeId; ?>
            <div class="prer-template-card <?php echo $isA ? 'is-active' : ''; ?>">
                <?php if ($isA): ?><div class="prer-tpl-active-badge">Active</div><?php endif; ?>
                <div class="prer-tpl-preview-thumb">
                    <iframe srcdoc="<?php echo esc_attr($tpl->html_body); ?>" class="prer-tpl-thumb-iframe" tabindex="-1"></iframe>
                    <div class="prer-tpl-thumb-overlay"><a href="<?php echo admin_url("admin.php?page=pop-email-recovery&tab=templates&edit={$tpl->id}"); ?>" class="prer-btn prer-btn-sm">Edit</a></div>
                </div>
                <div class="prer-tpl-info">
                    <h3 class="prer-tpl-name"><?php echo esc_html($tpl->name); ?></h3>
                    <p class="prer-tpl-subject"><?php echo esc_html($tpl->subject); ?></p>
                    <div class="prer-tpl-meta">Updated <?php echo esc_html(date_i18n('M j, Y',strtotime($tpl->updated_at))); ?></div>
                </div>
                <div class="prer-tpl-actions">
                    <?php if (!$isA): ?>
                    <button type="button" class="prer-tpl-action-btn prer-set-active-btn" data-id="<?php echo (int)$tpl->id;?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Activate</button>
                    <?php else: ?>
                    <span class="prer-tpl-action-btn prer-active-label"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>In Use</span>
                    <?php endif; ?>
                    <button type="button" class="prer-tpl-action-btn prer-duplicate-btn" data-id="<?php echo (int)$tpl->id;?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Duplicate</button>
                    <?php if (!$isA): ?>
                    <button type="button" class="prer-tpl-action-btn prer-delete-btn" data-id="<?php echo (int)$tpl->id;?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>Delete</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; endif;
    }

    /**
     * Clean email HTML from the editor.
     *
     * 1.5.8: $_POST is slashed by WordPress, so values must be unslashed first. Users who
     * are trusted with unfiltered HTML (site admins) keep the full email document;
     * wp_kses_post strips <html>/<head>/<style>, which breaks email layouts.
     */
    public static function cleanHtml($raw): string {
        $raw = wp_unslash((string) $raw);
        return current_user_can('unfiltered_html') ? $raw : wp_kses_post($raw);
    }

    /* -- AJAX ---------------------------- */
    public function ajaxSaveTemplate(): void {
        check_ajax_referer('prer_admin','nonce'); if (!current_user_can('manage_woocommerce')) wp_send_json_error('Denied');
        global $wpdb; $t=$wpdb->prefix.'po_email_templates';
        $id=(int)($_POST['id']??0); $n=sanitize_text_field(wp_unslash($_POST['name']??'')); $s=sanitize_text_field(wp_unslash($_POST['subject']??'')); $h=self::cleanHtml($_POST['html_body']??'');
        if(!$n||!$s||!$h) wp_send_json_error('All fields required.');
        if($id>0){$wpdb->update($t,['name'=>$n,'subject'=>$s,'html_body'=>$h,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id],['%s','%s','%s','%s'],['%d']); wp_send_json_success(['id'=>$id,'message'=>'Updated.']);}
        else{$wpdb->insert($t,['name'=>$n,'subject'=>$s,'html_body'=>$h,'status'=>'inactive','created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')],['%s','%s','%s','%s','%s','%s']); wp_send_json_success(['id'=>$wpdb->insert_id,'message'=>'Created.']);}
    }
    public function ajaxDeleteTemplate(): void {
        check_ajax_referer('prer_admin','nonce'); if (!current_user_can('manage_woocommerce')) wp_send_json_error('Denied');
        global $wpdb; $id=(int)($_POST['id']??0); if(!$id) wp_send_json_error('Invalid.');
        if($id===(int)get_option('prer_active_template_id',0)) wp_send_json_error('Cannot delete the active template.');
        $wpdb->delete($wpdb->prefix.'po_email_templates',['id'=>$id],['%d']); wp_send_json_success('Deleted.');
    }
    public function ajaxSetActive(): void {
        check_ajax_referer('prer_admin','nonce'); if (!current_user_can('manage_woocommerce')) wp_send_json_error('Denied');
        global $wpdb; $id=(int)($_POST['id']??0); $t=$wpdb->prefix.'po_email_templates'; if(!$id) wp_send_json_error('Invalid.');
        $wpdb->update($t,['status'=>'inactive'],['status'=>'active'],['%s'],['%s']);
        $wpdb->update($t,['status'=>'active'],['id'=>$id],['%s'],['%d']);
        update_option('prer_active_template_id',$id); wp_send_json_success('Activated.');
    }
    public function ajaxPreview(): void {
        check_ajax_referer('prer_admin','nonce'); if (!current_user_can('manage_woocommerce')) wp_send_json_error('Denied');
        $h=self::cleanHtml($_POST['html_body']??''); $s=sanitize_text_field(wp_unslash($_POST['subject']??''));
        $orderId = (int)($_POST['order_id'] ?? 0);

        if ($orderId && function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
            if ($order) {
                $c = self::buildRealOrderContext($order);
            } else {
                $c = MergeTags::buildSampleContext();
            }
        } else {
            $c = MergeTags::buildSampleContext();
        }

        wp_send_json_success(['html'=>MergeTags::resolve(MergeTags::ensureUnsubscribeLink($h),$c,true),'subject'=>MergeTags::resolve($s,$c)]);
    }

    /**
     * Build merge tag context from a real WC order + POP failure data.
     */
    private static function buildRealOrderContext(\WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $item) {
            $qty = $item->get_quantity();
            $name = $item->get_name();
            $items[] = $qty > 1 ? "{$name} \u{D7} {$qty}" : $name;
        }

        $failureCategory = $order->get_meta('_po_failure_category') ?: 'unknown';

        // Pull POP's per-code customer_message from failed_transactions
        global $wpdb;
        $failRow = $wpdb->get_row($wpdb->prepare(
            "SELECT failure_code, failure_reason, failure_category, customer_message FROM {$wpdb->prefix}po_failed_transactions WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $order->get_id()
        ));

        // Use POP's message if available, then order meta, then generic fallback
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
    public function ajaxDuplicate(): void {
        check_ajax_referer('prer_admin','nonce'); if (!current_user_can('manage_woocommerce')) wp_send_json_error('Denied');
        global $wpdb; $id=(int)($_POST['id']??0); $t=$wpdb->prefix.'po_email_templates';
        $o=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id)); if(!$o) wp_send_json_error('Not found.');
        $wpdb->insert($t,['name'=>$o->name.' (Copy)','subject'=>$o->subject,'html_body'=>$o->html_body,'status'=>'inactive','created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')],['%s','%s','%s','%s','%s','%s']);
        wp_send_json_success(['id'=>$wpdb->insert_id,'message'=>'Duplicated.']);
    }
}
