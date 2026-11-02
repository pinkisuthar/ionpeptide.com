<?php
/**
 * Site-time helpers for reporting (2.7.25).
 *
 * POP stores timestamps in two clocks: po_transactions, po_daily_stats and
 * po_logs use the site timezone (current_time('mysql')); po_failed_transactions
 * uses the database clock (UTC, CURRENT_TIMESTAMP). Reports must convert a site
 * day/range to the right clock for each table. (Unified storage: planned 2.8.0.)
 *
 * @package PaymentOrchestrator\Core
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Core;

defined('ABSPATH') || exit;

final class SiteTime {

    /**
     * UTC [start, end) for one site-timezone day ('Y-m-d'; default today).
     *
     * @return array{0:string,1:string}
     */
    public static function dayUtcRange(?string $siteDate = null): array {
        $tz    = wp_timezone();
        $date  = $siteDate ?: current_time('Y-m-d');
        $start = new \DateTimeImmutable($date . ' 00:00:00', $tz);
        $end   = $start->modify('+1 day');
        return [self::toUtc($start), self::toUtc($end)];
    }

    /**
     * UTC [start, end] for a site-timezone range given as 'Y-m-d H:i:s' strings.
     *
     * @return array{0:string,1:string}
     */
    public static function rangeToUtc(string $siteStart, string $siteEnd): array {
        $tz = wp_timezone();
        return [
            self::toUtc(new \DateTimeImmutable($siteStart, $tz)),
            self::toUtc(new \DateTimeImmutable($siteEnd, $tz)),
        ];
    }

    /** First day of the month containing $siteDate, in the site timezone. */
    public static function monthStart(string $modify = 'first day of this month'): \DateTimeImmutable {
        return new \DateTimeImmutable($modify . ' 00:00:00', wp_timezone());
    }

    private static function toUtc(\DateTimeImmutable $d): string {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
