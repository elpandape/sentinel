<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;

use function ElPandaPe\Sentinel\Tests\httpRequest;

it('hands the application the trace it can forward', function (): void {
    config()->set('sentinel.telemetry.enabled', true);
    httpRequest('/invoices', [
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'tracestate' => 'rojo=1',
    ]);

    expect(Sentinel::trace()?->traceparent())
        ->toBe('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')
        ->and(Sentinel::trace()?->tracestate())->toBe('rojo=1')
        ->and(Sentinel::trace()?->traceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('hands back nothing while telemetry is off', function (): void {
    httpRequest('/invoices', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    expect(Sentinel::trace())->toBeNull();
});
