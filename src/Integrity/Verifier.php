<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Archive\Manifest;
use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\Signer;
use ElPandaPe\Sentinel\Enums\CheckpointState;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class Verifier
{
    public function __construct(
        private Ledger $ledger,
        private Hasher $hasher,
        private Signers $signers,
        private Checkpoints $checkpoints,
        private Manifest $archives,
        private Dispatcher $events,
    ) {}

    public function verifyEntry(Audit $audit): bool
    {
        return hash_equals($audit->hash, $this->hasher->hash($audit));
    }

    /**
     * What the signature says, which is a different question from whether the entry reproduces its
     * own hash. An entry written before signing was switched on carries none and is reported as
     * carrying none; one whose key left the ring is reported as unresolvable, never as forged. The
     * verifier is only entitled to call a signature invalid when it held the key that could have
     * made it valid.
     */
    public function verifySignature(Audit $audit): SignatureState
    {
        $signature = $audit->signature;

        if ($signature === null) {
            return SignatureState::Unsigned;
        }

        $signer = $this->signers->for($audit->signature_key_id ?? '');

        if (! $signer instanceof Signer) {
            return SignatureState::UnknownKey;
        }

        return $signer->verify($audit->hash, $signature)
            ? SignatureState::Signed
            : SignatureState::Invalid;
    }

    public function verifyStream(string $name, ?int $from = null, ?int $to = null): VerificationResult
    {
        return $this->verify($name, $from, $to)->chain;
    }

    /**
     * One walk, both answers. The signature of every entry is checked here rather than behind a
     * flag, because a verification that skipped it would be claiming the trail sound while holding
     * an attestation it never opened — and when nothing is signed the check costs one null test a
     * row.
     *
     * A broken link stops the walk: past it nothing can be said. A broken signature does not, since
     * the chain still holds and the rest of it is still worth reading.
     *
     * An absence stops it too, unless two things account for it at once: the manifest says the range
     * was retired, and the anchors reach past it. Neither alone is enough. The manifest is unsigned
     * and unhashed, so on its own it would make "delete the rows, then insert one row" a supported
     * way of laundering a gap; the anchors are the evidence, and the manifest only says which
     * absence they are being asked about.
     */
    public function verify(string $name, ?int $from = null, ?int $to = null): StreamVerification
    {
        $expected = $from ?? 1;
        $previous = $this->linkBefore($name, $expected);
        $checked = 0;
        $archived = 0;
        $reach = null;
        $signatures = [];
        $forged = null;

        foreach ($this->ledger->stream($name)->range($expected, $to) as $audit) {
            if ($audit->sequence > $expected) {
                $reach ??= $this->checkpoints->reach($name);
                $retired = $this->accountedFor($name, $expected, $audit->sequence - 1, $reach);

                if ($retired === null) {
                    return new StreamVerification(
                        $this->announce($name, $checked, IntegrityBreak::SequenceGap, $expected, $audit->id, $archived),
                        $signatures,
                        $forged,
                    );
                }

                $archived += $retired;
                $expected = $audit->sequence;

                // The hash this entry links to left with the range, so the seam is not checked and
                // not invented either. It is the same answer linkBefore() gives at the edge of a
                // bounded range, and the reason these entries are counted apart from the read ones.
                $previous = null;
            }

            $broken = match (true) {
                $audit->sequence !== $expected => IntegrityBreak::SequenceGap,
                ! $this->verifyEntry($audit) => IntegrityBreak::HashMismatch,
                $this->unlinked($audit, $previous) => IntegrityBreak::LinkMismatch,
                default => null,
            };

            if ($broken !== null) {
                return new StreamVerification(
                    $this->announce($name, $checked, $broken, min($audit->sequence, $expected), $audit->id, $archived),
                    $signatures,
                    $forged,
                );
            }

            $state = $this->verifySignature($audit);
            $signatures[$state->value] = ($signatures[$state->value] ?? 0) + 1;

            if ($state === SignatureState::Invalid && ! $forged instanceof VerificationResult) {
                $forged = $this->announce($name, $checked, IntegrityBreak::SignatureMismatch, $audit->sequence, $audit->id, $archived);
            }

            $previous = $audit->hash;
            $expected++;
            $checked++;
        }

        return new StreamVerification(VerificationResult::intact($name, $checked, $archived), $signatures, $forged);
    }

    /**
     * The anchors of a stream, and the tail no anchor covers yet. It walks the chain of anchors in
     * history-over-window rows instead of the history, and it says exactly what that buys: a range
     * under a valid anchor comes back *anchored*, never intact, because not one entry of it was
     * read. `checked` counts what was read and `covered` counts what an anchor answered for.
     *
     * A stream nobody has anchored is reported as such and walked whole, which gives the same
     * answer the entry walk would have given, only having paid for it.
     */
    public function verifyAnchors(string $name): StreamVerification
    {
        return $this->overAnchors($name, false);
    }

    /**
     * The same walk with every root folded again from the hashes the entries carry now. It catches
     * a hash rewritten or reordered and names the range it happened in, and then walks that range
     * entry by entry, which is what turns a broken range into a broken entry.
     *
     * It reads two columns a row and rehashes nothing, so it is cheaper than the entry walk and
     * proves less: an entry whose canonical columns changed while its hash column did not folds
     * back exactly as before. That is why a range it agrees with is still only anchored.
     */
    public function verifyRoots(string $name): StreamVerification
    {
        return $this->overAnchors($name, true);
    }

    /**
     * The whole trail, one stream at a time. A ledger that cannot name its chains is refused rather
     * than reported empty: answering "nothing is broken" about a list nobody could produce is the
     * one answer that would be read as reassurance and mean nothing.
     */
    public function verifyEverything(): IntegrityReport
    {
        if (! $this->ledger instanceof EnumeratesStreams) {
            throw QueryException::cannotEnumerateStreams($this->ledger::class);
        }

        return new IntegrityReport(array_map(
            fn (string $stream): StreamVerification => $this->verify($stream),
            $this->ledger->streams(),
        ));
    }

    /**
     * How many entries an absence covers when something accounts for it, and null when nothing
     * does. The anchors are asked first because their reach was already read; the manifest costs a
     * query and is only worth it once the anchors have said they answer for the range.
     */
    private function accountedFor(string $name, int $from, int $to, int $reach): ?int
    {
        return $reach >= $to && $this->archives->holds($name, $from, $to)
            ? $to - $from + 1
            : null;
    }

    /**
     * One pass over the anchors of a stream: contiguity from the first entry onwards, the signature
     * of every anchor, optionally the root folded again, and then the tail nobody has anchored yet.
     */
    private function overAnchors(string $name, bool $refold): StreamVerification
    {
        $anchors = $this->checkpoints->of($name);

        if ($anchors === []) {
            $walk = $this->verify($name);

            return new StreamVerification(
                $walk->chain,
                $walk->signatures,
                $walk->signature,
                [CheckpointState::Absent->value => 1],
            );
        }

        $signatures = [];
        $forged = null;
        $expected = 1;
        $previous = null;
        $anchored = 0;
        $retired = 0;

        foreach ($anchors as $anchor) {
            if ($anchor->from !== $expected) {
                return new StreamVerification(
                    $this->announce($name, $anchored + $retired, IntegrityBreak::CheckpointMismatch, $anchor->from, $anchor->rootHash),
                    $signatures,
                    $forged,
                    $this->ranges($anchored, $retired),
                    $expected - 1,
                );
            }

            $state = $this->verifyAnchor($anchor);
            $signatures[$state->value] = ($signatures[$state->value] ?? 0) + 1;

            if ($state === SignatureState::Invalid && ! $forged instanceof VerificationResult) {
                $forged = $this->announce($name, $anchored + $retired, IntegrityBreak::SignatureMismatch, $anchor->from, $anchor->rootHash);
            }

            $root = $refold ? $this->checkpoints->refold($anchor, $previous) : $anchor->rootHash;

            if ($root !== $anchor->rootHash) {
                if (! $this->retired($name, $anchor, $root)) {
                    return new StreamVerification(
                        $this->located($name, $anchor, $anchored + $retired),
                        $signatures,
                        $forged,
                        $this->ranges($anchored, $retired),
                        $expected - 1,
                    );
                }

                $retired++;
            } else {
                $anchored++;
            }

            $previous = $anchor->rootHash;
            $expected = $anchor->to + 1;
        }

        $tail = $this->verify($name, $expected);

        return new StreamVerification(
            $tail->chain,
            [...$signatures, ...$tail->signatures],
            $forged ?? $tail->signature,
            $this->ranges($anchored, $retired),
            $expected - 1,
        );
    }

    /**
     * How many ranges landed in each state. Anchored is always published, including as a zero,
     * because "no range held" and "this walk had nothing to say about ranges" are different facts
     * and the empty array already means the second one.
     *
     * @return array<string, int>
     */
    private function ranges(int $anchored, int $retired): array
    {
        $states = [CheckpointState::Anchored->value => $anchored];

        if ($retired > 0) {
            $states[CheckpointState::Archived->value] = $retired;
        }

        return $states;
    }

    /**
     * Whether a root that did not come back is a range that left rather than a range that changed.
     *
     * Only a root that could not be recomputed at all qualifies. A range that folds to a DIFFERENT
     * root has its entries right there and they have moved, and the manifest must not be able to
     * excuse that: the row is unsigned and unhashed, so treating any failed fold as retired would
     * make one insert the cost of hiding a rewritten range from the deepest walk there is.
     */
    private function retired(string $name, Checkpoint $anchor, ?string $root): bool
    {
        return $root === null && $this->archives->holds($name, $anchor->from, $anchor->to);
    }

    /**
     * A range whose root no longer folds back is walked entry by entry, so the report names the
     * entry and not just the range. If the walk finds nothing wrong — the entries are gone, or the
     * anchor was reissued over a range that never changed — the anchor itself is what is reported.
     */
    private function located(string $name, Checkpoint $anchor, int $anchored): VerificationResult
    {
        $walk = $this->verify($name, $anchor->from, $anchor->to)->chain;

        return $walk->isIntact()
            ? $this->announce($name, $anchored, IntegrityBreak::CheckpointMismatch, $anchor->from, $anchor->rootHash)
            : $walk;
    }

    private function verifyAnchor(Checkpoint $anchor): SignatureState
    {
        if ($anchor->signature === null) {
            return SignatureState::Unsigned;
        }

        $signer = $this->signers->for($anchor->keyId ?? '');

        if (! $signer instanceof Signer) {
            return SignatureState::UnknownKey;
        }

        return $signer->verify($anchor->rootHash, $anchor->signature)
            ? SignatureState::Signed
            : SignatureState::Invalid;
    }

    /**
     * An entry with no readable predecessor is only held to one thing: the first of a chain links to
     * nothing. That is the first entry of a walk that starts at the beginning, one whose predecessor
     * is no longer in the table, and one that follows a range the walk stepped over.
     *
     * Everywhere else the link is checked, because verifying a range that only proved its entries
     * link to each other — and never that they hang off what came before — is the one property an
     * anchor over that range is sold as giving.
     *
     * The question is what can be read and not how far the walk has come. A hash column is never
     * null, so a null predecessor means exactly this and nothing else.
     */
    private function unlinked(Audit $audit, ?string $previous): bool
    {
        return $previous === null
            ? $audit->sequence === 1 && $audit->previous_hash !== null
            : $audit->previous_hash !== $previous;
    }

    /**
     * The hash the first entry of a walk has to hang from, or null when there is nothing before it
     * to read — a walk that starts at the first entry, or one whose predecessor is no longer in the
     * table. The second case degrades to what the walk did before and never to a false break.
     */
    private function linkBefore(string $stream, int $expected): ?string
    {
        if ($expected <= 1) {
            return null;
        }

        foreach ($this->ledger->stream($stream)->range($expected - 1, $expected - 1) as $audit) {
            return $audit->hash;
        }

        return null;
    }

    private function announce(string $stream, int $checked, IntegrityBreak $reason, int $sequence, string $auditId, int $archived = 0): VerificationResult
    {
        $this->events->dispatch(new IntegrityVerificationFailed($stream, $reason, $sequence, $auditId));

        return VerificationResult::broken($stream, $checked, $reason, $sequence, $auditId, $archived);
    }
}
