<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Builder;

use Bluehost\VerifactiApi\Dto\CorrectiveAmounts;
use Bluehost\VerifactiApi\Dto\InvoiceCreateRequest;
use Bluehost\VerifactiApi\Dto\InvoiceLine;
use Bluehost\VerifactiApi\Dto\InvoiceModifyRequest;
use Bluehost\VerifactiApi\Dto\InvoiceReference;
use Bluehost\VerifactiApi\Dto\OtherIdentifier;
use Bluehost\VerifactiApi\Dto\SpecialInvoiceData;

/**
 * Fluent builder for invoice create and modify requests.
 */
final class InvoiceBuilder
{
    private string $series = '';
    private string $number = '';
    private string $issueDate = '';
    private ?string $operationDate = null;
    private string $invoiceType = '';
    private string $description = '';

    /**
     * @var array<int, InvoiceLine>
     */
    private array $lines = [];

    private string $totalAmount = '';
    private ?string $nif = null;
    private ?OtherIdentifier $otherIdentifier = null;
    private ?string $recipientName = null;
    private ?bool $validateRecipient = null;
    private ?string $rectificationType = null;
    private ?CorrectiveAmounts $rectificationAmounts = null;

    /**
     * @var array<int, InvoiceReference>
     */
    private array $rectifiedInvoices = [];

    /**
     * @var array<int, InvoiceReference>
     */
    private array $replacedInvoices = [];

    private ?string $incidentCode = null;
    private ?SpecialInvoiceData $specialData = null;
    private ?string $previousRejectionStatus = null;

    /**
     * @var array<string, mixed>
     */
    private array $extraFields = [];

    /**
     * Set the invoice series.
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
     * Set the invoice number.
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
     * Set the issue date.
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
     * Set the operation date.
     *
     * @param string $operationDate Operation date in API format.
     *
     * @return self
     */
    public function withOperationDate(string $operationDate): self
    {
        $this->operationDate = $operationDate;

        return $this;
    }

    /**
     * Set the invoice type code.
     *
     * @param string $invoiceType Invoice type code.
     *
     * @return self
     */
    public function withInvoiceType(string $invoiceType): self
    {
        $this->invoiceType = $invoiceType;

        return $this;
    }

    /**
     * Set the invoice description.
     *
     * @param string $description Invoice description.
     *
     * @return self
     */
    public function withDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Append an invoice line.
     *
     * @param InvoiceLine $line Invoice line payload.
     *
     * @return self
     */
    public function addLine(InvoiceLine $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    /**
     * Set the total invoice amount.
     *
     * @param string $totalAmount Total amount.
     *
     * @return self
     */
    public function withTotalAmount(string $totalAmount): self
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    /**
     * Set recipient data using a Spanish NIF.
     *
     * @param string $nif           Recipient NIF.
     * @param string $recipientName Recipient name.
     *
     * @return self
     */
    public function withRecipient(string $nif, string $recipientName): self
    {
        $this->nif = $nif;
        $this->recipientName = $recipientName;

        return $this;
    }

    /**
     * Set recipient data using a foreign identifier.
     *
     * @param OtherIdentifier $identifier    Foreign identifier.
     * @param string|null     $recipientName Optional recipient name.
     *
     * @return self
     */
    public function withOtherIdentifier(OtherIdentifier $identifier, ?string $recipientName = null): self
    {
        $this->otherIdentifier = $identifier;
        $this->recipientName = $recipientName;

        return $this;
    }

    /**
     * Enable or disable recipient validation.
     *
     * @param bool $enabled Whether recipient validation is enabled.
     *
     * @return self
     */
    public function validateRecipient(bool $enabled = true): self
    {
        $this->validateRecipient = $enabled;

        return $this;
    }

    /**
     * Apply corrective invoice data from a builder.
     *
     * @param CorrectiveInvoiceBuilder $builder Corrective data builder.
     *
     * @return self
     */
    public function withCorrectiveData(CorrectiveInvoiceBuilder $builder): self
    {
        $payload = $builder->build();
        $this->rectificationType = isset($payload['tipo_rectificativa']) ? (string) $payload['tipo_rectificativa'] : null;
        $this->rectificationAmounts = $payload['importe_rectificativa'] ?? null;
        $this->rectifiedInvoices = $payload['facturas_rectificadas'] ?? [];
        $this->replacedInvoices = $payload['facturas_sustituidas'] ?? [];

        return $this;
    }

    /**
     * Set special invoice data.
     *
     * @param SpecialInvoiceData $specialData Special invoice payload.
     *
     * @return self
     */
    public function withSpecialData(SpecialInvoiceData $specialData): self
    {
        $this->specialData = $specialData;

        return $this;
    }

    /**
     * Set the incident code.
     *
     * @param string $incidentCode Incident code.
     *
     * @return self
     */
    public function withIncidentCode(string $incidentCode): self
    {
        $this->incidentCode = $incidentCode;

        return $this;
    }

    /**
     * Set the previous rejection status for modify requests.
     *
     * @param string $previousRejectionStatus Previous rejection status code.
     *
     * @return self
     */
    public function withPreviousRejectionStatus(string $previousRejectionStatus): self
    {
        $this->previousRejectionStatus = $previousRejectionStatus;

        return $this;
    }

    /**
     * Add an extra API field not covered by first-class properties.
     *
     * @param string $key   Field name.
     * @param mixed  $value Field value.
     *
     * @return self
     */
    public function withExtraField(string $key, mixed $value): self
    {
        $this->extraFields[$key] = $value;

        return $this;
    }

    /**
     * Build an invoice create request.
     *
     * @return InvoiceCreateRequest
     */
    public function buildCreate(): InvoiceCreateRequest
    {
        return new InvoiceCreateRequest(
            $this->series,
            $this->number,
            $this->issueDate,
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
            $this->extraFields
        );
    }

    /**
     * Build an invoice modify request.
     *
     * @return InvoiceModifyRequest
     */
    public function buildModify(): InvoiceModifyRequest
    {
        return new InvoiceModifyRequest(
            $this->series,
            $this->number,
            $this->issueDate,
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
            $this->previousRejectionStatus
        );
    }
}
