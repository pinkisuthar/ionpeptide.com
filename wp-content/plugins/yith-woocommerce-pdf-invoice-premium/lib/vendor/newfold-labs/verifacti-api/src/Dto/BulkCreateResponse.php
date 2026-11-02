<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\ResponseAccessor;

/**
 * Response wrapper for bulk invoice create operations.
 */
final class BulkCreateResponse extends ApiResponse
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
     * Return the bulk operation items.
     *
     * @return array<int, mixed>
     */
    public function getItems(): array
    {
        $value = ResponseAccessor::first($this->getData(), ['items', 'facturas', 'data', 'data.items'], []);

        return is_array($value) ? array_values($value) : [];
    }
}
