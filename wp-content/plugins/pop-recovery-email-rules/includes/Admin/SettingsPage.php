<?php
declare(strict_types=1);
namespace POPRecoveryEmail\Admin;
defined('ABSPATH') || exit;

final class SettingsPage {
    public function render(): void {
        $enabled = get_option('prer_enabled','yes');
        $pendingEnabled = get_option('prer_pending_enabled','no');
        $cats = get_option('prer_categories',['retryable_gateway','retryable_card_type']);
        if (!is_array($cats)) $cats = [];
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('prer_settings'); ?>
            <div class="prer-settings-grid">
                <div class="prer-card">
                    <h2 class="prer-card-title">General</h2>
                    <div class="prer-field">
                        <div class="prer-toggle-row">
                            <input type="hidden" name="prer_enabled" value="no">
                            <label class="prer-switch"><input type="checkbox" name="prer_enabled" value="yes" <?php checked($enabled,'yes');?>><span class="prer-switch-slider"></span></label>
                            <div class="prer-toggle-text"><span class="prer-toggle-title">Enable Email Recovery</span><span class="prer-toggle-desc">Send recovery emails when payments fail</span></div>
                        </div>
                    </div>
                    <div class="prer-field"><label>From Name</label><input type="text" name="prer_from_name" value="<?php echo esc_attr(get_option('prer_from_name',get_bloginfo('name')));?>" class="regular-text"></div>
                    <div class="prer-field"><label>From Email</label><input type="email" name="prer_from_email" value="<?php echo esc_attr(get_option('prer_from_email',get_option('admin_email')));?>" class="regular-text"></div>

                    <div class="prer-field" style="margin-top:20px">
                        <div class="prer-toggle-row">
                            <input type="hidden" name="prer_cc_admin" value="no">
                            <label class="prer-switch"><input type="checkbox" name="prer_cc_admin" value="yes" <?php checked(get_option('prer_cc_admin','no'),'yes');?>><span class="prer-switch-slider"></span></label>
                            <div class="prer-toggle-text"><span class="prer-toggle-title">CC Admin on Recovery Emails</span><span class="prer-toggle-desc">Send a copy of every recovery email to the admin address above</span></div>
                        </div>
                    </div>
                </div>

                <div class="prer-card">
                    <h2 class="prer-card-title">Timing &amp; Limits</h2>
                    <div class="prer-field"><label>Retryable Failure Delay</label><div class="prer-inline-field"><input type="number" name="prer_email_delay" value="<?php echo esc_attr(get_option('prer_email_delay',3));?>" min="1" max="1440"><span>minutes after failure</span></div><p class="prer-help">For retryable gateway and card type failures. Gives POP's auto-redirect time to succeed first.</p></div>

                    <div class="prer-field"><label>Non-Retryable Failure Delay</label><div class="prer-inline-field"><input type="number" name="prer_non_retryable_delay" value="<?php echo esc_attr(get_option('prer_non_retryable_delay',2));?>" min="0" max="1440"><span>minutes after failure (0 = instant)</span></div><p class="prer-help">CVV mismatch, expired card, insufficient funds &#x2014; POP won't retry these. A short delay avoids emailing customers who fix it themselves straight away; the email is cancelled if they pay first. Queued emails are sent by the 5-minute cron, so actual send time is the delay plus up to 5 minutes.</p></div>

                    <div class="prer-field" style="margin-top:24px">
                        <div class="prer-toggle-row">
                            <input type="hidden" name="prer_pending_enabled" value="no">
                            <label class="prer-switch"><input type="checkbox" name="prer_pending_enabled" value="yes" <?php checked($pendingEnabled,'yes');?>><span class="prer-switch-slider"></span></label>
                            <div class="prer-toggle-text"><span class="prer-toggle-title">On-Hold / Pending Recovery</span><span class="prer-toggle-desc">Email customers with orders stuck in on-hold or pending (e.g. Adyen 3DS abandoned)</span></div>
                        </div>
                    </div>
                    <div class="prer-field"><label>On-Hold Delay</label><div class="prer-inline-field"><input type="number" name="prer_pending_delay" value="<?php echo esc_attr(get_option('prer_pending_delay',120));?>" min="1" max="4320"><span>minutes after status change</span></div><p class="prer-help">How long to wait before sending the recovery email. Set shorter than the auto-cancel window so the customer has time to act.</p></div>
                    <div class="prer-field"><label>Monitor Gateways</label><input type="text" name="prer_onhold_gateways_text" value="<?php echo esc_attr(implode(', ', get_option('prer_onhold_gateways', ['adyen'])));?>" class="regular-text" placeholder="e.g. adyen, woosa_adyen"><p class="prer-help">Comma-separated gateway IDs to monitor for on-hold orders. Leave blank to monitor all POP-managed gateways. Only orders from these gateways will trigger on-hold recovery emails.</p></div>
                    <div class="prer-field"><label>Auto-Cancel On-Hold After</label><div class="prer-inline-field"><input type="number" name="prer_onhold_cancel_after" value="<?php echo esc_attr(get_option('prer_onhold_cancel_after',90));?>" min="0" max="4320"><span>minutes (0 = never)</span></div><p class="prer-help">Automatically cancel on-hold orders from monitored gateways after this period to release stock. WooCommerce will restock items automatically. The recovery email can reference this deadline.</p></div>

                    <div class="prer-field"><label>Min Order Value ($)</label><input type="number" name="prer_min_order_value" value="<?php echo esc_attr(get_option('prer_min_order_value',0));?>" min="0" step="0.01"></div>
                    <div class="prer-field"><label>Sequence Expiry (hours)</label><input type="number" name="prer_sequence_expiry" value="<?php echo esc_attr(get_option('prer_sequence_expiry',72));?>" min="1" max="168"></div>
                    <div class="prer-field"><label>Unsubscribe Expiry (days)</label><div class="prer-inline-field"><input type="number" name="prer_unsub_expiry_days" value="<?php echo esc_attr(get_option('prer_unsub_expiry_days',30));?>" min="0" max="365"><span>days (0 = never expires)</span></div><p class="prer-help">After this period, unsubscribed customers become eligible for recovery emails again.</p></div>
                    <div class="prer-field"><label>Max Emails / Customer (24h)</label><input type="number" name="prer_max_failures_24h" value="<?php echo esc_attr(get_option('prer_max_failures_24h',2));?>" min="1" max="10"><p class="prer-help">Skip recovery if customer has received this many emails in 24 hours.</p></div>
                    <div class="prer-field"><label>Inline Cooldown</label><div class="prer-inline-field"><input type="number" name="prer_inline_cooldown" value="<?php echo esc_attr(get_option('prer_inline_cooldown',15));?>" min="1" max="60"><span>minutes per email address</span></div><p class="prer-help">After sending a non-retryable instant email, wait this long before sending another to the same customer. Prevents spam when a customer retries with a bad card repeatedly.</p></div>

                    <div class="prer-field" style="margin-top:20px">
                        <div class="prer-toggle-row">
                            <input type="hidden" name="prer_debug_log" value="no">
                            <label class="prer-switch"><input type="checkbox" name="prer_debug_log" value="yes" <?php checked(get_option('prer_debug_log','yes'),'yes');?>><span class="prer-switch-slider"></span></label>
                            <div class="prer-toggle-text"><span class="prer-toggle-title">Debug Logging</span><span class="prer-toggle-desc">Write detailed logs to a private wp-content/prer-logs-{random}/ folder (3-day rolling cycle). Contains customer emails &#x2014; turn off when not troubleshooting.</span></div>
                        </div>
                    </div>
                    <div class="prer-field">
                        <div class="prer-toggle-row">
                            <input type="hidden" name="prer_delete_data_on_uninstall" value="no">
                            <label class="prer-switch"><input type="checkbox" name="prer_delete_data_on_uninstall" value="yes" <?php checked(get_option('prer_delete_data_on_uninstall','no'),'yes');?>><span class="prer-switch-slider"></span></label>
                            <div class="prer-toggle-text"><span class="prer-toggle-title">Delete Data on Uninstall</span><span class="prer-toggle-desc">Remove all plugin data (templates, settings, logs, history) when the plugin is deleted. Leave off to preserve data during updates.</span></div>
                        </div>
                    </div>
                </div>

                <div class="prer-card">
                    <h2 class="prer-card-title">Failure Categories</h2>
                    <p class="prer-help" style="margin-top:0">Which POP failure types should trigger recovery emails.</p>
                    <?php
                    $allCats = [
                        'retryable_gateway'   => ['Retryable Gateway', 'Temporary processing issues ' . "\u{2014}" . ' usually clears on retry'],
                        'retryable_card_type' => ['Retryable Card Type', 'Card-specific issues ' . "\u{2014}" . ' may work with different card/method'],
                        'non_retryable'       => ['Non-Retryable', 'Permanent failures (expired card, insufficient funds, etc.)'],
                        'other'               => ['Other / Unknown', 'Unclassified failures'],
                    ];
                    foreach ($allCats as $key => [$label, $desc]): ?>
                    <label class="prer-checkbox-label">
                        <input type="checkbox" name="prer_categories[]" value="<?php echo esc_attr($key);?>" <?php checked(in_array($key,$cats));?>>
                        <div><strong><?php echo esc_html($label);?></strong><br><span style="font-size:12px;color:#94a3b8"><?php echo esc_html($desc);?></span></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php submit_button('Save Settings', 'primary prer-save-btn'); ?>
        </form>
        <?php
    }
}
