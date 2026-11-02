<?php
/**
 * Command Center dashboard (2.7.33) - selectable in Settings -> Appearance.
 *
 * Styles, script and icons: assets/css|js/command-center.*, assets/icons/command-center.svg (2.7.35).
 *
 * Expects: $cc (CommandCenterData::build), $stats (VolumeTracker::getAllStats),
 * $history (VolumeTracker::getThreeMonthHistory), $daysRemaining.
 *
 * @package PaymentOrchestrator
 */

defined('ABSPATH') || exit;

use PaymentOrchestrator\Admin\CommandCenterView as CCV;

$s   = $cc['sales'];
$f   = $cc['failed'];
$r   = $cc['recovery'];
$lw  = $cc['last_week'];
$tz  = wp_timezone();
$dayLabel = (new DateTimeImmutable($cc['date'] . ' 12:00:00', $tz))->format('l, M j');
$lwLabel  = (new DateTimeImmutable($cc['last_week_date'] . ' 12:00:00', $tz))->format('D, M j');
$base     = admin_url('admin.php?page=payment-orchestrator');
$prevUrl  = add_query_arg('po_date', \PaymentOrchestrator\Admin\CommandCenterData::shiftDate($cc['date'], -1), $base);
$nextDate = \PaymentOrchestrator\Admin\CommandCenterData::shiftDate($cc['date'], 1);
$today    = current_time('Y-m-d');
$yday     = \PaymentOrchestrator\Admin\CommandCenterData::shiftDate($today, -1);
$recMult  = $lw['recovery'] > 0 ? round($r['value'] / $lw['recovery'], 1) : null;
$rerPct   = $r['rerouted'] > 0 ? round($r['rerouted_recovered'] / $r['rerouted'] * 100) : 0;
$maxWeek  = max(1.0, max(array_column($cc['week'], 'volume')));
$catMax   = max(1.0, $f['value']);
?>

<div class="wrap">
    <h1 style="display:none;">Payment Orchestrator</h1>
    <div class="po-wrap">
        <div class="po-layout">
            <?php $currentPage = 'payment-orchestrator'; include PO_PLUGIN_DIR . 'templates/admin/partials/header-nav.php'; ?>

