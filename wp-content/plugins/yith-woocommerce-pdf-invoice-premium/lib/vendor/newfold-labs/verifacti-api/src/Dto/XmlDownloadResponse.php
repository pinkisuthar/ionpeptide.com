<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\ResponseAccessor;

/**
 * Response wrapper for XML download requests.
 */
final class XmlDownloadResponse extends ApiResponse
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
     * Return downloaded XML file entries.
     *
     * @return array<int, mixed>
     */
    public function getFiles(): array
    {
        $value = ResponseAccessor::first($this->getData(), ['files', 'xmls', 'data.files', 'data.xmls'], []);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Return the request XML payload, if present.
     *
     * @return string|null
     */
    public function getRequestXml(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['request_xml', 'xml_request', 'data.request_xml']);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Return the response XML payload, if present.
     *
     * @return string|null
     */
    public function getResponseXml(): ?string
    {
        $value = ResponseAccessor::first($this->getData(), ['response_xml', 'xml_response', 'data.response_xml']);

        return is_scalar($value) ? (string) $value : null;
    }
}
