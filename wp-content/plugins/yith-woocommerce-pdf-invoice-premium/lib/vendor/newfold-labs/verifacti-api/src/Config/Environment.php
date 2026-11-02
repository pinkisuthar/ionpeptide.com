<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Config;

/**
 * Supported Verifacti API environment identifiers.
 */
final class Environment
{
    public const TEST = 'test';
    public const PRODUCTION = 'production';
    public const CUSTOM = 'custom';

    /**
     * Determine whether the given environment identifier is supported.
     *
     * @param string $environment Environment identifier.
     *
     * @return bool
     */
    public static function isValid(string $environment): bool
    {
        return in_array($environment, [self::TEST, self::PRODUCTION, self::CUSTOM], true);
    }
}
