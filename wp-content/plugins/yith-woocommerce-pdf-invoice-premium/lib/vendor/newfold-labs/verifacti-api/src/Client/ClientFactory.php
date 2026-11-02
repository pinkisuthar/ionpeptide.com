<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Client;

use Bluehost\VerifactiApi\Config\ConfigFactory;
use Bluehost\VerifactiApi\Config\VerifactiConfig;
use Bluehost\VerifactiApi\Transport\CurlHttpTransport;
use Bluehost\VerifactiApi\Transport\HttpTransportInterface;

/**
 * Factory for building {@see VerifactiClient} instances.
 */
final class ClientFactory
{
    /**
     * Build a client from an API key and optional configuration.
     *
     * @param string               $apiKey    Verifacti API key.
     * @param array<string, mixed> $options   Additional configuration options.
     * @param HttpTransportInterface|null $transport Optional HTTP transport override.
     *
     * @return VerifactiClient
     */
    public static function fromApiKey(
        string $apiKey,
        array $options = [],
        ?HttpTransportInterface $transport = null
    ): VerifactiClient {
        return self::fromConfig(
            ConfigFactory::fromArray(array_merge(['api_key' => $apiKey], $options)),
            $transport
        );
    }

    /**
     * Build a client from a configuration object.
     *
     * @param VerifactiConfig               $config    Client configuration.
     * @param HttpTransportInterface|null $transport Optional HTTP transport override.
     *
     * @return VerifactiClient
     */
    public static function fromConfig(
        VerifactiConfig $config,
        ?HttpTransportInterface $transport = null
    ): VerifactiClient {
        return new VerifactiClient($config, $transport ?? new CurlHttpTransport());
    }
}
