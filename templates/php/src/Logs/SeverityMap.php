<?php

declare(strict_types=1);

namespace Observability\Logs;

use OpenTelemetry\API\Logs\Severity;

use function strtoupper;

/**
 * Menerjemahkan level PSR-3 menjadi SeverityNumber dan SeverityText
 * OpenTelemetry. SeverityNumber dipakai Loki dan Grafana untuk memfilter log.
 */
final class SeverityMap
{
    /** @var array<string, int> */
    private const NUMBERS = [
        'debug' => Severity::DEBUG->value,
        'info' => Severity::INFO->value,
        'notice' => Severity::INFO2->value,
        'warning' => Severity::WARN->value,
        'error' => Severity::ERROR->value,
        'critical' => Severity::FATAL->value,
        'alert' => Severity::FATAL2->value,
        'emergency' => Severity::FATAL4->value,
    ];

    public static function number(string $level): int
    {
        return self::NUMBERS[$level] ?? Severity::INFO->value;
    }

    public static function text(string $level): string
    {
        return strtoupper($level);
    }

    /**
     * Urutan level untuk filter minimum di sisi aplikasi.
     */
    public static function weight(string $level): int
    {
        return self::NUMBERS[$level] ?? Severity::INFO->value;
    }

    public static function parseMinimum(string $level): string
    {
        $normalized = strtolower(trim($level));

        return isset(self::NUMBERS[$normalized]) ? $normalized : 'info';
    }
}
