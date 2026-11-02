<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Config;

/**
 * Factory for building {@see VerifactiConfig} instances from common inputs.
 */
final class ConfigFactory
{
    /**
     * Build a production environment configuration.
     *
     * @param string               $apiKey  Verifacti API key.
     * @param array<string, mixed> $options Additional configuration options.
     *
     * @return VerifactiConfig
     */
    public static function production(string $apiKey, array $options = []): VerifactiConfig
    {
        $options['environment'] = Environment::PRODUCTION;

        return self::fromArray(array_merge(['api_key' => $apiKey], $options));
    }

    /**
     * Build a test environment configuration.
     *
     * @param string               $apiKey  Verifacti API key.
     * @param array<string, mixed> $options Additional configuration options.
     *
     * @return VerifactiConfig
     */
    public static function test(string $apiKey, array $options = []): VerifactiConfig
    {
        $options['environment'] = Environment::TEST;

        return self::fromArray(array_merge(['api_key' => $apiKey], $options));
    }

    /**
     * Build a configuration from an associative options array.
     *
     * Supported keys: `api_key`, `timeout`, `environment`.
     *
     * @param array<string, mixed> $options Configuration options.
     *
     * @return VerifactiConfig
     */
    public static function fromArray(array $options): VerifactiConfig
    {
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 30;
        $environment = isset($options['environment']) ? (string) $options['environment'] : Environment::CUSTOM;
        $apiKey = isset($options['api_key']) ? (string) $options['api_key'] : '';

        return new VerifactiConfig(
            new AuthenticationConfig($apiKey),
            $timeout,
            $environment
        );
    }
}
