<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\ResponseAccessor;

/**
 * Response wrapper for XML export requests.
 */
final class XmlExportResponse extends ApiResponse
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
     * Return exported XML file entries.
     *
     * @return array<int, mixed>
     */
    public function getFiles(): array
    {
        $value = ResponseAccessor::first($this->getData(), ['files', 'items', 'data.files', 'data.items'], []);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Return the next export token, if present.
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['token', 'next_token', 'data.token']);

        return is_scalar($value) ? (string) $value : null;
    }
}
