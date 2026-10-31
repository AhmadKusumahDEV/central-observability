<?php

declare(strict_types=1);

namespace Observability\Logs;

use Observability\Propagation\TraceContext;
use OpenTelemetry\API\Logs\LoggerInterface as OtelLoggerInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

use function fopen;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function json_encode;
use function preg_match;
use function preg_replace_callback;
use function str_replace;
use function strtr;
use function trim;

/**
 * Logger PSR-3 yang mengirim log ke Collector lewat OTLP sekaligus menulis
 * satu baris JSON ke stderr. Trace ID ikut di kedua jalur sehingga log dan
 * trace bisa saling dibuka dari Grafana.
 */
final class OtelPsr3Logger extends AbstractLogger
{
    /** @var resource|null */
    private $stream;

    private readonly string $minimumLevel;

    /**
     * @param resource|null $stream
     */
    public function __construct(
        private readonly OtelLoggerInterface $otelLogger,
        private readonly string $serviceName,
        string $minimumLevel = 'info',
        $stream = null,
        private readonly bool $writeConsoleLine = true,
    ) {
        $this->minimumLevel = SeverityMap::parseMinimum($minimumLevel);
        $this->stream = $stream ?? fopen('php://stderr', 'wb');
    }

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $levelName = SeverityMap::parseMinimum((string) $level);
        $messageText = $message instanceof Stringable ? (string) $message : (string) $message;

        if (SeverityMap::weight($levelName) < SeverityMap::weight($this->minimumLevel)) {
            return;
        }

        $traceId = TraceContext::currentTraceId();
        $spanId = TraceContext::currentSpanId();
        $attributes = self::attributes($context);
        $attributes['service.name'] = $this->serviceName;
        if ($traceId !== '') {
            $attributes['trace_id'] = $traceId;
        }
        if ($spanId !== '') {
            $attributes['span_id'] = $spanId;
        }

        $builder = $this->otelLogger->logRecordBuilder();
        $builder
            ->setSeverityNumber(SeverityMap::number($levelName))
            ->setSeverityText(SeverityMap::text($levelName))
            ->setBody(self::interpolate($messageText, $context))
            ->setAttributes($attributes)
            ->setContext(null)
            ->emit();

        if ($this->writeConsoleLine && is_resource($this->stream)) {
            $line = [
                'time' => gmdate('c'),
                'level' => $levelName,
                'service' => $this->serviceName,
                'message' => self::interpolate($messageText, $context),
                'trace_id' => $traceId,
                'span_id' => $spanId,
            ];
            foreach ($attributes as $key => $value) {
                if ($key !== 'service.name' && $key !== 'trace_id' && $key !== 'span_id') {
                    $line[$key] = $value;
                }
            }

            $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                fwrite($this->stream, $json . "\n");
            }
        }
    }

    /**
     * Mengganti placeholder {nama} dengan nilai dari context, mirip PSR-3.
     *
     * @param array<array-key, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return $replacements === [] ? $message : strtr($message, $replacements);
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<string, mixed>
     */
    private static function attributes(array $context): array
    {
        $attributes = [];
        foreach ($context as $key => $value) {
            $name = (string) $key;
            if ($name === '' || $name === 'service.name' || preg_match('/^[a-zA-Z0-9_.\-]+$/', $name) !== 1) {
                continue;
            }

            $attributes[$name] = match (true) {
                $value === null => 'null',
                is_bool($value) => $value,
                is_scalar($value) => $value,
                $value instanceof Throwable => $value->getMessage(),
                $value instanceof Stringable => (string) $value,
                default => json_encode($value, JSON_UNESCAPED_SLASHES) ?: 'unserializable',
            };
        }

        return $attributes;
    }
}
