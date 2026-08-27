<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\TraceResolver;

use function ElPandaPe\Sentinel\Tests\httpRequest;

it('resolves nothing outside a request', function (): void {
    expect(app(TraceResolver::class)->resolve())->toBeEmpty();
});

it('resolves nothing without a traceparent', function (): void {
    httpRequest('/invoices');

    expect(app(TraceResolver::class)->resolve())->toBeEmpty();
});

it('takes the two identifiers a well formed traceparent carries', function (): void {
    httpRequest('/invoices', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    expect(app(TraceResolver::class)->resolve())->toBe([
        'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
        'span_id' => '00f067aa0ba902b7',
    ]);
});

it('ignores a traceparent it cannot read', function (): void {
    httpRequest('/invoices', ['traceparent' => 'not-a-traceparent']);

    expect(app(TraceResolver::class)->resolve())->toBeEmpty();
});

it('ignores the all-zero trace id the spec forbids', function (): void {
    httpRequest('/invoices', ['traceparent' => '00-'.str_repeat('0', 32).'-00f067aa0ba902b7-01']);

    expect(app(TraceResolver::class)->resolve())->toBeEmpty();
});

it('ignores the all-zero span id the spec forbids', function (): void {
    httpRequest('/invoices', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-'.str_repeat('0', 16).'-01']);

    expect(app(TraceResolver::class)->resolve())->toBeEmpty();
});
