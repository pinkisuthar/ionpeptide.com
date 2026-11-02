<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Serializer;

use Bluehost\VerifactiApi\Exception\SerializationException;
use JsonException;

/**
 * JSON encoder and decoder for Verifacti API payloads.
 */
final class JsonSerializer
{
    /**
     * Encode a payload as JSON.
     *
     * @param array<string, mixed> $payload Request payload.
     *
     * @return string
     *
     * @throws SerializationException When encoding fails.
     */
    public function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SerializationException('Unable to encode the request payload as JSON.', '', 0, $exception);
        }
    }

    /**
     * Decode a JSON payload into an associative array.
     *
     * @param string $payload JSON string.
     *
     * @return array<string, mixed>
     *
     * @throws SerializationException When decoding fails or the payload is not an array.
     */
    public function decode(string $payload): array
    {
        if (trim($payload) === '') {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SerializationException('Unable to decode the response JSON payload.', $payload, 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new SerializationException('Expected a JSON object or array in the response.', $payload);
        }

        return $decoded;
    }
}
