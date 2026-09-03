<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Telemetry\TraceContext;
use ElPandaPe\Sentinel\Telemetry\TraceParent;

use function ElPandaPe\Sentinel\Tests\traceParent;

it('exposes the trace an entry belongs to', function (): void {
    $context = new TraceContext(traceParent(), 'rojo=00f067aa0ba902b7');

    expect($context->traceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($context->spanId())->toBe('00f067aa0ba902b7')
        ->and($context->traceparent())->toBe('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')
        ->and($context->tracestate())->toBe('rojo=00f067aa0ba902b7')
        ->and($context->sampled())->toBeTrue();
});

it('carries no tracestate when none arrived', function (): void {
    expect(new TraceContext(TraceParent::root())->tracestate())->toBeNull();
});
