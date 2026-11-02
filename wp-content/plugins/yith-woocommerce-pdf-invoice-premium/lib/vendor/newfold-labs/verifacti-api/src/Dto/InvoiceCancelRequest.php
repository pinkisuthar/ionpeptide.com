<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Request payload for cancelling an invoice.
 */
final class InvoiceCancelRequest implements Arrayable
{
    /**
     * @param string      $series                  Invoice series.
     * @param string      $number                  Invoice number.
     * @param string      $issueDate               Issue date in API format.
     * @param string|null $previousRejectionStatus Optional previous rejection status.
     * @param string|null $withoutPreviousRecord   Optional sin_registro_previo flag.
     * @param string|null $incidentCode            Optional incident code.
     */
    public function __construct(
        private string $series,
        private string $number,
        private string $issueDate,
        private ?string $previousRejectionStatus = null,
        private ?string $withoutPreviousRecord = null,
        private ?string $incidentCode = null
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
        $payload = [
            'serie' => $this->series,
            'numero' => $this->number,
            'fecha_expedicion' => $this->issueDate,
        ];

        if ($this->previousRejectionStatus !== null) {
            $payload['rechazo_previo'] = $this->previousRejectionStatus;
        }

        if ($this->withoutPreviousRecord !== null) {
            $payload['sin_registro_previo'] = $this->withoutPreviousRecord;
        }

        if ($this->incidentCode !== null) {
            $payload['incidencia'] = $this->incidentCode;
        }

        return $payload;
    }
}
