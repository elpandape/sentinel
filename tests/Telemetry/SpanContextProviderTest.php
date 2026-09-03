<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\SpanContextProvider;
use ElPandaPe\Sentinel\Telemetry\NullSpanContextProvider;
use ElPandaPe\Sentinel\Telemetry\OpenTelemetry\Sdk;
use ElPandaPe\Sentinel\Telemetry\OpenTelemetry\SdkSpanContextProvider;
use ElPandaPe\Sentinel\Telemetry\TraceContext;
use ElPandaPe\Sentinel\Tests\Fixtures\ActiveSpan;

use function ElPandaPe\Sentinel\Tests\traceParent;

it('reads the SDK when the SDK is installed, and nothing when it is not', function (): void {
    expect(Sdk::reading(true))->toBeInstanceOf(SdkSpanContextProvider::class)
        ->and(Sdk::reading(false))->toBeInstanceOf(NullSpanContextProvider::class);
});

it('binds the reader the installation calls for', function (): void {
    expect(Sdk::present())->toBeTrue()
        ->and(app(SpanContextProvider::class))->toBeInstanceOf(SdkSpanContextProvider::class);
});

it('answers with nothing when nobody is tracing', function (): void {
    expect(new NullSpanContextProvider()->current())->toBeNull()
        ->and(new SdkSpanContextProvider()->current())->toBeNull();
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
