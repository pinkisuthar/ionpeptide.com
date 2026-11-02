<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Service;

use Bluehost\VerifactiApi\Dto\BulkCreateResponse;
use Bluehost\VerifactiApi\Dto\BulkInvoiceCreateRequest;
use Bluehost\VerifactiApi\Dto\InvoiceCancelRequest;
use Bluehost\VerifactiApi\Dto\InvoiceCreateRequest;
use Bluehost\VerifactiApi\Dto\InvoiceListRequest;
use Bluehost\VerifactiApi\Dto\InvoiceListResponse;
use Bluehost\VerifactiApi\Dto\InvoiceModifyRequest;
use Bluehost\VerifactiApi\Dto\InvoiceOperationResponse;
use Bluehost\VerifactiApi\Exception\ApiException;
use Bluehost\VerifactiApi\Exception\AuthenticationException;
use Bluehost\VerifactiApi\Exception\HttpException;
use Bluehost\VerifactiApi\Exception\SerializationException;
use Bluehost\VerifactiApi\Exception\TransportException;
use Bluehost\VerifactiApi\Exception\ValidationException;
use Bluehost\VerifactiApi\Support\HttpHeaderHelper;
use Bluehost\VerifactiApi\Validator\RequestValidator;

/**
 * Service for Verifacti invoice create, modify, cancel, and list endpoints.
 */
final class InvoiceService
{
    public function __construct(
        private ApiExecutor $executor,
        private RequestValidator $validator
    ) {
    }

    /**
     * Create a single invoice.
     *
     * @param InvoiceCreateRequest $request         Invoice payload.
     * @param string|null          $idempotencyKey Optional idempotency key header value.
     *
     * @return InvoiceOperationResponse
     *
     * @throws ValidationException
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function create(InvoiceCreateRequest $request, ?string $idempotencyKey = null): InvoiceOperationResponse
    {
        $this->validator->validateCreate($request);

        $headers = [];
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $normalizedIdempotencyKey = trim($idempotencyKey);
            HttpHeaderHelper::assertSafeRequestHeaderValue($normalizedIdempotencyKey, 'Idempotency-Key');
            $headers['Idempotency-Key'] = $normalizedIdempotencyKey;
        }

        return InvoiceOperationResponse::fromApiResponse(
            $this->executor->post('/verifactu/create', $request->toArray(), $headers)
        );
    }

    /**
     * Create invoices in bulk.
     *
     * @param BulkInvoiceCreateRequest $request Bulk invoice payload.
     *
     * @return BulkCreateResponse
     *
     * @throws ValidationException
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function createBulk(BulkInvoiceCreateRequest $request): BulkCreateResponse
    {
        $this->validator->validateBulkCreate($request);

        return BulkCreateResponse::fromApiResponse(
            $this->executor->post('/verifactu/create_bulk', $request->toArray())
        );
    }

    /**
     * Modify an existing invoice.
     *
     * @param InvoiceModifyRequest $request Modify payload.
     *
     * @return InvoiceOperationResponse
     *
     * @throws ValidationException
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function modify(InvoiceModifyRequest $request): InvoiceOperationResponse
    {
        $this->validator->validateModify($request);

        return InvoiceOperationResponse::fromApiResponse(
            $this->executor->put('/verifactu/modify', $request->toArray())
        );
    }

    /**
     * Cancel an invoice.
     *
     * @param InvoiceCancelRequest $request Cancel payload.
     *
     * @return InvoiceOperationResponse
     *
     * @throws ValidationException
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function cancel(InvoiceCancelRequest $request): InvoiceOperationResponse
    {
        $this->validator->validateCancel($request);

        return InvoiceOperationResponse::fromApiResponse(
            $this->executor->post('/verifactu/cancel', $request->toArray())
        );
    }

    /**
     * List invoices for a fiscal period.
     *
     * @param InvoiceListRequest $request List request payload.
     *
     * @return InvoiceListResponse
     *
     * @throws ValidationException
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function list(InvoiceListRequest $request): InvoiceListResponse
    {
        $this->validator->validateList($request);

        return InvoiceListResponse::fromApiResponse(
            $this->executor->post('/verifactu/list', $request->toArray())
        );
    }
}
