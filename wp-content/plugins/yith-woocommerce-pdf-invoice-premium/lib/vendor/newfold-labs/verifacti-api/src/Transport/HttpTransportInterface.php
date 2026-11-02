<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Transport;

use Bluehost\VerifactiApi\Config\VerifactiConfig;
use Bluehost\VerifactiApi\Exception\ConfigurationException;
use Bluehost\VerifactiApi\Exception\TransportException;

/**
 * Contract for HTTP transport adapters used by the Verifacti API client.
 */
interface HttpTransportInterface
{
    /**
     * Send an HTTP request to the Verifacti API.
     *
     * @param HttpRequest     $request Outbound request.
     * @param VerifactiConfig $config  Client configuration.
     *
     * @return HttpResponse
     *
     * @throws ConfigurationException When the transport cannot be initialized.
     * @throws TransportException     When the request fails before a response is received.
     */
    public function send(HttpRequest $request, VerifactiConfig $config): HttpResponse;
}
