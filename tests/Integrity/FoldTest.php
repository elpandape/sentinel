<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Integrity\Fold;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;

use function ElPandaPe\Sentinel\Tests\fold;
use function ElPandaPe\Sentinel\Tests\hashesOf;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\referenceHashes;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;

it('names the construction alongside the digest it folds with', function (): void {
    expect(Fold::name('sha256'))->toBe('fold-sha256')
        ->and(Fold::name('sha512'))->toBe('fold-sha512');
});

it('folds a range into the root it froze', function (): void {
    expect(fold()->root(ReferenceChain::STREAM, 1, 4, null, 'sha256', referenceHashes(ReferenceChain::STREAM, 1, 4)))
        ->toBe(ReferenceChain::ROOT_1_4);
});

it('folds the anchor before it into the next one', function (): void {
    $hashes = referenceHashes(ReferenceChain::STREAM, 5, 8);

    expect(fold()->root(ReferenceChain::STREAM, 5, 8, ReferenceChain::ROOT_1_4, 'sha256', $hashes))
        ->toBe(ReferenceChain::ROOT_5_8)
        ->and(fold()->root(ReferenceChain::STREAM, 5, 8, null, 'sha256', $hashes))
        ->not->toBe(ReferenceChain::ROOT_5_8);
});

it('anchors each stream on its own, so the fork does not borrow the root of the trunk', function (): void {
    expect(fold()->root(ReferenceChain::FORK, 1, 2, null, 'sha256', referenceHashes(ReferenceChain::FORK, 1, 2)))
        ->toBe(ReferenceChain::FORK_ROOT_1_2)
        ->and(fold()->root(ReferenceChain::FORK, 1, 4, null, 'sha256', referenceHashes(ReferenceChain::STREAM, 1, 4)))
        ->not->toBe(ReferenceChain::ROOT_1_4);
});

it('separates a range of one from the entry it contains', function (): void {
    $hashes = referenceHashes(ReferenceChain::STREAM, 1, 1);

    expect(fold()->root(ReferenceChain::STREAM, 1, 1, null, 'sha256', $hashes))->not->toBe($hashes[0]);
});

it('covers both ends, so the same entries under another span fold elsewhere', function (): void {
    expect(fold()->root(ReferenceChain::STREAM, 1, 5, null, 'sha256', referenceHashes(ReferenceChain::STREAM, 1, 4)))
        ->not->toBe(ReferenceChain::ROOT_1_4);
});

it('covers the order, so the same hashes reversed fold elsewhere', function (): void {
    expect(fold()->root(ReferenceChain::STREAM, 1, 4, null, 'sha256', array_reverse(referenceHashes(ReferenceChain::STREAM, 1, 4))))
        ->not->toBe(ReferenceChain::ROOT_1_4);
});

it('reproduces the frozen root without going through the package at all', function (): void {
    $root = hash('sha256', implode("\x1f", ['fold-sha256', ReferenceChain::STREAM, '1', '4', '']));

    foreach (referenceHashes(ReferenceChain::STREAM, 1, 4) as $hash) {
        $root = hash('sha256', $root."\x1f".$hash);
    }

    expect($root)->toBe(ReferenceChain::ROOT_1_4);
});

it('folds with the digest it was given, not with one of its own', function (): void {
    expect(fold()->root(ReferenceChain::STREAM, 1, 4, null, 'sha512', referenceHashes(ReferenceChain::STREAM, 1, 4)))
        ->toHaveLength(128);
});

it('refuses a digest the runtime does not know', function (): void {
    expect(fn (): string => fold()->root(ReferenceChain::STREAM, 1, 4, null, 'nonesuch', referenceHashes(ReferenceChain::STREAM, 1, 4)))
        ->toThrow(ConfigurationException::class, 'nonesuch');
});

it('folds the same root over a table and over an array filled out of order', function (): void {
    seedTheReferenceChain();

    $backwards = app(MemoryLedger::class);

    foreach (array_reverse(ReferenceChain::entries()) as $entry) {
        $backwards->append(new Audit()->forceFill($entry));
    }

    expect(fold()->root(ReferenceChain::STREAM, 1, 4, null, 'sha256', hashesOf(ledger(), ReferenceChain::STREAM, 1, 4)))
        ->toBe(ReferenceChain::ROOT_1_4)
        ->and(fold()->root(ReferenceChain::STREAM, 1, 4, null, 'sha256', hashesOf($backwards, ReferenceChain::STREAM, 1, 4)))
        ->toBe(ReferenceChain::ROOT_1_4);
});
