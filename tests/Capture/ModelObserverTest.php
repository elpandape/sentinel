<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\verifier;

it('records a created entry with the state that now exists and nothing before it', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);

    $audit = auditsOf($subject)->first();

    expect($audit?->event)->toBe('created')
        ->and($audit?->audit_type)->toBe('model')
        ->and($audit?->subject_type)->toBe($subject->getMorphClass())
        ->and($audit?->subject_id)->toBe((string) $subject->getKey())
        ->and($audit?->before)->toBeNull()
        ->and($audit?->after['name'] ?? null)->toBe('Ada')
        ->and($audit?->severity)->toBe(Severity::Info)
        ->and($audit?->source)->toBe(Source::System)
        ->and($audit?->changes)->toBeNull()
        ->and($audit?->version)->toBe(1);
});

it('records an updated entry with both sides of the change', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    $audit = auditsOf($subject)->last();

    expect($audit?->event)->toBe('updated')
        ->and($audit?->before['name'] ?? null)->toBe('Ada')
        ->and($audit?->after['name'] ?? null)->toBe('Grace');
});

it('records nothing when nothing was dirty', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Ada']);

    expect(auditsOf($subject))->toHaveCount(1);
});

it('records a deleted entry with the state that is leaving and grades it above an update', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->delete();

    $audit = auditsOf($subject)->last();

    expect($audit?->event)->toBe('deleted')
        ->and($audit?->before['name'] ?? null)->toBe('Ada')
        ->and($audit?->after)->toBeNull()
        ->and($audit?->severity)->toBe(Severity::Notice);
});

it('chains every entry of a subject from the first one', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);
    $subject->update(['name' => 'Hedy']);

    $audits = auditsOf($subject);

    expect($audits)->toHaveCount(3)
        ->and($audits->first()?->previous_hash)->toBeNull()
        ->and($audits->last()?->previous_hash)->toBe($audits[1]->hash)
        ->and($audits->pluck('version')->all())->toBe([1, 2, 3])
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('writes nothing while auditing is paused', function (): void {
    $subject = Sentinel::withoutAuditing(fn (): AuditedSubject => AuditedSubject::query()->create(['name' => 'Ada']));

    expect(auditsOf($subject))->toBeEmpty();
});

it('writes nothing when the package is disabled', function (): void {
    config()->set('sentinel.enabled', false);

    $subject = AuditedSubject::query()->create(['name' => 'Ada']);

    expect(auditsOf($subject))->toBeEmpty();
});
