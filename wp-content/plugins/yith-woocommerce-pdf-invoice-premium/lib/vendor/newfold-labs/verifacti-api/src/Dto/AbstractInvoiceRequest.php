<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Base request payload for invoice create and modify operations.
 */
abstract class AbstractInvoiceRequest implements Arrayable
{
    /**
     * @param array<int, InvoiceLine>        $lines
     * @param array<int, InvoiceReference>   $rectifiedInvoices
     * @param array<int, InvoiceReference>   $replacedInvoices
     * @param array<string, mixed>           $extraFields
     */
    public function __construct(
        protected string $series,
        protected string $number,
        protected string $issueDate,
        protected string $invoiceType,
        protected string $description,
        protected array $lines,
        protected string $totalAmount,
        protected ?string $operationDate = null,
        protected ?string $nif = null,
        protected ?OtherIdentifier $otherIdentifier = null,
        protected ?string $recipientName = null,
        protected ?bool $validateRecipient = null,
        protected ?string $rectificationType = null,
        protected ?CorrectiveAmounts $rectificationAmounts = null,
        protected array $rectifiedInvoices = [],
        protected array $replacedInvoices = [],
        protected ?string $incidentCode = null,
        protected ?SpecialInvoiceData $specialData = null,
        protected array $extraFields = []
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
     * Return the issue date in API format.
     *
     * @return string
     */
    public function getIssueDate(): string
    {
        return $this->issueDate;
    }

    /**
     * Return the invoice type code.
     *
     * @return string
     */
    public function getInvoiceType(): string
    {
        return $this->invoiceType;
    }

    /**
     * Return the invoice description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Return invoice line items.
     *
     * @return array<int, InvoiceLine>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Return the total invoice amount.
     *
     * @return string
     */
    public function getTotalAmount(): string
    {
        return $this->totalAmount;
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
     * Return the rectification type, if set.
     *
     * @return string|null
     */
    public function getRectificationType(): ?string
    {
        return $this->rectificationType;
    }

    /**
     * Return rectification amounts, if set.
     *
     * @return CorrectiveAmounts|null
     */
    public function getRectificationAmounts(): ?CorrectiveAmounts
    {
        return $this->rectificationAmounts;
    }

    /**
     * Return rectified invoice references.
     *
     * @return array<int, InvoiceReference>
     */
    public function getRectifiedInvoices(): array
    {
        return $this->rectifiedInvoices;
    }

    /**
     * Return replaced invoice references.
     *
     * @return array<int, InvoiceReference>
     */
    public function getReplacedInvoices(): array
    {
        return $this->replacedInvoices;
    }

    /**
     * Build a modify request from this invoice payload.
     *
     * @param string|null $series                  Override serie.
     * @param string|null $number                  Override numero.
     * @param string|null $issueDate               Override fecha_expedicion.
     * @param string|null $previousRejectionStatus rechazo_previo value.
     *
     * @return InvoiceModifyRequest
     */
    public function toModifyRequest(
        ?string $series = null,
        ?string $number = null,
        ?string $issueDate = null,
        ?string $previousRejectionStatus = null
    ): InvoiceModifyRequest {
        return new InvoiceModifyRequest(
            $series ?? $this->series,
            $number ?? $this->number,
            $issueDate ?? $this->issueDate,
            $this->invoiceType,
            $this->description,
            $this->lines,
            $this->totalAmount,
            $this->operationDate,
            $this->nif,
            $this->otherIdentifier,
            $this->recipientName,
            $this->validateRecipient,
            $this->rectificationType,
            $this->rectificationAmounts,
            $this->rectifiedInvoices,
            $this->replacedInvoices,
            $this->incidentCode,
            $this->specialData,
            $this->extraFields,
            $previousRejectionStatus
        );
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
            'tipo_factura' => $this->invoiceType,
            'descripcion' => $this->description,
            'lineas' => array_map(static function (InvoiceLine $line): array {
                return $line->toArray();
            }, $this->lines),
            'importe_total' => $this->totalAmount,
        ];

        if ($this->operationDate !== null) {
            $payload['fecha_operacion'] = $this->operationDate;
        }

        if ($this->nif !== null) {
            $payload['nif'] = $this->nif;
        }

        if ($this->otherIdentifier !== null) {
            $payload['id_otro'] = $this->otherIdentifier->toArray();
        }

        if ($this->recipientName !== null) {
            $payload['nombre'] = $this->recipientName;
        }

        if ($this->validateRecipient !== null) {
            $payload['validar_destinatario'] = $this->validateRecipient;
        }

        if ($this->rectificationType !== null) {
            $payload['tipo_rectificativa'] = $this->rectificationType;
        }

        if ($this->rectificationAmounts !== null) {
            $payload['importe_rectificativa'] = $this->rectificationAmounts->toArray();
        }

        if ($this->rectifiedInvoices !== []) {
            $payload['facturas_rectificadas'] = array_map(static function (InvoiceReference $reference): array {
                return $reference->toArray();
            }, $this->rectifiedInvoices);
        }

        if ($this->replacedInvoices !== []) {
            $payload['facturas_sustituidas'] = array_map(static function (InvoiceReference $reference): array {
                return $reference->toArray();
            }, $this->replacedInvoices);
        }

        if ($this->incidentCode !== null) {
            $payload['incidencia'] = $this->incidentCode;
        }

        if ($this->specialData !== null) {
            $payload['especial'] = $this->specialData->toArray();
        }

        return array_merge($payload, $this->extraFields);
    }
}
