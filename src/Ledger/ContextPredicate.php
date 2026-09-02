<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Exceptions\LedgerException;

/**
 * "This entry was recorded from that address", or "from that route", said in the JSON dialect of
 * each engine. Like ChangedFieldPredicate it takes the driver name rather than a connection, so the
 * three dialects can be read and tested without three databases.
 *
 * The key is never the caller's: it is the Filter case's own value, which is the context key that
 * case reads, so what gets interpolated into the SQL is a literal the enum declares.
 *
 * MySQL is the one that needs two clauses for one comparison, and it is not decoration. Its default
 * collation is accent- and case-insensitive, so `= 'invoices.show'` there answers with entries that
 * PostgreSQL and SQLite would not return, and a trail whose answer depends on the engine is not a
 * trail. Adding `collate utf8mb4_bin` fixes the answer and loses the index — measured: the plan goes
 * from an index lookup to a table scan, because a generated column indexed under one collation
 * cannot serve a comparison under another. So both clauses go in. The insensitive one matches a
 * superset of the sensitive one, which makes it a safe prefilter the index can serve; the binary one
 * then rechecks and decides. With the JSON index migration published the plan is an index lookup
 * with a filter on top; without it, a scan with the same answer.
 *
 * SQLite guards the column before reading it, for the reason PoisonedChangesTest states: this engine
 * stores JSON as bare text, and json_extract over something unparseable aborts a statement that
 * PDO::fetchAll then answers partially and without raising — an audit that looks complete and is not.
 */
final readonly class ContextPredicate
{
    /**
     * @return array{string, list<string>}
     */
    public function for(string $driver, string $column, Filter $filter, string $value): array
    {
        $reads = $this->expression($driver, $column, $filter);

        return $driver === 'mysql'
            ? ["{$reads} = ? and {$reads} collate utf8mb4_bin = ?", [$value, $value]]
            : ["{$reads} = ?", [$value]];
    }

    /**
     * The reading on its own, which is what the JSON index migration indexes. The two have to be
     * the same string to the letter or the index sits there unused, so there is one of them and
     * the migration asks for it rather than writing its own.
     */
    public function expression(string $driver, string $column, Filter $filter): string
    {
        $key = $filter->value;

        return match ($driver) {
            'sqlite' => "json_extract(case when json_valid({$column}) then {$column} else '{}' end, '$.{$key}')",
            'pgsql' => "{$column}->>'{$key}'",
            'mysql' => "json_unquote(json_extract({$column}, '$.{$key}'))",
            default => throw LedgerException::cannotTranslateOn($filter, $driver),
        };
    }
}
