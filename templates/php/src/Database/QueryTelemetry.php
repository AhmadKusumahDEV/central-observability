<?php

declare(strict_types=1);

namespace Observability\Database;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

use function strtoupper;

/**
 * Membungkus satu query database dengan span CLIENT. Query asli tidak
 * disimpan sebagai atribut karena bisa berisi data pribadi.
 */
final class QueryTelemetry
{
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly string $system = 'postgresql',
    ) {
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function trace(string $operation, string $table, callable $work, ?string $database = null): mixed
    {
        $operation = strtoupper($operation);
        $span = $this->tracer->spanBuilder($operation . ' ' . $table)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system.name', $this->system)
            ->setAttribute('db.operation.name', $operation)
            ->setAttribute('db.collection.name', $table)
            ->startSpan();

        if ($database !== null && $database !== '') {
            $span->setAttribute('db.namespace', $database);
        }

        $scope = $span->activate();

        try {
            return $work();
        } catch (Throwable $exception) {
            $span->recordException($exception);
            $span->setAttribute('error.type', $exception::class);

            throw $exception;
        } finally {
            $span->end();
            $scope->detach();
        }
    }
}
