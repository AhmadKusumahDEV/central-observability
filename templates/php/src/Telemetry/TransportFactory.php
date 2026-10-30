<?php

declare(strict_types=1);

namespace Observability\Telemetry;

use Observability\Config\TelemetryConfig;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use RuntimeException;

use function class_exists;
use function extension_loaded;
use function sprintf;

/**
 * Membuat transport OTLP untuk satu sinyal (traces, metrics, atau logs).
 */
final class TransportFactory implements TransportProvider
{
    /** @var array<string, TransportInterface> */
    private array $transports = [];

    public function __construct(private readonly TelemetryConfig $config)
    {
    }

    public function forSignal(string $signal): TransportInterface
    {
        return $this->transports[$signal] ??= $this->create($signal);
    }

    private function create(string $signal): TransportInterface
    {
        if ($this->config->protocol === TelemetryConfig::PROTOCOL_GRPC) {
            return $this->createGrpc($signal);
        }

        return (new OtlpHttpTransportFactory())->create(
            endpoint: $this->config->endpointFor($signal),
            contentType: ContentTypes::PROTOBUF,
            headers: $this->config->headers,
            compression: $this->config->compression,
            timeout: $this->config->timeoutSeconds,
            retryDelay: 200,
            maxRetries: 3,
        );
    }

    private function createGrpc(string $signal): TransportInterface
    {
        if (!extension_loaded('grpc')) {
            throw new RuntimeException(
                'OTEL_EXPORTER_OTLP_PROTOCOL=grpc butuh ekstensi PHP grpc. ' .
                'Pakai OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf bila ekstensi belum ada.',
            );
        }

        $class = 'OpenTelemetry\\Contrib\\Otlp\\OtlpGrpcTransportFactory';
        if (!class_exists($class)) {
            throw new RuntimeException(sprintf(
                'Transport gRPC belum terpasang. Jalankan: composer require open-telemetry/transport-grpc. ' .
                'Atau pakai http/protobuf untuk mengirim ke %s.',
                $this->config->endpointFor($signal),
            ));
        }

        /** @var object $factory */
        $factory = new $class();

        return $factory->create(
            endpoint: $this->config->grpcTarget(),
            contentType: ContentTypes::PROTOBUF,
            headers: $this->config->headers,
            compression: $this->config->compression,
            timeout: $this->config->timeoutSeconds,
        );
    }
}