<div class="po-cc">

    <!-- Day picker -->
    <div class="po-cc-bar">
        <nav class="po-cc-nav" aria-label="Choose a day">
            <a class="po-cc-btn" href="<?php echo esc_url($prevUrl); ?>" aria-label="Previous day"><?php echo CCV::icon('left'); ?></a>
            <a class="po-cc-btn<?php echo $cc['date'] === $yday ? ' is-on' : ''; ?>" href="<?php echo esc_url(add_query_arg('po_date', $yday, $base)); ?>">Yesterday</a>
            <a class="po-cc-btn<?php echo $cc['is_today'] ? ' is-on' : ''; ?>" href="<?php echo esc_url($base); ?>">Today</a>
            <a class="po-cc-btn" href="<?php echo esc_url(add_query_arg('po_date', $nextDate, $base)); ?>" aria-label="Next day" <?php echo $cc['is_today'] ? 'aria-disabled="true" tabindex="-1"' : ''; ?>><?php echo CCV::icon('right'); ?></a>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:inline-flex;gap:6px;margin:0;">
                <input type="hidden" name="page" value="payment-orchestrator">
                <label class="screen-reader-text" for="po-cc-date">Pick a date</label>
                <input class="po-cc-date" id="po-cc-date" type="date" name="po_date" value="<?php echo esc_attr($cc['date']); ?>" max="<?php echo esc_attr($today); ?>">
                <button type="submit" class="po-cc-btn"><?php echo CCV::icon('calendar'); ?>Go</button>
            </form>
        </nav>
        <p class="po-cc-day"><strong style="color:var(--cc-ink);font-weight:600;"><?php echo esc_html($dayLabel); ?></strong> &#xB7; compared with <?php echo esc_html($lwLabel); ?></p>
    </div>

    <div class="po-cc-grid">
        <!-- View 1 &#xB7; Revenue -->
        <section class="po-cc-card" aria-labelledby="po-cc-rev">
            <div class="po-cc-head">
                <p class="po-cc-label" id="po-cc-rev"><span class="po-cc-ic" style="background:rgba(55,138,221,.14);"><?php echo CCV::icon('chart', '#378ADD'); ?></span>Gross volume</p>
                <?php echo CCV::delta(\PaymentOrchestrator\Admin\CommandCenterData::change($s['volume'], $lw['volume'])); ?>
            </div>
            <p class="po-cc-big"><?php echo esc_html(CCV::money($s['volume'])); ?></p>
            <p class="po-cc-note">vs <?php echo esc_html(CCV::money($lw['volume'])); ?> on <?php echo esc_html($lwLabel); ?></p>
            <div class="po-cc-chart">
            <svg class="po-cc-svg" viewBox="0 0 340 112" width="100%" role="img" aria-label="Daily volume for the last 7 days, ending on the selected day. Hover, tap or focus a day for its total.">
                <title>Last 7 days of volume</title>
                <?php foreach ($cc['week'] as $i => $d):
                    $h = max(2, round($d['volume'] / $maxWeek * 88)); $x = 6 + $i * 48; $last = $i === 6; ?>
                    <?php $dl = date_i18n('D, M j', strtotime($d['date'])); $tip = wp_json_encode(['t' => $dl, 's' => CCV::money($d['volume']), 'r' => []], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
                    <g class="po-cc-hov" tabindex="0" role="button" aria-label="<?php echo esc_attr($dl . ': ' . CCV::money($d['volume'])); ?>" data-tip="<?php echo esc_attr($tip); ?>">
                    <rect x="<?php echo (int) ($x - 6); ?>" y="0" width="46" height="110" fill="transparent"/>
                    <rect x="<?php echo (int) $x; ?>" y="<?php echo 90 - $h; ?>" width="34" height="<?php echo (int) $h; ?>" rx="5" fill="#378ADD" opacity="<?php echo $last ? '1' : '.35'; ?>"/>
                    <text x="<?php echo (int) ($x + 17); ?>" y="106" text-anchor="middle" font-size="11" fill="#5c6370"><?php echo esc_html(substr(date_i18n('D', strtotime($d['date'])), 0, 1)); ?></text>
                    </g>
                <?php endforeach; ?>
            </svg>
            <div class="po-cc-tip" role="status" aria-live="polite" hidden></div>
            </div>
            <div class="po-cc-tiles">
                <div class="po-cc-tile"><span class="po-cc-ic" style="background:rgba(127,119,221,.14);"><?php echo CCV::icon('cart', '#7F77DD'); ?></span><div><b><?php echo esc_html(number_format($s['paid_orders'])); ?></b><span>Paid orders</span></div></div>
                <div class="po-cc-tile"><span class="po-cc-ic" style="background:rgba(29,158,117,.14);"><?php echo CCV::icon('target', '#1D9E75'); ?></span><div><b><?php echo esc_html($s['order_conversion']); ?>%</b><span>Order conversion</span></div></div>
                <div class="po-cc-tile"><span class="po-cc-ic" style="background:rgba(29,158,117,.14);"><?php echo CCV::icon('refresh', '#1D9E75'); ?></span><div><b><?php echo esc_html(CCV::money($r['value'])); ?></b><span>Recovery</span></div></div>
                <div class="po-cc-tile"><span class="po-cc-ic" style="background:rgba(216,90,48,.14);"><?php echo CCV::icon('alert', '#D85A30'); ?></span><div><b><?php echo esc_html(CCV::money($f['value'])); ?></b><span>Failed</span></div></div>
            </div>
            <p class="po-cc-note" style="margin-top:10px;">Approval rate <?php echo esc_html($s['approval_rate']); ?>% &#xB7; <?php echo esc_html(number_format($s['approved'])); ?> approved &#xB7; <?php echo esc_html(number_format($s['declined'])); ?> declined</p>
        </section>

        <!-- View 2 &#xB7; Recovery -->
        <section class="po-cc-card is-rec" aria-labelledby="po-cc-recv">
            <div class="po-cc-head">
                <p class="po-cc-label" id="po-cc-recv" style="color:var(--cc-green-tx);"><span class="po-cc-ic" style="background:rgba(29,158,117,.14);"><?php echo CCV::icon('shield', '#1D9E75'); ?></span>Recovery</p>
                <?php if ($recMult !== null): ?><span class="po-cc-pill is-up"><?php echo esc_html($recMult); ?>&#xD7; <?php echo esc_html(substr($lwLabel, 0, 3)); ?> last week</span><?php endif; ?>
            </div>
            <p class="po-cc-big" style="color:var(--cc-green-tx);"><?php echo esc_html(CCV::money($r['value'])); ?></p>
            <p class="po-cc-note" style="margin-bottom:12px;"><?php echo esc_html(number_format($r['orders'])); ?> failed orders turned into sales</p>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <svg viewBox="0 0 72 72" width="84" height="84" role="img" aria-label="<?php echo esc_attr($r['rerouted_recovered'] . ' of ' . $r['rerouted'] . ' rerouted orders recovered'); ?>">
                    <circle cx="36" cy="36" r="27" fill="none" stroke="rgba(29,158,117,.18)" stroke-width="8"/>
                    <circle cx="36" cy="36" r="27" fill="none" stroke="#1D9E75" stroke-width="8" stroke-linecap="round" stroke-dasharray="<?php echo esc_attr(round(169.6 * $rerPct / 100, 1)); ?> 169.6" transform="rotate(-90 36 36)"/>
                    <text x="36" y="41" text-anchor="middle" font-size="14" font-weight="600" fill="#15181d"><?php echo (int) $rerPct; ?>%</text>
                </svg>
                <div style="flex:1;min-width:200px;">
                    <p style="margin:0 0 6px;font-size:14px;">Rerouted <span style="color:var(--cc-sub);">&#xB7; <?php echo (int) $r['rerouted_recovered']; ?> of <?php echo (int) $r['rerouted']; ?> recovered</span></p>
                    <div class="po-cc-row"><span><?php echo CCV::icon('retry', '#1D9E75'); ?>POP Retry</span><span><b><?php echo esc_html(CCV::money($r['retry_value'])); ?></b>&nbsp;<span style="color:var(--cc-sub);">&#xB7; <?php echo (int) $r['retry_orders']; ?></span></span></div>
                    <div class="po-cc-row"><span><?php echo CCV::icon('mail', '#7F77DD'); ?>Recovery email</span><span><b><?php echo esc_html(CCV::money($r['email_value'])); ?></b>&nbsp;<span style="color:var(--cc-sub);">&#xB7; <?php echo (int) $r['email_orders']; ?></span></span></div>
                </div>
            </div>
            <div class="po-cc-tiles">
                <div class="po-cc-tile"><div><b><?php echo (int) $r['no_eligible']; ?></b><span>No eligible account for POP Retry</span></div></div>
                <div class="po-cc-tile"><div><b style="color:var(--cc-green-tx);"><?php echo esc_html(CCV::money($cc['week_recovery'])); ?></b><span>Recovery, last 7 days</span></div></div>
            </div>
        </section>
    </div>

    <div class="po-cc-grid">
        <!-- View 3 &#xB7; Failures -->
        <section class="po-cc-card" aria-labelledby="po-cc-fail">
            <div class="po-cc-head">
                <p class="po-cc-label" id="po-cc-fail"><span class="po-cc-ic" style="background:rgba(216,90,48,.14);"><?php echo CCV::icon('octagon', '#D85A30'); ?></span>Failed orders</p>
                <span class="po-cc-pill is-soft"><?php echo (int) $f['orders']; ?> orders &#xB7; <?php echo (int) $lw['failed']; ?> last week</span>
            </div>
            <p class="po-cc-big" style="margin-bottom:14px;"><?php echo esc_html(CCV::money($f['value'])); ?></p>
            <?php foreach ([['processor', 'Processor', 'server', '#E24B4A'], ['customer', 'Customer card', 'card', '#EF9F27'], ['other', 'Other', 'dots', '#888780']] as [$k, $label, $ic, $col]):
                [$n, $v] = $f['cats'][$k]; ?>
                <div style="margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;"><span style="display:flex;align-items:center;gap:6px;"><?php echo CCV::icon($ic, $col); ?><?php echo esc_html($label); ?> <span style="color:var(--cc-sub);">&#xB7; <?php echo (int) $n; ?></span></span><b><?php echo esc_html(CCV::money($v)); ?></b></div>
                    <div class="po-cc-track"><div style="width:<?php echo esc_attr(round($v / $catMax * 100, 1)); ?>%;background:<?php echo esc_attr($col); ?>;"></div></div>
                </div>
            <?php endforeach;
            $oc = $f['outcome']; $ot = max(1, array_sum($oc)); ?>
            <p class="po-cc-note" style="margin-top:14px;">Where the failed orders ended up</p>
            <div class="po-cc-stack" role="img" aria-label="<?php echo esc_attr($oc['recovery'] . ' recovery, ' . $oc['customer'] . ' paid by customer, ' . $oc['unpaid'] . ' still unpaid'); ?>">
                <div style="flex:<?php echo (int) $oc['recovery']; ?>;background:#1D9E75;"></div><div style="flex:<?php echo (int) $oc['customer']; ?>;background:#378ADD;"></div><div style="flex:<?php echo (int) $oc['unpaid']; ?>;background:#B4B2A9;"></div>
            </div>
            <div class="po-cc-legend"><span><i class="po-cc-dot" style="background:#1D9E75;"></i>Recovery <?php echo (int) $oc['recovery']; ?></span><span><i class="po-cc-dot" style="background:#378ADD;"></i>Paid by customer <?php echo (int) $oc['customer']; ?></span><span><i class="po-cc-dot" style="background:#B4B2A9;"></i>Still unpaid <?php echo (int) $oc['unpaid']; ?></span></div>
        </section>

        <!-- View 4 &#xB7; Activity by gateway -->
        <section class="po-cc-card" aria-labelledby="po-cc-act">
            <p class="po-cc-label" id="po-cc-act" style="margin-bottom:12px;"><span class="po-cc-ic" style="background:rgba(55,138,221,.14);"><?php echo CCV::icon('bank', '#378ADD'); ?></span>Activity by gateway</p>
            <div class="po-cc-gw">
                <?php foreach ($cc['names'] as $gid => $gname):
                    $col = $cc['colors'][$gid]; $sv = $s['by_gateway'][$gid] ?? ['volume' => 0, 'paid_orders' => 0];
                    $fv = $f['by_gateway'][$gid] ?? ['orders' => 0, 'value' => 0, 'processor' => 0, 'customer' => 0];
                    $rv = $r['by_gateway'][$gid] ?? [0, 0.0]; ?>
                    <div style="border:1px solid var(--cc-line);border-radius:14px;padding:14px;border-top:4px solid <?php echo esc_attr($col); ?>;">
                        <p style="margin:0;font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px;"><i class="po-cc-dot" style="background:<?php echo esc_attr($col); ?>;"></i><?php echo esc_html($gname); ?></p>
                        <p style="margin:8px 0 0;font-size:26px;font-weight:600;"><?php echo esc_html(CCV::money((float) $sv['volume'])); ?></p>
                        <p class="po-cc-note"><?php echo (int) $sv['paid_orders']; ?> paid orders</p>
                        <div class="po-cc-tiles">
                            <div class="po-cc-tile"><div><span><?php echo CCV::icon('alert', '#D85A30'); ?> Failed</span><b><?php echo esc_html(CCV::money((float) $fv['value'])); ?></b><span><?php echo (int) $fv['orders']; ?> &#xB7; <?php echo (int) $fv['processor']; ?> processor &#xB7; <?php echo (int) $fv['customer']; ?> customer</span></div></div>
                            <div class="po-cc-tile" style="background:var(--cc-green-bg);"><div><span style="color:var(--cc-green-tx);"><?php echo CCV::icon('refresh', '#0F6E56'); ?> Recovered here</span><b style="color:var(--cc-green-tx);"><?php echo esc_html(CCV::money((float) $rv[1])); ?></b><span style="color:var(--cc-green-tx);"><?php echo (int) $rv[0]; ?> orders</span></div></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <div class="po-cc-grid">
        <!-- View 5 &#xB7; Gateway performance (this month) -->
        <section class="po-cc-card" aria-labelledby="po-cc-perf">
            <div class="po-cc-head" style="margin-bottom:6px;">
                <p class="po-cc-label" id="po-cc-perf"><span class="po-cc-ic" style="background:rgba(55,138,221,.14);"><?php echo CCV::icon('calendar', '#378ADD'); ?></span><?php echo esc_html(current_time('F')); ?> &#xB7; <?php echo (int) $daysRemaining; ?> days left</p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=payment-orchestrator-gateways')); ?>" style="font-size:13px;">Manage gateways</a>
            </div>
            <?php foreach ($stats as $gid => $st):
                $col = $cc['colors'][$gid] ?? '#888780'; $ok = (int) ($st['success_count'] ?? 0); $bad = (int) ($st['fail_count'] ?? 0);
                $rate = ($ok + $bad) > 0 ? round($ok / ($ok + $bad) * 100, 1) : 100; $target = (float) ($st['monthly_target'] ?? 0);
                $pace = (string) ($st['pace_status'] ?? ''); ?>
                <div style="padding:12px 0;border-top:1px solid var(--cc-line);">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap;">
                        <span style="font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px;"><i class="po-cc-dot" style="background:<?php echo esc_attr($col); ?>;"></i><?php echo esc_html($cc['names'][$gid] ?? ($st['gateway']->displayName ?? $gid)); ?>
                            <?php if ($target <= 0): ?><span class="po-cc-pill is-blue">Primary</span><?php elseif ($pace === 'ahead'): ?><span class="po-cc-pill is-amber">Ahead</span><?php elseif ($pace === 'behind'): ?><span class="po-cc-pill is-down">Behind</span><?php else: ?><span class="po-cc-pill is-up">On track</span><?php endif; ?>
                        </span>
                        <span style="font-size:22px;font-weight:600;"><?php echo esc_html(CCV::money((float) ($st['monthly_volume'] ?? 0))); ?></span>
                    </div>
                    <p class="po-cc-note" style="margin:6px 0 4px;">Approval <?php echo esc_html($rate); ?>% &#xB7; <?php echo esc_html(number_format($ok)); ?> approved &#xB7; <?php echo esc_html(number_format($bad)); ?> declined</p>
                    <div class="po-cc-track" style="background:rgba(226,75,74,.2);"><div style="width:<?php echo esc_attr($rate); ?>%;background:#1D9E75;"></div></div>
                    <?php if ($target > 0): $pct = min(100, round(($st['monthly_volume'] ?? 0) / $target * 100)); ?>
                        <div style="display:flex;justify-content:space-between;font-size:13px;margin:10px 0 4px;"><span>Monthly target</span><span><b><?php echo (int) $pct; ?>%</b> <span style="color:var(--cc-sub);">&#xB7; <?php echo esc_html(CCV::money((float) $st['monthly_volume'])); ?> of <?php echo esc_html(CCV::money($target)); ?></span></span></div>
                        <div class="po-cc-track"><div style="width:<?php echo (int) $pct; ?>%;background:<?php echo esc_attr($col); ?>;"></div></div>
                    <?php else: ?>
                        <p class="po-cc-note" style="margin-top:6px;">No monthly cap</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- 3-month volume by gateway -->
        <section class="po-cc-card" aria-labelledby="po-cc-3m">
            <?php
            $months = $history['months'] ?? [];
            $mTotals = array_fill(0, count($months), 0.0);
            $gwTotals = [];
            foreach (($history['gateways'] ?? []) as $g) {
                foreach ($g['data'] as $mi => $md) {
                    $mTotals[$mi] = ($mTotals[$mi] ?? 0) + (float) $md['volume'];
                    $gwTotals[$g['gateway_id']] = ($gwTotals[$g['gateway_id']] ?? 0) + (float) $md['volume'];
                }
            }
            $maxM = max(1.0, max($mTotals ?: [1]));
            $n = max(1, count($months)); $slot = 340 / $n; $bw = min(80, $slot * 0.55);
            ?>
            <p class="po-cc-label" id="po-cc-3m"><span class="po-cc-ic" style="background:rgba(55,138,221,.14);"><?php echo CCV::icon('bars', '#378ADD'); ?></span>3-month volume by gateway</p>
            <p class="po-cc-big" style="font-size:30px;"><?php echo esc_html(CCV::short(array_sum($mTotals))); ?></p>
            <p class="po-cc-note" style="margin-bottom:10px;"><?php echo esc_html(($months[0] ?? '') . ' ' . "\u{2013}" . ' ' . (end($months) ?: '')); ?></p>
            <div class="po-cc-chart">
            <svg class="po-cc-svg" viewBox="0 0 340 190" width="100%" role="img" aria-label="Stacked monthly volume by gateway for the last three months. Hover, tap or focus a month for its breakdown.">
                <title>Monthly volume by gateway</title>
                <line x1="0" y1="160" x2="340" y2="160" stroke="#e6e8ec"/>
                <?php foreach ($months as $mi => $mLabel):
                    $x = $slot * $mi + ($slot - $bw) / 2; $y = 160.0; $rows = [];
                    foreach (($history['gateways'] ?? []) as $g) {
                        $v = (float) ($g['data'][$mi]['volume'] ?? 0);
                        if ($v > 0) {
                            $rows[] = ['n' => (string) $g['gateway_name'], 'c' => $cc['colors'][$g['gateway_id']] ?? '#888780', 'v' => CCV::money($v),
                                       'p' => $mTotals[$mi] > 0 ? round($v / $mTotals[$mi] * 100) . '%' : ''];
                        }
                    }
                    $tip = wp_json_encode(['t' => (string) $mLabel, 's' => CCV::money($mTotals[$mi]), 'r' => $rows], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
                    <g class="po-cc-hov" tabindex="0" role="button" aria-label="<?php echo esc_attr($mLabel . ': ' . CCV::money($mTotals[$mi]) . ' ' . "\u{2014}" . ' show breakdown'); ?>" data-tip="<?php echo esc_attr($tip); ?>">
                        <rect x="<?php echo esc_attr(round($slot * $mi, 1)); ?>" y="0" width="<?php echo esc_attr(round($slot, 1)); ?>" height="185" fill="transparent"/>
                        <?php foreach (($history['gateways'] ?? []) as $g):
                            $v = (float) ($g['data'][$mi]['volume'] ?? 0); if ($v <= 0) { continue; }
                            $h = $v / $maxM * 135; $y -= $h; ?>
                            <rect x="<?php echo esc_attr(round($x, 1)); ?>" y="<?php echo esc_attr(round($y, 1)); ?>" width="<?php echo esc_attr(round($bw, 1)); ?>" height="<?php echo esc_attr(round($h, 1)); ?>" fill="<?php echo esc_attr($cc['colors'][$g['gateway_id']] ?? '#888780'); ?>"/>
                        <?php endforeach; ?>
                        <text x="<?php echo esc_attr(round($x + $bw / 2, 1)); ?>" y="<?php echo esc_attr(round($y - 8, 1)); ?>" text-anchor="middle" font-size="13" font-weight="600" fill="#15181d"><?php echo esc_html(CCV::short($mTotals[$mi])); ?></text>
                        <text x="<?php echo esc_attr(round($x + $bw / 2, 1)); ?>" y="178" text-anchor="middle" font-size="12" fill="#5c6370"><?php echo esc_html($mLabel); ?></text>
                    </g>
                <?php endforeach; ?>
            </svg>
            <div class="po-cc-tip" role="status" aria-live="polite" hidden></div>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px;">
                <?php foreach (($history['gateways'] ?? []) as $g): ?>
                    <div style="display:flex;align-items:center;gap:8px;font-size:13px;">
                        <i class="po-cc-dot" style="background:<?php echo esc_attr($cc['colors'][$g['gateway_id']] ?? '#888780'); ?>;width:12px;height:12px;"></i>
                        <span style="flex:1;"><?php echo esc_html($g['gateway_name']); ?> <span style="color:var(--cc-sub);">&#xB7; <?php echo $g['is_unlimited'] ? 'uncapped' : 'monthly target'; ?></span></span>
                        <b><?php echo esc_html(CCV::short((float) ($gwTotals[$g['gateway_id']] ?? 0))); ?></b>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

</div><!-- .po-cc -->

        </div>
    </div>
</div>
