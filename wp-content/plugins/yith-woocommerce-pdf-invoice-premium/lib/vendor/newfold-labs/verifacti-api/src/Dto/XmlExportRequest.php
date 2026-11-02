<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Request payload for exporting invoice XML for a fiscal period.
 */
final class XmlExportRequest implements Arrayable
{
    /**
     * @param string      $fiscalYear Fiscal year.
     * @param string      $period     Fiscal period.
     * @param string|null $token      Optional pagination token.
     */
    public function __construct(
        private string $fiscalYear,
        private string $period,
        private ?string $token = null
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
     * Return the pagination token, if set.
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->token;
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

        if ($this->token !== null) {
            $payload['token'] = $this->token;
        }

        return $payload;
    }
}
