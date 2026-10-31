<?php

declare(strict_types=1);

namespace Observability\Telemetry;

use Observability\Config\TelemetryConfig;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\WithSampledTraceExemplarFilter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Merakit provider traces, metrics, dan logs dari konfigurasi yang sama.
 */
final class SdkFactory
{
    public static function build(TelemetryConfig $config, ?TransportProvider $transports = null): BuiltTelemetry
    {
        $transport = $transports ?? new TransportFactory($config);
        $resource = self::resource($config);

        $tracerProvider = TracerProvider::builder()
            ->setResource($resource)
            ->setSampler(new ParentBased(new AlwaysOnSampler()))
            ->addSpanProcessor(new BatchSpanProcessor(
                exporter: new SpanExporter($transport->forSignal('traces')),
                clock: ClockFactory::getDefault(),
                scheduledDelayMillis: $config->batchDelayMillis,
                exportTimeoutMillis: 10000,
                autoFlush: false,
            ))
            ->build();

        /** @var MetricExporterInterface $metricExporter */
        $metricExporter = new MetricExporter($transport->forSignal('metrics'));

        $meterProvider = MeterProvider::builder()
            ->setResource($resource)
            ->setExemplarFilter(new WithSampledTraceExemplarFilter())
            ->addReader(new ExportingReader($metricExporter))
            ->build();

        $loggerProvider = LoggerProvider::builder()
            ->setResource($resource)
            ->addLogRecordProcessor(new BatchLogRecordProcessor(
                exporter: new LogsExporter($transport->forSignal('logs')),
                clock: ClockFactory::getDefault(),
                scheduledDelayMillis: $config->batchDelayMillis,
                exportTimeoutMillis: 10000,
                autoFlush: false,
            ))
            ->build();

        return new BuiltTelemetry(
            tracerProvider: $tracerProvider,
            meterProvider: $meterProvider,
            loggerProvider: $loggerProvider,
            metricExportIntervalMillis: $config->metricExportIntervalMillis,
        );
    }

    private static function resource(TelemetryConfig $config): ResourceInfo
    {
        $attributes = [
            'service.name' => $config->serviceName,
            'service.version' => $config->serviceVersion,
            'deployment.environment.name' => $config->environment,
            'telemetry.sdk.language' => 'php',
        ];

        if ($config->serviceNamespace !== '') {
            $attributes['service.namespace'] = $config->serviceNamespace;
        }

        foreach ($config->resourceAttributes as $key => $value) {
            if (!isset($attributes[$key])) {
                $attributes[$key] = $value;
            }
        }

        return ResourceInfoFactory::defaultResource()
            ->merge(ResourceInfo::create(Attributes::create($attributes)));
    }
}
