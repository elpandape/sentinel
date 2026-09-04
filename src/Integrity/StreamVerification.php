<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Enums\ContentState;

/**
 * What one walk of one stream found: whether the chain holds, what it found out about the
 * signatures along the way, and how much of the stream it took on the word of an anchor rather
 * than by reading it.
 *
 * The three are separate answers on purpose. A chain can be perfectly intact while every entry in
 * it is unsigned, and calling that a failure would make the report useless on every installation
 * that has not switched signing on. And a range under a valid anchor is anchored, never intact:
 * intact is reserved for what was read, so `checked` and `covered` are published side by side and
 * a reader can see which of the two a number came from.
 *
 * An invalid signature is the one signature state that is a defect, so it arrives here in the same
 * shape a broken link does: the same reason vocabulary, the same point of rupture.
 *
 * Anchors are signed too, and their tally is kept apart from the entries'. The two answer different
 * questions — whether the entries somebody read are attested, and whether the anchors standing in
 * for the ones nobody read are — and an operator reading "two unsigned" needs to know which. One
 * tally for both also cost the truth of the number: merged, the states they shared overwrote each
 * other instead of adding up.
 */
final readonly class StreamVerification
{
    /**
     * @param  array<string, int>  $signatures  how many entries landed in each SignatureState
     * @param  array<string, int>  $anchors  how many ranges landed in each CheckpointState
     * @param  int  $covered  entries an anchor answered for, which is to say entries nobody read
     * @param  array<string, int>  $content  how many entries landed in each ContentState
     * @param  array<string, int>  $anchorSignatures  how many anchors landed in each SignatureState
     */
    public function __construct(
        public VerificationResult $chain,
        public array $signatures,
        public ?VerificationResult $signature = null,
        public array $anchors = [],
        public int $covered = 0,
        public array $content = [],
        public array $anchorSignatures = [],
    ) {}

    /**
     * How many entries of this walk were redacted. A declared redaction is counted and not announced:
     * it does not break the chain, does not stop the walk and does not invert isIntact(), because it
     * is an act somebody performed on purpose and left a trail for. A tampering does all three, and
     * wins over a tombstone standing next to it — otherwise a redaction would be a place to hide one.
     */
    public function redacted(): int
    {
        return $this->content[ContentState::Redacted->value] ?? 0;
    }

    /**
     * Entries the walk stepped over because they are not in the ledger any more. It is read off the
     * chain result rather than passed twice: the walk is what met the absence, and two copies of
     * one number is one of them going stale.
     */
    public function archived(): int
    {
        return $this->chain->archived;
    }

    public function isIntact(): bool
    {
        return $this->chain->isIntact() && ! $this->signature instanceof VerificationResult;
    }

    public function stream(): string
    {
        return $this->chain->stream;
    }

    /**
     * The first thing that was wrong, chain before signature: a stream whose links do not hold is
     * a bigger fact than one whose attestation does not, and a report that led with the smaller
     * one would bury it.
     */
    public function break(): ?VerificationResult
    {
        return $this->chain->isIntact() ? $this->signature : $this->chain;
    }
}
