<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\ResponseAccessor;

/**
 * Response wrapper for the responsible declaration endpoint.
 */
final class DeclarationResponse extends ApiResponse
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
     * Return the declaration document URL, if present.
     *
     * @return string|null
     */
    public function getDocumentUrl(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['url', 'document_url', 'data.url', 'data.document_url']);

        return is_scalar($value) ? (string) $value : null;
    }
}
