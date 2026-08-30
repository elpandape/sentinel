<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

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
 */
final readonly class StreamVerification
{
    /**
     * @param  array<string, int>  $signatures  how many entries landed in each SignatureState
     * @param  array<string, int>  $anchors  how many ranges landed in each CheckpointState
     * @param  int  $covered  entries an anchor answered for, which is to say entries nobody read
     */
    public function __construct(
        public VerificationResult $chain,
        public array $signatures,
        public ?VerificationResult $signature = null,
        public array $anchors = [],
        public int $covered = 0,
    ) {}

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
