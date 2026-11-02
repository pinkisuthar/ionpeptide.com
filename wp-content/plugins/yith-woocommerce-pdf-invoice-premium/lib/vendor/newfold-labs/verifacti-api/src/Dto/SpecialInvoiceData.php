<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Special invoice data payload.
 */
final class SpecialInvoiceData implements Arrayable
{
    /**
     * @param array<string, mixed> $fields Special invoice fields.
     */
    public function __construct(
        private array $fields
    ) {
    }

    /**
     * Return the underlying special invoice fields.
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
