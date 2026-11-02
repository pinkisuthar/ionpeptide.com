<?php
/**
 * Merge tag registry and resolver.
 *
 * Provides the canonical list of available merge tags, their descriptions,
 * and resolves them against a WC_Order + sequence data.
 *
 * @package POPRecoveryEmail\Core
 */

declare(strict_types=1);

namespace POPRecoveryEmail\Core;

defined('ABSPATH') || exit;

final class MergeTags {

    /**
     * Get all available merge tags grouped by category.
     *
     * @return array<string, array<string, string>> Category => [tag => description]
     */
    public static function getAll(): array {
        return [
            'Customer' => [
                '{{customer_name}}'       => 'Billing first name',
                '{{customer_last_name}}'  => 'Billing last name',
                '{{customer_full_name}}'  => 'Full billing name',
                '{{customer_email}}'      => 'Customer email address',
            ],
            'Order' => [
                '{{order_number}}'      => 'WooCommerce order number',
                '{{order_total}}'       => 'Formatted order total with currency',
                '{{order_items}}'       => 'Line item summary (e.g. "Product A ' . "\u{D7}" . ' 2")',
                '{{order_date}}'        => 'Date the order was placed',
                '{{cancel_deadline}}'   => 'Auto-cancel deadline for on-hold orders (e.g. "90 minutes")',
            ],
            'Payment' => [
                '{{failure_message}}'  => 'Customer-friendly failure reason',
                '{{failure_code}}'     => 'Raw failure code (e.g. 253)',
                '{{card_type}}'        => 'Card brand used (Visa, Mastercard, etc.)',
                '{{failed_gateway}}'   => 'Gateway that failed (internal ID)',
            ],
            'Links' => [
                '{{pay_url}}'          => 'Secure checkout link (tracked)',
                '{{unsubscribe_url}}'  => 'One-click unsubscribe link',
                '{{site_url}}'         => 'Your website URL',
            ],
            'Site' => [
                '{{site_name}}'      => 'Your site/store name',
                '{{site_logo_url}}'  => 'Site logo image URL (from WooCommerce or Customizer)',
                '{{support_email}}'  => 'Support email address',
                '{{current_year}}'   => 'Current year (e.g. 2026)',
            ],
        ];
    }

    /**
     * Get a flat array of all tag keys.
     *
     * @return string[]
     */
    public static function getAllKeys(): array {
        $keys = [];
        foreach (self::getAll() as $group) {
            $keys = array_merge($keys, array_keys($group));
        }
        return $keys;
    }

