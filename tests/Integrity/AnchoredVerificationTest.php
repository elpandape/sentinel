<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\CheckpointState;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Integrity\IntegrityReport;
use ElPandaPe\Sentinel\Integrity\StreamVerification;
use ElPandaPe\Sentinel\Integrity\VerificationResult;
use ElPandaPe\Sentinel\Support\Reference;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use ElPandaPe\Sentinel\Tests\Fixtures\SigningKeys;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\checkpointsTable;
use function ElPandaPe\Sentinel\Tests\redactor;
use function ElPandaPe\Sentinel\Tests\seedTheLongChain;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;
use function ElPandaPe\Sentinel\Tests\signingWith;
use function ElPandaPe\Sentinel\Tests\statementsDuring;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    seedTheReferenceChain();
});

it('reports a stream nobody anchored as such, and walks it whole', function (): void {
    $verification = verifier()->verifyAnchors(ReferenceChain::STREAM);

    expect($verification->anchors)->toBe([CheckpointState::Absent->value => 1])
        ->and($verification->chain->checked)->toBe(8)
        ->and($verification->covered)->toBe(0)
        ->and($verification->isIntact())->toBeTrue();
});

it('takes the anchored range on the anchors word and reads only the tail', function (): void {
    anchor(ReferenceChain::STREAM, 3);

    $verification = verifier()->verifyAnchors(ReferenceChain::STREAM);

    expect($verification->anchors)->toBe([CheckpointState::Anchored->value => 2])
        ->and($verification->covered)->toBe(6)
        ->and($verification->chain->checked)->toBe(2)
        ->and($verification->isIntact())->toBeTrue();
});

it('never counts a range it did not read as one it checked', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    $verification = verifier()->verifyAnchors(ReferenceChain::STREAM);

    expect($verification->chain->checked)->toBe(0)
        ->and($verification->covered)->toBe(8);
});

it('folds every root again and agrees with the anchors on a chain nobody touched', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    expect(verifier()->verifyRoots(ReferenceChain::STREAM)->isIntact())->toBeTrue();
});

it('names the entry inside the range whose hash somebody rewrote', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    DB::table(auditsTable())
        ->where('id', '01JCHAIN0000000000000000S2')
        ->update(['hash' => str_repeat('0', 64)]);

    $break = verifier()->verifyRoots(ReferenceChain::STREAM)->break();

    expect($break?->reason)->toBe(IntegrityBreak::HashMismatch)
        ->and($break?->sequence)->toBe(2);
});

it('takes an anchored range on trust that the walk of every entry refuses', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    DB::table(auditsTable())->where('id', '01JCHAIN0000000000000000S2')->update(['event' => 'moved']);

    expect(verifier()->verifyRoots(ReferenceChain::STREAM)->isIntact())->toBeTrue()
        ->and(verifier()->verifyStream(ReferenceChain::STREAM)->reason)->toBe(IntegrityBreak::HashMismatch);
});

it('names the missing entry rather than blaming the anchor over it', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    DB::table(auditsTable())->where('id', '01JCHAIN0000000000000000S2')->delete();

    $break = verifier()->verifyRoots(ReferenceChain::STREAM)->break();

    expect($break?->reason)->toBe(IntegrityBreak::SequenceGap)
        ->and($break?->sequence)->toBe(2);
});

it('reports the anchor when the range under it is sound and the root is not', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    DB::table(checkpointsTable())->where('sequence_from', 1)->update(['root_hash' => str_repeat('0', 64)]);

    $break = verifier()->verifyRoots(ReferenceChain::STREAM)->break();

    expect($break?->reason)->toBe(IntegrityBreak::CheckpointMismatch)
        ->and($break?->sequence)->toBe(1);
});

it('reports an anchor written by a construction this build does not know as one it cannot recompute', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    DB::table(checkpointsTable())->update(['algorithm' => 'merkle-sha256']);

    expect(verifier()->verifyRoots(ReferenceChain::STREAM)->break()?->reason)
        ->toBe(IntegrityBreak::CheckpointMismatch);
});

it('refuses a chain of anchors with a hole in it', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    DB::table(checkpointsTable())->where('sequence_from', 1)->delete();

    $break = verifier()->verifyAnchors(ReferenceChain::STREAM)->break();

    expect($break?->reason)->toBe(IntegrityBreak::CheckpointMismatch)
        ->and($break?->sequence)->toBe(5);
});

it('tallies what every anchor signature says', function (): void {
    signingWith('v1', SigningKeys::SECRET);
    anchor(ReferenceChain::STREAM, 4);

    expect(verifier()->verifyAnchors(ReferenceChain::STREAM)->anchorSignatures)
        ->toBe([SignatureState::Signed->value => 2]);
});

