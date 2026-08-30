<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Enums\PruneAction;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\ageEntries;
use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\archivesTable;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\frontiers;
use function ElPandaPe\Sentinel\Tests\pruner;
use function ElPandaPe\Sentinel\Tests\seedChain;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\verifier;

$now = new CarbonImmutable('2026-08-30 12:00:00');

beforeEach(function (): void {
    seedChain(12);
    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);
    sentinelConfig(['retention' => ['model' => '1 year']]);
});

it('retires a range in the middle, leaving the ones around it', function () use ($now): void {
    $pruning = pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);

    expect($pruning->windows)->toBe(1)
        ->and($pruning->removed->audits)->toBe(4)
        ->and(DB::table(auditsTable())->orderBy('sequence')->pluck('sequence')->all())
        ->toEqual([1, 2, 3, 4, 9, 10, 11, 12]);
});

it('leaves the chain verifying after a range in the middle is gone', function () use ($now): void {
    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);

    $verification = verifier()->verify('global');

    expect($verification->isIntact())->toBeTrue()
        ->and($verification->chain->checked)->toBe(8)
        ->and($verification->archived())->toBe(4);
});

it('writes down the range it retired before the rows go', function () use ($now): void {
    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);

    $archive = DB::table(archivesTable())->first();

    expect($archive?->sequence_from)->toEqual(5)
        ->and($archive?->sequence_to)->toEqual(8)
        ->and($archive?->records)->toEqual(4);
});

it('touches nothing on a dry run and counts what a real one would take', function () use ($now): void {
    $pruning = pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, true);

    expect($pruning->removed->audits)->toBe(4)
        ->and(DB::table(auditsTable())->count())->toBe(12)
        ->and(DB::table(archivesTable())->count())->toBe(0);
});

it('refuses to be the thing that destroys the evidence of a tampering', function () use ($now): void {
    DB::table(auditsTable())->where('sequence', 6)->update(['hash' => str_repeat('0', 64)]);

    $pruning = pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);

    expect($pruning->succeeded())->toBeFalse()
        ->and($pruning->break?->reason)->toBe(IntegrityBreak::CheckpointMismatch)
        ->and(DB::table(auditsTable())->count())->toBe(12);
});

it('publishes how fast it went, and nothing when it went nowhere', function () use ($now): void {
    $moved = pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);
    $still = pruner()->prune(frontiers(['model' => '900 years'])->of('global', $now), PruneAction::Delete, false);

    expect($moved->rate())->toBeGreaterThan(0.0)
        ->and($still->rate())->toBeNull();
});
