<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Service;

use Bluehost\VerifactiApi\Dto\XmlDownloadRequest;
use Bluehost\VerifactiApi\Dto\XmlDownloadResponse;
use Bluehost\VerifactiApi\Dto\XmlExportRequest;
use Bluehost\VerifactiApi\Dto\XmlExportResponse;
use Bluehost\VerifactiApi\Exception\ApiException;
use Bluehost\VerifactiApi\Exception\AuthenticationException;
use Bluehost\VerifactiApi\Exception\HttpException;
use Bluehost\VerifactiApi\Exception\SerializationException;
use Bluehost\VerifactiApi\Exception\TransportException;
use Bluehost\VerifactiApi\Exception\ValidationException;
use Bluehost\VerifactiApi\Validator\RequestValidator;

/**
 * Service for Verifacti XML export and download endpoints.
 */
final class ExportService
{
    public function __construct(
        private ApiExecutor $executor,
        private RequestValidator $validator
    ) {
    }

    /**
     * Export XML metadata for a fiscal period.
     *
     * @param XmlExportRequest $request Export request payload.
     *
     * @return XmlExportResponse
     *
     * @throws ValidationException
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function export(XmlExportRequest $request): XmlExportResponse
    {
        $this->validator->validateExport($request);

        return XmlExportResponse::fromApiResponse(
            $this->executor->post('/verifactu/export', $request->toArray())
        );
    }

    /**
     * Download XML files for an invoice.
     *
     * @param XmlDownloadRequest $request Download request payload.
     *
     * @return XmlDownloadResponse
     *
     * @throws ValidationException
     * @throws AuthenticationException
     * @throws ApiException
     * @throws HttpException
     * @throws SerializationException
     * @throws TransportException
     */
    public function download(XmlDownloadRequest $request): XmlDownloadResponse
    {
        $this->validator->validateDownload($request);

        return XmlDownloadResponse::fromApiResponse(
            $this->executor->post('/verifactu/downloadXML', $request->toArray())
        );
    }
}
