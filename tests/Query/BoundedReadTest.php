<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\seedTheTrail;

it('answers a read that fits inside the bound', function (): void {
    seedTheTrail(AuditQuery::DEFAULT_LIMIT);

    expect(Sentinel::audits()->get())->toHaveCount(AuditQuery::DEFAULT_LIMIT);
});

it('refuses a read that would come back looking complete when it is not', function (): void {
    seedTheTrail(AuditQuery::DEFAULT_LIMIT + 1);

    expect(fn (): AuditCollection => Sentinel::audits()->get())
        ->toThrow(QueryException::class, 'would look exactly like handing back all of them');
});

it('hands back a prefix that was asked for on purpose', function (): void {
    seedTheTrail(AuditQuery::DEFAULT_LIMIT + 50);

    expect(Sentinel::audits()->take(10)->get())->toHaveCount(10)
        ->and(Sentinel::audits()->take(AuditQuery::DEFAULT_LIMIT + 50)->get())
        ->toHaveCount(AuditQuery::DEFAULT_LIMIT + 50);
});

it('hands back a single entry when a single one is asked for', function (): void {
    seedTheTrail(10);

    expect(Sentinel::audits()->take(1)->get())->toHaveCount(1);
});

it('asks the store for one entry more than the bound, so it can tell it filled', function (): void {
    seedTheTrail(10);
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    Sentinel::audits()->get();

    expect($statements[0] ?? '')->toContain('limit '.(AuditQuery::DEFAULT_LIMIT + 1));
});

it('refuses a read of no entries at all', function (): void {
    expect(fn (): AuditQuery => Sentinel::audits()->take(0))
        ->toThrow(QueryException::class, 'is not a read: ask for at least one');
});

it('walks past the bound a page at a time', function (): void {
    seedTheTrail(AuditQuery::DEFAULT_LIMIT + 10);

    $page = Sentinel::audits()->paginate(AuditQuery::DEFAULT_LIMIT);

    expect($page->entries)->toHaveCount(AuditQuery::DEFAULT_LIMIT)
        ->and($page->hasMore)->toBeTrue();
});

it('hands back a new query rather than bounding the one it was given', function (): void {
    $query = Sentinel::audits();

    $query->take(10);

    expect($query->limit)->toBeNull();
});
