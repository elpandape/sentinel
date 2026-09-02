<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Partitions\Partition;

it('reads the month out of the name it would have written', function (): void {
    $partition = Partition::named('sentinel_audits_p2026_09');

    expect($partition->from?->format('Y-m-d'))->toBe('2026-09-01')
        ->and($partition->to?->format('Y-m-d'))->toBe('2026-10-01')
        ->and($partition->catchAll())->toBeFalse();
});

it('treats a name it did not write as a catch-all, so maintenance leaves it where it is', function (string $name): void {
    expect(Partition::named($name)->catchAll())->toBeTrue();
})->with(['pmax', 'sentinel_audits_default', 'p2026_9', 'archive_2026_09', 'p20260_09']);

it('holds a half-open month, so the last instant of it belongs to the next one', function (): void {
    $partition = Partition::of('', new CarbonImmutable('2026-09-30 23:59:59'));

    expect($partition->name)->toBe('p2026_09')
        ->and($partition->from?->format('Y-m-d H:i:s'))->toBe('2026-09-01 00:00:00')
        ->and($partition->to?->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:00:00');
});

it('is behind a cutoff only once its whole month is', function (): void {
    $partition = Partition::of('', new CarbonImmutable('2026-09-14'));

    expect($partition->endedBefore(new CarbonImmutable('2026-10-01')))->toBeTrue()
        ->and($partition->endedBefore(new CarbonImmutable('2026-09-30')))->toBeFalse()
        ->and(Partition::named('pmax')->endedBefore(new CarbonImmutable('2099-01-01')))->toBeFalse();
});
