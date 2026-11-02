<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Validator;

use Bluehost\VerifactiApi\Dto\AbstractInvoiceRequest;
use Bluehost\VerifactiApi\Dto\BulkInvoiceCreateRequest;
use Bluehost\VerifactiApi\Dto\InvoiceCancelRequest;
use Bluehost\VerifactiApi\Dto\InvoiceLine;
use Bluehost\VerifactiApi\Dto\InvoiceListRequest;
use Bluehost\VerifactiApi\Dto\InvoiceModifyRequest;
use Bluehost\VerifactiApi\Dto\InvoiceStatusLookupRequest;
use Bluehost\VerifactiApi\Dto\RecordStatusLookupRequest;
use Bluehost\VerifactiApi\Dto\XmlDownloadRequest;
use Bluehost\VerifactiApi\Dto\XmlExportRequest;
use Bluehost\VerifactiApi\Exception\ValidationException;
use Bluehost\VerifactiApi\Support\DateHelper;

/**
 * Validates request DTO payloads before they are sent to the API.
 */
final class RequestValidator
{
    /**
     * Validate a record status lookup request.
     *
     * @param RecordStatusLookupRequest $request Lookup request.
     *
     * @return void
     *
     * @throws ValidationException When validation fails.
     */
    public function validateRecordStatusLookup(RecordStatusLookupRequest $request): void
    {
        $this->throwIfErrors($this->collectNotBlankErrors([
            'uuid' => $request->getUuid(),
        ]));
    }

    /**
     * Validate an invoice status lookup request.
     *
     * @param InvoiceStatusLookupRequest $request Lookup request.
     *
     * @return void
     *
     * @throws ValidationException When validation fails.
     */
    public function validateInvoiceStatusLookup(InvoiceStatusLookupRequest $request): void
    {
        $payload = $request->toArray();
        $errors = $this->collectInvoiceIdentityErrors($payload['serie'], $payload['numero'], $payload['fecha_expedicion']);

        if (isset($payload['fecha_operacion']) && !DateHelper::isValidApiDate((string) $payload['fecha_operacion'])) {
            $errors[] = 'fecha_operacion must use the d-m-Y format.';
        }

        $this->throwIfErrors($errors);
    }

    /**
     * Validate an invoice create request.
     *
     * @param AbstractInvoiceRequest $request Invoice payload.
     *
     * @return void
     *
     * @throws ValidationException When validation fails.
     */
    public function validateCreate(AbstractInvoiceRequest $request): void
    {
        $this->throwIfErrors($this->validateInvoicePayload($request));
    }

    /**
     * Validate an invoice modify request.
     *
     * @param InvoiceModifyRequest $request Modify payload.
     *
     * @return void
     *
     * @throws ValidationException When validation fails.
     */
    public function validateModify(InvoiceModifyRequest $request): void
    {
        $errors = $this->validateInvoicePayload($request);
        $previousRejectionStatus = $request->getPreviousRejectionStatus();

        if ($previousRejectionStatus !== null && !in_array($previousRejectionStatus, ['N', 'X', 'S'], true)) {
            $errors[] = 'rechazo_previo must be one of N, X or S.';
        }

        $this->throwIfErrors($errors);
    }

    /**
     * Validate a bulk invoice create request.
     *
     * @param BulkInvoiceCreateRequest $request Bulk create payload.
     *
     * @return void
     *
     * @throws ValidationException When validation fails.
     */
    public function validateBulkCreate(BulkInvoiceCreateRequest $request): void
    {
        $errors = [];
        $invoices = $request->getInvoices();
        $count = count($invoices);

        if ($count === 0) {
            $errors[] = 'At least one invoice is required for create_bulk.';
        }

        if ($count > 50) {
            $errors[] = 'create_bulk supports at most 50 invoices per request.';
        }

        foreach ($invoices as $index => $invoice) {
            foreach ($this->validateInvoicePayload($invoice) as $error) {
                $errors[] = sprintf('Invoice %d: %s', $index + 1, $error);
            }
        }

        $this->throwIfErrors($errors);
    }

    /**
     * Validate an invoice cancel request.
     *
     * @param InvoiceCancelRequest $request Cancel payload.
     *
     * @return void
     *
     * @throws ValidationException When validation fails.
     */
    public function validateCancel(InvoiceCancelRequest $request): void
    {
        $payload = $request->toArray();
        $errors = $this->collectInvoiceIdentityErrors(
            (string) $payload['serie'],
            (string) $payload['numero'],
            (string) $payload['fecha_expedicion']
        );

        if (isset($payload['rechazo_previo']) && !in_array($payload['rechazo_previo'], ['N', 'X', 'S'], true)) {
            $errors[] = 'rechazo_previo must be one of N, X or S.';
        }

        $this->throwIfErrors($errors);
    }

