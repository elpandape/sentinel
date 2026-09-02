<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\archivesTable;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\frontiers;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\pruner;
use function ElPandaPe\Sentinel\Tests\redactor;
use function ElPandaPe\Sentinel\Tests\rehydrator;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

$later = new CarbonImmutable('2026-09-30 12:00:00');

beforeEach(function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);
});

it('archives a range that holds a tombstone instead of refusing it', function () use ($later): void {
    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    redactor()->redact(Audit::query()->where('sequence', 2)->firstOrFail(), 'erasure request');

    anchor('global', 4);

    $removed = pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    expect($removed->succeeded())->toBeTrue()
        ->and($removed->removed->audits)->toBeGreaterThan(0)
        ->and(Storage::disk('cold')->allFiles())->not->toBeEmpty()
        ->and(Audit::query()->where('sequence', 2)->exists())->toBeFalse();
});

it('brings a redacted entry back redacted, and not as tampering', function () use ($later): void {
    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    $redacted = Audit::query()->where('sequence', 2)->firstOrFail();
    redactor()->redact($redacted, 'erasure request');

    anchor('global', 4);
    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    rehydrator()->restore('global', 1, 4);

    $reloaded = Audit::query()->where('sequence', 2)->firstOrFail();

    expect($reloaded->verifyContent())->toBe(ContentState::Redacted)
        ->and($reloaded->before)->toBeNull()
        ->and($reloaded->redaction_reason)->toBe('erasure request')
        ->and($reloaded->hash)->toBe($redacted->hash);
});

it('redacts an entry of a range that was brought back, instead of refusing it for a row', function () use ($later): void {
    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    anchor('global', 4);
    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    rehydrator()->restore('global', 1, 4);

    $restored = Audit::query()->where('sequence', 2)->firstOrFail();

    redactor()->redact($restored, 'erasure request');

    expect(Audit::query()->where('sequence', 2)->firstOrFail()->verifyContent())->toBe(ContentState::Redacted);
});

it('keeps one manifest row for a range that is written out a second time', function () use ($later): void {
    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    anchor('global', 4);
    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    rehydrator()->restore('global', 1, 4);
    redactor()->redact(Audit::query()->where('sequence', 2)->firstOrFail(), 'erasure request');

    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    expect(DB::table(archivesTable())->where('stream', 'global')->where('sequence_from', 1)->count())->toBe(1);
});

it('gives back the redacted content after the round trip that redacted it', function () use ($later): void {
    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    anchor('global', 4);
    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    rehydrator()->restore('global', 1, 4);
    redactor()->redact(Audit::query()->where('sequence', 2)->firstOrFail(), 'erasure request');
    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    rehydrator()->restore('global', 1, 4);

    $reloaded = Audit::query()->where('sequence', 2)->firstOrFail();

    expect($reloaded->before)->toBeNull()
        ->and($reloaded->verifyContent())->toBe(ContentState::Redacted);
});
