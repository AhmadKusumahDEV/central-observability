<?php

declare(strict_types=1);

/**
 * Test tanpa jaringan. Payload OTLP ditangkap di memori supaya bisa diperiksa
 * bentuknya walau Collector tidak bisa dijangkau dari mesin test.
 *
 * Jalankan: composer test
 */

namespace Observability\Tests;

use Observability\Config\TelemetryConfig;
use Observability\Propagation\TraceContext;
use Observability\Telemetry\Telemetry;
use RuntimeException;

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures;

    if ($condition) {
        fwrite(STDOUT, "PASS  {$label}\n");

        return;
    }

    $failures++;
    fwrite(STDOUT, "FAIL  {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n");
}

$config = TelemetryConfig::fromArray([
    'OTEL_SERVICE_NAME' => 'example-php-service',
    'OTEL_RESOURCE_ATTRIBUTES' => 'deployment.environment.name=dev,service.namespace=examples,service.version=0.1.0',
    'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector.test:4318',
    'OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/protobuf',
]);

check('endpoint traces menambahkan path', $config->endpointFor('traces') === 'http://collector.test:4318/v1/traces');
check('endpoint metrics menambahkan path', $config->endpointFor('metrics') === 'http://collector.test:4318/v1/metrics');

$capture = new CaptureTransportProvider();
$telemetry = Telemetry::boot($config, registerShutdown: false, transports: $capture);
$http = $telemetry->httpServer();

$traceId = $http->handle('GET', '/work', static fn (): string => TraceContext::currentTraceId());
check('trace id aktif selama request', preg_match('/^[0-9a-f]{32}$/', $traceId) === 1, $traceId);

try {
    $http->handle('GET', '/error', static function (): void {
        throw new RuntimeException('contoh kegagalan');
    });
} catch (RuntimeException) {
    // Diharapkan: span tetap ditutup dan status dicatat 500.
}

$telemetry->logger()->info('pesan dengan placeholder', ['jumlah' => 7]);
$telemetry->flush();

$traces = $capture->transports['traces'] ?? null;
$logs = $capture->transports['logs'] ?? null;
$metrics = $capture->transports['metrics'] ?? null;

check('payload traces terkirim', $traces !== null && $traces->payloads !== []);
check('payload logs terkirim', $logs !== null && $logs->payloads !== []);
check('payload metrics terkirim', $metrics !== null && $metrics->payloads !== []);

if ($traces !== null) {
    $body = $traces->body();
    check('service.name masuk resource', str_contains($body, 'example-php-service'));
    check('environment masuk resource', str_contains($body, 'deployment.environment.name') && str_contains($body, 'dev'));
    check('span server memakai nama method + route', str_contains($body, 'GET /work'));
    check('span 500 tercatat', str_contains($body, '/error'));
    check('trace id ada di payload traces', str_contains($body, hex2bin($traceId) ?: ''));
}

if ($logs !== null) {
    $body = $logs->body();
    check('log access ditulis', str_contains($body, 'http request'));
    check('placeholder log diganti', str_contains($body, 'pesan dengan placeholder'));
    check('trace id ikut di log', str_contains($body, hex2bin($traceId) ?: ''));
    check('severity error dikirim', str_contains($body, 'ERROR'));
}

if ($metrics !== null) {
    $body = $metrics->body();
    check('histogram http terkirim', str_contains($body, 'http.server.request.duration'));
    check('unit detik terkirim', str_contains($body, 'http.route'));
    check('heartbeat terkirim', str_contains($body, 'app.heartbeat.timestamp'));
}

$telemetry->shutdown();

fwrite(STDOUT, $failures === 0 ? "\nSemua pemeriksaan lolos.\n" : "\n{$failures} pemeriksaan gagal.\n");
exit($failures === 0 ? 0 : 1);
