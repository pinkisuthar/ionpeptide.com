<?php
/**
 * DailyReporter - Sends daily performance reports via email
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Reports;

use PaymentOrchestrator\Gateway\GatewayRepository;
use PaymentOrchestrator\Volume\VolumeTracker;
use PaymentOrchestrator\Recovery\FailureHandler;
use PaymentOrchestrator\Core\Logger;

final class DailyReporter {
    private GatewayRepository $gateways;
    private VolumeTracker $volume;
    
    public function __construct(GatewayRepository $gateways, VolumeTracker $volume) {
        $this->gateways = $gateways;
        $this->volume = $volume;
    }
    
    /**
     * Send daily report email
     */
    public function send(): bool {
        $email = get_option('po_daily_report_email', get_option('admin_email'));
        
        if (!$email || !is_email($email)) {
            Logger::warning('Daily report skipped: invalid email');
            return false;
        }
        
        $stats = $this->volume->getAllStats();
        
        if (empty($stats)) {
            Logger::info('Daily report skipped: no gateways configured');
            return false;
        }
        
        $subject = $this->getSubject();
        $body = $this->buildEmailBody($stats);
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Payment Orchestrator <' . get_option('admin_email') . '>',
        ];
        
        $sent = wp_mail($email, $subject, $body, $headers);
        
        if ($sent) {
            Logger::info('Daily report sent', ['email' => $email]);
        } else {
            Logger::error('Daily report failed to send', ['email' => $email]);
        }
        
        return $sent;
    }
    
    /**
     * Get email subject
     */
    private function getSubject(): string {
        $siteName = get_bloginfo('name');
        $date = current_time('M j, Y');
        return "\u{1F4CA} Payment Report - {$siteName} - {$date}";
    }
    
    /**
     * Build email HTML body
     */
    private function buildEmailBody(array $stats): string {
        $date = current_time('l, F j, Y');
        $dayOfMonth = (int) current_time('j');
        $daysInMonth = (int) current_time('t');
        $daysRemaining = $daysInMonth - $dayOfMonth;
        
        // Calculate totals
        $totalToday = 0;
        $totalMonth = 0;
        $totalTarget = 0;
        $todayTransactions = 0;
        $totalSuccess = 0;
        $totalFailed = 0;
        
        foreach ($stats as $stat) {
            $totalToday += $stat['today_volume'];
            $totalMonth += $stat['monthly_volume'];
            $totalTarget += $stat['monthly_target'];
            $todayTransactions += $stat['today_count'];
            $totalSuccess += $stat['success_count'] ?? 0;
            $totalFailed += $stat['gateway_fail_count'] ?? 0;
        }
        
        $overallPercent = $totalTarget > 0 ? round(($totalMonth / $totalTarget) * 100, 1) : 0;
        $overallSuccessRate = ($totalSuccess + $totalFailed) > 0 
            ? round(($totalSuccess / ($totalSuccess + $totalFailed)) * 100, 1) 
            : 100;
        
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f4f7fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <div style="max-width:640px;margin:0 auto;padding:20px;">
        
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#1e293b 0%,#334155 100%);border-radius:16px 16px 0 0;padding:32px;text-align:center;">
            <h1 style="margin:0;color:#fff;font-size:24px;font-weight:600;">Payment Orchestrator</h1>
            <p style="margin:8px 0 0;color:rgba(255,255,255,0.85);font-size:14px;"><?php echo esc_html($date); ?></p>
        </div>
        
        <!-- Summary Cards -->
        <div style="background:#fff;padding:24px;border-bottom:1px solid #e5e7eb;">
            <div style="display:flex;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;min-width:100px;background:#f0fdf4;border-radius:12px;padding:16px;text-align:center;">
                    <div style="font-size:24px;font-weight:700;color:#16a34a;">$<?php echo number_format($totalToday, 0); ?></div>
                    <div style="font-size:12px;color:#166534;margin-top:4px;">Today's Volume</div>
                </div>
                <div style="flex:1;min-width:100px;background:#eff6ff;border-radius:12px;padding:16px;text-align:center;">
                    <div style="font-size:24px;font-weight:700;color:#2563eb;"><?php echo number_format($todayTransactions); ?></div>
                    <div style="font-size:12px;color:#1e40af;margin-top:4px;">Transactions</div>
                </div>
                <div style="flex:1;min-width:100px;background:#f8fafc;border-radius:12px;padding:16px;text-align:center;">
                    <div style="font-size:24px;font-weight:700;color:#1e293b;"><?php echo $overallPercent; ?>%</div>
                    <div style="font-size:12px;color:#1e293b;margin-top:4px;">Monthly Progress</div>
                </div>
                <div style="flex:1;min-width:100px;background:<?php echo $overallSuccessRate >= 95 ? '#f0fdf4' : ($overallSuccessRate >= 90 ? '#fef3c7' : '#fee2e2'); ?>;border-radius:12px;padding:16px;text-align:center;">
                    <div style="font-size:24px;font-weight:700;color:<?php echo $overallSuccessRate >= 95 ? '#16a34a' : ($overallSuccessRate >= 90 ? '#d97706' : '#dc2626'); ?>;"><?php echo $overallSuccessRate; ?>%</div>
                    <div style="font-size:12px;color:<?php echo $overallSuccessRate >= 95 ? '#166534' : ($overallSuccessRate >= 90 ? '#92400e' : '#991b1b'); ?>;margin-top:4px;">Success Rate</div>
                </div>
            </div>
            
            <!-- Overall Success/Fail Summary -->
            <div style="margin-top:16px;padding:12px 16px;background:#f9fafb;border-radius:8px;text-align:center;">
                <span style="color:#16a34a;font-weight:600;"><?php echo number_format($totalSuccess); ?> &#x2713; Successful</span>
                <span style="color:#9ca3af;margin:0 12px;">|</span>
                <span style="color:#dc2626;font-weight:600;"><?php echo number_format($totalFailed); ?> &#x2717; Failed</span>
            </div>
        </div>
        
        <!-- Today's Activity by Gateway -->
        <div style="background:#fff;padding:24px;border-bottom:1px solid #e5e7eb;">
            <h2 style="margin:0 0 16px;font-size:16px;font-weight:600;color:#1f2937;">Today's Activity by Gateway</h2>
            
            <?php
            $todayGatewayStats = $this->volume->getTodayStatsByGateway();
            ?>
            <?php if (empty($todayGatewayStats)): ?>
            <p style="color:#9ca3af;font-size:13px;">No gateway activity today.</p>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="border-bottom:2px solid #e5e7eb;">
                        <th style="text-align:left;padding:10px 8px;color:#6b7280;font-weight:500;">Gateway</th>
                        <th style="text-align:right;padding:10px 8px;color:#6b7280;font-weight:500;">Volume</th>
                        <th style="text-align:center;padding:10px 8px;color:#6b7280;font-weight:500;">Successful</th>
                        <th style="text-align:center;padding:10px 8px;color:#6b7280;font-weight:500;">Failed</th>
                        <th style="text-align:center;padding:10px 8px;color:#6b7280;font-weight:500;">Total</th>
                        <th style="text-align:center;padding:10px 8px;color:#6b7280;font-weight:500;">Success Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $emailTodayTotal = 0;
                    $emailTodaySuccess = 0;
                    $emailTodayFail = 0;
                    foreach ($todayGatewayStats as $tgs): 
                        $tgsTotal = $tgs['success'] + $tgs['fail'];
                        $tgsRate = $tgsTotal > 0 ? round(($tgs['success'] / $tgsTotal) * 100, 1) : 100;
                        $tgsRateColor = $tgsRate >= 95 ? '#16a34a' : ($tgsRate >= 90 ? '#d97706' : '#dc2626');
                        $emailTodayTotal += $tgs['volume'];
                        $emailTodaySuccess += $tgs['success'];
                        $emailTodayFail += $tgs['fail'];
                    ?>
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:10px 8px;font-weight:600;color:#1f2937;"><?php echo esc_html($tgs['gateway_name']); ?></td>
                        <td style="text-align:right;padding:10px 8px;font-weight:600;color:#1f2937;">$<?php echo number_format($tgs['volume'], 0); ?></td>
                        <td style="text-align:center;padding:10px 8px;color:#16a34a;font-weight:600;"><?php echo number_format($tgs['success']); ?> &#x2713;</td>
                        <td style="text-align:center;padding:10px 8px;color:<?php echo $tgs['fail'] > 0 ? '#dc2626' : '#9ca3af'; ?>;font-weight:600;"><?php echo number_format($tgs['fail']); ?> &#x2717;</td>
                        <td style="text-align:center;padding:10px 8px;color:#6b7280;"><?php echo number_format($tgsTotal); ?></td>
                        <td style="text-align:center;padding:10px 8px;">
                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;color:<?php echo $tgsRateColor; ?>;background:<?php echo $tgsRate >= 95 ? '#f0fdf4' : ($tgsRate >= 90 ? '#fef3c7' : '#fee2e2'); ?>;">
                                <?php echo $tgsRate; ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid #e5e7eb;">
                        <td style="padding:10px 8px;font-weight:700;color:#1f2937;">Totals</td>
                        <td style="text-align:right;padding:10px 8px;font-weight:700;color:#1f2937;">$<?php echo number_format($emailTodayTotal, 0); ?></td>
                        <td style="text-align:center;padding:10px 8px;color:#16a34a;font-weight:700;"><?php echo number_format($emailTodaySuccess); ?> &#x2713;</td>
                        <td style="text-align:center;padding:10px 8px;color:<?php echo $emailTodayFail > 0 ? '#dc2626' : '#9ca3af'; ?>;font-weight:700;"><?php echo number_format($emailTodayFail); ?> &#x2717;</td>
                        <td style="text-align:center;padding:10px 8px;font-weight:700;color:#6b7280;"><?php echo number_format($emailTodaySuccess + $emailTodayFail); ?></td>
                        <td style="text-align:center;padding:10px 8px;">
                            <?php 
                            $emailOverallRate = ($emailTodaySuccess + $emailTodayFail) > 0 
                                ? round(($emailTodaySuccess / ($emailTodaySuccess + $emailTodayFail)) * 100, 1) : 100;
                            $emailOverallRateColor = $emailOverallRate >= 95 ? '#16a34a' : ($emailOverallRate >= 90 ? '#d97706' : '#dc2626');
                            ?>
                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;color:<?php echo $emailOverallRateColor; ?>;background:<?php echo $emailOverallRate >= 95 ? '#f0fdf4' : ($emailOverallRate >= 90 ? '#fef3c7' : '#fee2e2'); ?>;">
                                <?php echo $emailOverallRate; ?>%
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
        
        <!-- Gateway Table -->
        <div style="background:#fff;padding:24px;">
            <h2 style="margin:0 0 16px;font-size:16px;font-weight:600;color:#1f2937;">Gateway Performance</h2>
            
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="border-bottom:2px solid #e5e7eb;">
                        <th style="text-align:left;padding:12px 8px;color:#6b7280;font-weight:500;">Gateway</th>
                        <th style="text-align:right;padding:12px 8px;color:#6b7280;font-weight:500;">Today</th>
                        <th style="text-align:center;padding:12px 8px;color:#6b7280;font-weight:500;">Success/Fail</th>
                        <th style="text-align:right;padding:12px 8px;color:#6b7280;font-weight:500;">Month</th>
                        <th style="text-align:center;padding:12px 8px;color:#6b7280;font-weight:500;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $stat): 
                        $successCount = $stat['success_count'] ?? 0;
                        $failCount = $stat['gateway_fail_count'] ?? 0;
                        $successRate = ($successCount + $failCount) > 0 
                            ? round(($successCount / ($successCount + $failCount)) * 100, 1) 
                            : 100;
                        $rateColor = $successRate >= 95 ? '#16a34a' : ($successRate >= 90 ? '#d97706' : '#dc2626');
                        
                        $statusColor = match($stat['pace_status']) {
                            'behind' => '#dc2626',
                            'ahead' => '#d97706',
                            'complete' => '#2563eb',
                            default => '#16a34a',
                        };
                        $statusBg = match($stat['pace_status']) {
                            'behind' => '#fee2e2',
                            'ahead' => '#fef3c7',
                            'complete' => '#dbeafe',
                            default => '#dcfce7',
                        };
                        $statusLabel = match($stat['pace_status']) {
                            'behind' => '' . "\u{26A0}" . '' . "\u{FE0F}" . ' Behind',
                            'ahead' => '' . "\u{1F680}" . ' Ahead',
                            'complete' => '' . "\u{2705}" . ' Complete',
                            default => '' . "\u{2713}" . ' On Track',
                        };
                    ?>
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:14px 8px;">
                            <div style="font-weight:600;color:#1f2937;"><?php echo esc_html($stat['gateway']->displayName); ?></div>
                            <div style="font-size:11px;color:#9ca3af;margin-top:2px;"><?php echo esc_html($stat['gateway']->id); ?></div>
                        </td>
                        <td style="text-align:right;padding:14px 8px;">
                            <div style="font-weight:600;color:#1f2937;">$<?php echo number_format($stat['today_volume'], 0); ?></div>
                            <div style="font-size:11px;color:#9ca3af;"><?php echo $stat['today_count']; ?> txns</div>
                        </td>
                        <td style="text-align:center;padding:14px 8px;">
                            <div style="font-size:12px;">
                                <span style="color:#16a34a;font-weight:600;"><?php echo number_format($successCount); ?>&#x2713;</span>
                                <span style="color:#9ca3af;margin:0 4px;">/</span>
                                <span style="color:#dc2626;font-weight:600;"><?php echo number_format($failCount); ?>&#x2717;</span>
                            </div>
                            <div style="font-size:11px;color:<?php echo $rateColor; ?>;margin-top:2px;"><?php echo $successRate; ?>% success</div>
                        </td>
                        <td style="text-align:right;padding:14px 8px;">
                            <div style="font-weight:600;color:#1f2937;">$<?php echo number_format($stat['monthly_volume'], 0); ?></div>
                            <div style="font-size:11px;color:#9ca3af;"><?php echo $stat['monthly_percent']; ?>% of target</div>
                        </td>
                        <td style="text-align:center;padding:14px 8px;">
                            <span style="display:inline-block;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;color:<?php echo $statusColor; ?>;background:<?php echo $statusBg; ?>;">
                                <?php echo $statusLabel; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Alerts Section -->
        <?php 
        $alerts = [];
        foreach ($stats as $stat) {
            // Check for high failure rate (gateway-attributable failures only)
            $successCount = $stat['success_count'] ?? 0;
            $failCount = $stat['gateway_fail_count'] ?? 0;
            if (($successCount + $failCount) > 10) {
                $successRate = round(($successCount / ($successCount + $failCount)) * 100, 1);
                if ($successRate < 90) {
                    $alerts[] = [
                        'type' => 'danger',
                        'message' => $stat['gateway']->displayName . ' has a low success rate of ' . $successRate . '% (' . $failCount . ' failures)',
                    ];
                }
            }
            
            if ($stat['pace_status'] === 'behind' && $stat['monthly_target'] > 0) {
                $needed = $stat['monthly_target'] - $stat['monthly_volume'];
                $perDay = $daysRemaining > 0 ? $needed / $daysRemaining : $needed;
                $alerts[] = [
                    'type' => 'warning',
                    'message' => $stat['gateway']->displayName . ' needs $' . number_format($perDay, 0) . '/day to hit target',
                ];
            }
            if ($stat['pace_status'] === 'complete') {
                $alerts[] = [
                    'type' => 'success',
                    'message' => $stat['gateway']->displayName . ' has reached its monthly target',
                ];
            }
        }
        
        if (!empty($alerts)):
        ?>
        <div style="background:#fff;padding:24px;border-top:1px solid #e5e7eb;">
            <h2 style="margin:0 0 16px;font-size:16px;font-weight:600;color:#1f2937;">Alerts</h2>
            <?php foreach ($alerts as $alert): 
                $bgColor = match($alert['type']) {
                    'danger' => '#fee2e2',
                    'warning' => '#fef3c7',
                    default => '#dcfce7',
                };
                $textColor = match($alert['type']) {
                    'danger' => '#991b1b',
                    'warning' => '#92400e',
                    default => '#166534',
                };
                $icon = match($alert['type']) {
                    'danger' => '' . "\u{1F6A8}" . '',
                    'warning' => '' . "\u{26A0}" . '' . "\u{FE0F}" . '',
                    default => '' . "\u{2705}" . '',
                };
            ?>
            <div style="padding:12px 16px;background:<?php echo $bgColor; ?>;border-radius:8px;margin-bottom:8px;color:<?php echo $textColor; ?>;font-size:13px;">
                <?php echo $icon; ?> <?php echo esc_html($alert['message']); ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Recovery Section -->
        <?php
        try {
            $recoveryStats = FailureHandler::getRecoveryStats(current_time('Y-m'));
            $hasRecoveryData = ($recoveryStats['total_failures'] ?? 0) > 0;
        } catch (\Throwable $e) {
            $hasRecoveryData = false;
        }
        
        if ($hasRecoveryData):
            $totalFailures = $recoveryStats['total_failures'];
            $retryableCount = $recoveryStats['total_retryable'];
            $recoveredCount = $recoveryStats['recovered_count'];
            $recoveredRevenue = $recoveryStats['recovered_revenue'];
            $lostRetryable = $recoveryStats['lost_retryable_revenue'] ?? 0;
            $recoveryRate = $recoveryStats['recovery_rate'];
            
            $rateColor = $recoveryRate >= 50 ? '#16a34a' : ($recoveryRate >= 25 ? '#d97706' : '#dc2626');
            $rateBg = $recoveryRate >= 50 ? '#f0fdf4' : ($recoveryRate >= 25 ? '#fef3c7' : '#fee2e2');
        ?>
        <div style="background:#fff;padding:24px;border-top:1px solid #e5e7eb;">
            <h2 style="margin:0 0 16px;font-size:16px;font-weight:600;color:#1f2937;">&#x1F4B0; Payment Recovery (This Month)</h2>
            
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
                <div style="flex:1;min-width:90px;background:#fee2e2;border-radius:10px;padding:14px;text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:#dc2626;"><?php echo number_format($totalFailures); ?></div>
                    <div style="font-size:11px;color:#991b1b;margin-top:2px;">Total Failures</div>
                </div>
                <div style="flex:1;min-width:90px;background:#dbeafe;border-radius:10px;padding:14px;text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:#2563eb;"><?php echo number_format($retryableCount); ?></div>
                    <div style="font-size:11px;color:#1e40af;margin-top:2px;">Retryable</div>
                </div>
                <div style="flex:1;min-width:90px;background:#f0fdf4;border-radius:10px;padding:14px;text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:#16a34a;"><?php echo number_format($recoveredCount); ?></div>
                    <div style="font-size:11px;color:#166534;margin-top:2px;">Recovered</div>
                </div>
                <div style="flex:1;min-width:90px;background:#f0fdf4;border-radius:10px;padding:14px;text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:#16a34a;">$<?php echo number_format($recoveredRevenue, 0); ?></div>
                    <div style="font-size:11px;color:#166534;margin-top:2px;">Revenue Saved</div>
                </div>
                <div style="flex:1;min-width:90px;background:<?php echo $rateBg; ?>;border-radius:10px;padding:14px;text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:<?php echo $rateColor; ?>;"><?php echo $recoveryRate; ?>%</div>
                    <div style="font-size:11px;color:<?php echo $rateColor; ?>;margin-top:2px;">Recovery Rate</div>
                </div>
            </div>
            
            <?php if ($lostRetryable > 0): ?>
            <div style="padding:10px 14px;background:#fef3c7;border-radius:8px;color:#92400e;font-size:13px;">
                &#x26A0;&#xFE0F; $<?php echo number_format($lostRetryable, 0); ?> in retryable failures not yet recovered this month
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Footer -->
        <div style="padding:24px;text-align:center;">
            <p style="margin:0;color:#9ca3af;font-size:12px;">
                Payment Orchestrator Pro v<?php echo PO_VERSION; ?><br>
                <?php echo $daysRemaining; ?> days remaining in <?php echo current_time('F'); ?>
            </p>
        </div>
        
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Send test report
     */
    public function sendTest(string $email): bool {
        if (!is_email($email)) {
            return false;
        }
        
        $originalEmail = get_option('po_daily_report_email');
        update_option('po_daily_report_email', $email);
        
        $result = $this->send();
        
        update_option('po_daily_report_email', $originalEmail);
        
        return $result;
    }
}
