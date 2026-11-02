<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Config;

use Bluehost\VerifactiApi\Exception\ConfigurationException;

/**
 * Runtime configuration for the Verifacti API client.
 */
final class VerifactiConfig
{
    public const BASE_URL = 'https://api.verifacti.com';

    private AuthenticationConfig $authentication;
    private int $timeoutSeconds;
    private string $environment;

    /**
     * @param AuthenticationConfig $authentication API authentication settings.
     * @param int                  $timeoutSeconds HTTP request timeout in seconds.
     * @param string               $environment    Environment identifier.
     *
     * @throws ConfigurationException When timeout or environment values are invalid.
     */
    public function __construct(
        AuthenticationConfig $authentication,
        int $timeoutSeconds = 30,
        string $environment = Environment::CUSTOM
    ) {
        if ($timeoutSeconds <= 0) {
            throw new ConfigurationException('The timeout must be greater than zero.');
        }

        if (!Environment::isValid($environment)) {
            throw new ConfigurationException(sprintf('Unsupported environment "%s".', $environment));
        }

        $this->authentication = $authentication;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->environment = $environment;
    }

    /**
     * Return the fixed Verifacti API base URL.
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return self::BASE_URL;
    }

    /**
     * Return the authentication configuration.
     *
     * @return AuthenticationConfig
     */
    public function getAuthentication(): AuthenticationConfig
    {
        return $this->authentication;
    }

    /**
     * Return the HTTP request timeout in seconds.
     *
     * @return int
     */
    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * Return the configured environment identifier.
     *
     * @return string
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Return default HTTP headers applied to every API request.
     *
     * @return array<string, string>
     */
    public function getDefaultHeaders(): array
    {
        return [
            AuthenticationConfig::HEADER_NAME => $this->authentication->formatHeaderValue(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }
}
