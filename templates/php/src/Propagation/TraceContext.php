<?php

declare(strict_types=1);

namespace Observability\Propagation;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;

/**
 * Pembungkus kecil untuk membaca dan menulis traceparent. Dipakai middleware
 * HTTP masuk dan client HTTP keluar supaya trace tetap nyambung antar service.
 */
final class TraceContext
{
    public static function currentTraceId(): string
    {
        $spanContext = Span::getCurrent()->getContext();

        return $spanContext->isValid() ? $spanContext->getTraceId() : '';
    }

    public static function currentSpanId(): string
    {
        $spanContext = Span::getCurrent()->getContext();

        return $spanContext->isValid() ? $spanContext->getSpanId() : '';
    }

    /**
     * @param array<string, string|array<string>> $headers
     */
    public static function extract(array $headers, ?ContextInterface $parent = null): ContextInterface
    {
        return TraceContextPropagator::getInstance()->extract($headers, null, $parent ?? Context::getCurrent());
    }

    /**
     * Menulis traceparent ke array header sebelum request keluar dikirim.
     *
     * @param array<string, string> $headers
     */
    public static function inject(array &$headers): void
    {
        TraceContextPropagator::getInstance()->inject($headers);
    }

    public static function activate(ContextInterface $context): ScopeInterface
    {
        return $context->activate();
    }
}
