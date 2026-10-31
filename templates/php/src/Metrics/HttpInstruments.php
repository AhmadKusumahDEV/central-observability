<?php

declare(strict_types=1);

namespace Observability\Metrics;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;

/**
 * Histogram durasi request HTTP. Nama dan atributnya sama dengan template
 * bahasa lain supaya dashboard dan alert bisa dipakai ulang.
 */
final class HttpInstruments
{
    public const HISTOGRAM_NAME = 'http.server.request.duration';

    /** Batas bucket dalam detik, mengikuti konvensi OpenTelemetry. */
    public const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 10];

    private ?HistogramInterface $duration = null;

    public function __construct(private readonly MeterInterface $meter)
    {
    }

    public function record(string $method, string $route, int $status, float $seconds): void
    {
        $this->duration ??= $this->meter->createHistogram(
            self::HISTOGRAM_NAME,
            's',
            'Durasi request HTTP server',
            ['ExplicitBucketBoundaries' => self::BUCKETS],
        );

        $this->duration->record(max(0.0, $seconds), [
            'http.request.method' => $method,
            'http.route' => $route,
            'http.response.status_code' => $status,
        ]);
    }
}
