<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Service;

use Bluehost\VerifactiApi\Dto\InvoiceStatusLookupRequest;
use Bluehost\VerifactiApi\Dto\RecordStatusLookupRequest;
use Bluehost\VerifactiApi\Dto\StatusResponse;
use Bluehost\VerifactiApi\Exception\ApiException;
use Bluehost\VerifactiApi\Exception\AuthenticationException;
use Bluehost\VerifactiApi\Exception\HttpException;
use Bluehost\VerifactiApi\Exception\SerializationException;
use Bluehost\VerifactiApi\Exception\TransportException;
use Bluehost\VerifactiApi\Exception\ValidationException;
use Bluehost\VerifactiApi\Validator\RequestValidator;

/**
 * Service for Verifacti invoice and record status endpoints.
 */
final class StatusService
{
    public function __construct(
        private ApiExecutor $executor,
        private RequestValidator $validator
    ) {
    }

    /**
     * Look up record status by UUID.
     *
     * @param RecordStatusLookupRequest $request Status lookup request.
     *
     * @return StatusResponse
     *
     * @throws ValidationException
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function getRecordStatus(RecordStatusLookupRequest $request): StatusResponse
    {
        $this->validator->validateRecordStatusLookup($request);

        return StatusResponse::fromApiResponse($this->executor->get('/verifactu/status', $request->toArray()));
    }

    /**
     * Look up invoice status by invoice identity fields.
     *
     * @param InvoiceStatusLookupRequest $request Status lookup request.
     *
     * @return StatusResponse
     *
     * @throws ValidationException
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function getInvoiceStatus(InvoiceStatusLookupRequest $request): StatusResponse
    {
        $this->validator->validateInvoiceStatusLookup($request);

        return StatusResponse::fromApiResponse($this->executor->post('/verifactu/status', $request->toArray()));
    }
}
