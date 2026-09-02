<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Ledger\ContextPredicate;

/**
 * The SQL itself, letter for letter, for the same reason ChangedFieldPredicateTest spells its own
 * out: two of the three dialects never run under `make ci`, so nothing else in the suite would
 * notice a stray edit until `make test-dbs` did.
 */
it('spells the sqlite dialect out', function (): void {
    [$sql, $bindings] = new ContextPredicate()->for('sqlite', 'context', Filter::Ip, '203.0.113.7');

    expect($sql)->toBe("json_extract(case when json_valid(context) then context else '{}' end, '$.ip') = ?")
        ->and($bindings)->toBe(['203.0.113.7']);
});

it('spells the postgresql dialect out', function (): void {
    [$sql, $bindings] = new ContextPredicate()->for('pgsql', 'context', Filter::Route, 'invoices.approve');

    expect($sql)->toBe("context->>'route' = ?")
        ->and($bindings)->toBe(['invoices.approve']);
});

/**
 * Two clauses for one comparison: the first is what the index can serve and the second is what
 * decides, because MySQL's default collation would otherwise answer a question about
 * `invoices.show` with entries recorded from `Invoices.Show`.
 */
it('spells the mysql dialect out, with the prefilter its index can serve', function (): void {
    [$sql, $bindings] = new ContextPredicate()->for('mysql', 'context', Filter::Ip, '203.0.113.7');

    expect($sql)->toBe(
        "json_unquote(json_extract(context, '$.ip')) = ?"
        ." and json_unquote(json_extract(context, '$.ip')) collate utf8mb4_bin = ?",
    )->and($bindings)->toBe(['203.0.113.7', '203.0.113.7']);
});

it('names the column it was given, wherever the table lives', function (string $driver): void {
    [$sql] = new ContextPredicate()->for($driver, '"audit_trail"."context"', Filter::Route, 'home');

    expect($sql)->toContain('"audit_trail"."context"');
})->with(['sqlite', 'pgsql', 'mysql']);

it('refuses an engine it has no dialect for', function (): void {
    new ContextPredicate()->for('sqlsrv', 'context', Filter::Ip, '203.0.113.7');
})->throws(LedgerException::class, 'no ip predicate for the [sqlsrv] engine');

it('hands the migration the same reading it compares against', function (string $driver): void {
    $predicate = new ContextPredicate;
    [$sql] = $predicate->for($driver, 'context', Filter::Ip, '203.0.113.7');

    expect($sql)->toStartWith($predicate->expression($driver, 'context', Filter::Ip).' = ?');
})->with(['sqlite', 'pgsql', 'mysql']);
