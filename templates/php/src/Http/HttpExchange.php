<?php

declare(strict_types=1);

namespace Observability\Http;

use Observability\Logs\OtelPsr3Logger;
use Observability\Metrics\HttpInstruments;
use Observability\Propagation\TraceContext;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use Throwable;

use function hrtime;

/**
 * Satu request HTTP yang sedang diukur: span, durasi, status, dan log akhir.
 */
final class HttpExchange
{
    private int $status = 0;
    private bool $ended = false;
    private readonly int $startedAt;

    public function __construct(
        private readonly SpanInterface $span,
        private readonly ScopeInterface $scope,
        private readonly HttpInstruments $instruments,
        private readonly ?OtelPsr3Logger $logger,
        private readonly string $method,
        private readonly string $route,
    ) {
        $this->startedAt = hrtime(true);
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function recordException(Throwable $exception): void
    {
        $this->span->recordException($exception);
        $this->span->setAttribute('error.type', $exception::class);
    }

    public function traceId(): string
    {
        return TraceContext::currentTraceId();
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        $status = $this->status > 0 ? $this->status : 200;
        $seconds = (hrtime(true) - $this->startedAt) / 1_000_000_000;

        $this->span->setAttribute('http.response.status_code', $status);

        if ($this->logger !== null) {
            $level = match (true) {
                $status >= 500 => 'error',
                $status >= 400 => 'warning',
                default => 'info',
            };

            // Log ditulis sebelum scope ditutup supaya trace_id masih terbaca.
            $this->logger->log($level, 'http request', [
                'http.method' => $this->method,
                'http.route' => $this->route,
                'http.status' => $status,
                'duration_ms' => round($seconds * 1000, 3),
            ]);
        }

        $this->instruments->record($this->method, $this->route, $status, $seconds);
        $this->span->end();
        $this->scope->detach();
    }
}