    /**
     * Resolve merge tags against real order + sequence data.
     *
     * @param string  $content  HTML/text with {{tags}}
     * @param array   $context  Pre-built context array
     * @return string
     */
    public static function resolve(string $content, array $context, bool $html = false): string {
        // URL-valued tags (escaped as URLs in HTML; never HTML-escaped as text).
        $urlKeys = ['pay_url', 'unsubscribe_url', 'open_track_url', 'site_url', 'site_logo_url'];
        $replacements = [];
        foreach ($context as $key => $value) {
            $value = (string) $value;
            if ($html) {
                // 1.5.8: customer-controlled values (names, product names, messages) were
                // inserted raw into the email HTML. Escape them for the HTML body.
                $value = in_array($key, $urlKeys, true) ? esc_url($value) : esc_html($value);
            }
            $replacements["{{{$key}}}"] = $value;
        }
        // Also handle double-curly syntax without the explicit key mapping
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * 1.5.8: guarantee a visible unsubscribe link in the email body. Templates without
     * {{unsubscribe_url}} relied only on the List-Unsubscribe header, which not every
     * mail client shows. Adds a small footer link before </body> (or at the end).
     */
    public static function ensureUnsubscribeLink(string $html): string {
        if ($html === '' || str_contains($html, '{{unsubscribe_url}}')) {
            return $html;
        }
        $footer = '<table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center" style="padding:16px;font-family:Arial,sans-serif;font-size:12px;color:#94a3b8;">'
            . 'Don\'t want payment reminders? <a href="{{unsubscribe_url}}" style="color:#94a3b8;text-decoration:underline;">Unsubscribe</a>'
            . '</td></tr></table>';
        $pos = strripos($html, '</body>');
        return $pos === false ? $html . $footer : substr_replace($html, $footer, $pos, 0);
    }

    /**
     * Build context array from a WC_Order and sequence data.
     *
     * @param \WC_Order $order
     * @param object    $sequence  Row from po_email_sequences
     * @param string    $payUrl
     * @param string    $unsubUrl
     * @param string    $openTrackUrl
     * @return array
     */
    public static function buildContext(
        \WC_Order $order,
        object $sequence,
        string $payUrl,
        string $unsubUrl,
        string $openTrackUrl
    ): array {
        // Line items
        $items = [];
        foreach ($order->get_items() as $item) {
            $qty   = $item->get_quantity();
            $name  = $item->get_name();
            $items[] = $qty > 1 ? "{$name} \u{D7} {$qty}" : $name;
        }

        // Pull customer_message from POP's failed_transactions table (per failure code)
        // Falls back to order meta, then generic message
        global $wpdb;
        $popMessage = $wpdb->get_var($wpdb->prepare(
            "SELECT customer_message FROM {$wpdb->prefix}po_failed_transactions WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $order->get_id()
        ));

        // Use POP's per-code message if available, otherwise check order meta
        if (!empty($popMessage)) {
            $failureMessage = $popMessage;
        } else {
            $metaMessage = $order->get_meta('_po_retry_customer_message');
            if (!empty($metaMessage)) {
                $failureMessage = $metaMessage;
            } else {
                // Category-aware fallback for orders without POP message
                $category = $sequence->failure_category ?? '';
                $failureMessage = match ($category) {
                    'pending_payment' => self::getPendingMessage(),
                    default           => 'There was a temporary issue processing your payment. This is usually resolved by trying again.',
                };
            }
        }

        return [
            'customer_name'      => $order->get_billing_first_name() ?: 'there',
            'customer_last_name' => $order->get_billing_last_name() ?: '',
            'customer_full_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: 'Customer',
            'customer_email'     => $order->get_billing_email(),
            'order_number'       => (string) $order->get_order_number(),
            'order_total'        => html_entity_decode(strip_tags($order->get_formatted_order_total()), ENT_QUOTES, 'UTF-8'),
            'order_items'        => implode(', ', $items),
            'order_date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n('M j, Y') : '',
            'cancel_deadline'    => self::getCancelDeadline(),
            'failure_message'    => $failureMessage,
            'failure_code'       => $sequence->failure_code,
            'card_type'          => $order->get_meta('_po_card_type') ?: 'N/A',
            'failed_gateway'     => $order->get_meta('_po_failed_gateway') ?: '',
            'pay_url'            => $payUrl,
            'unsubscribe_url'    => $unsubUrl,
            'open_track_url'     => $openTrackUrl,
            'site_name'          => get_bloginfo('name'),
            'site_logo_url'      => self::getSiteLogoUrl(),
            'site_url'           => home_url(),
            'support_email'      => get_option('prer_from_email', get_option('admin_email')),
            'current_year'       => gmdate('Y'),
        ];
    }

    /**
     * Build a sample context for template previews.
     *
     * @return array
     */
    public static function buildSampleContext(): array {
        return [
            'customer_name'      => 'John',
            'customer_last_name' => 'Smith',
            'customer_full_name' => 'John Smith',
            'customer_email'     => 'john@example.com',
            'order_number'       => '166210',
            'order_total'        => '$149.99',
            'order_items'        => 'Product A ' . "\u{D7}" . ' 2, Product B ' . "\u{D7}" . ' 1',
            'order_date'         => gmdate('M j, Y'),
            'cancel_deadline'    => self::getCancelDeadline(),
            'failure_message'    => 'This card cannot be used for this transaction. Please contact your bank or use a different card.',
            'failure_code'       => '253',
            'card_type'          => 'Visa',
            'failed_gateway'     => 'nmi2',
            'pay_url'            => home_url('/checkout/order-pay/166210/?pay_for_order=true&key=sample'),
            'unsubscribe_url'    => home_url('?po_email_track=unsubscribe&token=sample'),
            'open_track_url'     => home_url('?po_email_track=open&token=sample'),
            'site_name'          => get_bloginfo('name') ?: 'Your Store',
            'site_logo_url'      => self::getSiteLogoUrl(),
            'site_url'           => home_url(),
            'support_email'      => get_option('prer_from_email', get_option('admin_email', 'support@yourstore.com')),
            'current_year'       => gmdate('Y'),
        ];
    }

    /**
     * Build the pending payment message, with optional cancel deadline.
     */
    private static function getPendingMessage(): string {
        $deadline = self::getCancelDeadline();
        if (!empty($deadline)) {
            return "It looks like your payment wasn't fully completed. Your order will be automatically cancelled in {$deadline} to release stock. Please click below to finish checkout before then.";
        }
        return "It looks like your payment wasn't fully completed. Your order is reserved and ready \u{2014} just click below to finish checkout.";
    }

    /**
     * Get the human-readable auto-cancel deadline.
     */
    public static function getCancelDeadline(): string {
        $minutes = (int) get_option('prer_onhold_cancel_after', 90);
        if ($minutes <= 0) {
            return '';
        }
        if ($minutes >= 1440) {
            $hours = round($minutes / 60);
            return "{$hours} hours";
        }
        if ($minutes >= 120) {
            $hours = round($minutes / 60, 1);
            // Clean up ".0"
            $hours = (floor($hours) == $hours) ? (int) $hours : $hours;
            return "{$hours} hours";
        }
        return "{$minutes} minutes";
    }

    /**
     * Get the site logo URL.
     *
     * Priority: WooCommerce email header image -> Customizer site logo -> empty string.
     */
    public static function getSiteLogoUrl(): string {
        // 1. WooCommerce email header image (most likely what stores want)
        $wcImage = get_option('woocommerce_email_header_image', '');
        if (!empty($wcImage)) {
            return $wcImage;
        }

        // 2. WordPress Customizer site logo
        $customLogoId = get_theme_mod('custom_logo');
        if ($customLogoId) {
            $url = wp_get_attachment_image_url($customLogoId, 'medium');
            if ($url) {
                return $url;
            }
        }

        // 3. Site icon as last resort
        $siteIconId = get_option('site_icon');
        if ($siteIconId) {
            $url = wp_get_attachment_image_url($siteIconId, 'full');
            if ($url) {
                return $url;
            }
        }

        return '';
    }
}
