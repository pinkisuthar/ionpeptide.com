<?php
/**
 * Order status page for recovery-email links (1.5.10).
 *
 * When a customer clicks a recovery email for an order that no longer needs
 * payment, show a short, clear page instead of redirecting: paid orders show
 * "already paid" with the current status; cancelled/refunded orders say so.
 *
 * Previously paid orders went to the checkout "order received" page, which looks
 * like a new purchase (and can fire purchase tracking again); cancelled orders
 * went silently to the shop. Reached only through the customer's own tracked
 * email link. No address or payment details are shown.
 *
 * @package POPRecoveryEmail
 */

namespace POPRecoveryEmail;

defined('ABSPATH') || exit;

final class OrderStatusPage {

    /** Short, friendly explanation per order status. */
    private const STATUS_TEXT = [
        'processing' => 'We have received your payment and are preparing your order.',
        'on-hold'    => 'Your order is on hold. We will update you by email.',
        'completed'  => 'Your order is complete.',
        'shipped'    => 'Your order has shipped.',
        'delivered'  => 'Your order has been delivered.',
    ];

    /** @param \WC_Order|object $order Any order-like object (never a strict type: this runs on a customer's click). */
    public static function render($order, bool $isPaid): void {
        if (!headers_sent()) {
            status_header(200);
            nocache_headers();
            header('X-Robots-Tag: noindex, nofollow', true);
            header('Content-Type: text/html; charset=' . get_option('blog_charset', 'UTF-8'));
        }
        $html = self::html($order, $isPaid); // build fully before sending anything
        echo $html; // phpcs:ignore -- every value below is escaped
    }

    public static function html($order, bool $isPaid): string {
        $site    = (string) get_bloginfo('name');
        $icon    = function_exists('get_site_icon_url') ? (string) get_site_icon_url(96) : '';
        $status  = (string) $order->get_status();
        $label   = function_exists('wc_get_order_status_name') ? (string) wc_get_order_status_name($status) : ucfirst($status);
        $number  = (string) $order->get_order_number();
        $shop    = (string) (wc_get_page_permalink('shop') ?: home_url('/'));
        $refunded = $status === 'refunded';

        if ($isPaid) {
            $title   = 'Good news &#x2014; your order is already paid';
            $paid    = $order->get_date_paid();
            $paidOn  = $paid ? (string) wc_format_datetime($paid) : '';
            $detail  = self::STATUS_TEXT[$status] ?? ('Current status: ' . $label . '.');
            $meta    = 'Order #' . $number . ($paidOn !== '' ? ' &#xB7; Paid ' . esc_html($paidOn) : '');
            $tone    = 'ok';
        } else {
            $title   = $refunded ? 'This order was refunded' : 'This order was cancelled';
            $detail  = 'No payment is due for this order.';
            $meta    = 'Order #' . $number;
            $tone    = 'muted';
        }

        // Buttons: the customer's own order page when signed in as its owner;
        // otherwise their account page (sign in) - never the checkout pages.
        $buttons = '';
        if ($isPaid) {
            $ownerId = (int) $order->get_customer_id();
            if ($ownerId > 0 && get_current_user_id() === $ownerId) {
                $buttons .= self::button((string) $order->get_view_order_url(), 'View my order', true);
            } elseif ($ownerId > 0) {
                $buttons .= self::button((string) wc_get_page_permalink('myaccount'), 'Sign in to view my order', true);
            }
            $buttons .= self::button($shop, 'Continue shopping', $buttons === '');
        } else {
            $buttons .= self::button($shop, 'Shop again', true);
        }

        $total = $isPaid ? '<p class="total">Total <strong>' . wp_kses_post($order->get_formatted_order_total()) . '</strong> &#xB7; '
            . (int) $order->get_item_count() . ' item' . ((int) $order->get_item_count() === 1 ? '' : 's') . '</p>' : '';

        return '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>' . esc_html('Order #' . $number . ' - ' . $site) . '</title>'
            . '<style>'
            . 'body{margin:0;background:#f6f7f9;color:#15181d;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
            . '.wrap{max-width:520px;margin:0 auto;padding:40px 20px}'
            . '.brand{display:flex;align-items:center;gap:10px;margin-bottom:24px;font-weight:600}'
            . '.brand img{width:36px;height:36px;border-radius:8px}'
            . '.card{background:#fff;border:1px solid #e6e8ec;border-radius:16px;padding:28px 24px}'
            . '.badge{display:inline-block;font-size:13px;font-weight:600;padding:4px 12px;border-radius:999px;margin-bottom:14px}'
            . '.ok .badge{background:#E1F5EE;color:#0F6E56}.muted .badge{background:#f1f2f4;color:#4b5260}'
            . 'h1{font-size:22px;line-height:1.3;margin:0 0 6px}.meta{color:#5c6370;font-size:14px;margin:0 0 16px}'
            . '.detail{margin:0 0 12px}.total{color:#5c6370;font-size:14px;margin:0 0 22px}.total strong{color:#15181d}'
            . '.btns{display:flex;flex-wrap:wrap;gap:10px}'
            . '.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:10px;text-decoration:none;font-weight:600;font-size:15px}'
            . '.btn.primary{background:#15181d;color:#fff}.btn.secondary{background:#fff;color:#15181d;border:1px solid #d5d8de}'
            . '.help{color:#5c6370;font-size:13px;margin:22px 0 0}'
            . '@media (max-width:480px){.wrap{padding:24px 16px}.btn{flex:1 1 100%}}'
            . '</style></head><body><main class="wrap">'
            . '<div class="brand">' . ($icon !== '' ? '<img src="' . esc_url($icon) . '" alt="">' : '') . esc_html($site) . '</div>'
            . '<section class="card ' . esc_attr($tone) . '" aria-labelledby="po-title">'
            . '<span class="badge">' . esc_html($isPaid ? $label : ($refunded ? 'Refunded' : 'Cancelled')) . '</span>'
            . '<h1 id="po-title">' . $title . '</h1>'
            . '<p class="meta">' . $meta . '</p>'
            . '<p class="detail">' . esc_html($detail) . '</p>'
            . $total
            . '<div class="btns">' . $buttons . '</div>'
            . '<p class="help">Questions about this order? Reply to your order confirmation email and our team will help.</p>'
            . '</section></main></body></html>';
    }

    private static function button(string $url, string $text, bool $primary): string {
        return '<a class="btn ' . ($primary ? 'primary' : 'secondary') . '" href="' . esc_url($url) . '">' . esc_html($text) . '</a>';
    }
}
