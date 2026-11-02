<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Support;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Date parsing and validation helpers for Verifacti API payloads.
 */
final class DateHelper
{
    public const API_DATE_FORMAT = 'd-m-Y';

    /**
     * Determine whether a value matches the Verifacti API date format.
     *
     * @param string $value Date string candidate.
     *
     * @return bool
     */
    public static function isValidApiDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat(self::API_DATE_FORMAT, $value);

        return $date instanceof DateTimeImmutable && $date->format(self::API_DATE_FORMAT) === $value;
    }

    /**
     * Determine whether a value represents today's date in API format.
     *
     * @param string                 $value Date string candidate.
     * @param DateTimeInterface|null $clock Optional clock for testing.
     *
     * @return bool
     */
    public static function isToday(string $value, ?DateTimeInterface $clock = null): bool
    {
        if (!self::isValidApiDate($value)) {
            return false;
        }

        $now = $clock ?? new DateTimeImmutable('now');

        return $now->format(self::API_DATE_FORMAT) === $value;
    }
}
