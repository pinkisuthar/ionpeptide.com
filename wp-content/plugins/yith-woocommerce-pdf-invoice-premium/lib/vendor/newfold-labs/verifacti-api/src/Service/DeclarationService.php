<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Service;

use Bluehost\VerifactiApi\Dto\DeclarationResponse;
use Bluehost\VerifactiApi\Exception\ApiException;
use Bluehost\VerifactiApi\Exception\AuthenticationException;
use Bluehost\VerifactiApi\Exception\HttpException;
use Bluehost\VerifactiApi\Exception\SerializationException;
use Bluehost\VerifactiApi\Exception\TransportException;

/**
 * Service for the Verifacti declaration endpoint.
 */
final class DeclarationService
{
    public function __construct(private ApiExecutor $executor)
    {
    }

    /**
     * Fetch the Verifacti declaration document.
     *
     * @return DeclarationResponse
     *
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function fetch(): DeclarationResponse
    {
        return DeclarationResponse::fromApiResponse($this->executor->get('/verifactu/declaracion'));
    }
}
