<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditRow;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\divisionForThisEngine;
use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\partitionTheTrail;
use function ElPandaPe\Sentinel\Tests\seedChain;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    $division = divisionForThisEngine();

    if ($division === null) {
        $this->markTestSkipped('SQLite does not partition, so there is no divided table to write to.');
    }

    partitionTheTrail($division);
});

it('writes a chain that links exactly as it does on a flat table', function (): void {
    seedChain(5);

    $entries = DB::table(auditsTable())->orderBy('sequence')->get();

    expect($entries)->toHaveCount(5)
        ->and($entries[0]->previous_hash)->toBeNull()
        ->and($entries[1]->previous_hash)->toBe($entries[0]->hash)
        ->and($entries[4]->previous_hash)->toBe($entries[3]->hash);
});

it('verifies the same as the undivided table does', function (): void {
    seedChain(5);

    expect(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('takes an entry whose clock falls outside every declared range', function (): void {
    ledger()->write(auditData(['occurred_at' => new DateTimeImmutable('2099-01-01 00:00:00')]));

    DB::table(auditsTable())->update(['created_at' => '2099-06-01 00:00:00.000000']);

    expect(DB::table(auditsTable())->count())->toBe(1);
});

it('keeps the guarantee inside a tenant when the division is by tenant', function (): void {
    partitionTheTrail('pgsql-tenant');

    DB::statement('create table '.auditsTable().'_acme partition of '.auditsTable()." for values in ('acme')");
    DB::statement('create unique index '.auditsTable().'_acme_ss on '.auditsTable().'_acme (stream, sequence)');

    DB::table(auditsTable())->insert(auditRow(['sequence' => 1, 'stream' => 'acme', 'tenant_id' => 'acme']));

    expect(fn (): bool => DB::table(auditsTable())->insert(auditRow([
        'id' => frozenUlid('SECOND'), 'sequence' => 1, 'stream' => 'acme', 'tenant_id' => 'acme',
    ])))->toThrow(UniqueConstraintViolationException::class);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'Only PostgreSQL lets a unique index live on one partition rather than on the parent.',
);

it('still takes an entry that belongs to no tenant when the division is by tenant', function (): void {
    partitionTheTrail('pgsql-tenant');

    seedChain(3);

    expect(DB::table(auditsTable())->whereNull('tenant_id')->count())->toBe(3)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'The tenant division is a PostgreSQL stub: MySQL cannot hold the chain guarantee under it.',
);
