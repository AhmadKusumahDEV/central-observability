<?php

declare(strict_types=1);

/**
 * Proses kecil yang hidup terus untuk mengirim app.heartbeat.timestamp.
 * PHP-FPM membuat proses per request, jadi heartbeat tidak boleh ditaruh di
 * middleware: nilainya akan berhenti saat tidak ada request.
 *
 * Jalankan: php bin/heartbeat.php
 */

use Observability\Telemetry\Telemetry;

require dirname(__DIR__) . '/vendor/autoload.php';

$telemetry = Telemetry::boot(registerShutdown: false);
$intervalMs = max(1000, $telemetry->config->metricExportIntervalMillis);

$telemetry->logger()->info('heartbeat process started', [
    'pid' => getmypid(),
    'interval_ms' => $intervalMs,
    'endpoint' => $telemetry->config->endpointFor('metrics'),
]);

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function (int $signal) use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

while ($running) {
    // forceFlush membuat ExportingReader membaca gauge lalu mengirim datanya.
    $telemetry->flush();
    usleep($intervalMs * 1000);
}

$telemetry->logger()->info('heartbeat process stopped', ['pid' => getmypid()]);
$telemetry->flush();
$telemetry->shutdown();
