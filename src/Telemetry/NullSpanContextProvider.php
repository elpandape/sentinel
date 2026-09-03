<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Telemetry;

use ElPandaPe\Sentinel\Contracts\SpanContextProvider;

/**
 * What the container holds when no OpenTelemetry SDK is installed, which is the default and the
 * common case. It answers what an SDK with no active span would answer, so the precedence chain
 * has nothing to special-case.
 */
final class NullSpanContextProvider implements SpanContextProvider
{
    public function current(): ?TraceContext
    {
        return null;
    }
}
