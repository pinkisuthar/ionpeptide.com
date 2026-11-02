<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Rectified base and tax amounts for corrective invoices.
 */
final class CorrectiveAmounts implements Arrayable
{
    /**
     * @param string $baseRectificada  Rectified taxable base.
     * @param string $cuotaRectificada Rectified tax amount.
     */
    public function __construct(
        private string $baseRectificada,
        private string $cuotaRectificada
    ) {
    }

    /**
     * Return the rectified taxable base.
     *
     * @return string
     */
    public function getBaseRectificada(): string
    {
        return $this->baseRectificada;
    }

    /**
     * Return the rectified tax amount.
     *
     * @return string
     */
    public function getCuotaRectificada(): string
    {
        return $this->cuotaRectificada;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'base_rectificada' => $this->baseRectificada,
            'cuota_rectificada' => $this->cuotaRectificada,
        ];
    }
}
