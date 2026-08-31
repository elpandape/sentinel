<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Archive\Batch;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Models\AuditArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\ageEntries;
use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\archiver;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\checkpointsTable;
use function ElPandaPe\Sentinel\Tests\frontiers;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\manifest;
use function ElPandaPe\Sentinel\Tests\pruner;
use function ElPandaPe\Sentinel\Tests\seedChain;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\transactionsTable;
use function ElPandaPe\Sentinel\Tests\verifier;

$now = new CarbonImmutable('2026-08-30 12:00:00');

beforeEach(function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);
    seedChain(12);
    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);
});

it('writes the range out and records where it went', function () use ($now): void {
    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Archive, false);

    $archive = AuditArchive::query()->firstOrFail();

    expect($archive->disk)->toBe('cold')
        ->and($archive->path)->toContain('global')
        ->and($archive->checksum)->toStartWith('sha256:')
        ->and($archive->compressed)->toBe('gzip')
        ->and(Storage::disk('cold')->exists($archive->path))->toBeTrue();
});

it('leaves the chain verifying through the anchor after a range is written out', function () use ($now): void {
    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Archive, false);

    $verification = verifier()->verify('global');

    expect($verification->isIntact())->toBeTrue()
        ->and($verification->archived())->toBe(4)
        ->and(DB::table(auditsTable())->count())->toBe(8);
});

it('saves the name of an operation instead of destroying it with its entries', function () use ($now): void {
    $operation = str_pad('01JOP', 26, '0');

    DB::table(auditsTable())->delete();
    DB::table(checkpointsTable())->delete();
    DB::table(transactionsTable())->insert([
        'id' => $operation,
        'name' => 'billing.run',
        'started_at' => '2020-01-01 00:00:00.000000',
        'audits_count' => 4,
    ]);

    foreach (range(1, 12) as $sequence) {
        ledger()->write(auditData($sequence >= 5 && $sequence <= 8 ? ['transaction_id' => $operation] : []));
    }

    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);

    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Archive, false);

    $archive = AuditArchive::query()->firstOrFail();
    $body = gzdecode(Storage::disk('cold')->get($archive->path));

    expect(DB::table(transactionsTable())->count())->toBe(0)
        ->and($body)->toContain('billing.run')
        ->and(iterator_to_array(Batch::entriesIn($body)))->toHaveCount(4);
});

it('carries the labels of the entries it wrote out', function () use ($now): void {
    DB::table('sentinel_audit_tags')->insert([
        ['audit_id' => DB::table(auditsTable())->where('sequence', 5)->value('id'), 'tag' => 'billing'],
    ]);

    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Archive, false);

    $archive = AuditArchive::query()->firstOrFail();

    expect(gzdecode(Storage::disk('cold')->get($archive->path)))->toContain('billing');
});

it('removes nothing when the disk will not take the batch', function () use ($now): void {
    sentinelConfig(['ledger.ledgers.archive.path' => str_repeat('deep/', 120)]);

    rescue(fn (): mixed => pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Archive, false), report: false);

    expect(DB::table(auditsTable())->count())->toBe(12)
        ->and(AuditArchive::query()->count())->toBe(0);
});

it('gives back the batch a row points at, and nothing for a range that went nowhere', function () use ($now): void {
    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Archive, false);

    $archived = AuditArchive::query()->firstOrFail();
    $retired = manifest()->retired('other', 1, 4, 4);

    expect(manifest()->batchOf($archived)?->path)->toBe($archived->path)
        ->and(manifest()->batchOf($retired))->toBeNull();
});

it('finishes a run interrupted between writing the batch and removing the rows', function () use ($now): void {
    manifest()->archived(archiver()->archive('global', 5, 8));

    $pruning = pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Archive, false);

    expect($pruning->succeeded())->toBeTrue()
        ->and(DB::table(auditsTable())->count())->toBe(8)
        ->and(AuditArchive::query()->count())->toBe(1);
});

it('writes the batch again over the same key when it was interrupted before the row was recorded', function () use ($now): void {
    $orphan = archiver()->archive('global', 5, 8);

    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Archive, false);

    expect(Storage::disk('cold')->allFiles())->toBe([$orphan->path])
        ->and(AuditArchive::query()->value('path'))->toBe($orphan->path);
});
