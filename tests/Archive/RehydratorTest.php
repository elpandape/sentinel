<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Archive\ArchiveBatch;
use ElPandaPe\Sentinel\Archive\Line;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Exceptions\ArchiveException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\ageEntries;
use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\archivesTable;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditRelationsTable;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\auditTagsTable;
use function ElPandaPe\Sentinel\Tests\checkpointsTable;
use function ElPandaPe\Sentinel\Tests\frontiers;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\manifest;
use function ElPandaPe\Sentinel\Tests\pruner;
use function ElPandaPe\Sentinel\Tests\rehydrator;
use function ElPandaPe\Sentinel\Tests\seedChain;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\transactionsTable;
use function ElPandaPe\Sentinel\Tests\verifier;

$now = new CarbonImmutable('2026-08-30 12:00:00');

beforeEach(function () use ($now): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);
    seedChain(12);
    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);

    $this->before = Audit::query()->where('stream', 'global')->orderBy('sequence')->get()->keyBy('sequence');

    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Archive, false);
});

it('puts a range back exactly as it left', function (): void {
    $done = rehydrator()->restore('global', 5, 8);

    $after = Audit::query()->where('stream', 'global')->orderBy('sequence')->get()->keyBy('sequence');

    expect($done->restored)->toBe(4)
        ->and($after)->toHaveCount(12);

    foreach ([5, 6, 7, 8] as $sequence) {
        expect($after[$sequence]->hash)->toBe($this->before[$sequence]->hash)
            ->and($after[$sequence]->previous_hash)->toBe($this->before[$sequence]->previous_hash)
            ->and($after[$sequence]->version)->toBe($this->before[$sequence]->version)
            ->and($after[$sequence]->created_at->format('Y-m-d H:i:s.u'))
            ->toBe($this->before[$sequence]->created_at->format('Y-m-d H:i:s.u'));
    }
});

it('leaves the chain verifying by reading it, with nothing stepped over', function (): void {
    rehydrator()->restore('global', 5, 8);

    $verification = verifier()->verify('global');

    expect($verification->isIntact())->toBeTrue()
        ->and($verification->chain->checked)->toBe(12)
        ->and($verification->archived())->toBe(0);
});

it('gives an entry back carrying the labels it went in with', function () use ($now): void {
    DB::table(auditsTable())->delete();
    DB::table(checkpointsTable())->delete();
    DB::table(archivesTable())->delete();
    Storage::fake('cold');

    foreach (range(1, 12) as $sequence) {
        ledger()->write(auditData($sequence === 5 ? ['tags' => ['billing', 'refund']] : []));
    }

    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);
    pruner()->prune(frontiers(['model' => '1 year'])->of('global', $now), PruneAction::Archive, false);

    expect(DB::table(auditTagsTable())->count())->toBe(0);

    rehydrator()->restore('global', 5, 8);

    expect(Audit::query()->where('sequence', 5)->firstOrFail()->tags->map(fn (AuditTag $t): string => $t->tag)->all())
        ->toBe(['billing', 'refund']);
});

it('rebuilds the relation projection without it travelling in the batch', function (): void {
    rehydrator()->restore('global', 5, 8);

    expect(DB::table(auditRelationsTable())->count())->toBe(0);
});

it('puts back only what is missing when the same range comes back twice', function (): void {
    $first = rehydrator()->restore('global', 5, 8);
    $second = rehydrator()->restore('global', 5, 8);

    expect($first->restored)->toBe(4)
        ->and($second->restored)->toBe(0)
        ->and($second->skipped)->toBe(4)
        ->and(DB::table(auditsTable())->count())->toBe(12);
});

it('finishes a restore that was interrupted halfway', function (): void {
    rehydrator()->restore('global', 5, 8);
    DB::table(auditsTable())->whereIn('sequence', [7, 8])->delete();

    $second = rehydrator()->restore('global', 5, 8);

    expect($second->restored)->toBe(2)
        ->and($second->skipped)->toBe(2)
        ->and(DB::table(auditsTable())->count())->toBe(12);
});

it('refuses to put an entry back over a sequence another hash already holds', function (): void {
    DB::table(auditsTable())->insert(
        new Audit()->forceFill([
            'id' => str_pad('01JIMPOSTOR', 26, '0'),
            'stream' => 'global',
            'sequence' => 6,
            'audit_type' => 'model',
            'event' => 'created',
            'severity' => 'info',
            'source' => 'system',
            'context' => [],
            'payload_version' => 1,
            'algorithm' => 'sha256',
            'hash' => str_repeat('f', 64),
            'occurred_at' => '2026-08-30 12:00:00.000000',
            'created_at' => '2026-08-30 12:00:00.000000',
        ])->getAttributes(),
    );

    expect(fn (): mixed => rehydrator()->restore('global', 5, 8))
        ->toThrow(ArchiveException::class, 'already held by an entry with a different hash');
});

it('gives back nothing for a range no batch holds', function (): void {
    expect(rehydrator()->restore('global', 100, 200)->batches)->toBe(0);
});

it('does not rewrite the batch it is reading, even with a cold fanout destination configured', function (): void {
    $path = Storage::disk('cold')->allFiles()[0];
    $before = Storage::disk('cold')->get($path);

    sentinelConfig([
        'ledger.default' => 'fanout',
        'ledger.ledgers.fanout.destinations' => ['database', 'archive'],
    ]);

    rehydrator()->restore('global', 5, 8);
    app()->terminate();

    expect(Storage::disk('cold')->allFiles())->toBe([$path])
        ->and(Storage::disk('cold')->get($path))->toBe($before);
});

it('puts the operation header back so its entries stop pointing at nothing', function () use ($now): void {
    DB::table(auditsTable())->delete();
    DB::table(checkpointsTable())->delete();
    DB::table(archivesTable())->delete();
    Storage::fake('cold');

    $operation = str_pad('01JOP', 26, '0');

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

    expect(DB::table(transactionsTable())->count())->toBe(0);

    $done = rehydrator()->restore('global', 5, 8);

    expect($done->operations)->toBe(1)
        ->and(Audit::query()->where('sequence', 5)->firstOrFail()->transaction?->name)->toBe('billing.run');
});

it('refuses to put back an entry that no longer reproduces its own hash', function (): void {
    $entry = Audit::query()->where('sequence', 9)->firstOrFail();

    $body = json_encode(Line::header('global', 20, 20, 1, '2026-08-31 10:00:00.000000'))."\n"
        .json_encode([...Line::entry($entry), 'sequence' => 20, 'hash' => str_repeat('e', 64)])."\n";

    Storage::disk('cold')->put('sentinel/forged.ndjson', $body);

    manifest()->archived(new ArchiveBatch(
        'global', 20, 20, 1, 'cold', 'sentinel/forged.ndjson',
        'sha256:'.hash('sha256', $body), null,
    ));

    expect(fn (): mixed => rehydrator()->restore('global', 20, 20))
        ->toThrow(ArchiveException::class, 'does not reproduce its own hash')
        ->and(Audit::query()->where('sequence', 20)->count())->toBe(0);
});
