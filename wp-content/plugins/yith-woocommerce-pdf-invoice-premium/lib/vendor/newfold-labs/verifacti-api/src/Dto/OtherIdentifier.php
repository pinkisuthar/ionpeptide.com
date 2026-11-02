<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Foreign recipient identifier payload.
 */
final class OtherIdentifier implements Arrayable
{
    /**
     * @param string $countryCode Country code.
     * @param string $idType      Identifier type code.
     * @param string $identifier  Identifier value.
     */
    public function __construct(
        private string $countryCode,
        private string $idType,
        private string $identifier
    ) {
    }

    /**
     * Return the country code.
     *
     * @return string
     */
    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    /**
     * Return the identifier type code.
     *
     * @return string
     */
    public function getIdType(): string
    {
        return $this->idType;
    }

    /**
     * Return the identifier value.
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'codigo_pais' => $this->countryCode,
            'id_type' => $this->idType,
            'id' => $this->identifier,
        ];
    }
}
