<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Query\AuditQuery;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\jsonIndexMigration;
use function ElPandaPe\Sentinel\Tests\planFor;
use function ElPandaPe\Sentinel\Tests\readsAnIndex;
use function ElPandaPe\Sentinel\Tests\seedTheTrail;

beforeEach(function (): void {
    seedTheTrail();

    DB::table(auditsTable())->limit(1)->update([
        'context' => json_encode(['ip' => '203.0.113.7', 'route' => 'invoices.approve']),
    ]);
});

it('turns the two refiners into filters that find', function (Closure $narrow): void {
    $before = planFor($narrow()->take(AuditQuery::DEFAULT_LIMIT));

    jsonIndexMigration()->up();
    DB::statement(match (DB::connection()->getDriverName()) {
        'pgsql' => 'analyze '.auditsTable(),
        'mysql' => 'analyze table '.auditsTable(),
        default => 'analyze',
    });

    $after = planFor($narrow()->take(AuditQuery::DEFAULT_LIMIT));

    expect(readsAnIndex($before))->toBeFalse($before)
        ->and(readsAnIndex($after))->toBeTrue($after);
})->with([
    'address' => [fn (): AuditQuery => Sentinel::audits()->whereIp('203.0.113.7')],
    'route' => [fn (): AuditQuery => Sentinel::audits()->whereRoute('invoices.approve')],
]);

it('answers with the same entries whether or not it has been run', function (): void {
    $before = Sentinel::audits()->whereIp('203.0.113.7')->get()->pluck('id')->all();

    jsonIndexMigration()->up();

    expect(Sentinel::audits()->whereIp('203.0.113.7')->get()->pluck('id')->all())->toBe($before)
        ->and($before)->toHaveCount(1);
});

it('leaves the table as it found it when rolled back', function (): void {
    $migration = jsonIndexMigration();
    $columns = DB::getSchemaBuilder()->getColumnListing(auditsTable());

    $migration->up();
    $migration->down();

    expect(DB::getSchemaBuilder()->getColumnListing(auditsTable()))->toBe($columns)
        ->and(Sentinel::audits()->whereIp('203.0.113.7')->get())->toHaveCount(1);
});

it('changes no hash it indexes over', function (): void {
    $hashes = DB::table(auditsTable())->orderBy('id')->pluck('hash')->all();

    jsonIndexMigration()->up();

    expect(DB::table(auditsTable())->orderBy('id')->pluck('hash')->all())->toBe($hashes);
});
