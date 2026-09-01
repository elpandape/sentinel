<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\frontiers;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\pruner;
use function ElPandaPe\Sentinel\Tests\rehydrator;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\verifier;

$later = new CarbonImmutable('2026-09-30 12:00:00');

beforeEach(function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);
});

it('brings the frozen chain back reproducing every hash it was frozen with', function () use ($later): void {
    seedTheReferenceChain();
    anchor(ReferenceChain::STREAM, 4);

    pruner()->prune(frontiers(['model' => '1 day'])->of(ReferenceChain::STREAM, $later), PruneAction::Archive, false);

    expect(Audit::query()->where('stream', ReferenceChain::STREAM)->count())->toBe(4);

    rehydrator()->restore(ReferenceChain::STREAM, 1, 4);

    $back = Audit::query()->where('stream', ReferenceChain::STREAM)->orderBy('sequence')->get();
    $frozen = array_column(
        array_filter(ReferenceChain::entries(), fn (array $e): bool => $e['stream'] === ReferenceChain::STREAM),
        'hash',
        'sequence',
    );

    expect($back)->toHaveCount(8);

    foreach ($back as $entry) {
        expect($entry->hash)->toBe($frozen[$entry->sequence])
            ->and(hasher()->hash($entry))->toBe($frozen[$entry->sequence]);
    }

    expect(verifier()->verify(ReferenceChain::STREAM)->isIntact())->toBeTrue();
});

it('keeps the roots the frozen chain folded to after the round trip', function () use ($later): void {
    seedTheReferenceChain();
    anchor(ReferenceChain::STREAM, 4);

    pruner()->prune(frontiers(['model' => '1 day'])->of(ReferenceChain::STREAM, $later), PruneAction::Archive, false);
    rehydrator()->restore(ReferenceChain::STREAM, 1, 4);

    expect(verifier()->verifyRoots(ReferenceChain::STREAM)->isIntact())->toBeTrue();
});

it('numbers a subject from what the table holds, and a restore puts its history back', function () use ($later): void {
    $subject = ['subject_type' => AuditedSubject::class, 'subject_id' => '7'];

    foreach (range(1, 4) as $ignored) {
        ledger()->write(auditData());
    }

    foreach (range(1, 4) as $ignored) {
        ledger()->write(auditData($subject));
    }

    foreach (range(1, 4) as $ignored) {
        ledger()->write(auditData());
    }

    anchor('global', 4);

    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    $written = ledger()->write(auditData($subject));

    expect($written->version)->toBe(1);

    rehydrator()->restore('global', 5, 8);

    $versions = Audit::query()
        ->where('subject_type', AuditedSubject::class)
        ->orderBy('sequence')
        ->pluck('version')
        ->all();

    expect($versions)->toEqual([1, 2, 3, 4, 1])
        ->and(ledger()->write(auditData($subject))->version)->toBe(5);
});
