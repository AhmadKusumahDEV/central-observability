<?php

declare(strict_types=1);

namespace Observability\Telemetry;

use Observability\Config\TelemetryConfig;
use Observability\Database\QueryTelemetry;
use Observability\Http\HttpClient;
use Observability\Http\HttpServerTelemetry;
use Observability\Http\Psr15Middleware;
use Observability\Http\RouteTemplateResolver;
use Observability\Logs\OtelPsr3Logger;
use Observability\Metrics\Heartbeat;
use Observability\Metrics\HttpInstruments;
use OpenTelemetry\API\Logs\NoopLogger;
use OpenTelemetry\API\Logs\LoggerInterface as OtelLoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Sdk;
use RuntimeException;

/**
 * Titik masuk tunggal. Panggil Telemetry::boot() sekali di awal request atau
 * sekali di awal proses CLI, lalu pakai tracer(), logger(), dan httpServer().
 */
final class Telemetry
{
    private static ?self $instance = null;

    private function __construct(
        public readonly TelemetryConfig $config,
        private readonly ?BuiltTelemetry $built,
        private readonly TracerInterface $tracer,
        private readonly MeterInterface $meter,
        private readonly OtelPsr3Logger $logger,
        private readonly HttpInstruments $httpInstruments,
        private readonly Heartbeat $heartbeat,
    ) {
    }

    /**
     * @param bool $registerShutdown Kirim sisa data saat PHP selesai.
     */
    public static function boot(
        ?TelemetryConfig $config = null,
        bool $registerShutdown = true,
        ?TransportProvider $transports = null,
    ): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config ??= TelemetryConfig::fromEnvironment();

        if ($config->disabled) {
            $meter = (new NoopMeterProvider())->getMeter('observability.php');
            $self = new self(
                config: $config,
                built: null,
                tracer: NoopTracer::getInstance(),
                meter: $meter,
                logger: new OtelPsr3Logger(NoopLogger::getInstance(), $config->serviceName, $config->logLevel),
                httpInstruments: new HttpInstruments($meter),
                heartbeat: new Heartbeat(),
            );

            self::$instance = $self;

            return $self;
        }

        $built = SdkFactory::build($config, $transports);
        $tracer = $built->tracerProvider->getTracer('observability.php');
        $meter = $built->meterProvider->getMeter('observability.php');
        $otelLogger = self::otelLogger($built);

        Sdk::builder()
            ->setTracerProvider($built->tracerProvider)
            ->setMeterProvider($built->meterProvider)
            ->setLoggerProvider($built->loggerProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->setAutoShutdown(false)
            ->buildAndRegisterGlobal();

        $heartbeat = new Heartbeat();
        $heartbeat->register($meter);

        $self = new self(
            config: $config,
            built: $built,
            tracer: $tracer,
            meter: $meter,
            logger: new OtelPsr3Logger($otelLogger, $config->serviceName, $config->logLevel),
            httpInstruments: new HttpInstruments($meter),
            heartbeat: $heartbeat,
        );

        self::$instance = $self;

        if ($registerShutdown) {
            register_shutdown_function(static function (): void {
                self::$instance?->flush();
            });
        }

        return $self;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function current(): self
    {
        return self::$instance ?? throw new RuntimeException('Telemetry::boot() belum dipanggil.');
    }

    public function tracer(): TracerInterface
    {
        return $this->tracer;
    }

    public function meter(): MeterInterface
    {
        return $this->meter;
    }

    public function logger(): OtelPsr3Logger
    {
        return $this->logger;
    }

    public function httpServer(): HttpServerTelemetry
    {
        return new HttpServerTelemetry($this->tracer, $this->httpInstruments, $this->logger);
    }

    public function psr15(?RouteTemplateResolver $routes = null): Psr15Middleware
    {
        return new Psr15Middleware($this->httpServer(), $routes ?? new RouteTemplateResolver());
    }

    public function httpClient(): HttpClient
    {
        return new HttpClient($this->tracer);
    }

    public function database(string $system = 'postgresql'): QueryTelemetry
    {
        return new QueryTelemetry($this->tracer, $system);
    }

    public function isEnabled(): bool
    {
        return $this->built !== null;
    }

    public function flush(): void
    {
        $this->built?->flush();
    }

    public function shutdown(): void
    {
        $this->built?->shutdown();
        self::$instance = null;
    }

    private static function otelLogger(BuiltTelemetry $built): OtelLoggerInterface
    {
        return $built->loggerProvider->getLogger('observability.php');
    }
}
