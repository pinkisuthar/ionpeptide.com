<?php
/**
 * Help admin page template
 * 
 * @package PaymentOrchestrator
 */

defined('ABSPATH') || exit;
?>

<div class="po-help-page">
    <!-- Shared Header + Tab Nav -->
    <?php $currentPage = 'payment-orchestrator-help'; include PO_PLUGIN_DIR . 'templates/admin/partials/header-nav.php'; ?>
    
    <!-- Quick Navigation -->
    <div class="po-card po-help-nav">
        <div class="po-card-header">
            <h2>Quick Navigation</h2>
        </div>
        <div class="po-card-body">
            <div class="po-help-nav-grid">
                <a href="#overview" class="po-help-nav-item">
                    <span class="dashicons dashicons-info-outline"></span>
                    <span>Overview</span>
                </a>
                <a href="#getting-started" class="po-help-nav-item">
                    <span class="dashicons dashicons-flag"></span>
                    <span>Getting Started</span>
                </a>
                <a href="#gateways" class="po-help-nav-item">
                    <span class="dashicons dashicons-admin-plugins"></span>
                    <span>Managing Gateways</span>
                </a>
                <a href="#rotation" class="po-help-nav-item">
                    <span class="dashicons dashicons-randomize"></span>
                    <span>Rotation Logic</span>
                </a>
                <a href="#health" class="po-help-nav-item">
                    <span class="dashicons dashicons-heart"></span>
                    <span>Health & Alerts</span>
                </a>
                <a href="#recovery" class="po-help-nav-item">
                    <span class="dashicons dashicons-backup"></span>
                    <span>Recovery</span>
                </a>
                <a href="#dashboard" class="po-help-nav-item">
                    <span class="dashicons dashicons-dashboard"></span>
                    <span>Dashboard</span>
                </a>
                <a href="#reports" class="po-help-nav-item">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <span>Reports</span>
                </a>
                <a href="#settings" class="po-help-nav-item">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <span>Settings</span>
                </a>
                <a href="#troubleshooting" class="po-help-nav-item">
                    <span class="dashicons dashicons-sos"></span>
                    <span>Troubleshooting</span>
                </a>
                <a href="#glossary" class="po-help-nav-item">
                    <span class="dashicons dashicons-book"></span>
                    <span>Glossary</span>
                </a>
                <a href="#api-docs" class="po-help-nav-item">
                    <span class="dashicons dashicons-rest-api"></span>
                    <span>API Docs</span>
                </a>
                <a href="#changelog" class="po-help-nav-item">
                    <span class="dashicons dashicons-backup"></span>
                    <span>Changelog</span>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Overview -->
    <div class="po-card" id="overview">
        <div class="po-card-header"><h2><span class="dashicons dashicons-info-outline"></span> Overview</h2></div>
        <div class="po-card-body po-help-content">
            <h3>What is Payment Orchestrator Pro?</h3>
            <p>Payment Orchestrator Pro is a WooCommerce plugin that intelligently manages and routes transactions across multiple payment gateways. It ensures your primary processors handle the bulk of volume, your obligation gateways receive their exact monthly allocation organically, and revenue is never lost to a failed payment.</p>
            <ul>
                <li><strong>Primary processors get the lion's share</strong> &#x2014; unlimited gateways (monthly target = 0) dominate traffic and split proportionally by health</li>
                <li><strong>Obligation gateways get exactly their target</strong> &#x2014; capped gateways receive a daily drip sized to hit their monthly target by month-end, spread naturally across the trading day</li>
                <li><strong>Monitor gateway health</strong> and automatically reduce traffic to failing gateways &#x2014; only actual gateway errors count, not customer card problems</li>
                <li><strong>Recover lost revenue</strong> by automatically retrying failed payments on alternative gateways &#x2014; recovery is never blocked by monthly caps</li>
                <li><strong>Show friendly error messages</strong> to customers instead of raw payment processor codes</li>
                <li><strong>Alert you to problems</strong> with email notifications when a gateway starts failing</li>
                <li><strong>Generate reports</strong> on transactions, success rates, failures, and recovery</li>
            </ul>
            
            <h3>Key Concepts</h3>
            <div class="po-help-concepts">
                <div class="po-help-concept"><h4>Unlimited Gateway</h4><p>A gateway with <strong>Monthly Target = 0</strong>. This is your primary processor. It receives the majority of all traffic, health-weighted against other unlimited gateways. Two healthy unlimited gateways split roughly 50/50. Set your best, most reliable processors as unlimited.</p></div>
                <div class="po-help-concept"><h4>Capped Gateway</h4><p>A gateway with a <strong>Monthly Target &gt; 0</strong>. This is an obligation or relationship gateway. POP routes a daily drip to it &#x2014; sized to hit the target by month-end &#x2014; spread organically across the trading day. Once the target is hit, direct routing stops. Recovery retries are never blocked by the cap.</p></div>
                <div class="po-help-concept"><h4>Always On</h4><p>Gateways marked as "Always On" are always available to customers and are not included in the rotation system. Use this for gateways like PayPal, Apple Pay, or Zelle.</p></div>
                <div class="po-help-concept"><h4>Managed Gateways</h4><p>Gateways that are NOT marked as "Always On" participate in intelligent rotation. The plugin selects which one to show at checkout based on pool assignment, health, and drip state.</p></div>
                <div class="po-help-concept"><h4>Failure Recovery</h4><p>When a payment fails due to a gateway-side error, the system automatically redirects the customer to retry on a different gateway &#x2014; recovering revenue that would otherwise be lost. Recovery is not limited by monthly targets.</p></div>
                <div class="po-help-concept"><h4>Smart Health Scoring</h4><p>Health scores count failures caused by the processor or the merchant account (errors, timeouts, configuration issues, and merchant-category refusals such as Do Not Honor or pick-up-card declines). Customer card problems &#x2014; bad CVV, insufficient funds, card amount limits, card brands the gateway doesn't accept &#x2014; do NOT lower a gateway's health.</p></div>
                <div class="po-help-concept"><h4>Backfill</h4><p>The process of importing historical transaction data from WooCommerce orders into the plugin's tracking system. Always run backfill after enrolling a gateway mid-month so the drip calculation starts from the correct remaining balance.</p></div>
            </div>
        </div>
    </div>
    
    <!-- Getting Started -->
    <div class="po-card" id="getting-started">
        <div class="po-card-header"><h2><span class="dashicons dashicons-flag"></span> Getting Started</h2></div>
        <div class="po-card-body po-help-content">
            <h3>Initial Setup (Step by Step)</h3>
            <ol class="po-help-steps">
                <li><h4>Step 1: Add Your Gateways</h4><p>Go to <strong>Pay Orchestrator &#x2192; Gateways</strong> and click "Add Gateway". Select your payment gateway from the dropdown (only active WooCommerce payment methods are shown). Give it a friendly display name and set your monthly target volume.</p></li>
                <li><h4>Step 2: Configure Gateway Settings</h4>
                    <p>For each gateway, decide:</p>
                    <ul>
                        <li><strong>Monthly Target:</strong> How much volume (in dollars) should this gateway handle per month? Set to 0 for unlimited.</li>
                        <li><strong>Always On:</strong> Should this gateway always be available (like PayPal), or should it participate in rotation?</li>
                        <li><strong>Accepts Amex / Discover:</strong> Toggle off if the gateway does not accept these card types. The recovery system uses this to route card-type failures to a compatible gateway.</li>
                        <li><strong>Priority:</strong> Lower numbers = higher priority when gateways have equal weight.</li>
                    </ul>
                </li>
                <li><h4>Step 3: Backfill Historical Data</h4><p>For each gateway, use the "Backfill" button to import existing WooCommerce orders. This ensures the plugin knows your current monthly volume.</p></li>
                <li><h4>Step 4: Configure Settings</h4>
                    <p>Go to <strong>Pay Orchestrator &#x2192; Settings</strong> to configure:</p>
                    <ul>
                        <li>Which order statuses count as successful transactions</li>
                        <li>Daily email report settings</li>
                        <li>Failure alert thresholds and email</li>
                        <li>Recovery system toggle</li>
                        <li>Logging preferences</li>
                    </ul>
                </li>
                <li><h4>Step 5: Check the Recovery Page</h4><p>Go to <strong>Pay Orchestrator &#x2192; Recovery</strong> to see the failure classification rules, recovery stats, and recent failure data.</p></li>
                <li><h4>Step 6: Test the Rotation</h4><p>Use the "Simulate Distribution" feature on the Dashboard to see how transactions would be distributed across your gateways.</p></li>
            </ol>
            <div class="po-help-warning"><h4>&#x26A0;&#xFE0F; Important</h4><p>After initial setup, always run a backfill for the current month on each gateway to ensure accurate volume tracking.</p></div>
        </div>
    </div>
    
    <!-- Managing Gateways -->
    <div class="po-card" id="gateways">
        <div class="po-card-header"><h2><span class="dashicons dashicons-admin-plugins"></span> Managing Gateways</h2></div>
        <div class="po-card-body po-help-content">
            <h3>Master Orchestration Toggle</h3>
            <p>At the top of the Gateways page is the <strong>Gateway Orchestration</strong> switch. This controls whether POP's smart routing is active at checkout.</p>
            <table class="po-help-table">
                <thead><tr><th>State</th><th>Checkout Behavior</th><th>Tracking &amp; Recovery</th></tr></thead>
                <tbody>
                    <tr><td><strong>Active (On)</strong></td><td>POP rotates gateways based on health, pacing, and capacity</td><td>Running normally</td></tr>
                    <tr><td><strong>Paused (Off)</strong></td><td>WooCommerce uses its default gateway order &#x2014; no smart routing</td><td>Still running &#x2014; transactions, failures, recovery, health, and reports all continue recording normally</td></tr>
                </tbody>
            </table>
            <div class="po-help-tip"><h4>&#x26A0;&#xFE0F; Important</h4><p>Turning orchestration off does NOT disable failure tracking, recovery, or reporting. Only the checkout gateway selection logic is paused. Each individual gateway can also be toggled on/off in the Configured Gateways table using the per-gateway switch.</p></div>
            
            <h3>Adding a Gateway</h3>
            <ol>
                <li>Navigate to <strong>Pay Orchestrator &#x2192; Gateways</strong></li>
                <li>Select a payment method from the dropdown (only active WooCommerce gateways appear)</li>
                <li>Enter a <strong>Display Name</strong></li>
                <li>Set the <strong>Monthly Target</strong> in dollars (or 0 for unlimited)</li>
                <li>Select accepted card types (Visa, MC, Amex, Discover)</li>
                <li>Choose if this is an <strong>"Always On"</strong> gateway</li>
                <li>Click <strong>"Add Gateway"</strong></li>
            </ol>
            
            <h3>Gateway Settings Explained</h3>
            <table class="po-help-table">
                <thead><tr><th>Setting</th><th>Description</th><th>Recommended</th></tr></thead>
                <tbody>
                    <tr><td><strong>Display Name</strong></td><td>A friendly name shown in reports, recovery, and the dashboard. Does not affect checkout.</td><td>Use descriptive names like "NMI Primary"</td></tr>
                    <tr><td><strong>Monthly Target</strong></td><td>The dollar amount this gateway should process per month. <strong>Set to 0</strong> to make it a primary unlimited gateway &#x2014; it will receive the lion's share of all traffic. <strong>Set a value &gt; 0</strong> to make it a capped gateway &#x2014; it receives a daily drip sized to hit this number by month-end, then steps aside. Change this at any time; the drip recalculates on the next transaction.</td><td>0 for your primary processors; set a dollar amount for obligation/relationship gateways</td></tr>
                    <tr><td><strong>Max Transaction Amount</strong></td><td>The largest single order this gateway may take. Orders above it are routed to another gateway, and a gateway already chosen for a shopper is released if their order grows past its maximum. <strong>0</strong> = no limit.</td><td>Match your processor's per-transaction limit</td></tr>
                    <tr><td><strong>Gateway Type</strong></td><td><strong>Card Payment</strong> &#x2014; routes by card type (Visa, MC, Amex, Discover). <strong>Alternative</strong> &#x2014; for non-card methods like Zelle, ACH, P2P, cryptocurrency. Alternative gateways skip card-type routing logic.</td><td>Use "Alternative" for non-card payment methods</td></tr>
                    <tr><td><strong>Card Types</strong></td><td>Which card brands this gateway accepts (Visa, Mastercard, Amex, Discover). Only shown for Card Payment gateways. Used by recovery to route declined transactions to gateways that accept the card type, and by the card-brand switch to offer customers a gateway that accepts their card.</td><td>Match your processor's actual acceptance</td></tr>
                    <tr><td><strong>Always On</strong></td><td>Always available to customers, not included in rotation.</td><td>Enable for PayPal, Apple Pay, Zelle</td></tr>
                    <tr><td><strong>Rotation Toggle</strong></td><td>Per-gateway on/off switch in the table. Instantly enables or disables a gateway in the rotation without editing.</td><td>Use for quick maintenance toggling</td></tr>
                    <tr><td><strong>Priority</strong></td><td>When gateways have equal weight, lower numbers are preferred.</td><td>Set most reliable to priority 1</td></tr>
                </tbody>
            </table>
            
            <h3>Backfilling Transaction Data</h3>
            <ol>
                <li>Find the gateway in the list and click <strong>"Backfill"</strong></li>
                <li>Select the month to backfill</li>
                <li>Check <strong>"Re-backfill"</strong> to clear and reimport</li>
                <li>Click <strong>"Run Backfill"</strong></li>
            </ol>
            <div class="po-help-tip"><h4>&#x1F4A1; Tip</h4><p>Run backfill for each gateway after initial setup, and again if you notice volume discrepancies.</p></div>
        </div>
    </div>
    
    <!-- Rotation Logic -->
    <div class="po-card" id="rotation">
        <div class="po-card-header"><h2><span class="dashicons dashicons-randomize"></span> Rotation Logic</h2></div>
        <div class="po-card-body po-help-content">
            <h3>Two-Pool Routing Model</h3>
            <p>Every gateway belongs to one of two pools, determined automatically by its Monthly Target setting. The pool determines how each gateway competes for transactions at checkout.</p>

            <div class="po-help-flow">
                <div class="po-help-flow-step"><span class="po-help-flow-num">1</span><div><h4>Health Filter (hard gate)</h4><p>Any gateway with a critical health score is removed from selection entirely before scoring begins. Only gateways that can safely receive traffic proceed.</p></div></div>
                <div class="po-help-flow-step"><span class="po-help-flow-num">2</span><div><h4>Monthly Cap Check (capped gateways only)</h4><p>Capped gateways at or above their monthly target are removed from direct routing. They remain eligible for recovery retries &#x2014; revenue is never blocked by a cap.</p></div></div>
                <div class="po-help-flow-step"><span class="po-help-flow-num">3</span><div><h4>Pool Scoring</h4><p>Each remaining gateway is scored according to its pool. Pool 1 (unlimited) gateways score high and dominate. Pool 2 (capped) gateways score low unless their hourly drip slot is active.</p></div></div>
                <div class="po-help-flow-step"><span class="po-help-flow-num">4</span><div><h4>Weighted Random Selection</h4><p>A weighted random draw selects the final gateway. Higher-scoring gateways win more transactions proportionally &#x2014; not every single one, which produces natural-looking variety.</p></div></div>
                <div class="po-help-flow-step"><span class="po-help-flow-num">5</span><div><h4>Cold Protection (edge case)</h4><p>If a gateway has not received a transaction in 100 or more consecutive transactions, it is given priority regardless of score. This is a backstop only &#x2014; under normal operation it rarely triggers.</p></div></div>
            </div>

            <h3>Pool 1 &#x2014; Unlimited Gateways (Monthly Target = 0)</h3>
            <p>These are your primary processors. They receive the majority of all traffic by design.</p>
            <table class="po-help-table">
                <thead><tr><th>Condition</th><th>Base Score</th><th>Health Multiplier</th><th>Final Score</th></tr></thead>
                <tbody>
                    <tr><td>Healthy (95%+ success rate)</td><td>80</td><td>1.0&#xD7;</td><td>~80&#x2013;90</td></tr>
                    <tr><td>Good (85&#x2013;95% success rate)</td><td>80</td><td>0.9&#xD7;</td><td>~72&#x2013;81</td></tr>
                    <tr><td>Degraded (70&#x2013;85% success rate)</td><td>80</td><td>0.5&#xD7;</td><td>~40&#x2013;45</td></tr>
                    <tr><td>Critical (below 70%)</td><td colspan="3">Removed before scoring &#x2014; receives no traffic</td></tr>
                </tbody>
            </table>
            <p>Two healthy unlimited gateways at equal health split traffic ~50/50. When one degrades, the healthy gateway absorbs its share automatically &#x2014; no configuration needed.</p>
            <div class="po-help-tip"><h4>&#x1F4A1; Example</h4><p>Gateway A and Gateway B are both unlimited and healthy: each gets about half of the main traffic. Gateway B degrades (0.5&#xD7; multiplier, score ~40 vs Gateway A's 80): Gateway A gets ~67%, Gateway B ~33%. Gateway B goes critical: Gateway A gets ~85% and any capped gateways absorb the remainder through their daily drip.</p></div>

            <h3>Pool 2 &#x2014; Capped Gateways (Monthly Target &gt; 0)</h3>
            <p>These are obligation or relationship gateways. POP routes a precise daily drip to them and then yields &#x2014; they never compete with Pool 1 for the main volume.</p>

            <h4>Drip Calculation (all live &#x2014; recalculates every transaction)</h4>
            <table class="po-help-table">
                <thead><tr><th>Value</th><th>Formula</th><th>Example ($10K target, 13 days left, $4,500 processed)</th></tr></thead>
                <tbody>
                    <tr><td><strong>Remaining</strong></td><td>Target &#x2212; Monthly Volume</td><td>$10,000 &#x2212; $4,500 = $5,500</td></tr>
                    <tr><td><strong>Daily Drip</strong></td><td>Remaining at the start of today &#xF7; Days Left (including today). Fixed for the whole day &#x2014; today's sales don't shrink it.</td><td>$5,500 &#xF7; 13 = $423/day</td></tr>
                    <tr><td><strong>Hourly Drip</strong></td><td>Daily Drip &#xF7; 24 hours</td><td>$423 &#xF7; 24 = $17.63/hour</td></tr>
                    <tr><td><strong>Expected by Now</strong></td><td>Hourly Drip &#xD7; Hours Elapsed Today</td><td>At 10am (10h elapsed): $17.63 &#xD7; 10 = $176</td></tr>
                </tbody>
            </table>
            <p>The 17-hour active window runs from <strong>6:00am to 11:00pm</strong>. Changing the target or running a backfill takes effect on the very next transaction &#x2014; no restart or month-start required.</p>

            <h4>Intraday Scoring Signal</h4>
            <p>POP compares today's actual volume to the expected volume by the current hour. This keeps the drip organic &#x2014; appearing throughout the day rather than in a single burst.</p>
            <table class="po-help-table">
                <thead><tr><th>Intraday Position</th><th>Score Effect</th><th>Behavior</th></tr></thead>
                <tbody>
                    <tr><td>No transactions yet today</td><td>+35 (max boost)</td><td>Gateway competes actively for its first drip transactions</td></tr>
                    <tr><td>Exactly on hourly pace</td><td>0</td><td>No adjustment &#x2014; gateway holds its natural share</td></tr>
                    <tr><td>Ahead of hourly pace</td><td>Up to &#x2212;15</td><td>Gently backs off; unlimited gateways absorb excess</td></tr>
                    <tr><td>Daily drip fully consumed</td><td>Score &#x2192; ~2</td><td>Gateway steps aside for rest of day; unlimited gateways take over</td></tr>
                </tbody>
            </table>
            <div class="po-help-tip"><h4>&#x1F4A1; Score ceiling guarantee</h4><p>Maximum possible capped score: 20 (base) + 35 (max drip boost) + 10 (warmth) = <strong>65</strong>. Minimum healthy unlimited score: 80 &#xD7; 1.0 = <strong>80</strong>. A healthy unlimited gateway always outscores a capped gateway &#x2014; Pool 1 dominance is guaranteed by the math, not by configuration.</p></div>

            <h3>When All Unlimited Gateways Are Down</h3>
            <p>If every unlimited gateway is removed by the health filter, capped gateways automatically absorb all traffic. The system never goes dark. In this emergency state capped gateways may exceed their monthly target &#x2014; that is intentional. Keeping revenue flowing takes precedence over allocation limits.</p>

            <h3>Cold Protection</h3>
            <p>Under the two-pool model, unlimited gateways never go cold &#x2014; they always hold the majority of traffic. Cold protection (threshold: 100 transactions) exists as a backstop for capped gateways after their daily drip is consumed, ensuring the processor sees occasional activity and the relationship stays warm. It should rarely trigger in normal operation.</p>

            <h3>Promoting a Capped Gateway to Primary</h3>
            <p>If an unlimited gateway goes down permanently or you close the account, promote a capped gateway by setting its Monthly Target to <strong>0</strong>. It immediately moves to Pool 1 and begins receiving primary traffic on the very next transaction. No other changes are needed.</p>
        </div>
    </div>
    
    <!-- Health & Alerts -->
    <div class="po-card" id="health">
        <div class="po-card-header"><h2><span class="dashicons dashicons-heart"></span> Health Monitoring & Failure Alerts</h2></div>
        <div class="po-card-body po-help-content">
            <h3>Smart Health Scoring</h3>
            <div class="po-help-warning" style="background: #dbeafe; border-color: #3b82f6;"><h4 style="color: #1e40af;">&#x1F9E0; Key Insight</h4><p style="color: #1e40af;">Health measures whether a gateway (merchant account) is processing well. Failures caused by the processor or the merchant account count against it. Problems with the customer's card or details do not &#x2014; a gateway that correctly declines a bad card is working as intended.</p></div>
            <table class="po-help-table">
                <thead><tr><th>Failure Type</th><th>Examples</th><th>Counts Against Health?</th></tr></thead>
                <tbody>
                    <tr><td><strong>Processor / merchant account errors</strong></td><td>Invalid merchant ID (410), general error (400), response timeout (420), re-enter transaction (440), payment-token and duplicate errors, connection timeouts</td><td style="color: #dc2626; font-weight: 600;">YES</td></tr>
                    <tr><td><strong>Processor declines (merchant-category refusals)</strong></td><td>Do Not Honor (201) and pick-up-card declines (250, 251, 252, 253) &#x2014; retried on another gateway when High-Risk Retry is on</td><td style="color: #dc2626; font-weight: 600;">YES (when High-Risk Retry is on)</td></tr>
                    <tr><td><strong>Customer card or details</strong></td><td>Insufficient funds, wrong CVV, expired card, address (AVS) mismatch, closed account, daily limit reached</td><td style="color: #22c55e; font-weight: 600;">NO</td></tr>
                    <tr><td><strong>Card amount limit</strong></td><td>Invalid amount (440) &#x2014; the customer's card refuses this amount (common on prepaid or limited cards). Still retried on another gateway.</td><td style="color: #22c55e; font-weight: 600;">NO</td></tr>
                    <tr><td><strong>Card brand not accepted</strong></td><td>A card brand the gateway is not set up to accept</td><td style="color: #22c55e; font-weight: 600;">NO</td></tr>
                </tbody>
            </table>
            <p>Each failure stores its own "counts against health" flag when it is recorded, so health scores, scorecards and the dashboard all use the same rule.</p>

            <h3>Health Thresholds</h3>
            <table class="po-help-table">
                <thead><tr><th>Score</th><th>Status</th><th>Effect</th></tr></thead>
                <tbody>
                    <tr><td>95-100%</td><td style="color: #22c55e; font-weight: 600;">Excellent</td><td>Full traffic (100%)</td></tr>
                    <tr><td>85-94%</td><td style="color: #f59e0b; font-weight: 600;">Good</td><td>90% traffic</td></tr>
                    <tr><td>70-84%</td><td style="color: #f97316; font-weight: 600;">Degraded</td><td>50% traffic</td></tr>
                    <tr><td>Below 70%</td><td style="color: #dc2626; font-weight: 600;">Critical</td><td>Blocked</td></tr>
                </tbody>
            </table>
            <p>New gateways with fewer than 10 transactions in 24 hours are treated as healthy (100%) to build a track record.</p>

            <h3>Failure Alerting</h3>
            <p>When a gateway experiences multiple failures within a short window, the plugin sends an email alert. Configure the threshold, window, and email in Settings.</p>
            <p>Alerts fire for ALL failures (including customer-caused ones) because a sudden spike in any failure type may indicate a problem. Health scoring and alerting are separate systems.</p>
        </div>
    </div>
    
    <!-- Recovery -->
    <div class="po-card" id="recovery">
        <div class="po-card-header"><h2><span class="dashicons dashicons-backup"></span> Payment Recovery</h2></div>
        <div class="po-card-body po-help-content">
            <h3>How Recovery Works</h3>
            <div class="po-help-flow">
                <div class="po-help-flow-step"><span class="po-help-flow-num">1</span><div><h4>Capture</h4><p>Every payment failure is recorded with its code, reason, order total, gateway and whether it counts against health.</p></div></div>
                <div class="po-help-flow-step"><span class="po-help-flow-num">2</span><div><h4>Classify</h4><p>The failure is sorted into a category (below) and given a customer message.</p></div></div>
                <div class="po-help-flow-step"><span class="po-help-flow-num">3</span><div><h4>Retry or explain</h4><p><strong>Retryable:</strong> the customer is sent straight to a payment page on another eligible gateway. <strong>Not retryable:</strong> the customer sees what to fix (for example the CVV or billing address).</p></div></div>
                <div class="po-help-flow-step"><span class="po-help-flow-num">4</span><div><h4>Record the outcome</h4><p>Attempts and declines on the payment page are recorded too (including a second decline on an order that had already failed), and a successful payment marks the failure as recovered.</p></div></div>
            </div>

            <h3>Failure Categories</h3>
            <table class="po-help-table">
                <thead><tr><th>Category</th><th>What It Means</th><th>Action</th><th>Health Impact</th></tr></thead>
                <tbody>
                    <tr><td style="font-weight: 600;">Processor</td><td>The processor or merchant account caused it (errors, timeouts, merchant-category refusals)</td><td>Retry on another gateway</td><td>Counts</td></tr>
                    <tr><td style="font-weight: 600;">Card amount limit</td><td>"Invalid amount" &#x2014; the card refuses this amount</td><td>Retry on another gateway; message suggests a different card</td><td>Does not count</td></tr>
                    <tr><td style="font-weight: 600;">Card brand</td><td>The gateway does not accept this card brand</td><td>Retry on a gateway that accepts the brand</td><td>Does not count</td></tr>
                    <tr><td style="font-weight: 600;">Customer</td><td>Card or customer details (funds, CVV, expiry, address, closed account)</td><td>Show what to fix; recovery email can follow</td><td>Does not count</td></tr>
                    <tr><td style="font-weight: 600;">Other</td><td>Responses POP has no rule for yet (for example a bank system being temporarily unavailable)</td><td>Show a general message</td><td>Does not count</td></tr>
                </tbody>
            </table>

            <h3>Customer-Facing Error Messages</h3>
            <p>Each failure gets one message, chosen from the processor's response code and text. The same message is shown at checkout, on the retry payment page and in the recovery email. Examples:</p>
            <table class="po-help-table">
                <thead><tr><th>Failure</th><th>Message the customer sees</th></tr></thead>
                <tbody>
                    <tr><td>Processor decline (Do Not Honor, pick-up card)</td><td><em>"Payment failed to process. You can try this card again or use a different card to complete your order. Your card was not charged."</em></td></tr>
                    <tr><td>Address (AVS) mismatch</td><td><em>"Your billing address or ZIP code didn't match your card. Please re-enter them exactly as they appear on your card statement."</em></td></tr>
                    <tr><td>Wrong security code</td><td><em>"The security code (CVV) doesn't match. Please check the 3 or 4 digit code on your card and try again."</em></td></tr>
                    <tr><td>Card amount limit (Invalid amount)</td><td><em>"Your card couldn't approve this amount. Please use a different card, or contact your bank &#x2014; some cards limit the amount of a single purchase."</em></td></tr>
                    <tr><td>Order above the gateway's maximum ticket</td><td><em>"This order is above the amount this payment option can accept. Please split your purchase into smaller orders of $2,000 or less."</em></td></tr>
                    <tr><td>Processor timeout / no response</td><td><em>"We didn't receive a response from the payment processor. Please check your email for an order confirmation before trying again."</em> (never says "not charged" &#x2014; the charge may have gone through)</td></tr>
                </tbody>
            </table>
            <p>If the same order is declined again after a retry, the message suggests trying a different card.</p>

            <h3>Highlighting the Field to Fix</h3>
            <p>After an address (AVS) or security-code (CVV) decline, the fields to correct are outlined in red with a short tip, and the page scrolls to them &#x2014; the billing address and ZIP for address problems, the card's security-code box for CVV problems. This works at checkout and on payment pages (retry and recovery-email links), and the highlight clears as soon as the customer edits that field. On payment pages the billing address fields are added for address declines, since WooCommerce's payment page normally has none.</p>

            <h3>Card-Brand Switch</h3>
            <p>When the payment option shown does not accept a card brand that another eligible gateway does, checkout and payment pages show <em>"Paying with &lt;brands&gt;? Use this option instead."</em> under it. If the customer types a refused card, POP switches automatically, reloads the page and explains which brands are now accepted. Ticked checkout boxes (such as agreements) are kept. "Switch back" appears only when the previous option accepts brands the current one doesn't. Accepted brands come from each gateway's card settings, so this works for any brand.</p>
            <p>The secure card form reports the card brand only when the customer presses Pay (the card number never leaves the card form while it is being typed), so the automatic switch happens at that moment. Customers who see the "Use this option instead" row before typing can switch first and enter their card only once.</p>

            <h3>The Payment Page (retry and recovery-email links)</h3>
            <ul>
                <li>It offers the gateway POP chose for the retry, and keeps the order's saved payment method in step with it so the card fields always show.</li>
                <li>Customers arriving from a recovery email for a card problem see why the first payment failed, in a highlighted box at the top of the page.</li>
                <li>If the order failed address verification (AVS), the page also shows the <strong>billing address fields</strong>, pre-filled and highlighted, so the customer can correct the address to match their card. On Pay the address is validated and the order's billing address is updated before the charge (shipping address is never changed; an order note records the update). Without this, a customer could only retry with the same address.</li>
                <li>Every attempt and decline on this page is recorded, including a second decline on an order that had already failed.</li>
                <li>While the card is being charged, a "Processing your payment&#x2026; Please don't close or refresh this page." message is shown over the greyed-out form (the regular checkout shows its own).</li>
            </ul>

            <h3>POP Retry</h3>
            <p>When a processor-side decline happens, POP moves the customer to another eligible gateway and they pay there &#x2014; this is <strong>POP Retry</strong>. On the Dashboard, "Rerouted" shows how many orders POP Retry could move and how many of them were recovered; "No eligible account" counts retryable orders where no other gateway could take the card brand or the amount.</p>

            <h3>When a Gateway Stops Accepting a Card Brand</h3>
            <p>Untick the brand on that gateway (PC NMI Accounts' account settings, and the gateway's Card Types in POP). From then on: customers who see that gateway are offered one that accepts their card ("Paying with &lt;brand&gt;? Use this option instead"), a refused card is switched automatically, POP Retry never sends that brand to the gateway, and payment pages for orders paid with that brand show a gateway that accepts it.</p>

            <h3>Recovery Email Credit</h3>
            <p>A recovery counts as <strong>recovered by email</strong> only when the customer <strong>clicked the email link and paid that same order on the payment page it opened</strong>. Paying any other way (from checkout, or with a new order) is not credited to the email.</p>

            <h3>Recovery Dashboard</h3>
            <p>The Recovery page shows failures, retries and recoveries for the period, including <strong>Paid after failure</strong> (failed orders later paid by any route) and <strong>Unrecovered (net)</strong> (all failure types, excluding orders paid later and customers who placed a paid order within 7 days).</p>

            <h3>Safety Guarantees</h3>
            <ul>
                <li>A card can be charged only once per card entry: if the same card entry is submitted again (for example a second submission arriving just after a decline), it is stopped without charging and without counting as a failure &#x2014; the customer simply re-enters their card to try again.</li>
                <li>A duplicate-payment lock stops a second submission of the same payment page within 30 seconds (for example a double click) on every payment page &#x2014; retry and recovery-email visits alike. It is released as soon as an attempt finishes, so customers can try again straight after a decline.</li>
                <li>POP Retry only moves a customer to a gateway that is enabled, healthy enough for traffic, within its maximum transaction amount and accepts the card's brand. The brand is recorded with each payment, so a card is never sent to a gateway that doesn't take it.</li>
                <li>Recovery messages on the payment page are only shown with the order's own key.</li>
            </ul>
        </div>
    </div>
    
    <!-- Dashboard -->
    <div class="po-card" id="dashboard">
        <div class="po-card-header"><h2><span class="dashicons dashicons-dashboard"></span> Dashboard</h2></div>
        <div class="po-card-body po-help-content">
            <p>All "today" figures use your store's day in the store's timezone (Settings &#x2192; General in WordPress). With the <strong>Command Center</strong> layout (Settings &#x2192; Appearance) you can view any past day with the day picker; each figure is compared with the same weekday one week earlier, because sales follow a weekly rhythm.</p>
            <h3>Top Cards</h3>
            <table class="po-help-table">
                <thead><tr><th>Figure</th><th>Meaning</th></tr></thead>
                <tbody>
                    <tr><td><strong>Approval rate</strong></td><td>Approved payment attempts &#xF7; all payment attempts today. One customer retrying three times counts as three attempts.</td></tr>
                    <tr><td><strong>Order conversion</strong></td><td>Orders paid &#xF7; orders that attempted payment today &#x2014; how many customers eventually got through.</td></tr>
                    <tr><td><strong>Failures: Processor</strong></td><td>Failed orders that count against gateway health (processor errors and merchant-category refusals).</td></tr>
                    <tr><td><strong>Failures: Customer</strong></td><td>Card or customer-detail problems.</td></tr>
                    <tr><td><strong>Failures: Other</strong></td><td>A catch-all for everything that is neither a processor problem nor a customer card problem: card amount limits ("Invalid amount"), card brand not accepted, and responses POP has no rule for yet.</td></tr>
                    <tr><td><strong>POP Recovery</strong></td><td>Failed orders recovered by POP &#x2014; <strong>POP Retry</strong> (the customer is moved to another gateway and pays there) or a recovery email paid through its link.</td></tr>
                    <tr><td><strong>Paid after failure</strong></td><td>Failed orders that were paid afterwards by any route, including customers fixing the problem themselves.</td></tr>
                </tbody>
            </table>
            <h3>Gateway Status Cards</h3>
            <p>Each gateway shows monthly volume vs target, pace, transaction counts and health (using the health rules above).</p>
            <h3>Rotation Simulator</h3>
            <p>Click "Run Simulation" to see how 100 transactions would be distributed across your gateways using current weights.</p>
            <div class="po-help-tip"><h4>&#x1F4A1; Tip</h4><p>If the simulator shows 0% for all gateways, ensure at least one gateway is enabled, NOT "Always On", and hasn't reached its monthly target.</p></div>
            <h3>Volume History Chart</h3>
            <p>Shows monthly volume by gateway for the last three months, using the store's timezone for month boundaries.</p>
        </div>
    </div>
    
    <!-- Reports -->
    <div class="po-card" id="reports">
        <div class="po-card-header"><h2><span class="dashicons dashicons-chart-bar"></span> Reports</h2></div>
        <div class="po-card-body po-help-content">
            <h3>Report Filters</h3>
            <p>Filter by report type (Today, Yesterday, This Week, This Month, Last Month, Custom Range), date range, and gateway. When a gateway is selected, all data &#x2014; including the CSV export &#x2014; is scoped to that gateway only. Dates follow your store's timezone (WordPress Settings &#x2192; General), so a report for a day covers that day from midnight to midnight in your store's time.</p>
            
            <h3>Quick Order Lookup</h3>
            <p>Enter any Order ID in the lookup field (top-right of the Reports page) and click <strong>Lookup</strong>. This instantly shows the order's status, amount, gateway, date, and &#x2014; if the order has a failure record &#x2014; the failure code, reason, category, recovery status, and which gateway recovered it.</p>
            
            <h3>Report Sections</h3>
            <ul>
                <li><strong>Summary Cards:</strong> Total transactions, successful, failed, total volume, success rate</li>
                <li><strong>Gateway Breakdown:</strong> Per-gateway stats with color-coded success rates</li>
                <li><strong>Failed Transactions:</strong> Order IDs, gateways, amounts, failure reasons. Click through for deeper analysis.</li>
                <li><strong>Recent Transactions:</strong> Last 50 transactions with status</li>
            </ul>
            
            <h3>CSV Export</h3>
            <p>Click <strong>"Export CSV"</strong> to download transactions. The export respects the gateway filter &#x2014; if you've selected a specific gateway, only that gateway's transactions are exported. The CSV contains two sections:</p>
            <ul>
                <li><strong>TRANSACTIONS:</strong> All orders with Order ID, Gateway, Amount, Date/Time, Status</li>
                <li><strong>FAILED TRANSACTIONS:</strong> Failure details including failure code, reason, category, amount, recovered status, recovery gateway, recovery source, and a Notes column summarizing the recovery path (e.g., "Originally failed on NMI, recovered via Adyen")</li>
            </ul>
        </div>
    </div>
    
    <!-- Settings -->
    <div class="po-card" id="settings">
        <div class="po-card-header"><h2><span class="dashicons dashicons-admin-settings"></span> Settings</h2></div>
        <div class="po-card-body po-help-content">
            <h3>Appearance</h3>
            <p>Choose how Payment Orchestrator looks for you: <strong>Default</strong> (the standard look), <strong>Midnight Lime</strong> (dark), <strong>Daylight Ledger</strong> (light), or <strong>Command Center</strong> &#x2014; a different Dashboard layout with a day picker (any past day, compared with the same weekday a week earlier), large figures, a 7-day chart, Recovery with POP Retry and email, failures by type and outcome, a card per gateway, monthly performance and the 3-month chart, each processor in its own colour. The choice is saved for your user account only and applies as soon as you save. Themes change colours and fonts on POP's screens only &#x2014; layouts, numbers, emails and exports are unchanged &#x2014; and all text meets accessibility contrast guidelines.</p>

            <h3>General Settings</h3>
            <table class="po-help-table">
                <thead><tr><th>Setting</th><th>Description</th><th>Default</th></tr></thead>
                <tbody>
                    <tr><td><strong>Success Order Statuses</strong></td><td>Which order statuses count as successful transactions for volume tracking.</td><td>Processing, Completed</td></tr>
                    <tr><td><strong>Max Cold Transactions</strong></td><td>Transactions before a gateway gets a "cold protection" weight boost.</td><td>5</td></tr>
                    <tr><td><strong>Enable Recovery</strong></td><td>Master toggle for failure capture, classification, retry links, and friendly messages.</td><td>Yes</td></tr>
                </tbody>
            </table>
            <h3>Failure Alert Settings</h3>
            <table class="po-help-table">
                <thead><tr><th>Setting</th><th>Description</th><th>Default</th></tr></thead>
                <tbody>
                    <tr><td><strong>Enable Failure Alerts</strong></td><td>Send email when a gateway has multiple failures in a short period.</td><td>Yes</td></tr>
                    <tr><td><strong>Failure Threshold</strong></td><td>Number of failures that triggers an alert.</td><td>3</td></tr>
                    <tr><td><strong>Alert Window</strong></td><td>Time window in minutes.</td><td>10 minutes</td></tr>
                    <tr><td><strong>Alert Email</strong></td><td>Email address for failure alerts.</td><td>Site admin email</td></tr>
                </tbody>
            </table>
            <h3>Daily Report Settings</h3>
            <p>Configure daily email summary: enable/disable, time, timezone, and recipient email. The daily report includes gateway performance, volume tracking, alerts, and payment recovery metrics (total failures, recovered count, revenue saved, recovery rate).</p>
            
            <h3>Bypass Mode</h3>
            <p><strong>Emergency switch.</strong> When enabled, Payment Orchestrator stops filtering gateways entirely &#x2014; all WooCommerce payment methods are shown to every customer. Use this if gateways are failing and you need to restore normal checkout immediately.</p>
            <table class="po-help-table">
                <thead><tr><th>Behavior</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><strong>Manual Bypass</strong></td><td>Toggle the switch in Settings. All rotation, health checks, and gateway selection are skipped. Volume tracking and failure recording continue.</td></tr>
                    <tr><td><strong>Auto-Bypass</strong></td><td>Toggle to enable/disable. When on: if all managed gateways exceed a 90% failure rate within a 15-minute window, the system automatically bypasses rotation and exposes all gateways. Also triggers if all managed gateways are disabled.</td></tr>
                </tbody>
            </table>
            <h4>Smart Protection</h4>
            <p>Both auto-bypass and failure alerts include smart protection to prevent false alarms from a single bad card:</p>
            <table class="po-help-table">
                <thead><tr><th>Requirement</th><th>Why</th></tr></thead>
                <tbody>
                    <tr><td><strong>3+ unique customer accounts</strong></td><td>Failures must come from at least 3 different customer email addresses. A single customer retrying a declined card won't trigger alerts or bypass.</td></tr>
                    <tr><td><strong>3+ transactions per gateway</strong></td><td>Each gateway needs at least 3 transactions in the window before its failure rate is judged.</td></tr>
                    <tr><td><strong>Alert rate limiting</strong></td><td>Auto-bypass alerts are sent once per 30 minutes; failure alerts once per alert window.</td></tr>
                </tbody>
            </table>
            <div class="po-help-tip"><h4>&#x26A0;&#xFE0F; Important</h4><p>Remember to turn manual bypass mode off once the issue is resolved. Auto-bypass resumes normal rotation automatically once gateways start succeeding again.</p></div>
            
            <h3>High-Risk Retry Mode</h3>
            <p>For high-risk merchants. Two mutually exclusive modes (only one can be active):</p>
            <table class="po-help-table">
                <thead><tr><th>Mode</th><th>Behavior</th></tr></thead>
                <tbody>
                    <tr><td><strong>Off</strong></td><td>201 and 253 are non-retryable (standard). Customer sees "card declined" message.</td></tr>
                    <tr><td><strong>Any Available Gateway</strong></td><td>Bank declines are retried on the next healthy gateway using normal rotation logic.</td></tr>
                    <tr><td><strong>Specific Gateway</strong></td><td>Bank declines are always retried on one chosen gateway &#x2014; your highest performer for these codes. Falls back to auto-rotation if the specific gateway is disabled.</td></tr>
                </tbody>
            </table>
            <p>Affected codes:</p>
            <table class="po-help-table">
                <thead><tr><th>Code</th><th>Description</th><th>Why Retryable in High-Risk</th></tr></thead>
                <tbody>
                    <tr><td><strong>201</strong></td><td>Do Not Honor</td><td>Bank decline often based on MID risk profile, not the card itself</td></tr>
                    <tr><td><strong>253</strong></td><td>Pick Up Card - Suspected Fraud</td><td>Suspected fraud flag often MID-dependent, not actual fraud</td></tr>
                </tbody>
            </table>
            <p>Customer message: <em>"Payment failed to process. You can try this card again or use a different card to complete your order."</em> These retries do NOT count against gateway health.</p>
            <h3>Logging Settings</h3>
            <p>Toggle logging on/off and set minimum log level (Debug, Info, Warning, Error).</p>
            <div class="po-help-tip"><h4>&#x1F4A1; Tip</h4><p>Use "Debug" when troubleshooting, then switch to "Info" or "Warning" for normal operation.</p></div>
            <h3>Advanced</h3>
            <p><strong>Delete Data on Uninstall:</strong> If enabled, all plugin data (gateways, transactions, logs, failed transactions) will be permanently deleted on uninstall.</p>
        </div>
    </div>
    
    <!-- Logs -->
    <div class="po-card" id="logs">
        <div class="po-card-header"><h2><span class="dashicons dashicons-media-text"></span> Logs</h2></div>
        <div class="po-card-body po-help-content">
            <h3>Log Levels</h3>
            <table class="po-help-table">
                <thead><tr><th>Level</th><th>Meaning</th></tr></thead>
                <tbody>
                    <tr><td><strong>DEBUG</strong> (Gray)</td><td>Detailed technical info: gateway selection, session locking, health scores</td></tr>
                    <tr><td><strong>INFO</strong> (Blue)</td><td>Normal operations: backfills, transactions, recovery attempts, retry results</td></tr>
                    <tr><td><strong>WARNING</strong> (Yellow)</td><td>Non-critical: failure alerts sent, no gateways available</td></tr>
                    <tr><td><strong>ERROR</strong> (Red)</td><td>Problems that prevented an operation from completing</td></tr>
                </tbody>
            </table>
            <h3>Recovery Log Messages</h3>
            <ul>
                <li><strong>"Order eligible for retry"</strong> &#x2014; retryable failure, alternative gateway found</li>
                <li><strong>"No alternative gateway for retry"</strong> &#x2014; retryable but no healthy alternative</li>
                <li><strong>"Customer shown retry link"</strong> &#x2014; retry button displayed</li>
                <li><strong>"Customer shown friendly error"</strong> &#x2014; non-retryable, friendly message shown</li>
                <li><strong>"Revenue recovered"</strong> &#x2014; customer paid via retry gateway</li>
            </ul>
        </div>
    </div>
    
    <!-- Troubleshooting -->
    <div class="po-card" id="troubleshooting">
        <div class="po-card-header"><h2><span class="dashicons dashicons-sos"></span> Troubleshooting</h2></div>
        <div class="po-card-body po-help-content">
            <h3>Common Issues</h3>
            <div class="po-help-faq">
                <div class="po-help-faq-item"><h4>Customers see "Card Type Not Accepted"</h4><div class="po-help-faq-answer"><p>The payment option shown doesn't accept that card brand. If another enabled gateway accepts it, customers are offered "Paying with &lt;brand&gt;? Use this option instead" and are switched automatically when they type the card. Check each gateway's Card Types match what the processor actually accepts.</p></div></div>
                <div class="po-help-faq-item"><h4>Many "Invalid amount" declines</h4><div class="po-help-faq-answer"><p>Usually the customer's card limits the amount of a single purchase (common with prepaid or limited cards); the same customers often succeed with smaller orders. These are retried on another gateway but don't lower health. Ask your processor for the issuer's raw response code if they persist.</p></div></div>
                <div class="po-help-faq-item"><h4>Many address (AVS) rejections</h4><div class="po-help-faq-answer"><p>The billing address or ZIP entered doesn't match the card. Customers are told exactly what to fix. The billing address and ZIP fields are highlighted for them to correct &#x2014; at checkout, and on the payment page reached from a recovery email or retry link, where the billing address fields are added so the customer can fix it. How strict the address check is depends on your processor account settings &#x2014; review them if legitimate orders are being rejected.</p></div></div>
                <div class="po-help-faq-item"><h4>"Invalid merchant ID" (410) errors</h4><div class="po-help-faq-answer"><p>A processor or merchant-account configuration problem, not the customer. It counts against the gateway's health and the order is retried elsewhere. If it appears on more than one gateway, contact your processor.</p></div></div>
                <div class="po-help-faq-item"><h4>Test payments fail on a sandbox account with an email error</h4><div class="po-help-faq-answer"><p>Some processor sandbox accounts only send receipts to the email the sandbox was registered with. Use that email as the billing email when testing, or turn off customer receipts in the sandbox account.</p></div></div>
                <div class="po-help-faq-item"><h4>Backfill shows 0 orders imported</h4><div class="po-help-faq-answer"><p>Check: Gateway ID matches WooCommerce orders, orders exist in the date range, order statuses match your Settings. Solution: Delete and re-add the gateway with the correct payment method.</p></div></div>
                <div class="po-help-faq-item"><h4>Gateway health shows "Critical" but transactions are working</h4><div class="po-help-faq-answer"><p>In v2.4.2+, only gateway-side errors count against health. If a gateway is incorrectly stuck, click "Clear Health" on the Dashboard to reset. The score rebuilds from the last 24 hours.</p></div></div>
                <div class="po-help-faq-item"><h4>Recovery page shows no data</h4><div class="po-help-faq-answer"><p>Data only appears after the first failed transaction since v2.4.2. Check that Recovery is enabled in Settings. If the table is missing, deactivate and reactivate the plugin.</p></div></div>
                <div class="po-help-faq-item"><h4>Customer not seeing friendly error messages</h4><div class="po-help-faq-answer"><p>Friendly messages work for NMI-based gateways that write "NMI failure reason:" in order notes. Check that Recovery is enabled in Settings.</p></div></div>
                <div class="po-help-faq-item"><h4>Simulator shows 0% for all gateways</h4><div class="po-help-faq-answer"><p>Ensure at least one gateway is enabled, NOT "Always On", and hasn't reached its monthly target.</p></div></div>
                <div class="po-help-faq-item"><h4>Gateway not showing at checkout</h4><div class="po-help-faq-answer"><p>Check: monthly target not reached, gateway enabled, health not critical, WooCommerce payment method active. Check Dashboard and Logs for details.</p></div></div>
                <div class="po-help-faq-item"><h4>Volume numbers don't match WooCommerce</h4><div class="po-help-faq-answer"><p>Different status filters, backfill not run, or timezone differences. Run re-backfill and verify Success Order Statuses in Settings.</p></div></div>
                <div class="po-help-faq-item"><h4>All gateways failing &#x2014; checkout is broken</h4><div class="po-help-faq-answer"><p>Enable <strong>Bypass Mode</strong> in Settings to immediately expose all WooCommerce gateways and skip rotation. This is an emergency switch &#x2014; remember to turn it off once the issue is resolved. The system also auto-bypasses if all managed gateways become disabled.</p></div></div>
                <div class="po-help-faq-item"><h4>Lots of 201/253 declines on high-risk products</h4><div class="po-help-faq-answer"><p>Enable <strong>High-Risk Retry Mode</strong> in Settings. This reclassifies "Do Not Honor" (201) and "Pick Up Card - Suspected Fraud" (253) as retryable, offering the customer a retry on a different gateway. These declines are often bank-side decisions based on MID risk profile.</p></div></div>
                <div class="po-help-faq-item"><h4>Failed Transaction report showing unhelpful error reasons</h4><div class="po-help-faq-answer"><p>Reports pull from the structured <code>po_failed_transactions</code> table first (code + reason), then fall back to order notes. Failures recorded before v2.4.2 don't have structured data, so older entries may show raw note text.</p></div></div>
            </div>
            
            <h3>Getting Support</h3>
            <ol>
                <li>Enable debug logging in Settings</li>
                <li>Reproduce the issue</li>
                <li>Go to Logs and click "Export Logs"</li>
                <li>For recovery issues, also check the Recovery page for failure data</li>
            </ol>
            
            <h3>Database Tables</h3>
            <table class="po-help-table">
                <tbody>
                    <tr><td><strong>po_gateways</strong></td><td>Gateway configuration</td></tr>
                    <tr><td><strong>po_transactions</strong></td><td>Transaction records for volume tracking and health</td></tr>
                    <tr><td><strong>po_daily_stats</strong></td><td>Aggregated daily stats per gateway</td></tr>
                    <tr><td><strong>po_failed_transactions</strong></td><td>Classified failure data for recovery</td></tr>
                    <tr><td><strong>po_logs</strong></td><td>Debug and activity logs</td></tr>
                </tbody>
            </table>
            
            <h3>System Requirements</h3>
            <table class="po-help-table">
                <tbody>
                    <tr><td><strong>WordPress</strong></td><td>6.0 or higher</td></tr>
                    <tr><td><strong>WooCommerce</strong></td><td>8.0 or higher (HPOS compatible)</td></tr>
                    <tr><td><strong>PHP</strong></td><td>8.2 or higher</td></tr>
                    <tr><td><strong>MySQL</strong></td><td>5.7 or higher</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Glossary -->
    <div class="po-card" id="glossary">
        <div class="po-card-header"><h2><span class="dashicons dashicons-book"></span> Glossary</h2></div>
        <div class="po-card-body po-help-content">
            <dl class="po-help-glossary">
                <dt>Approval rate</dt><dd>Approved payment attempts &#xF7; all payment attempts.</dd>
                <dt>Order conversion</dt><dd>Orders paid &#xF7; orders that attempted payment.</dd>
                <dt>Processor decline</dt><dd>A decline caused by the processor or merchant account (including merchant-category refusals). Counts against gateway health.</dd>
                <dt>Card amount limit</dt><dd>An "Invalid amount" decline &#x2014; the customer's card refuses the amount. Retried, but doesn't count against health.</dd>
                <dt>Card-brand switch</dt><dd>Offering, or automatically moving the customer to, a gateway that accepts their card brand.</dd>
                <dt>Paid after failure</dt><dd>A failed order that was paid afterwards by any route.</dd>
                <dt>Recovered by email</dt><dd>The customer clicked the recovery email and paid the same order on the payment page it opened.</dd>
                <dt>Always On</dt><dd>A gateway always available to customers, bypassing rotation.</dd>
                <dt>Backfill</dt><dd>Importing historical WooCommerce orders into the plugin's volume tracking.</dd>
                <dt>Cold Protection</dt><dd>Weight boost for gateways that haven't received recent transactions, preventing idle periods.</dd>
                <dt>Bypass Mode</dt><dd>Emergency switch that disables all rotation logic, exposing all WooCommerce gateways directly. Also triggers automatically if all managed gateways are disabled.</dd>
                <dt>Failure Classification</dt><dd>Categorizing a failure as retryable (gateway/card-type) or non-retryable (customer). Determines recovery action and health impact.</dd>
                <dt>Failure Recovery</dt><dd>Offering the customer a retry on a different gateway when payment fails due to a gateway error.</dd>
                <dt>Gateway</dt><dd>A payment processor or method configured in WooCommerce.</dd>
                <dt>Health Score</dt><dd>Success rate metric counting only gateway-side errors. Customer card problems do not affect health.</dd>
                <dt>High-Risk Retry</dt><dd>Optional mode that reclassifies bank declines (201, 253) as retryable. Useful for high-risk merchants where these codes are often MID-dependent.</dd>
                <dt>HPOS</dt><dd>High-Performance Order Storage &#x2014; WooCommerce's custom table order storage. Fully compatible.</dd>
                <dt>Managed Gateway</dt><dd>A gateway participating in rotation (not "Always On"). Managed gateways are either unlimited (Pool 1) or capped (Pool 2).</dd>
                <dt>Monthly Target</dt><dd>The dollar amount a capped gateway should process per month. Set to 0 to make the gateway unlimited (Pool 1 &#x2014; primary processor).</dd>
                <dt>Pool 1 (Unlimited)</dt><dd>Primary processors with Monthly Target = 0. Receive the majority of all traffic, split proportionally by health score.</dd>
                <dt>Pool 2 (Capped)</dt><dd>Obligation or relationship gateways with Monthly Target &gt; 0. Receive a daily drip to hit their target organically by month-end, then step aside.</dd>
                <dt>Daily Drip</dt><dd>The amount a capped gateway should process today: remaining balance &#xF7; days remaining. Recalculates live on every transaction.</dd>
                <dt>Intraday Signal</dt><dd>The scoring adjustment that compares a capped gateway's actual today volume against its expected hourly pace, keeping its transactions spread organically across the trading day.</dd>
                <dt>Retryable Failure</dt><dd>A gateway-side failure that may succeed on a different gateway. Examples: timeout, merchant ID error.</dd>
                <dt>Rotation</dt><dd>Automatic gateway selection based on pool assignment, health, and drip state.</dd>
                <dt>Weight</dt><dd>Calculated value determining selection likelihood. Higher = more transactions.</dd>
            </dl>
        </div>
    </div>
    
    <!-- API Documentation -->
    <div class="po-card" id="api-docs">
        <div class="po-card-header"><h2><span class="dashicons dashicons-rest-api"></span> REST API Documentation</h2></div>
        <div class="po-card-body po-help-content">
            <p>The REST API provides read-only access to all POP data for external dashboards (Loveable, Retool, custom apps). All endpoints require API key authentication.</p>
            
            <h3>Authentication</h3>
            <p>Send your API key in the <code>X-PO-API-Key</code> header with every request:</p>
            <pre style="background:#0f172a; color:#e2e8f0; padding:16px; border-radius:8px; overflow-x:auto; font-size:13px;">curl -H "X-PO-API-Key: po_your_key_here" \
  <?php echo esc_html(rest_url('po/v1/stats/today')); ?></pre>
            
            <h3>Security</h3>
            <ul>
                <li><strong>API Key:</strong> SHA-256 hashed and compared with constant-time <code>hash_equals</code>. Plain key shown once at generation.</li>
                <li><strong>IP Allowlist:</strong> Optional comma-separated IP list in Settings. If set, requests from other IPs are rejected.</li>
                <li><strong>Rate Limiting:</strong> 60 requests per minute per key. Exceeding returns a 403.</li>
                <li><strong>HTTPS:</strong> Enforced by default (configurable in Settings). Non-SSL requests are rejected.</li>
                <li><strong>Read-Only:</strong> All endpoints are GET. No data can be created, modified, or deleted via the API.</li>
                <li><strong>No PII:</strong> Customer emails, names, and addresses are never included in API responses.</li>
            </ul>
            
            <h3>Base URL</h3>
            <pre style="background:#f1f5f9; padding:10px 16px; border-radius:6px; font-size:13px;"><?php echo esc_html(rest_url('po/v1/')); ?></pre>
            
            <h3>Endpoints</h3>
            
            <h4 style="margin-top:20px; color:#1e293b;">GET /po/v1/gateways</h4>
            <p>All gateway configurations with live health, pacing, and volume data.</p>
            <p style="font-size:13px; color:#64748b;"><strong>Response:</strong> <code>gateways[]</code> &#x2014; id, display_name, gateway_type, is_enabled, monthly_target, month_volume, pace_status, pace_percentage, health_score, health_status, card type flags.</p>
            
            <h4 style="margin-top:20px; color:#1e293b;">GET /po/v1/transactions</h4>
            <p>Paginated transaction records. Each row: gateway, order ID, amount, status, timestamp.</p>
            <p style="font-size:13px; color:#64748b;"><strong>Params:</strong> <code>gateway</code>, <code>status</code> (success|failed), <code>date_from</code> (YYYY-MM-DD), <code>date_to</code>, <code>page</code>, <code>per_page</code> (max 500)</p>
            
            <h4 style="margin-top:20px; color:#1e293b;">GET /po/v1/failures</h4>
            <p>Paginated failure records with full recovery narrative. No customer PII.</p>
            <p style="font-size:13px; color:#64748b;"><strong>Params:</strong> <code>gateway</code>, <code>category</code> (retryable_gateway|non_retryable|...), <code>code</code>, <code>recovered</code> (0|1), <code>date_from</code>, <code>date_to</code>, <code>page</code>, <code>per_page</code></p>
            <p style="font-size:13px; color:#64748b;"><strong>Response fields:</strong> order_id, gateway_id, failure_code, failure_reason, failure_category, card_type, order_total, retry_count, retry_gateway, recovered, recovered_at, recovery_source, created_at.</p>
            
            <h4 style="margin-top:20px; color:#1e293b;">GET /po/v1/stats/today</h4>
            <p>Complete today dashboard snapshot in one call. Summary totals + per-gateway breakdown.</p>
            <p style="font-size:13px; color:#64748b;"><strong>Response:</strong> <code>summary</code> (total_processed, success_rate, failed_revenue, recovered_revenue, gateway_fails, customer_fails, recovery_rate) + <code>gateways[]</code> per-gateway detail.</p>
            
            <h4 style="margin-top:20px; color:#1e293b;">GET /po/v1/stats/monthly</h4>
            <p>Monthly aggregated data with daily breakdown for charting.</p>
            <p style="font-size:13px; color:#64748b;"><strong>Params:</strong> <code>month</code> (YYYY-MM, default current)</p>
            <p style="font-size:13px; color:#64748b;"><strong>Response:</strong> <code>by_gateway.transactions[]</code>, <code>by_gateway.failures[]</code>, <code>by_gateway.recoveries[]</code>, <code>daily[]</code> (date, gateway, volume, success, failed &#x2014; one row per gateway per day).</p>
            
            <h4 style="margin-top:20px; color:#1e293b;">GET /po/v1/stats/recovery</h4>
            <p>Recovery statistics for today and selected month. Top failure codes, gateway pairs.</p>
            <p style="font-size:13px; color:#64748b;"><strong>Params:</strong> <code>month</code> (YYYY-MM, default current)</p>
            <p style="font-size:13px; color:#64748b;"><strong>Response:</strong> <code>today</code> (stats, top_codes, gateway_pairs) + <code>month</code> (same structure).</p>
            
            <h4 style="margin-top:20px; color:#1e293b;">GET /po/v1/stats/health</h4>
            <p>Real-time gateway health scores from the rolling transaction window.</p>
            <p style="font-size:13px; color:#64748b;"><strong>Response:</strong> <code>health[]</code> &#x2014; gateway_id, score (0&#x2013;100), status, recent_success, recent_failed, window_size.</p>
            
            <h4 style="margin-top:20px; color:#1e293b;">GET /po/v1/stats/weights</h4>
            <p>Current rotation weights and scoring breakdown for each gateway.</p>
            <p style="font-size:13px; color:#64748b;"><strong>Response:</strong> <code>weights{}</code> &#x2014; per-gateway ID, weight, status, reason, breakdown.</p>
            
            <h3>Example: Loveable Integration</h3>
            <pre style="background:#0f172a; color:#e2e8f0; padding:16px; border-radius:8px; overflow-x:auto; font-size:13px;">// Fetch today's dashboard data
const resp = await fetch('<?php echo esc_html(rest_url('po/v1/stats/today')); ?>', {
  headers: { 'X-PO-API-Key': 'po_your_key_here' }
});
const data = await resp.json();
// data.summary.total_processed, data.gateways[0].success_rate, etc.

// Fetch all failures for February, page 1
const fails = await fetch('<?php echo esc_html(rest_url('po/v1/failures')); ?>?date_from=2026-02-01&date_to=2026-02-28&per_page=200', {
  headers: { 'X-PO-API-Key': 'po_your_key_here' }
});
// fails.failures[], fails.pagination.total, etc.</pre>
        </div>
    </div>
    
    <!-- Changelog -->
    <div class="po-card" id="changelog">
        <div class="po-card-header"><h2><span class="dashicons dashicons-backup"></span> Changelog</h2></div>
        <div class="po-card-body po-help-content">
            <h3>v2.7.35 &#x2014; Security-Scanner-Friendly Code</h3>
            <ul>
                <li>A site security scanner reported the Command Center template as possible malware. The file was unchanged and harmless; its compact icon code and inline script resembled patterns scanners associate with hidden code. The Command Center now loads its icons from an SVG icon file and its styles and chart script from separate asset files; the template is plain markup.</li>
                <li>All plugin files now use plain ASCII source (special characters such as dashes, arrows and symbols are written as escape codes), and a colour helper was rewritten &#x2014; so common malware scanners no longer flag them. Everything shown on screen, in customer messages and in emails is unchanged.</li>
            </ul>

            <h3>v2.7.34 &#x2014; Spent-Token Guard &amp; Interactive Command Center Charts</h3>
            <ul>
                <li>On payment pages, a second submission reusing a card entry that was already charged (arriving just after a decline) is now stopped: no charge attempt, no failure record, no health impact. Previously the processor answered "Payment Token does not exist", which counted against the gateway's health.</li>
                <li>Command Center: hover, tap or keyboard-focus a month in the 3-month chart to see each processor's volume and share (the other months dim); the 7-day chart shows each day's total the same way.</li>
            </ul>

            <h3>v2.7.33 &#x2014; Card-Brand-Aware Routing &amp; Command Center</h3>
            <ul>
                <li><strong>Card brand recorded with each payment</strong> (from the secure card form's response; brand only). POP Retry, payment pages and the forced-retry setting never send a card to a gateway that doesn't accept its brand &#x2014; needed when a gateway stops accepting a brand. Previously POP Retry only checked the brand for "card type not accepted" declines.</li>
                <li><strong>Command Center</strong> Dashboard layout (Settings &#x2192; Appearance): day picker with same-weekday comparison, gross volume with 7-day chart, Recovery (POP Retry and email, rerouted success, no eligible account), failures by type and where they ended up, activity per gateway, monthly performance and the 3-month chart &#x2014; one fixed colour per processor; stacks to one column on phones.</li>
                <li>"Auto-retry" is now called <strong>POP Retry</strong>; "Orchestrator Recovery" is now <strong>POP Recovery</strong>.</li>
                <li>Plugin upgrades now use an atomic lock (only one request can run the upgrade).</li>
            </ul>

            <h3>v2.7.32 &#x2014; Processing Message on Payment Pages</h3>
            <ul>
                <li>Payment pages (retry and recovery-email links) now show "Processing your payment&#x2026; Please don't close or refresh this page." while the card is being charged. The card plugin only greyed the form, with no message. Shown only while the form is greyed out; it disappears if the payment fails or a card field error appears. Announced to screen readers.</li>
            </ul>

            <h3>v2.7.31 &#x2014; Fix the Billing Address on the Payment Page</h3>
            <ul>
                <li>WooCommerce's payment page has no billing fields, so a customer returning after an address (AVS) decline could only retry with the same address. For unpaid orders that have had an address failure (even if a different decline came after it), the payment page now shows the billing address fields (pre-filled, highlighted). On Pay they are validated (required fields, state, ZIP format) and the order's billing address is updated before the charge; a validation error stops the payment and says what to fix. Shipping address is never changed; an order note records the update.</li>
                <li>A pending validation error on the payment page is no longer counted as a payment attempt.</li>
            </ul>

            <h3>v2.7.30 &#x2014; Highlight the Field to Fix</h3>
            <ul>
                <li>After an address (AVS) or security-code (CVV) decline, the billing address / ZIP fields or the card's security-code box are outlined, a short tip is shown and the page scrolls to them &#x2014; at checkout and on payment pages. The highlight clears when the customer edits the field. Visual only; payment behaviour is unchanged.</li>
                <li>Help: field highlighting, and why the card-brand switch happens when Pay is pressed.</li>
            </ul>

            <h3>v2.7.29 &#x2014; Payment-Page Safety &amp; Clarity</h3>
            <ul>
                <li><strong>Duplicate-payment lock on every payment page.</strong> It protected only retry orders; a double submission on a recovery-email visit reused a spent card token and recorded a false processor failure against the gateway. Blocked duplicates are no longer counted as payment attempts.</li>
                <li><strong>Failure reason for recovery-email visitors</strong> now shows in the same highlighted box as the retry message (was plain text, easy to miss).</li>
                <li>Dashboard: "Card &amp; other" renamed <strong>"Other"</strong> &#x2014; a catch-all for failures that are neither processor nor customer-card problems. Help updated.</li>
            </ul>

            <h3>v2.7.28 &#x2014; Payment-Page Tracking, Email Credit &amp; Help Update</h3>
            <ul>
                <li><strong>Declines on the payment page are recorded.</strong> A second decline on an order that had already failed (retry or recovery-email link) previously went unrecorded &#x2014; no failure row, no health count, no funnel event. It is now recorded against the gateway that declined it, with the same classification as any failure.</li>
                <li><strong>Recovery email credit:</strong> a recovery counts as "recovered by email" only when the customer clicked the email and paid the same order on the payment page it opened &#x2014; including card-problem orders that are not auto-retried.</li>
                <li><strong>Payment page:</strong> customers returning from a recovery email for a card problem see why the first payment failed. The retry notice no longer claims "your card was not charged" (unsafe after a processor timeout).</li>
                <li>Payment-page events (viewed, attempted, declined, paid) are published for the recovery-email funnel.</li>
                <li>Appearance: a newly saved theme applies immediately.</li>
                <li><strong>Help updated</strong> for 2.7.22&#x2013;2.7.28: health rules, failure categories and messages, card-brand switch, payment page, email credit, dashboard figures, reports timezone, maximum transaction amount, Appearance, troubleshooting and glossary. Examples use generic gateway names.</li>
            </ul>

            <h3>v2.7.27 &#x2014; Theme Options</h3>
            <ul>
                <li><strong>Settings &#x2192; Appearance:</strong> Default (POP's standard look), Midnight Lime or Daylight Ledger. Saved per admin user; other users and all layouts, numbers and emails are unaffected.</li>
                <li>Themes recolour POP's screens only: every colour is mapped by its role (text, background, border) and meaning (text, muted text, surfaces, success, warning, danger, info, header); text on coloured fills is chosen for readability. All text/background pairings meet WCAG AA contrast (4.5:1). Default renders exactly as before.</li>
            </ul>

            <h3>v2.7.26 &#x2014; Routing Pace Fix</h3>
            <ul>
                <li>Capped gateways: today's share of the monthly target ("daily drip") is now based on the balance at the start of the day. Previously it shrank as the day's sales came in, so a capped gateway reached "daily drip consumed" early and was throttled for the rest of the day (about 5% under-delivery by evening). The monthly cap itself is unchanged.</li>
            </ul>

            <h3>v2.7.25 &#x2014; Customer Messages &amp; Reporting Accuracy</h3>
            <ul>
                <li><strong>Customer messages</strong> rewritten from NMI's response codes and texts (AVS, CVV, expiry format, card activation, daily limits, PIN tries, bank blocking the merchant type, card amount limits, processor timeouts, duplicates, session expiry, $2,000 split for over-limit orders). One rule set feeds checkout, the retry pay page and recovery emails, so the customer sees the same message everywhere. Timeouts and duplicates never claim "not charged".</li>
                <li><strong>Invalid amount (440)</strong>: still retried on another account, but no longer counts against gateway health &#x2014; it is the customer's card refusing the amount, not a processor fault.</li>
                <li><strong>Pick-up card declines</strong> (250/251/252/253 and any "pick up card" text) are treated as processor/MCC declines, like Do Not Honor: retried on another account and counted against gateway health.</li>
                <li><strong>Retry redirect notice</strong> shows the same message as the pay page and recovery email.</li>
                <li><strong>Dashboard "today"</strong> uses the store's day for failures and recoveries (was the UTC day, 7 pm&#x2013;7 pm Chicago); same fix in the recovery page, scorecards, API and failure drill-down.</li>
                <li><strong>Reports</strong>: multi-day ranges no longer shifted by the store's UTC offset.</li>
                <li><strong>Approval rate</strong> (per attempt) and <strong>Order conversion</strong> (per order) shown separately.</li>
                <li><strong>Failures card</strong>: Processor (counts against health) &#xB7; Customer &#xB7; Card &amp; other; total includes every category.</li>
                <li><strong>Recovery</strong>: "Orchestrator recovery" plus "Paid after failure" (any means); recovery page adds "Paid after failure" and "Unrecovered (net of reorders within 7 days)".</li>
                <li>Pace status uses the start-of-day balance; 3-month chart months use the store timezone; plugin upgrade runs once (no parallel runs).</li>
            </ul>

            <h3>v2.7.24 &#x2014; Admin Layout &amp; Switch Diagnostics</h3>
            <ul>
                <li>Add Gateway form: "Max Transaction Amount" now lines up with the other fields (its help text no longer pushes it up).</li>
                <li>Card-brand switch on pay-for-order pages built by page builders or custom checkout designs that skip WooCommerce's <code>woocommerce_pay_order_before_submit</code>: the option is now rendered once in the page footer and moved under the payment option, so it appears with any template.</li>
                <li>Card-brand switch on pay-for-order pages: an already-placed option is kept (the pay page isn't redrawn, so it was being removed right after load); order-pay URLs with a non-matching key show nothing.</li>
                <li>Card-brand switch diagnostics (why no option was shown, step trace) &#x2014; off by default; enable with <code>define('PO_CARD_SWITCH_DEBUG', true);</code> in wp-config.php when troubleshooting.</li>
            </ul>

            <h3>v2.7.23 &#x2014; Card-Brand Switch</h3>
            <ul>
                <li><strong>Pay with another card brand.</strong> When the payment option shown doesn't accept a card brand that another eligible gateway does, checkout and pay-for-order pages show "Paying with &lt;brands&gt;? Use this option instead." under it. Fully data-driven: brands come from each gateway's accepted card types (PC NMI Accounts for NMI gateways, POP's card flags otherwise), so it works for any brand any gateway refuses.</li>
                <li><strong>Automatic switch.</strong> When PC NMI Accounts reports a refused brand (browser event <code>pc_nmi_card_type_refused</code>, or its server action as a backstop), POP switches to an eligible gateway that accepts it (enabled, available, healthy, within its maximum amount) and reloads so the right card fields load. The customer re-enters the card.</li>
                <li><strong>Switch back.</strong> After a switch, the new option offers "Want to use a different card? Switch back" (with the previous option's card icons) for as long as the switch lasts (30 minutes) &#x2014; only when the previous option accepts brands the current one doesn't and isn't already offered. Switching reloads the page (one Collect.js per page, keyed to one account), with the payment area greyed out while switching, the customer returned to the payment section afterwards, and ticked checkout checkboxes (e.g. agreements) restored after the reload.</li>
                <li><strong>Session lock respects the maximum ticket.</strong> If the order total grows past the locked gateway's maximum transaction amount during the 30-minute lock, the lock is released and rotation picks an eligible gateway (previously the over-limit gateway kept being offered and the charge was declined).</li>
                <li><strong>Integration:</strong> POP never touches Collect.js. A switch sets the session lock, answers <code>pc_nmi_preferred_gateway</code>, and on pay-for-order pages updates the order's payment method (and retry gateway). Card-type retries now use the same accepted-brand source.</li>
            </ul>

            <h3>v2.7.22 &#x2014; Card-Type Retry, Health Accuracy &amp; Stability Hotfix</h3>
            <ul>
                <li><strong>Card-type refusals are now retried.</strong> "Card Type Not Accepted" (with or without a brand, e.g. <code>(amex)</code>) was classified as <code>other</code> and never retried. It is now <code>retryable_card_type</code> and retries on a gateway that accepts the card; when the brand is unknown, a gateway that accepts every brand is preferred.</li>
                <li><strong>Fail-closed refusals</strong> (Collect.js token missing, unreadable, or from another NMI account) are classified <code>non_retryable</code> with a "refresh and try again" message.</li>
                <li><strong>Health uses the classifier's own verdict.</strong> Each failure now stores <code>counts_against_health</code>, used by the health score, scorecard success rate, processor rate and API gateway-failure counts. Counted: processor errors, and issuer declines 201/253 when high-risk mode retries them (an issuer refusing the MCC on an account counts against that account). Not counted: customer problems (CVV, funds, expired, invalid card) and card-type refusals (the account correctly declined a brand it doesn't accept). Older rows use the equivalent rule.</li>
                <li><strong>Crash fixes:</strong> the pay-page duplicate-payment lock no longer throws (WooCommerce runs that hook outside its try/catch, so it produced a fatal error page); it shows a notice instead, and the lock is released after an unsuccessful attempt. High-risk "specific gateway" mode no longer crashes failure processing.</li>
                <li><strong>Retry pay page shows the retry gateway.</strong> When a retry pay page was opened without the checkout-time session lock (recovery-email links, another device, an expired lock), rotation could keep only the failed gateway, and the page offered the gateway that had just failed. Rotation now defers to the order's retry gateway on its pay page and locks the session to it.</li>
                <li><strong>Pay page card box no longer collapses.</strong> Plugins such as "Payment Gateway Based Fees and Discounts" treat the order's saved payment method as the selected gateway on the pay page and hide every other gateway's box. When the saved method was a POP gateway that wasn't offered (the failed gateway on a retry page, or a different account than rotation picked for a recovery-email visit), the offered gateway's card fields were hidden. On a normal pay-page view with a valid order key, POP now updates the saved method to the gateway it offers.</li>
                <li><strong>Security:</strong> the pay-page retry message now requires the order key; the checkout auto-redirect only follows same-site URLs.</li>
                <li>Database: new column <code>po_failed_transactions.counts_against_health</code>, added and verified automatically on upgrade.</li>
            </ul>

            <h3>v2.7.20 &#x2014; False Failure Recording Bug Fix</h3>
            <ul>
                <li><strong>Bug fixed:</strong> When an order failed on Gateway A and POP auto-redirected to Gateway B for recovery, WooCommerce sometimes updated the order's <code>payment_method</code> to Gateway B before the order briefly touched Failed status during the redirect flow. This caused <code>onOrderFailed</code> to fire and record a false failure against Gateway B &#x2014; even though Gateway B never actually failed, it succeeded. The fix adds a guard in <code>processFailure()</code>: if the current <code>payment_method</code> matches the designated <code>_po_retry_gateway</code> and a different gateway is already recorded as <code>_po_failed_gateway</code>, the trigger is recognised as a false positive from the redirect flow and skipped. Gateway B's failure counts, health score, and success rate are no longer incorrectly penalised for recoveries it performed.</li>
            </ul>

            <h3>v2.7.19 &#x2014; Dashboard Top Bar &amp; Gateway Tile Redesign</h3>
            <ul>
                <li><strong>Top bar labels:</strong> "Volume" renamed to "Today's Volume". "Success Rate" renamed to "Total success rate". No data change &#x2014; labels only.</li>
                <li><strong>Failures card &#x2014; dollar primary:</strong> Hero number changed from failure count to total failed revenue ($processor + $customer). Sub-numbers show dollar split with fail counts as supporting context: "$2,582 &#xB7; Processor &#xB7; 10 fails" and "$9,081 &#xB7; Customer &#xB7; 39 fails".</li>
                <li><strong>Gateway activity &#x2014; Option 3 row layout:</strong> Replaced the grid of individual cards with a single shared card containing one horizontal row per gateway. Each row shows: health dot (green/yellow/orange/red) + gateway name &#x2192; volume &#x2192; Success / Failed / Recovered stat columns &#x2192; Processor and Checkout rate badges. Unhealthy gateways get a light red row tint. The processor/customer fail split appears as a right-aligned detail on wider screens.</li>
                <li><strong>Mobile stacked cards:</strong> At &#x2264;600px each gateway row becomes its own stacked card. Header: name + health dot on the left, rate badges on the right. Volume below at 26px. Stats in a 3-column strip (Success / Failed / Recovered) separated by hairlines at the bottom. All data visible at mobile width &#x2014; nothing hidden or truncated.</li>
            </ul>

            <h3>v2.7.18 &#x2014; Two-Pool Routing Model (Primary Redesign)</h3>
            <ul>
                <li><strong>Architecture change:</strong> Gateway routing now operates on a two-pool model derived entirely from the existing <code>monthlyTarget</code> field. No new configuration required.</li>
                <li><strong>Pool 1 &#x2014; Unlimited gateways (<code>monthlyTarget = 0</code>):</strong> These are your primary processors. Base score of 80, health-weighted. Two healthy unlimited gateways split ~50/50. A degraded gateway (0.5&#xD7; multiplier, score ~40) yields to the healthy one (score ~80). All non-drip volume flows here.</li>
                <li><strong>Pool 2 &#x2014; Capped gateways (<code>monthlyTarget &gt; 0</code>):</strong> Obligation and relationship gateways. POP calculates a daily drip (<code>remaining &#xF7; daysLeft</code>) and an hourly sub-drip (<code>dailyDrip &#xF7; 17 active hours</code>). Score rises gently when the gateway is behind its hourly pace and backs off when ahead &#x2014; producing an organic, natural-looking transaction pattern across the trading day. Maximum capped score (65) is always below the healthy unlimited base (80), so unlimited gateways always dominate. Once the daily drip is consumed, score drops to ~2, yielding to unlimited for the rest of the day.</li>
                <li><strong>All drip math is live:</strong> <code>remaining</code>, <code>dailyDrip</code>, and <code>hourlyDrip</code> recalculate on every transaction from live <code>po_daily_stats</code> and <code>po_transactions</code> data. Deploy mid-month, run backfill, change a target &#x2014; the next transaction after any of those events scores correctly with no manual intervention.</li>
                <li><strong>Recovery above target preserved:</strong> The monthly hard cap in <code>selectGateway()</code> applies to direct routing only. <code>findRetryGateway()</code> has no cap check &#x2014; capped gateways that have hit their monthly target can still receive recovery retries. Revenue is never lost due to routing caps.</li>
                <li><strong>Unlimited gateway failure:</strong> If all unlimited gateways are health-critical (removed by <code>canReceiveTraffic()</code>), capped gateways absorb all traffic automatically. System never goes dark.</li>
                <li><strong>Cold protection threshold raised from 5 &#x2192; 100:</strong> Unlimited gateways never go cold under this model. Cold protection now serves as an edge-case backstop for capped gateways that have finished their daily drip &#x2014; ensuring processor relationship warmth with an occasional transaction rather than hours of inactivity.</li>
                <li><strong>Previous pace-based scoring removed:</strong> The old monthly pace ratio (+40 to &#x2212;30 bonus) and daily budget throttle (&#x2212;25 max) are replaced entirely. Those components were architecturally inverted &#x2014; urgently pushing volume to capped gateways at the expense of unlimited ones.</li>
            </ul>

            <h3>v2.7.17 &#x2014; Mobile &amp; Tablet Responsive Layout</h3>
            <ul>
                <li><strong>Full responsive coverage added:</strong> All admin pages now adapt correctly on tablet (&#x2264;900px) and mobile (&#x2264;600px). Previously most layouts used fixed-column inline grids with no breakpoints, making them unusable on small screens.</li>
                <li><strong>Two breakpoints:</strong> 900px collapses multi-column grids to single-column and stacks the header bar. 600px further tightens spacing, hides tab labels (icons only), collapses the scorecard stats row to 2 columns, and ensures all tables scroll horizontally rather than overflowing.</li>
                <li><strong>Affected areas:</strong> header bar and tab navigation (all pages), dashboard top-bar 3-card grid, Recovery page two-column layout, Scorecards gateway card grid and per-card stats row, Today's Activity gateway tiles, Rotation Simulator, Reports filter row and summary cards, all tables, modals, and form inputs.</li>
                <li><strong>Implementation:</strong> Problem inline grids were converted to CSS classes (<code>po-top-cards</code>, <code>po-two-col</code>, <code>po-scorecard-grid</code>, <code>po-scorecard-stats</code>, <code>po-header-bar</code>, <code>po-tab-link</code>, <code>po-tab-label</code>). All responsive rules live in a single block appended to <code>admin.css</code> &#x2014; no logic changes anywhere.</li>
            </ul>

            <h3>v2.7.16 &#x2014; Secondary NMI-Backed Gateway Classification Fix</h3>
            <ul>
                <li><strong>NMI numeric codes on secondary-processor gateways now classified correctly:</strong> Secondary gateways (and any other secondary-processor gateway that processes via NMI infrastructure) writes decline codes as numeric NMI codes &#x2014; e.g. <code>253 - Pick up card - SF</code>, <code>410 - Invalid merchant ID</code> &#x2014; inside a <code>second-processor failure reason:</code> note prefix. <code>classifyGP()</code> previously required an alphabetic string code (<code>[a-z_]+</code>) and fell through to <code>CATEGORY_UNKNOWN / is_retryable: false</code> when it received a numeric code, so codes like 253 and 410 were never retried even though both are gateway-side retryable errors. Fixed: when <code>classifyGP()</code> detects a numeric code it delegates directly to <code>classify()</code> (the NMI path) with the note rewritten into NMI format, so all existing NMI retryability logic applies. <code>253</code> (Pick up card) and <code>410</code> (Invalid merchant ID) will now correctly trigger auto-retry on a different gateway.</li>
            </ul>

            <h3>v2.7.15 &#x2014; Recovery Duplicate Fix &amp; Notification Repair</h3>
            <ul>
                <li><strong>Duplicate recovery events fixed:</strong> When WooCommerce transitions an order <code>failed &#x2192; processing &#x2192; completed</code>, both status hooks (<code>woocommerce_order_status_processing</code> and <code>woocommerce_order_status_completed</code>) call <code>processRecovery()</code>. The DB UPDATE already had an <code>AND recovered = 0</code> guard so the record was only written once, but <code>Logger::info('Revenue recovered')</code>, <code>do_action('po_order_recovered')</code>, and <code>sendRecoveryNotification()</code> all fired unconditionally after the UPDATE &#x2014; causing duplicate log entries, double-counting in recovery stats, and two notification emails per recovery. Fixed by checking <code>$wpdb->rows_affected === 0</code> immediately after the UPDATE and returning early if no row was changed.</li>
                <li><strong>Recovery notification namespace typo fixed:</strong> <code>sendRecoveryNotification()</code> resolved the gateway repository as <code>\PaymentOrchestrator\Gateways\GatewayRepository</code> (plural). The correct namespace is <code>\PaymentOrchestrator\Gateway\GatewayRepository</code> (singular). This threw <em>Call to a member function get() on null</em> on every recovery, which is the error visible in the logs. Recovery emails have been silently failing since this function was introduced.</li>
                <li><strong>Gateway display name lookup fixed:</strong> The same notification function referenced gateway model properties as <code>$gw->gateway_id</code> and <code>$gw->display_name</code> (snake_case). The Gateway model uses <code>$gw->id</code> and <code>$gw->displayName</code> (camelCase). Fixed to match the actual model, so recovery emails now show proper gateway names instead of falling back to the raw ID.</li>
            </ul>

            <h3>v2.7.14 &#x2014; Reports Success Rate Fix &amp; Dashboard Tile Legibility</h3>
            <ul>
                <li><strong>Reports success rate fixed:</strong> The <code>po_daily_stats</code> <code>transaction_count</code> column only increments on successful transactions &#x2014; failures increment <code>failure_count</code> but leave <code>transaction_count</code> unchanged. The Reports single-day query was using <code>SUM(transaction_count)</code> as the total, making it equal to successes and producing 100% success rate. Fixed to use <code>SUM(success_count) + SUM(failure_count)</code> as the true total, matching how the dashboard computes success rate from the same table.</li>
                <li><strong>Dashboard Failures card &#x2014; darker Customer sub-numbers:</strong> Customer failure count changed from light grey (<code>#94a3b8</code>) to dark slate (<code>#475569</code>) with bold weight. Customer dollar amount changed from near-white (<code>#cbd5e1</code>) to readable mid-grey (<code>#94a3b8</code>). Processor label color also darkened to <code>#64748b</code>.</li>
                <li><strong>Dashboard Recovery card &#x2014; labels unabbreviated:</strong> "Processor Rec." and "Overall Rec." now read "Processor Recovery" and "Overall Recovery" on two lines, eliminating the ambiguous abbreviation.</li>
            </ul>

            <h3>v2.7.13 &#x2014; Dashboard Top Bar Redesign &amp; Reports Data Source Fix</h3>
            <ul>
                <li><strong>Dashboard top bar &#x2014; Option B (3 cards):</strong> Replaced the six narrow tiles with three wider, information-dense cards.
                    <ul style="margin-top:6px; font-size:13px; color:#64748b;">
                        <li><strong>Volume</strong> &#x2014; Total processed today (large), with transaction count and success rate as sub-numbers side by side.</li>
                        <li><strong>Failures</strong> &#x2014; Total failure count (large), split into Processor (retryable gateway errors, in red) and Customer (non-retryable customer declines, in grey). Dollar amounts shown below each when non-zero. Card border turns red when failures exist.</li>
                        <li><strong>Recovery</strong> &#x2014; Recovered dollars today (large), with Processor Recovery Rate (recovered of processor failures only) and Overall Recovery Rate side by side. Card border turns green when recovery exists.</li>
                    </ul>
                    No new database queries &#x2014; all values come from the existing <code>po_failed_transactions</code> query already running at page load. The two new fields (<code>retryable_fail_cnt</code>, <code>retryable_recovered_cnt</code>) are added as additional correlated subqueries to the existing <code>get_row</code> call.
                </li>
                <li><strong>Reports page &#x2014; data source aligned with dashboard:</strong> For single-day report views (Today, Yesterday), the summary cards and gateway breakdown table now query <code>po_daily_stats</code> instead of <code>po_transactions</code>. This is the same table the dashboard uses, eliminating the UTC vs. WP-timezone offset that caused different counts to appear on the two pages. Multi-day views (This Week, This Month, Last Month, Custom Range) continue to use <code>po_transactions</code> with UTC-boundary conversion as before. Transaction detail lists (failed list, recent transactions) always use <code>po_transactions</code> for drill-down regardless of range.</li>
            </ul>

            <h3>v2.7.12 &#x2014; Health Scoring Accuracy &amp; Dashboard Clarity</h3>
            <ul>
                <li><strong>Dashboard tiles redesigned:</strong> Each gateway now shows two clear rate badges:
                    <ul style="margin-top:6px; font-size:13px; color:#64748b;">
                        <li><strong>Processor</strong> &#x2014; Is the payment processor working? Only counts failures caused by the processor itself (timeouts, server errors, processing failures). Customer declines like bad CVV or insufficient funds are excluded. This is the number to watch for gateway reliability.</li>
                        <li><strong>Checkout</strong> &#x2014; What percentage of all checkout attempts succeeded? Includes everything &#x2014; processor errors AND customer declines. This is the raw success rate across all attempts.</li>
                    </ul>
                    The breakdown line now reads "(X processor &#xB7; Y customer)" instead of "(X gw &#xB7; Y cust)" for clarity.
                </li>
                <li><strong>second-processor health scoring fixed:</strong> The health monitor now recognizes the legacy second processor failure codes. Previously, the health SQL only matched NMI error codes &#x2014; second-processor gateway-side failures (like <code>processing_error</code>, <code>service_issue</code>) were invisible to health scoring, causing the health dot to always show green regardless of second-processor performance.</li>
                <li><strong><code>service_issue</code> reclassified:</strong> Moved from unrecognized (fell to CATEGORY_OTHER) to retryable. This is a second-processor HTTP 500 server error &#x2014; the processor's infrastructure failed, not the customer's card. Now counts against health and triggers auto-retry on a different gateway.</li>
            </ul>
            
            <h3>v2.7.11 &#x2014; Success Rate Accuracy Across All Surfaces</h3>
            <ul>
                <li><strong>Scorecards:</strong> Success rate, failed count, and lost revenue now only count gateway-attributable failures (<code>retryable_gateway</code>, <code>retryable_card_type</code>). Customer-caused declines (bad CVV, insufficient funds) no longer penalize a gateway's displayed success rate &#x2014; matching how HealthMonitor already scores gateways internally.</li>
                <li><strong>Daily report:</strong> Email success rate and the "low success rate" alert now use gateway-only failures. A burst of customer card errors no longer triggers false alarms.</li>
                <li><strong>REST API:</strong> The <code>/stats/today</code> endpoint's per-gateway and summary <code>success_rate</code> fields now use gateway-only failures. External dashboards (Loveable) will show correct rates.</li>
                <li><strong>Gateway list:</strong> Max transaction amount now displays below monthly target in the gateway table when configured.</li>
            </ul>
            
            <h3>v2.7.10 &#x2014; Max Transaction Amount per Gateway</h3>
            <ul>
                <li><strong>Max transaction limit:</strong> Each gateway can now have a maximum single transaction amount. Orders above this amount automatically route to a gateway that can handle them. Set to 0 for no limit. Configured per gateway on the Gateways page.</li>
                <li><strong>Retry-aware:</strong> When a payment fails and POP selects a retry gateway, it also checks the max transaction amount &#x2014; ensuring the retry doesn't route to a gateway that can't handle the order size.</li>
                <li><strong>Graceful fallback:</strong> If all gateways have a max below the order amount, POP falls through to WooCommerce's default gateway ordering rather than blocking checkout.</li>
            </ul>
            
            <h3>v2.7.9 &#x2014; Timezone Consistency (WooCommerce Aligned)</h3>
            <ul>
                <li><strong>Transaction timestamps:</strong> All new transaction records (<code>po_transactions</code>) now store <code>created_at</code> in the WordPress configured timezone instead of MySQL server UTC. This ensures "today" means the same thing across dashboard, scorecards, recovery, and reports &#x2014; matching WooCommerce's own behavior.</li>
                <li><strong>Scorecards "Today" filter:</strong> Replaced MySQL <code>CURDATE()</code> (UTC) with WordPress <code>current_time('Y-m-d')</code>. Scorecards and dashboard now always agree on which transactions belong to "today."</li>
            </ul>
            
            <h3>v2.7.8 &#x2014; second-processor High-Risk Retry Parity with NMI</h3>
            <ul>
                <li><strong>second-processor high-risk retry:</strong> <code>do_not_honor</code>, <code>generic_decline</code>, and <code>pickup_card</code> now follow the same high-risk retry logic as NMI codes 201/253. When high-risk retry mode is enabled (auto or specific), these codes become retryable on a different gateway. Counts against gateway health because in high-risk scenarios these tend to not be customer fault.</li>
                <li><strong>Recovery page rules:</strong> second-processor high-risk codes dynamically move between retryable and non-retryable sections based on the high-risk retry mode setting &#x2014; same visual behavior as NMI 201/253.</li>
            </ul>
            
            <h3>v2.7.7 &#x2014; second-processor Bridge for Official Plugin Compatibility</h3>
            <ul>
                <li><strong>second-processor note bridge:</strong> When the official second-processor plugin is active, it stores the full API error response in order meta but doesn't write a structured failure note. POP now reads this meta at priority 4 (before classification at priority 5) and writes a normalized <code>second-processor failure reason: {code} - {message}</code> note. This ensures POP can classify second-processor failures regardless of which second-processor plugin variant is installed.</li>
                <li><strong>Safe coexistence:</strong> The bridge checks for an existing structured note before writing &#x2014; if the custom second-processor plugin already wrote one, the bridge exits immediately with zero overhead.</li>
            </ul>
            
            <h3>v2.7.6 &#x2014; the legacy second processor Classification (Clean Implementation)</h3>
            <ul>
                <li><strong>Multi-gateway classification:</strong> POP now classifies failures from both NMI (numeric codes, format <code>NMI failure reason: {code} - {reason}</code>) and the legacy second processor (string codes, format <code>second-processor failure reason: {code} - {reason}</code>). Both plugins (custom the legacy second processor and official the secondary gateway with bridge snippet) write the same normalized note format.</li>
                <li><strong>the legacy second processor retryable codes (10):</strong> processing_error, service_not_available, timeout, card_network_issue, duplicate_transaction, etc. &#x2014; classified as gateway-side, counts against health, triggers auto-retry.</li>
                <li><strong>the legacy second processor non-retryable codes (34):</strong> insufficient_funds, invalid_cvv, expired_card, do_not_honor, generic_decline, stolen_card, suspected_fraud, payment_issue, invalid_input, etc. &#x2014; classified as customer-side, does NOT count against health.</li>
                <li><strong>Customer messages:</strong> 17 friendly, processor-neutral messages for second-processor decline codes.</li>
                <li><strong>Recovery page rules:</strong> Failure Classification Rules section now shows second-processor retryable and non-retryable codes alongside NMI codes.</li>
            </ul>
            
            <h3>v2.7.4 &#x2014; Dashboard Net Revenue &amp; Failure Category Breakdown</h3>
            <ul>
                <li><strong>Dashboard "Net Revenue Lost" tile:</strong> Now shows failed revenue MINUS recovered revenue (net impact). Sub-line breaks out retryable (gateway-side) vs non-retryable (customer-side) dollar amounts so you can see how much of the loss was preventable vs customer error.</li>
                <li><strong>Dashboard "Recovered Today" tile:</strong> Now shows recovered amount with context line showing total fails it was recovered from.</li>
                <li><strong>Recovery page non-retryable data:</strong> Today and monthly summary rows now include a Non-Retryable tile showing count and dollar value of customer-caused failures (CVV, insufficient funds, expired card). Retryable tile also shows dollar amount. Gives complete visibility into both failure categories.</li>
            </ul>
            
            <h3>v2.7.3 &#x2014; Revenue Accuracy, Alert Fix &amp; UI Polish</h3>
            <ul>
                <li><strong>Revenue Lost de-duplication:</strong> Fixed inflated "Revenue Lost (Fails)" numbers across Dashboard, Scorecards, and Recovery pages. When an order had multiple failure records (e.g., failed on the previous gateway, retried on NMI, failed again), each record counted the same order_total separately. Now uses <code>DISTINCT order_id</code> so each order's value is counted only once.</li>
                <li><strong>Failure alert unique customer fix:</strong> Alerts now require failures from 3 unique customers (identified by customer ID, billing email, or IP address) before triggering. Previously, a single customer entering a wrong CVV 3 times would fire a false alarm because the uniqueness check was bypassed when customer emails weren't available in the request context.</li>
                <li><strong>API settings sliders:</strong> Fixed Enable API and Require HTTPS toggles that appeared as invisible checkboxes. Both now use the standard slider pattern matching all other settings toggles.</li>
                <li><strong>High-risk customer message:</strong> Updated the 201/253 retry message from bank-blame language to neutral: "Payment failed to process. You can try this card again or use a different card to complete your order."</li>
            </ul>
            
            <h3>v2.7.2 &#x2014; API Authentication Hardening</h3>
            <ul>
                <li><strong>Proxy-aware SSL detection:</strong> Fixed <code>is_ssl()</code> failing behind reverse proxies (Convesio, Cloudflare, load balancers) by also checking <code>X-Forwarded-Proto</code> and <code>X-Forwarded-SSL</code> headers. Previously, all API requests were rejected as non-SSL even when the client used HTTPS.</li>
                <li><strong>Multiple auth header formats:</strong> API key now accepted via <code>X-PO-API-Key</code>, <code>Authorization: Bearer</code>, raw <code>$_SERVER['HTTP_X_PO_API_KEY']</code>, and <code>api_key</code> query parameter. Covers hosting environments that strip or transform custom headers.</li>
                <li><strong>Diagnostic endpoint:</strong> New <code>GET /po/v1/ping</code> (no auth required) reports which checks would succeed or fail &#x2014; API enabled status, key configured, SSL detected, IP allowlist result, and which auth headers were received. Makes debugging connection issues instant.</li>
                <li><strong>Specific error responses:</strong> Authentication failures now return <code>WP_Error</code> with descriptive codes (<code>po_ssl_required</code>, <code>po_api_disabled</code>, <code>po_missing_key</code>, <code>po_invalid_key</code>, <code>po_ip_blocked</code>, <code>po_rate_limited</code>) instead of generic <code>rest_forbidden</code>.</li>
                <li><strong>Full rejection logging:</strong> Every authentication failure is now logged with the specific reason, client IP, and relevant context.</li>
            </ul>
            
            <h3>v2.7.1 &#x2014; REST API for External Dashboards</h3>
            <ul>
                <li><strong>REST API:</strong> 8 read-only endpoints exposing all POP data for external dashboards (Loveable, Retool, custom apps). Covers gateways, transactions, failures, today/monthly stats, recovery data, health scores, and rotation weights.</li>
                <li><strong>API security:</strong> 5-layer protection &#x2014; API key with SHA-256 hashing and constant-time comparison, optional IP allowlist, rate limiting (60 req/min), HTTPS enforcement, and strict read-only GET methods. No customer PII in any response.</li>
                <li><strong>Settings UI:</strong> New "REST API" section in Settings with enable toggle, one-click key generation (shown once, stored as hash), key revocation, IP allowlist field, and SSL toggle.</li>
                <li><strong>API documentation:</strong> Full endpoint reference with parameters, response fields, and Loveable integration examples in Help &#x2192; API Documentation.</li>
            </ul>
            
            <h3>v2.7.0 &#x2014; Data Accuracy, Failure Attribution &amp; Recovery Visibility</h3>
            <ul>
                <li><strong>Dashboard revenue consistency:</strong> Failed revenue and recovered revenue now reference the same cohort of orders (both keyed on <code>created_at</code>). Previously, recovered revenue used <code>recovered_at</code>, which could show recovery dollars on a different day than the corresponding failure &#x2014; making the numbers appear mismatched across tabs.</li>
                <li><strong>Gateway vs. customer failure split:</strong> Each gateway tile in "Today's Activity" now breaks failures into gateway-side (retryable errors like timeouts, token issues) and customer-side (CVV mismatch, insufficient funds, expired card). Displayed as <code>3 &#x2717; (1 gw &#xB7; 2 cust)</code> so you can see at a glance whether failures are the gateway's problem or the customer's.</li>
                <li><strong>Scorecards accuracy:</strong> Summary totals now use direct aggregate queries against the source tables instead of summing per-gateway arrays. Per-gateway cards clearly label "Failed on this gateway" vs "Recovered by this gateway" with counts and dollar amounts &#x2014; no ambiguity about attribution direction. Added Recovery Rate tile to totals bar.</li>
                <li><strong>Recovery page today/month toggle:</strong> Top Failure Codes section now defaults to Today with an instant client-side tab switch to the monthly view. Both datasets load on page render so toggling is instantaneous &#x2014; no page reload needed.</li>
            </ul>
            
            <h3>v2.6.4 &#x2014; Retry Race Condition Fix &amp; Dashboard Enhancements</h3>
            <ul>
                <li><strong>Fixed retry race condition:</strong> When a payment failed and POP redirected the customer to retry on a different gateway, two PHP sessions could process the same order simultaneously on the order-pay page (particularly with Direct Post gateways). This could cause duplicate API calls or cross-gateway token errors. Fix pre-stamps the order's payment method to the retry gateway during failure handling, and adds a transient-based processing lock that blocks the duplicate session.</li>
                <li><strong>Dashboard tile reorder:</strong> Summary tiles now show: Processed &#x2192; Transactions &#x2192; Success Rate &#x2192; Revenue Lost &#x2192; Recovered &#x2192; Recovery Rate. Moves success rate next to transaction count for a more logical reading flow.</li>
                <li><strong>Recovery Rate tile:</strong> New tile showing today's recovery percentage (recovered revenue &#xF7; failed revenue). Gives an at-a-glance measure of how effectively the system is saving failed transactions.</li>
                <li><strong>Per-gateway recovery figures:</strong> Each gateway in "Today's Activity" now shows how much revenue it recovered today &#x2014; i.e., transactions that failed on another gateway but were retried and succeeded on this one.</li>
            </ul>
            
            <h3>v2.6.3 &#x2014; Smooth Pacing, Daily Budget &amp; Message Refinement</h3>
            <ul>
                <li><strong>Continuous pace scoring:</strong> Replaced the 5-bucket pace ladder (way behind / behind / on track / ahead) with a smooth linear scale. Gateways slightly ahead now get a proportionally small score reduction instead of the same flat -20 penalty as gateways far ahead.</li>
                <li><strong>Daily budget throttle:</strong> New scoring component that divides remaining monthly capacity across remaining days. As today's fair share gets consumed, the gateway's score progressively drops &#x2014; other gateways absorb volume, creating an even daily drip. Budget resets each day automatically.</li>
                <li><strong>Removed capacity urgency buckets:</strong> The old "urgency ratio" bonus (which used its own discrete brackets) is replaced by the daily budget throttle, which achieves the same goal more precisely using actual intraday data.</li>
                <li><strong>Escalating messages for 201 &amp; 253:</strong> First failure shows neutral language ("not successful, no charges made"). Subsequent failures on the same order escalate to mention the bank's security filter and suggest a different card. Reduces customer anxiety on first attempt while providing clear guidance on retry.</li>
                <li><strong>Enhanced debug logging:</strong> When rotation debug is enabled, each gateway's full score breakdown (pace ratio, daily budget, today's volume, daily usage %) is logged for troubleshooting.</li>
            </ul>
            
            <h3>v2.6.2 &#x2014; Gateway Types, Order Lookup, Master Toggle</h3>
            <ul>
                <li><strong>Gateway types:</strong> Card Payment vs Alternative (Zelle, P2P, ACH). Alternative gateways skip card-type routing logic.</li>
                <li><strong>Full card type control:</strong> Visa and Mastercard are now individually toggleable (no longer assumed always-accepted).</li>
                <li><strong>Quick order lookup:</strong> Enter any Order ID on the Reports page to instantly see status, gateway, failure details, and recovery path.</li>
                <li><strong>Enhanced master toggle:</strong> Warning icon, colored border, detailed explanation of what continues running when orchestration is paused.</li>
                <li><strong>Help page updated</strong> with master toggle, gateway types, order lookup, and CSV export documentation.</li>
            </ul>
            
            <h3>v2.6.0 &ndash; v2.6.1 &#x2014; Navigation &amp; Reporting</h3>
            <ul>
                <li><strong>SVG icon tab bar:</strong> Redesigned navigation with clean stroke icons matching professional admin UI patterns.</li>
                <li><strong>Logs button:</strong> Right-aligned compact link in the tab bar for quick access without cluttering primary navigation.</li>
                <li><strong>CSV export gateway filter:</strong> Exports now respect the selected gateway filter instead of exporting all gateways.</li>
                <li><strong>Failed transactions in CSV:</strong> New section with failure code, reason, category, recovery gateway, recovery source, and a Notes column summarizing the recovery path.</li>
            </ul>
            
            <h3>v2.5.8 &ndash; v2.5.9 &#x2014; UI Enhancements</h3>
            <ul>
                <li><strong>Master on/off toggle:</strong> Pause gateway orchestration without disabling tracking, recovery, or reporting.</li>
                <li><strong>Unified tab navigation:</strong> Shared header with version badge and active/paused status across all admin pages.</li>
                <li><strong>Recovered revenue tile:</strong> Dashboard now shows today's recovered revenue alongside other summary metrics.</li>
                <li><strong>Monthly volume in gateway table:</strong> Current month's processed volume shown under each gateway's target with progress bar.</li>
                <li><strong>Per-gateway rotation toggle:</strong> Instant on/off switch for individual gateways without editing.</li>
                <li><strong>POP display names in Recovery:</strong> Recovery page now shows your custom gateway names instead of WooCommerce defaults.</li>
            </ul>
            
            <h3>v2.5.7 &#x2014; Integration Hooks</h3>
            <ul>
                <li><strong>po_recovery_eligible action:</strong> Fires after failure classification with full context for external email plugins.</li>
                <li><strong>po_order_recovered action:</strong> Fires after successful recovery for cancelling pending email sequences.</li>
                <li><strong>Public API methods:</strong> getRecoveryPayUrl(), isRecoveryActive(), getFailureDetails() for external plugin integration.</li>
                <li><strong>recovery_source column:</strong> Tracks whether recovery came from auto_redirect, email, sms, or manual.</li>
            </ul>
        </div>
    </div>
</div>

<style>
.po-help-page { max-width: 1000px; }
.po-help-header { margin-bottom: 24px; }
.po-help-header h1 { margin: 0 0 8px 0; font-size: 28px; font-weight: 600; }
.po-help-version { color: #6b7280; font-size: 14px; margin: 0; }
.po-help-nav-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
.po-help-nav-item { display: flex; flex-direction: column; align-items: center; padding: 16px; background: #f9fafb; border-radius: 8px; text-decoration: none; color: #374151; transition: all 0.2s; }
.po-help-nav-item:hover { background: #1e293b; color: #fff; }
.po-help-nav-item .dashicons { font-size: 24px; width: 24px; height: 24px; margin-bottom: 8px; }
.po-help-content h3 { color: #111827; font-size: 18px; font-weight: 600; margin: 24px 0 12px 0; padding-top: 16px; border-top: 1px solid #e5e7eb; }
.po-help-content h3:first-child { margin-top: 0; padding-top: 0; border-top: none; }
.po-help-content h4 { color: #374151; font-size: 15px; font-weight: 600; margin: 16px 0 8px 0; }
.po-help-content p { color: #4b5563; line-height: 1.6; margin: 0 0 12px 0; }
.po-help-content ul, .po-help-content ol { color: #4b5563; margin: 0 0 16px 0; padding-left: 24px; }
.po-help-content li { margin-bottom: 8px; line-height: 1.6; }
.po-help-table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 14px; }
.po-help-table th { text-align: left; padding: 12px; background: #f9fafb; border: 1px solid #e5e7eb; font-weight: 600; color: #374151; }
.po-help-table td { padding: 12px; border: 1px solid #e5e7eb; color: #4b5563; vertical-align: top; }
.po-help-concepts { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin: 16px 0; }
.po-help-concept { background: #f9fafb; padding: 16px; border-radius: 8px; border-left: 4px solid #1e293b; }
.po-help-concept h4 { margin: 0 0 8px 0; color: #1e293b; }
.po-help-concept p { margin: 0; font-size: 14px; }
.po-help-steps { counter-reset: step; }
.po-help-steps > li { margin-bottom: 20px; padding-left: 8px; }
.po-help-steps > li h4 { margin-top: 0; }
.po-help-warning { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 16px; margin: 16px 0; }
.po-help-warning h4 { margin: 0 0 8px 0; color: #92400e; }
.po-help-warning p { margin: 0; color: #92400e; }
.po-help-tip { background: #dbeafe; border: 1px solid #3b82f6; border-radius: 8px; padding: 16px; margin: 16px 0; }
.po-help-tip h4 { margin: 0 0 8px 0; color: #1e40af; }
.po-help-tip p { margin: 0; color: #1e40af; }
.po-help-flow { margin: 20px 0; }
.po-help-flow-step { display: flex; gap: 16px; margin-bottom: 16px; padding: 16px; background: #f9fafb; border-radius: 8px; }
.po-help-flow-num { display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #1e293b; color: #fff; border-radius: 50%; font-weight: 600; flex-shrink: 0; }
.po-help-flow-step h4 { margin: 0 0 4px 0; }
.po-help-flow-step p { margin: 0; font-size: 14px; }
.po-help-faq-item { border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px; overflow: hidden; }
.po-help-faq-item h4 { margin: 0; padding: 16px; background: #f9fafb; cursor: pointer; font-size: 15px; }
.po-help-faq-answer { padding: 16px; border-top: 1px solid #e5e7eb; }
.po-help-faq-answer p:last-child, .po-help-faq-answer ul:last-child { margin-bottom: 0; }
.po-help-glossary { margin: 0; }
.po-help-glossary dt { font-weight: 600; color: #111827; margin-top: 16px; }
.po-help-glossary dt:first-child { margin-top: 0; }
.po-help-glossary dd { margin: 4px 0 0 0; color: #4b5563; padding-bottom: 16px; border-bottom: 1px solid #e5e7eb; }
.po-card-header h2 .dashicons { margin-right: 8px; color: #1e293b; }
@media (max-width: 768px) { .po-help-nav-grid { grid-template-columns: repeat(2, 1fr); } .po-help-concepts { grid-template-columns: 1fr; } }
</style>
