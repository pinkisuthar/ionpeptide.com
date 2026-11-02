# Bluehost Verifacti API

Framework-agnostic PHP 8.0+ library for integrating the Verifacti REST API for Spanish electronic invoicing.

- PSR-4 autoloading and Composer-friendly structure
- cURL-based default HTTP transport behind an adapter interface
- Service-oriented client facade for the documented Verifacti endpoints
- Immutable DTOs and builders for invoice payloads
- Pre-flight validation with typed validation exceptions
- Typed exception hierarchy for config, transport, HTTP, auth, API, and serialization failures
- Raw response preservation for troubleshooting and future response mapping

## Supported endpoints

- `GET /verifactu/health`
- `GET /verifactu/status`
- `POST /verifactu/status`
- `POST /verifactu/create`
- `POST /verifactu/create_bulk`
- `PUT /verifactu/modify`
- `POST /verifactu/cancel`
- `POST /verifactu/list`
- `POST /verifactu/export`
- `POST /verifactu/downloadXML`
- `GET /verifactu/declaracion`

## Installation

In your composer.json file add these lines:
```bash
"repositories": {
    "newfold": {
      "type": "composer",
      "url": "https://newfold-labs.github.io/satis/",
      "only": [
        "newfold-labs/*"
      ]
    }
  },
    "require": {
    "newfold-labs/verifacti-api": "0.2.0"
  },
```

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Bluehost\VerifactiApi\Builder\InvoiceBuilder;
use Bluehost\VerifactiApi\Builder\InvoiceLineBuilder;
use Bluehost\VerifactiApi\Client\ClientFactory;

$client = ClientFactory::fromApiKey('your-api-key', [
    'timeout' => 30,
    'environment' => 'production',
]);

$invoice = (new InvoiceBuilder())
    ->withSeries('A')
    ->withNumber('1001')
    ->withIssueDate(date('d-m-Y'))
    ->withInvoiceType('F1')
    ->withDescription('Component sales')
    ->withRecipient('A15022510', 'Example customer')
    ->addLine(
        (new InvoiceLineBuilder())
            ->withTaxableBase('200')
            ->withTax('21', '42')
            ->build()
    )
    ->withTotalAmount('242')
    ->buildCreate();

$response = $client->createInvoice($invoice);

echo $response->getUuid() . PHP_EOL;
echo $response->getStatus() . PHP_EOL;
```

## Configuration

```php
use Bluehost\VerifactiApi\Client\ClientFactory;

$client = ClientFactory::fromApiKey('your-api-key', [
    'timeout' => 20,
    'environment' => 'test',
]);
```

Supported config keys:

- `api_key`
- `timeout`
- `environment`

## Public API

### Health check

```php
$health = $client->healthCheck();
```

### GET record status by UUID

```php
use Bluehost\VerifactiApi\Dto\RecordStatusLookupRequest;

$status = $client->getRecordStatus(new RecordStatusLookupRequest('uuid-from-create'));
```

### POST invoice status lookup

```php
use Bluehost\VerifactiApi\Dto\InvoiceStatusLookupRequest;

$status = $client->getInvoiceStatus(
    new InvoiceStatusLookupRequest('A', '1001', '25-05-2026')
);
```

### Create invoice

```php
$result = $client->createInvoice($invoice, 'custom-idempotency-key');
```

### Create invoices in bulk

```php
use Bluehost\VerifactiApi\Dto\BulkInvoiceCreateRequest;

$bulk = new BulkInvoiceCreateRequest([$invoiceA, $invoiceB]);
$result = $client->createBulkInvoices($bulk);
```

### Modify invoice

```php
$modify = (new InvoiceBuilder())
    ->withSeries('A')
    ->withNumber('1001')
    ->withIssueDate('24-02-2025')
    ->withInvoiceType('F1')
    ->withDescription('Updated description')
    ->withRecipient('A15022510', 'Example customer')
    ->withPreviousRejectionStatus('N')
    ->addLine(
        (new InvoiceLineBuilder())
            ->withTaxableBase('200')
            ->withTax('21', '42')
            ->build()
    )
    ->withTotalAmount('242')
    ->buildModify();

