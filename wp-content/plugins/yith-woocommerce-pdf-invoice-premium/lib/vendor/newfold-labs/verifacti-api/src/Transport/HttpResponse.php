<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Transport;

/**
 * Represents an HTTP response received from the Verifacti API.
 */
final class HttpResponse
{
    private int $statusCode;

    /**
     * @var array<string, string>
     */
    private array $headers;

    private string $body;

    /**
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * @param int                   $statusCode HTTP status code.
     * @param array<string, string> $headers    Response headers.
     * @param string                $body       Response body.
     * @param array<string, mixed>  $metadata   Transport metadata.
     */
    public function __construct(int $statusCode, array $headers, string $body, array $metadata = [])
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
        $this->metadata = $metadata;
    }

    /**
     * Return the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Return the response headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Return the response body.
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Return transport metadata collected by the HTTP adapter.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Determine whether the response represents a successful HTTP status.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Return the Content-Type header value, if present.
     *
     * @return string
     */
    public function getContentType(): string
    {
        return $this->headers['content-type'] ?? '';
    }
}
