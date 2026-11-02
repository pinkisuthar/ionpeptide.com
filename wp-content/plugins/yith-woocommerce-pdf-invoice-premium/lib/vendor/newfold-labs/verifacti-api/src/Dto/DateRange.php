<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Date range filter payload.
 */
final class DateRange implements Arrayable
{
    /**
     * @param array<string, mixed> $fields Date range fields.
     */
    public function __construct(
        private array $fields
    ) {
    }

    /**
     * Return the underlying date range fields.
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
