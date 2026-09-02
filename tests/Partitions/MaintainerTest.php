<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Retention\Duration;
use Illuminate\Database\Connection;

use function ElPandaPe\Sentinel\Tests\dividedConnection;
use function ElPandaPe\Sentinel\Tests\maintainer;

it('reports a table it cannot divide rather than failing on it', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlite');
    $connection->shouldReceive('select')->never();

    $maintenance = maintainer()->maintain($connection, 'sentinel_audits', 3, null, false, false, CarbonImmutable::now());

    expect($maintenance->divided)->toBeFalse()
        ->and($maintenance->created)->toBeEmpty();
});

it('reports an undivided table the same way when the engine could have divided it', function (): void {
    $maintenance = maintainer()->maintain(
        dividedConnection([]),
        'sentinel_audits',
        3,
        null,
        false,
        false,
        CarbonImmutable::now(),
    );

    expect($maintenance->divided)->toBeFalse();
});

it('creates only the months that are not there', function (): void {
    $connection = dividedConnection([(object) ['name' => 'sentinel_audits_p2026_09'], (object) ['name' => 'sentinel_audits_default']]);

    $connection->shouldReceive('statement')->once()
        ->with("create table sentinel_audits_p2026_10 partition of sentinel_audits for values from ('2026-10-01') to ('2026-11-01')");

    $maintenance = maintainer()->maintain(
        $connection,
        'sentinel_audits',
        1,
        null,
        false,
        false,
        new CarbonImmutable('2026-09-14'),
    );

    expect($maintenance->created)->toBe(['sentinel_audits_p2026_10'])
        ->and($maintenance->divided)->toBeTrue();
});

it('creates nothing on a second run over the same clock', function (): void {
    $connection = dividedConnection([
        (object) ['name' => 'sentinel_audits_p2026_09'],
        (object) ['name' => 'sentinel_audits_p2026_10'],
    ]);

    $connection->shouldReceive('statement')->never();

    expect(maintainer()->maintain($connection, 'sentinel_audits', 1, null, false, false, new CarbonImmutable('2026-09-14'))->idle())
        ->toBeTrue();
});

it('names what it would create without issuing a statement', function (): void {
    $connection = dividedConnection([(object) ['name' => 'sentinel_audits_default']]);

    $connection->shouldReceive('statement')->never();

    expect(maintainer()->maintain($connection, 'sentinel_audits', 0, null, false, true, new CarbonImmutable('2026-09-14'))->created)
        ->toBe(['sentinel_audits_p2026_09']);
});

it('retires a month behind the cutoff once it holds nothing', function (): void {
    $connection = dividedConnection([
        (object) ['name' => 'sentinel_audits_p2026_09'],
        (object) ['name' => 'sentinel_audits_p2026_06'],
    ], entries: 0);

    $connection->shouldReceive('statement')->once()->with('drop table sentinel_audits_p2026_06');

    $maintenance = maintainer()->maintain(
        $connection,
        'sentinel_audits',
        0,
        Duration::of('retire', '2 months'),
        false,
        false,
        new CarbonImmutable('2026-09-14'),
    );

    expect($maintenance->retired)->toBe(['sentinel_audits_p2026_06'])
        ->and($maintenance->kept)->toBeEmpty();
});

it('keeps a month that still holds entries, and says which reason it was', function (bool $compliance, bool $force, string $reason): void {
    $connection = dividedConnection([
        (object) ['name' => 'sentinel_audits_p2026_09'],
        (object) ['name' => 'sentinel_audits_p2026_06'],
    ], entries: 12);

    $connection->shouldReceive('statement')->never();

    $maintenance = maintainer($compliance)->maintain(
        $connection,
        'sentinel_audits',
        0,
        Duration::of('retire', '2 months'),
        $force,
        false,
        new CarbonImmutable('2026-09-14'),
    );

    expect($maintenance->kept)->toBe(['sentinel_audits_p2026_06' => $reason])
        ->and($maintenance->refused())->toBeTrue();
})->with([
    'compliance refuses whatever force says' => [true, true, 'unarchived'],
    'without compliance it is merely occupied' => [false, false, 'occupied'],
]);

it('drops an occupied month when forced and nothing forbids it', function (): void {
    $connection = dividedConnection([
        (object) ['name' => 'sentinel_audits_p2026_09'],
        (object) ['name' => 'sentinel_audits_p2026_06'],
    ], entries: 12);

    $connection->shouldReceive('statement')->once()->with('drop table sentinel_audits_p2026_06');

    expect(maintainer()->maintain(
        $connection,
        'sentinel_audits',
        0,
        Duration::of('retire', '2 months'),
        true,
        false,
        new CarbonImmutable('2026-09-14'),
    )->retired)->toBe(['sentinel_audits_p2026_06']);
});

it('retires nothing at all when no period was named', function (): void {
    $connection = dividedConnection([
        (object) ['name' => 'sentinel_audits_p2026_09'],
        (object) ['name' => 'sentinel_audits_p2020_01'],
    ]);

    $connection->shouldReceive('statement')->never();

    expect(maintainer()->maintain($connection, 'sentinel_audits', 0, null, false, false, new CarbonImmutable('2026-09-14'))->retired)
        ->toBeEmpty();
});
