<?php
declare(strict_types=1);
namespace POPRecoveryEmail\Admin;
defined('ABSPATH') || exit;

final class HelpPage {
    public function render(): void {
        ?>
        <div class="prer-help-page">

            <!-- Quick Start -->
            <div class="prer-card">
                <div class="prer-help-section-header">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    <h2 class="prer-card-title" style="margin:0">Quick Start</h2>
                </div>
                <div class="prer-help-content">
                    <p>POP Recovery Email Rules automatically sends a single recovery email to customers whose payments fail. It integrates directly with Payment Orchestrator Pro (POP) v2.5.7+ and requires no manual intervention once configured.</p>
                    <div class="prer-help-steps">
                        <div class="prer-help-step">
                            <span class="prer-step-num">1</span>
                            <div>
                                <strong>Enable the plugin</strong>
                                <p>Go to <a href="<?php echo admin_url('admin.php?page=pop-email-recovery&tab=settings'); ?>">Settings</a> and make sure "Enable Email Recovery" is toggled on.</p>
                            </div>
                        </div>
                        <div class="prer-help-step">
                            <span class="prer-step-num">2</span>
                            <div>
                                <strong>Set up a template</strong>
                                <p>Go to <a href="<?php echo admin_url('admin.php?page=pop-email-recovery&tab=templates'); ?>">Templates</a>. A default template is created on activation. Edit it, preview with real order data, and set it as active.</p>
                            </div>
                        </div>
                        <div class="prer-help-step">
                            <span class="prer-step-num">3</span>
                            <div>
                                <strong>Configure timing &amp; categories</strong>
                                <p>In Settings, set the delay (how long after failure before sending), select which failure categories trigger emails, and set your from name/email.</p>
                            </div>
                        </div>
                        <div class="prer-help-step">
                            <span class="prer-step-num">4</span>
                            <div>
                                <strong>Send a test email</strong>
                                <p>In the template editor, select a real failed order from the "Preview with" dropdown and click "Send Test" to see exactly what a customer would receive.</p>
                            </div>
                        </div>
                        <div class="prer-help-step">
                            <span class="prer-step-num">5</span>
                            <div>
                                <strong>Monitor results</strong>
                                <p>The <a href="<?php echo admin_url('admin.php?page=pop-email-recovery&tab=dashboard'); ?>">Dashboard</a> shows real-time stats. <a href="<?php echo admin_url('admin.php?page=pop-email-recovery&tab=reports'); ?>">Reports</a> gives you full send history, conversion funnel, and revenue attribution.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- How It Works -->
            <div class="prer-card">
                <div class="prer-help-section-header">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <h2 class="prer-card-title" style="margin:0">How It Works</h2>
                </div>
                <div class="prer-help-content">
                    <div class="prer-help-flow">
                        <div class="prer-flow-step"><span class="prer-flow-num">&#x2460;</span> Customer's payment fails at checkout</div>
                        <div class="prer-flow-arrow">&#x2193;</div>
                        <div class="prer-flow-step"><span class="prer-flow-num">&#x2461;</span> POP classifies the failure (retryable gateway, card type, non-retryable, etc.)</div>
                        <div class="prer-flow-arrow">&#x2193;</div>
                        <div class="prer-flow-step"><span class="prer-flow-num">&#x2462;</span> POP fires the <code>po_recovery_eligible</code> hook</div>
                        <div class="prer-flow-arrow">&#x2193;</div>
                        <div class="prer-flow-step"><span class="prer-flow-num">&#x2463;</span> This plugin checks all guard rails (category match, min value, unsubscribe list, rate limits, active template)</div>
                        <div class="prer-flow-arrow">&#x2193;</div>
                        <div class="prer-flow-step" style="background:#fefce8;border-color:#eab308"><span class="prer-flow-num" style="background:#eab308">&#x2464;</span> <strong>Two paths based on failure type:</strong></div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:16px 0 24px">
                        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:16px">
                            <strong style="color:#dc2626">&#x26A1; Non-Retryable &#x2192; Short Delay (or Instant)</strong>
                            <p style="margin:8px 0 0;font-size:13px;color:#64748b">POP won't auto-redirect these (expired card, CVV mismatch, etc). Email is queued for the Non-Retryable Delay (default 2 min) and cancelled if the customer pays first. Set the delay to 0 to send instantly inline.</p>
                        </div>
                        <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:10px;padding:16px">
                            <strong style="color:#2563eb">&#x1F504; Retryable &#x2192; Queued with Delay</strong>
                            <p style="margin:8px 0 0;font-size:13px;color:#64748b">POP may auto-redirect to another gateway. Email queued with delay (default 3 min). If POP recovers the order first, the email is cancelled automatically. Otherwise, cron sends it when the delay expires.</p>
                        </div>
                    </div>

                    <div class="prer-help-flow">
                        <div class="prer-flow-step"><span class="prer-flow-num">&#x2465;</span> Customer receives email with a tracked pay link</div>
                        <div class="prer-flow-arrow">&#x2193;</div>
                        <div class="prer-flow-step"><span class="prer-flow-num">&#x2466;</span> Customer clicks the link &#x2192; tracked &#x2192; redirected to checkout &#x2192; pays &#x2192; POP marks as recovered</div>
                    </div>

                    <h3 class="prer-help-subtitle">On-Hold / Pending Orders (Adyen 3DS)</h3>
                    <p>When enabled, the plugin monitors on-hold orders from configured gateways (e.g. Adyen). If a customer starts 3DS authentication and abandons it, the order stays on-hold indefinitely. The plugin:</p>
                    <div class="prer-help-list">
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Sends a recovery email after the configured on-hold delay (e.g. 3 minutes)</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Auto-cancels the order after the configured cancel window (e.g. 90 minutes) to release stock</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> If the customer completes payment before auto-cancel, the email sequence is cancelled</div>
                    </div>

                    <h3 class="prer-help-subtitle">Automatic Cancellation</h3>
                    <p>Pending emails are automatically cancelled if any of these happen before the send time:</p>
                    <div class="prer-help-list">
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Customer pays the order (via account page, pay link, or any other method)</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Order status changes to processing, completed, cancelled, or refunded</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> POP fires <code>po_order_recovered</code> (auto-retry succeeded)</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Customer clicks the unsubscribe link in a previous email</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> POP's <code>isRecoveryActive()</code> returns false for any reason</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Sequence exceeds the expiry window (default: 72 hours)</div>
                    </div>
                    <p><strong>This means a customer who already completed their order will never receive a recovery email.</strong></p>
                </div>
            </div>

            <!-- Settings Reference -->
            <div class="prer-card">
                <div class="prer-help-section-header">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                    <h2 class="prer-card-title" style="margin:0">Settings Reference</h2>
                </div>
                <div class="prer-help-content">
                    <table class="prer-help-table">
                        <thead><tr><th>Setting</th><th>Default</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><strong>Enable Email Recovery</strong></td><td>On</td><td>Master toggle. When off, no emails are queued or sent.</td></tr>
                            <tr><td><strong>From Name</strong></td><td>Site name</td><td>The sender name that appears in the customer's inbox.</td></tr>
                            <tr><td><strong>From Email</strong></td><td>Admin email</td><td>The sender email address. Use a verified domain for best deliverability.</td></tr>
                            <tr><td><strong>CC Admin</strong></td><td>Off</td><td>When enabled, sends a BCC copy of every recovery email to the From Email address. Useful for monitoring &#x2014; turn off once satisfied.</td></tr>
                            <tr><td><strong>Retryable Failure Delay</strong></td><td>3 min</td><td>Delay for retryable gateway and card type failures. Gives POP's auto-redirect time to attempt a retry first.</td></tr>
                            <tr><td><strong>Non-Retryable Failure Delay</strong></td><td>2 min</td><td>Delay for non-retryable failures (expired card, CVV mismatch, insufficient funds). 0 = send instantly. Queued emails go out on the next 5-minute cron run after the delay.</td></tr>
                            <tr><td><strong>On-Hold / Pending Recovery</strong></td><td>Off</td><td>When enabled, monitors orders stuck in on-hold or pending status from specified gateways (e.g. Adyen 3DS abandoned). Sends a "complete your order" email instead of a "payment failed" email.</td></tr>
                            <tr><td><strong>On-Hold Delay</strong></td><td>120 min</td><td>Delay for on-hold orders. Longer than failure delays because the payment may still be processing (e.g. Adyen webhook pending).</td></tr>
                            <tr><td><strong>Monitor Gateways</strong></td><td>adyen</td><td>Comma-separated gateway IDs whose on-hold orders to monitor. Matches against WC payment method and POP failed gateway meta. Leave blank to monitor all.</td></tr>
                            <tr><td><strong>Auto-Cancel On-Hold After</strong></td><td>90 min</td><td>Automatically cancels on-hold orders from monitored gateways after this period. WooCommerce restocks items automatically. Set to 0 to disable. An order note is added explaining the cancellation.</td></tr>
                            <tr><td><strong>Min Order Value</strong></td><td>$0</td><td>Orders below this amount won't trigger recovery emails.</td></tr>
                            <tr><td><strong>Sequence Expiry</strong></td><td>72 hours</td><td>Pending emails older than this are automatically cancelled. Prevents stale emails from being sent days later.</td></tr>
                            <tr><td><strong>Unsubscribe Expiry</strong></td><td>30 days</td><td>After this many days, an unsubscribed customer becomes eligible for recovery emails again. Set to 0 for permanent unsubscribes (never expires).</td></tr>
                            <tr><td><strong>Max Failures / Customer (24h)</strong></td><td>2</td><td>If a customer has this many or more failures in 24 hours, skip sending. Likely a persistent card issue, not a temporary glitch.</td></tr>
                            <tr><td><strong>Debug Logging</strong></td><td>On</td><td>Writes detailed logs to <code>wp-content/prer-logs-{random}/</code>. 3-day rolling cycle &#x2014; old files are automatically deleted. Visible in the Dashboard tab.</td></tr>
                        </tbody>
                    </table>

                    <h3 class="prer-help-subtitle">Failure Categories</h3>
                    <table class="prer-help-table">
                        <thead><tr><th>Category</th><th>When to Enable</th><th>Email Behavior</th></tr></thead>
                        <tbody>
                            <tr><td><strong>Retryable Gateway</strong></td><td>Recommended &#x2014; always</td><td>Queued with delay (default 3 min) to give POP's auto-redirect time to recover the order first. If POP recovers it, the email is cancelled automatically.</td></tr>
                            <tr><td><strong>Retryable Card Type</strong></td><td>Recommended &#x2014; always</td><td>Same as retryable gateway &#x2014; queued with delay.</td></tr>
                            <tr><td><strong>Non-Retryable</strong></td><td>Recommended</td><td>Queued for the Non-Retryable Delay (default 2 min; 0 = instant inline send). Cancelled automatically if the customer pays first.</td></tr>
                            <tr><td><strong>Other / Unknown</strong></td><td>Optional</td><td>Queued with retryable delay. Unclassified failures that don't fit other categories.</td></tr>
                        </tbody>
                    </table>

                    <h3 class="prer-help-subtitle">NMI Failure Code Reference</h3>
                    <p>This is the complete classification matrix from POP's FailureClassifier. It determines which category each failure falls into and whether POP will attempt an auto-redirect to a different gateway.</p>

                    <h4 style="margin:20px 0 10px;font-size:14px;color:#0284c7">&#x1F504; Retryable &#x2014; Gateway Problems</h4>
                    <p style="margin:0 0 10px;font-size:13px;color:#64748b">The customer's card is fine. The issue is on the gateway/processor side. POP will auto-redirect to a different gateway.</p>
                    <table class="prer-help-table">
                        <thead><tr><th style="width:60px">Code</th><th>Reason</th><th>What Happened</th></tr></thead>
                        <tbody>
                            <tr><td><code>400</code></td><td>General Error</td><td>Transaction approved but gateway returned error</td></tr>
                            <tr><td><code>410</code></td><td>Invalid Merchant ID</td><td>Gateway misconfiguration or MID issue</td></tr>
                            <tr><td><code>420</code></td><td>Response Timeout</td><td>Gateway didn't respond in time</td></tr>
                            <tr><td><code>440</code></td><td>Re-enter transaction / Invalid amount</td><td>Gateway-side processing error (specific sub-reasons only)</td></tr>
                            <tr><td><code>300</code></td><td>Payment Token / Duplicate / Auth Failed</td><td>Gateway-side token or processing error (specific sub-reasons only)</td></tr>
                            <tr><td colspan="3" style="border:0;padding:8px 0 4px;font-size:12px;color:#64748b"><em>Non-standard (no code):</em></td></tr>
                            <tr><td><code>&#x2014;</code></td><td>cURL error / Timed out</td><td>Connection failure between server and gateway</td></tr>
                            <tr><td><code>&#x2014;</code></td><td>Gateway response error</td><td>Unrecognized or malformed gateway response</td></tr>
                        </tbody>
                    </table>

                    <h4 style="margin:20px 0 10px;font-size:14px;color:#0284c7">&#x1F504; Retryable &#x2014; Card Type</h4>
                    <p style="margin:0 0 10px;font-size:13px;color:#64748b">The gateway doesn't accept this card brand. POP will redirect to a gateway that does.</p>
                    <table class="prer-help-table">
                        <thead><tr><th style="width:60px">Code</th><th>Reason</th><th>What Happened</th></tr></thead>
                        <tbody>
                            <tr><td><code>*</code></td><td>"cc payment type [Amex] not accepted"</td><td>Card brand rejected &#x2014; will try gateway that accepts it</td></tr>
                        </tbody>
                    </table>

                    <h4 style="margin:20px 0 10px;font-size:14px;color:#f59e0b">&#x26A0;&#xFE0F; High-Risk Retryable (when enabled in POP)</h4>
                    <p style="margin:0 0 10px;font-size:13px;color:#64748b">These codes are normally non-retryable but can be reclassified as retryable in POP's high-risk mode. Bank declines are often MID-dependent &#x2014; a different gateway with a different MID may succeed.</p>
                    <table class="prer-help-table">
                        <thead><tr><th style="width:60px">Code</th><th>Reason</th><th>Why Retryable in High-Risk Mode</th></tr></thead>
                        <tbody>
                            <tr><td><code>201</code></td><td>Do Not Honor</td><td>Generic bank decline &#x2014; often based on MID risk profile, not the card itself</td></tr>
                            <tr><td><code>253</code></td><td>Suspected Fraud</td><td>Fraud flag triggered by MID velocity/profile, may clear on different MID</td></tr>
                        </tbody>
                    </table>
                    <p style="font-size:13px;color:#64748b">High-risk mode is configured in POP settings: <strong>auto</strong> (next available gateway) or <strong>specific</strong> (a chosen gateway). When off, 201 and 253 fall through to non-retryable below. Customer message: "Transaction could not be completed. No charge was made. Please retry or use another method."</p>

                    <h4 style="margin:20px 0 10px;font-size:14px;color:#dc2626">&#x1F6AB; Non-Retryable &#x2014; Customer Problems</h4>
                    <p style="margin:0 0 10px;font-size:13px;color:#64748b">The problem is with the customer's card. Trying a different gateway won't help. POP will NOT auto-redirect. Recovery email sends instantly.</p>
                    <table class="prer-help-table">
                        <thead><tr><th style="width:60px">Code</th><th>Reason</th><th>Customer Message in Email</th></tr></thead>
                        <tbody>
                            <tr><td><code>201</code></td><td>Do Not Honor</td><td>Your card was declined by your bank. Please try a different card or contact your bank.</td></tr>
                            <tr><td><code>202</code></td><td>Insufficient Funds</td><td>Your card has insufficient funds. Please try a different card or contact your bank.</td></tr>
                            <tr><td><code>203</code></td><td>Exceeds Limit</td><td>Your card limit has been exceeded. Please try a different card or contact your bank.</td></tr>
                            <tr><td><code>220</code></td><td>Invalid Card Number</td><td>The card number appears to be invalid. Please double-check and try again.</td></tr>
                            <tr><td><code>222</code></td><td>Account Closed</td><td>This card account has been closed. Please use a different card.</td></tr>
                            <tr><td><code>223</code></td><td>Expired Card</td><td>This card has expired. Please use a different card.</td></tr>
                            <tr><td><code>225</code></td><td>CVV Mismatch</td><td>The security code (CVV) doesn't match. Please check the 3 or 4 digit code on your card and try again.</td></tr>
                            <tr><td><code>240</code></td><td>Contact Card Issuer</td><td>Your card was declined. Please contact your bank or use a different card.</td></tr>
                            <tr><td><code>250</code></td><td>Pick Up Card</td><td>This card cannot be used for this transaction. Please contact your bank or use a different card.</td></tr>
                            <tr><td><code>251</code></td><td>Pick Up Card (Lost)</td><td>This card cannot be used for this transaction. Please contact your bank or use a different card.</td></tr>
                            <tr><td><code>252</code></td><td>Pick Up Card (Stolen)</td><td>This card cannot be used for this transaction. Please contact your bank or use a different card.</td></tr>
                            <tr><td><code>253</code></td><td>Suspected Fraud</td><td>This card cannot be used for this transaction. Please contact your bank or use a different card.</td></tr>
                            <tr><td><code>261</code></td><td>Stop Recurring</td><td>Recurring payments have been stopped on this card. Please use a different card.</td></tr>
                            <tr><td><code>441</code></td><td>Invalid Transaction</td><td>This transaction could not be processed. Please try again or use a different card.</td></tr>
                            <tr><td colspan="3" style="border:0;padding:8px 0 4px;font-size:12px;color:#64748b"><em>Sub-reason matches (code alone is ambiguous):</em></td></tr>
                            <tr><td><code>204</code></td><td>Blocked / Not Permitted / Issuer Declined / No Credit Account</td><td>This transaction is not permitted by your bank. Please try a different card or contact your bank.</td></tr>
                            <tr><td><code>300</code></td><td>AVS Rejected / Declined / Invalid CC# / Card Expiration / CVV / Amount Exceeds</td><td>There was an issue processing your payment. Please check your card details and try again.</td></tr>
                            <tr><td><code>440</code></td><td>Security Violation</td><td>There was a security issue with this transaction. Please try again or use a different card.</td></tr>
                            <tr><td><code>&#x2014;</code></td><td>"Credit card details cannot be left incomplete"</td><td>Please fill in all credit card fields and try again.</td></tr>
                        </tbody>
                    </table>
                    <p style="font-size:13px;color:#64748b"><strong>Note:</strong> Codes 201 and 253 appear in both retryable (high-risk mode) and non-retryable sections. When high-risk mode is enabled in POP, they're classified as retryable and POP will auto-redirect. When high-risk mode is off, they fall through to non-retryable and the recovery email sends instantly.</p>

                    <h4 style="margin:20px 0 10px;font-size:14px;color:#94a3b8">&#x2753; Other / Unknown</h4>
                    <p style="margin:0 0 10px;font-size:13px;color:#64748b">Failure codes that don't match any of the above. Not retried by POP, doesn't count against gateway health. Queued with retryable delay if the "Other" category is enabled.</p>
                </div>
            </div>

            <!-- Templates -->
            <div class="prer-card">
                <div class="prer-help-section-header">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <h2 class="prer-card-title" style="margin:0">Templates</h2>
                </div>
                <div class="prer-help-content">
                    <p>Templates control the email content sent to customers. Only <strong>one template can be active at a time</strong>. You can create multiple templates, test them, and switch the active one without any downtime.</p>

                    <h3 class="prer-help-subtitle">Template Editor</h3>
                    <div class="prer-help-list">
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Code tab:</strong> Write or paste HTML email code with syntax highlighting (CodeMirror).</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Visual Preview tab:</strong> Read-only rendered preview of the email, exactly as the customer would see it. Editing is done in the Code tab &#x2014; the preview auto-refreshes when you switch tabs, or click the Refresh button.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Preview with real orders:</strong> Select a recent failed order from the dropdown to preview with actual customer data, items, amounts, and failure reasons.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Send Test:</strong> Send a real email to any address using the current editor content &#x2014; no need to save first. Uses the selected order data.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Merge Tag sidebar:</strong> Click any tag to insert it at the cursor position in the subject line or HTML body.</div>
                    </div>

                    <h3 class="prer-help-subtitle">Available Merge Tags</h3>
                    <table class="prer-help-table">
                        <thead><tr><th>Tag</th><th>Description</th><th>Example Output</th></tr></thead>
                        <tbody>
                            <tr><td><code>{{customer_name}}</code></td><td>Billing first name</td><td>John</td></tr>
                            <tr><td><code>{{customer_last_name}}</code></td><td>Billing last name</td><td>Smith</td></tr>
                            <tr><td><code>{{customer_full_name}}</code></td><td>Full name</td><td>John Smith</td></tr>
                            <tr><td><code>{{customer_email}}</code></td><td>Customer email</td><td>john@example.com</td></tr>
                            <tr><td><code>{{order_number}}</code></td><td>WooCommerce order ID</td><td>166210</td></tr>
                            <tr><td><code>{{order_total}}</code></td><td>Formatted total with currency</td><td>$149.99</td></tr>
                            <tr><td><code>{{order_items}}</code></td><td>Line item summary</td><td>Product A &#xD7; 2, Product B &#xD7; 1</td></tr>
                            <tr><td><code>{{order_date}}</code></td><td>Date order was placed</td><td>Feb 17, 2026</td></tr>
                            <tr><td><code>{{cancel_deadline}}</code></td><td>Auto-cancel deadline for on-hold orders</td><td>90 minutes</td></tr>
                            <tr><td><code>{{failure_message}}</code></td><td>Customer-friendly failure reason (pulled from POP's per-code messages)</td><td>The security code (CVV) doesn't match. Please check the 3 or 4 digit code on your card and try again.</td></tr>
                            <tr><td><code>{{failure_code}}</code></td><td>Raw failure code from gateway</td><td>253</td></tr>
                            <tr><td><code>{{card_type}}</code></td><td>Card brand used</td><td>Visa</td></tr>
                            <tr><td><code>{{pay_url}}</code></td><td>Tracked checkout link</td><td>(secure URL with tracking)</td></tr>
                            <tr><td><code>{{unsubscribe_url}}</code></td><td>One-click unsubscribe</td><td>(token-based URL)</td></tr>
                            <tr><td><code>{{site_name}}</code></td><td>Your store name</td><td>Your Store Name</td></tr>
                            <tr><td><code>{{site_logo_url}}</code></td><td>Site logo image URL</td><td>https://yourstore.com/logo.png</td></tr>
                            <tr><td><code>{{support_email}}</code></td><td>Support email address</td><td>support@yourstore.com</td></tr>
                            <tr><td><code>{{current_year}}</code></td><td>Current year</td><td>2026</td></tr>
                        </tbody>
                    </table>

                    <h3 class="prer-help-subtitle">Template Tips</h3>
                    <div class="prer-help-list">
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Use <code>{{site_logo_url}}</code> in an <code>&lt;img&gt;</code> tag to show your store logo instead of text. It pulls from WooCommerce email header image &#x2192; Customizer logo &#x2192; site icon, in that order.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Always include <code>{{pay_url}}</code> in a prominent CTA button &#x2014; this is how customers complete their order.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Always include <code>{{unsubscribe_url}}</code> in the footer &#x2014; legally required for commercial emails.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Include <code>{{open_track_url}}</code> as a 1&#xD7;1 pixel image for open tracking: <code>&lt;img src="{{open_track_url}}" width="1" height="1"&gt;</code></div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Use <code>{{failure_message}}</code> instead of <code>{{failure_code}}</code> in customer-facing content &#x2014; it's a friendly message, not a raw error code.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Use inline CSS for email compatibility. External stylesheets won't work in most email clients.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Keep email width at 600px max for mobile compatibility.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Duplicate the default template before making major changes &#x2014; you can always switch back.</div>
                    </div>
                </div>
            </div>

            <!-- Tracking & Reports -->
            <div class="prer-card">
                <div class="prer-help-section-header">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    <h2 class="prer-card-title" style="margin:0">Tracking &amp; Reports</h2>
                </div>
                <div class="prer-help-content">
                    <h3 class="prer-help-subtitle">How Tracking Works</h3>
                    <div class="prer-help-list">
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Open tracking:</strong> A tiny 1&#xD7;1 transparent pixel is embedded in each email. When the email client loads images, the open is recorded. Note: some email clients block images by default, so open rates may be lower than actual opens.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Click tracking:</strong> The pay URL in the email goes through a redirect. When clicked, it records the click and redirects the customer to their actual checkout page.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Recovery attribution:</strong> An order counts as <em>recovered by email</em> only when the customer <strong>clicked the email link and paid that same order on the payment page it opened</strong>. Orders paid any other way after an email was sent &#x2014; no click, or a click followed by paying from checkout or a new order &#x2014; are recorded as <code>paid_direct</code> ("Paid directly") and are not credited to email. With Payment Orchestrator Pro 2.7.28 or later, POP credits the email in its own reports by the same rule.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Payment-page funnel:</strong> After a click, the plugin records whether the customer reached the payment page, how many payment attempts they made there, and the reason for the latest decline (requires Payment Orchestrator Pro 2.7.28 or later).</div>
                    </div>

                    <h3 class="prer-help-subtitle">Reports Breakdown</h3>
                    <table class="prer-help-table">
                        <thead><tr><th>Section</th><th>What It Shows</th></tr></thead>
                        <tbody>
                            <tr><td><strong>KPI Cards</strong></td><td>Emails sent, open rate, click rate, recovery rate, revenue recovered, unsubscribes for the selected period.</td></tr>
                            <tr><td><strong>Conversion Funnel</strong></td><td>Sent &#x2192; Opened &#x2192; Clicked &#x2192; Landed on pay page &#x2192; Attempted payment &#x2192; Paid via link. Shows where customers drop off; "Paid via link" is the email's success measure.</td></tr>
                            <tr><td><strong>Recovery Attribution</strong></td><td>Revenue breakdown by source (email, auto-redirect, SMS, manual) from POP's <code>po_failed_transactions</code> table.</td></tr>
                            <tr><td><strong>Daily Activity</strong></td><td>Day-by-day breakdown of queued, sent, recovered counts with recovery rate bars and revenue.</td></tr>
                            <tr><td><strong>Send History</strong></td><td>Every email sent: timestamp, order, customer, subject, amount, open/click status, and conversion outcome. Paginated.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Safety & Compliance -->
            <div class="prer-card">
                <div class="prer-help-section-header">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <h2 class="prer-card-title" style="margin:0">Safety &amp; Compliance</h2>
                </div>
                <div class="prer-help-content">
                    <h3 class="prer-help-subtitle">Guard Rails</h3>
                    <p>Multiple layers prevent over-emailing or sending inappropriate emails:</p>
                    <table class="prer-help-table">
                        <thead><tr><th>Guard</th><th>What It Does</th></tr></thead>
                        <tbody>
                            <tr><td><strong>One email per customer</strong></td><td>Only one pending recovery email per email address at a time. If a customer has a pending email, new failures for the same email are skipped.</td></tr>
                            <tr><td><strong>24-hour rate limit</strong></td><td>If a customer has 2+ recovery sequences in 24 hours (configurable), recovery emails are skipped. This prevents spam when there's a persistent card issue.</td></tr>
                            <tr><td><strong>Unsubscribe list</strong></td><td>Customers who unsubscribe are permanently excluded. Checked before every send.</td></tr>
                            <tr><td><strong>Pre-send verification</strong></td><td>Right before sending (at cron time), the scheduler calls POP's <code>isRecoveryActive()</code> to verify the order still needs recovery. If the customer already paid, the email is cancelled.</td></tr>
                            <tr><td><strong>Order status monitoring</strong></td><td>If an order moves to processing, completed, cancelled, or refunded, any pending email is immediately cancelled.</td></tr>
                            <tr><td><strong>Category filtering</strong></td><td>Only failure categories you've enabled will trigger emails. You control exactly which types of failures are emailed.</td></tr>
                            <tr><td><strong>Minimum order value</strong></td><td>Orders below the threshold don't trigger emails.</td></tr>
                            <tr><td><strong>Sequence expiry</strong></td><td>Pending emails older than 72 hours (configurable) are automatically cancelled.</td></tr>
                            <tr><td><strong>POP-only scope</strong></td><td>Only orders processed through POP-managed gateways are affected. Orders from non-POP gateways are never touched.</td></tr>
                        </tbody>
                    </table>

                    <h3 class="prer-help-subtitle">Unsubscribe Compliance</h3>
                    <div class="prer-help-list">
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Every email includes an unsubscribe link (no login required). Opening it shows a confirm button, so email security scanners that pre-open links can't unsubscribe customers by accident. Mail clients' built-in one-click unsubscribe (RFC 8058) works without the extra click.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <code>List-Unsubscribe</code> and <code>List-Unsubscribe-Post</code> headers included for email client support</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Unsubscribed customers see a branded confirmation page</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> All active sequences for that email are immediately cancelled</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Unsubscribe expires after the configured period (default: 30 days), so customers with legitimate failures later can be reached again</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Set expiry to 0 for permanent unsubscribes (never auto-expires)</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Only affects this plugin</strong> &#x2014; WooCommerce order emails, shipping notifications, and all other site emails are completely unaffected</div>
                    </div>

                    <h3 class="prer-help-subtitle">Customer-Facing Messages</h3>
                    <p>The <code>{{failure_message}}</code> tag pulls directly from POP's per-failure-code <code>customer_message</code> field stored in the <code>po_failed_transactions</code> table. This ensures the email message matches exactly what POP shows the customer at checkout. Fallback order: POP table &#x2192; order meta (<code>_po_retry_customer_message</code>) &#x2192; generic default.</p>
                    <p>See the <strong>Non-Retryable</strong> table above for the full list of codes and the customer-facing messages used in emails. For retryable failures, the default message is: "There was an error processing your payment. Please try again."</p>
                    <p>For on-hold/pending orders (no POP failure record), the message defaults to: "It looks like your payment wasn't fully completed. Your order will be automatically cancelled in [deadline] to release stock. Please click below to finish checkout before then." (If auto-cancel is disabled, the message omits the deadline.)</p>
                </div>
            </div>

            <!-- Debug Logging -->
            <div class="prer-card">
                <div class="prer-help-section-header">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                    <h2 class="prer-card-title" style="margin:0">Debug Logging</h2>
                </div>
                <div class="prer-help-content">
                    <p>When Debug Logging is enabled (Settings), the plugin writes detailed logs to <code>wp-content/prer-logs-{random}/</code>.</p>
                    <div class="prer-help-list">
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Location:</strong> <code>wp-content/prer-logs-{random}/prer-YYYY-MM-DD.log</code> (the folder name contains a random per-site key)</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Retention:</strong> 3-day rolling cycle. Files older than 3 days are automatically deleted on every write.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Security:</strong> Unguessable folder name plus <code>.htaccess</code>/<code>web.config</code> deny rules. Note: Nginx hosts ignore <code>.htaccess</code>, so the random folder name is the main protection there. Logs contain customer emails and pay links &#x2014; keep debug logging off when not troubleshooting.</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> <strong>Viewer:</strong> Available in the Dashboard tab at the bottom of the page. Select date, refresh to see latest.</div>
                    </div>

                    <h3 class="prer-help-subtitle">What Gets Logged</h3>
                    <table class="prer-help-table">
                        <thead><tr><th>Event</th><th>Level</th><th>Details Included</th></tr></thead>
                        <tbody>
                            <tr><td>POP hook fires</td><td>INFO</td><td>order_id, category, failure_code, retry_eligible</td></tr>
                            <tr><td>Email queued</td><td>INFO</td><td>order_id, email, category, template_id, send_at, delay</td></tr>
                            <tr><td>Guard rail skip</td><td>DEBUG/INFO</td><td>Reason (unsubscribed, duplicate, min value, category, excessive failures, no template)</td></tr>
                            <tr><td>Queue processing</td><td>INFO</td><td>Count of sequences to process</td></tr>
                            <tr><td>Pre-send cancellation</td><td>INFO</td><td>order_id, reason (no longer active, unsubscribed, no pay URL)</td></tr>
                            <tr><td>Email sent</td><td>INFO</td><td>order_id, email, template_id, BCC status</td></tr>
                            <tr><td>Email send failure</td><td>ERROR</td><td>order_id, email, from address</td></tr>
                            <tr><td>Template not found</td><td>ERROR</td><td>template_id, order_id</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Technical Details -->
            <div class="prer-card">
                <div class="prer-help-section-header">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0891b2" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    <h2 class="prer-card-title" style="margin:0">Technical Details</h2>
                </div>
                <div class="prer-help-content">
                    <h3 class="prer-help-subtitle">Requirements</h3>
                    <div class="prer-help-list">
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> Payment Orchestrator Pro v2.5.7 or later</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> WordPress 6.0+</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> WooCommerce 8.0+ (HPOS compatible)</div>
                        <div class="prer-help-list-item"><span class="prer-check">&#x2713;</span> PHP 8.2+</div>
                    </div>

                    <h3 class="prer-help-subtitle">Database Tables</h3>
                    <table class="prer-help-table">
                        <thead><tr><th>Table</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <tr><td><code>{prefix}_po_email_templates</code></td><td>Stores email templates (name, subject, HTML body, active status)</td></tr>
                            <tr><td><code>{prefix}_po_email_sequences</code></td><td>Tracks queued/sent/recovered emails per order</td></tr>
                            <tr><td><code>{prefix}_po_email_log</code></td><td>Individual send records with tracking data</td></tr>
                            <tr><td><code>{prefix}_po_email_unsubscribes</code></td><td>Customer opt-out list</td></tr>
                        </tbody>
                    </table>

                    <h3 class="prer-help-subtitle">WP Cron Jobs</h3>
                    <table class="prer-help-table">
                        <thead><tr><th>Hook</th><th>Interval</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <tr><td><code>prer_process_email_queue</code></td><td>Every 5 minutes</td><td>Processes pending emails that have passed their delay time. Up to 50 per run.</td></tr>
                            <tr><td><code>prer_purge_old_data</code></td><td>Daily</td><td>Deletes completed/cancelled sequences and log entries older than 90 days.</td></tr>
                        </tbody>
                    </table>

                    <h3 class="prer-help-subtitle">POP Integration Points</h3>
                    <table class="prer-help-table">
                        <thead><tr><th>Hook / Method</th><th>Direction</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <tr><td><code>po_recovery_eligible</code></td><td>POP &#x2192; This plugin</td><td>Fired when POP classifies a payment failure. Triggers email queueing.</td></tr>
                            <tr><td><code>po_order_recovered</code></td><td>POP &#x2192; This plugin</td><td>Fired when a failed order is recovered. Cancels pending email.</td></tr>
                            <tr><td><code>FailureHandler::isRecoveryActive()</code></td><td>This plugin &#x2192; POP</td><td>Called before each send to verify order still needs recovery.</td></tr>
                            <tr><td><code>FailureHandler::getRecoveryPayUrl()</code></td><td>This plugin &#x2192; POP</td><td>Fallback pay URL when a link is clicked. Since 1.5.8 the email source is stamped on click, never when the email is sent.</td></tr>
                        </tbody>
                    </table>

                    <h3 class="prer-help-subtitle">Clean Uninstall</h3>
                    <p>Deactivating the plugin stops all cron jobs and no more emails will be sent. Uninstalling (deleting) the plugin removes all database tables, options, cron jobs, and log files. This is completely reversible by reinstalling.</p>
                </div>
            </div>

            <!-- Version History -->
            <div class="prer-card">
                <div class="prer-help-section-header">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <h2 class="prer-card-title" style="margin:0">Version History</h2>
                </div>
                <div class="prer-help-content">
                    <div class="prer-changelog">
                        <div class="prer-changelog-entry">
                            <strong>v1.5.10</strong> <span class="prer-changelog-date">Current</span>
                            <p><strong>Clear page for orders that no longer need payment.</strong> When a customer clicks a recovery email for an order that is already paid, they now see "Good news &#x2014; your order is already paid" with the order number, payment date, total and current status in plain words (Processing, Shipped, Delivered, Completed, On hold), plus "View my order" (signed-in owner) and "Continue shopping". Cancelled or refunded orders say so ("No payment is due"). Previously paid orders went to the checkout "order received" page, which looked like a new purchase and could fire purchase tracking again, and cancelled orders went silently to the shop. The page is mobile-friendly, hidden from search engines, shows no address, and falls back to the shop if anything goes wrong. All source files are now plain ASCII (security-scanner friendly); output unchanged.</p>
                        </div>
                        <div class="prer-changelog-entry">
                            <strong>v1.5.9</strong>
                            <p><strong>Email success = clicked and paid the same order via the email's pay link.</strong> Paying another way after a click no longer counts. New payment-page funnel (landed, attempts, last decline reason) using Payment Orchestrator Pro 2.7.28 events; Reports funnel extended; dashboard outcome labels follow the actual records ("&#x2709; Email", "&#x26A1; POP", "Paid directly"). One-time history correction: older records marked "recovered" without a click are relabelled "Paid directly". Database 1.3.0 (columns added and verified automatically).</p>
                        </div>
                        <div class="prer-changelog-entry">
                            <strong>v1.5.8</strong>
                            <p><strong>Critical:</strong> recovery-email clicks were redirected to the homepage. The <code>pay_url</code> column was added to the table definition in a later version, but tables were only created or updated on plugin <em>activation</em>, and in-place updates never re-activate the plugin (the schema version also stayed at 1.1.0). The column was never added, so every click lookup failed. Added an automatic versioned database upgrade that runs after any update, verifies the column exists and adds it directly if needed, and made click tracking resilient to schema problems. Debug logs moved to an unguessable folder (the old <code>.htaccess</code> protection does not work on Nginx) and legacy logs are removed. Unsubscribe now follows RFC 8058 (scanner-safe confirm page; repeat unsubscribes restart the expiry window). Merge-tag values are HTML-escaped in emails. Template editor no longer adds slashes or strips <code>&lt;head&gt;</code>/<code>&lt;style&gt;</code> for admins. Merge tags no longer insert into both subject and body. Click handling now runs after WooCommerce has registered its orders (it ran too early to load the order, so paid/closed-order redirects and attribution never worked). Cancelled/refunded orders no longer land on a thank-you page. HPOS-aware order links. Uses WooCommerce order numbers in emails. <strong>Also:</strong> recoveries are only credited to email when the link was clicked (new <code>paid_direct</code> status for orders paid another way), and the order's <code>recovery_source</code> is stamped on click instead of when the email is sent, so POP no longer credits unclicked emails; the Non-Retryable Delay setting now works (0 = instant); an unsubscribe link is added automatically to templates that lack <code>{{unsubscribe_url}}</code>; on-hold auto-cancel no longer gets stuck behind unmonitored on-hold orders.</p>
                        </div>
                        <div class="prer-changelog-entry">
                            <strong>v1.3.0</strong>
                            <p>Fixed critical bug: non-retryable emails stuck in queue because POP's <code>isRecoveryActive()</code> requires <code>retry_eligible=yes</code>. Scheduler now uses smart per-category verification &#x2014; checks order status directly for non-retryable/pending. Per-category delay settings: retryable (3 min), non-retryable (2 min), on-hold (120 min). On-hold gateway monitoring with configurable gateway list (e.g. Adyen). Auto-cancel on-hold orders after configurable period (default 90 min) to release stock. New <code>{{cancel_deadline}}</code> merge tag. Category-aware fallback messages for pending orders with deadline reference.</p>
                        </div>
                        <div class="prer-changelog-entry">
                            <strong>v1.2.6</strong>
                            <p>Added <code>{{site_logo_url}}</code> merge tag (pulls from WooCommerce email header &#x2192; Customizer logo &#x2192; site icon). Unsubscribe now has configurable expiry (default 30 days, 0 = permanent). Removed site-specific references from Help documentation. Updated default template to use logo in header.</p>
                        </div>
                        <div class="prer-changelog-entry">
                            <strong>v1.2.5</strong>
                            <p>Failure messages now pulled from POP's per-code <code>customer_message</code> for consistency. Fixed test email button stuck in sending state. Visual preview tab clearly labeled as read-only with Refresh button. Updated Help documentation.</p>
                        </div>
                        <div class="prer-changelog-entry">
                            <strong>v1.2.4</strong>
                            <p>Added comprehensive Help tab. Test email moved to template editor with real order data preview. Debug logging with 3-day rotation and Dashboard viewer. CC Admin toggle (BCC). Pending/on-hold order recovery for POP-managed gateways. Non-retryable category support. Single admin menu entry with icon tab navigation.</p>
                        </div>
                        <div class="prer-changelog-entry">
                            <strong>v1.1.0</strong>
                            <p>Rebuilt from v1.0.0. Single email model (replaced 3-email sequence). Template management with CRUD, visual preview, and code editor. Merge tag sidebar. Fixed CSS toggle. Removed quiet hours. Added send history and conversion tracking.</p>
                        </div>
                        <div class="prer-changelog-entry">
                            <strong>v1.0.0</strong>
                            <p>Initial release. 3-email recovery sequence, basic settings, HTML templates.</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }
}
