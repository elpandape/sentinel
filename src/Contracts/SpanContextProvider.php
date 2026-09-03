<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use ElPandaPe\Sentinel\Telemetry\TraceContext;

/**
 * The one place the package asks a tracer what it is tracing right now. Behind it there is either
 * nothing or an adapter for an OpenTelemetry SDK, and keeping that to a single point is what lets
 * the SDK be optional: the core depends on this method, never on a vendor's class.
 *
 * Null is the ordinary answer, not a failure. It means no tracer is running, or one is running and
 * is not inside a span, and either way the caller falls through to the incoming header.
 */
interface SpanContextProvider
{
    public function current(): ?TraceContext;
}
