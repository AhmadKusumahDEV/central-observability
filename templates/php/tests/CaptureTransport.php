<?php

declare(strict_types=1);

namespace Observability\Tests;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

/**
 * Penerima OTLP palsu: menyimpan payload di memori, tanpa jaringan.
 */
final class CaptureTransport implements TransportInterface
{
    /** @var list<string> */
    public array $payloads = [];

    public function __construct(private readonly string $type = 'application/x-protobuf')
    {
    }

    public function contentType(): string
    {
        return $this->type;
    }

    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $this->payloads[] = $payload;

        // Exporter mengharapkan isi respons sebagai string, bukan bool.
        return new CompletedFuture('');
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function body(): string
    {
        return implode('', $this->payloads);
    }
}
