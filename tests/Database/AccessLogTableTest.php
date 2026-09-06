<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Sentinel\Tests\accessLogTable;
use function ElPandaPe\Sentinel\Tests\accessRow;
use function ElPandaPe\Sentinel\Tests\frozenUlid;

it('creates the projection of who read the trail with the columns the contract names', function (): void {
    $columns = array_column(Schema::getColumns(accessLogTable()), 'name');

    expect($columns)->toHaveCount(9)
        ->and($columns)->toEqualCanonicalizing([
            'id',
            'audit_id',
            'actor_type',
            'actor_id',
            'tenant_id',
            'query',
            'results',
            'context',
            'created_at',
        ]);
});

it('indexes what this table is asked: who has been reading, and the entry that proves it', function (): void {
    $indexes = array_map(
        static fn (array $index): string => implode(', ', $index['columns']),
        array_values(array_filter(
            Schema::getIndexes(accessLogTable()),
            static fn (array $index): bool => $index['primary'] === false,
        )),
    );

    sort($indexes);

    expect($indexes)->toBe(['actor_type, actor_id, created_at', 'audit_id']);
});

it('holds the entry that proves the row without a key to it, because the entry can be pruned away', function (): void {
    DB::table(accessLogTable())->insert(accessRow());

    expect(Schema::getForeignKeys(accessLogTable()))->toBeEmpty()
        ->and(DB::table(accessLogTable())->where('id', frozenUlid('READ'))->value('audit_id'))
        ->toBe(frozenUlid('ENTR'));
});

it('takes a read nobody was authenticated for', function (): void {
    DB::table(accessLogTable())->insert(accessRow([
        'actor_type' => null,
        'actor_id' => null,
        'tenant_id' => null,
    ]));

    $read = DB::table(accessLogTable())->where('id', frozenUlid('READ'))->first();

    expect($read->actor_type)->toBeNull()
        ->and($read->actor_id)->toBeNull()
        ->and($read->tenant_id)->toBeNull()
        ->and($read->results)->toBe(12);
});

it('records a read that matched nothing, which is the one worth asking about', function (): void {
    DB::table(accessLogTable())->insert(accessRow(['results' => 0]));

    expect(DB::table(accessLogTable())->where('id', frozenUlid('READ'))->value('results'))->toBe(0);
});

it('leaves no trace after rolling back', function (): void {
    $this->artisan('migrate:rollback')->run();

    expect(Schema::hasTable(accessLogTable()))->toBeFalse();
});
