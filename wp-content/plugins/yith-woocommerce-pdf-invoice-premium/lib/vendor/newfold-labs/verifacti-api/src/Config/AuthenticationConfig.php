<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Config;

use Bluehost\VerifactiApi\Exception\ConfigurationException;
use Bluehost\VerifactiApi\Support\HttpHeaderHelper;

/**
 * Holds the Verifacti API key and formats the Authorization header value.
 */
final class AuthenticationConfig
{
    public const HEADER_NAME = 'Authorization';
    public const TOKEN_PREFIX = 'Bearer';

    private string $apiKey;

    /**
     * @param string $apiKey Verifacti API key. Must not be empty or contain unsafe characters.
     *
     * @throws ConfigurationException When the API key is empty or contains unsafe characters.
     */
    public function __construct(string $apiKey)
    {
        if (trim($apiKey) === '') {
            throw new ConfigurationException('The API key cannot be empty.');
        }

        $this->apiKey = trim($apiKey);

        HttpHeaderHelper::assertSafeConfigurationValue($this->apiKey, 'api_key');
    }

    /**
     * Return the API key.
     *
     * Warning: do not log, persist, or expose this value in user-facing output.
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Format the API key as a Bearer Authorization header value.
     *
     * @return string
     */
    public function formatHeaderValue(): string
    {
        return self::TOKEN_PREFIX . ' ' . $this->apiKey;
    }
}