    /**
     * Validate an invoice list request.
     *
     * @param InvoiceListRequest $request List filters.
     *
     * @return void
     *
     * @throws ValidationException When validation fails.
     */
    public function validateList(InvoiceListRequest $request): void
    {
        $payload = $request->toArray();
        $errors = $this->collectNotBlankErrors([
            'ejercicio' => (string) $payload['ejercicio'],
            'periodo' => (string) $payload['periodo'],
        ]);

        if (isset($payload['numero']) && !isset($payload['serie'])) {
            $errors[] = 'serie is required when numero is present.';
        }

        if (isset($payload['fecha_expedicion']) && !DateHelper::isValidApiDate((string) $payload['fecha_expedicion'])) {
            $errors[] = 'fecha_expedicion must use the d-m-Y format.';
        }

        $this->throwIfErrors($errors);
    }

    /**
     * Validate an XML export request.
     *
     * @param XmlExportRequest $request Export request.
     *
     * @return void
     *
     * @throws ValidationException When validation fails.
     */
    public function validateExport(XmlExportRequest $request): void
    {
        $this->throwIfErrors($this->collectNotBlankErrors($request->toArray()));
    }

    /**
     * Validate an XML download request.
     *
     * @param XmlDownloadRequest $request Download request.
     *
     * @return void
     *
     * @throws ValidationException When validation fails.
     */
    public function validateDownload(XmlDownloadRequest $request): void
    {
        $this->throwIfErrors($this->collectNotBlankErrors([
            'numero' => $request->toArray()['numero'],
        ]));
    }

    /**
     * Collect validation errors for a full invoice payload.
     *
     * @param AbstractInvoiceRequest $request Invoice payload.
     *
     * @return array<int, string>
     */
    private function validateInvoicePayload(AbstractInvoiceRequest $request): array
    {
        $errors = $this->collectInvoiceIdentityErrors(
            $request->getSeries(),
            $request->getNumber(),
            $request->getIssueDate()
        );

        $errors = array_merge($errors, $this->collectNotBlankErrors([
            'tipo_factura' => $request->getInvoiceType(),
            'descripcion' => $request->getDescription(),
            'importe_total' => $request->getTotalAmount(),
        ]));

        if (!DateHelper::isToday($request->getIssueDate())) {
            $errors[] = 'fecha_expedicion must match the current date.';
        }

        $lines = $request->getLines();
        if ($lines === []) {
            $errors[] = 'At least one invoice line is required.';
        }

        if (count($lines) > 12) {
            $errors[] = 'A maximum of 12 invoice lines is allowed.';
        }

        foreach ($lines as $index => $line) {
            foreach ($this->validateLine($line) as $lineError) {
                $errors[] = sprintf('lineas[%d]: %s', $index, $lineError);
            }
        }

        if ($request->getOperationDate() !== null && !DateHelper::isValidApiDate($request->getOperationDate())) {
            $errors[] = 'fecha_operacion must use the d-m-Y format.';
        }

        $invoiceType = strtoupper($request->getInvoiceType());
        $isRectificative = str_starts_with($invoiceType, 'R');

        if ($isRectificative && $request->getRectificationType() === null) {
            $errors[] = 'tipo_rectificativa is required for rectificative invoices.';
        }

        if ($request->getRectificationType() === 'S' && $request->getRectificationAmounts() === null) {
            $errors[] = 'importe_rectificativa is required when tipo_rectificativa is S.';
        }

        return $errors;
    }

    /**
     * Collect validation errors for a single invoice line.
     *
     * @param InvoiceLine $line Invoice line payload.
     *
     * @return array<int, string>
     */
    private function validateLine(InvoiceLine $line): array
    {
        $payload = $line->toArray();
        $errors = $this->collectNotBlankErrors([
            'base_imponible' => (string) ($payload['base_imponible'] ?? ''),
        ]);

        $hasTax = isset($payload['tipo_impositivo']) && isset($payload['cuota_repercutida']);
        $hasSpecialClassification = isset($payload['operacion_exenta']) || isset($payload['calificacion_operacion']);

        if (!$hasTax && !$hasSpecialClassification) {
            $errors[] = 'Either tax values or an exempt/non-subject classification must be provided.';
        }

        return $errors;
    }

    /**
     * Collect validation errors for invoice identity fields.
     *
     * @param string $series    Invoice series.
     * @param string $number    Invoice number.
     * @param string $issueDate Issue date.
     *
     * @return array<int, string>
     */
    private function collectInvoiceIdentityErrors(string $series, string $number, string $issueDate): array
    {
        $errors = $this->collectNotBlankErrors([
            'numero' => $number,
            'fecha_expedicion' => $issueDate,
        ]);

        if (!DateHelper::isValidApiDate($issueDate)) {
            $errors[] = 'fecha_expedicion must use the d-m-Y format.';
        }

        return $errors;
    }

    /**
     * Collect errors for fields that must not be blank.
     *
     * @param array<string, string> $values Field values keyed by field name.
     *
     * @return array<int, string>
     */
    private function collectNotBlankErrors(array $values): array
    {
        $errors = [];

        foreach ($values as $field => $value) {
            if (trim($value) === '') {
                $errors[] = sprintf('%s cannot be empty.', $field);
            }
        }

        return $errors;
    }

    /**
     * Throw a validation exception when errors are present.
     *
     * @param array<int, string> $errors Validation error messages.
     *
     * @return void
     *
     * @throws ValidationException When one or more errors are present.
     */
    private function throwIfErrors(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('The request payload is invalid.', $errors);
        }
    }
}
