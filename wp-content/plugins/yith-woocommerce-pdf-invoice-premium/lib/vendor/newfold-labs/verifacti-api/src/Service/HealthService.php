<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Service;

use Bluehost\VerifactiApi\Dto\HealthResponse;
use Bluehost\VerifactiApi\Exception\ApiException;
use Bluehost\VerifactiApi\Exception\AuthenticationException;
use Bluehost\VerifactiApi\Exception\HttpException;
use Bluehost\VerifactiApi\Exception\SerializationException;
use Bluehost\VerifactiApi\Exception\TransportException;

/**
 * Service for the Verifacti health check endpoint.
 */
final class HealthService
{
    public function __construct(private ApiExecutor $executor)
    {
    }

    /**
     * Check API health.
     *
     * @return HealthResponse
     *
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function check(): HealthResponse
    {
        return HealthResponse::fromApiResponse($this->executor->get('/verifactu/health'));
    }
}
