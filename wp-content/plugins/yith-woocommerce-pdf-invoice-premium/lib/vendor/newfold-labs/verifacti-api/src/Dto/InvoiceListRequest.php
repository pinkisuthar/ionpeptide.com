<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Request payload for listing invoices in a fiscal period.
 */
final class InvoiceListRequest implements Arrayable
{
    /**
     * @param string               $fiscalYear     Fiscal year.
     * @param string               $period         Fiscal period.
     * @param string|null          $series         Optional invoice series filter.
     * @param string|null          $number         Optional invoice number filter.
     * @param DateRange|null       $issueDateRange Optional issue date range filter.
     * @param string|null          $issueDate      Optional exact issue date filter.
     * @param Pagination|null      $pagination     Optional pagination payload.
     * @param array<string, mixed> $extraFields    Additional API fields.
     */
    public function __construct(
        private string $fiscalYear,
        private string $period,
        private ?string $series = null,
        private ?string $number = null,
        private ?DateRange $issueDateRange = null,
        private ?string $issueDate = null,
        private ?Pagination $pagination = null,
        private array $extraFields = []
    ) {
    }

    /**
     * Return the fiscal year.
     *
     * @return string
     */
    public function getFiscalYear(): string
    {
        return $this->fiscalYear;
    }

    /**
     * Return the fiscal period.
     *
     * @return string
     */
    public function getPeriod(): string
    {
        return $this->period;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        $payload = [
            'ejercicio' => $this->fiscalYear,
            'periodo' => $this->period,
        ];

        if ($this->series !== null) {
            $payload['serie'] = $this->series;
        }

        if ($this->number !== null) {
            $payload['numero'] = $this->number;
        }

        if ($this->issueDateRange !== null) {
            $payload['rango_fecha_expedicion'] = $this->issueDateRange->toArray();
        }

        if ($this->issueDate !== null) {
            $payload['fecha_expedicion'] = $this->issueDate;
        }

        if ($this->pagination !== null) {
            $payload['paginacion'] = $this->pagination->toArray();
        }

        return array_merge($payload, $this->extraFields);
    }
}
