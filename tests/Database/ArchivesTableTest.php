<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Sentinel\Tests\archiveRow;
use function ElPandaPe\Sentinel\Tests\archivesTable;
use function ElPandaPe\Sentinel\Tests\frozenUlid;

it('creates the register of retired ranges with the columns the contract names', function (): void {
    $columns = array_column(Schema::getColumns(archivesTable()), 'name');

    expect($columns)->toHaveCount(10)
        ->and($columns)->toEqualCanonicalizing([
            'id',
            'stream',
            'sequence_from',
            'sequence_to',
            'records',
            'disk',
            'path',
            'checksum',
            'compressed',
            'created_at',
        ]);
});

it('indexes both ends of the range, each over the exact columns', function (): void {
    $indexes = array_map(
        static fn (array $index): string => implode(', ', $index['columns']),
        array_values(array_filter(
            Schema::getIndexes(archivesTable()),
            static fn (array $index): bool => $index['primary'] === false,
        )),
    );

    sort($indexes);

    expect($indexes)->toBe(['stream, sequence_from', 'stream, sequence_to']);
});

it('leaves both indexes open, because registering a range twice is legal', function (): void {
    DB::table(archivesTable())->insert(archiveRow());
    DB::table(archivesTable())->insert(archiveRow(['id' => frozenUlid('TWIN')]));

    expect(DB::table(archivesTable())->count())->toBe(2);
});

it('takes a range that left without being written anywhere', function (): void {
    DB::table(archivesTable())->insert(archiveRow([
        'disk' => null,
        'path' => null,
        'checksum' => null,
        'compressed' => null,
    ]));

    $range = DB::table(archivesTable())->where('id', frozenUlid('ARCH'))->first();

    expect($range->disk)->toBeNull()
        ->and($range->path)->toBeNull()
        ->and($range->checksum)->toBeNull()
        ->and($range->compressed)->toBeNull()
        ->and($range->records)->toBe(4);
});

it('names the codec rather than answering whether there was one', function (): void {
    DB::table(archivesTable())->insert(archiveRow(['compressed' => 'zstd']));

    expect(DB::table(archivesTable())->where('id', frozenUlid('ARCH'))->value('compressed'))->toBe('zstd');
});

it('leaves no trace after rolling back', function (): void {
    $this->artisan('migrate:rollback')->run();

    expect(Schema::hasTable(archivesTable()))->toBeFalse();
});
