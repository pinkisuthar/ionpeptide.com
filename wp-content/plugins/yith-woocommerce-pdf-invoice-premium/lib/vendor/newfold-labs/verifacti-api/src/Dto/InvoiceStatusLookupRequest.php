<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Request payload for looking up invoice status by identity.
 */
final class InvoiceStatusLookupRequest implements Arrayable
{
    /**
     * @param string      $series        Invoice series.
     * @param string      $number        Invoice number.
     * @param string      $issueDate     Issue date in API format.
     * @param string|null $operationDate Optional operation date in API format.
     */
    public function __construct(
        private string $series,
        private string $number,
        private string $issueDate,
        private ?string $operationDate = null
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
     * Return the operation date, if set.
     *
     * @return string|null
     */
    public function getOperationDate(): ?string
    {
        return $this->operationDate;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        $payload = [
            'serie' => $this->series,
            'numero' => $this->number,
            'fecha_expedicion' => $this->issueDate,
        ];

        if ($this->operationDate !== null) {
            $payload['fecha_operacion'] = $this->operationDate;
        }

        return $payload;
    }
}
