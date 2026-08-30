<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Integrity\Checkpoint;
use ElPandaPe\Sentinel\Integrity\Fold;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use ElPandaPe\Sentinel\Tests\Fixtures\SigningKeys;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\anchorAheadOf;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\checkpoints;
use function ElPandaPe\Sentinel\Tests\fold;
use function ElPandaPe\Sentinel\Tests\referenceHashes;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;
use function ElPandaPe\Sentinel\Tests\signerRing;
use function ElPandaPe\Sentinel\Tests\signingWith;

beforeEach(function (): void {
    seedTheReferenceChain();
});

it('anchors the frozen chain into the roots it froze', function (): void {
    $anchors = anchor(ReferenceChain::STREAM, 4);

    expect($anchors)->toHaveCount(2)
        ->and($anchors[0]->rootHash)->toBe(ReferenceChain::ROOT_1_4)
        ->and($anchors[1]->rootHash)->toBe(ReferenceChain::ROOT_5_8);
});

it('anchors a stream of its own without borrowing the trunk', function (): void {
    expect(anchor(ReferenceChain::FORK, 2)[0]->rootHash)->toBe(ReferenceChain::FORK_ROOT_1_2);
});

it('lays the windows end to end, with neither a hole nor an overlap', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    expect(array_map(
        static fn (Checkpoint $anchor): array => [$anchor->from, $anchor->to],
        checkpoints()->of(ReferenceChain::STREAM),
    ))->toBe([[1, 4], [5, 8]]);
});

it('leaves the trailing window unanchored until it fills', function (): void {
    $anchors = anchor(ReferenceChain::STREAM, 3);

    expect(array_map(static fn (Checkpoint $anchor): int => $anchor->to, $anchors))->toBe([3, 6]);
});

it('anchors nothing over a stream shorter than one window', function (): void {
    expect(anchor(ReferenceChain::FORK, 4))->toBeEmpty();
});

it('anchors nothing the second time it is asked', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    expect(anchor(ReferenceChain::STREAM, 4))->toBeEmpty()
        ->and(checkpoints()->of(ReferenceChain::STREAM))->toHaveCount(2);
});

it('refuses a window with a hole in it', function (): void {
    DB::table(auditsTable())->where('id', '01JCHAIN0000000000000000S3')->delete();

    expect(anchor(ReferenceChain::STREAM, 4))->toBeEmpty();
});

it('names the construction and the digest in the column that carries them', function (): void {
    expect(anchor(ReferenceChain::STREAM, 4)[0]->algorithm)->toBe(Fold::name('sha256'));
});

it('signs the root with the key that signs now, and says which key it was', function (): void {
    signingWith('v1', SigningKeys::SECRET);

    $anchor = anchor(ReferenceChain::STREAM, 4)[0];

    expect($anchor->keyId)->toBe('v1')
        ->and($anchor->signature)->not->toBeNull()
        ->and(signerRing()->for('v1')?->verify($anchor->rootHash, (string) $anchor->signature))->toBeTrue();
});

it('leaves both signature columns empty when nobody is signing', function (): void {
    $anchor = anchor(ReferenceChain::STREAM, 4)[0];

    expect($anchor->signature)->toBeNull()
        ->and($anchor->keyId)->toBeNull();
});

it('folds each anchor over the one before it, so reissuing one obliges reissuing the rest', function (): void {
    $anchors = anchor(ReferenceChain::STREAM, 4);

    expect($anchors[1]->rootHash)->toBe(fold()->root(
        ReferenceChain::STREAM,
        5,
        8,
        $anchors[0]->rootHash,
        'sha256',
        referenceHashes(ReferenceChain::STREAM, 5, 8),
    ));
});

it('takes the window after the one it lost when a rival anchored first', function (): void {
    anchorAheadOf(ReferenceChain::STREAM, 1);

    expect(anchor(ReferenceChain::STREAM, 4))->toHaveCount(2);
});

it('gives the violation back rather than looping when it loses every race', function (): void {
    anchorAheadOf(ReferenceChain::STREAM, PHP_INT_MAX);

    expect(fn (): array => anchor(ReferenceChain::STREAM, 4))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('hands back the last anchor of a stream, and nothing for a stream with none', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    expect(checkpoints()->last(ReferenceChain::STREAM)?->to)->toBe(8)
        ->and(checkpoints()->last(ReferenceChain::FORK))->toBeNull();
});
