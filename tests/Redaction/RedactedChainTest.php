<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\checkpointsTable;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\redactor;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;

it('folds a range to the same root after one of its entries is redacted', function (): void {
    foreach (range(1, 4) as $ignored) {
        ledger()->write(auditData(['before' => ['city' => 'Lima'], 'after' => ['city' => 'Arequipa']]));
    }

    anchor('global', 4);

    $root = DB::table(checkpointsTable())->where('stream', 'global')->value('root_hash');

    redactor()->redact(Audit::query()->where('sequence', 2)->firstOrFail(), 'erasure request');

    anchor('global', 4);

    expect(DB::table(checkpointsTable())->where('stream', 'global')->value('root_hash'))->toBe($root)
        ->and(Sentinel::verifyRoots('global')->isIntact())->toBeTrue();
});

it('keeps the three depths from calling a redacted range tampered', function (): void {
    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['city' => 'Lima']]));
    }

    anchor('global', 8);

    redactor()->redact(Audit::query()->where('sequence', 3)->firstOrFail(), 'erasure request');

    expect(Sentinel::verifyIntegrity('global')->isIntact())->toBeTrue()
        ->and(Sentinel::verifyAnchors('global')->isIntact())->toBeTrue()
        ->and(Sentinel::verifyRoots('global')->isIntact())->toBeTrue();
});

it('leaves an anchored range reading as anchored, because the anchors never read the entry', function (): void {
    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['city' => 'Lima']]));
    }

    anchor('global', 8);

    redactor()->redact(Audit::query()->where('sequence', 3)->firstOrFail(), 'erasure request');

    $roots = Sentinel::verifyRoots('global');

    expect($roots->redacted())->toBe(0)
        ->and($roots->covered)->toBeGreaterThan(0);
});

it('verifies the frozen reference chain with a tombstone inside it', function (): void {
    seedTheReferenceChain();

    $entry = Audit::query()->where('stream', ReferenceChain::STREAM)->where('sequence', 3)->firstOrFail();

    redactor()->redact($entry, 'erasure request');

    $walk = Sentinel::verifyIntegrity(ReferenceChain::STREAM);
    $reloaded = Audit::query()->findOrFail($entry->id);

    expect($walk->isIntact())->toBeTrue()
        ->and($reloaded->verifyContent())->toBe(ContentState::Redacted)
        ->and(Audit::query()->where('stream', ReferenceChain::STREAM)->where('sequence', 4)->firstOrFail()->verifyContent())
        ->toBe(ContentState::Sealed);
});

it('keeps the frozen roots of the reference chain folding with a tombstone inside', function (): void {
    seedTheReferenceChain();

    redactor()->redact(
        Audit::query()->where('stream', ReferenceChain::STREAM)->where('sequence', 2)->firstOrFail(),
        'erasure request',
    );

    anchor(ReferenceChain::STREAM, 4);

    expect(DB::table(checkpointsTable())->where('stream', ReferenceChain::STREAM)->value('root_hash'))
        ->toBe(ReferenceChain::ROOT_1_4);
});
