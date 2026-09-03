<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\SpanContextProvider;
use ElPandaPe\Sentinel\Telemetry\NullSpanContextProvider;
use ElPandaPe\Sentinel\Telemetry\TraceContext;
use ElPandaPe\Sentinel\Tests\Fixtures\ActiveSpan;

use function ElPandaPe\Sentinel\Tests\traceParent;

it('holds the null provider until something registers a tracer', function (): void {
    expect(app(SpanContextProvider::class))->toBeInstanceOf(NullSpanContextProvider::class)
        ->and(app(SpanContextProvider::class)->current())->toBeNull();
});

it('answers with the span a tracer is inside', function (): void {
    app()->instance(SpanContextProvider::class, new ActiveSpan(new TraceContext(traceParent())));

    expect(app(SpanContextProvider::class)->current()?->traceId())
        ->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('answers with nothing when a tracer runs outside every span', function (): void {
    app()->instance(SpanContextProvider::class, new ActiveSpan(null));

    expect(app(SpanContextProvider::class)->current())->toBeNull();
});