$result = $client->modifyInvoice($modify);
```

### Cancel invoice

```php
use Bluehost\VerifactiApi\Dto\InvoiceCancelRequest;

$result = $client->cancelInvoice(
    new InvoiceCancelRequest('A', '1001', '25-02-2025')
);
```

### List invoices

```php
use Bluehost\VerifactiApi\Builder\PaginationBuilder;
use Bluehost\VerifactiApi\Dto\InvoiceListRequest;

$pagination = (new PaginationBuilder())
    ->withField('token', 'opaque-cursor')
    ->build();

$result = $client->listInvoices(
    new InvoiceListRequest('2026', '05', null, null, null, null, $pagination)
);
```

### Export XML metadata

```php
use Bluehost\VerifactiApi\Dto\XmlExportRequest;

$result = $client->exportXml(new XmlExportRequest('2026', '05'));
```

### Download XML files

```php
use Bluehost\VerifactiApi\Dto\XmlDownloadRequest;

$result = $client->downloadXml(new XmlDownloadRequest('A', '1001'));
```

### Fetch declaration

```php
$declaration = $client->fetchDeclaration();
```

## Builders

### Invoice line builder

```php
$line = (new InvoiceLineBuilder())
    ->withTaxableBase('200')
    ->withTax('21', '42')
    ->build();
```

### Corrective invoice builder

```php
use Bluehost\VerifactiApi\Builder\CorrectiveInvoiceBuilder;
use Bluehost\VerifactiApi\Builder\InvoiceReferenceBuilder;

$corrective = (new CorrectiveInvoiceBuilder())
    ->bySubstitution()
    ->withRectifiedAmounts('0', '0')
    ->addCorrectedInvoice(
        (new InvoiceReferenceBuilder())
            ->withSeries('A')
            ->withNumber('1')
            ->withIssueDate('07-04-2025')
            ->build()
    );
```

### Special and pagination objects

Because the public documentation does not fully document the inner shape of the API fields `especial`, `rango_fecha_expedicion`, and `paginacion`, the library models them as typed wrapper objects around associative arrays so you can stay compatible with future documented variants without changing the library core.

## Exception hierarchy

- `VerifactiException`
- `ConfigurationException`
- `ValidationException`
- `TransportException`
- `HttpException`
- `AuthenticationException`
- `ApiException`
- `SerializationException`

## Testing

```bash
composer test
```

## Documented assumptions

The public Verifacti documentation currently leaves some important integration details underspecified. This library makes only the following explicit assumptions:

1. Authentication is fixed to `Authorization: Bearer <API_KEY>` and is not configurable.
2. The API base URL is fixed to `https://api.verifacti.com` and is not configurable.
3. `GET /verifactu/status` is modeled with a `uuid` query parameter because the quick-start documentation states that the create call returns a `uuid` used to query record status.
4. `POST /verifactu/create_bulk` defaults to the payload wrapper `{"facturas": [...]}` because the endpoint body shape is not exposed in the public docs. You can change the wrapper key or disable it in `BulkInvoiceCreateRequest`.
5. Success responses are decoded as JSON when the payload or content type looks like JSON; otherwise the raw body is preserved in `ApiResponse`.

## Project structure

```text
verifacti-api/
├── composer.json
├── phpunit.xml.dist
├── README.md
├── src/
│   ├── Builder/
│   ├── Client/
│   ├── Config/
│   ├── Dto/
│   ├── Exception/
│   ├── Serializer/
│   ├── Service/
│   ├── Support/
│   ├── Transport/
│   └── Validator/
└── tests/
    ├── Builder/
    └── Validator/
```
