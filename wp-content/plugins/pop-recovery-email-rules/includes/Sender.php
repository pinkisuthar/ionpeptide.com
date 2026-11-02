<?php
/**
 * Renders and sends recovery emails using stored templates.
 *
 * @package POPRecoveryEmail
 */

declare(strict_types=1);

namespace POPRecoveryEmail;

use POPRecoveryEmail\Core\MergeTags;
use POPRecoveryEmail\Core\Logger;

defined('ABSPATH') || exit;

final class Sender {

    /**
     * Send a recovery email for a sequence.
     */
    public function send(object $sequence, string $payUrl): bool {
        global $wpdb;

        $orderId = (int) $sequence->order_id;
        $order   = wc_get_order($orderId);
        if (!$order) {
            return false;
        }

        // Load template
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}po_email_templates WHERE id = %d AND status = 'active' LIMIT 1",
            (int) $sequence->template_id
        ));

        if (!$template) {
            Logger::error('Template not found or inactive', ['template_id' => $sequence->template_id, 'order_id' => $orderId]);
            return false;
        }

        // Build tracking URLs
        $siteUrl       = home_url();
        $token         = $sequence->tracking_token;
        $openTrackUrl  = add_query_arg(['po_email_track' => 'open', 'token' => $token], $siteUrl);
        $clickTrackUrl = add_query_arg(['po_email_track' => 'click', 'token' => $token], $siteUrl);
        $unsubUrl      = add_query_arg(['po_email_track' => 'unsubscribe', 'token' => $token], $siteUrl);

        // Build context
        $context = MergeTags::buildContext($order, $sequence, $clickTrackUrl, $unsubUrl, $openTrackUrl);

        // Resolve subject + body
        $subject = MergeTags::resolve($template->subject, $context);
        $html    = MergeTags::resolve(MergeTags::ensureUnsubscribeLink((string) $template->html_body), $context, true);

        if (empty($html)) {
            return false;
        }

        // Headers
        $fromName  = get_option('prer_from_name', get_bloginfo('name'));
        $fromEmail = get_option('prer_from_email', get_option('admin_email'));
        $headers   = [
            "From: {$fromName} <{$fromEmail}>",
            'Content-Type: text/html; charset=UTF-8',
            "List-Unsubscribe: <{$unsubUrl}>",
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ];

        // CC admin if enabled
        if (get_option('prer_cc_admin', 'no') === 'yes') {
            $adminEmail = get_option('prer_from_email', get_option('admin_email'));
            if ($adminEmail && $adminEmail !== $sequence->customer_email) {
                $headers[] = "Bcc: {$adminEmail}";
            }
        }

        $sent = wp_mail($sequence->customer_email, $subject, $html, $headers);

        if ($sent) {
            Logger::info('wp_mail succeeded', ['order_id' => $orderId, 'to' => $sequence->customer_email, 'subject' => $subject, 'bcc' => get_option('prer_cc_admin', 'no') === 'yes' ? $fromEmail : 'none']);
            $wpdb->insert($wpdb->prefix . 'po_email_log', [
                'sequence_id'    => (int) $sequence->id,
                'order_id'       => $orderId,
                'template_id'    => (int) $template->id,
                'template_name'  => $template->name,
                'subject'        => $subject,
                'customer_email' => $sequence->customer_email,
                'sent_at'        => gmdate('Y-m-d H:i:s'),
                'order_total'    => (float) $sequence->order_total,
                'failure_code'   => $sequence->failure_code,
                'tracking_token' => $token,
            ], ['%d','%d','%d','%s','%s','%s','%s','%f','%s','%s']);
        } else {
            Logger::error('wp_mail FAILED', ['order_id' => $orderId, 'to' => $sequence->customer_email, 'from' => $fromEmail]);
        }

        return $sent;
    }
}
