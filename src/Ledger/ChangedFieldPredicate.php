<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Exceptions\LedgerException;

/**
 * "This entry touched that field", said in the JSON dialect of each engine. It takes the driver
 * name rather than a connection so the three dialects can be read, and tested, without three
 * databases — and so the two that a given run cannot reach are still code somebody looked at.
 *
 * The comparison is an equality and a prefix over the element's own path, matching what
 * Diff::for() already means by touching a field: the pointer, or anything beneath it. It is not
 * a LIKE, and that is measured rather than stylistic — LIKE is ASCII case-insensitive on SQLite
 * and MySQL and case-sensitive on PostgreSQL, so no amount of escaping makes the three engines
 * answer with the same entries. Comparing a substring does, and it leaves % and _ in a field
 * name inert as a side effect.
 *
 * Every dialect guards the column before walking it, and each for its own reason: SQLite stores
 * this column as bare text, so json_each on anything unparseable aborts the statement — and
 * PDO::fetchAll answers such a statement with a partial result and no exception at all, which
 * would make an audit read quietly incomplete. PostgreSQL raises on jsonb_array_elements over a
 * non-array. MySQL needs the element's path to be a string before comparing it, and needs a
 * binary collation, or a query for /email would come back with /Email as well.
 */
final readonly class ChangedFieldPredicate
{
    /**
     * @return array{string, array{string, string, string}}
     */
    public function for(string $driver, string $column, string $pointer): array
    {
        $descendant = $pointer.'/';

        return [$this->sql($driver, $column), [$pointer, $descendant, $descendant]];
    }

    private function sql(string $driver, string $column): string
    {
        return match ($driver) {
            'sqlite' => "exists (select 1 from json_each(case when json_valid({$column}) then {$column} else '[]' end) je"
                ." where (case when je.type = 'object' then json_extract(je.value, '$.path') end) = ?"
                ." or substr((case when je.type = 'object' then json_extract(je.value, '$.path') end), 1, length(?)) = ?)",
            'pgsql' => "exists (select 1 from jsonb_array_elements(case when jsonb_typeof({$column}) = 'array' then {$column} else '[]'::jsonb end) e"
                .' where e->>\'path\' = ?'
                .' or substr(e->>\'path\', 1, length(?)) = ?)',
            'mysql' => "exists (select 1 from (select {$column} as ch) d,"
                ." json_table(case when json_type(d.ch) = 'ARRAY' then d.ch else cast('[]' as json) end,"
                ." '$[*]' columns (p json path '$.path')) jt"
                ." where json_type(jt.p) = 'STRING'"
                .' and (json_unquote(jt.p) collate utf8mb4_bin = ?'
                .' or substr(json_unquote(jt.p), 1, char_length(?)) collate utf8mb4_bin = ?))',
            default => throw LedgerException::cannotTranslateOn(Filter::FieldChanged, $driver),
        };
    }
}
