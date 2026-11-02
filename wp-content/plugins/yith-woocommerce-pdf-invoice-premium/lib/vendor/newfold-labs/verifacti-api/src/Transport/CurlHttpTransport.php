<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Transport;

use Bluehost\VerifactiApi\Config\VerifactiConfig;
use Bluehost\VerifactiApi\Exception\ConfigurationException;
use Bluehost\VerifactiApi\Exception\TransportException;
use Bluehost\VerifactiApi\Support\HttpHeaderHelper;

/**
 * Default cURL-based HTTP transport for the Verifacti API client.
 */
final class CurlHttpTransport implements HttpTransportInterface
{
    private const MAX_RESPONSE_BYTES = 8388608;
    private const CURLE_FILESIZE_EXCEEDED = 63;

    /**
     * {@inheritDoc}
     */
    public function send(HttpRequest $request, VerifactiConfig $config): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new ConfigurationException('cURL is required for CurlHttpTransport.');
        }

        $headers = [];
        $url = $this->buildUrl($config->getBaseUrl(), $request);

        foreach ($request->getHeaders() as $name => $value) {
            $headers[] = sprintf(
                '%s: %s',
                HttpHeaderHelper::sanitize($name),
                HttpHeaderHelper::sanitize($value)
            );
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TransportException('Unable to initialize the cURL transport.');
        }

        $timeout = $request->getTimeoutSeconds();
        $connectTimeout = $timeout > 10 ? 10 : $timeout;

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);

                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return strlen($headerLine);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);

                return strlen($headerLine);
            },
        ]);

        if ($request->getBody() !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request->getBody());
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $message = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($errno === self::CURLE_FILESIZE_EXCEEDED) {
                throw new TransportException(
                    sprintf(
                        'The Verifacti API response exceeded the maximum allowed size of %d bytes.',
                        self::MAX_RESPONSE_BYTES
                    )
                );
            }

            throw new TransportException(sprintf('cURL transport error [%d]: %s', $errno, $message));
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $metadata = [
            'effective_url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            'total_time' => (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
        ];

        curl_close($ch);

        return new HttpResponse($statusCode, $responseHeaders, (string) $body, $metadata);
    }

    /**
     * Build the absolute request URL from the configured base URL and request path.
     *
     * @param string      $baseUrl Configured API base URL.
     * @param HttpRequest $request Outbound request.
     *
     * @return string
     */
    private function buildUrl(string $baseUrl, HttpRequest $request): string
    {
        $path = '/' . ltrim($request->getPath(), '/');
        $url = $baseUrl . $path;
        $query = $request->getQuery();

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
