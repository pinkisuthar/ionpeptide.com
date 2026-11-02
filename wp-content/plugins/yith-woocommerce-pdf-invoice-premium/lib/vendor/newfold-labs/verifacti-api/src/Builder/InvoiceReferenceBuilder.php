<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Builder;

use Bluehost\VerifactiApi\Dto\InvoiceReference;

/**
 * Fluent builder for invoice reference payloads.
 */
final class InvoiceReferenceBuilder
{
    private string $series = '';
    private string $number = '';
    private string $issueDate = '';

    /**
     * Set the referenced invoice series.
     *
     * @param string $series Invoice series.
     *
     * @return self
     */
    public function withSeries(string $series): self
    {
        $this->series = $series;

        return $this;
    }

    /**
     * Set the referenced invoice number.
     *
     * @param string $number Invoice number.
     *
     * @return self
     */
    public function withNumber(string $number): self
    {
        $this->number = $number;

        return $this;
    }

    /**
     * Set the referenced invoice issue date.
     *
     * @param string $issueDate Issue date in API format.
     *
     * @return self
     */
    public function withIssueDate(string $issueDate): self
    {
        $this->issueDate = $issueDate;

        return $this;
    }

    /**
     * Build the invoice reference DTO.
     *
     * @return InvoiceReference
     */
    public function build(): InvoiceReference
    {
        return new InvoiceReference($this->series, $this->number, $this->issueDate);
    }
}
