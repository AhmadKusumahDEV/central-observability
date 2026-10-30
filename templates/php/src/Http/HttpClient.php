<?php

declare(strict_types=1);

namespace Observability\Http;

use Observability\Propagation\TraceContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use RuntimeException;
use Throwable;

use function curl_close;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_array;
use function is_string;
use function parse_url;

/**
 * Client HTTP keluar yang menambahkan span CLIENT dan header traceparent,
 * sehingga service tujuan melanjutkan trace yang sama.
 */
final class HttpClient
{
    public function __construct(private readonly TracerInterface $tracer)
    {
    }

    /**
     * @param array<int, mixed> $curlOptions Opsi tambahan untuk cURL.
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, ?string $routeTemplate = null, array $curlOptions = []): array
    {
        $method = strtoupper($method);
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        $route = RouteTemplateResolver::normalize($routeTemplate ?? (string) (parse_url($url, PHP_URL_PATH) ?? '/'));

        $span = $this->tracer->spanBuilder($method . ' ' . $host)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.request.method', $method)
            ->setAttribute('server.address', $host)
            ->setAttribute('url.template', $route)
            ->startSpan();

        $scope = $span->activate();

        $headers = ['traceparent' => ''];
        TraceContext::inject($headers);

        $handle = curl_init($url);
        if ($handle === false) {
            $scope->detach();
            $span->end();

            throw new RuntimeException('Gagal membuat handle cURL.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => self::headerLines($headers),
        ];

        foreach ($curlOptions as $key => $value) {
            $options[$key] = $value;
        }

        curl_setopt_array($handle, $options);

        try {
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if ($body === false) {
                $message = curl_error($handle);
                $span->setAttribute('error.type', 'curl_error');

                throw new RuntimeException('Request keluar gagal: ' . $message);
            }

            $span->setAttribute('http.response.status_code', $status);
            if ($status >= 500) {
                $span->setAttribute('error.type', 'http_' . $status);
            }

            return ['status' => $status, 'body' => is_string($body) ? $body : ''];
        } catch (Throwable $exception) {
            $span->recordException($exception);

            throw $exception;
        } finally {
            curl_close($handle);
            $span->end();
            $scope->detach();
        }
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $value = (string) ($value[0] ?? '');
            }
            if ($value === '') {
                continue;
            }

            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
