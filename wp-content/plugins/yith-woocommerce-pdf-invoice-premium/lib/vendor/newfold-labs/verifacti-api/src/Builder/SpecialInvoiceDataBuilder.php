<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Builder;

use Bluehost\VerifactiApi\Dto\SpecialInvoiceData;

/**
 * Fluent builder for special invoice data payloads.
 */
final class SpecialInvoiceDataBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $fields = [];

    /**
     * Add a special invoice data field.
     *
     * @param string $key   Field name.
     * @param mixed  $value Field value.
     *
     * @return self
     */
    public function withField(string $key, mixed $value): self
    {
        $this->fields[$key] = $value;

        return $this;
    }

    /**
     * Build the special invoice data DTO.
     *
     * @return SpecialInvoiceData
     */
    public function build(): SpecialInvoiceData
    {
        return new SpecialInvoiceData($this->fields);
    }
}
