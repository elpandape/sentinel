<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;

use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\verifier;

/**
 * Adoption is not retroactive: a trail written before telemetry was switched on keeps its null
 * trace_id forever, because rewriting that column would break the hash that covers it. So the
 * ordinary shape of a real trail is a chain that carries both kinds of entry, and the chain has to
 * verify across the seam.
 */
it('verifies a chain that carries entries written with and without a trace', function (): void {
    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    config()->set('sentinel.telemetry.enabled', true);
    httpRequest('/invoices', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
    new AuditedSubject()->forceFill(['name' => 'Grace'])->save();

    config()->set('sentinel.telemetry.enabled', false);
    new AuditedSubject()->forceFill(['name' => 'Alan'])->save();

    config()->set('sentinel.telemetry.enabled', true);
    httpRequest('/invoices', ['traceparent' => '00-11111111111111111111111111111111-2222222222222222-01']);
    new AuditedSubject()->forceFill(['name' => 'Edsger'])->save();

    $traces = Audit::query()->orderBy('sequence')->pluck('trace_id')->all();

    expect($traces)->toBe([
        null,
        '4bf92f3577b34da6a3ce929d0e0e4736',
        null,
        '11111111111111111111111111111111',
    ])->and(verifier()->verify('global')->isIntact())->toBeTrue();
});

it('finds by trace only the entries that were written under one', function (): void {
    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    config()->set('sentinel.telemetry.enabled', true);
    httpRequest('/invoices', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
    new AuditedSubject()->forceFill(['name' => 'Grace'])->save();

    expect(app(ElPandaPe\Sentinel\Sentinel::class)->audits()->withTrace('4bf92f3577b34da6a3ce929d0e0e4736')->get())
        ->toHaveCount(1);
});
