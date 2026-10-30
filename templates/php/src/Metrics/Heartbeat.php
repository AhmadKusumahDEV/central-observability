<?php

declare(strict_types=1);

namespace Observability\Metrics;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * Gauge app.heartbeat.timestamp berisi waktu Unix saat ini. Alert
 * "service down" membandingkan nilai ini dengan waktu sekarang, jadi nilai
 * harus terus bergerak selama proses hidup.
 */
final class Heartbeat
{
    public const METRIC_NAME = 'app.heartbeat.timestamp';

    /** Instrument disimpan supaya callback tidak ikut hilang saat di-GC. */
    private ?ObservableGaugeInterface $gauge = null;

    public function register(MeterInterface $meter): void
    {
        $this->gauge = $meter->createObservableGauge(
            self::METRIC_NAME,
            's',
            'Unix timestamp heartbeat terakhir aplikasi',
            [],
            static function (ObserverInterface $observer): void {
                $observer->observe(microtime(true));
            },
        );
    }
}
