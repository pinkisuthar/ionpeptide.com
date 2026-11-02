<?php
/**
 * RotationManager - Handles intelligent gateway rotation
 *
 * v2.7.18 - Two-Pool Routing Model
 *
 * POOL 1 - Unlimited gateways (monthlyTarget = 0)
 *   Primary processors. Receive the lion's share of all traffic.
 *   Score = 80 + warmth(0-10), adjusted by health multiplier.
 *   Multiple unlimited gateways split proportionally by health score.
 *
 * POOL 2 - Capped gateways (monthlyTarget > 0)
 *   Obligation/relationship gateways. Receive a daily drip sized to
 *   hit their monthly target organically across remaining days.
 *   Score = 20 + dripSignal(-15 to +35) + warmth(0-10).
 *   Max score (65) is always below healthy unlimited base (80).
 *   Once daily drip is consumed, score drops to ~2 (yield to Pool 1).
 *   Monthly target hard cap enforced in selectGateway() - recovery
 *   retries bypass the cap so revenue is never lost.
 *
 * COLD PROTECTION
 *   Edge-case only (threshold = 100 transactions). Unlimited gateways
 *   never go cold under this model. Capped gateways use cold protection
 *   only after their daily drip is consumed, to maintain processor warmth.
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Routing;

use PaymentOrchestrator\Gateway\GatewayRepository;
use PaymentOrchestrator\Gateway\Gateway;
use PaymentOrchestrator\Volume\VolumeTracker;
use PaymentOrchestrator\Health\HealthMonitor;
use PaymentOrchestrator\Core\Logger;

final class RotationManager {
    private GatewayRepository $gateways;
    private VolumeTracker $volume;
    private HealthMonitor $health;
    
    // Store last decision for preview/debugging
    private static ?array $lastDecision = null;
    
    public function __construct(
        GatewayRepository $gateways, 
        VolumeTracker $volume,
        HealthMonitor $health
    ) {
        $this->gateways = $gateways;
        $this->volume = $volume;
        $this->health = $health;
    }
    
    /**
     * Select the next gateway for checkout
     *
     * @return Gateway|null
     */
    public function selectGateway(): ?Gateway {
        $managed = $this->gateways->getManaged();
        
        if (empty($managed)) {
            return null;
        }
        
        $maxColdTransactions = (int) get_option('po_max_cold_transactions', 100);
        $debugEnabled = get_option('po_rotation_debug', 'no') === 'yes';
        $candidates = [];
        $reasons = [];
        $overMaxGateways = []; // Track gateways skipped for max transaction amount
        
        // Get cart total once for max transaction checks
        $cartTotal = 0.0;
        if (function_exists('WC') && WC()->cart) {
            $cartTotal = (float) WC()->cart->get_total('edit');
        }
        
        foreach ($managed as $gateway) {
            $gid = $gateway->id;
            
            // Check health first
            if (!$this->health->canReceiveTraffic($gid)) {
                $reasons[$gid] = 'Health critical - no traffic';
                if ($debugEnabled) {
                    Logger::info('Gateway health critical, skipping', [
                        'gateway' => $gid,
                        'health_score' => $this->health->getHealthScore($gid),
                    ]);
                }
                continue;
            }
            
            // Skip if at or over monthly target
            if (!$gateway->hasUnlimitedCapacity()) {
                $monthlyVolume = $this->volume->getMonthlyVolume($gid);
                if ($monthlyVolume >= $gateway->monthlyTarget) {
                    $reasons[$gid] = 'At monthly target';
                    if ($debugEnabled) {
                        Logger::debug('Gateway at capacity, skipping', [
                            'gateway' => $gid,
                            'volume' => $monthlyVolume,
                            'target' => $gateway->monthlyTarget,
                        ]);
                    }
                    continue;
                }
            }
            
            // Skip if order exceeds gateway's max transaction amount
            // (saved for fallback - if no gateways pass, use highest max)
            if ($gateway->maxTransactionAmount > 0 && $cartTotal > 0 && $cartTotal > $gateway->maxTransactionAmount) {
                $overMaxGateways[] = $gateway;
                $reasons[$gid] = sprintf('Order $%s exceeds max $%s',
                    number_format($cartTotal, 0), number_format($gateway->maxTransactionAmount, 0));
                if ($debugEnabled) {
                    Logger::debug('Order exceeds gateway max transaction amount, skipping', [
                        'gateway' => $gid,
                        'cart_total' => $cartTotal,
                        'max_transaction' => $gateway->maxTransactionAmount,
                    ]);
                }
                continue;
            }
            
            // Calculate priority score
            $scoreData = $this->calculatePriorityScore($gateway);
            
            // Apply health multiplier
            $healthMultiplier = $this->health->getHealthMultiplier($gid);
            $finalScore = $scoreData['score'] * $healthMultiplier;
            
            // Check cold protection
            $transactionsSince = $this->volume->getTransactionsSinceGateway($gid);
            $isCold = $transactionsSince >= $maxColdTransactions;
            
            $candidates[] = [
                'gateway' => $gateway,
                'base_score' => $scoreData['score'],
                'health_multiplier' => $healthMultiplier,
                'final_score' => $finalScore,
                'score_breakdown' => $scoreData['breakdown'],
                'is_cold' => $isCold,
                'transactions_since' => $transactionsSince,
            ];
            
            $reasons[$gid] = $scoreData['reason'];
        }
        
        if (empty($candidates)) {
            // If gateways were only skipped for max transaction amount,
            // fall back to the one with the highest max (closest to handling the order).
            if (!empty($overMaxGateways)) {
                usort($overMaxGateways, fn($a, $b) => $b->maxTransactionAmount <=> $a->maxTransactionAmount);
                $fallback = $overMaxGateways[0];
                
                if ($debugEnabled) {
                    Logger::info('All gateways exceed max transaction ' . "\u{2014}" . ' falling back to highest max', [
                        'gateway' => $fallback->id,
                        'max_transaction' => $fallback->maxTransactionAmount,
                        'cart_total' => $cartTotal,
                    ]);
                }
                
                self::$lastDecision = [
                    'selected' => $fallback->id,
                    'selection_reason' => 'Fallback ' . "\u{2014}" . ' highest max transaction amount',
                    'candidates' => [],
                    'reasons' => $reasons,
                    'timestamp' => current_time('mysql'),
                ];
                
                return $fallback;
            }
            
            Logger::warning('No gateways available for rotation');
            self::$lastDecision = [
                'selected' => null,
                'candidates' => [],
                'reasons' => $reasons,
                'timestamp' => current_time('mysql'),
            ];
            return null;
        }
        
        // First, check if any gateway is cold (hasn't been used in X transactions)
        $coldGateways = array_filter($candidates, fn($c) => $c['is_cold']);
        
        if (!empty($coldGateways)) {
            // Pick the coldest gateway (most transactions since last use)
            usort($coldGateways, fn($a, $b) => $b['transactions_since'] <=> $a['transactions_since']);
            $selected = $coldGateways[0]['gateway'];
            
            if ($debugEnabled) {
                Logger::info('Selected cold gateway', [
                    'gateway' => $selected->id,
                    'transactions_since' => $coldGateways[0]['transactions_since'],
                ]);
            }
            
            self::$lastDecision = [
                'selected' => $selected->id,
                'selection_reason' => 'Cold protection - gateway needed traffic',
                'candidates' => $candidates,
                'reasons' => $reasons,
                'timestamp' => current_time('mysql'),
            ];
            
            return $selected;
        }
        
        // Sort by final score (higher = needs more volume)
        usort($candidates, fn($a, $b) => $b['final_score'] <=> $a['final_score']);
        
        // In test mode, always pick highest score
        if (get_option('po_test_mode', 'no') === 'yes') {
            $selected = $candidates[0]['gateway'];
            
            self::$lastDecision = [
                'selected' => $selected->id,
                'selection_reason' => 'Test mode - highest score',
                'candidates' => $candidates,
                'reasons' => $reasons,
                'timestamp' => current_time('mysql'),
            ];
            
            if ($debugEnabled) {
                Logger::debug('Test mode: selected highest score', ['gateway' => $selected->id]);
            }
            
            return $selected;
        }
        
        // Normal mode: weighted selection based on final scores
        $selected = $this->weightedSelect($candidates);
        
        self::$lastDecision = [
            'selected' => $selected->id,
            'selection_reason' => 'Weighted random selection',
            'candidates' => $candidates,
            'reasons' => $reasons,
            'timestamp' => current_time('mysql'),
        ];
        
        if ($debugEnabled) {
            Logger::info('Gateway selected', [
                'gateway' => $selected->id,
                'final_score' => $candidates[0]['final_score'],
                'all_scores' => array_map(fn($c) => [
                    'id' => $c['gateway']->id,
                    'score' => $c['final_score'],
                ], $candidates),
            ]);
        }
        
        return $selected;
    }
    
    /**
     * Calculate priority score for a gateway with detailed breakdown.
     *
     * v2.7.18 - Two-pool routing model
     * -----------------------------------------------------------------
     * POOL 1 - Unlimited gateways (monthlyTarget = 0)
     *   These are primary processors and should receive the lion's share
     *   of all traffic. Score is health-weighted around a high base.
     *
     *   score = BASE_UNLIMITED(80) + warmth(0-10)
     *   final = score x healthMultiplier
     *
     *   Two unlimited gateways at equal health split ~50/50.
     *   One degraded (0.5x) falls to ~40, the healthy one stays ~80 -
     *   the healthy gateway absorbs the degraded gateway's lost share.
     *
     * POOL 2 - Capped gateways (monthlyTarget > 0)
     *   These are obligation/relationship gateways. POP drips exactly
     *   their monthly target to them organically across the remaining
     *   days of the month, then they step aside. They should NEVER
     *   threaten Pool 1 dominance.
     *
     *   Drip math (all live - recalculates every transaction call):
     *     remaining   = max(0, target - monthlyVolume)            (monthly cap)
     *     dailyDrip   = max(0, target - (monthlyVolume - todayVolume))
     *                   / max(1, daysRemaining)                     (2.7.26: start-of-day balance)
     *     hourlyDrip  = dailyDrip / 24 hours (24/7 eCommerce store)
     *     expectedNow = hourlyDrip x hoursElapsedToday
     *
     *   Intraday signal (keeps distribution organic):
     *     intradayRatio = todayVolume / expectedNow
     *     ratio=0  -> dripSignal = +MAX_DRIP_BOOST(35)  (needs transactions)
     *     ratio=1  -> dripSignal = 0                     (on pace)
     *     ratio>1  -> dripSignal = -15 max               (suppress gently)
     *
     *   score = BASE_CAPPED(20) + dripSignal + warmth(0-10)
     *   final = score x healthMultiplier
     *
     *   Max capped score = 20+35+10 = 65 (healthy, cold, behind drip)
     *   Min unlimited score = 80x1.0 = 80 (healthy, no warmth)
     *   -> A healthy unlimited gateway always outscores a healthy capped one.
     *   -> Only when unlimited is degraded (0.5x, score~40) does a behind-drip
     *     capped gateway (score~55) temporarily win - this is correct: if your
     *     primary processor is sick, the backup absorbs traffic.
     *
     *   When daily drip is consumed: score drops to DAILY_CONSUMED(2) + tiny
     *   warmth, ensuring the gateway yields to unlimited for the rest of the day.
     *
     * COLD PROTECTION (edge case only, threshold raised to 100)
     *   Unlimited gateways should never go cold under this model.
     *   Capped gateways may go cold after consuming their daily drip -
     *   cold protection ensures the processor relationship stays warm with
     *   an occasional transaction rather than no activity for hours.
     *
     * RECOVERY (separate path - no monthly cap check)
     *   findRetryGateway() in FailureHandler has no cap check. Capped
     *   gateways that have hit their monthly target can still receive
     *   recovery retries - revenue is never lost due to routing caps.
     *
     * @param  Gateway $gateway  The gateway to score.
     * @return array{score: float, breakdown: array, reason: string}
     */
    private function calculatePriorityScore(Gateway $gateway): array {
        // -- Scoring constants -----------------------------------------
        // BASE_UNLIMITED: high base ensures unlimited gateways dominate
        // BASE_CAPPED: low base keeps capped gateways in drip-only role
        // MAX_DRIP_BOOST: max bonus when capped gateway needs drip
        //   20 + 35 + 10(warmth) = 65 < 80 (min unlimited) - safe ceiling
        // DAILY_CONSUMED_SCORE: near-zero score once daily drip is done
        // ACTIVE_HOURS_START/END: full 24-hour window - 24/7 eCommerce store
        // ACTIVE_HOURS: 24
        $scoreBaseUnlimited   = 80.0;
        $scoreBaseCapped      = 20.0;
        $scoreMaxDripBoost    = 35.0;
        $scoreDailyConsumed   = 2.0;
        // ACTIVE_HOURS: full 24-hour window - 24/7 eCommerce store
        $scoreActiveHoursStart = 0;   // midnight - store is always open
        $scoreActiveHours      = 24;  // full day

        $breakdown = [];
        $reasons   = [];

        // -- POOL 1: Unlimited gateway ---------------------------------
        if ($gateway->hasUnlimitedCapacity()) {
            $lastTxn   = $this->volume->getLastTransactionTime($gateway->id);
            $warmth    = 0.0;
            if ($lastTxn) {
                $hoursSince = (time() - strtotime($lastTxn)) / 3600.0;
                $warmth     = min(10.0, $hoursSince * 0.5);
            }
            // No recent transaction data at all -> small warmth (gateway is warm by nature)
            $score = $scoreBaseUnlimited + $warmth;

            $breakdown['pool']        = 'unlimited';
            $breakdown['base']        = $scoreBaseUnlimited;
            $breakdown['warmth']      = round($warmth, 1);
            $reasons[]                = 'Primary unlimited gateway';

            if (get_option('po_rotation_debug', 'no') === 'yes') {
                Logger::debug('Priority score calculated', [
                    'gateway'   => $gateway->id,
                    'pool'      => 'unlimited',
                    'score'     => round($score, 1),
                    'breakdown' => $breakdown,
                ]);
            }

            return [
                'score'     => round($score, 2),
                'breakdown' => $breakdown,
                'reason'    => implode(', ', $reasons),
            ];
        }

        // -- POOL 2: Capped gateway ------------------------------------
        $breakdown['pool'] = 'capped';
        $breakdown['base'] = $scoreBaseCapped;

        $monthlyVolume  = $this->volume->getMonthlyVolume($gateway->id);
        $target         = $gateway->monthlyTarget;

        // remaining is always live - picks up backfill, mid-month changes,
        // and new-month resets automatically.
        $remaining = max(0.0, $target - $monthlyVolume);
        $breakdown['monthly_target']  = round($target, 2);
        $breakdown['monthly_volume']  = round($monthlyVolume, 2);
        $breakdown['remaining']       = round($remaining, 2);

        if ($remaining <= 0.0) {
            // Should not be reached (selectGateway hard-caps at target),
            // but score defensively in case of floating-point edge.
            $breakdown['reason'] = 'monthly_target_met';
            return [
                'score'     => 1.0,
                'breakdown' => $breakdown,
                'reason'    => 'Monthly target met',
            ];
        }

        // Today's volume - live from po_daily_stats
        $todayVolume = $this->volume->getTodayVolume($gateway->id);
        
        // Daily drip - how much of the remaining target should be processed today.
        // 2.7.26: based on the balance at the START of today. Using the live
        // balance (already reduced by today's sales) made today's drip shrink as
        // sales came in, so the gateway reached "drip consumed" early and was
        // throttled for the rest of the day (~5% under-delivery by evening).
        // The live $remaining above still governs the monthly cap.
        $remainingAtDayStart = max(0.0, $target - ($monthlyVolume - $todayVolume));
        $daysInMonth    = (int) current_time('t');
        $dayOfMonth     = (int) current_time('j');
        $daysRemaining  = max(1, $daysInMonth - $dayOfMonth + 1); // include today
        $dailyDrip      = $remainingAtDayStart / $daysRemaining;
        $breakdown['remaining_day_start'] = round($remainingAtDayStart, 2);
        $breakdown['days_remaining'] = $daysRemaining;
        $breakdown['daily_drip']     = round($dailyDrip, 2);

        // Hourly drip - spreads daily allowance across the active trading window
        $currentHour     = (int) current_time('G'); // 0-23
        $hoursElapsed    = max(0, $currentHour - $scoreActiveHoursStart);
        $hourlyDrip      = $dailyDrip / $scoreActiveHours;
        $expectedByNow   = $hourlyDrip * $hoursElapsed;
        $breakdown['hourly_drip']    = round($hourlyDrip, 2);
        $breakdown['expected_by_now'] = round($expectedByNow, 2);

        $breakdown['today_volume'] = round($todayVolume, 2);

        // Daily fill ratio - has today's drip been consumed?
        $fillRatio = ($dailyDrip > 0.0) ? min(1.0, $todayVolume / $dailyDrip) : 1.0;
        $breakdown['fill_ratio'] = round($fillRatio, 3);

        // -- Daily drip consumed: yield to unlimited gateways ----------
        if ($fillRatio >= 1.0) {
            // Tiny warmth only - keeps a heartbeat for cold protection
            // without disrupting Pool 1 dominance.
            $lastTxn = $this->volume->getLastTransactionTime($gateway->id);
            $warmth  = 0.0;
            if ($lastTxn) {
                $hoursSince = (time() - strtotime($lastTxn)) / 3600.0;
                $warmth     = min(5.0, $hoursSince * 0.3);
            }
            $score = max(1.0, $scoreDailyConsumed + $warmth);

            $breakdown['drip_signal'] = 0.0;
            $breakdown['warmth']      = round($warmth, 1);
            $breakdown['reason']      = 'daily_drip_consumed';
            $reasons[]                = sprintf(
                'Daily drip consumed ($%s / $%s)',
                number_format($todayVolume, 0),
                number_format($dailyDrip, 0)
            );

            if (get_option('po_rotation_debug', 'no') === 'yes') {
                Logger::debug('Priority score calculated', [
                    'gateway'   => $gateway->id,
                    'pool'      => 'capped',
                    'score'     => round($score, 1),
                    'breakdown' => $breakdown,
                    'reasons'   => $reasons,
                ]);
            }

            return [
                'score'     => round($score, 2),
                'breakdown' => $breakdown,
                'reason'    => implode(', ', $reasons),
            ];
        }

        // -- Intraday position signal -----------------------------------
        // Compares today's actual volume to what it should be by this hour.
        // Produces a gentle, organic distribution across the trading day.
        //   intradayRatio = 0   -> +MAX_DRIP_BOOST (+35): needs transactions now
        //   intradayRatio = 1   -> 0: exactly on pace, no adjustment
        //   intradayRatio > 1   -> up to -15: slightly ahead, back off gently
        if ($expectedByNow > 0.0) {
            $intradayRatio = $todayVolume / $expectedByNow;
        } else {
            // expectedByNow = 0 at midnight (start of day) - boost so first
            // transactions of the day are picked up naturally.
            $intradayRatio = 0.0;
        }
        $breakdown['intraday_ratio'] = round($intradayRatio, 3);

        if ($intradayRatio <= 1.0) {
            $dripSignal = $scoreMaxDripBoost * (1.0 - $intradayRatio);
        } else {
            // Ahead of hourly pace - suppress proportionally, max -15
            // (small enough that capped gateway doesn't go dark mid-day)
            $dripSignal = max(-15.0, -15.0 * min(1.0, $intradayRatio - 1.0));
        }
        $dripSignal = round($dripSignal, 1);
        $breakdown['drip_signal'] = $dripSignal;

        // -- Warmth ----------------------------------------------------
        // Modest warmth bonus - prevents complete cold-out between hourly
        // drip windows without allowing the gateway to compete with Pool 1.
        $lastTxn = $this->volume->getLastTransactionTime($gateway->id);
        $warmth  = 0.0;
        if ($lastTxn) {
            $hoursSince = (time() - strtotime($lastTxn)) / 3600.0;
            $warmth     = min(10.0, $hoursSince * 0.5);
        } else {
            $warmth = 10.0; // Never used today - needs its first transaction
        }
        $breakdown['warmth'] = round($warmth, 1);

        // -- Final score -----------------------------------------------
        $score = max(1.0, $scoreBaseCapped + $dripSignal + $warmth);

        // Human-readable reason
        if ($intradayRatio < 0.5) {
            $reasons[] = sprintf('Behind hourly drip (%.0f%% of expected)', $intradayRatio * 100);
        } elseif ($intradayRatio <= 1.1) {
            $reasons[] = sprintf('On drip pace ($%s/$%s today)',
                number_format($todayVolume, 0), number_format($expectedByNow, 0));
        } else {
            $reasons[] = sprintf('Ahead of hourly drip (%.0f%%)', $intradayRatio * 100);
        }
        $breakdown['reason'] = 'normal';

        if (get_option('po_rotation_debug', 'no') === 'yes') {
            Logger::debug('Priority score calculated', [
                'gateway'   => $gateway->id,
                'pool'      => 'capped',
                'score'     => round($score, 1),
                'breakdown' => $breakdown,
                'reasons'   => $reasons,
            ]);
        }

        return [
            'score'     => round($score, 2),
            'breakdown' => $breakdown,
            'reason'    => implode(', ', $reasons),
        ];
    }
    
    /**
     * Weighted random selection based on scores
     */
    private function weightedSelect(array $candidates): Gateway {
        $totalScore = array_sum(array_column($candidates, 'final_score'));
        
        if ($totalScore <= 0) {
            return $candidates[0]['gateway'];
        }
        
        $random = mt_rand(1, (int) ($totalScore * 100)) / 100;
        $cumulative = 0;
        
        foreach ($candidates as $candidate) {
            $cumulative += $candidate['final_score'];
            if ($random <= $cumulative) {
                return $candidate['gateway'];
            }
        }
        
        return $candidates[0]['gateway'];
    }
    
    /**
     * Simulate rotation distribution for N transactions
     * Note: Uses weights only, skips cold protection to show true distribution
     */
    public function simulateDistribution(int $count = 100): array {
        $distribution = [];
        $managed = $this->gateways->getManaged();
        
        Logger::debug('Simulation started', [
            'count' => $count,
            'managed_gateways' => count($managed),
        ]);
        
        foreach ($managed as $gateway) {
            $distribution[$gateway->id] = [
                'gateway' => $gateway,
                'count' => 0,
                'percentage' => 0,
            ];
        }
        
        // Get current weights
        $weights = $this->getCurrentWeights();
        
        Logger::debug('Current weights for simulation', [
            'weights' => array_map(fn($w) => [
                'id' => $w['gateway']->id,
                'weight' => $w['weight'],
                'status' => $w['status'],
                'reason' => $w['reason'] ?? null,
            ], $weights),
        ]);
        
        // Filter to active gateways only
        $activeWeights = array_filter($weights, fn($w) => $w['status'] === 'active' && $w['weight'] > 0);
        
        Logger::debug('Active weights after filter', [
            'active_count' => count($activeWeights),
            'active_ids' => array_keys($activeWeights),
        ]);
        
        if (empty($activeWeights)) {
            Logger::warning('Simulation failed - no active gateways');
            return $distribution;
        }
        
        // Calculate total weight
        $totalWeight = array_sum(array_column($activeWeights, 'weight'));
        
        Logger::debug('Total weight for simulation', ['total' => $totalWeight]);
        
        if ($totalWeight <= 0) {
            Logger::warning('Simulation failed - total weight is zero');
            return $distribution;
        }
        
        // Run simulation using weighted random selection
        for ($i = 0; $i < $count; $i++) {
            $random = mt_rand(1, (int) ($totalWeight * 100)) / 100;
            $cumulative = 0;
            
            foreach ($activeWeights as $gid => $data) {
                $cumulative += $data['weight'];
                if ($random <= $cumulative) {
                    if (isset($distribution[$gid])) {
                        $distribution[$gid]['count']++;
                    }
                    break;
                }
            }
        }
        
        // Calculate percentages
        foreach ($distribution as $id => &$data) {
            $data['percentage'] = $count > 0 ? round(($data['count'] / $count) * 100, 1) : 0;
        }
        
        Logger::debug('Simulation complete', [
            'results' => array_map(fn($d) => [
                'id' => $d['gateway']->id,
                'count' => $d['count'],
                'percentage' => $d['percentage'],
            ], $distribution),
        ]);
        
        return $distribution;
    }
    
    /**
     * Get current weights without making a selection
     */
    public function getCurrentWeights(): array {
        $managed = $this->gateways->getManaged();
        $weights = [];
        
        foreach ($managed as $gateway) {
            $gid = $gateway->id;
            
            // Check health
            $healthMultiplier = $this->health->getHealthMultiplier($gid);
            if ($healthMultiplier === 0.0) {
                $weights[$gid] = [
                    'gateway' => $gateway,
                    'weight' => 0,
                    'status' => 'blocked',
                    'reason' => 'Health critical',
                ];
                continue;
            }
            
            // Check capacity
            if (!$gateway->hasUnlimitedCapacity()) {
                $monthlyVolume = $this->volume->getMonthlyVolume($gid);
                if ($monthlyVolume >= $gateway->monthlyTarget) {
                    $weights[$gid] = [
                        'gateway' => $gateway,
                        'weight' => 0,
                        'status' => 'blocked',
                        'reason' => 'At monthly target',
                    ];
                    continue;
                }
            }
            
            $scoreData = $this->calculatePriorityScore($gateway);
            $finalWeight = $scoreData['score'] * $healthMultiplier;
            
            $weights[$gid] = [
                'gateway' => $gateway,
                'weight' => round($finalWeight, 1),
                'base_score' => round($scoreData['score'], 1),
                'health_multiplier' => $healthMultiplier,
                'status' => 'active',
                'reason' => $scoreData['reason'],
                'breakdown' => $scoreData['breakdown'],
            ];
        }
        
        // Calculate percentages
        $totalWeight = array_sum(array_column($weights, 'weight'));
        foreach ($weights as $id => &$data) {
            $data['percentage'] = $totalWeight > 0 
                ? round(($data['weight'] / $totalWeight) * 100, 1) 
                : 0;
        }
        
        return $weights;
    }
    
    /**
     * Get the last decision made (for debugging/preview)
     */
    public static function getLastDecision(): ?array {
        return self::$lastDecision;
    }
    
    /**
     * Get the health monitor instance
     */
    public function getHealthMonitor(): HealthMonitor {
        return $this->health;
    }
}
