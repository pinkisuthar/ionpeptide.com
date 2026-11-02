<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

/**
 * Request payload for modifying an existing invoice.
 */
final class InvoiceModifyRequest extends AbstractInvoiceRequest
{
    /**
     * @param array<int, InvoiceLine>      $lines
     * @param array<int, InvoiceReference> $rectifiedInvoices
     * @param array<int, InvoiceReference> $replacedInvoices
     * @param array<string, mixed>         $extraFields
     */
    public function __construct(
        string $series,
        string $number,
        string $issueDate,
        string $invoiceType,
        string $description,
        array $lines,
        string $totalAmount,
        ?string $operationDate = null,
        ?string $nif = null,
        ?OtherIdentifier $otherIdentifier = null,
        ?string $recipientName = null,
        ?bool $validateRecipient = null,
        ?string $rectificationType = null,
        ?CorrectiveAmounts $rectificationAmounts = null,
        array $rectifiedInvoices = [],
        array $replacedInvoices = [],
        ?string $incidentCode = null,
        ?SpecialInvoiceData $specialData = null,
        array $extraFields = [],
        private ?string $previousRejectionStatus = null
    ) {
        parent::__construct(
            $series,
            $number,
            $issueDate,
            $invoiceType,
            $description,
            $lines,
            $totalAmount,
            $operationDate,
            $nif,
            $otherIdentifier,
            $recipientName,
            $validateRecipient,
            $rectificationType,
            $rectificationAmounts,
            $rectifiedInvoices,
            $replacedInvoices,
            $incidentCode,
            $specialData,
            $extraFields
        );
    }

    /**
     * Return the previous rejection status code.
     *
     * @return string|null
     */
    public function getPreviousRejectionStatus(): ?string
    {
        return $this->previousRejectionStatus;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        $payload = parent::toArray();

        if ($this->previousRejectionStatus !== null) {
            $payload['rechazo_previo'] = $this->previousRejectionStatus;
        }

        return $payload;
    }
}
