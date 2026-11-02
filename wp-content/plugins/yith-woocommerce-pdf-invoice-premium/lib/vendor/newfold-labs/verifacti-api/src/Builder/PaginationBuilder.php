<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Builder;

use Bluehost\VerifactiApi\Dto\Pagination;

/**
 * Fluent builder for list pagination payloads.
 */
final class PaginationBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $fields = [];

    /**
     * Add a pagination field.
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
     * Build the pagination DTO.
     *
     * @return Pagination
     */
    public function build(): Pagination
    {
        return new Pagination($this->fields);
    }
}
