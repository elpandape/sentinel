<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\ageEntries;
use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\archivesTable;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\checkpointsTable;
use function ElPandaPe\Sentinel\Tests\frontiers;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\manifest;
use function ElPandaPe\Sentinel\Tests\pruner;
use function ElPandaPe\Sentinel\Tests\retireEntries;
use function ElPandaPe\Sentinel\Tests\seedChain;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;
use function ElPandaPe\Sentinel\Tests\verifier;

$now = new CarbonImmutable('2026-08-30 12:00:00');

it('leaves the frozen chain reproducing its frozen hashes after a range in the middle is retired', function (): void {
    seedTheReferenceChain();
    anchor(ReferenceChain::STREAM, 4);
    retireEntries(ReferenceChain::STREAM, 1, 4);
    manifest()->retired(ReferenceChain::STREAM, 1, 4, 4);

    $survivors = Audit::query()->where('stream', ReferenceChain::STREAM)->orderBy('sequence')->get();

    expect($survivors)->toHaveCount(4)
        ->and($survivors->every(fn (Audit $audit): bool => hasher()->hash($audit) === $audit->hash))->toBeTrue();
});

it('leaves the anchor that answers for a retired range exactly where it was', function () use ($now): void {
    seedChain(12);
    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);

    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);

    expect(DB::table(checkpointsTable())->where('sequence_from', 5)->count())->toBe(1)
        ->and(DB::table(checkpointsTable())->count())->toBe(3);
});

it('lets the next write continue the chain instead of starting a second one', function () use ($now): void {
    seedChain(12);
    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);

    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);

    $written = ledger()->write(auditData(['stream' => 'global']));
    $twelfth = Audit::query()->where('stream', 'global')->where('sequence', 12)->firstOrFail();

    expect($written->sequence)->toBe(13)
        ->and($written->previous_hash)->toBe($twelfth->hash)
        ->and(verifier()->verify('global')->isIntact())->toBeTrue();
});

it('finishes a run that was interrupted between two batches', function () use ($now): void {
    seedChain(12);
    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);

    manifest()->retired('global', 5, 8, 4);
    retireEntries('global', 5, 6);

    $pruning = pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);

    expect($pruning->succeeded())->toBeTrue()
        ->and($pruning->removed->audits)->toBe(2)
        ->and(DB::table(auditsTable())->count())->toBe(8)
        ->and(verifier()->verify('global')->isIntact())->toBeTrue();
});

it('writes down a range it is finishing once and not twice', function () use ($now): void {
    seedChain(12);
    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);

    manifest()->retired('global', 5, 8, 4);
    retireEntries('global', 5, 6);

    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);

    expect(DB::table(archivesTable())->count())->toBe(1);
});

it('takes nothing twice when the same run is asked for again', function () use ($now): void {
    seedChain(12);
    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);

    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);
    $again = pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);

    expect($again->removed->audits)->toBe(0)
        ->and(DB::table(auditsTable())->count())->toBe(8);
});

it('refuses to remove a range a manifest row claims while its entries are still there and altered', function () use ($now): void {
    seedChain(12);
    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);

    manifest()->retired('global', 5, 8, 4);
    DB::table(auditsTable())->where('sequence', 6)->update(['hash' => str_repeat('0', 64)]);

    $pruning = pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Delete, false);

    expect($pruning->succeeded())->toBeFalse()
        ->and($pruning->break?->reason)->toBe(IntegrityBreak::CheckpointMismatch)
        ->and(DB::table(auditsTable())->count())->toBe(12);
});
