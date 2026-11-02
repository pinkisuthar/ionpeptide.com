<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Builder;

use Bluehost\VerifactiApi\Dto\CorrectiveAmounts;
use Bluehost\VerifactiApi\Dto\InvoiceReference;

/**
 * Fluent builder for corrective invoice metadata.
 */
final class CorrectiveInvoiceBuilder
{
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

    /**
     * Mark the corrective invoice as substitution-based.
     *
     * @return self
     */
    public function bySubstitution(): self
    {
        $this->rectificationType = 'S';

        return $this;
    }

    /**
     * Mark the corrective invoice as difference-based.
     *
     * @return self
     */
    public function byDifference(): self
    {
        $this->rectificationType = 'I';

        return $this;
    }

    /**
     * Set rectified base and tax amounts.
     *
     * @param string $baseRectificada   Rectified taxable base.
     * @param string $cuotaRectificada  Rectified tax amount.
     *
     * @return self
     */
    public function withRectifiedAmounts(string $baseRectificada, string $cuotaRectificada): self
    {
        $this->rectificationAmounts = new CorrectiveAmounts($baseRectificada, $cuotaRectificada);

        return $this;
    }

    /**
     * Append a rectified invoice reference.
     *
     * @param InvoiceReference $reference Rectified invoice reference.
     *
     * @return self
     */
    public function addCorrectedInvoice(InvoiceReference $reference): self
    {
        $this->rectifiedInvoices[] = $reference;

        return $this;
    }

    /**
     * Append a replaced invoice reference.
     *
     * @param InvoiceReference $reference Replaced invoice reference.
     *
     * @return self
     */
    public function addReplacedInvoice(InvoiceReference $reference): self
    {
        $this->replacedInvoices[] = $reference;

        return $this;
    }

    /**
     * Build the corrective invoice payload fragment.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $payload = [];

        if ($this->rectificationType !== null) {
            $payload['tipo_rectificativa'] = $this->rectificationType;
        }

        if ($this->rectificationAmounts !== null) {
            $payload['importe_rectificativa'] = $this->rectificationAmounts;
        }

        if ($this->rectifiedInvoices !== []) {
            $payload['facturas_rectificadas'] = $this->rectifiedInvoices;
        }

        if ($this->replacedInvoices !== []) {
            $payload['facturas_sustituidas'] = $this->replacedInvoices;
        }

        return $payload;
    }
}
