<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Capture\ModelCapture;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SelectiveSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SnapshotlessSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SoftDeletingSubject;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

it('writes only additions for a creation', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);

    $changes = auditsOf($subject)->first()?->changes ?? [];

    expect(array_column($changes, 'op'))->each->toBe('add')
        ->and(array_column($changes, 'path'))->toContain('/name');
});

it('writes the leaf that moved and nothing else for an update', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    $subject->update(['name' => 'Grace']);

    expect(auditsOf($subject)->last()?->diff()->toArray())->toBe([
        ['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace'],
    ]);
});

it('writes only removals for a deletion', function (): void {
    $subject = SoftDeletingSubject::query()->create(['name' => 'Ada']);
    $subject->delete();

    $changes = auditsOf($subject)->last()?->changes ?? [];

    expect(array_column($changes, 'op'))->each->toBe('remove');
});

it('writes an empty list, not a null, for an update the field policy leaves untouched', function (): void {
    stagedPipeline([ResolveContext::class]);

    $subject = SelectiveSubject::query()->create(['name' => 'Ada', 'secret' => 'first']);
    $subject->update(['secret' => 'second']);

    $last = auditsOf($subject)->last();

    expect($last?->event)->toBe('updated')
        ->and($last?->changes)->toBe([])
        ->and($last?->diff()->isEmpty())->toBeTrue();
});

it('keeps the diff as the only record of the change when the model drops its snapshots', function (): void {
    $subject = SnapshotlessSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    $last = auditsOf($subject)->last();

    expect($last?->before)->toBeNull()
        ->and($last?->after)->toBeNull()
        ->and($last?->diff()->toArray())->toBe([
            ['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace'],
        ]);
});

it('duplicates the state inside changes when a creation has no snapshots to fall back on', function (): void {
    $subject = SnapshotlessSubject::query()->create(['name' => 'Ada']);

    expect(auditsOf($subject)->first()?->changes)->not->toBeEmpty();
});

it('still chains and still verifies an entry that carries only its diff', function (): void {
    $subject = SnapshotlessSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    $audits = auditsOf($subject);

    expect($audits->last()?->previous_hash)->toBe($audits->first()?->hash)
        ->and($audits->last()?->verifyIntegrity())->toBeTrue();
});

it('keeps the diff when the configuration turns snapshots off globally', function (): void {
    config()->set('sentinel.snapshots.enabled', false);

    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $audit = auditsOf($subject)->first();

    expect($audit?->before)->toBeNull()
        ->and($audit?->after)->toBeNull()
        ->and($audit?->changes)->not->toBeNull();
});

it('leaves changes null for an event that has no pair to compare', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);

    $audit = app(ModelCapture::class)->record($subject, AuditEvent::Custom);

    expect($audit?->changes)->toBeNull()
        ->and($audit?->before)->toBeNull()
        ->and($audit?->after)->toBeNull();
});
