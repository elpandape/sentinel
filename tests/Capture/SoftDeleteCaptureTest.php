<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Tests\Fixtures\SoftDeletingSubject;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\verifier;

it('records one entry for a soft delete and calls it deleted', function (): void {
    $subject = SoftDeletingSubject::query()->create(['name' => 'Ada']);
    $subject->delete();

    $audits = auditsOf($subject);

    expect($audits->pluck('event')->all())->toBe(['created', 'deleted'])
        ->and($audits->last()?->after)->toBeNull();
});

it('records one entry for a force delete and calls it force_deleted', function (): void {
    $subject = SoftDeletingSubject::query()->create(['name' => 'Ada']);
    $subject->forceDelete();

    $audits = auditsOf($subject);

    expect($audits->pluck('event')->all())->toBe(['created', 'force_deleted'])
        ->and($audits->last()?->severity)->toBe(Severity::Warning)
        ->and($audits->last()?->before['name'] ?? null)->toBe('Ada')
        ->and($audits->last()?->after)->toBeNull();
});

it('records one entry for a force delete of a record already in the bin', function (): void {
    $subject = SoftDeletingSubject::query()->create(['name' => 'Ada']);
    $subject->delete();
    $subject->forceDelete();

    expect(auditsOf($subject)->pluck('event')->all())->toBe(['created', 'deleted', 'force_deleted']);
});

it('records one entry for a restore and calls it restored', function (): void {
    $subject = SoftDeletingSubject::query()->create(['name' => 'Ada']);
    $subject->delete();
    $subject->restore();

    expect(auditsOf($subject)->pluck('event')->all())->toBe(['created', 'deleted', 'restored']);
});

it('restores from the state the record had in the bin', function (): void {
    $subject = SoftDeletingSubject::query()->create(['name' => 'Ada']);
    $subject->delete();
    $subject->restore();

    $audit = auditsOf($subject)->last();

    expect($audit?->before['deleted_at'] ?? null)->not->toBeNull()
        ->and($audit?->after)->toHaveKey('deleted_at', null)
        ->and($audit?->before['name'] ?? null)->toBe('Ada');
});

it('records a plain update as an update even on a soft deleting model', function (): void {
    $subject = SoftDeletingSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    expect(auditsOf($subject)->pluck('event')->all())->toBe(['created', 'updated']);
});

it('keeps the chain intact across the whole life of a record', function (): void {
    $subject = SoftDeletingSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);
    $subject->delete();
    $subject->restore();
    $subject->forceDelete();

    $audits = auditsOf($subject);

    expect($audits->pluck('event')->all())
        ->toBe(['created', 'updated', 'deleted', 'restored', 'force_deleted'])
        ->and($audits->pluck('version')->all())->toBe([1, 2, 3, 4, 5])
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});
