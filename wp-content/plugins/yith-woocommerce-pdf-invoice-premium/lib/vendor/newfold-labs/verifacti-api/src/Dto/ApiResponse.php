<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;
use Bluehost\VerifactiApi\Support\ResponseAccessor;

/**
 * Base decoded API response wrapper.
 */
class ApiResponse
{
    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     * @param array<string, mixed>  $metadata
     */
    public function __construct(
        private int $statusCode,
        private array $data,
        private string $rawBody,
        private array $headers = [],
        private array $metadata = []
    ) {
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
     * Return the decoded response data.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Return the raw response body.
     *
     * @return string
     */
    public function getRawBody(): string
    {
        return $this->rawBody;
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
     * Return transport metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Return a nested value from the decoded response data.
     *
     * @param string $path    Dot-separated path.
     * @param mixed  $default Default value when the path is missing.
     *
     * @return mixed
     */
    public function get(string $path, mixed $default = null): mixed
    {
        return ResponseAccessor::get($this->data, $path, $default);
    }
}
