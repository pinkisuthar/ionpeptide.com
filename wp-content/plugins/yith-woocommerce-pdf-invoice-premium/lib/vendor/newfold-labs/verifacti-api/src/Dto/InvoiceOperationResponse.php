<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\ResponseAccessor;

/**
 * Response wrapper for invoice create, modify, and cancel operations.
 */
final class InvoiceOperationResponse extends ApiResponse
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
        $value = ResponseAccessor::first($this->getData(), ['uuid', 'data.uuid']);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Return the operation status, if present.
     *
     * @return string|null
     */
    public function getStatus(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['status', 'estado', 'data.status', 'data.estado']);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Return the QR code as a base64 string, if present.
     *
     * @return string|null
     */
    public function getQrCodeBase64(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['qr', 'qr_base64', 'data.qr', 'data.qr_base64']);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Return the QR code URL, if present.
     *
     * @return string|null
     */
    public function getQrCodeUrl(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['qr_url', 'data.qr_url', 'url_qr']);

        return is_scalar($value) ? (string) $value : null;
    }
}
