<?php

declare(strict_types=1);

/**
 * Aplikasi contoh tanpa framework. Jalankan dari folder templates/php:
 *   php -S 127.0.0.1:8080 -t examples/plain/public
 * Lalu buka /healthz, /work, /slow, /outbound, /error.
 */

use Observability\Propagation\TraceContext;
use Observability\Telemetry\Telemetry;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$telemetry = Telemetry::boot();
$http = $telemetry->httpServer();
$logger = $telemetry->logger();
$client = $telemetry->httpClient();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';

header('Content-Type: application/json');

try {
    $http->handle($method, $path, static function () use ($path, $logger, $client): void {
        switch ($path) {
            case '/healthz':
                http_response_code(200);
                echo json_encode(['status' => 'ok']);

                return;

            case '/work':
                $logger->info('menjalankan pekerjaan contoh', ['job' => 'report', 'items' => 42]);
                http_response_code(200);
                echo json_encode(['work' => 'done', 'items' => 42, 'trace_id' => TraceContext::currentTraceId()]);

                return;

            case '/slow':
                usleep(350000);
                $logger->warning('request lambat pada contoh', ['delay_ms' => 350]);
                http_response_code(200);
                echo json_encode(['slow' => true, 'trace_id' => TraceContext::currentTraceId()]);

                return;

            case '/outbound':
                $result = $client->request(
                    'GET',
                    'http://127.0.0.1:8080/healthz',
                    '/healthz',
                );
                http_response_code(200);
                echo json_encode([
                    'downstream_status' => $result['status'],
                    'trace_id' => TraceContext::currentTraceId(),
                ]);

                return;

            case '/error':
                throw new RuntimeException('contoh kegagalan yang disengaja');

            default:
                http_response_code(404);
                echo json_encode(['error' => 'not found', 'path' => $path]);
        }
    });
} catch (Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'error' => 'internal error',
        'message' => $exception->getMessage(),
        'trace_id' => TraceContext::currentTraceId(),
    ]);
}
