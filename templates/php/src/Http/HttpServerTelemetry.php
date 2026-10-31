<?php

declare(strict_types=1);

namespace Observability\Http;

use Observability\Logs\OtelPsr3Logger;
use Observability\Metrics\HttpInstruments;
use Observability\Propagation\TraceContext;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/**
 * Membuat span server, mengukur durasi, dan menulis access log untuk satu
 * request. Pemakaian paling singkat: $http->handle('GET', '/work', fn () => ...).
 */
final class HttpServerTelemetry
{
    private const INSTRUMENTATION_NAME = 'observability.php.http';

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly HttpInstruments $instruments,
        private readonly ?OtelPsr3Logger $logger = null,
    ) {
    }

    public function begin(string $method, string $route, ?ContextInterface $parent = null): HttpExchange
    {
        $route = RouteTemplateResolver::normalize($route);
        $builder = $this->tracer->spanBuilder($method . ' ' . $route)
            ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_SERVER)
            ->setAttribute('http.request.method', $method)
            ->setAttribute('http.route', $route);

        if ($parent !== null) {
            $builder->setParent($parent);
        }

        $span = $builder->startSpan();
        $scope = $span->activate();

        return new HttpExchange($span, $scope, $this->instruments, $this->logger, $method, $route);
    }

    /**
     * @param array<string, string|array<string>> $headers
     */
    public function beginFromHeaders(string $method, string $route, array $headers): HttpExchange
    {
        return $this->begin($method, $route, TraceContext::extract($headers));
    }

    /**
     * Menjalankan handler di dalam span. Bila handler melempar exception,
     * status dianggap 500 dan exception dicatat di span.
     *
     * @param callable(): mixed $handler
     */
    public function handle(string $method, string $route, callable $handler): mixed
    {
        $exchange = $this->begin($method, $route);

        try {
            $result = $handler();
            if (is_object($result) && method_exists($result, 'getStatusCode')) {
                $exchange->setStatus((int) $result->getStatusCode());
            }

            return $result;
        } catch (Throwable $exception) {
            $exchange->setStatus(500);
            $exchange->recordException($exception);

            throw $exception;
        } finally {
            $exchange->end();
        }
    }
}
