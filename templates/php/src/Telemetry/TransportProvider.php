<?php

declare(strict_types=1);

namespace Observability\Telemetry;

use OpenTelemetry\SDK\Common\Export\TransportInterface;

/**
 * Sumber transport OTLP per sinyal. Dipisah dari TransportFactory supaya test
 * bisa mengganti jaringan dengan penerima palsu di dalam proses.
 */
interface TransportProvider
{
    /**
     * @param string $signal traces, metrics, atau logs
     */
    public function forSignal(string $signal): TransportInterface;
}
