<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function ElPandaPe\Sentinel\Tests\auditTagsTable;

it('creates the tags table with its two columns', function (): void {
    $columns = array_column(Schema::getColumns(auditTagsTable()), 'name');

    expect($columns)->toHaveCount(2)
        ->and($columns)->toEqualCanonicalizing(['audit_id', 'tag']);
});

it('creates the two indexes over the exact columns, in order', function (): void {
    $indexes = array_map(
        static fn (array $index): string => ($index['unique'] ? 'unique' : 'index').'('.implode(', ', $index['columns']).')',
        array_values(array_filter(
            Schema::getIndexes(auditTagsTable()),
            static fn (array $index): bool => $index['primary'] === false,
        )),
    );

    sort($indexes);

    expect($indexes)->toBe([
        'index(tag, audit_id)',
        'unique(audit_id, tag)',
    ]);
});

it('refuses the same label twice on one entry', function (): void {
    $audit = Str::ulid()->toString();

    DB::table(auditTagsTable())->insert(['audit_id' => $audit, 'tag' => 'billing']);

    expect(fn () => DB::table(auditTagsTable())->insert(['audit_id' => $audit, 'tag' => 'billing']))
        ->toThrow(QueryException::class);
});

it('accepts the same label on another entry', function (): void {
    DB::table(auditTagsTable())->insert(['audit_id' => Str::ulid()->toString(), 'tag' => 'billing']);
    DB::table(auditTagsTable())->insert(['audit_id' => Str::ulid()->toString(), 'tag' => 'billing']);

    expect(DB::table(auditTagsTable())->where('tag', 'billing')->count())->toBe(2);
});

it('accepts many labels on one entry', function (): void {
    $audit = Str::ulid()->toString();

    DB::table(auditTagsTable())->insert([
        ['audit_id' => $audit, 'tag' => 'billing'],
        ['audit_id' => $audit, 'tag' => 'refund'],
    ]);

    expect(DB::table(auditTagsTable())->where('audit_id', $audit)->count())->toBe(2);
});

it('keeps a label whose entry no longer exists', function (): void {
    DB::table(auditTagsTable())->insert(['audit_id' => Str::ulid()->toString(), 'tag' => 'orphan']);

    expect(DB::table(auditTagsTable())->where('tag', 'orphan')->count())->toBe(1);
});

it('leaves no trace after rolling back', function (): void {
    $this->artisan('migrate:rollback')->run();

    expect(Schema::hasTable(auditTagsTable()))->toBeFalse();
});
