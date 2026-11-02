<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Reference to another invoice by series, number, and issue date.
 */
final class InvoiceReference implements Arrayable
{
    /**
     * @param string $series    Invoice series.
     * @param string $number    Invoice number.
     * @param string $issueDate Issue date in API format.
     */
    public function __construct(
        private string $series,
        private string $number,
        private string $issueDate
    ) {
    }

    /**
     * Return the invoice series.
     *
     * @return string
     */
    public function getSeries(): string
    {
        return $this->series;
    }

    /**
     * Return the invoice number.
     *
     * @return string
     */
    public function getNumber(): string
    {
        return $this->number;
    }

    /**
     * Return the issue date.
     *
     * @return string
     */
    public function getIssueDate(): string
    {
        return $this->issueDate;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'serie' => $this->series,
            'numero' => $this->number,
            'fecha_expedicion' => $this->issueDate,
        ];
    }
}
