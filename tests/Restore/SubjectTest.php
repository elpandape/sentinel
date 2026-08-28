<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Restore\Subject;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SoftDeletingSubject;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\restorableEntry;

it('finds the record an entry is about', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);

    expect(Subject::of(restorableEntry($record, ['name' => 'Ada']))?->getKey())->toBe($record->getKey());
});

it('finds a record that is in the recycle bin, which is where most restorations start', function (): void {
    $record = SoftDeletingSubject::query()->create(['name' => 'Ada']);
    $record->delete();

    expect(SoftDeletingSubject::query()->find($record->getKey()))->toBeNull()
        ->and(Subject::of(auditsOf($record)->last())?->getKey())->toBe($record->getKey());
});

it('finds nothing when the record was deleted for good', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);
    $entry = restorableEntry($record, ['name' => 'Ada']);
    $record->forceDelete();

    expect(Subject::of($entry))->toBeNull();
});

it('finds nothing when the entry names no record at all', function (): void {
    expect(Subject::of(restorableEntry(new AuditedSubject, ['name' => 'Ada'], [
        'subject_type' => null,
        'subject_id' => null,
    ])))->toBeNull();
});

it('finds nothing when the type the entry names is no longer a model', function (): void {
    $record = AuditedSubject::query()->create(['name' => 'Ada']);

    expect(Subject::of(restorableEntry($record, ['name' => 'Ada'], [
        'subject_type' => 'App\\Models\\Retired',
    ])))->toBeNull();
});
