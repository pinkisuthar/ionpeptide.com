<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Dto;

use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Request payload for creating multiple invoices in one call.
 */
final class BulkInvoiceCreateRequest implements Arrayable
{
    /**
     * @param array<int, InvoiceCreateRequest> $invoices   Invoice payloads.
     * @param string|null                      $wrapperKey Optional wrapper key for the payload.
     */
    public function __construct(
        private array $invoices,
        private ?string $wrapperKey = 'facturas'
    ) {
    }

    /**
     * Return the invoice payloads.
     *
     * @return array<int, InvoiceCreateRequest>
     */
    public function getInvoices(): array
    {
        return $this->invoices;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        $payload = array_map(static function (InvoiceCreateRequest $invoice): array {
            return $invoice->toArray();
        }, $this->invoices);

        if ($this->wrapperKey === null || $this->wrapperKey === '') {
            return $payload;
        }

        return [
            $this->wrapperKey => $payload,
        ];
    }
}
