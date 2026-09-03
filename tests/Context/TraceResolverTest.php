<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\TraceResolver;
use ElPandaPe\Sentinel\Contracts\SpanContextProvider;
use ElPandaPe\Sentinel\Telemetry\TraceContext;
use ElPandaPe\Sentinel\Tests\Fixtures\ActiveSpan;

use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\traceParent;

const INCOMING = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

beforeEach(function (): void {
    config()->set('sentinel.telemetry.enabled', true);
    config()->set('sentinel.telemetry.service_name', 'billing');
});

it('resolves nothing at all while telemetry is off', function (): void {
    config()->set('sentinel.telemetry.enabled', false);
    httpRequest('/invoices', ['traceparent' => INCOMING]);

    expect(app(TraceResolver::class)->resolve())->toBeEmpty();
});

it('names the service even when nothing traced the run', function (): void {
    expect(app(TraceResolver::class)->resolve())->toBe(['service_name' => 'billing']);
});

it('takes the two identifiers a well formed traceparent carries', function (): void {
    httpRequest('/invoices', ['traceparent' => INCOMING]);

    expect(app(TraceResolver::class)->resolve())->toBe([
        'service_name' => 'billing',
        'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
        'span_id' => '00f067aa0ba902b7',
    ]);
});

it('reads a header it cannot trust as no header at all', function (string $header): void {
    httpRequest('/invoices', ['traceparent' => $header]);

    expect(app(TraceResolver::class)->resolve())->toBe(['service_name' => 'billing']);
})->with([
    'forbidden version' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    'uppercase' => ['00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01'],
    'all zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
    'all zero span id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
    'truncated' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-0'],
    'nonsense' => ['not-a-traceparent'],
]);

it('ignores a header it is told not to trust, however well formed', function (): void {
    config()->set('sentinel.telemetry.trust_incoming_header', false);
    httpRequest('/invoices', ['traceparent' => INCOMING]);

    expect(app(TraceResolver::class)->resolve())->toBe(['service_name' => 'billing']);
});

it('lets the active span win over the header the caller sent', function (): void {
    app()->instance(SpanContextProvider::class, new ActiveSpan(new TraceContext(
        traceParent('00-11111111111111111111111111111111-2222222222222222-01'),
    )));
    httpRequest('/invoices', ['traceparent' => INCOMING]);

    expect(app(TraceResolver::class)->resolve())->toBe([
        'service_name' => 'billing',
        'trace_id' => '11111111111111111111111111111111',
        'span_id' => '2222222222222222',
    ]);
});

it('keeps tracestate out of the entry unless it is asked to store it', function (): void {
    httpRequest('/invoices', ['traceparent' => INCOMING, 'tracestate' => 'rojo=1']);

    expect(app(TraceResolver::class)->resolve())->not->toHaveKey('tracestate');
});

it('stores tracestate verbatim when it is asked to', function (): void {
    config()->set('sentinel.telemetry.store_tracestate', true);
    httpRequest('/invoices', ['traceparent' => INCOMING, 'tracestate' => 'rojo=1,azul=2']);

    expect(app(TraceResolver::class)->resolve())->toHaveKey('tracestate', 'rojo=1,azul=2');
});

it('drops a tracestate longer than the spec asks anyone to carry', function (): void {
    config()->set('sentinel.telemetry.store_tracestate', true);
    httpRequest('/invoices', [
        'traceparent' => INCOMING,
        'tracestate' => 'rojo='.str_repeat('a', TraceContext::TRACESTATE_LIMIT),
    ]);

    expect(app(TraceResolver::class)->resolve())->not->toHaveKey('tracestate');
});

it('opens a trace of its own when nothing traced the run and it is allowed to', function (): void {
    config()->set('sentinel.telemetry.root_context', true);

    expect(app(TraceResolver::class)->resolve())
        ->toHaveKeys(['trace_id', 'span_id']);
});

it('gives every entry of one run the same root trace', function (): void {
    config()->set('sentinel.telemetry.root_context', true);

    expect(app(TraceResolver::class)->resolve()['trace_id'])
        ->toBe(app(TraceResolver::class)->resolve()['trace_id']);
});

it('prefers the header it was sent over a root it would have opened', function (): void {
    config()->set('sentinel.telemetry.root_context', true);
    httpRequest('/invoices', ['traceparent' => INCOMING]);

    expect(app(TraceResolver::class)->resolve()['trace_id'])->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('falls back to the application name when the section names no service', function (): void {
    config()->set('sentinel.telemetry.service_name');
    config()->set('app.name', 'ledger');

    expect(app(TraceResolver::class)->resolve())->toBe(['service_name' => 'ledger']);
});

it('names no service when neither the configuration nor the application does', function (): void {
    config()->set('sentinel.telemetry.service_name');
    config()->set('app.name');

    expect(app(TraceResolver::class)->resolve())->toBeEmpty();
});
