<?php

declare(strict_types=1);

namespace Observability\Tests;

use Observability\Telemetry\TransportProvider;
use OpenTelemetry\SDK\Common\Export\TransportInterface;

final class CaptureTransportProvider implements TransportProvider
{
    /** @var array<string, CaptureTransport> */
    public array $transports = [];

    public function forSignal(string $signal): TransportInterface
    {
        return $this->transports[$signal] ??= new CaptureTransport();
    }
}
