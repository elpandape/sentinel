<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;

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

it('answers a query by walking what it holds, across every stream', function (): void {
    $this->ledger->write(auditData(['stream' => 'alpha', 'tenant_id' => 'acme']));
    $this->ledger->write(auditData(['stream' => 'beta', 'tenant_id' => 'globex']));
    $this->ledger->write(auditData(['stream' => 'beta', 'tenant_id' => 'acme']));

    expect($this->ledger->query(auditQuery($this->ledger)->forTenant('acme'))->pluck('stream')->all())
        ->toBe(['alpha', 'beta']);
});

it('narrows on each published criterion and leaves the rest of the trail out', function (
    Closure $narrow,
    array $matching,
): void {
    $this->ledger->write(auditData());
    $wanted = $this->ledger->write(auditData($matching));

    $found = $this->ledger->query($narrow(auditQuery($this->ledger)));

    expect($found)->toHaveCount(1)
        ->and($found->firstOrFail()->id)->toBe($wanted->id);
})->with([
    'subject' => [
        fn (AuditQuery $query): AuditQuery => $query->for('invoice', 500),
        ['subject_type' => 'invoice', 'subject_id' => '500'],
    ],
    'actor' => [
        fn (AuditQuery $query): AuditQuery => $query->by('user', 1),
        ['actor_type' => 'user', 'actor_id' => '1'],
    ],
    'event' => [
        fn (AuditQuery $query): AuditQuery => $query->whereEvent(AuditEvent::Deleted),
        ['event' => 'deleted'],
    ],
    'severity' => [
        fn (AuditQuery $query): AuditQuery => $query->whereSeverity(Severity::Critical),
        ['severity' => Severity::Critical],
    ],
    'source' => [
        fn (AuditQuery $query): AuditQuery => $query->whereSource(Source::Queue),
        ['source' => Source::Queue],
    ],
    'tenant' => [
        fn (AuditQuery $query): AuditQuery => $query->forTenant('acme'),
        ['tenant_id' => 'acme'],
    ],
    'transaction' => [
        fn (AuditQuery $query): AuditQuery => $query->inTransaction('01JTRANSACTION000000000000'),
        ['transaction_id' => '01JTRANSACTION000000000000'],
    ],
    'trace' => [
        fn (AuditQuery $query): AuditQuery => $query->withTrace('4bf92f3577b34da6a3ce929d0e0e4736'),
        ['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736'],
    ],
]);

it('tells an entry with half a reference apart from one with the whole of it', function (): void {
    $this->ledger->write(auditData(['subject_type' => 'invoice', 'subject_id' => '499']));
    $this->ledger->write(auditData(['subject_type' => 'order', 'subject_id' => '500']));

    expect($this->ledger->query(auditQuery($this->ledger)->for('invoice', 500)))->toBeEmpty();
});

it('bounds a period by both ends of the ledger clock', function (): void {
    $this->travelTo('2026-08-01 10:00:00');
    $this->ledger->write(auditData(['event' => 'early']));
    $this->travelTo('2026-08-15 10:00:00');
    $this->ledger->write(auditData(['event' => 'inside']));
    $this->travelTo('2026-08-31 10:00:00');
    $this->ledger->write(auditData(['event' => 'late']));

    $found = $this->ledger->query(auditQuery($this->ledger)->between(
        new DateTimeImmutable('2026-08-10 00:00:00'),
        new DateTimeImmutable('2026-08-20 00:00:00'),
    ));

    expect($found->pluck('event')->all())->toBe(['inside']);
});

it('gives the oldest entry first, and the newest first on request', function (): void {
    $this->ledger->write(auditData(['stream' => 'beta', 'event' => 'first']));
    $this->ledger->write(auditData(['stream' => 'alpha', 'event' => 'second']));
    $this->ledger->write(auditData(['stream' => 'beta', 'event' => 'third']));

    expect($this->ledger->query(auditQuery($this->ledger))->pluck('event')->all())
        ->toBe(['first', 'second', 'third'])
        ->and($this->ledger->query(auditQuery($this->ledger)->latest())->pluck('event')->all())
        ->toBe(['third', 'second', 'first']);
});

it('breaks a tie on the ledger clock by the identifier, not by the order it was handed them', function (): void {
    $this->travelTo('2026-08-15 10:00:00');

    foreach (['C', 'A', 'B'] as $position => $suffix) {
        $this->ledger->append(
            app(EntryBuilder::class)
                ->build(auditData(), 'global', $position + 1, null, null)
                ->forceFill(['id' => str_pad('01JTIE', 25, '0').$suffix])
        );
    }

    expect($this->ledger->query(auditQuery($this->ledger))->pluck('id')->all())
        ->toBe([str_pad('01JTIE', 25, '0').'A', str_pad('01JTIE', 25, '0').'B', str_pad('01JTIE', 25, '0').'C']);
});

it('orders entries stamped in the same microsecond by the ulid that carries the instant', function (): void {
    $this->travelTo('2026-08-15 10:00:00');
    $written = $this->ledger->writeMany([auditData(), auditData(), auditData()]);

    expect($this->ledger->query(auditQuery($this->ledger))->pluck('id')->all())
        ->toBe($written->pluck('id')->all());
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

it('names every stream it kept, in an order two runs can be diffed on', function (): void {
    $ledger = app(MemoryLedger::class);

    expect($ledger->streams())->toBeEmpty();

    $ledger->write(auditData(['stream' => 'second']));
    $ledger->write(auditData(['stream' => 'first']));

    expect($ledger->streams())->toBe(['first', 'second']);
});
