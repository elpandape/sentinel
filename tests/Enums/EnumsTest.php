<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Mode;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;

it('exposes the audit events the engine records', function (): void {
    expect(AuditEvent::from('force_deleted'))->toBe(AuditEvent::ForceDeleted)
        ->and(AuditEvent::from('rekeyed'))->toBe(AuditEvent::Rekeyed)
        ->and(AuditEvent::cases())->toHaveCount(12);
});

it('exposes every execution source', function (): void {
    expect(Source::from('scheduler'))->toBe(Source::Scheduler)
        ->and(Source::cases())->toHaveCount(8);
});

it('exposes every write mode', function (): void {
    expect(Mode::from('buffered'))->toBe(Mode::Buffered)
        ->and(Mode::cases())->toHaveCount(3);
});

it('ranks severities', function (): void {
    expect(Severity::Info->rank())->toBe(0)
        ->and(Severity::Notice->rank())->toBe(1)
        ->and(Severity::Warning->rank())->toBe(2)
        ->and(Severity::Critical->rank())->toBe(3);
});

it('compares severities against a floor', function (): void {
    expect(Severity::Critical->atLeast(Severity::Warning))->toBeTrue()
        ->and(Severity::Warning->atLeast(Severity::Warning))->toBeTrue()
        ->and(Severity::Info->atLeast(Severity::Notice))->toBeFalse();
});
