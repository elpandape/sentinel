<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\referenceChainOf;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;
use function ElPandaPe\Sentinel\Tests\verifier;

it('carries a link that is the hash of the entry before it, and nothing invented', function (string $stream): void {
    $previous = null;

    foreach (referenceChainOf($stream) as $position => $entry) {
        expect($entry['previous_hash'])->toBe($previous)
            ->and($entry['sequence'])->toBe($position + 1);

        $previous = $entry['hash'];
    }
})->with([ReferenceChain::STREAM, ReferenceChain::FORK]);

it('still reproduces the frozen hashes', function (): void {
    foreach (ReferenceChain::entries() as $entry) {
        expect(hasher()->hash(new Audit()->forceFill($entry)))->toBe($entry['hash']);
    }
});

it('reproduces the frozen hash without going through the package at all', function (): void {
    foreach (ReferenceChain::entries() as $entry) {
        $canonical = new JsonCanonicalizer()->canonicalize(
            CanonicalPayload::from(new Audit()->forceFill($entry)),
        );

        $prefix = implode("\x1f", [
            (string) $entry['payload_version'],
            (string) $entry['stream'],
            (string) $entry['sequence'],
            $entry['previous_hash'] ?? '',
        ]);

        expect(hash('sha256', $prefix."\x1f".$canonical))->toBe($entry['hash']);
    }
});

it('verifies whole, as the chain it is', function (string $stream, int $length): void {
    seedTheReferenceChain();

    $result = verifier()->verifyStream($stream);

    expect($result->isIntact())->toBeTrue()
        ->and($result->checked)->toBe($length);
})->with([[ReferenceChain::STREAM, 8], [ReferenceChain::FORK, 2]]);

it('verifies a range without reading the entries around it', function (): void {
    seedTheReferenceChain();

    $result = verifier()->verifyStream(ReferenceChain::STREAM, from: 5, to: 7);

    expect($result->isIntact())->toBeTrue()
        ->and($result->checked)->toBe(3);
});

it('stops at the entry someone changed, and names it', function (): void {
    seedTheReferenceChain();

    DB::table(auditsTable())->where('id', '01JCHAIN0000000000000000S6')->update(['event' => 'moved']);

    $result = verifier()->verifyStream(ReferenceChain::STREAM);

    expect($result->isIntact())->toBeFalse()
        ->and($result->reason)->toBe(IntegrityBreak::HashMismatch)
        ->and($result->sequence)->toBe(6)
        ->and($result->auditId)->toBe('01JCHAIN0000000000000000S6')
        ->and($result->checked)->toBe(5);
});

it('leaves the other stream intact when one of them breaks', function (): void {
    seedTheReferenceChain();

    DB::table(auditsTable())->where('id', '01JCHAIN0000000000000000S6')->update(['event' => 'moved']);

    expect(verifier()->verifyStream(ReferenceChain::FORK)->isIntact())->toBeTrue();
});

it('catches a rewritten link on the row that carries it, because the hash covers it', function (): void {
    seedTheReferenceChain();

    DB::table(auditsTable())
        ->where('id', '01JCHAIN0000000000000000S4')
        ->update(['previous_hash' => str_repeat('0', 64)]);

    $result = verifier()->verifyStream(ReferenceChain::STREAM);

    expect($result->reason)->toBe(IntegrityBreak::HashMismatch)
        ->and($result->sequence)->toBe(4);
});
