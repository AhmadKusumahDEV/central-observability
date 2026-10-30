<?php

declare(strict_types=1);

namespace Observability\Telemetry;

use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * Hasil pembuatan SDK: provider, logger OpenTelemetry, dan meter.
 */
final class BuiltTelemetry
{
    public function __construct(
        public readonly TracerProviderInterface $tracerProvider,
        public readonly MeterProviderInterface $meterProvider,
        public readonly LoggerProviderInterface $loggerProvider,
        public readonly int $metricExportIntervalMillis,
    ) {
    }

    public function flush(): void
    {
        $this->tracerProvider->forceFlush();
        $this->meterProvider->forceFlush();
        $this->loggerProvider->forceFlush();
    }

    public function shutdown(): void
    {
        $this->tracerProvider->shutdown();
        $this->meterProvider->shutdown();
        $this->loggerProvider->shutdown();
    }
}
