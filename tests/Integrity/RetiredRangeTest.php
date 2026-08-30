<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\CheckpointState;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\checkpointsTable;
use function ElPandaPe\Sentinel\Tests\manifest;
use function ElPandaPe\Sentinel\Tests\retireEntries;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    seedTheReferenceChain();
    anchor(ReferenceChain::STREAM, 4);
});

it('steps over a range the manifest accounts for and the anchors reach past', function (): void {
    retireEntries(ReferenceChain::STREAM, 1, 4);
    manifest()->record(ReferenceChain::STREAM, 1, 4, 4);

    $verification = verifier()->verify(ReferenceChain::STREAM);

    expect($verification->isIntact())->toBeTrue()
        ->and($verification->chain->checked)->toBe(4)
        ->and($verification->archived())->toBe(4);
});

it('counts what it stepped over apart from what it read', function (): void {
    retireEntries(ReferenceChain::STREAM, 1, 4);
    manifest()->record(ReferenceChain::STREAM, 1, 4, 4);

    $verification = verifier()->verify(ReferenceChain::STREAM);

    expect($verification->chain->checked + $verification->archived())->toBe(8);
});

it('reports a gap nothing accounts for, as it always did', function (): void {
    retireEntries(ReferenceChain::STREAM, 1, 4);

    $verification = verifier()->verify(ReferenceChain::STREAM);

    expect($verification->chain->reason)->toBe(IntegrityBreak::SequenceGap)
        ->and($verification->chain->sequence)->toBe(1)
        ->and($verification->archived())->toBe(0);
});

it('refuses to take the manifest word when no anchor reaches the range', function (): void {
    retireEntries(ReferenceChain::STREAM, 1, 4);
    manifest()->record(ReferenceChain::STREAM, 1, 4, 4);
    DB::table(checkpointsTable())->delete();

    expect(verifier()->verify(ReferenceChain::STREAM)->chain->reason)->toBe(IntegrityBreak::SequenceGap);
});

it('refuses to take the manifest word for more than it claims', function (): void {
    retireEntries(ReferenceChain::STREAM, 1, 4);
    manifest()->record(ReferenceChain::STREAM, 1, 3, 3);

    expect(verifier()->verify(ReferenceChain::STREAM)->chain->reason)->toBe(IntegrityBreak::SequenceGap);
});

it('still reads every entry that is there', function (): void {
    retireEntries(ReferenceChain::STREAM, 1, 4);
    manifest()->record(ReferenceChain::STREAM, 1, 4, 4);

    DB::table(auditsTable())
        ->where('stream', ReferenceChain::STREAM)
        ->where('sequence', 7)
        ->update(['event' => 'tampered']);

    expect(verifier()->verify(ReferenceChain::STREAM)->chain->reason)->toBe(IntegrityBreak::HashMismatch);
});

it('calls a range whose root it cannot fold again retired, when something accounts for it', function (): void {
    retireEntries(ReferenceChain::STREAM, 1, 4);
    manifest()->record(ReferenceChain::STREAM, 1, 4, 4);

    $verification = verifier()->verifyRoots(ReferenceChain::STREAM);

    expect($verification->isIntact())->toBeTrue()
        ->and($verification->anchors)->toBe([
            CheckpointState::Anchored->value => 1,
            CheckpointState::Archived->value => 1,
        ]);
});

it('still calls a range whose root it cannot fold again a break when nothing accounts for it', function (): void {
    retireEntries(ReferenceChain::STREAM, 1, 4);

    $verification = verifier()->verifyRoots(ReferenceChain::STREAM);

    expect($verification->isIntact())->toBeFalse()
        ->and($verification->chain->reason)->toBe(IntegrityBreak::CheckpointMismatch);
});

it('leaves the anchor walk alone, which never read those entries anyway', function (): void {
    retireEntries(ReferenceChain::STREAM, 1, 4);
    manifest()->record(ReferenceChain::STREAM, 1, 4, 4);

    $verification = verifier()->verifyAnchors(ReferenceChain::STREAM);

    expect($verification->isIntact())->toBeTrue()
        ->and($verification->covered)->toBe(8);
});

it('does not let a manifest row excuse a range whose entries are still there and no longer fold', function (): void {
    DB::table(auditsTable())
        ->where('stream', ReferenceChain::STREAM)
        ->where('sequence', 2)
        ->update(['hash' => str_repeat('0', 64)]);

    manifest()->record(ReferenceChain::STREAM, 1, 4, 4);

    $verification = verifier()->verifyRoots(ReferenceChain::STREAM);

    expect($verification->isIntact())->toBeFalse()
        ->and($verification->chain->reason)->toBeIn([IntegrityBreak::CheckpointMismatch, IntegrityBreak::HashMismatch]);
});
