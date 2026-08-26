<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\insertAudit;

it('creates the audits table with its forty columns', function (): void {
    $columns = array_column(Schema::getColumns(auditsTable()), 'name');

    expect($columns)->toHaveCount(40)
        ->and($columns)->toEqualCanonicalizing([
            'id', 'stream', 'sequence', 'audit_type', 'event', 'severity',
            'subject_type', 'subject_id', 'actor_type', 'actor_id',
            'impersonator_type', 'impersonator_id', 'tenant_id', 'transaction_id',
            'request_id', 'trace_id', 'span_id', 'source', 'version',
            'context', 'before', 'after', 'changes', 'metadata',
            'payload_version', 'encryption', 'algorithm', 'previous_hash', 'hash',
            'signature', 'signature_key_id',
            'capture_id', 'source_audit_id', 'criteria', 'affected_rows',
            'redacted_at', 'redaction_reason', 'redacted_hash',
            'occurred_at', 'created_at',
        ]);
});

it('creates the eleven non primary indexes over the exact columns, in order', function (): void {
    $indexes = array_map(
        static fn (array $index): string => ($index['unique'] ? 'unique' : 'index').'('.implode(', ', $index['columns']).')',
        array_values(array_filter(
            Schema::getIndexes(auditsTable()),
            static fn (array $index): bool => $index['primary'] === false,
        )),
    );

    sort($indexes);

    expect($indexes)->toHaveCount(11)
        ->and($indexes)->toBe([
            'index(actor_type, actor_id, id)',
            'index(audit_type, created_at)',
            'index(event)',
            'index(request_id)',
            'index(severity, created_at)',
            'index(subject_type, subject_id, id)',
            'index(tenant_id, created_at)',
            'index(trace_id)',
            'index(transaction_id)',
            'unique(capture_id)',
            'unique(stream, sequence)',
        ]);
});

it('resolves the json type from the engine grammar', function (): void {
    $column = collect(Schema::getColumns(auditsTable()))->firstWhere('name', 'context');

    expect($column['type_name'])->toBe(match (DB::connection()->getDriverName()) {
        'pgsql' => 'jsonb',
        'mysql' => 'json',
        default => 'text',
    });
});

it('resolves the microsecond date type from the engine grammar', function (): void {
    $column = collect(Schema::getColumns(auditsTable()))->firstWhere('name', 'occurred_at');

    expect($column['type'])->toBe(match (DB::connection()->getDriverName()) {
        'pgsql' => 'timestamp(6) without time zone',
        'mysql' => 'datetime(6)',
        default => 'datetime',
    });
});

it('rejects a second entry with the same sequence inside a stream', function (): void {
    insertAudit(['stream' => 'global', 'sequence' => 1]);

    expect(fn () => insertAudit(['stream' => 'global', 'sequence' => 1]))
        ->toThrow(QueryException::class);
});

it('accepts the same sequence in a different stream', function (): void {
    insertAudit(['stream' => 'tenant:1', 'sequence' => 1]);
    insertAudit(['stream' => 'tenant:2', 'sequence' => 1]);

    expect(DB::table(auditsTable())->count())->toBe(2);
});

it('rejects a duplicate capture id', function (): void {
    $capture = Str::ulid()->toString();

    insertAudit(['sequence' => 1, 'capture_id' => $capture]);

    expect(fn () => insertAudit(['sequence' => 2, 'capture_id' => $capture]))
        ->toThrow(QueryException::class);
});

it('accepts many entries without a capture id', function (): void {
    insertAudit(['sequence' => 1]);
    insertAudit(['sequence' => 2]);

    expect(DB::table(auditsTable())->whereNull('capture_id')->count())->toBe(2);
});

it('leaves the seven deferred columns null when nothing writes them', function (): void {
    insertAudit(['sequence' => 1]);

    $row = (array) DB::table(auditsTable())->first();

    expect(array_intersect_key($row, array_flip([
        'capture_id', 'source_audit_id', 'criteria', 'affected_rows',
        'redacted_at', 'redaction_reason', 'redacted_hash',
    ])))->toHaveCount(7)->each->toBeNull();
});

it('has no updated_at column', function (): void {
    expect(Schema::hasColumn(auditsTable(), 'updated_at'))->toBeFalse();
});

it('leaves no trace after rolling back', function (): void {
    $this->artisan('migrate:rollback')->run();

    expect(Schema::hasTable(auditsTable()))->toBeFalse();
});
