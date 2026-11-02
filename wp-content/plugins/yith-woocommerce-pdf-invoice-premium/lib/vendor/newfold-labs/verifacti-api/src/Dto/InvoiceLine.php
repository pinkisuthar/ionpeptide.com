<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Invoice line item payload.
 */
final class InvoiceLine implements Arrayable
{
    /**
     * @param array<string, mixed> $extraFields Additional API fields.
     */
    public function __construct(
        private string $taxableBase,
        private ?string $taxRate = null,
        private ?string $taxAmount = null,
        private ?string $exemptOperationCode = null,
        private ?string $operationClassification = null,
        private ?string $regimeKey = null,
        private array $extraFields = []
    ) {
    }

    /**
     * Return the taxable base amount.
     *
     * @return string
     */
    public function getTaxableBase(): string
    {
        return $this->taxableBase;
    }

    /**
     * Return the tax rate, if set.
     *
     * @return string|null
     */
    public function getTaxRate(): ?string
    {
        return $this->taxRate;
    }

    /**
     * Return the tax amount, if set.
     *
     * @return string|null
     */
    public function getTaxAmount(): ?string
    {
        return $this->taxAmount;
    }

    /**
     * Return the exempt operation code, if set.
     *
     * @return string|null
     */
    public function getExemptOperationCode(): ?string
    {
        return $this->exemptOperationCode;
    }

    /**
     * Return the operation classification, if set.
     *
     * @return string|null
     */
    public function getOperationClassification(): ?string
    {
        return $this->operationClassification;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        $payload = [
            'base_imponible' => $this->taxableBase,
        ];

        if ($this->taxRate !== null) {
            $payload['tipo_impositivo'] = $this->taxRate;
        }

        if ($this->taxAmount !== null) {
            $payload['cuota_repercutida'] = $this->taxAmount;
        }

        if ($this->exemptOperationCode !== null) {
            $payload['operacion_exenta'] = $this->exemptOperationCode;
        }

        if ($this->operationClassification !== null) {
            $payload['calificacion_operacion'] = $this->operationClassification;
        }

        if ($this->regimeKey !== null) {
            $payload['clave_regimen'] = $this->regimeKey;
        }

        return array_merge($payload, $this->extraFields);
    }
}
