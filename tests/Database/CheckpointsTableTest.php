<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Sentinel\Tests\checkpointRow;
use function ElPandaPe\Sentinel\Tests\checkpointsTable;
use function ElPandaPe\Sentinel\Tests\frozenUlid;

it('creates the anchor table with the columns the contract names, and no column for the anchor before it', function (): void {
    $columns = array_column(Schema::getColumns(checkpointsTable()), 'name');

    expect($columns)->toHaveCount(9)
        ->and($columns)->toEqualCanonicalizing([
            'id',
            'stream',
            'sequence_from',
            'sequence_to',
            'root_hash',
            'algorithm',
            'signature',
            'key_id',
            'created_at',
        ]);
});

it('indexes both ends of the range, each over the exact columns', function (): void {
    $indexes = array_map(
        static fn (array $index): string => implode(', ', $index['columns']),
        array_values(array_filter(
            Schema::getIndexes(checkpointsTable()),
            static fn (array $index): bool => $index['primary'] === false,
        )),
    );

    sort($indexes);

    expect($indexes)->toBe(['stream, sequence_from', 'stream, sequence_to']);
});

it('refuses a second anchor starting where one already starts', function (): void {
    DB::table(checkpointsTable())->insert(checkpointRow());

    expect(fn (): bool => DB::table(checkpointsTable())->insert(checkpointRow([
        'id' => frozenUlid('TWIN'),
        'sequence_to' => 8,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('takes two anchors of the same range on different streams', function (): void {
    DB::table(checkpointsTable())->insert(checkpointRow());
    DB::table(checkpointsTable())->insert(checkpointRow([
        'id' => frozenUlid('FORK'),
        'stream' => 'tenant:acme',
    ]));

    expect(DB::table(checkpointsTable())->count())->toBe(2);
});

it('takes an anchor nobody signed, because signing is not what makes it an anchor', function (): void {
    DB::table(checkpointsTable())->insert(checkpointRow());

    $anchor = DB::table(checkpointsTable())->where('id', frozenUlid('ANCH'))->first();

    expect($anchor->signature)->toBeNull()
        ->and($anchor->key_id)->toBeNull();
});

it('leaves no trace after rolling back', function (): void {
    $this->artisan('migrate:rollback')->run();

    expect(Schema::hasTable(checkpointsTable()))->toBeFalse();
});
