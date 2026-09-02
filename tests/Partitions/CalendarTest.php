<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Partitions\Calendar;
use ElPandaPe\Sentinel\Partitions\Partition;
use ElPandaPe\Sentinel\Retention\Duration;

it('asks for this month and the ones after it, because a first run has no partition for today', function (): void {
    $wanted = new Calendar()->ahead('sentinel_audits_', new CarbonImmutable('2026-09-14 10:00:00'), 3);

    expect(array_map(static fn (Partition $one): string => $one->name, $wanted))->toBe([
        'sentinel_audits_p2026_09',
        'sentinel_audits_p2026_10',
        'sentinel_audits_p2026_11',
        'sentinel_audits_p2026_12',
    ]);
});

it('asks for this month alone when nothing ahead is wanted', function (int $months): void {
    expect(new Calendar()->ahead('', new CarbonImmutable('2026-09-14'), $months))->toHaveCount(1);
})->with([0, -4]);

it('crosses the turn of the year without inventing a thirteenth month', function (): void {
    $wanted = new Calendar()->ahead('', new CarbonImmutable('2026-11-30'), 2);

    expect(array_map(static fn (Partition $one): string => $one->name, $wanted))
        ->toBe(['p2026_11', 'p2026_12', 'p2027_01']);
});

it('leaves alone the ones already there, comparing by the name it would have used', function (): void {
    $wanted = new Calendar()->ahead('', new CarbonImmutable('2026-09-14'), 2);
    $existing = [Partition::named('p2026_09'), Partition::named('p2026_11')];

    expect(array_map(
        static fn (Partition $one): string => $one->name,
        new Calendar()->missing($wanted, $existing),
    ))->toBe(['p2026_10']);
});

it('calls behind only what ended before the cutoff, not what merely started before it', function (): void {
    $existing = [
        Partition::named('p2026_06'),
        Partition::named('p2026_07'),
        Partition::named('p2026_08'),
    ];

    $behind = new Calendar()->behind($existing, new CarbonImmutable('2026-09-14'), Duration::of('retire', '2 months'));

    expect(array_map(static fn (Partition $one): string => $one->name, $behind))->toBe(['p2026_06']);
});

it('never calls the catch-all behind, whatever the cutoff', function (): void {
    $existing = [Partition::named('pmax'), Partition::named('sentinel_audits_default')];

    expect(new Calendar()->behind($existing, new CarbonImmutable('2099-01-01'), Duration::of('retire', '1 day')))
        ->toBeEmpty();
});
