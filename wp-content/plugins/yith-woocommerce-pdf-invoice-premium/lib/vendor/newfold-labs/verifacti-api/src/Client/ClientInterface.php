<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Client;

use Bluehost\VerifactiApi\Dto\BulkCreateResponse;
use Bluehost\VerifactiApi\Dto\BulkInvoiceCreateRequest;
use Bluehost\VerifactiApi\Dto\DeclarationResponse;
use Bluehost\VerifactiApi\Dto\HealthResponse;
use Bluehost\VerifactiApi\Dto\InvoiceCancelRequest;
use Bluehost\VerifactiApi\Dto\InvoiceCreateRequest;
use Bluehost\VerifactiApi\Dto\InvoiceListRequest;
use Bluehost\VerifactiApi\Dto\InvoiceListResponse;
use Bluehost\VerifactiApi\Dto\InvoiceModifyRequest;
use Bluehost\VerifactiApi\Dto\InvoiceOperationResponse;
use Bluehost\VerifactiApi\Dto\InvoiceStatusLookupRequest;
use Bluehost\VerifactiApi\Dto\RecordStatusLookupRequest;
use Bluehost\VerifactiApi\Dto\StatusResponse;
use Bluehost\VerifactiApi\Dto\XmlDownloadRequest;
use Bluehost\VerifactiApi\Dto\XmlDownloadResponse;
use Bluehost\VerifactiApi\Dto\XmlExportRequest;
use Bluehost\VerifactiApi\Dto\XmlExportResponse;
use Bluehost\VerifactiApi\Service\DeclarationService;
use Bluehost\VerifactiApi\Service\ExportService;
use Bluehost\VerifactiApi\Service\HealthService;
use Bluehost\VerifactiApi\Service\InvoiceService;
use Bluehost\VerifactiApi\Service\StatusService;

/**
 * Contract for the Verifacti API client facade.
 */
interface ClientInterface
{
    /**
     * Check API health.
     *
     * @return HealthResponse
     */
    public function healthCheck(): HealthResponse;

    /**
     * Look up record status by UUID.
     *
     * @param RecordStatusLookupRequest $request Lookup request.
     *
     * @return StatusResponse
     */
    public function getRecordStatus(RecordStatusLookupRequest $request): StatusResponse;

    /**
     * Look up invoice status by invoice identity.
     *
     * @param InvoiceStatusLookupRequest $request Lookup request.
     *
     * @return StatusResponse
     */
    public function getInvoiceStatus(InvoiceStatusLookupRequest $request): StatusResponse;

    /**
     * Create a single invoice.
     *
     * @param InvoiceCreateRequest $request         Invoice payload.
     * @param string|null          $idempotencyKey Optional idempotency key.
     *
     * @return InvoiceOperationResponse
     */
    public function createInvoice(InvoiceCreateRequest $request, ?string $idempotencyKey = null): InvoiceOperationResponse;

    /**
     * Create multiple invoices in a single request.
     *
     * @param BulkInvoiceCreateRequest $request Bulk create payload.
     *
     * @return BulkCreateResponse
     */
    public function createBulkInvoices(BulkInvoiceCreateRequest $request): BulkCreateResponse;

    /**
     * Modify an existing invoice.
     *
     * @param InvoiceModifyRequest $request Modify payload.
     *
     * @return InvoiceOperationResponse
     */
    public function modifyInvoice(InvoiceModifyRequest $request): InvoiceOperationResponse;

    /**
     * Cancel an existing invoice.
     *
     * @param InvoiceCancelRequest $request Cancel payload.
     *
     * @return InvoiceOperationResponse
     */
    public function cancelInvoice(InvoiceCancelRequest $request): InvoiceOperationResponse;

    /**
     * List invoices for a fiscal period.
     *
     * @param InvoiceListRequest $request List filters.
     *
     * @return InvoiceListResponse
     */
    public function listInvoices(InvoiceListRequest $request): InvoiceListResponse;

    /**
     * Export invoice XML for a fiscal period.
     *
     * @param XmlExportRequest $request Export request.
     *
     * @return XmlExportResponse
     */
    public function exportXml(XmlExportRequest $request): XmlExportResponse;

    /**
     * Download invoice XML for a specific invoice.
     *
     * @param XmlDownloadRequest $request Download request.
     *
     * @return XmlDownloadResponse
     */
    public function downloadXml(XmlDownloadRequest $request): XmlDownloadResponse;

    /**
     * Fetch the responsible declaration document.
     *
     * @return DeclarationResponse
     */
    public function fetchDeclaration(): DeclarationResponse;

    /**
     * Return the health service accessor.
     *
     * @return HealthService
     */
    public function health(): HealthService;

    /**
     * Return the status service accessor.
     *
     * @return StatusService
     */
    public function status(): StatusService;

    /**
     * Return the invoice service accessor.
     *
     * @return InvoiceService
     */
    public function invoices(): InvoiceService;

    /**
     * Return the export service accessor.
     *
     * @return ExportService
     */
    public function exports(): ExportService;

    /**
     * Return the declaration service accessor.
     *
     * @return DeclarationService
     */
    public function declaration(): DeclarationService;
}
