<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Models\Audit;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditQuery;
use function ElPandaPe\Sentinel\Tests\hasher;

beforeEach(function (): void {
    $this->ledger = app(MemoryLedger::class);
});

it('chains in memory exactly like a ledger with a table under it', function (): void {
    $first = $this->ledger->write(auditData());
    $second = $this->ledger->write(auditData());

    expect($first->sequence)->toBe(1)
        ->and($first->previous_hash)->toBeNull()
        ->and($second->sequence)->toBe(2)
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($second->hash)->toBe(hasher()->hash($second));
});

it('writes nothing to the database', function (): void {
    $this->ledger->write(auditData());

    expect(Audit::query()->count())->toBe(0);
});

it('hands back an entry that was never persisted', function (): void {
    expect($this->ledger->write(auditData())->exists)->toBeFalse();
});

it('keeps each stream on its own count', function (): void {
    $this->ledger->write(auditData(['stream' => 'alpha']));

    expect($this->ledger->write(auditData(['stream' => 'beta']))->sequence)->toBe(1);
});

it('finds what it wrote and nothing else', function (): void {
    $audit = $this->ledger->write(auditData());

    expect($this->ledger->find($audit->id)?->hash)->toBe($audit->hash)
        ->and($this->ledger->find('01JXXXXXXXXXXXXXXXXXXXXXXX'))->toBeNull();
});

it('walks what it wrote, bounded the same way', function (): void {
    $this->ledger->writeMany([auditData(), auditData(), auditData()]);

    expect(collect($this->ledger->stream('global'))->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and(collect($this->ledger->stream('global')->range(2))->pluck('sequence')->all())->toBe([2, 3]);
});

it('takes an entry it did not seal without touching it', function (): void {
    $sealed = app(EntryBuilder::class)->build(auditData(), 'imported', 7, str_repeat('a', 64), null);

    $this->ledger->append($sealed);

    expect($this->ledger->find($sealed->id)?->hash)->toBe($sealed->hash)
        ->and(collect($this->ledger->stream('imported'))->pluck('sequence')->all())->toBe([7]);
});

it('counts a version per subject out of what it holds', function (): void {
    $subject = ['subject_type' => 'fixture', 'subject_id' => '1'];

    expect($this->ledger->write(auditData($subject))->version)->toBe(1)
        ->and($this->ledger->write(auditData($subject))->version)->toBe(2)
        ->and($this->ledger->write(auditData())->version)->toBeNull();
});

it('writes nothing for an empty batch', function (): void {
    expect($this->ledger->writeMany([])->all())->toBeEmpty();
});

it('says out loud that the query api has not arrived yet', function (): void {
    expect(fn (): mixed => $this->ledger->query(auditQuery($this->ledger)))->toThrow(LedgerException::class);
});

it('counts a version per subject in memory too', function (): void {
    $written = $this->ledger->writeMany([
        auditData(['subject_type' => 'user', 'subject_id' => '1']),
        auditData(['subject_type' => 'user', 'subject_id' => '2']),
        auditData(['subject_type' => 'user', 'subject_id' => '1']),
        auditData(['subject_type' => 'role', 'subject_id' => '1']),
    ]);

    expect($written->pluck('version')->all())->toBe([1, 1, 2, 1]);
});

it('leaves an entry with half a subject out of the version count in memory too', function (): void {
    $written = $this->ledger->writeMany([
        auditData(['subject_type' => 'user']),
        auditData(['subject_id' => '1']),
    ]);

    expect($written->pluck('version')->all())->toBe([null, null]);
});

it('tells seven subjects apart however their type and key run together', function (): void {
    $written = $this->ledger->writeMany([
        auditData(['subject_type' => 'user', 'subject_id' => '1']),
        auditData(['subject_type' => 'user', 'subject_id' => '2']),
        auditData(['subject_type' => 'role', 'subject_id' => '1']),
        auditData(['subject_type' => 'user', 'subject_id' => '11']),
        auditData(['subject_type' => 'user1', 'subject_id' => '1']),
        auditData(['subject_type' => 'ab', 'subject_id' => 'c']),
        auditData(['subject_type' => 'b', 'subject_id' => 'ca']),
    ]);

    expect($written->pluck('version')->all())->toBe([1, 1, 1, 1, 1, 1, 1]);
});
