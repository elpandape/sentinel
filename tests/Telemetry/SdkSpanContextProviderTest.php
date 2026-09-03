<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Telemetry\OpenTelemetry\SdkSpanContextProvider;
use OpenTelemetry\API\Trace\TraceState;

use function ElPandaPe\Sentinel\Tests\insideSpan;

it('takes the span the tracer is inside', function (): void {
    insideSpan(1, null, function (): void {
        $context = new SdkSpanContextProvider()->current();

        expect($context?->traceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
            ->and($context?->spanId())->toBe('00f067aa0ba902b7')
            ->and($context?->sampled())->toBeTrue()
            ->and($context?->traceparent())->toBe('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')
            ->and($context?->tracestate())->toBeNull();
    });
});

it('carries the tracestate the span carries', function (): void {
    insideSpan(0, new TraceState('rojo=00f067aa0ba902b7'), function (): void {
        expect(new SdkSpanContextProvider()->current()?->tracestate())->toBe('rojo=00f067aa0ba902b7');
    });
});

it('takes nothing from a span whose flags are not a byte', function (): void {
    insideSpan(999, null, function (): void {
        expect(new SdkSpanContextProvider()->current())->toBeNull();
    });
});
