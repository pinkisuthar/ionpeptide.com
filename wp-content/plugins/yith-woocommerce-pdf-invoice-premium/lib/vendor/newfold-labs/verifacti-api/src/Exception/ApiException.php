<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Exception;

/**
 * Thrown when the Verifacti API returns a structured JSON error response.
 */
class ApiException extends HttpException
{
    /**
     * @var array<string, mixed>
     */
    private array $errorData;

    /**
     * @param string                $message      Human-readable error message.
     * @param int                   $statusCode   HTTP status code.
     * @param array<string, string> $headers      Response headers.
     * @param string                $responseBody Raw response body.
     * @param array<string, mixed>  $errorData    Decoded JSON error payload.
     * @param int                   $code         Exception code.
     * @param \Throwable|null       $previous     Previous exception.
     */
    public function __construct(
        string $message,
        int $statusCode,
        array $headers = [],
        string $responseBody = '',
        array $errorData = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $headers, $responseBody, $code, $previous);
        $this->errorData = $errorData;
    }

    /**
     * Return the decoded JSON error payload.
     *
     * @return array<string, mixed>
     */
    public function getErrorData(): array
    {
        return $this->errorData;
    }
}
