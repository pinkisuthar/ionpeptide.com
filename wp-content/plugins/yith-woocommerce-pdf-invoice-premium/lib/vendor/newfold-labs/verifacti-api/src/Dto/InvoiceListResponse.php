<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\ResponseAccessor;

/**
 * Response wrapper for invoice list requests.
 */
final class InvoiceListResponse extends ApiResponse
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
     * Return the listed invoice items.
     *
     * @return array<int, mixed>
     */
    public function getInvoices(): array
    {
        $value = ResponseAccessor::first($this->getData(), ['items', 'facturas', 'data.items', 'data.facturas'], []);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Return the next page token, if present.
     *
     * @return string|null
     */
    public function getNextPageToken(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['token', 'next_token', 'pagination.token']);

        return is_scalar($value) ? (string) $value : null;
    }
}
