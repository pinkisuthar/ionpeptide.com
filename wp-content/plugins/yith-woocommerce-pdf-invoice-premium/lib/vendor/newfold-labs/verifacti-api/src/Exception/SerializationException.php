<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Exception;

use Bluehost\VerifactiApi\Support\SensitiveDataHelper;

/**
 * Thrown when JSON encoding or decoding fails.
 */
class SerializationException extends VerifactiException
{
    private string $payload;

    /**
     * @param string          $message  Human-readable error message.
     * @param string          $payload  Raw payload that failed serialization.
     * @param int             $code     Exception code.
     * @param \Throwable|null $previous Previous exception.
     */
    public function __construct(
        string $message,
        string $payload = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->payload = $payload;
    }

    /**
     * Return a truncated payload safe for logging.
     *
     * @return string
     */
    public function getPayload(): string
    {
        return SensitiveDataHelper::truncate($this->payload);
    }

    /**
     * Return the full untruncated payload for explicit debugging.
     *
     * @return string
     */
    public function getFullPayload(): string
    {
        return $this->payload;
    }
}
