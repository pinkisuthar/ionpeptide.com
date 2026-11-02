<?php
/**
 * Unsubscribe handler - token-based, no login required.
 *
 * @package POPRecoveryEmail
 */

declare(strict_types=1);

namespace POPRecoveryEmail;

defined('ABSPATH') || exit;

final class UnsubscribeHandler {

    public function register(): void {
        add_action('init', [$this, 'handleUnsubscribe'], 2);
    }

    public function handleUnsubscribe(): void {
        if (
            !isset($_GET['po_email_track'], $_GET['token']) ||
            $_GET['po_email_track'] !== 'unsubscribe'
        ) {
            return;
        }

        $token = sanitize_text_field($_GET['token']);
        if (empty($token) || strlen($token) !== 64) {
            return;
        }

        global $wpdb;

        $seq = $wpdb->get_row($wpdb->prepare(
            "SELECT order_id, customer_email FROM {$wpdb->prefix}po_email_sequences WHERE tracking_token = %s LIMIT 1",
            $token
        ));

        if (!$seq || empty($seq->customer_email)) {
            $this->renderPage('error');
            return;
        }

        // 1.5.8 (RFC 8058): a plain GET only shows a confirmation button. Link scanners
        // (Outlook Safe Links, Mimecast, etc.) fetch every URL in an email with GET, which
        // previously unsubscribed customers who never clicked. Mail clients' one-click
        // unsubscribe uses POST, as does the confirmation button.
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'POST') {
            $this->renderPage('confirm');
            return;
        }

        $email = strtolower($seq->customer_email);
        $now   = gmdate('Y-m-d H:i:s');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}po_email_unsubscribes WHERE customer_email = %s LIMIT 1",
            $email
        ));

        if (!$existing) {
            $wpdb->insert($wpdb->prefix . 'po_email_unsubscribes', [
                'customer_email' => $email,
                'order_id'       => (int) $seq->order_id,
                'created_at'     => $now,
            ], ['%s','%d','%s']);
        } else {
            // Refresh the date so a repeat unsubscribe restarts the expiry window
            // (previously an expired row was left untouched and the opt-out was ignored).
            $wpdb->update($wpdb->prefix . 'po_email_unsubscribes',
                ['created_at' => $now, 'order_id' => (int) $seq->order_id],
                ['id' => (int) $existing], ['%s', '%d'], ['%d']
            );
        }

        SequenceManager::cancelSequencesForEmail($email, 'unsubscribed');
        $this->renderPage('success');
    }

    private function renderPage(string $state): void {
        $siteName = esc_html(get_bloginfo('name'));
        $homeUrl  = esc_url(home_url());
        $title    = match ($state) {
            'success' => 'Unsubscribed',
            'confirm' => 'Unsubscribe?',
            default   => 'Something went wrong',
        };
        $message  = match ($state) {
            'success' => "You've been unsubscribed from payment recovery emails. You can still place new orders anytime.",
            'confirm' => 'Stop receiving payment recovery emails from us? Order confirmations and shipping emails are not affected.',
            default   => 'We couldn\'t process your request. The link may have expired.',
        };
        $action = '';
        if ($state === 'confirm') {
            $formUrl = esc_url(add_query_arg([
                'po_email_track' => 'unsubscribe',
                'token'          => sanitize_text_field(wp_unslash($_GET['token'] ?? '')),
            ], home_url('/')));
            $action = '<form method="post" action="' . $formUrl . '" style="margin:0 0 16px"><button type="submit" style="padding:12px 32px;background:#1e293b;color:#fff;border:0;border-radius:8px;font-weight:600;font-size:16px;cursor:pointer">Unsubscribe</button></form>';
        }

        status_header($state === 'error' ? 404 : 200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$title}</title>
<style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;color:#1e293b}
.card{background:#fff;border-radius:16px;padding:48px;max-width:480px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08)}
h1{font-size:24px;margin:0 0 12px;color:#0f172a}p{font-size:16px;color:#475569;line-height:1.6;margin:0 0 24px}
a{display:inline-block;padding:12px 32px;background:#1e293b;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}a:hover{background:#334155}</style>
</head><body><div class="card"><h1>{$title}</h1><p>{$message}</p>{$action}<a href="{$homeUrl}">Return to {$siteName}</a></div></body></html>
HTML;
        exit;
    }
}
