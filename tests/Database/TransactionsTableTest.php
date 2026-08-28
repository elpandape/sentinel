<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\transactionRow;
use function ElPandaPe\Sentinel\Tests\transactionsTable;

it('creates the header table with the columns the contract names', function (): void {
    $columns = array_column(Schema::getColumns(transactionsTable()), 'name');

    expect($columns)->toHaveCount(9)
        ->and($columns)->toEqualCanonicalizing([
            'id',
            'name',
            'actor_type',
            'actor_id',
            'tenant_id',
            'started_at',
            'finished_at',
            'audits_count',
            'metadata',
        ]);
});

it('creates the three indexes over the exact columns, in order', function (): void {
    $indexes = array_map(
        static fn (array $index): string => implode(', ', $index['columns']),
        array_values(array_filter(
            Schema::getIndexes(transactionsTable()),
            static fn (array $index): bool => $index['primary'] === false,
        )),
    );

    sort($indexes);

    expect($indexes)->toBe([
        'name, started_at',
        'started_at',
        'tenant_id, started_at',
    ]);
});

it('keys the header by the identifier the entries carry', function (): void {
    $primary = array_values(array_filter(
        Schema::getIndexes(transactionsTable()),
        static fn (array $index): bool => $index['primary'] === true,
    ));

    expect($primary)->toHaveCount(1)
        ->and($primary[0]['columns'])->toBe(['id']);
});

it('opens a header before the operation has finished', function (): void {
    DB::table(transactionsTable())->insert(transactionRow([
        'id' => frozenUlid('OPEN'),
        'finished_at' => null,
    ]));

    $header = DB::table(transactionsTable())->where('id', frozenUlid('OPEN'))->first();

    expect($header)->not->toBeNull()
        ->and($header->finished_at)->toBeNull()
        ->and((int) $header->audits_count)->toBe(0);
});

it('takes an operation that wrote nothing', function (): void {
    DB::table(transactionsTable())->insert(transactionRow([
        'id' => frozenUlid('NONE'),
        'finished_at' => '2026-08-28 10:00:01.000000',
    ]));

    expect(DB::table(transactionsTable())->where('audits_count', 0)->count())->toBe(1);
});

it('completes a header that was opened, which sentinel_audits would refuse', function (): void {
    DB::table(transactionsTable())->insert(transactionRow(['id' => frozenUlid('SHUT')]));

    DB::table(transactionsTable())->where('id', frozenUlid('SHUT'))->update([
        'finished_at' => '2026-08-28 10:00:02.000000',
        'audits_count' => 3,
    ]);

    $header = DB::table(transactionsTable())->where('id', frozenUlid('SHUT'))->first();

    expect((int) $header->audits_count)->toBe(3)
        ->and($header->finished_at)->not->toBeNull();
});

it('keeps a header whose entries were never written', function (): void {
    DB::table(transactionsTable())->insert(transactionRow([
        'id' => frozenUlid('ORPH'),
        'name' => 'rolled-back',
    ]));

    expect(DB::table(transactionsTable())->where('name', 'rolled-back')->count())->toBe(1);
});

it('leaves no trace after rolling back', function (): void {
    $this->artisan('migrate:rollback')->run();

    expect(Schema::hasTable(transactionsTable()))->toBeFalse();
});
