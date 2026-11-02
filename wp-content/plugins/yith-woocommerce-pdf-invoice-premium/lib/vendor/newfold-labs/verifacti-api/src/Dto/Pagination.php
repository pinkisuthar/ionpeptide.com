<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Pagination payload for list requests.
 */
final class Pagination implements Arrayable
{
    /**
     * @param array<string, mixed> $fields Pagination fields.
     */
    public function __construct(
        private array $fields
    ) {
    }

    /**
     * Return the underlying pagination fields.
     *
     * @return array<string, mixed>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}
