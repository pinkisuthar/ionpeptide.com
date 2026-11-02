<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Transport;

/**
 * Represents an outbound HTTP request to the Verifacti API.
 */
final class HttpRequest
{
    /**
     * @param string                $method         HTTP method.
     * @param string                $path           API path relative to the base URL.
     * @param array<string, string> $headers        Request headers.
     * @param array<string, string> $query          Query string parameters.
     * @param string|null           $body           Optional request body.
     * @param int                   $timeoutSeconds Request timeout in seconds.
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $headers = [],
        private array $query = [],
        private ?string $body = null,
        private int $timeoutSeconds = 30
    ) {
        $this->method = strtoupper($this->method);
    }

    /**
     * Return the HTTP method.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Return the API path.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Return the request headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Return the query string parameters.
     *
     * @return array<string, string>
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * Return the request body, if any.
     *
     * @return string|null
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Return the request timeout in seconds.
     *
     * @return int
     */
    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }
}
