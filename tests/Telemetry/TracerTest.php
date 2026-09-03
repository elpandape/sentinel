<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Telemetry\Tracer;

use function ElPandaPe\Sentinel\Tests\httpRequest;

it('traces nothing while telemetry is off, however well traced the caller was', function (): void {
    httpRequest('/invoices', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    expect(app(Tracer::class)->current())->toBeNull();
});

it('answers with the trace the caller sent once telemetry is on', function (): void {
    config()->set('sentinel.telemetry.enabled', true);
    httpRequest('/invoices', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    expect(app(Tracer::class)->current()?->traceparent())
        ->toBe('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
});
