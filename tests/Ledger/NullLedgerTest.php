<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\hasher;

beforeEach(function (): void {
    $this->ledger = app(NullLedger::class);
});

it('seals and chains an entry it keeps nothing of', function (): void {
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

it('does not find the entry it just handed back', function (): void {
    expect($this->ledger->find($this->ledger->write(auditData())->id))->toBeNull();
});

it('walks nothing, however much was written to it', function (): void {
    $this->ledger->writeMany([auditData(), auditData(), auditData()]);

    expect(iterator_to_array($this->ledger->stream('global')))->toBeEmpty();
});

it('keeps each stream on its own count', function (): void {
    $this->ledger->write(auditData(['stream' => 'alpha']));

    expect($this->ledger->write(auditData(['stream' => 'beta']))->sequence)->toBe(1);
});

it('continues the chain from an entry it was appended', function (): void {
    $sealed = app(EntryBuilder::class)->build(auditData(['stream' => 'imported']), 'imported', 7, null, null);

    $this->ledger->append($sealed);
    $next = $this->ledger->write(auditData(['stream' => 'imported']));

    expect($next->sequence)->toBe(8)
        ->and($next->previous_hash)->toBe($sealed->hash);
});

it('counts a version per subject without holding an entry', function (): void {
    $subject = ['subject_type' => 'fixture', 'subject_id' => '1'];

    expect($this->ledger->write(auditData($subject))->version)->toBe(1)
        ->and($this->ledger->write(auditData($subject))->version)->toBe(2)
        ->and($this->ledger->write(auditData())->version)->toBeNull();
});

it('writes nothing for an empty batch', function (): void {
    expect($this->ledger->writeMany([])->all())->toBeEmpty();
});

it('says out loud that the query api has not arrived yet', function (): void {
    expect(fn (): mixed => $this->ledger->query(new AuditQuery))->toThrow(LedgerException::class);
});

it('leaves an entry with half a subject out of the version count', function (): void {
    $written = $this->ledger->writeMany([
        auditData(['subject_type' => 'user']),
        auditData(['subject_id' => '1']),
    ]);

    expect($written->pluck('version')->all())->toBe([null, null]);
});
