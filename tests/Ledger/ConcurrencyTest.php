<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\StreamGate;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditRow;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\lockTimeout;
use function ElPandaPe\Sentinel\Tests\raceTheGate;
use function ElPandaPe\Sentinel\Tests\rivalConnection;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    $this->rival = rivalConnection();

    if ($this->rival === null) {
        $this->markTestSkipped('A second connection to an in-memory SQLite database is a different database.');
    }

    $this->ledger = app(DatabaseLedger::class);
});

it('leaves neither a duplicate nor a hole when a rival writer races it', function (): void {
    raceTheGate($this->rival);

    $this->ledger->write(auditData());

    $sequences = DB::table(auditsTable())->orderBy('sequence')->pluck('sequence')->all();

    expect($sequences)->toBe(range(1, count($sequences)));
});

it('writes an entry that still verifies against its own row after a race', function (): void {
    raceTheGate($this->rival);

    $written = $this->ledger->write(auditData());

    expect(verifier()->verifyEntry($written))->toBeTrue();
});

it('holds an outside writer on the gap lock while a gate owns the stream', function (): void {
    $this->ledger->write(auditData());

    DB::beginTransaction();
    new StreamGate(DB::connection(), auditsTable())->tail('global');

    $blocked = false;

    try {
        DB::connection($this->rival)->statement(lockTimeout());
        DB::connection($this->rival)->table(auditsTable())->insert(auditRow(['sequence' => 2]));
    } catch (Throwable) {
        $blocked = true;
    } finally {
        DB::rollBack();
    }

    expect($blocked)->toBeTrue();
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'mysql',
    'Only InnoDB extends the lock over the gap an outside insert would land in.',
);

it('takes the next sequence when an outside writer slipped past the gate', function (): void {
    raceTheGate($this->rival);

    expect($this->ledger->write(auditData())->sequence)->toBe(2);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'An advisory lock serializes the writers that ask for it, not the ones that do not.',
);

it('makes a second writer of the same stream wait for the first', function (): void {
    $this->ledger->write(auditData());

    DB::beginTransaction();
    new StreamGate(DB::connection(), auditsTable())->tail('global');

    $blocked = false;

    try {
        DB::connection($this->rival)->statement(lockTimeout());
        DB::connection($this->rival)->transaction(function (): void {
            new StreamGate(DB::connection($this->rival), auditsTable())->tail('global');
        });
    } catch (Throwable) {
        $blocked = true;
    } finally {
        DB::rollBack();
    }

    expect($blocked)->toBeTrue();
});

it('lets a writer of another stream through while one stream is locked', function (): void {
    $this->ledger->write(auditData(['stream' => 'alpha']));
    $this->ledger->write(auditData(['stream' => 'beta']));

    DB::beginTransaction();
    new StreamGate(DB::connection(), auditsTable())->tail('alpha');

    try {
        DB::connection($this->rival)->statement(lockTimeout());
        $tail = DB::connection($this->rival)->transaction(
            fn (): int => new StreamGate(DB::connection($this->rival), auditsTable())->tail('beta')->sequence,
        );

        expect($tail)->toBe(1);
    } finally {
        DB::rollBack();
    }
});

it('leaves neither a duplicate nor a hole when a rival races a whole batch', function (): void {
    raceTheGate($this->rival);

    $this->ledger->writeMany([auditData(), auditData(), auditData()]);

    $sequences = DB::table(auditsTable())->orderBy('sequence')->pluck('sequence')->all();

    expect($sequences)->toBe(range(1, count($sequences)))
        ->and(count($sequences))->toBeGreaterThanOrEqual(3);
});

it('writes a batch whose every entry still verifies against its own row after a race', function (): void {
    raceTheGate($this->rival);

    $written = $this->ledger->writeMany([auditData(), auditData(), auditData()]);

    expect($written->every(static fn (Audit $audit): bool => verifier()->verifyEntry($audit)))->toBeTrue()
        ->and($written->pluck('previous_hash')->skip(1)->values()->all())
        ->toBe($written->pluck('hash')->take(2)->all());
});
