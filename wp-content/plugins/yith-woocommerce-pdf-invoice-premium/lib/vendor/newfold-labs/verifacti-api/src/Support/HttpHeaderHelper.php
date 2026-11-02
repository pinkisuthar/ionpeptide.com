<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Support;

use Bluehost\VerifactiApi\Exception\ConfigurationException;
use Bluehost\VerifactiApi\Exception\ValidationException;

/**
 * Helpers for validating and sanitizing HTTP header values.
 */
final class HttpHeaderHelper
{
    /**
     * Determine whether a value contains characters unsafe for HTTP headers.
     *
     * @param string $value Header value candidate.
     *
     * @return bool
     */
    public static function containsUnsafeCharacters(string $value): bool
    {
        return preg_match('/[\r\n\0]/', $value) === 1;
    }

    /**
     * Remove carriage return, newline, and null bytes from a header value.
     *
     * @param string $value Header value.
     *
     * @return string
     */
    public static function sanitize(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * Assert that a header name is safe to use.
     *
     * @param string $name  Header name.
     * @param string $field Human-readable field label for error messages.
     *
     * @throws ConfigurationException When the header name is unsafe.
     */
    public static function assertValidHeaderName(string $name, string $field = 'authentication header name'): void
    {
        if (self::containsUnsafeCharacters($name)) {
            throw new ConfigurationException(
                sprintf('The %s cannot contain carriage return, newline, or null bytes.', $field)
            );
        }

        if (!preg_match('/^[A-Za-z0-9-]+$/', $name)) {
            throw new ConfigurationException(
                sprintf('The %s contains invalid characters.', $field)
            );
        }
    }

    /**
     * Assert that a configuration value is safe to embed in headers.
     *
     * @param string $value Configuration value.
     * @param string $field Human-readable field label for error messages.
     *
     * @throws ConfigurationException When the value is unsafe.
     */
    public static function assertSafeConfigurationValue(string $value, string $field): void
    {
        if (self::containsUnsafeCharacters($value)) {
            throw new ConfigurationException(
                sprintf('"%s" cannot contain carriage return, newline, or null bytes.', $field)
            );
        }
    }

    /**
     * Assert that a request header value is safe to send.
     *
     * @param string $value Request header value.
     * @param string $field Human-readable field label for error messages.
     *
     * @throws ValidationException When the value is unsafe.
     */
    public static function assertSafeRequestHeaderValue(string $value, string $field): void
    {
        if (self::containsUnsafeCharacters($value)) {
            throw new ValidationException(
                sprintf('"%s" cannot contain carriage return, newline, or null bytes.', $field)
            );
        }
    }

    /**
     * Assert that a query parameter key is safe to include in a URL.
     *
     * @param string $key   Query parameter key.
     * @param string $field Human-readable field label for error messages.
     *
     * @throws ValidationException When the key is unsafe.
     */
    public static function assertSafeQueryKey(string $key, string $field): void
    {
        if (trim($key) === '') {
            throw new ValidationException(sprintf('"%s" cannot be empty.', $field));
        }

        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
            throw new ValidationException(
                sprintf('"%s" contains invalid characters.', $field)
            );
        }

        if (self::containsUnsafeCharacters($key)) {
            throw new ValidationException(
                sprintf('"%s" cannot contain carriage return, newline, or null bytes.', $field)
            );
        }
    }

    /**
     * Assert that a query parameter value is safe to include in a URL.
     *
     * @param string $value Query parameter value.
     * @param string $field Human-readable field label for error messages.
     *
     * @throws ValidationException When the value is unsafe.
     */
    public static function assertSafeQueryValue(string $value, string $field): void
    {
        if (self::containsUnsafeCharacters($value)) {
            throw new ValidationException(
                sprintf('"%s" cannot contain carriage return, newline, or null bytes.', $field)
            );
        }
    }
}
