<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Exception;

use Bluehost\VerifactiApi\Support\SensitiveDataHelper;

/**
 * Thrown when the Verifacti API returns a non-success HTTP response.
 */
class HttpException extends VerifactiException
{
    private int $statusCode;

    /**
     * @var array<string, string>
     */
    private array $headers;

    private string $responseBody;

    /**
     * @param string               $message      Human-readable error message.
     * @param int                  $statusCode   HTTP status code.
     * @param array<string, string> $headers     Response headers.
     * @param string               $responseBody Raw response body.
     * @param int                  $code         Exception code.
     * @param \Throwable|null      $previous     Previous exception.
     */
    public function __construct(
        string $message,
        int $statusCode,
        array $headers = [],
        string $responseBody = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->responseBody = $responseBody;
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
     * Return the HTTP response headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Return a truncated response body safe for logging.
     *
     * @return string
     */
    public function getResponseBody(): string
    {
        return SensitiveDataHelper::truncate($this->responseBody);
    }

    /**
     * Return the full untruncated response body for explicit debugging.
     *
     * @return string
     */
    public function getFullResponseBody(): string
    {
        return $this->responseBody;
    }
}
