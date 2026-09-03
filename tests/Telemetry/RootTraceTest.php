<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;

use function ElPandaPe\Sentinel\Tests\runtime;

beforeEach(function (): void {
    config()->set('sentinel.telemetry.enabled', true);
    config()->set('sentinel.telemetry.root_context', true);
});

it('files every entry of one command run under one trace', function (): void {
    runtime()->enteredCommand('invoices:close', ['month' => '2026-08']);

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    new AuditedSubject()->forceFill(['name' => 'Grace'])->save();
    new AuditedSubject()->forceFill(['name' => 'Alan'])->save();

    $traces = Audit::query()->pluck('trace_id');

    expect($traces)->toHaveCount(3)
        ->and($traces->unique())->toHaveCount(1)
        ->and($traces->first())->toHaveLength(32);
});

it('gives each entry of that run a span of the run, since nobody handed it one', function (): void {
    runtime()->enteredCommand('invoices:close', []);

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(Audit::query()->sole()->span_id)->toHaveLength(16);
});

it('opens no trace at all for a run that was told not to', function (): void {
    config()->set('sentinel.telemetry.root_context', false);
    runtime()->enteredCommand('invoices:close', []);

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(Audit::query()->sole()->trace_id)->toBeNull();
});

it('leaves a scheduled run correlated the same way', function (): void {
    runtime()->enteredSchedule();

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    new AuditedSubject()->forceFill(['name' => 'Grace'])->save();

    expect(Audit::query()->pluck('trace_id')->unique())->toHaveCount(1);
});
