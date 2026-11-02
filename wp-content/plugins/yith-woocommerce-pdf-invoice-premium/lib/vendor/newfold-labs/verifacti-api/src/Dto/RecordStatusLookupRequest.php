<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Exception\ValidationException;
use Bluehost\VerifactiApi\Support\Arrayable;
use Bluehost\VerifactiApi\Support\HttpHeaderHelper;

/**
 * Request payload for looking up record status by UUID.
 */
final class RecordStatusLookupRequest implements Arrayable
{
    /**
     * @var array<string, string>
     */
    private array $query;

    /**
     * @param string                $uuid  Record UUID returned by create.
     * @param array<string, string> $query Additional query parameters.
     *
     * @throws ValidationException When query keys or values are unsafe.
     */
    public function __construct(
        private string $uuid,
        array $query = []
    ) {
        $this->query = self::sanitizeQuery($query);
    }

    /**
     * Return the record UUID.
     *
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return array_merge(['uuid' => $this->uuid], $this->query);
    }

    /**
     * Validate and sanitize additional query parameters.
     *
     * @param array<string, string> $query Query parameters.
     *
     * @return array<string, string>
     *
     * @throws ValidationException When a key or value is unsafe.
     */
    private static function sanitizeQuery(array $query): array
    {
        $sanitized = [];

        foreach ($query as $key => $value) {
            HttpHeaderHelper::assertSafeQueryKey((string) $key, 'query key');
            HttpHeaderHelper::assertSafeQueryValue((string) $value, 'query value');
            $sanitized[(string) $key] = (string) $value;
        }

        return $sanitized;
    }
}
