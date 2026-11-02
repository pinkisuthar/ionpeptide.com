<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Exception;

/**
 * Thrown when a request payload fails pre-flight validation.
 */
class ValidationException extends VerifactiException
{
    /**
     * @var array<int, string>
     */
    private array $errors;

    /**
     * @param string             $message  Human-readable error message.
     * @param array<int, string> $errors   Validation error details.
     * @param int                $code     Exception code.
     * @param \Throwable|null    $previous Previous exception.
     */
    public function __construct(
        string $message = 'The request payload is invalid.',
        array $errors = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct(self::buildMessage($message, $errors), $code, $previous);
        $this->errors = $errors;
    }

    /**
     * Build a composite validation error message.
     *
     * @param string             $message Base message.
     * @param array<int, string> $errors  Validation error details.
     *
     * @return string
     */
    public static function buildMessage(string $message, array $errors): string
    {
        if ($errors === []) {
            return $message;
        }

        return $message . ': ' . implode('; ', $errors);
    }

    /**
     * Return the list of validation errors.
     *
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
