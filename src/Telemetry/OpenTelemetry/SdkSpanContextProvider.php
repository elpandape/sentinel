<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Telemetry\OpenTelemetry;

use ElPandaPe\Sentinel\Contracts\SpanContextProvider;
use ElPandaPe\Sentinel\Telemetry\TraceContext;
use ElPandaPe\Sentinel\Telemetry\TraceParent;
use OpenTelemetry\API\Trace\Span;

/**
 * The only class in the package that knows the OpenTelemetry SDK exists, which is the whole reason
 * it is alone in this namespace and an arch test keeps it that way.
 *
 * It reads the active span and never writes one. The API answers with a non-recording span carrying
 * an invalid context when no tracer is registered, so there is no null to check and no exception to
 * catch: the question is whether the context is valid.
 */
final class SdkSpanContextProvider implements SpanContextProvider
{
    public function current(): ?TraceContext
    {
        $span = Span::getCurrent()->getContext();

        if (! $span->isValid()) {
            return null;
        }

        $parent = TraceParent::of(
            $span->getTraceId(),
            $span->getSpanId(),
            sprintf('%02x', $span->getTraceFlags()),
        );

        return $parent instanceof TraceParent
            ? new TraceContext($parent, $span->getTraceState()?->toString(TraceContext::TRACESTATE_LIMIT))
            : null;
    }
}