it('reports an anchor signed with a key nobody holds as unresolvable, never as forged', function (): void {
    signingWith('v1', SigningKeys::SECRET);
    anchor(ReferenceChain::STREAM, 4);

    config()->set('sentinel.integrity.signature.keys', ['v2' => SigningKeys::ROTATED_SECRET]);

    app()->forgetScopedInstances();

    $verification = verifier()->verifyAnchors(ReferenceChain::STREAM);

    expect($verification->anchorSignatures)->toBe([SignatureState::UnknownKey->value => 2])
        ->and($verification->isIntact())->toBeTrue();
});

it('reports an anchor whose own key refuses its signature as forged', function (): void {
    signingWith('v1', SigningKeys::SECRET);
    anchor(ReferenceChain::STREAM, 4);

    DB::table(checkpointsTable())->where('sequence_from', 5)->update(['signature' => base64_encode('nonesuch')]);

    $verification = verifier()->verifyAnchors(ReferenceChain::STREAM);

    expect($verification->isIntact())->toBeFalse()
        ->and($verification->break()?->reason)->toBe(IntegrityBreak::SignatureMismatch)
        ->and($verification->break()?->sequence)->toBe(5);
});

it('walks the anchors in fewer statements than the entries they stand for', function (): void {
    DB::table(auditsTable())->delete();
    seedTheLongChain(1600);
    anchor('global', 1500);

    $overAnchors = statementsDuring(fn (): StreamVerification => verifier()->verifyAnchors('global'));
    $overEntries = statementsDuring(fn (): VerificationResult => verifier()->verifyStream('global'));

    expect($overAnchors)->toBeLessThan($overEntries)
        ->and(verifier()->verifyAnchors('global')->isIntact())->toBeTrue();
});

it('offers the two shallow walks on the facade without changing what the deep one promises', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    expect(Sentinel::verifyAnchors(ReferenceChain::STREAM)->covered)->toBe(8)
        ->and(Sentinel::verifyRoots(ReferenceChain::STREAM)->covered)->toBe(8)
        ->and(Sentinel::verifyIntegrity(ReferenceChain::STREAM)->checked)->toBe(8);
});

it('adds up what every stream read and what every stream took on an anchors word, apart', function (): void {
    anchor(ReferenceChain::STREAM, 4);
    anchor(ReferenceChain::FORK, 2);

    $report = new IntegrityReport([
        verifier()->verifyAnchors(ReferenceChain::STREAM),
        verifier()->verifyAnchors(ReferenceChain::FORK),
    ]);

    expect($report->covered())->toBe(10)
        ->and($report->checked())->toBe(0)
        ->and($report->anchors())->toBe([CheckpointState::Anchored->value => 3])
        ->and($report->anchorSignatures())->toBe([SignatureState::Unsigned->value => 3])
        ->and($report->signatures())->toBeEmpty()
        ->and($report->isIntact())->toBeTrue();
});

it('says in a sentence which anchor stopped folding to its own root', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    DB::table(checkpointsTable())->where('sequence_from', 1)->update(['root_hash' => str_repeat('0', 64)]);

    expect(verifier()->verifyRoots(ReferenceChain::STREAM)->break()?->message())
        ->toContain('no longer folds to the root it recorded')
        ->toContain(ReferenceChain::STREAM);
});

it('reports an anchor nobody signed as unsigned, which is not a defect', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    $verification = verifier()->verifyAnchors(ReferenceChain::STREAM);

    expect($verification->anchorSignatures)->toBe([SignatureState::Unsigned->value => 2])
        ->and($verification->isIntact())->toBeTrue();
});

it('keeps the anchors and the tail apart when they landed in the same state', function (): void {
    anchor(ReferenceChain::STREAM, 3);

    $verification = verifier()->verifyAnchors(ReferenceChain::STREAM);

    expect($verification->anchorSignatures)->toBe([SignatureState::Unsigned->value => 2])
        ->and($verification->signatures)->toBe([SignatureState::Unsigned->value => 2])
        ->and($verification->covered)->toBe(6)
        ->and($verification->chain->checked)->toBe(2);
});

it('counts a redaction in the tail, which a walk that read it would have counted', function (): void {
    anchor(ReferenceChain::STREAM, 3);

    redactor()->redact(Sentinel::audits()->take(8)->get()->last(), 'erasure request', new Reference('member', '77'));

    expect(verifier()->verifyAnchors(ReferenceChain::STREAM)->redacted())->toBe(1);
});
