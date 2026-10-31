<?php

declare(strict_types=1);

namespace Observability\Config;

use InvalidArgumentException;

use function explode;
use function getenv;
use function in_array;
use function max;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

/**
 * Konfigurasi telemetry yang dibaca dari environment variable standar
 * OpenTelemetry. Semua nilai punya default yang aman supaya aplikasi tetap
 * jalan walau environment variable belum diisi.
 */
final class TelemetryConfig
{
    public const PROTOCOL_GRPC = 'grpc';
    public const PROTOCOL_HTTP_PROTOBUF = 'http/protobuf';

    public const DEFAULT_HTTP_ENDPOINT = 'http://otel-collector:4318';
    public const DEFAULT_GRPC_ENDPOINT = 'http://otel-collector:4317';

    private const SIGNAL_ENDPOINT_ENV = [
        'traces' => 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT',
        'metrics' => 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT',
        'logs' => 'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT',
    ];

    private const SIGNAL_PATH = [
        'traces' => '/v1/traces',
        'metrics' => '/v1/metrics',
        'logs' => '/v1/logs',
    ];

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $resourceAttributes
     */
    private function __construct(
        public readonly string $serviceName,
        public readonly string $environment,
        public readonly string $serviceVersion,
        public readonly string $serviceNamespace,
        public readonly string $endpoint,
        public readonly string $protocol,
        public readonly array $headers,
        public readonly string $compression,
        public readonly float $timeoutSeconds,
        public readonly int $metricExportIntervalMillis,
        public readonly int $batchDelayMillis,
        public readonly string $logLevel,
        public readonly bool $disabled,
        public readonly array $resourceAttributes,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return self::fromArray(self::readEnvironment());
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromArray(array $env): self
    {
        $resourceAttributes = self::parseKeyValueList($env['OTEL_RESOURCE_ATTRIBUTES'] ?? '');

        $serviceName = trim($env['OTEL_SERVICE_NAME'] ?? '');
        if ($serviceName === '') {
            $serviceName = $resourceAttributes['service.name'] ?? 'unknown-service';
        }

        $protocol = strtolower(trim($env['OTEL_EXPORTER_OTLP_PROTOCOL'] ?? self::PROTOCOL_HTTP_PROTOBUF));
        if ($protocol === '') {
            $protocol = self::PROTOCOL_HTTP_PROTOBUF;
        }
        if (!in_array($protocol, [self::PROTOCOL_GRPC, self::PROTOCOL_HTTP_PROTOBUF], true)) {
            throw new InvalidArgumentException(sprintf(
                'OTEL_EXPORTER_OTLP_PROTOCOL=%s belum didukung template ini. Pakai %s atau %s.',
                $protocol,
                self::PROTOCOL_HTTP_PROTOBUF,
                self::PROTOCOL_GRPC,
            ));
        }

        $defaultEndpoint = $protocol === self::PROTOCOL_GRPC
            ? self::DEFAULT_GRPC_ENDPOINT
            : self::DEFAULT_HTTP_ENDPOINT;

        $endpoint = trim($env['OTEL_EXPORTER_OTLP_ENDPOINT'] ?? '');
        if ($endpoint === '') {
            $endpoint = $defaultEndpoint;
        }

        $compression = strtolower(trim($env['OTEL_EXPORTER_OTLP_COMPRESSION'] ?? 'none'));
        if ($compression === '') {
            $compression = 'none';
        }

        $interval = (int) ($env['OTEL_METRIC_EXPORT_INTERVAL'] ?? 10000);
        $interval = max(1000, $interval);

        return new self(
            serviceName: $serviceName,
            environment: trim($resourceAttributes['deployment.environment.name'] ?? 'unknown'),
            serviceVersion: trim($resourceAttributes['service.version'] ?? 'unknown'),
            serviceNamespace: trim($resourceAttributes['service.namespace'] ?? ''),
            endpoint: $endpoint,
            protocol: $protocol,
            headers: self::parseKeyValueList($env['OTEL_EXPORTER_OTLP_HEADERS'] ?? ''),
            compression: $compression,
            timeoutSeconds: max(1.0, (float) ($env['OTEL_EXPORTER_OTLP_TIMEOUT'] ?? 10000) / 1000.0),
            metricExportIntervalMillis: $interval,
            batchDelayMillis: max(1000, (int) ($env['OTEL_BSP_SCHEDULE_DELAY'] ?? 5000)),
            logLevel: strtoupper(trim($env['OTEL_LOG_LEVEL'] ?? 'INFO')),
            disabled: self::isTruthy($env['OTEL_SDK_DISABLED'] ?? 'false'),
            resourceAttributes: $resourceAttributes,
        );
    }

    /**
     * Endpoint lengkap untuk satu sinyal. OTLP/HTTP butuh path /v1/traces dan
     * seterusnya, sedangkan OTLP/gRPC memakai alamat host:port yang sama.
     */
    public function endpointFor(string $signal): string
    {
        $envKey = self::SIGNAL_ENDPOINT_ENV[$signal] ?? null;
        if ($envKey !== null) {
            $override = trim((string) (self::readEnvironment()[$envKey] ?? ''));
            if ($override !== '') {
                return $override;
            }
        }

        if ($this->protocol === self::PROTOCOL_GRPC) {
            return $this->grpcTarget();
        }

        return rtrim($this->endpoint, '/') . (self::SIGNAL_PATH[$signal] ?? '');
    }

    /**
     * Alamat gRPC tidak memakai skema http:// maupun path.
     */
    public function grpcTarget(): string
    {
        $endpoint = $this->endpoint;
        foreach (['http://', 'https://'] as $scheme) {
            if (str_starts_with($endpoint, $scheme)) {
                $endpoint = substr($endpoint, strlen($scheme));
            }
        }

        $slash = strpos($endpoint, '/');
        if ($slash !== false) {
            $endpoint = substr($endpoint, 0, $slash);
        }

        return $endpoint;
    }

    /**
     * @return array<string, string>
     */
    private static function readEnvironment(): array
    {
        $env = [];
        foreach (['OTEL_SERVICE_NAME', 'OTEL_RESOURCE_ATTRIBUTES', 'OTEL_EXPORTER_OTLP_ENDPOINT',
            'OTEL_EXPORTER_OTLP_PROTOCOL', 'OTEL_EXPORTER_OTLP_HEADERS', 'OTEL_EXPORTER_OTLP_COMPRESSION',
            'OTEL_EXPORTER_OTLP_TIMEOUT', 'OTEL_METRIC_EXPORT_INTERVAL', 'OTEL_BSP_SCHEDULE_DELAY',
            'OTEL_LOG_LEVEL', 'OTEL_SDK_DISABLED', 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT',
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT', 'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * Format daftar kunci=nilai dipisah koma, misalnya
     * deployment.environment.name=prod,service.version=1.4.0.
     *
     * @return array<string, string>
     */
    private static function parseKeyValueList(string $value): array
    {
        $result = [];
        foreach (explode(',', $value) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }

            [$key, $item] = explode('=', $pair, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $result[$key] = trim($item);
        }

        return $result;
    }

    private static function isTruthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
