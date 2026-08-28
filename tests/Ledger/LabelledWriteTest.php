<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditTagsTable;
use function ElPandaPe\Sentinel\Tests\verifier;

it('stores an entry and its labels in the operation that seals it', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData(['tags' => ['billing', 'refund']]));

    expect(DB::table(auditTagsTable())->where('audit_id', $audit->id)->pluck('tag')->sort()->values()->all())
        ->toBe(['billing', 'refund']);
});

it('keeps labels out of the attributes the ledger inserts and hashes', function (): void {
    $audit = app(EntryBuilder::class)->build(auditData(['tags' => ['billing']]), 'global', 1, null, null);

    expect($audit->getAttributes())->not->toHaveKey('tags')
        ->and($audit->relationLoaded('tags'))->toBeTrue()
        ->and($audit->tags)->toHaveCount(1);
});

it('still verifies a labelled entry against its own chain', function (): void {
    app(DatabaseLedger::class)->write(auditData(['tags' => ['billing']]));
    app(DatabaseLedger::class)->write(auditData(['tags' => ['refund']]));

    expect(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('leaves the hash alone when a label is added to an entry already sealed', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData());
    $sealed = $audit->hash;

    DB::table(auditTagsTable())->insert(['audit_id' => $audit->id, 'tag' => 'classified-later']);

    expect(Audit::query()->findOrFail($audit->id)->hash)->toBe($sealed)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('writes the labels of every entry a batch seals, across streams', function (): void {
    app(DatabaseLedger::class)->writeMany([
        auditData(['stream' => 'one', 'tags' => ['first']]),
        auditData(['stream' => 'two', 'tags' => ['second']]),
        auditData(['stream' => 'one', 'tags' => ['third']]),
    ]);

    expect(DB::table(auditTagsTable())->pluck('tag')->sort()->values()->all())
        ->toBe(['first', 'second', 'third']);
});

it('says a label once when a batch repeats it on the same entry', function (): void {
    $audit = app(DatabaseLedger::class)->write(auditData(['tags' => ['billing', 'billing']]));

    expect(DB::table(auditTagsTable())->where('audit_id', $audit->id)->count())->toBe(1);
});

it('keeps the labels of an entry a secondary destination is handed', function (): void {
    $sealed = app(MemoryLedger::class)->write(auditData(['tags' => ['billing', 'refund']]));

    app(DatabaseLedger::class)->append($sealed);

    expect(DB::table(auditTagsTable())->where('audit_id', $sealed->id)->pluck('tag')->sort()->values()->all())
        ->toBe(['billing', 'refund'])
        ->and(Audit::query()->findOrFail($sealed->id)->hash)->toBe($sealed->hash);
});

it('stores an entry that says nothing about its labels', function (): void {
    $sealed = app(MemoryLedger::class)->write(auditData(['tags' => ['billing']]));
    $sealed->unsetRelation('tags');

    app(DatabaseLedger::class)->append($sealed);

    expect(DB::table(auditTagsTable())->where('audit_id', $sealed->id)->count())->toBe(0)
        ->and(Audit::query()->findOrFail($sealed->id)->hash)->toBe($sealed->hash);
});

it('hands a memory ledger back the labels it was given', function (): void {
    $audit = app(MemoryLedger::class)->write(auditData(['tags' => ['billing']]));

    expect($audit->tags->pluck('tag')->all())->toBe(['billing']);
});
