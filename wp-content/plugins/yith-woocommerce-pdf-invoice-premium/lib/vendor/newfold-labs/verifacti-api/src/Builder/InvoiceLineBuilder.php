<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Builder;

use Bluehost\VerifactiApi\Dto\InvoiceLine;

/**
 * Fluent builder for invoice line payloads.
 */
final class InvoiceLineBuilder
{
    private string $taxableBase = '';
    private ?string $taxRate = null;
    private ?string $taxAmount = null;
    private ?string $exemptOperationCode = null;
    private ?string $operationClassification = null;
    private ?string $regimeKey = null;

    /**
     * @var array<string, mixed>
     */
    private array $extraFields = [];

    /**
     * Set the taxable base amount.
     *
     * @param string $taxableBase Taxable base amount.
     *
     * @return self
     */
    public function withTaxableBase(string $taxableBase): self
    {
        $this->taxableBase = $taxableBase;

        return $this;
    }

    /**
     * Set standard tax rate and amount.
     *
     * @param string $taxRate   Tax rate.
     * @param string $taxAmount Tax amount.
     *
     * @return self
     */
    public function withTax(string $taxRate, string $taxAmount): self
    {
        $this->taxRate = $taxRate;
        $this->taxAmount = $taxAmount;

        return $this;
    }

    /**
     * Mark the line as exempt.
     *
     * @param string $exemptOperationCode Exempt operation code.
     *
     * @return self
     */
    public function asExempt(string $exemptOperationCode): self
    {
        $this->exemptOperationCode = $exemptOperationCode;

        return $this;
    }

    /**
     * Set the operation classification.
     *
     * @param string $operationClassification Operation classification code.
     *
     * @return self
     */
    public function withOperationClassification(string $operationClassification): self
    {
        $this->operationClassification = $operationClassification;

        return $this;
    }

    /**
     * Set the tax regime key.
     *
     * @param string $regimeKey Regime key code.
     *
     * @return self
     */
    public function withRegimeKey(string $regimeKey): self
    {
        $this->regimeKey = $regimeKey;

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
     * Build the invoice line DTO.
     *
     * @return InvoiceLine
     */
    public function build(): InvoiceLine
    {
        return new InvoiceLine(
            $this->taxableBase,
            $this->taxRate,
            $this->taxAmount,
            $this->exemptOperationCode,
            $this->operationClassification,
            $this->regimeKey,
            $this->extraFields
        );
    }
}
