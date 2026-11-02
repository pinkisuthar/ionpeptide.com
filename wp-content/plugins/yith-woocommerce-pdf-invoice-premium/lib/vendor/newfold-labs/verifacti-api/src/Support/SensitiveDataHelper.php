<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Support;

/**
 * Utilities for reducing sensitive data exposure in logs and exception messages.
 */
final class SensitiveDataHelper
{
    private const DEFAULT_MAX_LENGTH = 512;

    /**
     * Truncate a string to a maximum length, appending an ellipsis when truncated.
     *
     * @param string $value    The value to truncate.
     * @param int    $maxLength Maximum number of characters to retain.
     *
     * @return string
     */
    public static function truncate(string $value, int $maxLength = self::DEFAULT_MAX_LENGTH): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (strlen($value) <= $maxLength) {
            return $value;
        }

        if ($maxLength <= 3) {
            return substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength - 3) . '...';
    }
}
