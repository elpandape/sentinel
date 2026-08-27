<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditRow;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\hasher;

beforeEach(function (): void {
    $this->ledger = app(DatabaseLedger::class);
});

it('writes the first entry of a chain with no previous link', function (): void {
    $audit = $this->ledger->write(auditData());

    expect($audit->sequence)->toBe(1)
        ->and($audit->previous_hash)->toBeNull()
        ->and($audit->stream)->toBe('global')
        ->and($audit->payload_version)->toBe(1)
        ->and($audit->algorithm)->toBe('sha256')
        ->and($audit->hash)->toBe(hasher()->hash($audit))
        ->and($audit->exists)->toBeTrue();
});

it('chains every entry to the one before it', function (): void {
    $first = $this->ledger->write(auditData());
    $second = $this->ledger->write(auditData());

    expect($second->sequence)->toBe(2)
        ->and($second->previous_hash)->toBe($first->hash);
});

it('numbers each stream on its own', function (): void {
    $this->ledger->write(auditData(['stream' => 'alpha']));
    $beta = $this->ledger->write(auditData(['stream' => 'beta']));

    expect($beta->sequence)->toBe(1)
        ->and($beta->previous_hash)->toBeNull();
});

it('persists what it returns', function (): void {
    $audit = $this->ledger->write(auditData());

    expect($this->ledger->find($audit->id)?->hash)->toBe($audit->hash)
        ->and(Audit::query()->count())->toBe(1);
});

it('writes an entry that verifies against the row it stored', function (): void {
    $written = $this->ledger->write(auditData(['tenant_id' => 'acme', 'metadata' => ['b' => 1, 'a' => 2]]));

    $stored = Audit::query()->firstOrFail();

    expect(hasher()->hash($stored))->toBe($written->hash);
});

it('marks an entry it was appended as one the row already holds', function (): void {
    $sealed = app(EntryBuilder::class)->build(auditData(), 'imported', 1, null, null);

    $appended = app(DatabaseLedger::class)->append($sealed);

    expect($appended->exists)->toBeTrue()
        ->and($appended->wasRecentlyCreated)->toBeTrue()
        ->and(Audit::query()->where('id', $sealed->id)->count())->toBe(1);
});

it('finds nothing for an id that was never written', function (): void {
    expect($this->ledger->find('01JXXXXXXXXXXXXXXXXXXXXXXX'))->toBeNull();
});

it('counts a version per subject and leaves it null without one', function (): void {
    $subject = ['subject_type' => 'fixture', 'subject_id' => '1'];

    expect($this->ledger->write(auditData($subject))->version)->toBe(1)
        ->and($this->ledger->write(auditData($subject))->version)->toBe(2)
        ->and($this->ledger->write(auditData(['subject_type' => 'fixture', 'subject_id' => '2']))->version)->toBe(1)
        ->and($this->ledger->write(auditData())->version)->toBeNull();
});

it('carries every field of the capture into the entry', function (): void {
    $capture = [
        'subject_type' => 'user',
        'subject_id' => '7',
        'actor_type' => 'admin',
        'actor_id' => '1',
        'impersonator_type' => 'support',
        'impersonator_id' => '2',
        'tenant_id' => 'acme',
        'transaction_id' => '01JTRANSACTION000000000001',
        'request_id' => 'req-1',
        'trace_id' => 'trace-1',
        'span_id' => 'span-1',
        'context' => ['ip' => '127.0.0.1'],
        'before' => ['name' => 'old'],
        'after' => ['name' => 'new'],
        'changes' => ['name' => ['old', 'new']],
        'metadata' => ['a' => 1],
        'encryption' => ['fields' => ['name'], 'key_id' => 'default'],
        'criteria' => ['id' => 1],
        'affected_rows' => 7,
        'source_audit_id' => '01JSOURCE00000000000000001',
        'capture_id' => '01JCAPTURE0000000000000001',
    ];

    $audit = $this->ledger->write(auditData($capture));

    $stored = Audit::query()->firstOrFail();

    foreach ($capture as $column => $value) {
        expect($stored->getAttribute($column))->toBe($value);
    }

    expect($stored->audit_type)->toBe('model')
        ->and($stored->event)->toBe('created')
        ->and($stored->severity)->toBe(Severity::Info)
        ->and($stored->source)->toBe(Source::System)
        ->and($stored->created_at)->not->toBeNull()
        ->and($stored->occurred_at->format('Y-m-d H:i:s.u'))->toBe('2026-08-26 10:00:00.000000')
        ->and($audit->id)->toBe($stored->id);
});

it('hashes what it carried, so a field the builder dropped would not verify', function (): void {
    $audit = $this->ledger->write(auditData([
        'actor_type' => 'admin',
        'actor_id' => '1',
        'impersonator_type' => 'support',
        'impersonator_id' => '2',
        'transaction_id' => '01JTRANSACTION000000000001',
        'request_id' => 'req-1',
        'trace_id' => 'trace-1',
        'span_id' => 'span-1',
        'encryption' => ['fields' => ['name']],
        'source_audit_id' => '01JSOURCE00000000000000001',
    ]));

    $stripped = Audit::query()->firstOrFail();
    $stripped->trace_id = null;

    expect(hasher()->hash($stripped))->not->toBe($audit->hash);
});

it('stamps when it was written apart from when it happened', function (): void {
    $audit = $this->ledger->write(auditData());

    expect($audit->created_at->format('Y'))->not->toBe('2026-08-26 10:00:00.000000')
        ->and($audit->occurred_at->format('Y-m-d H:i:s.u'))->toBe('2026-08-26 10:00:00.000000');
});

it('hands back a stream that walks what it wrote', function (): void {
    $this->ledger->write(auditData());
    $this->ledger->write(auditData());

    expect(collect($this->ledger->stream('global'))->pluck('sequence')->all())->toBe([1, 2])
        ->and($this->ledger->stream('global')->name())->toBe('global');
});

it('says out loud that the query api has not arrived yet', function (): void {
    expect(fn (): mixed => $this->ledger->query(new AuditQuery))->toThrow(LedgerException::class);
});

it('retries when the unique index says another writer got there first', function (): void {
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        if ($raced || ! str_contains($query->sql, 'desc')) {
            return;
        }

        $raced = true;

        DB::table(auditsTable())->insert(auditRow(['sequence' => 1]));
    });

    $audit = $this->ledger->write(auditData());

    expect($raced)->toBeTrue()
        ->and($audit->sequence)->toBe(1)
        ->and(Audit::query()->count())->toBe(1);
});

it('gives up after a bounded number of attempts instead of spinning forever', function (): void {
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'desc')) {
            DB::table(auditsTable())->insert(auditRow(['sequence' => 1]));
        }
    });

    expect(fn (): Audit => $this->ledger->write(auditData()))
        ->toThrow(UniqueConstraintViolationException::class);
});
