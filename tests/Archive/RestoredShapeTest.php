<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditRelation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditRelationsTable;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\frontiers;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\pruner;
use function ElPandaPe\Sentinel\Tests\rehydrator;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\transactionsTable;

$later = new CarbonImmutable('2026-09-30 12:00:00');

beforeEach(function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);
});

it('rebuilds the relation lines of an entry it puts back, without them travelling in the batch', function () use ($later): void {
    $lines = [
        ['relation' => 'members', 'operation' => 'attached', 'related_type' => 'member', 'related_id' => '9'],
    ];

    foreach (range(1, 4) as $sequence) {
        ledger()->write(auditData($sequence === 1 ? ['changes' => $lines] : []));
    }

    foreach (range(1, 4) as $ignored) {
        ledger()->write(auditData());
    }

    anchor('global', 4);

    expect(DB::table(auditRelationsTable())->count())->toBe(1);

    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    expect(DB::table(auditRelationsTable())->count())->toBe(0)
        ->and(Storage::disk('cold')->allFiles())->toHaveCount(1);

    rehydrator()->restore('global', 1, 4);

    $line = AuditRelation::query()->firstOrFail();

    expect(AuditRelation::query()->count())->toBe(1)
        ->and($line->relation)->toBe('members')
        ->and($line->related_id)->toBe('9');
});

it('skips an entry whose capture identifier is already held by another row', function () use ($later): void {
    $capture = str_pad('01JCAPTURE', 26, '0');

    ledger()->write(auditData(['capture_id' => $capture]));

    foreach (range(1, 7) as $ignored) {
        ledger()->write(auditData());
    }

    anchor('global', 4);
    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    $replayed = ledger()->write(auditData(['capture_id' => $capture]));

    $done = rehydrator()->restore('global', 1, 4);

    expect($replayed->sequence)->toBe(9)
        ->and($done->skipped)->toBe(1)
        ->and($done->restored)->toBe(3)
        ->and(DB::table(auditsTable())->where('capture_id', $capture)->count())->toBe(1);
});

it('puts an operation header back when a second pass finds it missing too', function () use ($later): void {
    $operation = str_pad('01JOP', 26, '0');

    DB::table(transactionsTable())->insert([
        'id' => $operation,
        'name' => 'billing.run',
        'started_at' => '2026-08-01 00:00:00.000000',
        'audits_count' => 4,
    ]);

    foreach (range(1, 4) as $ignored) {
        ledger()->write(auditData(['transaction_id' => $operation]));
    }

    foreach (range(1, 4) as $ignored) {
        ledger()->write(auditData());
    }

    anchor('global', 4);
    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    rehydrator()->restore('global', 1, 4);

    DB::table(auditsTable())->whereBetween('sequence', [3, 4])->delete();
    DB::table(transactionsTable())->delete();

    $second = rehydrator()->restore('global', 1, 4);

    expect($second->restored)->toBe(2)
        ->and($second->operations)->toBe(1)
        ->and(Audit::query()->where('sequence', 3)->firstOrFail()->transaction?->name)->toBe('billing.run');
});
