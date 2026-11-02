<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\ResponseAccessor;

/**
 * Response wrapper for the health check endpoint.
 */
final class HealthResponse extends ApiResponse
{
    /**
     * Create a typed response from a generic API response.
     *
     * @param ApiResponse $response Generic API response.
     *
     * @return self
     */
    public static function fromApiResponse(ApiResponse $response): self
    {
        return new self(
            $response->getStatusCode(),
            $response->getData(),
            $response->getRawBody(),
            $response->getHeaders(),
            $response->getMetadata()
        );
    }

    /**
     * Return the reported health status, if present.
     *
     * @return string|null
     */
    public function getHealthStatus(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['status', 'estado', 'api.status']);

        return is_scalar($value) ? (string) $value : null;
    }
}
