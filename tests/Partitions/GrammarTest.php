<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Partitions\Grammar;
use ElPandaPe\Sentinel\Partitions\Partition;

/**
 * The DDL itself, letter for letter, for the reason ChangedFieldPredicateTest gives for doing the
 * same: `make ci` runs on SQLite, which executes neither of these dialects, so nothing else in the
 * suite would notice a stray edit until `make test-dbs` ran. Whether the DDL is right is settled by
 * PartitionsCommandTest, which runs against whichever engine is live.
 */
it('creates a partition as a table beside its parent on postgresql', function (): void {
    $partition = Partition::of('sentinel_audits_', new CarbonImmutable('2026-09-14'));

    expect(new Grammar()->create('pgsql', 'sentinel_audits', $partition))->toBe([
        "create table sentinel_audits_p2026_09 partition of sentinel_audits for values from ('2026-09-01') to ('2026-10-01')",
    ]);
});

/**
 * MySQL names MAXVALUE twice because there is no other way in: the list lives inside the table
 * definition, so a month is added by reorganising the catch-all standing in front of it and putting
 * the catch-all back behind.
 */
it('creates a partition by reorganising the catch-all on mysql', function (): void {
    $partition = Partition::of('', new CarbonImmutable('2026-09-14'));

    expect(new Grammar()->create('mysql', 'sentinel_audits', $partition))->toBe([
        'alter table sentinel_audits reorganize partition pmax into ('
        ."partition p2026_09 values less than (to_days('2026-10-01')), "
        .'partition pmax values less than maxvalue)',
    ]);
});

it('retires a partition the way each engine holds it', function (string $driver, string $expected): void {
    expect(new Grammar()->retire($driver, 'sentinel_audits', Partition::named('sentinel_audits_p2026_09')))->toBe($expected);
})->with([
    'postgresql drops the table' => ['pgsql', 'drop table sentinel_audits_p2026_09'],
    'mysql alters the parent' => ['mysql', 'alter table sentinel_audits drop partition sentinel_audits_p2026_09'],
]);

it('counts what a partition holds by reaching for it the way the engine exposes it', function (string $driver, string $expected): void {
    expect(new Grammar()->count($driver, 'sentinel_audits', Partition::named('sentinel_audits_p2026_09')))->toBe($expected);
})->with([
    'postgresql reads the partition itself' => ['pgsql', 'select count(*) as total from sentinel_audits_p2026_09'],
    'mysql reads it through the parent' => ['mysql', 'select count(*) as total from sentinel_audits partition (sentinel_audits_p2026_09)'],
]);

it('asks each catalogue for the partitions of one table', function (string $driver, string $fragment): void {
    expect(new Grammar()->partitions($driver))->toContain($fragment);
})->with([
    'postgresql walks the inheritance' => ['pgsql', 'pg_inherits'],
    'mysql reads information_schema' => ['mysql', 'information_schema.partitions'],
]);

it('names a partition after its parent only where partitions share a namespace', function (string $driver, string $prefix): void {
    expect(new Grammar()->prefix($driver, 'sentinel_audits'))->toBe($prefix);
})->with([
    'postgresql partitions are tables' => ['pgsql', 'sentinel_audits_'],
    'mysql partitions are not' => ['mysql', ''],
]);

it('knows which engines divide a table at all', function (): void {
    expect(new Grammar()->divides('pgsql'))->toBeTrue()
        ->and(new Grammar()->divides('mysql'))->toBeTrue()
        ->and(new Grammar()->divides('sqlite'))->toBeFalse();
});

it('refuses an engine that does not partition, rather than inventing ddl for it', function (Closure $ask): void {
    $ask();
})->with([
    'listing' => [fn () => new Grammar()->partitions('sqlite')],
    'creating' => [fn () => new Grammar()->create('sqlite', 'sentinel_audits', Partition::of('', new CarbonImmutable('2026-09-01')))],
    'retiring' => [fn () => new Grammar()->retire('sqlite', 'sentinel_audits', Partition::named('p2026_09'))],
    'counting' => [fn () => new Grammar()->count('sqlite', 'sentinel_audits', Partition::named('p2026_09'))],
    'naming' => [fn () => new Grammar()->prefix('sqlite', 'sentinel_audits')],
])->throws(ConfigurationException::class, 'does not partition a table');
