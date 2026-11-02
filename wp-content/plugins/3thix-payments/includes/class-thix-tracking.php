<?php
/**
 * Shipment tracking metabox on the WooCommerce order screen.
 *
 * Lets a merchant enter carrier + tracking number on a WooCommerce order and,
 * on save, POSTs it to 3thix (POST /tracking) so the escrow release timer
 * starts. HPOS- and legacy-storage compatible.
 *
 * @package Thix_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Thix_Tracking {
    const NONCE = 'thix_tracking_nonce';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save'], 20, 1);
    }

    private static function gateway() {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        return isset($gateways['thix']) ? $gateways['thix'] : null;
    }

    public static function add_meta_box() {
        // HPOS uses the custom 'woocommerce_page_wc-orders' screen; legacy uses 'shop_order'.
        $screen = class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'thix_tracking',
            __('3thix Shipment Tracking', 'thix-payments'),
            [__CLASS__, 'render'],
            $screen,
            'side',
            'default'
        );
    }

    public static function render($post_or_order) {
        $order = $post_or_order instanceof WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;
        if (!$order) {
            return;
        }

        $carrier = $order->get_meta('_thix_carrier');
        $tracking = $order->get_meta('_thix_tracking_number');
        $synced = $order->get_meta('_thix_tracking_synced');
        $confirm_pending = (string) $order->get_meta('_thix_tracking_confirm_sig') !== '';
        $stale = (string) $order->get_meta('_thix_tracking_stale_sig') !== '';

        wp_nonce_field('thix_tracking_save', self::NONCE);
        ?>
        <?php if ($confirm_pending) : ?>
            <div style="border:1px solid #f0b429;background:#fffbeb;border-radius:4px;padding:8px 10px;margin-bottom:8px;">
                <p style="margin:0 0 6px;color:#92400e;"><strong><?php esc_html_e('Carrier says this shipment was already delivered.', 'thix-payments'); ?></strong><br />
                <?php esc_html_e('Was this item already sent for THIS order? If so, confirm below and update the order to submit the tracking to 3thix.', 'thix-payments'); ?></p>
                <label>
                    <input type="checkbox" name="thix_confirm_already_shipped" value="1" />
                    <?php esc_html_e('Yes — this shipment was sent for this order', 'thix-payments'); ?>
                </label>
            </div>
        <?php endif; ?>
        <?php if ($stale) : ?>
            <div style="border:1px solid #f04438;background:#fef3f2;border-radius:4px;padding:8px 10px;margin-bottom:8px;">
                <p style="margin:0;color:#b42318;"><strong><?php esc_html_e('Tracking rejected: delivered too long ago.', 'thix-payments'); ?></strong><br />
                <?php esc_html_e('The carrier reports this shipment was delivered too long ago to submit for payout. Please contact a 3thix admin to review this order.', 'thix-payments'); ?></p>
            </div>
        <?php endif; ?>
        <p style="margin-top:0;color:#475467;">
            <?php esc_html_e('Tracking is sent to 3thix automatically when you add it to this order (here, or via WooCommerce / Advanced Shipment Tracking) and the order is paid — no need to send it by hand.', 'thix-payments'); ?>
        </p>
        <p>
            <label for="thix_carrier"><strong><?php esc_html_e('Carrier', 'thix-payments'); ?></strong></label>
            <input type="text" id="thix_carrier" name="thix_carrier" class="widefat"
                value="<?php echo esc_attr($carrier); ?>" placeholder="UPS, USPS, FedEx…" />
        </p>
        <p>
            <label for="thix_tracking_number"><strong><?php esc_html_e('Tracking number', 'thix-payments'); ?></strong></label>
            <input type="text" id="thix_tracking_number" name="thix_tracking_number" class="widefat"
                value="<?php echo esc_attr($tracking); ?>" />
        </p>
        <p>
            <label>
                <input type="checkbox" name="thix_send_tracking" value="1" />
                <?php esc_html_e('Re-send to 3thix now on save (force, even if unchanged)', 'thix-payments'); ?>
            </label>
        </p>
        <?php if ($synced) : ?>
            <p style="color:#2CE28D;margin:0;"><?php
                /* translators: %s: date/time tracking was synced */
                echo esc_html(sprintf(__('Last synced to 3thix: %s', 'thix-payments'), $synced)); ?></p>
        <?php endif; ?>
        <?php
    }

    public static function save($order_id) {
        if (
            !isset($_POST[self::NONCE])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), 'thix_tracking_save')
        ) {
            return;
        }
        if (!current_user_can('edit_shop_orders')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $carrier = isset($_POST['thix_carrier']) ? sanitize_text_field(wp_unslash($_POST['thix_carrier'])) : '';
        $tracking = isset($_POST['thix_tracking_number']) ? sanitize_text_field(wp_unslash($_POST['thix_tracking_number'])) : '';

        $order->update_meta_data('_thix_carrier', $carrier);
        $order->update_meta_data('_thix_tracking_number', $tracking);
        $order->save();

        // Auto-sync handles the actual submission (de-duped, opt-out aware). The
        // checkbox just forces an immediate (re-)send even if nothing changed.
        // Answering the "already delivered — confirm?" question implies a forced
        // resubmission (the failed attempt never set the synced signature, but
        // force also bypasses the opt-out setting for this explicit human action).
        $confirm = !empty($_POST['thix_confirm_already_shipped']);
        $force = !empty($_POST['thix_send_tracking']) || $confirm;
        if ($force && ($carrier === '' || $tracking === '')) {
            // A manual force with no usable tracking on this order is a no-op worth
            // surfacing (auto-sync would also need a carrier + number to send).
            if (!self::extract_has_tracking($order)) {
                $order->add_order_note(__('3thix tracking not sent: enter a carrier and tracking number first.', 'thix-payments'));
                $order->save();
                return;
            }
        }

        if (class_exists('Thix_Auto_Tracking')) {
            Thix_Auto_Tracking::sync_order($order, $force, $confirm);
        }
    }

    /** True if the order carries usable tracking from any supported source. */
    private static function extract_has_tracking($order) {
        $items = $order->get_meta('_wc_shipment_tracking_items');
        if (is_array($items) && !empty($items)) {
            $item = end($items);
            if (is_array($item) && !empty($item['tracking_number'])) {
                return true;
            }
        }
        return $order->get_meta('_thix_carrier') !== '' && $order->get_meta('_thix_tracking_number') !== '';
    }
}
