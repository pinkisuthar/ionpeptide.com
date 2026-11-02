<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\ResponseAccessor;

/**
 * Response wrapper for record and invoice status lookups.
 */
final class StatusResponse extends ApiResponse
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
     * Return the record UUID, if present.
     *
     * @return string|null
     */
    public function getUuid(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['uuid', 'data.uuid', 'registro.uuid']);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Return the record status, if present.
     *
     * @return string|null
     */
    public function getStatus(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['status', 'estado', 'data.status', 'data.estado']);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Return whether the record status is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        $status = $this->getStatus();

        return $status !== null && strtolower($status) === 'pendiente';
    }
}
