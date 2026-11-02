<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Client;

use Bluehost\VerifactiApi\Config\VerifactiConfig;
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
use Bluehost\VerifactiApi\Serializer\JsonSerializer;
use Bluehost\VerifactiApi\Service\ApiExecutor;
use Bluehost\VerifactiApi\Service\DeclarationService;
use Bluehost\VerifactiApi\Service\ExportService;
use Bluehost\VerifactiApi\Service\HealthService;
use Bluehost\VerifactiApi\Service\InvoiceService;
use Bluehost\VerifactiApi\Service\StatusService;
use Bluehost\VerifactiApi\Transport\HttpTransportInterface;
use Bluehost\VerifactiApi\Validator\RequestValidator;

/**
 * Facade client for the Verifacti API.
 */
final class VerifactiClient implements ClientInterface
{
    private HealthService $healthService;
    private StatusService $statusService;
    private InvoiceService $invoiceService;
    private ExportService $exportService;
    private DeclarationService $declarationService;

    /**
     * @param VerifactiConfig          $config    Client configuration.
     * @param HttpTransportInterface $transport HTTP transport implementation.
     */
    public function __construct(
        private VerifactiConfig $config,
        HttpTransportInterface $transport
    ) {
        $validator = new RequestValidator();
        $executor = new ApiExecutor($config, $transport, new JsonSerializer());

        $this->healthService = new HealthService($executor);
        $this->statusService = new StatusService($executor, $validator);
        $this->invoiceService = new InvoiceService($executor, $validator);
        $this->exportService = new ExportService($executor, $validator);
        $this->declarationService = new DeclarationService($executor);
    }

    /**
     * Return the client configuration.
     *
     * @return VerifactiConfig
     */
    public function getConfig(): VerifactiConfig
    {
        return $this->config;
    }

    /**
     * {@inheritDoc}
     */
    public function healthCheck(): HealthResponse
    {
        return $this->healthService->check();
    }

    /**
     * {@inheritDoc}
     */
    public function getRecordStatus(RecordStatusLookupRequest $request): StatusResponse
    {
        return $this->statusService->getRecordStatus($request);
    }

    /**
     * {@inheritDoc}
     */
    public function getInvoiceStatus(InvoiceStatusLookupRequest $request): StatusResponse
    {
        return $this->statusService->getInvoiceStatus($request);
    }

    /**
     * {@inheritDoc}
     */
    public function createInvoice(InvoiceCreateRequest $request, ?string $idempotencyKey = null): InvoiceOperationResponse
    {
        return $this->invoiceService->create($request, $idempotencyKey);
    }

    /**
     * {@inheritDoc}
     */
    public function createBulkInvoices(BulkInvoiceCreateRequest $request): BulkCreateResponse
    {
        return $this->invoiceService->createBulk($request);
    }

    /**
     * {@inheritDoc}
     */
    public function modifyInvoice(InvoiceModifyRequest $request): InvoiceOperationResponse
    {
        return $this->invoiceService->modify($request);
    }

    /**
     * {@inheritDoc}
     */
    public function cancelInvoice(InvoiceCancelRequest $request): InvoiceOperationResponse
    {
        return $this->invoiceService->cancel($request);
    }

    /**
     * {@inheritDoc}
     */
    public function listInvoices(InvoiceListRequest $request): InvoiceListResponse
    {
        return $this->invoiceService->list($request);
    }

    /**
     * {@inheritDoc}
     */
    public function exportXml(XmlExportRequest $request): XmlExportResponse
    {
        return $this->exportService->export($request);
    }

    /**
     * {@inheritDoc}
     */
    public function downloadXml(XmlDownloadRequest $request): XmlDownloadResponse
    {
        return $this->exportService->download($request);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchDeclaration(): DeclarationResponse
    {
        return $this->declarationService->fetch();
    }

    /**
     * {@inheritDoc}
     */
    public function health(): HealthService
    {
        return $this->healthService;
    }

    /**
     * {@inheritDoc}
     */
    public function status(): StatusService
    {
        return $this->statusService;
    }

    /**
     * {@inheritDoc}
     */
    public function invoices(): InvoiceService
    {
        return $this->invoiceService;
    }

    /**
     * {@inheritDoc}
     */
    public function exports(): ExportService
    {
        return $this->exportService;
    }

    /**
     * {@inheritDoc}
     */
    public function declaration(): DeclarationService
    {
        return $this->declarationService;
    }
}
