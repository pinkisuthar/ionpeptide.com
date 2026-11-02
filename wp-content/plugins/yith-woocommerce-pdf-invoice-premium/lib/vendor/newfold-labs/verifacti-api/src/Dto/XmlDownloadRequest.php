<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Request payload for downloading invoice XML for a specific invoice.
 */
final class XmlDownloadRequest implements Arrayable
{
    /**
     * @param string $series Invoice series.
     * @param string $number Invoice number.
     */
    public function __construct(
        private string $series,
        private string $number
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
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'serie' => $this->series,
            'numero' => $this->number,
        ];
    }
}
