<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditRelation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function ElPandaPe\Sentinel\Tests\auditRelationsTable;
use function ElPandaPe\Sentinel\Tests\insertAudit;
use function ElPandaPe\Sentinel\Tests\relationRow;

it('creates the relations table with the columns the contract names', function (): void {
    $columns = array_column(Schema::getColumns(auditRelationsTable()), 'name');

    expect($columns)->toHaveCount(7)
        ->and($columns)->toEqualCanonicalizing([
            'audit_id',
            'relation',
            'operation',
            'related_type',
            'related_id',
            'pivot_before',
            'pivot_after',
        ]);
});

it('creates the three indexes over the exact columns, in order', function (): void {
    $indexes = array_map(
        static fn (array $index): string => implode(', ', $index['columns']),
        array_values(array_filter(
            Schema::getIndexes(auditRelationsTable()),
            static fn (array $index): bool => $index['primary'] === false,
        )),
    );

    sort($indexes);

    expect($indexes)->toBe([
        'audit_id',
        'related_type, related_id, audit_id',
        'relation, audit_id',
    ]);
});

it('accepts many lines on one entry, which is what a sync writes', function (): void {
    $audit = Str::ulid()->toString();

    DB::table(auditRelationsTable())->insert([
        relationRow(['audit_id' => $audit, 'related_id' => '3', 'operation' => 'attach']),
        relationRow(['audit_id' => $audit, 'related_id' => '7', 'operation' => 'detach']),
    ]);

    expect(DB::table(auditRelationsTable())->where('audit_id', $audit)->count())->toBe(2);
});

it('accepts the same related on the same entry through two relations', function (): void {
    $audit = Str::ulid()->toString();

    DB::table(auditRelationsTable())->insert([
        relationRow(['audit_id' => $audit, 'relation' => 'roles']),
        relationRow(['audit_id' => $audit, 'relation' => 'permissions']),
    ]);

    expect(DB::table(auditRelationsTable())->where('audit_id', $audit)->count())->toBe(2);
});

it('takes a related id of any width, from an int to a ulid', function (): void {
    foreach (['7', Str::uuid()->toString(), Str::ulid()->toString()] as $id) {
        DB::table(auditRelationsTable())->insert(relationRow(['related_id' => $id]));
    }

    expect(DB::table(auditRelationsTable())->count())->toBe(3);
});

it('keeps a line whose entry no longer exists', function (): void {
    DB::table(auditRelationsTable())->insert(relationRow(['relation' => 'orphan']));

    expect(DB::table(auditRelationsTable())->where('relation', 'orphan')->count())->toBe(1);
});

it('reads a line back through the entry that owns it', function (): void {
    $id = Str::ulid()->toString();

    insertAudit(['id' => $id, 'audit_type' => 'relation', 'event' => 'synced']);
    DB::table(auditRelationsTable())->insert([
        relationRow(['audit_id' => $id, 'relation' => 'roles', 'related_id' => '9']),
        relationRow(['audit_id' => $id, 'relation' => 'permissions', 'related_id' => '2']),
    ]);

    $lines = Audit::query()->findOrFail($id)->relations;

    expect($lines)->toHaveCount(2)
        ->and($lines->map(static fn (AuditRelation $line): string => $line->relation)->all())
        ->toBe(['permissions', 'roles']);
});

it('gives the pivot states back as maps, not as text', function (): void {
    $id = Str::ulid()->toString();

    insertAudit(['id' => $id, 'audit_type' => 'relation', 'event' => 'synced']);
    DB::table(auditRelationsTable())->insert(relationRow([
        'audit_id' => $id,
        'pivot_before' => json_encode(['expires_at' => '2026-01-01']),
        'pivot_after' => json_encode(['expires_at' => '2027-01-01']),
    ]));

    $line = Audit::query()->findOrFail($id)->relations->firstOrFail();

    expect($line->pivot_before)->toBe(['expires_at' => '2026-01-01'])
        ->and($line->pivot_after)->toBe(['expires_at' => '2027-01-01']);
});

it('tells a row that never existed from one that existed and carried nothing', function (): void {
    $id = Str::ulid()->toString();

    insertAudit(['id' => $id, 'audit_type' => 'relation', 'event' => 'attached']);
    DB::table(auditRelationsTable())->insert(relationRow([
        'audit_id' => $id,
        'pivot_before' => null,
        'pivot_after' => json_encode([]),
    ]));

    $line = Audit::query()->findOrFail($id)->relations->firstOrFail();

    expect($line->pivot_before)->toBeNull()
        ->and($line->pivot_after)->toBe([]);
});

it('leaves no trace after rolling back', function (): void {
    $this->artisan('migrate:rollback')->run();

    expect(Schema::hasTable(auditRelationsTable()))->toBeFalse();
});
