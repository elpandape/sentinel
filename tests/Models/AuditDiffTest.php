<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;

use function ElPandaPe\Sentinel\Tests\auditsOf;
use function ElPandaPe\Sentinel\Tests\insertAudit;

it('reads the diff the entry stored', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    $diff = auditsOf($subject)->last()?->diff();

    expect($diff)->toBeInstanceOf(Diff::class)
        ->and($diff?->toArray())->toBe([
            ['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace'],
        ]);
});

it('computes the diff on read for an entry written before the column was populated', function (): void {
    insertAudit([
        'event' => 'updated',
        'before' => json_encode(['name' => 'Ada']),
        'after' => json_encode(['name' => 'Grace']),
        'changes' => null,
    ]);

    expect(Audit::query()->sole()->diff()->toArray())->toBe([
        ['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace'],
    ]);
});

it('gives an empty diff, not an error, for an entry with nothing to compare', function (): void {
    insertAudit(['before' => null, 'after' => null, 'changes' => null]);

    expect(Audit::query()->sole()->diff()->isEmpty())->toBeTrue();
});

it('never rewrites the row it read', function (): void {
    insertAudit(['before' => json_encode(['a' => 1]), 'after' => json_encode(['a' => 2])]);

    $audit = Audit::query()->sole();
    $audit->diff();

    expect(Audit::query()->sole()->changes)->toBeNull()
        ->and($audit->isDirty())->toBeFalse();
});

it('narrows the diff to a subtree in dot notation', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    $subject->update(['name' => 'Grace', 'email' => 'grace@example.com']);

    expect(auditsOf($subject)->last()?->diffFor('name')->toArray())->toBe([
        ['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace'],
    ]);
});

it('exports what it read as a json patch', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $subject->update(['name' => 'Grace']);

    expect(auditsOf($subject)->last()?->diff()->toJsonPatch(tests: false))
        ->toBe([['op' => 'replace', 'path' => '/name', 'value' => 'Grace']]);
});
