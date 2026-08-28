<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Ledger\ChangedFieldPredicate;

/**
 * The SQL itself, letter for letter. Two of these three dialects are never executed by `make ci`,
 * which runs on SQLite alone, so nothing else in the suite would notice a stray edit to them until
 * `make test-dbs` ran — and what these assertions restate is exactly what that pass cannot check
 * cheaply. They exist to catch a change nobody meant to make; whether the SQL is *right* is settled
 * by the behavioural tests, which run against whichever engine is live.
 */
it('spells the sqlite dialect out', function (): void {
    [$sql, $bindings] = new ChangedFieldPredicate()->for('sqlite', 'changes', '/email');

    expect($sql)->toBe(
        'exists (select 1 from json_each(case when json_valid(changes) then changes else \'[]\' end) je'
        .' where (case when je.type = \'object\' then json_extract(je.value, \'$.path\') end) = ?'
        .' or substr((case when je.type = \'object\' then json_extract(je.value, \'$.path\') end), 1, length(?)) = ?)',
    )->and($bindings)->toBe(['/email', '/email/', '/email/']);
});

it('spells the postgresql dialect out', function (): void {
    [$sql] = new ChangedFieldPredicate()->for('pgsql', 'changes', '/email');

    expect($sql)->toBe(
        'exists (select 1 from jsonb_array_elements(case when jsonb_typeof(changes) = \'array\' then changes else \'[]\'::jsonb end) e'
        .' where e->>\'path\' = ?'
        .' or substr(e->>\'path\', 1, length(?)) = ?)',
    );
});

it('spells the mysql dialect out', function (): void {
    [$sql] = new ChangedFieldPredicate()->for('mysql', 'changes', '/email');

    expect($sql)->toBe(
        'exists (select 1 from (select changes as ch) d,'
        .' json_table(case when json_type(d.ch) = \'ARRAY\' then d.ch else cast(\'[]\' as json) end,'
        .' \'$[*]\' columns (p json path \'$.path\')) jt'
        .' where json_type(jt.p) = \'STRING\''
        .' and (json_unquote(jt.p) collate utf8mb4_bin = ?'
        .' or substr(json_unquote(jt.p), 1, char_length(?)) collate utf8mb4_bin = ?))',
    );
});

it('names the column it was given, wherever the table lives', function (string $driver): void {
    [$sql] = new ChangedFieldPredicate()->for($driver, '"audit_trail"."changes"', '/email');

    expect($sql)->toContain('"audit_trail"."changes"');
})->with(['sqlite', 'pgsql', 'mysql']);
